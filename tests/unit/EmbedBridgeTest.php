<?php
/**
 * Bridge embed/social (DBPH_Embed_Bridge): catalogo piattaforme, piattaforme
 * dal filtro (bug 16), pattern LIKE, invalidazione della cache di scansione,
 * pixel, contitolarità pagine social.
 *
 * La scansione SQL vera è negli integration test: qui la cache è
 * precompilata nel transient.
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class EmbedBridgeTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
	}

	private function detected( array $keys ) {
		set_transient( DBPH_Embed_Bridge::TRANSIENT_SCAN, $keys );
	}

	/* --- Catalogo e filtro (bug 16) ---------------------------------------- */

	public function test_catalogo_di_serie_completo(): void {
		$platforms = DBPH_Embed_Bridge::get_platforms();

		$this->assertSame(
			array( 'youtube', 'vimeo', 'facebook', 'instagram', 'tiktok', 'x', 'linkedin', 'spotify', 'google_maps' ),
			array_keys( $platforms )
		);
		foreach ( $platforms as $key => $p ) {
			$this->assertNotEmpty( $p['label'], $key );
			$this->assertNotEmpty( $p['patterns'], $key );
			$this->assertNotEmpty( $p['dest']['name'], $key );
		}
	}

	public function test_piattaforme_del_filtro_normalizzate(): void {
		add_filter(
			'dbph_embed_platforms',
			function ( $platforms ) {
				$platforms['solo_pattern'] = array( 'patterns' => 'widget.example/embed' );
				$platforms['completa']     = array(
					'label'    => 'Completa',
					'patterns' => array( 'completa.example/embed', '', array( 'x' ) ),
					'blocks'   => array( 'completa' ),
					'dest'     => array( 'name' => 'Completa Srl' ),
				);
				$platforms['dest_rotto']   = array(
					'label' => 'Dest rotto',
					'dest'  => 'Nome',
				);
				$platforms['non_array']    = 'x';
				$platforms['']             = array( 'label' => 'Chiave vuota' );
				return $platforms;
			}
		);

		$platforms = DBPH_Embed_Bridge::get_platforms();

		$this->assertSame(
			array(
				'label'    => 'solo_pattern',
				'patterns' => array( 'widget.example/embed' ),
				'blocks'   => array(),
				'dest'     => null,
			),
			$platforms['solo_pattern']
		);
		$this->assertSame( array( 'completa.example/embed' ), $platforms['completa']['patterns'] );
		$this->assertNull( $platforms['dest_rotto']['dest'] );
		$this->assertArrayNotHasKey( 'non_array', $platforms );
		$this->assertArrayNotHasKey( '', $platforms );
	}

	public function test_filtro_che_restituisce_un_non_array(): void {
		add_filter( 'dbph_embed_platforms', '__return_false' );
		$this->assertSame( array(), DBPH_Embed_Bridge::get_platforms() );
	}

	public function test_piattaforme_incomplete_attive_non_rompono_registro_e_destinatari(): void {
		add_filter(
			'dbph_embed_platforms',
			function ( $platforms ) {
				$platforms['minima'] = array( 'patterns' => array( 'minima.example' ) );
				return $platforms;
			}
		);
		$this->detected( array( 'minima', 'youtube' ) );

		$register = DBPH_Embed_Bridge::register_processings( array() );
		$dest     = DBPH_Embed_Bridge::register_destinatari( array() );

		$this->assertStringContainsString( '(minima, YouTube)', $register[0]['purpose'] );
		$this->assertSame( array( 'Google Ireland Ltd (YouTube)' ), array_column( $dest, 'name' ) );
	}

	/* --- Pattern LIKE ------------------------------------------------------ */

	public function test_pattern_like_con_escape_e_blocchi(): void {
		$likes = DBPH_Embed_Bridge::like_patterns(
			array(
				'patterns' => array( 'a_b%c.example' ),
				'blocks'   => array( 'youtube' ),
			)
		);

		$this->assertSame( array( '%a\\_b\\%c.example%', '%"providerNameSlug":"youtube"%' ), $likes );
	}

	public function test_google_maps_richiede_un_url_maps_prima_di_output_embed(): void {
		$likes = DBPH_Embed_Bridge::like_patterns( DBPH_Embed_Bridge::get_platforms()['google_maps'] );

		$this->assertContains( '%google.com/maps%output=embed%', $likes );
		$this->assertNotContains( '%output=embed%', $likes );
	}

	/* --- Piattaforme manuali ----------------------------------------------- */

	public function test_piattaforme_manuali_valide(): void {
		update_option( DBPH_Embed_Bridge::OPTION_MANUAL, array( 'Vimeo', 'inesistente', 'spotify' ) );
		$this->detected( array( 'youtube', 'spotify' ) );

		$this->assertSame( array( 'vimeo', 'spotify' ), DBPH_Embed_Bridge::get_manual_platforms() );
		$this->assertSame( array( 'youtube', 'spotify', 'vimeo' ), DBPH_Embed_Bridge::get_active_platforms() );
	}

	public function test_option_manuale_corrotta(): void {
		update_option( DBPH_Embed_Bridge::OPTION_MANUAL, 'youtube' );
		$this->assertSame( array(), DBPH_Embed_Bridge::get_manual_platforms() );
	}

	/* --- Invalidazione della cache (bug 16) -------------------------------- */

	/**
	 * @dataProvider provide_post_che_non_invalidano
	 */
	public function test_contenuti_non_scansionati_non_invalidano_la_cache( array $fields ): void {
		$this->detected( array( 'youtube' ) );
		dbph_test_add_post( 10, array( 'post_type' => 'post' ) );
		$post = dbph_test_add_post( 11, $fields );

		DBPH_Embed_Bridge::maybe_flush_scan_cache( 11, $post );

		$this->assertSame( array( 'youtube' ), get_transient( DBPH_Embed_Bridge::TRANSIENT_SCAN ) );
	}

	public function provide_post_che_non_invalidano() {
		return array(
			'revisione'      => array( array( 'post_type' => 'revision', 'post_name' => '10-revision-v1', 'post_parent' => 10 ) ),
			'autosalvataggio' => array( array( 'post_type' => 'revision', 'post_name' => '10-autosave-v1', 'post_parent' => 10 ) ),
			'ordine woo'     => array( array( 'post_type' => 'shop_order' ) ),
			'richiesta dsar' => array( array( 'post_type' => 'user_request' ) ),
			'bozza auto'     => array( array( 'post_type' => 'post', 'post_status' => 'auto-draft' ) ),
		);
	}

	public function test_un_articolo_salvato_invalida_la_cache(): void {
		$this->detected( array( 'youtube' ) );
		$post = dbph_test_add_post( 12, array( 'post_type' => 'page' ) );

		DBPH_Embed_Bridge::maybe_flush_scan_cache( 12, $post );

		$this->assertFalse( get_transient( DBPH_Embed_Bridge::TRANSIENT_SCAN ) );
	}

	public function test_post_inesistente(): void {
		$this->detected( array( 'youtube' ) );
		DBPH_Embed_Bridge::maybe_flush_scan_cache( 999 );
		$this->assertSame( array( 'youtube' ), get_transient( DBPH_Embed_Bridge::TRANSIENT_SCAN ) );
	}

	/* --- Pixel e registro -------------------------------------------------- */

	public function test_pixel_rilevati_dai_plugin_attivi(): void {
		update_option( 'active_plugins', array( 'pixelyoursite/pixelyoursite.php', 'tiktok-feed/tiktok-feed.php', 'official-facebook-pixel/facebook-for-wordpress.php' ) );

		$this->assertSame(
			array( 'PixelYourSite (Meta/Google/TikTok pixel)', 'Meta pixel' ),
			DBPH_Embed_Bridge::detect_pixels()
		);
	}

	public function test_nessun_embed_ne_pixel_nessuna_voce(): void {
		$this->detected( array() );
		$this->assertSame( array( 'x' ), DBPH_Embed_Bridge::register_processings( array( 'x' ) ) );
	}

	public function test_voci_embed_e_pixel(): void {
		$this->detected( array( 'vimeo' ) );
		update_option( 'active_plugins', array( 'tiktok-for-business/tiktok-for-business.php' ) );

		$register = DBPH_Embed_Bridge::register_processings( null );

		$this->assertSame( array( 'dbemb_embeds', 'dbemb_pixel' ), array_column( $register, 'id' ) );
		$this->assertStringContainsString( '(Vimeo)', $register[0]['purpose'] );
		$this->assertStringContainsString( '(TikTok pixel)', $register[1]['purpose'] );
	}

	/* --- Contitolarità pagine social --------------------------------------- */

	public function test_contitolarita_solo_se_attivata(): void {
		$sections = array( 'destinatari' => '<h3>Destinatari</h3>' );

		$this->assertSame( $sections, DBPH_Embed_Bridge::extend_sections( $sections, array() ) );

		update_option( DBPH_Embed_Bridge::OPTION_SOCIAL_PAGES, '1' );
		$this->assertStringContainsString( 'C-210/16', DBPH_Embed_Bridge::extend_sections( $sections, array() )['destinatari'] );
	}
}
