<?php
/**
 * Generazione della policy con plugin "finti" registrati sui filtri pubblici
 * e scansione dei contenuti incorporati su MySQL reale.
 *
 * @package DBPH\Tests\Integration
 */

class GenerationEmbedIntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		DBPH_Register::flush_cache();
		DBPH_Embed_Bridge::flush_scan_cache();
		delete_option( DBPH_Embed_Bridge::OPTION_MANUAL );
		update_option( 'dbph_titolare_nome', 'ACME Srl' );
		update_option( 'dbph_titolare_email', 'privacy@acme.example' );
		// I contenuti di prova contengono iframe: niente kses (in multisite
		// neanche l'amministratore ha unfiltered_html).
		kses_remove_filters();
	}

	public function tear_down() {
		DBPH_Register::flush_cache();
		DBPH_Embed_Bridge::flush_scan_cache();
		parent::tear_down();
	}

	private function publish( $content, $type = 'post', $status = 'publish' ) {
		return self::factory()->post->create(
			array(
				'post_type'    => $type,
				'post_status'  => $status,
				'post_content' => $content,
			)
		);
	}

	/* --- Generazione ------------------------------------------------------- */

	public function test_policy_completa_con_i_contratti_dei_plugin(): void {
		add_filter(
			'dbph_processing_register',
			function ( $register ) {
				$register[] = array(
					'id'             => 'dbfb_newsletter',
					'label'          => 'Newsletter',
					'status'         => 'active',
					'purpose'        => 'Invio newsletter.',
					'legal_basis'    => 'Consenso.',
					'data_collected' => 'Email.',
					'retention'      => 'Fino a revoca.',
					'transfers'      => 'Nessuno.',
				);
				return $register;
			}
		);
		add_filter(
			'dbph_policy_destinatari',
			function ( $dest ) {
				$dest[] = array(
					'name'        => 'Mailer Srl',
					'description' => 'Invio email.',
					'country'     => 'Italia',
				);
				return $dest;
			}
		);
		add_filter(
			'dbph_user_data_exporters',
			function ( $exporters ) {
				$exporters['dbfb'] = array(
					'label'    => 'Form',
					'callback' => '__return_empty_array',
				);
				return $exporters;
			}
		);

		$html = DBPH_Policy_Generator::generate();

		$this->assertStringContainsString( '<strong>ACME Srl</strong>', $html );
		$this->assertStringContainsString( '<h4>Newsletter</h4>', $html );
		$this->assertStringContainsString( '<strong>Mailer Srl</strong>', $html );
		$this->assertStringContainsString( 'Procedura di esercizio dei diritti', $html );
		$this->assertStringContainsString( 'mailto:privacy@acme.example', $html );
		// Passa da wp_kses_post senza perdere la struttura (come la pagina pubblicata).
		$this->assertSame( substr_count( $html, '<h3' ), substr_count( wp_kses_post( $html ), '<h3' ) );
	}

	public function test_alias_legacy_su_wordpress_reale(): void {
		add_filter(
			'dbseo_processing_register',
			function ( $legacy ) {
				$legacy[] = array(
					'id'     => 'dbseo_sitemap',
					'label'  => 'Sitemap (legacy)',
					'status' => 'active',
				);
				return $legacy;
			}
		);

		$this->setExpectedIncorrectUsage( 'apply_filters( "dbseo_processing_register" )' );
		$this->assertStringContainsString( 'Sitemap (legacy)', DBPH_Policy_Generator::generate() );
	}

	public function test_filtro_sezioni_rotto_su_wordpress_reale(): void {
		add_filter( 'dbph_policy_sections', '__return_null', 99 );

		$this->setExpectedIncorrectUsage( 'DBPH_Policy_Generator::generate' );
		$this->assertStringContainsString( '1. Titolare del trattamento', DBPH_Policy_Generator::generate() );
	}

	/* --- Scansione embed --------------------------------------------------- */

	public function test_scansione_dei_contenuti_pubblicati(): void {
		$this->publish( '<iframe src="https://www.youtube.com/embed/abc"></iframe>' );
		$this->publish( '<!-- wp:embed {"url":"https://vimeo.com/1","providerNameSlug":"vimeo"} /-->', 'page' );
		$this->publish( '<iframe src="https://www.google.com/maps?q=Mondovi&output=embed"></iframe>' );
		// Non pubblicati o solo link: non contano.
		$this->publish( '<iframe src="https://open.spotify.com/embed/track/1"></iframe>', 'post', 'draft' );
		$this->publish( '<a href="https://www.instagram.com/p/abc/">Seguici</a>' );
		$this->publish( '<iframe src="https://widget.example/x?output=embed"></iframe>' );

		$found = DBPH_Embed_Bridge::scan_content();

		$this->assertEqualsCanonicalizing( array( 'youtube', 'vimeo', 'google_maps' ), $found );
		$this->assertSame( $found, get_transient( DBPH_Embed_Bridge::TRANSIENT_SCAN ) );
	}

	public function test_embed_dalla_cache_oembed(): void {
		$post_id = $this->publish( "Guarda qui:\n\nhttps://www.tiktok.com/@x/video/1" );
		add_post_meta( $post_id, '_oembed_' . md5( 'x' ), '<blockquote class="tiktok-embed"><iframe src="https://www.tiktok.com/embed/v2/1"></iframe></blockquote>' );

		$this->assertContains( 'tiktok', DBPH_Embed_Bridge::scan_content() );
	}

	public function test_la_cache_si_aggiorna_quando_cambia_un_contenuto_pubblico(): void {
		$this->assertSame( array(), DBPH_Embed_Bridge::scan_content() );

		$this->publish( '<iframe src="https://player.vimeo.com/video/1"></iframe>' );

		$this->assertSame( array( 'vimeo' ), DBPH_Embed_Bridge::scan_content() );
	}

	public function test_un_ordine_o_una_richiesta_privacy_non_azzerano_la_cache(): void {
		set_transient( DBPH_Embed_Bridge::TRANSIENT_SCAN, array( 'youtube' ) );

		wp_create_user_request( 'a@example.com', 'export_personal_data' );
		$this->publish( 'bozza automatica', 'post', 'auto-draft' );

		$this->assertSame( array( 'youtube' ), get_transient( DBPH_Embed_Bridge::TRANSIENT_SCAN ) );
	}

	public function test_piattaforme_rilevate_nel_registro_e_nei_destinatari(): void {
		$this->publish( '<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe>' );

		$html = DBPH_Policy_Generator::generate();

		$this->assertStringContainsString( 'Contenuti incorporati da piattaforme terze', $html );
		$this->assertStringContainsString( 'Google Ireland Ltd (YouTube)', $html );
	}
}
