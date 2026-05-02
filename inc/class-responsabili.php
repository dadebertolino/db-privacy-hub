<?php
/**
 * DBPH_Responsabili — Gestione dei responsabili esterni del trattamento
 * dichiarati esplicitamente dall'admin (art. 28 GDPR).
 *
 * Differenza con la "detection automatica" del policy generator:
 *  - Detection automatica = "sembra che tu stia usando reCAPTCHA, lo dichiaro
 *    in policy". È deduzione tecnica.
 *  - Dichiarazione esplicita = "ho firmato un Data Processing Agreement con
 *    questo soggetto, è un responsabile ex art. 28". È un fatto giuridico.
 *
 * Storage: option `dbph_responsabili` come array di descrittori. Niente
 * tabella custom per mantenere il footprint basso.
 *
 * Schema della singola voce:
 *   array(
 *       'id'           => 'gd_hosting',     // chiave univoca
 *       'nome'         => 'GoDaddy Inc.',
 *       'ruolo'        => 'Hosting',
 *       'paese'        => 'Stati Uniti',
 *       'extra_ue'     => true,
 *       'garanzie'     => 'SCC + DPF',     // garanzie ex art. 46 GDPR
 *       'dpa_url'      => 'https://...',   // URL al DPA pubblico
 *       'note'         => '...',
 *   )
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Responsabili' ) ) {

	class DBPH_Responsabili {

		const OPTION_KEY = 'dbph_responsabili';

		public static function init() {
			// Niente hook qui: storage + getter/setter passivi, usati dalla UI
			// admin e dal policy generator.
		}

		/**
		 * Restituisce l'elenco dei responsabili dichiarati.
		 *
		 * @return array<int,array>
		 */
		public static function get_all() {
			$raw = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $raw ) ) {
				return array();
			}

			$out = array();
			foreach ( $raw as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['nome'] ) ) {
					continue;
				}
				$out[] = self::sanitize_entry( $entry );
			}
			return $out;
		}

		/**
		 * Sostituisce l'intero elenco.
		 *
		 * @param array $entries
		 * @return bool
		 */
		public static function save_all( array $entries ) {
			$clean = array();
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$nome = isset( $entry['nome'] ) ? trim( (string) $entry['nome'] ) : '';
				if ( $nome === '' ) {
					continue; // Salta voci vuote.
				}
				$clean[] = self::sanitize_entry( $entry );
			}
			return (bool) update_option( self::OPTION_KEY, $clean );
		}

		/**
		 * Pulisce e valida una singola voce.
		 *
		 * @param array $entry
		 * @return array
		 */
		public static function sanitize_entry( array $entry ) {
			$id = isset( $entry['id'] ) ? sanitize_key( (string) $entry['id'] ) : '';
			if ( $id === '' ) {
				$id = sanitize_key( substr( md5( wp_json_encode( $entry ) . microtime() ), 0, 12 ) );
			}

			return array(
				'id'        => $id,
				'nome'      => sanitize_text_field( $entry['nome'] ?? '' ),
				'ruolo'     => sanitize_text_field( $entry['ruolo'] ?? '' ),
				'paese'     => sanitize_text_field( $entry['paese'] ?? '' ),
				'extra_ue'  => ! empty( $entry['extra_ue'] ),
				'garanzie'  => sanitize_text_field( $entry['garanzie'] ?? '' ),
				'dpa_url'   => esc_url_raw( $entry['dpa_url'] ?? '' ),
				'note'      => sanitize_textarea_field( $entry['note'] ?? '' ),
			);
		}

		/**
		 * Verifica se sono stati dichiarati responsabili.
		 *
		 * @return bool
		 */
		public static function has_any() {
			return count( self::get_all() ) > 0;
		}
	}
}
