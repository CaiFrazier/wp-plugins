<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Admin UI: list columns, meta box, settings page, asset enqueue.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Admin {

	const META_NONCE_FIELD  = 'cfqr_meta_nonce';
	const META_NONCE_ACTION = 'cfqr_save_meta';

	public static function init() {
		// List screen columns.
		add_filter( 'manage_' . CFQR_POST_TYPE . '_posts_columns', array( __CLASS__, 'list_columns' ) );
		add_action( 'manage_' . CFQR_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );
		add_filter( 'manage_edit-' . CFQR_POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_list_sorting' ) );

		// Campaign filter dropdown above the list table.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_campaign_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_campaign_filter' ) );

		// Bulk action: download QR PNGs as a ZIP. Wired into the standard list-table flow.
		add_filter( 'bulk_actions-edit-' . CFQR_POST_TYPE, array( __CLASS__, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . CFQR_POST_TYPE, array( __CLASS__, 'handle_bulk_zip' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'register_export_page' ), 11 );

		// Edit screen meta box. Priority 1 ensures it registers before Yoast and
		// other plugins that hook add_meta_boxes at the default priority 10.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ), 1 );
		add_action( 'save_post_' . CFQR_POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );

		// Suppress WP's built-in permalink editor for our CPT. The cfqr_custom_slug
		// input in the meta box is the canonical place to edit the slug — having
		// two slug controls on the same screen is confusing.
		add_filter( 'get_sample_permalink_html', array( __CLASS__, 'hide_permalink_editor' ), 10, 2 );

		// Asset enqueue (admin only).
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		// Surface deferred admin notices written by save_meta.
		add_action( 'admin_notices', array( __CLASS__, 'render_deferred_notices' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_bulk_zip_errors' ) );

		// Settings page.
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// Quick edit support.
		add_action( 'quick_edit_custom_box', array( __CLASS__, 'quick_edit_box' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( __CLASS__, 'quick_edit_inline_script' ) );

		// Bust the campaign-filter choice cache whenever a code is saved or
		// deleted. The filter dropdown only changes when post meta changes.
		add_action( 'save_post_' . CFQR_POST_TYPE, array( __CLASS__, 'invalidate_campaign_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'invalidate_campaign_cache' ) );
		add_action( 'trashed_post', array( __CLASS__, 'invalidate_campaign_cache' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'invalidate_campaign_cache' ) );
	}

	const CAMPAIGN_CACHE_KEY = 'cfqr_campaign_choices';

	/**
	 * Distinct non-empty campaign values for the list-table filter, cached for
	 * 12 hours and invalidated on any cfqr_code save/delete. The raw query is a
	 * postmeta+posts JOIN that gets expensive once code volume grows past a few
	 * thousand rows; caching it makes list-table loads constant-time.
	 */
	public static function get_campaign_filter_choices() {
		$cached = get_transient( self::CAMPAIGN_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		// Direct query: postmeta + posts JOIN with DISTINCT has no WP_Query equivalent
		// that doesn't load entire post rows. Result is cached for 12 hours in the
		// transient above (busted on any cfqr_code save/delete by invalidate_campaign_cache),
		// so this query only runs once after each mutation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value
				 FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value <> ''
				   AND p.post_type = %s
				   AND p.post_status NOT IN ('trash','auto-draft')
				 ORDER BY meta_value ASC",
				CFQR_CPT::META_CAMPAIGN,
				CFQR_POST_TYPE
			)
		);
		$rows = is_array( $rows ) ? array_values( array_filter( $rows ) ) : array();
		set_transient( self::CAMPAIGN_CACHE_KEY, $rows, 12 * HOUR_IN_SECONDS );
		return $rows;
	}

	/**
	 * Cache invalidation hook used for save/delete/trash/untrash. Only acts on
	 * cfqr_code rows so other post types don't bust this cache.
	 */
	public static function invalidate_campaign_cache( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || CFQR_POST_TYPE !== $post->post_type ) {
			return;
		}
		delete_transient( self::CAMPAIGN_CACHE_KEY );
	}

	/**
	 * Override the columns shown on the QR Codes list table.
	 */
	public static function list_columns( $columns ) {
		$new = array(
			'cb'          => $columns['cb'] ?? '',
			'title'       => __( 'Label', 'cf-qr-redirect' ),
			'cfqr_short'  => __( 'Short URL', 'cf-qr-redirect' ),
			'cfqr_dest'   => __( 'Destination', 'cf-qr-redirect' ),
			'cfqr_camp'   => __( 'Campaign', 'cf-qr-redirect' ),
			'cfqr_hits'   => __( 'Hits', 'cf-qr-redirect' ),
			'cfqr_last'   => __( 'Last Hit', 'cf-qr-redirect' ),
			'cfqr_status' => __( 'Status', 'cf-qr-redirect' ),
			'date'        => __( 'Date', 'cf-qr-redirect' ),
		);
		return $new;
	}

	public static function render_list_column( $column, $post_id ) {
		switch ( $column ) {
			case 'cfqr_short':
				$post = get_post( $post_id );
				if ( ! $post ) {
					return;
				}
				$short_url = self::short_url_for( $post );
				printf(
					'<code class="cfqr-short">%s</code> <button type="button" class="button-link cfqr-copy" data-clipboard="%s" title="%s">%s</button>',
					esc_html( str_replace( array( 'https://', 'http://' ), '', $short_url ) ),
					esc_attr( $short_url ),
					esc_attr__( 'Copy short URL to clipboard', 'cf-qr-redirect' ),
					esc_html__( 'Copy', 'cf-qr-redirect' )
				);
				break;

			case 'cfqr_dest':
				$dest = (string) get_post_meta( $post_id, CFQR_CPT::META_DESTINATION, true );
				// Hidden span carries the raw value for Quick Edit pre-fill — using a
				// data attribute means the JS doesn't depend on the truncated visible text.
				printf( '<span class="cfqr-dest-inline" data-dest="%s" hidden></span>', esc_attr( $dest ) );
				if ( '' === $dest ) {
					echo '<em>' . esc_html__( '(none)', 'cf-qr-redirect' ) . '</em>';
					break;
				}
				$truncated = strlen( $dest ) > 60 ? substr( $dest, 0, 57 ) . '...' : $dest;
				printf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $dest ), esc_html( $truncated ) );
				break;

			case 'cfqr_camp':
				$camp = (string) get_post_meta( $post_id, CFQR_CPT::META_CAMPAIGN, true );
				printf( '<span class="cfqr-camp-inline" data-campaign="%s" hidden></span>', esc_attr( $camp ) );
				echo esc_html( $camp );
				break;

			case 'cfqr_hits':
				echo (int) get_post_meta( $post_id, CFQR_CPT::META_HIT_COUNT, true );
				break;

			case 'cfqr_last':
				$last = (string) get_post_meta( $post_id, CFQR_CPT::META_LAST_HIT_AT, true );
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

			case 'cfqr_status':
				$status = get_post_status( $post_id );
				echo esc_html( ucfirst( $status ) );
				break;
		}
	}

	public static function sortable_columns( $columns ) {
		$columns['cfqr_hits'] = 'cfqr_hits';
		$columns['cfqr_last'] = 'cfqr_last';
		return $columns;
	}

	/**
	 * Translate sortable column requests into meta-key orderby on the list table.
	 */
	public static function apply_list_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( CFQR_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( 'cfqr_hits' === $orderby ) {
			$query->set( 'meta_key', CFQR_CPT::META_HIT_COUNT );
			$query->set( 'orderby', 'meta_value_num' );
		} elseif ( 'cfqr_last' === $orderby ) {
			$query->set( 'meta_key', CFQR_CPT::META_LAST_HIT_AT );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Render a Campaign filter dropdown above the list table.
	 * Distinct campaigns are pulled directly from postmeta so we don't need a taxonomy.
	 */
	public static function render_campaign_filter( $post_type ) {
		if ( CFQR_POST_TYPE !== $post_type ) {
			return;
		}
		$campaigns = self::get_campaign_filter_choices();
		// List-table filter — same nonceless convention as core's search/paging/filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_GET['cfqr_campaign'] ) ? sanitize_text_field( wp_unslash( $_GET['cfqr_campaign'] ) ) : '';
		?>
		<select name="cfqr_campaign" id="cfqr-campaign-filter">
			<option value=""><?php esc_html_e( 'All campaigns', 'cf-qr-redirect' ); ?></option>
			<?php foreach ( $campaigns as $c ) : ?>
				<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $current, $c ); ?>><?php echo esc_html( $c ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Filter the list-table query when a campaign is selected.
	 */
	public static function apply_campaign_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( CFQR_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		// List-table filter — see render_campaign_filter() for nonce rationale.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['cfqr_campaign'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$campaign = sanitize_text_field( wp_unslash( $_GET['cfqr_campaign'] ) );
		$existing = $query->get( 'meta_query' );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$existing[] = array(
			'key'   => CFQR_CPT::META_CAMPAIGN,
			'value' => $campaign,
		);
		$query->set( 'meta_query', $existing );
	}

	/**
	 * Add our "Download QR PNGs (ZIP)" entry to the bulk-actions menu.
	 */
	public static function register_bulk_actions( $actions ) {
		$actions['cfqr_zip'] = __( 'Download QR PNGs (ZIP)', 'cf-qr-redirect' );
		return $actions;
	}

	/**
	 * Handle the bulk action: stage the selected codes' data into a transient
	 * keyed by a one-shot token, then redirect to the hidden export page where
	 * the JS-driven ZIP build runs.
	 */
	public static function handle_bulk_zip( $redirect_to, $action, $post_ids ) {
		if ( 'cfqr_zip' !== $action ) {
			return $redirect_to;
		}
		if ( ! current_user_can( CFQR_CAP_EXPORT ) ) {
			return $redirect_to;
		}
		$post_ids = array_map( 'absint', (array) $post_ids );
		$post_ids = array_filter( $post_ids );
		if ( empty( $post_ids ) ) {
			return add_query_arg( 'cfqr_zip_error', 'empty', $redirect_to );
		}

		$payload = array();
		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || CFQR_POST_TYPE !== $post->post_type || '' === (string) $post->post_name ) {
				continue;
			}
			$payload[] = array(
				'id'    => (int) $pid,
				'slug'  => (string) $post->post_name,
				'label' => (string) $post->post_title,
				'url'   => self::short_url_for( $post ),
			);
		}

		if ( empty( $payload ) ) {
			return add_query_arg( 'cfqr_zip_error', 'none-eligible', $redirect_to );
		}

		// One-shot token: the export page reads + deletes it on first render.
		// Binding the transient to the creating user's ID prevents another
		// authenticated user from consuming a token they didn't create, even
		// though the probability of guessing a 128-bit random token is negligible.
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			$token = wp_generate_password( 32, false, false );
		}
		set_transient(
			'cfqr_zip_' . $token,
			array(
				'user_id' => get_current_user_id(),
				'items'   => $payload,
			),
			5 * MINUTE_IN_SECONDS
		);

		return admin_url( 'edit.php?post_type=' . CFQR_POST_TYPE . '&page=cfqr-zip-export&token=' . rawurlencode( $token ) );
	}

	/**
	 * Register the hidden export page. It lives under the QR Codes top-level menu so
	 * the URL stays scoped, but doesn't appear in the menu itself.
	 */
	public static function register_export_page() {
		$hook = add_submenu_page(
			'edit.php?post_type=' . CFQR_POST_TYPE,
			__( 'Export QR Codes', 'cf-qr-redirect' ),
			__( 'Export QR Codes', 'cf-qr-redirect' ),
			CFQR_CAP_EXPORT,
			'cfqr-zip-export',
			array( __CLASS__, 'render_export_page' )
		);
		// Hide from the sidebar — only reachable via the bulk-action redirect.
		remove_submenu_page( 'edit.php?post_type=' . CFQR_POST_TYPE, 'cfqr-zip-export' );
	}

	/**
	 * Render the export page: pull the staged payload, then bootstrap the JS
	 * that generates QR PNGs in canvases and bundles them via JSZip.
	 */
	public static function render_export_page() {
		if ( ! current_user_can( CFQR_CAP_EXPORT ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'cf-qr-redirect' ), 403 );
		}
		// The 128-bit random token is itself the one-shot proof — it's generated
		// by handle_bulk_zip() (which IS protected by the bulk-action nonce) and
		// invalidated on first read, so a standard request-nonce here would be
		// redundant.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$raw   = $token ? get_transient( 'cfqr_zip_' . $token ) : false;
		if ( $token ) {
			delete_transient( 'cfqr_zip_' . $token );
		}

		// Validate: must be an array with items created by this user.
		// The user_id check prevents one authenticated user from consuming
		// another's export token, even though guessing a 128-bit token is
		// practically infeasible.
		$items = null;
		if (
			is_array( $raw )
			&& ! empty( $raw['items'] )
			&& (int) ( $raw['user_id'] ?? 0 ) === get_current_user_id()
		) {
			$items = $raw['items'];
		}

		$logo_url = CFQR_PLUGIN_URL . 'assets/cf-logo.svg';
		$back_url = admin_url( 'edit.php?post_type=' . CFQR_POST_TYPE );
		?>
		<div class="wrap">
			<h1 class="cfqr-h1">
				<img class="cfqr-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="CF">
				<span class="cfqr-h1-text"><?php esc_html_e( 'Export QR Codes', 'cf-qr-redirect' ); ?></span>
			</h1>
			<hr class="wp-header-end">

			<?php if ( ! is_array( $items ) || empty( $items ) ) : ?>
				<div class="notice notice-error"><p>
					<?php esc_html_e( 'No export data found, or this export link has already been used. Re-run the bulk action from the QR Codes list.', 'cf-qr-redirect' ); ?>
				</p></div>
				<p><a href="<?php echo esc_url( $back_url ); ?>" class="button"><?php esc_html_e( '← Back to QR Codes', 'cf-qr-redirect' ); ?></a></p>
				<?php return; ?>
			<?php endif; ?>

			<div class="cfqr-section">
				<h2>
					<?php
					/* translators: %d = number of codes */
					echo esc_html( sprintf( _n( 'Bundling %d QR code', 'Bundling %d QR codes', count( $items ), 'cf-qr-redirect' ), count( $items ) ) );
					?>
				</h2>

				<div id="cfqr-zip-status" class="cfqr-status-line" aria-live="polite">
					<span class="spinner is-active" style="float:none;margin:0"></span>
					<span class="cfqr-status-text"><?php esc_html_e( 'Generating PNGs in your browser…', 'cf-qr-redirect' ); ?></span>
				</div>

				<div class="cfqr-bar-track">
					<div id="cfqr-zip-bar"></div>
				</div>

				<p class="description"><?php esc_html_e( 'The download will start automatically once every PNG is rendered. Each code is generated at 1000×1000 with Level H error correction (suitable for print up to ~3").', 'cf-qr-redirect' ); ?></p>

				<script type="application/json" id="cfqr-zip-payload"><?php echo wp_json_encode( $items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
				<input type="hidden" id="cfqr-zip-total" value="<?php echo esc_attr( count( $items ) ); ?>">

				<p>
					<a href="<?php echo esc_url( $back_url ); ?>" class="button"><?php esc_html_e( '← Back to QR Codes', 'cf-qr-redirect' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Construct the canonical short URL for a code.
	 */
	public static function short_url_for( $post ) {
		return trailingslashit( home_url( CFQR_REWRITE_SLUG ) ) . $post->post_name;
	}

	public static function register_meta_box( $post_type ) {
		if ( CFQR_POST_TYPE !== $post_type ) {
			return;
		}
		add_meta_box(
			'cfqr_main',
			__( 'QR Code', 'cf-qr-redirect' ),
			array( __CLASS__, 'render_meta_box' ),
			CFQR_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Returns empty markup for the WP permalink editor on our CPT so users
	 * have one canonical slug control (the cfqr_custom_slug input).
	 */
	public static function hide_permalink_editor( $html, $post_id ) {
		$post = get_post( $post_id );
		if ( $post && CFQR_POST_TYPE === $post->post_type ) {
			return '';
		}
		return $html;
	}

	public static function render_meta_box( $post ) {
		// Defense in depth: the CPT restricts caps to CFQR_CAP_EDIT, so users
		// without that cap shouldn't reach this screen. Bail anyway.
		if ( ! current_user_can( CFQR_CAP_EDIT ) ) {
			esc_html_e( 'You do not have permission to manage QR codes.', 'cf-qr-redirect' );
			return;
		}

		wp_nonce_field( self::META_NONCE_ACTION, self::META_NONCE_FIELD );

		$destination = (string) get_post_meta( $post->ID, CFQR_CPT::META_DESTINATION, true );
		$campaign    = (string) get_post_meta( $post->ID, CFQR_CPT::META_CAMPAIGN, true );
		$mode        = (string) get_post_meta( $post->ID, CFQR_CPT::META_ANALYTICS_MODE, true );
		$source      = (string) get_post_meta( $post->ID, CFQR_CPT::META_UTM_SOURCE, true );
		$medium      = (string) get_post_meta( $post->ID, CFQR_CPT::META_UTM_MEDIUM, true );
		$hits        = (int) get_post_meta( $post->ID, CFQR_CPT::META_HIT_COUNT, true );
		$created     = (string) get_post_meta( $post->ID, CFQR_CPT::META_CREATED_AT, true );
		$last_hit    = (string) get_post_meta( $post->ID, CFQR_CPT::META_LAST_HIT_AT, true );

		if ( '' === $mode ) {
			$mode = (string) CFQR_Settings::get( 'default_analytics_mode' );
		}
		if ( '' === $source ) {
			$source = (string) CFQR_Settings::get( 'default_utm_source' );
		}
		if ( '' === $medium ) {
			$medium = (string) CFQR_Settings::get( 'default_utm_medium' );
		}

		$has_slug     = '' !== (string) $post->post_name;
		$is_published = 'publish' === $post->post_status;
		$short_url    = $has_slug ? self::short_url_for( $post ) : '';
		$url_prefix   = trailingslashit( home_url( CFQR_REWRITE_SLUG ) );
		$ga4_id       = CFQR_Settings::resolve_ga4_id();

		?>
		<div class="cfqr-metabox">

			<!-- Slug + QR preview ------------------------------------------------ -->
			<div class="cfqr-subsection">
				<h3 class="cfqr-subhead"><?php esc_html_e( 'Short URL & preview', 'cf-qr-redirect' ); ?></h3>

				<div class="cfqr-row">
					<label class="cfqr-label" for="cfqr_custom_slug"><?php esc_html_e( 'Custom slug', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<div class="cfqr-slug-editor">
							<code class="cfqr-slug-prefix"><?php echo esc_html( $url_prefix ); ?></code>
							<input type="text" name="cfqr_custom_slug" id="cfqr_custom_slug"
								value="<?php echo esc_attr( $post->post_name ); ?>"
								placeholder="<?php esc_attr_e( 'auto', 'cf-qr-redirect' ); ?>"
								class="cfqr-slug-input"
								autocomplete="off"
								spellcheck="false">
						</div>
						<p class="description">
							<?php esc_html_e( '3–64 chars; letters, numbers, and hyphens. Leave blank to auto-generate. Invalid or duplicate slugs are silently replaced with an auto-generated short code.', 'cf-qr-redirect' ); ?>
						</p>
						<?php if ( $has_slug ) : ?>
							<p>
								<button type="button" class="button button-small cfqr-copy" data-clipboard="<?php echo esc_attr( $short_url ); ?>"><?php esc_html_e( 'Copy URL', 'cf-qr-redirect' ); ?></button>
							</p>
						<?php endif; ?>
					</div>
				</div>

				<div class="cfqr-row">
					<label class="cfqr-label"><?php esc_html_e( 'QR Code', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<?php if ( $has_slug && $is_published ) : ?>
							<div id="cfqr-qrcode" data-url="<?php echo esc_attr( $short_url ); ?>"></div>
							<p>
								<button type="button" class="button button-secondary" id="cfqr-download-png"><?php esc_html_e( 'Download PNG (1000×1000)', 'cf-qr-redirect' ); ?></button>
							</p>
						<?php else : ?>
							<div class="cfqr-pending">
								<span class="dashicons dashicons-camera-alt cfqr-pending-icon" aria-hidden="true"></span>
								<div class="cfqr-pending-text">
									<?php if ( ! $has_slug ) : ?>
										<strong><?php esc_html_e( 'Save once to lock in a slug.', 'cf-qr-redirect' ); ?></strong><br>
										<?php esc_html_e( 'The QR code will appear after the post is published.', 'cf-qr-redirect' ); ?>
									<?php else : ?>
										<strong><?php esc_html_e( 'QR code is generated on first publish.', 'cf-qr-redirect' ); ?></strong><br>
										<?php esc_html_e( 'Tweak the slug, destination, and analytics options below. The QR is not created until you click Publish — that way you never end up with a printed code that points at a stale URL.', 'cf-qr-redirect' ); ?>
									<?php endif; ?>
								</div>
							</div>
							<?php if ( $has_slug ) : ?>
								<p class="cfqr-preview-url">
									<strong><?php esc_html_e( 'Preview URL:', 'cf-qr-redirect' ); ?></strong>
									<code><?php echo esc_html( $short_url ); ?></code>
									<button type="button" class="button-link cfqr-copy" data-clipboard="<?php echo esc_attr( $short_url ); ?>" title="<?php esc_attr_e( 'Copy preview URL to clipboard', 'cf-qr-redirect' ); ?>"><?php esc_html_e( 'Copy', 'cf-qr-redirect' ); ?></button>
									<br>
									<span class="description"><?php esc_html_e( 'This is the exact short URL that will be encoded into the QR. Verify it matches your slug before publishing — it locks in once you click Publish.', 'cf-qr-redirect' ); ?></span>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Destination + analytics ------------------------------------------ -->
			<div class="cfqr-subsection">
				<h3 class="cfqr-subhead"><?php esc_html_e( 'Destination & analytics', 'cf-qr-redirect' ); ?></h3>

				<div class="cfqr-row">
					<label class="cfqr-label" for="cfqr_destination"><?php esc_html_e( 'Destination URL', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<input type="url" name="cfqr_destination" id="cfqr_destination" value="<?php echo esc_attr( $destination ); ?>" placeholder="https://example.com/landing-page" class="large-text" required>
						<p class="description"><?php esc_html_e( 'Where the short URL redirects. Internal or external. Must be http(s).', 'cf-qr-redirect' ); ?></p>
					</div>
				</div>

				<div class="cfqr-row">
					<label class="cfqr-label"><?php esc_html_e( 'Analytics mode', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control cfqr-mode-picker">
						<label class="cfqr-mode-option <?php echo 'utm' === $mode ? 'is-active' : ''; ?>">
							<input type="radio" name="cfqr_analytics_mode" value="utm" <?php checked( 'utm', $mode ); ?>>
							<span class="cfqr-mode-pill cfqr-pill-utm"><?php esc_html_e( 'UTM', 'cf-qr-redirect' ); ?></span>
							<span class="cfqr-mode-text"><?php esc_html_e( 'Fast 302 redirect, appends utm_* params to the destination URL.', 'cf-qr-redirect' ); ?></span>
						</label>
						<label class="cfqr-mode-option <?php echo 'intermediate' === $mode ? 'is-active' : ''; ?>">
							<input type="radio" name="cfqr_analytics_mode" value="intermediate" <?php checked( 'intermediate', $mode ); ?>>
							<span class="cfqr-mode-pill cfqr-pill-event"><?php esc_html_e( 'Event', 'cf-qr-redirect' ); ?></span>
							<span class="cfqr-mode-text"><?php esc_html_e( 'Renders a minimal page that fires a GA4 event before redirecting. Best for external destinations.', 'cf-qr-redirect' ); ?></span>
						</label>
						<?php if ( '' === $ga4_id ) : ?>
							<p class="cfqr-warn-line cfqr-intermediate-only"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e( 'No GA4 Measurement ID configured — Intermediate mode falls back to UTM. Add one under QR Codes → Settings.', 'cf-qr-redirect' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Campaign + UTM / event preview ----------------------------------- -->
			<div class="cfqr-subsection">
				<h3 class="cfqr-subhead"><?php esc_html_e( 'Campaign & tagging', 'cf-qr-redirect' ); ?></h3>

				<div class="cfqr-row">
					<label class="cfqr-label" for="cfqr_campaign"><?php esc_html_e( 'Campaign name', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<input type="text" name="cfqr_campaign" id="cfqr_campaign" value="<?php echo esc_attr( $campaign ); ?>" placeholder="spring-mailer-2026" class="regular-text">
						<p class="description"><?php esc_html_e( 'Sent to GA4 as utm_campaign and as the campaign field on the qr_redirect event. Slugified on save (e.g. "Spring Mailer 2026" → "spring-mailer-2026").', 'cf-qr-redirect' ); ?></p>
					</div>
				</div>

				<div class="cfqr-row cfqr-utm-only">
					<label class="cfqr-label" for="cfqr_utm_source"><?php esc_html_e( 'UTM source', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<input type="text" name="cfqr_utm_source" id="cfqr_utm_source" value="<?php echo esc_attr( $source ); ?>" class="regular-text">
					</div>
				</div>

				<div class="cfqr-row cfqr-utm-only">
					<label class="cfqr-label" for="cfqr_utm_medium"><?php esc_html_e( 'UTM medium', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<input type="text" name="cfqr_utm_medium" id="cfqr_utm_medium" value="<?php echo esc_attr( $medium ); ?>" class="regular-text">
					</div>
				</div>

				<div class="cfqr-row cfqr-intermediate-only">
					<label class="cfqr-label"><?php esc_html_e( 'GA4 event preview', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<pre id="cfqr-event-preview" class="cfqr-codeblock"></pre>
					</div>
				</div>
			</div>

			<!-- Stats ----------------------------------------------------------- -->
			<div class="cfqr-subsection">
				<h3 class="cfqr-subhead"><?php esc_html_e( 'Performance', 'cf-qr-redirect' ); ?></h3>
				<div class="cfqr-row">
					<label class="cfqr-label"><?php esc_html_e( 'Stats', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<div class="cfqr-stat-row">
							<span class="cfqr-stat">
								<span class="cfqr-stat-num"><?php echo (int) $hits; ?></span>
								<span class="cfqr-stat-label"><?php esc_html_e( 'total hits', 'cf-qr-redirect' ); ?></span>
							</span>
							<span class="cfqr-stat">
								<span class="cfqr-stat-label"><?php esc_html_e( 'Last hit', 'cf-qr-redirect' ); ?></span>
								<span class="cfqr-stat-val">
									<?php echo $last_hit ? esc_html( $last_hit ) : '<em>' . esc_html__( 'never', 'cf-qr-redirect' ) . '</em>'; ?>
								</span>
							</span>
							<span class="cfqr-stat">
								<span class="cfqr-stat-label"><?php esc_html_e( 'Created', 'cf-qr-redirect' ); ?></span>
								<span class="cfqr-stat-val">
									<?php echo $created ? esc_html( $created ) : '<em>' . esc_html__( 'on first publish', 'cf-qr-redirect' ) . '</em>'; ?>
								</span>
							</span>
						</div>
					<?php
					if ( '' !== $ga4_id && '' !== $post->post_name ) :
						$ga4_link = CFQR_Settings::build_ga4_link();
						?>
						<p>
							<a href="<?php echo esc_url( $ga4_link ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
								<?php esc_html_e( 'Open GA4', 'cf-qr-redirect' ); ?>
							</a>
							&nbsp;
							<?php esc_html_e( 'Then filter by:', 'cf-qr-redirect' ); ?>
							<code>utm_content = <?php echo esc_html( $post->post_name ); ?></code>
							<button type="button" class="button-link cfqr-copy" data-clipboard="<?php echo esc_attr( $post->post_name ); ?>" title="<?php esc_attr_e( 'Copy utm_content value to clipboard', 'cf-qr-redirect' ); ?>"><?php esc_html_e( 'Copy', 'cf-qr-redirect' ); ?></button>
							<?php if ( '' === (string) CFQR_Settings::get( 'ga4_property_id' ) ) : ?>
								<br><span class="description"><?php esc_html_e( 'Tip: set your GA4 numeric property ID in Settings → QR Redirects to make this link land in the right property automatically.', 'cf-qr-redirect' ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div><!-- /.cfqr-subsection (Performance) -->

		</div><!-- /.cfqr-metabox -->
		<?php
	}

	/**
	 * Persist meta box fields. Validates destination, sanitizes, and saves.
	 */
	public static function save_meta( $post_id, $post ) {
		// Bail on autosave / cron / revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( CFQR_POST_TYPE !== $post->post_type ) {
			return;
		}
		// Capability + nonce.
		if ( ! current_user_can( CFQR_CAP_EDIT ) ) {
			return;
		}

		// The Quick Edit path uses inline_save with its own nonce. Verify it explicitly
		// (defense in depth — core also checks this for inline-save) before reading our fields.
		$is_quick_edit = defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['action'] ) && 'inline-save' === $_POST['action'];

		if ( $is_quick_edit ) {
			$inline_nonce = isset( $_POST['_inline_edit'] ) ? sanitize_key( wp_unslash( $_POST['_inline_edit'] ) ) : '';
			if ( '' === $inline_nonce || ! wp_verify_nonce( $inline_nonce, 'inlineeditnonce' ) ) {
				return;
			}
			self::save_quick_edit( $post_id );
			return;
		}

		if ( ! isset( $_POST[ self::META_NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::META_NONCE_FIELD ] ) ), self::META_NONCE_ACTION ) ) {
			return;
		}

		// Capture old destination before the update for the audit log.
		$old_destination = (string) get_post_meta( $post_id, CFQR_CPT::META_DESTINATION, true );

		// Destination URL: sanitize and validate via the shared CFQR_URL helper.
		// Surfaces a notice if rejected rather than silently saving an empty value.
		// sanitize_text_field strips control characters before esc_url_raw runs;
		// nothing legitimate in a URL is removed by this chain.
		$raw_destination = isset( $_POST['cfqr_destination'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['cfqr_destination'] ) ) )
			: '';
		$destination     = '' !== $raw_destination ? esc_url_raw( $raw_destination ) : '';
		if ( '' !== $destination ) {
			if ( ! CFQR_URL::is_safe_destination( $destination ) ) {
				self::queue_notice(
					sprintf(
						/* translators: %s = the destination URL the user submitted */
						__( 'The destination URL %s was rejected. Destinations must start with http:// or https:// and include a hostname. The QR code is now inactive (returns 410 Gone) until you provide a valid destination.', 'cf-qr-redirect' ),
						'<code>' . esc_html( $raw_destination ) . '</code>'
					),
					'error'
				);
				$destination = '';
			}
		} elseif ( '' !== $raw_destination ) {
			// Non-empty raw input was wiped by esc_url_raw — also a rejection.
			self::queue_notice(
				__( 'The destination URL was rejected because it contained no valid characters. The QR code is now inactive until a destination is set.', 'cf-qr-redirect' ),
				'error'
			);
		}
		update_post_meta( $post_id, CFQR_CPT::META_DESTINATION, $destination );
		self::maybe_log_destination_change( $post_id, $old_destination, $destination );

		// Analytics mode.
		$mode = isset( $_POST['cfqr_analytics_mode'] ) ? sanitize_key( wp_unslash( $_POST['cfqr_analytics_mode'] ) ) : 'utm';
		if ( ! in_array( $mode, array( 'utm', 'intermediate' ), true ) ) {
			$mode = 'utm';
		}
		update_post_meta( $post_id, CFQR_CPT::META_ANALYTICS_MODE, $mode );

		// Campaign: slugify on save so the value the editor sees in the list table
		// matches the utm_campaign GA4 will record. "Spring Mailer 2026!" -> "spring-mailer-2026".
		$campaign_raw = isset( $_POST['cfqr_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['cfqr_campaign'] ) ) : '';
		$campaign     = '' !== $campaign_raw ? sanitize_title( $campaign_raw ) : '';
		update_post_meta( $post_id, CFQR_CPT::META_CAMPAIGN, $campaign );

		// UTM source/medium.
		$source = isset( $_POST['cfqr_utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['cfqr_utm_source'] ) ) : '';
		$medium = isset( $_POST['cfqr_utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['cfqr_utm_medium'] ) ) : '';
		update_post_meta( $post_id, CFQR_CPT::META_UTM_SOURCE, $source );
		update_post_meta( $post_id, CFQR_CPT::META_UTM_MEDIUM, $medium );
	}

	/**
	 * Quick Edit save: only destination + campaign supported.
	 *
	 * Uses the same {@see CFQR_URL::is_safe_destination()} predicate the meta-box
	 * save and router use, so a destination that's accepted here is the same one
	 * that will resolve correctly at scan time. On rejection we queue an admin
	 * notice so editors aren't surprised when their saved code returns 410 Gone.
	 */
	private static function save_quick_edit( $post_id ) {
		// Nonce verification: the caller (save_meta) verifies _inline_edit /
		// inlineeditnonce before dispatching here, so any $_POST read in this
		// method is already authenticated. PHPCS can't see across functions.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['cfqr_destination'] ) ) {
			$old_destination = (string) get_post_meta( $post_id, CFQR_CPT::META_DESTINATION, true );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_destination = trim( sanitize_text_field( wp_unslash( $_POST['cfqr_destination'] ) ) );
			$destination     = '' !== $raw_destination ? esc_url_raw( $raw_destination ) : '';
			if ( '' !== $destination && ! CFQR_URL::is_safe_destination( $destination ) ) {
				self::queue_notice(
					sprintf(
						/* translators: %s = the destination URL the user submitted via Quick Edit */
						__( 'The destination URL %s was rejected. Destinations must start with http:// or https:// and include a hostname. The QR code is now inactive (returns 410 Gone) until you provide a valid destination.', 'cf-qr-redirect' ),
						'<code>' . esc_html( $raw_destination ) . '</code>'
					),
					'error'
				);
				$destination = '';
			} elseif ( '' === $destination && '' !== $raw_destination ) {
				self::queue_notice(
					__( 'The destination URL was rejected because it contained no valid characters. The QR code is now inactive until a destination is set.', 'cf-qr-redirect' ),
					'error'
				);
			}
			update_post_meta( $post_id, CFQR_CPT::META_DESTINATION, $destination );
			self::maybe_log_destination_change( $post_id, $old_destination, $destination );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['cfqr_campaign'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$campaign_raw = sanitize_text_field( wp_unslash( $_POST['cfqr_campaign'] ) );
			$campaign     = '' !== $campaign_raw ? sanitize_title( $campaign_raw ) : '';
			update_post_meta( $post_id, CFQR_CPT::META_CAMPAIGN, $campaign );
		}
	}

	/**
	 * Append an entry to the destination change log when the destination URL
	 * actually changes. Stores the last 20 entries in post meta as a PHP array
	 * (serialised by WP). The log is append-only from this method's perspective;
	 * entries are never edited or deleted here.
	 *
	 * This is the primary audit trail for "who redirected this printed QR to
	 * where, and when." It is not a substitute for server access logs, but it
	 * is immediately visible to any admin without log file access.
	 */
	private static function maybe_log_destination_change( $post_id, $old_destination, $new_destination ) {
		if ( $old_destination === $new_destination ) {
			return;
		}
		// Don't log the very first save (no old value) unless a destination
		// was set for the first time — that's still a meaningful event.
		$entry = array(
			'from'    => $old_destination,
			'to'      => $new_destination,
			'user_id' => get_current_user_id(),
			'ip'      => isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '',
			'ts'      => gmdate( 'c' ),
		);
		$log   = get_post_meta( $post_id, CFQR_CPT::META_DESTINATION_LOG, true );
		$log   = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 20 );
		update_post_meta( $post_id, CFQR_CPT::META_DESTINATION_LOG, $log );
	}

	/**
	 * Queue a notice for the current user. Persisted via a transient so it survives
	 * the post-save redirect and appears on the next admin page load.
	 */
	private static function queue_notice( $message, $type = 'error' ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$key      = 'cfqr_notices_' . $user_id;
		$existing = get_transient( $key );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$existing[] = array(
			'message' => (string) $message,
			'type'    => in_array( $type, array( 'error', 'warning', 'success', 'info' ), true ) ? $type : 'info',
		);
		set_transient( $key, $existing, 60 );
	}

	/**
	 * Show a friendly error if the bulk ZIP action failed up-front (no rows selected
	 * or none were eligible for export).
	 */
	public static function render_bulk_zip_errors() {
		// Read-only display flag set by handle_bulk_zip()'s redirect — no state
		// changes here, so the bulk-action nonce upstream is sufficient.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['cfqr_zip_error'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err = sanitize_key( wp_unslash( $_GET['cfqr_zip_error'] ) );
		$msg = '';
		switch ( $err ) {
			case 'empty':
				$msg = __( 'Select one or more QR codes before running the ZIP export.', 'cf-qr-redirect' );
				break;
			case 'none-eligible':
				$msg = __( 'None of the selected codes have a slug yet — save them first, then re-run the export.', 'cf-qr-redirect' );
				break;
		}
		if ( '' !== $msg ) {
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
		}
	}

	/**
	 * Render any queued notices for the current user, then drop them.
	 */
	public static function render_deferred_notices() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$key     = 'cfqr_notices_' . $user_id;
		$notices = get_transient( $key );
		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			$type    = isset( $notice['type'] ) ? $notice['type'] : 'info';
			$message = isset( $notice['message'] ) ? $notice['message'] : '';
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				wp_kses(
					$message,
					array(
						'code'   => array(),
						'strong' => array(),
						'em'     => array(),
					)
				)
			);
		}
	}

	/**
	 * Quick Edit fields HTML (rendered for each row by WP).
	 */
	public static function quick_edit_box( $column_name, $post_type ) {
		if ( CFQR_POST_TYPE !== $post_type ) {
			return;
		}
		// Render once, on the destination column.
		if ( 'cfqr_dest' !== $column_name ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'Destination URL', 'cf-qr-redirect' ); ?></span>
					<span class="input-text-wrap"><input type="url" name="cfqr_destination" class="cfqr_destination" value=""></span>
				</label>
				<label>
					<span class="title"><?php esc_html_e( 'Campaign', 'cf-qr-redirect' ); ?></span>
					<span class="input-text-wrap"><input type="text" name="cfqr_campaign" class="cfqr_campaign" value=""></span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * JS that pre-fills Quick Edit fields from the row's data attributes.
	 */
	public static function quick_edit_inline_script() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== CFQR_POST_TYPE ) {
			return;
		}
		?>
		<script>
		(function($){
			if (typeof inlineEditPost === 'undefined') return;
			var origEdit = inlineEditPost.edit;
			inlineEditPost.edit = function(id){
				origEdit.apply(this, arguments);
				var post_id = (typeof id === 'object') ? this.getId(id) : id;
				if (!post_id) return;
				var $row = $('#post-' + post_id);
				// Read raw values from hidden data spans (more reliable than text/href).
				var destLink = $row.find('.cfqr-dest-inline').data('dest') || '';
				var campaign = $row.find('.cfqr-camp-inline').data('campaign') || '';
				var $editRow = $('#edit-' + post_id);
				$editRow.find('input.cfqr_destination').val(destLink);
				$editRow.find('input.cfqr_campaign').val(campaign);
			};
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * Enqueue admin assets only on QR Code edit/list screens and our settings page.
	 */
	public static function enqueue_assets( $hook ) {
		$screen             = get_current_screen();
		$is_cpt_screen      = $screen && $screen->post_type === CFQR_POST_TYPE;
		$is_settings_screen = ( 'cfqr_code_page_cfqr-settings' === $hook );
		$is_export_screen   = $screen && false !== strpos( (string) $screen->id, 'cfqr-zip-export' );

		if ( ! $is_cpt_screen && ! $is_settings_screen && ! $is_export_screen ) {
			return;
		}

		wp_enqueue_style(
			'cfqr-admin',
			CFQR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			CFQR_VERSION
		);

		if ( $is_cpt_screen && $screen->base === 'post' ) {
			// Bundled qrcode.js gets its own pinned version string so a plugin
			// upgrade doesn't bust browser caches for an unchanged library.
			wp_enqueue_script(
				'cfqr-qrcode',
				CFQR_PLUGIN_URL . 'assets/js/qrcode.min.js',
				array(),
				CFQR_QRCODE_LIB_VERSION,
				true
			);
			wp_enqueue_script(
				'cfqr-admin',
				CFQR_PLUGIN_URL . 'assets/js/admin.js',
				array( 'cfqr-qrcode', 'jquery' ),
				CFQR_VERSION,
				true
			);
		}

		if ( $is_export_screen ) {
			wp_enqueue_script( 'cfqr-qrcode', CFQR_PLUGIN_URL . 'assets/js/qrcode.min.js', array(), CFQR_QRCODE_LIB_VERSION, true );
			wp_enqueue_script( 'cfqr-jszip', CFQR_PLUGIN_URL . 'assets/js/jszip.min.js', array(), '3.10.1', true );
			wp_enqueue_script( 'cfqr-export', CFQR_PLUGIN_URL . 'assets/js/export.js', array( 'cfqr-qrcode', 'cfqr-jszip' ), CFQR_VERSION, true );
		}
	}

	/**
	 * Register the Settings → QR Redirects submenu.
	 */
	public static function register_settings_page() {
		add_submenu_page(
			'edit.php?post_type=' . CFQR_POST_TYPE,
			__( 'QR Redirect Settings', 'cf-qr-redirect' ),
			__( 'Settings', 'cf-qr-redirect' ),
			CFQR_CAP_SETTINGS,
			'cfqr-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'cfqr_settings_group',
			CFQR_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'CFQR_Settings', 'sanitize' ),
				'default'           => CFQR_Settings::defaults(),
			)
		);
		// By default options.php requires manage_options. Override so the settings
		// form submits correctly for users who hold CFQR_CAP_SETTINGS but not the
		// broader manage_options capability. Without this filter, delegated users
		// could view the settings page but not save it, prompting site owners to
		// mistakenly grant manage_options instead.
		add_filter(
			'option_page_capability_cfqr_settings_group',
			function () {
				return CFQR_CAP_SETTINGS;
			}
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( CFQR_CAP_SETTINGS ) ) {
			return;
		}
		$settings     = CFQR_Settings::all();
		$resolved_ga4 = CFQR_Settings::resolve_ga4_id();
		$logo_url     = CFQR_PLUGIN_URL . 'assets/cf-logo.svg';
		?>
		<div class="wrap">

			<h1 class="cfqr-h1">
				<img class="cfqr-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="CF">
				<span class="cfqr-h1-text"><?php esc_html_e( 'QR Redirect Manager', 'cf-qr-redirect' ); ?></span>
			</h1>
			<hr class="wp-header-end">

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'cfqr_settings_group' ); ?>

				<!-- GA4 Integration ---------------------------------------------------- -->
				<div class="cfqr-section">
					<h2><?php esc_html_e( 'GA4 Integration', 'cf-qr-redirect' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Configure how QR scans appear in your Google Analytics property. Both fields are optional.', 'cf-qr-redirect' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="cfqr_ga4"><?php esc_html_e( 'Measurement ID', 'cf-qr-redirect' ); ?></label></th>
							<td>
								<input type="text" id="cfqr_ga4" name="<?php echo esc_attr( CFQR_OPTION_KEY ); ?>[ga4_measurement_id]" value="<?php echo esc_attr( $settings['ga4_measurement_id'] ); ?>" placeholder="G-XXXXXXX" class="regular-text">
								<p class="description">
									<?php
									if ( '' === $settings['ga4_measurement_id'] && '' !== $resolved_ga4 ) {
										/* translators: %s = resolved GA4 ID */
										printf( esc_html__( 'Currently resolved from another source: %s', 'cf-qr-redirect' ), '<code>' . esc_html( $resolved_ga4 ) . '</code>' );
									} else {
										esc_html_e( 'Required for Intermediate-page mode. If empty, the plugin checks for the CFQR_GA4_ID PHP constant or the Site Kit by Google plugin.', 'cf-qr-redirect' );
									}
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cfqr_ga4_prop"><?php esc_html_e( 'Property ID (numeric)', 'cf-qr-redirect' ); ?></label></th>
							<td>
								<input type="text" id="cfqr_ga4_prop" name="<?php echo esc_attr( CFQR_OPTION_KEY ); ?>[ga4_property_id]" value="<?php echo esc_attr( $settings['ga4_property_id'] ); ?>" placeholder="123456789" class="regular-text" inputmode="numeric" pattern="[0-9]*">
								<p class="description"><?php esc_html_e( 'Optional. Found in GA4 → Admin → Property Settings → Property ID. When set, the "Open GA4" button on each code\'s edit screen lands directly in this property.', 'cf-qr-redirect' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<!-- Defaults ----------------------------------------------------------- -->
				<div class="cfqr-section">
					<h2><?php esc_html_e( 'Defaults for new codes', 'cf-qr-redirect' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Applied to every new QR code unless overridden on the edit screen.', 'cf-qr-redirect' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="cfqr_default_mode"><?php esc_html_e( 'Analytics mode', 'cf-qr-redirect' ); ?></label></th>
							<td>
								<select id="cfqr_default_mode" name="<?php echo esc_attr( CFQR_OPTION_KEY ); ?>[default_analytics_mode]">
									<option value="utm" <?php selected( 'utm', $settings['default_analytics_mode'] ); ?>><?php esc_html_e( 'UTM Injection (fast 302 redirect, recommended for internal destinations)', 'cf-qr-redirect' ); ?></option>
									<option value="intermediate" <?php selected( 'intermediate', $settings['default_analytics_mode'] ); ?>><?php esc_html_e( 'Intermediate Page (fires GA4 event, recommended for external destinations)', 'cf-qr-redirect' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cfqr_default_source"><?php esc_html_e( 'UTM source', 'cf-qr-redirect' ); ?></label></th>
							<td><input type="text" id="cfqr_default_source" name="<?php echo esc_attr( CFQR_OPTION_KEY ); ?>[default_utm_source]" value="<?php echo esc_attr( $settings['default_utm_source'] ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th scope="row"><label for="cfqr_default_medium"><?php esc_html_e( 'UTM medium', 'cf-qr-redirect' ); ?></label></th>
							<td><input type="text" id="cfqr_default_medium" name="<?php echo esc_attr( CFQR_OPTION_KEY ); ?>[default_utm_medium]" value="<?php echo esc_attr( $settings['default_utm_medium'] ); ?>" class="regular-text"></td>
						</tr>
					</table>
				</div>

				<!-- Behavior ----------------------------------------------------------- -->
				<div class="cfqr-section">
					<h2><?php esc_html_e( 'Routing behavior', 'cf-qr-redirect' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="cfqr_status"><?php esc_html_e( 'Redirect status', 'cf-qr-redirect' ); ?></label></th>
							<td>
								<select id="cfqr_status" name="<?php echo esc_attr( CFQR_OPTION_KEY ); ?>[redirect_status]">
									<option value="302" <?php selected( 302, (int) $settings['redirect_status'] ); ?>><?php esc_html_e( '302 Found — recommended; destination changes take effect immediately', 'cf-qr-redirect' ); ?></option>
									<option value="301" <?php selected( 301, (int) $settings['redirect_status'] ); ?>><?php esc_html_e( '301 Moved Permanently — advanced; cached by scanners and cannot be recalled without reprinting', 'cf-qr-redirect' ); ?></option>
								</select>
								<p id="cfqr-301-warning" class="cfqr-warn-hard" <?php echo 301 === (int) $settings['redirect_status'] ? '' : 'hidden'; ?>>
									<span class="dashicons dashicons-warning" aria-hidden="true"></span>
									<strong><?php esc_html_e( 'Heads up:', 'cf-qr-redirect' ); ?></strong>
									<?php esc_html_e( '301 responses are cached aggressively by browsers, scanner apps, and CDNs. Once a scanner has cached a 301, changing the destination URL on this code will not redirect existing scanners — they will continue to bypass the QR redirect and go directly to the original destination, possibly for years. Use 302 unless the destination is genuinely permanent and you accept that you may need to reprint the QR code to ever change it.', 'cf-qr-redirect' ); ?>
								</p>
								<script>
								(function(){
									var sel  = document.getElementById('cfqr_status');
									var warn = document.getElementById('cfqr-301-warning');
									if (!sel || !warn) return;
									sel.addEventListener('change', function(){
										if (sel.value === '301') warn.removeAttribute('hidden');
										else warn.setAttribute('hidden','');
									});
								})();
								</script>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="cfqr_length"><?php esc_html_e( 'Short code length', 'cf-qr-redirect' ); ?></label></th>
							<td>
								<input type="number" id="cfqr_length" name="<?php echo esc_attr( CFQR_OPTION_KEY ); ?>[short_code_length]" value="<?php echo esc_attr( $settings['short_code_length'] ); ?>" min="4" max="12" class="small-text">
								<span class="description"><?php esc_html_e( 'Characters in auto-generated slugs. Range 4–12, default 6.', 'cf-qr-redirect' ); ?></span>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>

			<!-- Footer help -------------------------------------------------------- -->
			<div class="cfqr-section cfqr-section-meta">
				<p class="cfqr-meta-line">
					<?php
					/* translators: %s = capability name */
					printf( esc_html__( 'Access is gated by the %s capability — every administrator has this by default.', 'cf-qr-redirect' ), '<code>' . esc_html( CFQR_CAP ) . '</code>' );
					?>
				</p>
				<p class="cfqr-meta-line">
					<?php
					printf(
						wp_kses(
							/* translators: 1: plugin version string, 2: WordPress version string. */
							__( 'CF QR Redirect %1$s &nbsp;·&nbsp; WordPress %2$s &nbsp;·&nbsp; <a href="mailto:bugs@caifrazier.com">Report a bug</a>', 'cf-qr-redirect' ),
							[ 'a' => [ 'href' => [] ] ]
						),
						esc_html( CFQR_VERSION ),
						esc_html( get_bloginfo( 'version' ) )
					);
					?>
				</p>
			</div>

		</div>
		<?php
	}
}
