<?php
/**
 * Registro consensi (DBPH_Consents_Register): fonti dichiarate via
 * dbph_consents_register, vista unificata, fonti rotte (bug 3) e righe
 * malformate (bug 11).
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ConsentsRegisterTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
	}

	private function source( array $rows, array $extra = array() ) {
		return array_merge(
			array(
				'label' => 'Fonte',
				'count' => function () use ( $rows ) {
					return count( $rows );
				},
				'query' => function ( $args = array() ) use ( $rows ) {
					return isset( $args['limit'] ) ? array_slice( $rows, 0, $args['limit'] ) : $rows;
				},
			),
			$extra
		);
	}

	private function declare_sources( array $sources ) {
		add_filter(
			'dbph_consents_register',
			function ( $list ) use ( $sources ) {
				return array_merge( $list, $sources );
			}
		);
	}

	private function consent( $id, $timestamp ) {
		return array(
			'id'             => $id,
			'timestamp'      => $timestamp,
			'subject'        => 'u***@x.it',
			'consent_type'   => 'form:contatti',
			'consent_text'   => 'Acconsento',
			'policy_version' => 3,
			'extra'          => array(),
		);
	}

	/* --- get_sources ------------------------------------------------------- */

	public function test_scarta_le_fonti_incomplete(): void {
		$this->declare_sources(
			array(
				'ok'          => $this->source( array() ),
				'senza_label' => array( 'query' => '__return_true' ),
				'senza_query' => array( 'label' => 'X' ),
				'non_array'   => 'x',
			)
		);

		$sources = DBPH_Consents_Register::get_sources();

		$this->assertSame( array( 'ok' ), array_keys( $sources ) );
		$this->assertArrayHasKey( 'icon', $sources['ok'] );
		$this->assertNull( $sources['ok']['export'] );
	}

	public function test_chiavi_sanitizzate(): void {
		$this->declare_sources( array( 'Mio Plugin!' => $this->source( array() ) ) );
		$this->assertSame( array( 'mioplugin' ), array_keys( DBPH_Consents_Register::get_sources() ) );
	}

	/* --- query ------------------------------------------------------------- */

	public function test_query_all_ordina_e_inietta_la_fonte(): void {
		$this->declare_sources(
			array(
				'cookie' => $this->source( array( $this->consent( 'c1', '2026-10-01 10:00:00' ), $this->consent( 'c2', '2026-10-03 10:00:00' ) ), array( 'label' => 'Cookie' ) ),
				'form'   => $this->source( array( $this->consent( 'f1', '2026-10-02 10:00:00' ) ), array( 'label' => 'Form' ) ),
			)
		);

		$rows = DBPH_Consents_Register::query_all();

		$this->assertSame( array( 'c2', 'f1', 'c1' ), array_column( $rows, 'id' ) );
		$this->assertSame( array( 'cookie', 'form', 'cookie' ), array_column( $rows, 'source_key' ) );
		$this->assertSame( 'Form', $rows[1]['source_label'] );
		$this->assertSame( 3, DBPH_Consents_Register::count_all() );
	}

	public function test_query_all_propaga_e_rispetta_il_limite(): void {
		$seen = array();
		$this->declare_sources(
			array(
				'a' => array(
					'label' => 'A',
					'query' => function ( $args ) use ( &$seen ) {
						$seen = $args;
						return array();
					},
				),
				'b' => $this->source(
					array(
						$this->consent( 'b1', '2026-10-01' ),
						$this->consent( 'b2', '2026-10-02' ),
						$this->consent( 'b3', '2026-10-03' ),
					)
				),
			)
		);

		$rows = DBPH_Consents_Register::query_all( array(), 2 );

		$this->assertCount( 2, $rows );
		$this->assertSame( 2, $seen['limit'] );
		$this->assertSame( 2, $seen['_internal_limit'] );
	}

	public function test_query_all_su_una_sola_fonte(): void {
		$this->declare_sources(
			array(
				'a' => $this->source( array( $this->consent( 'a1', '2026-10-01' ) ) ),
				'b' => $this->source( array( $this->consent( 'b1', '2026-10-02' ) ) ),
			)
		);

		$this->assertSame( array( 'a1' ), array_column( DBPH_Consents_Register::query_all( array( 'source' => 'a' ) ), 'id' ) );
	}

	public function test_fonte_inesistente(): void {
		$this->assertSame( 0, DBPH_Consents_Register::count_for( 'nessuna' ) );
		$this->assertSame( array(), DBPH_Consents_Register::query_for( 'nessuna' ) );
		$this->assertSame( array(), DBPH_Consents_Register::query_all() );
	}

	public function test_righe_oggetto_convertite_in_array(): void {
		$this->declare_sources( array( 'obj' => $this->source( array( (object) $this->consent( 'o1', '2026-10-01' ) ) ) ) );

		$row = DBPH_Consents_Register::query_for( 'obj' )[0];

		$this->assertIsArray( $row );
		$this->assertSame( 'o1', $row['id'] );
		$this->assertSame( 'obj', $row['source_key'] );
	}

	/* --- Bug 11: righe malformate ------------------------------------------ */

	public function test_righe_malformate_normalizzate(): void {
		$this->declare_sources(
			array(
				'rotta' => $this->source(
					array(
						array(
							'id'             => 7,
							'timestamp'      => '2026-10-01 10:00:00',
							'subject'        => array( 'non', 'scalare' ),
							'consent_type'   => null,
							'consent_text'   => array( 'testo' => 'x' ),
							'policy_version' => 'abc',
							'extra'          => 'non array',
						),
						'stringa',
						42,
					)
				),
			)
		);

		$rows = DBPH_Consents_Register::query_for( 'rotta' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '7', $rows[0]['id'] );
		$this->assertSame( '', $rows[0]['subject'] );
		$this->assertSame( '', $rows[0]['consent_type'] );
		$this->assertSame( '', $rows[0]['consent_text'] );
		$this->assertSame( 0, $rows[0]['policy_version'] );
		$this->assertSame( array(), $rows[0]['extra'] );
	}

	public function test_policy_version_numerica_in_stringa(): void {
		$this->assertSame( 12, DBPH_Consents_Register::normalize_row( array( 'policy_version' => '12' ) )['policy_version'] );
	}

	public function test_count_non_numerico(): void {
		$this->declare_sources(
			array(
				'x' => $this->source(
					array(),
					array(
						'count' => function () {
							return 'molti';
						},
					)
				),
			)
		);
		$this->assertSame( 0, DBPH_Consents_Register::count_for( 'x' ) );
	}

	/* --- Bug 3: fonti che lanciano ----------------------------------------- */

	public function test_una_fonte_rotta_non_blocca_le_altre(): void {
		$this->declare_sources(
			array(
				'rotta' => array(
					'label' => 'Rotta',
					'count' => function () {
						throw new RuntimeException( 'db giù' );
					},
					'query' => function () {
						throw new TypeError( 'tipo' );
					},
				),
				'sana'  => $this->source( array( $this->consent( 's1', '2026-10-01' ) ) ),
			)
		);

		$this->assertSame( array( 's1' ), array_column( DBPH_Consents_Register::query_all(), 'id' ) );
		$this->assertSame( 1, DBPH_Consents_Register::count_all() );
		$this->assertCount( 2, $GLOBALS['__dbph_doing_it_wrong'] );
		$this->assertStringContainsString( 'db giù', $GLOBALS['__dbph_doing_it_wrong'][1][1] );
	}
}
