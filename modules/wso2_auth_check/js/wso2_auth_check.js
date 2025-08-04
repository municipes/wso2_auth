/**
 * WSO2 Auth Check - Implementazione con popup invisibile
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

        // DEBUG: Mostra configurazione intervallo
        if (config.debug) {
          console.group('⚙️ WSO2 Auth Check - Configurazione Intervallo');
          console.log('📊 Intervallo configurato:', config.checkInterval, 'minuti');
          console.log('📊 Equivale a:', (parseFloat(config.checkInterval) || 0.5) * 60 * 1000, 'millisecondi');
          console.groupEnd();
        }

        // Controllo intervallo con debug dettagliato
        const lastCheck = localStorage.getItem('wso2_auth_last_check');
        const intervalMinutes = parseFloat(config.checkInterval) || 0.5;
        const intervalMs = intervalMinutes * 60 * 1000;

        if (lastCheck) {
          const lastCheckTime = parseInt(lastCheck);
          const timeDiff = Date.now() - lastCheckTime;
          const nextCheckTime = lastCheckTime + intervalMs;
          const timeUntilNext = nextCheckTime - Date.now();

          if (config.debug) {
            console.group('🕐 WSO2 Auth Check - Analisi Timing');
            console.log('📅 Ultimo controllo:', new Date(lastCheckTime).toLocaleString());
            console.log('📅 Prossimo controllo:', new Date(nextCheckTime).toLocaleString());
            console.log('⏱️ Tempo trascorso:', Math.round(timeDiff / 1000), 'secondi');
            console.log('⏱️ Tempo rimanente:', Math.round(timeUntilNext / 1000), 'secondi');
            console.log('✅ Controllo necessario?', timeDiff >= intervalMs ? 'SÌ' : 'NO');
            console.groupEnd();
          }

          if (timeDiff < intervalMs) {
            const remainingMin = Math.ceil(timeUntilNext / 60000);
            const remainingSec = Math.ceil(timeUntilNext / 1000);

            if (config.debug) {
              debugLog(`⏳ Skip controllo - prossimo tra ${remainingMin} minuti (${remainingSec} secondi)`);
              debugLog(`📍 Orario prossimo controllo: ${new Date(nextCheckTime).toLocaleTimeString()}`);
            } else {
              debugLog(`⏳ Skip controllo - prossimo tra ${remainingMin} minuti`);
            }
            return;
          } else {
            debugLog('✅ Intervallo scaduto - procedo con il controllo');
          }
        } else {
          debugLog('🆕 Primo controllo - nessun timestamp precedente trovato');
        }

        // Controllo se l'ultimo controllo ha fallito di recente
        const lastFailure = localStorage.getItem('wso2_auth_not_authenticated');
        if (lastFailure) {
          const failureTime = parseInt(lastFailure);
          const failureAge = Date.now() - failureTime;
          const failureCooldown = config.debug ? 30 * 1000 : 10 * 60 * 1000; // 30 sec in debug, 10 min in produzione
          const failureCooldownEnd = failureTime + failureCooldown;
          const timeUntilCooldownEnd = failureCooldownEnd - Date.now();

          if (config.debug) {
            console.group('❌ WSO2 Auth Check - Analisi Fallimenti');
            console.log('📅 Ultimo fallimento:', new Date(failureTime).toLocaleString());
            console.log('📅 Fine cooldown:', new Date(failureCooldownEnd).toLocaleString());
            console.log('⏱️ Età fallimento:', Math.round(failureAge / 1000), 'secondi');
            console.log('⏱️ Tempo fino a fine cooldown:', Math.round(timeUntilCooldownEnd / 1000), 'secondi');
            console.log('✅ Cooldown scaduto?', failureAge >= failureCooldown ? 'SÌ' : 'NO');
            console.groupEnd();
          }

          if (failureAge < failureCooldown) {
            const cooldownMin = Math.ceil(timeUntilCooldownEnd / 60000);
            const cooldownSec = Math.ceil(timeUntilCooldownEnd / 1000);

            if (config.debug) {
              debugLog(`❌ Skip controllo - cooldown fallimento attivo per altri ${cooldownMin} minuti (${cooldownSec} secondi)`);
              debugLog(`📍 Fine cooldown: ${new Date(failureCooldownEnd).toLocaleTimeString()}`);
            } else {
              console.log('Config debug? ', config.debug);
              debugLog('❌ Skip controllo - fallimento recente');
            }
            return;
          } else {
            debugLog('✅ Cooldown fallimento scaduto - procedo con il controllo');
          }
        }

        /**
         * Esegue il probe SSO silenzioso usando popup invisibile
         */
        const executeSSOProbe = function() {
          return new Promise((resolve, reject) => {
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

            // Debug stop - ferma l'esecuzione per analisi log
            if (config.debug) {
              // Super log dettagliato
              console.group('🔍 WSO2 Debug Info');
              console.log('%c🔗 URL Authorization:', 'color: #2196F3; font-weight: bold;');
              console.log(authUrl.toString());
              console.log('%c🆔 State:', 'color: #4CAF50; font-weight: bold;', state);
              console.log('%c🔢 Nonce:', 'color: #FF9800; font-weight: bold;', nonce);
              console.log('%c⚙️ Config:', 'color: #9C27B0; font-weight: bold;', config);
              console.groupEnd();

              // Ferma con conferma utente
              // const shouldContinue = confirm('🛑 DEBUG MODE\n\nHai visto i log nella console?\n\nClicca OK per continuare con il popup, Annulla per fermare.');

              // if (!shouldContinue) {
              //   debugLog('❌ Debug session terminata dall\'utente');
              //   return; // FERMA TUTTO
              // }

              debugLog('✅ Continuazione autorizzata - apertura popup...');
            }

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
              if (popup && !popup.closed) {
                popup.close();
              }
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
         * Reindirizza l'utente all'endpoint di autenticazione Drupal
         */
        const redirectToAuthEndpoint = function() {
          debugLog('🎯 Reindirizzamento a endpoint autenticazione Drupal');

          const currentPath = window.location.pathname + window.location.search + window.location.hash;
          const loginUrl = (config.loginPath || '/wso2-auth/authorize/citizen') +
                          '?destinazione=' + encodeURIComponent(currentPath);

          showLoginNotification(loginUrl);

          setTimeout(() => {
            debugLog('🚀 Esecuzione reindirizzamento:', loginUrl);
            try {
              window.top.location.href = loginUrl;
            } catch (e) {
              window.location.replace(loginUrl);
            }
          }, 2000);
        };

        /**
         * Mostra notifica per login automatico
         */
        const showLoginNotification = function(loginUrl) {
          const notification = document.createElement('div');
          notification.style.cssText = `
            position: fixed; top: 0; left: 0; right: 0; z-index: 10000;
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white; padding: 20px; text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            transform: translateY(-100%); transition: transform 0.3s ease;
          `;

          notification.innerHTML = `
            <div style="max-width: 600px; margin: 0 auto;">
              <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">
                🔐 Sessione WSO2 attiva rilevata
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
          }, 8000);
        };

        /**
         * Logica principale semplificata
         */
        const executeAuthCheck = async function() {
          try {
            debugLog('🎯 Avvio controllo autenticazione WSO2...');

            const result = await executeSSOProbe();
            debugLog('📊 Risultato probe:', result);

            if (result.authenticated && result.code) {
              // Utente autenticato - reindirizza all'endpoint Drupal
              debugLog('🎉 CONFERMA: Utente autenticato su WSO2');
              debugLog('   Codice autorizzazione ricevuto, reindirizzamento...');

              redirectToAuthEndpoint();
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

          // Rimuovi tutti gli event listener per evitare duplicati
          document.removeEventListener('pointerdown', initializeAuthCheck);
          document.removeEventListener('click', initializeAuthCheck);
          document.removeEventListener('keydown', initializeAuthCheck);
          document.removeEventListener('touchstart', initializeAuthCheck);
          document.removeEventListener('scroll', initializeAuthCheck);

          executeAuthCheck();
        };

        // Logica di attivazione migliorata
        const setupActivation = function() {
          debugLog('🎯 Setup attivazione controllo autenticazione...');

          // Controlla se il DOM è già caricato
          if (document.readyState === 'loading') {
            debugLog('📄 DOM in caricamento - attendo DOMContentLoaded');
            document.addEventListener('DOMContentLoaded', setupActivation);
            return;
          }

          // Controlla se la pagina è nascosta
          if (document.hidden || document.visibilityState === 'prerender') {
            debugLog('📄 Pagina nascosta o prerender - skip controllo');
            return;
          }

          debugLog('✅ DOM pronto - configurazione event listeners');

          // Event listeners per diverse interazioni
          const events = ['pointerdown', 'click', 'keydown', 'touchstart', 'scroll'];
          events.forEach(eventType => {
            document.addEventListener(eventType, initializeAuthCheck, { once: true, passive: true });
            debugLog(`📎 Event listener aggiunto: ${eventType}`);
          });

          // Fallback con timer più aggressivo - DISABILITATO per evitare popup block
          // let fallbackCounter = 0;
          // const fallbackInterval = setInterval(() => {
          //   fallbackCounter++;
          //   debugLog(`⏰ Fallback timer #${fallbackCounter} (ogni 2 secondi)`);

          //   // Prova ogni 2 secondi per 3 volte, poi ogni 10 secondi
          //   if (fallbackCounter <= 3 || fallbackCounter % 5 === 0) {
          //     debugLog('🚀 Attivazione fallback - esecuzione controllo');
          //     clearInterval(fallbackInterval);
          //     initializeAuthCheck();
          //   }

          //   // Stop dopo 2 minuti
          //   if (fallbackCounter >= 60) {
          //     debugLog('⏰ Fallback timeout - stop tentativi');
          //     clearInterval(fallbackInterval);
          //   }
          // }, 2000);

          // Backup immediato senza delay (per testing) - DISABILITATO per evitare popup block
          // if (config.debug) {
          //   debugLog('🔧 DEBUG: Attivazione immediata per test');
          //   clearInterval(fallbackInterval);
          //   initializeAuthCheck();
          //   // Nessun setTimeout - esecuzione immediata
          // }
        };

        // Avvia il setup
        setupActivation();

        // Helper debug avanzati
        if (config.debug) {
          window.wso2ForceAuthCheck = function() {
            debugLog('🔧 Forzatura manuale controllo autenticazione');
            executeAuthCheck();
          };

          window.wso2TestProbe = executeSSOProbe;
          window.wso2Config = config;

          // Helper per controllare timing
          window.wso2ShowTiming = function() {
            const lastCheck = localStorage.getItem('wso2_auth_last_check');
            const lastFailure = localStorage.getItem('wso2_auth_not_authenticated');
            const intervalMs = (parseFloat(config.checkInterval) || 0.5) * 60 * 1000;

            console.group('🕐 WSO2 Timing Status');

            if (lastCheck) {
              const lastCheckTime = parseInt(lastCheck);
              const nextCheckTime = lastCheckTime + intervalMs;
              const timeUntilNext = nextCheckTime - Date.now();

              console.log('📅 Ultimo controllo:', new Date(lastCheckTime).toLocaleString());
              console.log('📅 Prossimo controllo:', new Date(nextCheckTime).toLocaleString());
              console.log('⏱️ Tempo fino al prossimo:', Math.round(timeUntilNext / 1000), 'secondi');
              console.log('✅ Controllo necessario ora?', timeUntilNext <= 0 ? 'SÌ' : 'NO');
            } else {
              console.log('🆕 Nessun controllo precedente registrato');
            }

            if (lastFailure) {
              const failureTime = parseInt(lastFailure);
              const failureCooldownEnd = failureTime + (10 * 60 * 1000);
              const timeUntilCooldownEnd = failureCooldownEnd - Date.now();

              console.log('❌ Ultimo fallimento:', new Date(failureTime).toLocaleString());
              console.log('❌ Fine cooldown:', new Date(failureCooldownEnd).toLocaleString());
              console.log('⏱️ Tempo fino a fine cooldown:', Math.round(timeUntilCooldownEnd / 1000), 'secondi');
              console.log('✅ Cooldown scaduto?', timeUntilCooldownEnd <= 0 ? 'SÌ' : 'NO');
            } else {
              console.log('✅ Nessun fallimento registrato');
            }

            console.groupEnd();
          };

          // Helper per resettare timing
          window.wso2ResetTiming = function() {
            localStorage.removeItem('wso2_auth_last_check');
            localStorage.removeItem('wso2_auth_not_authenticated');
            console.log('🔄 Timing reset completato - prossimo controllo sarà immediato');
          };

          // Helper per debug eventi
          window.wso2DebugEvents = function() {
            console.group('🎯 WSO2 Debug Eventi');
            console.log('📄 Document readyState:', document.readyState);
            console.log('👁️ Document hidden:', document.hidden);
            console.log('👁️ Visibility state:', document.visibilityState);
            console.log('🖱️ Event listeners attivi: pointerdown, click, keydown, touchstart, scroll');
            console.groupEnd();
          };

          debugLog('🔧 Debug: wso2ForceAuthCheck(), wso2TestProbe(), wso2Config');
          debugLog('🔧 Timing: wso2ShowTiming(), wso2ResetTiming()');
          debugLog('🔧 Eventi: wso2DebugEvents()');
        }

      });
    }
  };

})(Drupal, drupalSettings, once);
