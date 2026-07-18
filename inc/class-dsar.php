<?php
/**
 * DBPH_DSAR — Integrazione con WordPress Privacy Tools (artt. 15 e 17 GDPR).
 *
 * Si aggancia ai due hook core:
 *  - wp_privacy_personal_data_exporters  → diritto di accesso (art. 15)
 *  - wp_privacy_personal_data_erasers    → diritto alla cancellazione (art. 17)
 *
 * Espone due filter pubblici su cui ogni plugin DB dichiara i propri dati
 * personali da esportare/cancellare:
 *
 *   add_filter( 'dbph_user_data_exporters', function( $exporters ) {
 *       $exporters['mio-plugin'] = array(
 *           'label'    => __( 'Mio Plugin', 'mio-plugin' ),
 *           'callback' => 'mio_plugin_export_user_data',
 *       );
 *       return $exporters;
 *   } );
 *
 *   add_filter( 'dbph_user_data_erasers', function( $erasers ) {
 *       $erasers['mio-plugin'] = array(
 *           'label'    => __( 'Mio Plugin', 'mio-plugin' ),
 *           'callback' => 'mio_plugin_erase_user_data',
 *       );
 *       return $erasers;
 *   } );
 *
 * Le callback ricevono ($email_address, $page) e devono restituire la
 * struttura standard documentata da WordPress:
 *  - exporter: array('data' => array(), 'done' => bool)
 *  - eraser:   array('items_removed' => bool, 'items_retained' => bool,
 *                    'messages' => array(), 'done' => bool)
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_DSAR' ) ) {

	class DBPH_DSAR {

		public static function init() {
			add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ), 20 );
			add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ), 20 );
		}

		/**
		 * Aggiunge ai WP Privacy Tools tutti gli exporter dichiarati dai
		 * plugin DB tramite il filter dbph_user_data_exporters.
		 *
		 * @param array $exporters
		 * @return array
		 */
		public static function register_exporters( $exporters ) {
			if ( ! is_array( $exporters ) ) {
				$exporters = array();
			}

			$db_exporters = (array) apply_filters( 'dbph_user_data_exporters', array() );

			foreach ( $db_exporters as $key => $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['callback'] ) || empty( $entry['label'] ) ) {
					continue;
				}
				if ( ! is_callable( $entry['callback'] ) ) {
					continue;
				}
				$slug = sanitize_key( (string) $key );
				if ( $slug === '' ) {
					continue;
				}
				$exporters[ $slug ] = array(
					'exporter_friendly_name' => (string) $entry['label'],
					'callback'               => $entry['callback'],
				);
			}

			return $exporters;
		}

		/**
		 * Aggiunge ai WP Privacy Tools tutti gli eraser dichiarati dai
		 * plugin DB tramite il filter dbph_user_data_erasers.
		 *
		 * @param array $erasers
		 * @return array
		 */
		public static function register_erasers( $erasers ) {
			if ( ! is_array( $erasers ) ) {
				$erasers = array();
			}

			$db_erasers = (array) apply_filters( 'dbph_user_data_erasers', array() );

			foreach ( $db_erasers as $key => $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['callback'] ) || empty( $entry['label'] ) ) {
					continue;
				}
				if ( ! is_callable( $entry['callback'] ) ) {
					continue;
				}
				$slug = sanitize_key( (string) $key );
				if ( $slug === '' ) {
					continue;
				}
				$erasers[ $slug ] = array(
					'eraser_friendly_name' => (string) $entry['label'],
					'callback'             => $entry['callback'],
				);
			}

			return $erasers;
		}
	}
}
