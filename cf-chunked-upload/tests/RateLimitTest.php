<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\RateLimit;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase {

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
	}

	public function test_unlimited_when_per_minute_is_zero(): void {
		for ( $i = 0; $i < 1000; $i++ ) {
			self::assertTrue( RateLimit::check_chunk( 1, 0 ) );
		}
	}

	public function test_unlimited_for_user_id_zero(): void {
		for ( $i = 0; $i < 1000; $i++ ) {
			self::assertTrue( RateLimit::check_chunk( 0, 60 ) );
		}
	}

	public function test_first_burst_is_allowed(): void {
		$capacity = max( 6, (int) floor( 60 / 5 ) ); // = 12
		for ( $i = 0; $i < $capacity; $i++ ) {
			self::assertTrue( RateLimit::check_chunk( 42, 60 ), "Burst chunk $i should be allowed" );
		}
	}

	public function test_request_beyond_burst_is_rejected(): void {
		$capacity = max( 6, (int) floor( 60 / 5 ) ); // = 12
		for ( $i = 0; $i < $capacity; $i++ ) {
			RateLimit::check_chunk( 42, 60 );
		}
		self::assertFalse( RateLimit::check_chunk( 42, 60 ) );
	}

	public function test_different_users_have_independent_buckets(): void {
		$capacity = max( 6, (int) floor( 60 / 5 ) );
		for ( $i = 0; $i < $capacity; $i++ ) {
			RateLimit::check_chunk( 1, 60 ); // exhaust user 1
		}
		// User 2 still has a full bucket.
		self::assertTrue( RateLimit::check_chunk( 2, 60 ) );
	}

	public function test_minimum_burst_is_six(): void {
		// per_minute = 10 → floor(10/5) = 2, but minimum is 6
		for ( $i = 0; $i < 6; $i++ ) {
			self::assertTrue( RateLimit::check_chunk( 99, 10 ) );
		}
		self::assertFalse( RateLimit::check_chunk( 99, 10 ) );
	}
}
