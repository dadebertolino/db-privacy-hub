<?php
/**
 * Plugin Name: DBPH E2E Fixture
 * Description: Costruisce condizioni di test deterministiche per gli E2E di
 *              DB Privacy Hub. Attivo SOLO in ambiente wp-env (mu-plugin).
 *              NON fa parte del pacchetto distribuito.
 *
 * Fornisce:
 *  - REST POST /dbph-e2e/v1/reset → riporta l'Hub allo stato baseline
 *                   (vedi dbph_e2e_reset_state()). Chiamato dai test.
 *  - REST GET  /dbph-e2e/v1/state → stato lato server per le asserzioni
 *                   (option, conteggi log DSAR e archivio, pagine privacy).
 *  - Plugin "finti" che alimentano i contratti pubblici dell'Hub
 *    (trattamenti, destinatari, consensi, exporter/eraser DSAR), anche in
 *    versione volutamente malformata. Si accendono via reset (`fakes`) e
 *    restano attivi per le richieste successive.
 *
 * @package DBPH\Tests\Fixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin finti disponibili. Tutti spenti in baseline: ogni spec accende
 * quelli che gli servono.
 *
 *  - register        due trattamenti validi in dbph_processing_register
 *  - legacy_register un trattamento sul filtro legacy dbseo_processing_register
 *                    (con lo stesso id di uno dei validi, per la deduplica)
 *  - destinatari     un destinatario in dbph_policy_destinatari
 *  - consents        una fonte in dbph_consents_register (righe da `consents_rows`)
 *  - dsar            exporter + eraser validi (l'eraser trattiene dati: partial)
 *  - bad_register    voci non array / senza id nel registro
 *  - bad_sections    dbph_policy_sections restituisce null (bug 2)
 *  - bad_html        dbph_policy_html restituisce un array (bug 2)
 *  - throwing_dsar   exporter ed eraser che lanciano eccezione (bug 3)
 *  - throwing_consents fonte consensi che lancia eccezione (bug 3)
 *  - bad_consents    righe consensi malformate (bug 11)
 *  - woo_gateway     gateway di pagamento online "stripe" abilitato
 *                    (richiede woocommerce: true)
 *  - resp_template   modello di responsabile "dpo_esterno" dal filtro (bug 9)
 *
 * @return string[]
 */
function dbph_e2e_fake_names() {
	return array(
		'register',
		'legacy_register',
		'destinatari',
		'consents',
		'dsar',
		'bad_register',
		'bad_sections',
		'bad_html',
		'throwing_dsar',
		'throwing_consents',
		'bad_consents',
		'woo_gateway',
		'resp_template',
	);
}

/**
 * Titolare baseline: la generazione della policy funziona senza passare
 * dall'admin.
 *
 * @return array
 */
function dbph_e2e_baseline_titolare() {
	return array(
		'nome'      => 'E2E Test Srl',
		'piva'      => '01234567890',
		'indirizzo' => 'Via dei Test 1, 12084 Mondovì (CN)',
		'email'     => 'privacy@e2e.test',
		'pec'       => '',
		'dpo'       => '',
	);
}

/**
 * Option dell'Hub azzerate dal reset (stesse di uninstall.php, salvo i
 * marker di versione e schema che servono al plugin attivo).
 *
 * @return string[]
 */
function dbph_e2e_hub_options() {
	return array(
		'dbph_titolare_nome',
		'dbph_titolare_piva',
		'dbph_titolare_indirizzo',
		'dbph_titolare_email',
		'dbph_titolare_pec',
		'dbph_titolare_dpo',
		'dbph_page_title',
		'dbph_page_slug',
		'dbph_page_id',
		'dbph_responsabili',
		'dbph_show_rights_howto',
		'dbph_policy_current_version',
		'dbph_preserve_data_on_uninstall',
		'dbph_embed_manual',
		'dbph_social_pages_mention',
	);
}

