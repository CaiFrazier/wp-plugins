<?php

namespace CFMediaOptimizer\Tests;

use CFShared\Media\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase {

	private string $sandbox;
	private string $root;       // upload root
	private string $outside;    // a directory OUTSIDE upload root (sibling)
	private Paths $paths;

	protected function setUp(): void {
		$this->sandbox = sys_get_temp_dir() . '/cf-media-manager-paths-' . uniqid();
		$this->root    = $this->sandbox . '/uploads';
		$this->outside = $this->sandbox . '/outside';

		mkdir( $this->root . '/2026/05', 0777, true );
		mkdir( $this->outside, 0777, true );

		file_put_contents( $this->root . '/2026/05/photo.jpg',  'jpeg-bytes' );
		file_put_contents( $this->root . '/2026/05/photo.webp', 'webp-bytes' );
		file_put_contents( $this->outside . '/secret.txt', 'shh' );

		$this->paths = new Paths( $this->root, 'https://example.test/wp-content/uploads' );
	}

	protected function tearDown(): void {
		$this->rrmdir( $this->sandbox );
	}

	public function test_within_upload_dir_accepts_real_files_inside_uploads(): void {
		self::assertTrue( $this->paths->within_upload_dir( $this->root . '/2026/05/photo.jpg' ) );
	}

	public function test_within_upload_dir_rejects_files_outside_uploads(): void {
		self::assertFalse( $this->paths->within_upload_dir( $this->outside . '/secret.txt' ) );
	}

	public function test_within_upload_dir_rejects_traversal_when_path_does_not_exist(): void {
		// Even for a not-yet-written file, parent directory must be inside uploads.
		self::assertFalse( $this->paths->within_upload_dir( $this->outside . '/no-such.webp' ) );
	}

	public function test_within_upload_dir_accepts_nonexistent_path_with_valid_parent(): void {
		// Pre-write check: writing a new file into an existing uploads subdir is fine.
		self::assertTrue( $this->paths->within_upload_dir( $this->root . '/2026/05/new-file.webp' ) );
	}

	public function test_within_upload_dir_rejects_symlink_pointing_outside(): void {
		$link = $this->root . '/2026/05/escape.jpg';
		if ( ! @symlink( $this->outside . '/secret.txt', $link ) ) {
			self::markTestSkipped( 'symlink() not available' );
		}
		self::assertFalse( $this->paths->within_upload_dir( $link ) );
	}

	public function test_within_upload_dir_rejects_null_byte_input(): void {
		// PHP 8's realpath() throws ValueError on null bytes. The security
		// boundary must reject up front so poisoned attachment metadata can't
		// crash an admin endpoint before we return a clean false.
		self::assertFalse( $this->paths->within_upload_dir( $this->root . "/2026/05/photo\0.jpg" ) );
	}

	public function test_within_upload_dir_rejects_empty_input(): void {
		self::assertFalse( $this->paths->within_upload_dir( '' ) );
	}

	public function test_within_upload_dir_rejects_literal_dotdot_traversal(): void {
		// Even if realpath would resolve this back inside uploads on some
		// hosts, we reject literal `..` segments at the boundary so behavior
		// is consistent and callers can't depend on platform quirks.
		self::assertFalse( $this->paths->within_upload_dir( $this->root . '/2026/../../etc/passwd' ) );
	}

	public function test_url_to_path_maps_uploads_url_to_filesystem(): void {
		$path = $this->paths->url_to_path( 'https://example.test/wp-content/uploads/2026/05/photo.webp' );
		self::assertSame( $this->root . '/2026/05/photo.webp', $path );
	}

	public function test_url_to_path_returns_null_for_off_site_urls(): void {
		self::assertNull( $this->paths->url_to_path( 'https://other.example/2026/05/photo.webp' ) );
	}

	public function test_url_to_path_blocks_traversal_inside_url(): void {
		self::assertNull( $this->paths->url_to_path( 'https://example.test/wp-content/uploads/../../etc/passwd' ) );
	}

	public function test_url_to_path_rejects_symlink_resolving_outside_uploads(): void {
		$link = $this->root . '/2026/05/escape.webp';
		if ( ! @symlink( $this->outside . '/secret.txt', $link ) ) {
			self::markTestSkipped( 'symlink() not available' );
		}
		self::assertNull(
			$this->paths->url_to_path( 'https://example.test/wp-content/uploads/2026/05/escape.webp' )
		);
	}

	public function test_url_to_path_strips_query_string_before_filesystem_lookup(): void {
		// A WP-style cache-buster (`?ver=...`) was previously preserved through
		// url_to_path, so file_exists() saw `/photo.webp?ver=1` and returned
		// false — silently breaking the rewriter's variant_exists check on
		// every site that emitted versioned URLs (most page builders).
		$path = $this->paths->url_to_path( 'https://example.test/wp-content/uploads/2026/05/photo.webp?ver=12345' );

		self::assertSame( $this->root . '/2026/05/photo.webp', $path );
	}

	public function test_url_to_path_strips_fragment_before_lookup(): void {
		$path = $this->paths->url_to_path( 'https://example.test/wp-content/uploads/2026/05/photo.webp#hash' );

		self::assertSame( $this->root . '/2026/05/photo.webp', $path );
	}

	public function test_url_to_path_decodes_percent_encoded_filename(): void {
		$dir = $this->root . '/2026/05';
		file_put_contents( $dir . '/my photo.webp', 'x' );

		$path = $this->paths->url_to_path( 'https://example.test/wp-content/uploads/2026/05/my%20photo.webp' );

		self::assertSame( $dir . '/my photo.webp', $path );
	}

	public function test_url_to_path_rejects_null_byte_injection_after_decode(): void {
		// %00 decodes to a NUL byte — most filesystems truncate on NUL, which
		// could let an attacker target `/wp-content/uploads/safe%00../../etc/passwd`
		// as a path that resolves outside the tree. Reject before mapping.
		self::assertNull(
			$this->paths->url_to_path( 'https://example.test/wp-content/uploads/safe%00.jpg' )
		);
	}

	public function test_url_to_path_blocks_traversal_via_percent_encoded_dots(): void {
		self::assertNull(
			$this->paths->url_to_path( 'https://example.test/wp-content/uploads/%2E%2E/%2E%2E/etc/passwd' )
		);
	}

	public function test_swap_extension_handles_query_string(): void {
		self::assertSame(
			'https://example.test/foo.webp?v=1',
			Paths::swap_extension( 'https://example.test/foo.jpg?v=1', 'webp' )
		);
	}

	public function test_swap_extension_rejects_unrecognized_extension(): void {
		self::assertNull( Paths::swap_extension( 'https://example.test/foo.gif', 'webp' ) );
	}

	public function test_src_to_variant_path_replaces_extension(): void {
		self::assertSame( '/x/y/foo.avif', Paths::src_to_variant_path( '/x/y/foo.jpg', 'avif' ) );
		self::assertSame( '/x/y/foo.webp', Paths::src_to_variant_path( '/x/y/foo.jpeg', 'webp' ) );
		self::assertSame( '/x/y/foo.webp', Paths::src_to_variant_path( '/x/y/foo.PNG', 'webp' ) );
	}

	// ------------------------------------------------------------------
	// normalize_upload_url — covers the URL forms hand-coded HTML uses
	// ------------------------------------------------------------------

	public function test_normalize_accepts_absolute_url_under_base(): void {
		self::assertSame(
			'https://example.test/wp-content/uploads/2026/05/photo.png',
			$this->paths->normalize_upload_url( 'https://example.test/wp-content/uploads/2026/05/photo.png' )
		);
	}

	public function test_normalize_accepts_protocol_relative_url(): void {
		// `//host/path` is what some HTTPS-migration helpers emit. Should
		// normalize to the absolute base scheme + same host + same path.
		self::assertSame(
			'https://example.test/wp-content/uploads/2026/05/photo.png',
			$this->paths->normalize_upload_url( '//example.test/wp-content/uploads/2026/05/photo.png' )
		);
	}

	public function test_normalize_accepts_root_relative_url(): void {
		// The case that was previously rejected: Divi Code Module and similar
		// page-builder code blocks emit `/wp-content/uploads/...` without any
		// host or scheme. The rewriter silently dropped these.
		self::assertSame(
			'https://example.test/wp-content/uploads/2026/05/photo.png',
			$this->paths->normalize_upload_url( '/wp-content/uploads/2026/05/photo.png' )
		);
	}

	public function test_normalize_rejects_cross_host_absolute_url(): void {
		self::assertNull(
			$this->paths->normalize_upload_url( 'https://other-site.com/wp-content/uploads/2026/05/photo.png' )
		);
	}

	public function test_normalize_rejects_cross_host_protocol_relative_url(): void {
		self::assertNull(
			$this->paths->normalize_upload_url( '//other-site.com/wp-content/uploads/2026/05/photo.png' )
		);
	}

	public function test_normalize_rejects_root_relative_outside_uploads(): void {
		self::assertNull(
			$this->paths->normalize_upload_url( '/wp-content/themes/something/image.png' )
		);
		self::assertNull(
			$this->paths->normalize_upload_url( '/some/random/path/image.png' )
		);
	}

	public function test_normalize_rejects_empty_and_garbage(): void {
		self::assertNull( $this->paths->normalize_upload_url( '' ) );
		self::assertNull( $this->paths->normalize_upload_url( 'garbage' ) );
		self::assertNull( $this->paths->normalize_upload_url( 'relative/no/scheme.png' ) );
	}

	public function test_url_to_path_now_resolves_root_relative_url(): void {
		// End-to-end: root-relative URL → on-disk path. This is what makes
		// the rewriter's variant_exists() check work for Divi-rendered imgs.
		self::assertSame(
			$this->root . '/2026/05/photo.jpg',
			$this->paths->url_to_path( '/wp-content/uploads/2026/05/photo.jpg' )
		);
	}

	public function test_url_to_path_strips_query_on_root_relative_url(): void {
		self::assertSame(
			$this->root . '/2026/05/photo.jpg',
			$this->paths->url_to_path( '/wp-content/uploads/2026/05/photo.jpg?ver=12345' )
		);
	}

	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS );
		$rii = new \RecursiveIteratorIterator( $it, \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $rii as $f ) {
			if ( $f->isLink() || $f->isFile() ) {
				@unlink( $f->getPathname() );
			} else {
				@rmdir( $f->getPathname() );
			}
		}
		@rmdir( $dir );
	}

	// -------------------------------------------------------------------------
	// 5.1 — to_rel / to_rel_or_empty centralization
	// -------------------------------------------------------------------------

	public function test_to_rel_strips_upload_prefix(): void {
		self::assertSame(
			'2026/05/photo.jpg',
			$this->paths->to_rel( $this->root . '/2026/05/photo.jpg' )
		);
	}

	public function test_to_rel_returns_null_for_path_outside_uploads(): void {
		self::assertNull( $this->paths->to_rel( $this->outside . '/secret.txt' ) );
	}

	public function test_to_rel_returns_null_for_empty_input(): void {
		self::assertNull( $this->paths->to_rel( '' ) );
	}

	public function test_to_rel_returns_null_for_uploads_root_itself(): void {
		// Stripping the prefix from the root yields '' which is not a legitimate rel.
		self::assertNull( $this->paths->to_rel( $this->root . '/' ) );
	}

	public function test_to_rel_does_not_match_unrelated_prefix(): void {
		// "<root>-other" starts with similar chars but is NOT under the root.
		// Without the trailing-slash anchor in the prefix check this would
		// produce a false-positive rel.
		self::assertNull( $this->paths->to_rel( $this->root . '-other/file.jpg' ) );
	}

	public function test_to_rel_or_empty_returns_empty_string_for_outside_path(): void {
		self::assertSame( '', $this->paths->to_rel_or_empty( $this->outside . '/secret.txt' ) );
	}

	public function test_to_rel_or_empty_returns_rel_for_inside_path(): void {
		self::assertSame(
			'2026/05/photo.jpg',
			$this->paths->to_rel_or_empty( $this->root . '/2026/05/photo.jpg' )
		);
	}

	public function test_to_rel_or_empty_returns_empty_for_empty_input(): void {
		self::assertSame( '', $this->paths->to_rel_or_empty( '' ) );
	}
}
