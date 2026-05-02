<?php
/**
 * DBPH_Policy_Generator — Generatore di Privacy Policy completa.
 *
 * Compone un documento informativa privacy (artt. 13-14 GDPR) basato su:
 *  - dati del titolare (option dbph_titolare_*)
 *  - registro trattamenti raccolto via filter dbph_processing_register
 *  - destinatari rilevati automaticamente dal sito (Google, plugin SMTP…)
 *  - sezioni cookie importate dal Cookie Manager (se installato)
 *
 * Output: HTML pronto per essere salvato come post_content di una pagina
 * WordPress oppure scaricato come file .md/.html.
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Policy_Generator' ) ) {

	class DBPH_Policy_Generator {

		/**
		 * Genera la Privacy Policy come HTML.
		 *
		 * @return string
		 */
		public static function generate() {
			$context = self::build_context();

			$sections = array(
				'header'        => self::section_header( $context ),
				'titolare'      => self::section_titolare( $context ),
				'finalita'      => self::section_finalita( $context ),
				'trattamenti'   => self::section_trattamenti( $context ),
				'cookie'        => self::section_cookie( $context ),
				'destinatari'   => self::section_destinatari( $context ),
				'diritti'       => self::section_diritti( $context ),
				'conservazione' => self::section_conservazione( $context ),
				'modifiche'     => self::section_modifiche( $context ),
				'reclamo'       => self::section_reclamo( $context ),
				'footer'        => self::section_footer( $context ),
			);

			/**
			 * Filtra l'array delle sezioni HTML della Privacy Policy.
			 *
			 * @param array $sections Array associativo key => html.
			 * @param array $context  Contesto di rendering.
			 */
			$sections = apply_filters( 'dbph_policy_sections', $sections, $context );

			$html = implode( "\n", array_filter( array_map( 'trim', $sections ) ) );

			/**
			 * Filtra l'HTML finale della Privacy Policy.
			 *
			 * @param string $html
			 * @param array  $context
			 */
			return apply_filters( 'dbph_policy_html', $html, $context );
		}

		/* =====================================================================
		 * CONTEXT
		 * ================================================================== */

		private static function build_context() {
			return array(
				'site_name'     => get_bloginfo( 'name' ),
				'site_url'      => home_url( '/' ),
				'admin_email'   => get_option( 'admin_email' ),
				'date'          => date_i18n( get_option( 'date_format' ) ),
				'titolare'      => self::get_titolare(),
				'register'      => DBPH_Register::collect(),
				'destinatari'   => self::detect_destinatari(),
				'responsabili'  => class_exists( 'DBPH_Responsabili' ) ? DBPH_Responsabili::get_all() : array(),
				'has_cookie'    => class_exists( 'DBCM_Policy_Generator' ),
			);
		}

		/**
		 * Restituisce i dati del titolare letti dalle option.
		 *
		 * @return array
		 */
		public static function get_titolare() {
			return array(
				'nome'      => trim( (string) get_option( 'dbph_titolare_nome', '' ) ),
				'piva'      => trim( (string) get_option( 'dbph_titolare_piva', '' ) ),
				'indirizzo' => trim( (string) get_option( 'dbph_titolare_indirizzo', '' ) ),
				'email'     => trim( (string) get_option( 'dbph_titolare_email', '' ) ),
				'pec'       => trim( (string) get_option( 'dbph_titolare_pec', '' ) ),
				'dpo'       => trim( (string) get_option( 'dbph_titolare_dpo', '' ) ),
			);
		}

		/**
		 * Verifica se il titolare è stato configurato (almeno il nome).
		 *
		 * @return bool
		 */
		public static function is_titolare_configured() {
			$t = self::get_titolare();
			return $t['nome'] !== '';
		}

		/* =====================================================================
		 * Detection destinatari automatica
		 * ================================================================== */

		/**
		 * Rileva destinatari probabili (servizi terzi che ricevono dati) basandosi
		 * sui plugin attivi e sulle impostazioni note. Restituisce un array di
		 * descrittori già pronti per il rendering.
		 *
		 * @return array<int,array{name:string,description:string,country:string}>
		 */
		private static function detect_destinatari() {
			$out = array();

			// Plugin SMTP attivi (l'email transazionale passa per provider esterni).
			$active_plugins = (array) get_option( 'active_plugins', array() );
			$active_string  = implode( '|', $active_plugins );

			if ( preg_match( '/wp-mail-smtp/i', $active_string ) ) {
				$out[] = array(
					'name'        => 'WP Mail SMTP',
					'description' => __( 'Plugin di routing email transazionale: invia le email del sito attraverso un provider configurato (Gmail, Outlook, Sendinblue, Mailgun, SendGrid, ecc.). Il destinatario reale dei dati dipende dal provider configurato dall\'admin.', 'db-privacy-hub' ),
					'country'     => __( 'Variabile', 'db-privacy-hub' ),
				);
			}
			if ( preg_match( '/fluent-smtp/i', $active_string ) ) {
				$out[] = array(
					'name'        => 'FluentSMTP',
					'description' => __( 'Plugin di routing email transazionale: configurabile su SES/Mailgun/SendGrid/Outlook/Gmail/SMTP custom.', 'db-privacy-hub' ),
					'country'     => __( 'Variabile', 'db-privacy-hub' ),
				);
			}
			if ( preg_match( '/easy-wp-smtp/i', $active_string ) ) {
				$out[] = array(
					'name'        => 'Easy WP SMTP',
					'description' => __( 'Plugin di routing email transazionale.', 'db-privacy-hub' ),
					'country'     => __( 'Variabile', 'db-privacy-hub' ),
				);
			}
			if ( preg_match( '/post-smtp/i', $active_string ) ) {
				$out[] = array(
					'name'        => 'Post SMTP',
					'description' => __( 'Plugin di routing email transazionale.', 'db-privacy-hub' ),
					'country'     => __( 'Variabile', 'db-privacy-hub' ),
				);
			}

			// reCAPTCHA configurato (Form Builder o impostazione globale).
			if ( self::is_recaptcha_configured() ) {
				$out[] = array(
					'name'        => 'Google LLC',
					'description' => __( 'Servizio reCAPTCHA per la protezione dei form da bot. Google riceve l\'IP e dati di interazione del visitatore al caricamento del widget e all\'invio del form. Trattamento basato su Standard Contractual Clauses (SCC) e DPF.', 'db-privacy-hub' ),
					'country'     => __( 'Stati Uniti (extra-UE)', 'db-privacy-hub' ),
				);
			}

			// Webhook host estratti dai Form Builder.
			$webhook_hosts = self::extract_webhook_hosts();
			foreach ( $webhook_hosts as $host ) {
				$out[] = array(
					'name'        => $host,
					'description' => sprintf(
						/* translators: %s: hostname */
						__( 'Endpoint webhook configurato in DB Form Builder: i dati del modulo vengono trasmessi a %s al momento dell\'invio.', 'db-privacy-hub' ),
						$host
					),
					'country'     => __( 'Da verificare in base alla giurisdizione del provider.', 'db-privacy-hub' ),
				);
			}

			/**
			 * Filtra l'elenco dei destinatari rilevati. I plugin terzi possono
			 * aggiungere/rimuovere voci.
			 *
			 * @param array $destinatari
			 */
			return (array) apply_filters( 'dbph_policy_destinatari', $out );
		}

		private static function is_recaptcha_configured() {
			// Form Builder espone le sue impostazioni come option `dbfb_global_settings`.
			$dbfb = get_option( 'dbfb_global_settings', array() );
			if ( is_array( $dbfb ) ) {
				if ( ! empty( $dbfb['recaptcha_site_key'] ) || ! empty( $dbfb['recaptcha_secret_key'] ) ) {
					return true;
				}
			}
			return false;
		}

		private static function extract_webhook_hosts() {
			$hosts = array();

			// Form Builder: webhook URLs configurati nei singoli form (CPT dbfb_form).
			if ( post_type_exists( 'dbfb_form' ) ) {
				$forms = get_posts(
					array(
						'post_type'      => 'dbfb_form',
						'post_status'    => 'publish',
						'posts_per_page' => 50,
						'fields'         => 'ids',
					)
				);
				foreach ( $forms as $form_id ) {
					$webhook_url = get_post_meta( $form_id, '_dbfb_webhook_url', true );
					if ( ! is_string( $webhook_url ) || $webhook_url === '' ) {
						continue;
					}
					$host = wp_parse_url( $webhook_url, PHP_URL_HOST );
					if ( $host && ! in_array( $host, $hosts, true ) ) {
						$hosts[] = $host;
					}
				}
			}

			return $hosts;
		}

		/* =====================================================================
		 * SEZIONI
		 * ================================================================== */

		private static function section_header( $context ) {
			$site = esc_html( $context['site_name'] );
			$url  = esc_url( $context['site_url'] );
			$date = esc_html( $context['date'] );

			$html  = '<h2>' . esc_html__( 'Informativa sul trattamento dei dati personali', 'db-privacy-hub' ) . '</h2>';
			$html .= '<p><strong>' . esc_html__( 'Ultimo aggiornamento:', 'db-privacy-hub' ) . '</strong> ' . $date . '</p>';
			$html .= '<p>' . sprintf(
				/* translators: 1: nome sito, 2: URL sito */
				esc_html__( 'La presente informativa descrive le modalità di trattamento dei dati personali degli utenti che consultano il sito %1$s (%2$s), ai sensi degli artt. 13 e 14 del Regolamento (UE) 2016/679 (GDPR) e del D.Lgs. 196/2003 e successive modificazioni (Codice Privacy).', 'db-privacy-hub' ),
				'<strong>' . $site . '</strong>',
				$url
			) . '</p>';

			// Indice clickabile.
			$html .= '<h3>' . esc_html__( 'Indice', 'db-privacy-hub' ) . '</h3>';
			$html .= '<ol>';
			$html .= '<li>' . esc_html__( 'Titolare del trattamento', 'db-privacy-hub' ) . '</li>';
			$html .= '<li>' . esc_html__( 'Finalità del trattamento e basi giuridiche', 'db-privacy-hub' ) . '</li>';
			$html .= '<li>' . esc_html__( 'Trattamenti specifici', 'db-privacy-hub' ) . '</li>';
			if ( $context['has_cookie'] ) {
				$html .= '<li>' . esc_html__( 'Cookie e tecnologie simili', 'db-privacy-hub' ) . '</li>';
			}
			$html .= '<li>' . esc_html__( 'Destinatari dei dati', 'db-privacy-hub' ) . '</li>';
			$html .= '<li>' . esc_html__( 'Diritti dell\'interessato', 'db-privacy-hub' ) . '</li>';
			$html .= '<li>' . esc_html__( 'Conservazione dei dati', 'db-privacy-hub' ) . '</li>';
			$html .= '<li>' . esc_html__( 'Modifiche all\'informativa', 'db-privacy-hub' ) . '</li>';
			$html .= '<li>' . esc_html__( 'Reclamo all\'autorità di controllo', 'db-privacy-hub' ) . '</li>';
			$html .= '</ol>';

			return $html;
		}

		private static function section_titolare( $context ) {
			$t = $context['titolare'];

			$html = '<h3>' . esc_html__( '1. Titolare del trattamento', 'db-privacy-hub' ) . '</h3>';

			if ( $t['nome'] === '' ) {
				$html .= '<p style="background:#fff3cd;border:1px solid #ffeaa7;padding:12px"><strong>' . esc_html__( 'Attenzione:', 'db-privacy-hub' ) . '</strong> ' . esc_html__( 'i dati del titolare non sono ancora stati configurati. Compila la sezione "Dati del titolare" nelle impostazioni di DB Privacy Hub prima di pubblicare questa informativa.', 'db-privacy-hub' ) . '</p>';
				return $html;
			}

			$html .= '<p>' . esc_html__( 'Il titolare del trattamento dei dati personali è:', 'db-privacy-hub' ) . '</p>';
			$html .= '<p><strong>' . esc_html( $t['nome'] ) . '</strong>';

			if ( $t['indirizzo'] !== '' ) {
				$html .= '<br>' . esc_html( $t['indirizzo'] );
			}
			if ( $t['piva'] !== '' ) {
				$html .= '<br>' . esc_html__( 'P.IVA / C.F.:', 'db-privacy-hub' ) . ' ' . esc_html( $t['piva'] );
			}

			$contact_email = $t['email'] !== '' ? $t['email'] : (string) $context['admin_email'];
			if ( $contact_email !== '' ) {
				$html .= '<br>' . esc_html__( 'Email:', 'db-privacy-hub' ) . ' <a href="mailto:' . esc_attr( $contact_email ) . '">' . esc_html( $contact_email ) . '</a>';
			}
			if ( $t['pec'] !== '' ) {
				$html .= '<br>' . esc_html__( 'PEC:', 'db-privacy-hub' ) . ' ' . esc_html( $t['pec'] );
			}
			$html .= '</p>';

			if ( $t['dpo'] !== '' ) {
				$html .= '<p><strong>' . esc_html__( 'Responsabile della Protezione dei Dati (DPO):', 'db-privacy-hub' ) . '</strong> ' . esc_html( $t['dpo'] ) . '</p>';
			}

			return $html;
		}

		private static function section_finalita( $context ) {
			$html  = '<h3>' . esc_html__( '2. Finalità del trattamento e basi giuridiche', 'db-privacy-hub' ) . '</h3>';
			$html .= '<p>' . esc_html__( 'I dati personali raccolti tramite il sito vengono trattati per le finalità descritte di seguito. Per ciascuna finalità è indicata la base giuridica corrispondente, tra quelle previste dall\'art. 6 GDPR (consenso, esecuzione di un contratto, obbligo di legge, legittimo interesse).', 'db-privacy-hub' ) . '</p>';
			$html .= '<p>' . esc_html__( 'L\'elenco completo dei trattamenti specifici, comprensivo dei dati raccolti, della base giuridica puntuale e della durata di conservazione, è riportato nella sezione successiva.', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_trattamenti( $context ) {
			$register = $context['register'];

			$html = '<h3>' . esc_html__( '3. Trattamenti specifici', 'db-privacy-hub' ) . '</h3>';

			if ( empty( $register ) ) {
				$html .= '<p><em>' . esc_html__( 'Nessun trattamento dichiarato. Verifica che i plugin DB siano attivi e che dichiarino correttamente i propri trattamenti via il filter dbph_processing_register.', 'db-privacy-hub' ) . '</em></p>';
				return $html;
			}

			$html .= '<p>' . esc_html__( 'Di seguito l\'elenco dei trattamenti tecnici attivi sul sito:', 'db-privacy-hub' ) . '</p>';

			foreach ( $register as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['label'] ) ) {
					continue;
				}
				if ( isset( $entry['status'] ) && $entry['status'] !== 'active' ) {
					continue; // Salta i trattamenti dichiarati come inattivi.
				}

				$html .= '<h4>' . esc_html( $entry['label'] ) . '</h4>';
				$html .= '<ul>';
				if ( ! empty( $entry['purpose'] ) ) {
					$html .= '<li><strong>' . esc_html__( 'Finalità:', 'db-privacy-hub' ) . '</strong> ' . esc_html( $entry['purpose'] ) . '</li>';
				}
				if ( ! empty( $entry['legal_basis'] ) ) {
					$html .= '<li><strong>' . esc_html__( 'Base giuridica:', 'db-privacy-hub' ) . '</strong> ' . esc_html( $entry['legal_basis'] ) . '</li>';
				}
				if ( ! empty( $entry['data_collected'] ) ) {
					$html .= '<li><strong>' . esc_html__( 'Dati raccolti:', 'db-privacy-hub' ) . '</strong> ' . esc_html( $entry['data_collected'] ) . '</li>';
				}
				if ( ! empty( $entry['retention'] ) ) {
					$html .= '<li><strong>' . esc_html__( 'Conservazione:', 'db-privacy-hub' ) . '</strong> ' . esc_html( $entry['retention'] ) . '</li>';
				}
				if ( ! empty( $entry['transfers'] ) ) {
					$html .= '<li><strong>' . esc_html__( 'Trasferimenti:', 'db-privacy-hub' ) . '</strong> ' . esc_html( $entry['transfers'] ) . '</li>';
				}
				$html .= '</ul>';
			}

			return $html;
		}

		/**
		 * Sezione cookie: importa le sezioni dal Cookie Manager se installato.
		 * Salta header e titolare (gestiti dall'Hub) — prende solo le sezioni
		 * informative tecniche.
		 */
		private static function section_cookie( $context ) {
			if ( ! $context['has_cookie'] ) {
				return '';
			}

			if ( ! method_exists( 'DBCM_Policy_Generator', 'get_sections' ) ) {
				// Cookie Manager troppo vecchio (< 3.1.0) — invita all'aggiornamento
				// con un placeholder neutro che NON rompe l'output.
				return '<h3>' . esc_html__( '4. Cookie e tecnologie simili', 'db-privacy-hub' ) . '</h3>'
					. '<p><em>' . esc_html__( 'Per la sezione cookie completa è richiesto DB Cookie Manager 3.1.0 o superiore.', 'db-privacy-hub' ) . '</em></p>';
			}

			$cookie_sections = DBCM_Policy_Generator::get_sections();
			if ( ! is_array( $cookie_sections ) || empty( $cookie_sections ) ) {
				return '';
			}

			// Sezioni del Cookie Manager da inglobare (skip: header, titolare,
			// updates e footer — duplicano contenuti dell'Hub).
			$keep = array( 'what_are_cookies', 'cookies_used', 'external_services', 'browser_management' );

			$html = '<h3>' . esc_html__( '4. Cookie e tecnologie simili', 'db-privacy-hub' ) . '</h3>';
			$html .= '<p>' . esc_html__( 'L\'elenco completo dei cookie utilizzati sul sito, comprensivo di durata, fornitore e finalità, è riportato di seguito. Le preferenze possono essere modificate in qualsiasi momento attraverso il banner cookie.', 'db-privacy-hub' ) . '</p>';

			// Demote dei sotto-titoli h3 → h4 / h4 → h5 per coerenza gerarchica.
			$any = false;
			foreach ( $keep as $key ) {
				if ( empty( $cookie_sections[ $key ] ) ) {
					continue;
				}
				$any = true;
				$frag = (string) $cookie_sections[ $key ];
				$frag = preg_replace( '/<h4(\s|>)/', '<h5$1', $frag );
				$frag = preg_replace( '/<\/h4>/', '</h5>', $frag );
				$frag = preg_replace( '/<h3(\s|>)/', '<h4$1', $frag );
				$frag = preg_replace( '/<\/h3>/', '</h4>', $frag );
				$html .= "\n" . $frag;
			}

			if ( ! $any ) {
				return '';
			}

			return $html;
		}

		private static function section_destinatari( $context ) {
			$num = $context['has_cookie'] ? 5 : 4;
			$html = '<h3>' . sprintf(
				/* translators: %d: numero della sezione */
				esc_html__( '%d. Destinatari dei dati', 'db-privacy-hub' ),
				$num
			) . '</h3>';

			$has_responsabili = ! empty( $context['responsabili'] );

			if ( $has_responsabili ) {
				$html .= '<p>' . esc_html__( 'Per le finalità sopra indicate, i dati personali sono comunicati ai seguenti soggetti, in qualità di responsabili del trattamento ai sensi dell\'art. 28 GDPR (vincolati da apposito contratto):', 'db-privacy-hub' ) . '</p>';
				$html .= '<ul>';
				foreach ( $context['responsabili'] as $r ) {
					$html .= '<li><strong>' . esc_html( $r['nome'] ) . '</strong>';
					$details = array();
					if ( ! empty( $r['ruolo'] ) ) {
						$details[] = esc_html( $r['ruolo'] );
					}
					if ( ! empty( $r['paese'] ) ) {
						$details[] = ! empty( $r['extra_ue'] )
							? sprintf( esc_html__( 'paese: %s (extra-UE)', 'db-privacy-hub' ), esc_html( $r['paese'] ) )
							: sprintf( esc_html__( 'paese: %s', 'db-privacy-hub' ), esc_html( $r['paese'] ) );
					}
					if ( ! empty( $r['garanzie'] ) ) {
						$details[] = sprintf( esc_html__( 'garanzie: %s', 'db-privacy-hub' ), esc_html( $r['garanzie'] ) );
					}
					if ( $details ) {
						$html .= ' — ' . implode( '; ', $details );
					}
					if ( ! empty( $r['note'] ) ) {
						$html .= '. ' . esc_html( $r['note'] );
					}
					if ( ! empty( $r['dpa_url'] ) ) {
						$html .= ' <a href="' . esc_url( $r['dpa_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'DPA', 'db-privacy-hub' ) . '</a>';
					}
					$html .= '</li>';
				}
				$html .= '</ul>';
			} else {
				$html .= '<p>' . esc_html__( 'I dati personali raccolti possono essere comunicati ai seguenti destinatari, in qualità di responsabili del trattamento o autonomi titolari, esclusivamente per le finalità sopra indicate:', 'db-privacy-hub' ) . '</p>';
				$html .= '<ul>';
				$html .= '<li>' . esc_html__( 'Fornitore di hosting del sito web (responsabile del trattamento ai sensi dell\'art. 28 GDPR).', 'db-privacy-hub' ) . '</li>';

				$dest = $context['destinatari'];
				if ( ! empty( $dest ) ) {
					foreach ( $dest as $d ) {
						$html .= '<li><strong>' . esc_html( $d['name'] ) . '</strong> — ' . esc_html( $d['description'] );
						if ( ! empty( $d['country'] ) ) {
							$html .= ' <em>(' . esc_html__( 'Paese:', 'db-privacy-hub' ) . ' ' . esc_html( $d['country'] ) . ')</em>';
						}
						$html .= '</li>';
					}
				}

				$html .= '<li>' . esc_html__( 'Soggetti a cui la comunicazione sia necessaria per adempiere ad obblighi di legge (autorità giudiziaria, organi di vigilanza).', 'db-privacy-hub' ) . '</li>';
				$html .= '</ul>';
			}

			$html .= '<p>' . esc_html__( 'I dati non vengono diffusi e non vengono trasferiti a paesi extra-UE al di fuori di quanto eventualmente specificato sopra.', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_diritti( $context ) {
			$num = $context['has_cookie'] ? 6 : 5;
			$html = '<h3>' . sprintf(
				/* translators: %d: numero della sezione */
				esc_html__( '%d. Diritti dell\'interessato', 'db-privacy-hub' ),
				$num
			) . '</h3>';

			$html .= '<p>' . esc_html__( 'In qualità di interessato, l\'utente ha diritto di esercitare in qualsiasi momento i diritti previsti dagli artt. 15-22 del GDPR:', 'db-privacy-hub' ) . '</p>';

			$html .= '<ul>';
			$html .= '<li><strong>' . esc_html__( 'Accesso (art. 15)', 'db-privacy-hub' ) . '</strong> — ' . esc_html__( 'ottenere conferma dell\'esistenza del trattamento e copia dei dati.', 'db-privacy-hub' ) . '</li>';
			$html .= '<li><strong>' . esc_html__( 'Rettifica (art. 16)', 'db-privacy-hub' ) . '</strong> — ' . esc_html__( 'ottenere la correzione di dati inesatti o incompleti.', 'db-privacy-hub' ) . '</li>';
			$html .= '<li><strong>' . esc_html__( 'Cancellazione (art. 17)', 'db-privacy-hub' ) . '</strong> — ' . esc_html__( 'ottenere la cancellazione dei dati personali ("diritto all\'oblio") nei casi previsti.', 'db-privacy-hub' ) . '</li>';
			$html .= '<li><strong>' . esc_html__( 'Limitazione (art. 18)', 'db-privacy-hub' ) . '</strong> — ' . esc_html__( 'ottenere la limitazione del trattamento.', 'db-privacy-hub' ) . '</li>';
			$html .= '<li><strong>' . esc_html__( 'Portabilità (art. 20)', 'db-privacy-hub' ) . '</strong> — ' . esc_html__( 'ricevere i dati in formato strutturato e leggibile da dispositivo automatico.', 'db-privacy-hub' ) . '</li>';
			$html .= '<li><strong>' . esc_html__( 'Opposizione (art. 21)', 'db-privacy-hub' ) . '</strong> — ' . esc_html__( 'opporsi al trattamento per motivi connessi alla situazione particolare dell\'interessato.', 'db-privacy-hub' ) . '</li>';
			$html .= '<li><strong>' . esc_html__( 'Revoca del consenso', 'db-privacy-hub' ) . '</strong> — ' . esc_html__( 'revocare in ogni momento il consenso prestato, senza che ciò pregiudichi la liceità dei trattamenti svolti prima della revoca.', 'db-privacy-hub' ) . '</li>';
			$html .= '</ul>';

			// Menzione DSAR automatico Form Builder 2.5.0+.
			if ( self::has_dbfb_dsar() ) {
				$html .= '<p><strong>' . esc_html__( 'Procedura semplificata di esercizio dei diritti.', 'db-privacy-hub' ) . '</strong> ' . esc_html__( 'Il sito mette a disposizione una procedura automatica (DSAR — Data Subject Access Request) per richiedere la copia dei propri dati o la cancellazione: l\'utente può effettuare la richiesta direttamente attraverso un modulo dedicato, riceverà una email di conferma e i dati richiesti gli verranno forniti entro i termini di legge.', 'db-privacy-hub' ) . '</p>';
			}

			// Contatto per esercitare i diritti.
			$contact = self::get_contact_email_for_rights( $context );
			if ( $contact !== '' ) {
				$html .= '<p>' . sprintf(
					/* translators: %s: email del titolare */
					esc_html__( 'Le richieste relative all\'esercizio dei diritti possono essere inviate al titolare scrivendo a %s. Il titolare risponde entro un mese, prorogabile di altri due mesi in caso di particolare complessità.', 'db-privacy-hub' ),
					'<a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>'
				) . '</p>';
			}

			return $html;
		}

		private static function section_conservazione( $context ) {
			$num = $context['has_cookie'] ? 7 : 6;
			$html = '<h3>' . sprintf(
				/* translators: %d: numero della sezione */
				esc_html__( '%d. Conservazione dei dati', 'db-privacy-hub' ),
				$num
			) . '</h3>';
			$html .= '<p>' . esc_html__( 'I dati personali sono conservati per il tempo strettamente necessario al perseguimento delle finalità per cui sono stati raccolti. Le durate specifiche sono indicate nella sezione "Trattamenti specifici" per ciascun trattamento. Decorso il periodo di conservazione, i dati sono cancellati o anonimizzati in modo irreversibile, salvo obblighi di legge che ne richiedano una conservazione più lunga.', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_modifiche( $context ) {
			$num = $context['has_cookie'] ? 8 : 7;
			$html = '<h3>' . sprintf(
				/* translators: %d: numero della sezione */
				esc_html__( '%d. Modifiche all\'informativa', 'db-privacy-hub' ),
				$num
			) . '</h3>';
			$html .= '<p>' . esc_html__( 'La presente informativa può essere soggetta a modifiche per adeguamenti normativi o organizzativi. La data dell\'ultimo aggiornamento è indicata in alto. In caso di modifiche sostanziali, l\'utente sarà informato attraverso un avviso visibile sul sito.', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_reclamo( $context ) {
			$num = $context['has_cookie'] ? 9 : 8;
			$html = '<h3>' . sprintf(
				/* translators: %d: numero della sezione */
				esc_html__( '%d. Reclamo all\'autorità di controllo', 'db-privacy-hub' ),
				$num
			) . '</h3>';
			$html .= '<p>' . esc_html__( 'L\'interessato che ritenga che il trattamento dei propri dati personali avvenga in violazione di quanto previsto dal GDPR ha il diritto di proporre reclamo al Garante per la protezione dei dati personali — Piazza Venezia 11, 00187 Roma — sito web:', 'db-privacy-hub' ) . ' <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">www.garanteprivacy.it</a> — ' . esc_html__( 'oppure adire le opportune sedi giudiziarie.', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_footer( $context ) {
			$date = esc_html( $context['date'] );
			$html  = '<hr>';
			$html .= '<p style="font-size:0.85em;color:#666"><em>';
			$html .= sprintf(
				/* translators: %s: data generazione */
				esc_html__( 'Documento generato automaticamente da DB Privacy Hub il %s. Si raccomanda la verifica da parte di un professionista prima della pubblicazione definitiva.', 'db-privacy-hub' ),
				$date
			);
			$html .= '</em></p>';

			return $html;
		}

		/* =====================================================================
		 * Helper
		 * ================================================================== */

		private static function has_dbfb_dsar() {
			// Form Builder 2.5.0+ pubblica la presenza del DSAR via option/constante;
			// per ora rileviamo via flag esposto dal Form Builder.
			if ( defined( 'DBFB_DSAR_AVAILABLE' ) && DBFB_DSAR_AVAILABLE ) {
				return true;
			}
			$ver = get_option( 'dbfb_version', '' );
			if ( $ver && version_compare( $ver, '2.5.0', '>=' ) ) {
				return true;
			}
			return false;
		}

		private static function get_contact_email_for_rights( $context ) {
			$t = $context['titolare'];
			if ( $t['email'] !== '' ) {
				return $t['email'];
			}
			if ( ! empty( $context['admin_email'] ) ) {
				return (string) $context['admin_email'];
			}
			return '';
		}

		/* =====================================================================
		 * Conversione HTML → Markdown (per export .md)
		 * ================================================================== */

		/**
		 * Converte l'HTML della Privacy Policy in Markdown semplice.
		 * Supporta: h2-h5, p, strong/em, ul/ol/li, hr, a.
		 *
		 * @param string $html
		 * @return string
		 */
		public static function html_to_markdown( $html ) {
			$md = $html;

			// Headings.
			$md = preg_replace( '/<h2[^>]*>(.*?)<\/h2>/is', "\n## $1\n", $md );
			$md = preg_replace( '/<h3[^>]*>(.*?)<\/h3>/is', "\n### $1\n", $md );
			$md = preg_replace( '/<h4[^>]*>(.*?)<\/h4>/is', "\n#### $1\n", $md );
			$md = preg_replace( '/<h5[^>]*>(.*?)<\/h5>/is', "\n##### $1\n", $md );

			// Inline.
			$md = preg_replace( '/<strong[^>]*>(.*?)<\/strong>/is', '**$1**', $md );
			$md = preg_replace( '/<b[^>]*>(.*?)<\/b>/is', '**$1**', $md );
			$md = preg_replace( '/<em[^>]*>(.*?)<\/em>/is', '*$1*', $md );
			$md = preg_replace( '/<i[^>]*>(.*?)<\/i>/is', '*$1*', $md );
			$md = preg_replace( '/<code[^>]*>(.*?)<\/code>/is', '`$1`', $md );

			// Links.
			$md = preg_replace( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $md );

			// br/hr.
			$md = preg_replace( '/<br\s*\/?>/i', "\n", $md );
			$md = preg_replace( '/<hr\s*\/?>/i', "\n---\n", $md );

			// Lists: li → "- ", ul/ol vengono rimossi (la struttura linea-per-linea
			// è sufficiente per markdown semplice).
			$md = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $md );
			$md = preg_replace( '/<\/?(ul|ol)[^>]*>/i', "\n", $md );

			// Paragraphs.
			$md = preg_replace( '/<p[^>]*>(.*?)<\/p>/is', "\n$1\n", $md );

			// Tabelle (semplice fallback: pipe separator).
			$md = preg_replace_callback(
				'/<table[^>]*>(.*?)<\/table>/is',
				function ( $m ) {
					$inner = $m[1];
					$rows = array();
					if ( preg_match_all( '/<tr[^>]*>(.*?)<\/tr>/is', $inner, $tr_match ) ) {
						foreach ( $tr_match[1] as $tr ) {
							$cells = array();
							if ( preg_match_all( '/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $tr, $td_match ) ) {
								foreach ( $td_match[1] as $cell ) {
									$cells[] = trim( wp_strip_all_tags( $cell ) );
								}
							}
							if ( $cells ) {
								$rows[] = '| ' . implode( ' | ', $cells ) . ' |';
							}
						}
					}
					if ( $rows ) {
						// Riga di separazione dopo l'header.
						$header_cells = substr_count( $rows[0], '|' ) - 1;
						$sep          = '| ' . implode( ' | ', array_fill( 0, $header_cells, '---' ) ) . ' |';
						array_splice( $rows, 1, 0, array( $sep ) );
						return "\n" . implode( "\n", $rows ) . "\n";
					}
					return '';
				},
				$md
			);

			// Cleanup tag residui.
			$md = wp_strip_all_tags( $md );

			// Decode entities.
			$md = html_entity_decode( $md, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			// Normalizza whitespace.
			$md = preg_replace( "/\n{3,}/", "\n\n", $md );
			$md = trim( $md );

			return $md;
		}
	}
}
