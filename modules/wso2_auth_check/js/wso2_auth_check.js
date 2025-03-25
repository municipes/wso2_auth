/**
 * @file
 * JavaScript semplificato per WSO2 Auth Check.
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
        // Queste impostazioni sono fornite dal modulo di autenticazione attivo (wso2silfi o wso2_auth)
        const idpConfig = drupalSettings.wso2AuthCheck || {};
        debugLog('WSO2AuthCheck inizializzato con config da modulo attivo:', idpConfig);

        // Controlla se abbiamo già verificato recentemente che l'utente NON è autenticato
        const notAuthenticated = localStorage.getItem('wso2_auth_not_authenticated');
        if (notAuthenticated) {
          const lastCheckTime = parseInt(notAuthenticated, 10);
          const currentTime = Date.now();
          const timeDiff = currentTime - lastCheckTime;

          // Converti l'intervallo da minuti a millisecondi
          const checkIntervalMs = (idpConfig.checkInterval || 3) * 60 * 1000;

          // Se l'ultimo controllo negativo è stato fatto di recente, salta la verifica
          if (timeDiff < checkIntervalMs) {
            debugLog('Verifica IdP saltata: controllo negativo recente', new Date(lastCheckTime));
            debugLog('Prossimo controllo tra ' + Math.ceil((checkIntervalMs - timeDiff) / 60000) + ' minuti');
            return;
          }
        }

        // Funzione per il reindirizzamento
        const redirectToLogin = function() {
          // Rimuovi il flag di non autenticazione quando l'utente è autenticato
          localStorage.removeItem('wso2_auth_not_authenticated');

          const redirectUrl = idpConfig.loginPath || '/wso2silfi/connect/cittadino';
          debugLog('Reindirizzamento a', redirectUrl);

          // Link cliccabile nel DOM in caso di fallimento redirect automatico
          const redirectMessage = document.createElement('div');
          redirectMessage.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; background: #ffff99; padding: 10px; text-align: center; z-index: 10000;';
          redirectMessage.innerHTML = 'Reindirizzamento in corso... <a href="' + redirectUrl + '" style="font-weight: bold;">Clicca qui se non vieni reindirizzato automaticamente</a>';
          document.body.appendChild(redirectMessage);

          // Forza il reindirizzamento in più modi per massimizzare le possibilità di successo
          try {
            // Prima prova a settare window.top.location
            window.top.location.href = redirectUrl;
          } catch (e) {
            console.error('Errore nel reindirizzamento con window.top:', e);

            // Se fallisce, prova con window.location.replace (non aggiunge alla history)
            try {
              window.location.replace(redirectUrl);
            } catch (e2) {
              console.error('Errore nel reindirizzamento con replace:', e2);

              // Ultimo tentativo con window.location.href
              window.location.href = redirectUrl;
            }
          }
        };

        // Genera un nonce sicuro
        const generateNonce = function() {
          // Genera un valore casuale di 32 caratteri
          const array = new Uint8Array(16);
          window.crypto.getRandomValues(array);
          return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
        };

        // Genera un nonce e salvalo nel localStorage per la verifica futura
        const nonce = generateNonce();
        localStorage.setItem('wso2_auth_nonce', nonce);
        debugLog('Nonce generato:', nonce);

        // Costruisci l'URL per l'iframe di verifica.
        const authCheckUrl = new URL(idpConfig.idpUrl);
        authCheckUrl.searchParams.append('response_type', 'id_token');
        authCheckUrl.searchParams.append('client_id', idpConfig.clientId);
        // authCheckUrl.searchParams.append('redirect_uri', idpConfig.redirectUri);
        authCheckUrl.searchParams.append('redirect_uri', idpConfig.redirectUri);
        authCheckUrl.searchParams.append('scope', 'openid');
        authCheckUrl.searchParams.append('prompt', 'none');
        authCheckUrl.searchParams.append('nonce', nonce);
        // authCheckUrl.searchParams.append('response_mode', 'web_message');

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
            // (potrebbe non funzionare per policy di sicurezza cross-origin)
            const iframeLocation = iframe.contentWindow.location.href;
            debugLog('URL iframe dopo caricamento:', iframeLocation);

            // Se l'URL contiene id_token, l'utente è autenticato
            if (iframeLocation.includes('id_token=')) {
              debugLog('Token trovato nell\'URL dell\'iframe');

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
          }
        };

        // Gestisci i messaggi che potrebbero arrivare dall'iframe.
        const messageHandler = function(event) {
          debugLog('Messaggio ricevuto:', event);

          try {
            let data;
            // Gestisci sia stringhe JSON che oggetti diretti
            if (typeof event.data === 'string') {
              data = JSON.parse(event.data);
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
                    redirectToLogin();
                  } else {
                    console.error('Nonce non corrispondente, possibile attacco replay');
                  }
                } else {
                  console.error('Formato token non valido');
                }
              } catch (e) {
                console.error('Errore durante la verifica del nonce:', e);
                // In caso di errore nella verifica, non procedere con il reindirizzamento
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

        // Non reindirizzare automaticamente dopo un timeout
        // Reindirizza solo se viene rilevato un id_token
      });
    }
  };
})(Drupal, drupalSettings, once);
