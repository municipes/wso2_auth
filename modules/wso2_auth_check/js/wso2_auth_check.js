/**
 * WSO2 Auth Check - Improved with SameSite cookie handling
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
         * METODO DIRETTO: Verifica autenticazione WSO2 tramite richiesta fetch diretta
         * Questa è l'opzione più affidabile per verificare l'autenticazione
         */
        const checkDirectSession = function() {
          return new Promise((resolve, reject) => {
            debugLog('🔍 Verifica diretta sessione WSO2...');

            // URL per controllo sessione - può essere l'endpoint userinfo o un endpoint personalizzato
            const sessionCheckUrl = config.checkSessionUrl ||
                                  config.idpUrl.replace('/oauth2/authorize', '/oauth2/userinfo');

            debugLog('🔗 URL controllo sessione:', sessionCheckUrl);

            // Esegui fetch con credenziali (include cookies)
            fetch(sessionCheckUrl, {
              method: 'GET',
              credentials: 'include', // Importante: include cookies anche in richieste cross-origin
              headers: {
                'Accept': 'application/json'
              }
            })
            .then(response => {
              debugLog('📊 Risposta controllo sessione:', {
                status: response.status,
                ok: response.ok,
                headers: [...response.headers.entries()].reduce((obj, [key, val]) => {
                  obj[key] = val;
                  return obj;
                }, {})
              });

              if (response.ok) {
                // Status 200 indica sessione attiva
                debugLog('✅ Sessione attiva rilevata (status 200)');
                resolve({ authenticated: true });
              } else if (response.status === 401) {
                // 401 Unauthorized indica no sessione
                debugLog('❌ Nessuna sessione attiva (status 401)');
                resolve({ authenticated: false, reason: 'unauthorized' });
              } else {
                // Altri status sono ambigui, trattati come errore
                debugLog('⚠️ Risposta ambigua:', response.status);
                reject(new Error('Ambiguous response: ' + response.status));
              }
            })
            .catch(error => {
              // Errore CORS o di rete
              debugLog('❌ Errore controllo diretto:', error.message);
              reject(error);
            });
          });
        };

        /**
         * METODO IFRAME: Verifica autenticazione WSO2 tramite iframe e prompt=none
         */
        const checkIframeSession = function() {
          return new Promise((resolve, reject) => {
            debugLog('🔍 Verifica sessione WSO2 via iframe...');

            // Genera URL autorizzazione con prompt=none
            const authUrl = new URL('/oauth2/authorize', config.idpUrl.replace('/oauth2/authorize', ''));
            const state = crypto.randomUUID ? crypto.randomUUID() : 'probe_' + Date.now();
            const nonce = crypto.randomUUID ? crypto.randomUUID() : 'nonce_' + Date.now();

            authUrl.search = new URLSearchParams({
              response_type: 'code',
              client_id: config.clientId,
              redirect_uri: config.redirectUri,
              scope: 'openid',
              prompt: 'none',
              state: state,
              nonce: nonce
            }).toString();

            debugLog('🔗 URL iframe probe:', authUrl.toString());

            // Crea iframe nascosto
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';

            // Timeout più lungo (15 secondi)
            const timeout = setTimeout(() => {
              debugLog('⏰ Timeout iframe probe');

              if (document.body.contains(iframe)) {
                document.body.removeChild(iframe);
              }

              reject(new Error('Timeout'));
            }, 15000);

            // Handler messaggi
            const messageHandler = function(event) {
              debugLog('📨 Messaggio ricevuto:', event.origin, event.data);

              // Verifica che il messaggio provenga dal redirect_uri o da questo dominio
              const callbackOrigin = new URL(config.redirectUri).origin;
              if (event.origin !== callbackOrigin && event.origin !== window.location.origin) {
                debugLog('⚠️ Messaggio ignorato: origine non attendibile');
                return;
              }

              if (event.data?.type === 'wso2_sso_probe_result') {
                debugLog('📨 Risultato probe ricevuto:', event.data);

                clearTimeout(timeout);
                window.removeEventListener('message', messageHandler);

                if (document.body.contains(iframe)) {
                  document.body.removeChild(iframe);
                }

                if (event.data.code) {
                  // Codice autorizzazione ricevuto - utente autenticato
                  resolve({
                    authenticated: true,
                    code: event.data.code,
                    state: event.data.state
                  });
                } else if (event.data.error === 'login_required' ||
                          event.data.error === 'interaction_required') {
                  // Errore specifico - utente non autenticato
                  resolve({
                    authenticated: false,
                    reason: event.data.error
                  });
                } else {
                  // Altro errore
                  reject(new Error(event.data.error || 'Unknown error'));
                }
              }
            };

            window.addEventListener('message', messageHandler);

            // Carica iframe
            document.body.appendChild(iframe);
            iframe.src = authUrl.toString();

            // IMPORTANTE: Controllo iframe alternativo
            // Se la callback non viene eseguita correttamente, verifichiamo direttamente
            // la presenza del codice nell'URL finale dell'iframe
            const iframeCheckInterval = setInterval(() => {
              try {
                // Tenta di leggere l'URL finale dell'iframe
                const currentUrl = iframe.contentWindow.location.href;

                // Se è cambiato dal valore iniziale, analizzalo
                if (currentUrl && currentUrl !== 'about:blank' &&
                    !currentUrl.includes(authUrl.toString())) {

                  debugLog('🔍 URL iframe cambiato:', currentUrl);

                  // Verifica se contiene codice o errore
                  const urlObj = new URL(currentUrl);
                  const params = new URLSearchParams(urlObj.search);

                  if (params.has('code')) {
                    // Codice trovato - utente autenticato
                    clearInterval(iframeCheckInterval);
                    clearTimeout(timeout);
                    window.removeEventListener('message', messageHandler);

                    if (document.body.contains(iframe)) {
                      document.body.removeChild(iframe);
                    }

                    resolve({
                      authenticated: true,
                      code: params.get('code'),
                      state: params.get('state')
                    });
                  } else if (params.has('error')) {
                    // Errore trovato - utente non autenticato
                    clearInterval(iframeCheckInterval);
                    clearTimeout(timeout);
                    window.removeEventListener('message', messageHandler);

                    if (document.body.contains(iframe)) {
                      document.body.removeChild(iframe);
                    }

                    if (params.get('error') === 'login_required' ||
                        params.get('error') === 'interaction_required') {
                      resolve({
                        authenticated: false,
                        reason: params.get('error')
                      });
                    } else {
                      reject(new Error(params.get('error') || 'Unknown error'));
                    }
                  }
                }
              } catch (e) {
                // Ignora errori di accesso cross-origin - è normale
                // debugLog('⚠️ Cross-origin check:', e.message);
              }
            }, 500);
          });
        };

        /**
         * METODO IMG: Verifica autenticazione tramite caricamento immagine
         * Utile come ultimo tentativo se tutto il resto fallisce
         */
        const checkImgSession = function() {
          return new Promise((resolve, reject) => {
            debugLog('🔍 Verifica sessione WSO2 via img...');

            // URL di un'immagine o endpoint che richiede autenticazione
            const imgUrl = config.idpUrl.replace('/oauth2/authorize', '/oidc/userinfo') +
                          '?t=' + Date.now();

            debugLog('🔗 URL img probe:', imgUrl);

            const img = new Image();

            img.onload = function() {
              // Immagine caricata - utente autenticato
              debugLog('✅ Img caricata - sessione attiva');
              resolve({ authenticated: true });
            };

            img.onerror = function() {
              // Errore - utente non autenticato o errore di rete
              debugLog('❌ Errore caricamento img');
              resolve({ authenticated: false, reason: 'img_load_failed' });
            };

            // Timeout
            setTimeout(() => {
              img.src = ''; // Cancella richiesta
              debugLog('⏰ Timeout img probe');
              resolve({ authenticated: false, reason: 'timeout' });
            }, 5000);

            img.src = imgUrl;
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
         * Logica principale con tentativo su tutti i metodi
         */
        const executeAuthCheck = async function() {
          try {
            debugLog('🎯 Avvio controllo autenticazione WSO2...');

            // Salva timestamp controllo
            localStorage.setItem('wso2_auth_last_check', Date.now().toString());

            // Prova tutti i metodi in sequenza fino a successo
            let result = null;
            let authenticated = false;

            // 1. Prova metodo diretto (fetch)
            try {
              debugLog('🔄 Tentativo #1: Metodo diretto (fetch)');
              result = await checkDirectSession();
              authenticated = result.authenticated;

              if (authenticated) {
                debugLog('✅ Autenticazione confermata con metodo diretto');
              } else {
                debugLog('❌ Autenticazione fallita con metodo diretto:', result.reason);
              }
            } catch (error) {
              debugLog('⚠️ Errore metodo diretto:', error.message);

              // 2. Prova metodo iframe
              try {
                debugLog('🔄 Tentativo #2: Metodo iframe');
                result = await checkIframeSession();
                authenticated = result.authenticated;

                if (authenticated) {
                  debugLog('✅ Autenticazione confermata con iframe');
                } else {
                  debugLog('❌ Autenticazione fallita con iframe:', result.reason);
                }
              } catch (iframeError) {
                debugLog('⚠️ Errore metodo iframe:', iframeError.message);

                // 3. Prova metodo img come ultima risorsa
                try {
                  debugLog('🔄 Tentativo #3: Metodo img');
                  result = await checkImgSession();
                  authenticated = result.authenticated;

                  if (authenticated) {
                    debugLog('✅ Autenticazione confermata con img');
                  } else {
                    debugLog('❌ Autenticazione fallita con img:', result.reason);
                  }
                } catch (imgError) {
                  debugLog('⚠️ Errore metodo img:', imgError.message);
                }
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

        // Avvia il controllo con un breve delay per assicurarsi che la pagina sia caricata
        setTimeout(() => {
          debugLog('🚀 Avvio controllo autenticazione con delay...');
          executeAuthCheck();
        }, 500);

        // Debug helpers
        if (config.debug) {
          window.wso2ForceAuthCheck = executeAuthCheck;
          window.wso2TestDirect = checkDirectSession;
          window.wso2TestIframe = checkIframeSession;
          window.wso2TestImg = checkImgSession;

          // Reset timing
          window.wso2ResetTiming = function() {
            localStorage.removeItem('wso2_auth_last_check');
            localStorage.removeItem('wso2_auth_not_authenticated');
            console.log('🔄 Timing reset completato');
            return 'Reset completato';
          };

          debugLog('🔧 Debug helpers disponibili: wso2ForceAuthCheck(), wso2TestDirect(), wso2TestIframe(), wso2TestImg(), wso2ResetTiming()');
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
