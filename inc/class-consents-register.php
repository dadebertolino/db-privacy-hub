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
 *                                                              // ($args contiene 'limit' — alias
 *                                                              // '_internal_limit' — da rispettare;
 *                                                              // fino a 50000 per l'export CSV)
 *           'export'   => function( $args = array() ) { ... }, // opzionale, riservato:
 *                                                              // l'export CSV dell'Hub usa `query`
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
				if ( ! is_array( $src ) ) {
					continue;
				}
				if ( empty( $src['label'] ) || empty( $src['query'] ) ) {
					continue;
				}
				$valid[ sanitize_key( $key ) ] = wp_parse_args(
					$src,
					array(
						'label'  => '',
						'icon'   => '',
						'count'  => null,
						'query'  => null,
						'export' => null,
					)
				);
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
			if ( ! isset( $sources[ $source_key ] ) ) {
				return 0;
			}
			$cb = $sources[ $source_key ]['count'];
			if ( ! is_callable( $cb ) ) {
				return 0;
			}
			// 1.8.0: una fonte rotta non deve bloccare la vista delle altre.
			try {
				$count = call_user_func( $cb, $args );
			} catch ( Throwable $e ) {
				self::source_failed( $source_key, $e );
				return 0;
			}
			return is_numeric( $count ) ? max( 0, (int) $count ) : 0;
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
			if ( ! isset( $sources[ $source_key ] ) ) {
				return array();
			}
			$cb = $sources[ $source_key ]['query'];
			if ( ! is_callable( $cb ) ) {
				return array();
			}

			try {
				$rows = call_user_func( $cb, $args );
			} catch ( Throwable $e ) {
				self::source_failed( $source_key, $e );
				return array();
			}

			// 1.8.0: righe normalizzate al contratto (array con campi scalari):
			// la UI e l'export CSV non devono fare i conti con valori
			// arbitrari (array al posto del testo, oggetti, null).
			$out = array();
			foreach ( (array) $rows as $row ) {
				if ( is_object( $row ) ) {
					$row = get_object_vars( $row );
				}
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row                 = self::normalize_row( $row );
				$row['source_key']   = $source_key;
				$row['source_label'] = (string) $sources[ $source_key ]['label'];
				$out[]               = $row;
			}

			return $out;
		}

		/**
		 * Porta una riga al contratto documentato in testa al file.
		 *
		 * @since 1.8.0
		 * @param array $row
		 * @return array
		 */
		public static function normalize_row( array $row ) {
			foreach ( array( 'id', 'timestamp', 'subject', 'consent_type', 'consent_text' ) as $key ) {
				$row[ $key ] = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
			}
			$row['policy_version'] = isset( $row['policy_version'] ) && is_numeric( $row['policy_version'] ) ? (int) $row['policy_version'] : 0;
			$row['extra']          = isset( $row['extra'] ) && is_array( $row['extra'] ) ? $row['extra'] : array();
			return $row;
		}

		private static function source_failed( $source_key, $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				_doing_it_wrong(
					'DBPH_Consents_Register',
					esc_html(
						sprintf(
							/* translators: 1: chiave della fonte, 2: messaggio dell'eccezione */
							__( 'La fonte consensi "%1$s" ha lanciato un\'eccezione: %2$s', 'db-privacy-hub' ),
							$source_key,
							$e->getMessage()
						)
					),
					'1.8.0'
				);
			}
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
			if ( empty( $sources ) ) {
				return array();
			}

			// Propaga il limite alle callback: dato che il risultato finale è
			// comunque troncato a $limit, nessuna fonte ha bisogno di restituire
			// più di $limit righe. Evita merge in memoria illimitati con log
			// consensi di grandi dimensioni. Le fonti che ignorano args['limit']
			// continuano a funzionare (il troncamento finale resta).
			$args['limit'] = max( 1, (int) $limit );
			// 1.7.0: alias per le fonti scritte seguendo la vecchia
			// documentazione (PRIVACY-INTEGRATION.md citava `_internal_limit`):
			// senza, l'export CSV si fermava in silenzio a 1000 righe per fonte.
			$args['_internal_limit'] = $args['limit'];

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
			usort(
				$all,
				function ( $a, $b ) {
					return strcmp( $b['timestamp'], $a['timestamp'] );
				}
			);

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
