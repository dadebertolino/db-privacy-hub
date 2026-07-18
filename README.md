# DB Privacy Hub

**Hub privacy unificato per l'ecosistema plugin DB. Raccoglie i trattamenti dichiarati dai plugin DB (Cookie Manager, Form Builder, SEO Manager…) e genera una Privacy Policy completa pronta da pubblicare come pagina WordPress.**

> Niente servizi esterni, niente registrazioni, niente nag.

---

## 🇮🇹 Italiano

### A cosa serve

Quando hai più plugin DB attivi sul sito, ognuno tratta dati personali per i propri scopi: il Cookie Manager raccoglie il consenso ai cookie, il Form Builder riceve invii moduli e gestisce DSAR, il SEO Manager configura analytics e tracking, ecc. La normativa privacy (artt. 13-14 GDPR) impone un'**unica informativa** che descriva tutti questi trattamenti.

Il **DB Privacy Hub** raccoglie automaticamente le dichiarazioni di ogni plugin DB attivo, vi aggiunge i dati del titolare, le sezioni cookie importate dal Cookie Manager (se installato), un'individuazione automatica dei destinatari dei dati e tutto il boilerplate normativo (diritti dell'interessato, conservazione, reclamo all'autorità di controllo). Poi pubblica il documento come pagina WordPress.

### Caratteristiche principali

- **Registro trattamenti unificato** — un'unica pagina admin che mostra tutti i trattamenti dichiarati dai plugin DB attivi
- **Generatore Privacy Policy completo** — composizione automatica di un documento conforme agli artt. 13-14 GDPR
- **Form titolare** — nome, P.IVA, indirizzo, email, PEC, DPO; salvati una volta, riusati ovunque
- **Detection automatica destinatari** — riconosce reCAPTCHA configurato, plugin SMTP attivi, webhook host configurati nei form
- **Ponte WooCommerce** — con WooCommerce attivo, dichiara automaticamente i trattamenti e-commerce (ordini, fatturazione, account, pagamenti) e rileva i gateway di pagamento abilitati come destinatari
- **Importazione sezioni cookie** — se il DB Cookie Manager è installato, le sezioni cookie del documento vengono importate automaticamente (niente duplicazione di logica)
- **Integrazione DSAR** — se il DB Form Builder 2.5.0+ è installato, la sezione "Diritti dell'interessato" menziona la procedura DSAR automatica
- **Pubblicazione one-click** — crea (o rigenera) una pagina WordPress con titolo e slug configurabili, e la imposta come `wp_page_for_privacy_policy` core
- **Export `.md`** — scarica l'informativa come file Markdown
- **Auto-update** — aggiornamenti distribuiti via GitHub Releases, visibili direttamente nel pannello WordPress

### Requisiti

- WordPress 5.8+
- PHP 7.4+
- (Opzionale ma consigliato) DB Cookie Manager 3.1.0+ per integrazione cookie

### Installazione

1. Scarica l'ultimo ZIP dalle [Releases](https://github.com/dadebertolino/db-privacy-hub/releases)
2. WordPress → Plugin → Aggiungi nuovo → Carica plugin → Carica lo ZIP → Attiva
3. Vai a **Privacy → Genera Privacy Policy**, compila i dati del titolare, salva, click su "Crea pagina WordPress". Fatto.

Il plugin si auto-aggiorna da GitHub: gli aggiornamenti compaiono direttamente nel pannello plugin di WordPress.

### Filosofia

Lo stesso principio dell'intero ecosistema DB: nessun servizio esterno, nessun tracking, nessuna registrazione, nessun nag. Tutti i dati restano nel database WordPress locale. Il plugin non chiama mai server di terze parti (eccetto GitHub solo per il check aggiornamenti).

### Architettura

#### Filter `dbph_processing_register`

Il cuore del plugin è un filter pubblico su cui ogni plugin DB dichiara i propri trattamenti:

