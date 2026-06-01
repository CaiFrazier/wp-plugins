<?php
/**
 * PHPUnit bootstrap — no WordPress install required.
 *
 * Every WP function the classes under test touch is shimmed below. Tests
 * control behaviour by setting $GLOBALS['cfcal_test_*'] before invoking the
 * code under test. Call cfcal_test_reset_state() in setUp() to start clean.
 */

define( 'ABSPATH', __DIR__ . '/' );

@ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

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

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( preg_match( '#^(javascript|data|vbscript):#i', $url ) ) {
			return '';
		}
		return $url;
	}
}

// -----------------------------------------------------------------------------
// i18n
// -----------------------------------------------------------------------------

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

// -----------------------------------------------------------------------------
// Hook system — record but don't dispatch.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['cfcal_test_hooks'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['cfcal_test_filters'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action() { /* no-op */ }
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

// -----------------------------------------------------------------------------
// REST shims
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
		$GLOBALS['cfcal_test_routes'][] = [
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		];
		return true;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		private array $params = [];

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function set_param( $key, $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		public $data;
		public int $status;

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}
	}
}

// -----------------------------------------------------------------------------
// WP_Post shim
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		public int $ID            = 0;
		public string $post_title  = '';
		public string $post_status = 'draft';
		public string $post_type   = 'post';
		public string $post_date   = '';
		public string $post_date_gmt = '';
		public int $post_author    = 0;
	}
}

// -----------------------------------------------------------------------------
// WP_Error / is_wp_error shim
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

// -----------------------------------------------------------------------------
// WP_Query shim — tests inject posts via $GLOBALS.
// -----------------------------------------------------------------------------

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		public array $posts = [];
		public array $query_vars = [];

		public function __construct( array $args = [] ) {
			$this->query_vars = $args;
			$GLOBALS['cfcal_test_last_query_args'] = $args;
			$this->posts      = $GLOBALS['cfcal_test_query_posts'] ?? [];
		}
	}
}

// -----------------------------------------------------------------------------
// Capabilities — $GLOBALS['cfcal_test_caps']: bool or callable($cap, $id): bool
// -----------------------------------------------------------------------------

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap, $object_id = null ) {
		$caps = $GLOBALS['cfcal_test_caps'] ?? true;
		if ( is_callable( $caps ) ) {
			return (bool) $caps( $cap, $object_id );
		}
		return (bool) $caps;
	}
}

// -----------------------------------------------------------------------------
// Post types — tests inject via $GLOBALS['cfcal_test_post_types'].
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = [], $output = 'names' ) {
		$registered = $GLOBALS['cfcal_test_post_types'] ?? [
			'post'       => (object) [ 'name' => 'post', 'public' => true, 'labels' => (object) [ 'name' => 'Posts' ] ],
			'page'       => (object) [ 'name' => 'page', 'public' => true, 'labels' => (object) [ 'name' => 'Pages' ] ],
			'attachment' => (object) [ 'name' => 'attachment', 'public' => true, 'labels' => (object) [ 'name' => 'Media' ] ],
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
// Post CRUD — tests control via $GLOBALS.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		return $GLOBALS['cfcal_test_posts'][ (int) $post_id ] ?? null;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $data, $return_wp_error = false ) {
		$result = $GLOBALS['cfcal_test_insert_result'] ?? 99;
		$GLOBALS['cfcal_test_last_insert'] = $data;
		return $result;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $data, $return_wp_error = false ) {
		$result = $GLOBALS['cfcal_test_update_result'] ?? $data['ID'];
		$GLOBALS['cfcal_test_last_update'] = $data;
		if ( ! ( $result instanceof WP_Error ) && isset( $GLOBALS['cfcal_test_posts'][ $data['ID'] ] ) ) {
			$post      = $GLOBALS['cfcal_test_posts'][ $data['ID'] ];
			$edit_date = ! empty( $data['edit_date'] );
			foreach ( $data as $k => $v ) {
				// Mirror the real WP gotcha: post_date on an existing post is
				// ignored unless edit_date is true.
				if ( 'post_date' === $k && ! $edit_date ) {
					continue;
				}
				if ( property_exists( $post, $k ) ) {
					$post->$k = $v;
				}
			}
			$GLOBALS['cfcal_test_posts'][ $data['ID'] ] = $post;
		}
		return $result;
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $type ) {
		$types = get_post_types( [], 'objects' );
		if ( ! isset( $types[ $type ] ) ) {
			return null;
		}
		$obj = $types[ $type ];
		if ( ! isset( $obj->cap ) ) {
			$obj->cap = (object) [
				'create_posts' => 'post' === $type ? 'edit_posts' : 'edit_' . $type . 's',
				'edit_posts'   => 'edit_posts',
			];
		}
		return $obj;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['cfcal_test_user_id'] ?? 1 );
	}
}

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) {
		return $date;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users( $args = [] ) {
		return $GLOBALS['cfcal_test_users'] ?? [
			(object) [ 'ID' => 1, 'display_name' => 'Admin User' ],
			(object) [ 'ID' => 2, 'display_name' => 'Editor User' ],
		];
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post_id, $context = 'display' ) {
		return "https://example.com/wp-admin/post.php?post=$post_id&action=edit";
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return $GLOBALS['cfcal_test_current_time'] ?? '2026-05-15 12:00:00';
	}
}

// -----------------------------------------------------------------------------
// Reset state — tests call this in setUp().
// -----------------------------------------------------------------------------

function cfcal_test_reset_state(): void {
	$GLOBALS['cfcal_test_hooks']         = [];
	$GLOBALS['cfcal_test_filters']       = [];
	$GLOBALS['cfcal_test_routes']        = [];
	$GLOBALS['cfcal_test_caps']          = true;
	$GLOBALS['cfcal_test_post_types']    = null;
	$GLOBALS['cfcal_test_posts']         = [];
	$GLOBALS['cfcal_test_query_posts']   = [];
	$GLOBALS['cfcal_test_insert_result'] = null;
	$GLOBALS['cfcal_test_update_result'] = null;
	$GLOBALS['cfcal_test_last_insert']   = null;
	$GLOBALS['cfcal_test_last_update']   = null;
	$GLOBALS['cfcal_test_current_time']  = '2026-05-15 12:00:00';
	$GLOBALS['cfcal_test_user_id']       = 1;
	$GLOBALS['cfcal_test_users']         = null;
	$GLOBALS['cfcal_test_last_query_args'] = null;
}

cfcal_test_reset_state();

// -----------------------------------------------------------------------------
// Autoload plugin classes.
// -----------------------------------------------------------------------------

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
} else {
	foreach ( [ 'Plugin', 'Admin', 'RestController' ] as $cls ) {
		$path = __DIR__ . '/../includes/' . $cls . '.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
