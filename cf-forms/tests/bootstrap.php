<?php
/**
 * PHPUnit bootstrap.
 *
 * No WordPress install required — every WP function the classes under test
 * touch is shimmed below or made overrideable through $GLOBALS state, in the
 * same style as the schema-override-manager suite. Tests call
 * cff_test_reset_state() in setUp() and steer behavior via $GLOBALS['cff_test_*'].
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'CFF_VERSION', 'test' );
define( 'HOUR_IN_SECONDS', 3600 );

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

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return filter_var( $value, FILTER_SANITIZE_EMAIL );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $value ) {
		return (bool) filter_var( (string) $value, FILTER_VALIDATE_EMAIL );
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

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55 ) {
		$words = preg_split( '/\s+/', trim( (string) $text ) );
		if ( count( $words ) <= $num_words ) {
			return $text;
		}
		return implode( ' ', array_slice( $words, 0, $num_words ) ) . '…';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
		return json_encode( $value, $flags, $depth );
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

// -----------------------------------------------------------------------------
// Hook system — record but don't dispatch.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['cff_test_hooks'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['cff_test_filters'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['cff_test_actions'][] = [ 'hook' => $hook, 'args' => $args ];
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		$filters = $GLOBALS['cff_test_filters'][ $hook ] ?? [];
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
		return $GLOBALS['cff_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['cff_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['cff_test_postmeta'][ $post_id ][ $key ] ?? ( $single ? '' : [] );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['cff_test_postmeta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['cff_test_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['cff_test_transients'][ $key ]     = $value;
		$GLOBALS['cff_test_transient_ttls'][ $key ] = $ttl;
		return true;
	}
}

// -----------------------------------------------------------------------------
// Posts
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		public $ID          = 0;
		public $post_status = 'publish';
		public $post_type   = 'post';
		public $post_title  = '';
		public $post_date   = '';

		public function __construct( array $props = [] ) {
			foreach ( $props as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		if ( ! empty( $GLOBALS['cff_test_insert_post_fails'] ) ) {
			return $wp_error ? new WP_Error( 'db_insert_error', 'forced failure in test' ) : 0;
		}
		$id                                  = ++$GLOBALS['cff_test_next_post_id'];
		$GLOBALS['cff_test_posts'][ $id ]    = new WP_Post( array_merge( $postarr, [ 'ID' => $id ] ) );
		$GLOBALS['cff_test_inserted_posts'][] = $postarr;
		return $id;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return '2026-01-01 00:00:00';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
}

// -----------------------------------------------------------------------------
// Mail
// -----------------------------------------------------------------------------

if ( ! function_exists( 'wp_mail' ) ) {
	function wp_mail( $to, $subject, $message, $headers = '', $attachments = [] ) {
		$GLOBALS['cff_test_mail'][] = compact( 'to', 'subject', 'message', 'headers' );
		return $GLOBALS['cff_test_mail_result'] ?? true;
	}
}

// -----------------------------------------------------------------------------
// Errors
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		private string $code;
		private string $message;
		private array $data;

		public function __construct( $code = '', $message = '', $data = [] ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = (array) $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// -----------------------------------------------------------------------------
// REST shims
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		private array $json;
		private array $params;

		public function __construct( array $json = [], array $params = [] ) {
			$this->json   = $json;
			$this->params = $params;
		}

		public function get_json_params() {
			return $this->json;
		}

		public function get_params() {
			return $this->params;
		}

		public function get_param( $key ) {
			return $this->json[ $key ] ?? $this->params[ $key ] ?? null;
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
		$GLOBALS['cff_test_routes'][] = [
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		];
		return true;
	}
}

// -----------------------------------------------------------------------------
// CPT registration — recorded only, no real taxonomy engine needed for tests.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( $slug, $args = [] ) {
		$GLOBALS['cff_test_post_types'][ $slug ] = $args;
		return true;
	}
}

if ( ! function_exists( 'register_post_meta' ) ) {
	function register_post_meta( $slug, $key, $args = [] ) {
		$GLOBALS['cff_test_registered_meta'][ $slug ][ $key ] = $args;
		return true;
	}
}

// -----------------------------------------------------------------------------
// Reset state between tests. Tests call this in setUp().
// -----------------------------------------------------------------------------

function cff_test_reset_state(): void {
	$GLOBALS['cff_test_options']           = [];
	$GLOBALS['cff_test_postmeta']          = [];
	$GLOBALS['cff_test_transients']        = [];
	$GLOBALS['cff_test_transient_ttls']    = [];
	$GLOBALS['cff_test_hooks']             = [];
	$GLOBALS['cff_test_filters']           = [];
	$GLOBALS['cff_test_actions']           = [];
	$GLOBALS['cff_test_routes']            = [];
	$GLOBALS['cff_test_posts']             = [];
	$GLOBALS['cff_test_inserted_posts']    = [];
	$GLOBALS['cff_test_next_post_id']      = 0;
	$GLOBALS['cff_test_insert_post_fails'] = false;
	$GLOBALS['cff_test_mail']              = [];
	$GLOBALS['cff_test_mail_result']       = true;
	$GLOBALS['cff_test_post_types']        = [];
	$GLOBALS['cff_test_registered_meta']   = [];
}

cff_test_reset_state();

// -----------------------------------------------------------------------------
// Composer autoload — needed for CFShared + plugin classes.
// -----------------------------------------------------------------------------

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
} else {
	$includes = __DIR__ . '/../includes';
	foreach ( [ 'EntryPostType', 'Sanitizer', 'RateLimiter', 'Settings', 'Mailer', 'RestController', 'Admin', 'Plugin' ] as $cls ) {
		$path = $includes . '/' . $cls . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
