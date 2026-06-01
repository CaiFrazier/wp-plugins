<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\FinalizeJob;
use CFChunkedUpload\JobStatus;
use CFChunkedUpload\Paths;
use CFChunkedUpload\Settings;
use CFChunkedUpload\UploadSession;
use PHPUnit\Framework\TestCase;

final class FinalizeJobTest extends TestCase {

	private string $sandbox;
	private Paths $paths;
	private Settings $settings;
	private FinalizeJob $job;
	private const ID = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
		$this->sandbox = sys_get_temp_dir() . '/cf-cu-fin-' . uniqid();
		mkdir( $this->sandbox, 0777, true );
		$GLOBALS['cf_chunked_upload_test_uploads'] = [
			'basedir' => $this->sandbox,
			'baseurl' => 'http://example.test',
			'error'   => false,
		];
		$this->paths    = new Paths( $this->sandbox );
		$this->settings = new Settings( $this->paths );
		$this->job      = new FinalizeJob( $this->paths, $this->settings );
	}

	protected function tearDown(): void {
		UploadSession::rrmdir( $this->sandbox );
	}

	private function seed_complete( array $parts, array $meta_over = [] ): UploadSession {
		$whole = implode( '', $parts );
		$s     = new UploadSession( $this->paths, self::ID );
		$s->ensure_meta(
			array_merge(
				[
					'file_name'    => 'backup.zip',
					'mime_type'    => 'application/zip',
					'destination'  => 'import',
					'total_chunks' => count( $parts ),
					'total_sha256' => hash( 'sha256', $whole ),
					'file_size'    => strlen( $whole ),
				],
				$meta_over
			)
		);
		foreach ( $parts as $i => $bytes ) {
			$tmp = $this->sandbox . "/sd$i";
			file_put_contents( $tmp, $bytes );
			$s->store_chunk( $i, $tmp );
		}
		return $s;
	}

	public function test_import_happy_path_moves_file_and_verifies_hash(): void {
		$s   = $this->seed_complete( [ 'PK-header-', 'payload-', 'tail' ] );
		$res = $this->job->process( self::ID );

		self::assertTrue( $res['ok'], json_encode( $res ) );
		self::assertSame( 'import', $res['result']['destination'] );
		self::assertFileExists( $res['result']['path'] );
		self::assertSame( 'PK-header-payload-tail', file_get_contents( $res['result']['path'] ) );
		// Session directory is removed after a successful import.
		self::assertFalse( $s->exists() );
	}

	public function test_whole_file_hash_mismatch_is_rejected(): void {
		$this->seed_complete( [ 'aaa', 'bbb' ], [ 'total_sha256' => str_repeat( 'f', 64 ) ] );
		$res = $this->job->process( self::ID );

		self::assertFalse( $res['ok'] );
		self::assertSame( 'integrity_mismatch', $res['error'] );
	}

	public function test_finalize_supplied_digest_overrides_meta(): void {
		// Meta carries a WRONG digest (e.g. blank first chunk); the
		// finalize-supplied digest is correct and must take precedence.
		$this->seed_complete( [ 'xx', 'yy' ], [ 'total_sha256' => str_repeat( '0', 64 ) ] );
		$good = hash( 'sha256', 'xxyy' );

		$res = $this->job->process( self::ID, $good );

		self::assertTrue( $res['ok'], wp_json_encode( $res ) );
		self::assertSame( 'xxyy', file_get_contents( $res['result']['path'] ) );
	}

	public function test_finalize_supplied_wrong_digest_is_rejected_even_if_meta_ok(): void {
		$this->seed_complete( [ 'aa', 'bb' ] ); // meta digest is correct
		$res = $this->job->process( self::ID, str_repeat( 'e', 64 ) );

		self::assertFalse( $res['ok'] );
		self::assertSame( 'integrity_mismatch', $res['error'] );
	}

	public function test_session_is_reaped_on_post_assembly_failure(): void {
		// Integrity mismatch happens after the assembler has consumed parts;
		// the session must be gone so a retry can't hit a confusing
		// "incomplete" state with half the chunks missing.
		$s = $this->seed_complete( [ 'aa', 'bb' ], [ 'total_sha256' => str_repeat( 'b', 64 ) ] );
		$res = $this->job->process( self::ID );

		self::assertFalse( $res['ok'] );
		self::assertSame( 'integrity_mismatch', $res['error'] );
		self::assertFalse( $s->exists() );
	}

	public function test_incomplete_session_is_rejected(): void {
		$s = new UploadSession( $this->paths, self::ID );
		$s->ensure_meta(
			[
				'file_name'    => 'f.zip',
				'mime_type'    => 'application/zip',
				'destination'  => 'import',
				'total_chunks' => 3,
			]
		);
		$tmp = $this->sandbox . '/p0';
		file_put_contents( $tmp, 'x' );
		$s->store_chunk( 0, $tmp );

		$res = $this->job->process( self::ID );
		self::assertFalse( $res['ok'] );
		self::assertSame( 'incomplete', $res['error'] );
	}

	public function test_collision_policy_reject(): void {
		$this->settings->update( [ 'collision_policy' => 'reject' ] );
		// Pre-create the colliding target in the default imports dir.
		$imports = $this->settings->imports_dir();
		mkdir( $imports, 0777, true );
		file_put_contents( $imports . '/backup.zip', 'existing' );

		$this->seed_complete( [ 'new-', 'data' ] );
		$res = $this->job->process( self::ID );

		self::assertFalse( $res['ok'] );
		self::assertSame( 'name_collision', $res['error'] );
		self::assertSame( 'existing', file_get_contents( $imports . '/backup.zip' ) );
	}

	public function test_collision_policy_timestamp_renames(): void {
		$imports = $this->settings->imports_dir();
		mkdir( $imports, 0777, true );
		file_put_contents( $imports . '/backup.zip', 'existing' );

		$this->seed_complete( [ 'fresh' ] );
		$res = $this->job->process( self::ID );

		self::assertTrue( $res['ok'] );
		self::assertNotSame( 'backup.zip', $res['result']['fileName'] );
		self::assertMatchesRegularExpression( '/^backup-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $res['result']['fileName'] );
		self::assertSame( 'existing', file_get_contents( $imports . '/backup.zip' ) );
	}

	public function test_run_publishes_complete_status_and_lock_dedupes_peer(): void {
		$this->seed_complete( [ 'one', 'two' ] );

		$this->job->run( 'job-1', self::ID );
		$status = JobStatus::get( 'job-1' );
		self::assertSame( JobStatus::COMPLETE, $status['status'] );

		// A peer dispatch while the (already released) lock is gone would
		// re-run; simulate an unreleased lock and assert the second run bails
		// without clobbering the published result.
		add_option( FinalizeJob::LOCK_PREFIX . self::ID, [ 'token' => 'x', 'expires' => time() + 999 ], '', false );
		$this->job->run( 'job-2', self::ID );
		self::assertNull( JobStatus::get( 'job-2' ) === null ? null : JobStatus::get( 'job-2' )['result'] ?? null );
	}

	public function test_place_import_aborts_when_session_was_cancelled(): void {
		$s = $this->seed_complete( [ 'data' ] );
		// Simulate cancel landing after assembly: assemble manually, delete
		// the session dir, then call place_import directly.
		$assembled = $this->sandbox . '/asm.tmp';
		file_put_contents( $assembled, 'data' );
		$meta = $s->meta();
		$s->delete();

		$res = $this->job->place_import( $s, $assembled, $meta, 'text/plain', 'application/zip' );
		self::assertFalse( $res['ok'] );
		self::assertSame( 'cancelled', $res['error'] );
	}
}
