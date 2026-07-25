<?php

namespace BulkMetaEditor;

defined( 'ABSPATH' ) || exit;

use CFShared\Logger as SharedLogger;

class Admin {

	private Settings $settings;
	private SharedLogger $logger;

	/**
	 * Hook suffixes returned by add_*_page, captured so enqueue_assets can
	 * match the current admin screen without hardcoding the menu-parent
	 * portion of the hook (which changes if the parent slug changes).
	 *
	 * @var array<string,string> Keyed by app entry ('editor'|'settings'|'diagnostics').
	 */
	private array $page_hooks = [];

	public function __construct( Settings $settings, SharedLogger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;

		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_menus(): void {
		// One sidebar entry only. Settings and Diagnostics are registered as
		// hidden submenu pages (parent_slug = null) so admin.php?page=… still
		// routes to them, but they never render in the menu. Navigation
		// between the three views happens via the tab nav rendered at the
		// top of every page (see render_tab_nav).
		$this->page_hooks['editor'] = add_menu_page(
			__( 'CF Bulk Meta Editor', 'cf-bulk-meta-editor' ),
			__( 'CF Bulk Meta Editor', 'cf-bulk-meta-editor' ),
			'edit_posts',
			'cf-bulk-meta-editor',
			[ $this, 'render_editor_page' ],
			'dashicons-edit-large',
			75
		);

		$this->page_hooks['settings'] = add_submenu_page(
			null,
			__( 'CF Bulk Meta Editor Settings', 'cf-bulk-meta-editor' ),
			__( 'Settings', 'cf-bulk-meta-editor' ),
			'manage_options',
			'bulk-meta-editor-settings',
			[ $this, 'render_settings_page' ]
		);

		$this->page_hooks['diagnostics'] = add_submenu_page(
			null,
			__( 'CF Bulk Meta Editor Diagnostics', 'cf-bulk-meta-editor' ),
			__( 'Diagnostics', 'cf-bulk-meta-editor' ),
			'manage_options',
			'bulk-meta-editor-diagnostics',
			[ $this, 'render_diagnostics_page' ]
		);
	}

	/**
	 * Render the cross-page tab nav at the top of each BME admin screen.
	 *
	 * Tabs are plain server-rendered links (no JS dependency) styled with
	 * core's `nav-tab-wrapper` so they match every other tabbed settings
	 * screen in WP. Settings + Diagnostics tabs only appear for users with
	 * `manage_options` — the underlying pages already require that capability,
	 * so showing the links to editors would just be a dead end.
	 */
	private function render_tab_nav( string $active ): void {
		$tabs = [
			'editor'      => [
				'page'  => 'cf-bulk-meta-editor',
				'label' => __( 'Editor', 'cf-bulk-meta-editor' ),
				'cap'   => 'edit_posts',
			],
			'settings'    => [
				'page'  => 'bulk-meta-editor-settings',
				'label' => __( 'Settings', 'cf-bulk-meta-editor' ),
				'cap'   => 'manage_options',
			],
			'diagnostics' => [
				'page'  => 'bulk-meta-editor-diagnostics',
				'label' => __( 'Diagnostics', 'cf-bulk-meta-editor' ),
				'cap'   => 'manage_options',
			],
		];

		// Settings and Diagnostics React apps already render their own <h1>;
		// the editor's grid doesn't need one above it. Skipping a PHP <h1>
		// here avoids the double-heading on those two pages. The tabs
		// themselves carry sufficient context — WP's window title and the
		// active sidebar item also identify the page.
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Secondary menu', 'cf-bulk-meta-editor' ) . '" style="margin-top:1em;">';
		foreach ( $tabs as $key => $tab ) {
			if ( ! current_user_can( $tab['cap'] ) ) {
				continue;
			}
			$class = 'nav-tab' . ( $active === $key ? ' nav-tab-active' : '' );
			$url   = admin_url( 'admin.php?page=' . $tab['page'] );
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $tab['label'] )
			);
		}
		echo '</nav>';
	}

	public function render_editor_page(): void {
		echo '<div class="wrap">';
		$this->render_tab_nav( 'editor' );
		echo '<div id="bme-editor"></div>';
		echo '</div>';
	}

	public function render_settings_page(): void {
		echo '<div class="wrap">';
		$this->render_tab_nav( 'settings' );
		echo '<div id="bme-settings"></div>';
		printf(
			'<p class="description" style="margin-top:2em">%s</p>',
			wp_kses(
				sprintf(
					/* translators: 1: plugin version string, 2: WordPress version string. */
					__( 'CF Bulk Meta Editor %1$s &nbsp;·&nbsp; WordPress %2$s &nbsp;·&nbsp; <a href="mailto:bugs@caifrazier.com">Report a bug</a>', 'cf-bulk-meta-editor' ),
					esc_html( CFBME_VERSION ),
					esc_html( get_bloginfo( 'version' ) )
				),
				[ 'a' => [ 'href' => [] ] ]
			)
		);
		echo '</div>';
	}

	public function render_diagnostics_page(): void {
		echo '<div class="wrap">';
		$this->render_tab_nav( 'diagnostics' );
		echo '<div id="bme-diagnostics"></div>';
		echo '</div>';
	}

	public function enqueue_assets( string $hook ): void {
		// Match against the hook suffixes WP returned from add_*_page in
		// register_menus — this is portable across menu-parent moves (Tools
		// vs top-level) without us having to know the auto-derived prefix.
		if ( ( $this->page_hooks['editor'] ?? null ) === $hook ) {
			$this->enqueue_app( 'editor' );
			$this->localize_editor();
			return;
		}

		if ( ( $this->page_hooks['settings'] ?? null ) === $hook ) {
			$this->enqueue_app( 'settings' );
			$this->localize_settings();
			return;
		}

		if ( ( $this->page_hooks['diagnostics'] ?? null ) === $hook ) {
			$this->enqueue_app( 'diagnostics' );
			$this->localize_diagnostics();
		}
	}

	private function enqueue_app( string $entry ): void {
		$handle     = "bme-{$entry}";
		$asset_file = CFBME_DIR . "build/{$entry}.asset.php";

		if ( ! file_exists( $asset_file ) ) {
			// Build hasn't run — surface a clear notice instead of silently doing nothing.
			add_action(
				'admin_notices',
				function () use ( $entry ) {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
							/* translators: %s: build entry name (editor or settings) */
								__( 'Bulk Meta Editor: build assets for "%s" are missing. Run `npm run build` in the plugin directory.', 'cf-bulk-meta-editor' ),
								$entry
							)
						)
					);
				}
			);
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			$handle,
			CFBME_URL . "build/{$entry}.js",
			$asset['dependencies'] ?? [],
			$asset['version'] ?? CFBME_VERSION,
			true
		);

		// Wire up runtime translations for every JS string that flows through
		// @wordpress/i18n in the bundle. Without this, calls like __( 'foo', 'cf-bulk-meta-editor' )
		// in the React code stay in English regardless of the site locale.
		// wp-scripts already declared wp-i18n as a dependency above (see
		// build/{$entry}.asset.php), so wp.i18n is guaranteed to be on the
		// page; this call tells WP where to look for the .json language files
		// it serves to the script handle.
		wp_set_script_translations( $handle, 'cf-bulk-meta-editor', CFBME_DIR . 'languages' );

		// Component styles for Notice, Popover, Modal, etc.
		wp_enqueue_style( 'wp-components' );

		if ( file_exists( CFBME_DIR . "build/{$entry}.css" ) ) {
			wp_enqueue_style(
				$handle,
				CFBME_URL . "build/{$entry}.css",
				[ 'wp-components' ],
				$asset['version'] ?? CFBME_VERSION
			);
		}
	}

	private function localize_editor(): void {
		wp_add_inline_script(
			'bme-editor',
			'var bmeEditorData = ' . wp_json_encode(
				[
					'apiNamespace'     => RestController::REST_NAMESPACE,
					'restRoot'         => esc_url_raw( rest_url() ),
					'nonce'            => wp_create_nonce( 'wp_rest' ),
					'settings'         => $this->settings->get(),
					'columnVisibility' => (object) $this->settings->get_column_visibility( get_current_user_id() ),
					'adminUrl'         => admin_url(),
					'canMirrorLogs'    => current_user_can( 'manage_options' ),
				]
			) . ';',
			'before'
		);
	}

	private function localize_settings(): void {
		wp_add_inline_script(
			'bme-settings',
			'var bmeSettingsData = ' . wp_json_encode(
				[
					'apiNamespace'  => RestController::REST_NAMESPACE,
					'restRoot'      => esc_url_raw( rest_url() ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'settings'      => $this->settings->get(),
					'postTypes'     => $this->settings->get_public_post_types(),
					'presets'       => Settings::PRESETS,
					'canMirrorLogs' => current_user_can( 'manage_options' ),
				]
			) . ';',
			'before'
		);
	}

	private function localize_diagnostics(): void {
		wp_add_inline_script(
			'bme-diagnostics',
			'var bmeDiagnosticsData = ' . wp_json_encode(
				[
					'apiNamespace'  => RestController::REST_NAMESPACE,
					'restRoot'      => esc_url_raw( rest_url() ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'canMirrorLogs' => current_user_can( 'manage_options' ),
				]
			) . ';',
			'before'
		);
	}
}
