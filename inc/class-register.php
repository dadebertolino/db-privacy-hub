<?php
/**
 * DBPH_Register — Registro privacy unificato.
 *
 * Espone il filter `dbph_processing_register` su cui ogni plugin DB dichiara
 * i propri trattamenti tecnici. Il contratto della singola voce ricalca quello
 * del vecchio filter `dbseo_processing_register` del SEO Manager 1.2.x:
 *
 *   array(
 *       'id'             => 'mioplugin_invio_form',          // chiave univoca
 *       'label'          => __('Invio modulo contatti', '…'),
 *       'status'         => 'active',                         // active|inactive
 *       'purpose'        => __('Finalità.', '…'),
 *       'legal_basis'    => __('Consenso (art. 7 GDPR).', '…'),
 *       'data_collected' => __('Nome, email, messaggio.', '…'),
 *       'retention'      => __('365 giorni.', '…'),
 *       'transfers'      => __('Nessuno.', '…'),
 *   )
 *
 * Tutti i campi sono obbligatori. Il Privacy Hub non valida né normalizza:
 * la responsabilità del contenuto è del plugin che dichiara.
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Register' ) ) {

	class DBPH_Register {

		/** @var array|null Cache della singola request. */
		private static $cache = null;

		public static function init() {
			// Niente hook qui: il register è un servizio passivo, viene
			// chiamato dalla UI admin e dal policy generator on-demand.
		}

		/**
		 * Restituisce l'elenco completo dei trattamenti, raccolti via filter.
		 *
		 * Annota ogni voce con `_source` ('self' = Hub, 'external' = altro
		 * plugin). Il Hub stesso non dichiara trattamenti propri (non è il
		 * titolare): tutte le voci provengono dai plugin DB collegati.
		 *
		 * @return array
		 */
		public static function collect() {
			if ( null !== self::$cache ) {
				return self::$cache;
			}

			$register = (array) apply_filters( 'dbph_processing_register', array() );

			// Annota la sorgente. Plugin che vogliono dichiarare la sorgente
			// in modo esplicito possono valorizzare _source da soli.
			foreach ( $register as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				if ( ! isset( $register[ $key ]['_source'] ) ) {
					$register[ $key ]['_source'] = 'external';
				}
			}

			self::$cache = $register;
			return $register;
		}

		/**
		 * Resetta la cache. Utile dopo aver salvato impostazioni che
		 * influenzano il contenuto del registro (es. dati titolare).
		 */
		public static function flush_cache() {
			self::$cache = null;
		}

		/**
		 * Conta i plugin DB unici che hanno dichiarato voci nel registro.
		 *
		 * Euristica basata sul prefisso dell'id: voci con id che inizia con
		 * `dbcm_*` provengono dal Cookie Manager, `dbfb_*` dal Form Builder,
		 * ecc. Usato dalla UI admin per mostrare un contatore.
		 *
		 * @return array<string,int> map prefix => count
		 */
		public static function count_by_source() {
			$counts = array();
			foreach ( self::collect() as $entry ) {
				if ( empty( $entry['id'] ) ) {
					continue;
				}
				// Prefisso = tutto ciò che precede il primo underscore. Il vecchio
				// substr(id, 0, 4) troncava i prefissi a 5+ caratteri (es. dbseo_*
				// diventava "dbse" e le voci del SEO Manager venivano raggruppate male).
				$id     = strtolower( (string) $entry['id'] );
				$pos    = strpos( $id, '_' );
				$prefix = ( false === $pos ) ? $id : substr( $id, 0, $pos );
				if ( ! isset( $counts[ $prefix ] ) ) {
					$counts[ $prefix ] = 0;
				}
				++$counts[ $prefix ];
			}
			return $counts;
		}
	}
}
