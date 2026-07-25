<?php
/**
 * AuditRunner — orchestrator for the Audit subsystem.
 *
 * Responsibilities:
 *   1. Registry of AuditReportInterface instances, keyed by id.
 *   2. Per-report state machine: idle → scanning → complete (or failed).
 *   3. Chunked scan driver. Each `run_chunk()` call advances one chunk and
 *      persists state — safe to invoke from an AJAX endpoint with the JS
 *      polling, or from a future cron loop that calls it repeatedly until
 *      `is_complete`.
 *   4. Two-transient persistence:
 *        - state    (mutated every chunk; small, frequent writes)
 *        - results  (written once at completion; one large write)
 *      Separating them avoids re-serializing the full items array on every
 *      chunk tick.
 *   5. Atomic concurrency lock (add_option idiom — same pattern as
 *      Queue::QUEUE_LOCK) so two simultaneous AJAX calls can't double-run
 *      the same chunk.
 *   6. Staleness: a single autoloaded option tracks the most recent
 *      attachment mutation. is_stale() compares each report's
 *      scanned_at against it. Hooks bump the option on add/update/delete
 *      attachment.
 *   7. Dashboard summary across all registered reports — small, cheap, the
 *      dashboard card grid reads this on every tab open.
 *   8. Paginated result reads — the items array lives in one transient,
 *      pagination happens in PHP for the detail view.
 *   9. purge_all() for uninstall, mirroring IgnoredStore.
 *
 * Not built in this commit (deliberate — these arrive with their first
 * real consumer):
 *   - Background scheduling (cron hook calling run_chunk in a loop).
 *     run_chunk() is already shaped to be the atomic unit such a loop
 *     would drive.
 *   - CSV streaming. Will be added when the first detail view ships,
 *     since report-specific column shaping isn't decidable upfront.
 */

namespace CFMediaManager\Audit;

use CFMediaManager\Options;

defined( 'ABSPATH' ) || exit;

final class AuditRunner {

	// ---------------------------------------------------------------------
	// Storage keys
	// ---------------------------------------------------------------------

	/**
	 * Transient prefix for the per-report mutable state. Full key is
	 * STATE_TRANSIENT_PREFIX . $report_id. Updated on every chunk tick.
	 */
	const STATE_TRANSIENT_PREFIX = 'cf_mm_audit_state_';

	/**
	 * Transient prefix for the per-report results blob. Full key is
	 * RESULTS_TRANSIENT_PREFIX . $report_id. Written once on completion;
	 * read on detail-view paginated calls.
	 */
	const RESULTS_TRANSIENT_PREFIX = 'cf_mm_audit_results_';

	/**
	 * Option prefix for the per-report concurrency lock. The lock value is
	 * an array { token, expires } so a stuck lock can be reaped by a later
	 * caller whose timestamp exceeds the embedded expiry.
	 */
	const LOCK_OPTION_PREFIX = 'cf_mm_audit_lock_';

	// ---------------------------------------------------------------------
	// TTLs / time budgets (seconds)
	// ---------------------------------------------------------------------

	const STATE_TTL      = 24 * 3600;          // state survives a day across page closes
	const RESULTS_TTL    = 7 * 24 * 3600;      // a week — invalidated by staleness, not expiry
	const LOCK_TTL       = 60;                 // a stuck chunk can't block forever
	const ITEMS_HARD_CAP = 50000;              // refuse to load more than 50k items into one transient

	// ---------------------------------------------------------------------
	// Phases
	// ---------------------------------------------------------------------

	const PHASE_IDLE     = 'idle';     // never run, or reset
	const PHASE_SCANNING = 'scanning'; // start() called; awaiting run_chunk()s
	const PHASE_COMPLETE = 'complete'; // last chunk yielded is_complete=true
	const PHASE_FAILED   = 'failed';   // a chunk threw or returned an unusable cursor

	/**
	 * @var array<string,AuditReportInterface> Registered reports keyed by id().
	 */
	private array $reports = array();

	// =====================================================================
	// Registration
	// =====================================================================

	public function register( AuditReportInterface $report ): void {
		$this->reports[ $report->id() ] = $report;
	}

