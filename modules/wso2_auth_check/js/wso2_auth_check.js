/**
 * WSO2 Auth Check - Implementazione migliorata con fallback iframe
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

        // Controllo intervallo con debug dettagliato
        const lastCheck = localStorage.getItem('wso2_auth_last_check');
        const intervalMinutes = parseFloat(config.checkInterval) || 0.5;
        const intervalMs = intervalMinutes * 60 * 1000;

        if (lastCheck) {
          const lastCheckTime = parseInt(lastCheck);
          const timeDiff = Date.now() - lastCheckTime;

          if (timeDiff < intervalMs && !config.debug) {
            const remainingMin = Math.ceil((intervalMs - timeDiff) / 60000);
            debugLog(`⏳ Skip controllo - prossimo tra ${remainingMin} minuti`);
            return;
          }
        }

        // Controllo fallimenti recenti (versione semplificata)
        const lastFailure = localStorage.getItem('wso2_auth_not_authenticated');
        if (lastFailure && !config.debug) {
          const failureTime = parseInt(lastFailure);
          const failureAge = Date.now() - failureTime;
          const failureCooldown = 5 * 60 * 1000; // 5 minuti di cooldown

          if (failureAge < failureCooldown) {
            debugLog('❌ Skip controllo - fallimento recente');
            return;
          }
        }

        /**
         * Costruisce l'URL di autorizzazione
         */
        const buildAuthUrl = function() {
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

          return { url: authUrl.toString(), state, nonce };
        };

        /**
         * Esegue il probe SSO con metodo popup
         */
        const executePopupProbe = function() {
          return new Promise((resolve, reject) => {
            debugLog('🔍 Avvio SSO probe con popup...');

            const authData = buildAuthUrl();
            debugLog('🔗 URL probe:', authData.url);

            // Apri popup
            const popup = window.open(
              'about:blank',
              'wso2_sso_probe',
              'left=-1000,top=-1000,width=1,height=1,menubar=no,toolbar=no,location=yes,status=no,resizable=no'
            );

            if (!popup) {
              debugLog('❌ Popup bloccato - provo fallback iframe');
              reject(new Error('Popup blocked'));
              return;
            }

            // Naviga popup all'URL di autorizzazione
            try {
              popup.location.href = authData.url;
            } catch (e) {
              popup.close();
              debugLog('❌ Errore navigazione popup:', e.message);
              reject(new Error('Navigation error'));
              return;
            }

            // Timeout di sicurezza
            const timeout = setTimeout(() => {
              debugLog('⏰ Timeout popup probe');
              if (popup && !popup.closed) popup.close();
              reject(new Error('Timeout'));
            }, 10000);

            // Listener per messaggi
            const messageHandler = function(event) {
              if (event.origin !== location.origin) return;

              if (event.data?.type === 'wso2_sso_probe_result') {
                debugLog('📨 Risultato ricevuto:', event.data);

                clearTimeout(timeout);
                window.removeEventListener('message', messageHandler);
                if (popup && !popup.closed) popup.close();

                if (event.data.code) {
                  resolve({ authenticated: true, code: event.data.code });
                } else if (event.data.error === 'login_required') {
                  resolve({ authenticated: false, reason: 'login_required' });
                } else {
                  reject(new Error(event.data.error || 'Unknown error'));
                }
              }
            };

            window.addEventListener('message', messageHandler);

            // Check se popup è stato chiuso
            const checkInterval = setInterval(() => {
              if (popup.closed) {
                clearInterval(checkInterval);
                clearTimeout(timeout);
                window.removeEventListener('message', messageHandler);
                debugLog('⚠️ Popup chiuso senza risultato');
                reject(new Error('Popup closed'));
              }
            }, 500);
          });
        };

        /**
         * Esegue il probe SSO con metodo iframe (fallback)
         */
        const executeIframeProbe = function() {
          return new Promise((resolve, reject) => {
            debugLog('🔍 Avvio SSO probe con iframe (fallback)...');

            const authData = buildAuthUrl();
            debugLog('🔗 URL probe iframe:', authData.url);

            // Crea iframe nascosto
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            document.body.appendChild(iframe);

            // Timeout di sicurezza
            const timeout = setTimeout(() => {
              debugLog('⏰ Timeout iframe probe');
              if (document.body.contains(iframe)) {
                document.body.removeChild(iframe);
              }
              reject(new Error('Timeout'));
            }, 10000);

            // Gestisci messaggi
            const messageHandler = function(event) {
              if (event.origin !== location.origin) return;

              if (event.data?.type === 'wso2_sso_probe_result') {
                debugLog('📨 Risultato iframe ricevuto:', event.data);

                clearTimeout(timeout);
                window.removeEventListener('message', messageHandler);
                if (document.body.contains(iframe)) {
                  document.body.removeChild(iframe);
                }

                if (event.data.code) {
                  resolve({ authenticated: true, code: event.data.code });
                } else if (event.data.error === 'login_required') {
                  resolve({ authenticated: false, reason: 'login_required' });
                } else {
                  reject(new Error(event.data.error || 'Unknown error'));
                }
              }
            };

            window.addEventListener('message', messageHandler);

            // Imposta src dell'iframe
            try {
              iframe.src = authData.url;
            } catch (e) {
              clearTimeout(timeout);
              window.removeEventListener('message', messageHandler);
              if (document.body.contains(iframe)) {
                document.body.removeChild(iframe);
              }
              debugLog('❌ Errore iframe:', e.message);
              reject(new Error('Iframe error: ' + e.message));
            }
          });
        };

        /**
         * Reindirizza l'utente all'endpoint di autenticazione Drupal
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

          // Esegui reindirizzamento dopo breve delay
          setTimeout(() => {
            try {
              window.location.href = loginUrl;
            } catch (e) {
              window.location.replace(loginUrl);
            }
          }, 2000);
        };

        /**
         * Logica principale semplificata con fallback
         */
        const executeAuthCheck = async function() {
          try {
            debugLog('🎯 Avvio controllo autenticazione WSO2...');

            // Salva timestamp controllo
            localStorage.setItem('wso2_auth_last_check', Date.now().toString());

            // Prima prova con popup
            let result;
            try {
              result = await executePopupProbe();
            } catch (popupError) {
              debugLog('⚠️ Fallimento metodo popup:', popupError.message);
              debugLog('🔄 Provo fallback con iframe...');

              // Fallback a iframe se popup fallisce
              result = await executeIframeProbe();
            }

            debugLog('📊 Risultato finale:', result);

            if (result.authenticated) {
              // Utente autenticato - reindirizza
              debugLog('🎉 CONFERMA: Utente autenticato su WSO2');
              localStorage.removeItem('wso2_auth_not_authenticated');
              redirectToAuthEndpoint();
            } else {
              // Utente non autenticato
              debugLog('❌ Utente non autenticato:', result.reason || 'unknown');
              localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
            }

          } catch (error) {
            debugLog('❌ Errore durante controllo:', error.message);
            localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
          }
        };

        /**
         * Setup listeners per interazione utente (versione migliorata)
         */
        const setupUserInteractionListeners = function() {
          debugLog('🎯 Setup attivazione controllo autenticazione...');

          // Lista eventi da monitorare
          const events = ['mousedown', 'touchstart', 'keydown', 'scroll'];

          // Handler unificato
          const interactionHandler = function(event) {
            debugLog('👆 Interazione utente rilevata - avvio controllo');

            // Rimuovi tutti i listener
            events.forEach(eventType => {
              document.removeEventListener(eventType, interactionHandler, { capture: true });
            });

            // Esegui controllo
            executeAuthCheck();
          };

          // Aggiungi listener
          events.forEach(eventType => {
            document.addEventListener(eventType, interactionHandler, {
              once: false,  // Rimuoviamo manualmente per assicurarci che tutti vengano rimossi
              capture: true, // Fondamentale per catturare l'evento prima del blocco popup
              passive: true
            });
          });

          // Esecuzione di fallback dopo 30 secondi se nessuna interazione
          if (config.debug) {
            setTimeout(() => {
              debugLog('⏱️ Fallback timer - esecuzione controllo');
              events.forEach(eventType => {
                document.removeEventListener(eventType, interactionHandler, { capture: true });
              });
              executeAuthCheck();
            }, 30000);
          }
        };

        // Avvia il setup dopo che il DOM è pronto
        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', setupUserInteractionListeners);
        } else {
          setupUserInteractionListeners();
        }

        // Funzioni di debug
        if (config.debug) {
          window.wso2ForceAuthCheck = executeAuthCheck;
          window.wso2TestPopupProbe = executePopupProbe;
          window.wso2TestIframeProbe = executeIframeProbe;

          // Reset timing
          window.wso2ResetTiming = function() {
            localStorage.removeItem('wso2_auth_last_check');
            localStorage.removeItem('wso2_auth_not_authenticated');
            console.log('🔄 Timing reset completato');
            return 'Reset completato';
          };

          debugLog('🔧 Debug helpers disponibili: wso2ForceAuthCheck(), wso2TestPopupProbe(), wso2TestIframeProbe(), wso2ResetTiming()');
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
