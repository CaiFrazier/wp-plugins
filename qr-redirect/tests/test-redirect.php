<?php
/**
 * Standalone test harness for the standard redirect manager.
 *
 * Covers the pure-logic methods on CFQR_Redirect_CPT and CFQR_Redirect_Router:
 * path normalization, source sanitization, mode/status sanitization, wildcard
 * regex compilation, user regex compilation, capture substitution, and the
 * three pattern matchers (via reflection so the matchers can stay private).
 *
 * Runnable standalone (`php tests/test-redirect.php`) or chained from
 * tests/test-suite.php. Every WP mock here is guarded with function_exists
 * so re-including this file from a parent test runner is a no-op for the
 * shared mocks.
 *
 * @package CFQR
 */

// ---------- WP function mocks (guarded for re-inclusion) -------------------

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'CFQR_VERSION' ) ) {
	define( 'CFQR_VERSION', 'test' );
}
if ( ! defined( 'CFQR_PLUGIN_FILE' ) ) {
	define( 'CFQR_PLUGIN_FILE', __DIR__ . '/cf-qr-redirect.php' );
}
if ( ! defined( 'CFQR_PLUGIN_DIR' ) ) {
	define( 'CFQR_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'CFQR_PLUGIN_URL' ) ) {
	define( 'CFQR_PLUGIN_URL', 'https://example.test/wp-content/plugins/cf-qr-redirect/' );
}
if ( ! defined( 'CFQR_POST_TYPE' ) ) {
	define( 'CFQR_POST_TYPE', 'cfqr_code' );
}
if ( ! defined( 'CFQR_REWRITE_SLUG' ) ) {
	define( 'CFQR_REWRITE_SLUG', 'r' );
}
if ( ! defined( 'CFQR_REDIRECT_POST_TYPE' ) ) {
	define( 'CFQR_REDIRECT_POST_TYPE', 'cfqr_redirect' );
}
if ( ! defined( 'CFQR_404_POST_TYPE' ) ) {
	define( 'CFQR_404_POST_TYPE', 'cfqr_404' );
}
if ( ! defined( 'CFQR_REDIRECT_TAXONOMY' ) ) {
	define( 'CFQR_REDIRECT_TAXONOMY', 'cfqr_redirect_group' );
}
if ( ! defined( 'CFQR_OPTION_KEY' ) ) {
	define( 'CFQR_OPTION_KEY', 'cfqr_settings' );
}
foreach (
	array(
		'CFQR_CAP_READ'                       => 'cfqr_read_codes',
		'CFQR_CAP_CREATE'                     => 'cfqr_create_codes',
		'CFQR_CAP_EDIT'                       => 'cfqr_edit_codes',
		'CFQR_CAP_DELETE'                     => 'cfqr_delete_codes',
		'CFQR_CAP_EXPORT'                     => 'cfqr_export_codes',
		'CFQR_CAP_SETTINGS'                   => 'cfqr_manage_settings',
		'CFQR_CAP'                            => 'cfqr_edit_codes',
		'CFQR_REDIRECT_CAP_READ'              => 'cfqr_read_redirects',
		'CFQR_REDIRECT_CAP_CREATE'            => 'cfqr_create_redirects',
		'CFQR_REDIRECT_CAP_EDIT'              => 'cfqr_edit_redirects',
		'CFQR_REDIRECT_CAP_DELETE'            => 'cfqr_delete_redirects',
		'CFQR_REDIRECT_CAP_EXPORT'            => 'cfqr_export_redirects',
		'CFQR_REDIRECT_CAP_MANAGE_GROUPS'     => 'cfqr_manage_redirect_groups',
		'CFQR_REDIRECT_CAP_MANAGE_404'        => 'cfqr_manage_404_captures',
	) as $const => $value
) {
	if ( ! defined( $const ) ) {
		define( $const, $value );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_parse_str' ) ) {
	function wp_parse_str( $string, &$result ) {
		parse_str( (string) $string, $result );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = is_string( $str ) ? $str : '';
		$str = trim( $str );
		$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
		$str = preg_replace( '/[\x00-\x1F\x7F]/u', '', $str );
		return strip_tags( $str );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = is_string( $key ) ? strtolower( $key ) : '';
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		return preg_replace( '/[\x00-\x1F\x7F\s]/', '', $url );
	}
}
if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$value = strtolower( $value );
			return in_array( $value, array( '1', 'true', 'on', 'yes' ), true );
		}
		return (bool) $value;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}
}
if ( ! function_exists( 'register_taxonomy' ) ) {
	function register_taxonomy( ...$args ) {
		return true;
	}
}
if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( ...$args ) {
		return true;
	}
}
if ( ! function_exists( 'register_post_meta' ) ) {
	function register_post_meta( ...$args ) {
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {
		return true;
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = '' ) {
		return $text;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return true;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	$GLOBALS['cfqr_test_cache'] = array();
	function wp_cache_get( $key, $group = '' ) {
		return $GLOBALS['cfqr_test_cache'][ $group ][ $key ] ?? false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		$GLOBALS['cfqr_test_cache'][ $group ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['cfqr_test_cache'][ $group ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	$GLOBALS['cfqr_test_postmeta'] = array();
	function get_post_meta( $post_id, $key, $single = false ) {
		$val = $GLOBALS['cfqr_test_postmeta'][ $post_id ][ $key ] ?? '';
		return $single ? $val : array( $val );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		return true;
	}
}
if ( ! function_exists( 'gmdate' ) && false ) {
	// gmdate is native; the false guard keeps the block as documentation only.
}

require_once dirname( __DIR__ ) . '/includes/class-url.php';
require_once dirname( __DIR__ ) . '/includes/class-redirect-cpt.php';
require_once dirname( __DIR__ ) . '/includes/class-redirect-router.php';

// ---------- Tiny test framework --------------------------------------------

if ( ! isset( $GLOBALS['cfqr_test_passed'] ) ) {
	$GLOBALS['cfqr_test_passed'] = 0;
}
if ( ! isset( $GLOBALS['cfqr_test_failed'] ) ) {
	$GLOBALS['cfqr_test_failed'] = 0;
}

function cfqr_rd_assert( $description, $condition ) {
	if ( $condition ) {
		++$GLOBALS['cfqr_test_passed'];
		echo "  \033[32mPASS\033[0m  $description\n";
	} else {
		++$GLOBALS['cfqr_test_failed'];
		echo "  \033[31mFAIL\033[0m  $description\n";
	}
}

function cfqr_rd_assert_equal( $description, $expected, $actual ) {
	$ok = ( $expected === $actual );
	cfqr_rd_assert( $description, $ok );
	if ( ! $ok ) {
		echo '         expected: ' . var_export( $expected, true ) . "\n";
		echo '         actual:   ' . var_export( $actual, true ) . "\n";
	}
}

function cfqr_rd_section( $title ) {
	echo "\n== $title ==\n";
}

// Reflection helper for invoking private static methods. Plugin requires
// PHP 8.0+ — on 8.1+ private methods are accessible without setAccessible().
function cfqr_rd_invoke_private( $class, $method, array $args ) {
	$ref = new ReflectionMethod( $class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$ref->setAccessible( true );
	}
	return $ref->invoke( null, ...$args );
}

// ---------- Tests ----------------------------------------------------------

cfqr_rd_section( 'CFQR_Redirect_CPT::normalize_path' );

cfqr_rd_assert_equal( 'empty input becomes /', '/', CFQR_Redirect_CPT::normalize_path( '' ) );
cfqr_rd_assert_equal( 'whitespace-only becomes /', '/', CFQR_Redirect_CPT::normalize_path( '   ' ) );
cfqr_rd_assert_equal( 'leading slash added', '/foo', CFQR_Redirect_CPT::normalize_path( 'foo' ) );
cfqr_rd_assert_equal( 'trailing slash stripped', '/foo', CFQR_Redirect_CPT::normalize_path( '/foo/' ) );
cfqr_rd_assert_equal( 'root keeps single slash', '/', CFQR_Redirect_CPT::normalize_path( '/' ) );
cfqr_rd_assert_equal( 'consecutive slashes collapsed', '/foo/bar', CFQR_Redirect_CPT::normalize_path( '/foo//bar' ) );
cfqr_rd_assert_equal( 'case lowered', '/blog/post', CFQR_Redirect_CPT::normalize_path( '/Blog/POST' ) );
cfqr_rd_assert_equal( 'query string dropped', '/search', CFQR_Redirect_CPT::normalize_path( '/search?q=foo' ) );
cfqr_rd_assert_equal( 'fragment dropped', '/page', CFQR_Redirect_CPT::normalize_path( '/page#section' ) );
cfqr_rd_assert_equal( 'query and fragment dropped', '/page', CFQR_Redirect_CPT::normalize_path( '/page?q=1#section' ) );

cfqr_rd_section( 'CFQR_Redirect_CPT::sanitize_source_path' );

cfqr_rd_assert_equal( 'empty input passes through', '', CFQR_Redirect_CPT::sanitize_source_path( '' ) );
cfqr_rd_assert_equal( 'leading slash added', '/foo', CFQR_Redirect_CPT::sanitize_source_path( 'foo' ) );
cfqr_rd_assert_equal( 'own-host URL stripped to path', '/some/page', CFQR_Redirect_CPT::sanitize_source_path( 'https://example.test/some/page' ) );
cfqr_rd_assert_equal( 'own-host URL preserves query', '/search?q=foo', CFQR_Redirect_CPT::sanitize_source_path( 'https://example.test/search?q=foo' ) );
cfqr_rd_assert_equal( 'foreign-host URL left intact (leading-slash forced)', '/https://other.example/foo', CFQR_Redirect_CPT::sanitize_source_path( 'https://other.example/foo' ) );
cfqr_rd_assert_equal( 'wildcard preserved verbatim', '/blog/*', CFQR_Redirect_CPT::sanitize_source_path( '/blog/*' ) );
cfqr_rd_assert_equal( 'regex metachars preserved', '/(old|new)/(.*)', CFQR_Redirect_CPT::sanitize_source_path( '/(old|new)/(.*)' ) );
cfqr_rd_assert_equal( 'control chars stripped', '/foo', CFQR_Redirect_CPT::sanitize_source_path( "/foo\x00\x1F" ) );

cfqr_rd_section( 'CFQR_Redirect_CPT::sanitize_match_mode' );

cfqr_rd_assert_equal( 'exact accepted', 'exact', CFQR_Redirect_CPT::sanitize_match_mode( 'exact' ) );
cfqr_rd_assert_equal( 'wildcard accepted', 'wildcard', CFQR_Redirect_CPT::sanitize_match_mode( 'wildcard' ) );
cfqr_rd_assert_equal( 'regex accepted', 'regex', CFQR_Redirect_CPT::sanitize_match_mode( 'regex' ) );
cfqr_rd_assert_equal( 'query_aware accepted', 'query_aware', CFQR_Redirect_CPT::sanitize_match_mode( 'query_aware' ) );
cfqr_rd_assert_equal( 'garbage falls back to exact', 'exact', CFQR_Redirect_CPT::sanitize_match_mode( 'something_else' ) );
cfqr_rd_assert_equal( 'empty falls back to exact', 'exact', CFQR_Redirect_CPT::sanitize_match_mode( '' ) );

cfqr_rd_section( 'CFQR_Redirect_CPT::sanitize_status_code' );

foreach ( array( 301, 302, 307, 308 ) as $valid ) {
	cfqr_rd_assert_equal( "$valid kept", $valid, CFQR_Redirect_CPT::sanitize_status_code( $valid ) );
}
cfqr_rd_assert_equal( '404 rejected (falls back to 302)', 302, CFQR_Redirect_CPT::sanitize_status_code( 404 ) );
cfqr_rd_assert_equal( 'string "301" coerced to int', 301, CFQR_Redirect_CPT::sanitize_status_code( '301' ) );

cfqr_rd_section( 'CFQR_Redirect_Router::compile_wildcard_to_regex' );

$compile = function ( $src ) {
	return cfqr_rd_invoke_private( 'CFQR_Redirect_Router', 'compile_wildcard_to_regex', array( $src ) );
};
cfqr_rd_assert_equal( 'simple wildcard compiles', '#^/blog/(.*)$#i', $compile( '/blog/*' ) );
cfqr_rd_assert_equal( 'wildcard in middle', '#^/old/(.*)/page$#i', $compile( '/old/*/page' ) );
cfqr_rd_assert_equal( 'multiple wildcards', '#^/(.*)/(.*)$#i', $compile( '/*/*' ) );
cfqr_rd_assert_equal( 'normalizes trailing slash before compile', '#^/blog/(.*)$#i', $compile( '/blog/*/' ) );
cfqr_rd_assert_equal( 'root pattern rejected (would match everything)', null, $compile( '/' ) );
cfqr_rd_assert_equal( 'empty rejected', null, $compile( '' ) );

cfqr_rd_section( 'CFQR_Redirect_Router::compile_user_regex' );

cfqr_rd_assert_equal( 'simple regex compiles', '#^/old/(.*)$#', CFQR_Redirect_Router::compile_user_regex( '^/old/(.*)$' ) );
cfqr_rd_assert_equal( 'embedded # is escaped', '#/post\#anchor#', CFQR_Redirect_Router::compile_user_regex( '/post#anchor' ) );
cfqr_rd_assert_equal( 'malformed pattern returns null', null, CFQR_Redirect_Router::compile_user_regex( '[unclosed' ) );
cfqr_rd_assert_equal( 'empty pattern returns null', null, CFQR_Redirect_Router::compile_user_regex( '' ) );

cfqr_rd_section( 'CFQR_Redirect_Router::apply_captures' );

cfqr_rd_assert_equal(
	'simple substitution',
	'https://example.com/news/foo',
	CFQR_Redirect_Router::apply_captures( 'https://example.com/news/$1', array( '/blog/foo', 'foo' ) )
);
cfqr_rd_assert_equal(
	'multiple captures',
	'https://example.com/a/b',
	CFQR_Redirect_Router::apply_captures( 'https://example.com/$1/$2', array( '', 'a', 'b' ) )
);
cfqr_rd_assert_equal(
	'multi-digit index does not collide with single-digit',
	'https://example.com/eleven',
	CFQR_Redirect_Router::apply_captures( 'https://example.com/$10', array_pad( array(), 11, 'eleven' ) )
);
cfqr_rd_assert_equal(
	'missing index substitutes empty string',
	'https://example.com/',
	CFQR_Redirect_Router::apply_captures( 'https://example.com/$9', array( 'only-full-match' ) )
);
cfqr_rd_assert_equal(
	'empty captures array returns destination verbatim',
	'https://example.com/static',
	CFQR_Redirect_Router::apply_captures( 'https://example.com/static', array() )
);

cfqr_rd_section( 'Wildcard matcher integration' );

$mw = function ( $request_uri, $source ) {
	return cfqr_rd_invoke_private(
		'CFQR_Redirect_Router',
		'match_wildcard',
		array( $request_uri, array( 'id' => 42, 'source' => $source ) )
	);
};
$result = $mw( '/blog/2024/hello', '/blog/*' );
cfqr_rd_assert( 'matches /blog/* against /blog/2024/hello', is_array( $result ) && 42 === $result['id'] );
cfqr_rd_assert_equal( '   capture[1] is the wildcard portion', '2024/hello', $result['matches'][1] ?? null );

$result = $mw( '/Blog/Foo', '/blog/*' );
cfqr_rd_assert( 'case-insensitive: /Blog/Foo matches /blog/*', is_array( $result ) );

$result = $mw( '/news/foo', '/blog/*' );
cfqr_rd_assert_equal( 'non-matching path returns null', null, $result );

$result = $mw( '/old/abc/page', '/old/*/page' );
cfqr_rd_assert( 'wildcard in middle captures middle segment', is_array( $result ) && 'abc' === ( $result['matches'][1] ?? null ) );

cfqr_rd_section( 'Regex matcher integration' );

$mr = function ( $request_uri, $source ) {
	return cfqr_rd_invoke_private(
		'CFQR_Redirect_Router',
		'match_regex',
		array( $request_uri, array( 'id' => 7, 'source' => $source ) )
	);
};
$result = $mr( '/old/widget-42.html', '^/old/widget-(\d+)\.html$' );
cfqr_rd_assert( 'matches anchored regex', is_array( $result ) && 7 === $result['id'] );
cfqr_rd_assert_equal( '   capture[1] is the digits', '42', $result['matches'][1] ?? null );

$result = $mr( '/something-else', '^/old/(.*)$' );
cfqr_rd_assert_equal( 'non-matching returns null', null, $result );

$result = $mr( '/foo', '[unclosed' );
cfqr_rd_assert_equal( 'malformed pattern returns null (no PHP warning)', null, $result );

cfqr_rd_section( 'Query-aware matcher integration' );

$mq = function ( $request_uri, $source ) {
	return cfqr_rd_invoke_private(
		'CFQR_Redirect_Router',
		'match_query_aware',
		array( $request_uri, array( 'id' => 9, 'source' => $source ) )
	);
};
$result = $mq( '/search?q=foo', '/search?q=foo' );
cfqr_rd_assert( 'exact path+query matches', is_array( $result ) && 9 === $result['id'] );

$result = $mq( '/search?q=foo&page=2', '/search?q=foo' );
cfqr_rd_assert( 'extra query params on request still matches (subset)', is_array( $result ) );

$result = $mq( '/search?q=bar', '/search?q=foo' );
cfqr_rd_assert_equal( 'wrong query value returns null', null, $result );

$result = $mq( '/search', '/search?q=foo' );
cfqr_rd_assert_equal( 'missing required query param returns null', null, $result );

$result = $mq( '/different?q=foo', '/search?q=foo' );
cfqr_rd_assert_equal( 'different path returns null', null, $result );

$result = $mq( '/Search?q=foo', '/search?q=foo' );
cfqr_rd_assert( 'case-insensitive path match', is_array( $result ) );

cfqr_rd_section( 'CFQR_Redirect_CPT::sanitize_iso8601' );

cfqr_rd_assert_equal( 'empty input passes through', '', CFQR_Redirect_CPT::sanitize_iso8601( '' ) );
cfqr_rd_assert_equal( 'whitespace becomes empty', '', CFQR_Redirect_CPT::sanitize_iso8601( '   ' ) );
cfqr_rd_assert_equal( 'datetime-local format converts to UTC Z', '2026-06-01T14:30:00Z', CFQR_Redirect_CPT::sanitize_iso8601( '2026-06-01T14:30' ) );
cfqr_rd_assert_equal( 'invalid input becomes empty', '', CFQR_Redirect_CPT::sanitize_iso8601( 'not a date' ) );
$round_trip = CFQR_Redirect_CPT::sanitize_iso8601( CFQR_Redirect_CPT::sanitize_iso8601( '2026-06-01T14:30' ) );
cfqr_rd_assert_equal( 'sanitize is idempotent (round trip)', '2026-06-01T14:30:00Z', $round_trip );

cfqr_rd_section( 'CFQR_Redirect_CPT::is_within_schedule' );

cfqr_rd_assert( 'empty bounds = always active', CFQR_Redirect_CPT::is_within_schedule( '', '', strtotime( '2026-06-15T12:00:00Z' ) ) );
cfqr_rd_assert( 'before from = inactive', ! CFQR_Redirect_CPT::is_within_schedule( '2026-06-01T00:00:00Z', '', strtotime( '2026-05-31T23:59:59Z' ) ) );
cfqr_rd_assert( 'after from = active', CFQR_Redirect_CPT::is_within_schedule( '2026-06-01T00:00:00Z', '', strtotime( '2026-06-01T00:00:01Z' ) ) );
cfqr_rd_assert( 'before until = active', CFQR_Redirect_CPT::is_within_schedule( '', '2026-06-30T00:00:00Z', strtotime( '2026-06-29T23:59:59Z' ) ) );
cfqr_rd_assert( 'at until = inactive (exclusive upper bound)', ! CFQR_Redirect_CPT::is_within_schedule( '', '2026-06-30T00:00:00Z', strtotime( '2026-06-30T00:00:00Z' ) ) );
cfqr_rd_assert( 'inside both bounds = active', CFQR_Redirect_CPT::is_within_schedule( '2026-06-01T00:00:00Z', '2026-06-30T00:00:00Z', strtotime( '2026-06-15T12:00:00Z' ) ) );
cfqr_rd_assert( 'before window with both bounds = inactive', ! CFQR_Redirect_CPT::is_within_schedule( '2026-06-01T00:00:00Z', '2026-06-30T00:00:00Z', strtotime( '2026-05-15T12:00:00Z' ) ) );
cfqr_rd_assert( 'after window with both bounds = inactive', ! CFQR_Redirect_CPT::is_within_schedule( '2026-06-01T00:00:00Z', '2026-06-30T00:00:00Z', strtotime( '2026-07-15T12:00:00Z' ) ) );
cfqr_rd_assert( 'unparseable from = inactive (fail safe)', ! CFQR_Redirect_CPT::is_within_schedule( 'garbage', '', strtotime( '2026-06-15T12:00:00Z' ) ) );

cfqr_rd_section( 'CSV importer: row extraction + validation' );

// Load just the importer methods we want to exercise. Avoid requiring the
// full file because admin_menu / admin_post hooks would fire on include —
// but the class itself only registers hooks inside init(), not at file-load,
// so requiring it is safe here.
require_once dirname( __DIR__ ) . '/includes/class-redirect-import.php';

$header_index = array_flip( array( 'source', 'destination', 'mode', 'status', 'active', 'groups', 'label', 'active_from', 'active_until' ) );
$parsed = CFQR_Redirect_Import::extract_row(
	array( '/old', 'https://example.com/new', 'exact', '301', '1', 'mig-2026', 'Old', '', '' ),
	$header_index
);
cfqr_rd_assert_equal( 'extract_row pulls source', '/old', $parsed['source'] );
cfqr_rd_assert_equal( 'extract_row pulls status', '301', $parsed['status'] );
cfqr_rd_assert_equal( 'extract_row pulls groups', 'mig-2026', $parsed['groups'] );

$parsed_partial = CFQR_Redirect_Import::extract_row(
	array( '/x', 'https://example.com/y' ),
	array_flip( array( 'source', 'destination' ) )
);
cfqr_rd_assert_equal( 'extract_row defaults missing columns to empty', '', $parsed_partial['status'] );
cfqr_rd_assert_equal( 'extract_row keeps present columns', '/x', $parsed_partial['source'] );

cfqr_rd_assert_equal(
	'wildcard mode without * is rejected',
	'wildcard mode requires at least one * in the source.',
	CFQR_Redirect_Import::validate_source_for_import( '/blog/foo', CFQR_Redirect_CPT::MATCH_WILDCARD )
);
cfqr_rd_assert_equal(
	'wildcard mode with * is accepted',
	null,
	CFQR_Redirect_Import::validate_source_for_import( '/blog/*', CFQR_Redirect_CPT::MATCH_WILDCARD )
);
cfqr_rd_assert_equal(
	'query_aware mode without ? is rejected',
	'query_aware mode requires a query string in the source.',
	CFQR_Redirect_Import::validate_source_for_import( '/search', CFQR_Redirect_CPT::MATCH_QUERY_AWARE )
);
cfqr_rd_assert_equal(
	'query_aware mode with ? is accepted',
	null,
	CFQR_Redirect_Import::validate_source_for_import( '/search?q=foo', CFQR_Redirect_CPT::MATCH_QUERY_AWARE )
);
cfqr_rd_assert_equal(
	'reserved /wp-admin source is rejected',
	'source starts with reserved segment "wp-admin".',
	CFQR_Redirect_Import::validate_source_for_import( '/wp-admin/users.php', CFQR_Redirect_CPT::MATCH_EXACT )
);
cfqr_rd_assert_equal(
	'regex mode validates pattern compiles',
	'regex pattern does not compile.',
	CFQR_Redirect_Import::validate_source_for_import( '[unclosed', CFQR_Redirect_CPT::MATCH_REGEX )
);
cfqr_rd_assert_equal(
	'regex mode accepts valid pattern',
	null,
	CFQR_Redirect_Import::validate_source_for_import( '^/old/(\d+)$', CFQR_Redirect_CPT::MATCH_REGEX )
);
cfqr_rd_assert_equal(
	'root path is rejected',
	'source path "/" is too broad to redirect.',
	CFQR_Redirect_Import::validate_source_for_import( '/', CFQR_Redirect_CPT::MATCH_EXACT )
);

cfqr_rd_section( '404 capture: path hashing consistency' );

cfqr_rd_assert_equal(
	'sha1 of normalized path is stable',
	sha1( '/some/path' ),
	sha1( CFQR_Redirect_CPT::normalize_path( '/Some/Path/' ) )
);
cfqr_rd_assert(
	'sha1 differs for different paths',
	sha1( CFQR_Redirect_CPT::normalize_path( '/foo' ) ) !== sha1( CFQR_Redirect_CPT::normalize_path( '/bar' ) )
);
cfqr_rd_assert_equal(
	'sha1 is fixed-length (40 hex chars)',
	40,
	strlen( sha1( CFQR_Redirect_CPT::normalize_path( '/anything' ) ) )
);

cfqr_rd_section( 'CFQR_Redirect_Router::would_create_loop' );

// Prime the exact-match map and the per-post destinations the walker reads.
// Map keys are normalized source paths; values carry the post id. The walker
// follows each hop's destination meta to the next normalized path.
$GLOBALS['cfqr_test_cache'][ CFQR_Redirect_Router::CACHE_GROUP ][ CFQR_Redirect_Router::CACHE_KEY_EXACT ] = array(
	'/b' => array( 'id' => 2, 'from' => '', 'until' => '' ),
	'/c' => array( 'id' => 3, 'from' => '', 'until' => '' ),
	'/d' => array( 'id' => 4, 'from' => '', 'until' => '' ),
);
$GLOBALS['cfqr_test_postmeta'][2][ CFQR_Redirect_CPT::META_DESTINATION ] = 'https://example.test/a'; // B → A (closes a 2-hop cycle)
$GLOBALS['cfqr_test_postmeta'][3][ CFQR_Redirect_CPT::META_DESTINATION ] = 'https://example.test/d'; // C → D
$GLOBALS['cfqr_test_postmeta'][4][ CFQR_Redirect_CPT::META_DESTINATION ] = 'https://example.test/c'; // D → C (cycle not involving the source)

cfqr_rd_assert(
	'direct self-loop (A → A) is detected',
	CFQR_Redirect_Router::would_create_loop( '/a', 'https://example.test/a' )
);
cfqr_rd_assert(
	'multi-hop cycle (A → B → A) is detected',
	CFQR_Redirect_Router::would_create_loop( '/a', 'https://example.test/b' )
);
cfqr_rd_assert(
	'destination not in chain does not loop',
	! CFQR_Redirect_Router::would_create_loop( '/a', 'https://example.test/unmapped' )
);
cfqr_rd_assert(
	'cross-host destination cannot loop',
	! CFQR_Redirect_Router::would_create_loop( '/a', 'https://other.example/a' )
);
cfqr_rd_assert(
	'pre-existing cycle that excludes the source terminates and does not block',
	! CFQR_Redirect_Router::would_create_loop( '/a', 'https://example.test/c' )
);
cfqr_rd_assert(
	'destination path equal to source (trailing slash) still detected after normalization',
	CFQR_Redirect_Router::would_create_loop( '/a', 'https://example.test/a/' )
);

// ---------- Report ---------------------------------------------------------

echo "\n";
echo 'Tests run:    ' . ( $GLOBALS['cfqr_test_passed'] + $GLOBALS['cfqr_test_failed'] ) . "\n";
echo 'Tests passed: ' . $GLOBALS['cfqr_test_passed'] . "\n";
echo 'Tests failed: ' . $GLOBALS['cfqr_test_failed'] . "\n";

// Only set exit code if this file is the entry point — when chained from
// test-suite.php we let the parent decide.
if ( realpath( __FILE__ ) === realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) ) {
	exit( $GLOBALS['cfqr_test_failed'] > 0 ? 1 : 0 );
}
