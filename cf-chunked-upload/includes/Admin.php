<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Admin surfaces:
 *   - Media → Chunked Upload   (settings)
 *   - Tools → Import Large File (standalone importer)
 *
 * Both mount a small app from the wp-scripts build. The media-modal hook is
 * enqueued on the upload/post screens so large files chunk transparently
 * through the native modal. Every screen gets the same bootstrap object
 * (REST root, nonce, resolved settings) via wp_add_inline_script.
 */
final class Admin {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'admin_notices', [ $this, 'maybe_build_notice' ] );
	}

	public function register_pages(): void {
		add_submenu_page(
			'upload.php',
			__( 'Chunked Upload', 'cf-chunked-upload' ),
			__( 'Chunked Upload', 'cf-chunked-upload' ),
			'manage_options',
			'cf-chunked-upload',
			[ $this, 'render_settings_page' ]
		);

		add_management_page(
			__( 'Import Large File', 'cf-chunked-upload' ),
			__( 'Import Large File', 'cf-chunked-upload' ),
			$this->settings->importer_capability(),
			'cf-import-large-file',
			[ $this, 'render_importer_page' ]
		);
	}

	public function render_settings_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Chunked Upload', 'cf-chunked-upload' ) . '</h1>';
		echo '<div id="cf-chunked-upload-settings"></div></div>';
	}

	public function render_importer_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Import Large File', 'cf-chunked-upload' ) . '</h1>';
		echo '<div id="cf-chunked-upload-importer"></div></div>';
	}

	public function enqueue( string $hook ): void {
		$is_settings = ( 'media_page_cf-chunked-upload' === $hook );
		$is_importer = ( 'tools_page_cf-import-large-file' === $hook );
		$is_media    = in_array( $hook, [ 'upload.php', 'post.php', 'post-new.php', 'media-new.php' ], true );

		if ( ! $is_settings && ! $is_importer && ! $is_media ) {
			return;
		}

		$entry = $is_settings ? 'settings' : ( $is_importer ? 'importer' : 'media-hook' );
		$this->enqueue_entry( $entry );
	}

	/**
	 * Surface a clear error when the built assets are missing. Without this,
	 * the settings and importer pages render empty containers with no
	 * explanation — confusing on dev checkouts and during WP.org review.
	 *
	 * @return void
	 */
	public function maybe_build_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! file_exists( CF_CHUNKED_UPLOAD_DIR . 'build/settings.js' ) ) {
			?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'CF Chunked Upload: plugin assets are missing. If you installed from source, run `npm run build` in the plugin directory and reload.', 'cf-chunked-upload' ); ?></p>
			</div>
			<?php
		}
	}

	private function enqueue_entry( string $entry ): void {
		$base  = CF_CHUNKED_UPLOAD_DIR . 'build/';
		$asset = $base . $entry . '.asset.php';
		$jsrel = 'build/' . $entry . '.js';

		if ( ! file_exists( $base . $entry . '.js' ) ) {
			// Build not present (dev checkout without `npm run build`). The
			// admin page still renders its container; nothing to enqueue.
			return;
		}

		$meta   = file_exists( $asset ) ? include $asset : [
			'dependencies' => [],
			'version'      => CF_CHUNKED_UPLOAD_VERSION,
		];
		$handle = 'cf-chunked-upload-' . $entry;

		wp_enqueue_script(
			$handle,
			CF_CHUNKED_UPLOAD_URL . $jsrel,
			$meta['dependencies'] ?? [],
			$meta['version'] ?? CF_CHUNKED_UPLOAD_VERSION,
			true
		);

		wp_set_script_translations(
			$handle,
			'cf-chunked-upload',
			CF_CHUNKED_UPLOAD_DIR . 'languages'
		);

		if ( file_exists( $base . $entry . '.css' ) ) {
			wp_enqueue_style(
				$handle,
				CF_CHUNKED_UPLOAD_URL . 'build/' . $entry . '.css',
				[],
				$meta['version'] ?? CF_CHUNKED_UPLOAD_VERSION
			);
		}

		$boot = [
			'restRoot'             => esc_url_raw( rest_url( RestApi::NS ) ),
			'nonce'                => wp_create_nonce( 'wp_rest' ),
			'settings'             => $this->settings->get(),
			'hostInfo'             => HostInfo::snapshot(),
			'maxRetries'           => (int) $this->settings->get()['max_retries'],
			'concurrency'          => (int) $this->settings->get()['concurrency'],
			'chunkBytes'           => $this->settings->chunk_size_bytes(),
			'thresholdBytes'       => $this->settings->threshold_bytes(),
			'maxConcurrentImports' => $this->settings->max_concurrent_uploads_per_user(),
		];
		wp_add_inline_script(
			$handle,
			'window.CFChunkedUpload = ' . wp_json_encode( $boot ) . ';',
			'before'
		);
	}
}
