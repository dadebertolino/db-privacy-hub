<?php
/**
 * Bug 1 — data di richiesta DSAR alla conferma.
 *
 * Il termine GDPR di risposta (art. 12.3) decorre dal ricevimento della
 * richiesta, non dalla conferma dell'interessato. Se alla conferma la riga
 * del log non esiste ancora (richieste create prima dell'attivazione del
 * plugin o dell'aggiornamento alla 1.7.0), on_request_confirmed() la crea:
 * `requested_at` deve essere la data di creazione della WP_User_Request.
 *
 * @package DBPH\Tests\Integration
 */

class DsarRequestedAtIntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// Fuso diverso da UTC: requested_at è in ora locale del sito.
		update_option( 'timezone_string', 'Europe/Rome' );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME );
	}

	/**
	 * Crea una richiesta di export retrodatata e, se richiesto, rimuove la
	 * riga di log creata da on_request_created() (come per le richieste
	 * nate prima della 1.7.0).
	 *
	 * @param int  $days_ago
	 * @param bool $drop_log_row
	 * @return int ID della WP_User_Request.
	 */
	private function make_request( $days_ago, $drop_log_row ) {
		global $wpdb;

		$request_id = wp_create_user_request( 'interessato@example.com', 'export_personal_data' );
		$this->assertIsInt( $request_id );

		$created_gmt = gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date_gmt' => $created_gmt,
				'post_date'     => get_date_from_gmt( $created_gmt ),
			),
			array( 'ID' => $request_id )
		);
		clean_post_cache( $request_id );

		if ( $drop_log_row ) {
			$wpdb->delete( $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME, array( 'request_id' => $request_id ) );
		}
		return $request_id;
	}

	private function log_row( $request_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME . ' WHERE request_id = %d',
				$request_id
			)
		);
	}

	private function expected_requested_at( $request_id ) {
		$request = wp_get_user_request( $request_id );
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $request->created_timestamp ) );
	}

	public function test_conferma_senza_riga_usa_la_data_di_creazione(): void {
		$request_id = $this->make_request( 5, true );
		$this->assertNull( $this->log_row( $request_id ) );

		do_action( 'user_request_action_confirmed', $request_id );

		$row = $this->log_row( $request_id );
		$this->assertNotNull( $row );
		$this->assertSame( 'confirmed', $row->status );
		$this->assertSame( $this->expected_requested_at( $request_id ), $row->requested_at );
		// La conferma resta "adesso": è un dato diverso dalla richiesta.
		$this->assertNotSame( $row->requested_at, $row->confirmed_at );
	}

	public function test_conferma_con_riga_esistente_non_sposta_la_data_di_richiesta(): void {
		$request_id = $this->make_request( 5, false );
		global $wpdb;
		// La riga creata alla creazione ha la data reale: la allineiamo a
		// quella retrodatata, come se fosse stata creata 5 giorni fa.
		$wpdb->update(
			$wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME,
			array( 'requested_at' => $this->expected_requested_at( $request_id ) ),
			array( 'request_id' => $request_id )
		);

		do_action( 'user_request_action_confirmed', $request_id );

		$row = $this->log_row( $request_id );
		$this->assertSame( 'confirmed', $row->status );
		$this->assertSame( $this->expected_requested_at( $request_id ), $row->requested_at );
	}

	public function test_la_scadenza_decorre_dalla_richiesta(): void {
		$request_id = $this->make_request( 25, true );

		do_action( 'user_request_action_confirmed', $request_id );

		$deadline = DBPH_DSAR_Log::calculate_deadline( $this->log_row( $request_id ) );
		// Richiesta di 25 giorni fa: ne restano circa 5, non 30.
		$this->assertSame( 'due_soon', $deadline['class'] );
		$this->assertLessThanOrEqual( 6, $deadline['days'] );
	}
}
