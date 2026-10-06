<?php
/**
 * Bootstrap dei test UNIT.
 *
 * Le classi dell'Hub sono per lo più logica pura sopra un piccolo insieme di
 * funzioni WordPress (option, filtri, sanitize/escape, i18n). Le stubbiamo qui
 * così il job unit resta leggero: niente MySQL, niente WP test suite. Ciò che
 * richiede WordPress vero (tabelle, WP_User_Request, cron) sta negli
 * integration test.
 *
 * Gli stub sono volutamente fedeli dove il comportamento conta per i
 * contratti tra plugin: i filtri rispettano priorità e numero di argomenti,
 * come in WordPress, perché l'ordine di merge (es. alias legacy a 999) fa
 * parte di ciò che si testa.
 *
 * @package DBPH\Tests
 */

// Marcatore per i file del plugin che controllano ABSPATH.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// Percorso dei sorgenti del plugin (root del repo).
define( 'DBPH_TEST_ROOT', dirname( __DIR__, 2 ) );

if ( ! defined( 'DBPH_DIR' ) ) {
	define( 'DBPH_DIR', DBPH_TEST_ROOT . '/' );
}
if ( ! defined( 'DBPH_VERSION' ) ) {
	define( 'DBPH_VERSION', 'test' );
}
if ( ! defined( 'DBPH_TEXT_DOMAIN' ) ) {
	define( 'DBPH_TEXT_DOMAIN', 'db-privacy-hub' );
}
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'YEAR_IN_SECONDS'   => 31536000,
) as $dbph_const => $dbph_value ) {
	if ( ! defined( $dbph_const ) ) {
		define( $dbph_const, $dbph_value );
	}
}

/* -----------------------------------------------------------------------------
 * Stato globale simulato: option table, transient, filtri/azioni, chiamate
 * a _doing_it_wrong. Azzerato da dbph_test_reset() fra un test e l'altro.
 * -------------------------------------------------------------------------- */

$GLOBALS['__dbph_options']       = array();
$GLOBALS['__dbph_transients']    = array();
$GLOBALS['__dbph_filters']       = array();
$GLOBALS['__dbph_doing_it_wrong'] = array();

/* --- Option e transient --------------------------------------------------- */

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__dbph_options'] )
			? $GLOBALS['__dbph_options'][ $key ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__dbph_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $key, $GLOBALS['__dbph_options'] ) ) {
			return false;
		}
		$GLOBALS['__dbph_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['__dbph_options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['__dbph_transients'] )
			? $GLOBALS['__dbph_transients'][ $key ]
			: false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['__dbph_transients'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['__dbph_transients'][ $key ] );
		return true;
	}
}

/* --- Filtri e azioni (priorità e accepted_args come in WordPress) --------- */

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['__dbph_filters'][ $hook ][ (int) $priority ][] = array( $cb, (int) $accepted_args );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook, $cb, $priority, $accepted_args );
	}
}
if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $cb, $priority = 10 ) {
		if ( empty( $GLOBALS['__dbph_filters'][ $hook ][ $priority ] ) ) {
			return false;
		}
		foreach ( $GLOBALS['__dbph_filters'][ $hook ][ $priority ] as $i => $entry ) {
			if ( $entry[0] === $cb ) {
				unset( $GLOBALS['__dbph_filters'][ $hook ][ $priority ][ $i ] );
				return true;
			}
		}
		return false;
	}
}
if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $cb, $priority = 10 ) {
		return remove_filter( $hook, $cb, $priority );
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $hook, $cb = false ) {
		if ( empty( $GLOBALS['__dbph_filters'][ $hook ] ) ) {
			return false;
		}
		if ( false === $cb ) {
			return true;
		}
		foreach ( $GLOBALS['__dbph_filters'][ $hook ] as $priority => $entries ) {
			foreach ( $entries as $entry ) {
				if ( $entry[0] === $cb ) {
					return $priority;
				}
			}
		}
		return false;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		$args = array_slice( func_get_args(), 1 );
		if ( empty( $GLOBALS['__dbph_filters'][ $hook ] ) ) {
			return $value;
		}
		$by_priority = $GLOBALS['__dbph_filters'][ $hook ];
		ksort( $by_priority );
		foreach ( $by_priority as $entries ) {
			foreach ( $entries as $entry ) {
				$args[0] = call_user_func_array( $entry[0], array_slice( $args, 0, max( 1, $entry[1] ) ) );
			}
		}
		return $args[0];
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook ) {
		$args = array_slice( func_get_args(), 1 );
		if ( empty( $GLOBALS['__dbph_filters'][ $hook ] ) ) {
			return;
		}
		$by_priority = $GLOBALS['__dbph_filters'][ $hook ];
		ksort( $by_priority );
		foreach ( $by_priority as $entries ) {
			foreach ( $entries as $entry ) {
				call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
			}
		}
	}
}
if ( ! function_exists( '__return_true' ) ) {
	function __return_true() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames -- stesso nome di WordPress.
		return true;
	}
}
if ( ! function_exists( '__return_false' ) ) {
	function __return_false() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames -- stesso nome di WordPress.
		return false;
	}
}
if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( $function, $message, $version ) {
		$GLOBALS['__dbph_doing_it_wrong'][] = array( $function, $message, $version );
	}
}

