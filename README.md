# WSO2 Authentication Module

## Panoramica

Il modulo WSO2 Authentication fornisce integrazione completa tra Drupal e il WSO2 Identity Server, consentendo l'autenticazione degli utenti tramite il protocollo OAuth2. È stato progettato specificamente per l'integrazione con i sistemi di identità digitale italiani (SPID/CIE) tramite WSO2 come intermediario.

Il modulo supporta due tipologie di utenti:

1. **Cittadini**: Utenti che accedono tramite SPID o CIE
2. **Operatori**: Utenti istituzionali (dipendenti di PA, ecc) che accedono tramite credenziali specifiche

## Caratteristiche

### Modulo Principale (wso2_auth)

- Autenticazione OAuth2 con WSO2 Identity Server
- Supporto completo per Drupal 10 e 11.1+
- Implementazione completa Single Sign-On (SSO) e Single Logout (SLO)
- Gestione distinta di utenti cittadini e operatori
- Auto-registrazione degli utenti con mappatura campi configurabile
- Blocchi di login personalizzati
- Assegnazione automatica di ruoli con esclusioni
- Gestione dei privilegi per gli operatori
- Supporto per ambienti di produzione e staging
- Modalità debug per la risoluzione dei problemi
- Compatibilità con lo standard OpenID Connect

### Sottomodulo (wso2_auth_check)

- Rilevamento trasparente e automatico della sessione WSO2 attiva
- Auto-login per utenti già autenticati presso l'Identity Provider
- Controllo via iframe con intervallo configurabile
- Funzionamento completamente client-side con JavaScript
- Supporto per diverse configurazioni di Identity Provider
- Compatibilità multi-modulo (supporta anche wso2silfi se presente)

## Requisiti

