<?php
/**
 * PHPUnit bootstrap.
 *
 * No WordPress install required — every WP function the classes under test
 * touch is shimmed below or routed through $GLOBALS state so tests can
 * inject behavior at runtime. Tests call cf_media_manager_test_reset_state() in
 * setUp() to start clean.
 *
 * Converter and Queue tests build real fixture files on disk under a
 * per-test temp directory; their teardown cleans up.
 */

define( 'ABSPATH', __DIR__ . '/' );

// WordPress time constants — used in class-const initializers (e.g.
// InUseScanner::TRANSIENT_TTL). PHP evaluates those when the class is loaded,
// so they have to exist before any `use CFShared\Media\InUseScanner` triggers
// autoload.
// wpdb output-format flags — used by $wpdb->get_results( $sql, ARRAY_A ).
// VariantManifest's filter_existing_pairs references ARRAY_A; the test
// bootstrap needs to define it before any test triggers a mocked
// wpdb::get_results call.
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'ARRAY_N' ) ) { define( 'ARRAY_N', 'ARRAY_N' ); }
if ( ! defined( 'OBJECT' ) )  { define( 'OBJECT',  'OBJECT'  ); }

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) )   { define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS ); }
if ( ! defined( 'DAY_IN_SECONDS' ) )    { define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS ); }
if ( ! defined( 'WEEK_IN_SECONDS' ) )   { define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS ); }

// Silence error_log() so cron-style warning paths don't pollute stderr.
@ini_set( 'error_log', '/dev/null' );

// -----------------------------------------------------------------------------
// String / sanitization
// -----------------------------------------------------------------------------

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
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

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( ! empty( $file ) && @file_exists( $file ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $file );
		}
	}
}

// -----------------------------------------------------------------------------
// WordPress request-sanitization helpers. The Request::post_* accessors call
// these directly. Real WP implementations are richer — these shims aim for
// behavioral equivalence on common inputs, not byte-for-byte parity.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = (string) $str;
		// Strip tags, collapse whitespace, trim.
		$str = strip_tags( $str );
		$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
		$str = preg_replace( '/\s+/', ' ', $str );
		return trim( $str );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		$str = (string) $str;
		$str = strip_tags( $str );
		// Keep newlines, collapse other whitespace.
		$str = preg_replace( "/[ \t]+/", ' ', $str );
		return trim( $str );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		$url = (string) $url;
		// Minimal stripping — real esc_url_raw is more thorough.
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( is_array( $protocols ) && ! empty( $protocols ) ) {
			$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
			if ( '' !== $scheme && ! in_array( $scheme, $protocols, true ) ) {
				return '';
			}
		}
		return $url;
	}
}

// -----------------------------------------------------------------------------
// Hook system — recorded but not dispatched.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['cf_media_manager_test_hooks'][ $hook ][] = [ $callback, $priority, $accepted_args ];
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() { /* recorded indirectly */ }
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { return false; }
}

