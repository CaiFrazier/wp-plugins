<?php

namespace CFMediaManager\Tests;

use CFMediaManager\Converter;
use CFMediaManager\Options;
use CFMediaManager\Paths;
use CFMediaManager\VariantManifest;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the Converter — happy path, rejections, freshness skip.
 *
 * Real fixture files are built on disk under a per-test sandbox dir. Imagick
 * is optional on the test host; we prefer it when available, else the GD
 * fallback path executes. Either way a real .webp must be emitted.
 */
final class ConverterTest extends TestCase {

	private string $sandbox;
	private string $uploads;
	private Paths $paths;
	private Converter $converter;

	protected function setUp(): void {
		if ( ! extension_loaded( 'imagick' ) && ! function_exists( 'imagewebp' ) ) {
			self::markTestSkipped( 'Neither Imagick nor GD-with-WebP available.' );
		}

		cf_media_manager_test_reset_state();

		$this->sandbox = sys_get_temp_dir() . '/cf-media-manager-converter-' . uniqid();
		$this->uploads = $this->sandbox . '/uploads/2026/05';
		mkdir( $this->uploads, 0777, true );

		$this->paths     = new Paths( $this->sandbox . '/uploads', 'https://example.test/wp-content/uploads' );
		$this->converter = new Converter( $this->paths );
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

	private function write_jpeg( string $path, int $w = 64, int $h = 64 ): void {
		$im = imagecreatetruecolor( $w, $h );
		// A non-trivial gradient so the encoder actually has work to do.
		for ( $x = 0; $x < $w; $x++ ) {
			$color = imagecolorallocate( $im, $x * 4 % 256, 128, 200 );
			imageline( $im, $x, 0, $x, $h, $color );
		}
		imagejpeg( $im, $path, 90 );
		unset( $im );
	}

	// -------------------------------------------------------------------------
	// Happy path
	// -------------------------------------------------------------------------

	public function test_convert_emits_a_webp_for_a_real_jpeg(): void {
		$src  = $this->uploads . '/photo.jpg';
		$dest = $this->uploads . '/photo.webp';
		$this->write_jpeg( $src );

		self::assertTrue( $this->converter->convert( $src ) );
		self::assertFileExists( $dest );
		self::assertGreaterThan( 0, filesize( $dest ) );
	}

	// -------------------------------------------------------------------------
	// Rejections
	// -------------------------------------------------------------------------

	public function test_convert_rejects_malformed_image_file(): void {
		$src = $this->uploads . '/not-an-image.jpg';
		file_put_contents( $src, 'this is text, not a JPEG' );

		self::assertFalse( $this->converter->convert( $src ) );
	}

	public function test_convert_rejects_unsupported_image_type(): void {
		// Build a tiny GIF — supported by getimagesize() but rejected by the
		// type allow-list (only JPEG and PNG are converted).
		$src = $this->uploads . '/anim.gif';
		$im  = imagecreatetruecolor( 8, 8 );
		imagegif( $im, $src );
		unset( $im );

		self::assertFalse( $this->converter->convert( $src ) );
	}

	public function test_convert_rejects_paths_outside_upload_dir(): void {
		// Try to convert a file in a sibling directory. The Paths within-upload
		// check should refuse to write the .webp.
		$outside = $this->sandbox . '/outside';
		mkdir( $outside, 0777, true );
		$src = $outside . '/foreign.jpg';
		$this->write_jpeg( $src );

		self::assertFalse( $this->converter->convert( $src ) );
	}

	public function test_convert_is_idempotent_when_webp_is_fresher_than_source(): void {
		$src  = $this->uploads . '/photo.jpg';
		$dest = $this->uploads . '/photo.webp';
		$this->write_jpeg( $src );

		self::assertTrue( $this->converter->convert( $src ) );
		$first_mtime = filemtime( $dest );
		clearstatcache( true, $dest );

		// Sleep so any timestamp diff would be observable, then re-convert.
		// We expect the converter to short-circuit and NOT touch the file.
		sleep( 1 );
		self::assertTrue( $this->converter->convert( $src ) );
		clearstatcache( true, $dest );
		self::assertSame( $first_mtime, filemtime( $dest ), 'fresh webp was re-encoded — freshness check broken' );
	}

	public function test_convert_force_flag_overrides_freshness(): void {
		$src  = $this->uploads . '/photo.jpg';
		$dest = $this->uploads . '/photo.webp';
		$this->write_jpeg( $src );

		self::assertTrue( $this->converter->convert( $src ) );
		$first_mtime = filemtime( $dest );
		clearstatcache( true, $dest );

		sleep( 1 );
		self::assertTrue( $this->converter->convert( $src, null, /* force */ true ) );
		clearstatcache( true, $dest );
		self::assertGreaterThan( $first_mtime, filemtime( $dest ), 'force=true did not rewrite the webp' );
	}

	// -------------------------------------------------------------------------
	// convert_attachment empty-meta guard (WEBP-P1-006)
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Ownership protection — refuse to overwrite variants we did not write.
	//
	// In production the manifest is keyed by attachment_id; here we exercise
	// the boundary by passing the id explicitly so the fast path triggers.
	// -------------------------------------------------------------------------

	public function test_convert_refuses_to_overwrite_unowned_webp_under_force(): void {
		// Simulate the user uploading both logo.jpg and a hand-rolled logo.webp
		// directly. The plugin must not clobber that webp under any flag.
		$jpg  = $this->uploads . '/logo.jpg';
		$webp = $this->uploads . '/logo.webp';
		$this->write_jpeg( $jpg );

		// Pre-existing user file with mtime older than the JPG — would
		// normally trip the freshness re-encode, but ownership must shield it.
		file_put_contents( $webp, 'USER UPLOADED' );
		touch( $webp, filemtime( $jpg ) - 60 );

		// No manifest entry for attachment 7 → owned-check fails → refuse.
		self::assertFalse( $this->converter->convert( $jpg, null, true /* force */, 7 ) );
		self::assertSame( 'USER UPLOADED', file_get_contents( $webp ), 'user webp must remain bitwise unchanged' );
	}

	public function test_convert_overwrites_owned_webp_under_force(): void {
		// Same setup but pre-record manifest ownership — the converter should
		// now re-encode under force because the dest is ours to touch.
		$jpg  = $this->uploads . '/photo.jpg';
		$webp = $this->uploads . '/photo.webp';
		$this->write_jpeg( $jpg );

		$manifest = new VariantManifest( $this->paths );
		$manifest->record( 11, $webp );

		// Initial conversion fills the slot with real WebP bytes.
		self::assertTrue( $this->converter->convert( $jpg, null, false, 11 ) );
		$first_mtime = filemtime( $webp );

		sleep( 1 );

		// Force re-encode under owned manifest → should rewrite.
		self::assertTrue( $this->converter->convert( $jpg, null, true, 11 ) );
		clearstatcache( true, $webp );
		self::assertGreaterThan( $first_mtime, filemtime( $webp ) );
	}

	public function test_convert_records_ownership_on_fresh_write(): void {
		$jpg = $this->uploads . '/captured.jpg';
		$this->write_jpeg( $jpg );

		$manifest = new VariantManifest( $this->paths );

		self::assertTrue( $this->converter->convert( $jpg, null, false, 88 ) );
		self::assertTrue(
			$manifest->is_owned_by( 88, $this->uploads . '/captured.webp' ),
			'first successful write should claim the variant under the source attachment'
		);
	}

	public function test_convert_rejects_oversized_source_file(): void {
		// Build a file that exceeds the source-bytes ceiling. We don't write
		// real JPEG bytes — getimagesize() would reject it later anyway, but
		// the filesize cap must trip first so we never enter the decoder.
		// Default cap is 50 MB; use 51 MB to be sure we trip it regardless of
		// any host-side option drift in the test bootstrap.
		$jpg = $this->uploads . '/giant.jpg';
		$f = fopen( $jpg, 'wb' );
		ftruncate( $f, 51 * 1024 * 1024 );
		fclose( $f );

		self::assertFalse( $this->converter->convert( $jpg ) );
		self::assertNotNull(
			$this->converter->last_skip_reason(),
			'oversized source must populate last_skip_reason so the batch UI can surface why'
		);
		self::assertStringContainsString(
			'larger than',
			(string) $this->converter->last_skip_reason()
		);
	}

	public function test_convert_honors_user_configured_max_source_mb(): void {
		// Admin lowers the cap to 10 MB. An 11 MB source must now be rejected
		// even though it would have passed the default 50 MB cap.
		$GLOBALS['cf_media_manager_test_options']['cf_media_manager_max_source_mb'] = 10;

		$jpg = $this->uploads . '/medium.jpg';
		$f   = fopen( $jpg, 'wb' );
		ftruncate( $f, 11 * 1024 * 1024 );
		fclose( $f );

		self::assertFalse( $this->converter->convert( $jpg ) );
	}

	public function test_convert_clamps_oversized_max_source_mb_option(): void {
		// Hand-edited option claims 9999 MB. resolve_max_source_bytes() must
		// clamp to HARD_MAX_SOURCE_MB (200 MB), so a 201 MB source is still
		// rejected — defense against a UI bypass setting an arbitrary value.
		$GLOBALS['cf_media_manager_test_options']['cf_media_manager_max_source_mb'] = 9999;

		$jpg = $this->uploads . '/huge.jpg';
		$f   = fopen( $jpg, 'wb' );
		ftruncate( $f, 201 * 1024 * 1024 );
		fclose( $f );

		self::assertFalse( $this->converter->convert( $jpg ) );
	}

	public function test_convert_attachment_returns_reasons_for_skipped_variants(): void {
		// An attachment with a missing source file should report a clear
		// reason, not just a silent failure count.
		$GLOBALS['cf_media_manager_test_attachments'][77] = [
			'file' => $this->uploads . '/does-not-exist.jpg',
			'meta' => [ 'file' => 'does-not-exist.jpg', 'sizes' => [] ],
		];

		[ $converted, $failed, , , , $reasons ] = $this->converter->convert_attachment( 77 );

		self::assertSame( 0, $converted );
		self::assertGreaterThan( 0, $failed );
		self::assertNotEmpty( $reasons, 'failed attachments must surface at least one reason' );
		self::assertIsArray( $reasons );
	}

	public function test_convert_attachment_handles_empty_meta_file_without_corrupting_size_paths(): void {
		// Set up an attachment with a real original file but no meta['file'].
		// Before WEBP-P1-006 this would attempt to read sizes from
		// $upload_dir/./<size_file> (broken). Now the function should return
		// after converting the original and skip the sizes loop entirely.
		$src = $this->uploads . '/orphan.jpg';
		$this->write_jpeg( $src );
		$GLOBALS['cf_media_manager_test_attachments'][42] = [
			'file' => $src,
			'meta' => [ 'sizes' => [ 'thumb' => [ 'file' => 'thumb.jpg' ] ] /* no 'file' key */ ],
		];

		[ $converted, $failed ] = $this->converter->convert_attachment( 42 );

		self::assertSame( 1, $converted, 'original should still convert' );
		self::assertSame( 0, $failed, 'sizes loop should be skipped, not blow up' );
		self::assertFileExists( $this->uploads . '/orphan.webp' );
	}

	// -------------------------------------------------------------------------
	// is_attachment_converted — must agree with the rewriter, not just stat.
	//
	// Pre-1.2.2 this returned true on a foreign same-basename .webp because
	// it only checked existence + freshness. The dashboard would report the
	// attachment as "converted" while the rewriter (ownership-aware on 1.2.1+)
	// silently refused to serve the foreign variant. The status check now
	// requires manifest ownership too so the UI matches reality.
	// -------------------------------------------------------------------------

	public function test_is_attachment_converted_returns_true_after_a_real_convert(): void {
		$jpg = $this->uploads . '/captured.jpg';
		$this->write_jpeg( $jpg );
		$GLOBALS['cf_media_manager_test_attachments'][77] = [
			'file' => $jpg,
			'meta' => [ 'file' => 'captured.jpg', 'sizes' => [] ],
		];

		self::assertTrue( $this->converter->convert( $jpg, null, false, 77 ) );
		self::assertTrue( $this->converter->is_attachment_converted( 77 ) );
	}

	public function test_is_attachment_converted_returns_false_for_unowned_foreign_webp(): void {
		// Source jpg + a same-basename .webp on disk that we never recorded.
		// In 1.2.1 this returned true (status lied to the admin). After the
		// manifest gate it must return false — the file exists but the
		// rewriter will refuse it, and the status UI should agree.
		$jpg = $this->uploads . '/foreign.jpg';
		$this->write_jpeg( $jpg );
		$foreign_webp = $this->uploads . '/foreign.webp';
		file_put_contents( $foreign_webp, 'user-uploaded-webp' );
		// Bump mtime so it's fresher than the source (i.e. matches the old
		// "looks converted" heuristic).
		touch( $foreign_webp, time() + 60 );

		$GLOBALS['cf_media_manager_test_attachments'][88] = [
			'file' => $jpg,
			'meta' => [ 'file' => 'foreign.jpg', 'sizes' => [] ],
		];

		self::assertFalse(
			$this->converter->is_attachment_converted( 88 ),
			'a same-basename .webp the plugin did not write must not count as converted'
		);
	}

	// -------------------------------------------------------------------------
	// H7 — per-attachment convert lock
	// -------------------------------------------------------------------------

	/**
	 * Object-cache-held lock blocks a second convert_attachment call against
	 * the same id. The contract: we return a zero-state tuple with an
	 * "already in flight" reason so the caller's counters stay accurate
	 * and the UI surfaces the skip rather than swallowing it.
	 */
	public function test_convert_attachment_lock_blocks_concurrent_call_on_same_id(): void {
		// Pre-populate the per-attachment lock under the same key the
		// converter uses. wp_cache_add will return false on the next call
		// because the slot is occupied, so convert_attachment must take
		// the early-return path.
		$GLOBALS['cf_media_manager_test_cache'][ Converter::CONVERT_LOCK_GROUP ]['cfmm_conv_555'] = 4242;

		[ $converted, $failed, $bytes_saved, $gd, $avif, $reasons ] = $this->converter->convert_attachment( 555 );

		self::assertSame( 0, $converted );
		self::assertSame( 0, $failed );
		self::assertSame( 0, $bytes_saved );
		self::assertSame( 0, $gd );
		self::assertSame( 0, $avif );
		self::assertCount( 1, $reasons );
		self::assertStringContainsString( 'already in progress', $reasons[0] );

		// The lock we pre-populated must still be there — we did NOT take it,
		// so we must NOT have released it. Guards against the bug where
		// the finally block deletes a peer's lock.
		self::assertSame(
			4242,
			$GLOBALS['cf_media_manager_test_cache'][ Converter::CONVERT_LOCK_GROUP ]['cfmm_conv_555'] ?? null,
			'peer\'s lock must remain after blocked reentrancy'
		);
	}

	/**
	 * Cross-process protection: wp_cache_add can succeed in process B's
	 * in-memory cache (no persistent backend) even though process A is
	 * already converting this attachment. The option-level add_option is
	 * the cross-process signal — when it's already present, B must bail
	 * AND release the cache slot it temporarily took (otherwise the
	 * cache slot would leak on every blocked call).
	 */
	public function test_convert_attachment_lock_blocks_on_peer_option_and_releases_own_cache_slot(): void {
		// Pre-populate the option row a peer worker (in a different PHP
		// process) would have written. add_option will return false for
		// us, taking the bail path.
		$peer_option = Converter::CONVERT_LOCK_OPTION_PREFIX . '777';
		$GLOBALS['cf_media_manager_test_options'][ $peer_option ] = array(
			'pid'     => getmypid(), // a LIVE peer — must block, never be stolen.
			'token'   => 'peer-token',
			'expires' => time() + 300,
		);

		[ , , , , , $reasons ] = $this->converter->convert_attachment( 777 );

		self::assertCount( 1, $reasons );
		self::assertStringContainsString( 'already in progress', $reasons[0] );
		// Peer's option row must remain — we never owned it.
		self::assertSame(
			'peer-token',
			$GLOBALS['cf_media_manager_test_options'][ $peer_option ]['token'] ?? null,
			'peer\'s lock-option must remain after blocked reentrancy'
		);
		// AND we must have cleaned up the cache slot we briefly took so
		// the very next call (after the peer finishes and clears the
		// option) can acquire normally.
		self::assertArrayNotHasKey(
			'cfmm_conv_777',
			$GLOBALS['cf_media_manager_test_cache'][ Converter::CONVERT_LOCK_GROUP ] ?? array(),
			'cache slot must be released when we bail on the peer-option check'
		);
	}

	/**
	 * Stale-lock steal: a dead peer left its lock option with an expired
	 * TTL. The next caller must steal it via the verify-after-write
	 * pattern instead of being permanently blocked.
	 */
	public function test_convert_attachment_steals_expired_peer_lock(): void {
		$peer_option = Converter::CONVERT_LOCK_OPTION_PREFIX . '888';
		$GLOBALS['cf_media_manager_test_options'][ $peer_option ] = array(
			'pid'     => 99,
			'token'   => 'dead-peer',
			'expires' => time() - 100, // expired
		);

		// Attachment 888 has no source file — converter takes the early
		// return ("no source"). Critical: it must NOT report "already in
		// progress", because the stale lock should have been stolen.
		[ , , , , , $reasons ] = $this->converter->convert_attachment( 888 );

		self::assertStringNotContainsString( 'already in progress', $reasons[0] ?? '', 'expired lock must be stolen, not treated as held' );
		// Lock option must be gone after the call (release path runs).
		self::assertArrayNotHasKey( $peer_option, $GLOBALS['cf_media_manager_test_options'] );
	}

	/**
	 * Dead-owner reclaim: a worker that crashed mid-convert leaves a lock whose
	 * TTL has NOT yet expired but whose owner PID is gone. The next caller must
	 * reclaim it immediately (via the PID-liveness check) instead of waiting out
	 * the full TTL — this is the fix for the post-crash 'already in progress'
	 * lockout.
	 */
	public function test_convert_attachment_reclaims_dead_pid_lock(): void {
		if ( ! function_exists( 'posix_kill' ) ) {
			self::markTestSkipped( 'posix_kill unavailable — dead-PID reclaim not testable here.' );
		}
		$dead = 2147483647; // above any realistic pid_max -> guaranteed ESRCH.
		if ( @posix_kill( $dead, 0 ) ) {
			self::markTestSkipped( 'chosen PID is unexpectedly alive on this host.' );
		}

		$peer_option = Converter::CONVERT_LOCK_OPTION_PREFIX . '889';
		$GLOBALS['cf_media_manager_test_options'][ $peer_option ] = array(
			'pid'     => $dead,
			'token'   => 'crashed-peer',
			'expires' => time() + 300, // NOT expired — only the dead PID makes it stale.
		);

		[ , , , , , $reasons ] = $this->converter->convert_attachment( 889 );

		self::assertStringNotContainsString(
			'already in progress',
			$reasons[0] ?? '',
			'a lock whose owner PID is dead must be reclaimed before its TTL expires'
		);
		self::assertArrayNotHasKey( $peer_option, $GLOBALS['cf_media_manager_test_options'] );
	}

	/**
	 * Releases the lock cleanly on the normal early-return path (missing
	 * attachment metadata). Without a try/finally release, a single bad
	 * id would poison the slot for 300 seconds.
	 */
	public function test_convert_attachment_releases_lock_on_normal_early_return(): void {
		// No attachment registered for id 999 — get_attached_file returns
		// false, convert_attachment takes the "no source" early return.
		$first  = $this->converter->convert_attachment( 999 );
		$second = $this->converter->convert_attachment( 999 );

		// Both must report the "no source" reason, NOT the "already in
		// flight" reason. If the first call leaked the lock, the second
		// call would short-circuit with the lock-held reason instead.
		self::assertCount( 1, $first[5] );
		self::assertStringContainsString( 'no source file', $first[5][0] );
		self::assertCount( 1, $second[5] );
		self::assertStringContainsString( 'no source file', $second[5][0] );

		// Cache slot must be empty after each call.
		self::assertArrayNotHasKey(
			'cfmm_conv_999',
			$GLOBALS['cf_media_manager_test_cache'][ Converter::CONVERT_LOCK_GROUP ] ?? array()
		);
	}
}
