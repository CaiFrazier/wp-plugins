<?php
/**
 * PHPUnit bootstrap.
 *
 * No WordPress install required — every WP function the classes under test
 * touch is shimmed below or made overrideable through $GLOBALS state, in the
 * same style as the BME / cf-media-manager suites. Tests call
 * som_test_reset_state() in setUp() and steer behavior via $GLOBALS['som_test_*'].
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'SOM_VERSION', 'test' );

// Silence error_log() so the shared Logger's mirror line doesn't pollute
// stderr (PHPUnit's failOnRisky would otherwise flag it).
@ini_set( 'error_log', '/dev/null' );

// -----------------------------------------------------------------------------
// Plugin-detection markers. Defining these makes YoastIntegration /
// RankMathIntegration report active, so suppression hooks register in tests.
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WPSEO_Options' ) ) {
	class WPSEO_Options {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile
}
if ( ! class_exists( 'RankMath' ) ) {
	class RankMath {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile
}

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

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $value ) {
		$value = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $value );
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^(javascript|data|vbscript):#i', $url ) ) {
			return '';
		}
		return $url;
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

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text ) {
		return esc_html( $text );
	}
}

if ( ! function_exists( '__return_empty_array' ) ) {
	function __return_empty_array() {
		return [];
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false() {
		return false;
	}
}

// -----------------------------------------------------------------------------
// Hook system — record but don't dispatch.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['som_test_hooks'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['som_test_filters'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action() { /* no-op */ }
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		$filters = $GLOBALS['som_test_filters'][ $hook ] ?? [];
		// Priority order, matching WP. Registrations are [cb, priority, argc].
		usort( $filters, static fn( $a, $b ) => ( $a[1] ?? 10 ) <=> ( $b[1] ?? 10 ) );
		foreach ( $filters as $registered ) {
			$value = $registered[0]( $value, ...$args );
		}
		return $value;
	}
}

// -----------------------------------------------------------------------------
// Options / meta / transients — in-memory stores keyed in $GLOBALS.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['som_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['som_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['som_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['som_test_postmeta'][ $post_id ][ $key ] ?? ( $single ? '' : [] );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['som_test_postmeta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['som_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['som_test_transients'][ $key ]     = $value;
		$GLOBALS['som_test_transient_ttls'][ $key ] = $ttl;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset(
			$GLOBALS['som_test_transients'][ $key ],
			$GLOBALS['som_test_transient_ttls'][ $key ]
		);
		return true;
	}
}

// -----------------------------------------------------------------------------
// Capabilities / users
// -----------------------------------------------------------------------------

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, $object_id = null ) {
		$caps = $GLOBALS['som_test_caps'] ?? true;
		if ( is_callable( $caps ) ) {
			return (bool) $caps( $cap, $object_id );
		}
		return (bool) $caps;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['som_test_user_id'] ?? 1 );
	}
}

// -----------------------------------------------------------------------------
// Posts / permalinks / conditional tags
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		public $ID          = 0;
		public $post_status = 'publish';
		public $post_type   = 'post';

		public function __construct( array $props = [] ) {
			foreach ( $props as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['som_test_posts'][ (int) $post_id ] ?? null;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id ) {
		$post = $GLOBALS['som_test_posts'][ (int) $post_id ] ?? null;
		return $post->post_type ?? false;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) {
		return $GLOBALS['som_test_permalinks'][ (int) $post_id ]
			?? 'https://example.test/?p=' . (int) $post_id;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return ( $GLOBALS['som_test_home_url'] ?? 'https://example.test' ) . $path;
	}
}

