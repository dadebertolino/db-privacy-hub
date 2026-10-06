<?php
/**
 * Responsabili esterni art. 28 (DBPH_Responsabili): sanitizzazione,
 * salvataggio, id stabili (bug 10) e modelli rapidi (bug 9).
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ResponsabiliTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
	}

	public function test_sanitize_entry_pulisce_tutti_i_campi(): void {
		$out = DBPH_Responsabili::sanitize_entry(
			array(
				'id'       => 'Mio ID!',
				'nome'     => ' <b>Hosting Srl</b> ',
				'ruolo'    => "Hosting\n",
				'paese'    => 'Germania',
				'extra_ue' => '1',
				'garanzie' => 'SCC',
				'dpa_url'  => 'javascript:alert(1)',
				'note'     => '<script>x</script>Nota',
				'altro'    => 'ignorato',
			)
		);

		$this->assertSame(
			array(
				'id'       => 'mioid',
				'nome'     => 'Hosting Srl',
				'ruolo'    => 'Hosting',
				'paese'    => 'Germania',
				'extra_ue' => true,
				'garanzie' => 'SCC',
				'dpa_url'  => '',
				'note'     => 'Nota',
			),
			$out
		);
	}

	public function test_campi_non_scalari_diventano_vuoti(): void {
		$out = DBPH_Responsabili::sanitize_entry(
			array(
				'nome'    => 'Ok',
				'ruolo'   => array( 'x' ),
				'dpa_url' => array( 'https://x' ),
				'note'    => new stdClass(),
			)
		);

		$this->assertSame( '', $out['ruolo'] );
		$this->assertSame( '', $out['dpa_url'] );
		$this->assertSame( '', $out['note'] );
	}

	public function test_url_dpa_valido_conservato(): void {
		$out = DBPH_Responsabili::sanitize_entry(
			array(
				'nome'    => 'X',
				'dpa_url' => 'https://example.com/dpa',
			)
		);
		$this->assertSame( 'https://example.com/dpa', $out['dpa_url'] );
	}

	/* --- Bug 10: id stabili ------------------------------------------------ */

	public function test_voce_senza_id_ha_lo_stesso_id_a_ogni_lettura(): void {
		update_option(
			DBPH_Responsabili::OPTION_KEY,
			array(
				array(
					'nome'  => 'Studio Rossi',
					'ruolo' => 'Commercialista',
				),
			)
		);

		$first  = DBPH_Responsabili::get_all();
		$second = DBPH_Responsabili::get_all();

		$this->assertNotSame( '', $first[0]['id'] );
		$this->assertSame( $first[0]['id'], $second[0]['id'] );
	}

	public function test_voci_identiche_ricevono_id_distinti(): void {
		$entry = array( 'nome' => 'Doppione' );
		DBPH_Responsabili::save_all( array( $entry, $entry ) );

		$ids = array_column( DBPH_Responsabili::get_all(), 'id' );

		$this->assertCount( 2, array_unique( $ids ) );
		$this->assertSame( $ids[0] . '-2', $ids[1] );
	}

	public function test_id_esplicito_conservato(): void {
		DBPH_Responsabili::save_all(
			array(
				array(
					'id'   => 'hosting-1',
					'nome' => 'Hosting',
				),
			)
		);
		$this->assertSame( 'hosting-1', DBPH_Responsabili::get_all()[0]['id'] );
	}

	/* --- Salvataggio e lettura --------------------------------------------- */

	public function test_save_all_scarta_voci_vuote_e_non_array(): void {
		DBPH_Responsabili::save_all(
			array(
				array( 'nome' => '   ' ),
				'stringa',
				array( 'ruolo' => 'Senza nome' ),
				array( 'nome' => 'Valido' ),
			)
		);

		$all = DBPH_Responsabili::get_all();
		$this->assertCount( 1, $all );
		$this->assertSame( 'Valido', $all[0]['nome'] );
		$this->assertTrue( DBPH_Responsabili::has_any() );
	}

	public function test_option_corrotta(): void {
		update_option( DBPH_Responsabili::OPTION_KEY, 'non un array' );
		$this->assertSame( array(), DBPH_Responsabili::get_all() );
		$this->assertFalse( DBPH_Responsabili::has_any() );
	}

	/* --- Modelli (bug 9) --------------------------------------------------- */

	public function test_ogni_modello_di_serie_ha_la_sua_etichetta(): void {
		$this->assertSame(
			array_keys( DBPH_Responsabili::get_templates() ),
			array_keys( DBPH_Responsabili::get_template_labels() )
		);
		$this->assertSame( 'Provider di hosting', DBPH_Responsabili::get_template_labels()['hosting'] );
	}

	public function test_i_modelli_del_filtro_compaiono_nel_menu(): void {
		add_filter(
			'dbph_responsabili_templates',
			function ( $templates ) {
				$templates['dpo_esterno']  = array(
					'nome'  => '[Nome DPO]',
					'label' => 'DPO esterno',
				);
				$templates['marketing']    = array(
					'nome'  => '[Agenzia]',
					'ruolo' => 'Agenzia di marketing',
				);
				$templates['solo_chiave']  = array( 'nome' => '[X]' );
				$templates['senza_nome']   = array( 'label' => 'Rotto' );
				$templates['non_array']    = 'x';
				unset( $templates['backup'] );
				return $templates;
			}
		);

		$labels = DBPH_Responsabili::get_template_labels();

		$this->assertSame( 'DPO esterno', $labels['dpo_esterno'] );
		$this->assertSame( 'Agenzia di marketing', $labels['marketing'] );
		$this->assertSame( 'solo_chiave', $labels['solo_chiave'] );
		$this->assertArrayNotHasKey( 'senza_nome', $labels );
		$this->assertArrayNotHasKey( 'non_array', $labels );
		$this->assertArrayNotHasKey( 'backup', $labels );
	}

	public function test_filtro_modelli_che_restituisce_un_non_array(): void {
		add_filter( 'dbph_responsabili_templates', '__return_false' );
		$this->assertSame( array(), DBPH_Responsabili::get_template_labels() );
	}

	public function test_add_from_template(): void {
		$this->assertTrue( DBPH_Responsabili::add_from_template( 'webmaster' ) );
		$this->assertTrue( DBPH_Responsabili::add_from_template( 'webmaster' ) );
		$this->assertFalse( DBPH_Responsabili::add_from_template( 'inesistente' ) );

		$all = DBPH_Responsabili::get_all();
		$this->assertCount( 2, $all );
		$this->assertSame( '[Nome agenzia / webmaster]', $all[0]['nome'] );
		$this->assertNotSame( $all[0]['id'], $all[1]['id'] );
	}
}
