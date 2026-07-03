<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Option-name constants and server-side hard caps.
 *
 * Hard caps protect against malicious or buggy clients that bypass the UI's
 * own validation. The UI typically enforces tighter limits.
 */
final class Options {

	const QUALITY             = 'cf_media_manager_quality';         // int 1-100
	const REWRITE             = 'cf_media_manager_rewrite';         // bool — master toggle for HTML rewriting
	const SCOPE               = 'cf_media_manager_rewrite_scope';   // 'all' | 'guests'
	const FILTER_MODE         = 'cf_media_manager_filter_mode';     // 'none' | 'blacklist' | 'whitelist'
	const FILTER_PATTERNS     = 'cf_media_manager_filter_patterns'; // newline-separated URL path patterns
	const BATCH_SIZE          = 'cf_media_manager_batch_size';      // int 1-25 — attachments per AJAX tick AND per cron tick
	const PURGE_FLAG          = 'cf_media_manager_pending_purge';   // unix timestamp — set after conversion
	const ENABLE_AVIF         = 'cf_media_manager_enable_avif';     // bool — produce .avif alongside .webp
	const REWRITE_FAVICONS    = 'cf_media_manager_rewrite_favicons'; // bool — rewrite favicon/touch-icon <link> hrefs to .webp (off by default; iOS does not honor .webp for apple-touch-icon)
	const ALT_FALLBACK        = 'cf_media_manager_alt_fallback';    // bool — at render time fill empty/missing <img> alt from the attachment's _wp_attachment_image_alt (on by default; rides the rewrite output pass)
	const MAX_SOURCE_MB       = 'cf_media_manager_max_source_mb';   // int — admin-configurable source filesize cap in MB (clamped to [1, HARD_MAX_SOURCE_MB])
	const QUEUE_STATE         = 'cf_media_manager_queue_state';     // background queue progress + ids
	const QUEUE_LOCK          = 'cf_media_manager_queue_lock';      // option (autoload=false) — atomic add_option lock for process_chunk; carries token+expires struct
	const BACKFILL_DONE       = 'cf_media_manager_backfill_done';   // bool — admin completed the legacy-variant backfill
	const BACKFILL_LOCK       = 'cf_media_manager_backfill_lock';   // transient — in-flight backfill marker, prevents parallel runs
	const MIGRATION_OPTIMIZER_V1 = 'cf_media_manager_migration_optimizer_v1'; // bool — one-time migration marker; set after repairing the BACKFILL_DONE false-positive on cf-media-optimizer upgrades
	const BACKFILL_LOCK_TTL   = 600;                                // seconds — TTL refreshed per chunk; auto-expires if a run dies
	const LAST_BUILDER_FP     = 'cf_media_manager_last_builder_fp'; // string — last-seen InUseScanner builder fingerprint, used to skip the wildcard transient delete when no builder set changed
	const DELETE_ON_UNINSTALL = 'cf_media_manager_delete_variants_on_uninstall'; // bool — delete owned variants on plugin uninstall
	const AUDIT_IGNORED_PATHS = 'cf_media_manager_audit_ignored_paths';          // array<string,array{reports:array<string,array>}> — ignored orphan-file paths keyed by upload-relative path. Reports keyed by report id; each entry holds per-report meta (ignored_at, etc.)
	const AUDIT_STALE_SINCE   = 'cf_media_manager_audit_stale_since';            // int — unix timestamp of the most recent media-library mutation. Compared against each report's results' scanned_at to flag cards as stale. Autoload=yes (read on every dashboard render).
	const IN_USE_WARM_WANTED  = 'cf_media_manager_in_use_warm_wanted';           // int — unix ts of the last in-use-backed report read. While recent, cache invalidations schedule a background InUseScanner rescan (cron/Action Scheduler) so the next audit reads a warm cache instead of blocking. Sites that never open the audit reports never pay for background scans.

	const DEFAULT_QUALITY = 80;
	const DEFAULT_BATCH   = 1;

