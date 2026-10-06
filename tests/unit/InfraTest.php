<?php
/**
 * Smoke test dell'infrastruttura unit: se questi falliscono, i risultati
 * degli altri test non sono attendibili (stub WordPress o caricamento classi
 * rotti).
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class InfraTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
	}

	public function test_le_classi_del_plugin_sono_caricate(): void {
		foreach ( array(
			'DBPH_Register',
			'DBPH_Responsabili',
			'DBPH_Policy_Archive',
			'DBPH_Policy_Generator',
			'DBPH_Deprecated_Aliases',
			'DBPH_DSAR',
			'DBPH_DSAR_Log',
			'DBPH_Consents_Register',
			'DBPH_Woo_Bridge',
			'DBPH_Embed_Bridge',
			'DBPH_Admin',
		) as $class ) {
			$this->assertTrue( class_exists( $class ), $class );
		}
	}

	public function test_i_filtri_rispettano_la_priorita(): void {
		add_filter( 'dbph_test', function ( $v ) {
			return $v . 'c';
		}, 999 );
		add_filter( 'dbph_test', function ( $v ) {
			return $v . 'a';
		}, 5 );
		add_filter( 'dbph_test', function ( $v ) {
			return $v . 'b';
		} );

		$this->assertSame( 'abc', apply_filters( 'dbph_test', '' ) );
	}

	public function test_i_filtri_passano_solo_gli_argomenti_dichiarati(): void {
		add_filter( 'dbph_test', function ( $v, $ctx ) {
			return $v . $ctx;
		}, 10, 2 );
		add_filter( 'dbph_test', function ( $v ) {
			return $v . '!';
		} );

		$this->assertSame( 'x-ctx!', apply_filters( 'dbph_test', 'x-', 'ctx', 'ignorato' ) );
	}

	public function test_il_reset_svuota_option_filtri_e_cache(): void {
		update_option( 'dbph_titolare_nome', 'ACME' );
		add_filter( 'dbph_processing_register', function ( $r ) {
			$r[] = array( 'id' => 'x' );
			return $r;
		} );
		DBPH_Register::collect();

		dbph_test_reset();

		$this->assertFalse( get_option( 'dbph_titolare_nome' ) );
		$this->assertFalse( has_filter( 'dbph_processing_register' ) );
		$this->assertSame( array(), DBPH_Register::collect() );
	}

	public function test_i_metodi_privati_sono_raggiungibili(): void {
		$hash = dbph_test_call_private( 'DBPH_DSAR_Log', 'hash_email', array( 'mario@example.com' ) );

		$this->assertIsString( $hash );
		$this->assertSame( 64, strlen( $hash ) );
	}
}