/**
 * Riporta l'Hub a uno stato noto.
 *
 * $args (tutti opzionali):
 *  - titolare      array|false Campi titolare che sovrascrivono la baseline;
 *                              false = titolare non configurato.
 *  - fakes         string[]    Plugin finti da accendere (dbph_e2e_fake_names()).
 *  - consents_rows int         Righe restituite dalla fonte consensi finta (default 3).
 *  - privacy_page  bool        Crea una bozza "Privacy Policy" impostata come
 *                              pagina privacy di WordPress (come una nuova
 *                              installazione). Default: nessuna pagina.
 *  - seed_dsar     array       Richieste manuali nel log DSAR:
 *                              [{type, email, status, days_ago, count}].
 *  - seed_versions string[]    Versioni da inserire nell'archivio policy.
 *  - cookie_manager bool       Attiva DB Cookie Manager (montato da .wp-env.json,
 *                              spento in baseline: aggiungerebbe sezioni e
 *                              trattamenti alla policy di ogni spec).
 *                              Con `meta_pixel` (ID) ne attiva il Meta Pixel.
 *  - woocommerce   bool        Attiva WooCommerce (installato da setup-e2e.sh).
 *                              Default spento: il bridge Woo aggiungerebbe
 *                              trattamenti e destinatari a ogni spec.
 *                              L'attivazione vale dalla richiesta successiva.
 *
 * @param array $args
 * @return array|WP_Error Stato risultante (vedi dbph_e2e_get_state()).
 */
