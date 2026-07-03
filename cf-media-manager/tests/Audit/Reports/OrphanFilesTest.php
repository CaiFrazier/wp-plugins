<?php

namespace CFMediaManager\Tests\Audit\Reports;

use CFMediaManager\Audit\AuditChunk;
use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Audit\Reports\OrphanFiles;
use CFMediaManager\Audit\ScanContext;
use CFMediaManager\Paths;
use PHPUnit\Framework\TestCase;

/**
 * OrphanFiles diffs the filesystem against the `_wp_attached_file`
 * postmeta set. Tests use a real temp uploads directory populated with a
 * mix of attached files, variants, modern siblings, plugin-managed
 * extensions, and true orphans. A minimal $wpdb double returns the
 * attached-file set for the diff.
 */
final class OrphanFilesTest extends TestCase {

	private string $upload_dir;
	private Paths $paths;
	private IgnoredStore $ignored;
	private OrphanFiles $report;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();

		$this->upload_dir = sys_get_temp_dir() . '/cfmm_orphans_' . uniqid();
		mkdir( $this->upload_dir, 0777, true );

		$this->paths   = new Paths( $this->upload_dir, 'https://example.test/wp-content/uploads' );
		$this->ignored = new IgnoredStore();
		$this->report  = new OrphanFiles( $this->ignored, $this->paths );

