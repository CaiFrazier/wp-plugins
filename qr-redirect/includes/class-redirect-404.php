<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * 404 capture and one-click redirect creation.
 *
 * On every front-end 404, we record the requested path into a private CPT
 * keyed by sha1(path). Repeat 404s for the same path increment a hit counter
 * rather than creating duplicate rows. The admin list table surfaces these,
 * sorted by hits desc, with a "Create redirect" action that pre-fills the
 * cfqr_redirect editor via query args.
 *
 * Retention: a daily wp_cron event prunes rows older than 90 days so the
 * datastore doesn't grow unbounded on a site with chronic 404s.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Redirect_404 {

	const META_PATH      = '_cfqr_404_path';
	const META_HIT_COUNT = '_cfqr_hit_count';
	const META_FIRST_SEEN = '_cfqr_first_seen';
	const META_LAST_SEEN  = '_cfqr_last_seen';
	const META_REFERER    = '_cfqr_last_referer';
	const META_IGNORED    = '_cfqr_ignored';

	const RETENTION_DAYS  = 90;
	const CRON_HOOK       = 'cfqr_404_cleanup';
	// Path length cap. Anything longer is almost certainly probing junk and
	// we don't want it filling our datastore.
	const MAX_PATH_LENGTH = 512;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_capture' ), 999 );

		// Daily cleanup of old rows. The wp_cron event is scheduled in the
		// plugin's activation hook and unscheduled on deactivation.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cleanup' ) );

		if ( is_admin() ) {
			add_filter( 'manage_' . CFQR_404_POST_TYPE . '_posts_columns', array( __CLASS__, 'list_columns' ) );
			add_action( 'manage_' . CFQR_404_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );
			add_filter( 'manage_edit-' . CFQR_404_POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
			add_action( 'pre_get_posts', array( __CLASS__, 'apply_list_defaults' ) );
			add_filter( 'bulk_actions-edit-' . CFQR_404_POST_TYPE, array( __CLASS__, 'register_bulk_actions' ) );
			add_filter( 'handle_bulk_actions-edit-' . CFQR_404_POST_TYPE, array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
			add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );

			// Pre-fill the new-redirect form when the user clicks "Create redirect"
			// from a 404 row. The query args feed the meta-box default state.
			add_action( 'admin_footer-post-new.php', array( __CLASS__, 'maybe_prefill_new_redirect' ) );
		}
	}

	/**
	 * Register the private cfqr_404 CPT. Not public, not searchable, not in
	 * REST. show_in_menu nests under QR Codes (one branded surface).
	 */
	public static function register() {
		$labels = array(
			'name'               => _x( '404 Captures', 'post type general name', 'cf-qr-redirect' ),
			'singular_name'      => _x( '404 Capture', 'post type singular name', 'cf-qr-redirect' ),
			'menu_name'          => _x( '404 Captures', 'admin menu', 'cf-qr-redirect' ),
			'all_items'          => __( 'All 404s', 'cf-qr-redirect' ),
			'search_items'       => __( 'Search 404s', 'cf-qr-redirect' ),
			'not_found'          => __( 'No 404s captured yet. Anyone reaching a missing page on your site will show up here.', 'cf-qr-redirect' ),
			'not_found_in_trash' => __( 'No captured 404s in Trash.', 'cf-qr-redirect' ),
		);

		// Read/manage caps mapped to CFQR_REDIRECT_CAP_MANAGE_404. Create is
		// effectively no-op for humans (rows are inserted by the capture hook
		// only). We don't expose "Add new" so the cap is mostly read+delete.
		$caps = array(
			'edit_post'              => CFQR_REDIRECT_CAP_MANAGE_404,
			'read_post'              => CFQR_REDIRECT_CAP_MANAGE_404,
			'delete_post'            => CFQR_REDIRECT_CAP_MANAGE_404,
			'edit_posts'             => CFQR_REDIRECT_CAP_MANAGE_404,
			'edit_others_posts'      => CFQR_REDIRECT_CAP_MANAGE_404,
			'publish_posts'          => CFQR_REDIRECT_CAP_MANAGE_404,
			'read_private_posts'     => CFQR_REDIRECT_CAP_MANAGE_404,
			'delete_posts'           => CFQR_REDIRECT_CAP_MANAGE_404,
			'delete_private_posts'   => CFQR_REDIRECT_CAP_MANAGE_404,
			'delete_published_posts' => CFQR_REDIRECT_CAP_MANAGE_404,
			'delete_others_posts'    => CFQR_REDIRECT_CAP_MANAGE_404,
			'edit_private_posts'     => CFQR_REDIRECT_CAP_MANAGE_404,
			'edit_published_posts'   => CFQR_REDIRECT_CAP_MANAGE_404,
			// Disable "Add New" — humans don't create 404 rows.
			'create_posts'           => 'do_not_allow',
		);

		register_post_type(
			CFQR_404_POST_TYPE,
			array(
				'labels'             => $labels,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . CFQR_POST_TYPE,
				'show_in_rest'       => false,
				'capabilities'       => $caps,
				'map_meta_cap'       => false,
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title' ),
				'rewrite'            => false,
				'query_var'          => false,
				'exclude_from_search' => true,
			)
		);
	}

	/**
	 * Hooked late on template_redirect. If WP resolved this request as a 404,
	 * record the path. We skip when the request was already going to redirect
	 * (e.g. wp_safe_redirect from a 404-to-suggestions plugin), when the URL
	 * is suspicious (probing patterns we don't want to encourage logging of),
	 * and on non-GET methods (capturing POST 404s would inflate the log with
	 * webhook misfires).
	 */
	public static function maybe_capture() {
		if ( ! function_exists( 'is_404' ) || ! is_404() ) {
			return;
		}
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( '' === $path || '/' === $path ) {
			return; // root 404 means the site is broken; not a redirect target
		}
		if ( strlen( $path ) > self::MAX_PATH_LENGTH ) {
			return;
		}
		// Skip reserved prefixes — capturing /wp-admin/* 404s is noise.
		$first = strtolower( explode( '/', ltrim( $path, '/' ), 2 )[0] );
		if ( in_array( $first, array( CFQR_REWRITE_SLUG, 'wp-admin', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-json', 'wp-cron.php', 'xmlrpc.php', 'favicon.ico', 'robots.txt' ), true ) ) {
			return;
		}

		$normalized = CFQR_Redirect_CPT::normalize_path( $path );
		$slug       = sha1( $normalized );

		$referer = isset( $_SERVER['HTTP_REFERER'] )
			? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
			: '';

		// Do the write on shutdown so it doesn't delay the 404 response to the
		// user. Same rationale as the router's hit counter.
		add_action(
			'shutdown',
			function () use ( $normalized, $slug, $referer ) {
				self::record( $normalized, $slug, $referer );
			},
			1
		);
	}

	/**
	 * Insert or increment a 404 row. Uses post_name = sha1(normalized path) so
	 * the lookup is a single indexed query rather than a meta_query JOIN.
	 */
	public static function record( $normalized_path, $slug, $referer = '' ) {
		// Existing row by slug?
		$existing = get_page_by_path( $slug, OBJECT, CFQR_404_POST_TYPE );

		$now = gmdate( 'c' );

		if ( $existing ) {
			// Respect "ignored" — don't refresh last_seen on ignored rows so they
			// stay out of the active-attention view.
			$ignored = (string) get_post_meta( $existing->ID, self::META_IGNORED, true );
			if ( '1' === $ignored ) {
				return;
			}
			global $wpdb;
			// Atomic increment via the same CAST pattern as the redirect router.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = CASE
						WHEN meta_key = %s THEN CAST(meta_value AS UNSIGNED) + 1
						WHEN meta_key = %s THEN %s
					END
					WHERE post_id = %d AND meta_key IN (%s, %s)",
					self::META_HIT_COUNT,
					self::META_LAST_SEEN,
					$now,
					$existing->ID,
					self::META_HIT_COUNT,
					self::META_LAST_SEEN
				)
			);
			if ( '' !== $referer ) {
				update_post_meta( $existing->ID, self::META_REFERER, $referer );
			}
			wp_cache_delete( $existing->ID, 'post_meta' );
			return;
		}

		// New row.
		$post_id = wp_insert_post(
			array(
				'post_type'   => CFQR_404_POST_TYPE,
				'post_status' => 'publish',
				'post_name'   => $slug,
				'post_title'  => $normalized_path,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}
		update_post_meta( $post_id, self::META_PATH, $normalized_path );
		update_post_meta( $post_id, self::META_HIT_COUNT, 1 );
		update_post_meta( $post_id, self::META_FIRST_SEEN, $now );
		update_post_meta( $post_id, self::META_LAST_SEEN, $now );
		if ( '' !== $referer ) {
			update_post_meta( $post_id, self::META_REFERER, $referer );
		}
	}

	// ---------- Retention / cleanup -----------------------------------------

	/**
	 * Schedule the daily cleanup if not already scheduled. Called from the
	 * plugin's activation hook.
	 */
	public static function schedule_cleanup() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cleanup() {
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
	}

	/**
	 * Delete 404 rows whose last_seen is older than RETENTION_DAYS. Ignored
	 * rows are kept indefinitely — an admin who clicked "ignore" wants the
	 * suppression to persist even if the path stops being hit.
	 */
	public static function run_cleanup() {
		global $wpdb;
		$cutoff = gmdate( 'c', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_last ON pm_last.post_id = p.ID AND pm_last.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} pm_ign ON pm_ign.post_id = p.ID AND pm_ign.meta_key = %s
				 WHERE p.post_type = %s
				   AND pm_last.meta_value < %s
				   AND ( pm_ign.meta_value IS NULL OR pm_ign.meta_value <> '1' )",
				self::META_LAST_SEEN,
				self::META_IGNORED,
				CFQR_404_POST_TYPE,
				$cutoff
			)
		);
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	// ---------- Admin list table --------------------------------------------

	public static function list_columns( $columns ) {
		return array(
			'cb'              => $columns['cb'] ?? '',
			'cfqr_404_path'   => __( 'Path', 'cf-qr-redirect' ),
			'cfqr_404_hits'   => __( 'Hits', 'cf-qr-redirect' ),
			'cfqr_404_first'  => __( 'First seen', 'cf-qr-redirect' ),
			'cfqr_404_last'   => __( 'Last seen', 'cf-qr-redirect' ),
			'cfqr_404_ref'    => __( 'Last referer', 'cf-qr-redirect' ),
			'cfqr_404_status' => __( 'Status', 'cf-qr-redirect' ),
		);
	}

	public static function render_list_column( $column, $post_id ) {
		switch ( $column ) {
			case 'cfqr_404_path':
				$path = (string) get_post_meta( $post_id, self::META_PATH, true );
				if ( '' === $path ) {
					$path = (string) get_the_title( $post_id );
				}
				echo '<code>' . esc_html( $path ) . '</code>';
				break;

			case 'cfqr_404_hits':
				echo (int) get_post_meta( $post_id, self::META_HIT_COUNT, true );
				break;

			case 'cfqr_404_first':
				echo esc_html( (string) get_post_meta( $post_id, self::META_FIRST_SEEN, true ) );
				break;

			case 'cfqr_404_last':
				$last = (string) get_post_meta( $post_id, self::META_LAST_SEEN, true );
				if ( '' === $last ) {
					echo '<em>' . esc_html__( 'never', 'cf-qr-redirect' ) . '</em>';
					break;
				}
				$ts = strtotime( $last );
				if ( $ts ) {
					/* translators: %s = human time difference */
					echo esc_html( sprintf( __( '%s ago', 'cf-qr-redirect' ), human_time_diff( $ts, time() ) ) );
				} else {
					echo esc_html( $last );
				}
				break;

			case 'cfqr_404_ref':
				$ref = (string) get_post_meta( $post_id, self::META_REFERER, true );
				if ( '' === $ref ) {
					echo '<em>' . esc_html__( '—', 'cf-qr-redirect' ) . '</em>';
					break;
				}
				$truncated = strlen( $ref ) > 60 ? substr( $ref, 0, 57 ) . '...' : $ref;
				printf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $ref ), esc_html( $truncated ) );
				break;

			case 'cfqr_404_status':
				$ignored = (string) get_post_meta( $post_id, self::META_IGNORED, true );
				if ( '1' === $ignored ) {
					echo '<span class="cfqr-pill cfqr-pill-off">' . esc_html__( 'ignored', 'cf-qr-redirect' ) . '</span>';
					break;
				}
				echo '<span class="cfqr-pill cfqr-pill-warn">' . esc_html__( 'open', 'cf-qr-redirect' ) . '</span>';
				break;
		}
	}

	public static function sortable_columns( $columns ) {
		$columns['cfqr_404_hits']  = 'cfqr_404_hits';
		$columns['cfqr_404_last']  = 'cfqr_404_last';
		$columns['cfqr_404_first'] = 'cfqr_404_first';
		return $columns;
	}

	/**
	 * Default list-table ordering for the 404 captures screen: hits DESC so
	 * the most-trafficked broken paths surface first. Translates sortable
	 * column clicks into the right meta_query orderby.
	 */
	public static function apply_list_defaults( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( CFQR_404_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( '' === $orderby ) {
			// Default order: hits DESC.
			$query->set( 'meta_key', self::META_HIT_COUNT );
			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'order', 'DESC' );
			return;
		}
		if ( 'cfqr_404_hits' === $orderby ) {
			$query->set( 'meta_key', self::META_HIT_COUNT );
			$query->set( 'orderby', 'meta_value_num' );
		} elseif ( 'cfqr_404_last' === $orderby ) {
			$query->set( 'meta_key', self::META_LAST_SEEN );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( 'cfqr_404_first' === $orderby ) {
			$query->set( 'meta_key', self::META_FIRST_SEEN );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	public static function register_bulk_actions( $actions ) {
		// Remove "Edit" (no editor exists for this CPT — its meta is recorder-only).
		unset( $actions['edit'] );
		$actions['cfqr_404_ignore']   = __( 'Mark as ignored', 'cf-qr-redirect' );
		$actions['cfqr_404_unignore'] = __( 'Mark as open (un-ignore)', 'cf-qr-redirect' );
		return $actions;
	}

	public static function handle_bulk_action( $redirect_to, $action, $post_ids ) {
		if ( ! current_user_can( CFQR_REDIRECT_CAP_MANAGE_404 ) ) {
			return $redirect_to;
		}
		$post_ids = array_map( 'absint', (array) $post_ids );
		$post_ids = array_filter( $post_ids );
		if ( empty( $post_ids ) ) {
			return $redirect_to;
		}
		if ( 'cfqr_404_ignore' === $action ) {
			foreach ( $post_ids as $pid ) {
				update_post_meta( $pid, self::META_IGNORED, 1 );
			}
			return add_query_arg( 'cfqr_404_ignored', count( $post_ids ), $redirect_to );
		}
		if ( 'cfqr_404_unignore' === $action ) {
			foreach ( $post_ids as $pid ) {
				delete_post_meta( $pid, self::META_IGNORED );
			}
			return add_query_arg( 'cfqr_404_unignored', count( $post_ids ), $redirect_to );
		}
		return $redirect_to;
	}

	/**
	 * Add per-row actions: "Create redirect" links to the new-redirect editor
	 * with prefill query args. "Trash" is already there from WP defaults.
	 * We drop "Edit" because there's no useful per-row editor for a 404 row.
	 */
	public static function row_actions( $actions, $post ) {
		if ( CFQR_404_POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		// Drop quick-edit and view since neither is meaningful here.
		unset( $actions['edit'], $actions['inline hide-if-no-js'], $actions['view'] );

		$path = (string) get_post_meta( $post->ID, self::META_PATH, true );
		if ( '' === $path ) {
			$path = (string) $post->post_title;
		}

		$create_url = add_query_arg(
			array(
				'post_type'             => CFQR_REDIRECT_POST_TYPE,
				'cfqr_prefill_source'   => rawurlencode( $path ),
				'cfqr_prefill_from_404' => (int) $post->ID,
			),
			admin_url( 'post-new.php' )
		);
		$new_actions = array(
			'cfqr_create_redirect' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $create_url ),
				esc_html__( 'Create redirect', 'cf-qr-redirect' )
			),
		);
		return array_merge( $new_actions, $actions );
	}

	/**
	 * When the user lands on post-new.php?post_type=cfqr_redirect with a
	 * cfqr_prefill_source query arg, inject a small inline script that fills
	 * the source-path input. The destination is left empty — the operator
	 * chooses where the 404 should resolve.
	 *
	 * This is a JS-side prefill rather than a server-side value because the
	 * meta-box renders before this hook fires; the alternative would be a
	 * full meta-box restructure, which is overkill for one-off prefill.
	 */
	public static function maybe_prefill_new_redirect() {
		$screen = get_current_screen();
		if ( ! $screen || CFQR_REDIRECT_POST_TYPE !== $screen->post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['cfqr_prefill_source'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source = sanitize_text_field( wp_unslash( $_GET['cfqr_prefill_source'] ) );
		?>
		<script>
		(function(){
			var input = document.getElementById('cfqr_rd_source');
			if (input && !input.value) {
				input.value = <?php echo wp_json_encode( $source ); ?>;
			}
			var dest = document.getElementById('cfqr_rd_dest');
			if (dest) dest.focus();
		})();
		</script>
		<?php
	}
}
