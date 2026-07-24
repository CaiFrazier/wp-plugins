<?php

namespace CFMediaOptimizer\Tests;

use CFMediaOptimizer\Disk;
use CFShared\Media\Paths;
use PHPUnit\Framework\TestCase;

/**
 * Disk helper covers two surfaces:
 *  1. estimate_required_space — heuristic over (source + size variants)
 *  2. check_sufficient_space — disk_free_space() comparison vs required+reserve
 *
 * The estimator is pure math against filesystem state, so tests build real
 * fixture files. The check is exercised with a real upload dir (so
 * disk_free_space succeeds) — we vary required/reserve, not free space.
 */
final class DiskTest extends TestCase {

	private string $sandbox;
	private string $uploads;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();

		$this->sandbox = sys_get_temp_dir() . '/cf-media-manager-disk-' . uniqid();
		$this->uploads = $this->sandbox . '/uploads/2026/05';
		mkdir( $this->uploads, 0777, true );
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

	private function write_file( string $path, int $bytes ): void {
		file_put_contents( $path, str_repeat( 'x', $bytes ) );
	}

	// -------------------------------------------------------------------------
	// estimate_required_space
	// -------------------------------------------------------------------------

	public function test_estimate_returns_zero_for_empty_id_list(): void {
		self::assertSame( 0, Disk::estimate_required_space( [], true ) );
	}

	public function test_estimate_returns_zero_when_attachment_is_unknown(): void {
		// Id 99 has no attachment record — should contribute 0.
		self::assertSame( 0, Disk::estimate_required_space( [ 99 ], true ) );
	}

	public function test_estimate_returns_zero_when_source_file_missing(): void {
		$GLOBALS['cf_media_manager_test_attachments'][1] = [
			'file' => $this->uploads . '/missing.jpg', // file never written
			'meta' => [ 'file' => '2026/05/missing.jpg' ],
		];
		self::assertSame( 0, Disk::estimate_required_space( [ 1 ], true ) );
	}

	public function test_estimate_for_a_single_source_file_uses_webp_ratio(): void {
		$src = $this->uploads . '/photo.jpg';
		$this->write_file( $src, 10_000 ); // 10 KB

		$GLOBALS['cf_media_manager_test_attachments'][1] = [
			'file' => $src,
			'meta' => [ 'file' => '2026/05/photo.jpg' ],
		];

		// WebP-only: 10_000 * 0.6 = 6_000
		self::assertSame( 6_000, Disk::estimate_required_space( [ 1 ], false ) );
	}

	public function test_estimate_with_avif_adds_avif_ratio(): void {
		$src = $this->uploads . '/photo.jpg';
		$this->write_file( $src, 10_000 );

		$GLOBALS['cf_media_manager_test_attachments'][1] = [
			'file' => $src,
			'meta' => [ 'file' => '2026/05/photo.jpg' ],
		];

		// WebP+AVIF: 10_000 * 0.6 + 10_000 * 0.4 = 10_000
		self::assertSame( 10_000, Disk::estimate_required_space( [ 1 ], true ) );
	}

	public function test_estimate_includes_size_variants_from_meta(): void {
		$src   = $this->uploads . '/photo.jpg';
		$thumb = $this->uploads . '/photo-150x150.jpg';
		$this->write_file( $src, 10_000 );
		$this->write_file( $thumb, 1_000 );

		$GLOBALS['cf_media_manager_test_attachments'][1] = [
			'file' => $src,
			'meta' => [
				'file'  => '2026/05/photo.jpg',
				'sizes' => [ 'thumbnail' => [ 'file' => 'photo-150x150.jpg' ] ],
			],
		];

		// WebP-only: (10_000 * 0.6) + (1_000 * 0.6) = 6_600
		self::assertSame( 6_600, Disk::estimate_required_space( [ 1 ], false ) );
	}

	public function test_estimate_skips_size_variants_when_file_missing_on_disk(): void {
		$src = $this->uploads . '/photo.jpg';
		$this->write_file( $src, 10_000 );

		$GLOBALS['cf_media_manager_test_attachments'][1] = [
			'file' => $src,
			'meta' => [
				'file'  => '2026/05/photo.jpg',
				'sizes' => [ 'thumbnail' => [ 'file' => 'no-such-thumb.jpg' ] ],
			],
		];

		// Only the source counts: 10_000 * 0.6 = 6_000.
		self::assertSame( 6_000, Disk::estimate_required_space( [ 1 ], false ) );
	}

	public function test_estimate_sums_across_multiple_attachments(): void {
		$a = $this->uploads . '/a.jpg';
		$b = $this->uploads . '/b.jpg';
		$this->write_file( $a, 4_000 );
		$this->write_file( $b, 6_000 );

		$GLOBALS['cf_media_manager_test_attachments'] = [
			1 => [ 'file' => $a, 'meta' => [ 'file' => '2026/05/a.jpg' ] ],
			2 => [ 'file' => $b, 'meta' => [ 'file' => '2026/05/b.jpg' ] ],
		];

		// WebP-only: (4_000 + 6_000) * 0.6 = 6_000
		self::assertSame( 6_000, Disk::estimate_required_space( [ 1, 2 ], false ) );
	}

	// -------------------------------------------------------------------------
	// check_sufficient_space
	// -------------------------------------------------------------------------