// -----------------------------------------------------------------------------
// Options / cron — in-memory state keyed in $GLOBALS.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return $GLOBALS['cf_media_manager_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['cf_media_manager_test_options'][ $name ] = $value;
		// Test-only race hook: when a test arms this, the next update_option
		// to $name is immediately overwritten by a competing value. Used to
		// simulate a peer worker winning the lock-steal race between our
		// write and our verify-read.
		if ( isset( $GLOBALS['cf_media_manager_test_post_update_swap'][ $name ] ) ) {
			$swap = $GLOBALS['cf_media_manager_test_post_update_swap'][ $name ];
			unset( $GLOBALS['cf_media_manager_test_post_update_swap'][ $name ] );
			$GLOBALS['cf_media_manager_test_options'][ $name ] = $swap;
		}
		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		// Atomic add semantics — return false when the option already exists.
		// The Queue lock leans on this for its try-acquire.
		if ( array_key_exists( $name, $GLOBALS['cf_media_manager_test_options'] ?? array() ) ) {
			return false;
		}
		$GLOBALS['cf_media_manager_test_options'][ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['cf_media_manager_test_options'][ $name ] );
		return true;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$val = $GLOBALS['cf_media_manager_test_post_meta'][ (int) $post_id ][ $key ] ?? '';
		return $single ? $val : ( '' === $val ? array() : array( $val ) );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value, $prev_value = '' ) {
		$GLOBALS['cf_media_manager_test_post_meta'][ (int) $post_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_post_meta' ) ) {
	function add_post_meta( $post_id, $key, $value, $unique = false ) {
		// add_post_meta with $unique=true is the atomic "create-only" primitive
		// VariantManifest::record() relies on for idempotency.
		if ( $unique && isset( $GLOBALS['cf_media_manager_test_post_meta'][ (int) $post_id ][ $key ] ) ) {
			return false;
		}
		$GLOBALS['cf_media_manager_test_post_meta'][ (int) $post_id ][ $key ] = $value;
		// Real WP returns a meta_id (int); tests only care about the truthiness.
		return 1;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key, $value = '' ) {
		unset( $GLOBALS['cf_media_manager_test_post_meta'][ (int) $post_id ][ $key ] );
		return true;
	}
}

// -----------------------------------------------------------------------------
// Object cache shim — VariantManifest::is_owned() coalesces repeats through
// this layer so the public render path doesn't hammer the DB.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		$store = $GLOBALS['cf_media_manager_test_cache'][ $group ] ?? array();
		if ( array_key_exists( $key, $store ) ) {
			$found = true;
			return $store[ $key ];
		}
		$found = false;
		return false;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $expire = 0 ) {
		$GLOBALS['cf_media_manager_test_cache'][ $group ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['cf_media_manager_test_cache'][ $group ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $key, $value, $group = '', $expire = 0 ) {
		// Atomic create primitive — the per-attachment convert lock relies on
		// this for race-free try-acquire.
		if ( isset( $GLOBALS['cf_media_manager_test_cache'][ $group ][ $key ] ) ) {
			return false;
		}
		$GLOBALS['cf_media_manager_test_cache'][ $group ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		$entry = $GLOBALS['cf_media_manager_test_transients'][ $key ] ?? null;
		if ( null === $entry ) {
			return false;
		}
		if ( isset( $entry['expires'] ) && $entry['expires'] > 0 && $entry['expires'] < time() ) {
			unset( $GLOBALS['cf_media_manager_test_transients'][ $key ] );
			return false;
		}
		return $entry['value'];
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expire = 0 ) {
		$GLOBALS['cf_media_manager_test_transients'][ $key ] = array(
			'value'   => $value,
			'expires' => $expire > 0 ? time() + (int) $expire : 0,
		);
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['cf_media_manager_test_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'update_meta_cache' ) ) {
	function update_meta_cache( $meta_type, $object_ids ) {
		// No-op shim: real WP pre-loads postmeta rows for the given IDs into
		// the object cache. Tests don't have $wpdb so there's nothing to load.
		// Counts as a successful no-op so production callers stay clean.
		return is_array( $object_ids ) ? $object_ids : array();
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $original ) {
		if ( ! is_string( $original ) ) {
			return $original;
		}
		$tmp = @unserialize( $original );
		return false === $tmp && 'b:0;' !== $original ? $original : $tmp;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {
		unset( $GLOBALS['cf_media_manager_test_cron'][ $hook ] );
	}
}

// -----------------------------------------------------------------------------
// AltTextManager / WP_Query shims. Minimal stand-ins so AltTextManagerTest can
// exercise the list + save paths without a real WP runtime. Tests seed the
// post store via $GLOBALS['cf_media_manager_test_posts'] and the in-use scan
// result via cf_media_manager_test_inuse_scan; the shimmed WP_Query filters
// against both.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) {
		return $GLOBALS['cf_media_manager_test_posts'][ (int) $id ]['post_type'] ?? false;
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $id ) {
		return $GLOBALS['cf_media_manager_test_posts'][ (int) $id ]['post_mime_type'] ?? '';
	}
}

if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	function wp_get_attachment_image_src( $id, $size = 'thumbnail' ) {
		$src = $GLOBALS['cf_media_manager_test_posts'][ (int) $id ]['thumb_src'] ?? '';
		return '' === $src ? false : array( $src, 60, 60, true );
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id, $context = 'display' ) {
		return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
	}
}

// Bare-minimum WP_Query mock. Constructor stores $args and computes
// $posts / $found_posts / $max_num_pages from the seeded attachment list
// using a few of the args AltTextManager actually passes (post_type,
// post_mime_type, posts_per_page, paged, post__in, meta_query).
if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public array $posts = array();
		public int $found_posts = 0;
		public int $max_num_pages = 0;

		public function __construct( array $args ) {
			// Record the most recent constructor args so tests that need
			// to assert on query shape (orderby, paged, post_mime_type, …)
			// can read them after the call. LibraryRestController tests
			// rely on this; existing tests never inspect it so they're
			// unaffected.
			$GLOBALS['cf_media_manager_test_last_query_args'] = $args;

			// Library tests inject WP_Post objects directly via this
			// override slot so the REST controller iterates real
			// $post instanceof WP_Post entries. When the override is
			// set we skip Manager's ID-filter pipeline below.
			if ( isset( $GLOBALS['cf_media_manager_test_query_posts_override'] ) ) {
				$this->posts         = $GLOBALS['cf_media_manager_test_query_posts_override'];
				$found               = $GLOBALS['cf_media_manager_test_found_posts_override']
					?? count( $this->posts );
				$per_page            = (int) ( $args['posts_per_page'] ?? -1 );
				$this->found_posts   = (int) $found;
				$this->max_num_pages = ( $per_page > 0 && $found > 0 )
					? (int) ceil( $found / $per_page )
					: ( $found > 0 ? 1 : 0 );
				return;
			}

			$posts = $GLOBALS['cf_media_manager_test_posts'] ?? array();
			$ids   = array();
			foreach ( $posts as $id => $p ) {
				if ( isset( $args['post_type'] ) && $p['post_type'] !== $args['post_type'] ) {
					continue;
				}
				if ( isset( $args['post_mime_type'] ) ) {
					$wanted = (array) $args['post_mime_type'];
					$mime   = $p['post_mime_type'] ?? '';
					$match  = false;
					foreach ( $wanted as $w ) {
						if ( $w === $mime || ( 'image' === $w && 0 === strpos( $mime, 'image/' ) ) ) {
							$match = true;
							break;
						}
					}
					if ( ! $match ) {
						continue;
					}
				}
				if ( isset( $args['post__in'] ) && ! in_array( (int) $id, array_map( 'intval', $args['post__in'] ), true ) ) {
					continue;
				}
				if ( isset( $args['meta_query'] ) && ! self::matches_meta_query( (int) $id, $args['meta_query'] ) ) {
					continue;
				}
				$ids[] = (int) $id;
			}
			// Order by date DESC if requested; for tests we just keep the
			// insertion order (which test setUp controls).
			$this->found_posts   = count( $ids );
			$per_page            = (int) ( $args['posts_per_page'] ?? 10 );
			$this->max_num_pages = $per_page > 0 ? (int) ceil( $this->found_posts / $per_page ) : 0;
			$page                = max( 1, (int) ( $args['paged'] ?? 1 ) );
			$offset              = ( $page - 1 ) * $per_page;
			$this->posts         = array_slice( $ids, $offset, $per_page );
		}

		/**
		 * Greatly simplified meta_query matcher: supports only the shape
		 * AltTextManager actually uses (top-level AND of two OR groups,
		 * each with NOT EXISTS / '=' / '!=' comparators).
		 */
		private static function matches_meta_query( int $post_id, array $query ): bool {
			$meta = $GLOBALS['cf_media_manager_test_post_meta'][ $post_id ] ?? array();
			$top_relation = strtoupper( (string) ( $query['relation'] ?? 'AND' ) );
			$groups       = array_filter( $query, 'is_array' );

			$pass = ( 'AND' === $top_relation );
			foreach ( $groups as $group ) {
				$group_match = self::matches_meta_clause_group( $meta, $group );
				if ( 'AND' === $top_relation ) {
					$pass = $pass && $group_match;
				} else {
					$pass = $pass || $group_match;
				}
			}
			return (bool) $pass;
		}

		private static function matches_meta_clause_group( array $meta, array $group ): bool {
			$relation = strtoupper( (string) ( $group['relation'] ?? 'AND' ) );
			$clauses  = array_filter( $group, 'is_array' );

			$pass = ( 'AND' === $relation );
			foreach ( $clauses as $clause ) {
				$clause_match = self::matches_meta_clause( $meta, $clause );
				if ( 'AND' === $relation ) {
					$pass = $pass && $clause_match;
				} else {
					$pass = $pass || $clause_match;
				}
			}
			return (bool) $pass;
		}

		private static function matches_meta_clause( array $meta, array $clause ): bool {
			$key     = (string) ( $clause['key'] ?? '' );
			$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
			$has     = array_key_exists( $key, $meta );

			if ( 'NOT EXISTS' === $compare ) {
				return ! $has;
			}
			if ( 'EXISTS' === $compare ) {
				return $has;
			}
			$value  = $clause['value'] ?? '';
			$actual = $has ? $meta[ $key ] : '';
			if ( '=' === $compare ) {
				return (string) $actual === (string) $value;
			}
			if ( '!=' === $compare ) {
				return (string) $actual !== (string) $value;
			}
			return false;
		}
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['cf_media_manager_test_cron'][ $hook ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook ) {
		$GLOBALS['cf_media_manager_test_cron'][ $hook ] = $timestamp;
		return true;
	}
}

// -----------------------------------------------------------------------------
// Attachments — set $GLOBALS['cf_media_manager_test_attachments'][$id] = ['file'=>..., 'meta'=>...]
// -----------------------------------------------------------------------------

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $id ) {
		return $GLOBALS['cf_media_manager_test_attachments'][ $id ]['file'] ?? false;
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $id ) {
		// Call counter — LibraryAttachmentData's lazy-load test asserts
		// the metadata fetch happens at most once per row.
		$GLOBALS['cf_media_manager_test_meta_calls'] =
			( $GLOBALS['cf_media_manager_test_meta_calls'] ?? 0 ) + 1;
		return $GLOBALS['cf_media_manager_test_attachments'][ $id ]['meta'] ?? false;
	}
}

