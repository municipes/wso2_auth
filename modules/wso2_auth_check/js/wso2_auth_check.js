/**
 * WSO2 Auth Check - Implementazione con popup invisibile
 * Basato su pattern produzione usato da Google, Microsoft, Auth0
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
        debugLog('🚀 WSO2 Silent SSO Probe inizializzato');

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
        if (lastCheck) {
          const timeDiff = Date.now() - parseInt(lastCheck);
          const intervalMs = (parseFloat(config.checkInterval) || 3) * 60 * 1000;

          if (timeDiff < intervalMs) {
            const remainingMin = Math.ceil((intervalMs - timeDiff) / 60000);
            debugLog(`⏳ Skip controllo - prossimo tra ${remainingMin} minuti`);
            return;
          }
        }

        // Controllo se l'ultimo controllo ha fallito di recente
        const lastFailure = localStorage.getItem('wso2_auth_not_authenticated');
        if (lastFailure) {
          const failureAge = Date.now() - parseInt(lastFailure);
          const failureCooldown = 1 * 60 * 1000; // 1 minuti

          if (failureAge < failureCooldown) {
            debugLog('❌ Skip controllo - fallimento recente');
            return;
          }
        }

        /**
         * Esegue il probe SSO silenzioso usando popup invisibile
         */
        const executeSSOProbe = async function() {
        return new Promise(async (resolve, reject) => {
          debugLog('🔍 Avvio SSO probe con popup invisibile...');

          // 1. Costruisci URL di autorizzazione silenziosa
          const authUrl = new URL('/oauth2/authorize', config.idpUrl.replace('/oauth2/authorize', ''));
          const state = crypto.randomUUID ? crypto.randomUUID() : 'probe_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
          const nonce = crypto.randomUUID ? crypto.randomUUID() : 'nonce_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

          authUrl.search = new URLSearchParams({
            response_type: 'code',
            client_id: config.clientId,
            redirect_uri: config.redirectUri,
            scope: 'openid',
            prompt: 'none',
            state: state,
            nonce: nonce
          }).toString();

          debugLog('🔗 URL probe:', authUrl.toString());

          // Debug delay per permettere lettura del log
          // if (config.debug) {
             // Log strutturato e colorato
              // console.group('🔍 WSO2 Auth Check - Debug Session');
              // console.log('%c🔗 URL Authorization:', 'color: #2196F3; font-weight: bold;');
              // console.log(authUrl.toString());
              // console.log('%c🆔 State:', 'color: #4CAF50; font-weight: bold;', state);
              // console.log('%c🔢 Nonce:', 'color: #FF9800; font-weight: bold;', nonce);
              // console.log('%c⚙️ Config:', 'color: #9C27B0; font-weight: bold;', config);
              // console.groupEnd();

              // // Ferma con conferma utente
              // const shouldContinue = confirm('🛑 DEBUG MODE\n\nHai visto i log nella console?\n\nClicca OK per continuare con il popup, Annulla per fermare.');

              // if (!shouldContinue) {
              //   debugLog('❌ Debug session terminata dall\'utente');
              //   return; // FERMA TUTTO
              // }

              // debugLog('✅ Continuazione autorizzata - apertura popup...');
          // }

          // 2. Apri popup invisibile (0x0 pixel)
          const popup = window.open(
            authUrl.toString(),
              'wso2_sso_probe',
              'left=-1000,top=-1000,width=0,height=0,menubar=no,toolbar=no,resizable=no,noopener,noreferrer'
            );

            if (!popup) {
              debugLog('❌ Popup bloccato dal browser');
              reject(new Error('Popup blocked'));
              return;
            }

            // 3. Timeout di sicurezza
            const timeout = setTimeout(() => {
              debugLog('⏰ Timeout popup probe');
              popup.close();
              reject(new Error('Popup timeout'));
            }, 10000);

            // 4. Listener per il risultato
            const onMessage = (event) => {
              // Verifica origine per sicurezza
              if (event.origin !== location.origin) {
                debugLog('⚠️ Messaggio da origine non attendibile:', event.origin);
                return;
              }

              if (event.data?.type === 'wso2_sso_probe_result') {
                debugLog('📨 Risultato probe ricevuto:', event.data);

                clearTimeout(timeout);
                window.removeEventListener('message', onMessage);

                if (popup && !popup.closed) {
                  popup.close();
                }

                // Salva timestamp controllo
                localStorage.setItem('wso2_auth_last_check', Date.now().toString());

                if (event.data.code) {
                  // Utente autenticato - rimuovi marker fallimento
                  localStorage.removeItem('wso2_auth_not_authenticated');
                  resolve({
                    authenticated: true,
                    code: event.data.code,
                    state: event.data.state
                  });
                } else if (event.data.error) {
                  // Errore specifico
                  debugLog('❌ Errore da WSO2:', event.data.error, event.data.error_description);

                  if (event.data.error === 'login_required' || event.data.error === 'interaction_required') {
                    // Utente non autenticato - salva marker
                    localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
                    resolve({ authenticated: false, reason: event.data.error });
                  } else {
                    // Altri errori
                    localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
                    reject(new Error(event.data.error + ': ' + (event.data.error_description || '')));
                  }
                } else {
                  // Nessun codice e nessun errore esplicito
                  localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
                  resolve({ authenticated: false, reason: 'no_code_no_error' });
                }
              }
            };

            window.addEventListener('message', onMessage);

            // 5. Controlla se il popup si è chiuso senza comunicare (utente ha chiuso o errore)
            const checkClosed = setInterval(() => {
              if (popup.closed) {
                clearInterval(checkClosed);
                clearTimeout(timeout);
                window.removeEventListener('message', onMessage);
                debugLog('⚠️ Popup chiuso senza comunicare risultato');
                localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
                reject(new Error('Popup closed without result'));
              }
            }, 500);
          });
        };

        /**
         * Scambia il codice di autorizzazione con il backend Drupal
         */
        const exchangeCodeWithBackend = function(code, state) {
          debugLog('🔄 Scambio codice con backend...');

          return fetch('/sso/exchange', {
            method: 'POST',
            credentials: 'include',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
              code: code,
              state: state,
              csrf_token: config.csrfToken
            })
          })
          .then(response => {
            if (!response.ok) {
              throw new Error('HTTP ' + response.status);
            }
            return response.json();
          })
          .then(data => {
            debugLog('✅ Risposta backend:', data);

            if (data.logged_in) {
              // Login automatico riuscito
              showSuccessNotification();

              // Opzionale: ricarica la pagina per aggiornare l'UI
              setTimeout(() => {
                window.location.reload();
              }, 1500);

              return true;
            } else {
              debugLog('⚠️ Backend non ha confermato il login');
              return false;
            }
          })
          .catch(error => {
            debugLog('❌ Errore scambio codice:', error.message);
            // In caso di errore, prova il redirect manuale
            redirectToManualLogin();
            return false;
          });
        };

        /**
         * Reindirizzamento manuale come fallback
         */
        const redirectToManualLogin = function() {
          debugLog('🎯 Fallback: reindirizzamento manuale');

          const currentPath = window.location.pathname + window.location.search + window.location.hash;
          const loginUrl = (config.loginPath || '/wso2-auth/authorize/citizen') +
                          '?destinazione=' + encodeURIComponent(currentPath);

          showLoginNotification(loginUrl);

          setTimeout(() => {
            debugLog('🚀 Esecuzione reindirizzamento manuale:', loginUrl);
            window.location.href = loginUrl;
          }, 2000);
        };

        /**
         * Mostra notifica di successo
         */
        const showSuccessNotification = function() {
          const notification = document.createElement('div');
          notification.style.cssText = `
            position: fixed; top: 0; left: 0; right: 0; z-index: 10000;
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white; padding: 15px; text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            transform: translateY(-100%); transition: transform 0.3s ease;
          `;

          notification.innerHTML = `
            <div style="max-width: 600px; margin: 0 auto;">
              <div style="font-size: 16px; font-weight: 600;">
                ✅ Accesso automatico completato
              </div>
              <div style="font-size: 13px; opacity: 0.9; margin-top: 5px;">
                Aggiornamento della pagina in corso...
              </div>
            </div>
          `;

          document.body.appendChild(notification);

          // Animazione entrata
          setTimeout(() => {
            notification.style.transform = 'translateY(0)';
          }, 100);

          // Rimozione automatica
          setTimeout(() => {
            if (document.body.contains(notification)) {
              notification.style.transform = 'translateY(-100%)';
              setTimeout(() => {
                if (document.body.contains(notification)) {
                  document.body.removeChild(notification);
                }
              }, 300);
            }
          }, 3000);
        };

        /**
         * Mostra notifica per login manuale
         */
        const showLoginNotification = function(loginUrl) {
          const notification = document.createElement('div');
          notification.style.cssText = `
            position: fixed; top: 0; left: 0; right: 0; z-index: 10000;
            background: linear-gradient(135deg, #2196F3, #1976D2);
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
        };

        /**
         * Logica principale
         */
        const executeAuthCheck = async function() {
          try {
            debugLog('🎯 Avvio controllo autenticazione WSO2...');

            const result = await executeSSOProbe();
            debugLog('📊 Risultato probe:', result);

            if (result.authenticated && result.code) {
              // Utente autenticato - scambia il codice
              const exchangeSuccess = await exchangeCodeWithBackend(result.code, result.state);

              if (!exchangeSuccess) {
                debugLog('⚠️ Scambio codice fallito, provo redirect manuale');
                redirectToManualLogin();
              }
            } else {
              // Utente non autenticato
              debugLog('❌ Utente non autenticato:', result.reason || 'unknown');
            }

          } catch (error) {
            debugLog('❌ Errore durante controllo:', error.message);
            localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
          }
        };

        /**
         * Avvia il controllo solo dopo interazione utente (per evitare blocco popup)
         */
        const initializeAuthCheck = function() {
          debugLog('👆 Interazione utente rilevata - avvio controllo');
          executeAuthCheck();
        };

        // Production-grade silent SSO probe (pattern da Google, Microsoft, Auth0)
        const initializeSilentProbe = () => {
          debugLog('📄 Inizializzazione silent SSO probe...');

          if (document.hidden || document.visibilityState === 'prerender') {
            debugLog('📄 Pagina nascosta o prerender - skip controllo');
            return;
          }

          // Usa pointerdown per triggering rapido (prima del paint)
          debugLog('👆 Attendo prima interazione utente per silent probe...');
          document.addEventListener('pointerdown', () => {
            debugLog('👆 Prima interazione rilevata - avvio silent probe');
            executeAuthCheck();
          }, { once: true });
        };

        // Controlla se DOM è già carico
        if (document.readyState === 'loading') {
          debugLog('📄 DOM in caricamento - attendo DOMContentLoaded');
          document.addEventListener('DOMContentLoaded', initializeSilentProbe);
        } else {
          debugLog('📄 DOM già carico - avvio immediato');
          initializeSilentProbe();
        }

        // Helper debug
        if (config.debug) {
          window.wso2ForceAuthCheck = executeAuthCheck;
          window.wso2TestProbe = executeSSOProbe;
          debugLog('🔧 Debug: wso2ForceAuthCheck(), wso2TestProbe()');
        }

      });
    }
  };

})(Drupal, drupalSettings, once);
