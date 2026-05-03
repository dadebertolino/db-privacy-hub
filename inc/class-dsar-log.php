<?php
/**
 * DBPH_DSAR_Log — Registro delle richieste WordPress Privacy Tools.
 *
 * Tiene traccia di ogni richiesta di accesso (art. 15 GDPR) o cancellazione
 * (art. 17 GDPR) gestita dal sito tramite Strumenti → Esporta/Cancella dati
 * personali. Persiste in tabella custom (wp_dbph_dsar_log).
 *
 * Si aggancia agli hook che WordPress emette durante il ciclo di vita di
 * una richiesta:
 *  - user_request_action_confirmed       quando l'utente conferma via email
 *  - wp_privacy_personal_data_export_file  quando il file di export è pronto
 *  - wp_privacy_personal_data_erased       quando l'erase è completato
 *
 * Nota: WordPress mantiene le richieste come CPT (post_type=user_request)
 * solo finché l'admin non le cancella. Questa classe ne conserva uno storico
 * indipendente che resta anche dopo la cancellazione dei CPT, soddisfacendo
 * l'onere di accountability dell'art. 5.2 GDPR.
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_DSAR_Log' ) ) {

	class DBPH_DSAR_Log {

		const TABLE_NAME    = 'dbph_dsar_log';
		const SCHEMA_VERSION = '2.0';
		const SCHEMA_OPTION  = 'dbph_dsar_log_schema';

		/**
		 * Valori consentiti per la colonna `source`.
		 *
		 * - wp_native:       richiesta passata via Strumenti → Esporta/Cancella
		 *                    dati personali di WordPress (flusso standard)
		 * - manual:          richiesta arrivata fuori dal sistema (email, PEC,
		 *                    raccomandata) e registrata a mano dall'admin
		 * - consent_revoked: revoca del consenso ai cookie via Cookie Manager
		 *                    (riservato per future integrazioni, non emesso da
		 *                    nessuno nella 1.2.0)
		 */
		const SOURCE_WP_NATIVE       = 'wp_native';
		const SOURCE_MANUAL          = 'manual';
		const SOURCE_CONSENT_REVOKED = 'consent_revoked';

		/**
		 * Tipi di richiesta supportati. I primi due (export/erase) coincidono
		 * con i diritti gestiti dal flusso WordPress nativo. Gli altri sono
		 * inseribili solo via flusso manuale.
		 */
		const TYPE_EXPORT       = 'export';        // art. 15 GDPR
		const TYPE_ERASE        = 'erase';         // art. 17 GDPR
		const TYPE_RECTIFY      = 'rectify';       // art. 16 GDPR
		const TYPE_RESTRICT     = 'restrict';      // art. 18 GDPR
		const TYPE_PORTABILITY  = 'portability';   // art. 20 GDPR (export è anche portabilità, ma teniamo distinto)
		const TYPE_OBJECT       = 'object';        // art. 21 GDPR (opposizione)
		const TYPE_AUTOMATED    = 'automated';     // art. 22 GDPR (no decisioni automatizzate)
		const TYPE_CONSENT_REV  = 'consent_revoke';// art. 7.3 GDPR (revoca consenso)

		public static function init() {
			// Schema upgrade just-in-time (anche se l'activation hook non gira).
			self::maybe_upgrade_schema();

			// Hook ciclo vita richieste WP Privacy Tools.
			//
			// 1.2.0: aggancio anche al filter `user_request_action_email_content` che WP
			// emette PRIMA dell'invio dell'email di conferma. Questo permette di registrare
			// la richiesta come 'pending' anche quando l'utente non clicca mai il link
			// (accountability completa). Quando arriva la conferma, lo stato passa a
			// 'confirmed' tramite l'esistente on_request_confirmed.
			add_filter( 'user_request_action_email_content', array( __CLASS__, 'on_request_email_sent' ),  10, 2 );
			add_action( 'user_request_action_confirmed',     array( __CLASS__, 'on_request_confirmed' ),   10, 1 );
			add_action( 'wp_privacy_personal_data_export_file', array( __CLASS__, 'on_export_done' ),      10, 1 );
			add_action( 'wp_privacy_personal_data_erased',      array( __CLASS__, 'on_erase_done' ),       10, 1 );

			// Cron giornaliero: marca come 'expired' le richieste pending da > 7 giorni.
			add_action( 'dbph_dsar_cleanup_pending', array( __CLASS__, 'cron_expire_pending' ) );
			if ( ! wp_next_scheduled( 'dbph_dsar_cleanup_pending' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dbph_dsar_cleanup_pending' );
			}
		}

		/* =====================================================================
		 * Schema
		 * ================================================================== */

		public static function maybe_upgrade_schema() {
			$installed = get_option( self::SCHEMA_OPTION, '' );
			if ( $installed === self::SCHEMA_VERSION ) {
				return;
			}
			// dbDelta è additivo: aggiunge le colonne mancanti, conserva i dati.
			self::create_table();

			// Migrazione 1.0 → 2.0: valorizza `source` sulle righe pre-esistenti
			// come 'wp_native' (era l'unico flusso disponibile nella 1.0).
			if ( $installed === '1.0' ) {
				self::migrate_v1_to_v2();
			}

			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}

		public static function create_table() {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$table   = $wpdb->prefix . self::TABLE_NAME;
			$charset = $wpdb->get_charset_collate();

			// NOTA SULLO SCHEMA EVOLUTIVO:
			// La 1.0 aveva la tabella senza colonna `source`. Quando dbDelta gira
			// con questo CREATE TABLE su una tabella 1.0 esistente, aggiunge le
			// colonne mancanti senza distruggere i dati. La migrate_v1_to_v2()
			// chiamata da maybe_upgrade_schema() valorizza il `source` su tutte
			// le righe pre-esistenti come 'wp_native' (era l'unico flusso possibile
			// nella 1.0).
			$sql = "CREATE TABLE {$table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				request_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				source VARCHAR(20) NOT NULL DEFAULT 'wp_native',
				email_hash CHAR(64) NOT NULL,
				email_display VARCHAR(255) NOT NULL DEFAULT '',
				request_type VARCHAR(20) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				channel VARCHAR(20) NOT NULL DEFAULT '',
				description TEXT,
				requested_at DATETIME DEFAULT NULL,
				confirmed_at DATETIME DEFAULT NULL,
				completed_at DATETIME DEFAULT NULL,
				exporters_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				erasers_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				items_removed TINYINT(1) NOT NULL DEFAULT 0,
				items_retained TINYINT(1) NOT NULL DEFAULT 0,
				notes TEXT,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY request_id (request_id),
				KEY source (source),
				KEY email_hash (email_hash),
				KEY request_type (request_type),
				KEY status (status),
				KEY requested_at (requested_at)
			) {$charset};";

			dbDelta( $sql );
		}

		/**
		 * Migrazione dati schema 1.0 → 2.0.
		 *
		 * Viene chiamata da maybe_upgrade_schema() quando il valore option è '1.0'.
		 * Le colonne nuove sono già state aggiunte da dbDelta() in create_table();
		 * qui valorizziamo `source` sulle righe pre-esistenti (che per definizione
		 * erano tutte wp_native, perché era l'unico flusso possibile nella 1.0).
		 */
		private static function migrate_v1_to_v2() {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			// Solo le righe ancora a stringa vuota o NULL: evita di sovrascrivere
			// righe già migrate in precedenti tentativi falliti.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET source = %s WHERE source = '' OR source IS NULL",
				self::SOURCE_WP_NATIVE
			) );
		}

		/* =====================================================================
		 * Helpers
		 * ================================================================== */

		private static function hash_email( $email ) {
			$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
			return hash( 'sha256', strtolower( trim( (string) $email ) ) . $salt );
		}

		private static function mask_email( $email ) {
			$email = (string) $email;
			$at    = strpos( $email, '@' );
			if ( $at === false || $at < 1 ) {
				return '***';
			}
			$local  = substr( $email, 0, $at );
			$domain = substr( $email, $at + 1 );
			$local_masked = strlen( $local ) <= 2
				? str_repeat( '*', strlen( $local ) )
				: substr( $local, 0, 1 ) . str_repeat( '*', max( 1, strlen( $local ) - 2 ) ) . substr( $local, -1 );
			return $local_masked . '@' . $domain;
		}

		/* =====================================================================
		 * Hook: WP sta per inviare l'email di conferma (1.2.0)
		 *
		 * Filter `user_request_action_email_content` — emesso DURANTE la
		 * preparazione dell'email di conferma. Lo intercettiamo per inserire
		 * subito una riga 'pending' nel log, così la richiesta è tracciata
		 * anche se l'utente non clicca mai il link di conferma.
		 *
		 * IMPORTANTE: è un filter, non un'action. Dobbiamo restituire il primo
		 * argomento ($email_text) inalterato per non rompere il contenuto email.
		 * ================================================================== */

		public static function on_request_email_sent( $email_text, $email_data ) {
			$request_id = isset( $email_data['request_id'] ) ? (int) $email_data['request_id'] : 0;
			if ( ! $request_id ) {
				return $email_text;
			}

			$request = wp_get_user_request( $request_id );
			if ( ! $request ) {
				return $email_text;
			}

			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;

			// Skip se già loggata (es. WP rinvia l'email su richiesta admin).
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE request_id = %d LIMIT 1",
				$request_id
			) );
			if ( $existing_id ) {
				return $email_text;
			}

			$type  = self::normalize_type( (string) $request->action_name );
			$email = (string) $request->email;
			$now   = current_time( 'mysql' );
			$requested_at = $request->date_created_gmt
				? get_date_from_gmt( $request->date_created_gmt )
				: $now;

			$wpdb->insert(
				$table,
				array(
					'request_id'    => $request_id,
					'source'        => self::SOURCE_WP_NATIVE,
					'email_hash'    => self::hash_email( $email ),
					'email_display' => self::mask_email( $email ),
					'request_type'  => $type,
					'status'        => 'pending',
					'requested_at'  => $requested_at,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			return $email_text;
		}

		/**
		 * Normalizza il valore action_name di WP_User_Request al nostro vocabolario.
		 */
		private static function normalize_type( $action_name ) {
			if ( $action_name === 'remove_personal_data' ) return self::TYPE_ERASE;
			if ( $action_name === 'export_personal_data' ) return self::TYPE_EXPORT;
			return $action_name;
		}

		/* =====================================================================
		 * Cron: marca come 'expired' le richieste pending da > 7 giorni (1.2.0)
		 *
		 * Le richieste DSAR di WP scadono dopo 24h se non confermate (token
		 * usa-e-getta). Ma noi le teniamo nello storico per accountability.
		 * Dopo 7 giorni le marchiamo 'expired' per visualizzazione corretta
		 * nello storico (badge grigio).
		 * ================================================================== */

		public static function cron_expire_pending() {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table}
				 SET status = 'expired'
				 WHERE status = 'pending'
				   AND requested_at < %s
				   AND source = %s",
				$cutoff,
				self::SOURCE_WP_NATIVE
			) );
		}

		/* =====================================================================
		 * Hook: richiesta confermata (utente ha cliccato il link nell'email)
		 * ================================================================== */

		public static function on_request_confirmed( $request_id ) {
			$request = wp_get_user_request( (int) $request_id );
			if ( ! $request ) {
				return;
			}

			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;

			$type  = self::normalize_type( (string) $request->action_name );
			$email = (string) $request->email;

			// upsert: se la richiesta è già nel log (creata da on_request_email_sent
			// o da una conferma precedente), aggiorna il timestamp di conferma.
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE request_id = %d LIMIT 1",
				(int) $request_id
			) );

			$now = current_time( 'mysql' );
			$requested_at = $request->date_created_gmt
				? get_date_from_gmt( $request->date_created_gmt )
				: $now;

			if ( $existing_id ) {
				$wpdb->update(
					$table,
					array(
						'status'       => 'confirmed',
						'confirmed_at' => $now,
					),
					array( 'id' => $existing_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->insert(
					$table,
					array(
						'request_id'    => (int) $request_id,
						'source'        => self::SOURCE_WP_NATIVE,
						'email_hash'    => self::hash_email( $email ),
						'email_display' => self::mask_email( $email ),
						'request_type'  => $type,
						'status'        => 'confirmed',
						'requested_at'  => $requested_at,
						'confirmed_at'  => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}
		}

		/* =====================================================================
		 * Hook: export completato (file generato e inviato)
		 * ================================================================== */

		public static function on_export_done( $archive_url ) {
			$request_id = isset( $_REQUEST['request_id'] ) ? absint( wp_unslash( $_REQUEST['request_id'] ) ) : 0;
			if ( ! $request_id ) {
				return;
			}

			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;

			// Conta gli exporter registrati al momento (informativo).
			$exporters = (array) apply_filters( 'wp_privacy_personal_data_exporters', array() );
			$wpdb->update(
				$table,
				array(
					'status'          => 'completed',
					'completed_at'    => current_time( 'mysql' ),
					'exporters_count' => (int) count( $exporters ),
				),
				array( 'request_id' => (int) $request_id ),
				array( '%s', '%s', '%d' ),
				array( '%d' )
			);
		}

		/* =====================================================================
		 * Hook: erase completato
		 * ================================================================== */

		public static function on_erase_done( $request_id ) {
			$request_id = (int) $request_id;
			if ( ! $request_id ) {
				return;
			}

			$request = wp_get_user_request( $request_id );
			if ( ! $request ) {
				return;
			}

			$status = $request->status === 'request-completed' ? 'completed' : 'partial';

			$items_removed  = (bool) get_post_meta( $request_id, '_export_data_grouped', true ); // best-effort
			$items_retained = false;

			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;

			$erasers = (array) apply_filters( 'wp_privacy_personal_data_erasers', array() );
			$wpdb->update(
				$table,
				array(
					'status'         => $status,
					'completed_at'   => current_time( 'mysql' ),
					'erasers_count'  => (int) count( $erasers ),
					'items_removed'  => $items_removed ? 1 : 0,
					'items_retained' => $items_retained ? 1 : 0,
				),
				array( 'request_id' => $request_id ),
				array( '%s', '%s', '%d', '%d', '%d' ),
				array( '%d' )
			);
		}

		/* =====================================================================
		 * Query API per la pagina admin
		 * ================================================================== */

		public static function get_entries( $limit = 100, $offset = 0 ) {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			$limit = max( 1, min( 500, (int) $limit ) );
			$offset = max( 0, (int) $offset );
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit, $offset
			) );
		}

		public static function get_total_count() {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		public static function get_stats() {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			$rows = $wpdb->get_results(
				"SELECT request_type, status, COUNT(*) AS n FROM {$table} GROUP BY request_type, status",
				ARRAY_A
			);
			$out = array(
				'total'           => 0,
				'export_pending'  => 0,
				'export_done'     => 0,
				'erase_pending'   => 0,
				'erase_done'      => 0,
				// 1.2.0: nuove voci per scadenza GDPR e fonte.
				'overdue'         => 0,
				'due_soon'        => 0,
				'manual'          => 0,
			);
			foreach ( (array) $rows as $r ) {
				$n = (int) $r['n'];
				$out['total'] += $n;
				if ( $r['request_type'] === self::TYPE_EXPORT ) {
					$out[ $r['status'] === 'completed' ? 'export_done' : 'export_pending' ] += $n;
				} elseif ( $r['request_type'] === self::TYPE_ERASE ) {
					$out[ $r['status'] === 'completed' ? 'erase_done' : 'erase_pending' ] += $n;
				}
			}

			// Conteggi separati per scadenza GDPR e fonte manuale.
			$now    = current_time( 'mysql' );
			$soon   = gmdate( 'Y-m-d H:i:s', strtotime( '+10 days' ) );
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

			$out['overdue'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE status IN ('pending','confirmed','received','in_progress')
				   AND requested_at < %s",
				$cutoff
			) );
			$out['due_soon'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE status IN ('pending','confirmed','received','in_progress')
				   AND requested_at >= %s
				   AND DATE_ADD(requested_at, INTERVAL 30 DAY) < %s",
				$cutoff,
				$soon
			) );
			$out['manual'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source = %s",
				self::SOURCE_MANUAL
			) );

			return $out;
		}

		/* =====================================================================
		 * API per richieste manuali (1.2.0)
		 * ================================================================== */

		/**
		 * Inserisce una richiesta DSAR manuale (es. arrivata via email/PEC).
		 *
		 * @param array $args {
		 *     @type string   $request_type    Uno dei TYPE_* (obbligatorio)
		 *     @type string   $email           Email/identificativo (obbligatorio)
		 *     @type string   $channel         email|pec|mail|other (default 'email')
		 *     @type string   $description     Testo libero descrizione richiesta
		 *     @type string   $status          received|in_progress|completed|rejected
		 *     @type string   $requested_at    DATETIME (default now)
		 *     @type string   $completed_at    DATETIME o null
		 *     @type string   $notes           Note libere
		 * }
		 * @return int|WP_Error ID inserito o errore
		 */
		public static function insert_manual( $args ) {
			$args = wp_parse_args( $args, array(
				'request_type' => '',
				'email'        => '',
				'channel'      => 'email',
				'description'  => '',
				'status'       => 'received',
				'requested_at' => current_time( 'mysql' ),
				'completed_at' => null,
				'notes'        => '',
			) );

			$valid_types = self::get_valid_types();
			if ( empty( $args['request_type'] ) || ! in_array( $args['request_type'], $valid_types, true ) ) {
				return new WP_Error( 'dbph_invalid_type', __( 'Tipo di richiesta non valido.', 'db-privacy-hub' ) );
			}
			if ( empty( $args['email'] ) ) {
				return new WP_Error( 'dbph_missing_email', __( 'Identificativo (email) obbligatorio.', 'db-privacy-hub' ) );
			}

			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;

			$insert = array(
				'request_id'    => 0, // 0 = manuale, non c'è un user_request CPT
				'source'        => self::SOURCE_MANUAL,
				'email_hash'    => self::hash_email( $args['email'] ),
				'email_display' => self::mask_email( $args['email'] ),
				'request_type'  => $args['request_type'],
				'status'        => $args['status'],
				'channel'       => sanitize_text_field( $args['channel'] ),
				'description'   => wp_kses_post( $args['description'] ),
				'requested_at'  => $args['requested_at'],
				'completed_at'  => $args['completed_at'],
				'notes'         => wp_kses_post( $args['notes'] ),
			);

			$ok = $wpdb->insert( $table, $insert );
			return $ok ? (int) $wpdb->insert_id : new WP_Error( 'dbph_db_error', __( 'Errore durante il salvataggio.', 'db-privacy-hub' ) );
		}

		/**
		 * Aggiorna una richiesta manuale esistente (status, completed_at, note,
		 * description). Non permette di cambiare email/tipo/source per
		 * preservare l'integrità del record.
		 */
		public static function update_manual( $id, $args ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				return new WP_Error( 'dbph_invalid_id', __( 'ID non valido.', 'db-privacy-hub' ) );
			}
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;

			// Verifica che sia effettivamente manuale (sicurezza).
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
			if ( ! $row || $row->source !== self::SOURCE_MANUAL ) {
				return new WP_Error( 'dbph_not_manual', __( 'Solo le richieste manuali possono essere modificate.', 'db-privacy-hub' ) );
			}

			$update = array();
			$format = array();
			if ( isset( $args['status'] ) ) {
				$update['status'] = sanitize_text_field( $args['status'] );
				$format[] = '%s';
			}
			if ( isset( $args['description'] ) ) {
				$update['description'] = wp_kses_post( $args['description'] );
				$format[] = '%s';
			}
			if ( isset( $args['notes'] ) ) {
				$update['notes'] = wp_kses_post( $args['notes'] );
				$format[] = '%s';
			}
			if ( isset( $args['channel'] ) ) {
				$update['channel'] = sanitize_text_field( $args['channel'] );
				$format[] = '%s';
			}
			if ( array_key_exists( 'completed_at', $args ) ) {
				$update['completed_at'] = $args['completed_at']; // può essere null
				$format[] = '%s';
			}
			if ( array_key_exists( 'requested_at', $args ) && ! empty( $args['requested_at'] ) ) {
				$update['requested_at'] = $args['requested_at'];
				$format[] = '%s';
			}

			if ( empty( $update ) ) {
				return 0;
			}

			$wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );
			return $id;
		}

		public static function delete_manual( $id ) {
			$id = (int) $id;
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT source FROM {$table} WHERE id = %d", $id ) );
			if ( ! $row || $row->source !== self::SOURCE_MANUAL ) {
				return new WP_Error( 'dbph_not_manual', __( 'Solo le richieste manuali possono essere eliminate.', 'db-privacy-hub' ) );
			}
			return (bool) $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		}

		public static function get_by_id( $id ) {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
		}

		/**
		 * Tipi di richiesta consentiti.
		 *
		 * @return array<int,string>
		 */
		public static function get_valid_types() {
			return array(
				self::TYPE_EXPORT, self::TYPE_ERASE, self::TYPE_RECTIFY,
				self::TYPE_RESTRICT, self::TYPE_PORTABILITY, self::TYPE_OBJECT,
				self::TYPE_AUTOMATED, self::TYPE_CONSENT_REV,
			);
		}

		/**
		 * Mappa tipo → label leggibile (per UI e CSV export).
		 */
		public static function get_type_labels() {
			return array(
				self::TYPE_EXPORT      => __( 'Accesso (art. 15 GDPR)',        'db-privacy-hub' ),
				self::TYPE_RECTIFY     => __( 'Rettifica (art. 16 GDPR)',      'db-privacy-hub' ),
				self::TYPE_ERASE       => __( 'Cancellazione (art. 17 GDPR)',  'db-privacy-hub' ),
				self::TYPE_RESTRICT    => __( 'Limitazione (art. 18 GDPR)',    'db-privacy-hub' ),
				self::TYPE_PORTABILITY => __( 'Portabilità (art. 20 GDPR)',    'db-privacy-hub' ),
				self::TYPE_OBJECT      => __( 'Opposizione (art. 21 GDPR)',    'db-privacy-hub' ),
				self::TYPE_AUTOMATED   => __( 'No decisioni automatizzate (art. 22 GDPR)', 'db-privacy-hub' ),
				self::TYPE_CONSENT_REV => __( 'Revoca consenso (art. 7.3 GDPR)', 'db-privacy-hub' ),
			);
		}

		public static function get_status_labels() {
			return array(
				'pending'     => __( 'In attesa di conferma', 'db-privacy-hub' ),
				'confirmed'   => __( 'Confermata',            'db-privacy-hub' ),
				'received'    => __( 'Ricevuta',              'db-privacy-hub' ),
				'in_progress' => __( 'In lavorazione',        'db-privacy-hub' ),
				'completed'   => __( 'Completata',            'db-privacy-hub' ),
				'partial'     => __( 'Parzialmente completata','db-privacy-hub' ),
				'rejected'    => __( 'Respinta',              'db-privacy-hub' ),
				'expired'     => __( 'Scaduta (non confermata)', 'db-privacy-hub' ),
			);
		}

		public static function get_channel_labels() {
			return array(
				'email' => __( 'Email',                 'db-privacy-hub' ),
				'pec'   => __( 'PEC',                   'db-privacy-hub' ),
				'mail'  => __( 'Raccomandata cartacea', 'db-privacy-hub' ),
				'phone' => __( 'Telefono',              'db-privacy-hub' ),
				'other' => __( 'Altro',                 'db-privacy-hub' ),
			);
		}

		/**
		 * Calcola lo stato di scadenza GDPR di una riga (solo per status aperti).
		 *
		 * @return array{class:string,label:string,days:int} dove:
		 *   - class: 'overdue' | 'due_soon' | 'ok' | '' (vuoto = non applicabile)
		 *   - label: testo breve da mostrare in tabella
		 *   - days:  giorni residui (negativi se scaduta)
		 */
		public static function calculate_deadline( $row ) {
			if ( ! $row || empty( $row->requested_at ) ) {
				return array( 'class' => '', 'label' => '', 'days' => 0 );
			}
			$open_states = array( 'pending', 'confirmed', 'received', 'in_progress' );
			if ( ! in_array( $row->status, $open_states, true ) ) {
				return array( 'class' => '', 'label' => '', 'days' => 0 );
			}

			$deadline = strtotime( $row->requested_at . ' +30 days' );
			$now      = current_time( 'timestamp' );
			$days     = (int) round( ( $deadline - $now ) / DAY_IN_SECONDS );

			if ( $days < 0 ) {
				return array(
					'class' => 'overdue',
					'label' => sprintf( /* translators: %d: giorni di ritardo */ __( 'Scaduta (+%d gg)', 'db-privacy-hub' ), abs( $days ) ),
					'days'  => $days,
				);
			}
			if ( $days <= 10 ) {
				return array(
					'class' => 'due_soon',
					'label' => sprintf( /* translators: %d: giorni alla scadenza */ _n( '%d giorno', '%d giorni', $days, 'db-privacy-hub' ), $days ),
					'days'  => $days,
				);
			}
			return array(
				'class' => 'ok',
				'label' => sprintf( /* translators: %d: giorni alla scadenza */ __( '%d giorni', 'db-privacy-hub' ), $days ),
				'days'  => $days,
			);
		}

		/* =====================================================================
		 * Hook deattivazione plugin: pulisci il cron.
		 * ================================================================== */

		public static function on_deactivate() {
			$ts = wp_next_scheduled( 'dbph_dsar_cleanup_pending' );
			if ( $ts ) {
				wp_unschedule_event( $ts, 'dbph_dsar_cleanup_pending' );
			}
		}
	}
}
