/**
 * Implementazione corretta WSO2 con verifica reale autenticazione
 * "unchanged" non significa autenticato - significa solo session_state valido
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.wso2AuthCheckFixed = {
    attach: function (context, settings) {
      once('wso2-auth-check-fixed', 'body', context).forEach(function (element) {

        const debugLog = function(message, data) {
          if (idpConfig.debug) {
            console.log('[WSO2AuthCheck] ' + message, data || '');
          }
        };

        const idpConfig = drupalSettings.wso2AuthCheck || {};
        debugLog('🚀 WSO2 CheckSession inizializzato (logica corretta)');

        // Skip se utente già loggato
        if (drupalSettings.user && drupalSettings.user.uid > 0) {
          debugLog('✅ Utente già autenticato su Drupal');
          return;
        }

        // Controllo intervallo
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

        // Funzione reindirizzamento
        const redirectToLogin = function() {
          debugLog('🎯 Avvio reindirizzamento per login automatico');

          localStorage.setItem('wso2_auth_last_check', Date.now().toString());
          localStorage.removeItem('wso2_auth_not_authenticated');

          const currentPath = window.location.pathname + window.location.search + window.location.hash;
          const loginUrl = (idpConfig.loginPath || '/wso2-auth/authorize/citizen') +
                          '?destinazione=' + encodeURIComponent(currentPath);

          // Notifica utente
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

          setTimeout(() => {
            debugLog('🚀 Esecuzione reindirizzamento:', loginUrl);
            try {
              window.top.location.href = loginUrl;
            } catch (e) {
              window.location.replace(loginUrl);
            }
          }, 2000);
        };

        // VERIFICA REALE AUTENTICAZIONE (il punto chiave!)
        const verifyActualAuthentication = function() {
          return new Promise((resolve, reject) => {
            debugLog('🔍 Verifica REALE autenticazione WSO2...');

            const authFrame = document.createElement('iframe');
            authFrame.style.display = 'none';

            // Usa response_type=code (non id_token) per evitare problemi
            const authUrl = `https://id.055055.it:9443/oauth2/authorize?` +
              `response_type=code&client_id=${clientId}&` +
              `redirect_uri=${encodeURIComponent(redirectUri)}&` +
              `scope=openid&prompt=none&` +
              `state=verify_${Date.now()}&` +
              `nonce=${Math.random().toString(36).substr(2, 16)}`;

            authFrame.src = authUrl;

            const timeout = setTimeout(() => {
              if (document.body.contains(authFrame)) {
                document.body.removeChild(authFrame);
              }
              debugLog('⏰ Timeout verifica autenticazione');
              resolve({ authenticated: false, reason: 'timeout' });
            }, 10000);

            authFrame.onload = function() {
              try {
                const frameUrl = authFrame.contentWindow.location.href;
                debugLog('🔗 URL frame autenticazione:', frameUrl);

                clearTimeout(timeout);
                if (document.body.contains(authFrame)) {
                  document.body.removeChild(authFrame);
                }

                if (frameUrl.includes('code=')) {
                  // SUCCESS: Utente autenticato, authorization code ricevuto
                  const urlParams = new URLSearchParams(frameUrl.split('?')[1] || frameUrl.split('#')[1]);
                  const authCode = urlParams.get('code');
                  const sessionState = urlParams.get('session_state');

                  debugLog('✅ UTENTE AUTENTICATO - Authorization code ricevuto');
                  debugLog('🔑 Auth code:', authCode ? authCode.substring(0, 20) + '...' : 'Non trovato');
                  debugLog('🎫 Session state:', sessionState ? sessionState.substring(0, 20) + '...' : 'Non trovato');

                  resolve({
                    authenticated: true,
                    authCode: authCode,
                    sessionState: sessionState,
                    reason: 'authorization_code_received'
                  });

                } else if (frameUrl.includes('error=login_required') ||
                           frameUrl.includes('error=interaction_required')) {
                  // Utente NON autenticato
                  debugLog('❌ Utente NON autenticato (login_required)');
                  resolve({ authenticated: false, reason: 'login_required' });

                } else if (frameUrl.includes('error=')) {
                  // Altri errori
                  const urlParams = new URLSearchParams(frameUrl.split('?')[1] || frameUrl.split('#')[1]);
                  const error = urlParams.get('error');
                  const errorDesc = urlParams.get('error_description');

                  debugLog('⚠️ Errore autenticazione:', error, errorDesc);
                  resolve({ authenticated: false, reason: error, description: errorDesc });

                } else {
                  // URL non riconosciuto
                  debugLog('🤔 URL non riconosciuto:', frameUrl);
                  resolve({ authenticated: false, reason: 'unknown_response' });
                }

              } catch (e) {
                // Cross-origin - non possiamo leggere l'URL
                debugLog('🔒 Cross-origin - impossibile leggere URL frame');

                clearTimeout(timeout);
                if (document.body.contains(authFrame)) {
                  document.body.removeChild(authFrame);
                }

                // In caso di cross-origin, assumiamo non autenticato per sicurezza
                resolve({ authenticated: false, reason: 'cross_origin_blocked' });
              }
            };

            authFrame.onerror = function() {
              clearTimeout(timeout);
              if (document.body.contains(authFrame)) {
                document.body.removeChild(authFrame);
              }
              debugLog('❌ Errore caricamento frame autenticazione');
              resolve({ authenticated: false, reason: 'frame_load_error' });
            };

            document.body.appendChild(authFrame);
          });
        };

        // LOGICA PRINCIPALE CORRETTA
        const executeAuthCheck = async function() {
          try {
            debugLog('🎯 Avvio controllo autenticazione WSO2...');

            // STEP 1: Verifica REALE autenticazione (non checksession)
            const authResult = await verifyActualAuthentication();

            debugLog('📊 Risultato verifica autenticazione:', authResult);

            if (authResult.authenticated) {
              // UTENTE REALMENTE AUTENTICATO
              debugLog('🎉 CONFERMA: Utente autenticato su WSO2');
              debugLog('   Motivo:', authResult.reason);

              // Salva session state se presente
              if (authResult.sessionState) {
                localStorage.setItem('wso2_session_state', authResult.sessionState);
              }

              // REINDIRIZZA per login automatico
              redirectToLogin();

            } else {
              // UTENTE NON AUTENTICATO
              debugLog('❌ CONFERMA: Utente NON autenticato su WSO2');
              debugLog('   Motivo:', authResult.reason);
              debugLog('   Descrizione:', authResult.description || 'N/A');

              // Segna come non autenticato
              localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
              localStorage.setItem('wso2_auth_last_check', Date.now().toString());

              debugLog('✅ Controllo completato - nessuna azione richiesta');
            }

          } catch (error) {
            debugLog('❌ Errore durante controllo autenticazione:', error.message);
            localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
            localStorage.setItem('wso2_auth_last_check', Date.now().toString());
          }
        };

        // CONTROLLO OPZIONALE CHECKSESSION (per monitoraggio continuo)
        const monitorSessionChanges = function() {
          // Questo è utile DOPO aver confermato l'autenticazione
          // per monitorare cambiamenti di sessione
          const sessionState = localStorage.getItem('wso2_session_state');

          if (!sessionState) {
            debugLog('⚠️ Nessun session_state salvato - skip monitoraggio');
            return;
          }

          debugLog('👁️ Avvio monitoraggio cambiamenti sessione...');

          const iframeUrl = `${checkSessionUrl}?client_id=${clientId}&redirect_uri=${encodeURIComponent(redirectUri)}`;
          const monitorFrame = document.createElement('iframe');
          monitorFrame.style.display = 'none';
          monitorFrame.src = iframeUrl;

          const messageHandler = function(event) {
            if (event.origin !== 'https://id.055055.it:9443') return;
            if (event.source !== monitorFrame.contentWindow) return;

            debugLog('📨 Monitoraggio sessione:', event.data);

            if (event.data === 'changed') {
              debugLog('⚠️ Session state cambiato - riverifica autenticazione');
              // Riavvia controllo completo
              executeAuthCheck();
            }
          };

          window.addEventListener('message', messageHandler);

          monitorFrame.onload = function() {
            const checkInterval = setInterval(() => {
              const message = `${clientId} ${sessionState}`;
              monitorFrame.contentWindow.postMessage(message, 'https://id.055055.it:9443');
            }, 30000); // Controlla ogni 30 secondi

            // Cleanup dopo 5 minuti
            setTimeout(() => {
              clearInterval(checkInterval);
              window.removeEventListener('message', messageHandler);
              if (document.body.contains(monitorFrame)) {
                document.body.removeChild(monitorFrame);
              }
            }, 300000);
          };

          document.body.appendChild(monitorFrame);
        };

        // AVVIA CONTROLLO PRINCIPALE
        debugLog('🚀 Avvio sequenza controllo corretta...');
        executeAuthCheck();

        // Debug helpers
        if (idpConfig.debug) {
          window.wso2ForceAuthCheck = executeAuthCheck;
          window.wso2ForceLogin = redirectToLogin;
          window.wso2StartMonitoring = monitorSessionChanges;
          debugLog('🔧 Debug: wso2ForceAuthCheck(), wso2ForceLogin(), wso2StartMonitoring()');
        }

      });
    }
  };

})(Drupal, drupalSettings, once);
