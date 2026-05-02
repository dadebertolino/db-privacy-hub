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