```php
add_filter( 'dbph_processing_register', function( $register ) {
    $register[] = array(
        'id'             => 'mioplugin_invio_form',
        'label'          => __( 'Invio modulo contatti', 'mioplugin' ),
        'status'         => 'active',
        'purpose'        => __( 'Gestire le richieste di contatto degli utenti.', 'mioplugin' ),
        'legal_basis'    => __( 'Consenso (art. 6.1.a GDPR).', 'mioplugin' ),
        'data_collected' => __( 'Nome, email, oggetto, messaggio.', 'mioplugin' ),
        'retention'      => __( '365 giorni, poi cancellazione automatica.', 'mioplugin' ),
        'transfers'      => __( 'Nessuno.', 'mioplugin' ),
    );
    return $register;
} );
```

Tutti i campi sono obbligatori. L'Hub non valida né normalizza il contenuto: la responsabilità è del plugin che dichiara.

#### Compatibilità con il filter legacy

Per compatibilità con installazioni che ancora usano il SEO Manager 1.2.x (filter `dbseo_processing_register`), l'Hub raccoglie automaticamente entrambi i filter, deduplicando per `id`. Il filter legacy verrà rimosso in DB Privacy Hub 2.0.0.

#### Filter `dbph_policy_sections`

Permette ad altri plugin/temi di rimuovere o riordinare sezioni del documento finale:

```php
add_filter( 'dbph_policy_sections', function( $sections, $context ) {
    // Esempio: rimuovi la sezione cookie se non vuoi importarla.
    unset( $sections['cookie'] );
    return $sections;
}, 10, 2 );
```

Le chiavi disponibili sono: `header`, `titolare`, `finalita`, `trattamenti`, `cookie`, `destinatari`, `diritti`, `conservazione`, `modifiche`, `reclamo`, `footer`.

#### Filter `dbph_policy_destinatari`

Permette di aggiungere destinatari custom alla detection automatica:

```php
add_filter( 'dbph_policy_destinatari', function( $dest ) {
    $dest[] = array(
        'name'        => 'Stripe Inc.',
        'description' => __( 'Servizio di pagamento.', 'mio' ),
        'country'     => __( 'Stati Uniti (extra-UE)', 'mio' ),
    );
    return $dest;
} );
```

### FAQ

**Posso modificare a mano la pagina dopo che è stata generata?**
Sì. Le modifiche manuali vengono però sovrascritte se clicchi "Rigenera pagina". Se vuoi modifiche persistenti, usa i filter `dbph_policy_sections` o intervieni nei trattamenti dichiarati dai singoli plugin.

**Funziona senza il DB Cookie Manager?**
Sì. La sezione "Cookie e tecnologie simili" viene semplicemente omessa. Se installi il Cookie Manager 3.1.0+ in un secondo momento, basta cliccare "Rigenera pagina" e la sezione viene aggiunta.

**Sostituisce il consulente privacy?**
No. Il plugin produce un documento tecnicamente accurato, ma la verifica e l'adattamento al contesto specifico del titolare restano una responsabilità professionale.

### Licenza

GPL v2 or later. Vedi `LICENSE`.

### Crediti

