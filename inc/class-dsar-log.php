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
		const SCHEMA_VERSION = '1.0';
		const SCHEMA_OPTION  = 'dbph_dsar_log_schema';

		public static function init() {
			// Schema upgrade just-in-time (anche se l'activation hook non gira).
			self::maybe_upgrade_schema();

			// Hook ciclo vita richieste WP Privacy Tools.
			add_action( 'user_request_action_confirmed',          array( __CLASS__, 'on_request_confirmed' ),   10, 1 );
			add_action( 'wp_privacy_personal_data_export_file',   array( __CLASS__, 'on_export_done' ),         10, 1 );
			add_action( 'wp_privacy_personal_data_erased',        array( __CLASS__, 'on_erase_done' ),          10, 1 );
		}

		/* =====================================================================
		 * Schema
		 * ================================================================== */

		public static function maybe_upgrade_schema() {
			$installed = get_option( self::SCHEMA_OPTION, '' );
			if ( $installed === self::SCHEMA_VERSION ) {
				return;
			}
			self::create_table();
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}

		public static function create_table() {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$table   = $wpdb->prefix . self::TABLE_NAME;
			$charset = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				request_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				email_hash CHAR(64) NOT NULL,
				email_display VARCHAR(255) NOT NULL DEFAULT '',
				request_type VARCHAR(20) NOT NULL DEFAULT '',
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
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
				KEY email_hash (email_hash),
				KEY request_type (request_type),
				KEY status (status)
			) {$charset};";

			dbDelta( $sql );
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
		 * Hook: richiesta confermata (utente ha cliccato il link nell'email)
		 * ================================================================== */

		public static function on_request_confirmed( $request_id ) {
			$request = wp_get_user_request( (int) $request_id );
			if ( ! $request ) {
				return;
			}

			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;

			$type = (string) $request->action_name;
			$type = ( $type === 'remove_personal_data' ) ? 'erase' : ( ( $type === 'export_personal_data' ) ? 'export' : $type );

			$email = (string) $request->email;

			// upsert: se la richiesta è già nel log, aggiorna il timestamp di conferma.
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
						'email_hash'    => self::hash_email( $email ),
						'email_display' => self::mask_email( $email ),
						'request_type'  => $type,
						'status'        => 'confirmed',
						'requested_at'  => $requested_at,
						'confirmed_at'  => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
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
			);
			foreach ( (array) $rows as $r ) {
				$n = (int) $r['n'];
				$out['total'] += $n;
				$key = $r['request_type'] === 'export'
					? ( $r['status'] === 'completed' ? 'export_done' : 'export_pending' )
					: ( $r['status'] === 'completed' ? 'erase_done' : 'erase_pending' );
				$out[ $key ] += $n;
			}
			return $out;
		}
	}
}
