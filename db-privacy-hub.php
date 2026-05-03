<?php
/**
 * Plugin Name:       DB Privacy Hub
 * Plugin URI:        https://www.davidebertolino.it/progetti/db-privacy-hub/
 * Description:       Hub privacy unificato per l'ecosistema plugin DB. Raccoglie i trattamenti dichiarati dai plugin DB (Cookie Manager, Form Builder, SEO Manager…) e genera una Privacy Policy completa (artt. 13-14 GDPR) pronta da pubblicare come pagina WordPress. Importa automaticamente la Cookie Policy dal DB Cookie Manager se installato. Niente servizi esterni, niente tracciamento.
 * Version:           1.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Davide Bertolino
 * Author URI:        https://www.davidebertolino.it
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       db-privacy-hub
 * Domain Path:       /languages
 *
 * @package DB_Privacy_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Costanti
 * ---------------------------------------------------------------------- */
define( 'DBPH_VERSION',     '1.3.0' );
define( 'DBPH_FILE',        __FILE__ );
define( 'DBPH_DIR',         plugin_dir_path( __FILE__ ) );
define( 'DBPH_URL',         plugin_dir_url( __FILE__ ) );
define( 'DBPH_BASENAME',    plugin_basename( __FILE__ ) );
define( 'DBPH_TEXT_DOMAIN', 'db-privacy-hub' );

/* -------------------------------------------------------------------------
 * GitHub Auto-Updater
 * ---------------------------------------------------------------------- */
require_once DBPH_DIR . 'inc/class-updater.php';
new DB_GitHub_Updater( __FILE__, 'dadebertolino', 'db-privacy-hub' );

/* -------------------------------------------------------------------------
 * Bootstrap
 * ---------------------------------------------------------------------- */
require_once DBPH_DIR . 'inc/class-register.php';
require_once DBPH_DIR . 'inc/class-responsabili.php';
require_once DBPH_DIR . 'inc/class-policy-archive.php';
require_once DBPH_DIR . 'inc/class-policy-generator.php';
require_once DBPH_DIR . 'inc/class-deprecated-aliases.php';
require_once DBPH_DIR . 'inc/class-dsar.php';
require_once DBPH_DIR . 'inc/class-dsar-log.php';
// 1.3.0: aggregatore consensi via filter dbph_consents_register
require_once DBPH_DIR . 'inc/class-consents-register.php';
require_once DBPH_DIR . 'inc/class-admin.php';

/**
 * Avvia tutti i moduli su plugins_loaded@5 — prima del default 10, così che
 * altri plugin DB possano hookare normalmente.
 */
function dbph_boot() {
	DBPH_Deprecated_Aliases::init();
	DBPH_Register::init();
	DBPH_Responsabili::init();
	DBPH_Policy_Archive::init();
	DBPH_DSAR::init();
	DBPH_DSAR_Log::init();
	DBPH_Consents_Register::init();
	DBPH_Admin::init();
}
add_action( 'plugins_loaded', 'dbph_boot', 5 );

/**
 * Carica le traduzioni.
 */
function dbph_load_textdomain() {
	load_plugin_textdomain(
		DBPH_TEXT_DOMAIN,
		false,
		dirname( DBPH_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'dbph_load_textdomain' );

/* -------------------------------------------------------------------------
 * Activation / Deactivation
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, 'dbph_activate' );
register_deactivation_hook( __FILE__, 'dbph_deactivate' );

/**
 * Cleanup risorse al deactivate (cron, transient).
 * Le tabelle e le option restano: la rimozione completa avviene solo da
 * uninstall.php quando l'utente disinstalla il plugin.
 */
function dbph_deactivate() {
	if ( class_exists( 'DBPH_DSAR_Log' ) ) {
		DBPH_DSAR_Log::on_deactivate();
	}
}

function dbph_activate() {
	// Verifica requisiti minimi.
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		deactivate_plugins( DBPH_BASENAME );
		wp_die(
			esc_html__( 'DB Privacy Hub richiede PHP 7.4 o superiore.', 'db-privacy-hub' ),
			'DB Privacy Hub',
			array( 'back_link' => true )
		);
	}
	if ( version_compare( get_bloginfo( 'version' ), '5.8', '<' ) ) {
		deactivate_plugins( DBPH_BASENAME );
		wp_die(
			esc_html__( 'DB Privacy Hub richiede WordPress 5.8 o superiore.', 'db-privacy-hub' ),
			'DB Privacy Hub',
			array( 'back_link' => true )
		);
	}

	// Marker installazione (utile per future migrazioni e per uninstall.php).
	if ( false === get_option( 'dbph_version' ) ) {
		add_option( 'dbph_version', DBPH_VERSION );
	} else {
		update_option( 'dbph_version', DBPH_VERSION );
	}

	// Crea le tabelle custom dei moduli DSAR log e Policy Archive.
	if ( class_exists( 'DBPH_DSAR_Log' ) ) {
		DBPH_DSAR_Log::create_table();
		update_option( 'dbph_dsar_log_schema', DBPH_DSAR_Log::SCHEMA_VERSION );
	}
	if ( class_exists( 'DBPH_Policy_Archive' ) ) {
		DBPH_Policy_Archive::create_table();
		update_option( 'dbph_policy_archive_schema', DBPH_Policy_Archive::SCHEMA_VERSION );
	}
}
