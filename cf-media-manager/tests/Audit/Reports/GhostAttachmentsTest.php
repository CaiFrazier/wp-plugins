<?php

namespace CFMediaManager\Tests\Audit\Reports;

use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Audit\Reports\GhostAttachments;
use CFMediaManager\Audit\ScanContext;
use PHPUnit\Framework\TestCase;

/**
 * GhostAttachments detects DB attachment rows whose underlying file is
 * missing from disk. Tests use:
 *   - real temp files for "live" attachments so file_exists() returns true
 *   - missing paths for "ghost" attachments so file_exists() returns false
 *   - a minimal $wpdb test double that responds to the two SQL shapes
 *     the report emits (batch attachment query + _thumbnail_id reverse
 *     lookup)
 */
final class GhostAttachmentsTest extends TestCase {

	private GhostAttachments $report;
	private IgnoredStore $ignored;

	/** @var string[] Temp files to clean up after each test. */
	private array $temp_files = array();

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		// Ad-hoc test globals used by the inline $wpdb double below.
		// These aren't part of Manager's general reset surface because
		// they're scoped to this file. Clear them per test so fixtures
		// from earlier tests don't bleed into later assertions.
		$GLOBALS['fake_wpdb_attachments']    = array();
		$GLOBALS['fake_wpdb_thumbnail_meta'] = array();
		$this->ignored = new IgnoredStore();
		$this->report  = new GhostAttachments( $this->ignored );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['wpdb'],
			$GLOBALS['fake_wpdb_attachments'],
			$GLOBALS['fake_wpdb_thumbnail_meta']
		);
		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->temp_files = array();
	}

	// =====================================================================
	// Helpers
	// =====================================================================

	/**
	 * Register an attachment that has a real file on disk (not a ghost).
	 */
	private function add_live_attachment( int $id, array $row_overrides = array() ): void {
		$path = tempnam( sys_get_temp_dir(), 'cfmm_live' );
		file_put_contents( $path, 'x' );
		$this->temp_files[] = $path;

		$GLOBALS['cf_media_manager_test_attachments'][ $id ]      = array( 'file' => $path );
		$GLOBALS['fake_wpdb_attachments'][ $id ] = array_merge(
			array(
				'ID'             => $id,
				'post_title'     => 'live-' . $id,
				'post_parent'    => 0,
				'post_date'      => '2024-01-01 00:00:00',
				'post_mime_type' => 'image/jpeg',
			),
			$row_overrides
		);
	}

	/**
	 * Register an attachment whose file is missing on disk (a ghost).
	 */
	private function add_ghost_attachment( int $id, array $row_overrides = array() ): void {
		// get_attached_file returns this path; file_exists() is false.
		$GLOBALS['cf_media_manager_test_attachments'][ $id ] = array(
			'file' => '/nonexistent/' . uniqid() . '/' . $id . '.jpg',
		);
		$GLOBALS['cf_media_manager_test_post_meta'][ $id ]['_wp_attached_file']
			= '2024/01/ghost-' . $id . '.jpg';
		$GLOBALS['fake_wpdb_attachments'][ $id ] = array_merge(
			array(
				'ID'             => $id,
				'post_title'     => 'ghost-' . $id,
				'post_parent'    => 0,
				'post_date'      => '2024-01-01 00:00:00',
				'post_mime_type' => 'image/jpeg',
			),
			$row_overrides
		);
	}

	/**
	 * Install the wpdb double. Reads from $GLOBALS['fake_wpdb_attachments']
	 * and $GLOBALS['fake_wpdb_thumbnail_meta'] so each test can seed the
	 * dataset before calling scan_chunk.
	 */
	private function install_fake_wpdb(): void {
		$GLOBALS['fake_wpdb_attachments']    = $GLOBALS['fake_wpdb_attachments']    ?? array();
		$GLOBALS['fake_wpdb_thumbnail_meta'] = $GLOBALS['fake_wpdb_thumbnail_meta'] ?? array();

		$GLOBALS['wpdb'] = new class() {
			public string $posts    = 'wp_posts';
			public string $postmeta = 'wp_postmeta';

			public function prepare( string $sql, ...$args ): string {
				// Append a sentinel so get_results / get_col can recover args.
				return $sql . '|args=' . implode( ',', array_map( 'strval', $args ) );
			}

			public function get_results( string $sql, $output ): array {
				// The report's batch query: " WHERE ID > %d ... LIMIT %d "
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

			public function get_col( string $sql ): array {
				// The report's featured-image reverse lookup: meta_value=%d LIMIT %d.
				if ( ! preg_match( '/\|args=(\d+),\d+$/', $sql, $m ) ) {
					return array();
				}
				$att_id = (int) $m[1];
				return $GLOBALS['fake_wpdb_thumbnail_meta'][ $att_id ] ?? array();
			}
		};
	}

	private function scan_once( array $config_overrides = array() ): AuditChunk {
		$ctx = new ScanContext( false, 500, $config_overrides );
		return $this->report->scan_chunk( $ctx, null );
	}

	// =====================================================================
	// Identity
	// =====================================================================

	public function test_report_identity(): void {
		self::assertSame( 'ghost_attachments', $this->report->id() );
		self::assertNotEmpty( $this->report->label() );
		self::assertNotEmpty( $this->report->description() );
		self::assertSame( array( 'trash', 'ignore', 'unignore' ), $this->report->supports_bulk() );
	}

	// =====================================================================
	// Detection
	// =====================================================================

	public function test_chunk_is_complete_when_no_wpdb(): void {
		// $wpdb deliberately not installed.
		$chunk = $this->scan_once();
		self::assertTrue( $chunk->is_complete );
		self::assertSame( array(), $chunk->items );
	}

	public function test_chunk_is_complete_with_zero_attachments(): void {
		$this->install_fake_wpdb();
		$chunk = $this->scan_once();
		self::assertTrue( $chunk->is_complete );
		self::assertSame( array(), $chunk->items );
	}

	public function test_live_attachments_are_skipped(): void {
		$this->add_live_attachment( 1 );
		$this->add_live_attachment( 2 );
		$this->install_fake_wpdb();

		$chunk = $this->scan_once();

		self::assertCount( 0, $chunk->items );
		self::assertSame( 2, $chunk->running_totals[ AuditChunk::TOTAL_SCANNED ] );
		self::assertSame( 0, $chunk->running_totals[ AuditChunk::TOTAL_FLAGGED ] );
	}

	public function test_missing_files_are_flagged_as_ghosts(): void {
		$this->add_live_attachment( 1 );
		$this->add_ghost_attachment( 2 );
		$this->add_ghost_attachment( 3, array( 'post_parent' => 99 ) );
		$this->install_fake_wpdb();

		$chunk = $this->scan_once();

		self::assertCount( 2, $chunk->items );
		self::assertSame( 3, $chunk->running_totals[ AuditChunk::TOTAL_SCANNED ] );
		self::assertSame( 2, $chunk->running_totals[ AuditChunk::TOTAL_FLAGGED ] );

		// Ordered by ID ASC.
		self::assertSame( 2, $chunk->items[0]['id'] );
		self::assertSame( 3, $chunk->items[1]['id'] );
	}

	public function test_receipt_shape_is_complete(): void {
		$this->add_ghost_attachment( 42, array(
			'post_parent'    => 7,
			'post_title'     => 'Old hero image',
			'post_mime_type' => 'image/png',
			'post_date'      => '2022-04-12 10:30:00',
		) );
		$GLOBALS['fake_wpdb_thumbnail_meta'][42] = array( 7, 11 );
		$this->install_fake_wpdb();

		$chunk = $this->scan_once();
		$item  = $chunk->items[0];

		self::assertSame( 42, $item['id'] );
		self::assertSame( 'Old hero image', $item['title'] );
		self::assertSame( 'image/png', $item['mime'] );
		self::assertSame( '2022-04-12 10:30:00', $item['date_uploaded'] );
		self::assertSame( '2024/01/ghost-42.jpg', $item['attached_file_meta'] );
		self::assertStringContainsString( '42.jpg', $item['expected_path'] );

		// Why receipt — the differentiator.
		self::assertSame( 'file_missing', $item['why']['reason'] );
		self::assertSame( array( 'get_attached_file', 'file_exists' ), $item['why']['checked'] );
		self::assertSame( 7, $item['why']['attached_to_post_id'] );
		self::assertSame( array( 7, 11 ), $item['why']['featured_for_post_ids'] );
		self::assertTrue( $item['why']['has_active_references'] );
	}

	public function test_unreferenced_ghost_has_no_active_references_flag(): void {
		$this->add_ghost_attachment( 10 );  // post_parent=0, no featured_for
		$this->install_fake_wpdb();

		$chunk = $this->scan_once();
		$item  = $chunk->items[0];

		self::assertSame( 0, $item['why']['attached_to_post_id'] );
		self::assertSame( array(), $item['why']['featured_for_post_ids'] );
		self::assertFalse( $item['why']['has_active_references'] );
	}

	public function test_ignored_attachments_are_not_flagged(): void {
		$this->add_ghost_attachment( 5 );
		$this->add_ghost_attachment( 6 );
		$this->install_fake_wpdb();

		$this->ignored->ignore( 'ghost_attachments', 5 );

		$chunk = $this->scan_once();

		self::assertCount( 1, $chunk->items );
		self::assertSame( 6, $chunk->items[0]['id'] );
	}

	// =====================================================================
	// Chunked scanning
	// =====================================================================

	public function test_chunk_returns_partial_when_batch_filled(): void {
		// 5 attachments + chunk_size=3 ⇒ first call partial, cursor at 3.
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->add_ghost_attachment( $i );
		}
		$this->install_fake_wpdb();

		$ctx   = new ScanContext( false, 3 );
		$first = $this->report->scan_chunk( $ctx, null );

		self::assertFalse( $first->is_complete );
		self::assertSame( '3', $first->next_cursor );
		self::assertSame( 3, $first->running_totals[ AuditChunk::TOTAL_SCANNED ] );

		$second = $this->report->scan_chunk( $ctx, $first->next_cursor, $first->running_totals );

		self::assertTrue( $second->is_complete );
		self::assertNull( $second->next_cursor );
		self::assertSame( 5, $second->running_totals[ AuditChunk::TOTAL_SCANNED ] );
		self::assertSame( 5, $second->running_totals[ AuditChunk::TOTAL_FLAGGED ] );
	}

	public function test_prior_totals_carry_forward(): void {
		$this->add_ghost_attachment( 1 );
		$this->add_ghost_attachment( 2 );
		$this->install_fake_wpdb();

		$prior = array(
			AuditChunk::TOTAL_FLAGGED => 100,
			AuditChunk::TOTAL_SCANNED => 500,
		);
		$chunk = $this->report->scan_chunk( new ScanContext(), null, $prior );

		self::assertSame( 102, $chunk->running_totals[ AuditChunk::TOTAL_FLAGGED ] );
		self::assertSame( 502, $chunk->running_totals[ AuditChunk::TOTAL_SCANNED ] );
	}

	// =====================================================================
	// Bulk actions
	// =====================================================================

	public function test_bulk_trash_routes_to_wp_delete_attachment_with_force_false(): void {
		$result = $this->report->bulk_action( 'trash', array( 11, 22, 33 ) );

		self::assertTrue( $result->success );
		self::assertSame( 3, $result->processed );

		$calls = $GLOBALS['cf_media_manager_test_deleted_attachments'];
		self::assertCount( 3, $calls );
		foreach ( $calls as $call ) {
			self::assertFalse( $call['force'], 'Trash must NEVER bypass to permanent delete.' );
		}
	}

	public function test_bulk_trash_captures_per_item_failures(): void {
		// Pre-script: id=10 succeeds (default), id=20 returns false.
		$GLOBALS['cf_media_manager_test_delete_attachment_returns'][20] = false;

		$result = $this->report->bulk_action( 'trash', array( 10, 20 ) );

		self::assertSame( 1, $result->processed );
		self::assertArrayHasKey( 20, $result->errors );
	}

	public function test_bulk_trash_rejects_invalid_ids(): void {
		$result = $this->report->bulk_action( 'trash', array( 0, -5, 'not-a-number' ) );

		self::assertSame( 0, $result->processed );
		self::assertCount( 3, $result->errors );
	}

	public function test_bulk_ignore_writes_to_store(): void {
		$result = $this->report->bulk_action( 'ignore', array( 7, 8 ) );

		self::assertTrue( $result->success );
		self::assertSame( 2, $result->processed );
		self::assertTrue( $this->ignored->is_ignored( 'ghost_attachments', 7 ) );
		self::assertTrue( $this->ignored->is_ignored( 'ghost_attachments', 8 ) );
	}

	public function test_bulk_unignore_reverses_ignore(): void {
		$this->ignored->ignore( 'ghost_attachments', 9 );
		$this->report->bulk_action( 'unignore', array( 9 ) );
		self::assertFalse( $this->ignored->is_ignored( 'ghost_attachments', 9 ) );
	}

	public function test_unknown_bulk_action_returns_failure(): void {
		$result = $this->report->bulk_action( 'rm_rf', array( 1 ) );

		self::assertFalse( $result->success );
		self::assertNotEmpty( $result->message );
	}
}
