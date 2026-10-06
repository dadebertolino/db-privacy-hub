<?php
/**
 * DB_GitHub_Updater (bug 15): lettura della release da GitHub, scelta dello
 * ZIP, notifica di aggiornamento e riattivazione dopo l'installazione solo
 * se il plugin era attivo.
 *
 * Le chiamate HTTP e le funzioni dei plugin sono simulate.
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/var/www/wp-content/plugins' );
}

$GLOBALS['__dbph_http']      = null;  // Risposta di wp_remote_get, o WP_Error.
$GLOBALS['__dbph_http_hits'] = 0;
$GLOBALS['__dbph_activated'] = array();
$GLOBALS['__dbph_active']    = array( 'site' => false, 'network' => false );
$GLOBALS['__dbph_multisite'] = false;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public function __construct( $code = '', $message = '' ) {
			$this->code = $code;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		++$GLOBALS['__dbph_http_hits'];
		return $GLOBALS['__dbph_http'];
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? $response['code'] : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? $response['body'] : '';
	}
}
if ( ! function_exists( 'get_plugin_data' ) ) {
	function get_plugin_data( $file ) {
		return array(
			'Name'    => 'DB Privacy Hub',
			'Version' => '1.7.0',
		);
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return $GLOBALS['__dbph_multisite'];
	}
}
if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	function is_plugin_active_for_network( $plugin ) {
		return $GLOBALS['__dbph_active']['network'];
	}
}
if ( ! function_exists( 'activate_plugin' ) ) {
	function activate_plugin( $plugin, $redirect = '', $network_wide = false ) {
		$GLOBALS['__dbph_activated'][] = array( $plugin, $network_wide );
		return null;
	}
}

class DBPH_Test_Filesystem {
	public $moves = array();
	public $ok    = true;

	public function move( $from, $to ) {
		$this->moves[] = array( $from, $to );
		return $this->ok;
	}
}

class UpdaterTest extends TestCase {

	const FILE     = '/var/www/wp-content/plugins/db-privacy-hub/db-privacy-hub.php';
	const BASENAME = 'db-privacy-hub/db-privacy-hub.php';

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
		$GLOBALS['__dbph_http']      = null;
		$GLOBALS['__dbph_http_hits'] = 0;
		$GLOBALS['__dbph_activated'] = array();
		$GLOBALS['__dbph_active']    = array( 'site' => false, 'network' => false );
		$GLOBALS['__dbph_multisite'] = false;
		$GLOBALS['wp_filesystem']    = new DBPH_Test_Filesystem();
	}

	private function updater() {
		return new DB_GitHub_Updater( self::FILE, 'dadebertolino', 'db-privacy-hub' );
	}

	private function github( array $release, $code = 200 ) {
		$GLOBALS['__dbph_http'] = array(
			'code' => $code,
			'body' => json_encode( $release ),
		);
	}

	private function check( $updater, $installed = '1.7.0' ) {
		$transient          = new stdClass();
		$transient->checked = array( self::BASENAME => $installed );
		return $updater->check_update( $transient );
	}

	private function updates_to( $transient ) {
		return isset( $transient->response[ self::BASENAME ] ) ? $transient->response[ self::BASENAME ] : null;
	}

	/* --- Release e ZIP ----------------------------------------------------- */

	public function test_nuova_versione_con_asset_zip(): void {
		$this->github(
			array(
				'tag_name'    => 'v1.8.0',
				'zipball_url' => 'https://api.github.com/zipball',
				'assets'      => array(
					array( 'name' => 'note.txt', 'browser_download_url' => 'https://x/note.txt' ),
					array( 'name' => 'db-privacy-hub.zip', 'browser_download_url' => 'https://x/db-privacy-hub.zip' ),
				),
			)
		);

		$update = $this->updates_to( $this->check( $this->updater() ) );

		$this->assertSame( '1.8.0', $update->new_version );
		$this->assertSame( 'https://x/db-privacy-hub.zip', $update->package );
		$this->assertSame( 'db-privacy-hub', $update->slug );
	}

	public function test_senza_asset_si_usa_lo_zipball(): void {
		$this->github(
			array(
				'tag_name'    => '1.8.0',
				'zipball_url' => 'https://api.github.com/zipball',
			)
		);

		$this->assertSame( 'https://api.github.com/zipball', $this->updates_to( $this->check( $this->updater() ) )->package );
	}

	public function test_senza_zip_ne_zipball_nessun_aggiornamento(): void {
		$this->github(
			array(
				'tag_name' => 'v1.8.0',
				'assets'   => array( array( 'name' => 'rotto.zip' ), 'non oggetto' ),
			)
		);

		$this->assertNull( $this->updates_to( $this->check( $this->updater() ) ) );
	}

	public function test_versione_uguale_o_precedente(): void {
		$this->github(
			array(
				'tag_name'    => 'v1.7.0',
				'zipball_url' => 'https://z',
			)
		);
		$this->assertNull( $this->updates_to( $this->check( $this->updater() ) ) );
		$this->assertNull( $this->updates_to( $this->check( $this->updater(), '1.9.0' ) ) );
	}

	public function test_errore_http_messo_in_cache_un_ora(): void {
		$GLOBALS['__dbph_http'] = new WP_Error( 'http' );
		$updater                = $this->updater();

		$this->assertNull( $this->updates_to( $this->check( $updater ) ) );
		$this->assertNull( $this->updates_to( $this->check( $updater ) ) );
		$this->assertSame( 1, $GLOBALS['__dbph_http_hits'] );
	}

	public function test_risposta_senza_tag(): void {
		$this->github( array( 'message' => 'Not Found' ), 200 );
		$this->assertNull( $this->updates_to( $this->check( $this->updater() ) ) );
	}

	public function test_transient_senza_checked_invariato(): void {
		$transient = new stdClass();
		$this->assertSame( $transient, $this->updater()->check_update( $transient ) );
		$this->assertSame( 0, $GLOBALS['__dbph_http_hits'] );
	}

	/* --- Installazione (bug 15) -------------------------------------------- */

	private function install( $updater, $destination = '/var/www/wp-content/plugins/dadebertolino-db-privacy-hub-abc123/' ) {
		$extra = array( 'plugin' => self::BASENAME );
		$updater->pre_install( true, $extra );
		return $updater->post_install( true, $extra, array( 'destination' => $destination ) );
	}

	public function test_plugin_disattivato_resta_disattivato(): void {
		$result = $this->install( $this->updater() );

		$this->assertSame( array(), $GLOBALS['__dbph_activated'] );
		$this->assertSame( WP_PLUGIN_DIR . '/db-privacy-hub', $result['destination'] );
	}

	public function test_plugin_attivo_viene_riattivato(): void {
		$GLOBALS['__dbph_active']['site'] = true;
		update_option( 'active_plugins', array( self::BASENAME ) );

		$this->install( $this->updater() );

		$this->assertSame( array( array( self::BASENAME, false ) ), $GLOBALS['__dbph_activated'] );
	}

	public function test_attivo_in_rete_riattivato_in_rete(): void {
		$GLOBALS['__dbph_multisite']         = true;
		$GLOBALS['__dbph_active']['network'] = true;

		$this->install( $this->updater() );

		$this->assertSame( array( array( self::BASENAME, true ) ), $GLOBALS['__dbph_activated'] );
	}

	public function test_cartella_gia_corretta_non_viene_spostata(): void {
		$this->install( $this->updater(), WP_PLUGIN_DIR . '/db-privacy-hub/' );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moves );
	}

	public function test_spostamento_fallito_o_filesystem_assente(): void {
		$GLOBALS['wp_filesystem']->ok = false;
		$result                       = $this->install( $this->updater() );
		$this->assertStringContainsString( 'abc123', $result['destination'] );

		$GLOBALS['wp_filesystem'] = null;
		$result                   = $this->install( $this->updater() );
		$this->assertStringContainsString( 'abc123', $result['destination'] );
	}

	public function test_altri_plugin_ignorati(): void {
		$updater = $this->updater();
		$extra   = array( 'plugin' => 'altro/altro.php' );
		$updater->pre_install( true, $extra );
		$result = $updater->post_install( true, $extra, array( 'destination' => '/tmp/x' ) );

		$this->assertSame( array( 'destination' => '/tmp/x' ), $result );
		$this->assertSame( array(), $GLOBALS['__dbph_activated'] );
	}
}
