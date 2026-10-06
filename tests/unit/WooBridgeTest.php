<?php
/**
 * Bridge WooCommerce (DBPH_Woo_Bridge): trattamenti e-commerce, gateway di
 * pagamento come destinatari, limite di cancellazione dei dati fiscali.
 *
 * WooCommerce è simulato da WC() con un gestore dei gateway finto.
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class DBPH_Test_Gateway {
	public $id;
	public $enabled;
	private $title;

	public function __construct( $id, $title, $enabled = 'yes' ) {
		$this->id      = $id;
		$this->title   = $title;
		$this->enabled = $enabled;
	}

	public function get_method_title() {
		return $this->title;
	}
}

class DBPH_Test_Gateway_Manager {
	public $gateways = array();

	public function payment_gateways() {
		return $this->gateways;
	}
}

class DBPH_Test_WooCommerce {
	public $manager;

	public function __construct() {
		$this->manager = new DBPH_Test_Gateway_Manager();
	}

	public function payment_gateways() {
		return $this->manager;
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- stesso nome di WooCommerce.
		return $GLOBALS['__dbph_wc'];
	}
}

class WooBridgeTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
		$GLOBALS['__dbph_wc'] = new DBPH_Test_WooCommerce();
	}

	private function gateways( array $gateways ) {
		$GLOBALS['__dbph_wc']->manager->gateways = $gateways;
	}

	private function ids( array $register ) {
		return array_column( $register, 'id' );
	}

	public function test_trattamenti_di_base(): void {
		$register = DBPH_Woo_Bridge::register_processings( array() );

		$this->assertSame( array( 'dbwoo_orders', 'dbwoo_billing' ), $this->ids( $register ) );
		foreach ( $register as $entry ) {
			$this->assertSame( 'self', $entry['_source'] );
			foreach ( array( 'label', 'status', 'purpose', 'legal_basis', 'data_collected', 'retention', 'transfers' ) as $field ) {
				$this->assertNotEmpty( $entry[ $field ], $entry['id'] . '.' . $field );
			}
		}
	}

	public function test_trattamenti_condizionali(): void {
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$this->gateways( array( new DBPH_Test_Gateway( 'stripe', 'Stripe' ) ) );

		$this->assertSame(
			array( 'dbwoo_orders', 'dbwoo_billing', 'dbwoo_account', 'dbwoo_payments', 'dbwoo_tracking' ),
			$this->ids( DBPH_Woo_Bridge::register_processings( array() ) )
		);
	}

	public function test_registro_non_array_in_ingresso(): void {
		$this->assertCount( 2, DBPH_Woo_Bridge::register_processings( null ) );
	}

	public function test_gateway_offline_e_disattivati_esclusi(): void {
		$this->gateways(
			array(
				new DBPH_Test_Gateway( 'cod', 'Contrassegno' ),
				new DBPH_Test_Gateway( 'bacs', 'Bonifico' ),
				new DBPH_Test_Gateway( 'cheque', 'Assegno' ),
				new DBPH_Test_Gateway( 'stripe', 'Stripe', 'no' ),
				'non un oggetto',
			)
		);

		$this->assertSame( array(), DBPH_Woo_Bridge::register_destinatari( array() ) );
		$this->assertNotContains( 'dbwoo_payments', $this->ids( DBPH_Woo_Bridge::register_processings( array() ) ) );
	}

	public function test_gateway_noti_e_sconosciuti(): void {
		$this->gateways(
			array(
				new DBPH_Test_Gateway( 'stripe_sepa', 'SEPA' ),
				new DBPH_Test_Gateway( 'ppcp-gateway', 'PayPal' ),
				new DBPH_Test_Gateway( 'satispay', 'Satispay' ),
				new DBPH_Test_Gateway( 'banca_locale', '<b>Banca Locale</b>' ),
			)
		);

		$dest = DBPH_Woo_Bridge::register_destinatari( array( array( 'name' => 'Già presente' ) ) );

		$this->assertSame(
			array( 'Già presente', 'Stripe, Inc.', 'PayPal (Europe) S.à r.l. et Cie, S.C.A.', 'Satispay Europe S.A.', 'Banca Locale' ),
			array_column( $dest, 'name' )
		);
		$this->assertStringContainsString( '"Banca Locale"', $dest[4]['description'] );
	}

	public function test_senza_woocommerce_nessun_gateway(): void {
		$GLOBALS['__dbph_wc'] = null;
		$this->assertSame( array(), DBPH_Woo_Bridge::register_destinatari( array() ) );
	}

	public function test_limite_di_cancellazione_nella_sezione_diritti(): void {
		$sections = DBPH_Woo_Bridge::extend_rights_section(
			array(
				'diritti' => '<h3>Diritti</h3>',
				'altro'   => 'x',
			),
			array()
		);

		$this->assertStringStartsWith( '<h3>Diritti</h3><h4>Limiti al diritto di cancellazione', $sections['diritti'] );
		$this->assertStringContainsString( 'art. 17.3.b GDPR', $sections['diritti'] );
		$this->assertSame( 'x', $sections['altro'] );
	}

	public function test_sezione_diritti_assente_o_input_non_array(): void {
		$this->assertSame( array( 'altro' => 'x' ), DBPH_Woo_Bridge::extend_rights_section( array( 'altro' => 'x' ), array() ) );
		$this->assertNull( DBPH_Woo_Bridge::extend_rights_section( null, array() ) );
	}
}
