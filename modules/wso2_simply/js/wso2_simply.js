(function (drupalSettings) {
  'use strict';

  // Verifica che le impostazioni siano disponibili
  if (!drupalSettings.wso2_simply) {
    return;
  }

  const config = drupalSettings.wso2_simply;

  /**
   * Costruisce l'URL per la richiesta OAuth2
   */
  function buildOAuthUrl() {
    const params = new URLSearchParams({
      response_type: 'code',
      client_id: config.client_id,
      redirect_uri: config.redirect_uri,
      scope: config.scope,
      prompt: 'none'
    });

    return config.oauth_url + '?' + params.toString();
  }

  /**
   * Estrae parametri dall'URL di location
   */
  function getUrlParams(url) {
    const urlObj = new URL(url);
    return Object.fromEntries(urlObj.searchParams.entries());
  }

  /**
   * Ottiene il path corrente della pagina per il parametro destination
   */
  function getCurrentDestination() {
    return window.location.pathname + window.location.search;
  }

  /**
   * Costruisce l'URL di redirect finale
   */
  function buildRedirectUrl(destination) {
    const params = new URLSearchParams({
      destination: destination
    });

    return config.base_redirect_url + '?' + params.toString();
  }

  /**
   * Esegue la richiesta OAuth2 usando fetch
   */
  function checkAuthentication() {
    const oauthUrl = buildOAuthUrl();

    fetch(oauthUrl, {
      method: 'GET',
      redirect: 'manual', // Non seguire automaticamente i redirect
      credentials: 'include' // Importante: include i cookie di sessione
    })
    .then(function(response) {
      // Controllo se è un redirect (status 301, 302, etc.)
      if (response.status >= 300 && response.status < 400) {
        const location = response.headers.get('Location');

        if (!location) {
          console.log('WSO2 Simply: Nessun header Location trovato');
          return;
        }

        const params = getUrlParams(location);

        // Caso 1: Errore - utente non autenticato
        if (params.error) {
          console.log('WSO2 Simply: Utente non autenticato, errore:', params.error);
          return;
        }

        // Caso 2: Code presente - utente autenticato
        if (params.code) {
          console.log('WSO2 Simply: Utente autenticato, code:', params.code);

          const destination = getCurrentDestination();
          const redirectUrl = buildRedirectUrl(destination);

          console.log('WSO2 Simply: Redirect a:', redirectUrl);
          window.location.href = redirectUrl;
        }
      } else {
        console.log('WSO2 Simply: Risposta non prevista, status:', response.status);
      }
    })
    .catch(function(error) {
      console.error('WSO2 Simply: Errore nella richiesta OAuth2:', error);
    });
  }

  // Avvia il controllo autenticazione quando il DOM è pronto
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', checkAuthentication);
  } else {
    checkAuthentication();
  }

})(drupalSettings);
