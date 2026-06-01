<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\Cleanup;
use CFChunkedUpload\Paths;
use CFChunkedUpload\UploadSession;
use PHPUnit\Framework\TestCase;

final class CleanupTest extends TestCase {

	private string $sandbox;
	private Paths $paths;

	private const FRESH = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';
	private const STALE = '7cb8c921-aebe-42e2-91c5-11d15fe541d9';

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
		$this->sandbox = sys_get_temp_dir() . '/cf-cu-clean-' . uniqid();
		mkdir( $this->sandbox, 0777, true );
		$this->paths = new Paths( $this->sandbox );
	}

	protected function tearDown(): void {
		UploadSession::rrmdir( $this->sandbox );
	}

	private function make_session( string $id, int $age_seconds ): UploadSession {
		$s = new UploadSession( $this->paths, $id );
		$s->ensure_meta(
			[
				'file_name'    => 'f.zip',
				'mime_type'    => 'application/zip',
				'destination'  => 'import',
				'total_chunks' => 1,
			]
		);
		$tmp = $this->sandbox . '/seed-' . $id;
		file_put_contents( $tmp, 'data' );
		$s->store_chunk( 0, $tmp );
		$s->touch_heartbeat();

		$when = time() - $age_seconds;
		foreach ( (array) scandir( $s->dir() ) as $e ) {
			if ( '.' !== $e && '..' !== $e ) {
				touch( $s->dir() . '/' . $e, $when );
			}
		}
		return $s;
	}

	public function test_deletes_stale_session_keeps_fresh_one(): void {
		$fresh = $this->make_session( self::FRESH, 60 );        // 1 min old
		$stale = $this->make_session( self::STALE, 4 * 3600 );  // 4 hr old

		$cleanup = new Cleanup( $this->paths, fn() => 2 * 3600 ); // 2 hr retention
		$deleted = $cleanup->run();

		self::assertSame( 1, $deleted );
		self::assertTrue( $fresh->exists() );
		self::assertFalse( $stale->exists() );
	}

	public function test_session_at_retention_boundary_with_recent_heartbeat_survives(): void {
		// Parts are old but the heartbeat was just touched (active long upload).
		$s = $this->make_session( self::FRESH, 4 * 3600 );
		$s->touch_heartbeat(); // now

		$deleted = ( new Cleanup( $this->paths, fn() => 2 * 3600 ) )->run();

		self::assertSame( 0, $deleted );
		self::assertTrue( $s->exists() );
	}

	public function test_directory_without_meta_or_heartbeat_is_swept_regardless_of_age(): void {
		$dir = $this->paths->session_dir( self::FRESH );
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/stray.txt', 'x' ); // fresh, but no .meta/.heartbeat

		$deleted = ( new Cleanup( $this->paths, fn() => 2 * 3600 ) )->run();

		self::assertSame( 1, $deleted );
		self::assertDirectoryDoesNotExist( $dir );
	}

	public function test_returns_zero_when_root_absent(): void {
		self::assertSame( 0, ( new Cleanup( $this->paths, fn() => 3600 ) )->run() );
	}

	public function test_schedule_registers_cron_once(): void {
		Cleanup::schedule();
		self::assertNotFalse( wp_next_scheduled( Cleanup::HOOK ) );
		Cleanup::unschedule();
		self::assertFalse( wp_next_scheduled( Cleanup::HOOK ) );
	}

	// --- SEC-7: assembling marker ---

	public function test_session_with_recent_assembling_marker_is_skipped(): void {
		$s = $this->make_session( self::STALE, 4 * 3600 ); // old enough to be reaped
		$s->mark_assembling();                              // but finalize job is queued/running

		$deleted = ( new Cleanup( $this->paths, fn() => 2 * 3600 ) )->run();

		self::assertSame( 0, $deleted );
		self::assertTrue( $s->exists() );
	}

	// --- FEAT-5: importer file retention ---

	public function test_imports_retention_deletes_old_file_and_keeps_recent(): void {
		$imports_dir = $this->sandbox . '/cf-imports';
		mkdir( $imports_dir, 0777, true );

		$old_file  = $imports_dir . '/old.zip';
		$new_file  = $imports_dir . '/new.zip';
		file_put_contents( $old_file, 'old' );
		file_put_contents( $new_file, 'new' );

		// Back-date old file beyond retention.
		touch( $old_file, time() - ( 8 * 86400 ) ); // 8 days old

		$cleanup = new Cleanup(
			$this->paths,
			fn() => 2 * 3600,                  // session retention (irrelevant here)
			fn() => $imports_dir,
			fn() => 7                           // 7-day import retention
		);
		$cleanup->run();

		self::assertFileDoesNotExist( $old_file );
		self::assertFileExists( $new_file );
	}

	public function test_imports_retention_skipped_when_days_is_zero(): void {
		$imports_dir = $this->sandbox . '/cf-imports';
		mkdir( $imports_dir, 0777, true );
		$file = $imports_dir . '/backup.zip';
		file_put_contents( $file, 'data' );
		touch( $file, time() - ( 100 * 86400 ) ); // very old

		$cleanup = new Cleanup(
			$this->paths,
			fn() => 2 * 3600,
			fn() => $imports_dir,
			fn() => 0 // 0 = never delete
		);
		$cleanup->run();

		self::assertFileExists( $file );
	}

	public function test_stale_assembling_marker_falls_through_to_normal_age_check(): void {
		$s = $this->make_session( self::STALE, 4 * 3600 );
		$s->mark_assembling();
		// Back-date the marker beyond the retention window — worker died.
		touch( $s->dir() . '/' . UploadSession::ASSEMBLING_FILE, time() - ( 3 * 3600 ) );

		$deleted = ( new Cleanup( $this->paths, fn() => 2 * 3600 ) )->run();

		self::assertSame( 1, $deleted );
		self::assertFalse( $s->exists() );
	}
}
