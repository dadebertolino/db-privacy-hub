<?php
/**
 * Log DSAR (DBPH_DSAR_Log): scadenza del termine di risposta (bug 6 e 17),
 * mascheramento e hash dell'email (bug 13), vocabolari di tipi/stati/canali.
 *
 * @package DBPH\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class DsarLogTest extends TestCase {

	/** @var string */
	private $php_tz;

	protected function set_up() {
		parent::set_up();
		dbph_test_reset();
		update_option( 'timezone_string', 'Europe/Rome' );
		$this->php_tz = date_default_timezone_get();
	}

	protected function tear_down() {
		date_default_timezone_set( $this->php_tz );
		parent::tear_down();
	}

	/**
	 * Riga di log con requested_at (ora locale del sito) a $seconds_ago
	 * secondi da adesso.
	 */
	private function row( $seconds_ago, $status = 'received' ) {
		return (object) array(
			'status'       => $status,
			'requested_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $seconds_ago ),
		);
	}

	/* --- calculate_deadline ------------------------------------------------ */

	public function test_richiesta_appena_ricevuta(): void {
		$d = DBPH_DSAR_Log::calculate_deadline( $this->row( 60 ) );

		$this->assertSame( 'ok', $d['class'] );
		$this->assertSame( 29, $d['days'] );
	}

	public function test_in_scadenza_sotto_i_dieci_giorni(): void {
		$d = DBPH_DSAR_Log::calculate_deadline( $this->row( 21 * DAY_IN_SECONDS ) );

		$this->assertSame( 'due_soon', $d['class'] );
		$this->assertSame( 9, $d['days'] );
		$this->assertSame( '9 giorni', $d['label'] );
	}

	public function test_a_dieci_giorni_e_mezzo_non_e_ancora_in_scadenza(): void {
		// Con il vecchio round() 10,5 giorni diventavano "10 → in scadenza",
		// mentre il contatore SQL non la contava.
		$d = DBPH_DSAR_Log::calculate_deadline( $this->row( 19 * DAY_IN_SECONDS + 12 * HOUR_IN_SECONDS ) );

		$this->assertSame( 'ok', $d['class'] );
		$this->assertSame( 10, $d['days'] );
	}

	/**
	 * Bordo dei 30 giorni: il badge deve coincidere con il contatore SQL
	 * di get_stats() (scaduta se requested_at < adesso − 30 giorni).
	 *
	 * @dataProvider provide_bordo_trenta_giorni
	 */
	public function test_bordo_dei_trenta_giorni( $seconds_ago, $class, $days ): void {
		$d = DBPH_DSAR_Log::calculate_deadline( $this->row( $seconds_ago ) );

		$this->assertSame( $class, $d['class'] );
		$this->assertSame( $days, $d['days'] );
	}

	public function provide_bordo_trenta_giorni() {
		return array(
			'29 giorni'            => array( 29 * DAY_IN_SECONDS, 'due_soon', 1 ),
			'30 giorni meno 1 ora' => array( 30 * DAY_IN_SECONDS - HOUR_IN_SECONDS, 'due_soon', 0 ),
			'30 giorni più 1 ora'  => array( 30 * DAY_IN_SECONDS + HOUR_IN_SECONDS, 'overdue', -1 ),
			'31 giorni'            => array( 31 * DAY_IN_SECONDS, 'overdue', -1 ),
			'40 giorni e mezzo'    => array( 40 * DAY_IN_SECONDS + 12 * HOUR_IN_SECONDS, 'overdue', -11 ),
		);
	}

	public function test_etichetta_scaduta(): void {
		$d = DBPH_DSAR_Log::calculate_deadline( $this->row( 35 * DAY_IN_SECONDS ) );
		$this->assertSame( 'Scaduta (+5 gg)', $d['label'] );
	}

	public function test_indipendente_dal_fuso_di_default_di_php(): void {
		$expected = DBPH_DSAR_Log::calculate_deadline( $this->row( 25 * DAY_IN_SECONDS ) );

		date_default_timezone_set( 'America/New_York' );

		$this->assertSame( $expected, DBPH_DSAR_Log::calculate_deadline( $this->row( 25 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * @dataProvider provide_stati_chiusi
	 */
	public function test_stati_chiusi_senza_scadenza( $status ): void {
		$this->assertSame( '', DBPH_DSAR_Log::calculate_deadline( $this->row( 40 * DAY_IN_SECONDS, $status ) )['class'] );
	}

	public function provide_stati_chiusi() {
		return array( array( 'completed' ), array( 'partial' ), array( 'rejected' ), array( 'expired' ) );
	}

	public function test_riga_assente_o_data_non_valida(): void {
		$this->assertSame( '', DBPH_DSAR_Log::calculate_deadline( null )['class'] );
		$this->assertSame( '', DBPH_DSAR_Log::calculate_deadline( (object) array( 'status' => 'received', 'requested_at' => '' ) )['class'] );
		$this->assertSame( '', DBPH_DSAR_Log::calculate_deadline( (object) array( 'status' => 'received', 'requested_at' => 'ieri' ) )['class'] );
	}

	/* --- mask_email / hash_email (bug 13) --------------------------------- */

	/**
	 * @dataProvider provide_email
	 */
	public function test_mask_email( $email, $masked ): void {
		$this->assertSame( $masked, dbph_test_call_private( 'DBPH_DSAR_Log', 'mask_email', array( $email ) ) );
	}

	public function provide_email() {
		return array(
			'normale'          => array( 'mario.rossi@example.com', 'm*********i@example.com' ),
			'tre caratteri'    => array( 'abc@x.it', 'a*c@x.it' ),
			'due caratteri'    => array( 'ab@x.it', '**@x.it' ),
			'un carattere'     => array( 'a@x.it', '*@x.it' ),
			'multibyte'        => array( 'élodie@exemple.fr', 'é****e@exemple.fr' ),
			'multibyte corta'  => array( 'ñü@x.es', '**@x.es' ),
			'cirillico'        => array( 'иван@почта.рф', 'и**н@почта.рф' ),
			'spazi'            => array( '  luca@x.it ', 'l**a@x.it' ),
			'senza chiocciola' => array( 'non-email', '***' ),
			'chiocciola prima' => array( '@x.it', '***' ),
		);
	}

	public function test_mask_email_e_utf8_valido(): void {
		$masked = dbph_test_call_private( 'DBPH_DSAR_Log', 'mask_email', array( 'ǅemal@x.hr' ) );
		$this->assertTrue( mb_check_encoding( $masked, 'UTF-8' ) );
	}

	public function test_hash_email_normalizza_maiuscole_e_spazi(): void {
		$a = dbph_test_call_private( 'DBPH_DSAR_Log', 'hash_email', array( 'Mario@Example.com ' ) );
		$b = dbph_test_call_private( 'DBPH_DSAR_Log', 'hash_email', array( 'mario@example.com' ) );
		$c = dbph_test_call_private( 'DBPH_DSAR_Log', 'hash_email', array( 'luigi@example.com' ) );

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
	}

	/* --- Vocabolari -------------------------------------------------------- */

	public function test_ogni_tipo_valido_ha_un_etichetta(): void {
		$this->assertEqualsCanonicalizing( DBPH_DSAR_Log::get_valid_types(), array_keys( DBPH_DSAR_Log::get_type_labels() ) );
	}

	public function test_tipi_dei_diritti_da_15_a_22(): void {
		$labels = implode( ' ', DBPH_DSAR_Log::get_type_labels() );
		foreach ( array( '15', '16', '17', '18', '20', '21', '22' ) as $art ) {
			$this->assertStringContainsString( "art. {$art} GDPR", $labels );
		}
	}

	public function test_normalize_type_dal_vocabolario_wordpress(): void {
		$this->assertSame( 'export', dbph_test_call_private( 'DBPH_DSAR_Log', 'normalize_type', array( 'export_personal_data' ) ) );
		$this->assertSame( 'erase', dbph_test_call_private( 'DBPH_DSAR_Log', 'normalize_type', array( 'remove_personal_data' ) ) );
	}

	public function test_stati_e_canali(): void {
		$this->assertSame(
			array( 'pending', 'confirmed', 'received', 'in_progress', 'completed', 'partial', 'rejected', 'expired' ),
			array_keys( DBPH_DSAR_Log::get_status_labels() )
		);
		$this->assertSame( array( 'email', 'pec', 'mail', 'phone', 'other' ), array_keys( DBPH_DSAR_Log::get_channel_labels() ) );
	}
}
