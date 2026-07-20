<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Custom Post Type registration and meta field definitions for cfqr_code.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_CPT {

	const META_DESTINATION    = '_cfqr_destination';
	const META_CAMPAIGN       = '_cfqr_campaign';
	const META_UTM_SOURCE     = '_cfqr_utm_source';
	const META_UTM_MEDIUM     = '_cfqr_utm_medium';
	const META_ANALYTICS_MODE = '_cfqr_analytics_mode';
	const META_HIT_COUNT      = '_cfqr_hit_count';
	const META_CREATED_AT     = '_cfqr_created_at';
	const META_LAST_HIT_AT    = '_cfqr_last_hit_at';
	// Append-only audit log of destination changes: JSON array of
	// {from, to, user_id, ip, ts} entries, capped at 20 entries.
	const META_DESTINATION_LOG = '_cfqr_destination_log';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'auto_assign_slug' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'stamp_created_at' ), 10, 3 );
	}

	/**
	 * Register the cfqr_code CPT. Public + publicly_queryable so /r/{slug} resolves
	 * via the rewrite rule, but show_in_rest=false to keep the meta off the REST API.
	 */
	public static function register() {
		$labels = array(
			'name'               => _x( 'QR Codes', 'post type general name', 'cf-qr-redirect' ),
			'singular_name'      => _x( 'QR Code', 'post type singular name', 'cf-qr-redirect' ),
			'menu_name'          => _x( 'QR Codes', 'admin menu', 'cf-qr-redirect' ),
			'name_admin_bar'     => _x( 'QR Code', 'add new on admin bar', 'cf-qr-redirect' ),
			'add_new'            => _x( 'Add New', 'qr code', 'cf-qr-redirect' ),
			'add_new_item'       => __( 'Add New QR Code', 'cf-qr-redirect' ),
			'new_item'           => __( 'New QR Code', 'cf-qr-redirect' ),
			'edit_item'          => __( 'Edit QR Code', 'cf-qr-redirect' ),
			'view_item'          => __( 'View QR Code', 'cf-qr-redirect' ),
			'all_items'          => __( 'All QR Codes', 'cf-qr-redirect' ),
			'search_items'       => __( 'Search QR Codes', 'cf-qr-redirect' ),
			'not_found'          => __( 'No QR codes found.', 'cf-qr-redirect' ),
			'not_found_in_trash' => __( 'No QR codes found in Trash.', 'cf-qr-redirect' ),
		);

		// CPT capabilities mapped to the granular prefixed caps (see cf-qr-redirect.php).
		// WordPress uses edit_posts to authorize the list screen and menu, so that
		// primitive maps to the read capability. Row actions remain protected by
		// their separate edit, create, and delete capabilities.
		// map_meta_cap=false means WP checks these literals — no fallback to edit_posts.
		$caps = array(
			'edit_post'              => CFQR_CAP_EDIT,
			'read_post'              => CFQR_CAP_READ,
			'delete_post'            => CFQR_CAP_DELETE,
			'edit_posts'             => CFQR_CAP_READ,
			'edit_others_posts'      => CFQR_CAP_EDIT,
			'publish_posts'          => CFQR_CAP_EDIT,
			'read_private_posts'     => CFQR_CAP_READ,
			'delete_posts'           => CFQR_CAP_DELETE,
			'delete_private_posts'   => CFQR_CAP_DELETE,
			'delete_published_posts' => CFQR_CAP_DELETE,
			'delete_others_posts'    => CFQR_CAP_DELETE,
			'edit_private_posts'     => CFQR_CAP_EDIT,
			'edit_published_posts'   => CFQR_CAP_EDIT,
			'create_posts'           => CFQR_CAP_CREATE,
		);

		register_post_type(
			CFQR_POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => CFQR_MENU_SLUG,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-admin-links',
				'menu_position'       => 26,
				'capabilities'        => $caps,
				'map_meta_cap'        => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				// 'custom-fields' intentionally omitted — protected meta is hidden from that UI
				// anyway, but dropping the supports flag also removes the empty box on the screen.
				'supports'            => array( 'title' ),
				'rewrite'             => array(
					'slug'       => CFQR_REWRITE_SLUG,
					'with_front' => false,
					'feeds'      => false,
					'pages'      => false,
				),
				'query_var'           => CFQR_POST_TYPE,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Register every post meta key with REST disabled and an admin-only auth callback.
	 */
	public static function register_meta() {
		$auth_cb = function () {
			return current_user_can( CFQR_CAP_EDIT );
		};

		$keys = array(
			self::META_DESTINATION     => array(
				'type'     => 'string',
				'sanitize' => 'esc_url_raw',
			),
			self::META_CAMPAIGN        => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			self::META_UTM_SOURCE      => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			self::META_UTM_MEDIUM      => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			self::META_ANALYTICS_MODE  => array(
				'type'     => 'string',
				'sanitize' => array( __CLASS__, 'sanitize_mode' ),
			),
			self::META_HIT_COUNT       => array(
				'type'     => 'integer',
				'sanitize' => 'absint',
			),
			self::META_CREATED_AT      => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			self::META_LAST_HIT_AT     => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
			self::META_DESTINATION_LOG => array(
				'type'     => 'string',
				'sanitize' => 'sanitize_text_field',
			),
		);

		foreach ( $keys as $key => $cfg ) {
			register_post_meta(
				CFQR_POST_TYPE,
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

	public static function sanitize_mode( $value ) {
		$value = sanitize_key( $value );
		return in_array( $value, array( 'utm', 'intermediate' ), true ) ? $value : 'utm';
	}

	/**
	 * If the user hasn't supplied a slug for a new cfqr_code post, generate one.
	 * Validates user-supplied vanity slugs and falls back to auto-gen when invalid.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature requires $postarr.
	public static function auto_assign_slug( $data, $postarr ) {
		if ( CFQR_POST_TYPE !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		// Revisions are stored as post_status='inherit' with parent_id-revision-vN slugs.
		// Don't touch them.
		if ( isset( $data['post_status'] ) && 'inherit' === $data['post_status'] ) {
			return $data;
		}

		// If the meta box was submitted, the cfqr_custom_slug input is the canonical
		// source of truth for the slug — overrides whatever the WP permalink editor
		// (or auto-draft) put in $data['post_name']. The nonce check ensures we
		// only react to real user form submissions, not programmatic post updates.
		$is_meta_box_submission = isset( $_POST[ CFQR_Admin::META_NONCE_FIELD ] )
			&& wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST[ CFQR_Admin::META_NONCE_FIELD ] ) ),
				CFQR_Admin::META_NONCE_ACTION
			);

		if ( $is_meta_box_submission && isset( $_POST['cfqr_custom_slug'] ) ) {
			$user_slug = trim( sanitize_text_field( wp_unslash( $_POST['cfqr_custom_slug'] ) ) );
			// Empty input is a deliberate "auto-generate" signal on first save and
			// a no-op on subsequent saves (we leave the existing slug alone).
			if ( '' !== $user_slug ) {
				$data['post_name'] = $user_slug;
			} elseif ( '' === (string) ( $data['post_name'] ?? '' ) ) {
				// First save with no custom slug → fall through to auto-gen below.
				$data['post_name'] = '';
			}
		}

		$proposed = isset( $data['post_name'] ) ? trim( $data['post_name'] ) : '';

		if ( '' === $proposed ) {
			// No slug supplied — generate one. WP will still pass it through wp_unique_post_slug.
			$data['post_name'] = CFQR_Slug_Generator::generate();
			return $data;
		}

		// User supplied a vanity slug. Reject reserved/conflicting values so the QR
		// can't accidentally encode a path that shadows core admin or rewrite roots.
		$reserved = array( 'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'admin', CFQR_REWRITE_SLUG );
		if ( ! CFQR_Slug_Generator::is_valid( $proposed ) || in_array( strtolower( $proposed ), $reserved, true ) ) {
			$data['post_name'] = CFQR_Slug_Generator::generate();
		}

		return $data;
	}

	/**
	 * Stamp _cfqr_created_at the first time a code transitions to publish.
	 */
	public static function stamp_created_at( $new_status, $old_status, $post ) {
		if ( CFQR_POST_TYPE !== $post->post_type ) {
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
