# DB Privacy Hub — Piano di test e correzioni

Documento di lavoro per portare il Privacy Hub allo stesso livello di test del
DB Cookie Manager (unit + integration + E2E + run notturna). Si segue fase per
fase; ogni fase è una PR. Le caselle si spuntano man mano.

Base di partenza: v1.7.0 (analisi del 2026-10-06).

---

## 1. Situazione attuale

- ~6.000 righe PHP in 12 classi (`inc/`), nessun JS.
- **Nessun test.** CI (`.github/workflows/ci.yml`): `php -l` su PHP 7.4 e 8.3,
  PHPCS, PHPCompatibilityWP 7.4+. Release da tag `v*` (`release.yml`).
- Il plugin non ha frontend dinamico. Il valore sta in:
  1. **contratti tra plugin** (filtri che gli altri plugin DB alimentano);
  2. **generazione e archivio della Privacy Policy** con le versioni;
  3. **log DSAR** agganciato agli strumenti privacy di WordPress.

Quindi la piramide pesa su **unit + integration**; gli E2E coprono l'admin, il
flusso DSAR negli strumenti di WordPress e l'**ecosistema** (Hub + Cookie
Manager installati insieme).

### Contratti pubblici (da proteggere con i test)

| Filtro / API | Forma attesa | Consumatore |
|---|---|---|
| `dbph_processing_register` | `array<{id,label,status,purpose,legal_basis,data_collected,retention,transfers}>` | `DBPH_Register::collect()` |
| `dbseo_processing_register` (legacy, via in 2.0) | come sopra, dedup per `id` | `DBPH_Deprecated_Aliases::merge_legacy()` (prio 999) |
| `dbph_policy_destinatari` | `array<{name,description,country}>` | `section_destinatari()` |
| `dbph_policy_sections` | `array<key ⇒ html>` | `DBPH_Policy_Generator::generate()` |
| `dbph_policy_html` | stringa HTML | `generate()` |
| `dbph_user_data_exporters` / `_erasers` | `array<key ⇒ {label, callback(email,page)}>` | `DBPH_DSAR::register_*` (prio 20) |
| `dbph_consents_register` | `array<key ⇒ {label, icon, count(args), query(args)}>` | `DBPH_Consents_Register` |
| `dbph_responsabili_templates`, `dbph_embed_platforms`, `dbph_woo_bridge_enabled`, `dbph_embed_bridge_enabled`, `dbph_dsar_available` | vedi codice | — |
| `DBPH_Policy_Archive::get_current_version_id()` | `int` (0 se nessuna versione) | DB Cookie Manager, DB Form Builder (registro consensi) |
| `DBCM_Policy_Generator::get_sections()` (uscente) | `array<key ⇒ html>` | `section_cookie()` |

---

## 2. Bug noti (da correggere, ciascuno con il suo test)

Priorità: **A** = dati legali o crash, **B** = dati errati in admin,
**C** = qualità/robustezza.