	public function get( string $report_id ): ?AuditReportInterface {
		return $this->reports[ $report_id ] ?? null;
	}

	/**
	 * @return array<string,AuditReportInterface>
	 */
	public function all(): array {
		return $this->reports;
	}

	/**
	 * Hooks the runner registers when wired into Plugin::boot(). Currently:
	 *   - attachment add/update/delete → bump the global stale-since timestamp
	 *
	 * Idempotent — safe to call multiple times.
	 */
	public function register_hooks(): void {
		$bump = array( $this, 'mark_all_stale' );
		add_action( 'add_attachment', $bump );
		add_action( 'attachment_updated', $bump );
		add_action( 'delete_attachment', $bump );
	}

	// =====================================================================
	// Lifecycle: start / run_chunk / cancel / reset
	// =====================================================================

	/**
	 * Begin a new scan for the given report.
	 *
	 *   - If `force = true`, any in-progress or completed state is wiped
	 *     and a fresh scan begins.
	 *   - Otherwise, an existing scanning/complete state is preserved
	 *     (this is a no-op). Use this idiom for "ensure a scan exists"
	 *     callers like the dashboard's auto-start flow.
	 *
	 * Returns the post-call state so AJAX can reflect the new phase.
	 */
	public function start( string $report_id, array $config = array(), bool $force = false ): array {
		$this->require_registered( $report_id );

		if ( ! $force ) {
			$existing = $this->read_state( $report_id );
			if ( in_array( $existing['phase'], array( self::PHASE_SCANNING, self::PHASE_COMPLETE ), true ) ) {
				return $existing;
			}
		}

		$state               = $this->fresh_state();
		$state['phase']      = self::PHASE_SCANNING;
		$state['started_at'] = time();
		$state['config']     = $config;

		$this->write_state( $report_id, $state );
		$this->clear_results( $report_id );

		return $state;
	}

	/**
	 * Drive the next chunk for the given report. Idempotent under
	 * concurrent calls — only one caller acquires the lock per chunk.
	 *
	 * Returns the post-call state. The shape includes `running_totals`
	 * (for in-flight scan progress UIs), `is_locked` (when another caller
	 * holds the lock and this call no-op'd), and `phase`.
	 *
	 * If the report's scan_chunk() raises, this method catches and routes
	 * to PHASE_FAILED with the message captured in the state. The chunk
	 * is NOT retried automatically — the UI surfaces the failure and lets
	 * the user click Rescan.
	 */
	public function run_chunk( string $report_id ): array {
		$this->require_registered( $report_id );

		$state = $this->read_state( $report_id );
		if ( self::PHASE_SCANNING !== $state['phase'] ) {
			return $state;
		}

		$lock_token = $this->acquire_lock( $report_id );
		if ( null === $lock_token ) {
			$state['is_locked'] = true;
			return $state;
		}

		try {
			$report = $this->reports[ $report_id ];
			$ctx    = new ScanContext(
				(bool) ( $state['config']['force'] ?? false ),
				(int) ( $state['config']['chunk_size'] ?? ScanContext::DEFAULT_CHUNK_SIZE ),
				is_array( $state['config'] ) ? $state['config'] : array()
			);

			$chunk = $report->scan_chunk(
				$ctx,
				$state['cursor'],
				is_array( $state['running_totals'] ) ? $state['running_totals'] : array()
			);

			$state = $this->merge_chunk( $report_id, $state, $chunk );
			$this->write_state( $report_id, $state );
		} catch ( \Throwable $e ) {
			$state['phase']        = self::PHASE_FAILED;
			$state['error']        = $e->getMessage();
			$state['completed_at'] = time();
			$this->write_state( $report_id, $state );
		} finally {
			$this->release_lock( $report_id, $lock_token );
		}

		return $state;
	}

	/**
	 * Mark a running scan as cancelled. The state moves to PHASE_FAILED
	 * with a synthetic error so the UI can distinguish user-cancel from
	 * exception-failure if it ever wants to.
	 */
	public function cancel( string $report_id ): array {
		$this->require_registered( $report_id );

		$state = $this->read_state( $report_id );
		if ( self::PHASE_SCANNING !== $state['phase'] ) {
			return $state;
		}

		$state['phase']        = self::PHASE_FAILED;
		$state['error']        = 'cancelled';
		$state['completed_at'] = time();
		$this->write_state( $report_id, $state );

		return $state;
	}

