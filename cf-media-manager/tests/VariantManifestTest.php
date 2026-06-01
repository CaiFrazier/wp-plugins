<?php

namespace CFMediaManager\Tests;

use CFMediaManager\Paths;
use CFMediaManager\VariantManifest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-attachment variant ownership tracker.
 *
 * The manifest is the gate that stops the plugin from overwriting or
 * deleting user-uploaded `.webp`/`.avif` files that happen to share a
 * basename with one of our generated derivatives. We test the gate
 * directly here; the converter integration is exercised in ConverterTest.
 */
final class VariantManifestTest extends TestCase {

	private string $sandbox;
	private string $upload_dir;
	private Paths $paths;
	private VariantManifest $manifest;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();

		$this->sandbox    = sys_get_temp_dir() . '/cf-media-manager-manifest-' . uniqid();
		$this->upload_dir = $this->sandbox . '/uploads';
		mkdir( $this->upload_dir, 0777, true );

		$this->paths    = new Paths( $this->upload_dir, 'https://example.test/wp-content/uploads' );
		$this->manifest = new VariantManifest( $this->paths );
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

	// ----------------------------------------------------------------------
	// record / is_owned_by
	// ----------------------------------------------------------------------

	public function test_record_marks_variant_as_owned_by_attachment(): void {
		$path = $this->upload_dir . '/2026/05/photo.webp';
		$this->manifest->record( 42, $path );

		self::assertTrue( $this->manifest->is_owned_by( 42, $path ) );
	}

	public function test_record_is_idempotent(): void {
		$path = $this->upload_dir . '/2026/05/photo.webp';
		$this->manifest->record( 42, $path );
		$this->manifest->record( 42, $path );
		$this->manifest->record( 42, $path );

		// is_owned_by must still report ownership; multiple record() calls
		// must not create duplicate meta rows (add_post_meta unique semantics).
		self::assertTrue( $this->manifest->is_owned_by( 42, $path ) );
		// Writes go to the current schema version (v1). Reads also accept
		// the unversioned v0 key for installs that wrote rows pre-versioning.
		$key = VariantManifest::meta_key_for( '2026/05/photo.webp' );
		self::assertSame( '2026/05/photo.webp', get_post_meta( 42, $key, true ) );
	}

	public function test_is_owned_by_reads_legacy_v0_postmeta(): void {
		// Backwards compatibility: rows written by 2.0.0a–2.0.0c land under
		// the unversioned `_cf_media_manager_owns_<md5>` key. is_owned_by()
		// must still report ownership after the v1 prefix change.
		$rel       = '2026/05/photo.webp';
		$path      = $this->upload_dir . '/' . $rel;
		$v0_key    = VariantManifest::meta_key_for_v0( $rel );

		// Simulate a v0-era row directly in the test postmeta store —
		// bypass record() since the writer now emits v1.
		update_post_meta( 99, $v0_key, $rel );

		self::assertTrue( $this->manifest->is_owned_by( 99, $path ) );
	}

	public function test_record_skips_paths_outside_uploads(): void {
		// A path that doesn't resolve under uploads must NEVER end up in the
		// manifest — otherwise the ownership check leaks across the boundary
		// it exists to enforce.
		$this->manifest->record( 42, '/etc/passwd' );

		// Nothing should have been written under any of our meta keys.
		self::assertSame( array(), $GLOBALS['cf_media_manager_test_post_meta'][42] ?? array() );
	}

	public function test_record_rejects_zero_or_negative_attachment_id(): void {
		$path = $this->upload_dir . '/2026/05/photo.webp';
		$this->manifest->record( 0, $path );
		$this->manifest->record( -5, $path );

		self::assertSame( array(), $GLOBALS['cf_media_manager_test_post_meta'][0] ?? array() );
	}

	public function test_is_owned_by_returns_false_when_no_meta_row_exists(): void {
		self::assertFalse( $this->manifest->is_owned_by( 42, $this->upload_dir . '/photo.webp' ) );
	}

	public function test_is_owned_by_isolates_attachments(): void {
		$path = $this->upload_dir . '/2026/05/photo.webp';
		$this->manifest->record( 42, $path );

		self::assertTrue( $this->manifest->is_owned_by( 42, $path ) );
		// A *different* attachment claiming nothing should not somehow match.
		self::assertFalse( $this->manifest->is_owned_by( 43, $path ) );
	}

	// ----------------------------------------------------------------------
	// forget
	// ----------------------------------------------------------------------

	public function test_forget_removes_only_the_specified_variant(): void {
		$webp = $this->upload_dir . '/2026/05/photo.webp';
		$avif = $this->upload_dir . '/2026/05/photo.avif';
		$this->manifest->record( 42, $webp );
		$this->manifest->record( 42, $avif );

		$this->manifest->forget( 42, $webp );

		self::assertFalse( $this->manifest->is_owned_by( 42, $webp ) );
		self::assertTrue( $this->manifest->is_owned_by( 42, $avif ) );
	}

