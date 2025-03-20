Ho analizzato attentamente il modulo WSO2 Authentication per Drupal. Ecco un README completo basato sul codice presente su GitHub:

# WSO2 Authentication Module per Drupal

## Panoramica

Il modulo WSO2 Authentication fornisce l'integrazione con WSO2 Identity Server per l'autenticazione degli utenti Drupal tramite OAuth2. Consente agli utenti di accedere al tuo sito Drupal utilizzando la loro identità WSO2, con supporto specifico per il sistema di autenticazione SPID (Sistema Pubblico di Identità Digitale).

## Caratteristiche

- Autenticazione OAuth2 con WSO2 Identity Server
- Supporto per ambienti di staging e produzione
- Registrazione automatica degli utenti
- Mappatura dei campi tra WSO2 e i campi utente di Drupal
- Mappatura di campi personalizzati come codice fiscale e numero di cellulare
- Assegnazione automatica di ruoli per i nuovi utenti
- Capacità di Single Sign-On (SSO)
- Capacità di Single Logout (SLO)
- Integrazione con il form di login di Drupal
- Validazione della sessione e controlli di sicurezza
- Opzione per saltare la verifica SSL in ambienti di sviluppo
- Modalità di autenticazione separata per cittadini e operatori

## Funzionalità Single Sign-On (SSO)

Il modulo supporta due principali scenari SSO:

1. **Navigazione standard**: Quando un utente arriva sul sito Drupal come inizio navigazione, non viene reindirizzato automaticamente all'autenticazione se non effettua un login esplicito.

2. **Autenticazione tramite altro sito**: Se l'utente si è già autenticato presso l'IdP WSO2 tramite un altro sito, quando arriva sul sito Drupal viene automaticamente autenticato senza alcun intervento utente, in modo completamente trasparente.

## Requisiti

