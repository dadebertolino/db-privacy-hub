<?php
/**
 * DBPH_Deprecated_Aliases — Compatibilità con il filter legacy
 * `dbseo_processing_register` del SEO Manager 1.2.x.
 *
 * SCENARIO STORICO
 * ----------------
 * Fino al SEO Manager 1.2.2, il registro privacy unificato era esposto via
 * filter `dbseo_processing_register`. Dal Privacy Hub 1.0.0, il filter
 * canonico diventa `dbph_processing_register`.
 *
 * COSA FACCIAMO QUI
 * -----------------
 * Plugin DB che ancora si agganciano a `dbseo_processing_register` (versioni
 * vecchie già installate, o sviluppatori terzi) continuano a funzionare:
 * questo modulo redirige automaticamente le loro dichiarazioni nel registro
 * dell'Hub.
 *
 * In pratica, quando il Privacy Hub raccoglie il suo registro
 * (`dbph_processing_register`), aggiunge in coda anche tutto ciò che è
 * stato dichiarato sotto `dbseo_processing_register` — emettendo un
 * `_doing_it_wrong` notice per ogni dichiarazione legacy quando WP_DEBUG
 * è attivo, così lo sviluppatore sa che deve aggiornare il suo plugin.
 *
 * RIMOZIONE FUTURA
 * ----------------
 * Questo alias è marcato per la rimozione in DB Privacy Hub 2.0.0.
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Deprecated_Aliases' ) ) {

	class DBPH_Deprecated_Aliases {

		/** @var bool Flag per evitare ricorsione fra i due filter. */
		private static $is_resolving = false;

		public static function init() {
			// Priority 999: vogliamo che merge_legacy giri DOPO che tutti gli
			// altri plugin hanno già hookato e popolato il filter nuovo. Solo
			// allora la dedup per id può funzionare (le voci dichiarate da
			// plugin aggiornati che usano entrambi i filter sono già nella
			// lista, quindi non vengono ri-aggiunte come legacy).
			add_filter( 'dbph_processing_register', array( __CLASS__, 'merge_legacy' ), 999, 1 );
		}

		/**
		 * Aggiunge in coda al registro Hub le voci dichiarate sotto il filter
		 * legacy `dbseo_processing_register`.
		 *
		 * @param array $register Registro Hub corrente (vuoto al primo giro).
		 * @return array
		 */
		public static function merge_legacy( $register ) {
			if ( self::$is_resolving ) {
				return $register;
			}

			self::$is_resolving = true;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook legacy di DB SEO Manager, supportato per retrocompatibilità.
			$legacy = (array) apply_filters( 'dbseo_processing_register', array() );
			self::$is_resolving = false;

			if ( empty( $legacy ) ) {
				return $register;
			}

			// De-duplica per id: una voce dichiarata su entrambi i filter
			// (caso normale post-migrazione, vedi Cookie Manager 3.1.0)
			// non viene contata due volte.
			$known_ids = array();
			foreach ( $register as $entry ) {
				if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
					$known_ids[ $entry['id'] ] = true;
				}
			}

			$emitted_warning = false;
			foreach ( $legacy as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
					continue;
				}
				if ( isset( $known_ids[ $entry['id'] ] ) ) {
					continue; // già dichiarata sul filter nuovo, skip
				}

				if ( ! $emitted_warning && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					_doing_it_wrong(
						'apply_filters( "dbseo_processing_register" )',
						esc_html__(
							'Il filter "dbseo_processing_register" è deprecato dal SEO Manager 1.3.0 / Privacy Hub 1.0.0. Aggiorna i tuoi plugin per agganciarsi a "dbph_processing_register". Le dichiarazioni legacy continuano a funzionare ma verranno rimosse nel Privacy Hub 2.0.0.',
							'db-privacy-hub'
						),
						'1.0.0'
					);
					$emitted_warning = true;
				}

				$entry['_source']  = 'external';
				$entry['_legacy']  = true;
				$register[]        = $entry;
				$known_ids[ $entry['id'] ] = true;
			}

			return $register;
		}
	}
}
