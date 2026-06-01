<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Admin UI for the standard redirect manager: list columns, filters, meta box,
 * save handler, asset enqueue.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Redirect_Admin {

	const META_NONCE_FIELD  = 'cfqr_redirect_meta_nonce';
	const META_NONCE_ACTION = 'cfqr_redirect_save_meta';

	public static function init() {
		add_filter( 'manage_' . CFQR_REDIRECT_POST_TYPE . '_posts_columns', array( __CLASS__, 'list_columns' ) );
		add_action( 'manage_' . CFQR_REDIRECT_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_list_column' ), 10, 2 );
		add_filter( 'manage_edit-' . CFQR_REDIRECT_POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_list_sorting' ) );

		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filters' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ), 1 );
		add_action( 'save_post_' . CFQR_REDIRECT_POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_deferred_notices' ) );
	}

	/**
	 * Override the columns shown on the Redirects list table.
	 */
	public static function list_columns( $columns ) {
		return array(
			'cb'             => $columns['cb'] ?? '',
			'title'          => __( 'Label', 'cf-qr-redirect' ),
			'cfqr_rd_source' => __( 'Source', 'cf-qr-redirect' ),
			'cfqr_rd_dest'   => __( 'Destination', 'cf-qr-redirect' ),
			'cfqr_rd_mode'   => __( 'Match', 'cf-qr-redirect' ),
			'cfqr_rd_status' => __( 'Status', 'cf-qr-redirect' ),
			'cfqr_rd_active' => __( 'Active', 'cf-qr-redirect' ),
			'cfqr_rd_groups' => __( 'Groups', 'cf-qr-redirect' ),
			'cfqr_rd_hits'   => __( 'Hits', 'cf-qr-redirect' ),
			'cfqr_rd_last'   => __( 'Last Hit', 'cf-qr-redirect' ),
			'date'           => __( 'Date', 'cf-qr-redirect' ),
		);
	}

	public static function render_list_column( $column, $post_id ) {
		switch ( $column ) {
			case 'cfqr_rd_source':
				$src = (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_SOURCE_PATH, true );
				echo '<code>' . esc_html( '' === $src ? '(none)' : $src ) . '</code>';
				break;

			case 'cfqr_rd_dest':
				$dest = (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_DESTINATION, true );
				if ( '' === $dest ) {
					echo '<em>' . esc_html__( '(none)', 'cf-qr-redirect' ) . '</em>';
					break;
				}
				$truncated = strlen( $dest ) > 60 ? substr( $dest, 0, 57 ) . '...' : $dest;
				printf(
					'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( $dest ),
					esc_html( $truncated )
				);
				break;

			case 'cfqr_rd_mode':
				$mode = (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_MATCH_MODE, true );
				echo esc_html( self::match_mode_label( $mode ) );
				break;

			case 'cfqr_rd_status':
				$status = (int) get_post_meta( $post_id, CFQR_Redirect_CPT::META_STATUS_CODE, true );
				echo (int) ( in_array( $status, CFQR_Redirect_CPT::ALLOWED_STATUSES, true ) ? $status : 302 );
				break;

			case 'cfqr_rd_active':
				$active = (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_ACTIVE, true );
				$is_on  = ( '' === $active || '1' === $active );
				echo $is_on
					? '<span class="cfqr-pill cfqr-pill-on">' . esc_html__( 'on', 'cf-qr-redirect' ) . '</span>'
					: '<span class="cfqr-pill cfqr-pill-off">' . esc_html__( 'off', 'cf-qr-redirect' ) . '</span>';
				// Surface schedule windows so the operator can see at a glance
				// why an "on" redirect isn't firing yet (or stopped firing).
				$from  = (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_ACTIVE_FROM, true );
				$until = (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_ACTIVE_UNTIL, true );
				if ( '' !== $from || '' !== $until ) {
					$in_window = CFQR_Redirect_CPT::is_within_schedule( $from, $until );
					$pill      = $in_window ? 'cfqr-pill-on' : 'cfqr-pill-warn';
					$label     = $in_window ? __( 'scheduled', 'cf-qr-redirect' ) : __( 'out of window', 'cf-qr-redirect' );
					$tooltip   = trim( ( '' !== $from ? __( 'from', 'cf-qr-redirect' ) . ' ' . $from : '' ) . ' ' . ( '' !== $until ? __( 'until', 'cf-qr-redirect' ) . ' ' . $until : '' ) );
					echo ' <span class="cfqr-pill ' . esc_attr( $pill ) . '" title="' . esc_attr( $tooltip ) . '">' . esc_html( $label ) . '</span>';
				}
				break;

			case 'cfqr_rd_groups':
				$terms = get_the_terms( $post_id, CFQR_REDIRECT_TAXONOMY );
				if ( empty( $terms ) || is_wp_error( $terms ) ) {
					echo '<em>' . esc_html__( '—', 'cf-qr-redirect' ) . '</em>';
					break;
				}
				$labels = array();
				foreach ( $terms as $term ) {
					$labels[] = sprintf(
						'<a href="%s">%s</a>',
						esc_url(
							add_query_arg(
								array(
									'post_type'              => CFQR_REDIRECT_POST_TYPE,
									CFQR_REDIRECT_TAXONOMY   => $term->slug,
								),
								admin_url( 'edit.php' )
							)
						),
						esc_html( $term->name )
					);
				}
				echo wp_kses( implode( ', ', $labels ), array( 'a' => array( 'href' => array() ) ) );
				break;

			case 'cfqr_rd_hits':
				echo (int) get_post_meta( $post_id, CFQR_Redirect_CPT::META_HIT_COUNT, true );
				break;

			case 'cfqr_rd_last':
				$last = (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_LAST_HIT_AT, true );
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
		}
	}

	public static function sortable_columns( $columns ) {
		$columns['cfqr_rd_hits'] = 'cfqr_rd_hits';
		$columns['cfqr_rd_last'] = 'cfqr_rd_last';
		return $columns;
	}

	public static function apply_list_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( CFQR_REDIRECT_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( 'cfqr_rd_hits' === $orderby ) {
			$query->set( 'meta_key', CFQR_Redirect_CPT::META_HIT_COUNT );
			$query->set( 'orderby', 'meta_value_num' );
		} elseif ( 'cfqr_rd_last' === $orderby ) {
			$query->set( 'meta_key', CFQR_Redirect_CPT::META_LAST_HIT_AT );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	public static function render_filters( $post_type ) {
		if ( CFQR_REDIRECT_POST_TYPE !== $post_type ) {
			return;
		}
		// List-table filter — same nonceless convention as core's search/paging/filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$mode = isset( $_GET['cfqr_rd_mode'] ) ? sanitize_key( wp_unslash( $_GET['cfqr_rd_mode'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['cfqr_rd_status'] ) ? (int) $_GET['cfqr_rd_status'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$group = isset( $_GET[ CFQR_REDIRECT_TAXONOMY ] ) ? sanitize_text_field( wp_unslash( $_GET[ CFQR_REDIRECT_TAXONOMY ] ) ) : '';

		// Group dropdown — populated from taxonomy terms. Empty list → don't render.
		// Native WP filtering on taxonomies works via the ?taxonomy=slug query var,
		// so we don't need a custom pre_get_posts handler for it.
		$groups = get_terms(
			array(
				'taxonomy'   => CFQR_REDIRECT_TAXONOMY,
				'hide_empty' => true,
			)
		);
		?>
		<select name="cfqr_rd_mode">
			<option value=""><?php esc_html_e( 'Any match mode', 'cf-qr-redirect' ); ?></option>
			<?php foreach ( self::all_modes() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $mode, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="cfqr_rd_status">
			<option value=""><?php esc_html_e( 'Any status', 'cf-qr-redirect' ); ?></option>
			<?php foreach ( CFQR_Redirect_CPT::ALLOWED_STATUSES as $code ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $status, $code ); ?>><?php echo esc_html( $code ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php if ( ! empty( $groups ) && ! is_wp_error( $groups ) ) : ?>
			<select name="<?php echo esc_attr( CFQR_REDIRECT_TAXONOMY ); ?>">
				<option value=""><?php esc_html_e( 'Any group', 'cf-qr-redirect' ); ?></option>
				<?php foreach ( $groups as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $group, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php endif;
	}

	public static function apply_filters( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( CFQR_REDIRECT_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['cfqr_rd_mode'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$mode = sanitize_key( wp_unslash( $_GET['cfqr_rd_mode'] ) );
			if ( in_array( $mode, array_keys( self::all_modes() ), true ) ) {
				$meta_query[] = array(
					'key'   => CFQR_Redirect_CPT::META_MATCH_MODE,
					'value' => $mode,
				);
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['cfqr_rd_status'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status = (int) $_GET['cfqr_rd_status'];
			if ( in_array( $status, CFQR_Redirect_CPT::ALLOWED_STATUSES, true ) ) {
				$meta_query[] = array(
					'key'   => CFQR_Redirect_CPT::META_STATUS_CODE,
					'value' => $status,
				);
			}
		}
		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}
	}

	public static function register_meta_box( $post_type ) {
		if ( CFQR_REDIRECT_POST_TYPE !== $post_type ) {
			return;
		}
		// Defense-in-depth: don't even register the meta box for users without
		// the edit cap. Capability is already enforced by the CPT registration,
		// but skipping registration removes the empty-shell render too.
		if ( ! current_user_can( CFQR_REDIRECT_CAP_EDIT ) ) {
			return;
		}
		add_meta_box(
			'cfqr_redirect_main',
			__( 'Redirect', 'cf-qr-redirect' ),
			array( __CLASS__, 'render_meta_box' ),
			CFQR_REDIRECT_POST_TYPE,
			'normal',
			'high'
		);
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( self::META_NONCE_ACTION, self::META_NONCE_FIELD );

		$source   = (string) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_SOURCE_PATH, true );
		$dest     = (string) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_DESTINATION, true );
		$mode     = (string) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_MATCH_MODE, true );
		$status   = (int) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_STATUS_CODE, true );
		$active   = (string) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_ACTIVE, true );
		$hits     = (int) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_HIT_COUNT, true );
		$created  = (string) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_CREATED_AT, true );
		$last_hit = (string) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_LAST_HIT_AT, true );
		$log      = get_post_meta( $post->ID, CFQR_Redirect_CPT::META_DESTINATION_LOG, true );
		$log      = is_array( $log ) ? $log : array();
		$from_utc = (string) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_ACTIVE_FROM, true );
		$until_utc = (string) get_post_meta( $post->ID, CFQR_Redirect_CPT::META_ACTIVE_UNTIL, true );
		// Convert stored UTC back to site-local format that <input type=datetime-local> wants ("Y-m-d\TH:i").
		$from_local  = self::utc_to_local_datetime_input( $from_utc );
		$until_local = self::utc_to_local_datetime_input( $until_utc );

		if ( '' === $mode ) {
			$mode = CFQR_Redirect_CPT::MATCH_EXACT;
		}
		if ( ! in_array( $status, CFQR_Redirect_CPT::ALLOWED_STATUSES, true ) ) {
			$status = 302;
		}
		// New posts default to active.
		$is_active = ( '' === $active || '1' === $active );
		?>
		<div class="cfqr-metabox">

			<div class="cfqr-subsection">
				<h3 class="cfqr-subhead"><?php esc_html_e( 'Source &amp; destination', 'cf-qr-redirect' ); ?></h3>

				<div class="cfqr-row">
					<label class="cfqr-label" for="cfqr_rd_source"><?php esc_html_e( 'Source path', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<input type="text" name="cfqr_rd_source" id="cfqr_rd_source" value="<?php echo esc_attr( $source ); ?>" placeholder="/old-page/" class="large-text" required>
						<p class="description">
							<?php esc_html_e( 'The path on this site to intercept. Leading slash required. Paste a full URL on this site and the host will be stripped automatically. Cannot start with /r/ (reserved for QR shortlinks) or any wp-* path.', 'cf-qr-redirect' ); ?>
						</p>
					</div>
				</div>

				<div class="cfqr-row">
					<label class="cfqr-label" for="cfqr_rd_dest"><?php esc_html_e( 'Destination URL', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<input type="url" name="cfqr_rd_dest" id="cfqr_rd_dest" value="<?php echo esc_attr( $dest ); ?>" placeholder="https://example.com/new-page/" class="large-text" required>
						<p class="description"><?php esc_html_e( 'Where the source path redirects. Must be http(s). Internal or external.', 'cf-qr-redirect' ); ?></p>
					</div>
				</div>
			</div>

			<div class="cfqr-subsection">
				<h3 class="cfqr-subhead"><?php esc_html_e( 'Behavior', 'cf-qr-redirect' ); ?></h3>

				<div class="cfqr-row">
					<label class="cfqr-label" for="cfqr_rd_mode"><?php esc_html_e( 'Match mode', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<select name="cfqr_rd_mode" id="cfqr_rd_mode">
							<?php foreach ( self::all_modes() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $mode, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<strong><?php esc_html_e( 'Exact', 'cf-qr-redirect' ); ?>:</strong>
							<?php esc_html_e( 'source path must match exactly (case-insensitive, trailing slashes ignored).', 'cf-qr-redirect' ); ?>
							<br>
							<strong><?php esc_html_e( 'Wildcard', 'cf-qr-redirect' ); ?>:</strong>
							<?php
							printf(
								/* translators: 1: example source pattern, 2: example destination */
								esc_html__( 'use %1$s in the source to match any text. Reference matches in the destination with %2$s.', 'cf-qr-redirect' ),
								'<code>*</code>',
								'<code>$1</code>, <code>$2</code>'
							);
							?>
							<?php esc_html_e( 'Example: source', 'cf-qr-redirect' ); ?>
							<code>/blog/*</code>,
							<?php esc_html_e( 'destination', 'cf-qr-redirect' ); ?>
							<code>https://example.com/news/$1</code>.
							<br>
							<strong><?php esc_html_e( 'Regex', 'cf-qr-redirect' ); ?>:</strong>
							<?php esc_html_e( 'raw PCRE pattern (no delimiters). Case-sensitive by default; prepend (?i) for case-insensitive. Captures available as $1, $2, … in destination. Pattern must compile.', 'cf-qr-redirect' ); ?>
							<br>
							<strong><?php esc_html_e( 'Query-aware', 'cf-qr-redirect' ); ?>:</strong>
							<?php esc_html_e( 'source includes ?key=value. Matches when the path equals the source path AND every specified query param matches on the request (extra params are ignored).', 'cf-qr-redirect' ); ?>
						</p>
					</div>
				</div>

				<div class="cfqr-row">
					<label class="cfqr-label" for="cfqr_rd_status"><?php esc_html_e( 'Status code', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<select name="cfqr_rd_status" id="cfqr_rd_status">
							<option value="302" <?php selected( 302, $status ); ?>><?php esc_html_e( '302 Found — temporary; not aggressively cached', 'cf-qr-redirect' ); ?></option>
							<option value="301" <?php selected( 301, $status ); ?>><?php esc_html_e( '301 Moved Permanently — cached aggressively by browsers and CDNs', 'cf-qr-redirect' ); ?></option>
							<option value="307" <?php selected( 307, $status ); ?>><?php esc_html_e( '307 Temporary Redirect — preserves request method', 'cf-qr-redirect' ); ?></option>
							<option value="308" <?php selected( 308, $status ); ?>><?php esc_html_e( '308 Permanent Redirect — preserves request method, cached aggressively', 'cf-qr-redirect' ); ?></option>
						</select>
						<p class="description cfqr-warn-line" id="cfqr-rd-status-warn" <?php echo ( 301 === $status || 308 === $status ) ? '' : 'hidden'; ?>>
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							<?php esc_html_e( '301 and 308 are cached by browsers and CDNs. After a client has cached the response, changing the destination will not affect them. Use 302/307 unless permanent.', 'cf-qr-redirect' ); ?>
						</p>
						<script>
						(function(){
							var sel  = document.getElementById('cfqr_rd_status');
							var warn = document.getElementById('cfqr-rd-status-warn');
							if (!sel || !warn) return;
							sel.addEventListener('change', function(){
								var v = parseInt(sel.value, 10);
								if (v === 301 || v === 308) warn.removeAttribute('hidden');
								else warn.setAttribute('hidden','');
							});
						})();
						</script>
					</div>
				</div>

				<div class="cfqr-row">
					<label class="cfqr-label" for="cfqr_rd_active"><?php esc_html_e( 'Active', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<label>
							<input type="checkbox" name="cfqr_rd_active" id="cfqr_rd_active" value="1" <?php checked( $is_active ); ?>>
							<?php esc_html_e( 'Redirect is active. Uncheck to temporarily disable without deleting.', 'cf-qr-redirect' ); ?>
						</label>
					</div>
				</div>

				<div class="cfqr-row">
					<label class="cfqr-label"><?php esc_html_e( 'Schedule', 'cf-qr-redirect' ); ?></label>
					<div class="cfqr-control">
						<label class="cfqr-schedule-label">
							<?php esc_html_e( 'Active from', 'cf-qr-redirect' ); ?>
							<input type="datetime-local" name="cfqr_rd_from" value="<?php echo esc_attr( $from_local ); ?>">
						</label>
						<label class="cfqr-schedule-label">
							<?php esc_html_e( 'until', 'cf-qr-redirect' ); ?>
							<input type="datetime-local" name="cfqr_rd_until" value="<?php echo esc_attr( $until_local ); ?>">
						</label>
						<p class="description">
							<?php
							printf(
								/* translators: %s = site timezone string, e.g. "America/Los_Angeles" */
								esc_html__( 'Optional window — leave either bound blank for "no limit on that side." Times are interpreted in the site timezone (%s) and stored as UTC.', 'cf-qr-redirect' ),
								'<code>' . esc_html( self::site_timezone_label() ) . '</code>'
							);
							?>
							<?php esc_html_e( 'Outside the window the redirect returns nothing (request falls through to normal WP routing).', 'cf-qr-redirect' ); ?>
						</p>
					</div>
				</div>
			</div>

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
					</div>
				</div>
			</div>

			<?php if ( ! empty( $log ) ) : ?>
				<div class="cfqr-subsection">
					<h3 class="cfqr-subhead"><?php esc_html_e( 'Destination change history', 'cf-qr-redirect' ); ?></h3>
					<div class="cfqr-row">
						<label class="cfqr-label"></label>
						<div class="cfqr-control">
							<ul class="cfqr-audit-log">
								<?php foreach ( $log as $entry ) :
									$user = isset( $entry['user_id'] ) ? get_user_by( 'id', (int) $entry['user_id'] ) : false;
									?>
									<li>
										<code><?php echo esc_html( (string) ( $entry['ts'] ?? '' ) ); ?></code>
										<?php
										if ( $user ) {
											echo ' &middot; ' . esc_html( $user->user_login );
										}
										?>
										<br>
										<small>
											<?php esc_html_e( 'from:', 'cf-qr-redirect' ); ?> <code><?php echo esc_html( (string) ( $entry['from'] ?? '' ) ); ?></code><br>
											<?php esc_html_e( 'to:', 'cf-qr-redirect' ); ?> <code><?php echo esc_html( (string) ( $entry['to'] ?? '' ) ); ?></code>
										</small>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
				</div>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Persist meta-box fields. Validates source path + destination, normalizes
	 * source path for the router's lookup table, appends to audit log on
	 * destination change.
	 */
	public static function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( CFQR_REDIRECT_POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ! current_user_can( CFQR_REDIRECT_CAP_EDIT ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::META_NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::META_NONCE_FIELD ] ) ), self::META_NONCE_ACTION )
		) {
			return;
		}

		// --- Match mode (read first so source/destination validation can branch on it) ---
		$mode_raw = isset( $_POST['cfqr_rd_mode'] ) ? sanitize_key( wp_unslash( $_POST['cfqr_rd_mode'] ) ) : CFQR_Redirect_CPT::MATCH_EXACT;
		$mode     = CFQR_Redirect_CPT::sanitize_match_mode( $mode_raw );

		// --- Source path -----------------------------------------------------
		// CFQR_Redirect_CPT::sanitize_source_path() is the canonical sanitizer
		// for this field. sanitize_text_field() would collapse whitespace and
		// strip characters that regex-mode sources need (newlines never appear
		// in a path, but the linter doesn't know that; more importantly, the
		// stricter sanitizer would mangle wildcard/regex metachars). The
		// custom sanitizer enforces leading slash, strips control chars, and
		// caps length.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$source_raw = isset( $_POST['cfqr_rd_source'] ) ? wp_unslash( $_POST['cfqr_rd_source'] ) : '';
		$source     = CFQR_Redirect_CPT::sanitize_source_path( $source_raw );

		$source_error = self::validate_source( $source, $post_id, $mode );
		if ( null !== $source_error ) {
			self::queue_notice( $source_error, 'error' );
			$source = '';
		}

		update_post_meta( $post_id, CFQR_Redirect_CPT::META_SOURCE_PATH, $source );
		// SOURCE_NORMALIZED powers the exact-match cache — only meaningful for
		// exact mode. For pattern modes, leave it blank so the cache builder
		// (which already filters on mode) doesn't accidentally route through
		// a stale normalized value.
		$normalized_for_cache = '';
		if ( '' !== $source && CFQR_Redirect_CPT::MATCH_EXACT === $mode ) {
			$normalized_for_cache = CFQR_Redirect_CPT::normalize_path( $source );
		}
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_SOURCE_NORMALIZED, $normalized_for_cache );

		// --- Destination -----------------------------------------------------
		$old_destination = (string) get_post_meta( $post_id, CFQR_Redirect_CPT::META_DESTINATION, true );
		$raw_destination = isset( $_POST['cfqr_rd_dest'] )
			? trim( sanitize_text_field( wp_unslash( $_POST['cfqr_rd_dest'] ) ) )
			: '';
		$destination     = '' !== $raw_destination ? esc_url_raw( $raw_destination ) : '';
		if ( '' !== $destination && ! CFQR_URL::is_safe_destination( $destination ) ) {
			self::queue_notice(
				sprintf(
					/* translators: %s = the destination URL the user submitted */
					__( 'The destination URL %s was rejected. Destinations must start with http:// or https:// and include a hostname. This redirect is now inactive until a valid destination is set.', 'cf-qr-redirect' ),
					'<code>' . esc_html( $raw_destination ) . '</code>'
				),
				'error'
			);
			$destination = '';
		}

		// Loop guard. Only meaningful for exact mode — pattern modes substitute
		// captures into the destination at request time, so the literal template
		// can't be resolved statically. The router does a post-substitution
		// self-loop check on every request as the safety net.
		//
		// would_create_loop() walks the chain of existing exact-mode redirects
		// starting at this destination and reports true if it routes back to
		// this source — catching both the direct self-loop (A → A) and
		// multi-hop cycles (A → B → A).
		if (
			CFQR_Redirect_CPT::MATCH_EXACT === $mode
			&& '' !== $destination
			&& '' !== $normalized_for_cache
			&& CFQR_Redirect_Router::would_create_loop( $normalized_for_cache, $destination )
		) {
			self::queue_notice(
				__( 'This destination would create a redirect loop back to this source (directly or through other redirects), so the destination was cleared.', 'cf-qr-redirect' ),
				'error'
			);
			$destination = '';
		}

		update_post_meta( $post_id, CFQR_Redirect_CPT::META_DESTINATION, $destination );
		self::maybe_log_destination_change( $post_id, $old_destination, $destination );

		// --- Match mode ($mode read at top of this method) -------------------
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_MATCH_MODE, $mode );

		// --- Status code -----------------------------------------------------
		$status_raw = isset( $_POST['cfqr_rd_status'] ) ? (int) $_POST['cfqr_rd_status'] : 302;
		$status     = CFQR_Redirect_CPT::sanitize_status_code( $status_raw );
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_STATUS_CODE, $status );

		// --- Active flag -----------------------------------------------------
		$active = isset( $_POST['cfqr_rd_active'] ) ? 1 : 0;
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_ACTIVE, $active );

		// --- Schedule window -------------------------------------------------
		// Stored as ISO 8601 UTC; sanitize_iso8601 converts site-local input
		// to UTC. Empty input is preserved as the "no bound" signal.
		$from_raw  = isset( $_POST['cfqr_rd_from'] ) ? sanitize_text_field( wp_unslash( $_POST['cfqr_rd_from'] ) ) : '';
		$until_raw = isset( $_POST['cfqr_rd_until'] ) ? sanitize_text_field( wp_unslash( $_POST['cfqr_rd_until'] ) ) : '';
		$from_utc  = CFQR_Redirect_CPT::sanitize_iso8601( $from_raw );
		$until_utc = CFQR_Redirect_CPT::sanitize_iso8601( $until_raw );

		// If both bounds are set and until <= from, the window is empty.
		// Reject the until value rather than silently saving an unreachable window.
		if ( '' !== $from_utc && '' !== $until_utc && strtotime( $until_utc ) <= strtotime( $from_utc ) ) {
			self::queue_notice(
				__( 'Schedule "until" must be after "from". The "until" value was cleared.', 'cf-qr-redirect' ),
				'warning'
			);
			$until_utc = '';
		}

		update_post_meta( $post_id, CFQR_Redirect_CPT::META_ACTIVE_FROM, $from_utc );
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_ACTIVE_UNTIL, $until_utc );
	}

	/**
	 * Convert a stored ISO 8601 UTC timestamp to the local-time string format
	 * that an <input type="datetime-local"> control wants ("Y-m-d\TH:i"). The
	 * stored timestamp is in UTC; the input expects site-local time.
	 */
	private static function utc_to_local_datetime_input( $utc ) {
		$utc = trim( (string) $utc );
		if ( '' === $utc ) {
			return '';
		}
		try {
			$dt = new DateTimeImmutable( $utc );
		} catch ( Exception $e ) {
			return '';
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		return $dt->setTimezone( $tz )->format( 'Y-m-d\TH:i' );
	}

	/**
	 * Human-readable site timezone label for the schedule help text.
	 */
	private static function site_timezone_label() {
		$tz_string = get_option( 'timezone_string' );
		if ( is_string( $tz_string ) && '' !== $tz_string ) {
			return $tz_string;
		}
		$offset = (float) get_option( 'gmt_offset', 0 );
		$sign   = $offset >= 0 ? '+' : '-';
		$abs    = abs( $offset );
		$hours  = (int) $abs;
		$mins   = (int) ( ( $abs - $hours ) * 60 );
		return sprintf( 'UTC%s%02d:%02d', $sign, $hours, $mins );
	}

	// Regex sources are length-capped at save time so a single pathological
	// pattern can't blow past sensible PCRE limits. Wildcard/query-aware/exact
	// fall under the broader 2048-char cap in CFQR_Redirect_CPT::sanitize_source_path.
	const REGEX_MAX_LEN = 500;

	/**
	 * Validate a source path against the rules for its match mode. Returns
	 * null on success, or a translated error message on failure.
	 *
	 * @param string $source  Sanitized source (output of CFQR_Redirect_CPT::sanitize_source_path).
	 * @param int    $post_id Current post ID (for self-exclusion in the dup check).
	 * @param string $mode    One of CFQR_Redirect_CPT::MATCH_*.
	 */
	private static function validate_source( $source, $post_id, $mode = CFQR_Redirect_CPT::MATCH_EXACT ) {
		if ( '' === $source ) {
			return __( 'Source path is required.', 'cf-qr-redirect' );
		}

		// Regex mode: the source is a raw PCRE pattern, not a path. Skip all
		// path-shaped checks (normalization, reserved-prefix) — those would
		// reject legitimate patterns like `^/old/(.*)$`. The user owns the
		// pattern; we just verify it compiles and isn't absurdly long.
		if ( CFQR_Redirect_CPT::MATCH_REGEX === $mode ) {
			if ( strlen( $source ) > self::REGEX_MAX_LEN ) {
				return sprintf(
					/* translators: %d = max regex pattern length */
					__( 'Regex pattern is too long (max %d characters).', 'cf-qr-redirect' ),
					self::REGEX_MAX_LEN
				);
			}
			if ( null === CFQR_Redirect_Router::compile_user_regex( $source ) ) {
				return __( 'Regex pattern does not compile. Provide a valid PCRE pattern without delimiters (e.g. ^/old/(.*)$).', 'cf-qr-redirect' );
			}
			return null;
		}

		// Mode-specific shape checks before the generic path checks.
		if ( CFQR_Redirect_CPT::MATCH_WILDCARD === $mode && false === strpos( $source, '*' ) ) {
			return __( 'Wildcard mode requires at least one * in the source. Use Exact mode instead, or add a * (e.g. /blog/*).', 'cf-qr-redirect' );
		}
		if ( CFQR_Redirect_CPT::MATCH_QUERY_AWARE === $mode && false === strpos( $source, '?' ) ) {
			return __( 'Query-aware mode requires a query string in the source (e.g. /search?q=foo).', 'cf-qr-redirect' );
		}

		$normalized = CFQR_Redirect_CPT::normalize_path( $source );
		if ( '/' === $normalized ) {
			return __( 'Redirecting the site root would break every page on this site. Source path must be more specific than /.', 'cf-qr-redirect' );
		}

		// Reject paths inside reserved namespaces. For wildcard sources we
		// check the LITERAL prefix segment — `*/foo` is allowed because the
		// first segment is just the wildcard. `/wp-admin/*` is rejected.
		$first = explode( '/', ltrim( $normalized, '/' ), 2 )[0];
		// Strip any wildcards from the literal-segment check so a bare `*` at
		// the front doesn't trip the reserved-list match.
		$first_literal = str_replace( '*', '', $first );
		$reserved      = array( CFQR_REWRITE_SLUG, 'wp-admin', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-json', 'wp-cron.php', 'xmlrpc.php' );
		if ( '' !== $first_literal && in_array( $first_literal, $reserved, true ) ) {
			return sprintf(
				/* translators: %s = the reserved path segment, like 'wp-admin' */
				__( 'Source path cannot start with the reserved segment %s.', 'cf-qr-redirect' ),
				'<code>/' . esc_html( $first_literal ) . '/</code>'
			);
		}

		// Duplicate-source check applies only to exact mode. Pattern modes
		// can legitimately overlap (e.g. two regexes that both match `/foo`)
		// and detecting that statically isn't tractable — the router resolves
		// ties at request time by longest-source-first order.
		if ( CFQR_Redirect_CPT::MATCH_EXACT === $mode ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT pm.post_id FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = %s
					   AND pm.meta_value = %s
					   AND p.post_type = %s
					   AND p.post_status NOT IN ('trash','auto-draft')
					   AND p.ID <> %d
					 LIMIT 1",
					CFQR_Redirect_CPT::META_SOURCE_NORMALIZED,
					$normalized,
					CFQR_REDIRECT_POST_TYPE,
					(int) $post_id
				)
			);
			if ( $existing ) {
				return sprintf(
					/* translators: %s = source path */
					__( 'A redirect for %s already exists. Edit that one instead, or trash it before adding a new redirect for the same source.', 'cf-qr-redirect' ),
					'<code>' . esc_html( $normalized ) . '</code>'
				);
			}
		}

		return null;
	}

	private static function maybe_log_destination_change( $post_id, $old, $new ) {
		if ( $old === $new ) {
			return;
		}
		$entry = array(
			'from'    => (string) $old,
			'to'      => (string) $new,
			'user_id' => get_current_user_id(),
			'ip'      => isset( $_SERVER['REMOTE_ADDR'] )
				? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
				: '',
			'ts'      => gmdate( 'c' ),
		);
		$log = get_post_meta( $post_id, CFQR_Redirect_CPT::META_DESTINATION_LOG, true );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 20 );
		update_post_meta( $post_id, CFQR_Redirect_CPT::META_DESTINATION_LOG, $log );
	}

	/**
	 * Notice queue (separate namespace from CFQR_Admin so the two classes don't
	 * stomp each other or double-render). Notices survive the post-save redirect
	 * via a per-user transient.
	 */
	private static function queue_notice( $message, $type = 'error' ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$key      = 'cfqr_redirect_notices_' . $user_id;
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

	public static function render_deferred_notices() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$key     = 'cfqr_redirect_notices_' . $user_id;
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

	public static function enqueue_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || CFQR_REDIRECT_POST_TYPE !== $screen->post_type ) {
			return;
		}
		// Reuse the QR admin stylesheet — it already styles .cfqr-metabox /
		// .cfqr-subsection / .cfqr-stat-row / .cfqr-pill which this UI uses too.
		wp_enqueue_style(
			'cfqr-admin',
			CFQR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			CFQR_VERSION
		);
	}

	/**
	 * Map of all match-mode keys to translated display labels.
	 *
	 * @return array<string,string>
	 */
	private static function all_modes() {
		return array(
			CFQR_Redirect_CPT::MATCH_EXACT       => __( 'Exact path', 'cf-qr-redirect' ),
			CFQR_Redirect_CPT::MATCH_WILDCARD    => __( 'Wildcard', 'cf-qr-redirect' ),
			CFQR_Redirect_CPT::MATCH_REGEX       => __( 'Regex', 'cf-qr-redirect' ),
			CFQR_Redirect_CPT::MATCH_QUERY_AWARE => __( 'Query-aware', 'cf-qr-redirect' ),
		);
	}

	private static function match_mode_label( $mode ) {
		$modes = self::all_modes();
		return $modes[ $mode ] ?? __( 'Exact path', 'cf-qr-redirect' );
	}
}
