<?php

namespace CFMediaOptimizer\Tests;

use CFShared\Media\Paths;
use CFMediaOptimizer\VariantScanner;
use PHPUnit\Framework\TestCase;

/**
 * Tests the incremental scanner that replaced the legacy "walk the whole
 * uploads tree in one synchronous AJAX call" pattern. The two surfaces:
 *
 *  1. next_subtree(cursor) — resolves which top-level subdirectory to scan
 *     on this tick and what cursor to return to the client.
 *  2. walk_variants(subtree) — generator that yields one variant per file,
 *     applying containment + orphan-source filters.
 */
final class VariantScannerTest extends TestCase {

	private string $sandbox;
	private string $upload_dir;
	private Paths $paths;
	private VariantScanner $scanner;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();

		$this->sandbox    = sys_get_temp_dir() . '/cf-media-manager-scanner-' . uniqid();
		$this->upload_dir = $this->sandbox . '/uploads';
		mkdir( $this->upload_dir, 0777, true );

		$this->paths   = new Paths( $this->upload_dir, 'https://example.test/wp-content/uploads' );
		$this->scanner = new VariantScanner( $this->paths );
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
			is_dir( $path ) && ! is_link( $path ) ? $this->rrmdir( $path ) : @unlink( $path );
		}
		rmdir( $dir );
	}

	private function pair( string $rel_dir, string $base, string $ext ): array {
		$dir = $this->upload_dir . '/' . $rel_dir;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$src = $dir . '/' . $base . '.jpg';
		$var = $dir . '/' . $base . '.' . $ext;
		file_put_contents( $src, 'jpeg' );
		file_put_contents( $var, str_repeat( 'x', 100 ) );
		return [ 'src' => $src, 'variant' => $var ];
	}

	// -------------------------------------------------------------------------
	// next_subtree cursor protocol
	// -------------------------------------------------------------------------

	public function test_next_subtree_returns_null_when_upload_dir_is_empty(): void {
		$resolved = $this->scanner->next_subtree( '' );

		self::assertNull( $resolved['subtree'] );
		self::assertNull( $resolved['next_cursor'] );
		self::assertSame( 0, $resolved['progress']['total'] );
	}

	public function test_next_subtree_returns_first_top_level_dir_for_empty_cursor(): void {
		mkdir( $this->upload_dir . '/2024', 0777, true );
		mkdir( $this->upload_dir . '/2025', 0777, true );
		mkdir( $this->upload_dir . '/2026', 0777, true );

		$resolved = $this->scanner->next_subtree( '' );

		self::assertSame( $this->upload_dir . '/2024', $resolved['subtree'] );
		self::assertSame( '2025', $resolved['next_cursor'] );
		self::assertSame( [ 'index' => 1, 'total' => 3, 'current' => '2024' ], $resolved['progress'] );
	}

	public function test_next_subtree_advances_to_named_cursor(): void {
		mkdir( $this->upload_dir . '/2024', 0777, true );
		mkdir( $this->upload_dir . '/2025', 0777, true );
		mkdir( $this->upload_dir . '/2026', 0777, true );

		$resolved = $this->scanner->next_subtree( '2025' );

		self::assertSame( $this->upload_dir . '/2025', $resolved['subtree'] );
		self::assertSame( '2026', $resolved['next_cursor'] );
		self::assertSame( 2, $resolved['progress']['index'] );
	}

	public function test_next_subtree_returns_null_next_cursor_on_final_subtree(): void {
		mkdir( $this->upload_dir . '/2024', 0777, true );
		mkdir( $this->upload_dir . '/2025', 0777, true );

		$resolved = $this->scanner->next_subtree( '2025' );

		self::assertSame( $this->upload_dir . '/2025', $resolved['subtree'] );
		self::assertNull( $resolved['next_cursor'] );
	}

	public function test_next_subtree_treats_stale_cursor_as_done(): void {
		mkdir( $this->upload_dir . '/2024', 0777, true );

		// Client sent a cursor pointing at a year folder that's since been
		// deleted (concurrent admin action). Must not iterate from the start.
		$resolved = $this->scanner->next_subtree( '2025' );

		self::assertNull( $resolved['subtree'] );
		self::assertNull( $resolved['next_cursor'] );
	}

	public function test_next_subtree_sorts_dirs_alphabetically_for_determinism(): void {
		// Create out-of-order so the filesystem doesn't trivially return them
		// in alphabetical order.
		mkdir( $this->upload_dir . '/zeta', 0777, true );
		mkdir( $this->upload_dir . '/alpha', 0777, true );
		mkdir( $this->upload_dir . '/mu', 0777, true );

		$first  = $this->scanner->next_subtree( '' );
		$second = $this->scanner->next_subtree( (string) $first['next_cursor'] );
		$third  = $this->scanner->next_subtree( (string) $second['next_cursor'] );

		self::assertStringEndsWith( '/alpha', $first['subtree'] );
		self::assertStringEndsWith( '/mu',    $second['subtree'] );
		self::assertStringEndsWith( '/zeta',  $third['subtree'] );
	}

	public function test_next_subtree_returns_root_pass_when_root_files_present(): void {
		// Files at the upload root (no year/month organization) must be
		// scannable too. The first unit is a non-recursive pass over the
		// upload root itself; subsequent units are directories.
		mkdir( $this->upload_dir . '/2026', 0777, true );
		file_put_contents( $this->upload_dir . '/photo.jpg', 'x' );
		file_put_contents( $this->upload_dir . '/photo.webp', 'x' );

		$first  = $this->scanner->next_subtree( '' );
		$second = $this->scanner->next_subtree( (string) $first['next_cursor'] );

		self::assertSame( $this->upload_dir, $first['subtree'], 'root pass must come first' );
		self::assertSame( 'root', $first['scope'] );
		self::assertSame( $this->upload_dir . '/2026', $second['subtree'] );
		self::assertSame( 'subtree', $second['scope'] );
		self::assertNull( $second['next_cursor'] );
		self::assertSame( 2, $first['progress']['total'] );
	}

	public function test_next_subtree_omits_root_pass_when_only_dirs_present(): void {
		// No files at the upload root — no synthetic root pass is needed.
		mkdir( $this->upload_dir . '/2026', 0777, true );

		$resolved = $this->scanner->next_subtree( '' );

		self::assertSame( $this->upload_dir . '/2026', $resolved['subtree'] );
		self::assertSame( 'subtree', $resolved['scope'] ?? 'subtree' );
		self::assertSame( 1, $resolved['progress']['total'] );
	}

	public function test_walk_variants_non_recursive_finds_root_level_pairs(): void {
		// The upload-root pass walks the immediate directory only — no
		// recursion into year folders (those get their own pass).
		file_put_contents( $this->upload_dir . '/root.jpg',  'jpg' );
		file_put_contents( $this->upload_dir . '/root.webp', 'webp' );

		mkdir( $this->upload_dir . '/2026/05', 0777, true );
		file_put_contents( $this->upload_dir . '/2026/05/nested.jpg',  'jpg' );
		file_put_contents( $this->upload_dir . '/2026/05/nested.webp', 'webp' );

		$results = iterator_to_array(
			$this->scanner->walk_variants( $this->upload_dir, null, true /* non_recursive */ ),
			false
		);

		self::assertCount( 1, $results );
		self::assertSame( $this->upload_dir . '/root.webp', $results[0]['path'] );
	}

	public function test_next_subtree_ignores_symlink_directories(): void {
		mkdir( $this->upload_dir . '/2026', 0777, true );
		if ( ! @symlink( '/tmp', $this->upload_dir . '/symlinked' ) ) {
			self::markTestSkipped( 'symlink() not available' );
		}

		$resolved = $this->scanner->next_subtree( '' );

		// Only the real dir counts.
		self::assertSame( 1, $resolved['progress']['total'] );
		self::assertSame( $this->upload_dir . '/2026', $resolved['subtree'] );
	}

	// -------------------------------------------------------------------------
	// walk_variants
	// -------------------------------------------------------------------------

	public function test_walk_variants_yields_webp_and_avif_with_source(): void {
		$this->pair( '2026/05', 'a', 'webp' );
		$this->pair( '2026/05', 'b', 'avif' );

		$results = iterator_to_array( $this->scanner->walk_variants( $this->upload_dir . '/2026' ), false );

		self::assertCount( 2, $results );
		$exts = array_column( $results, 'ext' );
		sort( $exts );
		self::assertSame( [ 'avif', 'webp' ], $exts );
	}

	public function test_walk_variants_skips_orphan_variants_without_source(): void {
		// WebP exists but no .jpg/.jpeg/.png sibling.
		$dir = $this->upload_dir . '/2026/05';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/orphan.webp', 'x' );

		$results = iterator_to_array( $this->scanner->walk_variants( $this->upload_dir . '/2026' ), false );

		self::assertCount( 0, $results );
	}

	public function test_walk_variants_returns_empty_generator_for_missing_subtree(): void {
		$results = iterator_to_array( $this->scanner->walk_variants( $this->upload_dir . '/nope' ), false );

		self::assertSame( [], $results );
	}

	public function test_walk_variants_reports_correct_size_per_file(): void {
		$paths = $this->pair( '2026/05', 'a', 'webp' );
		file_put_contents( $paths['variant'], str_repeat( 'x', 4242 ) );

		$results = iterator_to_array( $this->scanner->walk_variants( $this->upload_dir . '/2026' ), false );

		self::assertCount( 1, $results );
		self::assertSame( 4242, $results[0]['size'] );
	}

	public function test_walk_variants_includes_jpeg_siblings_not_just_jpg(): void {
		$dir = $this->upload_dir . '/2026/05';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/photo.jpeg', 'jpeg' );
		file_put_contents( $dir . '/photo.webp', 'webp' );

		$results = iterator_to_array( $this->scanner->walk_variants( $this->upload_dir . '/2026' ), false );

		self::assertCount( 1, $results );
	}

	public function test_walk_variants_recurses_into_nested_subdirs(): void {
		$this->pair( '2026/01', 'jan', 'webp' );
		$this->pair( '2026/06', 'jun', 'webp' );
		$this->pair( '2026/12', 'dec', 'avif' );

		$results = iterator_to_array( $this->scanner->walk_variants( $this->upload_dir . '/2026' ), false );

		self::assertCount( 3, $results );
	}

	// -------------------------------------------------------------------------
	// Full-cycle simulation — drive the cursor loop end-to-end like the
	// admin.js client does, and verify counts accumulate correctly.
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// owned_set filter — used by Delete All to skip user-uploaded variants.
	// -------------------------------------------------------------------------

	public function test_walk_variants_marks_entries_owned_when_present_in_set(): void {
		$ours    = $this->pair( '2026/05', 'ours', 'webp' );
		$theirs  = $this->pair( '2026/05', 'theirs', 'webp' );

		$owned_set = array( $ours['variant'] => true );

		$results = iterator_to_array(
			$this->scanner->walk_variants( $this->upload_dir . '/2026', $owned_set ),
			false
		);

		// Index by path so order doesn't matter — recursion order is FS-dependent.
		$by_path = array();
		foreach ( $results as $r ) {
			$by_path[ $r['path'] ] = $r;
		}
		self::assertTrue( $by_path[ $ours['variant'] ]['owned'] );
		self::assertFalse( $by_path[ $theirs['variant'] ]['owned'] );
	}

	public function test_walk_variants_owned_flag_is_false_when_no_set_provided(): void {
		// Conservative default — without an explicit allow-list, nothing is
		// considered owned. Delete All would skip everything in this state.
		$this->pair( '2026/05', 'x', 'webp' );

		$results = iterator_to_array( $this->scanner->walk_variants( $this->upload_dir . '/2026' ), false );

		self::assertCount( 1, $results );
		self::assertFalse( $results[0]['owned'] );
	}

	public function test_full_cursor_loop_visits_every_subtree_exactly_once(): void {
		$this->pair( '2024/06', 'a', 'webp' );
		$this->pair( '2025/01', 'b', 'webp' );
		$this->pair( '2025/12', 'c', 'avif' );
		$this->pair( '2026/05', 'd', 'webp' );

		$total      = 0;
		$cursor     = '';
		$iterations = 0;

		do {
			$resolved = $this->scanner->next_subtree( $cursor );
			if ( null === $resolved['subtree'] ) {
				break;
			}
			foreach ( $this->scanner->walk_variants( $resolved['subtree'] ) as $entry ) {
				$total++;
			}
			$cursor = (string) ( $resolved['next_cursor'] ?? '' );
			$iterations++;
			self::assertLessThan( 10, $iterations, 'cursor loop did not terminate' );
		} while ( null !== $resolved['next_cursor'] );

		self::assertSame( 4, $total );
		self::assertSame( 3, $iterations ); // three top-level year dirs
	}
}
