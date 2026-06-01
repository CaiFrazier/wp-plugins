<?php

namespace CFMediaManager\Tests;

use CFMediaManager\Converter;
use CFMediaManager\Options;
use CFMediaManager\Paths;
use CFMediaManager\Queue;
use PHPUnit\Framework\TestCase;

/**
 * Queue tests focus on the state machine — start/cancel/process_chunk
 * transitions, dedupe, error isolation, and the chunk-size boundary.
 *
 * The Converter dependency is real, but most tests use attachment ids that
 * don't resolve to files (get_attached_file returns false), so the converter
 * cheaply returns [0,0,0,0,0] for each — perfect for exercising queue glue
 * without re-testing image encoding.
 */
final class QueueTest extends TestCase {

	private string $sandbox;
	private Paths $paths;
	private Converter $converter;
	private Queue $queue;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();

		$this->sandbox = sys_get_temp_dir() . '/cf-media-manager-queue-' . uniqid();
		mkdir( $this->sandbox . '/uploads/2026/05', 0777, true );

		$this->paths     = new Paths( $this->sandbox . '/uploads', 'https://example.test/wp-content/uploads' );
		$this->converter = new Converter( $this->paths );
		$this->queue     = new Queue( $this->converter );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->sandbox );
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	// -------------------------------------------------------------------------
	// start()
	// -------------------------------------------------------------------------

	public function test_start_with_attachment_ids_transitions_to_running_and_schedules_a_tick(): void {
		$state = $this->queue->start( [ 11, 22, 33 ], false );

		self::assertSame( 'running', $state['status'] );
		self::assertSame( 3, $state['total'] );
		self::assertSame( [ 11, 22, 33 ], $state['pending'] );
		self::assertSame( 0, $state['processed'] );
		// schedule_next_tick should have requested a cron event.
		self::assertNotFalse( $GLOBALS['cf_media_manager_test_cron'][ Queue::ACTION_HOOK ] ?? false );
	}

	public function test_start_with_empty_ids_stays_idle_and_does_not_schedule(): void {
		$state = $this->queue->start( [], false );

		self::assertSame( 'idle', $state['status'] );
		self::assertSame( [], $state['pending'] );
		self::assertArrayNotHasKey( Queue::ACTION_HOOK, $GLOBALS['cf_media_manager_test_cron'] );
	}

	public function test_start_dedupes_and_filters_zero_ids(): void {
		$state = $this->queue->start( [ 11, 22, 11, 0, 33, 22 ], false );

		self::assertSame( [ 11, 22, 33 ], array_values( $state['pending'] ) );
		self::assertSame( 3, $state['total'] );
	}

	public function test_start_records_force_flag_verbatim(): void {
		$state = $this->queue->start( [ 11 ], true );

		self::assertTrue( $state['force'] );
	}

	// -------------------------------------------------------------------------
	// cancel()
	// -------------------------------------------------------------------------

	public function test_cancel_transitions_running_state_to_cancelled(): void {
		$this->queue->start( [ 11, 22 ], false );
		$this->queue->cancel();

		$state = $this->queue->state();
		self::assertSame( 'cancelled', $state['status'] );
		self::assertSame( [], $state['pending'] );
	}

	public function test_cancel_is_idempotent_when_no_run_is_active(): void {
		// No prior start(). Cancel should be a no-op without errors.
		$this->queue->cancel();

		$state = $this->queue->state();
		self::assertSame( 'idle', $state['status'] );
	}

	public function test_cancel_clears_pending_cron_event(): void {
		$this->queue->start( [ 11 ], false );
		self::assertNotFalse( $GLOBALS['cf_media_manager_test_cron'][ Queue::ACTION_HOOK ] ?? false );

		$this->queue->cancel();
		self::assertFalse( $GLOBALS['cf_media_manager_test_cron'][ Queue::ACTION_HOOK ] ?? false );
	}

	// -------------------------------------------------------------------------
	// process_chunk()
	// -------------------------------------------------------------------------

	public function test_process_chunk_is_a_noop_when_status_is_idle(): void {
		$this->queue->process_chunk();
		self::assertSame( 'idle', $this->queue->state()['status'] );
	}

	public function test_process_chunk_consumes_up_to_chunk_size_attachments(): void {
		// Drive the queue at its max chunk size by setting BATCH_SIZE to the
		// ceiling. With more ids than fit in a single chunk we can observe
		// partial progress between ticks.
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = Options::QUEUE_CHUNK_MAX;
		$ids = range( 1, Options::QUEUE_CHUNK_MAX + 5 );
		$this->queue->start( $ids, false );

		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( 'running', $state['status'], 'status should still be running with ids pending' );
		self::assertSame( Options::QUEUE_CHUNK_MAX, $state['processed'] );
		self::assertCount( 5, $state['pending'] );
	}

	public function test_process_chunk_honors_user_configured_batch_size(): void {
		// User picked batch_size=3 in settings; queue should consume exactly
		// 3 per tick, not the legacy hardcoded ceiling.
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 3;
		$ids = range( 1, 10 );
		$this->queue->start( $ids, false );

		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( 3, $state['processed'] );
		self::assertCount( 7, $state['pending'] );
	}

	public function test_process_chunk_clamps_batch_size_to_safe_ceiling(): void {
		// A hand-edited option (or future settings bug) could try to set
		// batch_size above the ceiling. The clamp must protect the worker.
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 1000;
		$ids = range( 1, Options::QUEUE_CHUNK_MAX + 50 );
		$this->queue->start( $ids, false );

		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( Options::QUEUE_CHUNK_MAX, $state['processed'] );
	}

	public function test_process_chunk_falls_back_to_default_batch_when_option_missing(): void {
		// No BATCH_SIZE option set — should use DEFAULT_BATCH.
		$ids = range( 1, 5 );
		$this->queue->start( $ids, false );

		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( Options::DEFAULT_BATCH, $state['processed'] );
	}

	public function test_process_chunk_marks_complete_when_pending_drains(): void {
		// Set chunk size large enough that 2 ids fit in one tick.
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 25;
		$this->queue->start( [ 11, 22 ], false );
		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( 'complete', $state['status'] );
		self::assertSame( [], $state['pending'] );
		self::assertSame( 2, $state['processed'] );
	}

	public function test_process_chunk_increments_failed_counter_on_unconvertible_attachments(): void {
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 25;
		// Set up a real attachment with a malformed source — convert_attachment
		// will return [0, 1, 0, 0, 0], which is the queue's "failed" signal.
		$path = $this->sandbox . '/uploads/2026/05/broken.jpg';
		file_put_contents( $path, 'not a real jpeg' );
		$GLOBALS['cf_media_manager_test_attachments'][99] = [
			'file' => $path,
			'meta' => [ 'file' => '2026/05/broken.jpg', 'sizes' => [] ],
		];

		$this->queue->start( [ 99 ], false );
		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( 'complete', $state['status'] );
		self::assertSame( 1, $state['failed'] );
		self::assertSame( 0, $state['converted'] );
		self::assertCount( 1, $state['errors'] );
		self::assertSame( 99, $state['errors'][0]['id'] );
	}

	public function test_process_chunk_isolates_failures_from_neighboring_attachments(): void {
		// One bad, one good, in the same chunk. Verify both run and counters
		// reflect both outcomes — i.e., the bad one doesn't poison the loop.
		if ( ! function_exists( 'imagewebp' ) && ! extension_loaded( 'imagick' ) ) {
			self::markTestSkipped( 'Need GD-with-WebP or Imagick.' );
		}
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 25;

		$good_path = $this->sandbox . '/uploads/2026/05/good.jpg';
		$im        = imagecreatetruecolor( 32, 32 );
		imagejpeg( $im, $good_path, 90 );

		$bad_path = $this->sandbox . '/uploads/2026/05/bad.jpg';
		file_put_contents( $bad_path, 'garbage' );

		$GLOBALS['cf_media_manager_test_attachments'][100] = [
			'file' => $good_path,
			'meta' => [ 'file' => '2026/05/good.jpg', 'sizes' => [] ],
		];
		$GLOBALS['cf_media_manager_test_attachments'][101] = [
			'file' => $bad_path,
			'meta' => [ 'file' => '2026/05/bad.jpg', 'sizes' => [] ],
		];

		$this->queue->start( [ 100, 101 ], false );
		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( 1, $state['converted'] );
		self::assertSame( 1, $state['failed'] );
		self::assertSame( 2, $state['processed'] );
	}

	public function test_process_chunk_self_schedules_next_tick_when_more_pending(): void {
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = Options::QUEUE_CHUNK_MAX;
		$ids = range( 1, Options::QUEUE_CHUNK_MAX + 1 );
		$this->queue->start( $ids, false );
		// Clear the cron event from start() so we can check that process_chunk
		// scheduled a fresh one for the next batch.
		unset( $GLOBALS['cf_media_manager_test_cron'][ Queue::ACTION_HOOK ] );

		$this->queue->process_chunk();

		self::assertNotFalse( $GLOBALS['cf_media_manager_test_cron'][ Queue::ACTION_HOOK ] ?? false );
	}

	// -------------------------------------------------------------------------
	// clear_finished()
	// -------------------------------------------------------------------------

	public function test_clear_finished_removes_state_after_complete_run(): void {
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 25;
		$this->queue->start( [ 11 ], false );
		$this->queue->process_chunk();
		self::assertSame( 'complete', $this->queue->state()['status'] );

		$this->queue->clear_finished();
		self::assertArrayNotHasKey( Options::QUEUE_STATE, $GLOBALS['cf_media_manager_test_options'] );
	}

	// -------------------------------------------------------------------------
	// Locking — overlapping workers must not double-process the same ids.
	// -------------------------------------------------------------------------

	public function test_process_chunk_skips_when_lock_held_by_another_worker(): void {
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 25;
		$this->queue->start( array( 11, 22 ), false );

		// Simulate a peer worker that's already acquired the lock and is
		// mid-encode. add_option() will refuse to overwrite, so the second
		// process_chunk should no-op.
		$GLOBALS['cf_media_manager_test_options'][ Options::QUEUE_LOCK ] = array(
			'token'   => 'peer-token',
			'expires' => time() + 60,
		);

		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( 0, $state['processed'], 'a contended chunk must not advance counters' );
		self::assertCount( 2, $state['pending'], 'pending ids must stay queued for the peer worker' );
	}

	public function test_process_chunk_steals_stale_lock(): void {
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 25;
		$this->queue->start( array( 11 ), false );

		// Stale lock — owner died mid-chunk and never released. The TTL has
		// expired so the next caller is allowed to steal it.
		$GLOBALS['cf_media_manager_test_options'][ Options::QUEUE_LOCK ] = array(
			'token'   => 'dead-worker',
			'expires' => time() - 1,
		);

		$this->queue->process_chunk();

		self::assertSame( 'complete', $this->queue->state()['status'] );
		self::assertArrayNotHasKey(
			Options::QUEUE_LOCK,
			$GLOBALS['cf_media_manager_test_options'],
			'lock should be released after the steal-and-run completes'
		);
	}

	public function test_start_does_not_steal_a_live_workers_lock(): void {
		// A peer worker is mid-chunk and holds a non-expired lock. A fresh
		// start() must NOT delete it — the peer will release its own lock in
		// its `finally`, and the run_id guard below catches any stale save.
		$this->queue->start( array( 11 ), false );
		$GLOBALS['cf_media_manager_test_options'][ Options::QUEUE_LOCK ] = array(
			'token'   => 'peer-token',
			'expires' => time() + 60,
		);

		$this->queue->start( array( 22, 33 ), true );

		self::assertArrayHasKey(
			Options::QUEUE_LOCK,
			$GLOBALS['cf_media_manager_test_options'],
			'a fresh start must not rip the lock out from under a live peer worker'
		);
		// New run scheduled its own tick regardless.
		self::assertNotFalse( $GLOBALS['cf_media_manager_test_cron'][ Queue::ACTION_HOOK ] ?? false );
	}

	public function test_start_assigns_a_fresh_run_id_each_call(): void {
		$state_a = $this->queue->start( array( 11 ), false );
		$state_b = $this->queue->start( array( 22 ), false );

		self::assertNotEmpty( $state_a['run_id'] );
		self::assertNotEmpty( $state_b['run_id'] );
		self::assertNotSame( $state_a['run_id'], $state_b['run_id'], 'every start() must mint a new run_id' );
	}

	public function test_cancel_mints_a_new_run_id_so_running_workers_cant_resurrect(): void {
		// Without a new run_id on cancel, a worker that read state inside its
		// lock could later save its post-chunk state (status=running or
		// =complete) over the admin's cancellation. The fix is to bump
		// run_id on cancel and have the worker verify it before saving.
		$this->queue->start( array( 11, 22 ), false );
		$before_cancel = $this->queue->state();

		$this->queue->cancel();
		$after_cancel = $this->queue->state();

		self::assertSame( 'cancelled', $after_cancel['status'] );
		self::assertNotSame(
			$before_cancel['run_id'],
			$after_cancel['run_id'],
			'cancel must mint a new run_id to invalidate any worker mid-chunk'
		);
	}

	public function test_orphan_worker_cannot_resurrect_a_cancelled_run(): void {
		// Model an actual orphan worker: it captured run_id under the
		// original run, the admin then cancelled, and the orphan's post-
		// chunk save attempt must be dropped.
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 25;
		$this->queue->start( array( 11 ), false );

		// Admin cancels — flips status + mints new run_id.
		$this->queue->cancel();
		$cancelled = $this->queue->state();
		self::assertSame( 'cancelled', $cancelled['status'] );

		// Orphan worker tries to process. The early-return inside the lock
		// catches "status !== running" so process_chunk no-ops; state must
		// remain cancelled with empty pending.
		$this->queue->process_chunk();
		$after = $this->queue->state();
		self::assertSame( 'cancelled', $after['status'] );
		self::assertSame( array(), $after['pending'] );
	}

	public function test_stale_worker_cannot_overwrite_a_freshly_started_run(): void {
		// Simulate an orphan worker mid-chunk: it has captured state but
		// before it can save, an admin restarts the queue with a different
		// id list. The run_id guard must drop the orphan's write.
		$GLOBALS['cf_media_manager_test_options'][ Options::BATCH_SIZE ] = 25;

		// Phase 1 — original run; an orphan worker would have captured its
		// run_id at this point. We model the orphan by reading state, then
		// letting the admin start a new run, then having the orphan worker
		// finish process_chunk.
		$this->queue->start( array( 11, 22 ), false );
		$original = $this->queue->state();
		self::assertNotEmpty( $original['run_id'] );

		// Phase 2 — admin restarts. New run_id replaces the option.
		$this->queue->start( array( 99 ), true );
		$replacement = $this->queue->state();
		self::assertNotSame( $original['run_id'], $replacement['run_id'] );

		// Phase 3 — orphan worker tries to process a chunk. It should
		// successfully acquire the lock (peer's lock from start() was not
		// stolen, but the peer never wrote one — and stale ones would be
		// stolen anyway). Then it reads state, processes, and reaches the
		// run_id guard. Because the option now carries a different run_id,
		// the orphan must NOT save. After the run, state must still match
		// the admin's fresh start — the orphan's ids (11, 22) must not
		// appear in pending, and `processed` must not have advanced.
		$this->queue->process_chunk();
		$after = $this->queue->state();

		self::assertSame( $replacement['run_id'], $after['run_id'] );
		// The chunk that ran processed [99] (the new run), draining pending
		// completely; the orphan's pending (11, 22) must not have leaked back.
		self::assertNotContains( 11, $after['pending'] );
		self::assertNotContains( 22, $after['pending'] );
	}

	public function test_clear_finished_does_not_remove_in_flight_state(): void {
		$ids = range( 1, Options::QUEUE_CHUNK_MAX + 5 );
		$this->queue->start( $ids, false );
		$this->queue->process_chunk();
		self::assertSame( 'running', $this->queue->state()['status'] );

		$this->queue->clear_finished();
		// State should still be there — a running run is sacred.
		self::assertArrayHasKey( Options::QUEUE_STATE, $GLOBALS['cf_media_manager_test_options'] );
	}

	// -------------------------------------------------------------------------
	// H5 — lock-steal race resolution (verify-after-write)
	// -------------------------------------------------------------------------

	/**
	 * Happy path: an expired peer lock is stolen cleanly when no other writer
	 * is competing. Verify-after-write reads our own token and we proceed
	 * with the chunk.
	 */
	public function test_lock_steal_succeeds_when_no_competing_writer(): void {
		$this->queue->start( array( 99999 ), false );

		// Plant an expired lock — peer worker died before releasing.
		$GLOBALS['cf_media_manager_test_options'][ Options::QUEUE_LOCK ] = array(
			'token'   => 'stale-peer',
			'expires' => time() - 100,
		);

		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( array(), $state['pending'], 'pending must drain — we held the lock and processed the id' );
		self::assertSame( 1, $state['processed'] );
		// release_lock() in finally deletes the option since we owned it.
		self::assertArrayNotHasKey(
			Options::QUEUE_LOCK,
			$GLOBALS['cf_media_manager_test_options'],
			'release_lock must remove the option after a successful chunk'
		);
	}

	/**
	 * Race path: an expired lock is contested. Process A writes its token,
	 * process B's write lands immediately after (simulated via the
	 * post-update-swap shim), and A's verify-read sees B's token. A must
	 * return false from acquire_lock and bail out of process_chunk without
	 * touching pending counters OR deleting B's lock.
	 *
	 * Without verify-after-write, A would proceed to process the chunk in
	 * parallel with B — the bug this test guards against.
	 */
	public function test_lock_steal_aborts_when_peer_wins_race(): void {
		$this->queue->start( array( 99999 ), false );

		// Plant an expired lock so the steal path activates.
		$GLOBALS['cf_media_manager_test_options'][ Options::QUEUE_LOCK ] = array(
			'token'   => 'stale-peer',
			'expires' => time() - 100,
		);

		// Arm the race: as soon as acquire_lock's update_option lands, the
		// shim overwrites our token with a peer's "winning" token.
		$GLOBALS['cf_media_manager_test_post_update_swap'][ Options::QUEUE_LOCK ] = array(
			'token'   => 'peer-won',
			'expires' => time() + 300,
		);

		$this->queue->process_chunk();

		$state = $this->queue->state();
		self::assertSame( array( 99999 ), $state['pending'], 'pending must be untouched — we lost the lock race' );
		self::assertSame( 0, $state['processed'] );
		// We must NOT have deleted the peer's lock. release_lock guards
		// against this via $this->lock_token === null on the lost-race path.
		self::assertSame(
			'peer-won',
			$GLOBALS['cf_media_manager_test_options'][ Options::QUEUE_LOCK ]['token'] ?? null,
			'peer\'s lock must remain — we never owned it, so we must not delete it'
		);
	}
}
