/**
 * WSO2 Auth Check - Implementation with direct authorize endpoint
 * Rileva sessioni WSO2 attive e reindirizza all'endpoint Drupal per login automatico
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.wso2AuthCheck = {
    attach: function (context, settings) {
      once('wso2-auth-check', 'body', context).forEach(function (element) {

        const debugLog = function(message, data) {
          if (config.debug) {
            console.log('[WSO2AuthCheck] ' + message, data || '');
          }
        };

        const config = drupalSettings.wso2AuthCheck || {};
        debugLog('🚀 WSO2 Auth Check inizializzato');

        // Skip se funzionalità disabilitata
        if (config.enabled === false) {
          debugLog('⚠️ WSO2 Auth Check disabilitato nella configurazione');
          return;
        }

        // Skip se utente già loggato
        if (drupalSettings.user && drupalSettings.user.uid > 0) {
          debugLog('✅ Utente già autenticato su Drupal');
          return;
        }

        // Controllo configurazione essenziale
        if (!config.clientId || !config.redirectUri || !config.idpUrl) {
          console.error('[WSO2AuthCheck] Configurazione incompleta:', config);
          return;
        }

        // Controllo intervallo
        const lastCheck = localStorage.getItem('wso2_auth_last_check');
        const intervalMinutes = parseFloat(config.checkInterval) || 0.5;
        const intervalMs = intervalMinutes * 60 * 1000;

        if (lastCheck && !config.debug) {
          const lastCheckTime = parseInt(lastCheck);
          const timeDiff = Date.now() - lastCheckTime;

          if (timeDiff < intervalMs) {
            const remainingMin = Math.ceil((intervalMs - timeDiff) / 60000);
            debugLog(`⏳ Skip controllo - prossimo tra ${remainingMin} minuti`);
            return;
          }
        }

        // Controllo fallimenti recenti
        const lastFailure = localStorage.getItem('wso2_auth_not_authenticated');
        if (lastFailure && !config.debug) {
          const failureTime = parseInt(lastFailure);
          const failureAge = Date.now() - failureTime;
          const failureCooldown = 2 * 60 * 1000; // 2 minuti di cooldown

          if (failureAge < failureCooldown) {
            debugLog('❌ Skip controllo - fallimento recente');
            return;
          }
        }

        /**
         * METODO DIRETTO: Verifica autenticazione con authorize endpoint e prompt=none
         */
        const checkDirectAuth = function() {
          return new Promise((resolve, reject) => {
            debugLog('🔍 Verifica diretta sessione WSO2 con authorize endpoint...');

            // Genera URL di autorizzazione con tutti i parametri specificati
            const authUrl = new URL(config.idpUrl);
            const state = crypto.randomUUID ? crypto.randomUUID() : 'probe_' + Date.now();
            const nonce = crypto.randomUUID ? crypto.randomUUID() : 'nonce_' + Date.now();

            // Aggiungi tutti i parametri richiesti
            const params = {
              agEntityId: config.agEntityId,
              client_id: config.clientId,
              redirect_uri: config.redirectUri,
              response_type: 'code',
              scope: 'openid',
              prompt: 'none',
              state: state,
              nonce: nonce
            };

            const urlParams = new URLSearchParams(params);
            const fullUrl = authUrl.toString() + '?' + urlParams.toString();

            debugLog('🔗 URL authorize check:', fullUrl);

            // Usa fetch con credenziali incluse
            fetch(fullUrl, {
              method: 'GET',
              credentials: 'include', // Importante: include cookies
              redirect: 'manual', // Non segue redirect automaticamente
            })
            .then(response => {
              debugLog('📊 Risposta authorize:', {
                status: response.status,
                type: response.type,
                ok: response.ok,
                redirected: response.redirected,
                url: response.url
              });

              // Analizza la risposta
              if (response.type === 'opaqueredirect') {
                // Redirect indica successo (il server sta reindirizzando alla callback)
                debugLog('✅ Redirect rilevato - analisi redirect URL');

                // Tenta di estrarre il codice dal redirect, anche se non possiamo accedere direttamente all'URL
                // In questo caso, consideriamo il redirect stesso come indicazione di sessione attiva
                resolve({ authenticated: true });

              } else if (response.status === 200) {
                // Status 200 è ambiguo, ma potrebbe indicare sessione attiva
                debugLog('⚠️ Status 200 ricevuto - potrebbero esserci form di login');
                // Consideriamo questo come una non-autenticazione
                resolve({ authenticated: false, reason: 'possible_login_form' });

              } else if (response.status === 400 || response.status === 401) {
                // 400/401 indica probabilmente "login_required"
                debugLog('❌ Stato 401/400 - utente non autenticato');
                resolve({ authenticated: false, reason: 'unauthorized' });

              } else {
                // Altri status sono ambigui
                debugLog('⚠️ Status inatteso:', response.status);
                reject(new Error('Unexpected status: ' + response.status));
              }
            })
            .catch(error => {
              debugLog('❌ Errore fetch:', error.message);

              // Alcuni errori CORS potrebbero indicare che un redirect è avvenuto
              // In questo caso, potrebbe essere un segno positivo (l'utente è autenticato)
              if (error.message.includes('redirect') ||
                  error.message.includes('opaque')) {
                debugLog('⚠️ Errore potrebbe indicare redirect - consideriamo autenticato');
                resolve({ authenticated: true, reason: 'redirect_detected' });
              } else {
                reject(error);
              }
            });
          });
        };

        /**
         * METODO IFRAME: Verifica autenticazione tramite iframe hidden
         */
        const checkIframeAuth = function() {
          return new Promise((resolve, reject) => {
            debugLog('🔍 Verifica sessione WSO2 via iframe...');

            // Crea iframe
            const iframe = document.createElement('iframe');
            iframe.style.cssText = 'position:absolute;width:0;height:0;border:0;visibility:hidden;';

            // Genera URL authorize con tutti i parametri richiesti
            const authUrl = new URL(config.idpUrl);
            const state = crypto.randomUUID ? crypto.randomUUID() : 'probe_' + Date.now();
            const nonce = crypto.randomUUID ? crypto.randomUUID() : 'nonce_' + Date.now();

            const params = {
              agEntityId: config.agEntityId,
              client_id: config.clientId,
              redirect_uri: config.redirectUri,
              response_type: 'code',
              scope: 'openid',
              prompt: 'none',
              state: state,
              nonce: nonce
            };

            const urlParams = new URLSearchParams(params);
            const fullUrl = authUrl.toString() + '?' + urlParams.toString();

            debugLog('🔗 URL iframe:', fullUrl);

            // Timeout esteso a 20 secondi
            const timeout = setTimeout(() => {
              debugLog('⏰ Timeout iframe check');

              if (document.body.contains(iframe)) {
                document.body.removeChild(iframe);
              }

              resolve({ authenticated: false, reason: 'timeout' });
            }, 20000);

            // Ascolta messaggi dalla pagina di callback
            const messageHandler = function(event) {
              // Verifica origine del messaggio (dovrebbe essere dal tuo dominio o dal dominio di callback)
              const callbackOrigin = new URL(config.redirectUri).origin;
              if (event.origin !== callbackOrigin && event.origin !== window.location.origin) {
                return;
              }

              if (event.data?.type === 'wso2_sso_probe_result') {
                debugLog('📨 Messaggio ricevuto da callback:', event.data);

                clearTimeout(timeout);
                window.removeEventListener('message', messageHandler);

                if (document.body.contains(iframe)) {
                  document.body.removeChild(iframe);
                }

                if (event.data.code) {
                  // Codice ricevuto - utente autenticato
                  resolve({ authenticated: true, code: event.data.code });
                } else if (event.data.error === 'login_required') {
                  // Errore specifico - utente non autenticato
                  resolve({ authenticated: false, reason: 'login_required' });
                } else {
                  // Altro errore
                  resolve({ authenticated: false, reason: event.data.error || 'unknown_error' });
                }
              }
            };

            window.addEventListener('message', messageHandler);

            // Verifica cambio URL (meccanismo di backup)
            const checkIntervalId = setInterval(() => {
              try {
                const currentUrl = iframe.contentWindow.location.href;

                // Tenta di rilevare cambio URL (anche se spesso bloccato da CORS)
                if (currentUrl && currentUrl !== 'about:blank' && !currentUrl.includes(fullUrl)) {
                  debugLog('🔍 URL iframe cambiato:', currentUrl);

                  try {
                    // Prova a leggere parametri dall'URL
                    const urlObj = new URL(currentUrl);
                    const responseParams = new URLSearchParams(urlObj.search);

                    if (responseParams.has('code')) {
                      // Codice trovato - autenticato
                      clearInterval(checkIntervalId);
                      clearTimeout(timeout);
                      window.removeEventListener('message', messageHandler);

                      if (document.body.contains(iframe)) {
                        document.body.removeChild(iframe);
                      }

                      resolve({ authenticated: true, code: responseParams.get('code') });
                    } else if (responseParams.has('error')) {
                      // Errore trovato - non autenticato
                      clearInterval(checkIntervalId);
                      clearTimeout(timeout);
                      window.removeEventListener('message', messageHandler);

                      if (document.body.contains(iframe)) {
                        document.body.removeChild(iframe);
                      }

                      resolve({ authenticated: false, reason: responseParams.get('error') });
                    }
                  } catch (urlError) {
                    // Errore nella lettura dei parametri - probabile errore CORS
                    debugLog('⚠️ Errore lettura parametri URL:', urlError.message);
                  }
                }
              } catch (e) {
                // Errore CORS - normale e atteso
                // debugLog('⚠️ Errore CORS check URL iframe:', e.message);
              }
            }, 500);

            // Avvia il processo caricando l'iframe
            document.body.appendChild(iframe);
            iframe.src = fullUrl;

            // Monitoraggio loading stato iframe
            iframe.onload = function() {
              debugLog('🔄 Iframe caricato');

              // Controlla dimensioni iframe (se troppo grandi, potrebbe essere un form di login)
              try {
                const iframeHeight = iframe.contentWindow.document.body.scrollHeight;
                const iframeWidth = iframe.contentWindow.document.body.scrollWidth;

                debugLog('📏 Dimensioni iframe:', { width: iframeWidth, height: iframeHeight });

                if (iframeHeight > 100 || iframeWidth > 100) {
                  // Dimensioni grandi potrebbero indicare un form di login
                  debugLog('⚠️ Iframe troppo grande - possibile form di login');
                }
              } catch (e) {
                // Errore CORS - normale
              }
            };
          });
        };

        /**
         * Reindirizza all'endpoint di autenticazione Drupal
         */
        const redirectToAuthEndpoint = function() {
          debugLog('🎯 Reindirizzamento a endpoint autenticazione Drupal');

          const currentPath = window.location.pathname + window.location.search + window.location.hash;
          const loginUrl = (config.loginPath || '/wso2-auth/authorize/citizen') +
                          '?destinazione=' + encodeURIComponent(currentPath);

          // Mostra notifica
          const notification = document.createElement('div');
          notification.style.cssText = `
            position: fixed; top: 0; left: 0; right: 0; z-index: 10000;
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white; padding: 20px; text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
          `;

          notification.innerHTML = `
            <div style="max-width: 600px; margin: 0 auto;">
              <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">
                🔐 Sessione attiva rilevata
              </div>
              <div style="font-size: 14px; opacity: 0.9; margin-bottom: 15px;">
                Reindirizzamento per accesso automatico...
              </div>
              <a href="${loginUrl}" style="
                color: white; text-decoration: none;
                background: rgba(255,255,255,0.2);
                padding: 8px 16px; border-radius: 4px;
                border: 1px solid rgba(255,255,255,0.3);
                font-size: 13px;
              ">Clicca qui se non vieni reindirizzato →</a>
            </div>
          `;

          document.body.appendChild(notification);

          // Reindirizza dopo breve delay
          setTimeout(() => {
            try {
              window.top.location.href = loginUrl;
            } catch (e) {
              window.location.replace(loginUrl);
            }
          }, 2000);
        };

        /**
         * Logica principale con approccio a più tentativi
         */
        const executeAuthCheck = async function() {
          try {
            debugLog('🎯 Avvio controllo autenticazione WSO2...');

            // Salva timestamp controllo
            localStorage.setItem('wso2_auth_last_check', Date.now().toString());

            // Prova diversi metodi fino a successo
            let result = null;
            let authenticated = false;

            // 1. Prova metodo diretto
            try {
              debugLog('🔄 Tentativo #1: Metodo diretto (authorize endpoint)');
              result = await checkDirectAuth();
              authenticated = result.authenticated;

              if (authenticated) {
                debugLog('✅ Autenticazione confermata con metodo diretto');
              } else {
                debugLog('❌ Autenticazione fallita con metodo diretto:', result.reason);
              }
            } catch (directError) {
              debugLog('⚠️ Errore metodo diretto:', directError.message);

              // 2. Prova metodo iframe
              try {
                debugLog('🔄 Tentativo #2: Metodo iframe');
                result = await checkIframeAuth();
                authenticated = result.authenticated;

                if (authenticated) {
                  debugLog('✅ Autenticazione confermata con iframe');
                } else {
                  debugLog('❌ Autenticazione fallita con iframe:', result.reason);
                }
              } catch (iframeError) {
                debugLog('⚠️ Errore metodo iframe:', iframeError.message);
              }
            }

            // Gestione risultato finale
            if (authenticated) {
              // Utente autenticato - reindirizza
              debugLog('🎉 CONFERMA FINALE: Utente autenticato su WSO2');
              localStorage.removeItem('wso2_auth_not_authenticated');
              redirectToAuthEndpoint();
            } else {
              // Utente non autenticato
              debugLog('❌ CONFERMA FINALE: Utente non autenticato');
              localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
            }

          } catch (error) {
            debugLog('❌ Errore generale durante controllo:', error.message);
            localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
          }
        };

        // Avvia il controllo con un breve delay
        setTimeout(() => {
          debugLog('🚀 Avvio controllo autenticazione con delay...');
          executeAuthCheck();
        }, 500);

        // Debug helpers
        if (config.debug) {
          window.wso2ForceAuthCheck = executeAuthCheck;
          window.wso2TestDirect = checkDirectAuth;
          window.wso2TestIframe = checkIframeAuth;

          // Reset timing
          window.wso2ResetTiming = function() {
            localStorage.removeItem('wso2_auth_last_check');
            localStorage.removeItem('wso2_auth_not_authenticated');
            console.log('🔄 Timing reset completato');
            return 'Reset completato';
          };

          debugLog('🔧 Debug helpers disponibili: wso2ForceAuthCheck(), wso2TestDirect(), wso2TestIframe(), wso2ResetTiming()');
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