	public function test_forget_deletes_meta_row_when_variant_removed(): void {
		$path = $this->upload_dir . '/2026/05/photo.webp';
		$this->manifest->record( 42, $path );
		$this->manifest->forget( 42, $path );

		$key = VariantManifest::META_KEY_PREFIX . md5( '2026/05/photo.webp' );
		self::assertSame(
			'',
			get_post_meta( 42, $key, true ),
			'forget should delete the exact-key meta row'
		);
		self::assertFalse( $this->manifest->is_owned_by( 42, $path ) );
	}

	// ----------------------------------------------------------------------
	// is_owned (slow path, no attachment id)
	// ----------------------------------------------------------------------

	public function test_is_owned_returns_null_when_no_database_available(): void {
		// Test harness has no $wpdb — manifest should report "indeterminate"
		// so callers can apply a permissive default rather than refusing
		// every conversion in non-WP environments.
		$result = $this->manifest->is_owned( $this->upload_dir . '/photo.webp' );

		self::assertNull( $result );
	}

	public function test_is_owned_returns_false_for_paths_outside_uploads(): void {
		$result = $this->manifest->is_owned( '/etc/passwd' );

		self::assertFalse( $result, 'paths that don\'t live in uploads should fail closed, not return null' );
	}

	// ----------------------------------------------------------------------
	// build_owned_paths_set
	// ----------------------------------------------------------------------

	public function test_build_owned_paths_set_returns_empty_array_without_wpdb(): void {
		// No $wpdb -> graceful empty set rather than a fatal error. This is
		// the failure mode that matters for safe defaults in delete_all.
		$set = $this->manifest->build_owned_paths_set();

		self::assertSame( array(), $set );
	}

	// ----------------------------------------------------------------------
	// guess_source_path
	// ----------------------------------------------------------------------

	public function test_guess_source_path_returns_matching_jpg_sibling(): void {
		$dir = $this->upload_dir . '/2026/05';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/photo.jpg', 'jpeg' );
		file_put_contents( $dir . '/photo.webp', 'webp' );

		self::assertSame( $dir . '/photo.jpg', $this->manifest->guess_source_path( $dir . '/photo.webp' ) );
	}

	public function test_guess_source_path_returns_null_for_orphan(): void {
		$dir = $this->upload_dir . '/2026/05';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/orphan.webp', 'webp' );

		self::assertNull( $this->manifest->guess_source_path( $dir . '/orphan.webp' ) );
	}

	public function test_guess_source_path_prefers_jpg_over_jpeg(): void {
		// When both .jpg and .jpeg exist alongside a .webp, return .jpg first
		// — matches the order in the lookup loop and keeps callers deterministic.
		$dir = $this->upload_dir . '/2026/05';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/photo.jpg', 'jpeg-a' );
		file_put_contents( $dir . '/photo.jpeg', 'jpeg-b' );
		file_put_contents( $dir . '/photo.webp', 'webp' );

		self::assertSame( $dir . '/photo.jpg', $this->manifest->guess_source_path( $dir . '/photo.webp' ) );
	}

	// ----------------------------------------------------------------------
	// (Removed in 2.0.1 alongside VariantManifest::backfill_subtree(): the
	// 6 callable-based tests that exercised the legacy backfill code path.
	// The same semantics live in backfill_subtree_bulk() — adopt-guard,
	// dry-run, non-recursive, multi-claim — and would need a $wpdb mock to
	// test end-to-end. Listed as a Phase 5 follow-up in CHANGELOG.)
	// ----------------------------------------------------------------------

	public function test_find_all_source_paths_returns_every_existing_sibling(): void {
		$dir = $this->upload_dir . '/2026/05';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/foo.jpg',  'jpeg' );
		file_put_contents( $dir . '/foo.png',  'png' );

		$matches = $this->manifest->find_all_source_paths( $dir . '/foo.webp' );

		self::assertContains( $dir . '/foo.jpg', $matches );
		self::assertContains( $dir . '/foo.png', $matches );
		self::assertCount( 2, $matches );
	}

	public function test_find_all_source_paths_returns_empty_when_no_siblings(): void {
		$dir = $this->upload_dir . '/2026/05';
		mkdir( $dir, 0777, true );

		self::assertSame( array(), $this->manifest->find_all_source_paths( $dir . '/orphan.webp' ) );
	}

	// ----------------------------------------------------------------------
	// Storage migration — installs that wrote the pre-1.2.2 serialized-array
	// format under `_cf_media_manager_variants` must keep being recognized as owned
	// until the admin re-runs backfill. Otherwise upgrading silently breaks
	// Delete All and the rewriter's serve check.
	// ----------------------------------------------------------------------

