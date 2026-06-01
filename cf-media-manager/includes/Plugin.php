<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin facade. Wires the collaborators together and exposes them through
 * accessor methods so cli.php (and tests) can reach in without re-instantiating.
 */
final class Plugin {

	/**
	 * Nonce action name used by every AJAX endpoint, the post-conversion
	 * notice handler, and the admin script localization. Single source of
	 * truth — changing it here propagates to every consumer.
	 */
	const NONCE_ACTION = 'cf_media_manager';

	/**
	 * Prefix every AJAX action name shares. Register hooks build their
	 * full action string from this + an unprefixed slug.
	 */
	const AJAX_PREFIX = 'cf_media_manager_';

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
	private AltTextManager $alt_text;
	private LibraryPage $library_page;
	private LibraryRestController $library_rest;
	private Audit\IgnoredStore $audit_ignored;
	private Audit\AuditRunner $audit_runner;
	private AuditPage $audit_page;
	private AuditAjax $audit_ajax;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot(): void {
		// Repair BACKFILL_DONE that was prematurely set on cf-media-optimizer
		// → cf-media-manager upgrade sites. Runs once, no-ops after that.
		self::run_optimizer_migration();

		// WordPress 4.6+ auto-loads translations for plugins hosted on
		// WordPress.org based on the plugin slug. The explicit
		// load_plugin_textdomain() call is no longer needed (and is flagged
		// by Plugin Check as discouraged). The shipped `languages/cf-media-manager.pot`
		// is still useful for translation contributors and for installs that
		// distribute the plugin via channels other than WP.org.

		$dirs                 = wp_upload_dir();
		$this->paths          = new Paths( $dirs['basedir'], $dirs['baseurl'] );
		$this->manifest       = new VariantManifest( $this->paths );
		$this->converter      = new Converter( $this->paths, $this->manifest );
		$this->rewriter       = new Rewriter( $this->paths, $this->manifest );
		$this->cache_purger   = new CachePurger();
		$this->queue          = new Queue( $this->converter );
		$this->in_use_scanner = new InUseScanner( $this->paths );
		$this->alt_text       = new AltTextManager( $this->in_use_scanner );
		$this->library_page   = new LibraryPage();
		$this->library_rest   = new LibraryRestController();

		// Audit subsystem. Build the runner + reports before constructing
		// AdminPage so AdminPage can delegate the Audit tab to AuditPage.
		$this->audit_ignored = new Audit\IgnoredStore();
		$this->audit_runner  = new Audit\AuditRunner();
		$this->audit_runner->register( new Audit\Reports\GhostAttachments( $this->audit_ignored ) );
		$this->audit_runner->register( new Audit\Reports\OrphanFiles( $this->audit_ignored, $this->paths ) );
		$this->audit_runner->register(
			new Audit\Reports\UnusedAttachments(
				$this->audit_ignored,
				array( $this->in_use_scanner, 'get' )
			)
		);
		$this->audit_runner->register(
			new Audit\Reports\DuplicateOriginals(
				$this->audit_ignored,
				array( $this->in_use_scanner, 'get' )
			)
		);
		$this->audit_runner->register( new Audit\Reports\OversizedOriginals( $this->audit_ignored ) );
		$this->audit_page    = new AuditPage( $this->audit_runner );
		$this->audit_ajax    = new AuditAjax(
			$this->audit_runner,
			array( $this->alt_text, 'missing_count' )
		);

		$this->admin_page    = new AdminPage( $this->converter, $this->audit_page );
		$this->ajax          = new Ajax( $this->paths, $this->converter, $this->rewriter, $this->cache_purger, $this->queue, $this->in_use_scanner, $this->manifest );

		// Front-end rewriting + SEO filters.
		$this->rewriter->register_hooks();

		// Convert every size after WP generates thumbnails on upload.
		add_filter( 'wp_generate_attachment_metadata', array( $this->converter, 'on_upload' ), 20, 2 );

		// Background queue.
		$this->queue->register_hooks();

		// In-use scanner — invalidates its transient on post/attachment save.
		$this->in_use_scanner->register_hooks();

		// Audit subsystem — wires staleness hooks on attachment lifecycle.
		// Registered outside the is_admin gate because attachment hooks
		// can fire during front-end uploads (REST, gallery shortcodes).
		$this->audit_runner->register_hooks();

		// Media Library list view REST endpoint. Registered outside the
		// is_admin() gate because REST requests don't always set is_admin()
		// to true (they do during /wp-admin/admin-ajax.php fallbacks but
		// not on the REST URL itself).
		add_action(
			'rest_api_init',
			array( $this->library_rest, 'register_routes' )
		);

		// Admin UI + AJAX (only registered in admin context — saves cycles
		// on every public-facing request). is_admin() returns true during
		// admin-ajax / admin-post requests too, so this single branch
		// covers both the dashboard and AJAX surfaces.
		if ( is_admin() ) {
			$this->admin_page->register_hooks();
			$this->ajax->register_hooks();
			$this->alt_text->register_hooks();
			$this->library_page->register_hooks();
			$this->audit_ajax->register_hooks();
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
	public function alt_text(): AltTextManager {
		return $this->alt_text; }
	public function library_page(): LibraryPage {
		return $this->library_page; }
	public function library_rest(): LibraryRestController {
		return $this->library_rest; }
	public function audit_runner(): Audit\AuditRunner {
		return $this->audit_runner; }
	public function audit_page(): AuditPage {
		return $this->audit_page; }
	public function audit_ajax(): AuditAjax {
		return $this->audit_ajax; }
	public function audit_ignored(): Audit\IgnoredStore {
		return $this->audit_ignored; }

	/**
	 * Verify host requirements at activation. Returns null when the host is
	 * fit to run the plugin, or an HTML-safe error message describing what's
	 * missing. Pure function — no side effects, so the registered activation
	 * hook can wrap this in deactivate_plugins() + wp_die() and tests can
	 * exercise every branch without touching the WP runtime.
	 *
	 * Checks: PHP >= 8.0 (matches the plugin header; WP core does not enforce
	 * the Requires PHP header for a zip dropped straight into wp-content), and
	 * at least one WebP encoder (Imagick-WEBP or GD-imagewebp). AVIF is
	 * intentionally NOT required — it's an optional second variant on top of WebP.
	 */
	/**
	 * Activation-time install routine. Run once when the plugin is
	 * activated, AFTER {@see check_requirements()} has confirmed the
	 * host can run us.
	 *
	 * Currently:
	 *   - **Fresh-install BACKFILL_DONE setter.** Pre-1.2.2 installs
	 *     stored the variant manifest as a serialized array under one
	 *     postmeta key; 1.2.2+ stores one row per variant under a
	 *     hashed key. The render path's `VariantManifest::is_owned()`
	 *     keeps a fallback `LIKE` scan over the legacy serialized
	 *     value, gated on `! get_option( BACKFILL_DONE )`. That gate
	 *     is set when an admin completes a backfill run — which is
	 *     correct for upgrade-from-1.x sites, but means greenfield
	 *     sites that never click Adopt pay the legacy-scan cost on
	 *     every render miss.
	 *
	 *     Fresh installs are detected as "none of our options exist
	 *     yet". When detected, BACKFILL_DONE is set immediately with
	 *     autoload=true so the gate short-circuits on every request
	 *     with zero extra DB queries.
	 */
	public static function run_install(): void {
		if ( self::is_fresh_install() ) {
			// autoload=true is intentional — this option is read on
			// every render-path manifest miss, and a yes-autoload
			// option costs zero extra DB queries (it ships in the
			// alloptions blob loaded once per request).
			add_option( Options::BACKFILL_DONE, 1, '', 'yes' );
		}
	}

	/**
	 * True when no plugin-owned options exist in the database yet.
	 * Used by `run_install()` to discriminate fresh installs from
	 * upgrades — upgrades from a pre-1.2.2 install have legacy
	 * variant manifest data that the render path still needs to scan,
	 * so we MUST NOT auto-set BACKFILL_DONE for them.
	 */
	private static function is_fresh_install(): bool {
		// Sentinel options that any 1.x install would have written
		// at least once (settings the admin probably configured, or
		// state our code wrote on first use). If NONE of them exist,
		// we're confident this is a brand-new install.
		//
		// The pre-rename prefix (cf_media_optimizer_*) is included so
		// sites upgrading from that version are not mistakenly detected
		// as fresh installs — they may have legacy variant manifest data
		// that the Adopt flow still needs to claim.
		$sentinels = array(
			Options::QUALITY,
			Options::REWRITE,
			Options::QUEUE_STATE,
			Options::BACKFILL_DONE,
			// Legacy option names from the cf-media-optimizer era (May 2026).
			'cf_media_optimizer_quality',
			'cf_media_optimizer_rewrite',
			'cf_media_optimizer_queue_state',
			'cf_media_optimizer_backfill_done',
		);
		foreach ( $sentinels as $opt ) {
			// `false === get_option( $opt, false )` is the "option
			// doesn't exist" idiom (the default itself is false).
			// We want "option row exists" so use the no-default form
			// + is-not-false check.
			if ( false !== get_option( $opt, false ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One-time migration for sites that activated cf-media-manager while
	 * the database still had cf-media-optimizer options. `is_fresh_install()`
	 * previously only checked for cf_media_manager_* keys, so optimizer→manager
	 * upgrades were misdetected as fresh installs and had BACKFILL_DONE set
	 * prematurely, hiding the Adopt button. Clears that flag so the button
	 * re-appears on affected sites.
	 *
	 * Guarded by Options::MIGRATION_OPTIMIZER_V1 (autoloaded). After it runs
	 * once the option ships in the alloptions blob, making every subsequent
	 * boot a fast cache-hit with no extra DB work.
	 *
	 * Exposed as public static so it can be exercised directly in PluginTest.
	 */
	public static function run_optimizer_migration(): void {
		if ( get_option( Options::MIGRATION_OPTIMIZER_V1, false ) ) {
			return;
		}
		// Mark migration done first — prevents a retry loop if a fatal fires
		// partway through.
		add_option( Options::MIGRATION_OPTIMIZER_V1, 1, '', 'yes' );

		// Was this an upgrade from cf-media-optimizer? Any of its core
		// options being present is sufficient evidence.
		$had_optimizer =
			false !== get_option( 'cf_media_optimizer_quality', false ) ||
			false !== get_option( 'cf_media_optimizer_rewrite', false );

		if ( ! $had_optimizer ) {
			return;
		}

		// The optimizer was installed. If BACKFILL_DONE is set it was almost
		// certainly written by the false fresh-install detection (because the
		// Adopt button was hidden immediately after activation). Clear it so
		// the button re-appears. Worst case: a user who somehow ran adopt via
		// WP-CLI after activation sees the button once more and clicks it —
		// that re-run is a safe no-op (0 files claimed).
		delete_option( Options::BACKFILL_DONE );
	}

	public static function check_requirements(): ?string {
		if ( PHP_VERSION_ID < 80000 ) {
			return sprintf(
				/* translators: 1: required PHP version 2: detected PHP version */
				__( 'CF Media Manager requires PHP %1$s or newer. This site is running PHP %2$s. Ask your host to upgrade PHP, or contact support.', 'cf-media-manager' ),
				'8.0',
				PHP_VERSION
			);
		}

		if ( ! Converter::webp_supported() ) {
			return __(
				'CF Media Manager requires either the Imagick extension (with the WEBP coder) or the GD extension (with imagewebp()) to be available in PHP. Neither was detected on this host. Ask your host to enable Imagick or rebuild GD with WebP support.',
				'cf-media-manager'
			);
		}

		return null;
	}
}
