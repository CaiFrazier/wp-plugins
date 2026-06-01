<?php

namespace CFMediaManager\Tests\Audit\Reports;

use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Audit\Reports\UnusedAttachments;
use CFMediaManager\Audit\ScanContext;
use PHPUnit\Framework\TestCase;

/**
 * UnusedAttachments inverts InUseScanner's output. Tests inject a
 * fake in-use-callback so the suite is decoupled from the full
 * scanner — we exercise the diff logic, snapshot caching, and
 * receipt shape directly.
 */
final class UnusedAttachmentsTest extends TestCase {

	private IgnoredStore $ignored;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		$this->ignored                       = new IgnoredStore();
		$GLOBALS['fake_wpdb_attachments']    = array();
		$GLOBALS['fake_in_use_call_count']   = 0;
		$GLOBALS['fake_in_use_last_force']   = null;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['wpdb'],
			$GLOBALS['fake_wpdb_attachments'],
			$GLOBALS['fake_in_use_call_count'],
			$GLOBALS['fake_in_use_last_force']
		);
		delete_transient( UnusedAttachments::SNAPSHOT_TRANSIENT );
	}

	// =====================================================================
	// Fixtures
	// =====================================================================

	/**
	 * Build a fresh report whose in-use callback returns the provided
	 * fixture. Records each call into the GLOBALS counters so tests can
	 * assert "snapshot was reused / scanner was hit once."
	 */
	private function make_report( array $in_use_ids, array $extra = array() ): UnusedAttachments {
		$callback = function ( bool $force ) use ( $in_use_ids, $extra ) {
			$GLOBALS['fake_in_use_call_count']++;
			$GLOBALS['fake_in_use_last_force'] = $force;
			return array_merge(
				array(
					'ids'        => $in_use_ids,
					'scanned_at' => time() - 60,
					'builders'   => array( 'divi' => false, 'elementor' => false ),
					'sources'    => array(),
				),
				$extra
			);
		};
		return new UnusedAttachments( $this->ignored, $callback );
	}

	private function add_attachment( int $id, array $overrides = array() ): void {
		$GLOBALS['fake_wpdb_attachments'][ $id ] = array_merge(
			array(
				'ID'             => $id,
				'post_title'     => 'attachment-' . $id,
				'post_parent'    => 0,
				'post_date'      => '2024-01-01 00:00:00',
				'post_mime_type' => 'image/jpeg',
			),
			$overrides
		);
	}

	private function install_fake_wpdb(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';

			public function prepare( string $sql, ...$args ): string {
				return $sql . '|args=' . implode( ',', array_map( 'strval', $args ) );
			}

			public function get_results( string $sql, $output ): array {
				if ( ! preg_match( '/\|args=(\d+),(\d+)$/', $sql, $m ) ) {
					return array();
				}
				$after_id = (int) $m[1];
				$limit    = (int) $m[2];

				$matches = array();
				foreach ( $GLOBALS['fake_wpdb_attachments'] as $att ) {
					if ( (int) $att['ID'] > $after_id ) {
						$matches[] = $att;
					}
				}
				usort( $matches, fn( $a, $b ) => (int) $a['ID'] <=> (int) $b['ID'] );
				return array_slice( $matches, 0, $limit );
			}
		};
	}

	// =====================================================================
	// Identity
	// =====================================================================

	public function test_report_identity(): void {
		$report = $this->make_report( array() );
		self::assertSame( 'unused_attachments', $report->id() );
		self::assertNotEmpty( $report->label() );
		self::assertNotEmpty( $report->description() );
		self::assertSame( array( 'trash', 'ignore', 'unignore' ), $report->supports_bulk() );
	}

	public function test_checked_sources_covers_every_in_use_scanner_lane(): void {
		$lanes = UnusedAttachments::checked_sources();
		foreach ( array( 'post_content', 'featured', 'divi', 'elementor', 'beaver', 'bricks', 'wpbakery', 'acf', 'woocommerce', 'widgets' ) as $expected ) {
			self::assertContains( $expected, $lanes, "checked_sources is missing '{$expected}'" );
		}
	}

	// =====================================================================
	// Detection
	// =====================================================================

	public function test_referenced_attachments_are_not_flagged(): void {
		$this->add_attachment( 1 );
		$this->add_attachment( 2 );
		$this->install_fake_wpdb();

		$report = $this->make_report( array( 1, 2 ) );
		$chunk  = $report->scan_chunk( new ScanContext(), null );

		self::assertTrue( $chunk->is_complete );
		self::assertSame( array(), $chunk->items );
		self::assertSame( 2, $chunk->running_totals[ AuditChunk::TOTAL_SCANNED ] );
		self::assertSame( 0, $chunk->running_totals[ AuditChunk::TOTAL_FLAGGED ] );
	}

	public function test_unreferenced_attachments_are_flagged(): void {
		$this->add_attachment( 10 );
		$this->add_attachment( 20 );
		$this->add_attachment( 30 );
		$this->install_fake_wpdb();

		// Only id=20 is in use.
		$report = $this->make_report( array( 20 ) );
		$chunk  = $report->scan_chunk( new ScanContext(), null );

		self::assertSame( 2, $chunk->running_totals[ AuditChunk::TOTAL_FLAGGED ] );
		self::assertSame( array( 10, 30 ), array_column( $chunk->items, 'id' ) );
	}

	public function test_ignored_attachments_are_skipped(): void {
		$this->add_attachment( 100 );
		$this->add_attachment( 101 );
		$this->install_fake_wpdb();

		$this->ignored->ignore( 'unused_attachments', 100 );

		$report = $this->make_report( array() );
		$chunk  = $report->scan_chunk( new ScanContext(), null );

		self::assertSame( array( 101 ), array_column( $chunk->items, 'id' ) );
	}

	// =====================================================================
	// Receipt — the differentiator
	// =====================================================================

	public function test_receipt_lists_every_checked_source(): void {
		$this->add_attachment( 7 );
		$this->install_fake_wpdb();

		$report = $this->make_report( array() );
		$item   = $report->scan_chunk( new ScanContext(), null )->items[0];

		$lanes = UnusedAttachments::checked_sources();
		self::assertSame( $lanes, $item['why']['checked'] );
		self::assertNotEmpty( $item['why']['checked'] );
	}

	public function test_receipt_includes_builders_active_map(): void {
		$this->add_attachment( 8 );
		$this->install_fake_wpdb();

		$report = $this->make_report( array(), array(
			'builders' => array( 'divi' => true, 'elementor' => false, 'bricks' => true ),
		) );
		$item   = $report->scan_chunk( new ScanContext(), null )->items[0];

		self::assertSame(
			array( 'divi' => true, 'elementor' => false, 'bricks' => true ),
			$item['why']['builders_active']
		);
	}

	public function test_receipt_carries_scan_age(): void {
		$this->add_attachment( 9 );
		$this->install_fake_wpdb();

		$report = $this->make_report( array() );
		$item   = $report->scan_chunk( new ScanContext(), null )->items[0];

		self::assertIsInt( $item['why']['scan_age_seconds'] );
		self::assertGreaterThanOrEqual( 0, $item['why']['scan_age_seconds'] );
		self::assertSame( 'not_referenced_anywhere', $item['why']['reason'] );
	}

	public function test_receipt_pulls_size_from_metadata_filesize(): void {
		$this->add_attachment( 42 );
		$GLOBALS['cf_media_manager_test_post_meta'][42]['_wp_attachment_metadata'] = array(
			'filesize' => 2_500_000,
		);
		$this->install_fake_wpdb();

		$report = $this->make_report( array() );
		$item   = $report->scan_chunk( new ScanContext(), null )->items[0];

		self::assertSame( 2_500_000, $item['size_bytes'] );
	}

	// =====================================================================
	// Snapshot — locks the in-use set against mid-scan drift
	// =====================================================================

	public function test_snapshot_is_created_on_first_chunk(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->add_attachment( $i );
		}
		$this->install_fake_wpdb();

		$report = $this->make_report( array( 1, 2 ) );
		$ctx    = new ScanContext( false, 2 );

		$first = $report->scan_chunk( $ctx, null );
		self::assertFalse( $first->is_complete );

		// Snapshot transient should now exist.
		$snapshot = get_transient( UnusedAttachments::SNAPSHOT_TRANSIENT );
		self::assertIsArray( $snapshot );
		self::assertArrayHasKey( 'ids_set', $snapshot );
		self::assertSame( array( 1 => true, 2 => true ), $snapshot['ids_set'] );
	}

	public function test_subsequent_chunks_reuse_snapshot_without_re_querying_scanner(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->add_attachment( $i );
		}
		$this->install_fake_wpdb();

		$report = $this->make_report( array( 1 ) );
		$ctx    = new ScanContext( false, 2 );

		$first = $report->scan_chunk( $ctx, null );
		self::assertSame( 1, $GLOBALS['fake_in_use_call_count'] );

		$report->scan_chunk( $ctx, $first->next_cursor, $first->running_totals );

		// Second chunk MUST NOT trigger a fresh scanner call — that's the
		// drift-resistance property the snapshot was designed for.
		self::assertSame( 1, $GLOBALS['fake_in_use_call_count'] );
	}

	public function test_snapshot_is_cleared_on_scan_completion(): void {
		$this->add_attachment( 1 );
		$this->install_fake_wpdb();

		$report = $this->make_report( array() );
		$report->scan_chunk( new ScanContext(), null );

		self::assertFalse( get_transient( UnusedAttachments::SNAPSHOT_TRANSIENT ) );
	}

	public function test_force_flag_propagates_to_first_scanner_call_only(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->add_attachment( $i );
		}
		$this->install_fake_wpdb();

		$report = $this->make_report( array() );
		$ctx    = new ScanContext( true, 1 );  // force=true, chunk_size=1

		$first  = $report->scan_chunk( $ctx, null );
		self::assertTrue( $GLOBALS['fake_in_use_last_force'], 'First chunk must propagate force=true.' );

		// Subsequent chunks should reuse the snapshot, never re-call the
		// scanner — and certainly never re-force.
		$report->scan_chunk( $ctx, $first->next_cursor, $first->running_totals );
		self::assertSame( 1, $GLOBALS['fake_in_use_call_count'] );
	}

	// =====================================================================
	// Chunked totals accumulation
	// =====================================================================

	public function test_running_totals_accumulate_across_chunks(): void {
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->add_attachment( $i );
		}
		$this->install_fake_wpdb();

		$report = $this->make_report( array() );  // all 4 are unused
		$ctx    = new ScanContext( false, 2 );

		$first  = $report->scan_chunk( $ctx, null );
		self::assertSame( 2, $first->running_totals[ AuditChunk::TOTAL_FLAGGED ] );

		$second = $report->scan_chunk( $ctx, $first->next_cursor, $first->running_totals );
		self::assertSame( 4, $second->running_totals[ AuditChunk::TOTAL_FLAGGED ] );
		self::assertSame( 4, $second->running_totals[ AuditChunk::TOTAL_SCANNED ] );
	}

	// =====================================================================
	// Bulk actions
	// =====================================================================

	public function test_bulk_trash_uses_force_false_recoverable_path(): void {
		$report = $this->make_report( array() );
		$result = $report->bulk_action( 'trash', array( 11, 22 ) );

		self::assertTrue( $result->success );
		self::assertSame( 2, $result->processed );

		foreach ( $GLOBALS['cf_media_manager_test_deleted_attachments'] as $call ) {
			self::assertFalse( $call['force'], 'Trash must NEVER bypass to permanent delete.' );
		}
	}

	public function test_bulk_trash_captures_per_item_errors(): void {
		$GLOBALS['cf_media_manager_test_delete_attachment_returns'][50] = false;

		$report = $this->make_report( array() );
		$result = $report->bulk_action( 'trash', array( 40, 50 ) );

		self::assertSame( 1, $result->processed );
		self::assertArrayHasKey( 50, $result->errors );
	}

	public function test_bulk_ignore_round_trip(): void {
		$report = $this->make_report( array() );
		$report->bulk_action( 'ignore', array( 5, 6 ) );

		self::assertTrue( $this->ignored->is_ignored( 'unused_attachments', 5 ) );
		self::assertTrue( $this->ignored->is_ignored( 'unused_attachments', 6 ) );

		$report->bulk_action( 'unignore', array( 5 ) );
		self::assertFalse( $this->ignored->is_ignored( 'unused_attachments', 5 ) );
	}

	public function test_unknown_action_returns_failure(): void {
		$result = $this->make_report( array() )->bulk_action( 'rm_rf', array( 1 ) );
		self::assertFalse( $result->success );
	}

	public function test_invalid_ids_in_bulk_trash_are_rejected(): void {
		$report = $this->make_report( array() );
		$result = $report->bulk_action( 'trash', array( 0, -3, 'abc' ) );

		self::assertSame( 0, $result->processed );
		self::assertCount( 3, $result->errors );
	}
}