	/**
	 * Wipe state, results, and lock for a report. Returns the report to
	 * PHASE_IDLE. Used by the "clear card" UI affordance and by tests.
	 */
	public function reset( string $report_id ): void {
		$this->require_registered( $report_id );

		delete_transient( self::STATE_TRANSIENT_PREFIX . $report_id );
		delete_transient( self::RESULTS_TRANSIENT_PREFIX . $report_id );
		delete_option( self::LOCK_OPTION_PREFIX . $report_id );
	}

	// =====================================================================
	// Reads — for the dashboard and detail views
	// =====================================================================

	/**
	 * Current state for one report. Always returns a fully-shaped array
	 * (defaults from fresh_state()) — callers never have to guard on null.
	 */
	public function state( string $report_id ): array {
		$this->require_registered( $report_id );
		return $this->read_state( $report_id );
	}

	/**
	 * Dashboard payload — one entry per registered report. Cheap: reads N
	 * tiny state transients + N tiny result heads. No items loaded.
	 *
	 * @return array<int,array{
	 *     id:string,
	 *     label:string,
	 *     description:string,
	 *     phase:string,
	 *     totals:array,
	 *     scanned_at:?int,
	 *     duration_ms:?int,
	 *     is_stale:bool,
	 *     error:?string
	 * }>
	 */
	public function dashboard(): array {
		$rows = array();
		foreach ( $this->reports as $id => $report ) {
			$state   = $this->read_state( $id );
			$results = $this->read_results_meta( $id );

			$rows[] = array(
				'id'          => $id,
				'label'       => $report->label(),
				'description' => $report->description(),
				'phase'       => $state['phase'],
				'totals'      => $results ? $results['totals'] : $state['running_totals'],
				'scanned_at'  => $results['scanned_at'] ?? null,
				'duration_ms' => $results['duration_ms'] ?? null,
				'is_stale'    => $this->is_stale( $id ),
				'error'       => $state['error'] ?? null,
			);
		}
		return $rows;
	}

	/**
	 * Paginated items for a report's detail view.
	 *
	 * Returns null when no results are cached (the UI shows "run a scan"
	 * empty state). Otherwise returns:
	 *   { items, page, per_page, total, totals, scanned_at, is_stale }
	 *
	 * Pagination is PHP-side over the cached results array — acceptable
	 * for the ITEMS_HARD_CAP-bounded sizes we target. If a future report
	 * routinely exceeds that, sharding the results transient is the
	 * upgrade path (transparent to callers).
	 */
	public function results( string $report_id, int $page = 1, int $per_page = 50 ): ?array {
		$this->require_registered( $report_id );

		$results = $this->read_results( $report_id );
		if ( null === $results ) {
			return null;
		}

		$page     = max( 1, $page );
		$per_page = max( 1, min( 200, $per_page ) );

		$total  = count( $results['items'] );
		$offset = ( $page - 1 ) * $per_page;
		$slice  = array_slice( $results['items'], $offset, $per_page );

		return array(
			'items'       => $slice,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'pages'       => (int) ceil( $total / $per_page ),
			'totals'      => $results['totals'],
			'scanned_at'  => $results['scanned_at'],
			'duration_ms' => $results['duration_ms'],
			'is_stale'    => $this->is_stale( $report_id ),
		);
	}

	// =====================================================================
	// Bulk action proxy
	// =====================================================================

	/**
	 * Apply a bulk action to a report's items. Pure proxy to the report —
	 * the runner doesn't validate the action against supports_bulk() here
	 * because the report itself is the authority and rejects unknowns via
	 * an error ActionResult. This keeps the runner stateless w.r.t.
	 * report-specific verbs.
	 */
	public function bulk( string $report_id, string $action, array $ids, array $args = array() ): ActionResult {
		$this->require_registered( $report_id );
		return $this->reports[ $report_id ]->bulk_action( $action, $ids, $args );
	}

	// =====================================================================
	// Staleness
	// =====================================================================