| # | Pri. | Dove | Problema | Test che lo prova |
|---|---|---|---|---|
| 1 ✅ | A | `class-dsar-log.php:370` | Legge `$request->date_created_gmt`, che su `WP_User_Request` non esiste (è `created_timestamp`). Warning PHP 8 a ogni conferma; `requested_at` diventa "adesso" → il termine GDPR di 30 giorni parte dalla conferma, non dalla richiesta. | Integration: richiesta creata 5 giorni fa, confermata oggi → `requested_at` = data di creazione. |
| 2 ✅ | A | `class-policy-generator.php:63` | Un filtro `dbph_policy_sections` che restituisce un non-array → fatal in `array_map`; `dbph_policy_html` non è controllato. Un plugin terzo blocca la generazione. | Unit: filtro che restituisce `null`/stringa → policy generata comunque. |
| 3 ✅ | A | `class-dsar.php`, `class-consents-register.php` | Le eccezioni dei callback DSAR/consensi di altri plugin non sono intercettate: un plugin rotto blocca la richiesta degli altri (contro quanto promette il README). | Unit/integration: un exporter che lancia eccezione, gli altri rispondono. |
| 4 ✅ | A | `class-admin.php:800` (`do_overwrite_page`) | Il backup pre-sovrascrittura viene salvato come versione e diventa per un momento `dbph_policy_current_version`: un consenso registrato in quell'istante punta a un testo sbagliato. | Integration: dopo la sovrascrittura, la versione corrente è quella pubblicata e il backup è marcato come tale. |
| 5 ✅ | B | `class-dsar-log.php:621` (`get_stats`) | Cancellazione `partial` contata come pendente; `expired` e `rejected` contati come aperti; tipi art. 16–22 esclusi. Il cruscotto sovrastima le richieste pendenti. | Unit/integration su un set di righe con tutti gli stati. |
| 6 ✅ | B | `get_stats` vs `calculate_deadline` | Scadenza calcolata in SQL (`INTERVAL 30 DAY`) e in PHP (giorni arrotondati): ai bordi badge e contatori non coincidono. | Unit: richiesta a 29, 30, 31 giorni. |
| 7 ✅ | B | `class-admin.php` (`do_create_new_page`) | "Nuova pagina" ripetuto crea pagine duplicate (`-2`, `-3`) invece di riusare `dbph_page_id`. | E2E: due pubblicazioni → una sola pagina privacy. |
| 8 ✅ | B | `class-admin.php:1136-1149` | Avviso di conferma DSAR manuale mostrato due volte; `sanitize_key($_GET[...])` senza `wp_unslash` (anche 1739, 1906). | E2E: un solo avviso. |
| 9 ✅ | B | `class-responsabili.php:201` | Modelli aggiunti con `dbph_responsabili_templates` non compaiono nel menu (etichette fisse). | Unit + E2E. |
| 10 ✅ | B | `class-responsabili.php:93` | Id generato da `microtime`: un responsabile senza id ne riceve uno diverso a ogni lettura. | Unit: due letture → stesso id. |
| 11 ✅ | C | `class-admin.php:1863` | `mb_substr` su `consent_text` non stringa → TypeError; `esc_html` su valori non scalari → "Array" nel testo. | Unit sui contratti con dati malformati. |
| 12 ✅ | C | `html_to_markdown()` | Le regex `<b…>`/`<i…>` catturano anche `<br>`, `<blockquote>`, `<img>`, `<iframe>`; liste annidate appiattite. | Unit con casi dedicati. |
| 13 ✅ | C | `class-dsar-log.php` (`mask_email`) | `substr`/`strlen` a byte: email con caratteri multibyte mascherate male. | Unit. |
| 14 ✅ | C | `uninstall.php` | Niente ciclo multisite; non rimuove il transient dell'updater. | Integration. |
| 15 ✅ | C | `class-updater.php` | `post_install` attiva il plugin anche se era disattivato; `zipball_url` letto senza controllo. Stesso codice condiviso con gli altri plugin DB: correggere ovunque. | Unit. |
| 16 ✅ | C | `class-embed-bridge.php` | Cache della scansione invalidata a ogni `save_post` (revisioni, autosalvataggi, ordini Woo); pattern `output=embed` attribuisce a Google Maps qualunque embed. Piattaforme dal filtro senza `patterns`/`blocks`/`label` → warning. | Unit + integration. |
| 17 ✅ | C | Fusi orari | Archivio in ora MySQL, log DSAR in ora WordPress; `strtotime` presuppone fuso PHP UTC. | Unit con fuso diverso. |
| 18 ✅ | C | `languages/` | Cartella assente ma caricata da `load_plugin_textdomain`. | — |
| 19 ✅ | C | Testo policy | La policy dice "entro un mese", il codice usa 30 giorni. Allineare il testo o il calcolo. | — |

Nessuna SQL injection trovata; nonce e capability presenti su ogni handler.

Legenda: ✅ corretto con test (`UpdaterTest` per il 15; il 18 non ha test:
è la cartella). Bug 15: corretto qui (`DB_GitHub_Updater` 1.1.0); il file è
condiviso da tutti i plugin DB e va copiato negli altri repository.
Bug 7 e 8 sono corretti in Fase 2 (causa del 7: la pagina collegata mancava
dal menu di destinazione); i test E2E che li provano arrivano in Fase 3.
Bug 19: allineato il calcolo al testo (un mese di calendario, art. 12.3).

---

## 3. Fase 0 — Infrastruttura

Riusare dal DB Cookie Manager (`../db-cookie-manager`), adattando prefissi e
nomi:

- [x] `composer.json`: `phpunit/phpunit ^9.6`, `yoast/phpunit-polyfills`;
      script `test:unit`, `test:integration`.
- [x] `phpunit.xml.dist` (suite `unit`) e `phpunit-integration.xml.dist`.
- [x] `tests/unit/bootstrap.php` con stub WordPress minimi (filtri con
      priorità e `accepted_args`, option, transient, `_doing_it_wrong`
      registrato) + `InfraTest`.
- [x] `bin/install-wp-tests.sh` (copiato dal Cookie Manager: supporta
      `trunk` e `curl -f`) e `tests/integration/bootstrap.php` +
      `InfraIntegrationTest`.
- [x] `package.json` + `package-lock.json` (Playwright, `@wordpress/env`,
      `@axe-core/playwright`), `playwright.config.js` con progetto `setup`.
