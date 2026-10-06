<?php
/**
 * Log DSAR su MySQL: richieste manuali (anche artt. 16–22), statistiche del
 * cruscotto (bug 5) coerenti con i badge di scadenza (bug 6, 19) e
 * conservazione limitata.
 *
 * @package DBPH\Tests\Integration
 */

class DsarManualStatsIntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME );
		delete_option( DBPH_DSAR_Log::RETENTION_OPTION );
	}

	/**
	 * Inserisce una richiesta manuale con requested_at a $seconds_ago secondi
	 * da adesso (ora locale del sito).
	 */
	private function manual( $type, $status, $seconds_ago = 0, array $extra = array() ) {
		$id = DBPH_DSAR_Log::insert_manual(
			array_merge(
				array(
					'request_type' => $type,
					'email'        => $type . '-' . $status . '-' . wp_rand() . '@example.com',
					'status'       => $status,
					'requested_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $seconds_ago ),
				),
				$extra
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/* --- Richieste manuali ------------------------------------------------- */

	/**
	 * @dataProvider provide_tipi
	 */
	public function test_ogni_tipo_valido_si_registra( $type ): void {
		$id  = $this->manual( $type, 'received' );
		$row = DBPH_DSAR_Log::get_by_id( $id );

		$this->assertSame( $type, $row->request_type );
		$this->assertSame( 'manual', $row->source );
		$this->assertSame( '0', $row->request_id );
	}

	public function provide_tipi() {
		return array_map(
			function ( $type ) {
				return array( $type );
			},
			array( 'export', 'rectify', 'erase', 'restrict', 'portability', 'object', 'automated', 'consent_revoke' )
		);
	}

	public function test_dati_non_validi(): void {
		$this->assertWPError( DBPH_DSAR_Log::insert_manual( array( 'request_type' => 'inventato', 'email' => 'a@b.it' ) ) );
		$this->assertWPError( DBPH_DSAR_Log::insert_manual( array( 'request_type' => 'export' ) ) );

		$id = $this->manual( 'export', 'stato-inventato' );
		$this->assertSame( 'received', DBPH_DSAR_Log::get_by_id( $id )->status );
	}

	public function test_modifica_di_una_richiesta_manuale(): void {
		$id = $this->manual( 'rectify', 'received' );

		$this->assertSame(
			$id,
			DBPH_DSAR_Log::update_manual(
				$id,
				array(
					'status'       => 'completed',
					'completed_at' => '2026-10-01 12:00:00',
					'notes'        => '<script>x</script>Rettificato l\'indirizzo.',
				)
			)
		);

		$row = DBPH_DSAR_Log::get_by_id( $id );
		$this->assertSame( 'completed', $row->status );
		$this->assertSame( '2026-10-01 12:00:00', $row->completed_at );
		$this->assertStringNotContainsString( '<script>', $row->notes );

		DBPH_DSAR_Log::update_manual( $id, array( 'status' => 'inventato', 'completed_at' => null ) );
		$row = DBPH_DSAR_Log::get_by_id( $id );
		$this->assertSame( 'completed', $row->status );
		$this->assertNull( $row->completed_at );
	}

	public function test_le_richieste_native_non_si_modificano_ne_si_eliminano(): void {
		$request_id = wp_create_user_request( 'nativa@example.com', 'export_personal_data' );
		global $wpdb;
		$row_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME . ' WHERE request_id = %d', $request_id ) );

		$this->assertWPError( DBPH_DSAR_Log::update_manual( $row_id, array( 'status' => 'completed' ) ) );
		$this->assertWPError( DBPH_DSAR_Log::delete_manual( $row_id ) );
		$this->assertSame( 'pending', DBPH_DSAR_Log::get_by_id( $row_id )->status );
	}

	public function test_eliminazione_di_una_richiesta_manuale(): void {
		$id = $this->manual( 'object', 'received' );

		$this->assertTrue( DBPH_DSAR_Log::delete_manual( $id ) );
		$this->assertNull( DBPH_DSAR_Log::get_by_id( $id ) );
		$this->assertWPError( DBPH_DSAR_Log::delete_manual( $id ) );
	}

	/* --- Statistiche (bug 5) ----------------------------------------------- */

	public function test_statistiche_per_stato_e_tipo(): void {
		$this->manual( 'export', 'received' );
		$this->manual( 'export', 'completed' );
		$this->manual( 'export', 'expired' );
		$this->manual( 'erase', 'in_progress' );
		$this->manual( 'erase', 'completed' );
		$this->manual( 'erase', 'partial' );
		$this->manual( 'erase', 'rejected' );
		$this->manual( 'rectify', 'received' );
		$this->manual( 'object', 'completed' );

		$stats = DBPH_DSAR_Log::get_stats();

		$this->assertSame( 9, $stats['total'] );
		$this->assertSame( 1, $stats['export_pending'] );
		$this->assertSame( 1, $stats['export_done'] );
		// 'partial' è evasa (prima contava come pendente).
		$this->assertSame( 1, $stats['erase_pending'] );
		$this->assertSame( 2, $stats['erase_done'] );
		// Aperte/evase/chiuse su tutti i tipi, anche artt. 16–22.
		$this->assertSame( 3, $stats['open'] );
		$this->assertSame( 4, $stats['done'] );
		$this->assertSame( 2, $stats['closed'] );
		$this->assertSame( 9, $stats['manual'] );
	}

	/**
	 * I contatori SQL e i badge calcolati in PHP devono coincidere riga per
	 * riga, anche ai bordi del termine (bug 6).
	 */
	public function test_contatori_e_badge_coincidono(): void {
		$ages = array(
			HOUR_IN_SECONDS,
			15 * DAY_IN_SECONDS,
			19 * DAY_IN_SECONDS + 12 * HOUR_IN_SECONDS,
			25 * DAY_IN_SECONDS,
			28 * DAY_IN_SECONDS,
			29 * DAY_IN_SECONDS,
			31 * DAY_IN_SECONDS + HOUR_IN_SECONDS,
			32 * DAY_IN_SECONDS,
			45 * DAY_IN_SECONDS,
		);
		foreach ( $ages as $age ) {
			$this->manual( 'erase', 'received', $age );
		}
		// Le chiuse non contano mai.
		$this->manual( 'erase', 'completed', 45 * DAY_IN_SECONDS );
		$this->manual( 'erase', 'expired', 45 * DAY_IN_SECONDS );

		$classes = array(
			'overdue'  => 0,
			'due_soon' => 0,
		);
		foreach ( DBPH_DSAR_Log::get_entries( 100 ) as $row ) {
			$class = DBPH_DSAR_Log::calculate_deadline( $row )['class'];
			if ( isset( $classes[ $class ] ) ) {
				++$classes[ $class ];
			}
		}

		$stats = DBPH_DSAR_Log::get_stats();
		$this->assertSame( $classes['overdue'], $stats['overdue'] );
		$this->assertSame( $classes['due_soon'], $stats['due_soon'] );
		$this->assertGreaterThan( 0, $stats['overdue'] );
		$this->assertGreaterThan( 0, $stats['due_soon'] );
	}

	/* --- Conservazione ----------------------------------------------------- */

	public function test_retention_elimina_solo_le_chiuse_oltre_il_periodo(): void {
		$six_years  = 6 * YEAR_IN_SECONDS;
		$old_done   = $this->manual( 'erase', 'completed', $six_years, array( 'completed_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $six_years ) ) );
		$old_reject = $this->manual( 'export', 'rejected', $six_years );
		$old_open   = $this->manual( 'export', 'received', $six_years );
		// Richiesta vecchia ma completata da poco: si conta dal completamento.
		$late_done  = $this->manual( 'erase', 'completed', $six_years, array( 'completed_at' => current_time( 'mysql' ) ) );
		$recent     = $this->manual( 'export', 'completed', DAY_IN_SECONDS );

		$this->assertSame( 2, DBPH_DSAR_Log::purge_expired_rows() );

		$this->assertNull( DBPH_DSAR_Log::get_by_id( $old_done ) );
		$this->assertNull( DBPH_DSAR_Log::get_by_id( $old_reject ) );
		$this->assertNotNull( DBPH_DSAR_Log::get_by_id( $old_open ) );
		$this->assertNotNull( DBPH_DSAR_Log::get_by_id( $late_done ) );
		$this->assertNotNull( DBPH_DSAR_Log::get_by_id( $recent ) );
	}

	public function test_retention_zero_conserva_tutto(): void {
		update_option( DBPH_DSAR_Log::RETENTION_OPTION, 0 );
		$id = $this->manual( 'erase', 'completed', 30 * YEAR_IN_SECONDS );

		$this->assertSame( 0, DBPH_DSAR_Log::purge_expired_rows() );
		$this->assertNotNull( DBPH_DSAR_Log::get_by_id( $id ) );
	}

	public function test_la_retention_gira_con_il_cron_giornaliero(): void {
		$this->assertNotFalse( has_action( 'dbph_dsar_cleanup_pending', array( 'DBPH_DSAR_Log', 'purge_expired_rows' ) ) );

		update_option( DBPH_DSAR_Log::RETENTION_OPTION, 1 );
		$id = $this->manual( 'export', 'completed', 2 * YEAR_IN_SECONDS );

		do_action( 'dbph_dsar_cleanup_pending' );

		$this->assertNull( DBPH_DSAR_Log::get_by_id( $id ) );
	}
}
