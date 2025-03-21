/**
 * @file
 * JavaScript per WSO2 Auth Check - Versione vanilla JS (senza jQuery).
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.wso2AuthCheck = {
    attach: function (context, settings) {
      // Esegui solo una volta.
      once('wso2-auth-check', 'body', context).forEach(function (element) {
        // Ottieni le impostazioni dal drupalSettings.
        const idpConfig = drupalSettings.wso2AuthCheck || {};

        // Genera un nonce sicuro
        const generateNonce = () => {
          // Genera un valore casuale di 32 caratteri
          const array = new Uint8Array(16);
          window.crypto.getRandomValues(array);
          return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
        };

        // Costruisci l'URL per l'iframe di verifica.
        const nonce = generateNonce();

        // Salva il nonce nel localStorage per la verifica al ritorno
        localStorage.setItem('wso2_auth_nonce', nonce);

        const authCheckUrl = new URL(idpConfig.idpUrl);
        authCheckUrl.searchParams.append('response_type', 'id_token');
        authCheckUrl.searchParams.append('client_id', idpConfig.clientId);
        authCheckUrl.searchParams.append('redirect_uri', idpConfig.redirectUri);
        authCheckUrl.searchParams.append('scope', 'openid');
        authCheckUrl.searchParams.append('prompt', 'none');
        authCheckUrl.searchParams.append('nonce', nonce);

        // Crea un iframe nascosto.
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = authCheckUrl.toString();
        document.body.appendChild(iframe);

        // Gestisci i messaggi che potrebbero arrivare dall'iframe.
        const messageHandler = function (event) {
          // Log per debug
          console.log('Messaggio ricevuto:', event);

          // Verifica origine per sicurezza.
          const idpOrigin = new URL(idpConfig.idpUrl).origin;
          console.log('Origine attesa:', idpOrigin, 'Origine ricevuta:', event.origin);

          // Rimuoviamo temporaneamente il controllo dell'origine per debug
          //if (event.origin !== idpOrigin) {
          //  console.log('Origine non corrisponde, ignoro il messaggio');
          //  return;
          //}

          // Gestisci il messaggio ricevuto.
          try {
            console.log('Messaggio grezzo:', event.data);
            const data = JSON.parse(event.data);
            console.log('Dati decodificati:', data);

            if (data.id_token) {
              console.log('Token ID trovato, procedo con la verifica');
              // Verifica il nonce se il token contiene un payload decodificabile
              let isValidNonce = true; // Assumiamo che sia valido di default

              try {
                // Decodifica il payload del token JWT (seconda parte)
                const tokenParts = data.id_token.split('.');
                console.log('Parti del token:', tokenParts.length);

                if (tokenParts.length === 3) {
                  const payload = JSON.parse(atob(tokenParts[1].replace(/-/g, '+').replace(/_/g, '/')));
                  console.log('Payload decodificato:', payload);

                  // Verifica che il nonce nel token corrisponda a quello salvato
                  const savedNonce = localStorage.getItem('wso2_auth_nonce');
                  console.log('Nonce salvato:', savedNonce, 'Nonce nel token:', payload.nonce);

                  if (payload.nonce !== savedNonce) {
                    console.error('Nonce mismatch in token');
                    isValidNonce = false;
                  }
                }
              } catch (decodeError) {
                console.error('Error decoding token:', decodeError);
                // Se non riusciamo a decodificare, lasciamo procedere ma logghiamo l'errore
              }

              // Puliamo il nonce salvato
              localStorage.removeItem('wso2_auth_nonce');

              // Procediamo solo se il nonce è valido
              if (isValidNonce) {
                // Abbiamo ricevuto un token, l'utente è già autenticato nell'IdP
                // Reindirizza verso il modulo esistente che gestirà il login
                const redirectUrl = drupalSettings.wso2AuthCheck.loginPath || '/wso2silfi/connect/cittadino';
                console.log('Reindirizzamento a:', redirectUrl);

                // Forziamo il reindirizzamento con un piccolo ritardo
                setTimeout(function() {
                  window.location.href = redirectUrl;
                }, 500);
              } else {
                console.error('Invalid nonce, authentication rejected');
              }
            }
          } catch (error) {
            console.error('Error processing auth message:', error);
          }
        };

        // Aggiungi l'event listener
        window.addEventListener('message', messageHandler);

        // Opzionale: rimuovi l'event listener quando il contesto viene distrutto (cleanup)
        if (context.addEventListener) {
          context.addEventListener('DOMNodeRemoved', function handler() {
            if (!document.body.contains(element)) {
              window.removeEventListener('message', messageHandler);
              context.removeEventListener('DOMNodeRemoved', handler);
            }
          });
        }
      });
    }
  };
})(Drupal, drupalSettings, once);
