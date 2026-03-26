# Automa Work Mode

Versione documentata: `0.1.3`

Automa Work Mode e un plugin WordPress pensato per alleggerire temporaneamente il backend durante attivita operative come copywriting, revisione contenuti e gestione editoriale. Permette di spegnere per un tempo limitato solo i plugin selezionati manualmente e di ripristinarli automaticamente o manualmente al termine della sessione.

## Funzioni principali

- selezione manuale dei plugin da disattivare
- auto-attivazione opzionale al login backend
- restore anche al logout
- timer in minuti con restore automatico
- fallback restore su admin load se WP-Cron ritarda
- notice admin persistente con countdown live
- widget rapido in Bacheca
- plugin `protected` visibili ma non selezionabili
- link rapido `Impostazioni` nella schermata Plugin

## Comportamento 0.1.3

### Default prima installazione

Se le opzioni del plugin non esistono ancora, all'attivazione vengono impostati subito questi default:

- auto-attivazione al login: attiva
- ruoli ammessi: `administrator`
- timer manuale predefinito: `120` minuti
- plugin selezionati di default: `broken-link-checker`, `internal-links`, `wp-compress`

Se le opzioni esistono gia, aggiornamenti e riattivazioni non le sovrascrivono.

### Avvio Work Mode

Quando la Work Mode parte:

- salva una fotografia iniziale del sistema plugin
- disattiva solo i plugin selezionati manualmente e attivi in quel momento
- pianifica il restore automatico
- mostra notice e countdown nel backend

### Auto-attivazione al login

La Work Mode parte automaticamente se:

- `auto_activate_on_login` e attivo
- esistono plugin selezionati
- il login sta portando davvero al backend WordPress
- la Work Mode non e gia attiva
- l'utente e `administrator`

Regole di sicurezza:

- il plugin e utilizzabile solo dagli amministratori
- non fa nulla se la Work Mode e gia attiva
- non fa nulla se non ci sono plugin selezionati ne plugin login di default disponibili
- non fa nulla se il ruolo non e ammesso
- usa un timer runtime dedicato di `10` minuti
- non sovrascrive il timer manuale salvato nelle impostazioni
- aggiunge automaticamente il plugin base `internal-links` se installato
- non tocca eventuali varianti Pro di `internal-links`

Il flusso e valido gia dal primo login backend reale dopo l'installazione: non richiede un'avvio manuale precedente.

### Restore

Quando la sessione termina o viene fermata manualmente:

- riattiva solo i plugin che erano attivi all'avvio e che la Work Mode ha davvero spento
- non riattiva plugin gia inattivi prima dell'avvio
- pulisce lo stato runtime
- esegue un cache flush prudente dove supportato

La stessa logica di restore viene eseguita anche al logout, se la Work Mode e ancora attiva.

Se un plugin non puo essere ripristinato automaticamente, il problema viene registrato nel log e viene richiesto un intervento manuale.

### Logging

Il log interno mantiene:

- attivazioni della Work Mode
- restore e restore incompleti
- auto-attivazioni al login riuscite
- skip reasons per auto-attivazione login:
  - `non_backend_login`
  - `already_active`
  - `no_selected_plugins`
  - `role_not_allowed`
  - `activation_failed`

## Interfaccia admin

La pagina `Tools > Automa Work Mode` mostra:

- stato `ACTIVE` / `INACTIVE`
- ora di fine prevista
- tempo residuo
- input minuti
- opzione login auto-attivo
- ruoli ammessi
- tabella plugin installati
- classificazione `protected`, `recommended-heavy`, `neutral`, `unknown`

Dettagli UI attuali:

- checkbox `disabled` per i plugin protected
- lista verticale dei plugin spenti durante la sessione
- bordo notice `#ED128C`
- background widget Bacheca attivo `#ED128C`
- countdown sempre visibile nel notice, nella pagina opzioni e nel widget Bacheca, con fallback lato server

## Installazione

1. Copiare il plugin in `wp-content/plugins/automa-work-mode`.
2. Attivarlo da WordPress.
3. Aprire `Tools > Automa Work Mode`.
4. Verificare o aggiornare la selezione dei plugin da disattivare.

## Test rapido consigliato

1. Verificare che alla prima installazione siano gia attivi login automatico, ruolo `administrator` e timer manuale `120`.
2. Selezionare uno o piu plugin non protetti e salvare.
3. Avviare manualmente la Work Mode e verificare countdown, notice e lista plugin spenti.
4. Usare `Riattiva ora` e verificare il restore.
5. Ripetere lasciando scadere il timer.
6. Con Work Mode attiva, fare logout e verificare il restore.
7. Con Work Mode inattiva, fare logout/login nel backend con un ruolo ammesso.
8. Verificare auto-attivazione, timer runtime `10` minuti, inclusione base `internal-links` e logging.

## Limiti attuali

- classificazione plugin intenzionalmente semplice
- possibile selezionare plugin che hanno impatto sul frontend
- WP-Cron puo essere in ritardo
- multisite non supportato

## Changelog

### 0.1.3

- `Internal Link Juicer` base (`internal-links/internal-links.php`) aggiunto alla lista base anche su installazioni gia aggiornate, senza includere versioni Pro
- timer dedicato per auto-attivazione al login portato a `10` minuti senza sovrascrivere il timer manuale salvato
- plugin base `internal-links` aggiunto ai default del login automatico e ai default iniziali di selezione
- `object-cache-pro` rimosso dalle logiche di protezione implicita, quindi di nuovo selezionabile e ripristinabile
- countdown reso sempre visibile in notice, pagina opzioni e widget dashboard con fallback lato server
- widget dashboard attivo evidenziato con background `#ED128C`

### 0.1.2

- plugin limitato ai soli amministratori per uso manuale e auto-attivazione login
- default prima installazione portati a login auto-attivo, ruolo `administrator` e timer `5` minuti
- protezione esplicita contro overwrite delle opzioni gia salvate durante riattivazione/aggiornamento
- auto-attivazione al login limitata ai login backend reali
- first-use allineato al primo login backend reale, senza richiedere una precedente attivazione manuale
- restore automatico aggiunto anche al logout
- logging login mantenuto con eventi di successo e skip reasons (`non_backend_login`, `already_active`, `no_selected_plugins`, `role_not_allowed`, `activation_failed`)
