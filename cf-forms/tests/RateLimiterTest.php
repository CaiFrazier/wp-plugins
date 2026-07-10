<?php
namespace CFForms\Tests;

use CFForms\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		cff_test_reset_state();
	}

	public function test_allows_submissions_under_the_cap(): void {
		$limiter = new RateLimiter( 3, 3600 );

		$this->assertFalse( $limiter->is_exceeded( '1.2.3.4' ) );
		$limiter->record( '1.2.3.4' );
		$this->assertFalse( $limiter->is_exceeded( '1.2.3.4' ) );
	}

	public function test_blocks_once_the_cap_is_reached(): void {
		$limiter = new RateLimiter( 2, 3600 );

		$limiter->record( '1.2.3.4' );
		$limiter->record( '1.2.3.4' );

		$this->assertTrue( $limiter->is_exceeded( '1.2.3.4' ) );
	}

	public function test_tracks_ips_independently(): void {
		$limiter = new RateLimiter( 1, 3600 );

		$limiter->record( '1.2.3.4' );

		$this->assertTrue( $limiter->is_exceeded( '1.2.3.4' ) );
		$this->assertFalse( $limiter->is_exceeded( '5.6.7.8' ) );
	}
}
