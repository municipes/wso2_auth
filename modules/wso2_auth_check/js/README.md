Caratteristiche del codice completo:
✅ Funzioni principali:

executeSSOProbe() - Apre popup invisibile e gestisce la comunicazione
redirectToAuthEndpoint() - Reindirizza all'endpoint Drupal esistente
showLoginNotification() - Mostra notifica user-friendly
executeAuthCheck() - Logica principale semplificata
initializeAuthCheck() - Gestisce l'attivazione dopo interazione utente

✅ Gestione sicurezza:

Verifica origine dei messaggi
Gestione timeout popup
Controllo chiusura popup
State e nonce generation

✅ Gestione performance:

Controllo intervalli tra check
Cooldown per fallimenti
Skip per utenti già loggati
Lazy loading su interazione utente

✅ Debug avanzato:

Log colorati e strutturati
Conferma interattiva per continuare
Helper window per testing manuale
Console dettagliata con tabelle

✅ UX migliorata:

Notifiche animate
Link di fallback manuale
Feedback visivo durante processo
Gestione errori user-friendly