- Drupal 10 o 11.1+
- PHP 8.1+ (PHP 8.3 raccomandato per Drupal 11)
- Modulo [externalauth](https://www.drupal.org/project/externalauth)
- WSO2 Identity Server con supporto OAuth2/OpenID Connect

## Installazione

```bash
composer require municipes/wso2_auth
drush en wso2_auth wso2_auth_check
```

## Configurazione

### Configurazione del Server WSO2

Prima di configurare il modulo, è necessario impostare un'applicazione OAuth2 nel WSO2 Identity Server:

1. Registra una nuova applicazione OAuth2 con le seguenti impostazioni:
   - URL di callback: `https://tuositodrupal.it/wso2-auth/callback`
   - Tipi di grant: Authorization Code
   - Scope richiesti: openid

2. Configura l'Identity Server per l'integrazione con i fornitori d'identità (IdP) SPID e CIE, se necessario.

### Configurazione del Modulo WSO2 Authentication

#### Impostazioni Generali

1. Vai a Amministrazione » Configurazione » Persone » Impostazioni WSO2 Authentication (`/admin/config/people/wso2-auth`).
2. Abilita il modulo e configura:
   - URL del server di autenticazione (es. `https://id.055055.it:9443/oauth2`)
   - ID entità (agEntityId): L'ID entità da utilizzare per l'autenticazione (es. "FIRENZE")
   - Modalità (produzione o staging)
   - Opzioni di visualizzazione (logo SPID nel form)

#### Impostazioni per Cittadini

1. Configura OAuth2:
   - Client ID e Client Secret
   - Scope OAuth2 (es. "openid")

2. Impostazioni utenti:
   - Auto-registrazione: abilita/disabilita
   - Ruolo predefinito da assegnare
   - Ruoli da escludere dall'assegnazione automatica

3. Mappatura campi:
   - Imposta quali campi del JWT mappare ai campi utente Drupal
   - Configurazioni specifiche per codice fiscale e altri dati personali

#### Impostazioni per Operatori

1. Abilita l'autenticazione per operatori e configura:
   - Client ID e Client Secret specifici
   - Parametri per l'autenticazione operatore

2. Configurazione privilegi:
   - Regole di mappatura ruoli basate sulle funzioni dell'operatore
   - URL del servizio privilegi per gli operatori

### Configurazione del Modulo WSO2 Authentication Check

1. Vai a Amministrazione » Configurazione » Persone » Impostazioni WSO2 Auth Check (`/admin/config/people/wso2-auth-check`).
2. Abilita il controllo automatico e configura:
   - Intervallo di controllo (in minuti)
   - Abilita/disabilita modalità debug

## Funzionamento

### Single Sign-On (SSO)

Il modulo supporta due scenari principali:

1. **Autenticazione esplicita**: L'utente clicca sui pulsanti di login per SPID/CIE (cittadino) o Operatore.

2. **Autenticazione trasparente** (tramite wso2_auth_check): Se un utente ha già una sessione attiva con l'IdP WSO2, viene automaticamente autenticato in Drupal quando visita il sito, senza necessità di interazione.

### Single Logout (SLO)

Quando un utente effettua il logout da Drupal, viene anche disconnesso dalla sessione WSO2, garantendo un logout completo dal sistema.

### Blocchi di Login

Il modulo fornisce due blocchi di login che possono essere posizionati in qualsiasi regione:

1. **Blocco Login Cittadino**: Per l'autenticazione tramite SPID/CIE
2. **Blocco Login Operatore**: Per l'autenticazione specifica degli operatori

## Sicurezza

- Gestione sicura dello stato (state) per prevenire attacchi CSRF/XSRF
- Protezione del token nonce per prevenire attacchi replay
- Verifica dell'integrità del token JWT
- Controlli di sessione per prevenire sessioni non autorizzate
- Supporto HTTPS per tutte le comunicazioni
- Opzione per saltare la verifica SSL in ambienti di sviluppo (non raccomandata in produzione)

## Estendere il Modulo

### Hook tradizionali

Il modulo espone diversi hook per personalizzare il comportamento:

- `hook_wso2_auth_userinfo_alter(&$user_data)`: Modifica i dati utente prima dell'autenticazione
- `hook_wso2_auth_post_login($account, $user_data, $auth_type)`: Esegue azioni dopo il login
- `hook_wso2_auth_authorization_url_alter(&$url, $params)`: Modifica l'URL di autorizzazione
- `hook_wso2_auth_token_request_alter(&$params, $code)`: Modifica i parametri della richiesta token
- `hook_wso2_auth_userinfo_request_alter(&$options, $access_token)`: Modifica la richiesta userinfo
- `hook_wso2_auth_logout_url_alter(&$url, $params)`: Modifica l'URL di logout

### Implementazione con Attributi (Drupal 11.1+)

A partire da Drupal 11.1, il modulo supporta l'implementazione tramite attributi PHP in classi orientate agli oggetti:

#### 1. Hook con Attributi

```php
use Drupal\Core\Hook\Attribute\Hook;

class MyModuleHooks implements ContainerInjectionInterface {
  #[Hook(hook: 'wso2_auth_userinfo_alter')]
  public function alterUserInfo(&$user_data) {
    // Modifica i dati utente
    $user_data['display_name'] = 'Prefisso: ' . $user_data['display_name'];
  }
}
```

#### 2. Plugin con Attributi (con compatibilità all'indietro)

```php
// Approccio con doppia dichiarazione (compatibile con Drupal 10 e 11)
/**
 * Provides a 'CitizenBlock' block.
 *
 * @Block(
 *  id = "wso2_citizen_block",
 *  admin_label = @Translation("WSO2 Blocco login Cittadino"),
 * )
 */
#[Block(
  id: "wso2_citizen_block",
  admin_label: new TranslatableMarkup("WSO2 Blocco login Cittadino")
)]
class CitizenBlock extends BlockBase implements ContainerFactoryPluginInterface {
```

## API

Il modulo fornisce servizi che possono essere utilizzati da altri moduli:

```php
// Ottieni il servizio WSO2 Auth
$wso2_auth = \Drupal::service('wso2_auth.authentication');

// Controlla se l'autenticazione WSO2 è configurata
if ($wso2_auth->isConfigured()) {
  // Fai qualcosa...
}

// Ottieni l'URL di autorizzazione
$url = $wso2_auth->getAuthorizationUrl($destination, 'citizen');

// Ottieni il servizio helper per l'ambiente
$env_helper = \Drupal::service('wso2_auth.environment_helper');
```

## Risoluzione dei Problemi

- Abilita la modalità debug nelle impostazioni di entrambi i moduli
- Controlla i log di Drupal per messaggi dettagliati
- Verifica la console del browser per gli errori JavaScript (per wso2_auth_check)
- Assicurati che i certificati SSL siano validi
- Controlla che l'URL di callback corrisponda a quello configurato nel WSO2 Identity Server

## Compatibilità Drupal 11.1

Il modulo è completamente compatibile con Drupal 11.1, utilizzando:

- Dependency Injection moderno per tutti i servizi
- Implementazione a doppio stile (attributi e annotazioni) per i plugin
- Implementazione con attributi per gli hook
- Type hinting completo e return types per tutte le classi
- Supporto per PHP 8.3

## Crediti

Questo modulo è stato sviluppato da Maurizio Cavalletti come parte del progetto di integrazione dei sistemi di identità digitale italiani (SPID/CIE) con le piattaforme Drupal per la Pubblica Amministrazione.

## Licenza

Questo modulo è rilasciato sotto licenza [GNU General Public License v3](https://www.gnu.org/licenses/gpl-3.0.html).
