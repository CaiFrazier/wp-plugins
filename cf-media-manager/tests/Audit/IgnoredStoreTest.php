<?php

namespace CFMediaManager\Tests\Audit;

use CFMediaManager\Audit\IgnoredStore;
use CFMediaManager\Options;
use PHPUnit\Framework\TestCase;

/**
 * IgnoredStore is the hybrid postmeta + option-blob backing for ignored
 * items. Tests cover both backends, the (report, key) merge semantics,
 * path normalization, and uninstall purge.
 *
 * The postmeta side is exercised through the bootstrap's get/update/delete
 * shims (which read $GLOBALS['cf_media_manager_test_post_meta']). The
 * option side runs through the bootstrap's options shim.
 *
 * list_ignored() also queries $wpdb directly for the attachment-ID side.
 * Manager's bootstrap deliberately omits $wpdb, so list_ignored() degrades
 * to "paths only" by design (the isset($wpdb) guard). The dedicated
 * `test_list_ignored_returns_attachment_ids_via_wpdb` case installs a
 * minimal wpdb double to verify the SQL path.
 */
final class IgnoredStoreTest extends TestCase {

	private IgnoredStore $store;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		$this->store = new IgnoredStore();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	// =====================================================================
	// Attachment-ID side (postmeta-backed)
	// =====================================================================

	public function test_ignoring_an_attachment_id_writes_postmeta(): void {
		$this->store->ignore( 'unused_attachments', 42 );

		$bag = $GLOBALS['cf_media_manager_test_post_meta'][42][ IgnoredStore::POSTMETA_KEY ] ?? null;
		self::assertIsArray( $bag );
		self::assertArrayHasKey( 'unused_attachments', $bag );
		self::assertArrayHasKey( 'ignored_at', $bag['unused_attachments'] );
	}

	public function test_is_ignored_reads_back_an_attachment_id(): void {
		$this->store->ignore( 'unused_attachments', 42 );
		self::assertTrue( $this->store->is_ignored( 'unused_attachments', 42 ) );
		self::assertFalse( $this->store->is_ignored( 'unused_attachments', 43 ) );
		self::assertFalse( $this->store->is_ignored( 'other_report', 42 ) );
	}

	public function test_multiple_reports_on_same_attachment_coexist(): void {
		$this->store->ignore( 'unused_attachments', 42 );
		$this->store->ignore( 'duplicates', 42 );

		self::assertTrue( $this->store->is_ignored( 'unused_attachments', 42 ) );
		self::assertTrue( $this->store->is_ignored( 'duplicates', 42 ) );
	}

	public function test_re_ignoring_refreshes_timestamp_and_merges_meta(): void {
		$this->store->ignore( 'duplicates', 42, array( 'reason' => 'manual' ) );
		$first = $this->store->meta( 'duplicates', 42 );

		$this->store->ignore( 'duplicates', 42, array( 'note' => 'kept original' ) );
		$second = $this->store->meta( 'duplicates', 42 );

		self::assertSame( 'manual', $second['reason'], 'Pre-existing meta keys survive a re-ignore.' );
		self::assertSame( 'kept original', $second['note'], 'New meta keys merge in.' );
		self::assertGreaterThanOrEqual( $first['ignored_at'], $second['ignored_at'] );
	}

	public function test_unignoring_an_attachment_removes_only_that_report(): void {
		$this->store->ignore( 'unused_attachments', 42 );
		$this->store->ignore( 'duplicates', 42 );

		$this->store->unignore( 'duplicates', 42 );

		self::assertTrue( $this->store->is_ignored( 'unused_attachments', 42 ) );
		self::assertFalse( $this->store->is_ignored( 'duplicates', 42 ) );
	}

	public function test_unignoring_the_last_report_deletes_the_postmeta_row(): void {
		$this->store->ignore( 'unused_attachments', 42 );
		$this->store->unignore( 'unused_attachments', 42 );

		self::assertArrayNotHasKey(
			IgnoredStore::POSTMETA_KEY,
			$GLOBALS['cf_media_manager_test_post_meta'][42] ?? array(),
			'Empty bag should drop the postmeta row, not leave an empty array behind.'
		);
	}

	public function test_unignore_is_a_noop_for_unknown_attachment(): void {
		// Should not raise, should leave postmeta empty.
		$this->store->unignore( 'unused_attachments', 999 );
		self::assertArrayNotHasKey( 999, $GLOBALS['cf_media_manager_test_post_meta'] );
	}

	public function test_meta_returns_null_for_unignored_attachment(): void {
		self::assertNull( $this->store->meta( 'unused_attachments', 42 ) );
	}

	// =====================================================================
	// Path-key side (option-blob-backed)
	// =====================================================================

	public function test_ignoring_a_path_writes_option_blob(): void {
		$this->store->ignore( 'orphan_files', '2024/05/photo.jpg' );

		$blob = $GLOBALS['cf_media_manager_test_options'][ Options::AUDIT_IGNORED_PATHS ] ?? null;
		self::assertIsArray( $blob );
		self::assertArrayHasKey( '2024/05/photo.jpg', $blob );
		self::assertArrayHasKey( 'orphan_files', $blob['2024/05/photo.jpg'] );
	}

	public function test_is_ignored_reads_back_a_path(): void {
		$this->store->ignore( 'orphan_files', '2024/05/photo.jpg' );

		self::assertTrue( $this->store->is_ignored( 'orphan_files', '2024/05/photo.jpg' ) );
		self::assertFalse( $this->store->is_ignored( 'orphan_files', '2024/05/other.jpg' ) );
	}

