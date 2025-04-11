# WSO2 Authentication Check

## Panoramica

WSO2 Authentication Check è un sottomodulo di WSO2 Authentication che fornisce funzionalità di rilevamento automatico delle sessioni WSO2 attive e login trasparente su Drupal, abilitando un vero meccanismo di Single Sign-On (SSO).

Questo modulo è progettato per verificare se un utente ha già una sessione attiva presso l'Identity Provider WSO2 e, in caso affermativo, autenticarlo automaticamente su Drupal senza richiedere un'interazione esplicita.

## Funzionamento

Il modulo utilizza un approccio client-side per verificare lo stato di autenticazione dell'utente:

1. Un iframe nascosto viene creato dinamicamente tramite JavaScript per gli utenti anonimi che visitano il sito
2. L'iframe richiede un id_token al server WSO2 con il parametro `prompt=none`
3. Se l'utente ha già una sessione attiva, WSO2 restituisce immediatamente un token senza richiedere credenziali
4. Il token viene verificato lato client (inclusa verifica del nonce per prevenire attacchi replay)
5. Se valido, l'utente viene reindirizzato all'endpoint di autenticazione standard di WSO2 Auth per completare il login su Drupal

Per ottimizzare le prestazioni, il modulo memorizza temporaneamente i controlli falliti per evitare richieste eccessive al server di autenticazione.

## Caratteristiche

- Rilevamento automatico di sessioni WSO2 attive per utenti anonimi
- Auto-login trasparente senza interazione utente
- Meccanismo di caching lato client per ridurre le richieste
- Intervallo di controllo configurabile
- Verifica sicura dei token con protezione nonce
- Modalità debug per la risoluzione dei problemi
- Compatibilità con diversi moduli di autenticazione WSO2 (anche wso2silfi)

## Requisiti

- Modulo principale WSO2 Authentication
- Browser moderno con supporto a localStorage e iframe sicuri
- Server WSO2 Identity configurato per supportare richieste con `prompt=none`

## Installazione

Il modulo viene installato automaticamente con il modulo principale WSO2 Authentication, ma può essere abilitato separatamente:

```bash
drush en wso2_auth_check
```

## Configurazione

1. Vai a Amministrazione » Configurazione » Persone » Impostazioni WSO2 Auth Check (`/admin/config/people/wso2-auth-check`)
2. Configura:
   - **Abilitato**: Attiva/disattiva il controllo automatico
   - **Intervallo di controllo**: Tempo in minuti tra i controlli (default: 3)
   - **Debug**: Attiva/disattiva la modalità debug

## Sicurezza

Il modulo implementa diverse misure di sicurezza:

- Generazione di nonce casuale per ogni richiesta
- Verifica del nonce per prevenire attacchi replay
- Uso di localStorage limitato ai dati non sensibili
- Caricamento di iframe tramite HTTPS
- Controlli anti-framejacking
- Protezione contro attacchi CSRF con token di sicurezza

## Risoluzione dei Problemi

Per risolvere problemi con il modulo:

1. Abilita la modalità debug nelle impostazioni
2. Apri la console del browser (F12) e controlla i messaggi con prefisso `[WSO2AuthCheck]`
3. Verifica che la console non mostri errori cross-origin o di sicurezza
4. Controlla che il server WSO2 supporti richieste con `prompt=none`
5. Assicurati che i certificati SSL siano validi

## Compatibilità Drupal 11.1

Il modulo è completamente compatibile con Drupal 11.1, utilizzando:

- Dependency Injection moderno per tutti i servizi
- JavaScript moderno compatibile con gli standard ES6+
- Type hinting completo e return types per tutte le classi
- Supporto per PHP 8.3

## Note Tecniche

Il modulo utilizza un approccio non invasivo per il rilevamento delle sessioni:

1. Il JavaScript viene caricato solo per utenti anonimi
2. L'iframe viene rimosso automaticamente dopo il controllo
3. Le librerie JavaScript vengono caricate solo quando necessario
4. Il modulo si integra con le configurazioni esistenti di WSO2 Authentication
5. Esegue controlli solo su pagine normali, evitando callback e pagine di autenticazione

## Integrazione

Il modulo si integra automaticamente con il modulo principale WSO2 Authentication, e può essere esteso tramite:

- Drupal JavaScript behaviors (`Drupal.behaviors.wso2AuthCheck`)
- Drupal settings (`drupalSettings.wso2AuthCheck`)
- Alterazione delle configurazioni tramite il servizio `wso2_auth_check.config`

## API

Il modulo fornisce un servizio che può essere utilizzato da altri moduli:

```php
// Ottieni il servizio di configurazione
$config_service = \Drupal::service('wso2_auth_check.config');

// Verifica se il controllo automatico è abilitato
if ($config_service->isEnabled()) {
  // Fai qualcosa...
}

// Ottieni le configurazioni
$config = $config_service->getConfig();
```

## Crediti

Questo modulo è stato sviluppato come parte del sistema di autenticazione WSO2 per l'integrazione con i sistemi di identità digitale italiani (SPID/CIE) nelle piattaforme Drupal per la Pubblica Amministrazione.

## Licenza

Questo modulo è rilasciato sotto licenza [GNU General Public License v3](https://www.gnu.org/licenses/gpl-3.0.html).