function dbph_e2e_reset_state( $args = array() ) {
	global $wpdb;

	$args = is_array( $args ) ? $args : array();

	// Option dell'Hub.
	foreach ( dbph_e2e_hub_options() as $option ) {
		delete_option( $option );
	}
	delete_transient( 'dbph_embed_scan' );

	// Titolare.
	$titolare = array_key_exists( 'titolare', $args ) ? $args['titolare'] : array();
	if ( false !== $titolare ) {
		$titolare = array_merge( dbph_e2e_baseline_titolare(), is_array( $titolare ) ? $titolare : array() );
		foreach ( $titolare as $key => $value ) {
			update_option( 'dbph_titolare_' . sanitize_key( $key ), (string) $value );
		}
	}

	// WooCommerce: attivo solo su richiesta.
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$woo = 'woocommerce/woocommerce.php';
	if ( ! empty( $args['woocommerce'] ) ) {
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $woo ) ) {
			return new WP_Error( 'dbph_e2e_no_woo', 'WooCommerce non installato: eseguire bin/setup-e2e.sh.', array( 'status' => 500 ) );
		}
		if ( ! is_plugin_active( $woo ) ) {
			activate_plugin( $woo );
		}
		update_option( 'woocommerce_coming_soon', 'no' );
		// Alla prima attivazione Woo reindirizza l'admin alla procedura guidata.
		delete_transient( '_wc_activation_redirect' );
	} elseif ( is_plugin_active( $woo ) ) {
		deactivate_plugins( $woo, true );
	}

	// DB Cookie Manager: attivo solo su richiesta, registro consensi vuoto.
	$cm = dbph_e2e_cookie_manager_file();
	if ( ! empty( $args['cookie_manager'] ) ) {
		if ( ! $cm ) {
			return new WP_Error( 'dbph_e2e_no_cm', 'DB Cookie Manager non montato: controllare .wp-env.json.', array( 'status' => 500 ) );
		}
		if ( ! is_plugin_active( $cm ) ) {
			activate_plugin( $cm );
		}
		$cm_settings                       = (array) get_option( 'dbcm_settings', array() );
		$cm_settings['meta_pixel_enabled'] = ! empty( $args['meta_pixel'] );
		$cm_settings['meta_pixel_id']      = ! empty( $args['meta_pixel'] ) ? (string) $args['meta_pixel'] : '';
		update_option( 'dbcm_settings', $cm_settings );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'dbcm_consent_log' );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dbcm\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dbcm\\_rl\\_%'" );
	} elseif ( $cm && is_plugin_active( $cm ) ) {
		deactivate_plugins( $cm, true );
	}

	// Plugin finti, letti dai filtri a ogni richiesta.
	$fakes = isset( $args['fakes'] ) && is_array( $args['fakes'] )
		? array_values( array_intersect( dbph_e2e_fake_names(), $args['fakes'] ) )
		: array();
	update_option(
		'dbph_e2e_fakes',
		array(
			'enabled'       => $fakes,
			'consents_rows' => isset( $args['consents_rows'] ) ? max( 0, (int) $args['consents_rows'] ) : 3,
		)
	);

	// Log DSAR e archivio policy vuoti; niente richieste privacy del core.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME );
	$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . DBPH_Policy_Archive::TABLE_NAME );
	// phpcs:enable
	$requests = get_posts(
		array(
			'post_type'      => 'user_request',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $requests as $request_id ) {
		wp_delete_post( $request_id, true );
	}

	// Pagine privacy: tutte via (anche i duplicati -2, -3 del bug 7).
	foreach ( dbph_e2e_privacy_pages() as $page_id ) {
		wp_delete_post( $page_id, true );
	}
	update_option( 'wp_page_for_privacy_policy', 0 );
	if ( ! empty( $args['privacy_page'] ) ) {
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Privacy Policy',
				'post_name'    => 'privacy-policy',
				'post_content' => '<p>Testo privacy preesistente scritto a mano.</p>',
			)
		);
		update_option( 'wp_page_for_privacy_policy', (int) $page_id );
	}

	// Seed del log DSAR (richieste manuali, con data retrodatata).
	if ( ! empty( $args['seed_dsar'] ) && is_array( $args['seed_dsar'] ) ) {
		foreach ( $args['seed_dsar'] as $i => $seed ) {
			$count    = isset( $seed['count'] ) ? max( 1, (int) $seed['count'] ) : 1;
			$days_ago = isset( $seed['days_ago'] ) ? max( 0, (int) $seed['days_ago'] ) : 0;
			for ( $n = 0; $n < $count; $n++ ) {
				DBPH_DSAR_Log::insert_manual(
					array(
						'request_type' => isset( $seed['type'] ) ? (string) $seed['type'] : 'export',
						'email'        => isset( $seed['email'] ) ? (string) $seed['email'] : "utente{$i}-{$n}@e2e.test",
						'status'       => isset( $seed['status'] ) ? (string) $seed['status'] : 'received',
						'requested_at' => wp_date( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ),
					)
				);
			}
		}
	}

	// Seed dell'archivio policy.
	if ( ! empty( $args['seed_versions'] ) && is_array( $args['seed_versions'] ) ) {
		foreach ( $args['seed_versions'] as $n => $content ) {
			DBPH_Policy_Archive::save( (string) $content, 'Seed E2E #' . ( $n + 1 ) );
		}
	}

	return dbph_e2e_get_state();
}

/**
 * File principale di DB Cookie Manager, se montato da wp-env.
 *
 * @return string Percorso relativo a WP_PLUGIN_DIR, '' se assente.
 */
function dbph_e2e_cookie_manager_file() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	foreach ( array_keys( get_plugins() ) as $file ) {
		if ( basename( $file ) === 'db-cookie-manager.php' ) {
			return $file;
		}
	}
	return '';
}

/**
 * ID delle pagine con slug privacy-policy, privacy-policy-2, …
 *
 * @return int[]
 */
function dbph_e2e_privacy_pages() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	return array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status <> 'auto-draft' AND post_name LIKE %s",
				$wpdb->esc_like( 'privacy-policy' ) . '%'
			)
		)
	);
}

/**
 * Stato corrente per le asserzioni lato server.
 *
 * @return array
 */
