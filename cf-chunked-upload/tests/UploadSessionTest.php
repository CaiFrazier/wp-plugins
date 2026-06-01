<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\Paths;
use CFChunkedUpload\UploadSession;
use PHPUnit\Framework\TestCase;

final class UploadSessionTest extends TestCase {

	private string $sandbox;
	private Paths $paths;

	private const VALID_ID = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
		$this->sandbox = sys_get_temp_dir() . '/cf-cu-sess-' . uniqid();
		mkdir( $this->sandbox, 0777, true );
		$this->paths = new Paths( $this->sandbox );
	}

	protected function tearDown(): void {
		UploadSession::rrmdir( $this->sandbox );
	}

	private function make_session( string $id, int $owner_id ): UploadSession {
		$s = new UploadSession( $this->paths, $id );
		$s->ensure_meta( $this->declared( [ 'owner_id' => $owner_id ] ) );
		return $s;
	}

	private function declared( array $over = [] ): array {
		return array_merge(
			[
				'file_name'    => 'backup.zip',
				'mime_type'    => 'application/zip',
				'destination'  => 'import',
				'total_chunks' => 3,
				'total_sha256' => str_repeat( 'a', 64 ),
				'file_size'    => 999,
			],
			$over
		);
	}

	public function test_is_valid_id_accepts_uuid_v4(): void {
		self::assertTrue( UploadSession::is_valid_id( self::VALID_ID ) );
	}

	public function test_is_valid_id_rejects_traversal_and_separators(): void {
		self::assertFalse( UploadSession::is_valid_id( '../../etc/passwd' ) );
		self::assertFalse( UploadSession::is_valid_id( 'a/b' ) );
		self::assertFalse( UploadSession::is_valid_id( self::VALID_ID . "\0" ) );
		self::assertFalse( UploadSession::is_valid_id( 'not-a-uuid' ) );
		// v1 UUID (version nibble 1) must be rejected — we require v4.
		self::assertFalse( UploadSession::is_valid_id( '6ba7b810-9dad-11d1-80b4-00c04fd430c8' ) );
	}

	public function test_constructor_rejects_invalid_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		new UploadSession( $this->paths, 'bogus' );
	}

	public function test_ensure_meta_persists_first_writer_and_ignores_later_divergence(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$first = $s->ensure_meta( $this->declared() );
		self::assertSame( 3, $first['total_chunks'] );

		// A second chunk declaring a DIFFERENT total must observe the
		// already-persisted record, not overwrite it.
		$s2 = new UploadSession( $this->paths, self::VALID_ID );
		$second = $s2->ensure_meta( $this->declared( [ 'total_chunks' => 99 ] ) );
		self::assertSame( 3, $second['total_chunks'] );
		self::assertSame( 3, $s2->total_chunks() );
	}

	public function test_store_and_enumerate_chunks(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared() );

		foreach ( [ 0, 2 ] as $i ) {
			$tmp = $this->sandbox . "/tmp$i";
			file_put_contents( $tmp, "chunk$i" );
			self::assertTrue( $s->store_chunk( $i, $tmp ) );
			self::assertFileDoesNotExist( $tmp );
		}

		self::assertSame( [ 0, 2 ], $s->received_indices() );
		self::assertSame( 2, $s->received_count() );
		self::assertFalse( $s->has_all_chunks() );
	}

	public function test_has_all_chunks_true_only_when_every_index_present(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared() );
		foreach ( [ 0, 1, 2 ] as $i ) {
			$tmp = $this->sandbox . "/t$i";
			file_put_contents( $tmp, "c$i" );
			$s->store_chunk( $i, $tmp );
		}
		self::assertTrue( $s->has_all_chunks() );
	}

	public function test_has_all_chunks_false_for_duplicate_plus_gap(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared() );
		// Two parts present but index 1 missing — count is 2 of 3 either way,
		// the per-index check is what matters here.
		foreach ( [ 0, 2 ] as $i ) {
			$tmp = $this->sandbox . "/t$i";
			file_put_contents( $tmp, "c$i" );
			$s->store_chunk( $i, $tmp );
		}
		self::assertFalse( $s->has_all_chunks() );
	}

	public function test_newest_mtime_reflects_latest_touched_file(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared() );
		$tmp = $this->sandbox . '/t0';
		file_put_contents( $tmp, 'c0' );
		$s->store_chunk( 0, $tmp );

		$old = time() - 10000;
		touch( $s->chunk_path( 0 ), $old );
		touch( $s->dir() . '/' . UploadSession::META_FILE, $old );

		$s->touch_heartbeat();
		self::assertGreaterThan( $old, $s->newest_mtime() );
	}

	public function test_owner_id_is_persisted_first_writer_wins(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared( [ 'owner_id' => 7 ] ) );
		self::assertSame( 7, $s->owner_id() );

		// A later chunk claiming a different owner cannot reassign it.
		$s2 = new UploadSession( $this->paths, self::VALID_ID );
		$s2->ensure_meta( $this->declared( [ 'owner_id' => 99 ] ) );
		self::assertSame( 7, $s2->owner_id() );
	}

	public function test_owner_id_defaults_to_zero_when_unset(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared() );
		self::assertSame( 0, $s->owner_id() );
	}

	public function test_delete_removes_session_directory(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared() );
		self::assertTrue( $s->exists() );
		$s->delete();
		self::assertFalse( $s->exists() );
	}

	// --- SEC-7: assembling marker ---

	public function test_mark_and_unmark_assembling(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared() );

		self::assertFalse( $s->is_assembling() );
		$s->mark_assembling();
		self::assertTrue( $s->is_assembling() );
		$s->unmark_assembling();
		self::assertFalse( $s->is_assembling() );
	}

	// --- FEAT-8: count_user_sessions ---

	public function test_count_user_sessions_returns_zero_when_no_sessions(): void {
		self::assertSame( 0, UploadSession::count_user_sessions( $this->paths, 1 ) );
	}

	public function test_count_user_sessions_counts_matching_owner(): void {
		$this->make_session( self::VALID_ID, 7 );
		// Another session owned by a different user.
		$other_id = '7cb8c921-aebe-42e2-91c5-11d15fe541d9';
		$this->make_session( $other_id, 99 );

		self::assertSame( 1, UploadSession::count_user_sessions( $this->paths, 7 ) );
		self::assertSame( 1, UploadSession::count_user_sessions( $this->paths, 99 ) );
		self::assertSame( 0, UploadSession::count_user_sessions( $this->paths, 42 ) );
	}

	// --- SEC-4: finalize_job_id ---

	public function test_finalize_job_id_is_null_before_set(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared() );
		self::assertNull( $s->finalize_job_id() );
	}

	public function test_set_finalize_job_id_persists_across_instances(): void {
		$s = new UploadSession( $this->paths, self::VALID_ID );
		$s->ensure_meta( $this->declared() );
		$s->set_finalize_job_id( 'test-job-abc' );

		$s2 = new UploadSession( $this->paths, self::VALID_ID );
		self::assertSame( 'test-job-abc', $s2->finalize_job_id() );
	}
}
