# Automa Work Mode

Versione documentata: `0.1.1`

Automa Work Mode e un plugin WordPress pensato per alleggerire temporaneamente il backend durante attivita operative come copywriting, revisione contenuti e gestione editoriale. Il plugin consente di selezionare manualmente quali plugin spegnere per una finestra di tempo limitata e di riattivarli automaticamente o manualmente alla fine della sessione.

Questo MVP privilegia controllo manuale, leggibilita e comportamento prevedibile.

## Stato attuale del plugin

La versione `0.1.1` include questi comportamenti reali:

- timer espresso in minuti
- selezione manuale dei plugin da disattivare
- plugin `protected` mostrati ma non selezionabili
- pagina opzioni con stato Work Mode e inventario plugin
- widget in Bacheca con azioni rapide
- notice admin persistente con branding `Automa Modalita Operativa`
- countdown live lato admin con fallback server-side
- pulsante `Riattiva ora`
- restore automatico e fallback restore su ogni admin load
- link rapido `Impostazioni` nella schermata Plugin di WordPress

## Obiettivo operativo

Il plugin non decide piu in autonomia quali plugin spegnere. La classificazione interna resta un aiuto visivo, ma la decisione finale e sempre dell'utente.

Quando la Work Mode parte:

- viene salvata una fotografia completa del sistema plugin
- vengono disattivati solo i plugin selezionati manualmente e attivi in quel momento
- viene pianificata la riattivazione automatica

Quando la Work Mode termina:

- vengono riattivati solo i plugin che erano attivi all'avvio e che la Work Mode ha davvero spento
- i plugin gia inattivi prima dell'avvio restano inattivi

## Timer in minuti

Il timer usa come input primario i minuti.

Dettagli:

- label: `Minuti prima della riattivazione automatica`
- default: `120`
- range: `1-720`

Il valore scelto viene salvato come impostazione del plugin e usato per calcolare il timestamp finale della sessione.

## Selezione manuale dei plugin

La tabella plugin nella pagina opzioni include una colonna di selezione.

Regole attuali:

- i plugin `protected` sono mostrati con checkbox visibile ma `disabled`
- la checkbox dei plugin protetti puo avere tooltip `Plugin protetto`
- i plugin `recommended-heavy` possono risultare preselezionati come suggerimento iniziale
- i plugin `neutral` e `unknown` possono essere selezionati manualmente se non protetti

Questo rende esplicito:

- quali plugin sono installati
- quali plugin sono attivi
- quali plugin sono protetti
- quali plugin sono stati selezionati da te per la Work Mode

## Pagina opzioni

La pagina `Tools > Automa Work Mode` mostra:

- stato Work Mode: `ACTIVE` / `INACTIVE`
- ora di fine prevista
- tempo residuo
- input minuti
- tabella plugin installati
- checkbox di selezione manuale
- stato attivo/inattivo di ogni plugin
- classificazione:
  - `protected`
  - `recommended-heavy`
  - `neutral`
  - `unknown`

Quando la Work Mode e attiva, la sezione stato mostra anche la lista dei plugin spenti in formato verticale:

- un plugin per riga
- nome leggibile come elemento principale
- `plugin file/path` come informazione secondaria

## Widget in Bacheca

Nella Bacheca WordPress e presente un widget compatto con:

- stato corrente della Work Mode
- tempo residuo se la modalita e attiva
- pulsante `Riattiva ora` se la modalita e attiva
- pulsante `Avvia modalita operativa` se la modalita non e attiva

## Notice admin persistente

Quando la Work Mode e attiva compare in tutto il backend un notice non chiudibile con branding chiaro:

- titolo: `Automa Modalita Operativa`
- testo secondario: `Tempo restante alla riattivazione dei plugin: [COUNTDOWN]`
- azione rapida: `Riattiva ora`

Dettagli UI:

- il notice usa una classe CSS dedicata: `automa-work-mode-notice`
- il bordo sinistro e forzato a `#ED128C`
- il notice resta visibile in tutto il backend finche la modalita e attiva

## Countdown live

Il countdown usa due livelli:

### Source of truth server-side

Il backend salva un `end_timestamp` reale che rappresenta la fine della sessione. Questo valore resta la fonte ufficiale del tempo residuo.

### Display live lato admin

Nel markup vengono esposti:

- il valore iniziale calcolato lato server
- il timestamp finale tramite attributo HTML

Uno script admin leggero aggiorna poi il countdown ogni secondo:

