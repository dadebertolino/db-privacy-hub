<?php
/**
 * DBPH_Embed_Bridge — Detection e dichiarazione di contenuti social/embed.
 *
 * Rileva le piattaforme terze i cui contenuti sono INCORPORATI nel sito
 * (non semplicemente linkati: un link non trasferisce dati, un embed sì)
 * e le traduce nelle dichiarazioni che l'Hub si aspetta:
 *
 *  1. Trattamento `dbemb_embeds` sul registro (base: consenso art. 6.1.a),
 *     solo se viene rilevata o abilitata a mano almeno una piattaforma.
 *  2. Trattamento `dbemb_pixel` (remarketing) se è attivo un plugin pixel noto.
 *  3. Piattaforme come destinatari (`dbph_policy_destinatari`) con
 *     descrittori precompilati (titolarità, paese, garanzie extra-UE).
 *  4. Paragrafo sulla contitolarità per i dati Insights delle pagine social
 *     (CGUE C-210/16 Wirtschaftsakademie), attivabile con checkbox.
 *
 * CANALI DI DETECTION (in ordine di affidabilità):
 *  - blocchi Gutenberg embed (marker espliciti providerNameSlug)
 *  - iframe/script di incorporamento nel post_content (pattern di embed,
 *    MAI hostname generici: i link ai profili social sono volutamente esclusi)
 *  - plugin noti attivi (feed social, pixel)
 *
 * ABILITAZIONE MANUALE: le piattaforme note possono essere dichiarate a mano
 * con i checkbox nella pagina "Genera Privacy Policy" (option
 * `dbph_embed_manual`), per i casi che la scansione non può vedere: embed nel
 * tema, share button, page builder che non salvano nel post_content.
 * La detection AGGIUNGE, il manuale AGGIUNGE: non si escludono a vicenda.
 *
 * La scansione contenuti è cachata in transient (12h), invalidato ad ogni
 * salvataggio di contenuto. Il registro viene raccolto solo in admin, quindi
 * il costo sul frontend è nullo.
 *
 * Disattivabile via filter:
 *   add_filter( 'dbph_embed_bridge_enabled', '__return_false' );
 *
 * FUORI SCOPE (dichiarato): social login (dipende dal plugin specifico) e
 * share button renderizzati dal tema (non ispezionabili dal database) — per
 * questi ultimi esiste appunto l'abilitazione manuale.
 *
 * @package DB_Privacy_Hub
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Embed_Bridge' ) ) {

	class DBPH_Embed_Bridge {

		const OPTION_MANUAL       = 'dbph_embed_manual';
		const OPTION_SOCIAL_PAGES = 'dbph_social_pages_mention';
		const TRANSIENT_SCAN      = 'dbph_embed_scan';

		public static function init() {
			/**
			 * Permette di disattivare completamente il bridge embed/social.
			 *
			 * @since 1.6.0
			 * @param bool $enabled Default true.
			 */
			if ( ! apply_filters( 'dbph_embed_bridge_enabled', true ) ) {
				return;
			}

			add_filter( 'dbph_processing_register', array( __CLASS__, 'register_processings' ), 20 );
			add_filter( 'dbph_policy_destinatari',  array( __CLASS__, 'register_destinatari' ), 20 );
			add_filter( 'dbph_policy_sections',     array( __CLASS__, 'extend_sections' ), 25, 2 );

			// Invalida la cache di scansione quando un contenuto cambia.
			add_action( 'save_post',    array( __CLASS__, 'flush_scan_cache' ) );
			add_action( 'deleted_post', array( __CLASS__, 'flush_scan_cache' ) );
		}

		public static function flush_scan_cache() {
			delete_transient( self::TRANSIENT_SCAN );
		}

		/* =====================================================================
		 * Catalogo piattaforme
		 * ================================================================== */

		/**
		 * Catalogo delle piattaforme note.
		 *
		 * Per ciascuna: label UI, pattern di embed nel contenuto (LIKE, MAI
		 * hostname generici), slug dei blocchi Gutenberg embed, descrittore
		 * destinatario precompilato.
		 *
		 * @since 1.6.0
		 * @return array<string,array>
		 */
		public static function get_platforms() {
			$autonomo = __( 'Riceve indirizzo IP, dati di navigazione e identificatori (cookie o tecnologie simili) al caricamento dei contenuti incorporati nel sito, in qualità di autonomo titolare del trattamento, anche per proprie finalità di profilazione. Il caricamento è subordinato al consenso espresso tramite il banner cookie.', 'db-privacy-hub' );

			$platforms = array(
				'youtube' => array(
					'label'    => 'YouTube',
					'patterns' => array( 'youtube.com/embed', 'youtube-nocookie.com/embed' ),
					'blocks'   => array( 'youtube' ),
					'dest'     => array(
						'name'        => 'Google Ireland Ltd (YouTube)',
						'description' => $autonomo,
						'country'     => __( 'Irlanda (UE); possibili trasferimenti verso Google LLC, Stati Uniti (extra-UE) — garanzie: SCC + DPF.', 'db-privacy-hub' ),
					),
				),
				'vimeo' => array(
					'label'    => 'Vimeo',
					'patterns' => array( 'player.vimeo.com' ),
					'blocks'   => array( 'vimeo' ),
					'dest'     => array(
						'name'        => 'Vimeo.com, Inc.',
						'description' => $autonomo,
						'country'     => __( 'Stati Uniti (extra-UE) — garanzie: SCC.', 'db-privacy-hub' ),
					),
				),
				'facebook' => array(
					'label'    => 'Facebook',
					'patterns' => array( 'facebook.com/plugins', 'connect.facebook.net' ),
					'blocks'   => array( 'facebook' ),
					'dest'     => array(
						'name'        => 'Meta Platforms Ireland Ltd (Facebook)',
						'description' => $autonomo,
						'country'     => __( 'Irlanda (UE); possibili trasferimenti verso Meta Platforms Inc., Stati Uniti (extra-UE) — garanzie: SCC + DPF.', 'db-privacy-hub' ),
					),
				),
				'instagram' => array(
					'label'    => 'Instagram',
					'patterns' => array( 'instagram.com/embed', 'instagram.com/p/', 'instgrm.Embeds' ),
					'blocks'   => array( 'instagram' ),
					'dest'     => array(
						'name'        => 'Meta Platforms Ireland Ltd (Instagram)',
						'description' => $autonomo,
						'country'     => __( 'Irlanda (UE); possibili trasferimenti verso Meta Platforms Inc., Stati Uniti (extra-UE) — garanzie: SCC + DPF.', 'db-privacy-hub' ),
					),
				),
				'tiktok' => array(
					'label'    => 'TikTok',
					'patterns' => array( 'tiktok.com/embed' ),
					'blocks'   => array( 'tiktok' ),
					'dest'     => array(
						'name'        => 'TikTok Technology Ltd',
						'description' => $autonomo,
						'country'     => __( 'Irlanda (UE); possibili trasferimenti extra-UE (Stati Uniti e altri paesi) — garanzie: SCC.', 'db-privacy-hub' ),
					),
				),
				'x' => array(
					'label'    => 'X (Twitter)',
					'patterns' => array( 'platform.twitter.com', 'twitter.com/widgets' ),
					'blocks'   => array( 'twitter' ),
					'dest'     => array(
						'name'        => 'X Corp. / Twitter International ULC',
						'description' => $autonomo,
						'country'     => __( 'Irlanda (UE) / Stati Uniti (extra-UE) — garanzie: SCC.', 'db-privacy-hub' ),
					),
				),
				'linkedin' => array(
					'label'    => 'LinkedIn',
					'patterns' => array( 'linkedin.com/embed', 'platform.linkedin.com' ),
					'blocks'   => array(),
					'dest'     => array(
						'name'        => 'LinkedIn Ireland Unlimited Company',
						'description' => $autonomo,
						'country'     => __( 'Irlanda (UE); possibili trasferimenti verso LinkedIn Corp., Stati Uniti (extra-UE) — garanzie: SCC + DPF.', 'db-privacy-hub' ),
					),
				),
				'spotify' => array(
					'label'    => 'Spotify',
					'patterns' => array( 'open.spotify.com/embed' ),
					'blocks'   => array( 'spotify' ),
					'dest'     => array(
						'name'        => 'Spotify AB',
						'description' => $autonomo,
						'country'     => __( 'Svezia (UE).', 'db-privacy-hub' ),
					),
				),
				'google_maps' => array(
					'label'    => 'Google Maps',
					'patterns' => array( 'google.com/maps/embed', 'maps.google.com/maps' ),
					'blocks'   => array(),
					'dest'     => array(
						'name'        => 'Google Ireland Ltd (Google Maps)',
						'description' => $autonomo,
						'country'     => __( 'Irlanda (UE); possibili trasferimenti verso Google LLC, Stati Uniti (extra-UE) — garanzie: SCC + DPF.', 'db-privacy-hub' ),
					),
				),
			);

			/**
			 * Filtra il catalogo delle piattaforme embed riconosciute.
			 *
			 * @since 1.6.0
			 * @param array $platforms
			 */
			return (array) apply_filters( 'dbph_embed_platforms', $platforms );
		}

		/* =====================================================================
		 * Detection
		 * ================================================================== */

		/**
		 * Piattaforme attive = rilevate automaticamente ∪ abilitate a mano.
		 *
		 * @return array<int,string> chiavi piattaforma
		 */
		public static function get_active_platforms() {
			$detected = self::scan_content();
			$manual   = self::get_manual_platforms();
			return array_values( array_unique( array_merge( $detected, $manual ) ) );
		}

		/**
		 * Piattaforme abilitate a mano dall'admin (option).
		 *
		 * @return array<int,string>
		 */
		public static function get_manual_platforms() {
			$manual = get_option( self::OPTION_MANUAL, array() );
			if ( ! is_array( $manual ) ) {
				return array();
			}
			$valid = array_keys( self::get_platforms() );
			return array_values( array_intersect( array_map( 'sanitize_key', $manual ), $valid ) );
		}

		/**
		 * Scansiona i contenuti pubblicati alla ricerca di marker di embed.
		 * Risultato cachato in transient 12h.
		 *
		 * @return array<int,string> chiavi piattaforma rilevate
		 */
		public static function scan_content() {
			$cached = get_transient( self::TRANSIENT_SCAN );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			global $wpdb;

			$post_types = get_post_types( array( 'public' => true ) );
			unset( $post_types['attachment'] );
			if ( empty( $post_types ) ) {
				$post_types = array( 'post', 'page' );
			}
			$types_in = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

			$found = array();
			foreach ( self::get_platforms() as $key => $platform ) {
				$needles = $platform['patterns'];
				foreach ( (array) $platform['blocks'] as $block_slug ) {
					// Marker del blocco Gutenberg embed: molto affidabile.
					$needles[] = '"providerNameSlug":"' . $block_slug . '"';
				}

				foreach ( $needles as $needle ) {
					$like = '%' . $wpdb->esc_like( $needle ) . '%';
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $types_in è esc_sql'd
					$hit = $wpdb->get_var( $wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						 WHERE post_status = 'publish'
						   AND post_type IN ({$types_in})
						   AND post_content LIKE %s
						 LIMIT 1",
						$like
					) );
					if ( $hit ) {
						$found[] = $key;
						break;
					}
				}
			}

			set_transient( self::TRANSIENT_SCAN, $found, 12 * HOUR_IN_SECONDS );
			return $found;
		}

		/**
		 * Rileva plugin pixel/remarketing noti attivi.
		 *
		 * @return array<int,string> label dei pixel rilevati
		 */
		public static function detect_pixels() {
			$active = implode( '|', (array) get_option( 'active_plugins', array() ) );

			$map = array(
				'/pixelyoursite/i'              => 'PixelYourSite (Meta/Google/TikTok pixel)',
				'/facebook-for-woocommerce/i'   => 'Facebook for WooCommerce (Meta pixel)',
				'/official-facebook-pixel|meta-pixel/i' => 'Meta pixel',
				'/tiktok/i'                     => 'TikTok pixel',
			);

			$found = array();
			foreach ( $map as $regex => $label ) {
				if ( preg_match( $regex, $active ) ) {
					$found[] = $label;
				}
			}
			return $found;
		}

		/* =====================================================================
		 * 1-2. Trattamenti → registro
		 * ================================================================== */

		public static function register_processings( $register ) {
			if ( ! is_array( $register ) ) {
				$register = array();
			}

			$active    = self::get_active_platforms();
			$platforms = self::get_platforms();

			if ( ! empty( $active ) ) {
				$labels = array();
				foreach ( $active as $key ) {
					if ( isset( $platforms[ $key ] ) ) {
						$labels[] = $platforms[ $key ]['label'];
					}
				}

				$register[] = array(
					'id'             => 'dbemb_embeds',
					'label'          => __( 'Contenuti incorporati da piattaforme terze', 'db-privacy-hub' ),
					'status'         => 'active',
					'purpose'        => sprintf(
						/* translators: %s: elenco piattaforme */
						__( 'Arricchimento dei contenuti del sito tramite elementi incorporati da piattaforme esterne (%s). Al caricamento di tali elementi il browser dell\'utente contatta i server della piattaforma.', 'db-privacy-hub' ),
						implode( ', ', $labels )
					),
					'legal_basis'    => __( 'Consenso dell\'interessato (art. 6.1.a GDPR), raccolto tramite il banner cookie prima del caricamento dei contenuti.', 'db-privacy-hub' ),
					'data_collected' => __( 'Indirizzo IP, dati di navigazione (pagina visitata, user agent), identificatori tramite cookie o tecnologie simili impostati dalla piattaforma.', 'db-privacy-hub' ),
					'retention'      => __( 'I dati sono trattati direttamente dalle piattaforme secondo le rispettive privacy policy; il sito non li conserva.', 'db-privacy-hub' ),
					'transfers'      => __( 'Piattaforme elencate nella sezione Destinatari, in qualità di autonomi titolari; eventuali trasferimenti extra-UE sono indicati per ciascuna.', 'db-privacy-hub' ),
				);
			}

			$pixels = self::detect_pixels();
			if ( ! empty( $pixels ) ) {
				$register[] = array(
					'id'             => 'dbemb_pixel',
					'label'          => __( 'Remarketing e misurazione pubblicitaria (pixel)', 'db-privacy-hub' ),
					'status'         => 'active',
					'purpose'        => sprintf(
						/* translators: %s: elenco pixel rilevati */
						__( 'Misurazione delle campagne pubblicitarie e creazione di pubblici personalizzati tramite pixel di tracciamento (%s).', 'db-privacy-hub' ),
						implode( ', ', $pixels )
					),
					'legal_basis'    => __( 'Consenso dell\'interessato (art. 6.1.a GDPR), raccolto tramite il banner cookie prima dell\'attivazione del pixel.', 'db-privacy-hub' ),
					'data_collected' => __( 'Indirizzo IP, identificatori pubblicitari, eventi di navigazione e conversione (pagine visitate, acquisti).', 'db-privacy-hub' ),
					'retention'      => __( 'Secondo le policy della piattaforma pubblicitaria; il sito non conserva copia degli eventi.', 'db-privacy-hub' ),
					'transfers'      => __( 'Piattaforme pubblicitarie titolari del pixel, anche extra-UE con garanzie ex art. 46 GDPR.', 'db-privacy-hub' ),
				);
			}

			return $register;
		}

		/* =====================================================================
		 * 3. Piattaforme → destinatari
		 * ================================================================== */

		public static function register_destinatari( $destinatari ) {
			if ( ! is_array( $destinatari ) ) {
				$destinatari = array();
			}

			$platforms = self::get_platforms();
			foreach ( self::get_active_platforms() as $key ) {
				if ( isset( $platforms[ $key ]['dest'] ) ) {
					$destinatari[] = $platforms[ $key ]['dest'];
				}
			}

			return $destinatari;
		}

		/* =====================================================================
		 * 4. Contitolarità pagine social (CGUE C-210/16)
		 * ================================================================== */

		public static function extend_sections( $sections, $context ) {
			if ( ! is_array( $sections ) || empty( $sections['destinatari'] ) ) {
				return $sections;
			}
			if ( get_option( self::OPTION_SOCIAL_PAGES, '0' ) !== '1' ) {
				return $sections;
			}

			$note  = '<h4>' . esc_html__( 'Pagine social del titolare', 'db-privacy-hub' ) . '</h4>';
			$note .= '<p>' . esc_html__( 'Il titolare gestisce pagine e profili su piattaforme social. Per i dati statistici aggregati relativi ai visitatori di tali pagine (es. Page Insights), il titolare e la piattaforma operano in regime di contitolarità del trattamento (art. 26 GDPR; CGUE, causa C-210/16), secondo l\'accordo di contitolarità messo a disposizione dalla piattaforma stessa. Per i trattamenti effettuati sulle pagine social si rinvia alle informative delle rispettive piattaforme; l\'interessato può esercitare i propri diritti sia verso il titolare sia verso la piattaforma.', 'db-privacy-hub' ) . '</p>';

			$sections['destinatari'] .= $note;

			return $sections;
		}
	}
}
