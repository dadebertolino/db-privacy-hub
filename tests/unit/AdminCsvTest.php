<?php
/**
 * Export CSV dell'admin: protezione da formula injection (csv_row) e date
 * dei filtri (sanitize_ymd). Le celle contengono dati inseriti dai
 * visitatori (identificativi, testi di consenso).
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class AdminCsvTest extends TestCase {

	private function csv_row( array $row ) {
		return dbph_test_call_private( 'DBPH_Admin', 'csv_row', array( $row ) );
	}

	/**
	 * @dataProvider provide_formule
	 */
	public function test_le_formule_vengono_neutralizzate( $cell ): void {
		$this->assertSame( array( "'" . $cell ), $this->csv_row( array( $cell ) ) );
	}

	public function provide_formule() {
		return array(
			'uguale'      => array( '=HYPERLINK("http://x")' ),
			'piu'         => array( '+1+1' ),
			'meno'        => array( '-2+3' ),
			'chiocciola'  => array( '@SUM(A1)' ),
			'tab'         => array( "\t=1" ),
			'ritorno'     => array( "\r=1" ),
		);
	}

	public function test_valori_innocui_invariati(): void {
		$row = array( 'mario@x.it', 'Testo = normale', '', 42, null, 3.5 );
		$this->assertSame( $row, $this->csv_row( $row ) );
	}

	public function test_mantiene_le_chiavi(): void {
		$this->assertSame(
			array(
				'a' => 'ok',
				'b' => "'=x",
			),
			$this->csv_row(
				array(
					'a' => 'ok',
					'b' => '=x',
				)
			)
		);
	}

	/**
	 * @dataProvider provide_date
	 */
	public function test_sanitize_ymd( $raw, $expected ): void {
		$this->assertSame( $expected, dbph_test_call_private( 'DBPH_Admin', 'sanitize_ymd', array( $raw ) ) );
	}

	public function provide_date() {
		return array(
			'valida'        => array( '2026-10-06', '2026-10-06' ),
			'con spazi'     => array( ' 2026-10-06 ', '2026-10-06' ),
			'formato it'    => array( '06/10/2026', '' ),
			'con orario'    => array( '2026-10-06 10:00', '' ),
			'iniezione'     => array( "2026-10-06' OR 1=1", '' ),
			'vuota'         => array( '', '' ),
			'null'          => array( null, '' ),
		);
	}
}
