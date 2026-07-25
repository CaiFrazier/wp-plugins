<?php

namespace CFMediaOptimizer;

defined( 'ABSPATH' ) || exit;

use CFShared\Media\Paths;
use CFShared\Media\InUseScanner;

/**
 * Plugin facade. Wires the delivery-side collaborators together and exposes
 * them through accessor methods so cli.php (and tests) can reach in without
 * re-instantiating.
 *
 * CF Media Optimizer is the delivery half of the former CF Media Manager 2.3.0:
 * WebP/AVIF conversion + <picture> rewriting on the front-end hot path. The
 * management half (Library list view, audit reports, bulk alt-text) lives in
 * the separate CF Media Manager plugin. The two are independent — each installs
 * and runs from its own zip — and share only the CFShared\Media kernel
 * (Paths + InUseScanner), bundled into each zip at release.
 */
final class Plugin {

	/**
	 * Nonce action name used by every AJAX endpoint, the post-conversion
	 * notice handler, and the admin script localization. Single source of
	 * truth — changing it here propagates to every consumer.
	 */
	const NONCE_ACTION = 'cf_media_optimizer';

	/**
	 * Prefix every AJAX action name shares. Register hooks build their
	 * full action string from this + an unprefixed slug.
	 */
	const AJAX_PREFIX = 'cf_media_optimizer_';

	/**
	 * Version marker baked into manifest postmeta keys. Reads transparently
	 * accept earlier marker prefixes for backwards compatibility; writes
	 * always go to the current version. Bumping this constant signals a
	 * format change that older versions cannot read.
	 */
	const MANIFEST_SCHEMA_VERSION = 1;

	private static ?self $instance = null;

	private Paths $paths;
	private Converter $converter;
	private Rewriter $rewriter;
	private CachePurger $cache_purger;
	private Queue $queue;
	private InUseScanner $in_use_scanner;
	private AdminPage $admin_page;
	private Ajax $ajax;
	private VariantManifest $manifest;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot(): void {
		// WordPress 4.6+ auto-loads translations by slug; no explicit
		// load_plugin_textdomain() call is needed. The shipped
		// languages/cf-media-optimizer.pot is still useful for contributors.

		$dirs                 = wp_upload_dir();
		$this->paths          = new Paths( $dirs['basedir'], $dirs['baseurl'] );
		$this->manifest       = new VariantManifest( $this->paths );
		$this->converter      = new Converter( $this->paths, $this->manifest );
		$this->rewriter       = new Rewriter( $this->paths, $this->manifest );
		$this->cache_purger   = new CachePurger();
		$this->queue          = new Queue( $this->converter );
		$this->in_use_scanner = new InUseScanner( $this->paths );

		$this->admin_page = new AdminPage( $this->converter );
		$this->ajax       = new Ajax( $this->paths, $this->converter, $this->rewriter, $this->cache_purger, $this->queue, $this->in_use_scanner, $this->manifest );

		// Front-end rewriting + SEO filters.
		$this->rewriter->register_hooks();

		// Convert every size after WP generates thumbnails on upload.
		add_filter( 'wp_generate_attachment_metadata', array( $this->converter, 'on_upload' ), 20, 2 );

		// Background queue.
		$this->queue->register_hooks();

		// In-use scanner — invalidates its transient on post/attachment save.
		$this->in_use_scanner->register_hooks();

		// Admin UI + AJAX (only registered in admin context — saves cycles
		// on every public-facing request). is_admin() returns true during
		// admin-ajax / admin-post requests too, so this single branch
		// covers both the dashboard and AJAX surfaces.
		if ( is_admin() ) {
			$this->admin_page->register_hooks();
			$this->ajax->register_hooks();
		}
	}

	public function paths(): Paths {
		return $this->paths; }
	public function converter(): Converter {
		return $this->converter; }
	public function rewriter(): Rewriter {
		return $this->rewriter; }
	public function cache_purger(): CachePurger {
		return $this->cache_purger; }
	public function queue(): Queue {
		return $this->queue; }
	public function in_use_scanner(): InUseScanner {
		return $this->in_use_scanner; }
	public function ajax(): Ajax {
		return $this->ajax; }
	public function manifest(): VariantManifest {
		return $this->manifest; }

	/**
	 * Activation-time install routine. Run once when the plugin is activated,
	 * AFTER {@see check_requirements()} has confirmed the host can run us.
	 *
	 * Fresh installs get BACKFILL_DONE set immediately (autoload=true) so the
	 * render path's legacy serialized-manifest LIKE-scan fallback never fires
	 * on greenfield sites. Sites carrying pre-1.2.2 variant data are detected
	 * as non-fresh and keep the fallback until an admin completes a backfill.
	 */
	public static function run_install(): void {
		if ( self::is_fresh_install() ) {
			add_option( Options::BACKFILL_DONE, 1, '', 'yes' );
		}
	}

	/**
	 * True when no plugin-owned options exist in the database yet. Sentinels
	 * include the pre-rename cf_media_optimizer_* names so sites carrying the
	 * original optimizer's variant data are not misdetected as fresh.
	 */
	private static function is_fresh_install(): bool {
		$sentinels = array(
			Options::QUALITY,
			Options::REWRITE,
			Options::QUEUE_STATE,
			Options::BACKFILL_DONE,
			// Legacy option names from the original cf-media-optimizer era (May 2026).
			'cf_media_optimizer_quality',
			'cf_media_optimizer_rewrite',
			'cf_media_optimizer_queue_state',
			'cf_media_optimizer_backfill_done',
		);
		foreach ( $sentinels as $opt ) {
			if ( false !== get_option( $opt, false ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Verify host requirements at activation. Returns null when the host is
	 * fit to run the plugin, or an HTML-safe error message. Pure function — no
	 * side effects — so the activation hook can wrap it in deactivate_plugins()
	 * + wp_die() and tests can exercise every branch without the WP runtime.
	 *
	 * Checks: PHP >= 8.1 and at least one WebP encoder (Imagick-WEBP or
	 * GD-imagewebp). AVIF is intentionally NOT required — it's an optional
	 * second variant on top of WebP.
	 */
	public static function check_requirements(): ?string {
		if ( PHP_VERSION_ID < 80100 ) {
			return sprintf(
				/* translators: 1: required PHP version 2: detected PHP version */
				__( 'CF Media Optimizer requires PHP %1$s or newer. This site is running PHP %2$s. Ask your host to upgrade PHP, or contact support.', 'cf-media-optimizer' ),
				'8.1',
				PHP_VERSION
			);
		}

		if ( ! Converter::webp_supported() ) {
			return __(
				'CF Media Optimizer requires either the Imagick extension (with the WEBP coder) or the GD extension (with imagewebp()) to be available in PHP. Neither was detected on this host. Ask your host to enable Imagick or rebuild GD with WebP support.',
				'cf-media-optimizer'
			);
		}

		return null;
	}
}
