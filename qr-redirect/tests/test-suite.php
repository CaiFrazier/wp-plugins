<?php
/**
 * Standalone test harness for CF QR Redirect.
 *
 * Mocks the subset of WordPress functions our pure-logic classes touch, then
 * exercises slug generation, settings sanitization, URL/UTM building, and
 * the destination safety check. Run with: php tests/test-suite.php
 *
 * @package CFQR
 */

// ---------- WP function mocks ----------------------------------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Constants the plugin expects.
define( 'CFQR_VERSION', 'test' );
define( 'CFQR_PLUGIN_FILE', __DIR__ . '/cf-qr-redirect.php' );
define( 'CFQR_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'CFQR_PLUGIN_URL', 'https://example.test/wp-content/plugins/cf-qr-redirect/' );
define( 'CFQR_POST_TYPE', 'cfqr_code' );
define( 'CFQR_REWRITE_SLUG', 'r' );
define( 'CFQR_OPTION_KEY', 'cfqr_settings' );

// In-memory option store for the settings class.
$GLOBALS['cfqr_test_options']  = array();
$GLOBALS['cfqr_test_postmeta'] = array();
$GLOBALS['cfqr_test_posts']    = array();

function get_option( $key, $default = false ) {
	return $GLOBALS['cfqr_test_options'][ $key ] ?? $default;
}
function update_option( $key, $value ) {
	$GLOBALS['cfqr_test_options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['cfqr_test_options'][ $key ] );
	return true;
}
function get_post_meta( $post_id, $key, $single = false ) {
	$val = $GLOBALS['cfqr_test_postmeta'][ $post_id ][ $key ] ?? '';
	return $single ? $val : array( $val );
}
function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['cfqr_test_postmeta'][ $post_id ][ $key ] = $value;
	return true;
}
function add_post_meta( $post_id, $key, $value, $unique = false ) {
	if ( ! isset( $GLOBALS['cfqr_test_postmeta'][ $post_id ][ $key ] ) ) {
		$GLOBALS['cfqr_test_postmeta'][ $post_id ][ $key ] = $value;
	}
	return true;
}

function sanitize_text_field( $str ) {
	$str = is_string( $str ) ? $str : '';
	$str = trim( $str );
	$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
	$str = preg_replace( '/[\x00-\x1F\x7F]/u', '', $str );
	return strip_tags( $str );
}
function sanitize_key( $key ) {
	$key = is_string( $key ) ? strtolower( $key ) : '';
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}
function sanitize_title( $title ) {
	$title = is_string( $title ) ? strtolower( $title ) : '';
	$title = preg_replace( '/[^a-z0-9\-_]/', '-', $title );
	$title = preg_replace( '/-+/', '-', $title );
	return trim( $title, '-' );
}
function wp_unslash( $v ) {
	if ( is_array( $v ) ) {
		return array_map( 'wp_unslash', $v );
	}
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function esc_url_raw( $url ) {
	if ( ! is_string( $url ) ) {
		return '';
	}
	$url = trim( $url );
	// Strip control chars and whitespace.
	$url = preg_replace( '/[\x00-\x1F\x7F\s]/', '', $url );
	return $url;
}
function wp_parse_url( $url ) {
	return parse_url( $url );
}
function add_query_arg() {
	$args = func_get_args();
	if ( count( $args ) === 2 ) {
		$params = $args[0];
		$url    = $args[1];
	} else {
		$params = array( $args[0] => $args[1] );
		$url    = $args[2];
	}
	$parts = parse_url( $url );
	parse_str( $parts['query'] ?? '', $existing );
	$merged = array_merge( $existing, $params );
	$qs     = http_build_query( $merged );
	$out    = ( $parts['scheme'] ?? 'http' ) . '://' . ( $parts['host'] ?? '' );
	if ( ! empty( $parts['port'] ) ) {
		$out .= ':' . $parts['port'];
	}
	$out .= $parts['path'] ?? '';
	if ( '' !== $qs ) {
		$out .= '?' . $qs;
	}
	if ( ! empty( $parts['fragment'] ) ) {
		$out .= '#' . $parts['fragment'];
	}
	return $out;
}
function add_settings_error( $setting, $code, $message ) {
	$GLOBALS['cfqr_test_settings_errors'][] = compact( 'setting', 'code', 'message' );
}
function __( $text, $domain = '' ) {
	return $text;
}
function _x( $text, $context, $domain = '' ) {
	return $text;
}
function absint( $maybe_int ) {
	return abs( (int) $maybe_int );
}
function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max );
}
function wp_generate_password( $length = 12, $special = true, $extra = true ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	return $out;
}