/* --- i18n ----------------------------------------------------------------- */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = 'default' ) {
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

/* --- Sanitize ed escape --------------------------------------------------- */

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = strip_tags( (string) $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		$email = trim( (string) $email );
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return filter_var( (string) $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $str, $remove_breaks = false ) {
		$str = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $str );
		$str = strip_tags( $str );
		if ( $remove_breaks ) {
			$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		}
		return trim( $str );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = 'default' ) {
		return esc_html( $t );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $t, $d = 'default' ) {
		return esc_attr( $t );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) {
		$u = esc_url_raw( $u );
		return htmlspecialchars( $u, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) {
		$u = trim( (string) $u );
		// Stub minimale: accetta http/https/mailto, come i protocolli WP di default.
		if ( '' === $u || ! preg_match( '#^(https?://|mailto:)#i', $u ) ) {
			return '';
		}
		return $u;
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) {
		return preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $html );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0 ) {
		return json_encode( $data, $options );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}

/* --- Sito, date, ambiente ------------------------------------------------- */

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://hub.example' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	}
}
if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		return home_url( $path );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $key = 'name' ) {
		return 'version' === $key ? '6.6' : 'Sito Di Test';
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		$tz = get_option( 'timezone_string' );
		return new DateTimeZone( $tz ? $tz : 'UTC' );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		$now = new DateTimeImmutable( 'now', $gmt ? new DateTimeZone( 'UTC' ) : wp_timezone() );
		if ( 'timestamp' === $type || 'U' === $type ) {
			return $gmt ? $now->getTimestamp() : $now->getTimestamp() + $now->getOffset();
		}
		return $now->format( 'mysql' === $type ? 'Y-m-d H:i:s' : $type );
	}
}
if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $ts = false, $gmt = false ) {
		return gmdate( $format ? $format : 'Y-m-d', false === $ts ? time() : (int) $ts );
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $ts = null, $tz = null ) {
		$dt = new DateTimeImmutable( '@' . ( null === $ts ? time() : (int) $ts ) );
		return $dt->setTimezone( $tz ? $tz : wp_timezone() )->format( $format );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'dbph-test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return ! empty( $GLOBALS['__dbph_is_admin'] );
	}
}
if ( ! function_exists( 'is_plugin_active' ) ) {
	function is_plugin_active( $plugin ) {
		return in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
	}
}

/* -----------------------------------------------------------------------------
 * Helper per i test.
 * -------------------------------------------------------------------------- */

/**
 * Reset dello stato globale fra un test e l'altro: option, transient, filtri,
 * _doing_it_wrong e le cache statiche delle classi dell'Hub.
 */
function dbph_test_reset() {
	$GLOBALS['__dbph_options']        = array();
	$GLOBALS['__dbph_transients']     = array();
	$GLOBALS['__dbph_filters']        = array();
	$GLOBALS['__dbph_doing_it_wrong'] = array();
	$GLOBALS['__dbph_is_admin']       = false;

	// Cache "per request" delle classi: in produzione durano una richiesta,
	// qui vanno svuotate a ogni test.
	foreach ( array(
		'DBPH_Register'          => 'cache',
		'DBPH_Consents_Register' => 'sources_cache',
	) as $class => $property ) {
		if ( property_exists( $class, $property ) ) {
			dbph_test_set_static( $class, $property, null );
		}
	}
}

/**
 * Invoca un metodo privato/protetto statico via Reflection. Serve a testare
 * logica non pubblica (es. mask_email) senza cambiarne la visibilità.
 *
 * @param string $class
 * @param string $method
 * @param array  $args
 * @return mixed
 */
function dbph_test_call_private( $class, $method, array $args = array() ) {
	$ref = new ReflectionMethod( $class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$ref->setAccessible( true );
	}
	return $ref->invokeArgs( null, $args );
}

/**
 * Imposta una proprietà statica privata (cache delle classi).
 *
 * @param string $class
 * @param string $property
 * @param mixed  $value
 */
function dbph_test_set_static( $class, $property, $value ) {
	$ref = new ReflectionProperty( $class, $property );
	if ( PHP_VERSION_ID < 80100 ) {
		$ref->setAccessible( true );
	}
	$ref->setValue( null, $value );
}

// Carica i sorgenti sotto test: le classi sono solo definizioni, nessun
// codice viene eseguito al require (gli init() li chiamano i test).
foreach ( glob( DBPH_TEST_ROOT . '/inc/class-*.php' ) as $dbph_file ) {
	require_once $dbph_file;
}

// Autoload Composer per PHPUnit e polyfill.
require_once DBPH_TEST_ROOT . '/vendor/autoload.php';
