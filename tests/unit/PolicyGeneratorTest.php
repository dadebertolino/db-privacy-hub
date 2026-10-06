<?php
/**
 * Generatore della Privacy Policy (DBPH_Policy_Generator::generate()):
 * sezioni, numerazione, titolare, destinatari, paragrafo DSAR e robustezza
 * contro i filtri dbph_policy_sections / dbph_policy_html malformati (bug 2).
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/*
 * Finto DB Cookie Manager: con $sections vuoto si comporta come se il
 * Cookie Manager non producesse sezioni (has_cookie torna false), quindi
 * definirlo non cambia il risultato degli altri test.
 */
if ( ! class_exists( 'DBCM_Policy_Generator' ) ) {
	class DBCM_Policy_Generator {
		public static $sections = array();

		public static function get_sections() {
			return self::$sections;
		}
	}
}

class PolicyGeneratorTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
		DBCM_Policy_Generator::$sections = array();
		update_option( 'admin_email', 'admin@hub.example' );
		update_option( 'date_format', 'd/m/Y' );
	}

	private function set_titolare( array $fields = array() ) {
		$fields = array_merge(
			array(
				'nome'  => 'ACME Srl',
				'email' => 'privacy@acme.example',
			),
			$fields
		);
		foreach ( $fields as $key => $value ) {
			update_option( 'dbph_titolare_' . $key, $value );
		}
	}

	private function declare_destinatari( array $list ) {
		add_filter(
			'dbph_policy_destinatari',
			function ( $dest ) use ( $list ) {
				return array_merge( $dest, $list );
			}
		);
	}

	/**
	 * Titoli h3 numerati della policy, nell'ordine.
	 */
	private function headings( $html ) {
		preg_match_all( '/<h3 id="dbph-[a-z]+">(.*?)<\/h3>/', $html, $m );
		return $m[1];
	}

	/* --- Struttura --------------------------------------------------------- */

	public function test_sezioni_e_numerazione_senza_cookie(): void {
		$this->set_titolare();

		$this->assertSame(
			array(
				'1. Titolare del trattamento',
				'2. Finalità del trattamento e basi giuridiche',
				'3. Trattamenti specifici',
				'4. Destinatari dei dati',
				'5. Diritti dell&#039;interessato',
				'6. Conservazione dei dati',
				'7. Modifiche all&#039;informativa',
				'8. Reclamo all&#039;autorità di controllo',
			),
			$this->headings( DBPH_Policy_Generator::generate() )
		);
	}

	public function test_sezione_cookie_importata_e_numerazione_spostata(): void {
		DBCM_Policy_Generator::$sections = array(
			'header'       => '<h2>Header CM</h2>',
			'cookies_used' => '<h3>Cookie usati</h3><h4>Tecnici</h4>',
		);

		$html = DBPH_Policy_Generator::generate();

		$this->assertContains( '4. Cookie e tecnologie simili', $this->headings( $html ) );
		$this->assertContains( '5. Destinatari dei dati', $this->headings( $html ) );
		$this->assertContains( '9. Reclamo all&#039;autorità di controllo', $this->headings( $html ) );
		// Sottotitoli retrocessi di un livello; header del CM non importato.
		$this->assertStringContainsString( '<h4>Cookie usati</h4><h5>Tecnici</h5>', $html );
		$this->assertStringNotContainsString( 'Header CM', $html );
		$this->assertStringContainsString( 'href="#dbph-cookie"', $html );
	}

	public function test_cookie_manager_senza_sezioni_utili_non_compare_in_indice(): void {
		DBCM_Policy_Generator::$sections = array( 'footer' => '<p>solo footer</p>' );

		$html = DBPH_Policy_Generator::generate();

		$this->assertStringNotContainsString( 'href="#dbph-cookie"', $html );
		$this->assertContains( '4. Destinatari dei dati', $this->headings( $html ) );
	}

	public function test_titolare_non_configurato_mostra_avviso(): void {
		$html = DBPH_Policy_Generator::generate();

		$this->assertFalse( DBPH_Policy_Generator::is_titolare_configured() );
		$this->assertStringContainsString( 'i dati del titolare non sono ancora stati configurati', $html );
	}

	public function test_dati_del_titolare(): void {
		$this->set_titolare(
			array(
				'nome'      => 'ACME <Srl>',
				'piva'      => '01234567890',
				'indirizzo' => 'Via Roma 1',
				'pec'       => 'acme@pec.example',
				'dpo'       => 'Dott. Bianchi',
			)
		);

		$html = DBPH_Policy_Generator::generate();

		$this->assertStringContainsString( '<strong>ACME &lt;Srl&gt;</strong>', $html );
		$this->assertStringContainsString( 'P.IVA / C.F.: 01234567890', $html );
		$this->assertStringContainsString( 'PEC: acme@pec.example', $html );
		$this->assertStringContainsString( 'Dott. Bianchi', $html );
		$this->assertStringContainsString( 'mailto:privacy@acme.example', $html );
	}

	public function test_senza_email_del_titolare_si_usa_quella_admin(): void {
		$this->set_titolare( array( 'email' => '' ) );
		$this->assertStringContainsString( 'mailto:admin@hub.example', DBPH_Policy_Generator::generate() );
	}

	/* --- Trattamenti ------------------------------------------------------- */

	public function test_trattamenti_attivi_e_scarto_degli_inattivi(): void {
		add_filter(
			'dbph_processing_register',
			function ( $r ) {
				$r[] = array(
					'id'          => 'dbfb_contatti',
					'label'       => 'Modulo contatti',
					'status'      => 'active',
					'purpose'     => 'Rispondere',
					'legal_basis' => 'Contratto',
				);
				$r[] = array(
					'id'     => 'dbfb_vecchio',
					'label'  => 'Trattamento spento',
					'status' => 'inactive',
				);
				$r[] = array( 'id' => 'senza_label' );
				return $r;
			}
		);

		$html = DBPH_Policy_Generator::generate();

		$this->assertStringContainsString( '<h4>Modulo contatti</h4>', $html );
		$this->assertStringContainsString( '<strong>Finalità:</strong> Rispondere', $html );
		$this->assertStringNotContainsString( 'Trattamento spento', $html );
	}

	public function test_registro_vuoto(): void {
		$this->assertStringContainsString( 'Nessun trattamento dichiarato', DBPH_Policy_Generator::generate() );
	}

	/* --- Destinatari ------------------------------------------------------- */

	public function test_destinatari_dedup_per_nome_e_voci_malformate(): void {
		$this->declare_destinatari(
			array(
				array(
					'name'        => 'Stripe, Inc.',
					'description' => 'PSP',
					'country'     => 'USA',
				),
				array(
					'name'        => ' stripe, inc. ',
					'description' => 'Duplicato',
				),
				array( 'description' => 'Senza nome' ),
				'non array',
			)
		);

		$html = DBPH_Policy_Generator::generate();

		$this->assertSame( 1, substr_count( $html, 'Stripe, Inc.' ) );
		$this->assertStringContainsString( '(Paese: USA)', $html );
		$this->assertStringNotContainsString( 'Duplicato', $html );
		$this->assertStringNotContainsString( 'Senza nome', $html );
	}

	public function test_il_responsabile_dichiarato_prevale_sul_rilevato(): void {
		DBPH_Responsabili::save_all(
			array(
				array(
					'nome'     => 'Mailer Srl',
					'ruolo'    => 'Email transazionale',
					'paese'    => 'USA',
					'extra_ue' => true,
					'dpa_url'  => 'https://mailer.example/dpa',
				),
			)
		);
		$this->declare_destinatari(
			array(
				array(
					'name'        => 'MAILER SRL',
					'description' => 'Rilevato in automatico',
				),
			)
		);

		$html = DBPH_Policy_Generator::generate();

		$this->assertStringContainsString( 'Responsabili del trattamento (art. 28 GDPR)', $html );
		$this->assertStringContainsString( 'paese: USA (extra-UE)', $html );
		$this->assertStringContainsString( 'href="https://mailer.example/dpa"', $html );
		$this->assertStringNotContainsString( 'Rilevato in automatico', $html );
		// Con responsabili dichiarati, niente voce generica sull'hosting.
		$this->assertStringNotContainsString( 'Fornitore di hosting del sito web', $html );
	}

	public function test_senza_responsabili_compare_la_voce_hosting(): void {
		$this->assertStringContainsString( 'Fornitore di hosting del sito web', DBPH_Policy_Generator::generate() );
	}

	/* --- Diritti ----------------------------------------------------------- */

	public function test_paragrafo_dsar_solo_con_un_exporter_dichiarato(): void {
		$this->assertStringNotContainsString( 'Procedura di esercizio dei diritti', DBPH_Policy_Generator::generate() );

		add_filter(
			'dbph_user_data_exporters',
			function ( $e ) {
				$e['x'] = array(
					'label'    => 'X',
					'callback' => '__return_true',
				);
				return $e;
			}
		);

		$this->assertStringContainsString( 'Procedura di esercizio dei diritti', DBPH_Policy_Generator::generate() );
	}

	public function test_paragrafo_dsar_dal_filtro_dbph_dsar_available(): void {
		add_filter( 'dbph_dsar_available', '__return_true' );
		$this->assertStringContainsString( 'Procedura di esercizio dei diritti', DBPH_Policy_Generator::generate() );
	}

	public function test_istruzioni_operative_disattivabili(): void {
		$this->assertStringContainsString( 'Come esercitare concretamente i tuoi diritti', DBPH_Policy_Generator::generate() );

		update_option( 'dbph_show_rights_howto', '0' );
		$this->assertStringNotContainsString( 'Come esercitare concretamente i tuoi diritti', DBPH_Policy_Generator::generate() );
	}

	/* --- Bug 2: filtri malformati ------------------------------------------ */

	/**
	 * @dataProvider provide_valori_non_array
	 */
	public function test_filtro_sezioni_non_array_non_blocca_la_generazione( $value ): void {
		$this->set_titolare();
		add_filter(
			'dbph_policy_sections',
			function () use ( $value ) {
				return $value;
			}
		);

		$html = DBPH_Policy_Generator::generate();

		$this->assertStringContainsString( '1. Titolare del trattamento', $html );
		$this->assertStringContainsString( 'dbph_policy_sections', $GLOBALS['__dbph_doing_it_wrong'][0][1] );
	}

	public function provide_valori_non_array() {
		return array(
			'null'    => array( null ),
			'stringa' => array( '<p>solo questo</p>' ),
			'false'   => array( false ),
		);
	}

	public function test_sezioni_con_valori_non_stringa_vengono_saltate(): void {
		add_filter(
			'dbph_policy_sections',
			function ( $sections ) {
				$sections['rotta']  = array( 'html' => '<p>x</p>' );
				$sections['oggetto'] = new stdClass();
				$sections['extra']  = '<p>Sezione extra</p>';
				return $sections;
			}
		);

		$html = DBPH_Policy_Generator::generate();

		$this->assertStringContainsString( '<p>Sezione extra</p>', $html );
		$this->assertStringNotContainsString( 'Array', $html );
	}

	public function test_filtro_html_non_stringa_restituisce_la_policy_dell_hub(): void {
		add_filter(
			'dbph_policy_html',
			function () {
				return array( 'x' );
			}
		);

		$html = DBPH_Policy_Generator::generate();

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'Informativa sul trattamento dei dati personali', $html );
	}

	public function test_filtro_html_valido_viene_applicato(): void {
		add_filter(
			'dbph_policy_html',
			function ( $html, $context ) {
				return '<div data-site="' . $context['site_name'] . '">' . $html . '</div>';
			},
			10,
			2
		);

		$this->assertStringStartsWith( '<div data-site="Sito Di Test">', DBPH_Policy_Generator::generate() );
	}
}
