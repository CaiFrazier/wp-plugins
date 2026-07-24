<?php

namespace CFMediaOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Option-name constants and server-side hard caps.
 *
 * Hard caps protect against malicious or buggy clients that bypass the UI's
 * own validation. The UI typically enforces tighter limits.
 *
 * STORAGE COMPATIBILITY: the option keys below deliberately keep the
 * `cf_media_manager_*` prefix even though this plugin is CF Media Optimizer.
 * CF Media Optimizer was split out of CF Media Manager 2.3.0, which shipped to
 * production; the conversion settings AND the per-variant ownership postmeta
 * (`_cf_media_manager_owns_*`, see {@see VariantManifest}) already live under
 * that prefix on every installed site. Renaming the keys would orphan every
 * converted image and silently drop sites back to unoptimized originals, so
 * the storage namespace stays put. Plugin identity (name, slug, text domain,
 * PHP namespace) is CF Media Optimizer; only the persisted keys are inherited.
 */
final class Options {

	const QUALITY                = 'cf_media_manager_quality';         // int 1-100
	const REWRITE                = 'cf_media_manager_rewrite';         // bool — master toggle for HTML rewriting
	const SCOPE                  = 'cf_media_manager_rewrite_scope';   // 'all' | 'guests'
	const FILTER_MODE            = 'cf_media_manager_filter_mode';     // 'none' | 'blacklist' | 'whitelist'
	const FILTER_PATTERNS        = 'cf_media_manager_filter_patterns'; // newline-separated URL path patterns
	const BATCH_SIZE             = 'cf_media_manager_batch_size';      // int 1-25 — attachments per AJAX tick AND per cron tick
	const PURGE_FLAG             = 'cf_media_manager_pending_purge';   // unix timestamp — set after conversion
	const ENABLE_AVIF            = 'cf_media_manager_enable_avif';     // bool — produce .avif alongside .webp
	const REWRITE_FAVICONS       = 'cf_media_manager_rewrite_favicons'; // bool — rewrite favicon/touch-icon <link> hrefs to .webp (off by default; iOS does not honor .webp for apple-touch-icon)
	const ALT_FALLBACK           = 'cf_media_manager_alt_fallback';    // bool — at render time fill empty/missing <img> alt from the attachment's _wp_attachment_image_alt (on by default; rides the rewrite output pass)
	const MAX_SOURCE_MB          = 'cf_media_manager_max_source_mb';   // int — admin-configurable source filesize cap in MB (clamped to [1, HARD_MAX_SOURCE_MB])
	const QUEUE_STATE            = 'cf_media_manager_queue_state';     // background queue progress + ids
	const QUEUE_LOCK             = 'cf_media_manager_queue_lock';      // option (autoload=false) — atomic add_option lock for process_chunk; carries token+expires struct
	const BACKFILL_DONE          = 'cf_media_manager_backfill_done';   // bool — admin completed the legacy-variant backfill
	const BACKFILL_LOCK          = 'cf_media_manager_backfill_lock';   // transient — in-flight backfill marker, prevents parallel runs
	const MIGRATION_OPTIMIZER_V1 = 'cf_media_manager_migration_optimizer_v1'; // bool — one-time migration marker (retained from the pre-split lineage)
	const BACKFILL_LOCK_TTL      = 600;                                // seconds — TTL refreshed per chunk; auto-expires if a run dies
	const DELETE_ON_UNINSTALL    = 'cf_media_manager_delete_variants_on_uninstall'; // bool — delete owned variants on plugin uninstall

	const DEFAULT_QUALITY = 80;
	const DEFAULT_BATCH   = 1;

	const MAX_BATCH_IDS = 100;            // ids per AJAX call
	const MAX_QUEUE_IDS = 50000;          // hard ceiling on a single queue start (defense vs UI bypass)
	const MAX_PIXELS    = 25_000_000;     // ~5000×5000 — guard against decompression bombs and memory exhaustion

	// Source filesize cap. The active value is the admin-configurable
	// `MAX_SOURCE_MB` option, clamped to [1, HARD_MAX_SOURCE_MB] by
	// `resolve_max_source_bytes()`. The cap exists to bound decoder memory
	// allocation per request — letting it climb arbitrarily would let any
	// upload OOM a PHP-FPM worker. 200 MB is the highest value we accept;
	// pair it with an `image` memory_limit of 512 MB or more at the host.
	const DEFAULT_MAX_SOURCE_MB = 50;
	const HARD_MAX_SOURCE_MB    = 200;
	const MAX_PATTERNS_LEN      = 8192;           // bytes — pattern textarea ceiling
	const VERIFY_TIMEOUT        = 10;             // seconds
	const VERIFY_MAX_BYTES      = 5 * 1024 * 1024; // 5 MB — verifier response cap
	const VERIFY_MAX_HOPS       = 3;              // redirect hops the verifier will follow
	const QUEUE_CHUNK_MAX       = 25;             // server-side ceiling on attachments per cron tick
	const QUEUE_LOCK_TTL        = 300;            // seconds — process_chunk lock TTL
	const COUNTS_PAGE_SIZE      = 1000;           // batch size for paginating attachment ID scans

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
	 * Audit/alt-text options (AUDIT_*) belong to CF Media Manager and are NOT
	 * removed here; the shared InUseScanner's own transient/fingerprint keys
	 * (cf_media_in_use_*) are left in place too, since CF Media Manager may
	 * still be using them.
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
		];
	}

	private function __construct() {}
}