function dbph_e2e_get_state() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$titolare = array();
	foreach ( array_keys( dbph_e2e_baseline_titolare() ) as $key ) {
		$titolare[ $key ] = (string) get_option( 'dbph_titolare_' . $key, '' );
	}
	$fakes = get_option( 'dbph_e2e_fakes', array() );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$dsar_rows = $wpdb->get_results(
		'SELECT id, request_id, source, request_type, status, requested_at, confirmed_at, completed_at FROM '
		. $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME . ' ORDER BY id ASC',
		ARRAY_A
	);
	$versions  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . DBPH_Policy_Archive::TABLE_NAME );
	// phpcs:enable

	// Ultimo consenso registrato dal Cookie Manager, se attivo.
	$cm_last = null;
	$cm      = dbph_e2e_cookie_manager_file();
	if ( $cm && is_plugin_active( $cm ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$cm_last = $wpdb->get_row( 'SELECT consent_type, policy_version FROM ' . $wpdb->prefix . 'dbcm_consent_log ORDER BY id DESC LIMIT 1', ARRAY_A );
	}

	return array(
		'titolare'        => $titolare,
		'cookie_manager'  => $cm && is_plugin_active( $cm ),
		'cm_last_consent' => $cm_last,
		'fakes'           => isset( $fakes['enabled'] ) ? $fakes['enabled'] : array(),
		'woocommerce'     => is_plugin_active( 'woocommerce/woocommerce.php' ),
		'page_id'         => (int) get_option( 'dbph_page_id', 0 ),
		'wp_privacy_page' => (int) get_option( 'wp_page_for_privacy_policy', 0 ),
		'privacy_pages'   => dbph_e2e_privacy_pages(),
		'policy_versions' => $versions,
		'current_version' => DBPH_Policy_Archive::get_current_version_id(),
		'dsar'            => $dsar_rows,
	);
}

/**
 * Endpoint REST. Senza autenticazione di proposito: il mu-plugin è montato
 * solo da .wp-env.json e non esiste nel pacchetto distribuito. Usare
 * ?rest_route= nei test, così non dipende dai permalink.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'dbph-e2e/v1',
			'/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					return rest_ensure_response( dbph_e2e_reset_state( (array) $request->get_json_params() ) );
				},
			)
		);
		register_rest_route(
			'dbph-e2e/v1',
			'/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					return rest_ensure_response( dbph_e2e_get_state() );
				},
			)
		);
	}
);

/* -----------------------------------------------------------------------------
 * Plugin finti.
 * -------------------------------------------------------------------------- */

/**
 * Il plugin finto è acceso?
 *
 * @param string $name
 * @return bool
 */
function dbph_e2e_fake_on( $name ) {
	$fakes = get_option( 'dbph_e2e_fakes', array() );
	return isset( $fakes['enabled'] ) && in_array( $name, (array) $fakes['enabled'], true );
}

add_filter(
	'dbph_processing_register',
	function ( $register ) {
		if ( dbph_e2e_fake_on( 'register' ) ) {
			$register[] = array(
				'id'             => 'e2e_newsletter',
				'label'          => 'E2E Newsletter',
				'status'         => 'active',
				'purpose'        => 'Invio della newsletter agli iscritti.',
				'legal_basis'    => 'Consenso (art. 6.1.a GDPR).',
				'data_collected' => 'Email, data di iscrizione.',
				'retention'      => 'Fino alla disiscrizione.',
				'transfers'      => 'Nessuno.',
			);
			$register[] = array(
				'id'             => 'e2e_contatti',
				'label'          => 'E2E Modulo contatti',
				'status'         => 'active',
				'purpose'        => 'Risposta alle richieste di contatto.',
				'legal_basis'    => 'Misure precontrattuali (art. 6.1.b GDPR).',
				'data_collected' => 'Nome, email, messaggio.',
				'retention'      => '12 mesi.',
				'transfers'      => 'Nessuno.',
			);
		}
		if ( dbph_e2e_fake_on( 'bad_register' ) ) {
			$register[] = 'non sono un array';
			$register[] = array( 'label' => 'E2E Voce senza id' );
		}
		return $register;
	}
);

