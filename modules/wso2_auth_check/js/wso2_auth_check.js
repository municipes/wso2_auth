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
              agEntityId: config.agEntityId || 'FIRENZE',
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
            const fullUrl = authUrl.toString() + '?' + urlParams.toString()
