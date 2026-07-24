<?php

namespace CFMediaOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Background queue for bulk conversion.
 *
 * The previous AJAX-driven approach forced admins to keep the browser tab
 * open during a multi-thousand-image run. This queue persists work across
 * requests and processes a chunk per tick.
 *
 * Backend:
 *   - Action Scheduler when available (bundled with WooCommerce and many
 *     other plugins) — gives proper async, retries, and an admin log.
 *   - WP-Cron otherwise. WP-Cron is best-effort and tied to traffic, but
 *     it's universally available and good enough for this workload.
 *
 * Queue state lives in a single option (cf_media_manager_queue_state) so the admin
 * UI can poll it without expensive reconstruction.
 */
final class Queue {

	const ACTION_HOOK = 'cf_media_manager_process_queue_chunk';
	const AS_GROUP    = 'cf-media-optimizer';

	private Converter $converter;

	/** Token held by the current process while it owns the chunk lock. */
	private ?string $lock_token = null;

	public function __construct( Converter $converter ) {
		$this->converter = $converter;
	}

	public function register_hooks(): void {
		add_action( self::ACTION_HOOK, [ $this, 'process_chunk' ] );
	}

	public static function action_scheduler_available(): bool {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Queue a fresh run. Replaces any state from a prior run.
	 *
	 * Returns the initial state structure (so the caller can return it to
	 * the UI without a second read). Cancels any in-flight schedule first so
	 * a partially-drained prior queue doesn't continue ticking against the
	 * new state.
	 */
	public function start( array $attachment_ids, bool $force ): array {
		// Defensive: dedupe + reindex, then cap to the queue ceiling so a
		// hostile caller can't pin a 50MB option row.
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) );
		if ( count( $ids ) > Options::MAX_QUEUE_IDS ) {
			$ids = array_slice( $ids, 0, Options::MAX_QUEUE_IDS );
		}

		// Tear down anything scheduled by a previous run so a no-longer-relevant
		// cron tick can't fire against the new state. We deliberately do NOT
		// release a lock we don't own here — a currently-running worker is
		// allowed to finish its chunk and then notice (via the run_id guard
		// below) that its state belongs to a superseded run.
		$this->unschedule_all();

