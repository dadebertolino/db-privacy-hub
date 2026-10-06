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
	 * Riga di log con requested_at (ora locale del sito).
	 */
	private function row( $requested_at, $status = 'received' ) {
		return (object) array(
			'status'       => $status,
			'requested_at' => $requested_at,
		);
	}

	/**
	 * Timestamp "locale nudo", come current_time( 'timestamp' ).
	 */
	private function local_ts( $datetime ) {
		return ( new DateTimeImmutable( $datetime, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}

	private function deadline( $requested_at, $now, $status = 'received' ) {
		return DBPH_DSAR_Log::calculate_deadline( $this->row( $requested_at, $status ), $this->local_ts( $now ) );
	}

	/* --- deadline_for: un mese di calendario (bug 19) --------------------- */

	/**
	 * @dataProvider provide_termini
	 */
	public function test_termine_di_un_mese( $requested, $expected ): void {
		$deadline = DBPH_DSAR_Log::deadline_for( new DateTimeImmutable( $requested, new DateTimeZone( 'UTC' ) ) );
		$this->assertSame( $expected, $deadline->format( 'Y-m-d H:i:s' ) );
	}

	public function provide_termini() {
		return array(
			'stesso giorno'         => array( '2026-03-15 10:30:00', '2026-04-15 10:30:00' ),
			'31 gennaio → febbraio' => array( '2026-01-31 09:00:00', '2026-02-28 09:00:00' ),
			'anno bisestile'        => array( '2028-01-30 09:00:00', '2028-02-29 09:00:00' ),
			'31 marzo → aprile'     => array( '2026-03-31 09:00:00', '2026-04-30 09:00:00' ),
			'dicembre → gennaio'    => array( '2026-12-31 23:00:00', '2027-01-31 23:00:00' ),
			'febbraio: 28 giorni'   => array( '2026-02-01 00:00:00', '2026-03-01 00:00:00' ),
		);
	}

	/* --- calculate_deadline (bug 6, 17, 19) -------------------------------- */

	public function test_richiesta_appena_ricevuta(): void {
		$d = $this->deadline( '2026-03-15 10:00:00', '2026-03-15 10:01:00' );

		$this->assertSame( 'ok', $d['class'] );
		$this->assertSame( 30, $d['days'] );
		$this->assertSame( '30 giorni', $d['label'] );
	}

	public function test_in_scadenza_sotto_i_dieci_giorni(): void {
		$d = $this->deadline( '2026-03-15 10:00:00', '2026-04-05 12:00:00' );

		$this->assertSame( 'due_soon', $d['class'] );
		$this->assertSame( 9, $d['days'] );
		$this->assertSame( '9 giorni', $d['label'] );
	}

	public function test_a_dieci_giorni_e_mezzo_non_e_ancora_in_scadenza(): void {
		// Con il vecchio round() 10,5 giorni diventavano "10 → in scadenza",
		// mentre il contatore SQL non la contava.
		$d = $this->deadline( '2026-03-15 10:00:00', '2026-04-04 22:00:00' );

		$this->assertSame( 'ok', $d['class'] );
		$this->assertSame( 10, $d['days'] );
	}

	/**
	 * Bordo del termine: il badge deve coincidere con il contatore SQL di
	 * get_stats() (scaduta se DATE_ADD(requested_at, INTERVAL 1 MONTH) < adesso).
	 *
	 * @dataProvider provide_bordo_del_termine
	 */
	public function test_bordo_del_termine( $requested, $now, $class, $days ): void {
		$d = $this->deadline( $requested, $now );

		$this->assertSame( $class, $d['class'] );
		$this->assertSame( $days, $d['days'] );
	}

	public function provide_bordo_del_termine() {
		return array(
			'un giorno prima'          => array( '2026-03-15 10:00:00', '2026-04-14 10:00:00', 'due_soon', 1 ),
			'un\'ora prima'            => array( '2026-03-15 10:00:00', '2026-04-15 09:00:00', 'due_soon', 0 ),
			'allo scoccare'            => array( '2026-03-15 10:00:00', '2026-04-15 10:00:00', 'due_soon', 0 ),
			'un\'ora dopo'             => array( '2026-03-15 10:00:00', '2026-04-15 11:00:00', 'overdue', -1 ),
			'dieci giorni e mezzo dopo' => array( '2026-03-15 10:00:00', '2026-04-25 22:00:00', 'overdue', -11 ),
			// 30 giorni dal 31 gennaio cadrebbero il 2 marzo: con il termine
			// di un mese la richiesta è già scaduta il 1° marzo.
			'febbraio: scaduta prima dei 30 giorni' => array( '2026-01-31 09:00:00', '2026-03-01 09:00:00', 'overdue', -1 ),
		);
	}

	public function test_etichetta_scaduta(): void {
		$d = $this->deadline( '2026-03-15 10:00:00', '2026-04-20 09:00:00' );
		$this->assertSame( 'Scaduta (+5 gg)', $d['label'] );
	}

	public function test_senza_now_usa_l_ora_locale_del_sito(): void {
		$requested = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS );
		$this->assertSame( 'ok', DBPH_DSAR_Log::calculate_deadline( $this->row( $requested ) )['class'] );
	}

	public function test_indipendente_dal_fuso_di_default_di_php(): void {
		$expected = $this->deadline( '2026-03-15 10:00:00', '2026-04-08 10:00:00' );

		date_default_timezone_set( 'America/New_York' );

		$this->assertSame( $expected, $this->deadline( '2026-03-15 10:00:00', '2026-04-08 10:00:00' ) );
	}

	/**
	 * @dataProvider provide_stati_chiusi
	 */
	public function test_stati_chiusi_senza_scadenza( $status ): void {
		$this->assertSame( '', $this->deadline( '2026-01-01 10:00:00', '2026-04-01 10:00:00', $status )['class'] );
	}

	public function provide_stati_chiusi() {
		return array( array( 'completed' ), array( 'partial' ), array( 'rejected' ), array( 'expired' ) );
	}

	public function test_riga_assente_o_data_non_valida(): void {
		$this->assertSame( '', DBPH_DSAR_Log::calculate_deadline( null )['class'] );
		$this->assertSame( '', DBPH_DSAR_Log::calculate_deadline( $this->row( '' ) )['class'] );
		$this->assertSame( '', DBPH_DSAR_Log::calculate_deadline( $this->row( 'ieri' ) )['class'] );
	}

	/* --- Retention --------------------------------------------------------- */

	/**
	 * @dataProvider provide_anni
	 */
	public function test_sanitize_retention_years( $value, $expected ): void {
		$this->assertSame( $expected, DBPH_DSAR_Log::sanitize_retention_years( $value ) );
	}

	public function provide_anni() {
		return array(
			'default'       => array( '5', 5 ),
			'zero'          => array( 0, 0 ),
			'negativo'      => array( '-3', 0 ),
			'oltre il max'  => array( 99, 20 ),
			'decimale'      => array( '2.7', 2 ),
			'non numerico'  => array( 'abc', 5 ),
			'vuoto'         => array( '', 5 ),
		);
	}

	public function test_retention_di_default(): void {
		$this->assertSame( 5, DBPH_DSAR_Log::get_retention_years() );
		update_option( DBPH_DSAR_Log::RETENTION_OPTION, '0' );
		$this->assertSame( 0, DBPH_DSAR_Log::get_retention_years() );
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