- [x] `.wp-env.json`: plugin `.` + mu-plugin di fixture. Il Cookie Manager
      per l'ecosistema si aggiunge in Fase 3 (in CI non c'è `../`: servirà la
      sorgente GitHub `dadebertolino/db-cookie-manager`).
- [x] `tests/fixtures/dbph-e2e-fixture.php`: endpoint REST di reset
      (`dbph-e2e/v1/reset`, `/state`), plugin "finti" che dichiarano
      trattamenti, destinatari, consensi, exporter ed eraser (anche
      volutamente malformati, attivabili via reset), WooCommerce on/off.
- [x] `tests/e2e/helpers.js`, `auth.setup.js` (login admin), `infra.spec.js`.
- [x] `bin/setup-e2e.sh`: permalink, plugin attivo, WooCommerce installato ma
      spento (lo accende il reset), stato baseline.
- [x] CI: job `unit` (PHP 7.4–8.4), `integration` (MySQL, WordPress 6.0 e
      latest), `e2e` tramite workflow riutilizzabile; `nightly.yml` su
      WordPress trunk e PHP 8.4.
- [x] `.gitignore`: `composer.lock` resta ignorato (solo dipendenze di
      sviluppo, risolte per PHP); aggiunti `tests/e2e/.auth/`,
      `playwright-report/`, `test-results/`, `.phpunit.result.cache`.
- [x] `release.yml`: esclusi dallo ZIP `tests/`, `bin/`, `package*.json`,
      `playwright.config.js`, `phpunit*.xml.dist`, `.wp-env.json`,
      `TESTING*.md`; controllo che lo ZIP non contenga file di sviluppo.
- [x] `TESTING.md` con struttura e comandi.
- [x] Requisito WordPress 6.0 (decisione §7): header, controllo
      all'attivazione, `phpcs.xml.dist`, README, changelog (voce "Non rilasciata").

## 4. Fase 1 — Unit test (168 test, 2026-10-06)

- [x] **DSAR router** (`DsarRouterTest`): `normalize_export_response`,
      `normalize_erase_response` (tutte le forme), registrazione con callback
      non callable, chiavi vuote, etichetta di ripiego; callback che lanciano
      (bug 3).
- [x] **Registro trattamenti e alias legacy** (`RegisterTest`): voci non
      array, filtro che restituisce `null`/scalare, cache, `count_by_source()`;
      unione `dbseo_processing_register`, dedup per `id`, ricorsione evitata.
- [x] **Responsabili** (`ResponsabiliTest`): `sanitize_entry`, salvataggio,
      id stabili e univoci (bug 10), modelli dal filtro nel menu (bug 9).
- [x] **Archivio policy** (`PolicyArchiveTest`): `normalize_for_compare`.
- [x] **Generatore** (`PolicyGeneratorTest`): sezioni e numerazione con e
      senza sezione cookie, titolare, trattamenti, destinatari (dedup,
      responsabili dichiarati, voci malformate), paragrafo DSAR, filtri
      malformati (bug 2).
- [x] **Markdown** (`MarkdownTest`): titoli, inline, link, liste annidate e
      numerate, tabelle, `<br>`/`<blockquote>`/`<img>` (bug 12).
- [x] **Log DSAR** (`DsarLogTest`): `calculate_deadline` ai bordi e con fuso
      PHP diverso (bug 6, 17), `mask_email` multibyte (bug 13), `hash_email`,
      tipi/stati/canali.
- [x] **CSV** (`AdminCsvTest`): formula injection (`csv_row`), `sanitize_ymd`.
- [x] **Consensi** (`ConsentsRegisterTest`): `get_sources`, `query_all`
      (ordinamento, limite, sorgente singola), righe malformate (bug 11),
      fonti che lanciano (bug 3).
- [x] **Bridge Woo** (`WooBridgeTest`): trattamenti condizionali, gateway noti
      e sconosciuti, offline esclusi, sezione diritti.
- [x] **Bridge embed** (`EmbedBridgeTest`): catalogo, piattaforme dal filtro
      (bug 16), pattern LIKE, cache di scansione, manuali, pixel.

Verifica della regola §8: con i sorgenti di `main` falliscono i test di
tutti i bug corretti in questa fase.

## 5. Fase 2 — Integration test (2026-10-06)

WordPress + MySQL reali (`WP_UnitTestCase`), WordPress 6.0 e latest, più una
variante multisite.

- [x] **Ciclo DSAR completo** (`DsarLifecycleIntegrationTest`):
      `wp_create_user_request` → email di conferma (senza duplicati) →
      conferma → export (`completed`) → cancellazione completa, parziale
      (messaggi nelle note) e con eraser che lancia → scadenza via cron dei
      `pending` oltre 7 giorni. Bug 1 in `DsarRequestedAtIntegrationTest`.
