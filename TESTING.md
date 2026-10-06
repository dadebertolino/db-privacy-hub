# Test & CI — DB Privacy Hub

Piramide dei test su GitHub Actions: `.github/workflows/ci.yml` a ogni push e
PR, `.github/workflows/nightly.yml` ogni notte. Gli E2E stanno in un workflow
riutilizzabile (`.github/workflows/e2e.yml`) chiamato da entrambi. La release
resta in `.github/workflows/release.yml`, sul tag `v*`.

Il piano di lavoro (bug noti, test da scrivere fase per fase) è in
`TESTING-PLAN.md`.

## I job della CI

| Job | Cosa verifica | Quando |
|-----|---------------|--------|
| **lint** | `php -l` su PHP 7.4 e 8.3 | ogni push/PR |
| **phpcs** | standard WordPress + compatibilità PHP 7.4+ | ogni push/PR |
| **unit** | logica PHP pura (PHPUnit), matrice PHP 7.4–8.4 | ogni push/PR |
| **integration** | WordPress + MySQL reali, su WordPress 6.0 (minimo), ultima versione e ultima versione multisite | dopo lint |
| **e2e** | browser reale su wp-env (Playwright) | dopo lint, phpcs e unit |

Le dipendenze npm sono fissate da `package-lock.json` (`npm ci`, con cache npm
e cache dei browser Playwright). `composer.lock` non è versionato: ci sono solo
dipendenze di sviluppo, risolte per la versione PHP di ogni job.

## Run notturna

`nightly.yml` gira ogni notte alle 03:47 UTC (e a mano da *Actions → Nightly →
Run workflow*) contro ciò che cambia senza un nostro commit:

| Variante | Perché |
|----------|--------|
| E2E su WordPress trunk (PHP 8.3) | avvisa prima che una nuova versione di WordPress rompa gli strumenti privacy o il plugin |
| E2E su PHP 8.4 | la CI esegue gli E2E solo su PHP 8.1 |
| Integration su WordPress trunk (PHP 8.4) | stesse API WordPress, senza browser |

PHP 7.4 non ha una variante E2E (l'immagine wp-env per 7.4 non si costruisce
più): resta coperto dagli unit test a ogni push.

## Perché questa struttura

L'Hub non ha frontend dinamico: il suo valore sta nei **contratti tra plugin**
(filtri che gli altri plugin DB alimentano), nella **generazione e
nell'archivio della Privacy Policy** e nel **log DSAR** agganciato agli
strumenti privacy di WordPress. Per questo la piramide pesa su unit e
integration:

- **unit** — contratti e logica pura: normalizzazione delle risposte DSAR,
  registro trattamenti e alias legacy, generatore, Markdown, scadenze,
  mascheramento email. Girano senza WordPress: gli stub di
  `tests/unit/bootstrap.php` rispettano priorità e numero di argomenti dei
  filtri, perché l'ordine di merge fa parte del contratto.
- **integration** — tutto ciò che dipende dal core: tabelle, migrazioni,
  `WP_User_Request` e il suo ciclo di vita, cron, disinstallazione.
- **e2e** — l'admin, il flusso negli strumenti di WordPress (Esporta /
  Cancella dati personali) e, dalla Fase 3, l'ecosistema con DB Cookie Manager.

## Unit

`tests/unit/bootstrap.php` definisce gli stub WordPress e carica tutte le
classi di `inc/` (sono solo definizioni: nessun `init()` parte al require).

| Helper | A cosa serve |
|--------|--------------|
| `dbph_test_reset()` | azzera option, transient, filtri, `_doing_it_wrong` e le cache statiche delle classi; da chiamare in `set_up()` |
| `dbph_test_call_private( $class, $method, $args )` | invoca un metodo statico privato (es. `mask_email`) |
| `dbph_test_set_static( $class, $property, $value )` | imposta una proprietà statica privata |
| `$GLOBALS['__dbph_doing_it_wrong']` | chiamate a `_doing_it_wrong` registrate durante il test |

I test estendono `Yoast\PHPUnitPolyfills\TestCases\TestCase` (metodi
`set_up()` / `tear_down()`), così girano uguali da PHP 7.4 a 8.4.

## Integration

`tests/integration/bootstrap.php` carica il plugin su `muplugins_loaded`:
`dbph_boot()` gira su `plugins_loaded@5` e crea le tabelle fuori dalla
transazione dei test. I test estendono `WP_UnitTestCase` e il loro file deve
finire in `IntegrationTest.php`.

Note:

- ogni test parte da tabelle vuote con `DELETE FROM` (non `TRUNCATE`, che
  chiuderebbe la transazione del test);
