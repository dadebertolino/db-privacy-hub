<?php
/**
 * Registro trattamenti (DBPH_Register) e alias legacy
 * dbseo_processing_register (DBPH_Deprecated_Aliases).
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class RegisterTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
	}

	private function entry( $id, $label = null ) {
		return array(
			'id'    => $id,
			'label' => null === $label ? 'Voce ' . $id : $label,
		);
	}

	private function declare( $hook, array $entries, $priority = 10 ) {
		add_filter(
			$hook,
			function ( $register ) use ( $entries ) {
				return array_merge( (array) $register, $entries );
			},
			$priority
		);
	}

	/* --- collect() --------------------------------------------------------- */

	public function test_senza_dichiarazioni_il_registro_e_vuoto(): void {
		$this->assertSame( array(), DBPH_Register::collect() );
	}

	public function test_annota_la_sorgente_external_senza_sovrascrivere(): void {
		$own          = $this->entry( 'dbwoo_orders' );
		$own['_source'] = 'self';
		$this->declare( 'dbph_processing_register', array( $this->entry( 'dbfb_form' ), $own ) );

		$register = DBPH_Register::collect();

		$this->assertSame( 'external', $register[0]['_source'] );
		$this->assertSame( 'self', $register[1]['_source'] );
	}

	public function test_scarta_le_voci_non_array(): void {
		$this->declare( 'dbph_processing_register', array( 'stringa', 42, null, $this->entry( 'dbcm_x' ) ) );

		$register = DBPH_Register::collect();

		$this->assertCount( 1, $register );
		$this->assertSame( 'dbcm_x', $register[0]['id'] );
	}

	/**
	 * @dataProvider provide_filtri_rotti
	 */
	public function test_filtro_che_restituisce_un_non_array( $value ): void {
		add_filter(
			'dbph_processing_register',
			function () use ( $value ) {
				return $value;
			}
		);

		$this->assertSame( array(), DBPH_Register::collect() );
	}

	public function provide_filtri_rotti() {
		return array(
			'null'    => array( null ),
			'stringa' => array( 'x' ),
			'intero'  => array( 7 ),
		);
	}

	public function test_il_risultato_e_in_cache_fino_al_flush(): void {
		$this->declare( 'dbph_processing_register', array( $this->entry( 'dbcm_a' ) ) );
		$this->assertCount( 1, DBPH_Register::collect() );

		$this->declare( 'dbph_processing_register', array( $this->entry( 'dbcm_b' ) ) );
		$this->assertCount( 1, DBPH_Register::collect() );

		DBPH_Register::flush_cache();
		$this->assertCount( 2, DBPH_Register::collect() );
	}

	public function test_count_by_source_usa_il_prefisso_intero(): void {
		$this->declare(
			'dbph_processing_register',
			array(
				$this->entry( 'dbseo_sitemap' ),
				$this->entry( 'dbseo_analytics' ),
				$this->entry( 'DBCM_Banner' ),
				$this->entry( 'senzaprefisso' ),
				array( 'label' => 'Senza id' ),
			)
		);

		$this->assertSame(
			array(
				'dbseo'         => 2,
				'dbcm'          => 1,
				'senzaprefisso' => 1,
			),
			DBPH_Register::count_by_source()
		);
	}

	/* --- Alias legacy ------------------------------------------------------ */

	public function test_le_voci_legacy_si_accodano_marcate(): void {
		DBPH_Deprecated_Aliases::init();
		$this->declare( 'dbph_processing_register', array( $this->entry( 'dbcm_a' ) ) );
		$this->declare( 'dbseo_processing_register', array( $this->entry( 'dbseo_b' ) ) );

		$register = DBPH_Register::collect();

		$this->assertSame( array( 'dbcm_a', 'dbseo_b' ), array_column( $register, 'id' ) );
		$this->assertTrue( $register[1]['_legacy'] );
		$this->assertSame( 'external', $register[1]['_source'] );
		$this->assertCount( 1, $GLOBALS['__dbph_doing_it_wrong'] );
	}

	public function test_dedup_per_id_vince_la_voce_nuova_anche_se_hookata_dopo(): void {
		DBPH_Deprecated_Aliases::init();
		$this->declare( 'dbseo_processing_register', array( $this->entry( 'dbcm_a', 'Legacy' ) ) );
		// Priorità alta ma < 999: il merge legacy gira comunque dopo.
		$this->declare( 'dbph_processing_register', array( $this->entry( 'dbcm_a', 'Nuova' ) ), 500 );

		$register = DBPH_Register::collect();

		$this->assertCount( 1, $register );
		$this->assertSame( 'Nuova', $register[0]['label'] );
		$this->assertArrayNotHasKey( '_legacy', $register[0] );
		$this->assertSame( array(), $GLOBALS['__dbph_doing_it_wrong'] );
	}

	public function test_voci_legacy_senza_id_o_non_array_scartate(): void {
		DBPH_Deprecated_Aliases::init();
		$this->declare( 'dbseo_processing_register', array( 'x', array( 'label' => 'Senza id' ), $this->entry( 'dbseo_ok' ), $this->entry( 'dbseo_ok' ) ) );

		$this->assertSame( array( 'dbseo_ok' ), array_column( DBPH_Register::collect(), 'id' ) );
	}

	public function test_nessuna_ricorsione_se_il_legacy_rilegge_il_registro(): void {
		DBPH_Deprecated_Aliases::init();
		// Un vecchio plugin che fa da ponte inverso: legge il registro nuovo
		// dentro il filtro legacy.
		add_filter(
			'dbseo_processing_register',
			function ( $legacy ) {
				$legacy[] = array(
					'id'    => 'dbseo_ponte',
					'label' => 'Ponte (' . count( (array) apply_filters( 'dbph_processing_register', array() ) ) . ')',
				);
				return $legacy;
			}
		);

		$register = DBPH_Register::collect();

		$this->assertSame( array( 'dbseo_ponte' ), array_column( $register, 'id' ) );
		$this->assertSame( 'Ponte (0)', $register[0]['label'] );
	}
}
