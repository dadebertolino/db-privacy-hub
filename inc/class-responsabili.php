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
			return self::unique_ids( $out );
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
			return (bool) update_option( self::OPTION_KEY, self::unique_ids( $clean ) );
		}

		/**
		 * Rende univoci gli id dell'elenco (due voci identiche senza id
		 * ricevono lo stesso id derivato dal contenuto): dalla seconda
		 * occorrenza si aggiunge un suffisso -2, -3…
		 *
		 * @since 1.8.0
		 * @param array $entries Voci già sanitizzate.
		 * @return array
		 */
		private static function unique_ids( array $entries ) {
			$seen = array();
			foreach ( $entries as $i => $entry ) {
				$id   = $entry['id'];
				$base = $id;
				$n    = 1;
				while ( isset( $seen[ $id ] ) ) {
					++$n;
					$id = $base . '-' . $n;
				}
				$seen[ $id ]         = true;
				$entries[ $i ]['id'] = $id;
			}
			return $entries;
		}

		/**
		 * Pulisce e valida una singola voce.
		 *
		 * @param array $entry
		 * @return array
		 */
		public static function sanitize_entry( array $entry ) {
			$clean = array(
				'id'        => isset( $entry['id'] ) && is_scalar( $entry['id'] ) ? sanitize_key( (string) $entry['id'] ) : '',
				'nome'      => self::text( $entry, 'nome' ),
				'ruolo'     => self::text( $entry, 'ruolo' ),
				'paese'     => self::text( $entry, 'paese' ),
				'extra_ue'  => ! empty( $entry['extra_ue'] ),
				'garanzie'  => self::text( $entry, 'garanzie' ),
				'dpa_url'   => isset( $entry['dpa_url'] ) && is_string( $entry['dpa_url'] ) ? esc_url_raw( $entry['dpa_url'] ) : '',
				'note'      => isset( $entry['note'] ) && is_scalar( $entry['note'] ) ? sanitize_textarea_field( (string) $entry['note'] ) : '',
			);

			// 1.8.0: id derivato dal contenuto, non da microtime(): una voce
			// salvata senza id riceve lo stesso id a ogni lettura.
			if ( $clean['id'] === '' ) {
				$fingerprint = $clean;
				unset( $fingerprint['id'] );
				$clean['id'] = 'r' . substr( md5( wp_json_encode( $fingerprint ) ), 0, 11 );
			}

			return $clean;
		}

		private static function text( array $entry, $key ) {
			return isset( $entry[ $key ] ) && is_scalar( $entry[ $key ] ) ? sanitize_text_field( (string) $entry[ $key ] ) : '';
		}

		/**
		 * Verifica se sono stati dichiarati responsabili.
		 *
		 * @return bool
		 */
		public static function has_any() {
			return count( self::get_all() ) > 0;
		}

		/* =====================================================================
		 * Modelli rapidi (1.5.0)
		 * ================================================================== */

		/**
		 * Modelli precompilati per i responsabili art. 28 più comuni di un
		 * sito / e-commerce. Il campo `nome` è volutamente un placeholder:
		 * l'admin DEVE sostituirlo con la ragione sociale reale del soggetto
		 * nominato — il modello serve solo a non dimenticare la categoria e
		 * a precompilare ruolo e note.
		 *
		 * @since 1.5.0
		 * @return array<string,array>
		 */
		public static function get_templates() {
			$templates = array(
				'commercialista' => array(
					'nome'     => __( '[Nome studio / commercialista]', 'db-privacy-hub' ),
					'ruolo'    => __( 'Consulenza fiscale e contabile', 'db-privacy-hub' ),
					'paese'    => __( 'Italia', 'db-privacy-hub' ),
					'extra_ue' => false,
					'garanzie' => '',
					'note'     => __( 'Tenuta della contabilità ed elaborazione dei documenti fiscali relativi agli ordini. Verificare che l\'atto di nomina ex art. 28 sia stato sottoscritto prima di pubblicare la policy.', 'db-privacy-hub' ),
				),
				'webmaster' => array(
					'nome'     => __( '[Nome agenzia / webmaster]', 'db-privacy-hub' ),
					'ruolo'    => __( 'Gestione e manutenzione tecnica del sito', 'db-privacy-hub' ),
					'paese'    => __( 'Italia', 'db-privacy-hub' ),
					'extra_ue' => false,
					'garanzie' => '',
					'note'     => __( 'Accesso all\'amministrazione del sito e ai dati in esso contenuti per attività di sviluppo, manutenzione e assistenza.', 'db-privacy-hub' ),
				),
				'hosting' => array(
					'nome'     => __( '[Nome provider hosting]', 'db-privacy-hub' ),
					'ruolo'    => __( 'Hosting del sito web', 'db-privacy-hub' ),
					'paese'    => '',
					'extra_ue' => false,
					'garanzie' => '',
					'note'     => __( 'Conservazione dei dati del sito sui propri server. Indicare paese ed eventuali garanzie se extra-UE.', 'db-privacy-hub' ),
				),
				'fatturazione' => array(
					'nome'     => __( '[Servizio fatturazione elettronica]', 'db-privacy-hub' ),
					'ruolo'    => __( 'Fatturazione elettronica', 'db-privacy-hub' ),
					'paese'    => __( 'Italia', 'db-privacy-hub' ),
					'extra_ue' => false,
					'garanzie' => '',
					'note'     => __( 'Emissione, trasmissione allo SdI e conservazione a norma delle fatture elettroniche.', 'db-privacy-hub' ),
				),
				'email' => array(
					'nome'     => __( '[Provider email transazionale]', 'db-privacy-hub' ),
					'ruolo'    => __( 'Invio email transazionali', 'db-privacy-hub' ),
					'paese'    => '',
					'extra_ue' => false,
					'garanzie' => '',
					'note'     => __( 'Recapito delle email del sito (conferme ordine, notifiche). Indicare paese ed eventuali garanzie se extra-UE.', 'db-privacy-hub' ),
				),
				'backup' => array(
					'nome'     => __( '[Servizio di backup esterno]', 'db-privacy-hub' ),
					'ruolo'    => __( 'Backup e disaster recovery', 'db-privacy-hub' ),
					'paese'    => '',
					'extra_ue' => false,
					'garanzie' => '',
					'note'     => __( 'Copie di sicurezza del sito e del database, inclusi i dati personali in essi contenuti.', 'db-privacy-hub' ),
				),
			);

			/**
			 * Filtra i modelli rapidi di responsabili esterni.
			 *
			 * @since 1.5.0
			 * @param array $templates
			 */
			$filtered = apply_filters( 'dbph_responsabili_templates', $templates );

			// 1.8.0: solo modelli ben formati (array con nome).
			$valid = array();
			foreach ( is_array( $filtered ) ? $filtered : array() as $key => $template ) {
				$key = sanitize_key( (string) $key );
				if ( $key !== '' && is_array( $template ) && ! empty( $template['nome'] ) && is_scalar( $template['nome'] ) ) {
					$valid[ $key ] = $template;
				}
			}
			return $valid;
		}

		/**
		 * Etichette leggibili dei modelli, per la UI.
		 *
		 * 1.8.0: derivate dai modelli effettivi, così compaiono anche quelli
		 * aggiunti con il filtro dbph_responsabili_templates. Etichetta: la
		 * chiave `label` del modello, altrimenti quella di serie, altrimenti
		 * il ruolo, altrimenti la chiave.
		 *
		 * @since 1.5.0
		 * @return array<string,string>
		 */
		public static function get_template_labels() {
			$builtin = array(
				'commercialista' => __( 'Commercialista / consulente fiscale', 'db-privacy-hub' ),
				'webmaster'      => __( 'Webmaster / agenzia web', 'db-privacy-hub' ),
				'hosting'        => __( 'Provider di hosting', 'db-privacy-hub' ),
				'fatturazione'   => __( 'Fatturazione elettronica', 'db-privacy-hub' ),
				'email'          => __( 'Email transazionale', 'db-privacy-hub' ),
				'backup'         => __( 'Backup esterno', 'db-privacy-hub' ),
			);

			$labels = array();
			foreach ( self::get_templates() as $key => $template ) {
				if ( ! empty( $template['label'] ) && is_scalar( $template['label'] ) ) {
					$labels[ $key ] = (string) $template['label'];
				} elseif ( isset( $builtin[ $key ] ) ) {
					$labels[ $key ] = $builtin[ $key ];
				} elseif ( ! empty( $template['ruolo'] ) && is_scalar( $template['ruolo'] ) ) {
					$labels[ $key ] = (string) $template['ruolo'];
				} else {
					$labels[ $key ] = $key;
				}
			}
			return $labels;
		}

		/**
		 * Aggiunge in coda all'elenco una voce precompilata da modello.
		 *
		 * @since 1.5.0
		 * @param string $template_key
		 * @return bool  false se il modello non esiste.
		 */
		public static function add_from_template( $template_key ) {
			$templates = self::get_templates();
			if ( ! isset( $templates[ $template_key ] ) ) {
				return false;
			}
			$entries   = self::get_all();
			$entries[] = self::sanitize_entry( $templates[ $template_key ] );
			return self::save_all( $entries );
		}
	}
}