// -----------------------------------------------------------------------------
// Reset helper — tests call this in setUp().
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// i18n — return source string unchanged.
// -----------------------------------------------------------------------------

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = null ) { return esc_attr( $text ); }
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = null ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return $GLOBALS['cf_media_manager_test_current_user'] ?? 0;
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		$val = $GLOBALS['cf_media_manager_test_user_meta'][ (int) $user_id ][ $key ] ?? '';
		return $single ? $val : ( '' === $val ? array() : array( $val ) );
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['cf_media_manager_test_user_meta'][ (int) $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'http://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

// -----------------------------------------------------------------------------
// HTTP API shims for UrlVerifier tests. wp_safe_remote_get is NOT shimmed —
// UrlVerifier tests use set_http_for_test() to inject responses without
// touching the network. The retrieve_* helpers parse the shimmed-response
// shape so test responses look like real wp_remote_get responses.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return ( -1 === $component ) ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		$base = $GLOBALS['cf_media_manager_test_home_url'] ?? 'https://example.test';
		return rtrim( $base, '/' ) . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		if ( ! is_array( $response ) || empty( $response['headers'] ) ) {
			return '';
		}
		$header = strtolower( (string) $header );
		foreach ( $response['headers'] as $k => $v ) {
			if ( strtolower( (string) $k ) === $header ) {
				return is_array( $v ) ? ( $v[0] ?? '' ) : (string) $v;
			}
		}
		return '';
	}
}

