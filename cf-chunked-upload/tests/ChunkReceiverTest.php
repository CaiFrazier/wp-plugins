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
}
