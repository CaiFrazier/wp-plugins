<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\Assembler;
use CFChunkedUpload\Paths;
use CFChunkedUpload\UploadSession;
use PHPUnit\Framework\TestCase;

final class AssemblerTest extends TestCase {

	private string $sandbox;
	private Paths $paths;
	private const ID = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
		$this->sandbox = sys_get_temp_dir() . '/cf-cu-asm-' . uniqid();
		mkdir( $this->sandbox, 0777, true );
		$this->paths = new Paths( $this->sandbox );
	}

	protected function tearDown(): void {
		UploadSession::rrmdir( $this->sandbox );
	}

	private function seed( array $parts ): UploadSession {
		$s = new UploadSession( $this->paths, self::ID );
		$s->ensure_meta(
			[
				'file_name'    => 'f.bin',
				'mime_type'    => 'application/octet-stream',
				'destination'  => 'import',
				'total_chunks' => count( $parts ),
			]
		);
		foreach ( $parts as $i => $bytes ) {
			$tmp = $this->sandbox . "/seed$i";
			file_put_contents( $tmp, $bytes );
			$s->store_chunk( $i, $tmp );
		}
		return $s;
	}

	public function test_assembles_in_order_and_hashes_whole_file(): void {
		$parts = [ 'AAAA', 'BBBB', 'CCCC' ];
		$s     = $this->seed( $parts );
		$dest  = $this->sandbox . '/out.bin';

		$res = Assembler::assemble( $s, $dest );

		self::assertTrue( $res['ok'] );
		self::assertSame( 'AAAABBBBCCCC', file_get_contents( $dest ) );
		self::assertSame( hash( 'sha256', 'AAAABBBBCCCC' ), $res['sha256'] );
		self::assertSame( 12, $res['size'] );
	}

	public function test_assembles_large_multiblock_payload(): void {
		$a   = random_bytes( 1_500_000 );
		$b   = random_bytes( 1_200_000 );
		$s   = $this->seed( [ $a, $b ] );
		$dest = $this->sandbox . '/big.bin';

		$res = Assembler::assemble( $s, $dest );

		self::assertTrue( $res['ok'] );
		self::assertSame( hash( 'sha256', $a . $b ), $res['sha256'] );
		self::assertSame( strlen( $a . $b ), $res['size'] );
	}

	public function test_parts_are_unlinked_after_assembly(): void {
		$s    = $this->seed( [ 'x', 'y' ] );
		$dest = $this->sandbox . '/o.bin';
		Assembler::assemble( $s, $dest );

		self::assertFileDoesNotExist( $s->chunk_path( 0 ) );
		self::assertFileDoesNotExist( $s->chunk_path( 1 ) );
	}

	public function test_fails_when_a_chunk_is_missing(): void {
		$s = new UploadSession( $this->paths, self::ID );
		$s->ensure_meta(
			[
				'file_name'    => 'f.bin',
				'mime_type'    => 'application/octet-stream',
				'destination'  => 'import',
				'total_chunks' => 3,
			]
		);
		$tmp = $this->sandbox . '/only0';
		file_put_contents( $tmp, 'zero' );
		$s->store_chunk( 0, $tmp );

		$res = Assembler::assemble( $s, $this->sandbox . '/out.bin' );

		self::assertFalse( $res['ok'] );
		self::assertSame( 'incomplete', $res['error'] );
		self::assertFileDoesNotExist( $this->sandbox . '/out.bin' );
	}

	public function test_progress_callback_fires_once_per_chunk_in_order(): void {
		$s    = $this->seed( [ 'a', 'b', 'c', 'd' ] );
		$dest = $this->sandbox . '/p.bin';
		$seen = [];

		Assembler::assemble( $s, $dest, function ( $done, $total ) use ( &$seen ) {
			$seen[] = [ $done, $total ];
		} );

		self::assertSame(
			[ [ 1, 4 ], [ 2, 4 ], [ 3, 4 ], [ 4, 4 ] ],
			$seen
		);
	}

	public function test_rejects_zero_byte_part_in_multi_chunk_upload(): void {
		$s = new UploadSession( $this->paths, self::ID );
		$s->ensure_meta(
			[
				'file_name'    => 'f.bin',
				'mime_type'    => 'application/octet-stream',
				'destination'  => 'import',
				'total_chunks' => 2,
			]
		);
		// Index 0 legitimately written, index 1 is a 0-byte (truncated) part.
		$t0 = $this->sandbox . '/z0';
		file_put_contents( $t0, 'data' );
		$s->store_chunk( 0, $t0 );
		file_put_contents( $s->chunk_path( 1 ), '' );

		$res = Assembler::assemble( $s, $this->sandbox . '/o.bin' );

		self::assertFalse( $res['ok'] );
		self::assertSame( 'empty_part', $res['error'] );
		self::assertFileDoesNotExist( $this->sandbox . '/o.bin' );
	}

	public function test_single_zero_byte_chunk_is_a_valid_empty_file(): void {
		$s = new UploadSession( $this->paths, self::ID );
		$s->ensure_meta(
			[
				'file_name'    => 'empty.bin',
				'mime_type'    => 'application/octet-stream',
				'destination'  => 'import',
				'total_chunks' => 1,
			]
		);
		file_put_contents( $s->chunk_path( 0 ), '' );

		$res = Assembler::assemble( $s, $this->sandbox . '/empty.bin' );

		self::assertTrue( $res['ok'] );
		self::assertSame( 0, $res['size'] );
		self::assertSame( hash( 'sha256', '' ), $res['sha256'] );
	}

	public function test_fails_when_no_metadata(): void {
		$s = new UploadSession( $this->paths, self::ID );
		// No ensure_meta() call — total_chunks resolves to 0.
		$res = Assembler::assemble( $s, $this->sandbox . '/out.bin' );
		self::assertFalse( $res['ok'] );
		self::assertSame( 'no_metadata', $res['error'] );
	}
}