		$run_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'run_', true );

		$state = [
			'status'       => $ids === [] ? 'idle' : 'running',
			'pending'      => $ids,
			'total'        => count( $ids ),
			'processed'    => 0,
			'converted'    => 0,
			'failed'       => 0,
			'bytes_saved'  => 0,
			'gd_fallbacks' => 0,
			'avif_written' => 0,
			'force'        => (bool) $force,
			'started_at'   => time(),
			'updated_at'   => time(),
			'errors'       => [], // last 10 attachment-level errors
			'backend'      => self::action_scheduler_available() ? 'action_scheduler' : 'wp_cron',
			// Stamp every run with a unique id. Workers capture this when they
			// read state and refuse to save back if the option has been
			// replaced by a fresh start() in the meantime. Prevents an orphan
			// worker from clobbering a newly queued run with stale counters.
			'run_id'       => $run_id,
		];

		$this->save_state( $state );

		if ( $ids !== [] ) {
			$this->schedule_next_tick();
		}

		return $state;
	}

	/**
	 * Cancel an in-flight run. Idempotent — safe to call when nothing is queued.
	 *
	 * Mints a fresh `run_id` on the cancelled state so any worker currently
	 * inside `process_chunk()` will fail the run-id guard when it tries to
	 * save its post-chunk state. Without this, a long-running worker could
	 * resurrect a cancelled queue back to `running` / `complete` after the
	 * admin clicked Cancel.
	 */
	public function cancel(): void {
		$this->unschedule_all();
		$this->release_lock();

		$state = $this->state();
		if ( ( $state['status'] ?? 'idle' ) !== 'idle' ) {
			$state['status']     = 'cancelled';
			$state['pending']    = [];
			$state['updated_at'] = time();
			$state['run_id']     = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cancel_', true );
			$this->save_state( $state );
		}
	}

	/**
	 * Action handler: process up to QUEUE_CHUNK_SIZE attachments and schedule
	 * the next tick if more remain.
	 *
	 * Locking: Action Scheduler can dispatch overlapping runs (worker pool +
	 * retries), and WP-Cron can fire twice from concurrent requests. Without
	 * a lock, two workers race to read state, both process the same pending
	 * ids, and whichever writes last clobbers the other's counters. The
	 * lock is a short-lived transient with a TTL well above one chunk's
	 * conversion time, released in `finally` so a fatal mid-encode still
	 * frees the slot.
	 */
	public function process_chunk(): void {
		if ( ! $this->acquire_lock() ) {
			// A peer worker is already processing. Bail — it will schedule
			// the next tick when it's done.
			return;
		}
		try {
			// Re-read state INSIDE the lock so we don't act on a snapshot
			// that's already stale by the time we got here.
			$state = $this->state();
			if ( ( $state['status'] ?? 'idle' ) !== 'running' ) {
				return;
			}

			// Capture the run_id we'll defend against. If the option gets
			// replaced by a fresh start() while this chunk runs, we'll detect
			// it below and refuse to save back stale counters.
			$run_id = (string) ( $state['run_id'] ?? '' );

			$chunk_size       = Options::resolve_queue_chunk_size();
			$chunk            = array_slice( $state['pending'], 0, $chunk_size );
			$state['pending'] = array_slice( $state['pending'], count( $chunk ) );

			foreach ( $chunk as $id ) {
				[ $converted, $failed, $bytes, $gd, $avif ] = $this->converter->convert_attachment( (int) $id, (bool) $state['force'] );

				$state['converted']    += $converted;
				$state['failed']       += $failed;
				$state['bytes_saved']  += $bytes;
				$state['gd_fallbacks'] += $gd;
				$state['avif_written'] += $avif;
				++$state['processed'];

				if ( $failed > 0 && $converted === 0 ) {
					$state['errors'][] = [
						'id'   => (int) $id,
						'time' => time(),
					];
					if ( count( $state['errors'] ) > 10 ) {
						$state['errors'] = array_slice( $state['errors'], -10 );
					}
				}
			}

			$state['updated_at'] = time();

			if ( $state['pending'] === [] ) {
				$state['status'] = 'complete';
				if ( $state['bytes_saved'] > 0 || $state['converted'] > 0 ) {
					update_option( Options::PURGE_FLAG, time() );
				}
			}

			// Run-id + status guard: refuse to write back if a fresh start()
			// replaced the queue while we were converting, OR if cancel()
			// flipped the status to `cancelled`. Without the status check, a
			// worker mid-chunk could resurrect a cancelled run back to
			// `running` or `complete` after the admin clicked Cancel. With
			// both checks, an obsolete worker exits silently and the new run
			// (if any) carries on with its own scheduled ticks.
			$current        = $this->state();
			$current_run_id = (string) ( $current['run_id'] ?? '' );
			$current_status = (string) ( $current['status'] ?? 'idle' );
			if ( '' !== $run_id && ( $current_run_id !== $run_id || 'running' !== $current_status ) ) {
				return;
			}

			$this->save_state( $state );

			if ( $state['status'] === 'running' ) {
				$this->schedule_next_tick();
			}
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Atomic try-acquire on the queue lock. Uses an option row plus
	 * add_option()/get_option semantics to avoid the write-then-check race
	 * that a naive transient would have. WP transients lean on the options
	 * table when no persistent object cache is configured, so we get the
	 * same atomicity via add_option() in that scenario.
	 *
	 * Falls back to transient + check on the rare case where add_option
	 * doesn't error on a duplicate row (filtered get_option returning the
	 * existing value).
	 */
	private function acquire_lock(): bool {
		$token = uniqid( '', true );
		// add_option returns false if the option already exists — that's our
		// atomic "lock held by peer" signal. autoload=false keeps it out of
		// alloptions on every front-end request.
		$created = add_option(
			Options::QUEUE_LOCK,
			array(
				'token'   => $token,
				'expires' => time() + Options::QUEUE_LOCK_TTL,
			),
			'',
			false
		);
		if ( $created ) {
			$this->lock_token = $token;
			return true;
		}
		// Existing lock — check expiry. Stale locks (peer died mid-chunk) get
		// stolen by the next caller. The naive update_option-then-trust is
		// racy: two workers can see the same expired row, both update_option
		// with their own token, and update_option's "no-op when value is
		// identical" optimization doesn't help us here because the tokens
		// differ. Both calls succeed, both think they hold the lock, and
		// they then process the same chunk in parallel.
		//
		// Verify-after-write closes the window: we update with our token,
		// flush the persistent cache so the next get_option re-hits the DB
		// (otherwise the cached row from the read above would lie), then
		// re-read. Whichever process wrote LAST is the one whose token is
		// on disk — that process holds the lock; everyone else returns false.
		$current = get_option( Options::QUEUE_LOCK );
		if ( is_array( $current ) && isset( $current['expires'] ) && (int) $current['expires'] < time() ) {
			update_option(
				Options::QUEUE_LOCK,
				array(
					'token'   => $token,
					'expires' => time() + Options::QUEUE_LOCK_TTL,
				),
				false
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( Options::QUEUE_LOCK, 'options' );
			}
			$stored = get_option( Options::QUEUE_LOCK );
			if ( ! is_array( $stored ) || ( $stored['token'] ?? '' ) !== $token ) {
				// Lost the race — another caller's write landed after ours.
				// Do NOT set $this->lock_token; do NOT release on bail-out.
				return false;
			}
			$this->lock_token = $token;
			return true;
		}
		return false;
	}

	/**
	 * Release the chunk lock — but ONLY if we own it. The previous version
	 * deleted the lock unconditionally when $this->lock_token was null, which
	 * meant a fresh `start()` (which has no token) could rip the lock out
	 * from under a currently-running worker. The run_id guard in
	 * process_chunk() now catches the stale-save case downstream, and this
	 * method stays narrowly scoped to releasing locks we acquired.
	 */
	private function release_lock(): void {
		if ( null === $this->lock_token ) {
			return;
		}
		$current = get_option( Options::QUEUE_LOCK );
		if ( is_array( $current ) && ( $current['token'] ?? '' ) === $this->lock_token ) {
			delete_option( Options::QUEUE_LOCK );
		}
		$this->lock_token = null;
	}

	private function unschedule_all(): void {
		if ( self::action_scheduler_available() ) {
			as_unschedule_all_actions( self::ACTION_HOOK, [], self::AS_GROUP );
		}
		wp_clear_scheduled_hook( self::ACTION_HOOK );
	}

	/**
	 * Acknowledge a finished/cancelled run — clears the state so the admin
	 * UI returns to its idle layout.
	 */
	public function clear_finished(): void {
		$state  = $this->state();
		$status = $state['status'] ?? 'idle';
		if ( in_array( $status, [ 'complete', 'cancelled', 'idle' ], true ) ) {
			delete_option( Options::QUEUE_STATE );
		}
	}

	public function state(): array {
		$default = [
			'status'       => 'idle',
			'pending'      => [],
			'total'        => 0,
			'processed'    => 0,
			'converted'    => 0,
			'failed'       => 0,
			'bytes_saved'  => 0,
			'gd_fallbacks' => 0,
			'avif_written' => 0,
			'force'        => false,
			'started_at'   => 0,
			'updated_at'   => 0,
			'errors'       => [],
			'backend'      => 'none',
		];
		$raw     = get_option( Options::QUEUE_STATE, [] );
		if ( ! is_array( $raw ) ) {
			return $default;
		}
		return array_merge( $default, $raw );
	}

	private function save_state( array $state ): void {
		// `false` autoload — this option churns frequently during a run and is
		// only read by admins on the queue page.
		update_option( Options::QUEUE_STATE, $state, false );
	}

	private function schedule_next_tick(): void {
		if ( self::action_scheduler_available() ) {
			as_schedule_single_action( time(), self::ACTION_HOOK, [], self::AS_GROUP );
			return;
		}

		// WP-Cron: a near-immediate single event. WP-Cron's resolution is
		// limited to "next request that goes through wp-cron.php", so this is
		// best-effort. We deliberately don't use a recurring event — the
		// process_chunk handler self-schedules so a stalled run can't pile
		// up duplicate cron events.
		if ( ! wp_next_scheduled( self::ACTION_HOOK ) ) {
			wp_schedule_single_event( time() + 1, self::ACTION_HOOK );
		}
	}
}