if ( ! class_exists( 'WP_Http' ) ) {
	class WP_Http {
		public static function make_absolute_url( $maybe_relative, $base ) {
			// Good enough for test redirect cases: if it already has a
			// scheme, return as-is; otherwise prefix with the base.
			if ( preg_match( '#^https?://#i', (string) $maybe_relative ) ) {
				return $maybe_relative;
			}
			$bp = parse_url( $base );
			if ( ! isset( $bp['scheme'], $bp['host'] ) ) {
				return $maybe_relative;
			}
			$root = $bp['scheme'] . '://' . $bp['host'];
			return $root . ( '/' === substr( (string) $maybe_relative, 0, 1 ) ? '' : '/' ) . $maybe_relative;
		}
	}
}

// -----------------------------------------------------------------------------
// Capability / nonce / JSON-response shims
//
// These mirror enough of the WP runtime that Security:: gate paths can be
// exercised by unit tests. The failure side throws a CFMediaManagerHttpExit
// exception in place of wp_die() so the test process survives and assertions
// can inspect the would-have-been HTTP response.
// -----------------------------------------------------------------------------

if ( ! class_exists( 'CFMediaManagerHttpExit' ) ) {
	final class CFMediaManagerHttpExit extends \RuntimeException {
		public bool $success;
		public $data;
		public int $status;
		public function __construct( bool $success, $data, int $status ) {
			parent::__construct( 'http exit ' . $status );
			$this->success = $success;
			$this->data    = $data;
			$this->status  = $status;
		}
	}
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null, $status = 200 ): void {
		throw new \CFMediaManagerHttpExit( true, $data, (int) $status );
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {
		// Production calls header() to push Cache-Control values to the
		// response. Tests don't ship a response — no-op.
	}
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, $status = 200 ): void {
		throw new \CFMediaManagerHttpExit( false, $data, (int) $status );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		// Mirrors the check_ajax_referer shim below: valid by default, with
		// the same pre-seeded flag to exercise the failure branch. Real WP
		// returns 1|2|false; callers only test truthiness.
		if ( ! empty( $GLOBALS['cf_media_manager_test_nonce_invalid'] ) ) {
			return false;
		}
		return empty( $nonce ) ? false : 1;
	}
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) {
		// Tests can pre-seed a "this nonce is bad" flag to exercise the
		// failure branch. Default behavior is "nonce is valid".
		if ( ! empty( $GLOBALS['cf_media_manager_test_nonce_invalid'] ) ) {
			if ( $die ) {
				throw new \CFMediaManagerHttpExit( false, 'bad nonce', 403 );
			}
			return false;
		}
		return 1;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		return (bool) ( $GLOBALS['cf_media_manager_test_is_multisite'] ?? false );
	}
}

