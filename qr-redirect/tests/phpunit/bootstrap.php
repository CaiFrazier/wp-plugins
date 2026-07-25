<?php
/**
 * PHPUnit bootstrap for CF QR Redirect.
 *
 * Runs alongside the two standalone harnesses in tests/ rather than replacing
 * them: those cover 182 assertions of pure routing logic and there is no value
 * in a risky mechanical rewrite of passing tests. New coverage lands here, and
 * the standalone suites can migrate incrementally.
 *
 * No WordPress install required. Every WP function the classes under test
 * touch is shimmed below and backed by $GLOBALS so tests can inject state.
 * Call cfqr_test_reset_state() in setUp().
 *
 * @package CFQR
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

// -----------------------------------------------------------------------------
// Sanitization / string helpers
// -----------------------------------------------------------------------------

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		$str = strip_tags( $str );
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( $str );
	}
}

/**
 * Deterministic stand-in for WordPress's salt. Real wp_salt() returns a
 * site-specific secret; the tests only require that it is stable within a run
 * and that the code actually passes *something* to hash_hmac.
 */
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'cfqr-test-salt-' . $scheme;
	}
}

// -----------------------------------------------------------------------------
// Transients. Backed by a plain array — no expiry simulation, because the code
// under test relies on TTL only through "was it written again?", which the
// write log below captures directly.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return array_key_exists( $key, $GLOBALS['cfqr_test_transients'] )
			? $GLOBALS['cfqr_test_transients'][ $key ]
			: false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['cfqr_test_transients'][ $key ] = $value;
		// Every write is logged so tests can assert on write COUNT, not just
		// final state. The dedupe logic's whole purpose is avoiding repeat
		// writes, and final state alone cannot distinguish one write from ten.
		$GLOBALS['cfqr_test_transient_writes'][] = array(
			'key'   => $key,
			'value' => $value,
			'ttl'   => $ttl,
		);
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['cfqr_test_actions'][] = array( 'hook' => $hook, 'callback' => $callback );
		return true;
	}
}

// -----------------------------------------------------------------------------
// Minimal plugin-side stubs. class-router.php references these constants at
// runtime; the suppression logic under test never reads them, but loading the
// class must not fatal.
// -----------------------------------------------------------------------------

if ( ! class_exists( 'CFQR_CPT' ) ) {
	class CFQR_CPT {
		const META_HIT_COUNT      = '_cfqr_hit_count';
		const META_LAST_HIT_AT    = '_cfqr_last_hit_at';
		const META_DESTINATION    = '_cfqr_destination';
		const META_ANALYTICS_MODE = '_cfqr_analytics_mode';
	}
}

function cfqr_test_reset_state(): void {
	$GLOBALS['cfqr_test_transients']       = array();
	$GLOBALS['cfqr_test_transient_writes'] = array();
	$GLOBALS['cfqr_test_actions']          = array();
	unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
}

cfqr_test_reset_state();

// __DIR__ is <plugin>/tests/phpunit, so two levels up is the plugin root.
require_once dirname( __DIR__, 2 ) . '/includes/class-router.php';