Sviluppato da [Davide Bertolino](https://www.davidebertolino.it). Parte dell'ecosistema plugin DB.

### Changelog

#### 1.6.0 — Bridge social e contenuti incorporati _(2026)_

**Tooling e CI**

- **GitHub Actions CI** — lint di sintassi su PHP 7.4 e 8.3 (matrice), PHPCS con WordPress Coding Standards (`phpcs.xml.dist`) e verifica PHPCompatibilityWP per il requisito PHP 7.4+
- **Workflow di release** — al push di un tag `v*`: verifica che il tag corrisponda alla versione nel plugin header e nella costante `DBPH_VERSION`, builda lo ZIP con radice `db-privacy-hub/` senza file di sviluppo e lo allega alla GitHub Release — l'asset è quello che l'auto-updater scarica (senza asset l'updater ripiegherebbe sullo zipball, che ha la cartella radice sbagliata)
- **Codebase allineata a WPCS** — ~4.700 rilievi risolti tra auto-correzione (phpcbf) e interventi manuali: variabili globali prefissate in `uninstall.php`, short ternary espliciti, commenti translators mancanti, annotazioni `phpcs:ignore` motivate per i falsi positivi (sanitizzazione a valle, hook core WP); le esclusioni per scelte progettuali deliberate (tabelle custom, ora locale DSAR, export CSV in streaming) sono documentate nel ruleset
- `composer.json` con script `lint`, `phpcs`, `phpcbf` per l'uso locale

**Funzionalità**

- **Nuovo modulo `DBPH_Embed_Bridge`** — rileva le piattaforme terze i cui contenuti sono incorporati nel sito (YouTube, Vimeo, Facebook, Instagram, TikTok, X, LinkedIn, Spotify, Google Maps) tramite blocchi Gutenberg embed, iframe nei contenuti e plugin noti; i semplici link ai profili sono volutamente esclusi (un link non trasferisce dati)
- **Abilitazione manuale** — checkbox nella pagina "Genera Privacy Policy" per dichiarare a mano le piattaforme che la scansione non può vedere (embed del tema, share button, page builder); le piattaforme rilevate automaticamente sono contrassegnate e dichiarate comunque
- **Voci di registro automatiche** — "Contenuti incorporati da piattaforme terze" (base: consenso art. 6.1.a, con rinvio al banner cookie) e "Remarketing e misurazione pubblicitaria" se è attivo un plugin pixel noto (PixelYourSite, Meta pixel, Facebook for WooCommerce, TikTok)
- **Piattaforme come destinatari** — descrittori precompilati con titolarità, paese e garanzie extra-UE (SCC/DPF), integrati nel merge destinatari della 1.5.0
- **Contitolarità pagine social** — checkbox che aggiunge alla policy il paragrafo sui dati Insights delle pagine social (art. 26 GDPR, CGUE C-210/16)
- La scansione dei contenuti è cachata (transient 12h, invalidato al salvataggio); il bridge è disattivabile con `add_filter( 'dbph_embed_bridge_enabled', '__return_false' )`; catalogo piattaforme estendibile via `dbph_embed_platforms`
- Fuori scope (dichiarato): social login e share button lato tema — per questi ultimi esiste appunto l'abilitazione manuale

#### 1.5.0 — Modelli responsabili + merge destinatari _(2026)_

- **Merge delle liste nella sezione "Destinatari"** — la Privacy Policy ora mostra SEMPRE due blocchi distinti: "Responsabili del trattamento (art. 28 GDPR)" con le dichiarazioni esplicite, e "Altri destinatari" con autonomi titolari e servizi rilevati automaticamente (gateway di pagamento, SMTP, reCAPTCHA, webhook). Fino alla 1.4.0 le dichiarazioni esplicite nascondevano la detection automatica: su un e-commerce con responsabili dichiarati i gateway sparivano dal documento
- **Dedup per nome** — se un soggetto rilevato è già dichiarato esplicitamente, prevale la dichiarazione (il fatto giuridico) e la voce rilevata viene omessa
- **Modelli rapidi di responsabili** — la pagina "Responsabili esterni" include una checklist di categorie tipiche (commercialista, webmaster/agenzia, hosting, fatturazione elettronica, email transazionale, backup) con voci precompilate a un click; segnaposto da sostituire con la ragione sociale reale, avviso in pagina finché restano segnaposto non sostituiti, filter `dbph_responsabili_templates` per estendere l'elenco
- **Fix notices** — i messaggi di conferma delle azioni DSAR manuali (registrata/aggiornata/eliminata) erano usati nei redirect ma mancavano dalla mappa e non venivano mai mostrati

#### 1.4.0 — Ponte privacy WooCommerce _(2026)_

- **Nuovo modulo `DBPH_Woo_Bridge`** — se WooCommerce è attivo, il plugin dichiara automaticamente sul registro i trattamenti e-commerce standard: gestione ordini e spedizione (base contrattuale), fatturazione e obblighi fiscali (obbligo di legge, 10 anni), account cliente (solo se la registrazione è abilitata), pagamenti online (solo se esistono gateway online abilitati) e telemetria WooCommerce verso Automattic (solo se l'opzione di tracking è attiva)
- **Detection gateway di pagamento** — i gateway online abilitati vengono aggiunti ai destinatari della Privacy Policy come autonomi titolari, con descrittori precompilati per Stripe, PayPal, Klarna, Nexi/XPay, Braintree, Mollie, Amazon Pay e Satispay (paese e garanzie di trasferimento incluse) e descrittore generico per i gateway non riconosciuti; i metodi offline (contrassegno, bonifico, assegno) sono esclusi
- **Sezione diritti integrata** — la Privacy Policy generata include il chiarimento sul limite alla cancellazione dei dati fiscali/contabili (art. 17.3.b GDPR) con spiegazione dell'anonimizzazione degli ordini
- Il ponte è disattivabile con `add_filter( 'dbph_woo_bridge_enabled', '__return_false' )`
- Fuori scope (da dichiarare manualmente): corrieri (Responsabili esterni) e consensi marketing delle estensioni

#### 1.3.1 — Bugfix e hardening _(2026)_

- **Fix timezone modulo DSAR** — cron di scadenza e contatori "in scadenza / scadute" ora usano l'ora locale del sito, coerentemente con i timestamp salvati (prima erano sfasati dell'offset del fuso orario)
- **Fix hook export** — il completamento dell'export dati personali viene registrato usando l'ID richiesta passato dall'hook core (funziona anche per export via WP-CLI); rimossa l'euristica inattendibile su `items_removed` nel completamento erase
- **Validazione DSAR manuale** — datetime non parsabili non producono più date epoch 1970; lo status è validato contro la whitelist; email normalizzata se valida
- **Fix contatore per plugin** — il raggruppamento delle voci del registro trattamenti riconosce correttamente prefissi di lunghezza arbitraria (es. `dbseo_*`)
- **Fix numerazione Privacy Policy** — se il Cookie Manager è attivo ma non produce sezioni cookie, indice e numerazione delle sezioni restano coerenti
- **Registro consensi** — il limite di righe viene propagato alle fonti (`args['limit']`) per evitare merge in memoria illimitati
- **Nuovo: conservazione dati alla disinstallazione** — opzione "Conserva i dati alla disinstallazione" (default OFF) che preserva log DSAR, archivio policy e impostazioni per finalità di accountability (art. 5.2 GDPR)

#### 1.3.0 — Registro consensi unificato + version ID Privacy Policy _(2026)_

Estensione che chiude il cerchio dell'accountability: ogni consenso espresso sul sito (banner cookie, checkbox form, futuri eventi) viene linkato alla versione esatta della Privacy Policy in vigore al momento, e visibile in una vista unificata.

**Filter pubblico `dbph_consents_register`:**
- I plugin DB compatibili dichiarano la propria fonte di consensi via filter. L'Hub aggrega automaticamente tutte le fonti in `Privacy → Registro consensi`. Pattern coerente con `dbph_processing_register` per i trattamenti e `dbph_user_data_exporters` per le DSAR.
- Contratto: ogni fonte fornisce callback `count`, `query`, `export`. La pagina admin chiama `query_all()` per la vista unificata (merge cronologico) o `query_for($key)` per una singola fonte.

**Nuova pagina admin `Privacy → Registro consensi`:**
- Vista cronologica unificata: cookie, form, futuri eventi.
- Filtri: data range, identificativo (testo libero su email mascherata), fonte specifica.
- Card riepilogativa: totale per ogni fonte.
- Export CSV completo (BOM UTF-8 per Excel italiano), rispetta i filtri attivi.
- Limite di 200 righe in vista (per performance), nessun limite nell'export.

**API `DBPH_Policy_Archive::get_current_version_id()`:**
- Restituisce l'ID dello snapshot Privacy Policy attualmente in vigore.
- Letto da Cookie Manager 3.2.0+ e Form Builder 2.11.0+ al momento del consenso, per linkare la riga di consenso al documento esatto che l'utente leggeva.
- Cache via option `dbph_policy_current_version` aggiornata automaticamente ad ogni `save()` di snapshot.
- Restituisce `0` se nessuna policy pubblicata: i plugin trattano questo caso come "policy non disponibile al momento".

**Compatibilità retroattiva:**
- Nessun breaking change. Il filter è opt-in dai plugin compatibili.
- Senza Cookie Manager 3.2.0+ né Form Builder 2.11.0+, la pagina mostra un messaggio informativo che indica come popolarla.
- Le righe pre-1.3.0 in `wp_dbcm_consent_log` (Cookie Manager) e nelle submission Form Builder non sono visibili nel Registro consensi: solo quelle generate dopo l'aggiornamento dei rispettivi plugin.

#### 1.2.0 — Suite accountability avanzata _(2026)_

Estensione completa del registro DSAR per coprire tutti gli scenari di accountability previsti dal GDPR (art. 5.2), incluse le richieste arrivate fuori dal flusso WordPress nativo.

**Registrazione manuale richieste DSAR (`Privacy → Registra DSAR manuale`):**
- Nuova pagina admin per registrare richieste arrivate via email, PEC, raccomandata o altri canali esterni a WordPress.
- Supporta tutti gli 8 diritti GDPR: accesso (art. 15), rettifica (art. 16), cancellazione (art. 17), limitazione (art. 18), portabilità (art. 20), opposizione (art. 21), no decisioni automatizzate (art. 22), revoca consenso (art. 7.3).
- Campi: tipo richiesta, identificativo utente (memorizzato mascherato + hash SHA-256), canale di ricezione, data ricezione, descrizione, stato, data completamento, note operative.
- Le richieste manuali sono modificabili ed eliminabili dall'admin (a differenza di quelle WP native che sono read-only).

**Tracking richieste WP non confermate:**
- Le richieste DSAR avviate via `Strumenti → Esporta/Cancella dati personali` ora vengono registrate **al momento dell'invio dell'email di conferma**, non solo dopo il click di conferma. Lo stato iniziale è `pending`, passa a `confirmed` quando l'utente clicca il link.
- Cron giornaliero `dbph_dsar_cleanup_pending` che marca come `expired` le richieste pending da più di 7 giorni (per evitare accumulo di richieste mai confermate).

**Promemoria scadenze GDPR (art. 12.3):**
- Lo Storico DSAR mostra una colonna "Scadenza GDPR" con codice colore: verde > 10 giorni, arancione 1-10 giorni, rosso scaduta. Calcolo basato su `requested_at + 30 giorni`.
- Statistiche aggregate "in scadenza" e "scadute" mostrate nella card riepilogativa.
- Le righe scadute sono evidenziate con sfondo rosa nella tabella.

**Export CSV registro DSAR:**
- Nuovo bottone "Esporta CSV" nello Storico DSAR. Esporta fino a 5000 righe con BOM UTF-8 (apertura corretta in Excel italiano).
- Colonne: ID, fonte, email mascherata, hash, tipo, stato, canale, date, scadenza in giorni, descrizione, note.
- Utile per produrre il registro al Garante in caso di ispezione.

**Sezione "Esercita i tuoi diritti" nella Privacy Policy:**
- Nuovo toggle nel form titolare "Includi istruzioni operative" (default ON).
- Quando attivo, la Privacy Policy generata include un blocco passo passo che spiega all'utente come esercitare concretamente i propri diritti: identificazione del diritto, modalità di invio della richiesta, contenuti utili, tempi di risposta del titolare (1 mese ex art. 12.3), gratuità dell'esercizio, diritto di reclamo al Garante (link a www.gpdp.it).

**Schema DB:**
- Migrazione automatica `dbph_dsar_log` 1.0 → 2.0: nuove colonne `source` (wp_native/manual/consent_revoked), `channel`, `description`, nuovo indice su `requested_at`. Le righe pre-esistenti sono migrate automaticamente come `source = wp_native`.

**Compatibilità retroattiva:**
- Nessun breaking change. Tutte le funzionalità preesistenti continuano a funzionare. Plugin che producono richieste DSAR via filter `wp_privacy_personal_data_*` (Form Builder, Cookie Manager) non richiedono modifiche.

#### 1.1.2 — Selettore pagina di destinazione _(2026)_
- Nella pagina `Privacy → Genera Privacy Policy` un nuovo dropdown permette di scegliere se creare una nuova pagina WordPress oppure **sovrascrivere il contenuto di una pagina esistente**. Il dropdown è popolato automaticamente con le pagine i cui titoli contengono "privacy", "informativa", "cookie", "gdpr", più la pagina già impostata come privacy policy core di WordPress
- L'overwrite preserva titolo e slug della pagina esistente, modifica solo il `post_content`
- Conferma JavaScript prima di sovrascrivere una pagina esistente
- Backup automatico nell'archivio Hub del contenuto pre-sovrascrittura (oltre alle revisioni native di WordPress)

#### 1.1.1 — Bugfix dedup filter legacy _(2026)_
- Fix: il warning "filter dbseo_processing_register deprecato" scattava anche per plugin DB già aggiornati (Cookie Manager 3.1.0+) che dichiarano sullo stesso filter nuovo. La causa era una priorità troppo bassa di `merge_legacy` (5), che girava prima che il filter nuovo raccogliesse le voci dei plugin aggiornati. Spostata a priority 999: ora la dedup per `id` funziona correttamente e il warning compare solo per plugin terzi che davvero usano ancora il filter legacy

#### 1.1.0 — Accountability suite _(2026)_
- **Storico richieste DSAR**: nuova pagina `Privacy → Storico DSAR`. Tabella custom `wp_dbph_dsar_log` che registra ogni richiesta di accesso (art. 15 GDPR) o cancellazione (art. 17 GDPR) gestita tramite WordPress Privacy Tools, con timestamp di richiesta/conferma/completamento. Email mascherate, hash SHA-256 conservato per verifica
- **Responsabili esterni dichiarati**: nuova pagina `Privacy → Responsabili esterni`. Form per dichiarare esplicitamente i soggetti con cui hai stipulato un Data Processing Agreement (art. 28 GDPR). Campi: nome, ruolo, paese, flag extra-UE, garanzie ex art. 46, URL DPA, note. Le dichiarazioni esplicite hanno priorità sulla detection automatica nella sezione "Destinatari" della Privacy Policy generata
- **Versionamento Privacy Policy**: nuova pagina `Privacy → Storico Policy`. Tabella `wp_dbph_policy_archive` salva uno snapshot ad ogni pubblicazione/rigenerazione. Visualizzazione versione e diff side-by-side rispetto alla versione precedente (basato su `wp_text_diff()` di WordPress core). De-duplica gli snapshot identici via hash SHA-256 del contenuto
- L'auto-snapshot è integrato nel flusso `Crea pagina WordPress` / `Rigenera`

#### 1.0.0 — Prima release pubblica _(2026)_
- Registro trattamenti unificato via filter `dbph_processing_register`
- Generatore Privacy Policy completo (artt. 13-14 GDPR) in italiano
- Form dati titolare (nome, P.IVA, indirizzo, email, PEC, DPO)
- Detection automatica destinatari (reCAPTCHA, SMTP, webhook host)
- Importazione sezioni cookie da DB Cookie Manager 3.1.0+
- Menzione DSAR automatica se DB Form Builder 2.5.0+ è installato
- **DSAR helper**: collettore degli exporter/eraser WP Privacy Tools per i plugin DB. Filter `dbph_user_data_exporters` e `dbph_user_data_erasers` permettono ad ogni plugin di dichiarare i propri dati personali da includere nelle richieste di accesso/cancellazione (artt. 15 e 17 GDPR)
- Pubblicazione pagina WordPress con titolo/slug configurabili + imposta `wp_page_for_privacy_policy`
- Export `.md` del documento
- Compatibilità retroattiva con filter legacy `dbseo_processing_register` (SEO Manager 1.2.x)
- GitHub Auto-Updater integrato
- Design system condiviso `db-admin-ui.css`

---

## 🇬🇧 English

**Unified privacy hub for the DB plugin ecosystem. Collects processing declarations from DB plugins (Cookie Manager, Form Builder, SEO Manager…) and generates a complete Privacy Policy ready to publish as a WordPress page.**

The plugin UI and the generated documents are in Italian, targeting GDPR-compliant Italian websites. For an English version, see future releases or contribute via PR.

### Architecture summary

- Public filter `dbph_processing_register` — DB plugins hook here to declare their data processings
- `DBPH_Policy_Generator` composes the final document combining: data controller info, declared processings, auto-detected recipients, cookie sections imported from DB Cookie Manager (if installed)
- `DBPH_Admin` provides the top-level "Privacy" admin menu with two sub-pages: "Registro trattamenti" (read-only register view) and "Genera Privacy Policy" (form + actions)
- Backward-compat with the legacy `dbseo_processing_register` filter from SEO Manager 1.2.x — to be removed in 2.0.0

### License

GPL v2 or later.
