<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI for the management side: the "Media → Media Manager" page hosting the
 * Accessibility (bulk alt-text) and Audit tabs.
 *
 * Distinct from the AJAX classes (AltTextManager, AuditAjax) — this class only
 * renders markup and registers assets. The Library list view is a separate page
 * ({@see LibraryPage}); this page focuses on alt-text + audit.
 */
final class AdminPage {

	private ?AuditPage $audit_page;

	public function __construct( ?AuditPage $audit_page = null ) {
		$this->audit_page = $audit_page;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( CF_MEDIA_MANAGER_FILE ),
			array( $this, 'add_settings_action_link' )
		);
	}

	/**
	 * Prepend a link to the plugin's page on the Plugins admin screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_action_link( $links ): array {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$url  = admin_url( 'upload.php?page=cf-media-manager' );
		$open = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'cf-media-manager' ) . '</a>';
		array_unshift( $links, $open );
		return $links;
	}

	/**
	 * True when the sibling CF Media Optimizer plugin is present. Used to light
	 * up a convenience cross-link — never to require it (independence rule) and
	 * never for promotion. Degrades silently when absent.
	 */
	public static function optimizer_active(): bool {
		return class_exists( '\\CFMediaOptimizer\\Plugin' );
	}

	public static function explainer_dismissed(): bool {
		if ( ! function_exists( 'get_current_user_id' ) || ! function_exists( 'get_user_meta' ) ) {
			return false;
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		return (bool) get_user_meta( $user_id, 'cf_media_manager_explainer_dismissed', true );
	}

	public function admin_menu(): void {
		add_media_page(
			__( 'Media Manager', 'cf-media-manager' ),
			__( 'Media Manager', 'cf-media-manager' ),
			'manage_options',
			'cf-media-manager',
			array( $this, 'render_page' )
		);
	}

	public function admin_scripts( string $hook ): void {
		if ( $hook !== 'media_page_cf-media-manager' ) {
			return;
		}
		$languages_path = plugin_dir_path( CF_MEDIA_MANAGER_FILE ) . 'languages';

		wp_enqueue_media();
		wp_enqueue_style(
			'cf-media-manager-admin',
			plugins_url( 'assets/admin.css', CF_MEDIA_MANAGER_FILE ),
			array(),
			CF_MEDIA_MANAGER_VERSION
		);
		wp_enqueue_script(
			'cf-media-manager-admin',
			plugins_url( 'assets/admin.js', CF_MEDIA_MANAGER_FILE ),
			array( 'jquery', 'wp-i18n' ),
			CF_MEDIA_MANAGER_VERSION,
			true
		);
		wp_set_script_translations( 'cf-media-manager-admin', 'cf-media-manager', $languages_path );
		wp_localize_script(
			'cf-media-manager-admin',
			'cfMediaManager',
			array(
				'nonce'   => wp_create_nonce( Plugin::NONCE_ACTION ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);

		// Audit tab assets. Loaded alongside admin.js; the script self-defers
		// hydration until the Audit tab is opened.
		wp_enqueue_script(
			'cf-media-manager-audit',
			plugins_url( 'assets/audit.js', CF_MEDIA_MANAGER_FILE ),
			array( 'jquery', 'wp-i18n', 'cf-media-manager-admin' ),
			CF_MEDIA_MANAGER_VERSION,
			true
		);
		wp_set_script_translations( 'cf-media-manager-audit', 'cf-media-manager', $languages_path );
	}

	public function render_page(): void {
		?>
		<div class="wrap">

		<h1 class="cf-media-manager-h1">
			<img class="cf-media-manager-logo" src="<?php echo esc_url( plugins_url( 'assets/cf-logo.svg', CF_MEDIA_MANAGER_FILE ) ); ?>" alt="CF">
			<span class="cf-media-manager-h1-text"><?php esc_html_e( 'Media Manager', 'cf-media-manager' ); ?></span>
		</h1>
		<hr class="wp-header-end">

		<?php if ( self::optimizer_active() ) : ?>
			<p class="description cf-media-manager-sibling-link">
				<?php
				printf(
					/* translators: %s: link to the CF Media Optimizer settings page. */
					esc_html__( 'WebP/AVIF conversion and %s are handled by CF Media Optimizer.', 'cf-media-manager' ),
					'<a href="' . esc_url( admin_url( 'upload.php?page=cf-media-optimizer' ) ) . '">' . esc_html__( 'image delivery settings', 'cf-media-manager' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>

		<nav class="cf-media-manager-tabs" role="tablist">
			<button class="cf-media-manager-tab is-active" role="tab" data-tab="accessibility"
					id="cf-media-manager-tab-accessibility" aria-controls="cf-media-manager-panel-accessibility" aria-selected="true">
				<?php esc_html_e( 'Accessibility', 'cf-media-manager' ); ?>
			</button>
			<button class="cf-media-manager-tab" role="tab" data-tab="library"
					id="cf-media-manager-tab-library" aria-controls="cf-media-manager-panel-library" aria-selected="false">
				<?php esc_html_e( 'Audit', 'cf-media-manager' ); ?>
			</button>
		</nav>

		<!-- ================================================================ -->
		<!-- Tab: Accessibility (bulk alt-text)                                -->
		<!-- ================================================================ -->
		<div id="cf-media-manager-panel-accessibility" class="cf-media-manager-tab-panel is-active" role="tabpanel" aria-labelledby="cf-media-manager-tab-accessibility">

		<div class="cf-media-manager-section cf-alt-section">
			<h2 class="cf-media-manager-section-h2">
				<span><?php esc_html_e( 'Alt Text', 'cf-media-manager' ); ?></span>
				<button type="button" id="cf-alt-refresh" class="button button-link cf-media-manager-recheck" title="<?php esc_attr_e( 'Reload the current page', 'cf-media-manager' ); ?>">↻ <?php esc_html_e( 'Refresh', 'cf-media-manager' ); ?></button>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Audits the attachment-level alt text field (the same one WordPress shows on the Media Library edit screen). Block-level alt overrides set inline on a specific page are not detected.', 'cf-media-manager' ); ?>
			</p>

			<div class="cf-alt-controls">
				<label for="cf-alt-filter" class="cf-alt-filter-label"><?php esc_html_e( 'Show:', 'cf-media-manager' ); ?></label>
				<select id="cf-alt-filter">
					<option value="all"><?php esc_html_e( 'All images', 'cf-media-manager' ); ?></option>
					<option value="missing"><?php esc_html_e( 'Missing alt text', 'cf-media-manager' ); ?></option>
					<option value="in_use"><?php esc_html_e( 'In-use only', 'cf-media-manager' ); ?></option>
					<option value="in_use_missing" selected><?php esc_html_e( 'In-use + missing alt (recommended)', 'cf-media-manager' ); ?></option>
				</select>
				<span id="cf-alt-summary" class="cf-alt-summary"></span>
				<button type="button" id="cf-alt-save-all" class="button button-primary cf-alt-save-all" disabled>
					<?php esc_html_e( 'Save all changes', 'cf-media-manager' ); ?>
				</button>
			</div>

			<div id="cf-alt-loading" class="cf-alt-loading" style="display:none">
				<span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle"></span>
				<?php esc_html_e( 'Loading…', 'cf-media-manager' ); ?>
			</div>

			<div id="cf-alt-error" class="cf-alt-error notice notice-error inline" style="display:none"><p></p></div>

			<div id="cf-alt-empty" class="cf-alt-empty" style="display:none">
				<p><?php esc_html_e( 'No images match this filter.', 'cf-media-manager' ); ?></p>
			</div>

			<table id="cf-alt-table" class="cf-alt-table widefat striped" style="display:none">
				<thead>
					<tr>
						<th class="cf-alt-col-thumb"><?php esc_html_e( 'Image', 'cf-media-manager' ); ?></th>
						<th class="cf-alt-col-meta"><?php esc_html_e( 'File', 'cf-media-manager' ); ?></th>
						<th class="cf-alt-col-input"><?php esc_html_e( 'Alt text', 'cf-media-manager' ); ?></th>
						<th class="cf-alt-col-decorative"><?php esc_html_e( 'Decorative', 'cf-media-manager' ); ?></th>
						<th class="cf-alt-col-actions"><?php esc_html_e( 'Save', 'cf-media-manager' ); ?></th>
					</tr>
				</thead>
				<tbody id="cf-alt-tbody"></tbody>
			</table>

			<div id="cf-alt-pagination" class="cf-alt-pagination" style="display:none">
				<button type="button" id="cf-alt-prev" class="button">← <?php esc_html_e( 'Previous', 'cf-media-manager' ); ?></button>
				<span id="cf-alt-page-info" class="cf-alt-page-info"></span>
				<button type="button" id="cf-alt-next" class="button"><?php esc_html_e( 'Next', 'cf-media-manager' ); ?> →</button>
			</div>
		</div>

		<!-- Full-image popup for the Alt Text editor. Hidden until a thumb is -->
		<!-- clicked; populated and toggled entirely from admin.js.            -->
		<div id="cf-alt-lightbox" class="cf-alt-lightbox" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Image preview', 'cf-media-manager' ); ?>">
			<div class="cf-alt-lightbox-backdrop"></div>
			<div class="cf-alt-lightbox-body" role="document">
				<button type="button" class="cf-alt-lightbox-close" aria-label="<?php esc_attr_e( 'Close preview', 'cf-media-manager' ); ?>">&times;</button>
				<img class="cf-alt-lightbox-img" src="" alt="">
				<div class="cf-alt-lightbox-caption"></div>
			</div>
		</div>

		</div><!-- /#cf-media-manager-panel-accessibility -->

		<!-- ================================================================ -->
		<!-- Tab: Audit (panel id `library` retained for persisted tab state)  -->
		<!-- ================================================================ -->
		<div id="cf-media-manager-panel-library" class="cf-media-manager-tab-panel" role="tabpanel" aria-labelledby="cf-media-manager-tab-library">

		<div class="cf-media-manager-section">
			<?php
			if ( $this->audit_page instanceof AuditPage ) {
				$this->audit_page->render_section();
			} else {
				echo '<p class="description">' . esc_html__( 'The audit subsystem is not initialized.', 'cf-media-manager' ) . '</p>';
			}
			?>
		</div>

		</div><!-- /#cf-media-manager-panel-library -->

		<p class="cf-media-manager-footer-meta">
			<span>
				<?php
				printf(
					wp_kses(
						/* translators: 1: plugin version string, 2: WordPress version string. */
						__( 'Media Manager %1$s &nbsp;·&nbsp; WordPress %2$s &nbsp;·&nbsp; <a href="mailto:bugs@caifrazier.com">Report a bug</a>', 'cf-media-manager' ),
						[ 'a' => [ 'href' => [] ] ]
					),
					esc_html( CF_MEDIA_MANAGER_VERSION ),
					esc_html( get_bloginfo( 'version' ) )
				);
				?>
			</span>
		</p>

		</div><!-- /.wrap -->
		<?php
	}
}