add_filter(
	'dbseo_processing_register',
	function ( $register ) {
		if ( dbph_e2e_fake_on( 'legacy_register' ) ) {
			$register[] = array(
				'id'             => 'e2e_newsletter', // Duplicato: deve vincere la voce nuova.
				'label'          => 'E2E Newsletter (legacy)',
				'status'         => 'active',
				'purpose'        => 'Legacy.',
				'legal_basis'    => 'Legacy.',
				'data_collected' => 'Legacy.',
				'retention'      => 'Legacy.',
				'transfers'      => 'Legacy.',
			);
			$register[] = array(
				'id'             => 'e2e_legacy_seo',
				'label'          => 'E2E Trattamento legacy SEO',
				'status'         => 'active',
				'purpose'        => 'Dichiarato con il filtro legacy.',
				'legal_basis'    => 'Legittimo interesse (art. 6.1.f GDPR).',
				'data_collected' => 'Nessuno.',
				'retention'      => 'Nessuna.',
				'transfers'      => 'Nessuno.',
			);
		}
		return $register;
	}
);

add_filter(
	'dbph_policy_destinatari',
	function ( $destinatari ) {
		if ( dbph_e2e_fake_on( 'destinatari' ) ) {
			$destinatari[] = array(
				'name'        => 'E2E Mailer Srl',
				'description' => 'Servizio di invio email del plugin finto E2E.',
				'country'     => 'Italia',
			);
		}
		return $destinatari;
	}
);

add_filter(
	'dbph_policy_sections',
	function ( $sections ) {
		return dbph_e2e_fake_on( 'bad_sections' ) ? null : $sections;
	},
	99
);

add_filter(
	'dbph_policy_html',
	function ( $html ) {
		return dbph_e2e_fake_on( 'bad_html' ) ? array( 'non', 'una', 'stringa' ) : $html;
	},
	99
);

/**
 * Righe della fonte consensi finta: deterministiche, dalla più recente.
 *
 * @param int $count
 * @return array
 */
function dbph_e2e_consent_rows( $count ) {
	$rows = array();
	for ( $i = 1; $i <= $count; $i++ ) {
		$rows[] = array(
			'id'             => 'e2e-' . $i,
			'timestamp'      => wp_date( 'Y-m-d H:i:s', time() - $i * HOUR_IN_SECONDS ),
			'subject'        => 'u***' . $i . '@e2e.test',
			'consent_type'   => 0 === $i % 2 ? 'form:newsletter' : 'form:contatti',
			'consent_text'   => 'Acconsento al trattamento dei dati (E2E #' . $i . ').',
			'policy_version' => 0,
			'extra'          => array( 'fixture' => true ),
		);
	}
	if ( dbph_e2e_fake_on( 'bad_consents' ) ) {
		$rows[] = array(
			'id'             => 'e2e-bad',
			'timestamp'      => wp_date( 'Y-m-d H:i:s' ),
			'subject'        => array( 'non', 'scalare' ),
			'consent_type'   => null,
			'consent_text'   => array( 'testo' => 'non stringa' ),
			'policy_version' => 'abc',
			'extra'          => 'non array',
		);
	}
	return $rows;
}

add_filter(
	'dbph_consents_register',
	function ( $sources ) {
		if ( dbph_e2e_fake_on( 'consents' ) || dbph_e2e_fake_on( 'bad_consents' ) ) {
			$sources['e2e_consents'] = array(
				'label' => 'E2E Consensi',
				'icon'  => 'forms',
				'count' => function ( $args = array() ) {
					$fakes = get_option( 'dbph_e2e_fakes', array() );
					return count( dbph_e2e_consent_rows( isset( $fakes['consents_rows'] ) ? (int) $fakes['consents_rows'] : 3 ) );
				},
				'query' => function ( $args = array() ) {
					$fakes = get_option( 'dbph_e2e_fakes', array() );
					$rows  = dbph_e2e_consent_rows( isset( $fakes['consents_rows'] ) ? (int) $fakes['consents_rows'] : 3 );
					$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
					return $limit > 0 ? array_slice( $rows, 0, $limit ) : $rows;
				},
			);
		}
		if ( dbph_e2e_fake_on( 'throwing_consents' ) ) {
			$sources['e2e_throwing'] = array(
				'label' => 'E2E Fonte rotta',
				'icon'  => 'warning',
				'count' => function () {
					throw new RuntimeException( 'Fonte consensi E2E rotta.' );
				},
				'query' => function () {
					throw new RuntimeException( 'Fonte consensi E2E rotta.' );
				},
			);
		}
		return $sources;
	}
);

