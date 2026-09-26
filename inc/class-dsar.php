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
 * Dalla 1.7.0 le risposte vengono normalizzate (vedi normalize_*_response):
 * una callback non conforme non interrompe più la richiesta degli altri plugin.
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
				$label = is_array( $entry ) ? self::entry_label( $entry, 'exporter_friendly_name' ) : '';
				if ( ! is_array( $entry ) || empty( $entry['callback'] ) || $label === '' ) {
					continue;
				}
				if ( ! is_callable( $entry['callback'] ) ) {
					continue;
				}
				$slug = sanitize_key( (string) $key );
				if ( $slug === '' ) {
					continue;
				}
				$callback           = $entry['callback'];
				$exporters[ $slug ] = array(
					'exporter_friendly_name' => $label,
					'callback'               => function ( $email_address, $page = 1 ) use ( $callback, $slug ) {
						return DBPH_DSAR::normalize_export_response( call_user_func( $callback, $email_address, $page ), $slug );
					},
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
				$label = is_array( $entry ) ? self::entry_label( $entry, 'eraser_friendly_name' ) : '';
				if ( ! is_array( $entry ) || empty( $entry['callback'] ) || $label === '' ) {
					continue;
				}
				if ( ! is_callable( $entry['callback'] ) ) {
					continue;
				}
				$slug = sanitize_key( (string) $key );
				if ( $slug === '' ) {
					continue;
				}
				$callback         = $entry['callback'];
				$erasers[ $slug ] = array(
					'eraser_friendly_name' => $label,
					'callback'             => function ( $email_address, $page = 1 ) use ( $callback, $slug ) {
						return DBPH_DSAR::normalize_erase_response( call_user_func( $callback, $email_address, $page ), $slug );
					},
				);
			}

			return $erasers;
		}

		/* =====================================================================
		 * Normalizzazione (1.7.0)
		 *
		 * WordPress interrompe l'INTERA richiesta DSAR (tutti i plugin) se una
		 * sola callback restituisce una struttura non conforme ("Expected data
		 * in response array", "Expected done flag"…). Avvolgiamo quindi ogni
		 * callback dichiarata via dbph_* in un normalizzatore: un plugin
		 * scritto male non blocca più l'export/cancellazione degli altri.
		 * Con WP_DEBUG attivo viene emesso un _doing_it_wrong per segnalare
		 * il plugin da correggere.
		 * ================================================================== */

		/**
		 * Label della voce: `label` (contratto Hub) o, in tolleranza, la
		 * chiave del contratto core (`exporter_friendly_name` / `eraser_friendly_name`).
		 *
		 * @param array  $entry
		 * @param string $core_key
		 * @return string
		 */
		private static function entry_label( array $entry, $core_key ) {
			if ( ! empty( $entry['label'] ) ) {
				return (string) $entry['label'];
			}
			if ( ! empty( $entry[ $core_key ] ) ) {
				self::doing_it_wrong(
					sprintf(
						/* translators: 1: chiave usata, 2: chiave attesa */
						__( 'Le voci dei filter dbph_user_data_exporters/erasers usano la chiave "%2$s", non "%1$s".', 'db-privacy-hub' ),
						$core_key,
						'label'
					)
				);
				return (string) $entry[ $core_key ];
			}
			return '';
		}

		/**
		 * @param mixed  $response Risposta grezza dell'exporter.
		 * @param string $slug     Chiave dell'exporter (per il messaggio di debug).
		 * @return array{data:array,done:bool}
		 */
		public static function normalize_export_response( $response, $slug ) {
			if ( is_array( $response ) && array_key_exists( 'data', $response ) ) {
				if ( ! is_array( $response['data'] ) ) {
					$response['data'] = array();
				}
				if ( ! array_key_exists( 'done', $response ) ) {
					self::doing_it_wrong( self::shape_message( $slug, 'done' ) );
					$response['done'] = true;
				}
				return $response;
			}

			self::doing_it_wrong( self::shape_message( $slug, 'data/done' ) );

			// Lista piatta di item (errore comune): la trattiamo come data.
			return array(
				'data' => ( is_array( $response ) && ( empty( $response ) || isset( $response[0] ) ) ) ? array_values( $response ) : array(),
				'done' => true,
			);
		}

		/**
		 * @param mixed  $response Risposta grezza dell'eraser.
		 * @param string $slug     Chiave dell'eraser (per il messaggio di debug).
		 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
		 */
		public static function normalize_erase_response( $response, $slug ) {
			$required = array( 'items_removed', 'items_retained', 'messages', 'done' );
			if ( ! is_array( $response ) ) {
				self::doing_it_wrong( self::shape_message( $slug, implode( '/', $required ) ) );
				$response = array();
			}
			$missing = array_diff( $required, array_keys( $response ) );
			if ( $missing ) {
				self::doing_it_wrong( self::shape_message( $slug, implode( '/', $missing ) ) );
			}
			return array(
				'items_removed'  => ! empty( $response['items_removed'] ),
				'items_retained' => ! empty( $response['items_retained'] ),
				'messages'       => isset( $response['messages'] ) && is_array( $response['messages'] ) ? array_values( $response['messages'] ) : array(),
				'done'           => array_key_exists( 'done', $response ) ? (bool) $response['done'] : true,
			);
		}

		private static function shape_message( $slug, $keys ) {
			return sprintf(
				/* translators: 1: slug callback, 2: chiavi mancanti */
				__( 'La callback DSAR "%1$s" registrata via DB Privacy Hub non restituisce la struttura attesa da WordPress (mancano: %2$s). La risposta è stata normalizzata: aggiorna il plugin che la dichiara.', 'db-privacy-hub' ),
				$slug,
				$keys
			);
		}

		private static function doing_it_wrong( $message ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				_doing_it_wrong( 'DBPH_DSAR', esc_html( $message ), '1.7.0' );
			}
		}
	}
}
