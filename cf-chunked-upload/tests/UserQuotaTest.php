<?php

namespace CFChunkedUpload\Tests;

use CFChunkedUpload\UserQuota;
use PHPUnit\Framework\TestCase;

final class UserQuotaTest extends TestCase {

	protected function setUp(): void {
		cf_chunked_upload_test_reset_state();
	}

	public function test_unlimited_when_limit_is_zero(): void {
		self::assertTrue( UserQuota::check_and_reserve( 1, PHP_INT_MAX, 0 ) );
	}

	public function test_unlimited_for_user_id_zero(): void {
		self::assertTrue( UserQuota::check_and_reserve( 0, PHP_INT_MAX, 1024 ) );
	}

	public function test_reserve_within_limit_is_allowed(): void {
		$limit = 100 * 1073741824; // 100 GB
		self::assertTrue( UserQuota::check_and_reserve( 1, 50 * 1073741824, $limit ) );
	}

	public function test_reserve_exceeding_limit_is_rejected(): void {
		$limit = 10 * 1073741824; // 10 GB
		self::assertFalse( UserQuota::check_and_reserve( 1, 11 * 1073741824, $limit ) );
	}

	public function test_cumulative_reserves_sum_correctly(): void {
		$limit = 10 * 1073741824;
		$chunk = 4 * 1073741824;
		self::assertTrue( UserQuota::check_and_reserve( 5, $chunk, $limit ) ); // 4 GB used
		self::assertTrue( UserQuota::check_and_reserve( 5, $chunk, $limit ) ); // 8 GB used
		self::assertFalse( UserQuota::check_and_reserve( 5, $chunk, $limit ) ); // 12 GB > limit
	}

	public function test_release_frees_reserved_space(): void {
		$limit = 10 * 1073741824;
		$chunk = 8 * 1073741824;
		UserQuota::check_and_reserve( 7, $chunk, $limit ); // 8 GB used
		UserQuota::release( 7, $chunk );                    // back to 0
		self::assertTrue( UserQuota::check_and_reserve( 7, $chunk, $limit ) ); // should pass again
	}

	public function test_release_does_not_go_negative(): void {
		UserQuota::release( 3, 1073741824 ); // release without prior reserve
		$key     = 'cf_cu_quota_3';
		$current = (int) get_option( $key, 0 );
		self::assertGreaterThanOrEqual( 0, $current );
	}

	public function test_different_users_have_independent_quotas(): void {
		$limit = 5 * 1073741824;
		$full  = 5 * 1073741824;
		UserQuota::check_and_reserve( 10, $full, $limit ); // user 10 at limit
		self::assertTrue( UserQuota::check_and_reserve( 11, $full, $limit ) ); // user 11 unaffected
	}
}