// $wpdb shim: we only need prepare + get_var for the slug generator.
class CFQR_Test_WPDB {
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public function prepare( $sql, ...$args ) {
		// Trivial placeholder substitution for our tests — we don't actually run SQL.
		return $sql;
	}
	public function get_var( $sql ) {
		// Pretend every slug is available for the generator's collision check.
		return null;
	}
	public function query( $sql ) {
		return 0;
	}
	public function get_col( $sql ) {
		return array();
	}
	public function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}
}
$GLOBALS['wpdb'] = new CFQR_Test_WPDB();

// Minimal CFQR_Admin stub. class-cpt.php's auto_assign_slug() references
// CFQR_Admin::META_NONCE_FIELD and ::META_NONCE_ACTION while detecting meta-box
// submissions. We don't want to load the full class-admin.php (it binds to
// admin-only WP hooks and pulls in dozens of helpers) just to expose two
// constants. Mirror the real values from includes/class-admin.php so the
// constant references resolve identically.
if ( ! class_exists( 'CFQR_Admin' ) ) {
	class CFQR_Admin {
		const META_NONCE_FIELD  = 'cfqr_meta_nonce';
		const META_NONCE_ACTION = 'cfqr_save_meta';
	}
}

// ---------- Load classes under test ---------------------------------------

require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-slug-generator.php';
require_once __DIR__ . '/../includes/class-url.php';
// class-cpt.php defines CFQR_CPT (just constants + static methods); loading it
// is safe — its hook registrations only fire if init() is called.
require_once __DIR__ . '/../includes/class-cpt.php';
// We can't easily test class-router / class-admin without WP because they bind
// to action hooks and call deeply WP-internal helpers. Destination-safety lives
// in the shared CFQR_URL helper, so we can test it directly.
require_once __DIR__ . '/../includes/class-analytics.php';

// Backwards-compat shim: earlier revisions of this harness inlined the
// is_safe_destination predicate as cfqr_test_is_safe_destination(). Existing
// tests below call it. The implementation now lives on CFQR_URL so the harness
// and runtime stay in lockstep automatically.
function cfqr_test_is_safe_destination( $url ) {
	return CFQR_URL::is_safe_destination( $url );
}

// ---------- Tiny test runner ----------------------------------------------

$tests_run    = 0;
$tests_passed = 0;
$tests_failed = array();

function it( $name, $callable ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	try {
		$callable();
		$tests_passed++;
		echo "  PASS  $name\n";
	} catch ( Throwable $e ) {
		$tests_failed[] = "$name -- " . $e->getMessage();
		echo "  FAIL  $name -- " . $e->getMessage() . "\n";
	}
}
function assert_true( $cond, $msg = 'expected true' ) {
	if ( ! $cond ) {
		throw new Exception( $msg );
	}
}
function assert_equal( $expected, $actual, $msg = '' ) {
	if ( $expected !== $actual ) {
		$ex = var_export( $expected, true );
		$ac = var_export( $actual, true );
		throw new Exception( $msg . " expected=$ex actual=$ac" );
	}
}

// ---------- Tests ----------------------------------------------------------

echo "\n== Slug Generator ==\n";

it( 'alphabet excludes 0/O/I/l/1', function () {
	$alpha = CFQR_Slug_Generator::ALPHABET;
	foreach ( array( '0', 'O', 'I', 'l', '1' ) as $c ) {
		assert_true( false === strpos( $alpha, $c ), "alphabet should not contain '$c'" );
	}
	assert_equal( 55, strlen( $alpha ), 'alphabet size' );
} );

