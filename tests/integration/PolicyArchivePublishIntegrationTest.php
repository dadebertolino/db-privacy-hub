<?php
/**
 * Archivio delle versioni della policy e pubblicazione come pagina
 * WordPress (DBPH_Policy_Archive, DBPH_Policy_Publisher).
 *
 * La versione corrente (get_current_version_id) è l'API che Cookie Manager
 * e Form Builder registrano accanto a ogni consenso: deve sempre indicare il
 * testo effettivamente pubblicato (bug 4).
 *
 * @package DBPH\Tests\Integration
 */

class PolicyArchivePublishIntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . DBPH_Policy_Archive::TABLE_NAME );
		delete_option( 'dbph_policy_current_version' );
		delete_option( 'dbph_page_id' );
		update_option( 'wp_page_for_privacy_policy', 0 );
		// Il testo di prova contiene markup: niente filtri kses per l'admin.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function versions() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, kind, note FROM ' . $wpdb->prefix . DBPH_Policy_Archive::TABLE_NAME . ' ORDER BY id ASC' );
	}

	private function page( $content, $status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Privacy',
				'post_status'  => $status,
				'post_content' => $content,
			)
		);
	}

	/* --- Archivio ---------------------------------------------------------- */

	public function test_senza_versioni_la_versione_corrente_e_zero(): void {
		$this->assertSame( 0, DBPH_Policy_Archive::get_current_version_id() );
	}

	public function test_save_deduplica_hash_e_date(): void {
		$v1 = DBPH_Policy_Archive::save( '<p>Testo <span class="dbph-date">01/10/2026</span></p>', 'Prima' );

		$this->assertIsInt( $v1 );
		$this->assertFalse( DBPH_Policy_Archive::save( '<p>Testo <span class="dbph-date">01/10/2026</span></p>' ) );
		$this->assertFalse( DBPH_Policy_Archive::save( "<p>Testo  <span class=\"dbph-date\">06/10/2026</span></p>\n" ) );

		$v2 = DBPH_Policy_Archive::save( '<p>Testo cambiato</p>', 'Seconda' );
		$this->assertGreaterThan( $v1, $v2 );
		$this->assertSame( $v2, DBPH_Policy_Archive::get_current_version_id() );
		$this->assertSame( 2, DBPH_Policy_Archive::get_total_count() );
	}

	public function test_versione_corrente_senza_option(): void {
		$v1 = DBPH_Policy_Archive::save( '<p>Uno</p>' );
		delete_option( 'dbph_policy_current_version' );

		$this->assertSame( $v1, DBPH_Policy_Archive::get_current_version_id() );
		$this->assertSame( $v1, (int) get_option( 'dbph_policy_current_version' ) );
	}

	public function test_created_at_in_ora_locale(): void {
		update_option( 'timezone_string', 'Pacific/Auckland' );
		$id = DBPH_Policy_Archive::save( '<p>Fuso</p>' );

		$created = strtotime( DBPH_Policy_Archive::get( $id )->created_at . ' UTC' );
		$this->assertEqualsWithDelta( current_time( 'timestamp' ), $created, 60 );
	}

	public function test_la_modifica_manuale_della_pagina_privacy_crea_una_versione(): void {
		$page_id = $this->page( '<p>Originale</p>' );
		update_option( 'wp_page_for_privacy_policy', $page_id );

		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '<p>Ritoccata a mano</p>',
			)
		);

		$latest = DBPH_Policy_Archive::get_latest();
		$this->assertSame( '<p>Ritoccata a mano</p>', $latest->content );
		$this->assertStringContainsString( 'Modifica manuale', $latest->note );
		$this->assertSame( (int) $latest->id, DBPH_Policy_Archive::get_current_version_id() );
	}

	public function test_modifiche_ad_altre_pagine_o_bozze_ignorate(): void {
		$privacy = $this->page( '<p>Privacy</p>', 'draft' );
		update_option( 'wp_page_for_privacy_policy', $privacy );
		$other = $this->page( '<p>Altro</p>' );

		wp_update_post( array( 'ID' => $other, 'post_content' => '<p>Altro 2</p>' ) );
		wp_update_post( array( 'ID' => $privacy, 'post_content' => '<p>Bozza 2</p>' ) );

		$this->assertSame( 0, DBPH_Policy_Archive::get_total_count() );
	}

	/* --- Pubblicazione ----------------------------------------------------- */

	public function test_creazione_della_pagina(): void {
		$page_id = DBPH_Policy_Publisher::create( 'Informativa', 'informativa', '<h2>Policy</h2>' );

		$this->assertIsInt( $page_id );
		$this->assertSame( 'publish', get_post_status( $page_id ) );
		$this->assertSame( 'informativa', get_post( $page_id )->post_name );
		$this->assertSame( $page_id, (int) get_option( 'dbph_page_id' ) );
		$this->assertSame( $page_id, (int) get_option( 'wp_page_for_privacy_policy' ) );
		$this->assertSame( '<h2>Policy</h2>', DBPH_Policy_Archive::get( DBPH_Policy_Archive::get_current_version_id() )->content );
		$this->assertSame( $page_id, DBPH_Policy_Publisher::get_linked_page()->ID );
	}

	public function test_aggiornare_la_pagina_collegata_non_crea_duplicati(): void {
		$page_id = DBPH_Policy_Publisher::create( 'Privacy Policy', 'privacy-policy', '<p>v1</p>' );

		$this->assertSame( $page_id, DBPH_Policy_Publisher::overwrite( $page_id, '<p>v2</p>' ) );

		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'name'        => 'privacy-policy',
				'fields'      => 'ids',
			)
		);
		$this->assertSame( array( $page_id ), array_map( 'intval', $pages ) );
		$this->assertSame( '<p>v2</p>', get_post( $page_id )->post_content );
		// Il testo precedente era già una versione: nessun backup.
		$this->assertSame( array( 'version', 'version' ), wp_list_pluck( $this->versions(), 'kind' ) );
	}

	/**
	 * Bug 4: il backup della pagina sovrascritta non diventa mai la
	 * versione corrente, neanche durante la pubblicazione.
	 */
	public function test_sovrascrittura_di_una_pagina_scritta_a_mano(): void {
		$old_version = DBPH_Policy_Archive::save( '<p>Versione precedente dell\'Hub</p>' );
		$page_id     = $this->page( '<p>Testo scritto a mano</p>' );

		$during = null;
		add_filter(
			'wp_insert_post_data',
			function ( $data ) use ( &$during ) {
				$during = DBPH_Policy_Archive::get_current_version_id();
				return $data;
			}
		);

		$this->assertSame( $page_id, DBPH_Policy_Publisher::overwrite( $page_id, '<p>Nuova policy</p>' ) );

		// Durante il salvataggio della pagina vale ancora la versione precedente.
		$this->assertSame( $old_version, $during );

		$versions = $this->versions();
		$this->assertSame( array( 'version', 'backup', 'version' ), wp_list_pluck( $versions, 'kind' ) );
		$this->assertStringContainsString( 'Backup pre-sovrascrittura', $versions[1]->note );
		$this->assertSame( (int) $versions[2]->id, DBPH_Policy_Archive::get_current_version_id() );
		$this->assertSame( '<p>Nuova policy</p>', DBPH_Policy_Archive::get_latest()->content );
		$this->assertSame( $page_id, (int) get_option( 'wp_page_for_privacy_policy' ) );
	}

	public function test_backup_non_ripetuto_e_versione_corrente_dopo_option_persa(): void {
		$page_id = $this->page( '<p>Scritto a mano</p>' );
		DBPH_Policy_Publisher::overwrite( $page_id, '<p>Hub v1</p>' );
		wp_update_post( array( 'ID' => $page_id, 'post_content' => '<p>Scritto a mano</p>' ) );
		DBPH_Policy_Publisher::overwrite( $page_id, '<p>Hub v1</p>' );

		$this->assertSame( 1, count( wp_list_filter( $this->versions(), array( 'kind' => 'backup' ) ) ) );

		// Senza l'option la versione corrente si ricava dall'archivio, mai da un backup.
		delete_option( 'dbph_policy_current_version' );
		$this->assertSame( 'version', DBPH_Policy_Archive::get( DBPH_Policy_Archive::get_current_version_id() )->kind );
	}

	public function test_pagina_di_destinazione_non_valida(): void {
		$trashed = $this->page( '<p>x</p>', 'trash' );
		$post    = self::factory()->post->create();

		$this->assertWPError( DBPH_Policy_Publisher::overwrite( $trashed, '<p>y</p>' ) );
		$this->assertWPError( DBPH_Policy_Publisher::overwrite( $post, '<p>y</p>' ) );
		$this->assertWPError( DBPH_Policy_Publisher::overwrite( 999999, '<p>y</p>' ) );
		$this->assertSame( 0, DBPH_Policy_Archive::get_total_count() );
	}

	public function test_pagina_collegata_nel_cestino(): void {
		$page_id = DBPH_Policy_Publisher::create( 'Privacy', 'privacy', '<p>x</p>' );
		wp_trash_post( $page_id );

		$this->assertNull( DBPH_Policy_Publisher::get_linked_page() );
	}
}
