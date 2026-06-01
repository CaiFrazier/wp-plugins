<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\Integrity;
use PHPUnit\Framework\TestCase;

final class IntegrityTest extends TestCase {

	private string $sandbox;

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
		$this->sandbox = sys_get_temp_dir() . '/cf-cu-int-' . uniqid();
		mkdir( $this->sandbox, 0777, true );
	}

	protected function tearDown(): void {
		\CFChunkedUpload\UploadSession::rrmdir( $this->sandbox );
	}

	public function test_hash_bytes_matches_native(): void {
		self::assertSame( hash( 'sha256', 'hello' ), Integrity::hash_bytes( 'hello' ) );
	}

	public function test_hash_file_matches_native_for_large_multiblock_file(): void {
		$path = $this->sandbox . '/big.bin';
		// 2.5 MB exercises the multi-block read loop (1 MB blocks).
		$data = random_bytes( 2_500_000 );
		file_put_contents( $path, $data );

		self::assertSame( hash( 'sha256', $data ), Integrity::hash_file( $path ) );
	}

	public function test_hash_file_returns_null_for_missing_file(): void {
		self::assertNull( Integrity::hash_file( $this->sandbox . '/nope.bin' ) );
	}

	public function test_digests_match_is_case_insensitive(): void {
		$lower = hash( 'sha256', 'x' );
		self::assertTrue( Integrity::digests_match( $lower, strtoupper( $lower ) ) );
	}

	public function test_digests_match_rejects_different_digests(): void {
		self::assertFalse(
			Integrity::digests_match( hash( 'sha256', 'a' ), hash( 'sha256', 'b' ) )
		);
	}

	public function test_is_sha256_hex_validates_format(): void {
		self::assertTrue( Integrity::is_sha256_hex( str_repeat( 'a', 64 ) ) );
		self::assertFalse( Integrity::is_sha256_hex( str_repeat( 'a', 63 ) ) );
		self::assertFalse( Integrity::is_sha256_hex( str_repeat( 'g', 64 ) ) );
		self::assertFalse( Integrity::is_sha256_hex( '' ) );
	}
}
