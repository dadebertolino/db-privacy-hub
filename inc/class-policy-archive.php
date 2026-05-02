<?php
/**
 * DBPH_Policy_Archive — Archivio storico delle versioni della Privacy Policy.
 *
 * Ogni volta che l'admin pubblica o rigenera la pagina Privacy Policy via
 * "Crea pagina WordPress", l'Hub salva uno snapshot del documento. La pagina
 * admin "Storico Policy" mostra l'evoluzione e permette di confrontare
 * versioni successive.
 *
 * Storage: tabella custom `wp_dbph_policy_archive`. Tabella custom (non CPT)
 * perché non vogliamo affollare wp_posts con record di sistema.
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Policy_Archive' ) ) {

	class DBPH_Policy_Archive {

		const TABLE_NAME    = 'dbph_policy_archive';
		const SCHEMA_VERSION = '1.0';
		const SCHEMA_OPTION  = 'dbph_policy_archive_schema';

		public static function init() {
			self::maybe_upgrade_schema();
		}

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
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				content_hash CHAR(64) NOT NULL,
				content LONGTEXT NOT NULL,
				bytes INT UNSIGNED NOT NULL DEFAULT 0,
				note VARCHAR(255) NOT NULL DEFAULT '',
				PRIMARY KEY (id),
				KEY content_hash (content_hash),
				KEY created_at (created_at)
			) {$charset};";

			dbDelta( $sql );
		}

		/**
		 * Salva uno snapshot solo se diverso dall'ultimo.
		 * Usa un hash del contenuto per evitare duplicati identici.
		 *
		 * @param string $content   HTML completo della policy
		 * @param string $note      Nota libera (es. "Pubblicazione iniziale", "Rigenerazione")
		 * @return int|false        ID dello snapshot inserito, false se duplicato.
		 */
		public static function save( $content, $note = '' ) {
			$content = (string) $content;
			$hash    = hash( 'sha256', $content );

			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;

			$last_hash = $wpdb->get_var( "SELECT content_hash FROM {$table} ORDER BY id DESC LIMIT 1" );
			if ( $last_hash === $hash ) {
				return false; // contenuto identico all'ultimo snapshot, skip.
			}

			$ok = $wpdb->insert(
				$table,
				array(
					'content_hash' => $hash,
					'content'      => $content,
					'bytes'        => strlen( $content ),
					'note'         => sanitize_text_field( $note ),
				),
				array( '%s', '%s', '%d', '%s' )
			);
			return $ok ? (int) $wpdb->insert_id : false;
		}

		/**
		 * Restituisce le versioni in ordine cronologico inverso.
		 *
		 * @param int $limit
		 * @return array
		 */
		public static function get_all( $limit = 50 ) {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			$limit = max( 1, min( 100, (int) $limit ) );
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT id, created_at, content_hash, bytes, note FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			) );
		}

		/**
		 * Restituisce una versione specifica.
		 *
		 * @param int $id
		 * @return object|null
		 */
		public static function get( $id ) {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
		}

		public static function get_total_count() {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_NAME;
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		/**
		 * Diff testuale a livello di blocco (paragrafo) tra due snapshot.
		 * Restituisce array con righe rimosse/aggiunte rispetto alla versione precedente.
		 * Usa wp_text_diff() interno di WordPress.
		 *
		 * @param int $id_a (precedente)
		 * @param int $id_b (successivo)
		 * @return string  HTML della tabella diff (output di wp_text_diff)
		 */
		public static function diff( $id_a, $id_b ) {
			$a = self::get( $id_a );
			$b = self::get( $id_b );
			if ( ! $a || ! $b ) {
				return '';
			}

			require_once ABSPATH . 'wp-admin/includes/revision.php';

			// Convertiamo HTML in testo plain con preservazione della struttura
			// minimale (newline tra blocchi) per un diff più leggibile.
			$strip = function ( $html ) {
				$txt = preg_replace( '/<\/(h[1-6]|p|li|tr|hr|div)>/i', "$0\n", (string) $html );
				$txt = wp_strip_all_tags( $txt );
				$txt = html_entity_decode( $txt, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$txt = preg_replace( "/\n{3,}/", "\n\n", $txt );
				return trim( $txt );
			};

			$args = array(
				'title_left'  => __( 'Versione precedente', 'db-privacy-hub' ),
				'title_right' => __( 'Versione attuale', 'db-privacy-hub' ),
				'show_split_view' => true,
			);
			return wp_text_diff( $strip( $a->content ), $strip( $b->content ), $args );
		}
	}
}
