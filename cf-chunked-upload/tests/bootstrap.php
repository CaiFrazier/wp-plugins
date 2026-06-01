<?php
/**
 * PHPUnit bootstrap.
 *
 * No WordPress install required — every WP function the classes under test
 * touch is shimmed below or routed through $GLOBALS state so tests can inject
 * behavior at runtime. Tests call cf_chunked_upload_test_reset_state() in
 * setUp() to start clean. Classes that touch the filesystem build real
 * fixture files under a per-test temp directory.
 */

define( 'ABSPATH', __DIR__ . '/' );

@ini_set( 'error_log', '/dev/null' );

// -----------------------------------------------------------------------------
// String / sanitization
// -----------------------------------------------------------------------------

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
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

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $name ) {
		$name = (string) $name;
		$name = preg_replace( '/[^A-Za-z0-9._-]/', '-', $name );
		$name = preg_replace( '/-+/', '-', $name );
		$name = preg_replace( '/\.+/', '.', $name );
		$name = trim( $name, '.-' );
		return '' === $name ? 'file' : $name;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) {
		return abs( (int) $n );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ),
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
	}
}

// -----------------------------------------------------------------------------
// Filesystem
// -----------------------------------------------------------------------------

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return $GLOBALS['cf_chunked_upload_test_uploads'] ?? [
			'basedir' => sys_get_temp_dir() . '/cf-cu-uploads',
			'baseurl' => 'http://example.test/wp-content/uploads',
			'error'   => false,
		];
	}
}

// -----------------------------------------------------------------------------
// Hooks / cron — recorded but not dispatched.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['cf_chunked_upload_test_hooks'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() { /* recorded indirectly */ }
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action() { /* no-op in tests */ }
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {
		unset( $GLOBALS['cf_chunked_upload_test_cron'][ $hook ] );
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['cf_chunked_upload_test_cron'][ $hook ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		$GLOBALS['cf_chunked_upload_test_cron'][ $hook ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook ) {
		$GLOBALS['cf_chunked_upload_test_cron'][ $hook ] = $timestamp;
		return true;
	}
}

// -----------------------------------------------------------------------------
// Options — in-memory, with atomic add_option semantics for lock primitives.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['cf_chunked_upload_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['cf_chunked_upload_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $name, $GLOBALS['cf_chunked_upload_test_options'] ?? [] ) ) {
			return false;
		}
		$GLOBALS['cf_chunked_upload_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['cf_chunked_upload_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $expiration = 0 ) {
		$GLOBALS['cf_chunked_upload_test_transients'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		return $GLOBALS['cf_chunked_upload_test_transients'][ $name ] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		unset( $GLOBALS['cf_chunked_upload_test_transients'][ $name ] );
		return true;
	}
}

// -----------------------------------------------------------------------------
// Capabilities / i18n / JSON
// -----------------------------------------------------------------------------

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		return in_array( $cap, $GLOBALS['cf_chunked_upload_test_caps'] ?? [], true );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['cf_chunked_upload_test_current_user'] ?? 0;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = null ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $str ) ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$bytes = (float) $bytes;
		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return round( $bytes, $decimals ) . ' ' . $units[ $i ];
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $value ) {
		if ( is_string( $value ) && strlen( $value ) > 1 && ':' === ( $value[1] ?? '' ) ) {
			$r = @unserialize( $value );
			return false !== $r ? $r : $value;
		}
		return $value;
	}
}

// -----------------------------------------------------------------------------
// $wpdb stub — Cleanup::run() uses it to sweep expired finalize locks.
// Operates directly against $GLOBALS['cf_chunked_upload_test_options'].
// -----------------------------------------------------------------------------

class CfCuTestWpdb {

	/** @var string Table alias (not used in queries here, but referenced as a property). */
	public string $options = 'wp_options';

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		return str_replace( '%s', "'" . addslashes( (string) ( $args[0] ?? '' ) ) . "'", $query );
	}

	/** Return stdClass rows from the in-memory options store matching a LIKE prefix. */
	public function get_results( string $sql ): array {
		if ( ! preg_match( "/LIKE\s+'([^'%]+)%'/i", $sql, $m ) ) {
			return [];
		}
		$prefix = stripslashes( $m[1] );
		$rows   = [];
		foreach ( $GLOBALS['cf_chunked_upload_test_options'] ?? [] as $name => $value ) {
			if ( 0 === strpos( (string) $name, $prefix ) ) {
				$row               = new \stdClass();
				$row->option_name  = (string) $name;
				$row->option_value = $value; // PHP value; maybe_unserialize returns it as-is
				$rows[]            = $row;
			}
		}
		return $rows;
	}
}

// -----------------------------------------------------------------------------
// Reset helper — tests call this in setUp().
// -----------------------------------------------------------------------------

function cf_chunked_upload_test_reset_state(): void {
	$GLOBALS['cf_chunked_upload_test_options']      = [];
	$GLOBALS['cf_chunked_upload_test_transients']   = [];
	$GLOBALS['cf_chunked_upload_test_hooks']        = [];
	$GLOBALS['cf_chunked_upload_test_cron']         = [];
	$GLOBALS['cf_chunked_upload_test_caps']         = [];
	$GLOBALS['cf_chunked_upload_test_current_user'] = 0;
	unset( $GLOBALS['cf_chunked_upload_test_uploads'] );
	$GLOBALS['wpdb'] = new CfCuTestWpdb();
	// OPT-2: clear HostInfo snapshot cache between tests. The guard is needed
	// because this function is called before the autoloader runs.
	if ( class_exists( 'CFChunkedUpload\\HostInfo' ) ) {
		\CFChunkedUpload\HostInfo::reset_cache();
	}
}

cf_chunked_upload_test_reset_state();

// Composer autoloader — falls back to manual require if vendor/ is missing so
// the suite runs without `composer install`.
$autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
} else {
	$includes = __DIR__ . '/../includes';
	require_once $includes . '/Paths.php';
	require_once $includes . '/Integrity.php';
	require_once $includes . '/UploadSession.php';
	require_once $includes . '/ChunkReceiver.php';
	require_once $includes . '/Assembler.php';
	require_once $includes . '/Cleanup.php';
	require_once $includes . '/HostInfo.php';
	require_once $includes . '/Settings.php';
	require_once $includes . '/JobStatus.php';
	require_once $includes . '/RateLimit.php';
	require_once $includes . '/UserQuota.php';
	require_once $includes . '/FinalizeJob.php';
	require_once $includes . '/Capabilities.php';
	require_once $includes . '/Preflight.php';
}