	public function test_check_returns_null_when_disk_has_room_for_required_plus_reserve(): void {
		// Tiny required + tiny reserve, real /tmp has gigabytes free.
		self::assertNull( Disk::check_sufficient_space( $this->uploads, 1024, 1024 ) );
	}

	public function test_check_returns_error_when_required_plus_reserve_exceeds_free(): void {
		// 100 EB required — guaranteed larger than any real disk.
		$huge   = PHP_INT_MAX - 1;
		$result = Disk::check_sufficient_space( $this->uploads, $huge, 1024 );

		self::assertNotNull( $result );
		self::assertIsString( $result );
		self::assertStringContainsString( 'disk space', strtolower( $result ) );
	}

	public function test_check_passes_through_when_disk_free_space_unavailable(): void {
		// Path that doesn't exist — disk_free_space returns false. Helper
		// must not block; it returns null and lets the conversion proceed.
		$result = @Disk::check_sufficient_space( '/path/does/not/exist/anywhere', 1_000_000_000 );
		self::assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// Containment check (Paths $paths param) — was uncovered before B2.
	// Defends against poisoned attachment metadata pointing outside uploads.
	// -------------------------------------------------------------------------

	public function test_estimate_skips_sources_outside_uploads_tree_when_paths_provided(): void {
		// Source file lives OUTSIDE the configured uploads dir.
		$outside_dir = $this->sandbox . '/outside';
		mkdir( $outside_dir, 0777, true );
		$outside_file = $outside_dir . '/poisoned.jpg';
		$this->write_file( $outside_file, 10_000 );

		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'file' => $outside_file,
			'meta' => array( 'file' => '../outside/poisoned.jpg' ),
		);

		$paths   = new Paths( $this->sandbox . '/uploads', 'https://example.test/wp-content/uploads' );
		$result  = Disk::estimate_required_space( array( 1 ), true, $paths );

		self::assertSame(
			0,
			$result,
			'estimator must skip sources whose path resolves outside the uploads tree, even when they exist on disk'
		);
	}

	public function test_estimate_skips_size_variants_outside_uploads_tree_when_paths_provided(): void {
		// Source IS inside uploads; one of its size variants points OUTSIDE
		// (poisoned `_wp_attachment_metadata.sizes[]`).
		$source = $this->uploads . '/photo.jpg';
		$this->write_file( $source, 10_000 );

		$outside_dir = $this->sandbox . '/outside';
		mkdir( $outside_dir, 0777, true );
		$variant_outside = $outside_dir . '/photo-300x200.jpg';
		$this->write_file( $variant_outside, 5_000 );

		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'file' => $source,
			'meta' => array(
				'file'  => '2026/05/photo.jpg',
				'sizes' => array(
					// dirname($source) + '/' + sizes[..]['file'] must equal
					// $variant_outside. That means the size 'file' value
					// has to traverse out of $uploads/2026/05.
					'medium' => array( 'file' => '../../outside/photo-300x200.jpg' ),
				),
			),
		);

		$paths  = new Paths( $this->sandbox . '/uploads', 'https://example.test/wp-content/uploads' );
		$result = Disk::estimate_required_space( array( 1 ), true, $paths );

		// Source DOES land in the estimate (it's inside uploads). The
		// traversal-resolved size MUST be skipped.
		$source_only = (int) ceil( 10_000 * 0.6 ) + (int) ceil( 10_000 * 0.4 );
		self::assertSame( $source_only, $result, 'traversal-resolving size must be excluded from the estimate' );
	}

	public function test_estimate_allows_outside_paths_when_paths_arg_is_null(): void {
		// Backward-compat: pre-Phase-1 callers that don't pass $paths get
		// the legacy (no-containment) behavior. Documents the contract;
		// production callers (Ajax + cli) always pass $paths.
		$source = $this->sandbox . '/outside/photo.jpg';
		mkdir( dirname( $source ), 0777, true );
		$this->write_file( $source, 10_000 );

		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'file' => $source,
			'meta' => array( 'file' => '../outside/photo.jpg' ),
		);

		// Without $paths, no containment check → file's bytes contribute.
		$result = Disk::estimate_required_space( array( 1 ), true /* avif */ );

		$expected = (int) ceil( 10_000 * 0.6 ) + (int) ceil( 10_000 * 0.4 );
		self::assertSame( $expected, $result );
	}

	// -------------------------------------------------------------------------
	// Phase 3 meta-cache prewarm — sanity check that it doesn't break anything
	// -------------------------------------------------------------------------

	/**
	 * Phase 3 added an `update_meta_cache( 'post', $batch_ids )` prewarm
	 * call at the top of `estimate_required_space`. The prewarm is a
	 * no-op in the test environment (no $wpdb), but its presence must
	 * not change the return value vs. a non-prewarm baseline.
	 */
	public function test_estimate_unchanged_by_meta_cache_prewarm(): void {
		$source = $this->uploads . '/photo.jpg';
		$this->write_file( $source, 12_345 );

		$GLOBALS['cf_media_manager_test_attachments'][1] = array(
			'file' => $source,
			'meta' => array( 'file' => '2026/05/photo.jpg' ),
		);

		$paths  = new Paths( $this->sandbox . '/uploads', 'https://example.test/wp-content/uploads' );
		$result = Disk::estimate_required_space( array( 1 ), false /* webp only */, $paths );

		self::assertSame( (int) ceil( 12_345 * 0.6 ), $result );
	}
}
