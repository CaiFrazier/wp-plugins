<?php

namespace CFMediaOptimizer;

defined( 'ABSPATH' ) || exit;

use CFShared\Media\Paths;
use CFShared\Media\InUseScanner;

/**
 * AJAX endpoint handlers.
 *
 * Every endpoint's first line is `$this->authorize()`, which runs
 * `check_ajax_referer( 'cf_media_manager', 'nonce' )` plus a `manage_options`
 * capability check before any `$_POST` access. PHPCS can't trace the
 * indirection through `authorize()`, so the file disables the
 * NonceVerification rule wholesale — re-enable only if you remove the
 * authorize() call (you shouldn't).
 *
 * The Ajax class is the only public surface for browser-driven actions,
 * which keeps the security review tight. No `_nopriv` variants are
 * registered — there is no anonymous use-case.
 */
// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- authorize() runs check_ajax_referer for every endpoint.
final class Ajax {

	private Paths $paths;
	private Converter $converter;
	private Rewriter $rewriter;
	private CachePurger $cache_purger;
	private Queue $queue;
	private InUseScanner $in_use_scanner;
	private VariantManifest $manifest;

	public function __construct(
		Paths $paths,
		Converter $converter,
		Rewriter $rewriter,
		CachePurger $cache_purger,
		Queue $queue,
		InUseScanner $in_use_scanner,
		?VariantManifest $manifest = null
	) {
		$this->paths          = $paths;
		$this->converter      = $converter;
		$this->rewriter       = $rewriter;
		$this->cache_purger   = $cache_purger;
		$this->queue          = $queue;
		$this->in_use_scanner = $in_use_scanner;
		$this->manifest       = $manifest ?? $converter->manifest();
	}

	public function register_hooks(): void {
		// Slugs are appended to Plugin::AJAX_PREFIX to form the actual
		// AJAX action names. Keeps the prefix in one place so a future
		// rename only edits the constant.
		$endpoints = array(
			'status'            => 'status',
			'convert_batch'     => 'convert_batch',
			'save_quality'      => 'save_quality',
			'save_settings'     => 'save_settings',
			'delete_all'        => 'delete_all',
			'count_variants'    => 'count_variants',
			'attachment_status' => 'attachment_status',
			'dismiss_purge'     => 'dismiss_purge',
			'verify_url'        => 'verify_url',
			'purge_caches'      => 'purge_caches',
			'detect_caches'     => 'detect_caches',
			'queue_start'       => 'queue_start',
			'queue_status'      => 'queue_status',
			'queue_cancel'      => 'queue_cancel',
			'queue_clear'       => 'queue_clear',
			'in_use_scan'       => 'in_use_scan',
			'dismiss_explainer' => 'dismiss_explainer',
			'backfill_manifest' => 'backfill_manifest',
			'diagnose_variant'  => 'diagnose_variant',
			'claim_variant'     => 'claim_variant',
			'delete_conflicting_variant' => 'delete_conflicting_variant',
		);
		foreach ( $endpoints as $slug => $method ) {
			add_action( 'wp_ajax_' . Plugin::AJAX_PREFIX . $slug, array( $this, $method ) );
		}
	}

	/**
	 * Common gate: nonce + capability. Delegates to {@see Security::authorize_ajax()}
	 * so the implementation is in one place — see that method's docblock for
	 * the rationale.
	 */
	private function authorize(): void {
		Security::authorize_ajax();
	}

	/**
	 * Network-scope gate for endpoints with cross-site blast radius. Single-
	 * site behavior is identical to {@see authorize()}; on multisite the
	 * required capability is raised to `manage_network_options`. Used by
	 * destructive or installation-wide actions: variant counts/deletes/
	 * backfill, batch conversion, queue start, settings writes, and the
	 * cache purger (which crosses site boundaries via object cache flushes,
	 * WP Engine memcached, Cloudflare zone).
	 *
	 * See {@see Security::authorize_ajax_network()} for the rationale.
	 */
	private function authorize_network(): void {
		Security::authorize_ajax_network( Plugin::NONCE_ACTION );
	}

	// ====================================================================
	// Status
	// ====================================================================

	public function status(): void {
		$this->authorize();

		// Fresh data is the whole point of this endpoint — admins look at it
		// right after uploading. Bypass every cache layer.
		nocache_headers();
		clearstatcache();
		$this->rewriter->reset_exists_cache();

		[ $total, $done, $pending, $all ] = $this->get_conversion_counts();

		wp_send_json_success(
			array(
				'total'   => $total,
				'done'    => $done,
				'pending' => $pending,
				'all'     => $all,
			)
		);
	}

	// ====================================================================
	// Synchronous batch conversion (foreground AJAX runner)
	// ====================================================================

