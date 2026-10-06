<?php
/**
 * Archivio policy: confronto delle versioni (normalize_for_compare). Due
 * generazioni che differiscono solo per la data o per gli spazi non devono
 * produrre una nuova versione.
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class PolicyArchiveTest extends TestCase {

	public function test_ignora_la_data_di_generazione(): void {
		$a = '<p>Aggiornata il <span class="dbph-date">01/01/2026</span></p>';
		$b = '<p>Aggiornata il <span class="dbph-date">06/10/2026</span></p>';

		$this->assertSame( DBPH_Policy_Archive::normalize_for_compare( $a ), DBPH_Policy_Archive::normalize_for_compare( $b ) );
	}

	public function test_ignora_spazi_e_a_capo(): void {
		$this->assertSame(
			'<p>Uno due</p> <p>tre</p>',
			DBPH_Policy_Archive::normalize_for_compare( "  <p>Uno   due</p>\n\n\t<p>tre</p> " )
		);
	}

	public function test_un_cambio_di_testo_resta_visibile(): void {
		$this->assertNotSame(
			DBPH_Policy_Archive::normalize_for_compare( '<p>Conservazione 12 mesi</p>' ),
			DBPH_Policy_Archive::normalize_for_compare( '<p>Conservazione 24 mesi</p>' )
		);
	}

	public function test_valori_non_stringa(): void {
		$this->assertSame( '', DBPH_Policy_Archive::normalize_for_compare( null ) );
		$this->assertSame( '42', DBPH_Policy_Archive::normalize_for_compare( 42 ) );
	}
}
