<?php
/**
 * Smoke test dell'infrastruttura di integrazione: plugin caricato, moduli
 * avviati, tabelle create. Se questi falliscono, i risultati degli altri
 * integration test non sono attendibili.
 *
 * @package DBPH\Tests\Integration
 */

class InfraIntegrationTest extends WP_UnitTestCase {

	public function test_il_plugin_e_avviato(): void {
		$this->assertTrue( defined( 'DBPH_VERSION' ) );
		$this->assertSame( DBPH_VERSION, get_option( 'dbph_version' ) );
		$this->assertSame( 5, has_action( 'plugins_loaded', 'dbph_boot' ) );
	}

	public function test_le_tabelle_esistono(): void {
		global $wpdb;

		foreach ( array( DBPH_DSAR_Log::TABLE_NAME, DBPH_Policy_Archive::TABLE_NAME ) as $table ) {
			$name = $wpdb->prefix . $table;
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ), $name );
		}
		$this->assertSame( DBPH_DSAR_Log::SCHEMA_VERSION, get_option( DBPH_DSAR_Log::SCHEMA_OPTION ) );
		$this->assertSame( DBPH_Policy_Archive::SCHEMA_VERSION, get_option( DBPH_Policy_Archive::SCHEMA_OPTION ) );
	}

	public function test_il_router_dsar_e_agganciato_agli_strumenti_privacy(): void {
		$this->assertSame( 20, has_filter( 'wp_privacy_personal_data_exporters', array( 'DBPH_DSAR', 'register_exporters' ) ) );
		$this->assertSame( 20, has_filter( 'wp_privacy_personal_data_erasers', array( 'DBPH_DSAR', 'register_erasers' ) ) );
	}

	public function test_il_cron_dei_pending_e_pianificato(): void {
		$this->assertNotFalse( wp_next_scheduled( 'dbph_dsar_cleanup_pending' ) );
	}
}