	/**
	 * Bump the global stale-since timestamp. Called from the registered
	 * hooks on attachment add/update/delete. Public so tests and other
	 * subsystems (e.g., Converter on bulk completion) can trigger it.
	 */
	public function mark_all_stale(): void {
		update_option( Options::AUDIT_STALE_SINCE, time(), 'yes' );
	}

	public function is_stale( string $report_id ): bool {
		$this->require_registered( $report_id );

		$results = $this->read_results_meta( $report_id );
		if ( null === $results ) {
			return false;
		}
		$stale_since = (int) get_option( Options::AUDIT_STALE_SINCE, 0 );
		return $stale_since > $results['scanned_at'];
	}

	// =====================================================================
	// Maintenance
	// =====================================================================

	/**
	 * Drop every persisted artifact the runner owns. Mirrors
	 * IgnoredStore::purge_all() — both must be called from uninstall.php
	 * when DELETE_ON_UNINSTALL is set.
	 */
	public function purge_all(): void {
		// Clear per-report transients + locks for every registered report.
		// For un-registered IDs (the runner has been instantiated without
		// loading all reports yet), the option-level cleanup below catches
		// orphan stale options.
		foreach ( array_keys( $this->reports ) as $id ) {
			$this->reset( $id );
		}

		delete_option( Options::AUDIT_STALE_SINCE );

		// Belt-and-suspenders: sweep any orphaned audit transients/locks
		// from reports that previously existed but aren't currently
		// registered. Lives in options as both autoload (transient meta)
		// and non-autoload (transient body) rows in WP.
		global $wpdb;
		if ( isset( $wpdb ) ) {
			$prefix_state     = '_transient_' . self::STATE_TRANSIENT_PREFIX;
			$prefix_results   = '_transient_' . self::RESULTS_TRANSIENT_PREFIX;
			$prefix_state_t   = '_transient_timeout_' . self::STATE_TRANSIENT_PREFIX;
			$prefix_results_t = '_transient_timeout_' . self::RESULTS_TRANSIENT_PREFIX;
			$prefix_lock      = self::LOCK_OPTION_PREFIX;

			foreach ( array( $prefix_state, $prefix_results, $prefix_state_t, $prefix_results_t, $prefix_lock ) as $p ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep for orphan transient/option rows from previously-registered reports. delete_transient is keyed; we don't know every key from prior versions.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
						$wpdb->esc_like( $p ) . '%'
					)
				);
			}
		}
	}

	// =====================================================================
	// State persistence
	// =====================================================================

	private function fresh_state(): array {
		return array(
			'phase'          => self::PHASE_IDLE,
			'started_at'     => null,
			'completed_at'   => null,
			'cursor'         => null,
			'running_totals' => array(),
			'chunks_done'    => 0,
			'error'          => null,
			'config'         => array(),
		);
	}

	private function read_state( string $report_id ): array {
		$raw = get_transient( self::STATE_TRANSIENT_PREFIX . $report_id );
		if ( ! is_array( $raw ) ) {
			return $this->fresh_state();
		}
		// Merge over fresh to fill any keys added in later versions.
		return array_merge( $this->fresh_state(), $raw );
	}

	private function write_state( string $report_id, array $state ): void {
		set_transient( self::STATE_TRANSIENT_PREFIX . $report_id, $state, self::STATE_TTL );
	}

	/**
	 * Apply a chunk to running state. On completion, the accumulated items
	 * are flushed to the results transient and the state moves to
	 * PHASE_COMPLETE.
	 */
	private function merge_chunk( string $report_id, array $state, AuditChunk $chunk ): array {
		// Items accumulate in a separate transient throughout the scan so
		// the state transient stays small. Read-modify-write here.
		$items_accumulator = $this->read_pending_items( $report_id );
		foreach ( $chunk->items as $item ) {
			if ( count( $items_accumulator ) >= self::ITEMS_HARD_CAP ) {
				break;
			}
			$items_accumulator[] = $item;
		}
		$this->write_pending_items( $report_id, $items_accumulator );

		$state['cursor']         = $chunk->next_cursor;
		$state['running_totals'] = $chunk->running_totals;
		$state['chunks_done']    = (int) $state['chunks_done'] + 1;

		if ( $chunk->is_complete ) {
			$started_at            = (int) ( $state['started_at'] ?? time() );
			$now                   = time();
			$state['phase']        = self::PHASE_COMPLETE;
			$state['completed_at'] = $now;

			$this->write_results(
				$report_id,
				array(
					'items'       => $items_accumulator,
					'totals'      => $chunk->running_totals,
					'scanned_at'  => $now,
					'duration_ms' => ( $now - $started_at ) * 1000,
				)
			);
		}

		return $state;
	}

	/**
	 * The pending-items accumulator lives in the same key as the final
	 * results transient — on completion the merge_chunk() write replaces
	 * it with the full results envelope. This avoids a third transient
	 * for the (count_reports × 2) cost.
	 */
	private function read_pending_items( string $report_id ): array {
		$raw = get_transient( self::RESULTS_TRANSIENT_PREFIX . $report_id );
		if ( is_array( $raw ) && isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
			return $raw['items'];
		}
		return array();
	}

	private function write_pending_items( string $report_id, array $items ): void {
		set_transient(
			self::RESULTS_TRANSIENT_PREFIX . $report_id,
			array( 'items' => $items ),
			self::RESULTS_TTL
		);
	}

	private function write_results( string $report_id, array $envelope ): void {
		set_transient(
			self::RESULTS_TRANSIENT_PREFIX . $report_id,
			$envelope,
			self::RESULTS_TTL
		);
	}

	private function read_results( string $report_id ): ?array {
		$raw = get_transient( self::RESULTS_TRANSIENT_PREFIX . $report_id );
		if ( ! is_array( $raw ) || ! isset( $raw['items'], $raw['scanned_at'] ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Lightweight read for the dashboard: returns the results envelope's
	 * metadata (totals + scanned_at + duration_ms) without the items
	 * array. Currently equivalent to read_results() — kept as a distinct
	 * method so a future sharded-items layout can return cheap metadata
	 * without loading shards.
	 */
	private function read_results_meta( string $report_id ): ?array {
		$results = $this->read_results( $report_id );
		if ( null === $results ) {
			return null;
		}
		return array(
			'totals'      => $results['totals'] ?? array(),
			'scanned_at'  => $results['scanned_at'] ?? null,
			'duration_ms' => $results['duration_ms'] ?? null,
		);
	}

	private function clear_results( string $report_id ): void {
		delete_transient( self::RESULTS_TRANSIENT_PREFIX . $report_id );
	}

	// =====================================================================
	// Concurrency lock
	// =====================================================================

	/**
	 * Atomic lock acquisition via add_option — same pattern Queue.php uses.
	 * Returns the token on success, or null when another caller already
	 * holds an unexpired lock.
	 *
	 * The lock value carries an expires field; a later caller whose
	 * timestamp exceeds expires will steal the lock (defensive against a
	 * worker that died mid-chunk without releasing).
	 */
	private function acquire_lock( string $report_id ): ?string {
		$key   = self::LOCK_OPTION_PREFIX . $report_id;
		$token = bin2hex( random_bytes( 16 ) );
		$now   = time();
		$entry = array(
			'token'   => $token,
			'expires' => $now + self::LOCK_TTL,
		);

		// add_option with autoload=no is atomic in MySQL via the UNIQUE key
		// on option_name. WP wraps it accordingly.
		if ( add_option( $key, $entry, '', 'no' ) ) {
			return $token;
		}

		// Lock exists. If expired, steal it.
		$current = get_option( $key, null );
		if ( is_array( $current ) && isset( $current['expires'] ) && $now > (int) $current['expires'] ) {
			update_option( $key, $entry, false );
			return $token;
		}

		return null;
	}

	private function release_lock( string $report_id, string $token ): void {
		$key     = self::LOCK_OPTION_PREFIX . $report_id;
		$current = get_option( $key, null );
		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], $token ) ) {
			delete_option( $key );
		}
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	private function require_registered( string $report_id ): void {
		if ( ! isset( $this->reports[ $report_id ] ) ) {
			// esc_html on the interpolated id — the exception message can
			// surface in admin notices via wp_die() if an AJAX caller
			// references an unknown report.
			throw new \InvalidArgumentException(
				esc_html( 'Unknown audit report id: ' . $report_id )
			);
		}
	}
}