add_filter(
	'dbph_user_data_exporters',
	function ( $exporters ) {
		if ( dbph_e2e_fake_on( 'dsar' ) ) {
			$exporters['e2e-plugin'] = array(
				'label'    => 'E2E Plugin',
				'callback' => function ( $email ) {
					return array(
						'data' => array(
							array(
								'group_id'    => 'e2e-plugin',
								'group_label' => 'E2E Plugin',
								'item_id'     => 'e2e-' . md5( $email ),
								'data'        => array(
									array(
										'name'  => 'Iscritto dal',
										'value' => '2026-01-01',
									),
								),
							),
						),
						'done' => true,
					);
				},
			);
		}
		if ( dbph_e2e_fake_on( 'throwing_dsar' ) ) {
			$exporters['e2e-throwing'] = array(
				'label'    => 'E2E Exporter rotto',
				'callback' => function () {
					throw new RuntimeException( 'Exporter E2E rotto.' );
				},
			);
		}
		return $exporters;
	}
);

add_filter(
	'dbph_user_data_erasers',
	function ( $erasers ) {
		if ( dbph_e2e_fake_on( 'dsar' ) ) {
			$erasers['e2e-plugin'] = array(
				'label'    => 'E2E Plugin',
				'callback' => function () {
					return array(
						'items_removed'  => true,
						'items_retained' => true,
						'messages'       => array( 'E2E: dati fiscali conservati 10 anni.' ),
						'done'           => true,
					);
				},
			);
		}
		if ( dbph_e2e_fake_on( 'throwing_dsar' ) ) {
			$erasers['e2e-throwing'] = array(
				'label'    => 'E2E Eraser rotto',
				'callback' => function () {
					throw new RuntimeException( 'Eraser E2E rotto.' );
				},
			);
		}
		return $erasers;
	}
);

/* -----------------------------------------------------------------------------
 * Gateway WooCommerce finto (fake "woo_gateway").
 * -------------------------------------------------------------------------- */

/**
 * Dichiara il gateway finto quando WooCommerce è caricato. Gateway online
 * con id "stripe": il bridge Woo lo riconosce come Stripe, Inc. Abilitato
 * senza passare dalle impostazioni.
 */
function dbph_e2e_define_gateway() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) || class_exists( 'DBPH_E2E_Gateway' ) ) {
		return;
	}
	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
	class DBPH_E2E_Gateway extends WC_Payment_Gateway {
		public function __construct() {
			$this->id                 = 'stripe';
			$this->method_title       = 'Stripe (E2E)';
			$this->method_description = 'Gateway finto per gli E2E.';
			$this->title              = 'Carta (E2E)';
			$this->enabled            = 'yes';
		}
	}
	// phpcs:enable
}
add_action( 'plugins_loaded', 'dbph_e2e_define_gateway', 20 );

add_filter(
	'woocommerce_payment_gateways',
	function ( $gateways ) {
		if ( class_exists( 'DBPH_E2E_Gateway' ) && dbph_e2e_fake_on( 'woo_gateway' ) ) {
			$gateways[] = 'DBPH_E2E_Gateway';
		}
		return $gateways;
	}
);

add_filter(
	'dbph_responsabili_templates',
	function ( $templates ) {
		if ( dbph_e2e_fake_on( 'resp_template' ) ) {
			$templates['dpo_esterno'] = array(
				'label' => 'DPO esterno (E2E)',
				'nome'  => '[Nome DPO]',
				'ruolo' => 'Responsabile della protezione dei dati',
			);
		}
		return $templates;
	}
);