- Drupal 10 o 11
- Modulo [External Auth](https://www.drupal.org/project/externalauth)
- WSO2 Identity Server con supporto OAuth2

## Installazione

1. Scarica e installa il modulo come faresti con qualsiasi modulo Drupal:

```bash
composer require municipes/wso2_auth
drush en wso2_auth
```

2. Configura le impostazioni del modulo in Amministrazione » Configurazione » Persone » Impostazioni WSO2 Authentication (/admin/config/people/wso2-auth).

## Configurazione

### Configurazione del WSO2 Identity Server

Prima di configurare il modulo, è necessario impostare un'applicazione OAuth2 nel WSO2 Identity Server:

1. Accedi alla console di gestione del WSO2 Identity Server.
2. Registra una nuova applicazione OAuth2 con le seguenti impostazioni:
   - URL di callback: `https://tuositodrupal.it/wso2-auth/callback`
   - Tipi di grant: Authorization Code
   - Scope richiesti: openid

3. Prendi nota del Client ID e del Client Secret forniti da WSO2.

### Configurazione del Modulo

#### Impostazioni Generali

1. Vai a Amministrazione » Configurazione » Persone » Impostazioni WSO2 Authentication (/admin/config/people/wso2-auth).
2. Abilita il modulo WSO2 Authentication.
3. Scegli se utilizzare l'ambiente di staging o produzione.
4. Inserisci le informazioni del server WSO2:
   - URL del server di autenticazione: L'URL dell'endpoint OAuth2 del tuo WSO2 Identity Server (es., https://id.055055.it:9443/oauth2)
   - ID entità (agEntityId): L'ID entità da utilizzare per l'autenticazione (es., FIRENZE)
   - ID entità (comEntityId): Il parametro aggiuntivo comEntityId

5. Configura le impostazioni di aspetto:
   - Abilita logo SPID nel form di login: Mostra o nascondi il logo SPID nel form di login

6. Configura le impostazioni avanzate:
   - Auto-redirect al login WSO2: Reindirizza automaticamente gli utenti anonimi alla pagina di login WSO2
   - Abilita auto-login (Single Sign-On): Autentica automaticamente gli utenti già loggati in WSO2 quando visitano il sito
   - Abilita modalità debug: Registra informazioni aggiuntive per il debugging
   - Salta verifica SSL: Salta la verifica del certificato SSL (solo per sviluppo)

#### Impostazioni Cittadini

1. Configura le informazioni OAuth2:
   - Client ID OAuth2: Il client ID ottenuto da WSO2
   - Client Secret OAuth2: Il client secret ottenuto da WSO2
   - Scope OAuth2: Lo scope richiesto per l'autenticazione (es., openid)

2. Configura le impostazioni utente:
   - Auto-registrazione utenti: Registra automaticamente i nuovi utenti quando si autenticano con WSO2
   - Ruolo da assegnare: Il ruolo da assegnare ai nuovi utenti registrati
   - Ruoli da controllare: Ruoli da verificare quando un utente effettua l'accesso

3. Configura le mappature dei campi:
   - Mappa i campi WSO2 ai campi utente Drupal
   - Configura le mappature per codice fiscale e numero di cellulare se disponibili

#### Impostazioni Operatori

1. Abilita l'autenticazione per gli operatori.
2. Configura le informazioni OAuth2 specifiche per gli operatori.
3. Configura i parametri specifici per gli operatori (ente, app).
4. Configura le regole di mappatura dei ruoli basate sulle funzioni dell'operatore.
5. Configura l'URL del servizio privilegi per gli operatori.

## Utilizzo

### Login Utente

Una volta configurato, gli utenti possono accedere al tuo sito Drupal utilizzando le loro credenziali WSO2 in due modi:

1. Attraverso il form di login standard di Drupal, che includerà un pulsante "Accedi con WSO2".
2. Visitando il percorso `/wso2-auth/authorize`, che li reindirizzerà alla pagina di login WSO2.

### Auto-login (Single Sign-On)

Se l'utente è già autenticato in WSO2 (tramite un altro sito che utilizza lo stesso IdP), quando visita il tuo sito Drupal verrà automaticamente autenticato se hai abilitato la funzione di auto-login. Questo processo è completamente trasparente per l'utente.

### Logout Utente

Quando gli utenti effettuano il logout dal tuo sito Drupal, verranno anche disconnessi dalla loro sessione WSO2 se fanno clic sul link "Logout" o visitano il percorso `/wso2-auth/logout`.

## Estendere il Modulo

Il modulo fornisce diversi hook che permettono ad altri moduli di alterarne il comportamento:

- `hook_wso2_auth_userinfo_alter(&$user_data)`: Altera i dati utente ricevuti da WSO2 prima dell'autenticazione.
- `hook_wso2_auth_post_login($account, $user_data)`: Reagisce a un'autenticazione riuscita.
- `hook_wso2_auth_authorization_url_alter(&$url, $params)`: Altera l'URL di autorizzazione.
- `hook_wso2_auth_token_request_alter(&$params, $code)`: Altera i parametri della richiesta del token.
- `hook_wso2_auth_userinfo_request_alter(&$options, $access_token)`: Altera la richiesta di informazioni utente.
- `hook_wso2_auth_logout_url_alter(&$url, $params)`: Altera l'URL di logout.

Vedi `wso2_auth.api.php` per ulteriori informazioni.

## Risoluzione dei Problemi

Se riscontri problemi con il modulo, prova quanto segue:

1. Abilita la modalità debug nelle impostazioni del modulo per ottenere più informazioni nei log di Drupal.
2. Controlla i log di Drupal per eventuali errori (Amministrazione » Rapporti » Messaggi recenti).
3. Verifica che il tuo WSO2 Identity Server sia configurato correttamente e accessibile.
4. Assicurati che l'URL di callback in WSO2 corrisponda all'URI di redirect configurato nelle impostazioni del modulo.
5. Controlla che il client ID e il client secret siano corretti.
6. Prova ad abilitare l'opzione "Salta verifica SSL" se stai avendo problemi relativi a SSL in un ambiente di sviluppo.

## Considerazioni sulla Sicurezza

- Utilizza sempre HTTPS per il tuo sito Drupal e il WSO2 Identity Server per prevenire attacchi man-in-the-middle.
- Aggiorna regolarmente il modulo e le sue dipendenze per assicurarti di avere le ultime patch di sicurezza.
- Il modulo implementa la validazione dello stato per prevenire attacchi CSRF durante il processo di autenticazione.
- Considera di abilitare l'auto-logout per assicurarti che gli utenti siano completamente disconnessi da entrambi i sistemi.
- Disabilita "Salta verifica SSL" negli ambienti di produzione.

## API

Il modulo fornisce servizi che possono essere utilizzati da altri moduli:

```php
// Ottieni il servizio WSO2 Auth
$wso2_auth = \Drupal::service('wso2_auth.authentication');

// Controlla se l'autenticazione WSO2 è configurata
if ($wso2_auth->isConfigured()) {
  // Fai qualcosa...
}

// Controlla se l'utente è autenticato con WSO2
if ($wso2_auth->isUserAuthenticated()) {
  // Fai qualcosa...
}

// Ottieni il servizio helper per l'ambiente
$env_helper = \Drupal::service('wso2_auth.environment_helper');

// Controlla se stai utilizzando l'ambiente di staging
if ($env_helper->isStaging()) {
  // Fai qualcosa...
}
```

## Crediti

Questo modulo è stato sviluppato da Maurizio Cavalletti e altri contributori, basato sulle specifiche del protocollo OAuth2 e dell'integrazione con WSO2 Identity Server.

## Licenza

Questo modulo è rilasciato sotto licenza [GNU General Public License v3](https://www.gnu.org/licenses/gpl-3.0.html).
