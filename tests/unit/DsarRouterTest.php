<?php
/**
 * Router DSAR (DBPH_DSAR): registrazione degli exporter/eraser dichiarati
 * via dbph_user_data_exporters / _erasers negli strumenti privacy di
 * WordPress e normalizzazione delle risposte.
 *
 * Un plugin scritto male non deve bloccare la richiesta DSAR degli altri:
 * WordPress interrompe l'intera richiesta alla prima risposta non conforme.
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class DsarRouterTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
	}

	private function declare_exporters( array $exporters ) {
		add_filter(
			'dbph_user_data_exporters',
			function ( $list ) use ( $exporters ) {
				return array_merge( $list, $exporters );
			}
		);
	}

	private function declare_erasers( array $erasers ) {
		add_filter(
			'dbph_user_data_erasers',
			function ( $list ) use ( $erasers ) {
				return array_merge( $list, $erasers );
			}
		);
	}

	/* --- normalize_export_response ---------------------------------------- */

	public function test_risposta_export_conforme_resta_invariata(): void {
		$response = array(
			'data' => array( array( 'group_id' => 'x' ) ),
			'done' => false,
		);
		$this->assertSame( $response, DBPH_DSAR::normalize_export_response( $response, 'p' ) );
		$this->assertSame( array(), $GLOBALS['__dbph_doing_it_wrong'] );
	}

	public function test_export_senza_done_viene_chiuso(): void {
		$out = DBPH_DSAR::normalize_export_response( array( 'data' => array() ), 'p' );
		$this->assertTrue( $out['done'] );
		$this->assertCount( 1, $GLOBALS['__dbph_doing_it_wrong'] );
		$this->assertStringContainsString( 'mancano: done', $GLOBALS['__dbph_doing_it_wrong'][0][1] );
	}

	public function test_export_con_data_non_array_diventa_vuoto(): void {
		$out = DBPH_DSAR::normalize_export_response(
			array(
				'data' => 'testo',
				'done' => true,
			),
			'p'
		);
		$this->assertSame( array(), $out['data'] );
	}

	public function test_export_lista_piatta_diventa_data(): void {
		$items = array( array( 'group_id' => 'a' ), array( 'group_id' => 'b' ) );
		$out   = DBPH_DSAR::normalize_export_response( $items, 'p' );
		$this->assertSame( $items, $out['data'] );
		$this->assertTrue( $out['done'] );
	}

	/**
	 * @dataProvider provide_risposte_export_non_valide
	 */
	public function test_export_non_valido_diventa_vuoto_e_chiuso( $response ): void {
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			DBPH_DSAR::normalize_export_response( $response, 'p' )
		);
	}

	public function provide_risposte_export_non_valide() {
		return array(
			'null'            => array( null ),
			'false'           => array( false ),
			'stringa'         => array( 'ok' ),
			'mappa senza data' => array( array( 'items' => array() ) ),
		);
	}

	/* --- normalize_erase_response ----------------------------------------- */

	public function test_risposta_erase_conforme(): void {
		$out = DBPH_DSAR::normalize_erase_response(
			array(
				'items_removed'  => 1,
				'items_retained' => 0,
				'messages'       => array( 'k' => 'msg' ),
				'done'           => false,
			),
			'p'
		);
		$this->assertSame(
			array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array( 'msg' ),
				'done'           => false,
			),
			$out
		);
		$this->assertSame( array(), $GLOBALS['__dbph_doing_it_wrong'] );
	}

	public function test_erase_parziale_completa_le_chiavi_mancanti(): void {
		$out = DBPH_DSAR::normalize_erase_response( array( 'items_removed' => true ), 'p' );
		$this->assertTrue( $out['items_removed'] );
		$this->assertFalse( $out['items_retained'] );
		$this->assertSame( array(), $out['messages'] );
		$this->assertTrue( $out['done'] );
		$this->assertStringContainsString( 'items_retained/messages/done', $GLOBALS['__dbph_doing_it_wrong'][0][1] );
	}

	public function test_erase_non_array(): void {
		$out = DBPH_DSAR::normalize_erase_response( 'fatto', 'p' );
		$this->assertFalse( $out['items_removed'] );
		$this->assertTrue( $out['done'] );
	}

	public function test_erase_messages_non_array(): void {
		$out = DBPH_DSAR::normalize_erase_response( array( 'messages' => 'testo' ), 'p' );
		$this->assertSame( array(), $out['messages'] );
	}

	/* --- register_exporters / register_erasers ---------------------------- */

	public function test_registra_gli_exporter_validi_senza_toccare_quelli_esistenti(): void {
		$this->declare_exporters(
			array(
				'Mio-Plugin' => array(
					'label'    => 'Mio Plugin',
					'callback' => '__return_false',
				),
			)
		);
		$core = array(
			'wordpress-user' => array(
				'exporter_friendly_name' => 'Utente',
				'callback'               => '__return_true',
			),
		);

		$out = DBPH_DSAR::register_exporters( $core );

		$this->assertSame( $core['wordpress-user'], $out['wordpress-user'] );
		$this->assertArrayHasKey( 'mio-plugin', $out );
		$this->assertSame( 'Mio Plugin', $out['mio-plugin']['exporter_friendly_name'] );
		$this->assertIsCallable( $out['mio-plugin']['callback'] );
	}

	public function test_scarta_le_voci_non_valide(): void {
		$this->declare_exporters(
			array(
				'senza-callback'   => array( 'label' => 'X' ),
				'non-callable'     => array(
					'label'    => 'X',
					'callback' => 'funzione_inesistente_dbph',
				),
				'senza-label'      => array( 'callback' => '__return_true' ),
				'non-array'        => 'stringa',
				'!!!'              => array(
					'label'    => 'Chiave vuota dopo sanitize',
					'callback' => '__return_true',
				),
			)
		);

		$this->assertSame( array(), DBPH_DSAR::register_exporters( array() ) );
	}

	public function test_accetta_la_chiave_core_come_etichetta_di_ripiego(): void {
		$this->declare_erasers(
			array(
				'legacy' => array(
					'eraser_friendly_name' => 'Legacy',
					'callback'             => '__return_true',
				),
			)
		);

		$out = DBPH_DSAR::register_erasers( array() );

		$this->assertSame( 'Legacy', $out['legacy']['eraser_friendly_name'] );
		$this->assertNotEmpty( $GLOBALS['__dbph_doing_it_wrong'] );
	}

	public function test_input_non_array_dal_core(): void {
		$this->assertSame( array(), DBPH_DSAR::register_exporters( null ) );
		$this->assertSame( array(), DBPH_DSAR::register_erasers( 'x' ) );
	}

	public function test_la_callback_registrata_normalizza_la_risposta(): void {
		$this->declare_exporters(
			array(
				'piatto' => array(
					'label'    => 'Piatto',
					'callback' => function ( $email, $page ) {
						return array( array( 'item_id' => $email . '#' . $page ) );
					},
				),
			)
		);

		$out = DBPH_DSAR::register_exporters( array() );
		$res = call_user_func( $out['piatto']['callback'], 'a@b.it', 2 );

		$this->assertSame( array( array( 'item_id' => 'a@b.it#2' ) ), $res['data'] );
		$this->assertTrue( $res['done'] );
	}

	/* --- Bug 3: eccezioni delle callback ---------------------------------- */

	public function test_exporter_che_lancia_non_blocca_gli_altri(): void {
		$this->declare_exporters(
			array(
				'rotto' => array(
					'label'    => 'Rotto',
					'callback' => function () {
						throw new RuntimeException( 'boom' );
					},
				),
				'sano'  => array(
					'label'    => 'Sano',
					'callback' => function () {
						return array(
							'data' => array( array( 'item_id' => 1 ) ),
							'done' => true,
						);
					},
				),
			)
		);

		$out = DBPH_DSAR::register_exporters( array() );

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			call_user_func( $out['rotto']['callback'], 'a@b.it', 1 )
		);
		$this->assertCount( 1, call_user_func( $out['sano']['callback'], 'a@b.it', 1 )['data'] );
		$this->assertStringContainsString( 'boom', $GLOBALS['__dbph_doing_it_wrong'][0][1] );
	}

	public function test_eraser_che_lancia_segna_i_dati_come_trattenuti(): void {
		$this->declare_erasers(
			array(
				'rotto' => array(
					'label'    => 'Rotto',
					'callback' => function () {
						throw new Error( 'fatal' );
					},
				),
			)
		);

		$out = DBPH_DSAR::register_erasers( array() );
		$res = call_user_func( $out['rotto']['callback'], 'a@b.it', 1 );

		$this->assertFalse( $res['items_removed'] );
		$this->assertTrue( $res['items_retained'] );
		$this->assertTrue( $res['done'] );
		$this->assertCount( 1, $res['messages'] );
		$this->assertStringContainsString( '"rotto"', $res['messages'][0] );
	}
}
