<?php
/**
 * Uninstall — DB Privacy Hub
 *
 * Eseguito quando l'utente disinstalla (NON disattiva) il plugin. Rimuove
 * tutte le option e le tabelle create dal plugin.
 *
 * NOTA: NON rimuove la pagina WordPress della Privacy Policy se è stata
 * pubblicata: l'admin potrebbe averla aggiornata a mano e contiene contenuto
 * che vuole conservare. La pagina può essere cancellata manualmente.
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/* -------------------------------------------------------------------------
 * Tabelle custom (1.1.0+)
 * ---------------------------------------------------------------------- */
$tables = array(
	$wpdb->prefix . 'dbph_dsar_log',
	$wpdb->prefix . 'dbph_policy_archive',
);
foreach ( $tables as $tbl ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$tbl}`" );
}

/* -------------------------------------------------------------------------
 * Opzioni
 * ---------------------------------------------------------------------- */
$options = array(
	'dbph_version',
	'dbph_titolare_nome',
	'dbph_titolare_piva',
	'dbph_titolare_indirizzo',
	'dbph_titolare_email',
	'dbph_titolare_pec',
	'dbph_titolare_dpo',
	'dbph_page_title',
	'dbph_page_slug',
	'dbph_page_id',
	'dbph_responsabili',
	'dbph_dsar_log_schema',
	'dbph_policy_archive_schema',
	// 1.2.0: nuova option toggle istruzioni operative diritti.
	'dbph_show_rights_howto',
	// 1.3.0: cache ID versione Privacy Policy corrente
	'dbph_policy_current_version',
);

foreach ( $options as $opt ) {
	delete_option( $opt );
}

// 1.2.0: pulisci anche eventuali cron schedulati.
$ts = wp_next_scheduled( 'dbph_dsar_cleanup_pending' );
if ( $ts ) {
	wp_unschedule_event( $ts, 'dbph_dsar_cleanup_pending' );
}
