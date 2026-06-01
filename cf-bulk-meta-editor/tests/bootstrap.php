<?php
/**
 * PHPUnit bootstrap.
 *
 * No WordPress install required — every WP function the classes under test
 * touch is shimmed below or made overrideable through $GLOBALS state. This
 * keeps the suite fast (`composer install && composer test`) and isolates
 * each test from real DB/options state.
 *
 * For tests that need to inject behavior at runtime (e.g. simulate a
 * permission check failing), set $GLOBALS['bme_test_*'] before invoking
 * the code under test. The shims read those globals on every call.
 */

define( 'ABSPATH', __DIR__ . '/' );

// Silence error_log() so the shared Logger's mirror line doesn't pollute
// stderr (PHPUnit's failOnRisky would otherwise flag it). Setting threshold
// to ERROR in test setUp() prevents it in most cases — this is defense in
// depth in case an error-level call slips through.
@ini_set( 'error_log', '/dev/null' );

// -----------------------------------------------------------------------------
// String / sanitization
// -----------------------------------------------------------------------------

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		$value = is_string( $value ) ? $value : '';
		$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
		$value = strip_tags( $value );
		return trim( $value );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		$value = is_string( $value ) ? $value : '';
		$value = strip_tags( $value );
		// Real WP sanitize_textarea_field preserves newlines but collapses
		// runs of horizontal whitespace. Close enough for boundary tests.
		$value = preg_replace( '/[ \t]+/', ' ', $value );
		return trim( $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	// Approximation of wp_kses_post for tests — strips script/style/iframe
	// and on*= event handlers but allows other tags + attributes. Real WP
	// is stricter; this is sufficient to assert that the dispatcher routes
	// 'html' through a stripping pipeline.
	function wp_kses_post( $value ) {
		$value = is_string( $value ) ? $value : '';
		$value = preg_replace( '#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $value );
		$value = preg_replace( '#<(script|style|iframe|object|embed)\b[^>]*/?>#i', '', $value );
		$value = preg_replace( '#\son\w+\s*=\s*"[^"]*"#i', '', $value );
		$value = preg_replace( "#\son\w+\s*=\s*'[^']*'#i", '', $value );
		return $value;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url ) {
			return '';
		}
		// Strip dangerous schemes that WP would reject.
		if ( preg_match( '#^(javascript|data|vbscript):#i', $url ) ) {
			return '';
		}
		return $url;
	}
}

// -----------------------------------------------------------------------------
// Hook system — record but don't dispatch.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['bme_test_hooks'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['bme_test_filters'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action() { /* no-op for these unit tests */ }
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	function register_setting() { /* recorded indirectly via add_action */ }
}

// -----------------------------------------------------------------------------
// Options / meta — back-of-the-envelope in-memory store keyed in $GLOBALS.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['bme_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value ) {
		$GLOBALS['bme_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['bme_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		$value = $GLOBALS['bme_test_postmeta'][ $post_id ][ $key ] ?? ( $single ? '' : [] );
		return $value;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$existing = $GLOBALS['bme_test_postmeta'][ $post_id ][ $key ] ?? null;
		if ( $existing === $value ) {
			// Real WP returns false when value is unchanged.
			return false;
		}
		$GLOBALS['bme_test_postmeta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return $GLOBALS['bme_test_usermeta'][ $user_id ][ $key ] ?? ( $single ? '' : [] );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['bme_test_usermeta'][ $user_id ][ $key ] = $value;
		return true;
	}
}

// -----------------------------------------------------------------------------
// Capabilities — tests set $GLOBALS['bme_test_caps'] to control behavior.
// Two shapes supported:
//   - bool: every cap check returns this value.
//   - callable( string $cap, int|null $object_id ): bool
// -----------------------------------------------------------------------------

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, $object_id = null ) {
		$caps = $GLOBALS['bme_test_caps'] ?? true;
		if ( is_callable( $caps ) ) {
			return (bool) $caps( $cap, $object_id );
		}
		return (bool) $caps;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['bme_test_user_id'] ?? 0 );
	}
}

// -----------------------------------------------------------------------------
// Post-type registry — tests inject types via $GLOBALS.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = [], $output = 'names' ) {
		$registered = $GLOBALS['bme_test_post_types'] ?? [
			'post' => (object) [ 'name' => 'post', 'label' => 'Posts', 'public' => true ],
			'page' => (object) [ 'name' => 'page', 'label' => 'Pages', 'public' => true ],
		];
		$out = [];
		foreach ( $registered as $name => $obj ) {
			if ( isset( $args['public'] ) && ! empty( $obj->public ) !== (bool) $args['public'] ) {
				continue;
			}
			$out[ $name ] = ( 'objects' === $output ) ? $obj : $name;
		}
		return $out;
	}
}

// -----------------------------------------------------------------------------
// REST shims — record routes so tests can introspect what got registered.
// The constants WP_REST_Server::READABLE etc. are normally provided by core.
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
		const EDITABLE  = 'POST, PUT, PATCH';
		const DELETABLE = 'DELETE';
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = [], $override = false ) {
		$GLOBALS['bme_test_routes'][] = [
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		];
		return true;
	}
}

// -----------------------------------------------------------------------------
// Cache + minor utilities
// -----------------------------------------------------------------------------

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set() { return true; }
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get() { return false; }
}
if ( ! function_exists( 'cache_users' ) ) {
	function cache_users() { /* no-op */ }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		// Tests don't need real uploads. Returning an "error" makes the shared
		// Logger short-circuit its path() and skip file writes.
		return [ 'error' => 'no-uploads-in-tests', 'basedir' => '', 'baseurl' => '' ];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) { return false; }
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		$out = [];
		foreach ( $list as $row ) {
			$out[] = is_object( $row ) ? ( $row->$field ?? null ) : ( $row[ $field ] ?? null );
		}
		return $out;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text ) { return esc_html( $text ); }
}

// -----------------------------------------------------------------------------
// Reset state between tests. Tests call this in setUp().
// -----------------------------------------------------------------------------

function bme_test_reset_state(): void {
	$GLOBALS['bme_test_options']    = [];
	$GLOBALS['bme_test_postmeta']   = [];
	$GLOBALS['bme_test_usermeta']   = [];
	$GLOBALS['bme_test_hooks']      = [];
	$GLOBALS['bme_test_filters']    = [];
	$GLOBALS['bme_test_routes']     = [];
	$GLOBALS['bme_test_caps']       = true;
	$GLOBALS['bme_test_user_id']    = 1;
	$GLOBALS['bme_test_post_types'] = null;
}

bme_test_reset_state();

// -----------------------------------------------------------------------------
// Composer autoload — needed for shared CFShared\Logger.
// -----------------------------------------------------------------------------

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
} else {
	// Fallback so the suite is still discoverable without `composer install`.
	$includes = __DIR__ . '/../includes';
	foreach ( [ 'Settings', 'PostDataService', 'RestController', 'CsvExporter' ] as $cls ) {
		$path = $includes . '/' . $cls . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
