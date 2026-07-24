<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Option-name constants for the management side (Library list view, audit
 * reports, bulk alt-text).
 *
 * CF Media Manager 3.0.0 is the management half of the former 2.3.0 bundle; the
 * delivery half (conversion + rewriting) moved to CF Media Optimizer along with
 * its option keys. Only the audit-report bookkeeping keys live here now. The
 * shared image-usage scanner's own state (cf_media_in_use_*) lives in the
 * CFShared\Media\InUseScanner kernel, not here, so a co-installed CF Media
 * Optimizer shares one warm cache.
 */
final class Options {

	const AUDIT_IGNORED_PATHS = 'cf_media_manager_audit_ignored_paths';          // array<string,array{reports:array<string,array>}> — ignored orphan-file paths keyed by upload-relative path. Reports keyed by report id; each entry holds per-report meta (ignored_at, etc.)
	const AUDIT_STALE_SINCE   = 'cf_media_manager_audit_stale_since';            // int — unix timestamp of the most recent media-library mutation. Compared against each report's results' scanned_at to flag cards as stale. Autoload=yes (read on every dashboard render).

	/**
	 * Every option this plugin writes. Used by uninstall.php to clean up.
	 * The decorative-image alt flag (_cf_media_manager_decorative postmeta) is
	 * intentionally NOT removed on uninstall — it's a shared dataset a
	 * co-installed CF Media Optimizer reads for its render-time alt fallback.
	 */
	public static function all(): array {
		return [
			self::AUDIT_IGNORED_PATHS,
			self::AUDIT_STALE_SINCE,
		];
	}

	private function __construct() {}
}
