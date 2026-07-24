<?php

namespace CFMediaOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: settings page, asset enqueueing, post-conversion notice, and
 * the Media Library "WebP" status column.
 *
 * Distinct from Ajax — this class only deals with rendering markup and
 * registering scripts. AJAX endpoints live in their own class so the
 * surface area for security review stays small.
 */
final class AdminPage {

	/**
	 * User meta key: set to "1" when the current user permanently dismisses
	 * the In-use / Background-queue explainer card. Stored per-user so a
	 * site-admin can leave it visible for newer collaborators on the same
	 * install.
	 */
	const EXPLAINER_DISMISSED_META = 'cf_media_manager_explainer_dismissed';

	private Converter $converter;

	public function __construct( Converter $converter ) {
		$this->converter = $converter;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_purge_notice' ) );
		add_filter( 'manage_media_columns', array( $this, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_column_styles' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( CF_MEDIA_OPTIMIZER_FILE ),
			array( $this, 'add_settings_action_link' )
		);
	}

	/**
	 * Prepend a "Settings" link to the plugin row on the Plugins admin
	 * screen so admins don't have to hunt under Media → Media Optimizer.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_action_link( $links ): array {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$url      = admin_url( 'upload.php?page=cf-media-optimizer' );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cf-media-optimizer' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * True when the current admin has permanently dismissed the In-use /
	 * Background-queue explainer card. Anonymous / unauthed contexts are
	 * treated as "not dismissed" so the test suite and CLI see the same
	 * default rendering an admin would on first load.
	 */
	public static function explainer_dismissed(): bool {
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'get_user_meta' ) ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) get_user_meta( $user_id, self::EXPLAINER_DISMISSED_META, true );
	}

	/**
	 * True when the sibling CF Media Manager plugin is present. Used to light
	 * up a convenience cross-link — never to require it (independence rule) and
	 * never for promotion. Degrades silently when absent.
	 */
	public static function manager_active(): bool {
		return class_exists( '\\CFMediaManager\\Plugin' );
	}

	public function admin_menu(): void {
		add_media_page(
			__( 'Media Optimizer', 'cf-media-optimizer' ),
			__( 'Media Optimizer', 'cf-media-optimizer' ),
			'manage_options',
			'cf-media-optimizer',
			array( $this, 'render_page' )
		);
	}

	public function admin_scripts( string $hook ): void {
		$languages_path = plugin_dir_path( CF_MEDIA_OPTIMIZER_FILE ) . 'languages';

		// The post-conversion notice can appear on dashboard, media library, and
		// the plugin page. Enqueue the small notice handler everywhere admins
		// might see the notice; the full UI bundle only loads on the plugin page.
		$notice_screens = array( 'index.php', 'upload.php', 'media_page_cf-media-optimizer' );
		if ( in_array( $hook, $notice_screens, true ) && current_user_can( 'manage_options' ) && get_option( Options::PURGE_FLAG, 0 ) ) {
			wp_enqueue_script(
				'cf-media-optimizer-notice',
				plugins_url( 'assets/notice.js', CF_MEDIA_OPTIMIZER_FILE ),
				array( 'wp-i18n' ),
				CF_MEDIA_OPTIMIZER_VERSION,
				true
			);
			wp_set_script_translations( 'cf-media-optimizer-notice', 'cf-media-optimizer', $languages_path );
			wp_localize_script(
				'cf-media-optimizer-notice',
				'cfMediaOptimizerNotice',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( Plugin::NONCE_ACTION ),
				)
			);
		}

		if ( $hook !== 'media_page_cf-media-optimizer' ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			'cf-media-optimizer-admin',
			plugins_url( 'assets/admin.css', CF_MEDIA_OPTIMIZER_FILE ),
			array(),
			CF_MEDIA_OPTIMIZER_VERSION
		);
		wp_enqueue_script(
			'cf-media-optimizer-admin',
			plugins_url( 'assets/admin.js', CF_MEDIA_OPTIMIZER_FILE ),
			array( 'jquery', 'wp-i18n' ),
			CF_MEDIA_OPTIMIZER_VERSION,
			true
		);
		wp_set_script_translations( 'cf-media-optimizer-admin', 'cf-media-optimizer', $languages_path );
		wp_localize_script(
			'cf-media-optimizer-admin',
			'cfMediaOptimizer',
			array(
				'nonce'         => wp_create_nonce( Plugin::NONCE_ACTION ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'batchSize'     => max( 1, min( 25, (int) get_option( Options::BATCH_SIZE, Options::DEFAULT_BATCH ) ) ),
				'avifSupported' => Converter::avif_supported(),
			)
		);
	}

	public function render_page(): void {
		$engine              = extension_loaded( 'imagick' )
			? 'Imagick'
			: ( function_exists( 'imagewebp' ) ? 'GD' : 'NONE' );
		$avif_ready          = Converter::avif_supported();
		$quality             = (int) get_option( Options::QUALITY, Options::DEFAULT_QUALITY );
		$rewrite             = (bool) get_option( Options::REWRITE, true );
		$scope               = get_option( Options::SCOPE, 'all' );
		$filter              = get_option( Options::FILTER_MODE, 'none' );
		$patterns            = get_option( Options::FILTER_PATTERNS, '' );
		$batch_size          = max( 1, min( 25, (int) get_option( Options::BATCH_SIZE, Options::DEFAULT_BATCH ) ) );
		$enable_avif         = (bool) get_option( Options::ENABLE_AVIF, true );
		$rewrite_favicons    = (bool) get_option( Options::REWRITE_FAVICONS, false );
		$alt_fallback        = (bool) get_option( Options::ALT_FALLBACK, true );
		$max_source_mb       = max( 1, min( Options::HARD_MAX_SOURCE_MB, (int) get_option( Options::MAX_SOURCE_MB, Options::DEFAULT_MAX_SOURCE_MB ) ) );
		$delete_on_uninstall = (bool) get_option( Options::DELETE_ON_UNINSTALL, false );
		?>
		<div class="wrap">

		<h1 class="cf-media-optimizer-h1">
			<img class="cf-media-optimizer-logo" src="<?php echo esc_url( plugins_url( 'assets/cf-logo.svg', CF_MEDIA_OPTIMIZER_FILE ) ); ?>" alt="CF">
			<span class="cf-media-optimizer-h1-text"><?php esc_html_e( 'Media Optimizer', 'cf-media-optimizer' ); ?></span>
		</h1>
		<hr class="wp-header-end">

		<?php if ( self::manager_active() ) : ?>
			<p class="description cf-media-optimizer-sibling-link">
				<?php
				printf(
					/* translators: %s: link to the CF Media Manager page. */
					esc_html__( 'Media Library auditing, bulk alt text, and the list view are in %s.', 'cf-media-optimizer' ),
					'<a href="' . esc_url( admin_url( 'upload.php?page=cf-media-manager' ) ) . '">' . esc_html__( 'CF Media Manager', 'cf-media-optimizer' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( $engine === 'NONE' ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'No WebP support found — install the Imagick PHP extension or enable GD with WebP support.', 'cf-media-optimizer' ); ?></p></div>
		<?php endif; ?>

		<nav class="cf-media-optimizer-tabs" role="tablist">
			<button class="cf-media-optimizer-tab is-active" role="tab" data-tab="convert"
					id="cf-media-optimizer-tab-convert" aria-controls="cf-media-optimizer-panel-convert" aria-selected="true">
				<?php esc_html_e( 'Convert', 'cf-media-optimizer' ); ?>
			</button>
			<button class="cf-media-optimizer-tab" role="tab" data-tab="settings"
					id="cf-media-optimizer-tab-settings" aria-controls="cf-media-optimizer-panel-settings" aria-selected="false">
				<?php esc_html_e( 'Settings', 'cf-media-optimizer' ); ?>
			</button>
		</nav>

		<!-- ================================================================ -->
		<!-- Tab: Convert                                                      -->
		<!-- ================================================================ -->
		<div id="cf-media-optimizer-panel-convert" class="cf-media-optimizer-tab-panel is-active" role="tabpanel" aria-labelledby="cf-media-optimizer-tab-convert">

		<!-- Bulk Conversion ------------------------------------------------- -->
		<div class="cf-media-optimizer-section">
			<h2 class="cf-media-optimizer-section-h2">
				<span><?php esc_html_e( 'Bulk Conversion', 'cf-media-optimizer' ); ?></span>
				<button id="cf-media-optimizer-recheck" class="button button-link cf-media-optimizer-recheck" title="<?php esc_attr_e( 'Recheck the media library for newly uploaded images', 'cf-media-optimizer' ); ?>">↻ <?php esc_html_e( 'Recheck', 'cf-media-optimizer' ); ?></button>
			</h2>

			<div id="cf-media-optimizer-status"><?php esc_html_e( 'Loading…', 'cf-media-optimizer' ); ?></div>

			<div class="cf-media-optimizer-bar-track">
				<div id="cf-media-optimizer-bar"></div>
			</div>

			<fieldset class="cf-media-optimizer-scope">
				<legend class="screen-reader-text"><?php esc_html_e( 'Which images to convert', 'cf-media-optimizer' ); ?></legend>
				<label class="cf-media-optimizer-scope-opt">
					<input type="radio" name="cf_media_optimizer_bulk_scope" value="all" checked>
					<span class="cf-media-optimizer-scope-label"><?php esc_html_e( 'All images in the media library', 'cf-media-optimizer' ); ?></span>
				</label>
				<label class="cf-media-optimizer-scope-opt">
					<input type="radio" name="cf_media_optimizer_bulk_scope" value="in_use">
					<span class="cf-media-optimizer-scope-label"><?php esc_html_e( 'In-use only — images referenced on the front end', 'cf-media-optimizer' ); ?></span>
					<span class="cf-media-optimizer-scope-count" data-scope="in_use"><?php esc_html_e( 'scanning…', 'cf-media-optimizer' ); ?></span>
					<button type="button" id="cf-media-optimizer-rescan" class="button-link cf-media-optimizer-rescan" title="<?php esc_attr_e( 'Re-scan the site for image references', 'cf-media-optimizer' ); ?>">↻ <?php esc_html_e( 'Rescan', 'cf-media-optimizer' ); ?></button>
				</label>
				<p class="description cf-media-optimizer-scope-detail" id="cf-media-optimizer-scope-detail"></p>
			</fieldset>

			<p>
				<label>
					<input id="cf-media-optimizer-force" type="checkbox">
					<?php esc_html_e( 'Force reconvert all — re-encodes even up-to-date files. Use after changing quality.', 'cf-media-optimizer' ); ?>
				</label>
			</p>

			<p>
				<button id="cf-media-optimizer-run" class="button button-primary" disabled><?php esc_html_e( 'Convert Now', 'cf-media-optimizer' ); ?></button>
				<button id="cf-media-optimizer-run-bg" class="button"><?php esc_html_e( 'Convert in Background', 'cf-media-optimizer' ); ?></button>
				<button id="cf-media-optimizer-stop" class="button" style="display:none"><?php esc_html_e( 'Stop', 'cf-media-optimizer' ); ?></button>
				<button id="cf-media-optimizer-delete" class="button cf-btn-delete"><?php esc_html_e( 'Delete All Variants', 'cf-media-optimizer' ); ?></button>
				<span id="cf-media-optimizer-done-msg" class="cf-media-optimizer-done-msg" style="display:none">&#10003; <?php esc_html_e( 'All done!', 'cf-media-optimizer' ); ?></span>
			</p>

			<p class="cf-media-optimizer-backfill-row">
				<button id="cf-media-optimizer-backfill" class="button" type="button"><?php esc_html_e( 'Claim all untracked variants…', 'cf-media-optimizer' ); ?></button>
				<span class="description">
					<?php esc_html_e( 'Claims existing .webp/.avif files into the plugin\'s ownership manifest in one pass, so Delete All can remove them and the rewriter can serve them. Use this when Convert reports "An unowned WebP exists in the destination slot" for many images at once — e.g. variants left by a previous plugin or brought in by a media import. Files that are themselves Media Library attachments are skipped (use Diagnose Attachment to remove those individually). Asks for confirmation before writing, and is safe to re-run anytime.', 'cf-media-optimizer' ); ?>
				</span>
			</p>

			<?php if ( ! self::explainer_dismissed() ) : ?>
			<div class="cf-media-optimizer-explainer" id="cf-media-optimizer-explainer">
				<button
					type="button"
					class="cf-media-optimizer-explainer-dismiss"
					id="cf-media-optimizer-explainer-dismiss"
					aria-label="<?php esc_attr_e( 'Dismiss this explainer permanently', 'cf-media-optimizer' ); ?>"
					title="<?php esc_attr_e( 'Dismiss permanently', 'cf-media-optimizer' ); ?>"
				>&times;</button>
				<p>
					<strong><?php esc_html_e( 'In-use only', 'cf-media-optimizer' ); ?></strong>
					<?php esc_html_e( 'scans for images referenced in post content, featured images, page builders (Divi, Elementor, Beaver Builder, Bricks, WPBakery), reusable blocks, block theme templates, widget areas, ACF image fields, WooCommerce galleries, site logo, and site icon. Anything in the media library nothing links to is skipped — a big speed-up on sites with lots of stale imagery.', 'cf-media-optimizer' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Convert in Background', 'cf-media-optimizer' ); ?></strong>
					<?php
					if ( Queue::action_scheduler_available() ) {
						esc_html_e( 'queues the run on the server (Action Scheduler) and processes a small batch every few seconds. You can close this tab — conversion keeps going. Reopen this page anytime to see live progress.', 'cf-media-optimizer' );
					} else {
						esc_html_e( 'queues the run on the server (WP-Cron) and processes a small batch on each cron tick. You can close this tab — conversion keeps going, but cron only fires when someone visits the site. Reopen this page anytime to see live progress.', 'cf-media-optimizer' );
					}
					?>
				</p>
			</div>
			<?php endif; ?>

			<div id="cf-media-optimizer-queue" class="cf-media-optimizer-queue" style="display:none"></div>

			<div id="cf-media-optimizer-log"></div>
		</div>

		<!-- Convert Specific Images ----------------------------------------- -->
		<div class="cf-media-optimizer-section">
			<h2><?php esc_html_e( 'Convert Specific Images', 'cf-media-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'Pick individual images from your media library using the standard WordPress media browser.', 'cf-media-optimizer' ); ?></p>

			<button id="cf-media-optimizer-select" class="button"><?php esc_html_e( 'Choose Images…', 'cf-media-optimizer' ); ?></button>

			<div id="cf-media-optimizer-selection" style="display:none">
				<p id="cf-media-optimizer-sel-summary" class="cf-sel-summary"></p>
				<div id="cf-media-optimizer-sel-list" class="cf-sel-list"></div>
				<p style="margin-top:12px">
					<button id="cf-media-optimizer-convert-selected" class="button button-primary"><?php esc_html_e( 'Convert Selected', 'cf-media-optimizer' ); ?></button>
				</p>
			</div>
		</div>

		<!-- Cache Management ------------------------------------------------ -->
		<div class="cf-media-optimizer-section">
			<h2><?php esc_html_e( 'Cache Management', 'cf-media-optimizer' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: <code>&lt;picture&gt;</code> markup tag. */
					esc_html__( 'Some page builders (Divi in particular) cache compiled CSS that embeds image URLs. After converting images you typically need to purge those caches before the new %s markup appears in the browser.', 'cf-media-optimizer' ),
					'<code>&lt;picture&gt;</code>'
				);
				?>
			</p>

			<p id="cf-media-optimizer-cache-layers" class="cf-cache-layers">
				<span class="cf-cache-layers-loading"><?php esc_html_e( 'Detecting active caches…', 'cf-media-optimizer' ); ?></span>
			</p>

			<p>
				<button id="cf-media-optimizer-purge-now" class="button button-primary"><?php esc_html_e( 'Purge All Caches', 'cf-media-optimizer' ); ?></button>
				<span id="cf-media-optimizer-purge-status" class="cf-media-optimizer-purge-status"></span>
			</p>

			<div id="cf-media-optimizer-purge-results" style="display:none"></div>

			<p class="description">
				<?php
				printf(
					/* translators: %s: <code>cf_media_manager_purge_caches</code> filter name. */
					esc_html__( 'If you use a cache plugin that isn\'t detected, hook into %s from your theme or a mu-plugin to add it.', 'cf-media-optimizer' ),
					'<code>cf_media_manager_purge_caches</code>'
				);
				?>
			</p>
		</div>

		<!-- Live Page Verifier --------------------------------------------- -->
		<div class="cf-media-optimizer-section">
			<h2><?php esc_html_e( 'Verify a Live Page', 'cf-media-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'Fetch a public URL and report how many of its images are being served as WebP/AVIF. Useful for confirming the rewrite works against production caches.', 'cf-media-optimizer' ); ?></p>

			<p>
				<input type="url" id="cf-media-optimizer-verify-url" class="regular-text" value="<?php echo esc_attr( home_url( '/' ) ); ?>">
				<button id="cf-media-optimizer-verify-btn" class="button"><?php esc_html_e( 'Verify', 'cf-media-optimizer' ); ?></button>
			</p>

			<div id="cf-media-optimizer-verify-result" style="display:none"></div>
		</div>

		<!-- Diagnose Attachment ---------------------------------------------- -->
		<div class="cf-media-optimizer-section">
			<h2><?php esc_html_e( 'Diagnose Attachment', 'cf-media-optimizer' ); ?></h2>
			<p>
				<?php esc_html_e( 'Pass an attachment ID to see the variant-ownership state for that image. Useful when Convert reports "An unowned WebP exists in the destination slot" for a file you expected Adopt to claim. The report tells you exactly why and offers a one-click fix.', 'cf-media-optimizer' ); ?>
			</p>
			<p>
				<label for="cf-diag-id"><?php esc_html_e( 'Attachment ID:', 'cf-media-optimizer' ); ?></label>
				<input type="number" id="cf-diag-id" min="1" class="small-text" placeholder="485">
				<button type="button" id="cf-diag-run" class="button"><?php esc_html_e( 'Diagnose', 'cf-media-optimizer' ); ?></button>
			</p>
			<div id="cf-diag-result" style="display:none"></div>
		</div>

		</div><!-- /#cf-media-optimizer-panel-convert -->

		<!-- ================================================================ -->
		<!-- Tab: Settings                                                     -->
		<!-- ================================================================ -->
		<div id="cf-media-optimizer-panel-settings" class="cf-media-optimizer-tab-panel" role="tabpanel" aria-labelledby="cf-media-optimizer-tab-settings">

		<!-- HTML Rewriting ------------------------------------------------- -->
		<div class="cf-media-optimizer-section">
			<h2><?php esc_html_e( 'HTML Rewriting', 'cf-media-optimizer' ); ?></h2>
			<div class="cf-toggle-row">
				<label class="cf-toggle" for="cf-media-optimizer-rewrite-enabled">
					<input type="checkbox" id="cf-media-optimizer-rewrite-enabled" class="cf-toggle__input" <?php checked( $rewrite, true ); ?>>
					<span class="cf-toggle__track" aria-hidden="true">
						<span class="cf-toggle__thumb"></span>
					</span>
					<span class="screen-reader-text"><?php esc_html_e( 'Enable HTML rewriting', 'cf-media-optimizer' ); ?></span>
				</label>
				<div class="cf-toggle-copy">
					<strong class="cf-toggle-title"><?php esc_html_e( 'Enable HTML rewriting', 'cf-media-optimizer' ); ?></strong>
					<span class="cf-toggle-desc">
						<?php
						printf(
							/* translators: 1: <code>&lt;img&gt;</code>, 2: <code>&lt;picture&gt;</code>. */
							esc_html__( 'Wrap upload-dir %1$s tags in %2$s with WebP/AVIF sources.', 'cf-media-optimizer' ),
							'<code>&lt;img&gt;</code>',
							'<code>&lt;picture&gt;</code>'
						);
						?>
					</span>
				</div>
			</div>

			<table class="form-table cf-media-optimizer-rewrite-settings" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Serve modern formats to', 'cf-media-optimizer' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="cf_media_optimizer_scope" value="all" <?php checked( $scope, 'all' ); ?>>
								<?php esc_html_e( 'All visitors', 'cf-media-optimizer' ); ?> <span class="description"><?php esc_html_e( '(recommended)', 'cf-media-optimizer' ); ?></span>
							</label><br>
							<label>
								<input type="radio" name="cf_media_optimizer_scope" value="guests" <?php checked( $scope, 'guests' ); ?>>
								<?php esc_html_e( 'Logged-out visitors only', 'cf-media-optimizer' ); ?>
							</label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Use "logged-out only" if admin users or page-builder editors need to see original image URLs.', 'cf-media-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'AVIF output', 'cf-media-optimizer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="cf-media-optimizer-enable-avif" <?php checked( $enable_avif && $avif_ready, true ); ?> <?php disabled( ! $avif_ready ); ?>>
							<?php esc_html_e( 'Generate AVIF alongside WebP', 'cf-media-optimizer' ); ?>
						</label>
						<p class="description">
							<?php if ( $avif_ready ) : ?>
								<?php
								printf(
									/* translators: %s: <code>&lt;picture&gt;</code> markup tag. */
									esc_html__( 'AVIF is typically 20–30%% smaller than WebP at the same visual quality. Browsers without AVIF receive WebP via %s negotiation.', 'cf-media-optimizer' ),
									'<code>&lt;picture&gt;</code>'
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'Your Imagick build does not include the AVIF coder. Install ImageMagick with libavif support to enable.', 'cf-media-optimizer' ); ?>
							<?php endif; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Favicon rewriting', 'cf-media-optimizer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="cf-media-optimizer-rewrite-favicons" <?php checked( $rewrite_favicons, true ); ?>>
							<?php esc_html_e( 'Rewrite favicon and touch-icon links to WebP', 'cf-media-optimizer' ); ?>
						</label>
						<p class="description">
							<?php
							printf(
								wp_kses(
									/* translators: 1: <strong>Recommended:</strong> label, 2: <code>apple-touch-icon</code> rel value. */
									__( '%1$s leave this off. iOS rejects %2$s in WebP for home-screen install, and the conventional multi-format favicon declaration (.ico fallback, PNG 32×32 and 192×192, Apple touch PNG) is still the most compatible pattern. Enable only if you have verified every consumer of your favicons can handle .webp.', 'cf-media-optimizer' ),
									array( 'strong' => array(), 'code' => array() )
								),
								'<strong>' . esc_html__( 'Recommended:', 'cf-media-optimizer' ) . '</strong>',
								'<code>apple-touch-icon</code>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Alt text fallback', 'cf-media-optimizer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="cf-media-optimizer-alt-fallback" <?php checked( $alt_fallback, true ); ?>>
							<?php esc_html_e( 'Apply Media Library alt text to images that render without it', 'cf-media-optimizer' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'At render time, fills an empty or missing alt attribute on any uploads-folder image from that attachment\'s Media Library alt text (the same field CF Media Optimizer\'s Accessibility tab edits, when installed). Page builders like Divi store their own per-module alt and ignore the attachment field, so alt set in the media library otherwise never reaches the page. Only adds alt — never overrides an existing one, and skips images marked decorative or aria-hidden. Requires HTML rewriting (above) to be enabled.', 'cf-media-optimizer' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Page filter', 'cf-media-optimizer' ); ?></th>
					<td>
						<select id="cf-media-optimizer-filter-mode">
							<option value="none" <?php selected( $filter, 'none' ); ?>><?php esc_html_e( 'No filter — rewrite on all pages', 'cf-media-optimizer' ); ?></option>
							<option value="blacklist" <?php selected( $filter, 'blacklist' ); ?>><?php esc_html_e( 'Blacklist — skip listed pages', 'cf-media-optimizer' ); ?></option>
							<option value="whitelist" <?php selected( $filter, 'whitelist' ); ?>><?php esc_html_e( 'Whitelist — only rewrite listed pages', 'cf-media-optimizer' ); ?></option>
						</select>
						<p class="description">
							<?php
							printf(
								wp_kses(
									/* translators: 1: <strong>Blacklist:</strong> label, 2: <strong>Whitelist:</strong> label. */
									__( '%1$s rewrite everywhere except the listed pages.<br>%2$s rewrite only on the listed pages.', 'cf-media-optimizer' ),
									array( 'br' => array() )
								),
								'<strong>' . esc_html__( 'Blacklist:', 'cf-media-optimizer' ) . '</strong>',
								'<strong>' . esc_html__( 'Whitelist:', 'cf-media-optimizer' ) . '</strong>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cf-media-optimizer-batch-size"><?php esc_html_e( 'Batch size', 'cf-media-optimizer' ); ?></label></th>
					<td>
						<input id="cf-media-optimizer-batch-size" type="number" min="1" max="25" value="<?php echo esc_attr( $batch_size ); ?>" class="small-text">
						<span class="description">
							<?php
							printf(
								/* translators: 1: <strong>1</strong>, 2: <strong>5–10</strong>. */
								esc_html__( 'Attachments processed per AJAX call. %1$s = smoothest progress, %2$s = much faster on large libraries.', 'cf-media-optimizer' ),
								'<strong>1</strong>',
								'<strong>5&ndash;10</strong>'
							);
							?>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cf-media-optimizer-max-source-mb"><?php esc_html_e( 'Max source filesize', 'cf-media-optimizer' ); ?></label></th>
					<td>
						<input id="cf-media-optimizer-max-source-mb" type="number" min="1" max="<?php echo (int) Options::HARD_MAX_SOURCE_MB; ?>" value="<?php echo esc_attr( $max_source_mb ); ?>" class="small-text"> <?php esc_html_e( 'MB', 'cf-media-optimizer' ); ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: default cap in MB, 2: hard ceiling in MB. */
								esc_html__( 'Sources larger than this are skipped (decoder memory cap). Default %1$d MB; hard ceiling %2$d MB. Raise this if you want full-resolution WebP/AVIF for high-megapixel photography — but make sure PHP\'s memory_limit for image operations can handle the larger decode.', 'cf-media-optimizer' ),
								(int) Options::DEFAULT_MAX_SOURCE_MB,
								(int) Options::HARD_MAX_SOURCE_MB
							);
							?>
						</p>
					</td>
				</tr>
				<tr id="cf-media-optimizer-patterns-row" <?php echo $filter === 'none' ? ' style="display:none"' : ''; ?>>
					<th scope="row"><?php esc_html_e( 'URL patterns', 'cf-media-optimizer' ); ?></th>
					<td>
						<textarea id="cf-media-optimizer-filter-patterns" rows="7" class="large-text code"
									placeholder="/legacy-page/&#10;/shop/*&#10;*/old-gallery/*"><?php echo esc_textarea( $patterns ); ?></textarea>
						<p class="description">
							<?php
							printf(
								wp_kses(
									/* translators: 1: <code>*</code>, 2: <code>#</code>. */
									__( 'One URL path per line. Use %1$s as a wildcard. Lines starting with %2$s are ignored.<br>Examples: <code>/contact/</code> &nbsp;·&nbsp; <code>/shop/*</code> &nbsp;·&nbsp; <code>*/legacy-gallery/*</code>', 'cf-media-optimizer' ),
									array(
										'br'   => array(),
										'code' => array(),
									)
								),
								'<code>*</code>',
								'<code>#</code>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'On uninstall', 'cf-media-optimizer' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="cf-media-optimizer-delete-on-uninstall" <?php checked( $delete_on_uninstall ); ?>>
							<?php esc_html_e( 'Delete generated WebP and AVIF files when this plugin is uninstalled', 'cf-media-optimizer' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Only files written by this plugin are removed. Original JPEGs and PNGs are never touched. Leave unchecked to keep generated variants on disk after uninstalling.', 'cf-media-optimizer' ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button id="cf-media-optimizer-save-settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'cf-media-optimizer' ); ?></button>
				<span id="cf-media-optimizer-settings-saved" class="cf-media-optimizer-saved"> <?php esc_html_e( 'Saved.', 'cf-media-optimizer' ); ?></span>
			</p>
		</div>

		<!-- Quality -------------------------------------------------------- -->
		<div class="cf-media-optimizer-section">
			<h2><?php esc_html_e( 'Quality', 'cf-media-optimizer' ); ?></h2>
			<p>
				<label for="cf-media-optimizer-quality"><?php esc_html_e( 'Output quality:', 'cf-media-optimizer' ); ?></label>
				<input id="cf-media-optimizer-quality" type="number" min="1" max="100"
						value="<?php echo esc_attr( $quality ); ?>" class="small-text">
				<span class="description"><?php esc_html_e( '80 matches the default of common image-processing libraries (Sharp, etc.) and is visually lossless for most photos. Lower = smaller files. Applied to both WebP and AVIF.', 'cf-media-optimizer' ); ?></span>
			</p>
			<p>
				<button id="cf-media-optimizer-save-quality" class="button"><?php esc_html_e( 'Save Quality', 'cf-media-optimizer' ); ?></button>
				<button id="cf-media-optimizer-apply-quality" class="button button-primary"><?php esc_html_e( 'Save & Apply', 'cf-media-optimizer' ); ?></button>
				<span id="cf-media-optimizer-quality-saved" class="cf-media-optimizer-saved"> <?php esc_html_e( 'Saved.', 'cf-media-optimizer' ); ?></span>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: "Save Quality" button name in <strong>, 2: "Save & Apply" button name in <strong>. */
					esc_html__( '%1$s updates the setting only. %2$s re-encodes every existing image at the new quality.', 'cf-media-optimizer' ),
					'<strong>' . esc_html__( 'Save Quality', 'cf-media-optimizer' ) . '</strong>',
					'<strong>' . esc_html__( 'Save & Apply', 'cf-media-optimizer' ) . '</strong>'
				);
				?>
			</p>
		</div>

		</div><!-- /#cf-media-optimizer-panel-settings -->

		<p class="cf-media-optimizer-footer-meta">
			<?php if ( $engine !== 'NONE' ) : ?>
				<span>
					<?php
					printf(
						/* translators: %s: name of the conversion engine in use (Imagick or GD). */
						esc_html__( 'Conversion engine: %s', 'cf-media-optimizer' ),
						'<strong>' . esc_html( $engine ) . '</strong>'
					);
					?>
				</span>
				<?php if ( $avif_ready ) : ?>
					<span class="cf-avif-badge"><?php esc_html_e( 'AVIF available', 'cf-media-optimizer' ); ?></span>
				<?php else : ?>
					<span class="cf-avif-badge cf-avif-badge--off"><?php esc_html_e( 'AVIF unavailable', 'cf-media-optimizer' ); ?></span>
				<?php endif; ?>
				<span aria-hidden="true">·</span>
			<?php endif; ?>
			<span>
				<?php
				printf(
					wp_kses(
						/* translators: 1: plugin version string, 2: WordPress version string. */
						__( 'Media Optimizer %1$s &nbsp;·&nbsp; WordPress %2$s &nbsp;·&nbsp; <a href="mailto:bugs@caifrazier.com">Report a bug</a>', 'cf-media-optimizer' ),
						[ 'a' => [ 'href' => [] ] ]
					),
					esc_html( CF_MEDIA_OPTIMIZER_VERSION ),
					esc_html( get_bloginfo( 'version' ) )
				);
				?>
			</span>
		</p>

		</div><!-- /.wrap -->
		<?php
	}

	// ====================================================================
	// Post-conversion admin notice
	// ====================================================================

	public function maybe_show_purge_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_option( Options::PURGE_FLAG, 0 ) ) {
			return;
		}
		// Limit to the plugin's own page, the dashboard, and the Media library.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? $screen->id : '';
		$allow  = array( 'media_page_cf-media-optimizer', 'dashboard', 'upload' );
		if ( ! in_array( $id, $allow, true ) ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible cf-media-optimizer-purge-notice">
			<p>
				<?php
				printf(
					/* translators: %s: "Media Optimizer:" label in <strong>. */
					esc_html__( '%s Conversion complete. If you use a page cache or page builder (Divi, WP Engine, WP Rocket, LiteSpeed, etc.), purge it now so existing cached pages reload with the new markup.', 'cf-media-optimizer' ),
					'<strong>' . esc_html__( 'Media Optimizer:', 'cf-media-optimizer' ) . '</strong>'
				);
				?>
			</p>
			<p>
				<button type="button" class="button button-primary cf-media-optimizer-notice-purge"><?php esc_html_e( 'Purge All Caches Now', 'cf-media-optimizer' ); ?></button>
				<span class="cf-media-optimizer-notice-purge-status"></span>
			</p>
		</div>
		<?php
	}

	// ====================================================================
	// Media Library "WebP" column
	// ====================================================================

	public function add_media_column( array $columns ): array {
		$columns['cf_media_manager'] = __( 'WebP', 'cf-media-optimizer' );
		return $columns;
	}

	public function render_media_column( string $column, int $post_id ): void {
		if ( $column !== 'cf_media_manager' ) {
			return;
		}
		$mime = get_post_mime_type( $post_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			echo '<span class="cf-media-optimizer-col cf-media-optimizer-col--na">—</span>';
			return;
		}
		if ( $this->converter->is_attachment_converted( $post_id ) ) {
			printf(
				'<span class="cf-media-optimizer-col cf-media-optimizer-col--ok" title="%s">&#10003; %s</span>',
				esc_attr__( 'WebP converted', 'cf-media-optimizer' ),
				esc_html__( 'Converted', 'cf-media-optimizer' )
			);
		} else {
			printf(
				'<span class="cf-media-optimizer-col cf-media-optimizer-col--pending" title="%s">%s</span>',
				esc_attr__( 'Not yet converted', 'cf-media-optimizer' ),
				esc_html__( 'Pending', 'cf-media-optimizer' )
			);
		}
	}

	public function enqueue_media_column_styles( string $hook ): void {
		if ( $hook !== 'upload.php' ) {
			return;
		}
		wp_register_style( 'cf-media-optimizer-media-column', false, array(), CF_MEDIA_OPTIMIZER_VERSION );
		wp_enqueue_style( 'cf-media-optimizer-media-column' );
		wp_add_inline_style(
			'cf-media-optimizer-media-column',
			'.column-cf_media_manager{width:100px}'
			. '.cf-media-optimizer-col{font-size:12px;font-weight:600;padding:2px 8px;border-radius:99px;display:inline-block}'
			. '.cf-media-optimizer-col--ok{background:#edfaef;color:#00a32a}'
			. '.cf-media-optimizer-col--pending{background:#f0f0f1;color:#646970}'
			. '.cf-media-optimizer-col--na{color:#c3c4c7;font-weight:400}'
		);
	}
}
