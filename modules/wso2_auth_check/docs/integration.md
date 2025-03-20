# Integrazione con WSO2 Authentication

## Come funziona l'integrazione

Il modulo WSO2 Authentication Check fornisce un meccanismo automatico per rilevare e gestire sessioni attive di WSO2 Identity Provider.

### Scenario principale

L'obiettivo principale del modulo è gestire questo scenario:

1. Un utente si autentica presso l'Identity Provider WSO2 tramite un altro sito
2. L'utente arriva successivamente sul sito Drupal
3. Il modulo rileva automaticamente la sessione attiva di WSO2
4. L'utente viene silenziosamente autenticato anche su Drupal, senza necessità di intervento

### Flusso tecnico

Il processo di rilevamento delle sessioni e autenticazione avviene con il seguente flusso:

1. **Verifica iniziale**: Per ogni richiesta di pagina effettuata da un utente anonimo, l'`EventSubscriber` verifica se il modulo è abilitato e se la richiesta non è su un percorso escluso.

2. **Rilevamento sessione WSO2**: Il modulo verifica se l'utente ha già una sessione attiva con l'Identity Provider WSO2 utilizzando il parametro `prompt=none`. Questa è una funzionalità standard di OAuth2/OpenID Connect che permette di verificare lo stato di autenticazione senza interrompere l'utente.

3. **Interpretazione della risposta**:
   - Se l'IdP risponde con un parametro `code`, l'utente è già autenticato
   - Se l'IdP risponde con `error=login_required`, l'utente non è autenticato
   - Se si verifica un errore, il modulo salta il processo di autenticazione per sicurezza

4. **Gestione redirect**: Se viene rilevata una sessione attiva, il modulo reindirizza automaticamente l'utente al flusso di autenticazione standard di WSO2, passando il percorso corrente come destinazione dopo l'autenticazione.

5. **Autenticazione su Drupal**: Il processo di autenticazione segue il flusso standard del modulo principale WSO2 Authentication, che gestisce l'autenticazione effettiva su Drupal.

6. **Ritorno alla pagina originale**: Dopo l'autenticazione, l'utente viene reindirizzato alla pagina che stava cercando di accedere inizialmente.

### Il parametro `prompt=none`

Il parametro `prompt=none` è parte della specifica OpenID Connect e indica al provider di identità che:

1. Non deve mostrare alcuna interfaccia di autenticazione all'utente
2. Deve controllare se l'utente ha già una sessione attiva
3. Deve restituire immediatamente un risultato basato sullo stato della sessione

Quando l'IdP WSO2 riceve una richiesta con `prompt=none`:
- Se l'utente è già autenticato, l'IdP restituisce un codice di autorizzazione
- Se l'utente non è autenticato, l'IdP restituisce un errore con messaggio `error=login_required`

Questo meccanismo permette di verificare lo stato di autenticazione in modo non intrusivo, senza redirect completi e senza mostrare pagine di login all'utente quando non necessario.

Esempio di risposta quando l'utente non è autenticato:
```
Stato = 404
{
    "GET": {
        "scheme": "https",
        "host": "www.comune.fi.it",
        "filename": "/michele",
        "query": {
            "error_description": "Authentication required",
            "error": "login_required",
            "session_state": "d5aeb9f2469bc873b33cc3f3e279922f08af67babbdf09798cbbb9a5e8d4be1d.MKYPNUQYU3ZLRBPobenY-w"
        },
        "remote": {
            "Indirizzo": "159.xxx.xxx.xxx:443"
        }
    }
}
```

### Considerazioni sulla performance

Per minimizzare l'impatto sulla performance:

- Il modulo verifica la sessione solo per utenti anonimi
- È possibile configurare il modulo per controllare una sola volta per sessione
- È possibile impostare un intervallo minimo tra i controlli
- Il modulo salta le richieste AJAX e POST
- È possibile escludere percorsi specifici dal controllo
- L'uso di `prompt=none` riduce il carico sul server di identità

## Integrazione con altri moduli

Il modulo WSO2 Authentication Check si integra principalmente con il modulo base WSO2 Authentication, ma può anche essere utilizzato con:

- **Single Sign-On (SSO)**: Si integra perfettamente con soluzioni SSO basate su WSO2 Identity Server.
- **Moduli di accesso Drupal**: Non interferisce con altri moduli di autenticazione.

## Personalizzazione

È possibile personalizzare il comportamento del modulo tramite:

- **Configurazione**: Utilizzando l'interfaccia di amministrazione per impostare i parametri di base.
- **Hooks**: Implementando hooks nei moduli personalizzati per alterare il comportamento.
- **Estensione del codice**: Creando servizi o classi che estendono le funzionalità esistenti.

### Esempi di hook

```php
/**
 * Implements hook_wso2_auth_check_paths_alter().
 */
function mymodule_wso2_auth_check_paths_alter(array &$paths) {
  // Aggiungere percorsi da escludere.
  $paths[] = '/my/custom/path';
}

/**
 * Implements hook_wso2_auth_check_should_check_alter().
 */
function mymodule_wso2_auth_check_should_check_alter(&$should_check, $session) {
  // Implementare logica personalizzata per decidere se eseguire il controllo.
  if (isset($_COOKIE['my_custom_cookie'])) {
    $should_check = FALSE;
  }
}
```

## Risoluzione dei problemi

Se riscontri problemi con l'autenticazione automatica:

1. Verifica che il modulo WSO2 Authentication sia correttamente configurato e funzionante.
2. Assicurati che l'utente abbia effettivamente una sessione attiva con l'Identity Provider WSO2.
3. Controlla i log di Drupal per eventuali errori relativi al modulo.
4. Prova ad abilitare la modalità debug nel modulo principale WSO2 Authentication.
5. Verifica che i percorsi esclusi siano configurati correttamente.
6. Controlla che il parametro `prompt=none` sia supportato correttamente dall'IdP WSO2.

### Log comuni

I log più comuni generati dal modulo includono:

- `WSO2 session check skipped for path: {path}`: Indica che un percorso è stato escluso dal controllo.
- `Silent WSO2 session check result for path {path}: authenticated/not authenticated/undetermined`: Indica il risultato del controllo silenzioso con `prompt=none`.
- `Active WSO2 session detected. Initiating auto-login for path: {path}`: Indica che è stata rilevata una sessione attiva.
- `Error during silent authentication check: {error}`: Indica un errore durante il controllo silenzioso.
- `Auto-login completed successfully for user {uid}`: Indica che l'autenticazione automatica è stata completata con successo.