	public function test_is_owned_by_falls_back_to_legacy_serialized_array(): void {
		$path = $this->upload_dir . '/2026/05/legacy.webp';
		// Simulate a pre-1.2.2 install: the serialized array is stored under
		// the legacy key, but no exact-key entry exists.
		update_post_meta( 42, VariantManifest::META_KEY_LEGACY, array( '2026/05/legacy.webp' ) );

		self::assertTrue( $this->manifest->is_owned_by( 42, $path ) );
	}

	// ----------------------------------------------------------------------
	// Object-cache layer — is_owned() is on the public-render hot path. After
	// a cache write, subsequent calls must NOT issue a DB query (cache hit).
	// ----------------------------------------------------------------------

	public function test_is_owned_uses_object_cache_on_repeat_lookups(): void {
		$path = $this->upload_dir . '/2026/05/photo.webp';
		// Seed the cache directly so we don't depend on $wpdb for the test.
		wp_cache_set( md5( '2026/05/photo.webp' ), 1, VariantManifest::CACHE_GROUP );

		self::assertTrue( $this->manifest->is_owned( $path ) );
	}

	public function test_record_warms_the_object_cache(): void {
		$path = $this->upload_dir . '/2026/05/photo.webp';
		$this->manifest->record( 42, $path );

		// is_owned() must succeed via cache hit alone — no DB required, which
		// in this harness would return null (no $wpdb shim).
		self::assertTrue( $this->manifest->is_owned( $path ) );
	}

	public function test_forget_invalidates_the_object_cache(): void {
		$path = $this->upload_dir . '/2026/05/photo.webp';
		$this->manifest->record( 42, $path );
		self::assertTrue( $this->manifest->is_owned( $path ), 'precondition: cache is warm' );

		$this->manifest->forget( 42, $path );

		// Cache should be invalidated. Without $wpdb the slow path returns
		// null (indeterminate). The test asserts the cache no longer carries
		// the stale "owned" answer.
		$found = false;
		wp_cache_get( md5( '2026/05/photo.webp' ), VariantManifest::CACHE_GROUP, false, $found );
		self::assertFalse( $found, 'forget() must invalidate the cached ownership entry' );
	}

	// -------------------------------------------------------------------------
	// H2 — bulk_insert_owns containment check
	// -------------------------------------------------------------------------

	/**
	 * Belt-and-suspenders against poisoned scanner output: a manifest write
	 * whose rel resolves outside the uploads tree must be dropped before
	 * we commit it to postmeta. Otherwise the rewriter could later
	 * dereference the stored rel to a path outside uploads.
	 */
	public function test_filter_outside_upload_dir_drops_traversal_writes(): void {
		// Create the parent dir so within_upload_dir's "file may not exist
		// yet, check the parent" branch can realpath() a real directory.
		mkdir( $this->upload_dir . '/2026/05', 0777, true );

		$writes = array(
			array(
				'post_id'    => 11,
				'meta_key'   => '_cf_media_manager_owns_abc',
				'meta_value' => '2026/05/photo.webp', // legitimate
			),
			array(
				'post_id'    => 12,
				'meta_key'   => '_cf_media_manager_owns_def',
				'meta_value' => '../../etc/passwd', // traversal
			),
			array(
				'post_id'    => 13,
				'meta_key'   => '_cf_media_manager_owns_ghi',
				'meta_value' => '2026/05/../../../escape.webp', // mid-path traversal
			),
		);

		$kept = VariantManifest::filter_outside_upload_dir( $writes, $this->paths );

		self::assertCount( 1, $kept );
		self::assertSame( 11, $kept[0]['post_id'] );
		self::assertSame( '2026/05/photo.webp', $kept[0]['meta_value'] );
	}

	public function test_filter_outside_upload_dir_drops_empty_meta_value(): void {
		$writes = array(
			array( 'post_id' => 21, 'meta_key' => 'k', 'meta_value' => '' ),
			array( 'post_id' => 22, 'meta_key' => 'k', 'meta_value' => '/' ),
		);

		$kept = VariantManifest::filter_outside_upload_dir( $writes, $this->paths );

		self::assertSame( array(), $kept, 'empty / slash-only meta_value cannot be a legitimate ownership row' );
	}

	public function test_filter_outside_upload_dir_handles_leading_slash(): void {
		mkdir( $this->upload_dir . '/2026/05', 0777, true );

		$writes = array(
			array(
				'post_id'    => 31,
				'meta_key'   => '_cf_media_manager_owns_abc',
				'meta_value' => '/2026/05/photo.webp', // unexpected leading slash, but in uploads
			),
		);

		$kept = VariantManifest::filter_outside_upload_dir( $writes, $this->paths );

		self::assertCount( 1, $kept );
	}

	public function test_filter_outside_upload_dir_noop_on_empty_input(): void {
		self::assertSame( array(), VariantManifest::filter_outside_upload_dir( array(), $this->paths ) );
	}
}