it( 'generated slug uses only alphabet characters', function () {
	$alpha = CFQR_Slug_Generator::ALPHABET;
	for ( $i = 0; $i < 50; $i++ ) {
		$slug = CFQR_Slug_Generator::generate( 6 );
		assert_equal( 6, strlen( $slug ), 'slug length' );
		for ( $j = 0; $j < strlen( $slug ); $j++ ) {
			assert_true( false !== strpos( $alpha, $slug[ $j ] ), "slug char '{$slug[$j]}' must be in alphabet (slug=$slug)" );
		}
	}
} );

it( 'generated slugs are not predictable across calls', function () {
	$seen = array();
	for ( $i = 0; $i < 100; $i++ ) {
		$seen[ CFQR_Slug_Generator::generate( 6 ) ] = true;
	}
	assert_true( count( $seen ) > 95, 'should have near-100 unique values out of 100 generations' );
} );

it( 'is_valid accepts plain alphanumeric', function () {
	assert_true( CFQR_Slug_Generator::is_valid( 'FH7B3c' ) );
	assert_true( CFQR_Slug_Generator::is_valid( 'SPRING26' ) );
	assert_true( CFQR_Slug_Generator::is_valid( 'spring-26' ) );
} );

it( 'is_valid rejects empty / too short / too long', function () {
	assert_true( ! CFQR_Slug_Generator::is_valid( '' ) );
	assert_true( ! CFQR_Slug_Generator::is_valid( 'ab' ) );
	assert_true( ! CFQR_Slug_Generator::is_valid( str_repeat( 'a', 65 ) ) );
} );

it( 'is_valid rejects characters outside whitelist', function () {
	assert_true( ! CFQR_Slug_Generator::is_valid( 'with spaces' ) );
	assert_true( ! CFQR_Slug_Generator::is_valid( 'spring/26' ) );
	assert_true( ! CFQR_Slug_Generator::is_valid( '<script>' ) );
	assert_true( ! CFQR_Slug_Generator::is_valid( 'abc.def' ) );
	assert_true( ! CFQR_Slug_Generator::is_valid( 'abc?d' ) );
	assert_true( ! CFQR_Slug_Generator::is_valid( '-leading' ) );
	assert_true( ! CFQR_Slug_Generator::is_valid( 'trailing-' ) );
} );

it( 'length param is clamped to [4,12]', function () {
	$short = CFQR_Slug_Generator::generate( 1 );
	$long  = CFQR_Slug_Generator::generate( 30 );
	assert_equal( 4, strlen( $short ), 'should clamp to min 4' );
	assert_equal( 12, strlen( $long ), 'should clamp to max 12' );
} );

echo "\n== Settings ==\n";

it( 'defaults shape', function () {
	$d = CFQR_Settings::defaults();
	assert_true( array_key_exists( 'ga4_measurement_id', $d ) );
	assert_equal( 'utm', $d['default_analytics_mode'] );
	assert_equal( 302, $d['redirect_status'] );
	assert_equal( 8, $d['short_code_length'] );
} );

it( 'sanitize accepts valid GA4 id', function () {
	$out = CFQR_Settings::sanitize( array( 'ga4_measurement_id' => 'G-ABC1234' ) );
	assert_equal( 'G-ABC1234', $out['ga4_measurement_id'] );
} );

it( 'sanitize rejects invalid GA4 id and emits an error', function () {
	$GLOBALS['cfqr_test_settings_errors'] = array();
	$out = CFQR_Settings::sanitize( array( 'ga4_measurement_id' => 'UA-12345' ) );
	assert_equal( '', $out['ga4_measurement_id'] );
	assert_true( ! empty( $GLOBALS['cfqr_test_settings_errors'] ), 'expected a settings error' );
} );