		$GLOBALS['fake_attached_files'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['fake_attached_files'] );
		$this->rmrf( $this->upload_dir );
		delete_transient( OrphanFiles::CANDIDATES_TRANSIENT );
		delete_transient( OrphanFiles::TRUNCATED_TRANSIENT );
	}

	// =====================================================================
	// Fixture helpers
	// =====================================================================

	private function put_file( string $rel ): void {
		$abs = $this->upload_dir . '/' . $rel;
		$dir = dirname( $abs );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		file_put_contents( $abs, 'x' );
	}

	private function mark_attached( string $rel ): void {
		$GLOBALS['fake_attached_files'][] = $rel;
	}

	private function install_fake_wpdb(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $postmeta = 'wp_postmeta';

			public function prepare( string $sql, ...$args ): string {
				return $sql;
			}

			public function get_col( string $sql ): array {
				return $GLOBALS['fake_attached_files'] ?? array();
			}
		};
	}

	private function rmrf( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $entry ) {
			if ( $entry->isDir() ) {
				rmdir( $entry->getPathname() );
			} else {
				unlink( $entry->getPathname() );
			}
		}
		rmdir( $path );
	}

	private function scan_to_completion(): array {
		// Loops scan_chunk until is_complete. Returns all collected items.
		$items  = array();
		$cursor = null;
		$priors = array();
		$ctx    = new ScanContext( false, 1000 );
		do {
			$chunk  = $this->report->scan_chunk( $ctx, $cursor, $priors );
			$items  = array_merge( $items, $chunk->items );
			$cursor = $chunk->next_cursor;
			$priors = $chunk->running_totals;
		} while ( ! $chunk->is_complete );
		return $items;
	}

	// =====================================================================
	// Identity
	// =====================================================================

	public function test_report_identity(): void {
		self::assertSame( 'orphan_files', $this->report->id() );
		self::assertNotEmpty( $this->report->label() );
		self::assertSame( array( 'delete', 'ignore', 'unignore' ), $this->report->supports_bulk() );
	}

	// =====================================================================
	// Detection — the core heuristic suite
	// =====================================================================

	public function test_attached_file_is_not_an_orphan(): void {
		$this->put_file( '2024/01/photo.jpg' );
		$this->mark_attached( '2024/01/photo.jpg' );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertSame( array(), $items );
	}

	public function test_thumbnail_variant_is_not_an_orphan(): void {
		$this->put_file( '2024/01/photo.jpg' );
		$this->put_file( '2024/01/photo-300x200.jpg' );
		$this->put_file( '2024/01/photo-1024x768.jpg' );
		$this->mark_attached( '2024/01/photo.jpg' );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertSame( array(), $items );
	}

	public function test_scaled_variant_is_not_an_orphan(): void {
		$this->put_file( '2024/01/photo.jpg' );
		$this->put_file( '2024/01/photo-scaled.jpg' );
		$this->mark_attached( '2024/01/photo.jpg' );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertSame( array(), $items );
	}

	public function test_modern_sibling_of_attached_image_is_not_an_orphan(): void {
		$this->put_file( '2024/01/photo.jpg' );
		$this->put_file( '2024/01/photo.webp' );
		$this->put_file( '2024/01/photo.avif' );
		$this->mark_attached( '2024/01/photo.jpg' );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertSame( array(), $items );
	}

	public function test_scaled_modern_sibling_is_not_an_orphan(): void {
		// CF Media Manager's actual output: foo-scaled.webp where foo.jpg
		// is the attached image. Caught by the scaled+sibling combo branch.
		$this->put_file( '2024/01/photo.jpg' );
		$this->put_file( '2024/01/photo-scaled.webp' );
		$this->mark_attached( '2024/01/photo.jpg' );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertSame( array(), $items );
	}

	public function test_truly_unattached_file_is_an_orphan(): void {
		$this->put_file( '2024/02/orphan.jpg' );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertCount( 1, $items );
		self::assertSame( '2024/02/orphan.jpg', $items[0]['path'] );
		self::assertSame( 'no_attachment_record', $items[0]['why']['reason'] );
		self::assertSame( 'jpg', $items[0]['extension'] );
	}

	public function test_non_media_extensions_are_skipped(): void {
		$this->put_file( '2024/02/orphan.jpg' );
		$this->put_file( '2024/02/log.txt' );
		$this->put_file( '2024/02/notes.html' );
		$this->put_file( 'cache/whatever.cache' );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertCount( 1, $items );
		self::assertSame( '2024/02/orphan.jpg', $items[0]['path'] );
	}

	public function test_orphan_thumbnail_without_base_is_still_an_orphan(): void {
		// foo-300x200.jpg without foo.jpg attached → orphan thumbnail.
		// We intentionally surface this; the user can ignore if they want.
		$this->put_file( '2024/03/abandoned-300x200.jpg' );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		self::assertCount( 1, $items );
		self::assertSame( '2024/03/abandoned-300x200.jpg', $items[0]['path'] );
	}

	public function test_receipt_includes_size_mtime_and_provenance(): void {
		$abs = $this->upload_dir . '/2024/04/big.png';
		mkdir( dirname( $abs ), 0777, true );
		file_put_contents( $abs, str_repeat( 'a', 1500 ) );
		touch( $abs, 1700000000 );

		$this->install_fake_wpdb();
		$items = $this->scan_to_completion();

		self::assertCount( 1, $items );
		$item = $items[0];
		self::assertSame( 1500, $item['size_bytes'] );
		self::assertSame( 1700000000, $item['mtime'] );
		self::assertSame( '2023-11-14', $item['mtime_human'] );
		self::assertContains( 'attached_file_match', $item['why']['checked'] );
		self::assertContains( 'modern_sibling', $item['why']['checked'] );
	}

	public function test_ignored_paths_are_not_flagged(): void {
		$this->put_file( '2024/05/a.jpg' );
		$this->put_file( '2024/05/b.jpg' );
		$this->install_fake_wpdb();

		$this->ignored->ignore( 'orphan_files', '2024/05/a.jpg' );

		$items = $this->scan_to_completion();
		self::assertCount( 1, $items );
		self::assertSame( '2024/05/b.jpg', $items[0]['path'] );
	}

	// =====================================================================
	// Chunked scanning
	// =====================================================================

	public function test_candidate_list_is_cached_across_chunks(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->put_file( "2024/06/orphan-{$i}.jpg" );
		}
		$this->install_fake_wpdb();

		$ctx   = new ScanContext( false, 2 );
		$first = $this->report->scan_chunk( $ctx, null );

		self::assertFalse( $first->is_complete );
		self::assertSame( '2', $first->next_cursor );
		self::assertCount( 2, $first->items );

		// Side transient should now hold the candidate list.
		$cached = get_transient( OrphanFiles::CANDIDATES_TRANSIENT );
		self::assertIsArray( $cached );
		self::assertCount( 5, $cached );

		$second = $this->report->scan_chunk( $ctx, '2', $first->running_totals );
		self::assertFalse( $second->is_complete );
		self::assertCount( 2, $second->items );

		$third = $this->report->scan_chunk( $ctx, '4', $second->running_totals );
		self::assertTrue( $third->is_complete );
		self::assertCount( 1, $third->items );

		// Transient cleared on completion.
		self::assertFalse( get_transient( OrphanFiles::CANDIDATES_TRANSIENT ) );
	}

	public function test_running_totals_accumulate_savings_bytes(): void {
		$abs1 = $this->upload_dir . '/2024/07/big-1.jpg';
		$abs2 = $this->upload_dir . '/2024/07/big-2.jpg';
		mkdir( dirname( $abs1 ), 0777, true );
		file_put_contents( $abs1, str_repeat( 'a', 1000 ) );
		file_put_contents( $abs2, str_repeat( 'b', 2000 ) );

		$this->install_fake_wpdb();
		$items  = $this->scan_to_completion();
		$ctx    = new ScanContext( false, 1000 );
		$single = $this->report->scan_chunk( $ctx, null );

		self::assertSame( 3000, $single->running_totals[ AuditChunk::TOTAL_SAVINGS_BYTES ] );
	}

	// =====================================================================
	// Symlink safety — the walk must not follow links out of the tree
	// =====================================================================

	public function test_symlinked_directory_is_not_followed_out_of_uploads(): void {
		// An external directory holding a jpg that must never surface in the
		// orphan report even though a symlink inside uploads points at it.
		$external = sys_get_temp_dir() . '/cfmm_ext_dir_' . uniqid();
		mkdir( $external, 0777, true );
		file_put_contents( $external . '/secret.jpg', 'x' );

		$link = $this->upload_dir . '/2024/linked';
		mkdir( dirname( $link ), 0777, true );
		if ( ! @symlink( $external, $link ) ) {
			$this->rmrf( $external );
			self::markTestSkipped( 'Filesystem does not support symlinks.' );
		}

		// A genuine in-tree orphan so we can prove the walk still runs.
		$this->put_file( '2024/real-orphan.jpg' );
		$this->install_fake_wpdb();

		$items = $this->scan_to_completion();
		$paths = array_column( $items, 'path' );

		self::assertContains( '2024/real-orphan.jpg', $paths );
		foreach ( $paths as $p ) {
			self::assertStringNotContainsString(
				'secret.jpg',
				$p,
				'A symlinked directory must not be followed out of the uploads tree.'
			);
		}

		unlink( $link );
		$this->rmrf( $external );
	}

	public function test_symlinked_file_is_skipped(): void {
		$external = sys_get_temp_dir() . '/cfmm_ext_file_' . uniqid() . '.jpg';
		file_put_contents( $external, 'x' );

		$link = $this->upload_dir . '/2024/link-to-outside.jpg';
		mkdir( dirname( $link ), 0777, true );
		if ( ! @symlink( $external, $link ) ) {
			@unlink( $external );
			self::markTestSkipped( 'Filesystem does not support symlinks.' );
		}

		$this->install_fake_wpdb();
		$items = $this->scan_to_completion();

		self::assertSame( array(), $items, 'A symlinked file must be skipped, not reported as an orphan.' );

		unlink( $link );
		@unlink( $external );
	}

	public function test_normal_walk_reports_no_truncation(): void {
		$this->put_file( '2024/x.jpg' );
		$this->install_fake_wpdb();
		$this->scan_to_completion();

		self::assertSame( 0, $this->report->last_walk_truncated_at() );
	}

	public function test_evaporated_cache_completes_gracefully(): void {
		$this->put_file( '2024/08/x.jpg' );
		$this->install_fake_wpdb();

		// Simulate a mid-scan cache eviction by feeding a non-null cursor
		// with no transient seeded.
		delete_transient( OrphanFiles::CANDIDATES_TRANSIENT );

		$chunk = $this->report->scan_chunk( new ScanContext( false, 1000 ), 'some-cursor', array() );

		self::assertTrue( $chunk->is_complete );
		self::assertSame( array(), $chunk->items );
	}

	// =====================================================================
	// Bulk delete — the safety-rail-sensitive surface
	// =====================================================================

	public function test_bulk_delete_removes_the_file_from_disk(): void {
		$this->put_file( '2024/09/doomed.jpg' );
		$result = $this->report->bulk_action( 'delete', array( '2024/09/doomed.jpg' ) );

		self::assertTrue( $result->success );
		self::assertSame( 1, $result->processed );
		self::assertFileDoesNotExist( $this->upload_dir . '/2024/09/doomed.jpg' );
	}

	public function test_bulk_delete_skips_missing_files(): void {
		// Parent directory must exist for the containment guard to pass.
		// "File present at scan, gone by delete-time" is the actual race
		// we're modeling — the directory survives the file's removal.
		mkdir( $this->upload_dir . '/2024/10', 0777, true );

		$result = $this->report->bulk_action( 'delete', array( '2024/10/never-existed.jpg' ) );

		self::assertTrue( $result->success );
		self::assertSame( 0, $result->processed );
		self::assertSame( 1, $result->skipped );
	}

	public function test_bulk_delete_rejects_path_traversal(): void {
		// Plant a sentinel OUTSIDE uploads. If the containment guard
		// fails, the report deletes it.
		$sentinel = sys_get_temp_dir() . '/cfmm_sentinel_' . uniqid();
		file_put_contents( $sentinel, 'untouchable' );

		$result = $this->report->bulk_action( 'delete', array( '../../' . basename( $sentinel ) ) );

		// ActionResult::partial returns success=true with errors. The
		// real safety check is the file survives + errors are recorded.
		self::assertSame( 0, $result->processed );
		self::assertNotEmpty( $result->errors );
		self::assertFileExists( $sentinel, 'Sentinel outside uploads must survive a traversal payload.' );

		unlink( $sentinel );
	}

	public function test_bulk_delete_rejects_empty_path(): void {
		$result = $this->report->bulk_action( 'delete', array( '' ) );
		self::assertSame( 0, $result->processed );
		self::assertArrayHasKey( '', $result->errors );
	}

	public function test_bulk_ignore_writes_to_store(): void {
		$result = $this->report->bulk_action( 'ignore', array( '2024/11/a.jpg', '2024/11/b.jpg' ) );

		self::assertTrue( $result->success );
		self::assertSame( 2, $result->processed );
		self::assertTrue( $this->ignored->is_ignored( 'orphan_files', '2024/11/a.jpg' ) );
		self::assertTrue( $this->ignored->is_ignored( 'orphan_files', '2024/11/b.jpg' ) );
	}

	public function test_bulk_unignore_reverses_ignore(): void {
		$this->ignored->ignore( 'orphan_files', '2024/11/c.jpg' );
		$this->report->bulk_action( 'unignore', array( '2024/11/c.jpg' ) );
		self::assertFalse( $this->ignored->is_ignored( 'orphan_files', '2024/11/c.jpg' ) );
	}

	public function test_unknown_action_returns_failure(): void {
		$result = $this->report->bulk_action( 'rm_rf', array( 'x.jpg' ) );
		self::assertFalse( $result->success );
	}

	// =====================================================================
	// CSV export contract
	// =====================================================================

	public function test_csv_columns_label_path_and_size(): void {
		$cols = $this->report->csv_columns();
		$keys = array_column( $cols, 'key' );
		self::assertContains( 'path', $keys );
		self::assertContains( 'size_bytes', $keys );
		self::assertContains( 'mtime_human', $keys );
	}

	public function test_csv_row_flattens_item_in_column_order(): void {
		$cols = $this->report->csv_columns();
		$item = array(
			'path'        => '2024/01/orphan.jpg',
			'extension'   => 'jpg',
			'size_bytes'  => 12345,
			'size_human'  => '12 KB',
			'mtime'       => 1700000000,
			'mtime_human' => '2023-11-14',
		);
		$row = $this->report->csv_row( $item );

		self::assertCount( count( $cols ), $row );
		self::assertSame( '2024/01/orphan.jpg', $row[ array_search( 'path', array_column( $cols, 'key' ), true ) ] );
		self::assertSame( '12345', $row[ array_search( 'size_bytes', array_column( $cols, 'key' ), true ) ] );
	}
}
