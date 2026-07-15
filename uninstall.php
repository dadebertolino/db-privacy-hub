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

/* -------------------------------------------------------------------------
 * Conservazione dati (1.3.1)
 *
 * Il log DSAR e l'archivio policy sono dati di accountability (art. 5.2
 * GDPR): se l'admin ha attivato "Conserva i dati alla disinstallazione"
 * nelle impostazioni, non rimuoviamo nulla. Alla reinstallazione del plugin
 * tabelle e impostazioni vengono ritrovate intatte.
 * ---------------------------------------------------------------------- */
if ( get_option( 'dbph_preserve_data_on_uninstall' ) === '1' ) {
	return;
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
	// 1.3.1: toggle conservazione dati alla disinstallazione
	'dbph_preserve_data_on_uninstall',
	// 1.6.0: piattaforme embed manuali + contitolarità pagine social
	'dbph_embed_manual',
	'dbph_social_pages_mention',
);

foreach ( $options as $opt ) {
	delete_option( $opt );
}

// 1.6.0: transient cache scansione embed.
delete_transient( 'dbph_embed_scan' );

// 1.2.0: pulisci anche eventuali cron schedulati.
$ts = wp_next_scheduled( 'dbph_dsar_cleanup_pending' );
if ( $ts ) {
	wp_unschedule_event( $ts, 'dbph_dsar_cleanup_pending' );
}
