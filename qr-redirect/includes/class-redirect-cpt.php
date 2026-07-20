<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Custom Post Type registration and meta field definitions for cfqr_redirect.
 *
 * Standard redirect manager: maps an arbitrary source path on this site to any
 * http(s) destination URL with a configurable status code. Unlike CFQR_CPT
 * (which owns the /r/{slug} rewrite namespace), this CPT is NOT publicly
 * queryable — routing is handled by CFQR_Redirect_Router via parse_request.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Redirect_CPT {

	const META_SOURCE_PATH     = '_cfqr_source_path';
	const META_DESTINATION     = '_cfqr_destination';
	const META_MATCH_MODE      = '_cfqr_match_mode';
	const META_STATUS_CODE     = '_cfqr_status_code';
	const META_ACTIVE          = '_cfqr_active';
	const META_HIT_COUNT       = '_cfqr_hit_count';
	const META_CREATED_AT      = '_cfqr_created_at';
	const META_LAST_HIT_AT     = '_cfqr_last_hit_at';
	const META_DESTINATION_LOG = '_cfqr_destination_log';
	// Normalized form of source_path used by the router's exact-match lookup.
	// Maintained alongside META_SOURCE_PATH so the matcher never re-normalizes
	// on the request path; it just hashes and compares.
	const META_SOURCE_NORMALIZED = '_cfqr_source_normalized';
	// Scheduled enable/disable window. ISO 8601 UTC timestamps; empty string
	// means "no bound on this side." Both empty = always active (the default).
	const META_ACTIVE_FROM  = '_cfqr_active_from';
	const META_ACTIVE_UNTIL = '_cfqr_active_until';

	const MATCH_EXACT       = 'exact';
	const MATCH_WILDCARD    = 'wildcard';
	const MATCH_REGEX       = 'regex';
	const MATCH_QUERY_AWARE = 'query_aware';

	const ALLOWED_STATUSES = array( 301, 302, 307, 308 );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'stamp_created_at' ), 10, 3 );
	}

	/**
	 * Register the cfqr_redirect_group taxonomy. Flat (non-hierarchical) for
	 * tag-like UX in the editor. Not exposed in REST or as public archives —
	 * groups are an admin-side organization tool, not a public-facing concept.
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'                       => _x( 'Groups', 'taxonomy general name', 'cf-qr-redirect' ),
			'singular_name'              => _x( 'Group', 'taxonomy singular name', 'cf-qr-redirect' ),
			'search_items'               => __( 'Search Groups', 'cf-qr-redirect' ),
			'all_items'                  => __( 'All Groups', 'cf-qr-redirect' ),
			'edit_item'                  => __( 'Edit Group', 'cf-qr-redirect' ),
			'update_item'                => __( 'Update Group', 'cf-qr-redirect' ),
			'add_new_item'               => __( 'Add New Group', 'cf-qr-redirect' ),
			'new_item_name'              => __( 'New Group Name', 'cf-qr-redirect' ),
			'menu_name'                  => __( 'Groups', 'cf-qr-redirect' ),
			'separate_items_with_commas' => __( 'Separate groups with commas', 'cf-qr-redirect' ),
		);

		register_taxonomy(
			CFQR_REDIRECT_TAXONOMY,
			CFQR_REDIRECT_POST_TYPE,
			array(
				'labels'             => $labels,
				'hierarchical'       => false,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => false,
				'show_admin_column'  => false, // The plugin renders its own styled column.
				'show_tagcloud'      => false,
				'rewrite'            => false,
				'query_var'          => false,
				'capabilities'       => array(
					'manage_terms' => CFQR_REDIRECT_CAP_MANAGE_GROUPS,
					'edit_terms'   => CFQR_REDIRECT_CAP_MANAGE_GROUPS,
					'delete_terms' => CFQR_REDIRECT_CAP_MANAGE_GROUPS,
					// Assigning an existing term to a post falls under "edit
					// the redirect" — anyone who can edit a redirect can tag it.
					'assign_terms' => CFQR_REDIRECT_CAP_EDIT,
				),
			)
		);
	}

	/**
	 * Register the cfqr_redirect CPT. Not public, not publicly_queryable, not in
	 * REST — the router intercepts requests directly by source path. show_in_menu
	 * nests admin items under the plugin's QR Codes top-level menu so the plugin
	 * presents one cohesive surface instead of two parallel menus.
	 */
	public static function register() {
		$labels = array(
			'name'               => _x( 'Redirects', 'post type general name', 'cf-qr-redirect' ),
			'singular_name'      => _x( 'Redirect', 'post type singular name', 'cf-qr-redirect' ),
			'menu_name'          => _x( 'Redirects', 'admin menu', 'cf-qr-redirect' ),
			'name_admin_bar'     => _x( 'Redirect', 'add new on admin bar', 'cf-qr-redirect' ),
			'add_new'            => _x( 'Add New', 'redirect', 'cf-qr-redirect' ),
			'add_new_item'       => __( 'Add New Redirect', 'cf-qr-redirect' ),
			'new_item'           => __( 'New Redirect', 'cf-qr-redirect' ),
			'edit_item'          => __( 'Edit Redirect', 'cf-qr-redirect' ),
			'view_item'          => __( 'View Redirect', 'cf-qr-redirect' ),
			'all_items'          => __( 'All Redirects', 'cf-qr-redirect' ),
			'search_items'       => __( 'Search Redirects', 'cf-qr-redirect' ),
			'not_found'          => __( 'No redirects found.', 'cf-qr-redirect' ),
			'not_found_in_trash' => __( 'No redirects found in Trash.', 'cf-qr-redirect' ),
		);

		// Granular caps mirror the QR set so delegation is consistent across both CPTs.
		// WordPress uses edit_posts to authorize the list screen and menu, so that
		// primitive maps to the read capability. Row actions remain protected by
		// their separate edit, create, and delete capabilities.
		// map_meta_cap=false stops WP from falling back to edit_posts.
		$caps = array(
			'edit_post'              => CFQR_REDIRECT_CAP_EDIT,
			'read_post'              => CFQR_REDIRECT_CAP_READ,
			'delete_post'            => CFQR_REDIRECT_CAP_DELETE,
			'edit_posts'             => CFQR_REDIRECT_CAP_READ,
			'edit_others_posts'      => CFQR_REDIRECT_CAP_EDIT,
			'publish_posts'          => CFQR_REDIRECT_CAP_EDIT,
			'read_private_posts'     => CFQR_REDIRECT_CAP_READ,
			'delete_posts'           => CFQR_REDIRECT_CAP_DELETE,
			'delete_private_posts'   => CFQR_REDIRECT_CAP_DELETE,
			'delete_published_posts' => CFQR_REDIRECT_CAP_DELETE,
			'delete_others_posts'    => CFQR_REDIRECT_CAP_DELETE,
			'edit_private_posts'     => CFQR_REDIRECT_CAP_EDIT,
			'edit_published_posts'   => CFQR_REDIRECT_CAP_EDIT,
			'create_posts'           => CFQR_REDIRECT_CAP_CREATE,
		);

		register_post_type(
			CFQR_REDIRECT_POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => true,
				// Nest under the QR Codes top-level menu — gives the plugin one
				// branded surface instead of two parallel top-level entries.
				'show_in_menu'        => CFQR_MENU_SLUG,
				'show_in_rest'        => false,
				'capabilities'        => $caps,
				'map_meta_cap'        => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				// title only — the title is a human-readable label for the
				// admin list; routing is driven by post meta, not post_name.
				'supports'            => array( 'title' ),
				'rewrite'             => false,
				'query_var'           => false,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Register every post meta key with REST disabled and an edit-cap auth gate.
	 */
	public static function register_meta() {
		$auth_cb = function () {
			return current_user_can( CFQR_REDIRECT_CAP_EDIT );
		};

		$keys = array(
			self::META_SOURCE_PATH       => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_source_meta' ),
			),
			self::META_SOURCE_NORMALIZED => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			self::META_DESTINATION       => array(
				'type'     => 'string',
				'sanitize' => 'esc_url_raw',
			),
			self::META_MATCH_MODE        => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_match_mode' ),
			),
			self::META_STATUS_CODE       => array(
				'type'     => 'integer',
				'sanitize' => array( __CLASS__, 'sanitize_status_code' ),
			),
			self::META_ACTIVE            => array(
				'type'     => 'boolean',
				'sanitize' => array( __CLASS__, 'sanitize_bool' ),
			),
			self::META_HIT_COUNT         => array(
				'type'     => 'integer',
				'sanitize' => 'absint',
			),
			self::META_CREATED_AT        => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			self::META_LAST_HIT_AT       => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			self::META_DESTINATION_LOG   => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			self::META_ACTIVE_FROM       => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_iso8601' ),
			),
			self::META_ACTIVE_UNTIL      => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_iso8601' ),
			),
		);

		foreach ( $keys as $key => $cfg ) {
			register_post_meta(
				CFQR_REDIRECT_POST_TYPE,
				$key,
				array(
					'type'              => $cfg['type'],
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => $cfg['sanitize'],
					'auth_callback'     => $auth_cb,
				)
			);
		}
	}

	public static function sanitize_match_mode( $value ) {
		$value   = sanitize_key( $value );
		$allowed = array( self::MATCH_EXACT, self::MATCH_WILDCARD, self::MATCH_REGEX, self::MATCH_QUERY_AWARE );
		return in_array( $value, $allowed, true ) ? $value : self::MATCH_EXACT;
	}

	public static function sanitize_status_code( $value ) {
		$value = (int) $value;
		return in_array( $value, self::ALLOWED_STATUSES, true ) ? $value : 302;
	}

	/**
	 * Sanitize a user-entered datetime into an ISO 8601 UTC string. Accepts
	 * the value that <input type="datetime-local"> posts (e.g. "2026-06-01T14:30")
	 * and the timestamp from a programmatic update. Empty input is preserved
	 * as empty (the "no bound" signal).
	 */
	public static function sanitize_iso8601( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		// datetime-local omits the timezone; assume site timezone, then convert to UTC.
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		try {
			$dt = new DateTimeImmutable( $value, $tz );
		} catch ( Exception $e ) {
			return '';
		}
		return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Is the given schedule window active at the given timestamp?
	 *
	 * Empty bound = "no bound on that side." Both empty = always active.
	 * The router calls this on every cached row to decide whether to fire,
	 * so it must be cheap and deterministic.
	 *
	 * @param string $from  ISO 8601 UTC, or '' for no lower bound.
	 * @param string $until ISO 8601 UTC, or '' for no upper bound.
	 * @param int    $now   Unix timestamp to evaluate against (default: time()).
	 * @return bool
	 */
	public static function is_within_schedule( $from, $until, $now = null ) {
		$now = ( null === $now ) ? time() : (int) $now;
		if ( '' !== (string) $from ) {
			$from_ts = strtotime( (string) $from );
			if ( false === $from_ts || $now < $from_ts ) {
				return false;
			}
		}
		if ( '' !== (string) $until ) {
			$until_ts = strtotime( (string) $until );
			if ( false === $until_ts || $now >= $until_ts ) {
				return false;
			}
		}
		return true;
	}

	public static function sanitize_bool( $value ) {
		// rest_sanitize_boolean handles the usual "1"/"true"/"on" inputs the
		// admin form posts as checkboxes. Falls back to (bool) cast.
		if ( function_exists( 'rest_sanitize_boolean' ) ) {
			return rest_sanitize_boolean( $value ) ? 1 : 0;
		}
		return $value ? 1 : 0;
	}

	/**
	 * Apply the storage-safe portion of source sanitization.
	 *
	 * Registered meta sanitizers do not receive the redirect's match mode, so
	 * this callback must not normalize the value as a URL path. Mode-aware
	 * callers use sanitize_source_path() before saving; this final gate only
	 * removes control characters and enforces the storage length limit.
	 *
	 * @param mixed $value Source value.
	 * @return string Cleaned source value.
	 */
	public static function sanitize_source_meta( $value ) {
		$value = trim( (string) $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		if ( strlen( $value ) > 2048 ) {
			$value = substr( $value, 0, 2048 );
		}
		return $value;
	}

	/**
	 * Sanitize a user-entered redirect source for its selected match mode.
	 *
	 * Regex sources are raw PCRE bodies without delimiters and must remain
	 * byte-for-byte intact after the narrow storage cleanup. Other modes match
	 * URL paths, so they receive same-site URL stripping and a leading slash.
	 *
	 * @param mixed  $value Source value.
	 * @param string $mode  Redirect match mode.
	 * @return string Sanitized source.
	 */
	public static function sanitize_source_path( $value, $mode = self::MATCH_EXACT ) {
		$value = self::sanitize_source_meta( $value );
		if ( '' === $value || self::MATCH_REGEX === $mode ) {
			return $value;
		}
		// If the user pasted a full URL on this site, peel off the origin so we
		// store just the path. Avoids storing values that won't match the
		// $_SERVER['REQUEST_URI'] the router actually sees.
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$parsed    = wp_parse_url( $value );
		if ( is_array( $parsed ) && ! empty( $parsed['host'] ) && strtolower( $parsed['host'] ) === strtolower( (string) $home_host ) ) {
			$value = ( $parsed['path'] ?? '/' ) . ( isset( $parsed['query'] ) ? '?' . $parsed['query'] : '' );
		}
		// Ensure leading slash.
		if ( '' !== $value && '/' !== $value[0] ) {
			$value = '/' . $value;
		}
		return $value;
	}

	/**
	 * Repair a regex source damaged by the legacy path sanitizer.
	 *
	 * Before 1.3.1, regex sources that began with an anchor or inline option
	 * group were incorrectly prefixed with a slash. Only unambiguous shapes are
	 * repaired; ordinary slash-prefixed patterns are left untouched.
	 *
	 * @param mixed $source Stored regex source.
	 * @return string Repaired or original source.
	 */
	public static function repair_legacy_regex_source( $source ) {
		$source = self::sanitize_source_meta( $source );
		if ( preg_match( '/^\/(?:\^|\\\\A|\(\?[a-zA-Z-]+(?:\)|:))/', $source ) ) {
			return substr( $source, 1 );
		}
		return $source;
	}

	/**
	 * Normalize a source path for exact-match lookup. Same path with and
	 * without a trailing slash must collide in the cache so a redirect for
	 * "/old-page/" also catches "/old-page".
	 *
	 * Strips the query string by design — the exact matcher only considers
	 * paths. Query-aware matching is a separate (scaffolded) mode.
	 *
	 * @param string $path Raw or sanitized source path.
	 * @return string Lowercase path, leading slash, no trailing slash (except root).
	 */
	public static function normalize_path( $path ) {
		$path = (string) $path;
		// Drop query/fragment.
		$qpos = strpos( $path, '?' );
		if ( false !== $qpos ) {
			$path = substr( $path, 0, $qpos );
		}
		$hpos = strpos( $path, '#' );
		if ( false !== $hpos ) {
			$path = substr( $path, 0, $hpos );
		}
		$path = trim( $path );
		if ( '' === $path ) {
			return '/';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		// Collapse consecutive slashes so /foo//bar matches /foo/bar.
		$path = preg_replace( '#/+#', '/', $path );
		// Strip trailing slash except for root.
		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
		}
		return strtolower( $path );
	}

	/**
	 * Stamp _cfqr_created_at the first time a redirect transitions to publish.
	 */
	public static function stamp_created_at( $new_status, $old_status, $post ) {
		if ( CFQR_REDIRECT_POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( 'publish' !== $new_status ) {
			return;
		}
		$existing = get_post_meta( $post->ID, self::META_CREATED_AT, true );
		if ( '' === $existing ) {
			update_post_meta( $post->ID, self::META_CREATED_AT, gmdate( 'c' ) );
		}
	}
}
