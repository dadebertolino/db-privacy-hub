<?php
/**
 * Ciclo di vita di una richiesta DSAR negli strumenti privacy di WordPress,
 * con le funzioni del core: creazione → email di conferma → conferma →
 * export / cancellazione → scadenza dei pending.
 *
 * @package DBPH\Tests\Integration
 */

class DsarLifecycleIntegrationTest extends WP_UnitTestCase {

	const EMAIL = 'interessato@example.com';

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME );
		reset_phpmailer_instance();

		// Le funzioni del flusso di cancellazione stanno in wp-admin: le
		// carichiamo e agganciamo come fa l'admin.
		require_once ABSPATH . 'wp-admin/includes/privacy-tools.php';
		if ( false === has_filter( 'wp_privacy_personal_data_erasure_page', 'wp_privacy_process_personal_data_erasure_page' ) ) {
			add_filter( 'wp_privacy_personal_data_erasure_page', 'wp_privacy_process_personal_data_erasure_page', 10, 5 );
		}
	}

	private function row( $request_id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME . ' WHERE request_id = %d', $request_id )
		);
	}

	private function row_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME );
	}

	/**
	 * Dichiara un eraser via dbph_user_data_erasers.
	 */
	private function declare_eraser( $key, array $response ) {
		add_filter(
			'dbph_user_data_erasers',
			function ( $erasers ) use ( $key, $response ) {
				$erasers[ $key ] = array(
					'label'    => $key,
					'callback' => function () use ( $response ) {
						return $response;
					},
				);
				return $erasers;
			}
		);
	}

	/**
	 * Esegue la cancellazione come fa l'AJAX degli strumenti privacy: ogni
	 * eraser, pagina 1, risposta passata al filtro di pagina (che al termine
	 * dell'ultimo eraser completa la richiesta ed emette
	 * wp_privacy_personal_data_erased).
	 */
	private function run_erasure( $request_id ) {
		$erasers = array_values( apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
		foreach ( $erasers as $i => $eraser ) {
			$response = call_user_func( $eraser['callback'], self::EMAIL, 1 );
			apply_filters( 'wp_privacy_personal_data_erasure_page', $response, $i + 1, self::EMAIL, 1, $request_id );
		}
	}

	/* --------------------------------------------------------------------- */

	public function test_la_creazione_registra_una_richiesta_pending(): void {
		$request_id = wp_create_user_request( self::EMAIL, 'export_personal_data' );

		$row = $this->row( $request_id );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( 'export', $row->request_type );
		$this->assertSame( 'wp_native', $row->source );
		$this->assertSame( 'i*********o@example.com', $row->email_display );
		$this->assertStringNotContainsString( self::EMAIL, implode( '|', (array) $row ) );
	}

	public function test_richiesta_creata_gia_confermata(): void {
		$request_id = wp_create_user_request( self::EMAIL, 'remove_personal_data', array(), 'confirmed' );

		$row = $this->row( $request_id );
		$this->assertSame( 'confirmed', $row->status );
		$this->assertNotNull( $row->confirmed_at );
	}

	public function test_l_email_di_conferma_non_duplica_la_riga(): void {
		$request_id = wp_create_user_request( self::EMAIL, 'export_personal_data' );

		$this->assertTrue( wp_send_user_request( $request_id ) );
		$this->assertTrue( wp_send_user_request( $request_id ) );

		$this->assertSame( 1, $this->row_count() );
		$this->assertSame( 'pending', $this->row( $request_id )->status );
	}

	public function test_conferma_ed_export_completato(): void {
		$request_id = wp_create_user_request( self::EMAIL, 'export_personal_data' );

		do_action( 'user_request_action_confirmed', $request_id );
		$this->assertSame( 'confirmed', $this->row( $request_id )->status );

		// Il core, su questo evento, genera lo ZIP: qui interessa solo il log.
		remove_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file', 10 );
		do_action( 'wp_privacy_personal_data_export_file', $request_id );

		$row = $this->row( $request_id );
		$this->assertSame( 'completed', $row->status );
		$this->assertNotNull( $row->completed_at );
		$this->assertGreaterThan( 0, (int) $row->exporters_count );
	}

	public function test_cancellazione_completa(): void {
		$this->declare_eraser(
			'e2e-ok',
			array(
				'items_removed'  => true,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			)
		);
		$request_id = wp_create_user_request( self::EMAIL, 'remove_personal_data', array(), 'confirmed' );

		$this->run_erasure( $request_id );

		$row = $this->row( $request_id );
		$this->assertSame( 'completed', $row->status );
		$this->assertSame( '1', $row->items_removed );
		$this->assertSame( '0', $row->items_retained );
		$this->assertSame( 'request-completed', get_post_status( $request_id ) );
	}

	public function test_cancellazione_con_dati_trattenuti_e_parziale(): void {
		$this->declare_eraser(
			'e2e-fiscale',
			array(
				'items_removed'  => true,
				'items_retained' => true,
				'messages'       => array( 'Dati <b>fiscali</b> conservati 10 anni.' ),
				'done'           => true,
			)
		);
		$request_id = wp_create_user_request( self::EMAIL, 'remove_personal_data', array(), 'confirmed' );

		$this->run_erasure( $request_id );

		$row = $this->row( $request_id );
		$this->assertSame( 'partial', $row->status );
		$this->assertSame( '1', $row->items_retained );
		$this->assertStringContainsString( '• Dati fiscali conservati 10 anni.', $row->notes );
		$this->assertGreaterThan( 0, (int) $row->erasers_count );
	}

	public function test_una_nuova_esecuzione_azzera_l_esito_precedente(): void {
		$request_id = wp_create_user_request( self::EMAIL, 'remove_personal_data', array(), 'confirmed' );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME,
			array(
				'items_retained' => 1,
				'notes'          => 'vecchio',
			),
			array( 'request_id' => $request_id )
		);

		$this->run_erasure( $request_id );

		$row = $this->row( $request_id );
		$this->assertSame( '0', $row->items_retained );
		$this->assertStringNotContainsString( 'vecchio', (string) $row->notes );
		$this->assertSame( 'completed', $row->status );
	}

	public function test_eraser_che_lancia_rende_la_cancellazione_parziale(): void {
		add_filter(
			'dbph_user_data_erasers',
			function ( $erasers ) {
				$erasers['e2e-rotto'] = array(
					'label'    => 'Rotto',
					'callback' => function () {
						throw new RuntimeException( 'boom' );
					},
				);
				return $erasers;
			}
		);
		$request_id = wp_create_user_request( self::EMAIL, 'remove_personal_data', array(), 'confirmed' );

		$this->setExpectedIncorrectUsage( 'DBPH_DSAR' );
		$this->run_erasure( $request_id );

		$row = $this->row( $request_id );
		$this->assertSame( 'partial', $row->status );
		$this->assertStringContainsString( '"e2e-rotto"', $row->notes );
	}

	public function test_il_cron_scade_solo_i_pending_vecchi_e_nativi(): void {
		global $wpdb;
		$table = $wpdb->prefix . DBPH_DSAR_Log::TABLE_NAME;

		$old    = wp_create_user_request( 'vecchia@example.com', 'export_personal_data' );
		$recent = wp_create_user_request( 'recente@example.com', 'export_personal_data' );
		$done   = wp_create_user_request( 'evasa@example.com', 'export_personal_data', array(), 'confirmed' );
		$eight_days_ago = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 8 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET requested_at = %s WHERE request_id IN (%d, %d)", $eight_days_ago, $old, $done ) );
		$manual = DBPH_DSAR_Log::insert_manual(
			array(
				'request_type' => 'export',
				'email'        => 'manuale@example.com',
				'status'       => 'received',
				'requested_at' => $eight_days_ago,
			)
		);

		DBPH_DSAR_Log::cron_expire_pending();

		$this->assertSame( 'expired', $this->row( $old )->status );
		$this->assertSame( 'pending', $this->row( $recent )->status );
		$this->assertSame( 'confirmed', $this->row( $done )->status );
		$this->assertSame( 'received', DBPH_DSAR_Log::get_by_id( $manual )->status );
	}

	public function test_gli_exporter_dell_hub_sono_negli_strumenti_privacy(): void {
		add_filter(
			'dbph_user_data_exporters',
			function ( $exporters ) {
				$exporters['e2e-plugin'] = array(
					'label'    => 'E2E Plugin',
					'callback' => function () {
						return array(
							array(
								'group_id'    => 'e2e',
								'group_label' => 'E2E',
								'item_id'     => '1',
								'data'        => array(),
							),
						);
					},
				);
				return $exporters;
			}
		);

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( 'wordpress-user', $exporters );
		$this->assertArrayHasKey( 'e2e-plugin', $exporters );
		$this->setExpectedIncorrectUsage( 'DBPH_DSAR' );
		$response = call_user_func( $exporters['e2e-plugin']['callback'], self::EMAIL, 1 );
		$this->assertTrue( $response['done'] );
		$this->assertCount( 1, $response['data'] );
	}
}
