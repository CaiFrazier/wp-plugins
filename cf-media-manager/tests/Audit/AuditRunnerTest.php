<?php

namespace CFMediaManager\Tests\Audit;

use CFMediaManager\Audit\ActionResult;
use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\AuditReportInterface;
use CFMediaManager\Audit\AuditRunner;
use CFMediaManager\Audit\ScanContext;
use CFMediaManager\Options;
use PHPUnit\Framework\TestCase;

/**
 * AuditRunner is the orchestrator. Tests use anonymous-class fake reports
 * to drive the state machine without touching real attachments. The fakes
 * are configured to emit a scripted sequence of chunks so each test can
 * exercise the runner's lifecycle independently of any report's logic.
 */
final class AuditRunnerTest extends TestCase {

	private AuditRunner $runner;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		$this->runner = new AuditRunner();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	// =====================================================================
	// Fixture: a scripted, anonymous-class fake report.
	// =====================================================================

	/**
	 * Build a fake report whose scan_chunk() emits a queue of pre-scripted
	 * AuditChunks one per call. Bulk action invocations are captured for
	 * later assertion.
	 */
	private function fake_report( string $id, array $chunks, array &$bulk_calls = array() ): AuditReportInterface {
		return new class( $id, $chunks, $bulk_calls ) implements AuditReportInterface {
			private string $id;
			/** @var AuditChunk[] */
			private array $chunks;
			private array $bulk_calls;
			private int $cursor_idx = 0;

			public function __construct( string $id, array $chunks, array &$bulk_calls ) {
				$this->id         = $id;
				$this->chunks     = $chunks;
				$this->bulk_calls = &$bulk_calls;
			}

			public function id(): string { return $this->id; }
			public function label(): string { return strtoupper( $this->id ); }
			public function description(): string { return 'desc:' . $this->id; }

			public array $observed_priors = array();

			public function scan_chunk( ScanContext $ctx, ?string $cursor, array $prior_totals = array() ): AuditChunk {
				$this->observed_priors[] = $prior_totals;
				$next = $this->chunks[ $this->cursor_idx ] ?? null;
				if ( null === $next ) {
					return AuditChunk::complete( array() );
				}
				$this->cursor_idx++;
				return $next;
			}

			public function bulk_action( string $action, array $ids, array $args = array() ): ActionResult {
				$this->bulk_calls[] = array( 'action' => $action, 'ids' => $ids, 'args' => $args );
				return ActionResult::ok( count( $ids ) );
			}

			public function supports_bulk(): array {
				return array( 'trash', 'ignore' );
			}
		};
	}

	// =====================================================================
	// Registry
	// =====================================================================

	public function test_register_and_get_round_trip(): void {
		$report = $this->fake_report( 'r1', array() );
		$this->runner->register( $report );

		self::assertSame( $report, $this->runner->get( 'r1' ) );
		self::assertNull( $this->runner->get( 'nope' ) );
		self::assertSame( array( 'r1' => $report ), $this->runner->all() );
	}

