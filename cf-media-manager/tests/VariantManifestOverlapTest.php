<?php

namespace CFMediaManager\Tests;

use CFMediaManager\Paths;
use CFMediaManager\VariantManifest;
use PHPUnit\Framework\TestCase;

/**
 * H3 concurrency: two parallel `backfill_subtree_bulk()` runs over an
 * overlapping subtree must NOT produce duplicate (post_id, meta_key) rows.
 *
 * The defense lives in `bulk_insert_owns()`:
 *
 *   1. `filter_outside_upload_dir()` — H2 containment, also drops rows
 *      with a path that resolves outside the uploads tree.
 *   2. `filter_existing_pairs()` — SELECTs the (post_id, meta_key) pairs
 *      already present in postmeta and filters $writes against them
 *      before any INSERT. This catches the case where a parallel writer
 *      committed the pair between our in-memory `owned_pairs` snapshot
 *      and our INSERT.
 *   3. Per-row `wpdb::insert()` for whatever survives both filters.
 *
 * The unit-level `dedupe_writes()` helper has its own coverage in
 * `SecurityHardeningTest`. THIS file exercises the integration end-to-end
 * with a `$wpdb` mock, so we know the SELECT-before-INSERT plumbing
 * actually feeds dedupe_writes the right shape and that no INSERT lands
 * for already-present pairs.
 *
 * The mock supports the three wpdb methods bulk_insert_owns uses
 * (`prepare`, `get_results`, `insert`); insert calls are recorded so the
 * test can assert exactly which (post_id, meta_key) rows would have
 * landed in postmeta.
 */
final class VariantManifestOverlapTest extends TestCase {

	private string $sandbox;
	private string $upload_dir;
	private Paths $paths;
	private VariantManifest $manifest;

	/** Records `[ post_id, meta_key ]` of every INSERT the mock $wpdb sees. */
	private array $insert_log;

	/** Records the SQL of every $wpdb->query() the convergence-DELETE issues. */
	private array $query_log;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();

		$this->sandbox    = sys_get_temp_dir() . '/cf-media-manager-overlap-' . uniqid();
		$this->upload_dir = $this->sandbox . '/uploads';
		mkdir( $this->upload_dir . '/2026/05', 0777, true );

