<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

use CFShared\Media\Paths;
use CFShared\Media\InUseScanner;

/**
 * Plugin facade. Wires the management-side collaborators together and exposes
 * them through accessor methods so cli.php (and tests) can reach in without
 * re-instantiating.
 *
 * CF Media Manager 3.0.0 is the management half of the former CF Media Manager
 * 2.3.0: the Media Library list view, the audit-report engine, and the bulk
 * alt-text editor. The delivery half — WebP/AVIF conversion + <picture>
 * rewriting — ships as the separate CF Media Optimizer plugin. The two are
 * independent (each installs from its own zip) and share only the CFShared\Media
 * kernel (Paths + InUseScanner + AltMeta), bundled into each zip at release.
 */
final class Plugin {

	/**
	 * Nonce action name used by every AJAX endpoint and the admin script
	 * localization. Single source of truth — changing it here propagates to
	 * every consumer.
	 */
	const NONCE_ACTION = 'cf_media_manager';

	/**
	 * Prefix every AJAX action name shares. Register hooks build their full
	 * action string from this + an unprefixed slug.
	 */
	const AJAX_PREFIX = 'cf_media_manager_';

	private static ?self $instance = null;

	private Paths $paths;
	private InUseScanner $in_use_scanner;
	private AdminPage $admin_page;
	private AltTextManager $alt_text;
	private LibraryPage $library_page;
	private LibraryRestController $library_rest;
	private Audit\IgnoredStore $audit_ignored;
	private Audit\AuditRunner $audit_runner;
	private AuditPage $audit_page;
	private AuditAjax $audit_ajax;
	private SplitNotice $split_notice;

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
		// languages/cf-media-manager.pot is still useful for contributors.

		$dirs                 = wp_upload_dir();
		$this->paths          = new Paths( $dirs['basedir'], $dirs['baseurl'] );
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
				array( $this->in_use_scanner, 'get_for_report' )
			)
		);
		$this->audit_runner->register(
			new Audit\Reports\DuplicateOriginals(
				$this->audit_ignored,
				array( $this->in_use_scanner, 'get_for_report' )
			)
		);
		$this->audit_runner->register( new Audit\Reports\OversizedOriginals( $this->audit_ignored ) );
		$this->audit_page = new AuditPage( $this->audit_runner );
		$this->audit_ajax = new AuditAjax(
			$this->audit_runner,
			array( $this->alt_text, 'missing_count' )
		);

		$this->admin_page = new AdminPage( $this->audit_page );

		// Upgrade notice for 2.x sites that lost conversion to the split.
		// Self-limiting: never fires on a fresh 3.0.0 install.
		$this->split_notice = new SplitNotice();

		// In-use scanner — invalidates its transient on post/attachment save.
		// Registered outside the is_admin gate because attachment hooks can
		// fire during front-end uploads (REST, gallery shortcodes).
		$this->in_use_scanner->register_hooks();

		// Audit subsystem — wires staleness hooks on attachment lifecycle.
		$this->audit_runner->register_hooks();

		// Media Library list view REST endpoint. Registered outside the
		// is_admin() gate because REST requests don't always set is_admin()
		// to true.
		add_action(
			'rest_api_init',
			array( $this->library_rest, 'register_routes' )
		);

		// Admin UI + AJAX (only registered in admin context — is_admin()
		// returns true during admin-ajax / admin-post requests too, so this
		// single branch covers both the dashboard and AJAX surfaces).
		if ( is_admin() ) {
			$this->admin_page->register_hooks();
			$this->alt_text->register_hooks();
			$this->library_page->register_hooks();
			$this->audit_ajax->register_hooks();
			$this->split_notice->register_hooks();
		}
	}

	public function paths(): Paths {
		return $this->paths; }
	public function in_use_scanner(): InUseScanner {
		return $this->in_use_scanner; }
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
	public function split_notice(): SplitNotice {
		return $this->split_notice; }
	public function audit_ignored(): Audit\IgnoredStore {
		return $this->audit_ignored; }

	/**
	 * Verify host requirements at activation. Returns null when the host can
	 * run the plugin, or an HTML-safe error message. Pure function — no side
	 * effects — so the activation hook can wrap it in deactivate_plugins() +
	 * wp_die() and tests can exercise every branch without the WP runtime.
	 *
	 * The management side needs only the PHP baseline; unlike CF Media
	 * Optimizer it does not require a WebP encoder.
	 */
	public static function check_requirements(): ?string {
		if ( PHP_VERSION_ID < 80100 ) {
			return sprintf(
				/* translators: 1: required PHP version 2: detected PHP version */
				__( 'CF Media Manager requires PHP %1$s or newer. This site is running PHP %2$s. Ask your host to upgrade PHP, or contact support.', 'cf-media-manager' ),
				'8.1',
				PHP_VERSION
			);
		}

		return null;
	}
}