it( 'sanitize accepts empty GA4 id without error', function () {
	$GLOBALS['cfqr_test_settings_errors'] = array();
	$out = CFQR_Settings::sanitize( array( 'ga4_measurement_id' => '' ) );
	assert_equal( '', $out['ga4_measurement_id'] );
	assert_true( empty( $GLOBALS['cfqr_test_settings_errors'] ), 'no error expected for empty value' );
} );

it( 'sanitize whitelists analytics_mode', function () {
	$out = CFQR_Settings::sanitize( array( 'default_analytics_mode' => 'utm' ) );
	assert_equal( 'utm', $out['default_analytics_mode'] );
	$out = CFQR_Settings::sanitize( array( 'default_analytics_mode' => 'intermediate' ) );
	assert_equal( 'intermediate', $out['default_analytics_mode'] );
	$out = CFQR_Settings::sanitize( array( 'default_analytics_mode' => 'evil' ) );
	assert_equal( 'utm', $out['default_analytics_mode'] );
} );

it( 'sanitize whitelists redirect_status', function () {
	assert_equal( 301, CFQR_Settings::sanitize( array( 'redirect_status' => 301 ) )['redirect_status'] );
	assert_equal( 302, CFQR_Settings::sanitize( array( 'redirect_status' => 302 ) )['redirect_status'] );
	assert_equal( 302, CFQR_Settings::sanitize( array( 'redirect_status' => 999 ) )['redirect_status'] );
	assert_equal( 302, CFQR_Settings::sanitize( array( 'redirect_status' => 'haha' ) )['redirect_status'] );
} );

it( 'sanitize clamps short_code_length to [6,12]', function () {
	assert_equal( 6, CFQR_Settings::sanitize( array( 'short_code_length' => 1 ) )['short_code_length'] );
	assert_equal( 6, CFQR_Settings::sanitize( array( 'short_code_length' => 4 ) )['short_code_length'] );
	assert_equal( 12, CFQR_Settings::sanitize( array( 'short_code_length' => 99 ) )['short_code_length'] );
	assert_equal( 8, CFQR_Settings::sanitize( array( 'short_code_length' => 8 ) )['short_code_length'] );
} );

it( 'sanitize defaults blank UTM source/medium to safe defaults', function () {
	$out = CFQR_Settings::sanitize( array( 'default_utm_source' => '', 'default_utm_medium' => '' ) );
	assert_equal( 'qr', $out['default_utm_source'] );
	assert_equal( 'qr-code', $out['default_utm_medium'] );
} );

echo "\n== Destination safety ==\n";

it( 'accepts http/https URLs with a host', function () {
	assert_true( cfqr_test_is_safe_destination( 'https://example.com/path' ) );
	assert_true( cfqr_test_is_safe_destination( 'http://example.com/' ) );
	assert_true( cfqr_test_is_safe_destination( 'https://example.com:8080/x?y=1' ) );
} );

it( 'rejects javascript: and data: URIs', function () {
	assert_true( ! cfqr_test_is_safe_destination( 'javascript:alert(1)' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'JAVASCRIPT:alert(1)' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'data:text/html,<script>alert(1)</script>' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'file:///etc/passwd' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'ftp://example.com/' ) );
} );

it( 'rejects empty, scheme-less, and host-less URLs', function () {
	assert_true( ! cfqr_test_is_safe_destination( '' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'example.com' ) );
	assert_true( ! cfqr_test_is_safe_destination( '/relative/path' ) );
	assert_true( ! cfqr_test_is_safe_destination( '//protocol-relative.com' ) );
	// Per parse_url, scheme without authority would still pass our check, so
	// document the boundary:
	assert_true( ! cfqr_test_is_safe_destination( 'http:///nopath' ) );
} );

it( 'rejects URLs with userinfo credentials', function () {
	// https://trusted.example@evil.example/path renders as "trusted.example" in
	// some UI surfaces but routes to "evil.example" — classic phishing vector.
	assert_true( ! cfqr_test_is_safe_destination( 'https://user@example.com/' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'https://user:pass@example.com/' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'https://trusted.example@evil.example/' ) );
} );