- nel notice admin
- nella pagina opzioni
- nel widget Bacheca quando la Work Mode e attiva

Formato attuale:

- se `>= 1h`: `Xh Ym`
- se `< 1h`: `Xm Ys`

Fallback:

- se JavaScript non gira, il tempo residuo renderizzato lato server resta comunque visibile e corretto al page load

## Classificazione plugin

La classificazione e semplice e interna al plugin.

### Recommended-heavy iniziali

- `broken-link-checker`
- `wp-compress`

### Protected iniziali

- Breeze
- Redis cache / object cache
- Elementor
- Elementor Pro
- Classic Editor
- Classic Widgets
- SMTP
- plugin di sicurezza noti
- plugin con pattern login / permalink / template

I plugin `protected` non possono essere selezionati dalla Work Mode.

## Logica runtime

### Avvio della Work Mode

All'avvio il plugin salva una fotografia iniziale completa con:

- lista plugin installati
- lista plugin attivi
- lista plugin inattivi
- lista plugin selezionati manualmente
- lista plugin effettivamente spenti
- timestamp di avvio
- timestamp di fine

Poi:

- disattiva solo i plugin selezionati e realmente attivi
- pianifica la riattivazione automatica
- mostra il notice persistente con countdown

### Stop manuale o scadenza

Quando la modalita viene fermata manualmente o arriva a scadenza:

- riattiva solo i plugin che erano attivi all'inizio e che la Work Mode ha spento
- non riattiva plugin gia inattivi prima dell'avvio
- pulisce lo stato attivo della Work Mode
- aggiorna l'interfaccia
- esegue un cache flush prudente dove supportato

### Fallback di sicurezza

Se WP-Cron non esegue il restore in tempo, ogni admin load controlla la scadenza e forza il ripristino quando necessario.

## Cache flush

Il plugin evita `wp_cache_flush()` per non interferire con Redis o object cache core.

Se presenti, prova solo flush prudenziali su integrazioni compatibili:

- WP Rocket
- LiteSpeed Cache
- SiteGround Optimizer
- hook custom `automa_work_mode_flush_cache`

Breeze resta escluso.

## Accesso rapido dalla schermata Plugin

Nella schermata `Plugin` di WordPress, sotto `Automa Work Mode`, e presente il link rapido:

- `Impostazioni`

Il link apre direttamente la pagina opzioni del plugin.

## Architettura corrente

```text
.
|-- README.md
|-- MILESTONES.md
|-- ROADMAP.md
|-- TODO.md
`-- automa-work-mode
    |-- automa-work-mode.php
    |-- uninstall.php
    |-- assets
    |   |-- css
    |   |   `-- admin.css
    |   `-- js
    |       `-- admin.js
    `-- includes
        |-- class-automa-work-mode.php
        `-- class-automa-work-mode-admin.php
```

## Come testare

1. Installare il plugin in `wp-content/plugins/automa-work-mode`.
2. Attivarlo da WordPress.
3. Verificare nella schermata Plugin:
   - descrizione in italiano
   - link rapido `Impostazioni`
4. Aprire `Tools > Automa Work Mode`.
5. Verificare stato iniziale `INACTIVE`.
6. Verificare input minuti con default `120`.
7. Verificare che i plugin `protected` abbiano checkbox `disabled`.
8. Selezionare manualmente uno o piu plugin non protetti.
9. Salvare la selezione.
10. Avviare la modalita operativa.
11. Verificare:
   - stato `ACTIVE`
   - ora di fine prevista
   - tempo residuo
   - countdown live nel notice
   - countdown live nella pagina opzioni
   - bordo notice `#ED128C`
   - lista verticale dei plugin spenti
   - disattivazione dei soli plugin selezionati e attivi
12. Usare `Riattiva ora`.
13. Ripetere lasciando scadere il timer.
14. Verificare il fallback aprendo una pagina admin dopo la scadenza.

## Rischi e limiti attuali

- la classificazione plugin e intenzionalmente semplice
- la selezione manuale riduce il rischio, ma e comunque possibile scegliere plugin che hanno impatto sul frontend
- WP-Cron puo essere in ritardo, anche se il fallback su admin load riduce il rischio di stato bloccato
- non e ancora presente supporto multisite

## Fuori scope in questa versione

- multisite
- WP-CLI
- export/import configurazione
- targeting automatico sofisticato
- reportistica avanzata

Questa versione `0.1.1` e un MVP operativo, leggibile e testabile subito.

