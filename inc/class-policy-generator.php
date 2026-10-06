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

			// La sezione cookie viene renderizzata per prima: l'indice e la
			// numerazione delle sezioni successive dipendono dalla sua presenza
			// EFFETTIVA. Se il Cookie Manager è attivo ma non produce sezioni
			// utili, has_cookie viene azzerato per evitare un indice che elenca
			// una sezione inesistente e una numerazione sfasata.
			$cookie_html           = self::section_cookie( $context );
			$context['has_cookie'] = ( trim( $cookie_html ) !== '' );

			$sections = array(
				'header'        => self::section_header( $context ),
				'titolare'      => self::section_titolare( $context ),
				'finalita'      => self::section_finalita( $context ),
				'trattamenti'   => self::section_trattamenti( $context ),
				'cookie'        => $cookie_html,
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
			$filtered = apply_filters( 'dbph_policy_sections', $sections, $context );
			// 1.8.0: un plugin terzo che restituisce un non-array non deve
			// bloccare la generazione: si torna alle sezioni dell'Hub.
			if ( is_array( $filtered ) ) {
				$sections = $filtered;
			} else {
				self::doing_it_wrong( 'dbph_policy_sections' );
			}

			$parts = array();
			foreach ( $sections as $section ) {
				if ( is_scalar( $section ) && trim( (string) $section ) !== '' ) {
					$parts[] = trim( (string) $section );
				}
			}
			$html = implode( "\n", $parts );

			/**
			 * Filtra l'HTML finale della Privacy Policy.
			 *
			 * @param string $html
			 * @param array  $context
			 */
			$filtered = apply_filters( 'dbph_policy_html', $html, $context );
			if ( ! is_string( $filtered ) ) {
				self::doing_it_wrong( 'dbph_policy_html' );
				return $html;
			}
			return $filtered;
		}

		/**
		 * Segnala (con WP_DEBUG) un filtro che ha restituito un tipo errato.
		 *
		 * @since 1.8.0
		 * @param string $hook
		 */
		private static function doing_it_wrong( $hook ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				_doing_it_wrong(
					'DBPH_Policy_Generator::generate',
					esc_html(
						sprintf(
							/* translators: %s: nome del filtro */
							__( 'Il filtro "%s" ha restituito un tipo non valido: il valore è stato ignorato.', 'db-privacy-hub' ),
							$hook
						)
					),
					'1.8.0'
				);
			}
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
				'has_db_dsar'   => self::has_db_dsar(),
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
			// 1.7.0: l'option sopravvive alla disattivazione del Form Builder:
			// dichiariamo reCAPTCHA solo se il plugin è effettivamente attivo.
			if ( ! post_type_exists( 'dbfb_form' ) ) {
				return false;
			}
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
					// 1.7.0: il Form Builder salva il webhook in `_dbfb_settings`
					// (webhook_url + enable_webhook); il meta `_dbfb_webhook_url`
					// letto fino alla 1.6.0 non è mai esistito.
					$settings = get_post_meta( $form_id, '_dbfb_settings', true );
					if ( ! is_array( $settings ) || empty( $settings['enable_webhook'] ) ) {
						continue;
					}
					$webhook_url = isset( $settings['webhook_url'] ) ? $settings['webhook_url'] : '';
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
			$date = '<span class="dbph-date">' . esc_html( $context['date'] ) . '</span>';

			$html  = '<h2>' . esc_html__( 'Informativa sul trattamento dei dati personali', 'db-privacy-hub' ) . '</h2>';
			$html .= '<p><strong>' . esc_html__( 'Ultimo aggiornamento:', 'db-privacy-hub' ) . '</strong> ' . $date . '</p>';
			$html .= '<p>' . sprintf(
				/* translators: 1: nome sito, 2: URL sito */
				esc_html__( 'La presente informativa descrive le modalità di trattamento dei dati personali degli utenti che consultano il sito %1$s (%2$s), ai sensi degli artt. 13 e 14 del Regolamento (UE) 2016/679 (GDPR) e del D.Lgs. 196/2003 e successive modificazioni (Codice Privacy).', 'db-privacy-hub' ),
				'<strong>' . $site . '</strong>',
				$url
			) . '</p>';

			// Indice clickabile (1.7.0: con ancore verso le sezioni).
			$index = array(
				'titolare'      => __( 'Titolare del trattamento', 'db-privacy-hub' ),
				'finalita'      => __( 'Finalità del trattamento e basi giuridiche', 'db-privacy-hub' ),
				'trattamenti'   => __( 'Trattamenti specifici', 'db-privacy-hub' ),
				'cookie'        => __( 'Cookie e tecnologie simili', 'db-privacy-hub' ),
				'destinatari'   => __( 'Destinatari dei dati', 'db-privacy-hub' ),
				'diritti'       => __( 'Diritti dell\'interessato', 'db-privacy-hub' ),
				'conservazione' => __( 'Conservazione dei dati', 'db-privacy-hub' ),
				'modifiche'     => __( 'Modifiche all\'informativa', 'db-privacy-hub' ),
				'reclamo'       => __( 'Reclamo all\'autorità di controllo', 'db-privacy-hub' ),
			);
			if ( ! $context['has_cookie'] ) {
				unset( $index['cookie'] );
			}
			$html .= '<h3>' . esc_html__( 'Indice', 'db-privacy-hub' ) . '</h3>';
			$html .= '<ol>';
			foreach ( $index as $anchor => $label ) {
				$html .= '<li><a href="#' . esc_attr( self::anchor( $anchor ) ) . '">' . esc_html( $label ) . '</a></li>';
			}
			$html .= '</ol>';

			return $html;
		}

		private static function section_titolare( $context ) {
			$t = $context['titolare'];

			$html = '<h3 id="' . esc_attr( self::anchor( 'titolare' ) ) . '">' . esc_html__( '1. Titolare del trattamento', 'db-privacy-hub' ) . '</h3>';

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

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- firma uniforme con le altre sezioni del generatore.
		private static function section_finalita( $context ) {
			$html  = '<h3 id="' . esc_attr( self::anchor( 'finalita' ) ) . '">' . esc_html__( '2. Finalità del trattamento e basi giuridiche', 'db-privacy-hub' ) . '</h3>';
			$html .= '<p>' . esc_html__( 'I dati personali raccolti tramite il sito vengono trattati per le finalità descritte di seguito. Per ciascuna finalità è indicata la base giuridica corrispondente, tra quelle previste dall\'art. 6 GDPR (consenso, esecuzione di un contratto, obbligo di legge, legittimo interesse).', 'db-privacy-hub' ) . '</p>';
			$html .= '<p>' . esc_html__( 'L\'elenco completo dei trattamenti specifici, comprensivo dei dati raccolti, della base giuridica puntuale e della durata di conservazione, è riportato nella sezione successiva.', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_trattamenti( $context ) {
			$register = $context['register'];

			$html = '<h3 id="' . esc_attr( self::anchor( 'trattamenti' ) ) . '">' . esc_html__( '3. Trattamenti specifici', 'db-privacy-hub' ) . '</h3>';

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
				return '<h3 id="' . esc_attr( self::anchor( 'cookie' ) ) . '">' . esc_html__( '4. Cookie e tecnologie simili', 'db-privacy-hub' ) . '</h3>'
					. '<p><em>' . esc_html__( 'Per la sezione cookie completa è richiesto DB Cookie Manager 3.1.0 o superiore.', 'db-privacy-hub' ) . '</em></p>';
			}

			$cookie_sections = DBCM_Policy_Generator::get_sections();
			if ( ! is_array( $cookie_sections ) || empty( $cookie_sections ) ) {
				return '';
			}

			// Sezioni del Cookie Manager da inglobare (skip: header, titolare,
			// updates e footer — duplicano contenuti dell'Hub).
			$keep = array( 'what_are_cookies', 'cookies_used', 'external_services', 'browser_management' );

			$html = '<h3 id="' . esc_attr( self::anchor( 'cookie' ) ) . '">' . esc_html__( '4. Cookie e tecnologie simili', 'db-privacy-hub' ) . '</h3>';
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
			$html = '<h3 id="' . esc_attr( self::anchor( 'destinatari' ) ) . '">' . sprintf(
				/* translators: %d: numero della sezione */
				esc_html__( '%d. Destinatari dei dati', 'db-privacy-hub' ),
				$num
			) . '</h3>';

			$responsabili = (array) $context['responsabili'];
			$dest         = (array) $context['destinatari'];

			// 1.5.0: le due liste CONFLUISCONO entrambe nel documento, in due
			// blocchi distinti (prima le dichiarazioni esplicite art. 28 con
			// DPA, poi i destinatari rilevati automaticamente / autonomi
			// titolari). Fino alla 1.4.0 le dichiarazioni esplicite
			// NASCONDEVANO la detection automatica: su un e-commerce con
			// responsabili dichiarati i gateway di pagamento sparivano dalla
			// policy.
			//
			// Dedup per nome (case-insensitive): se un soggetto rilevato è
			// già stato dichiarato esplicitamente, prevale la dichiarazione
			// esplicita (è il fatto giuridico) e la voce rilevata viene
			// omessa.
			$declared_names = array();
			foreach ( $responsabili as $r ) {
				if ( ! empty( $r['nome'] ) ) {
					$declared_names[] = strtolower( trim( $r['nome'] ) );
				}
			}
			$dest = array_values(
				array_filter(
					$dest,
					function ( $d ) use ( $declared_names ) {
						return empty( $d['name'] ) || ! in_array( strtolower( trim( $d['name'] ) ), $declared_names, true );
					}
				)
			);

			// 1.7.0: dedup dei destinatari rilevati per nome. Più gateway dello
			// stesso fornitore (stripe + stripe_sepa, ppcp-gateway + ppcp-card)
			// o più fonti di detection producevano voci ripetute.
			$seen = array();
			$dest = array_values(
				array_filter(
					$dest,
					function ( $d ) use ( &$seen ) {
						if ( ! is_array( $d ) || empty( $d['name'] ) ) {
							return false;
						}
						$k = strtolower( trim( (string) $d['name'] ) );
						if ( isset( $seen[ $k ] ) ) {
							return false;
						}
						$seen[ $k ] = true;
						return true;
					}
				)
			);

			$html .= '<p>' . esc_html__( 'Per le finalità sopra indicate, i dati personali possono essere comunicati ai soggetti elencati di seguito. I dati non vengono diffusi.', 'db-privacy-hub' ) . '</p>';

			/* -----------------------------------------------------------------
			 * Blocco 1: responsabili del trattamento (art. 28) dichiarati.
			 * -------------------------------------------------------------- */
			if ( ! empty( $responsabili ) ) {
				$html .= '<h4>' . esc_html__( 'Responsabili del trattamento (art. 28 GDPR)', 'db-privacy-hub' ) . '</h4>';
				$html .= '<p>' . esc_html__( 'Soggetti che trattano i dati per conto del titolare, vincolati da apposito contratto di nomina:', 'db-privacy-hub' ) . '</p>';
				$html .= '<ul>';
				foreach ( $responsabili as $r ) {
					$html .= '<li><strong>' . esc_html( $r['nome'] ) . '</strong>';
					$details = array();
					if ( ! empty( $r['ruolo'] ) ) {
						$details[] = esc_html( $r['ruolo'] );
					}
					if ( ! empty( $r['paese'] ) ) {
						if ( ! empty( $r['extra_ue'] ) ) {
							/* translators: %s: paese del responsabile */
							$details[] = sprintf( esc_html__( 'paese: %s (extra-UE)', 'db-privacy-hub' ), esc_html( $r['paese'] ) );
						} else {
							/* translators: %s: paese del responsabile */
							$details[] = sprintf( esc_html__( 'paese: %s', 'db-privacy-hub' ), esc_html( $r['paese'] ) );
						}
					}
					if ( ! empty( $r['garanzie'] ) ) {
						/* translators: %s: garanzie per il trasferimento */
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
			}

			/* -----------------------------------------------------------------
			 * Blocco 2: altri destinatari (autonomi titolari e soggetti
			 * rilevati automaticamente). Sempre presente: contiene almeno la
			 * voce sugli obblighi di legge, il fallback hosting quando non
			 * dichiarato esplicitamente, e i servizi rilevati (gateway di
			 * pagamento, SMTP, reCAPTCHA, webhook…).
			 * -------------------------------------------------------------- */
			$html .= '<h4>' . esc_html__( 'Altri destinatari', 'db-privacy-hub' ) . '</h4>';
			$html .= '<p>' . esc_html__( 'Soggetti che ricevono i dati in qualità di autonomi titolari del trattamento o per obbligo di legge:', 'db-privacy-hub' ) . '</p>';
			$html .= '<ul>';

			// Fallback hosting: solo se non risulta già una dichiarazione
			// esplicita (di norma l'hosting è un responsabile art. 28 e
			// andrebbe dichiarato nel blocco precedente).
			if ( empty( $responsabili ) ) {
				$html .= '<li>' . esc_html__( 'Fornitore di hosting del sito web (responsabile del trattamento ai sensi dell\'art. 28 GDPR).', 'db-privacy-hub' ) . '</li>';
			}

			foreach ( $dest as $d ) {
				if ( empty( $d['name'] ) ) {
					continue;
				}
				$html .= '<li><strong>' . esc_html( $d['name'] ) . '</strong> — ' . esc_html( $d['description'] ?? '' );
				if ( ! empty( $d['country'] ) ) {
					$html .= ' <em>(' . esc_html__( 'Paese:', 'db-privacy-hub' ) . ' ' . esc_html( $d['country'] ) . ')</em>';
				}
				$html .= '</li>';
			}

			$html .= '<li>' . esc_html__( 'Soggetti a cui la comunicazione sia necessaria per adempiere ad obblighi di legge (autorità giudiziaria, organi di vigilanza).', 'db-privacy-hub' ) . '</li>';
			$html .= '</ul>';

			$html .= '<p>' . esc_html__( 'I dati non vengono trasferiti a paesi extra-UE al di fuori di quanto specificato sopra per i singoli soggetti.', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_diritti( $context ) {
			$num = $context['has_cookie'] ? 6 : 5;
			$html = '<h3 id="' . esc_attr( self::anchor( 'diritti' ) ) . '">' . sprintf(
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

			// Menzione della procedura DSAR (1.7.0: generica per tutti i plugin
			// DB che dichiarano exporter/eraser, e testo allineato al flusso
			// reale — WordPress non offre un modulo pubblico: la richiesta
			// arriva al titolare, che la avvia dagli strumenti privacy).
			if ( ! empty( $context['has_db_dsar'] ) ) {
				$html .= '<p><strong>' . esc_html__( 'Procedura di esercizio dei diritti di accesso e cancellazione.', 'db-privacy-hub' ) . '</strong> ' . esc_html__( 'Il sito dispone di una procedura strutturata per l\'esportazione e la cancellazione dei dati personali: ricevuta la richiesta, il titolare invia all\'indirizzo email indicato un messaggio di conferma; dopo la conferma, i dati vengono raccolti da tutte le funzionalità del sito che li trattano e forniti in formato elettronico strutturato (o cancellati, salvo i dati soggetti a obblighi di conservazione) entro i termini di legge.', 'db-privacy-hub' ) . '</p>';
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

			// 1.2.0: blocco operativo "Esercita i tuoi diritti" — opt-in via option dbph_show_rights_howto (default ON).
			$show_howto = get_option( 'dbph_show_rights_howto', '1' );
			if ( $show_howto === '1' || $show_howto === 1 || $show_howto === true ) {
				$html .= self::build_rights_howto_block( $context );
			}

			return $html;
		}

		/**
		 * Blocco "Come esercitare i tuoi diritti" — istruzioni operative
		 * dettagliate per l'utente. Opt-in dal form titolare (default ON).
		 *
		 * @since 1.2.0
		 */
		private static function build_rights_howto_block( $context ) {
			$contact = self::get_contact_email_for_rights( $context );

			$html  = '<h4>' . esc_html__( 'Come esercitare concretamente i tuoi diritti', 'db-privacy-hub' ) . '</h4>';
			$html .= '<p>' . esc_html__( 'Per esercitare uno dei diritti sopra elencati, segui questa procedura:', 'db-privacy-hub' ) . '</p>';

			$html .= '<ol>';
			$html .= '<li>' . esc_html__( 'Identifica il diritto che vuoi esercitare tra quelli elencati (accesso, rettifica, cancellazione, ecc.).', 'db-privacy-hub' ) . '</li>';

			if ( $contact !== '' ) {
				$html .= '<li>' . sprintf(
					/* translators: %s: email del titolare */
					esc_html__( 'Invia una richiesta scritta a %s. Il messaggio non richiede una forma particolare, ma più la richiesta è specifica più sarà rapida la risposta.', 'db-privacy-hub' ),
					'<a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>'
				) . '</li>';
			} else {
				$html .= '<li>' . esc_html__( 'Invia una richiesta scritta al titolare del trattamento utilizzando i recapiti riportati in cima all\'informativa.', 'db-privacy-hub' ) . '</li>';
			}

			$html .= '<li>' . esc_html__( 'Includi: i tuoi dati identificativi (nome, cognome, indirizzo email utilizzato sul sito), la natura del diritto che intendi esercitare, eventuali dettagli utili per individuare i dati di tuo interesse.', 'db-privacy-hub' ) . '</li>';

			$html .= '<li>' . esc_html__( 'Per identificarti il titolare può richiederti documenti aggiuntivi, soprattutto in caso di richieste di cancellazione o portabilità.', 'db-privacy-hub' ) . '</li>';

			$html .= '<li>' . esc_html__( 'Il titolare risponde alla richiesta entro un mese dal ricevimento (art. 12.3 GDPR), prorogabile di ulteriori due mesi nei casi più complessi, dandone comunicazione all\'interessato.', 'db-privacy-hub' ) . '</li>';

			$html .= '<li>' . esc_html__( 'L\'esercizio dei diritti è gratuito. Solo in caso di richieste manifestamente infondate o eccessive (in particolare per il loro carattere ripetitivo) il titolare può addebitare un contributo spese ragionevole o rifiutare la richiesta motivandola.', 'db-privacy-hub' ) . '</li>';

			$html .= '</ol>';

			// 1.7.0: il reclamo al Garante è trattato una sola volta, nella
			// sezione dedicata (prima compariva due volte con due URL diversi).
			$html .= '<p>' . sprintf(
				/* translators: %s: link alla sezione reclamo */
				esc_html__( 'Resta fermo il diritto di proporre reclamo all\'autorità di controllo, descritto nella %s.', 'db-privacy-hub' ),
				'<a href="#' . esc_attr( self::anchor( 'reclamo' ) ) . '">' . esc_html__( 'sezione dedicata', 'db-privacy-hub' ) . '</a>'
			) . '</p>';

			return $html;
		}

		private static function section_conservazione( $context ) {
			$num = $context['has_cookie'] ? 7 : 6;
			$html = '<h3 id="' . esc_attr( self::anchor( 'conservazione' ) ) . '">' . sprintf(
				/* translators: %d: numero della sezione */
				esc_html__( '%d. Conservazione dei dati', 'db-privacy-hub' ),
				$num
			) . '</h3>';
			$html .= '<p>' . esc_html__( 'I dati personali sono conservati per il tempo strettamente necessario al perseguimento delle finalità per cui sono stati raccolti. Le durate specifiche sono indicate nella sezione "Trattamenti specifici" per ciascun trattamento. Decorso il periodo di conservazione, i dati sono cancellati o anonimizzati in modo irreversibile, salvo obblighi di legge che ne richiedano una conservazione più lunga.', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_modifiche( $context ) {
			$num = $context['has_cookie'] ? 8 : 7;
			$html = '<h3 id="' . esc_attr( self::anchor( 'modifiche' ) ) . '">' . sprintf(
				/* translators: %d: numero della sezione */
				esc_html__( '%d. Modifiche all\'informativa', 'db-privacy-hub' ),
				$num
			) . '</h3>';
			$html .= '<p>' . esc_html__( 'La presente informativa può essere soggetta a modifiche per adeguamenti normativi o organizzativi. La data dell\'ultimo aggiornamento è indicata in alto. In caso di modifiche sostanziali, l\'utente sarà informato attraverso un avviso visibile sul sito.', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_reclamo( $context ) {
			$num = $context['has_cookie'] ? 9 : 8;
			$html = '<h3 id="' . esc_attr( self::anchor( 'reclamo' ) ) . '">' . sprintf(
				/* translators: %d: numero della sezione */
				esc_html__( '%d. Reclamo all\'autorità di controllo', 'db-privacy-hub' ),
				$num
			) . '</h3>';
			$html .= '<p>' . esc_html__( 'L\'interessato che ritenga che il trattamento dei propri dati personali avvenga in violazione di quanto previsto dal GDPR ha il diritto di proporre reclamo al Garante per la protezione dei dati personali (art. 77 GDPR) — Piazza Venezia 11, 00187 Roma — sito web:', 'db-privacy-hub' ) . ' <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener noreferrer">www.garanteprivacy.it</a> — ' . esc_html__( 'oppure adire le opportune sedi giudiziarie (art. 79 GDPR).', 'db-privacy-hub' ) . '</p>';

			return $html;
		}

		private static function section_footer( $context ) {
			$date = '<span class="dbph-date">' . esc_html( $context['date'] ) . '</span>';
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

		/**
		 * Verifica se almeno un plugin DB attivo implementa il DSAR
		 * (exporter/eraser dichiarati sull'Hub o marker XXX_DSAR_AVAILABLE).
		 *
		 * 1.7.0: prima era riconosciuto solo il Form Builder (costante
		 * DBFB_DSAR_AVAILABLE + un'option `dbfb_version` mai scritta).
		 *
		 * @return bool
		 */
		private static function has_db_dsar() {
			$db_exporters = (array) apply_filters( 'dbph_user_data_exporters', array() );
			if ( ! empty( $db_exporters ) ) {
				return true;
			}
			$markers = array( 'DBFB_DSAR_AVAILABLE', 'DBEM_DSAR_AVAILABLE', 'DBR54_DSAR_AVAILABLE', 'DBSM_DSAR_AVAILABLE', 'DBSEO_DSAR_AVAILABLE' );
			foreach ( $markers as $const ) {
				if ( defined( $const ) && constant( $const ) ) {
					return true;
				}
			}
			/**
			 * Permette a plugin terzi di dichiarare la disponibilità del DSAR.
			 *
			 * @since 1.7.0
			 * @param bool $available
			 */
			return (bool) apply_filters( 'dbph_dsar_available', false );
		}

		/**
		 * ID HTML stabile per le ancore dell'indice.
		 *
		 * @since 1.7.0
		 * @param string $key
		 * @return string
		 */
		private static function anchor( $key ) {
			return 'dbph-' . sanitize_key( $key );
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
		 * Supporta: h2-h5, p, strong/b, em/i, code, a, br, hr, blockquote,
		 * ul/ol/li (anche annidati), table.
		 *
		 * @param string $html
		 * @return string
		 */
		public static function html_to_markdown( $html ) {
			$md = $html;

			// Headings.
			$md = preg_replace( '/<h2(?:\s[^>]*)?>(.*?)<\/h2>/is', "\n## $1\n", $md );
			$md = preg_replace( '/<h3(?:\s[^>]*)?>(.*?)<\/h3>/is', "\n### $1\n", $md );
			$md = preg_replace( '/<h4(?:\s[^>]*)?>(.*?)<\/h4>/is', "\n#### $1\n", $md );
			$md = preg_replace( '/<h5(?:\s[^>]*)?>(.*?)<\/h5>/is', "\n##### $1\n", $md );

			// Inline. 1.8.0: i nomi dei tag sono delimitati (`<b>` ma non
			// `<br>`/`<blockquote>`, `<i>` ma non `<img>`/`<iframe>`).
			$md = preg_replace( '/<strong(?:\s[^>]*)?>(.*?)<\/strong>/is', '**$1**', $md );
			$md = preg_replace( '/<b(?:\s[^>]*)?>(.*?)<\/b>/is', '**$1**', $md );
			$md = preg_replace( '/<em(?:\s[^>]*)?>(.*?)<\/em>/is', '*$1*', $md );
			$md = preg_replace( '/<i(?:\s[^>]*)?>(.*?)<\/i>/is', '*$1*', $md );
			$md = preg_replace( '/<code(?:\s[^>]*)?>(.*?)<\/code>/is', '`$1`', $md );

			// Links.
			$md = preg_replace( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $md );

			// br/hr.
			$md = preg_replace( '/<br\s*\/?>/i', "\n", $md );
			$md = preg_replace( '/<hr\s*\/?>/i', "\n---\n", $md );

			// Citazioni: ogni riga preceduta da "> ".
			$md = preg_replace_callback(
				'/<blockquote(?:\s[^>]*)?>(.*?)<\/blockquote>/is',
				function ( $m ) {
					$text = trim( wp_strip_all_tags( preg_replace( '/<\/p>\s*<p(?:\s[^>]*)?>/i', "\n\n", $m[1] ) ) );
					return "\n" . preg_replace( '/^/m', '> ', $text ) . "\n";
				},
				$md
			);

			// Liste. 1.8.0: le liste annidate sono indentate (2 spazi per
			// livello) e le <ol> numerate, invece di essere appiattite.
			$md = self::lists_to_markdown( $md );

			// Paragraphs.
			$md = preg_replace( '/<p(?:\s[^>]*)?>(.*?)<\/p>/is', "\n$1\n", $md );

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
			$md = preg_replace( '/[ \t]+$/m', '', $md );
			$md = preg_replace( "/\n{3,}/", "\n\n", $md );
			$md = trim( $md );

			return $md;
		}

		/**
		 * Converte <ul>/<ol>/<li> in elenchi Markdown, rispettando
		 * l'annidamento.
		 *
		 * @since 1.8.0
		 * @param string $html
		 * @return string
		 */
		private static function lists_to_markdown( $html ) {
			$stack = array(); // Un elemento per lista aperta: ['type' => ul|ol, 'n' => int].
			return preg_replace_callback(
				'/\s*<(\/?)(ul|ol|li)(?:\s[^>]*)?>\s*/i',
				function ( $m ) use ( &$stack ) {
					$closing = $m[1] === '/';
					$tag     = strtolower( $m[2] );
					if ( $tag === 'ul' || $tag === 'ol' ) {
						if ( $closing ) {
							array_pop( $stack );
							return empty( $stack ) ? "\n\n" : '';
						}
						$stack[] = array(
							'type' => $tag,
							'n'    => 0,
						);
						return count( $stack ) === 1 ? "\n\n" : '';
					}
					if ( $closing ) {
						return '';
					}
					// <li>: fuori da una lista (HTML malformato) vale come <ul>.
					$depth = max( 1, count( $stack ) );
					$top   = empty( $stack ) ? null : count( $stack ) - 1;
					$mark  = '- ';
					if ( null !== $top && $stack[ $top ]['type'] === 'ol' ) {
						++$stack[ $top ]['n'];
						$mark = $stack[ $top ]['n'] . '. ';
					}
					return "\n" . str_repeat( '  ', $depth - 1 ) . $mark;
				},
				$html
			);
		}
	}
}