it( 'rejects localhost and private/reserved IP literals', function () {
	assert_true( ! cfqr_test_is_safe_destination( 'http://localhost/' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'http://localhost:8080/admin' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'http://127.0.0.1/' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'http://10.0.0.1/' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'http://192.168.1.1/' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'http://172.16.0.1/' ) );
	assert_true( ! cfqr_test_is_safe_destination( 'http://169.254.0.1/' ) ); // link-local
	// Public IP literals (e.g. 8.8.8.8) are allowed — there's no plugin policy
	// against them, only against private/reserved ranges.
	assert_true( cfqr_test_is_safe_destination( 'https://8.8.8.8/' ) );
} );

it( 'rejects URLs longer than 2048 chars', function () {
	$long = 'https://example.com/' . str_repeat( 'x', 2048 );
	assert_true( ! cfqr_test_is_safe_destination( $long ) );
} );

echo "\n== UTM URL building ==\n";

it( 'injects all four utm params with defaults', function () {
	// Seed an in-memory post and meta.
	$post              = (object) array( 'ID' => 7, 'post_name' => 'AB12cd' );
	$GLOBALS['cfqr_test_postmeta'][7] = array(
		CFQR_CPT::META_UTM_SOURCE => 'qr',
		CFQR_CPT::META_UTM_MEDIUM => 'qr-code',
		CFQR_CPT::META_CAMPAIGN   => 'spring-mailer-2026',
	);
	$result = CFQR_Analytics::build_utm_url( 'https://dest.example/landing', $post );
	$parts  = parse_url( $result );
	parse_str( $parts['query'] ?? '', $q );
	assert_equal( 'qr', $q['utm_source'] );
	assert_equal( 'qr-code', $q['utm_medium'] );
	assert_equal( 'spring-mailer-2026', $q['utm_campaign'] );
	assert_equal( 'AB12cd', $q['utm_content'] );
	assert_equal( 'dest.example', $parts['host'] );
} );

it( 'preserves existing query params on destination', function () {
	$post              = (object) array( 'ID' => 8, 'post_name' => 'XY34ef' );
	$GLOBALS['cfqr_test_postmeta'][8] = array();
	$result = CFQR_Analytics::build_utm_url( 'https://dest.example/x?ref=existing&a=1', $post );
	parse_str( parse_url( $result, PHP_URL_QUERY ), $q );
	assert_equal( 'existing', $q['ref'] ?? '' );
	assert_equal( '1', $q['a'] ?? '' );
	assert_equal( 'XY34ef', $q['utm_content'] ?? '' );
} );

it( 'plugin-supplied utm params win over destination-supplied ones', function () {
	$post              = (object) array( 'ID' => 9, 'post_name' => 'WIN999' );
	$GLOBALS['cfqr_test_postmeta'][9] = array(
		CFQR_CPT::META_UTM_SOURCE => 'qr',
		CFQR_CPT::META_UTM_MEDIUM => 'qr-code',
	);
	$result = CFQR_Analytics::build_utm_url( 'https://dest.example/?utm_source=other&utm_medium=other', $post );
	parse_str( parse_url( $result, PHP_URL_QUERY ), $q );
	assert_equal( 'qr', $q['utm_source'] );
	assert_equal( 'qr-code', $q['utm_medium'] );
} );

it( 'falls back to settings defaults when meta is empty', function () {
	$post              = (object) array( 'ID' => 10, 'post_name' => 'DEF000' );
	$GLOBALS['cfqr_test_postmeta'][10] = array();
	// No settings stored — defaults() applies.
	$result = CFQR_Analytics::build_utm_url( 'https://dest.example/', $post );
	parse_str( parse_url( $result, PHP_URL_QUERY ), $q );
	assert_equal( 'qr', $q['utm_source'] );
	assert_equal( 'qr-code', $q['utm_medium'] );
} );