- `SchemaUninstallIntegrationTest` fa DDL vero (ALTER, DROP): disattiva le
  tabelle temporanee della test suite e in `tear_down()` ricrea tabelle e
  option, con `COMMIT`;
- i test `@group ms-required` girano solo con `WP_MULTISITE=1` (variante
  multisite della CI), altrimenti vengono saltati;
- il flusso di cancellazione usa le funzioni di
  `wp-admin/includes/privacy-tools.php`, caricate dal test come fa l'admin.

```bash
# Variante multisite in locale
WP_MULTISITE=1 WP_TESTS_DIR=/tmp/wordpress-tests-lib composer run test:integration
```

## Fixture E2E

Nessuna dipendenza da siti esterni. Il mu-plugin
`tests/fixtures/dbph-e2e-fixture.php` (montato solo in wp-env, non fa parte del
pacchetto distribuito) fornisce:

| Risorsa | A cosa serve |
|---------|--------------|
| `POST /?rest_route=/dbph-e2e/v1/reset` | Riporta l'Hub allo stato **baseline**: option dell'Hub azzerate, titolare di prova configurato, log DSAR e archivio policy vuoti, nessuna richiesta privacy del core, nessuna pagina privacy, WooCommerce spento, plugin finti spenti. Accetta `titolare` (oggetto o `false`), `fakes`, `consents_rows`, `privacy_page`, `seed_dsar`, `seed_versions`, `woocommerce`. |
| `GET /?rest_route=/dbph-e2e/v1/state` | Stato lato server: titolare, plugin finti accesi, `dbph_page_id`, pagina privacy di WordPress, tutte le pagine `privacy-policy*` (duplicati compresi), numero di versioni e versione corrente, righe del log DSAR. |

### Plugin finti (`fakes`)

| Nome | Cosa dichiara |
|------|---------------|
| `register` | due trattamenti validi (`e2e_newsletter`, `e2e_contatti`) |
| `legacy_register` | due voci su `dbseo_processing_register`, una con id duplicato |
| `destinatari` | il destinatario "E2E Mailer Srl" |
| `consents` | la fonte consensi `e2e_consents` (righe: `consents_rows`, default 3) |
| `dsar` | exporter ed eraser `e2e-plugin`; l'eraser trattiene dati (esito *parziale*) |
| `bad_register` | voci non array e senza id nel registro |
| `bad_sections` | `dbph_policy_sections` restituisce `null` |
| `bad_html` | `dbph_policy_html` restituisce un array |
| `throwing_dsar` | exporter ed eraser che lanciano eccezione |
| `throwing_consents` | fonte consensi che lancia eccezione |
| `bad_consents` | righe consensi con campi non scalari |

I plugin finti malformati riproducono i bug noti del piano: finché un bug non è
corretto, lo spec che li accende fallisce di proposito.

## Progetti Playwright

- **setup** (`tests/e2e/auth.setup.js`): reset baseline + login admin
  (`admin`/`password` di wp-env, sovrascrivibili con `WP_ADMIN_USER` /
  `WP_ADMIN_PASS`). Salva la sessione in `tests/e2e/.auth/admin.json`
  (ignorata da git).
- **chromium**: tutti gli spec, dopo `setup`. Gli spec admin usano
  `test.use( { storageState: ADMIN_STATE } )`; gli URL delle pagine admin sono
  in `ADMIN_PAGES` (`tests/e2e/helpers.js`).

`tests/e2e/infra.spec.js` verifica l'infrastruttura stessa (reset, stato,
sessione admin, plugin finti): se fallisce, i risultati degli altri spec non
sono attendibili.

## Eseguire in locale

```bash
# Prerequisiti: Docker attivo, Node 22, PHP 8.x, Composer.

# --- lint + unit ---
composer install
composer run lint          # php -l ricorsivo
composer run phpcs         # standard WordPress
composer run test:unit     # PHPUnit

# --- integration (MySQL locale) ---
bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer run test:integration

# --- e2e ---
npm ci
npx playwright install --with-deps chromium
npm run env:start          # avvia wp-env (Docker)
npm run env:setup          # plugin attivo, WooCommerce installato, baseline
npm run test:e2e
npm run env:stop

# Variante WordPress/PHP come nella run notturna:
WP_ENV_CORE=WordPress/WordPress#master WP_ENV_PHP_VERSION=8.3 npm run env:start
```

## Release

Il tag annotato `vX.Y.Z` si crea solo dopo CI verde su `main`. `release.yml`
verifica che tag, header `Version:` e costante `DBPH_VERSION` coincidano,
costruisce lo ZIP con la cartella radice `db-privacy-hub/` (richiesta da
`DB_GitHub_Updater`), controlla che non contenga file di sviluppo e lo allega
alla GitHub Release.