if ( ! function_exists( 'get_theme_mod' ) ) {
	function get_theme_mod( $name, $default = false ) {
		return $GLOBALS['cf_media_manager_test_theme_mods'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	// Real WP returns the firing-count for the action. The InUseScanner
	// builder detectors use this to recognize page builders that
	// announce themselves via an action hook (Elementor, Bricks, etc.);
	// in tests we simulate "no builder is active" by returning 0.
	function did_action( $hook ) {
		return (int) ( $GLOBALS['cf_media_manager_test_did_action'][ $hook ] ?? 0 );
	}
}

// Multisite-related shims used by the uninstall test. switch_to_blog /
// restore_current_blog track the call sequence so the test can assert
// per-site iteration. delete_site_option is mirrored against a
// dedicated $GLOBALS bucket so it doesn't collide with normal options.
if ( ! function_exists( 'get_sites' ) ) {
	function get_sites( $args = array() ) {
		$ids = $GLOBALS['cf_media_manager_test_sites'] ?? array();
		if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
			return $ids;
		}
		return array_map(
			static function ( $id ) {
				$o          = new \stdClass();
				$o->blog_id = $id;
				return $o;
			},
			$ids
		);
	}
}

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		$GLOBALS['cf_media_manager_test_blog_stack'][]   = $blog_id;
		$GLOBALS['cf_media_manager_test_blog_switches'][] = (int) $blog_id;
		return true;
	}
}