it( 'campaign passes through to utm_campaign without further transformation', function () {
	// Campaigns are now slugified on save (CFQR_Admin::save_meta), so the stored value
	// is already the canonical slug. build_utm_url should pass it through unchanged.
	$post              = (object) array( 'ID' => 11, 'post_name' => 'ZZ1234' );
	$GLOBALS['cfqr_test_postmeta'][11] = array(
		CFQR_CPT::META_CAMPAIGN => 'spring-mailer-2026',
	);
	$result = CFQR_Analytics::build_utm_url( 'https://dest.example/', $post );
	parse_str( parse_url( $result, PHP_URL_QUERY ), $q );
	assert_equal( 'spring-mailer-2026', $q['utm_campaign'] );
} );

it( 'utm values are NOT double-encoded', function () {
	// Pre-encoding before add_query_arg would turn "%" into "%25".
	$post              = (object) array( 'ID' => 12, 'post_name' => 'CHK001' );
	$GLOBALS['cfqr_test_postmeta'][12] = array(
		CFQR_CPT::META_UTM_SOURCE => 'qr+print',
		CFQR_CPT::META_UTM_MEDIUM => 'qr code',
	);
	$result = CFQR_Analytics::build_utm_url( 'https://dest.example/', $post );
	$qs     = parse_url( $result, PHP_URL_QUERY );
	// "%2B" is a single-encoded "+"; "%252B" would be the double-encoded form.
	assert_true( false === strpos( $qs, '%252B' ), 'must not contain double-encoded %252B (saw: ' . $qs . ')' );
	assert_true( false === strpos( $qs, '%2520' ), 'must not contain double-encoded %2520 (saw: ' . $qs . ')' );
	parse_str( $qs, $q );
	assert_equal( 'qr+print', $q['utm_source'] );
	assert_equal( 'qr code', $q['utm_medium'] );
} );

it( 'mode-B intermediate template uses 500ms timeout (not 1500ms)', function () {
	// Read the template file directly and assert the safety-net timeout was lowered.
	$template = file_get_contents( __DIR__ . '/../templates/intermediate.php' );
	assert_true( false !== strpos( $template, 'event_timeout: 500' ), 'event_timeout must be 500' );
	assert_true( false !== strpos( $template, 'setTimeout(go, 500)' ), 'setTimeout safety net must be 500' );
	assert_true( false === strpos( $template, '1500' ), 'no remaining 1500-ms references should exist' );
} );

echo "\n== Single-step creation (auto-draft slug) ==\n";

it( 'auto_assign_slug now generates a slug for auto-draft posts', function () {
	// Previously auto-draft was excluded; the single-step creation flow requires it.
	$result = CFQR_CPT::auto_assign_slug(
		array( 'post_type' => CFQR_POST_TYPE, 'post_name' => '', 'post_status' => 'auto-draft' ),
		array()
	);
	assert_true( '' !== $result['post_name'], 'auto-draft cfqr_code must get a slug' );
	assert_true( strlen( $result['post_name'] ) >= 4, 'generated slug must be a real value' );
} );

it( 'auto_assign_slug still skips revisions (post_status=inherit)', function () {
	$result = CFQR_CPT::auto_assign_slug(
		array( 'post_type' => CFQR_POST_TYPE, 'post_name' => '', 'post_status' => 'inherit' ),
		array()
	);
	assert_equal( '', $result['post_name'], 'revisions must not get a slug' );
} );

echo "\n== GA4 deep link ==\n";

it( 'GA4 link falls back to home URL when no property ID set', function () {
	CFQR_Settings::update( array() ); // resets to defaults
	$link = CFQR_Settings::build_ga4_link();
	assert_equal( 'https://analytics.google.com/analytics/web/', $link );
} );

it( 'GA4 link deep-links into the property when ga4_property_id is set', function () {
	CFQR_Settings::update( array( 'ga4_property_id' => '123456789' ) );
	$link = CFQR_Settings::build_ga4_link();
	assert_true( false !== strpos( $link, '/p123456789/' ), 'link should include the property ID path' );
} );

