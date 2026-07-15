<?php
/**
 * DBPH_Consents_Register — Aggregatore consensi via filter pubblico.
 *
 * Espone il filter `dbph_consents_register` con cui ogni plugin DB (Cookie
 * Manager, Form Builder, e in futuro altri) dichiara la propria fonte di
 * consensi. L'Hub raccoglie le dichiarazioni e fornisce all'admin una vista
 * unificata in `Privacy → Registro consensi`.
 *
 * Contratto del filter:
 *
 *   add_filter( 'dbph_consents_register', function( $sources ) {
 *       $sources['mio_plugin'] = array(
 *           'label'    => __( 'Mio Plugin — Consensi', 'mio' ),
 *           'icon'     => 'cookie',                            // chiave icona admin
 *           'count'    => function( $args = array() ) { ... }, // ritorna int
 *           'query'    => function( $args = array() ) { ... }, // ritorna array<row>
 *                                                              // ($args può contenere 'limit':
 *                                                              // le fonti dovrebbero rispettarlo)
 *           'export'   => function( $args = array() ) { ... }, // CSV streaming
 *       );
 *       return $sources;
 *   } );
 *
 * Ogni `row` ritornata da `query` deve avere chiavi:
 *   - id            (string, identificatore stabile lato fonte)
 *   - source_key    (string, lo stesso usato nell'array sopra — popolato dal Hub)
 *   - source_label  (string, label UI — popolato dal Hub)
 *   - timestamp     (string MySQL DATETIME)
 *   - subject       (string, identificativo utente — email mascherata o ID)
 *   - consent_type  (string, breve descrizione: "cookie:analytics", "form:contact", ecc.)
 *   - consent_text  (string, testo letto dall'utente)
 *   - policy_version(int, ID snapshot Privacy Hub — 0 se non disponibile)
 *   - extra         (array, metadata libere per la UI dettaglio)
 *
 * @package DB_Privacy_Hub
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Consents_Register' ) ) {

	class DBPH_Consents_Register {

		/**
		 * Cache delle sources per il request, per evitare di ri-applicare il
		 * filter più volte se le pagine admin lo interrogano N volte.
		 *
		 * @var array|null
		 */
		private static $sources_cache = null;

		public static function init() {
			// Niente hook propri: l'Hub interroga apply_filters quando serve.
		}

		/**
		 * Restituisce tutte le fonti dichiarate dai plugin via filter.
		 *
		 * @return array<string,array>
		 */
		public static function get_sources() {
			if ( self::$sources_cache !== null ) {
				return self::$sources_cache;
			}
			$sources = (array) apply_filters( 'dbph_consents_register', array() );

			// Sanitize/validate: ogni fonte deve avere almeno label + query.
			$valid = array();
			foreach ( $sources as $key => $src ) {
				if ( ! is_array( $src ) ) continue;
				if ( empty( $src['label'] ) || empty( $src['query'] ) ) continue;
				$valid[ sanitize_key( $key ) ] = wp_parse_args( $src, array(
					'label'  => '',
					'icon'   => '',
					'count'  => null,
					'query'  => null,
					'export' => null,
				) );
			}
			self::$sources_cache = $valid;
			return $valid;
		}

		/**
		 * Conta le righe totali per una fonte (delega al callback dichiarato).
		 *
		 * @param string $source_key
		 * @param array  $args  Filtri (date_from, date_to, subject, ecc.)
		 * @return int
		 */
		public static function count_for( $source_key, $args = array() ) {
			$sources = self::get_sources();
			if ( ! isset( $sources[ $source_key ] ) ) return 0;
			$cb = $sources[ $source_key ]['count'];
			if ( ! is_callable( $cb ) ) return 0;
			return (int) call_user_func( $cb, $args );
		}

		/**
		 * Ritorna le righe per una fonte, normalizzate.
		 *
		 * @param string $source_key
		 * @param array  $args
		 * @return array<array>
		 */
		public static function query_for( $source_key, $args = array() ) {
			$sources = self::get_sources();
			if ( ! isset( $sources[ $source_key ] ) ) return array();
			$cb = $sources[ $source_key ]['query'];
			if ( ! is_callable( $cb ) ) return array();

			$rows = (array) call_user_func( $cb, $args );

			// Iniettiamo source_key e source_label in ogni riga (la fonte non li conosce).
			foreach ( $rows as &$row ) {
				if ( is_array( $row ) ) {
					$row['source_key']   = $source_key;
					$row['source_label'] = $sources[ $source_key ]['label'];
				} elseif ( is_object( $row ) ) {
					$row->source_key   = $source_key;
					$row->source_label = $sources[ $source_key ]['label'];
				}
			}
			unset( $row );

			return $rows;
		}

		/**
		 * Vista cronologica unificata: merge di tutte le fonti, ordinata per
		 * timestamp decrescente. Limite di sicurezza per evitare blow-up con
		 * fonti grosse.
		 *
		 * @param array $args  date_from, date_to, subject, source (filtro su 1 sola fonte)
		 * @param int   $limit Default 200
		 * @return array<array>
		 */
		public static function query_all( $args = array(), $limit = 200 ) {
			$sources = self::get_sources();
			if ( empty( $sources ) ) return array();

			// Propaga il limite alle callback: dato che il risultato finale è
			// comunque troncato a $limit, nessuna fonte ha bisogno di restituire
			// più di $limit righe. Evita merge in memoria illimitati con log
			// consensi di grandi dimensioni. Le fonti che ignorano args['limit']
			// continuano a funzionare (il troncamento finale resta).
			$args['limit'] = max( 1, (int) $limit );

			// Se l'utente ha chiesto una singola fonte, limita.
			if ( ! empty( $args['source'] ) && isset( $sources[ $args['source'] ] ) ) {
				return array_slice( self::query_for( $args['source'], $args ), 0, $args['limit'] );
			}

			$all = array();
			foreach ( array_keys( $sources ) as $key ) {
				$rows = self::query_for( $key, $args );
				$all = array_merge( $all, $rows );
			}

			// Sort cronologico decrescente sul timestamp.
			usort( $all, function ( $a, $b ) {
				$ta = is_array( $a ) ? ( $a['timestamp'] ?? '' ) : ( $a->timestamp ?? '' );
				$tb = is_array( $b ) ? ( $b['timestamp'] ?? '' ) : ( $b->timestamp ?? '' );
				return strcmp( $tb, $ta );
			} );

			return array_slice( $all, 0, $limit );
		}

		/**
		 * Conteggio totale aggregato (per la card riepilogativa admin).
		 *
		 * @param array $args
		 * @return int
		 */
		public static function count_all( $args = array() ) {
			$sources = self::get_sources();
			$total = 0;
			foreach ( array_keys( $sources ) as $key ) {
				$total += self::count_for( $key, $args );
			}
			return $total;
		}
	}
}