if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		array_pop( $GLOBALS['cf_media_manager_test_blog_stack'] );
		$GLOBALS['cf_media_manager_test_blog_restores'][] = true;
		return true;
	}
}

if ( ! function_exists( 'delete_site_option' ) ) {
	function delete_site_option( $name ) {
		$GLOBALS['cf_media_manager_test_site_options_deleted'][] = $name;
		return true;
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		// Returns a path the uninstall code can stat without exploding.
		// Tests that exercise actual filesystem cleanup set up their own
		// sandbox; this default is only enough for uninstall to walk
		// over an empty tree.
		$base = $GLOBALS['cf_media_manager_test_upload_dir'] ?? sys_get_temp_dir() . '/cf-media-manager-uninstall-noop';
		if ( ! is_dir( $base ) ) {
			@mkdir( $base, 0777, true );
		}
		return array(
			'basedir' => $base,
			'baseurl' => 'https://example.test/wp-content/uploads',
		);
	}
}


if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		$caps = $GLOBALS['cf_media_manager_test_user_caps'] ?? array();
		return ! empty( $caps[ $capability ] );
	}
}

function cf_media_manager_test_reset_state(): void {
	$GLOBALS['cf_media_manager_test_options']       = [];
	$GLOBALS['cf_media_manager_test_hooks']         = [];
	$GLOBALS['cf_media_manager_test_cron']          = [];
	$GLOBALS['cf_media_manager_test_attachments']   = [];
	$GLOBALS['cf_media_manager_test_current_user']  = 0;
	$GLOBALS['cf_media_manager_test_user_meta']     = [];
	$GLOBALS['cf_media_manager_test_post_meta']     = [];
	$GLOBALS['cf_media_manager_test_cache']         = [];
	$GLOBALS['cf_media_manager_test_is_multisite']  = false;
	$GLOBALS['cf_media_manager_test_user_caps']     = [];
	$GLOBALS['cf_media_manager_test_nonce_invalid'] = false;
	$GLOBALS['cf_media_manager_test_home_url']      = 'https://example.test';
	$GLOBALS['cf_media_manager_test_post_update_swap']        = [];
	$GLOBALS['cf_media_manager_test_transients']              = [];
	$GLOBALS['cf_media_manager_test_sites']                   = [];
	$GLOBALS['cf_media_manager_test_blog_stack']              = [];
	$GLOBALS['cf_media_manager_test_blog_switches']           = [];
	$GLOBALS['cf_media_manager_test_blog_restores']           = [];
	$GLOBALS['cf_media_manager_test_site_options_deleted']    = [];
	$GLOBALS['cf_media_manager_test_posts']                   = [];
	$GLOBALS['cf_media_manager_test_meta_calls']              = 0;
	$GLOBALS['cf_media_manager_test_routes']                  = [];
	$GLOBALS['cf_media_manager_test_last_query_args']         = [];
	$GLOBALS['cf_media_manager_test_users']                   = [];
	$GLOBALS['cf_media_manager_test_post_objects']            = [];
	$GLOBALS['cf_media_manager_test_deleted_attachments']     = [];
	$GLOBALS['cf_media_manager_test_delete_attachment_returns'] = [];
	unset(
		$GLOBALS['cf_media_manager_test_upload_dir'],
		$GLOBALS['cf_media_manager_test_query_posts_override'],
		$GLOBALS['cf_media_manager_test_found_posts_override']
	);
}

cf_media_manager_test_reset_state();