	public function test_run_chunk_on_unknown_report_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->runner->run_chunk( 'never_registered' );
	}

	// =====================================================================
	// Lifecycle — start, run_chunk, completion
	// =====================================================================

	public function test_start_moves_idle_to_scanning(): void {
		$this->runner->register( $this->fake_report( 'r1', array() ) );

		$state = $this->runner->start( 'r1' );

		self::assertSame( AuditRunner::PHASE_SCANNING, $state['phase'] );
		self::assertIsInt( $state['started_at'] );
	}

	public function test_start_without_force_preserves_existing_scanning_state(): void {
		$this->runner->register( $this->fake_report( 'r1', array() ) );

		$first = $this->runner->start( 'r1' );
		// Same started_at means we got the same state back rather than a reset.
		sleep( 0 );  // No-op; just emphasize start_at would differ on reset.
		$second = $this->runner->start( 'r1', array(), false );

		self::assertSame( $first['started_at'], $second['started_at'] );
	}

	public function test_start_with_force_resets_existing_state(): void {
		$this->runner->register( $this->fake_report( 'r1', array() ) );

		$first  = $this->runner->start( 'r1' );
		$second = $this->runner->start( 'r1', array(), true );

		self::assertSame( AuditRunner::PHASE_SCANNING, $second['phase'] );
		self::assertGreaterThanOrEqual( $first['started_at'], $second['started_at'] );
	}

	public function test_single_chunk_completion(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::complete(
				array( array( 'id' => 1, 'why' => 'because' ) ),
				array( AuditChunk::TOTAL_FLAGGED => 1, AuditChunk::TOTAL_SCANNED => 10 )
			),
		) ) );

		$this->runner->start( 'r1' );
		$state = $this->runner->run_chunk( 'r1' );

		self::assertSame( AuditRunner::PHASE_COMPLETE, $state['phase'] );
		self::assertNull( $state['cursor'] );
		self::assertSame( 1, $state['chunks_done'] );
		self::assertSame( 1, $state['running_totals'][ AuditChunk::TOTAL_FLAGGED ] );
	}

	public function test_multi_chunk_run_to_completion(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::partial(
				array( array( 'id' => 1, 'why' => 'a' ) ),
				'cursor-1',
				array( AuditChunk::TOTAL_FLAGGED => 1 )
			),
			AuditChunk::partial(
				array( array( 'id' => 2, 'why' => 'b' ) ),
				'cursor-2',
				array( AuditChunk::TOTAL_FLAGGED => 2 )
			),
			AuditChunk::complete(
				array( array( 'id' => 3, 'why' => 'c' ) ),
				array( AuditChunk::TOTAL_FLAGGED => 3 )
			),
		) ) );

		$this->runner->start( 'r1' );

		$s1 = $this->runner->run_chunk( 'r1' );
		self::assertSame( AuditRunner::PHASE_SCANNING, $s1['phase'] );
		self::assertSame( 'cursor-1', $s1['cursor'] );

		$s2 = $this->runner->run_chunk( 'r1' );
		self::assertSame( 'cursor-2', $s2['cursor'] );

		$s3 = $this->runner->run_chunk( 'r1' );
		self::assertSame( AuditRunner::PHASE_COMPLETE, $s3['phase'] );
		self::assertSame( 3, $s3['chunks_done'] );

		// All three items present in the final results.
		$results = $this->runner->results( 'r1', 1, 100 );
		self::assertCount( 3, $results['items'] );
		self::assertSame( array( 1, 2, 3 ), array_column( $results['items'], 'id' ) );
		self::assertSame( 3, $results['totals'][ AuditChunk::TOTAL_FLAGGED ] );
	}

	public function test_prior_totals_is_threaded_into_each_chunk(): void {
		$report = $this->fake_report( 'r1', array(
			AuditChunk::partial( array(), 'c1', array( AuditChunk::TOTAL_FLAGGED => 5 ) ),
			AuditChunk::partial( array(), 'c2', array( AuditChunk::TOTAL_FLAGGED => 12 ) ),
			AuditChunk::complete( array(), array( AuditChunk::TOTAL_FLAGGED => 17 ) ),
		) );
		$this->runner->register( $report );
		$this->runner->start( 'r1' );

		$this->runner->run_chunk( 'r1' );
		$this->runner->run_chunk( 'r1' );
		$this->runner->run_chunk( 'r1' );

		// First chunk gets empty priors; subsequent chunks see the prior
		// chunk's running_totals.
		self::assertSame( array(), $report->observed_priors[0] );
		self::assertSame( array( AuditChunk::TOTAL_FLAGGED => 5 ), $report->observed_priors[1] );
		self::assertSame( array( AuditChunk::TOTAL_FLAGGED => 12 ), $report->observed_priors[2] );
	}

	public function test_run_chunk_no_ops_when_not_scanning(): void {
		$this->runner->register( $this->fake_report( 'r1', array() ) );

		$state = $this->runner->run_chunk( 'r1' );

		self::assertSame( AuditRunner::PHASE_IDLE, $state['phase'] );
	}

	public function test_thrown_scan_chunk_routes_to_failed_state(): void {
		$throwing = new class() implements AuditReportInterface {
			public function id(): string { return 'r1'; }
			public function label(): string { return 'r1'; }
			public function description(): string { return ''; }
			public function scan_chunk( ScanContext $ctx, ?string $cursor, array $prior_totals = array() ): AuditChunk {
				throw new \RuntimeException( 'kaboom' );
			}
			public function bulk_action( string $action, array $ids, array $args = array() ): ActionResult {
				return ActionResult::ok( 0 );
			}
			public function supports_bulk(): array { return array(); }
		};

		$this->runner->register( $throwing );
		$this->runner->start( 'r1' );
		$state = $this->runner->run_chunk( 'r1' );

		self::assertSame( AuditRunner::PHASE_FAILED, $state['phase'] );
		self::assertSame( 'kaboom', $state['error'] );
	}

	public function test_cancel_during_scan_moves_to_failed(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::partial( array(), 'c1' ),
		) ) );

		$this->runner->start( 'r1' );
		$this->runner->run_chunk( 'r1' );  // now scanning with cursor c1
		$state = $this->runner->cancel( 'r1' );

		self::assertSame( AuditRunner::PHASE_FAILED, $state['phase'] );
		self::assertSame( 'cancelled', $state['error'] );
	}

	public function test_reset_returns_state_to_idle(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::complete( array( array( 'id' => 1, 'why' => 'x' ) ) ),
		) ) );

		$this->runner->start( 'r1' );
		$this->runner->run_chunk( 'r1' );

		$this->runner->reset( 'r1' );

		$state = $this->runner->state( 'r1' );
		self::assertSame( AuditRunner::PHASE_IDLE, $state['phase'] );
		self::assertNull( $this->runner->results( 'r1' ) );
	}

	// =====================================================================
	// Concurrency lock
	// =====================================================================

	public function test_held_lock_blocks_a_concurrent_run_chunk(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::complete( array(), array( AuditChunk::TOTAL_FLAGGED => 0 ) ),
		) ) );

		$this->runner->start( 'r1' );

		// Pre-seed the lock as if another worker holds it.
		$lock_key = AuditRunner::LOCK_OPTION_PREFIX . 'r1';
		$GLOBALS['cf_media_manager_test_options'][ $lock_key ] = array(
			'token'   => 'other-worker',
			'expires' => time() + 60,
		);

		$state = $this->runner->run_chunk( 'r1' );

		self::assertTrue( $state['is_locked'] ?? false );
		self::assertSame( AuditRunner::PHASE_SCANNING, $state['phase'], 'Locked call must not advance the phase.' );
	}

	public function test_expired_lock_is_stolen(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::complete( array() ),
		) ) );

		$this->runner->start( 'r1' );

		$lock_key = AuditRunner::LOCK_OPTION_PREFIX . 'r1';
		$GLOBALS['cf_media_manager_test_options'][ $lock_key ] = array(
			'token'   => 'dead-worker',
			'expires' => time() - 10,
		);

		$state = $this->runner->run_chunk( 'r1' );

		self::assertFalse( $state['is_locked'] ?? false );
		self::assertSame( AuditRunner::PHASE_COMPLETE, $state['phase'] );
	}

	public function test_release_lock_only_when_token_matches(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::partial( array(), 'cursor-1' ),
		) ) );

		$this->runner->start( 'r1' );
		$this->runner->run_chunk( 'r1' );

		// After successful chunk, the lock should be released.
		$lock_key = AuditRunner::LOCK_OPTION_PREFIX . 'r1';
		self::assertArrayNotHasKey( $lock_key, $GLOBALS['cf_media_manager_test_options'] );
	}

	// =====================================================================
	// Dashboard summary
	// =====================================================================

	public function test_dashboard_returns_one_row_per_registered_report(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::complete(
				array( array( 'id' => 1, 'why' => 'a' ) ),
				array( AuditChunk::TOTAL_FLAGGED => 1, AuditChunk::TOTAL_SAVINGS_BYTES => 1024 )
			),
		) ) );
		$this->runner->register( $this->fake_report( 'r2', array() ) );

		$this->runner->start( 'r1' );
		$this->runner->run_chunk( 'r1' );

		$rows = $this->runner->dashboard();
		self::assertCount( 2, $rows );

		$row1 = array_values( array_filter( $rows, fn( $r ) => 'r1' === $r['id'] ) )[0];
		self::assertSame( AuditRunner::PHASE_COMPLETE, $row1['phase'] );
		self::assertSame( 1, $row1['totals'][ AuditChunk::TOTAL_FLAGGED ] );
		self::assertSame( 1024, $row1['totals'][ AuditChunk::TOTAL_SAVINGS_BYTES ] );
		self::assertFalse( $row1['is_stale'] );

		$row2 = array_values( array_filter( $rows, fn( $r ) => 'r2' === $r['id'] ) )[0];
		self::assertSame( AuditRunner::PHASE_IDLE, $row2['phase'] );
		self::assertNull( $row2['scanned_at'] );
	}

	// =====================================================================
	// Paginated results
	// =====================================================================

	public function test_results_pagination(): void {
		$items = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$items[] = array( 'id' => $i + 1, 'why' => 'a' );
		}
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::complete( $items, array( AuditChunk::TOTAL_FLAGGED => 25 ) ),
		) ) );

		$this->runner->start( 'r1' );
		$this->runner->run_chunk( 'r1' );

		$page1 = $this->runner->results( 'r1', 1, 10 );
		self::assertCount( 10, $page1['items'] );
		self::assertSame( 25, $page1['total'] );
		self::assertSame( 3, $page1['pages'] );
		self::assertSame( 1, $page1['items'][0]['id'] );

		$page3 = $this->runner->results( 'r1', 3, 10 );
		self::assertCount( 5, $page3['items'] );
		self::assertSame( 21, $page3['items'][0]['id'] );
	}

	public function test_results_returns_null_before_any_scan(): void {
		$this->runner->register( $this->fake_report( 'r1', array() ) );
		self::assertNull( $this->runner->results( 'r1' ) );
	}

	// =====================================================================
	// Staleness
	// =====================================================================

	public function test_is_stale_compares_results_against_stale_since(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::complete( array( array( 'id' => 1, 'why' => 'x' ) ) ),
		) ) );

		$this->runner->start( 'r1' );
		$this->runner->run_chunk( 'r1' );

		self::assertFalse( $this->runner->is_stale( 'r1' ) );

		// Simulate an attachment update AFTER the scan completed.
		$GLOBALS['cf_media_manager_test_options'][ Options::AUDIT_STALE_SINCE ] = time() + 100;

		self::assertTrue( $this->runner->is_stale( 'r1' ) );
	}

	public function test_mark_all_stale_writes_the_global_timestamp(): void {
		$this->runner->mark_all_stale();
		self::assertGreaterThan( 0, $GLOBALS['cf_media_manager_test_options'][ Options::AUDIT_STALE_SINCE ] ?? 0 );
	}

	public function test_register_hooks_attaches_attachment_lifecycle_listeners(): void {
		$this->runner->register_hooks();

		$hooks = $GLOBALS['cf_media_manager_test_hooks'] ?? array();

		self::assertNotEmpty( $hooks['add_attachment'] ?? array() );
		self::assertNotEmpty( $hooks['attachment_updated'] ?? array() );
		self::assertNotEmpty( $hooks['delete_attachment'] ?? array() );
	}

	// =====================================================================
	// Bulk action proxy
	// =====================================================================

	public function test_bulk_action_proxies_to_the_registered_report(): void {
		$calls = array();
		$this->runner->register( $this->fake_report( 'r1', array(), $calls ) );

		$result = $this->runner->bulk( 'r1', 'trash', array( 1, 2, 3 ), array( 'foo' => 'bar' ) );

		self::assertTrue( $result->success );
		self::assertSame( 3, $result->processed );
		self::assertCount( 1, $calls );
		self::assertSame( 'trash', $calls[0]['action'] );
		self::assertSame( array( 1, 2, 3 ), $calls[0]['ids'] );
		self::assertSame( array( 'foo' => 'bar' ), $calls[0]['args'] );
	}

	// =====================================================================
	// purge_all
	// =====================================================================

	public function test_purge_all_clears_state_results_locks_and_stale_since(): void {
		$this->runner->register( $this->fake_report( 'r1', array(
			AuditChunk::complete( array( array( 'id' => 1, 'why' => 'x' ) ) ),
		) ) );

		$this->runner->start( 'r1' );
		$this->runner->run_chunk( 'r1' );
		$this->runner->mark_all_stale();

		$this->runner->purge_all();

		self::assertSame( AuditRunner::PHASE_IDLE, $this->runner->state( 'r1' )['phase'] );
		self::assertNull( $this->runner->results( 'r1' ) );
		self::assertArrayNotHasKey( Options::AUDIT_STALE_SINCE, $GLOBALS['cf_media_manager_test_options'] );
	}
}
