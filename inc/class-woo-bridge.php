<?php
/**
 * DBPH_Woo_Bridge — Ponte privacy per WooCommerce.
 *
 * WooCommerce non conosce (e mai conoscerà) i filter dell'Hub: questo modulo
 * "parla" al suo posto, traducendo lo stato reale del negozio nelle
 * dichiarazioni che l'Hub si aspetta:
 *
 *  1. Trattamenti e-commerce sul registro (`dbph_processing_register`):
 *     ordini/spedizione, fatturazione, account cliente (se registrazione
 *     abilitata), pagamenti online, telemetria Automattic (se attiva).
 *  2. Gateway di pagamento abilitati come destinatari
 *     (`dbph_policy_destinatari`), con qualifica di autonomo titolare e
 *     indicazione di eventuale trasferimento extra-UE.
 *  3. Integrazione della sezione "Diritti dell'interessato"
 *     (`dbph_policy_sections`) con il limite alla cancellazione dei dati
 *     fiscali/contabili (art. 17.3.b GDPR).
 *
 * Il modulo è un puro consumatore dei contratti pubblici dell'Hub: non
 * modifica nulla del core. Viene caricato solo se WooCommerce è attivo ed è
 * disattivabile via filter:
 *
 *   add_filter( 'dbph_woo_bridge_enabled', '__return_false' );
 *
 * FUORI SCOPE (dichiarato): corrieri (non ricavabili in modo affidabile dai
 * shipping method — vanno dichiarati a mano nei Responsabili esterni) e
 * consensi marketing (dipendono dalle estensioni, rinviati a una fase 2).
 * Il lato DSAR non richiede ponte: gli exporter/eraser di WooCommerce si
 * registrano sugli hook core di WordPress che l'Hub già ascolta.
 *
 * @package DB_Privacy_Hub
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBPH_Woo_Bridge' ) ) {

	class DBPH_Woo_Bridge {

		public static function init() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}

			/**
			 * Permette di disattivare completamente il ponte WooCommerce,
			 * per chi preferisce dichiarare i trattamenti e-commerce a mano.
			 *
			 * @since 1.4.0
			 * @param bool $enabled Default true.
			 */
			if ( ! apply_filters( 'dbph_woo_bridge_enabled', true ) ) {
				return;
			}

			// Priority 20: dopo le dichiarazioni dei plugin DB (default 10),
			// prima del merge legacy (999).
			add_filter( 'dbph_processing_register', array( __CLASS__, 'register_processings' ), 20 );
			add_filter( 'dbph_policy_destinatari', array( __CLASS__, 'register_destinatari' ), 20 );
			add_filter( 'dbph_policy_sections', array( __CLASS__, 'extend_rights_section' ), 20, 2 );
		}

		/* =====================================================================
		 * 1. Trattamenti e-commerce → registro
		 * ================================================================== */

		/**
		 * Dichiara sul registro dell'Hub i trattamenti standard di un negozio
		 * WooCommerce. Le voci condizionali (account, pagamenti, telemetria)
		 * vengono aggiunte solo se la relativa funzione è realmente attiva.
		 *
		 * @param array $register
		 * @return array
		 */
		public static function register_processings( $register ) {
			if ( ! is_array( $register ) ) {
				$register = array();
			}

			$register[] = array(
				'id'             => 'dbwoo_orders',
				'label'          => __( 'Gestione ordini e spedizione (WooCommerce)', 'db-privacy-hub' ),
				'status'         => 'active',
				'purpose'        => __( 'Ricezione ed evasione degli ordini di acquisto, gestione della spedizione, comunicazioni relative allo stato dell\'ordine, assistenza post-vendita e gestione di resi e reclami.', 'db-privacy-hub' ),
				'legal_basis'    => __( 'Esecuzione di un contratto di cui l\'interessato è parte (art. 6.1.b GDPR).', 'db-privacy-hub' ),
				'data_collected' => __( 'Nome e cognome, indirizzo di fatturazione e di spedizione, email, telefono, contenuto dell\'ordine, indirizzo IP al momento dell\'acquisto.', 'db-privacy-hub' ),
				'retention'      => __( 'Per la durata del rapporto contrattuale e successivamente per il termine di prescrizione ordinaria (10 anni, art. 2946 c.c.).', 'db-privacy-hub' ),
				'transfers'      => __( 'Corriere incaricato della consegna (limitatamente ai dati di spedizione). Nessun trasferimento extra-UE al di fuori di quanto indicato nella sezione Destinatari.', 'db-privacy-hub' ),
			);

			$register[] = array(
				'id'             => 'dbwoo_billing',
				'label'          => __( 'Fatturazione e obblighi fiscali (WooCommerce)', 'db-privacy-hub' ),
				'status'         => 'active',
				'purpose'        => __( 'Emissione dei documenti fiscali relativi agli acquisti e adempimento degli obblighi contabili e tributari.', 'db-privacy-hub' ),
				'legal_basis'    => __( 'Obbligo di legge (art. 6.1.c GDPR; DPR 633/1972; art. 2220 c.c.).', 'db-privacy-hub' ),
				'data_collected' => __( 'Dati di fatturazione: nome/ragione sociale, indirizzo, codice fiscale / partita IVA, dettagli dell\'acquisto.', 'db-privacy-hub' ),
				'retention'      => __( '10 anni dalla data di emissione del documento (art. 2220 c.c.). NOTA: essendo la conservazione un obbligo di legge, questi dati sono esclusi dal diritto di cancellazione (art. 17.3.b GDPR); alla richiesta di cancellazione l\'ordine viene anonimizzato, non eliminato.', 'db-privacy-hub' ),
				'transfers'      => __( 'Consulente fiscale / commercialista del titolare, ove nominato. Nessun trasferimento extra-UE.', 'db-privacy-hub' ),
			);

			if ( self::is_registration_enabled() ) {
				$register[] = array(
					'id'             => 'dbwoo_account',
					'label'          => __( 'Account cliente (WooCommerce)', 'db-privacy-hub' ),
					'status'         => 'active',
					'purpose'        => __( 'Creazione e gestione dell\'area riservata del cliente: storico ordini, indirizzi salvati, gestione dei dati di contatto.', 'db-privacy-hub' ),
					'legal_basis'    => __( 'Esecuzione di un contratto e di misure precontrattuali adottate su richiesta dell\'interessato (art. 6.1.b GDPR).', 'db-privacy-hub' ),
					'data_collected' => __( 'Email, nome utente, password (in forma cifrata), indirizzi salvati, storico degli ordini.', 'db-privacy-hub' ),
					'retention'      => __( 'Fino alla cancellazione dell\'account da parte dell\'utente o su sua richiesta, fatti salvi gli obblighi di conservazione dei dati fiscali collegati agli ordini.', 'db-privacy-hub' ),
					'transfers'      => __( 'Nessuno.', 'db-privacy-hub' ),
				);
			}

			$gateways = self::get_enabled_online_gateways();
			if ( ! empty( $gateways ) ) {
				$register[] = array(
					'id'             => 'dbwoo_payments',
					'label'          => __( 'Pagamenti online (WooCommerce)', 'db-privacy-hub' ),
					'status'         => 'active',
					'purpose'        => __( 'Incasso del corrispettivo degli ordini tramite fornitori di servizi di pagamento e prevenzione delle frodi.', 'db-privacy-hub' ),
					'legal_basis'    => __( 'Esecuzione di un contratto (art. 6.1.b GDPR); per il fornitore del servizio di pagamento si aggiungono obblighi di legge propri (PSD2, normativa antiriciclaggio).', 'db-privacy-hub' ),
					'data_collected' => __( 'Importo ed esito della transazione, identificativo/token della transazione, metodo di pagamento scelto. I dati completi dello strumento di pagamento (es. numero di carta) vengono raccolti direttamente dal fornitore del servizio e NON transitano né vengono conservati dal sito.', 'db-privacy-hub' ),
					'retention'      => __( 'I riferimenti della transazione sono conservati insieme all\'ordine per gli stessi termini (10 anni). La conservazione dei dati di pagamento completi è disciplinata dalle policy del fornitore del servizio.', 'db-privacy-hub' ),
					'transfers'      => __( 'Fornitori di servizi di pagamento elencati nella sezione Destinatari, in qualità di autonomi titolari; eventuali trasferimenti extra-UE sono indicati per ciascun fornitore.', 'db-privacy-hub' ),
				);
			}

			if ( get_option( 'woocommerce_allow_tracking' ) === 'yes' ) {
				$register[] = array(
					'id'             => 'dbwoo_tracking',
					'label'          => __( 'Telemetria WooCommerce (Automattic)', 'db-privacy-hub' ),
					'status'         => 'active',
					'purpose'        => __( 'Invio periodico di dati di utilizzo non essenziali ad Automattic Inc. per il miglioramento di WooCommerce (opzione "Consenti tracciamento" attiva nelle impostazioni WooCommerce).', 'db-privacy-hub' ),
					'legal_basis'    => __( 'Legittimo interesse del fornitore del software (art. 6.1.f GDPR); l\'opzione è disattivabile in qualsiasi momento da WooCommerce → Impostazioni → Avanzate.', 'db-privacy-hub' ),
					'data_collected' => __( 'Dati tecnici e di configurazione del negozio (versioni software, impostazioni, dati aggregati di utilizzo). Non include i dati personali dei clienti.', 'db-privacy-hub' ),
					'retention'      => __( 'Secondo le policy di Automattic Inc.', 'db-privacy-hub' ),
					'transfers'      => __( 'Automattic Inc., Stati Uniti (extra-UE), sulla base di Standard Contractual Clauses.', 'db-privacy-hub' ),
				);
			}

			return $register;
		}

		/* =====================================================================
		 * 2. Gateway abilitati → destinatari
		 * ================================================================== */

		/**
		 * Aggiunge ai destinatari della policy i gateway di pagamento online
		 * abilitati. I gateway noti ricevono un descrittore precompilato
		 * (titolarità, paese, garanzie); quelli non riconosciuti un
		 * descrittore generico, così nulla resta non dichiarato.
		 *
		 * @param array $destinatari
		 * @return array
		 */
		public static function register_destinatari( $destinatari ) {
			if ( ! is_array( $destinatari ) ) {
				$destinatari = array();
			}

			$known = self::known_gateway_map();

			foreach ( self::get_enabled_online_gateways() as $gw_id => $gw_title ) {
				$matched = null;
				foreach ( $known as $needle => $descr ) {
					if ( strpos( $gw_id, $needle ) !== false ) {
						$matched = $descr;
						break;
					}
				}

				if ( $matched ) {
					$destinatari[] = $matched;
				} else {
					$destinatari[] = array(
						'name'        => $gw_title,
						'description' => sprintf(
							/* translators: %s: nome del gateway */
							__( 'Fornitore di servizi di pagamento (gateway "%s" abilitato in WooCommerce): riceve i dati necessari all\'esecuzione della transazione in qualità di autonomo titolare del trattamento, anche per i propri obblighi di legge (PSD2, antiriciclaggio).', 'db-privacy-hub' ),
							$gw_title
						),
						'country'     => __( 'Da verificare in base alla giurisdizione del fornitore.', 'db-privacy-hub' ),
					);
				}
			}

			return $destinatari;
		}

		/**
		 * Mappa dei gateway noti: chiave = frammento dell'ID gateway,
		 * valore = descrittore destinatario precompilato.
		 *
		 * @return array<string,array{name:string,description:string,country:string}>
		 */
		private static function known_gateway_map() {
			$psp = __( 'Fornitore di servizi di pagamento: riceve i dati della transazione (importo, dati dello strumento di pagamento, dati identificativi dell\'acquirente) in qualità di autonomo titolare del trattamento, anche per i propri obblighi di legge (PSD2, antiriciclaggio) e per la prevenzione delle frodi.', 'db-privacy-hub' );

			return array(
				'stripe'    => array(
					'name'        => 'Stripe, Inc.',
					'description' => $psp,
					'country'     => __( 'Stati Uniti (extra-UE) — garanzie: SCC + DPF; entità europea Stripe Payments Europe Ltd (Irlanda).', 'db-privacy-hub' ),
				),
				'paypal'    => array(
					'name'        => 'PayPal (Europe) S.à r.l. et Cie, S.C.A.',
					'description' => $psp,
					'country'     => __( 'Lussemburgo (UE); possibili trasferimenti infragruppo extra-UE con garanzie ex art. 46 GDPR.', 'db-privacy-hub' ),
				),
				'ppcp'      => array(
					'name'        => 'PayPal (Europe) S.à r.l. et Cie, S.C.A.',
					'description' => $psp,
					'country'     => __( 'Lussemburgo (UE); possibili trasferimenti infragruppo extra-UE con garanzie ex art. 46 GDPR.', 'db-privacy-hub' ),
				),
				'klarna'    => array(
					'name'        => 'Klarna Bank AB',
					'description' => $psp,
					'country'     => __( 'Svezia (UE).', 'db-privacy-hub' ),
				),
				'kco'       => array(
					'name'        => 'Klarna Bank AB',
					'description' => $psp,
					'country'     => __( 'Svezia (UE).', 'db-privacy-hub' ),
				),
				'xpay'      => array(
					'name'        => 'Nexi Payments S.p.A.',
					'description' => $psp,
					'country'     => __( 'Italia (UE).', 'db-privacy-hub' ),
				),
				'nexi'      => array(
					'name'        => 'Nexi Payments S.p.A.',
					'description' => $psp,
					'country'     => __( 'Italia (UE).', 'db-privacy-hub' ),
				),
				'braintree' => array(
					'name'        => 'Braintree (PayPal, Inc.)',
					'description' => $psp,
					'country'     => __( 'Stati Uniti (extra-UE) — garanzie: SCC.', 'db-privacy-hub' ),
				),
				'mollie'    => array(
					'name'        => 'Mollie B.V.',
					'description' => $psp,
					'country'     => __( 'Paesi Bassi (UE).', 'db-privacy-hub' ),
				),
				'amazon_payments' => array(
					'name'        => 'Amazon Pay (Amazon Payments Europe s.c.a.)',
					'description' => $psp,
					'country'     => __( 'Lussemburgo (UE); possibili trasferimenti infragruppo extra-UE con garanzie ex art. 46 GDPR.', 'db-privacy-hub' ),
				),
				'satispay'  => array(
					'name'        => 'Satispay Europe S.A.',
					'description' => $psp,
					'country'     => __( 'Lussemburgo (UE).', 'db-privacy-hub' ),
				),
			);
		}

		/* =====================================================================
		 * 3. Sezione diritti → limite cancellazione dati fiscali
		 * ================================================================== */

		/**
		 * Appende alla sezione "Diritti dell'interessato" il chiarimento sul
		 * limite alla cancellazione dei dati fiscali/contabili — la prima
		 * obiezione pratica in un e-commerce.
		 *
		 * @param array $sections
		 * @param array $context
		 * @return array
		 */
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- la firma a 2 argomenti è il contratto del filter dbph_policy_sections.
		public static function extend_rights_section( $sections, $context ) {
			if ( ! is_array( $sections ) || empty( $sections['diritti'] ) ) {
				return $sections;
			}

			$note  = '<h4>' . esc_html__( 'Limiti al diritto di cancellazione per i dati di acquisto', 'db-privacy-hub' ) . '</h4>';
			$note .= '<p>' . esc_html__( 'I dati necessari all\'adempimento di obblighi fiscali e contabili (dati di fatturazione e dettagli degli ordini) non possono essere cancellati su richiesta dell\'interessato finché sussiste l\'obbligo legale di conservazione (art. 17.3.b GDPR; art. 2220 c.c. — 10 anni). In caso di richiesta di cancellazione, i dati dell\'ordine vengono resi anonimi: l\'ordine resta agli atti per le finalità di legge ma non è più riconducibile all\'interessato.', 'db-privacy-hub' ) . '</p>';

			$sections['diritti'] .= $note;

			return $sections;
		}

		/* =====================================================================
		 * Helpers
		 * ================================================================== */

		/**
		 * Verifica se la registrazione account cliente è abilitata
		 * (da pagina Il mio account o dal checkout).
		 *
		 * @return bool
		 */
		private static function is_registration_enabled() {
			return get_option( 'woocommerce_enable_myaccount_registration' ) === 'yes'
				|| get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) === 'yes';
		}

		/**
		 * Restituisce i gateway di pagamento ONLINE abilitati, esclusi i
		 * metodi offline (contrassegno, bonifico, assegno) che non comportano
		 * comunicazione di dati a un fornitore di servizi di pagamento.
		 *
		 * Usa payment_gateways() (non get_available_payment_gateways(), che
		 * dipende dal contesto carrello) e filtra su enabled === 'yes':
		 * funziona in admin, dove il registro viene raccolto on-demand.
		 *
		 * @return array<string,string> map gateway_id => title
		 */
		private static function get_enabled_online_gateways() {
			if ( ! function_exists( 'WC' ) || ! is_callable( array( WC(), 'payment_gateways' ) ) ) {
				return array();
			}

			$manager = WC()->payment_gateways();
			if ( ! $manager || ! is_callable( array( $manager, 'payment_gateways' ) ) ) {
				return array();
			}

			$offline = array( 'cod', 'bacs', 'cheque' );
			$out     = array();

			foreach ( (array) $manager->payment_gateways() as $gateway ) {
				if ( ! is_object( $gateway ) || empty( $gateway->id ) ) {
					continue;
				}
				if ( isset( $gateway->enabled ) && $gateway->enabled !== 'yes' ) {
					continue;
				}
				if ( in_array( $gateway->id, $offline, true ) ) {
					continue;
				}
				$title = method_exists( $gateway, 'get_method_title' ) ? $gateway->get_method_title() : ( $gateway->title ?? $gateway->id );
				$out[ $gateway->id ] = wp_strip_all_tags( (string) $title );
			}

			return $out;
		}
	}
}
