<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin facade. Wires collaborators and registers hooks. Accessors let tests
 * and any future CLI reach in without re-instantiating.
 */
final class Plugin {

	private static ?self $instance = null;

	private Paths $paths;
	private Settings $settings;
	private Capabilities $caps;
	private RestApi $rest;
	private FinalizeJob $finalize;
	private Cleanup $cleanup;
	private Admin $admin;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot(): void {
		$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';

		$this->paths    = new Paths( $content_dir );
		$this->settings = new Settings( $this->paths );
		$this->caps     = new Capabilities( $this->settings );
		$this->rest     = new RestApi( $this->paths, $this->settings, $this->caps );
		$this->finalize = new FinalizeJob( $this->paths, $this->settings );
		$this->cleanup  = new Cleanup(
			$this->paths,
			fn() => $this->settings->retention_seconds(),
			fn() => $this->settings->imports_dir(),
			fn() => $this->settings->imports_retention_days()
		);
		$this->admin    = new Admin( $this->settings );

		$this->rest->register_hooks();
		$this->finalize->register_hooks();
		$this->cleanup->register_hooks();

		// Hourly stale-chunk sweep. Self-healing: scheduled on every load if
		// the event went missing (deactivation clears it; reactivation or any
		// admin load re-arms it).
		Cleanup::schedule();

		if ( is_admin() ) {
			$this->admin->register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cf-cu', new Cli( $this->paths, $this->settings, $this->finalize, $this->cleanup ) );
		}
	}

	public function paths(): Paths {
		return $this->paths; }
	public function settings(): Settings {
		return $this->settings; }
	public function finalize(): FinalizeJob {
		return $this->finalize; }
	public function cleanup(): Cleanup {
		return $this->cleanup; }

	/**
	 * Activation gate. Returns null when the host can run the plugin, else an
	 * HTML-safe message. Pure — no side effects — so the activation hook can
	 * wrap it in deactivate_plugins() + wp_die() and tests can exercise every
	 * branch without the WP runtime.
	 */
	public static function check_requirements(): ?string {
		if ( PHP_VERSION_ID < 70400 ) {
			return sprintf(
				/* translators: 1: required PHP version 2: detected PHP version */
				__( 'CF Chunked Upload requires PHP %1$s or newer. This site runs PHP %2$s.', 'cf-chunked-upload' ),
				'7.4',
				PHP_VERSION
			);
		}
		if ( ! function_exists( 'hash_init' ) ) {
			return __( 'CF Chunked Upload requires the PHP hash extension for integrity verification.', 'cf-chunked-upload' );
		}
		// SEC-3: finfo is required for the post-assembly content-based MIME
		// check. Without it we cannot safely verify that the assembled file is
		// the type the client declared, so media-destination uploads would
		// accept any payload regardless of extension or declared MIME.
		if ( ! function_exists( 'finfo_open' ) ) {
			return __( 'CF Chunked Upload requires the PHP fileinfo extension to safely verify uploaded file types. Contact your host to enable it.', 'cf-chunked-upload' );
		}
		return null;
	}
}