	public function test_path_normalization_collapses_alternate_writings(): void {
		// All four spellings refer to the same canonical key.
		$variants = array(
			'2024/05/photo.jpg',
			'/2024/05/photo.jpg',
			'2024\\05\\photo.jpg',
			'2024//05/./photo.jpg',
		);
		foreach ( $variants as $v ) {
			self::assertSame( '2024/05/photo.jpg', IgnoredStore::normalize_key( $v ), "Variant: {$v}" );
		}

		$this->store->ignore( 'orphan_files', '2024\\05\\photo.jpg' );
		self::assertTrue( $this->store->is_ignored( 'orphan_files', '/2024/05/photo.jpg' ) );
	}

	public function test_path_normalization_preserves_case(): void {
		// Linux filesystems are case-sensitive; the store must not lowercase.
		self::assertSame( 'Uploads/IMG_001.JPG', IgnoredStore::normalize_key( 'Uploads/IMG_001.JPG' ) );
	}

	public function test_unignoring_the_last_report_drops_the_path_entry(): void {
		$this->store->ignore( 'orphan_files', '2024/05/photo.jpg' );
		$this->store->unignore( 'orphan_files', '2024/05/photo.jpg' );

		$blob = $GLOBALS['cf_media_manager_test_options'][ Options::AUDIT_IGNORED_PATHS ] ?? null;
		self::assertTrue(
			null === $blob || array() === $blob,
			'Last entry removal should delete the option entirely, not leave an empty array.'
		);
	}

	public function test_unignoring_one_report_keeps_others_on_same_path(): void {
		$this->store->ignore( 'orphan_files', '2024/05/photo.jpg' );
		$this->store->ignore( 'duplicates', '2024/05/photo.jpg' );

		$this->store->unignore( 'duplicates', '2024/05/photo.jpg' );

		self::assertTrue( $this->store->is_ignored( 'orphan_files', '2024/05/photo.jpg' ) );
		self::assertFalse( $this->store->is_ignored( 'duplicates', '2024/05/photo.jpg' ) );
	}

	// =====================================================================
	// list_ignored — both sides
	// =====================================================================

	public function test_list_ignored_returns_paths_for_path_keyed_report(): void {
		$this->store->ignore( 'orphan_files', '2024/05/a.jpg' );
		$this->store->ignore( 'orphan_files', '2024/05/b.jpg' );
		$this->store->ignore( 'orphan_files', '2024/06/c.jpg' );
		$this->store->ignore( 'duplicates', '2024/06/c.jpg' );

		$paths = $this->store->list_ignored( 'orphan_files' );
		sort( $paths );

		self::assertSame(
			array( '2024/05/a.jpg', '2024/05/b.jpg', '2024/06/c.jpg' ),
			$paths
		);
	}

	public function test_list_ignored_for_unknown_report_is_empty(): void {
		$this->store->ignore( 'orphan_files', '2024/05/a.jpg' );
		self::assertSame( array(), $this->store->list_ignored( 'nope' ) );
	}

	public function test_list_ignored_returns_attachment_ids_via_wpdb(): void {
		// Minimal wpdb test double — answers exactly the prepared query the
		// store issues, ignoring real SQL semantics.
		$GLOBALS['wpdb'] = new class() {
			public string $postmeta = 'wp_postmeta';
			public string $posts    = 'wp_posts';

			public function prepare( string $sql, ...$args ): string {
				// Echo the args back in the SQL so the call is traceable in
				// the harness, even though our get_results impl ignores it.
				return $sql . '|' . implode( ',', array_map( 'strval', $args ) );
			}

			public function get_results( string $sql, $output ): array {
				$rows = array();
				foreach ( $GLOBALS['cf_media_manager_test_post_meta'] as $post_id => $meta ) {
					if ( ! isset( $meta[ IgnoredStore::POSTMETA_KEY ] ) ) {
						continue;
					}
					$rows[] = array(
						'id'  => (int) $post_id,
						'bag' => serialize( $meta[ IgnoredStore::POSTMETA_KEY ] ),
					);
				}
				return $rows;
			}
		};

		$this->store->ignore( 'unused_attachments', 10 );
		$this->store->ignore( 'unused_attachments', 11 );
		$this->store->ignore( 'duplicates', 12 );
		$this->store->ignore( 'orphan_files', '2024/x.jpg' );  // path-side, must NOT appear

		$ids = $this->store->list_ignored( 'unused_attachments' );
		sort( $ids );

		self::assertSame( array( 10, 11 ), $ids );
	}

	// =====================================================================
	// purge_all
	// =====================================================================

	public function test_purge_all_clears_the_path_option_blob(): void {
		$this->store->ignore( 'orphan_files', '2024/05/a.jpg' );
		$this->store->purge_all();

		self::assertArrayNotHasKey(
			Options::AUDIT_IGNORED_PATHS,
			$GLOBALS['cf_media_manager_test_options']
		);
	}

	public function test_purge_all_calls_wpdb_delete_for_postmeta_rows(): void {
		$captured = (object) array( 'deletes' => array() );

		$GLOBALS['wpdb'] = new class( $captured ) {
			public string $postmeta = 'wp_postmeta';
			private \stdClass $captured;
			public function __construct( \stdClass $captured ) {
				$this->captured = $captured;
			}
			public function delete( string $table, array $where ): int {
				$this->captured->deletes[] = array( 'table' => $table, 'where' => $where );
				return 1;
			}
		};

		$this->store->purge_all();

		self::assertCount( 1, $captured->deletes );
		self::assertSame( 'wp_postmeta', $captured->deletes[0]['table'] );
		self::assertSame( IgnoredStore::POSTMETA_KEY, $captured->deletes[0]['where']['meta_key'] );
	}
}
