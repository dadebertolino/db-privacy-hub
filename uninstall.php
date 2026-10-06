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
 * 1.8.0: in multisite la pulizia avviene su ogni sito della rete (tabelle e
 * option sono per sito), ciascuno secondo la propria impostazione
 * "Conserva i dati alla disinstallazione".
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'dbph_uninstall_site' ) ) {
	/**
	 * Rimuove tabelle, option, transient e cron dell'Hub dal sito corrente.
	 *
	 * Conservazione dati (1.3.1): il log DSAR e l'archivio policy sono dati
	 * di accountability (art. 5.2 GDPR). Se l'admin ha attivato "Conserva i
	 * dati alla disinstallazione", il sito non viene toccato: alla
	 * reinstallazione tabelle e impostazioni vengono ritrovate intatte.
	 */
	function dbph_uninstall_site() {
		if ( get_option( 'dbph_preserve_data_on_uninstall' ) === '1' ) {
			return;
		}

		global $wpdb;

		// Tabelle custom (1.1.0+).
		foreach ( array( 'dbph_dsar_log', 'dbph_policy_archive' ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
		}

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
			// 1.2.0: toggle istruzioni operative diritti.
			'dbph_show_rights_howto',
			// 1.3.0: cache ID versione Privacy Policy corrente.
			'dbph_policy_current_version',
			// 1.3.1: toggle conservazione dati alla disinstallazione.
			'dbph_preserve_data_on_uninstall',
			// 1.6.0: piattaforme embed manuali + contitolarità pagine social.
			'dbph_embed_manual',
			'dbph_social_pages_mention',
			// 1.8.0: conservazione dello storico DSAR.
			'dbph_dsar_retention_years',
		);
		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// 1.6.0: cache della scansione embed.
		delete_transient( 'dbph_embed_scan' );

		// 1.8.0: cache della release dell'updater (chiave di DB_GitHub_Updater).
		delete_transient( 'dbgu_' . md5( WP_UNINSTALL_PLUGIN ) );

		// 1.2.0: cron giornaliero (scadenza pending e, dalla 1.8.0, retention).
		wp_clear_scheduled_hook( 'dbph_dsar_cleanup_pending' );
	}
}

if ( is_multisite() ) {
	$dbph_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $dbph_site_ids as $dbph_site_id ) {
		switch_to_blog( (int) $dbph_site_id );
		dbph_uninstall_site();
		restore_current_blog();
	}
} else {
	dbph_uninstall_site();
}