- [x] **DSAR manuali e statistiche** (`DsarManualStatsIntegrationTest`):
      tutti i tipi artt. 15–22 e 7.3, modifica/eliminazione solo delle
      manuali, `get_stats` su ogni stato (bug 5), contatori SQL = badge PHP
      (bug 6, 19), retention.
- [x] **Archivio e pubblicazione** (`PolicyArchivePublishIntegrationTest`):
      deduplica, modifica manuale della pagina, `get_current_version_id()`
      con e senza option, ora locale (bug 17), backup pre-sovrascrittura mai
      corrente, neanche durante il salvataggio (bug 4), nessun duplicato
      aggiornando la pagina collegata.
- [x] **Generazione ed embed** (`GenerationEmbedIntegrationTest`): plugin
      finti sui filtri, alias legacy, filtro rotto; scansione SQL di
      post_content, blocchi Gutenberg e cache oEmbed; invalidazione della
      cache (bug 16).
- [x] **Schema e disinstallazione** (`SchemaUninstallIntegrationTest`):
      migrazioni log DSAR 1.0 → 2.0 e archivio 1.0 → 1.1, attivazione pulita,
      uninstall con e senza "conserva dati", multisite (bug 14).
- [x] **Retention DSAR** (decisione §7): opzione `dbph_dsar_retention_years`
      (default 5, 0 = mai), cron giornaliero, solo richieste chiuse.

## 6. Fase 3 — E2E (stima 30–40)

- [ ] **Titolare e pubblicazione**: salvataggio, pagina creata e impostata
      come pagina privacy di WordPress, rigenerazione, sovrascrittura di una
      pagina esistente con avviso, nessun duplicato (bug 7: due
      pubblicazioni di fila dal menu predefinito), export `.md`.
- [ ] **Impostazioni**: retention DSAR salvata e limitata a 0–20.
- [ ] **Responsabili**: aggiunta, modelli, salvataggio.
- [ ] **DSAR negli strumenti WordPress** (Strumenti → Esporta / Cancella dati
      personali): la richiesta compare nello storico DSAR con stato e date
      corretti; export CSV.
- [ ] **DSAR manuale**: form, modifica, eliminazione, avviso singolo (bug 8).
- [ ] **Registro consensi**: filtri, export CSV.
- [ ] **Storico policy**: elenco versioni, vista singola, confronto.
- [ ] **WooCommerce**: con un gateway online attivo compaiono trattamenti e
      destinatario.
- [ ] **Ecosistema con DB Cookie Manager** (entrambi montati in wp-env):
  - sezioni cookie importate nella policy;
  - un consenso dal banner del Cookie Manager registra
    `policy_version` = versione corrente dell'Hub;
  - con Meta Pixel attivo, Meta compare tra i destinatari;
  - trattamenti del Cookie Manager nel registro dell'Hub.
- [ ] **Accessibilità** (axe-core) delle pagine admin principali e della
      policy pubblicata.

## 7. Decisioni (prese il 2026-10-06)

- [x] Requisito minimo WordPress: da **5.8** a **6.0** come il Cookie Manager
      (header, controllo all'attivazione, README, changelog). Fatto in Fase 0,
      così la matrice CI parte già da 6.0.
- [x] Ordine: **Fase 0**, poi subito il **bug 1** come primo integration test
      (test rosso → correzione), poi Fasi 1 → 2 → 3.
- [x] Meno tag (2026-10-06): niente release per singola correzione o per
      fase. Le correzioni si accumulano su `main` (changelog "Non
      rilasciata") e si tagga solo per release cumulative: **1.8.0** quando
      sono chiuse le Fasi 1–2 (bug A e B corretti, retention DSAR), poi al
      massimo una release a fine Fase 3 se l'E2E porta altre correzioni. Un
      tag fuori programma solo per un problema che tocca dati legali in
      produzione.
- [x] Retention: solo sul **log DSAR**, opzione configurabile (default
      **5 anni**), cron che elimina le righe chiuse più vecchie. L'archivio
      policy **non** si tocca: le versioni sono citate da `policy_version` nei
      registri consensi di Cookie Manager e Form Builder (prova del consenso).
      Da implementare con i suoi test dopo la Fase 2.

## 8. Convenzioni

- Un branch e una PR in bozza per fase; merge con `--merge --delete-branch`.
- Ogni bug corretto ha un test che fallisce prima della correzione.
- Changelog nel README alla voce "Non rilasciata" a ogni PR; il numero di
  versione si decide al tag. Tag annotato `vX.Y.Z` solo per le release
  cumulative (§7) e dopo CI verde su `main` (la release parte dal tag e verifica che tag, header e
  costante `DBPH_VERSION` coincidano).
- Riferimento: `../db-cookie-manager/TESTING.md` e
  `../db-cookie-manager/tests/` per struttura, fixture e helper.