// Composer autoloader — falls back to manual require if vendor/ is missing
// so the suite is still runnable without `composer install`.
$autoload = __DIR__ . '/../vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
} else {
	// No vendor/ (suite run without `composer install`). Lazily load the
	// plugin's own classes from includes/ and the bundled CFShared library
	// from the sibling shared/ source tree. Lazy loading means dependency
	// order is handled automatically (e.g. AltTextManager referencing
	// CFShared\Media\AltMeta at class-definition time).
	spl_autoload_register(
		static function ( $class ) {
			$map = array(
				'CFMediaManager\\' => __DIR__ . '/../includes/',
				'CFShared\\'       => __DIR__ . '/../../shared/src/',
			);
			foreach ( $map as $prefix => $base ) {
				if ( 0 === strpos( $class, $prefix ) ) {
					$rel  = substr( $class, strlen( $prefix ) );
					$path = $base . str_replace( '\\', '/', $rel ) . '.php';
					if ( is_file( $path ) ) {
						require_once $path;
					}
					return;
				}
			}
		}
	);
}

// Audit-report shims (added with the GhostAttachments report). Tests
// inject behavior via $GLOBALS['cf_media_manager_test_deleted_attachments']
// so each call gets recorded with its force-flag argument.
if ( ! function_exists( 'wp_delete_attachment' ) ) {
	function wp_delete_attachment( $post_id, $force_delete = false ) {
		$override = $GLOBALS['cf_media_manager_test_delete_attachment_returns'][ $post_id ] ?? null;
		if ( null !== $override ) {
			$GLOBALS['cf_media_manager_test_deleted_attachments'][] = array(
				'id'    => (int) $post_id,
				'force' => (bool) $force_delete,
			);
			return $override;
		}
		// Default: succeed and return a stub WP_Post-shaped object.
		$GLOBALS['cf_media_manager_test_deleted_attachments'][] = array(
			'id'    => (int) $post_id,
			'force' => (bool) $force_delete,
		);
		if ( class_exists( 'WP_Post' ) ) {
			return new \WP_Post( array( 'ID' => (int) $post_id ) );
		}
		return (object) array( 'ID' => (int) $post_id );
	}
}

// -----------------------------------------------------------------------------
// Shims added for the Library list-view tests (LibraryAttachmentData,
// LibraryColumnRegistry, LibraryCsvExporter, LibraryRestController). Kept
// at the end of the bootstrap so they don't perturb the order of the
// existing shims.
// -----------------------------------------------------------------------------

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $id ) {
		return $GLOBALS['cf_media_manager_test_attachments'][ $id ]['url'] ?? false;
	}
}

if ( ! function_exists( 'wp_make_link_relative' ) ) {
	function wp_make_link_relative( $link ) {
		return preg_replace( '|^(https?:)?//[^/]+(/?.*)|i', '$2', (string) $link );
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		$bytes = (float) $bytes;
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return round( $bytes, $decimals ) . ' ' . $units[ $i ];
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', (string) $string );
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return $GLOBALS['cf_media_manager_test_post_objects'][ $id ] ?? null;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $id ) {
		return $GLOBALS['cf_media_manager_test_users'][ $id ] ?? false;
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		const READABLE  = 'GET';
		const CREATABLE = 'POST';
		const EDITABLE  = 'POST, PUT, PATCH';
		const DELETABLE = 'DELETE';
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
		$GLOBALS['cf_media_manager_test_routes'][] = array(
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		);
		return true;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		private array $params;

		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

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
		private $data;

		public function __construct( $data = null, $status = 200 ) {
			$this->data = $data;
		}

		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post { // phpcs:ignore Generic.Files.OneObjectStructurePerFile
		public $ID                = 0;
		public $post_title        = '';
		public $post_name         = '';
		public $guid              = '';
		public $post_mime_type    = '';
		public $post_excerpt      = '';
		public $post_content      = '';
		public $post_parent       = 0;
		public $post_author       = 0;
		public $post_date         = '';
		public $post_date_gmt     = '';
		public $post_modified     = '';
		public $post_modified_gmt = '';
		public $post_status       = 'inherit';
		public $menu_order        = 0;
		public $comment_status    = 'closed';
		public $post_type         = 'attachment';

		public function __construct( array $props = array() ) {
			foreach ( $props as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}
