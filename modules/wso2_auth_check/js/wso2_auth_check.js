/**
 * Implementazione WSO2 CheckSession che FUNZIONA
 * Basata sui risultati dei test
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.wso2AuthCheckWorking = {
    attach: function (context, settings) {
      once('wso2-auth-check-working', 'body', context).forEach(function (element) {

        const debugLog = function(message, data) {
          if (idpConfig.debug) {
            console.log('[WSO2AuthCheck] ' + message, data || '');
          }
        };

        const idpConfig = drupalSettings.wso2AuthCheck || {};
        debugLog('🚀 WSO2 CheckSession inizializzato');

        // Skip se utente già loggato
        if (drupalSettings.user && drupalSettings.user.uid > 0) {
          debugLog('✅ Utente già autenticato su Drupal');
          return;
        }

        // Controllo intervallo per evitare spam
        const lastCheck = localStorage.getItem('wso2_auth_last_check');
        if (lastCheck) {
          const timeDiff = Date.now() - parseInt(lastCheck);
          const intervalMs = (parseFloat(idpConfig.checkInterval) || 3) * 60 * 1000;

          if (timeDiff < intervalMs) {
            const remainingMin = Math.ceil((intervalMs - timeDiff) / 60000);
            debugLog(`⏳ Skip controllo - prossimo tra ${remainingMin} minuti`);
            return;
          }
        }

        // Configurazione
        const clientId = idpConfig.clientId;
        const redirectUri = idpConfig.redirectUri;
        const checkSessionUrl = idpConfig.checkSessionUrl;

        if (!clientId || !redirectUri || !checkSessionUrl) {
          console.error('[WSO2AuthCheck] Configurazione incompleta');
          return;
        }

        // URL iframe con parametri (formato WSO2)
        const iframeUrl = `${checkSessionUrl}?client_id=${clientId}&redirect_uri=${encodeURIComponent(redirectUri)}`;
        debugLog('🔗 URL iframe:', iframeUrl);

        // Variabili stato
        let sessionState = null;
        let checkAttempts = 0;
        let maxAttempts = 3;

        // Funzione reindirizzamento
        const redirectToLogin = function() {
          debugLog('🎯 Avvio reindirizzamento per login automatico');

          // Aggiorna timestamp
          localStorage.setItem('wso2_auth_last_check', Date.now().toString());
          localStorage.removeItem('wso2_auth_not_authenticated');

          const currentPath = window.location.pathname + window.location.search + window.location.hash;
          const loginUrl = (idpConfig.loginPath || '/wso2-auth/authorize/citizen') +
                          '?destinazione=' + encodeURIComponent(currentPath);

          // Mostra notifica elegante
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
                🔐 Accesso rilevato
              </div>
              <div style="font-size: 14px; opacity: 0.9; margin-bottom: 15px;">
                Sessione attiva trovata - reindirizzamento in corso...
              </div>
              <a href="${loginUrl}" style="
                color: white; text-decoration: none;
                background: rgba(255,255,255,0.2);
                padding: 8px 16px; border-radius: 4px;
                border: 1px solid rgba(255,255,255,0.3);
                font-size: 13px; transition: all 0.2s;
              " onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                 onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                Clicca qui se non vieni reindirizzato automaticamente →
              </a>
            </div>
          `;

          document.body.appendChild(notification);

          // Reindirizzamento dopo breve pausa
          setTimeout(() => {
            debugLog('🚀 Esecuzione reindirizzamento:', loginUrl);
            try {
              window.top.location.href = loginUrl;
            } catch (e) {
              debugLog('Fallback reindirizzamento');
              window.location.replace(loginUrl);
            }
          }, 2000000000);
        };

        // Ottieni session_state iniziale con prompt=none
        const getInitialSessionState = function() {
          return new Promise((resolve, reject) => {
            debugLog('🔍 Ottenimento session_state iniziale...');

            const authFrame = document.createElement('iframe');
            authFrame.style.display = 'none';

            const authUrl = `https://id.055055.it:9443/oauth2/authorize?` +
              `response_type=code&client_id=${clientId}&` +
              `redirect_uri=${encodeURIComponent(redirectUri)}&` +
              `scope=openid&prompt=none&` +
              `state=initial_${Date.now()}&` +
              `nonce=${Math.random().toString(36).substr(2, 16)}`;

            authFrame.src = authUrl;

            const timeout = setTimeout(() => {
              if (document.body.contains(authFrame)) {
                document.body.removeChild(authFrame);
              }
              resolve(null); // Nessun session_state ottenuto
            }, 8000);

            authFrame.onload = function() {
              try {
                const frameUrl = authFrame.contentWindow.location.href;
                const urlParams = new URLSearchParams(frameUrl.split('?')[1] || frameUrl.split('#')[1]);
                const foundSessionState = urlParams.get('session_state');

                clearTimeout(timeout);
                if (document.body.contains(authFrame)) {
                  document.body.removeChild(authFrame);
                }

                if (foundSessionState) {
                  debugLog('✅ Session state ottenuto:', foundSessionState.substring(0, 20) + '...');
                  resolve(foundSessionState);
                } else {
                  debugLog('⚠️ Nessun session_state nell\'URL');
                  resolve(null);
                }

              } catch (e) {
                // Cross-origin - normale
                clearTimeout(timeout);
                if (document.body.contains(authFrame)) {
                  document.body.removeChild(authFrame);
                }
                resolve(null);
              }
            };

            document.body.appendChild(authFrame);
          });
        };

        // Test checksession con session_state
        const testCheckSession = function(testSessionState) {
          return new Promise((resolve, reject) => {
            debugLog('🧪 Test checksession con session_state...');

            const testFrame = document.createElement('iframe');
            testFrame.style.display = 'none';
            testFrame.src = iframeUrl;

            const messageHandler = function(event) {
              if (event.origin !== 'https://id.055055.it:9443') return;
              if (event.source !== testFrame.contentWindow) return;

              debugLog('📨 Risposta checksession test:', event.data);

              window.removeEventListener('message', messageHandler);
              if (document.body.contains(testFrame)) {
                document.body.removeChild(testFrame);
              }

              resolve(event.data);
            };

            window.addEventListener('message', messageHandler);

            testFrame.onload = function() {
              setTimeout(() => {
                const message = testSessionState ?
                  `${clientId} ${testSessionState}` :
                  `${clientId} `;

                debugLog('📤 Invio messaggio test:', message);
                testFrame.contentWindow.postMessage(message, 'https://id.055055.it:9443');

                // Timeout sicurezza
                setTimeout(() => {
                  window.removeEventListener('message', messageHandler);
                  if (document.body.contains(testFrame)) {
                    document.body.removeChild(testFrame);
                    resolve('timeout');
                  }
                }, 5000);
              }, 1000);
            };

            document.body.appendChild(testFrame);
          });
        };

        // Sequenza principale
        const executeCheck = async function() {
          try {
            debugLog('🎯 Avvio sequenza controllo WSO2...');

            // Step 1: Ottieni session_state
            sessionState = await getInitialSessionState();

            if (!sessionState) {
              debugLog('❌ Nessun session_state ottenuto - utente non autenticato');
              localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
              localStorage.setItem('wso2_auth_last_check', Date.now().toString());
              return;
            }

            // Step 2: Test checksession
            const checkResult = await testCheckSession(sessionState);

            // Step 3: Interpreta risultato
            if (checkResult === 'unchanged') {
              debugLog('🎉 SESSIONE WSO2 ATTIVA - Reindirizzamento!');
              redirectToLogin();

            } else if (checkResult === 'changed') {
              debugLog('⚠️ Session state cambiato - verifica aggiuntiva necessaria');

              // Riprova senza session_state per vedere se è un problema di formato
              const recheckResult = await testCheckSession(null);

              if (recheckResult === 'error') {
                debugLog('❌ Utente non autenticato (conferma)');
                localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
              } else {
                debugLog('🤔 Risultato incerto - assumo non autenticato');
                localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
              }

            } else if (checkResult === 'error') {
              debugLog('❌ Errore checksession - utente non autenticato');
              localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());

            } else {
              debugLog('🤷 Risposta non riconosciuta:', checkResult);
              localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
            }

            localStorage.setItem('wso2_auth_last_check', Date.now().toString());

          } catch (error) {
            debugLog('❌ Errore durante controllo:', error.message);
            localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
            localStorage.setItem('wso2_auth_last_check', Date.now().toString());
          }
        };

        // Avvia controllo
        debugLog('🚀 Avvio controllo autenticazione WSO2...');
        executeCheck();

        // Debug helpers
        if (idpConfig.debug) {
          window.wso2ForceCheck = executeCheck;
          window.wso2ForceLogin = redirectToLogin;
          debugLog('🔧 Debug: wso2ForceCheck(), wso2ForceLogin()');
        }

      });
    }
  };

})(Drupal, drupalSettings, once);