	const MAX_BATCH_IDS    = 100;            // ids per AJAX call
	const MAX_QUEUE_IDS    = 50000;          // hard ceiling on a single queue start (defense vs UI bypass)
	const MAX_PIXELS       = 25_000_000;     // ~5000×5000 — guard against decompression bombs and memory exhaustion

	// Source filesize cap. The active value is the admin-configurable
	// `MAX_SOURCE_MB` option, clamped to [1, HARD_MAX_SOURCE_MB] by
	// `resolve_max_source_bytes()`. The cap exists to bound decoder memory
	// allocation per request — letting it climb arbitrarily would let any
	// upload OOM a PHP-FPM worker. 200 MB is the highest value we accept;
	// pair it with an `image` memory_limit of 512 MB or more at the host.
	const DEFAULT_MAX_SOURCE_MB = 50;
	const HARD_MAX_SOURCE_MB    = 200;
	const MAX_PATTERNS_LEN = 8192;           // bytes — pattern textarea ceiling
	const VERIFY_TIMEOUT   = 10;             // seconds
	const VERIFY_MAX_BYTES = 5 * 1024 * 1024; // 5 MB — verifier response cap
	const VERIFY_MAX_HOPS  = 3;              // redirect hops the verifier will follow
	const QUEUE_CHUNK_MAX  = 25;             // server-side ceiling on attachments per cron tick
	const QUEUE_LOCK_TTL   = 300;            // seconds — process_chunk lock TTL
	const COUNTS_PAGE_SIZE = 1000;           // batch size for paginating attachment ID scans

	// Files processed per AJAX call in the legacy-variant backfill's inner
	// loop. With the pre-built lookup maps a hash-resolve + sibling-probe
	// completes in microseconds, but file_exists() on the source candidates
	// is disk-bound — keep this modest so a subtree on a slow disk still
	// returns inside max_execution_time. 1000 files / call comfortably
	// finishes in ~1-2 seconds on commodity hosts.
	const BACKFILL_CHUNK_FILES = 1000;

	/**
	 * Resolve the effective chunk size for the background queue. Reads the
	 * user-configured BATCH_SIZE option and clamps it to [1, QUEUE_CHUNK_MAX]
	 * so a hand-edited option can't OOM the cron worker by attempting 1000
	 * conversions per tick.
	 */
	public static function resolve_queue_chunk_size(): int {
		$configured = (int) get_option( self::BATCH_SIZE, self::DEFAULT_BATCH );
		return max( 1, min( self::QUEUE_CHUNK_MAX, $configured ) );
	}

	/**
	 * Resolve the active source filesize cap, in bytes. Reads the user-
	 * configured `MAX_SOURCE_MB` option and clamps to `[1, HARD_MAX_SOURCE_MB]`
	 * so a hand-edited option can't bypass the host-memory ceiling.
	 */
	public static function resolve_max_source_bytes(): int {
		$configured = (int) get_option( self::MAX_SOURCE_MB, self::DEFAULT_MAX_SOURCE_MB );
		$mb         = max( 1, min( self::HARD_MAX_SOURCE_MB, $configured ) );
		return $mb * 1024 * 1024;
	}

	/**
	 * Every option this plugin writes. Used by uninstall.php to clean up.
	 */
	public static function all(): array {
		return [
			self::QUALITY,
			self::REWRITE,
			self::SCOPE,
			self::FILTER_MODE,
			self::FILTER_PATTERNS,
			self::BATCH_SIZE,
			self::PURGE_FLAG,
			self::ENABLE_AVIF,
			self::REWRITE_FAVICONS,
			self::ALT_FALLBACK,
			self::MAX_SOURCE_MB,
			self::QUEUE_STATE,
			self::QUEUE_LOCK,
			self::BACKFILL_DONE,
			self::MIGRATION_OPTIMIZER_V1,
			self::DELETE_ON_UNINSTALL,
			self::LAST_BUILDER_FP,
			self::AUDIT_IGNORED_PATHS,
			self::AUDIT_STALE_SINCE,
			self::IN_USE_WARM_WANTED,
		];
	}

	private function __construct() {}
}