if ( ! function_exists( 'is_singular' ) ) {
	function is_singular() {
		return (bool) ( $GLOBALS['som_test_is_singular'] ?? false );
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page() {
		return (bool) ( $GLOBALS['som_test_is_front_page'] ?? false );
	}
}

if ( ! function_exists( 'is_home' ) ) {
	function is_home() {
		return (bool) ( $GLOBALS['som_test_is_home'] ?? false );
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() {
		return (int) ( $GLOBALS['som_test_queried_object_id'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post_id ) {
		// Tests set $GLOBALS['som_test_revisions'][ child_id ] = parent_id.
		return $GLOBALS['som_test_revisions'][ (int) $post_id ] ?? false;
	}
}

// -----------------------------------------------------------------------------
// HTTP — responder injected via $GLOBALS['som_test_http'] (array or callable);
// every call is recorded so tests can assert fetch counts.
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		private string $message;

		public function __construct( $code = '', $message = '' ) {
			$this->message = (string) $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = [] ) {
		$GLOBALS['som_test_http_calls'][] = [ 'url' => $url, 'args' => $args ];
		$responder = $GLOBALS['som_test_http'] ?? null;
		if ( is_callable( $responder ) ) {
			return $responder( $url, $args );
		}
		return $responder ?? [ 'code' => 200, 'body' => '' ];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? ( $response['body'] ?? '' ) : '';
	}
}

// -----------------------------------------------------------------------------
// REST shims
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		private array $params;
		private string $body;
		private string $route;

		public function __construct( array $params = [], string $body = '', string $route = '/som/v1/test' ) {
			$this->params = $params;
			$this->body   = $body;
			$this->route  = $route;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_json_params() {
			return json_decode( $this->body, true );
		}

		public function get_body(): string {
			return $this->body;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		private $data;
		private int $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return $response instanceof WP_REST_Response
			? $response
			: new WP_REST_Response( $response, 200 );
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = [], $override = false ) {
		$GLOBALS['som_test_routes'][] = [
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		];
		return true;
	}
}

// -----------------------------------------------------------------------------
// Uploads — "error" shape makes the shared Logger skip file writes.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return [ 'error' => 'no-uploads-in-tests', 'basedir' => '', 'baseurl' => '' ];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		return false;
	}
}

// -----------------------------------------------------------------------------
// Reset state between tests. Tests call this in setUp().
// -----------------------------------------------------------------------------

function som_test_reset_state(): void {
	$GLOBALS['som_test_options']           = [];
	$GLOBALS['som_test_postmeta']          = [];
	$GLOBALS['som_test_transients']        = [];
	$GLOBALS['som_test_transient_ttls']    = [];
	$GLOBALS['som_test_hooks']             = [];
	$GLOBALS['som_test_filters']           = [];
	$GLOBALS['som_test_routes']            = [];
	$GLOBALS['som_test_caps']              = true;
	$GLOBALS['som_test_user_id']           = 1;
	$GLOBALS['som_test_posts']             = [];
	$GLOBALS['som_test_permalinks']        = [];
	$GLOBALS['som_test_home_url']          = 'https://example.test';
	$GLOBALS['som_test_is_singular']       = false;
	$GLOBALS['som_test_is_front_page']     = false;
	$GLOBALS['som_test_is_home']           = false;
	$GLOBALS['som_test_queried_object_id'] = 0;
	$GLOBALS['som_test_http']              = null;
	$GLOBALS['som_test_http_calls']        = [];
	$GLOBALS['som_test_revisions']         = [];
}

som_test_reset_state();

// -----------------------------------------------------------------------------
// Composer autoload — needed for CFShared\Logger + plugin classes.
// -----------------------------------------------------------------------------

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
} else {
	// Fallback so the suite is still discoverable without `composer install`.
	$includes = __DIR__ . '/../includes';
	foreach ( [
		'Util',
		'Sanitizer',
		'Settings',
		'SchemaOutput',
		'Suppressor',
		'SchemaDetector',
		'RestController',
		'Logger',
		'Integrations/YoastIntegration',
		'Integrations/RankMathIntegration',
		'Integrations/ThemeIntegration',
	] as $cls ) {
		$path = $includes . '/' . $cls . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