	public function convert_batch(): void {
		$this->authorize_network();

		$ids   = Request::post_id_list( 'ids', Options::MAX_BATCH_IDS );
		$force = Request::post_bool( 'force' );

		// Disk-space pre-check: refuse to start if the heuristic says we'd
		// fill the volume. disk_free_space() unavailable on the host falls
		// through (returns null) so we don't block legitimate work.
		$include_avif = Converter::avif_supported() && (bool) get_option( Options::ENABLE_AVIF, true );
		$required     = Disk::estimate_required_space( $ids, $include_avif, $this->paths );
		$error        = Disk::check_sufficient_space( $this->paths->upload_dir(), $required );
		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ), 507 );
		}

		$results         = array();
		$bytes_saved     = 0;
		$gd_fallbacks    = 0;
		$avif_written    = 0;
		$converted_total = 0;

		foreach ( $ids as $id ) {
			[ $converted, $failed, $bytes, $gd, $avif, $reasons ] = $this->converter->convert_attachment( (int) $id, $force );

			$bytes_saved     += $bytes;
			$gd_fallbacks    += $gd;
			$avif_written    += $avif;
			$converted_total += $converted;

			$file           = get_attached_file( (int) $id );
			$results[ $id ] = array(
				'status'      => $converted > 0 && $failed === 0 ? 'ok' : ( $converted === 0 ? 'skip' : 'partial' ),
				'file'        => $file ? basename( $file ) : '',
				'converted'   => $converted,
				'failed'      => $failed,
				'bytes_saved' => $bytes,
				'reasons'     => array_values( (array) $reasons ),
			);
		}

		// Set the purge flag whenever ANY conversion landed, not only when
		// bytes_saved > 0 — a re-encode that lands the same byte count, or a
		// forced re-encode at a different quality, still changes what the
		// front end serves and admins need the purge prompt. Mirrors
		// Queue::process_chunk's condition.
		if ( $bytes_saved > 0 || $converted_total > 0 ) {
			update_option( Options::PURGE_FLAG, time() );
		}

		wp_send_json_success(
			array(
				'results'      => $results,
				'bytes_saved'  => $bytes_saved,
				'gd_fallbacks' => $gd_fallbacks,
				'avif_written' => $avif_written,
			)
		);
	}

	// ====================================================================
	// Background queue
	// ====================================================================

	public function queue_start(): void {
		$this->authorize_network();

		$force = Request::post_bool( 'force' );
		$mode  = Request::post_key( 'mode', 'pending' );

		if ( $mode === 'selected' ) {
			// Queue start with mode=selected uses the larger MAX_QUEUE_IDS
			// cap (50K) instead of the per-AJAX-call MAX_BATCH_IDS (100):
			// the queue is the path admins use when they DO need to push
			// thousands of attachments through. Hostile or buggy clients
			// are still bounded by MAX_QUEUE_IDS.
			$ids = Request::post_id_list( 'ids', Options::MAX_QUEUE_IDS );
		} elseif ( $mode === 'in_use' ) {
			$ids = $this->filter_ids_by_pending( $this->in_use_scanner->get()['ids'] ?? array(), $force );
		} else {
			[ , , $pending, $all ] = $this->get_conversion_counts();
			$ids                   = $force ? $all : $pending;
		}

		// Cap the id list BEFORE we ask Disk::estimate_required_space() to walk
		// it. A 300K-attachment library should not get 300K filesize() calls
		// just to find out it'll be truncated to MAX_QUEUE_IDS downstream.
		if ( count( $ids ) > Options::MAX_QUEUE_IDS ) {
			$ids = array_slice( array_values( array_unique( array_map( 'intval', $ids ) ) ), 0, Options::MAX_QUEUE_IDS );
		}

		// Same disk-space pre-check applied to foreground batches. Queue
		// runs across many cron ticks so the user can't observe ENOSPC the
		// way they would on a synchronous batch — the pre-check is more
		// important here, not less.
		$include_avif = Converter::avif_supported() && (bool) get_option( Options::ENABLE_AVIF, true );
		$required     = Disk::estimate_required_space( $ids, $include_avif, $this->paths );
		$error        = Disk::check_sufficient_space( $this->paths->upload_dir(), $required );
		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ), 507 );
		}

		$state = $this->queue->start( $ids, $force );

		wp_send_json_success( array( 'state' => $state ) );
	}

	public function queue_status(): void {
		$this->authorize();
		wp_send_json_success( array( 'state' => $this->queue->state() ) );
	}

	public function queue_cancel(): void {
		$this->authorize();
		$this->queue->cancel();
		wp_send_json_success( array( 'state' => $this->queue->state() ) );
	}

	public function queue_clear(): void {
		$this->authorize();
		$this->queue->clear_finished();
		wp_send_json_success();
	}

	// ====================================================================
	// In-use scan (front-end-referenced attachments only)
	// ====================================================================

	/**
	 * Run the in-use scanner and return:
	 *   - the full set of referenced IDs
	 *   - the subset still pending conversion (or all of them when force=1)
	 *   - per-source breakdown + active builder flags (for the UI explainer)
	 *   - scan timestamp
	 *
	 * POST refresh=1 forces a fresh scan, bypassing the cached transient.
	 */
	public function in_use_scan(): void {
		$this->authorize();

		$force_refresh = Request::post_bool( 'refresh' );
		$force_convert = Request::post_bool( 'force' );

		// get( true ) bypasses the transient AND persists the new result,
		// so a subsequent queue_start with mode=in_use sees the fresh ids.
		$scan        = $this->in_use_scanner->get( $force_refresh );
		$ids         = isset( $scan['ids'] ) && is_array( $scan['ids'] ) ? array_map( 'intval', $scan['ids'] ) : array();
		$pending_ids = $this->filter_ids_by_pending( $ids, $force_convert );

		wp_send_json_success(
			array(
				'ids'         => $ids,
				'pending_ids' => $pending_ids,
				'total'       => count( $ids ),
				'pending'     => count( $pending_ids ),
				'sources'     => $scan['sources'] ?? array(),
				'builders'    => $scan['builders'] ?? array(),
				'scanned_at'  => $scan['scanned_at'] ?? 0,
			)
		);
	}

	// ====================================================================
	// Settings + quality
	// ====================================================================

	public function save_quality(): void {
		// Network gate: this writes a per-site option that controls
		// encoder quality across the whole site, same blast radius as
		// save_settings. A site-admin on subsite B should not be able to
		// influence subsite A's stored quality.
		$this->authorize_network();
		$q = max( 1, min( 100, Request::post_int( 'quality', Options::DEFAULT_QUALITY ) ) );
		update_option( Options::QUALITY, $q );
		wp_send_json_success( array( 'quality' => $q ) );
	}

	public function save_settings(): void {
		$this->authorize_network();

		$rewrite          = Request::post_bool( 'rewrite' );
		$enable_avif      = Request::post_bool( 'enable_avif' );
		$rewrite_favicons = Request::post_bool( 'rewrite_favicons' );
		$alt_fallback     = Request::post_bool( 'alt_fallback' );
		$max_source_mb    = max( 1, min( Options::HARD_MAX_SOURCE_MB, Request::post_int( 'max_source_mb', Options::DEFAULT_MAX_SOURCE_MB ) ) );
		$scope_in   = Request::post_key( 'scope' );
		$filter_in  = Request::post_key( 'filter_mode' );
		$scope      = in_array( $scope_in, array( 'all', 'guests' ), true ) ? $scope_in : 'all';
		$filter     = in_array( $filter_in, array( 'none', 'blacklist', 'whitelist' ), true ) ? $filter_in : 'none';
		$patterns   = Request::post_textarea( 'filter_patterns' );
		if ( strlen( $patterns ) > Options::MAX_PATTERNS_LEN ) {
			$patterns = substr( $patterns, 0, Options::MAX_PATTERNS_LEN );
		}
		$batch_size          = max( 1, min( 25, Request::post_int( 'batch_size', Options::DEFAULT_BATCH ) ) );
		$delete_on_uninstall = Request::post_bool( 'delete_on_uninstall' );

		update_option( Options::REWRITE, $rewrite );
		update_option( Options::ENABLE_AVIF, $enable_avif );
		update_option( Options::REWRITE_FAVICONS, $rewrite_favicons );
		update_option( Options::ALT_FALLBACK, $alt_fallback );
		update_option( Options::MAX_SOURCE_MB, $max_source_mb );
		update_option( Options::SCOPE, $scope );
		update_option( Options::FILTER_MODE, $filter );
		update_option( Options::FILTER_PATTERNS, $patterns );
		update_option( Options::BATCH_SIZE, $batch_size );
		update_option( Options::DELETE_ON_UNINSTALL, $delete_on_uninstall );

		wp_send_json_success( array( 'batch_size' => $batch_size ) );
	}

	// ====================================================================
	// Counts / deletion
	// ====================================================================

	/**
	 * Incremental variant counter.
	 *
	 * The previous full-scan implementation walked the entire uploads tree in
	 * one synchronous AJAX call and OOM'd on 100K+ attachment sites. This
	 * version processes one top-level subtree per call and returns a cursor
	 * so the client can poll until done.
	 *
	 * Client contract:
	 *   POST cursor=''       -> first call, scans first subtree, returns next_cursor
	 *   POST cursor='2025'   -> resumes from "2025" subtree
	 *   response.next_cursor -> name of next subtree, or null = done
	 *   response.partial     -> counts/bytes from THIS chunk only; client accumulates
	 */
	public function count_variants(): void {
		$this->authorize_network();

		$cursor   = Request::post_string( 'cursor' );
		$scanner  = new VariantScanner( $this->paths );
		$resolved = $scanner->next_subtree( $cursor );

		$count_webp_owned   = 0;
		$count_avif_owned   = 0;
		$count_webp_foreign = 0;
		$count_avif_foreign = 0;
		$bytes_owned        = 0;
		$bytes_foreign      = 0;

		if ( null !== $resolved['subtree'] ) {
			$owned_set     = $this->manifest->build_owned_paths_set();
			$non_recursive = ( $resolved['scope'] ?? 'subtree' ) === 'root';
			foreach ( $scanner->walk_variants( $resolved['subtree'], $owned_set, $non_recursive ) as $entry ) {
				$owned = ! empty( $entry['owned'] );
				if ( 'webp' === $entry['ext'] ) {
					$owned ? $count_webp_owned++ : $count_webp_foreign++;
				} else {
					$owned ? $count_avif_owned++ : $count_avif_foreign++;
				}
				if ( $owned ) {
					$bytes_owned += $entry['size'];
				} else {
					$bytes_foreign += $entry['size'];
				}
			}
		}

		wp_send_json_success(
			array(
				'next_cursor' => $resolved['next_cursor'],
				'progress'    => $resolved['progress'],
				'partial'     => array(
					// Owned-only counters drive the default Delete All flow.
					'count_webp'    => $count_webp_owned,
					'count_avif'    => $count_avif_owned,
					'count'         => $count_webp_owned + $count_avif_owned,
					'bytes'         => $bytes_owned,
					// Foreign counters surface untracked files in the UI so admins
					// know there are user-uploaded variants the plugin won't touch.
					'foreign_webp'  => $count_webp_foreign,
					'foreign_avif'  => $count_avif_foreign,
					'foreign'       => $count_webp_foreign + $count_avif_foreign,
					'foreign_bytes' => $bytes_foreign,
				),
			)
		);
	}

	/**
	 * Incremental deletion. Same cursor protocol as count_variants — client
	 * polls until next_cursor is null. Containment + variant-has-source
	 * checks are applied per file so we never unlink outside uploads or
	 * delete a variant whose source has been moved.
	 *
	 * Only files claimed by the variant manifest are deleted. Variant-shaped
	 * files we did not write (`logo.webp` uploaded directly by the user) are
	 * left in place and reported as `skipped_foreign`. Admins who want them
	 * gone can run the backfill action first to claim them, or remove them
	 * manually.
	 */
	public function delete_all(): void {
		$this->authorize_network();

		$cursor   = Request::post_string( 'cursor' );
		$scanner  = new VariantScanner( $this->paths );
		$resolved = $scanner->next_subtree( $cursor );

		$deleted         = 0;
		$errors          = 0;
		$skipped_foreign = 0;

		if ( null !== $resolved['subtree'] ) {
			$owned_set     = $this->manifest->build_owned_paths_set();
			$non_recursive = ( $resolved['scope'] ?? 'subtree' ) === 'root';
			foreach ( $scanner->walk_variants( $resolved['subtree'], $owned_set, $non_recursive ) as $entry ) {
				if ( empty( $entry['owned'] ) ) {
					++$skipped_foreign;
					continue;
				}
				// Use wp_delete_file so any plugin filtering deletes (logging,
				// quarantine, etc.) sees this. Returns void; verify with a
				// post-call file_exists(). The clearstatcache() flush is
				// required because file_exists() answers from the per-request
				// stat cache, which still says "yes" for the path we just
				// unlinked — leading to a false-positive error count.
				wp_delete_file( $entry['path'] );
				clearstatcache( true, $entry['path'] );
				if ( ! file_exists( $entry['path'] ) ) {
					$this->manifest->forget_anywhere( $entry['path'] );
					++$deleted;
				} else {
					++$errors;
				}
			}
		}

		if ( null === $resolved['next_cursor'] ) {
			// Final tick — drop the per-request existence cache so subsequent
			// page renders pick up the deletions.
			$this->rewriter->reset_exists_cache();
		}

		wp_send_json_success(
			array(
				'next_cursor' => $resolved['next_cursor'],
				'progress'    => $resolved['progress'],
				'partial'     => array(
					'deleted'         => $deleted,
					'errors'          => $errors,
					'skipped_foreign' => $skipped_foreign,
				),
			)
		);
	}

	/**
	 * One-shot backfill: claim ownership of every variant-shaped file in the
	 * uploads tree whose source still exists. For pre-1.2 installs that
	 * upgraded — without this step Delete All would leave their existing
	 * variants in place because the manifest has no record of them, and the
	 * rewriter (which is now ownership-aware) would refuse to serve them.
	 *
	 * Two modes:
	 *   - dry_run=1: walks the tree and returns counts only. Used by the UI
	 *     to show admins "we'd adopt N files; M look like real Media Library
	 *     uploads and would be skipped" before they commit.
	 *   - dry_run=0 (default): commits adoptions to the manifest.
	 *
	 * Cursor-paginated like delete_all so a 100K-attachment library doesn't
	 * stall on a single AJAX call.
	 */
	public function backfill_manifest(): void {
		$this->authorize_network();

		$cursor  = Request::post_string( 'cursor' );
		$dry_run = Request::post_bool( 'dry_run' );

		// Overlap guard for commit runs. Two admins clicking "Backfill" in
		// parallel (or one admin double-clicking) would otherwise process
		// the same subtree twice, both write the same (post_id, meta_key)
		// rows, and produce duplicate manifest entries that bloat the
		// postmeta table. The transient is set on the first request and
		// refreshed on every subsequent chunk; it auto-expires after
		// BACKFILL_LOCK_TTL seconds so a crashed run doesn't permanently
		// block backfill.
		//
		// Distinguishing "fresh start" from "continuing" via the cursor:
		//   - cursor empty + lock held → second click, reject with 409.
		//   - cursor empty + lock free → first chunk of a new run, take it.
		//   - cursor non-empty         → continuing our own run, refresh lock.
		//
		// Dry-run requests are read-only and skip the lock entirely so the
		// confirmation prompt can run during an in-flight commit.
		if ( ! $dry_run ) {
			if ( '' === $cursor && false !== get_transient( Options::BACKFILL_LOCK ) ) {
				wp_send_json_error(
					__( 'A backfill run is already in progress (or completed within the last 10 minutes). Wait for it to finish, or try again after the lock expires.', 'cf-media-optimizer' ),
					409
				);
			}
			set_transient( Options::BACKFILL_LOCK, time(), Options::BACKFILL_LOCK_TTL );
		}

		// Cursor format:
		//   ""                 → start from the beginning
		//   "<subtree>"        → continue at subtree <subtree>, offset 0
		//   "<subtree>:<off>"  → resume at subtree <subtree>, file offset <off>
		//
		// The outer cursor (subtree name) is what VariantScanner understands.
		// The inner offset is added so a single oversized subtree can be split
		// across multiple AJAX calls without blowing past max_execution_time.
		$outer_cursor = $cursor;
		$offset       = 0;
		if ( '' !== $cursor && false !== strpos( $cursor, ':' ) ) {
			list( $outer_cursor, $offset_str ) = explode( ':', $cursor, 2 );
			$offset = max( 0, (int) $offset_str );
		}

		$scanner  = new VariantScanner( $this->paths );
		$resolved = $scanner->next_subtree( $outer_cursor );

		$claimed     = 0;
		$processed   = 0;
		$total_files = 0;
		$next_cursor = null;

		if ( null !== $resolved['subtree'] ) {
			// Build the lookup maps once and the owned-pairs set once per
			// request. AttachmentLookup memoizes the maps statically, so even
			// if anything else in this request needs them they're free.
			$path_to_id     = AttachmentLookup::path_to_id();
			$size_to_parent = AttachmentLookup::size_to_parent();
			$owned_pairs    = $this->manifest->build_owned_pairs_set();
			$non_recursive  = ( $resolved['scope'] ?? 'subtree' ) === 'root';

			$result = $this->manifest->backfill_subtree_bulk(
				$resolved['subtree'],
				$path_to_id,
				$size_to_parent,
				$owned_pairs,
				$offset,
				Options::BACKFILL_CHUNK_FILES,
				$non_recursive,
				$dry_run
			);

			$claimed     = $result['claimed'];
			$processed   = $result['processed'];
			$total_files = $result['total_files'];

			if ( null !== $result['next_offset'] ) {
				// Still more files in this subtree — bake offset into the cursor.
				$next_cursor = $outer_cursor . ':' . $result['next_offset'];
			} else {
				// Subtree fully processed — advance to the next subtree (or
				// null if the scanner is done).
				$next_cursor = $resolved['next_cursor'];
			}
		}

		// Only mark the migration done on a real commit that fully completed.
		// Release the overlap lock too — without this, a fresh-start click
		// immediately after a completed run would have to wait for the
		// transient TTL to expire before it could begin.
		//
		// autoload=true on BACKFILL_DONE: the option is read on every
		// public render-path manifest miss (gates the legacy LIKE scan),
		// so it earns a slot in the alloptions blob loaded once per
		// request. update_option also accepts the autoload arg as the
		// third positional param even for existing rows on WP 4.2+.
		if ( ! $dry_run && null === $next_cursor ) {
			update_option( Options::BACKFILL_DONE, 1, true );
			delete_transient( Options::BACKFILL_LOCK );
		}

		wp_send_json_success(
			array(
				'dry_run'     => $dry_run,
				'next_cursor' => $next_cursor,
				'progress'    => $resolved['progress'] ?? array(
					'index' => 0,
					'total' => 0,
				),
				'partial'     => array(
					'claimed'     => $claimed,
					'processed'   => $processed,
					'total_files' => $total_files,
				),
			)
		);
	}

	// ====================================================================
	// Per-attachment status (selection picker)
	// ====================================================================

	public function attachment_status(): void {
		$this->authorize();

		$ids      = Request::post_id_list( 'ids', Options::MAX_BATCH_IDS );
		$statuses = array();
		foreach ( $ids as $id ) {
			$file = get_attached_file( (int) $id );
			if ( ! $file ) {
				$statuses[ $id ] = array(
					/* translators: %d: attachment ID, shown as a fallback when no filename is available. */
					'label'     => sprintf( __( 'ID %d', 'cf-media-optimizer' ), (int) $id ),
					'converted' => false,
				);
				continue;
			}
			$statuses[ $id ] = array(
				'label'     => basename( $file ),
				'converted' => $this->converter->is_attachment_converted( (int) $id ),
			);
		}
		wp_send_json_success( array( 'statuses' => $statuses ) );
	}

	// ====================================================================
	// Cache management
	// ====================================================================

	public function dismiss_purge(): void {
		$this->authorize();
		delete_option( Options::PURGE_FLAG );
		wp_send_json_success();
	}

	/**
	 * Permanently dismiss the In-use / Background-queue explainer card for
	 * the current user. Per-user (not site-wide) so different admins on the
	 * same install can each make the call.
	 */
	public function dismiss_explainer(): void {
		$this->authorize();
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, AdminPage::EXPLAINER_DISMISSED_META, 1 );
		}
		wp_send_json_success();
	}

	public function detect_caches(): void {
		$this->authorize();
		wp_send_json_success( array( 'layers' => $this->cache_purger->detect_active() ) );
	}

	/**
	 * Purge every detected cache layer. On multisite, several layers (object
	 * cache flush, WP Engine memcached, Cloudflare zone) cross site
	 * boundaries; `manage_options` is a per-site capability and is not the
	 * right gate. The network-scope gate raises the bar to
	 * `manage_network_options` on multisite. Single-site behavior is
	 * unchanged (`manage_options`).
	 */
	public function purge_caches(): void {
		$this->authorize_network();
		wp_send_json_success( array( 'results' => $this->cache_purger->purge_all() ) );
	}

	// ====================================================================
	// Live page verifier
	// ====================================================================

	public function verify_url(): void {
		$this->authorize();

		// post_string runs wp_unslash + sanitize_text_field; the explicit
		// esc_url_raw call here adds protocol restriction (no javascript:,
		// data:, etc. — only http/https). Stacked sanitization is harmless
		// on URL inputs.
		$raw = esc_url_raw( Request::post_string( 'url' ), array( 'http', 'https' ) );
		if ( ! $raw ) {
			wp_send_json_error( __( 'Missing or invalid URL.', 'cf-media-optimizer' ), 400 );
		}

		// Rebuild the URL anchored at this site's home_url(). Only the
		// path/query are taken from the user; scheme and host always come
		// from trusted site config. UrlVerifier::fetch then runs the full
		// same-host + path-policy + DNS + IP-pin defense chain.
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) ) {
			wp_send_json_error( __( 'Invalid URL.', 'cf-media-optimizer' ), 400 );
		}
		$path  = $parts['path'] ?? '/';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$url   = home_url( $path ) . $query;

		$result = UrlVerifier::fetch( $url );
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( $result['error'] ?? __( 'Fetch failed.', 'cf-media-optimizer' ), (int) ( $result['status'] ?? 502 ) );
		}
		$code = (int) $result['code'];
		$html = (string) $result['body'];

		$base = preg_quote( rtrim( $this->paths->upload_url(), '/' ), '#' );

		$webp = preg_match_all( '#' . $base . '/[^"\'>\s\)\?]+\.webp(?:\?[^"\'>\s\)]*)?#i', $html, $m_webp )
			? count( array_unique( $m_webp[0] ) ) : 0;

		$avif = preg_match_all( '#' . $base . '/[^"\'>\s\)\?]+\.avif(?:\?[^"\'>\s\)]*)?#i', $html, $m_avif )
			? count( array_unique( $m_avif[0] ) ) : 0;

		$legacy          = 0;
		$samples_legacy  = array();
		$favicon_count   = 0;
		$samples_favicon = array();
		if ( preg_match_all( '#' . $base . '/[^"\'>\s\)\?]+\.(?:jpe?g|png)(?:\?[^"\'>\s\)]*)?#i', $html, $m_legacy ) ) {
			// Legacy URLs that appear inside <picture><img> are expected fallbacks,
			// not failures. Filter them out before counting.
			$unique  = array_values( array_unique( $m_legacy[0] ) );
			$wrapped = array();
			if ( preg_match_all( '#<picture\b[^>]*>.*?</picture>#is', $html, $m_pictures ) ) {
				foreach ( $m_pictures[0] as $picture_block ) {
					if ( preg_match_all( '#' . $base . '/[^"\'>\s\)\?]+\.(?:jpe?g|png)(?:\?[^"\'>\s\)]*)?#i', $picture_block, $inner ) ) {
						foreach ( array_unique( $inner[0] ) as $u ) {
							$wrapped[ $u ] = true;
						}
					}
				}
			}
			// Favicon / touch-icon URLs are intentionally kept as PNG/ICO (iOS
			// rejects .webp for apple-touch-icon; multi-format favicon
			// declaration is still the recommended pattern). Surface them as
			// their own bucket instead of counting them as failures.
			$favicon_urls = array();
			if ( preg_match_all( Rewriter::FAVICON_LINK_REGEX, $html, $m_link_blocks ) ) {
				foreach ( $m_link_blocks[0] as $link_tag ) {
					if ( preg_match_all( '#' . $base . '/[^"\'>\s\)\?]+\.(?:jpe?g|png)(?:\?[^"\'>\s\)]*)?#i', $link_tag, $inner ) ) {
						foreach ( array_unique( $inner[0] ) as $u ) {
							$favicon_urls[ $u ] = true;
						}
					}
				}
			}
			$standalone      = array_values(
				array_filter(
					$unique,
					static function ( $u ) use ( $wrapped, $favicon_urls ) {
						return ! isset( $wrapped[ $u ] ) && ! isset( $favicon_urls[ $u ] );
					}
				)
			);
			$legacy          = count( $standalone );
			$samples_legacy  = array_slice( $standalone, 0, 5 );
			$favicon_count   = count( $favicon_urls );
			$samples_favicon = array_slice( array_keys( $favicon_urls ), 0, 5 );
		}

		$og_image  = '';
		$og_modern = false;
		if ( preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $html, $m_og ) ) {
			$og_image  = $m_og[1];
			$og_modern = (bool) preg_match( '#\.(webp|avif)(\?|$)#i', $og_image );
		}

		$total = $webp + $avif + $legacy;
		$pct   = $total > 0 ? round( ( ( $webp + $avif ) / $total ) * 100 ) : 0;

		wp_send_json_success(
			array(
				'http_code'       => $code,
				'webp_count'      => $webp,
				'avif_count'      => $avif,
				'legacy_count'    => $legacy,
				'favicon_count'   => $favicon_count,
				'percent_modern'  => $pct,
				'og_image'        => $og_image,
				'og_is_modern'    => $og_modern,
				'samples_legacy'  => $samples_legacy,
				'samples_favicon' => $samples_favicon,
			)
		);
	}

	// ====================================================================
	// Helpers
	// ====================================================================

	/**
	 * Drop IDs that are already converted (unless $force, in which case
	 * we keep everything so the queue re-encodes). Used by both the
	 * queue_start in_use branch and the in_use_scan response.
	 */
	private function filter_ids_by_pending( array $ids, bool $force ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( $force ) {
			return $ids;
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( ! $this->converter->is_attachment_converted( $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Total / done / pending / all attachment IDs for JPEG and PNG library items.
	 * Bypasses every cache layer so admins always see truth on the status
	 * endpoint right after a fresh upload.
	 *
	 * Paginated: large libraries (50K+ attachments) used to attempt a single
	 * `posts_per_page=-1` query that stalled the request and held tens of
	 * megabytes of post objects in memory before we even got to the per-id
	 * filesystem stats. We now page through 1K rows at a time.
	 */
	public function get_conversion_counts(): array {
		$total   = 0;
		$done    = 0;
		$pending = array();
		$all     = array();
		$page    = 1;
		$size    = Options::COUNTS_PAGE_SIZE;

		do {
			$ids = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_mime_type'         => array( 'image/jpeg', 'image/png' ),
					'post_status'            => 'inherit',
					'posts_per_page'         => $size,
					'paged'                  => $page,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					// suppress_filters is intentionally NOT set true (VIP rule
					// prohibits it); rely on the perf flags below to keep the
					// counts pass cheap on large libraries.
					'cache_results'          => false,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			if ( empty( $ids ) ) {
				break;
			}
			// Pre-warm the postmeta cache for this page. Each
			// is_attachment_converted() call below resolves to two
			// get_post_meta lookups (variant manifest + attached file);
			// without pre-warming that's 2 × COUNTS_PAGE_SIZE separate
			// SELECTs against postmeta. One update_meta_cache turns those
			// into a single indexed WHERE post_id IN (...) query.
			$int_ids = array_map( 'intval', $ids );
			if ( function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'post', $int_ids );
			}
			foreach ( $int_ids as $id_int ) {
				$all[]  = $id_int;
				++$total;
				if ( $this->converter->is_attachment_converted( $id_int ) ) {
					++$done;
				} else {
					$pending[] = $id_int;
				}
			}
			++$page;
			// Defensive — protect against infinite loops if a filter returns
			// the same page twice. 100M attachments is plenty of runway.
			if ( $page > 100000 ) {
				break;
			}
		} while ( count( $ids ) === $size );

		return array( $total, $done, $pending, $all );
	}

	// ====================================================================
	// Diagnostics — used to debug "unowned WebP exists in destination slot"
	// errors when an Adopt pass seemingly completed but convert still fails
	// for specific attachments.
	// ====================================================================

	/**
	 * Given an attachment ID, return a full report on the variant ownership
	 * state for that attachment: source path on disk, destination .webp/.avif
	 * paths, file existence, ownership flags, what the lookup maps say,
	 * what the adopt-guard would do. Answers "why isn't Adopt claiming this
	 * file?" without requiring SSH or DB access.
	 */
	public function diagnose_variant(): void {
		$this->authorize();

		$id = Request::post_int( 'id' );
		if ( $id <= 0 ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'cf-media-optimizer' ), 400 );
		}

		$mime = (string) get_post_mime_type( $id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: attachment mime type. */
					__( 'Attachment %s is not a JPEG or PNG.', 'cf-media-optimizer' ),
					'' === $mime ? 'unknown' : $mime
				),
				400
			);
		}

		$source_abs = (string) get_attached_file( $id );
		$source_rel = $this->paths->to_rel_or_empty( $source_abs );

		// Build the same lookup maps backfill uses.
		$path_to_id     = AttachmentLookup::path_to_id();
		$size_to_parent = AttachmentLookup::size_to_parent();

		$webp_abs = Paths::src_to_variant_path( $source_abs, 'webp' );
		$avif_abs = Paths::src_to_variant_path( $source_abs, 'avif' );

		$webp_rel = $this->paths->to_rel_or_empty( $webp_abs );
		$avif_rel = $this->paths->to_rel_or_empty( $avif_abs );

		$report = array(
			'id'                  => $id,
			'mime'                => $mime,
			'source'              => array(
				'abs_path'    => $source_abs,
				'rel_path'    => $source_rel,
				'exists'      => '' !== $source_abs && file_exists( $source_abs ),
				// What attachment ID does the lookup map say owns this source rel?
				// SHOULD equal $id. Mismatch indicates path encoding / case issues.
				'mapped_id'   => ( '' !== $source_rel && isset( $path_to_id[ $source_rel ] ) )
					? (int) $path_to_id[ $source_rel ]
					: 0,
				'in_size_map' => ( '' !== $source_rel && isset( $size_to_parent[ $source_rel ] ) )
					? (int) $size_to_parent[ $source_rel ]
					: 0,
			),
			'webp'                => array(
				'abs_path'         => $webp_abs,
				'rel_path'         => $webp_rel,
				'exists'           => '' !== $webp_abs && file_exists( $webp_abs ),
				'owned_by_this_id' => '' !== $webp_abs && $this->manifest->is_owned_by( $id, $webp_abs ),
				// Adopt-guard fires when the WebP's own rel is in path_to_id
				// (i.e. the .webp is itself a Media Library attachment). If
				// true, Adopt deliberately skipped this file.
				'is_attachment'    => ( '' !== $webp_rel && isset( $path_to_id[ $webp_rel ] ) )
					? (int) $path_to_id[ $webp_rel ]
					: 0,
			),
			'avif'                => array(
				'abs_path'         => $avif_abs,
				'rel_path'         => $avif_rel,
				'exists'           => '' !== $avif_abs && file_exists( $avif_abs ),
				'owned_by_this_id' => '' !== $avif_abs && $this->manifest->is_owned_by( $id, $avif_abs ),
				'is_attachment'    => ( '' !== $avif_rel && isset( $path_to_id[ $avif_rel ] ) )
					? (int) $path_to_id[ $avif_rel ]
					: 0,
			),
			'path_to_id_size'     => count( $path_to_id ),
			'size_to_parent_size' => count( $size_to_parent ),
		);

		// Diagnostic verdict — translate the raw flags into a one-line
		// summary the admin can act on.
		$verdict = $this->summarize_diagnosis( $report );
		$report['verdict'] = $verdict;

		wp_send_json_success( $report );
	}

	/**
	 * Targeted "claim this WebP for this attachment" action. Used when the
	 * diagnostic says the .webp exists on disk, has the right source sibling,
	 * but isn't owned. Lets the admin fix individual attachments without
	 * re-running the full Adopt pass.
	 *
	 * Safety: refuses to claim a .webp that's itself a Media Library
	 * attachment (same guard as Adopt).
	 */
	public function claim_variant(): void {
		$this->authorize_network();

		$id = Request::post_int( 'id' );
		if ( $id <= 0 ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'cf-media-optimizer' ), 400 );
		}

		$source_abs = (string) get_attached_file( $id );
		if ( '' === $source_abs || ! file_exists( $source_abs ) ) {
			wp_send_json_error( __( 'Source file not found on disk.', 'cf-media-optimizer' ), 404 );
		}

		$path_to_id = AttachmentLookup::path_to_id();

		$claimed = array();
		$skipped = array();

		foreach ( array( 'webp', 'avif' ) as $ext ) {
			$variant_abs = Paths::src_to_variant_path( $source_abs, $ext );
			if ( '' === $variant_abs || ! $this->paths->within_upload_dir( $variant_abs ) ) {
				continue;
			}
			if ( ! file_exists( $variant_abs ) ) {
				continue;
			}
			$variant_rel = $this->paths->to_rel_or_empty( $variant_abs );
			if ( '' !== $variant_rel && isset( $path_to_id[ $variant_rel ] ) ) {
				// Variant is itself a Media Library attachment — refuse to
				// claim it. Same guard as the bulk Adopt path.
				$skipped[] = array(
					'ext'    => $ext,
					'reason' => 'is_attachment',
				);
				continue;
			}
			if ( $this->manifest->is_owned_by( $id, $variant_abs ) ) {
				$skipped[] = array(
					'ext'    => $ext,
					'reason' => 'already_owned',
				);
				continue;
			}
			$this->manifest->record( $id, $variant_abs );
			$claimed[] = $ext;
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'claimed' => $claimed,
				'skipped' => $skipped,
			)
		);
	}

	/**
	 * Delete a "shadow" variant attachment occupying a JPEG/PNG source's
	 * destination slot. This is the {@see summarize_diagnosis()} `is_attachment`
	 * case: a `.webp`/`.avif` file that was imported as its OWN Media Library
	 * attachment (typically by a migration or an earlier conversion plugin),
	 * blocking the plugin from writing and owning its generated derivative.
	 *
	 * Neither bulk Adopt nor the per-attachment Claim can resolve this — both
	 * deliberately refuse to seize a file that belongs to a real attachment.
	 * The only fix is to remove the duplicate attachment, after which Convert
	 * regenerates and owns the derivative.
	 *
	 * Safety:
	 *   - Re-derives the target from the SOURCE attachment id; it never deletes
	 *     a client-supplied attachment id directly. The deleted attachment must
	 *     genuinely sit in the source's `.webp`/`.avif` destination slot.
	 *   - Refuses (HTTP 409) to delete a variant attachment that is referenced
	 *     on the front end (InUseScanner) — deleting it would break that
	 *     reference.
	 *   - Force-deletes (bypasses Trash) so the destination slot is actually
	 *     freed for the regenerated derivative.
	 */
	public function delete_conflicting_variant(): void {
		$this->authorize_network();

		$id = Request::post_int( 'id' );
		if ( $id <= 0 ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'cf-media-optimizer' ), 400 );
		}

		$mime = (string) get_post_mime_type( $id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			wp_send_json_error( __( 'Source is not a JPEG or PNG attachment.', 'cf-media-optimizer' ), 400 );
		}

		$source_abs = (string) get_attached_file( $id );
		if ( '' === $source_abs ) {
			wp_send_json_error( __( 'Source file not found.', 'cf-media-optimizer' ), 404 );
		}

		$path_to_id = AttachmentLookup::path_to_id();

		// Find every conflicting variant attachment sitting in this source's
		// destination slots. Keyed by ext so the response can report which.
		$targets = array();
		foreach ( array( 'webp', 'avif' ) as $ext ) {
			$variant_abs = Paths::src_to_variant_path( $source_abs, $ext );
			if ( '' === $variant_abs || ! $this->paths->within_upload_dir( $variant_abs ) ) {
				continue;
			}
			$variant_rel = $this->paths->to_rel_or_empty( $variant_abs );
			if ( '' === $variant_rel || ! isset( $path_to_id[ $variant_rel ] ) ) {
				continue;
			}
			$att_id = (int) $path_to_id[ $variant_rel ];
			// Guard against the degenerate case where the source rel and the
			// variant rel collide onto the same id — never delete the source.
			if ( $att_id > 0 && $att_id !== $id ) {
				$targets[ $ext ] = $att_id;
			}
		}

		if ( empty( $targets ) ) {
			wp_send_json_error( __( 'No conflicting variant attachment found for this image. It may already be resolved — try Convert again.', 'cf-media-optimizer' ), 404 );
		}

		// In-use safety: refuse if any target is referenced on the front end.
		$scan       = $this->in_use_scanner->get();
		$in_use_ids = isset( $scan['ids'] ) ? array_map( 'intval', (array) $scan['ids'] ) : array();
		$in_use     = array();
		foreach ( $targets as $att_id ) {
			if ( in_array( $att_id, $in_use_ids, true ) ) {
				$in_use[] = $att_id;
			}
		}
		if ( ! empty( $in_use ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: comma-separated attachment IDs. */
					__( 'The conflicting attachment (%s) is referenced on the front end. Deleting it would break that reference — update or remove those references first, then retry.', 'cf-media-optimizer' ),
					implode( ', ', array_map( 'strval', array_values( array_unique( $in_use ) ) ) )
				),
				409
			);
		}

		$deleted = array();
		$failed  = array();
		foreach ( $targets as $att_id ) {
			if ( in_array( $att_id, $deleted, true ) || in_array( $att_id, $failed, true ) ) {
				continue; // same attachment claimed both slots — delete once.
			}
			if ( wp_delete_attachment( $att_id, true ) ) {
				$deleted[] = $att_id;
			} else {
				$failed[] = $att_id;
			}
		}

		if ( empty( $deleted ) ) {
			wp_send_json_error( __( 'Failed to delete the conflicting attachment.', 'cf-media-optimizer' ), 500 );
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'deleted' => array_values( $deleted ),
				'failed'  => array_values( $failed ),
			)
		);
	}

	private function summarize_diagnosis( array $report ): string {
		$id          = (int) ( $report['id'] ?? 0 );
		$source      = $report['source'] ?? array();
		$webp        = $report['webp'] ?? array();
		$source_id   = (int) ( $source['mapped_id'] ?? 0 );
		$source_size = (int) ( $source['in_size_map'] ?? 0 );

		if ( ! ( $source['exists'] ?? false ) ) {
			return __( 'Source file is missing on disk.', 'cf-media-optimizer' );
		}
		if ( 0 === $source_id && 0 === $source_size ) {
			return __( 'Source rel path is not in either lookup map — the path in _wp_attached_file likely differs from the on-disk filename (case, encoding, or normalization).', 'cf-media-optimizer' );
		}
		if ( $source_id > 0 && $source_id !== $id ) {
			return sprintf(
				/* translators: 1: this attachment id, 2: id the lookup returned. */
				__( 'Source rel path maps to attachment %2$d, not %1$d. Two attachments may share the same _wp_attached_file value.', 'cf-media-optimizer' ),
				$id,
				$source_id
			);
		}
		if ( ! ( $webp['exists'] ?? false ) ) {
			return __( 'No WebP at destination slot — nothing to adopt; Convert would proceed normally.', 'cf-media-optimizer' );
		}
		if ( ( $webp['is_attachment'] ?? 0 ) > 0 ) {
			return sprintf(
				/* translators: %d: attachment id the .webp is registered as. */
				__( 'WebP is itself registered as Media Library attachment %d — Adopt and Claim deliberately skip it. If it is a stale duplicate, use "Delete the conflicting variant attachment" below, then Convert again.', 'cf-media-optimizer' ),
				(int) $webp['is_attachment']
			);
		}
		if ( $webp['owned_by_this_id'] ?? false ) {
			return __( 'WebP exists and is owned by this attachment — Convert should not be blocked. Try Convert again.', 'cf-media-optimizer' );
		}
		return __( 'WebP exists, source resolves correctly, but the manifest row is missing. Click "Claim this WebP" to fix.', 'cf-media-optimizer' );
	}
}
