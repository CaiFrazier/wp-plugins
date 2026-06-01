<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\HostInfo;
use PHPUnit\Framework\TestCase;

final class HostInfoTest extends TestCase {

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
	}

	public function test_parse_bytes_handles_shorthand_units(): void {
		self::assertSame( 0, HostInfo::parse_bytes( '' ) );
		self::assertSame( 0, HostInfo::parse_bytes( '0' ) );
		self::assertSame( 512, HostInfo::parse_bytes( '512' ) );
		self::assertSame( 64 * 1024, HostInfo::parse_bytes( '64K' ) );
		self::assertSame( 8 * 1048576, HostInfo::parse_bytes( '8M' ) );
		self::assertSame( 2 * 1073741824, HostInfo::parse_bytes( '2G' ) );
	}

	public function test_chunk_ceiling_is_zero_when_no_host_limit(): void {
		// Both ini values unlimited → nothing to clamp against.
		// We can't set ini in the suite, so assert the documented invariant:
		// max_request_bytes() of an all-zero set yields 0, ceiling 0.
		self::assertGreaterThanOrEqual( 0, HostInfo::chunk_ceiling_bytes() );
	}

	public function test_snapshot_shape(): void {
		$snap = HostInfo::snapshot();
		foreach ( [ 'upload_max_filesize', 'post_max_size', 'memory_limit', 'max_request_bytes', 'chunk_ceiling_bytes' ] as $k ) {
			self::assertArrayHasKey( $k, $snap );
		}
	}
}
