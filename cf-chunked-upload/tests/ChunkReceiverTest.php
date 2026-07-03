<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\ChunkReceiver;
use CFChunkedUpload\Paths;
use CFChunkedUpload\UploadSession;
use PHPUnit\Framework\TestCase;

final class ChunkReceiverTest extends TestCase {

	private string $sandbox;
	private ChunkReceiver $rx;
	private const ID = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
		$this->sandbox = sys_get_temp_dir() . '/cf-cu-rx-' . uniqid();
		mkdir( $this->sandbox, 0777, true );
		// Permissive mime gate; type-policy is exercised separately below.
		$this->rx = new ChunkReceiver( new Paths( $this->sandbox ), fn() => true );
	}

	protected function tearDown(): void {
		UploadSession::rrmdir( $this->sandbox );
	}

	private function tmp_with( string $bytes ): string {
		$p = $this->sandbox . '/up-' . uniqid();
		file_put_contents( $p, $bytes );
		return $p;
	}

	private function input( string $bytes, array $over = [] ): array {
		return array_merge(
			[
				'upload_id'    => self::ID,
				'chunk_index'  => 0,
				'total_chunks' => 2,
				'file_name'    => 'backup.zip',
				'mime_type'    => 'application/zip',
				'destination'  => 'import',
				'chunk_sha256' => hash( 'sha256', $bytes ),
				'total_sha256' => str_repeat( 'a', 64 ),
				'file_size'    => 8,
			],
			$over
		);
	}

	public function test_happy_path_stores_chunk_and_reports_progress(): void {
		$res = $this->rx->receive( $this->input( 'hello---' ), $this->tmp_with( 'hello---' ) );

		self::assertTrue( $res['ok'] );
		self::assertSame( 200, $res['status'] );
		self::assertSame( 0, $res['data']['received'] );
		self::assertSame( 1, $res['data']['remaining'] );
		self::assertFalse( $res['data']['complete'] );
	}

	public function test_rejects_invalid_upload_id(): void {
		$res = $this->rx->receive( $this->input( 'x', [ 'upload_id' => '../evil' ] ), $this->tmp_with( 'x' ) );
		self::assertFalse( $res['ok'] );
		self::assertSame( 400, $res['status'] );
		self::assertSame( 'invalid_upload_id', $res['error'] );
	}

	public function test_rejects_unknown_destination(): void {
		$res = $this->rx->receive( $this->input( 'x', [ 'destination' => 'somewhere' ] ), $this->tmp_with( 'x' ) );
		self::assertSame( 'invalid_destination', $res['error'] );
	}

	public function test_rejects_chunk_index_out_of_range(): void {
		$res = $this->rx->receive( $this->input( 'x', [ 'chunk_index' => 5, 'total_chunks' => 2 ] ), $this->tmp_with( 'x' ) );
		self::assertSame( 'chunk_index_out_of_range', $res['error'] );
	}

	public function test_rejects_corrupted_chunk_with_422(): void {
		// Declared hash is for 'hello---' but the body is different.
		$res = $this->rx->receive( $this->input( 'hello---' ), $this->tmp_with( 'TAMPERED' ) );
		self::assertFalse( $res['ok'] );
		self::assertSame( 422, $res['status'] );
		self::assertSame( 'chunk_integrity_mismatch', $res['error'] );
	}

	public function test_corrupted_chunk_does_not_create_session(): void {
		$this->rx->receive( $this->input( 'hello---' ), $this->tmp_with( 'TAMPERED' ) );
		$s = new UploadSession( new Paths( $this->sandbox ), self::ID );
		self::assertFalse( $s->exists() );
	}

	public function test_type_gate_rejection_returns_415(): void {
		$rx  = new ChunkReceiver( new Paths( $this->sandbox ), fn() => false );
		$res = $rx->receive( $this->input( 'x' ), $this->tmp_with( 'x' ) );
		self::assertSame( 415, $res['status'] );
		self::assertSame( 'disallowed_type', $res['error'] );
	}

	public function test_conflicting_total_chunks_rejected_after_session_established(): void {
		$this->rx->receive( $this->input( 'aaa', [ 'chunk_index' => 0, 'total_chunks' => 2 ] ), $this->tmp_with( 'aaa' ) );
		// Second chunk claims a different total for the same id.
		$res = $this->rx->receive(
			$this->input( 'bbb', [ 'chunk_index' => 1, 'total_chunks' => 9 ] ),
			$this->tmp_with( 'bbb' )
		);
		self::assertFalse( $res['ok'] );
		self::assertSame( 409, $res['status'] );
		self::assertSame( 'session_conflict', $res['error'] );
	}

	public function test_retried_chunk_is_idempotent(): void {
		$this->rx->receive( $this->input( 'dup' ), $this->tmp_with( 'dup' ) );
		$res = $this->rx->receive( $this->input( 'dup' ), $this->tmp_with( 'dup' ) );
		self::assertTrue( $res['ok'] );
		$s = new UploadSession( new Paths( $this->sandbox ), self::ID );
		self::assertSame( [ 0 ], $s->received_indices() );
	}

	public function test_completes_when_all_chunks_received(): void {
		$this->rx->receive( $this->input( 'aaa', [ 'chunk_index' => 0 ] ), $this->tmp_with( 'aaa' ) );
		$res = $this->rx->receive( $this->input( 'bbb', [ 'chunk_index' => 1 ] ), $this->tmp_with( 'bbb' ) );
		self::assertTrue( $res['data']['complete'] );
		self::assertSame( 0, $res['data']['remaining'] );
	}

	// --- SEC-9: quota enforced against ACTUAL bytes (spoofed fileSize bypass) ---

	public function test_quota_counts_actual_bytes_and_ignores_declared_filesize(): void {
		// Quota of 10 bytes. The client "declares" fileSize 0 (the classic spoof
		// that used to grant a free pass) but the real body is 8 bytes, which is
		// what must be counted. The session's persisted size and the per-user
		// tally both reflect the ACTUAL 8 bytes, not the declared 0.
		$rx  = new ChunkReceiver( new Paths( $this->sandbox ), fn() => true, 10 );
		$res = $rx->receive(
			$this->input( 'hello---', [ 'owner_id' => 1, 'file_size' => 0 ] ),
			$this->tmp_with( 'hello---' )
		);

		self::assertTrue( $res['ok'] );
		$s = new UploadSession( new Paths( $this->sandbox ), self::ID );
		self::assertSame( 8, (int) $s->meta()['file_size'] );
		self::assertSame( 8, (int) get_option( 'cf_cu_quota_1', 0 ) );
	}

	public function test_quota_rejects_when_actual_bytes_exceed_limit(): void {
		// First 8-byte chunk fits a 10-byte quota; the second pushes the ACTUAL
		// total to 16 > 10 and is rejected with 507 even though fileSize is
		// understated. The rejected chunk is never written, and the tally only
		// ever counts what actually landed on disk.
		$rx = new ChunkReceiver( new Paths( $this->sandbox ), fn() => true, 10 );

		$ok = $rx->receive(
			$this->input( 'aaaaaaaa', [ 'owner_id' => 1, 'chunk_index' => 0, 'file_size' => 0 ] ),
			$this->tmp_with( 'aaaaaaaa' )
		);
		self::assertTrue( $ok['ok'] );

		$res = $rx->receive(
			$this->input( 'bbbbbbbb', [ 'owner_id' => 1, 'chunk_index' => 1, 'file_size' => 0 ] ),
			$this->tmp_with( 'bbbbbbbb' )
		);
		self::assertFalse( $res['ok'] );
		self::assertSame( 507, $res['status'] );
		self::assertSame( 'quota_exceeded', $res['error'] );

		$s = new UploadSession( new Paths( $this->sandbox ), self::ID );
		self::assertSame( [ 0 ], $s->received_indices() );
		self::assertSame( 8, (int) get_option( 'cf_cu_quota_1', 0 ) );
	}

	public function test_idempotent_retry_does_not_double_count_quota(): void {
		// Re-sending the same index nets zero new bytes, so the tally must not
		// grow on the retry.
		$rx = new ChunkReceiver( new Paths( $this->sandbox ), fn() => true, 100 );
		$rx->receive( $this->input( 'aaaaaaaa', [ 'owner_id' => 1 ] ), $this->tmp_with( 'aaaaaaaa' ) );
		$rx->receive( $this->input( 'aaaaaaaa', [ 'owner_id' => 1 ] ), $this->tmp_with( 'aaaaaaaa' ) );

		self::assertSame( 8, (int) get_option( 'cf_cu_quota_1', 0 ) );
	}

	public function test_quota_disabled_when_limit_is_zero(): void {
		// Default constructor (quota 0 = unlimited): large bytes, no owner tally.
		$res = $this->rx->receive( $this->input( 'hello---', [ 'owner_id' => 1 ] ), $this->tmp_with( 'hello---' ) );
		self::assertTrue( $res['ok'] );
		self::assertSame( 0, (int) get_option( 'cf_cu_quota_1', 0 ) );
	}

	// --- SEC-9: disk-exhaustion guard ---

	public function test_disk_guard_rejects_when_free_space_below_minimum(): void {
		// Injected probe reports 5 bytes free; with an 8-byte chunk and a
		// 1000-byte minimum, the receive is refused with 507 and nothing is
		// written to disk.
		$probe = static fn( string $dir ) => 5;
		$rx    = new ChunkReceiver( new Paths( $this->sandbox ), fn() => true, 0, 0, 1000, $probe );

		$res = $rx->receive( $this->input( 'hello---' ), $this->tmp_with( 'hello---' ) );
		self::assertFalse( $res['ok'] );
		self::assertSame( 507, $res['status'] );
		self::assertSame( 'insufficient_disk', $res['error'] );

		$s = new UploadSession( new Paths( $this->sandbox ), self::ID );
		self::assertSame( [], $s->received_indices() );
	}

	public function test_disk_guard_allows_when_space_is_ample(): void {
		$probe = static fn( string $dir ) => 1000000000;
		$rx    = new ChunkReceiver( new Paths( $this->sandbox ), fn() => true, 0, 0, 1000, $probe );

		$res = $rx->receive( $this->input( 'hello---' ), $this->tmp_with( 'hello---' ) );
		self::assertTrue( $res['ok'] );
	}

	// --- SEC-9: per-session absolute ceiling ---

	public function test_session_ceiling_rejects_oversized_upload(): void {
		// Ceiling of 5 bytes; an 8-byte chunk exceeds it and is refused with 413
		// independently of any quota (quota disabled here).
		$rx  = new ChunkReceiver( new Paths( $this->sandbox ), fn() => true, 0, 5 );
		$res = $rx->receive( $this->input( 'hello---' ), $this->tmp_with( 'hello---' ) );

		self::assertFalse( $res['ok'] );
		self::assertSame( 413, $res['status'] );
		self::assertSame( 'session_too_large', $res['error'] );

		$s = new UploadSession( new Paths( $this->sandbox ), self::ID );
		self::assertSame( [], $s->received_indices() );
	}

	public function test_session_ceiling_allows_upload_within_limit(): void {
		$rx  = new ChunkReceiver( new Paths( $this->sandbox ), fn() => true, 0, 1000 );
		$res = $rx->receive( $this->input( 'hello---' ), $this->tmp_with( 'hello---' ) );
		self::assertTrue( $res['ok'] );
	}
}
