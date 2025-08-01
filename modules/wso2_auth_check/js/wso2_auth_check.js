/**
 * @file
 * JavaScript migliorato per WSO2 Auth Check con supporto per checksession.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.wso2AuthCheck = {
    attach: function (context, settings) {
      // Esegui solo una volta.
      once('wso2-auth-check', 'body', context).forEach(function (element) {
        // Funzione di utilità per il logging con controllo debug
        const debugLog = function(message, data) {
          if (idpConfig.debug) {
            if (data !== undefined) {
              console.log('[WSO2AuthCheck] ' + message, data);
            } else {
              console.log('[WSO2AuthCheck] ' + message);
            }
          }
        };

        // Ottieni le impostazioni dal drupalSettings.
        const idpConfig = drupalSettings.wso2AuthCheck || {};
        debugLog('WSO2AuthCheck inizializzato con config da modulo attivo:', idpConfig);

        // Verifica se l'utente è già autenticato su Drupal
        const isUserLoggedIn = drupalSettings.user && drupalSettings.user.uid > 0;

        // Impedisci l'inizializzazione se l'utente è già loggato
        if (isUserLoggedIn) {
          debugLog('Utente già autenticato su Drupal (uid: ' + drupalSettings.user.uid + '). Controllo IdP saltato.');
          return;
        }

        // Controlla se abbiamo già verificato recentemente che l'utente NON è autenticato
        const notAuthenticated = localStorage.getItem('wso2_auth_not_authenticated');
        if (notAuthenticated) {
          const lastCheckTime = parseInt(notAuthenticated, 10);
          const currentTime = Date.now();
          const timeDiff = currentTime - lastCheckTime;

          // Converti l'intervallo da minuti a millisecondi
          const checkIntervalMs = (parseFloat(idpConfig.checkInterval) || 3) * 60 * 1000;

          // Se l'ultimo controllo negativo è stato fatto di recente, salta la verifica
          if (timeDiff < checkIntervalMs) {
            debugLog('Verifica IdP saltata: controllo negativo recente', new Date(lastCheckTime));
            // Visualizza il tempo rimanente in minuti e secondi
            const minutesRemaining = Math.floor((checkIntervalMs - timeDiff) / 60000);
            const secondsRemaining = Math.floor(((checkIntervalMs - timeDiff) % 60000) / 1000);
            debugLog('Prossimo controllo tra ' +
                    (minutesRemaining > 0 ? minutesRemaining + ' minuti e ' : '') +
                    secondsRemaining + ' secondi');
            return;
          }
        }

        // Funzione per il reindirizzamento
        const redirectToLogin = function() {
          // Rimuovi il flag di non autenticazione
          localStorage.removeItem('wso2_auth_not_authenticated');

          // Ottieni il path corrente per usarlo come destination
          const currentPath = window.location.pathname + window.location.search + window.location.hash;
          debugLog('Path corrente rilevato:', currentPath);

          // Costruisci l'URL di redirect
          const redirectUrl = (idpConfig.loginPath || '/wso2-auth/authorize/citizen') +
                              (currentPath ? '?destinazione=' + encodeURIComponent(currentPath) : '?nc=' + Date.now());

          debugLog('Reindirizzamento a', redirectUrl);

          // Link cliccabile in caso di fallimento redirect automatico
          const redirectMessage = document.createElement('div');
          redirectMessage.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; background: #ffff99; padding: 10px; text-align: center; z-index: 10000;';
          redirectMessage.innerHTML = 'Reindirizzamento in corso... <a href="' + redirectUrl + '" style="font-weight: bold;">Clicca qui se non vieni reindirizzato automaticamente</a>';
          document.body.appendChild(redirectMessage);

          // Reindirizzamento
          try {
            window.top.location.href = redirectUrl;
          } catch (e) {
            console.error('Errore nel reindirizzamento con window.top:', e);
            window.location.replace(redirectUrl);
          }
        };

        // Determina quale metodo di controllo sessione utilizzare
        const checkSessionMethod = idpConfig.checkSessionMethod || 'iframe';
        debugLog('Metodo di controllo sessione:', checkSessionMethod);

        if (checkSessionMethod === 'checksession' && idpConfig.checkSessionUrl) {
          // ===============================
          // NUOVO METODO: OIDC checksession
          // ===============================

          debugLog('Utilizzo del metodo checksession OIDC');

          // Genera un ID casuale per questo client
          const clientId = idpConfig.clientId;
          debugLog('Client ID:', clientId);

          // Costruisci l'URL per l'iframe di controllo sessione
          const checkSessionUrl = new URL(idpConfig.checkSessionUrl);
          checkSessionUrl.searchParams.append('client_id', clientId);
	        checkSessionUrl.searchParams.append('redirect_uri', idpConfig.redirectUri);
          // Aggiungi un parametro per evitare la cache del browser
          checkSessionUrl.searchParams.append('nc', Date.now().toString());

          debugLog('URL iframe checkSession:', checkSessionUrl.toString());

          // Crea l'iframe di controllo sessione
          const opFrame = document.createElement('iframe');
          opFrame.style.display = 'none';  // Nascondi l'iframe
          opFrame.src = checkSessionUrl.toString();

          // Inizializza variabili per la gestione della sessione  
          let sessionState = null; // Inizializza con null per primo controllo
          let checkSessionInterval = null;
          let initialized = false;
          let retryCount = 0;
          const maxRetries = 2;

          // Funzione per verificare lo stato della sessione
          const checkSessionState = function() {
            if (opFrame.contentWindow) {
              try {
                if (!initialized) {
                  debugLog('Tentativo di inizializzazione #' + (retryCount + 1));
                  
                  // Per il primo controllo, generiamo un session_state dummy
                  if (sessionState === null) {
                    // Genera un session_state dummy per il primo controllo
                    sessionState = 'dummy_session_state.' + Math.random().toString(36).substr(2, 9);
                    debugLog('Generato sessionState dummy per primo controllo:', sessionState);
                  }

                  // Invia il messaggio nel formato atteso: "client_id session_state"
                  const message = clientId + ' ' + sessionState;
                  debugLog('Invio messaggio di controllo sessione:', message);
                  opFrame.contentWindow.postMessage(message, 'https://id.055055.it:9443');

                  retryCount++;
                  
                  if (retryCount >= maxRetries) {
                    initialized = true; // Considera inizializzato dopo i tentativi
                  }
                } else {
                  // Controlli successivi con sessionState aggiornato
                  const message = clientId + ' ' + sessionState;
                  debugLog('Invio messaggio di controllo sessione (controllo periodico):', message);
                  opFrame.contentWindow.postMessage(message, 'https://id.055055.it:9443');
                }
              } catch (e) {
                debugLog('Errore durante il controllo della sessione:', e);
              }
            } else {
              debugLog('contentWindow non disponibile');
            }
          };

          // Gestisci i messaggi ricevuti dall'iframe
          const messageHandler = function(event) {
            // Per il debugging, logga tutti i messaggi ricevuti
            debugLog('Messaggio ricevuto (da ' + event.origin + '):', event.data);

            // Verifica origine del messaggio
            if (event.origin !== 'https://id.055055.it:9443') {
              debugLog('Origine messaggio non valida:', event.origin);
              return;
            }

            const message = event.data;
            
            // Segna come inizializzato dopo il primo messaggio ricevuto
            if (!initialized) {
              initialized = true;
              debugLog('Comunicazione checksession inizializzata');
            }

            // Gestiamo diversi possibili formati di risposta
            if (message === 'unchanged') {
              debugLog('Stato sessione: invariato - utente non autenticato su WSO2');
              initialized = true;
              // Memorizza che l'utente non è autenticato per evitare controlli troppo frequenti
              localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
              
              // FERMA il controllo periodico - abbiamo la risposta
              if (checkSessionInterval) {
                clearInterval(checkSessionInterval);
                checkSessionInterval = null;
                debugLog('Controllo periodico checksession fermato - nessuna autenticazione');
              }
            } else if (message === 'changed') {
              debugLog('Stato sessione: cambiato - l\'utente è autenticato su WSO2');
              initialized = true;
              
              // FERMA il controllo periodico per evitare loop
              if (checkSessionInterval) {
                clearInterval(checkSessionInterval);
                checkSessionInterval = null;
                debugLog('Controllo periodico checksession fermato');
              }
              
              // L'utente è autenticato su WSO2, reindirizza al login automatico
              debugLog('Utente autenticato su WSO2 - avvio login automatico su Drupal');
              redirectToLogin();
            } else if (message === 'error') {
              debugLog('Stato sessione: errore');
              initialized = true;
              // Memorizza il fallimento per evitare check troppo frequenti
              localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
              
              // FERMA il controllo periodico in caso di errore
              if (checkSessionInterval) {
                clearInterval(checkSessionInterval);
                checkSessionInterval = null;
                debugLog('Controllo periodico checksession fermato per errore');
              }
            } else if (typeof message === 'string') {
              // Altri possibili formati
              if (message.startsWith('init:')) {
                sessionState = message.substring(5);
                debugLog('Stato sessione inizializzato:', sessionState);
                initialized = true;
              } else if (message.includes('::')) {
                // Altro possibile formato di inizializzazione
                const parts = message.split('::');
                if (parts.length > 1) {
                  sessionState = parts[1];
                  debugLog('Stato sessione inizializzato (formato alternativo):', sessionState);
                  initialized = true;
                }
              } else if (message.includes(' ')) {
                // Potrebbe essere un messaggio di risposta nel formato "clientId sessionState"
                const parts = message.split(' ');
                if (parts.length > 1 && parts[0] === clientId) {
                  sessionState = parts[1];
                  debugLog('Stato sessione aggiornato:', sessionState);
                  initialized = true;
                }
              }

              // Se siamo riusciti a inizializzare con un messaggio
              if (initialized && !checkSessionInterval) {
                debugLog('Avvio controllo periodico della sessione');
                checkSessionInterval = setInterval(checkSessionState, 3000);
              }
            }
          };

          // Aggiungi l'event listener per i messaggi - usa "*" per diagnosi
          window.addEventListener('message', messageHandler);

          // Gestisci il caricamento dell'iframe
          opFrame.onload = function() {
            debugLog('Iframe checkSession caricato');
            // Prova a ispezionare il contenuto dell'iframe per debug
            try {
              setTimeout(function() {
                if (opFrame.contentDocument && opFrame.contentDocument.body) {
                  debugLog('Contenuto iframe checksession:', opFrame.contentDocument.body.innerHTML);
                } else {
                  debugLog('Impossibile accedere al contenuto iframe (cross-origin)');
                }
              }, 1000);
            } catch (e) {
              debugLog('Errore accesso contenuto iframe:', e);
            }

            // Avvia la sequenza di inizializzazione
            setTimeout(function() {
              // Avvia il controllo periodico
              if (!checkSessionInterval) {
                checkSessionInterval = setInterval(checkSessionState, 3000);
              }
            }, 1000); // Piccolo delay per assicurarsi che l'iframe sia completamente caricato
          };

          // Aggiungi l'iframe al DOM
          document.body.appendChild(opFrame);

          // Cleanup quando la pagina viene abbandonata
          window.addEventListener('beforeunload', function() {
            if (checkSessionInterval) {
              clearInterval(checkSessionInterval);
            }
          });

        } else {
          // ===============================
          // METODO TRADIZIONALE: prompt=none
          // ===============================

          // [Codice esistente per il metodo prompt=none...]
          debugLog('Utilizzo del metodo iframe tradizionale con prompt=none');

          // Genera un nonce sicuro
          const generateNonce = function() {
            const array = new Uint8Array(16);
            window.crypto.getRandomValues(array);
            return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
          };

          // Genera un nonce e salvalo nel localStorage per la verifica futura
          const nonce = generateNonce();
          localStorage.setItem('wso2_auth_nonce', nonce);

          // Costruisci l'URL per l'iframe di verifica.
          const authCheckUrl = new URL(idpConfig.idpUrl);
          authCheckUrl.searchParams.append('response_type', 'id_token');
          authCheckUrl.searchParams.append('client_id', idpConfig.clientId);
          authCheckUrl.searchParams.append('redirect_uri', idpConfig.redirectUri);
          authCheckUrl.searchParams.append('scope', 'openid');
          authCheckUrl.searchParams.append('prompt', 'none');
          authCheckUrl.searchParams.append('nonce', nonce);
          // Aggiungi un parametro per evitare la cache del browser
          authCheckUrl.searchParams.append('nc', Date.now().toString());

          debugLog('URL iframe:', authCheckUrl.toString());

          // Crea un iframe per il controllo autenticazione
          const iframe = document.createElement('iframe');
          iframe.style.display = 'none';  // Nascondi l'iframe
          iframe.src = authCheckUrl.toString();

          // Gestisci eventi dell'iframe
          iframe.onload = function() {
            debugLog('Iframe caricato');

            // Dopo il caricamento dell'iframe, controlla il suo URL
            try {
              // Se possiamo accedere al contenuto dell'iframe, controlla l'URL
              const iframeLocation = iframe.contentWindow.location.href;
              debugLog('URL iframe dopo caricamento:', iframeLocation);

              // Se l'URL contiene id_token, l'utente è autenticato
              if (iframeLocation.includes('id_token=')) {
                debugLog('Parametro idToken trovato nell\'URL dell\'iframe');

                // Estrai il token dall'URL
                const urlParams = new URLSearchParams(iframeLocation.split('#')[1]);
                const idToken = urlParams.get('id_token');

                if (idToken) {
                  // Verifica il nonce
                  try {
                    // Decodifica il payload del token JWT (seconda parte)
                    const tokenParts = idToken.split('.');
                    if (tokenParts.length === 3) {
                      const payload = JSON.parse(atob(tokenParts[1].replace(/-/g, '+').replace(/_/g, '/')));
                      debugLog('Payload token:', payload);

                      // Recupera il nonce salvato
                      const savedNonce = localStorage.getItem('wso2_auth_nonce');
                      debugLog('Confronto nonce - Salvato:', savedNonce, 'Nel token:', payload.nonce);

                      // Verifica che il nonce corrisponda
                      if (payload.nonce === savedNonce) {
                        debugLog('Nonce verificato correttamente');
                        localStorage.removeItem('wso2_auth_nonce'); // Pulisci il nonce
                        debugLog('Utente autenticato su WSO2 (iframe) - avvio login automatico su Drupal');
                        redirectToLogin();
                      } else {
                        console.error('Nonce non corrispondente, possibile attacco replay');
                      }
                    } else {
                      console.error('Formato token non valido');
                    }
                  } catch (e) {
                    console.error('Errore durante la verifica del nonce:', e);
                  }
                }
              } else {
                debugLog('Nessun token trovato nell\'URL dell\'iframe, l\'utente non è autenticato nell\'IdP');
                // Memorizza il timestamp del controllo negativo
                localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
              }
            } catch (e) {
              debugLog('Non posso accedere al contenuto dell\'iframe (normale per sicurezza cross-origin):', e);
              // Memorizza il timestamp del controllo negativo dopo un errore
              localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
            }
          };

          // Gestisci i messaggi che potrebbero arrivare dall'iframe.
          const messageHandler = function(event) {
            debugLog('Messaggio ricevuto:', event);

            try {
              let data;
              // Gestisci sia stringhe JSON che oggetti diretti
              if (typeof event.data === 'string') {
                try {
                  data = JSON.parse(event.data);
                } catch (e) {
                  // Se non è JSON, usa il dato così com'è
                  data = { message: event.data };
                }
              } else {
                data = event.data;
              }

              debugLog('Dati decodificati:', data);

              // Se abbiamo un id_token, l'utente è autenticato
              if (data && data.id_token) {
                debugLog('Token ID trovato nel messaggio');

                // Verifica il nonce nel token
                try {
                  // Decodifica il payload del token JWT (seconda parte)
                  const tokenParts = data.id_token.split('.');
                  if (tokenParts.length === 3) {
                    const payload = JSON.parse(atob(tokenParts[1].replace(/-/g, '+').replace(/_/g, '/')));
                    debugLog('Payload token:', payload);

                    // Recupera il nonce salvato
                    const savedNonce = localStorage.getItem('wso2_auth_nonce');
                    debugLog('Confronto nonce - Salvato:', savedNonce, 'Nel token:', payload.nonce);

                    // Verifica che il nonce corrisponda
                    if (payload.nonce === savedNonce) {
                      debugLog('Nonce verificato correttamente');
                      localStorage.removeItem('wso2_auth_nonce'); // Pulisci il nonce
                      debugLog('Utente autenticato su WSO2 (messaggio) - avvio login automatico su Drupal');
                      redirectToLogin();
                    } else {
                      console.error('Nonce non corrispondente, possibile attacco replay');
                    }
                  } else {
                    console.error('Formato token non valido');
                  }
                } catch (e) {
                  console.error('Errore durante la verifica del nonce:', e);
                }
              } else {
                debugLog('Nessun token nel messaggio, l\'utente non è autenticato nell\'IdP');
                // Memorizza il timestamp del controllo negativo
                localStorage.setItem('wso2_auth_not_authenticated', Date.now().toString());
              }
            } catch (error) {
              console.error('Errore processamento messaggio:', error);
            }
          };

          // Aggiungi l'event listener per i messaggi
          window.addEventListener('message', messageHandler);

          // Aggiungi l'iframe al DOM
          document.body.appendChild(iframe);

        } // Fine else (metodo tradizionale)
      });
    }
  };
})(Drupal, drupalSettings, once);