it( 'GA4 property ID setting strips non-digits', function () {
	$out = CFQR_Settings::sanitize( array( 'ga4_property_id' => 'abc 12-34_5' ) );
	assert_equal( '12345', $out['ga4_property_id'] );
} );

echo "\n== Patches verified by audit ==\n";

it( 'auto_assign_slug regenerates when user supplies a reserved slug', function () {
	// Reserved slugs from class-cpt.php: wp-admin, wp-login, wp-content, wp-includes, admin, r.
	$result = CFQR_CPT::auto_assign_slug( array( 'post_type' => CFQR_POST_TYPE, 'post_name' => 'wp-admin', 'post_status' => 'publish' ), array() );
	assert_true( 'wp-admin' !== $result['post_name'], 'wp-admin should have been replaced' );
	assert_true( strlen( $result['post_name'] ) >= 4, 'replacement must be a real slug' );

	$result = CFQR_CPT::auto_assign_slug( array( 'post_type' => CFQR_POST_TYPE, 'post_name' => 'r', 'post_status' => 'publish' ), array() );
	assert_true( 'r' !== $result['post_name'], 'rewrite-base "r" should have been replaced' );
} );

it( 'auto_assign_slug regenerates when user supplies an invalid-character slug', function () {
	$result = CFQR_CPT::auto_assign_slug( array( 'post_type' => CFQR_POST_TYPE, 'post_name' => 'with spaces', 'post_status' => 'publish' ), array() );
	assert_true( 'with spaces' !== $result['post_name'] );
	$result = CFQR_CPT::auto_assign_slug( array( 'post_type' => CFQR_POST_TYPE, 'post_name' => '<script>', 'post_status' => 'publish' ), array() );
	assert_true( '<script>' !== $result['post_name'] );
} );

it( 'auto_assign_slug keeps a valid user-supplied vanity slug', function () {
	$result = CFQR_CPT::auto_assign_slug( array( 'post_type' => CFQR_POST_TYPE, 'post_name' => 'SPRING26', 'post_status' => 'publish' ), array() );
	assert_equal( 'SPRING26', $result['post_name'] );
} );

it( 'auto_assign_slug ignores non-cfqr post types', function () {
	$in     = array( 'post_type' => 'page', 'post_name' => 'wp-admin', 'post_status' => 'publish' );
	$result = CFQR_CPT::auto_assign_slug( $in, array() );
	assert_equal( 'wp-admin', $result['post_name'], 'should not modify other post types' );
} );

it( 'Settings::update routes through sanitize', function () {
	// Try to inject an unsafe redirect_status; sanitize should clamp to 302.
	CFQR_Settings::update( array( 'redirect_status' => 999, 'short_code_length' => 999 ) );
	$stored = get_option( CFQR_OPTION_KEY );
	assert_equal( 302, $stored['redirect_status'] );
	assert_equal( 12, $stored['short_code_length'] );
} );

echo "\n== JSON encoding hardening (intermediate template) ==\n";

it( 'wp_json_encode with hex flags neutralizes </script> in destination', function () {
	$evil  = 'https://evil.com/</script><script>alert(1)</script>';
	$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
	$json  = json_encode( $evil, $flags );
	assert_true( false === strpos( $json, '<' ), 'encoded JSON must not contain a literal < character' );
	assert_true( false === strpos( $json, '>' ), 'encoded JSON must not contain a literal > character' );
	assert_true( false !== strpos( $json, '\\u003C' ) || false !== strpos( $json, '\\u003c' ), 'encoded JSON should contain hex-escaped <' );
} );

// ---------- Summary --------------------------------------------------------

echo "\n";
echo "Tests run:    $tests_run\n";
echo "Tests passed: $tests_passed\n";
echo "Tests failed: " . count( $tests_failed ) . "\n";

if ( ! empty( $tests_failed ) ) {
	echo "\nFAILURES:\n";
	foreach ( $tests_failed as $f ) {
		echo "  - $f\n";
	}
	exit( 1 );
}
exit( 0 );