		$this->paths    = new Paths( $this->upload_dir, 'https://example.test/wp-content/uploads' );
		$this->manifest = new VariantManifest( $this->paths );
		$this->insert_log = array();
		$this->query_log  = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
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
			is_dir( $path ) && ! is_link( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	/**
	 * Install a $wpdb mock for this test. Closures over $existing (the
	 * "pre-seeded" rows the SELECT will return) and the per-test
	 * insert_log buffer.
	 *
	 * @param array<int,array{post_id:int,meta_key:string}> $existing
	 */
	private function install_wpdb_mock( array $existing ): void {
		$log     = &$this->insert_log;
		$querys  = &$this->query_log;
		$GLOBALS['wpdb'] = new class( $existing, $log, $querys ) {
			public string $postmeta = 'wp_postmeta';
			private array $existing;
			private array $log;     // intentionally NOT by reference — we mutate via the local & alias below
			private array $querys;

			public function __construct( array $existing, array &$log, array &$querys ) {
				$this->existing = $existing;
				$this->log      = &$log;
				$this->querys   = &$querys;
			}

			public function prepare( $sql, ...$args ) {
				// Faithfully render the placeholders so the test can inspect
				// the SQL if it ever needs to. Production wpdb does more
				// escaping than this; for the dedup test we just need the
				// returned string to be non-empty (truthy).
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				return vsprintf( str_replace( array( '%d', '%s' ), array( '%d', "'%s'" ), $sql ), $args );
			}

			public function get_results( $sql, $output = 0 ) {
				// Return the pre-seeded "existing" rows. Production wpdb would
				// have filtered by the IN clauses; the mock returns the seed
				// unfiltered (the dedupe_writes step inside filter_existing_pairs
				// only consumes the pairs anyway).
				return $this->existing;
			}

			public function insert( $table, $data, $format = null ) {
				$this->log[] = array(
					'post_id'  => (int) $data['post_id'],
					'meta_key' => (string) $data['meta_key'],
				);
				return 1; // wpdb::insert returns affected-row count on success.
			}

			public function esc_like( $text ) {
				return addcslashes( (string) $text, '_%\\' );
			}

			public function query( $sql ) {
				// Records the SQL for the test to inspect. Returns 0 (no
				// affected rows) since the mock holds no real data.
				$this->querys[] = $sql;
				return 0;
			}
		};
	}

	/**
	 * Drive the bulk path. Layout: two .webp variants in the upload
	 * subtree, both backed by an on-disk .jpg source. Returns the
	 * actual result tuple from backfill_subtree_bulk so the test can
	 * make additional assertions if needed.
	 *
	 * @return array{claimed:int,processed:int,next_offset:?int,total_files:int}
	 */
	private function run_bulk_backfill(): array {
		$dir = $this->upload_dir . '/2026/05';
		// Two .webp variants, each with a real .jpg source on disk.
		file_put_contents( $dir . '/photo-a.jpg',  'jpeg-a' );
		file_put_contents( $dir . '/photo-a.webp', 'webp-a' );
		file_put_contents( $dir . '/photo-b.jpg',  'jpeg-b' );
		file_put_contents( $dir . '/photo-b.webp', 'webp-b' );

		// path_to_id maps source rel → attachment id. The backfill uses
		// this to resolve which attachment a variant belongs to.
		$path_to_id = array(
			'2026/05/photo-a.jpg' => 100,
			'2026/05/photo-b.jpg' => 200,
		);
		$size_to_parent = array();
		$owned_pairs    = array(); // fresh snapshot — nothing claimed yet

		return $this->manifest->backfill_subtree_bulk(
			$dir,
			$path_to_id,
			$size_to_parent,
			$owned_pairs,
			0,
			100,
			false,
			false
		);
	}

	// =========================================================================
	// H3 — bulk_insert_owns / filter_existing_pairs integration
	// =========================================================================

	/**
	 * Baseline: no concurrent writer. Both variants land as INSERTs.
	 * Confirms the mock plumbing actually exercises the INSERT path so
	 * the "exists pre-empts insert" tests below have meaning.
	 */
	public function test_baseline_with_no_existing_rows_inserts_both(): void {
		$this->install_wpdb_mock( array() ); // postmeta SELECT returns nothing.

		$result = $this->run_bulk_backfill();

		self::assertSame( 2, $result['claimed'] );
		self::assertCount( 2, $this->insert_log, 'both rows must INSERT when no overlap exists' );
		$post_ids = array_column( $this->insert_log, 'post_id' );
		sort( $post_ids );
		self::assertSame( array( 100, 200 ), $post_ids );
	}

	/**
	 * Concurrent writer scenario: pair (100, owns_<photo-a.webp>) already
	 * landed in postmeta between our snapshot and our INSERT. The dedupe
	 * SELECT catches it; only the other pair (200, ...) is INSERTed.
	 *
	 * Without filter_existing_pairs we'd see TWO inserts here, the first
	 * producing a duplicate (post_id, meta_key) row in postmeta.
	 */
	public function test_overlap_filters_already_existing_pair_before_insert(): void {
		// Compute the actual meta_key the bulk path will write for photo-a.
		$existing_key = $this->meta_key_for( '2026/05/photo-a.webp' );

		$this->install_wpdb_mock( array(
			array( 'post_id' => 100, 'meta_key' => $existing_key ),
		) );

		$result = $this->run_bulk_backfill();

		// The bulk path's `claimed` counter increments before the dedupe
		// SELECT (it's bumped during in-memory resolution), so claimed=2.
		// But on the wire only ONE INSERT should have hit the mock.
		self::assertCount( 1, $this->insert_log, 'pair already in postmeta must NOT be re-INSERTed' );
		self::assertSame( 200, $this->insert_log[0]['post_id'], 'the surviving INSERT must be the non-overlapping pair' );
	}

	/**
	 * All-overlap edge case: every pair already exists in postmeta. The
	 * INSERT loop must be skipped entirely (bulk_insert_owns early-
	 * returns after dedupe wipes the writes).
	 */
	public function test_all_writes_already_exist_yields_zero_inserts(): void {
		$this->install_wpdb_mock( array(
			array( 'post_id' => 100, 'meta_key' => $this->meta_key_for( '2026/05/photo-a.webp' ) ),
			array( 'post_id' => 200, 'meta_key' => $this->meta_key_for( '2026/05/photo-b.webp' ) ),
		) );

		$this->run_bulk_backfill();

		self::assertSame( array(), $this->insert_log, 'no INSERT when every pair is already on disk' );
	}

	/**
	 * H2 belt-and-suspenders: the containment filter runs BEFORE the
	 * dedupe SELECT, so a poisoned rel never even contributes to the
	 * SELECT IN clause. This test calls filter_outside_upload_dir
	 * directly so the assertion is unambiguous about ordering.
	 */
	public function test_containment_filter_strips_traversal_rels_before_db(): void {
		$writes = array(
			array( 'post_id' => 1, 'meta_key' => 'k', 'meta_value' => '2026/05/clean.webp' ),
			array( 'post_id' => 2, 'meta_key' => 'k', 'meta_value' => '../../etc/passwd' ),
		);
		// 2026/05 is already created in setUp(); within_upload_dir's
		// parent-realpath fallback will succeed against it.

		$kept = VariantManifest::filter_outside_upload_dir( $writes, $this->paths );

		self::assertCount( 1, $kept );
		self::assertSame( 1, $kept[0]['post_id'] );
	}

	// =========================================================================
	// A2 — post-INSERT convergence DELETE
	// =========================================================================

	/**
	 * After the chunk's INSERTs land, bulk_insert_owns issues a DELETE-JOIN
	 * scoped to our meta_key prefix and the chunk's post_id set.
	 * Idempotent — runs to a no-op when there are no duplicates, but
	 * the SQL MUST fire so that a true concurrent INSERT (which our
	 * filter_existing_pairs SELECT cannot see in time) converges to
	 * uniqueness after the fact.
	 */
	public function test_convergence_delete_fires_after_inserts(): void {
		$this->install_wpdb_mock( array() ); // no pre-existing rows.

		$this->run_bulk_backfill();

		self::assertCount( 1, $this->query_log, 'exactly one convergence DELETE per chunk' );

		$sql = $this->query_log[0];
		self::assertStringContainsString( 'DELETE', $sql );
		self::assertStringContainsString( 'INNER JOIN', $sql );
		self::assertStringContainsString( 'p1.meta_id  > p2.meta_id', $sql, 'must keep the earliest meta_id per pair' );
		// esc_like turns underscores into '\_' for the LIKE pattern.
		// Assert on the escaped form so the test reflects production SQL.
		self::assertStringContainsString( "\\_cf\\_media\\_manager\\_owns\\_", $sql, 'must scope to our meta_key prefix' );
		// Post_ids 100 + 200 must appear in the IN clause.
		self::assertMatchesRegularExpression( '/IN \(\s*100\s*,\s*200\s*\)|IN \(\s*200\s*,\s*100\s*\)/', $sql );
	}

	/**
	 * Empty post_id set (e.g. every write got dropped by the containment
	 * or dedupe pre-filters) must NOT fire the DELETE. Defends against
	 * a meaningless `DELETE ... WHERE post_id IN ()` syntax error.
	 */
	public function test_convergence_delete_skipped_when_all_writes_filtered(): void {
		$this->install_wpdb_mock( array(
			array( 'post_id' => 100, 'meta_key' => $this->meta_key_for( '2026/05/photo-a.webp' ) ),
			array( 'post_id' => 200, 'meta_key' => $this->meta_key_for( '2026/05/photo-b.webp' ) ),
		) );

		$this->run_bulk_backfill();

		// All writes already exist → dedupe wipes $writes → bulk_insert_owns
		// early-returns BEFORE the INSERT loop, so the convergence DELETE
		// doesn't fire either. Acceptable: there's nothing new to converge.
		self::assertSame( array(), $this->insert_log );
		self::assertSame( array(), $this->query_log, 'convergence DELETE must not fire when no INSERTs ran' );
	}

	/**
	 * Thin wrapper around the production helper so the test stays
	 * version-stable: when the schema marker bumps from v1 to v2 the
	 * test follows automatically. Using a reimplementation would silently
	 * drift on a future bump.
	 */
	private function meta_key_for( string $rel ): string {
		return VariantManifest::meta_key_for( $rel );
	}
}
