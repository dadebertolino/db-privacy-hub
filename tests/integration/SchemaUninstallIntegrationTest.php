<?php
/**
 * Schema e disinstallazione: migrazioni delle tabelle (log DSAR 1.0 → 2.0,
 * archivio 1.0 → 1.1) e uninstall.php, con e senza "conserva i dati", anche
 * in multisite (bug 14).
 *
 * Questi test fanno DDL vero (ALTER/DROP): disattivano la conversione in
 * tabelle temporanee della test suite e, alla fine, ricreano tabelle e
 * option come le lascia il bootstrap.
 *
 * @package DBPH\Tests\Integration
 */

class SchemaUninstallIntegrationTest extends WP_UnitTestCase {

	const PLUGIN = 'db-privacy-hub/db-privacy-hub.php';

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', self::PLUGIN );
		}
	}

	public function tear_down() {
		parent::tear_down();
		// Ripristino fuori dalla transazione del test, e confermato.
		global $wpdb;
		foreach ( $this->sites() as $site_id ) {
			$this->switch_to( $site_id );
			DBPH_DSAR_Log::create_table();
			DBPH_Policy_Archive::create_table();
			update_option( DBPH_DSAR_Log::SCHEMA_OPTION, DBPH_DSAR_Log::SCHEMA_VERSION );
			update_option( DBPH_Policy_Archive::SCHEMA_OPTION, DBPH_Policy_Archive::SCHEMA_VERSION );
			update_option( 'dbph_version', DBPH_VERSION );
			delete_option( 'dbph_preserve_data_on_uninstall' );
			$this->restore();
		}
		if ( ! wp_next_scheduled( 'dbph_dsar_cleanup_pending' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dbph_dsar_cleanup_pending' );
		}
		$wpdb->query( 'COMMIT' );
	}

	private function sites() {
		return is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 1 );
	}

	private function switch_to( $site_id ) {
		if ( is_multisite() ) {
			switch_to_blog( $site_id );
		}
	}

	private function restore() {
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}

	private function table_exists( $table ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$ok = false !== $wpdb->query( "SELECT 1 FROM {$table} LIMIT 1" );
		$wpdb->suppress_errors( false );
		return $ok;
	}

	private function columns( $table ) {
		global $wpdb;
		return $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
	}

	private function uninstall() {
		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	/* --- Migrazioni -------------------------------------------------------- */

	public function test_migrazione_log_dsar_da_1_0(): void {
		global $wpdb;
		$table = $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table}" );
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN source" );
		$wpdb->insert(
			$table,
			array(
				'request_id'   => 42,
				'email_hash'   => str_repeat( 'a', 64 ),
				'request_type' => 'export',
				'status'       => 'completed',
			)
		);
		update_option( DBPH_DSAR_Log::SCHEMA_OPTION, '1.0' );

		DBPH_DSAR_Log::maybe_upgrade_schema();

		$this->assertContains( 'source', $this->columns( $table ) );
		$this->assertSame( 'wp_native', $wpdb->get_var( "SELECT source FROM {$table} WHERE request_id = 42" ) );
		$this->assertSame( DBPH_DSAR_Log::SCHEMA_VERSION, get_option( DBPH_DSAR_Log::SCHEMA_OPTION ) );
		$wpdb->query( "DELETE FROM {$table}" );
	}

	public function test_migrazione_archivio_da_1_0_marca_i_backup(): void {
		global $wpdb;
		$table = $wpdb->prefix . DBPH_Policy_Archive::TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table}" );
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN kind" );
		foreach ( array( 'Pubblicazione iniziale', 'Backup pre-sovrascrittura di "Privacy" (ID 5)', 'Pubblicazione su "Privacy" (ID 5)' ) as $note ) {
			$wpdb->insert(
				$table,
				array(
					'content_hash' => hash( 'sha256', $note ),
					'content'      => $note,
					'note'         => $note,
				)
			);
		}
		update_option( DBPH_Policy_Archive::SCHEMA_OPTION, '1.0' );

		DBPH_Policy_Archive::maybe_upgrade_schema();

		$this->assertSame( array( 'version', 'backup', 'version' ), $wpdb->get_col( "SELECT kind FROM {$table} ORDER BY id ASC" ) );
		$this->assertSame( DBPH_Policy_Archive::SCHEMA_VERSION, get_option( DBPH_Policy_Archive::SCHEMA_OPTION ) );
		$wpdb->query( "DELETE FROM {$table}" );
	}

	public function test_attivazione_su_installazione_pulita(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . DBPH_Policy_Archive::TABLE_NAME );
		delete_option( 'dbph_version' );

		dbph_activate();

		$this->assertTrue( $this->table_exists( $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME ) );
		$this->assertTrue( $this->table_exists( $wpdb->prefix . DBPH_Policy_Archive::TABLE_NAME ) );
		$this->assertSame( DBPH_VERSION, get_option( 'dbph_version' ) );
	}

	/* --- Disinstallazione -------------------------------------------------- */

	public function test_disinstallazione_rimuove_tutto(): void {
		global $wpdb;
		update_option( 'dbph_titolare_nome', 'ACME' );
		update_option( DBPH_DSAR_Log::RETENTION_OPTION, 3 );
		set_transient( 'dbph_embed_scan', array( 'youtube' ) );
		set_transient( 'dbgu_' . md5( self::PLUGIN ), array( 'version' => '9.9.9' ) );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'dbph_page_id', $page_id );

		$this->uninstall();

		$this->assertFalse( $this->table_exists( $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME ) );
		$this->assertFalse( $this->table_exists( $wpdb->prefix . DBPH_Policy_Archive::TABLE_NAME ) );
		foreach ( array( 'dbph_titolare_nome', 'dbph_version', 'dbph_page_id', DBPH_DSAR_Log::RETENTION_OPTION ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
		$this->assertFalse( get_transient( 'dbph_embed_scan' ) );
		$this->assertFalse( get_transient( 'dbgu_' . md5( self::PLUGIN ) ) );
		$this->assertFalse( wp_next_scheduled( 'dbph_dsar_cleanup_pending' ) );
		// La pagina privacy resta: può contenere modifiche dell'admin.
		$this->assertNotNull( get_post( $page_id ) );
	}

	public function test_conserva_i_dati_se_richiesto(): void {
		global $wpdb;
		update_option( 'dbph_preserve_data_on_uninstall', '1' );
		update_option( 'dbph_titolare_nome', 'ACME' );

		$this->uninstall();

		$this->assertTrue( $this->table_exists( $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME ) );
		$this->assertSame( 'ACME', get_option( 'dbph_titolare_nome' ) );
	}

	/**
	 * @group ms-required
	 */
	public function test_multisite_pulisce_ogni_sito_secondo_la_sua_impostazione(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Richiede WP_MULTISITE=1.' );
		}
		global $wpdb;

		$keep  = self::factory()->blog->create();
		$clean = self::factory()->blog->create();
		foreach ( array( $keep, $clean ) as $blog ) {
			switch_to_blog( $blog );
			DBPH_DSAR_Log::create_table();
			DBPH_Policy_Archive::create_table();
			update_option( 'dbph_titolare_nome', 'Sito ' . $blog );
			restore_current_blog();
		}
		switch_to_blog( $keep );
		update_option( 'dbph_preserve_data_on_uninstall', '1' );
		restore_current_blog();

		$this->uninstall();

		switch_to_blog( $clean );
		$this->assertFalse( get_option( 'dbph_titolare_nome' ) );
		$this->assertFalse( $this->table_exists( $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME ) );
		restore_current_blog();

		switch_to_blog( $keep );
		$this->assertSame( 'Sito ' . $keep, get_option( 'dbph_titolare_nome' ) );
		$this->assertTrue( $this->table_exists( $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME ) );
		restore_current_blog();

		// Sito principale: nessuna conservazione impostata.
		$this->assertFalse( $this->table_exists( $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME ) );

		// Le tabelle dei siti di prova sono reali (DDL senza tabelle
		// temporanee): vanno eliminate esplicitamente.
		// Eliminare un sito elimina anche le tabelle dell'Hub di quel sito.
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		$keep_table = $wpdb->get_blog_prefix( $keep ) . DBPH_DSAR_Log::TABLE_NAME;
		wpmu_delete_blog( $keep, true );
		wpmu_delete_blog( $clean, true );
		$this->assertFalse( $this->table_exists( $keep_table ) );
	}
}
