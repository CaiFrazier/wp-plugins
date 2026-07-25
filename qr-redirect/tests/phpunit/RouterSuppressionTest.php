<?php

namespace CFQR\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers CFQR_Router's two-stage hit suppression: per-fingerprint dedupe and
 * per-slug rate limiting.
 *
 * This logic had no test coverage at all despite being described as a verified
 * write-throttle. It is worth pinning precisely because its failure mode is
 * silent: a regression does not break redirects, it just quietly restores one
 * DB write per scan on a shared host, which nobody notices until the database
 * is under load.
 *
 * The methods are private by design (the router's public surface is a single
 * template_redirect hook), so they are reached by reflection — the same
 * approach tests/test-redirect.php already uses for the pattern matchers.
 *
 * Note both methods are tested through their *write behaviour*, not just their
 * return value. "Did it write again?" is the actual contract; the return value
 * alone cannot distinguish a correct implementation from one that refreshes a
 * TTL on every request.
 */
final class RouterSuppressionTest extends TestCase {

	protected function setUp(): void {
		cfqr_test_reset_state();
	}

	/**
	 * No setAccessible() call: it has been a no-op since PHP 8.1 and is
	 * deprecated as of 8.5, which this suite's failOnWarning would surface as
	 * noise on the CI matrix. The plugin requires 8.1, so it is safe to omit.
	 */
	private static function invoke( string $method, array $args = [] ) {
		return ( new ReflectionMethod( 'CFQR_Router', $method ) )->invokeArgs( null, $args );
	}

	private static function code( string $slug = 'spring-sale' ): object {
		return (object) array( 'ID' => 42, 'post_name' => $slug, 'post_status' => 'publish' );
	}

	private static function writes(): array {
		return $GLOBALS['cfqr_test_transient_writes'];
	}

	// -----------------------------------------------------------------------
	// is_duplicate_scan
	// -----------------------------------------------------------------------

	public function test_first_scan_is_not_a_duplicate_and_is_recorded(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

		self::assertFalse( self::invoke( 'is_duplicate_scan', [ self::code() ] ) );
		self::assertCount( 1, self::writes(), 'First scan should write the fingerprint once.' );
	}

	public function test_repeat_scan_is_a_duplicate(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

		self::invoke( 'is_duplicate_scan', [ self::code() ] );
		self::assertTrue( self::invoke( 'is_duplicate_scan', [ self::code() ] ) );
	}

	/**
	 * The documented reason this logic exists: refreshing the TTL on every
	 * duplicate produced one transient write per request even for bots, which
	 * defeated the point. A regression here is invisible except under load.
	 */
	public function test_duplicate_scans_never_refresh_the_transient(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';

		for ( $i = 0; $i < 25; $i++ ) {
			self::invoke( 'is_duplicate_scan', [ self::code() ] );
		}

		self::assertCount(
			1,
			self::writes(),
			'25 scans from one fingerprint must produce exactly one transient write.'
		);
	}

	public function test_scan_with_neither_ip_nor_user_agent_is_counted(): void {
		self::assertFalse( self::invoke( 'is_duplicate_scan', [ self::code() ] ) );
		self::assertSame( [], self::writes(), 'With no fingerprint there is nothing to store.' );
	}

	public function test_a_different_user_agent_is_a_different_fingerprint(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		self::invoke( 'is_duplicate_scan', [ self::code() ] );

		$_SERVER['HTTP_USER_AGENT'] = 'CustomScanner/2.0';
		self::assertFalse( self::invoke( 'is_duplicate_scan', [ self::code() ] ) );
	}

	public function test_a_different_ip_is_a_different_fingerprint(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		self::invoke( 'is_duplicate_scan', [ self::code() ] );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.4';
		self::assertFalse( self::invoke( 'is_duplicate_scan', [ self::code() ] ) );
	}

	/**
	 * Two codes scanned by the same person must count separately, or a
	 * multi-code mailer would under-report every code after the first.
	 */
	public function test_a_different_slug_is_a_different_fingerprint(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		self::invoke( 'is_duplicate_scan', [ self::code( 'spring-sale' ) ] );

		self::assertFalse( self::invoke( 'is_duplicate_scan', [ self::code( 'fall-sale' ) ] ) );
	}

	/**
	 * The fingerprint is HMAC'd with a salt specifically so leaked transient
	 * keys are not brute-forceable: IPv4 + a common UA + a known slug is a
	 * small search space for a plain digest.
	 */
	public function test_fingerprint_is_salted_not_a_plain_digest(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
		self::invoke( 'is_duplicate_scan', [ self::code( 'spring-sale' ) ] );

		$raw       = '203.0.113.7|Mozilla/5.0|spring-sale';
		$unsalted  = 'cfqr_seen_' . substr( hash( 'sha256', $raw ), 0, 32 );
		$writtenAt = self::writes()[0]['key'];

		self::assertNotSame( $unsalted, $writtenAt, 'Fingerprint must not be an unsalted sha256.' );
		self::assertStringStartsWith( 'cfqr_seen_', $writtenAt );
	}

	// -----------------------------------------------------------------------
	// is_rate_limited
	// -----------------------------------------------------------------------

	public function test_scans_under_the_limit_are_not_rate_limited(): void {
		for ( $i = 0; $i < \CFQR_Router::RATE_LIMIT_WRITES_PER_SEC; $i++ ) {
			self::assertFalse(
				self::invoke( 'is_rate_limited', [ 'spring-sale' ] ),
				"Scan {$i} is within the per-second allowance."
			);
		}
	}

	public function test_the_limit_engages_once_the_allowance_is_spent(): void {
		for ( $i = 0; $i < \CFQR_Router::RATE_LIMIT_WRITES_PER_SEC; $i++ ) {
			self::invoke( 'is_rate_limited', [ 'spring-sale' ] );
		}
		self::assertTrue( self::invoke( 'is_rate_limited', [ 'spring-sale' ] ) );
	}

	/**
	 * Once limited, the counter must stop being written too — otherwise a
	 * runaway scanner still generates a write per request and the limit only
	 * moves the cost rather than removing it.
	 */
	public function test_a_limited_slug_stops_writing_the_counter(): void {
		for ( $i = 0; $i < \CFQR_Router::RATE_LIMIT_WRITES_PER_SEC; $i++ ) {
			self::invoke( 'is_rate_limited', [ 'spring-sale' ] );
		}
		$writesWhenSaturated = count( self::writes() );

		for ( $i = 0; $i < 50; $i++ ) {
			self::invoke( 'is_rate_limited', [ 'spring-sale' ] );
		}

		self::assertCount(
			$writesWhenSaturated,
			self::writes(),
			'A saturated slug must not keep writing its counter.'
		);
	}

	public function test_slugs_are_limited_independently(): void {
		for ( $i = 0; $i < \CFQR_Router::RATE_LIMIT_WRITES_PER_SEC; $i++ ) {
			self::invoke( 'is_rate_limited', [ 'spring-sale' ] );
		}

		self::assertTrue( self::invoke( 'is_rate_limited', [ 'spring-sale' ] ) );
		self::assertFalse(
			self::invoke( 'is_rate_limited', [ 'fall-sale' ] ),
			'One saturated code must not suppress counts for every other code.'
		);
	}

	public function test_empty_slug_is_never_rate_limited(): void {
		self::assertFalse( self::invoke( 'is_rate_limited', [ '' ] ) );
		self::assertSame( [], self::writes() );
	}

	/**
	 * The counter is deliberately a 1-second window, which is what makes the
	 * limit self-resetting rather than a lockout.
	 */
	public function test_rate_counter_uses_a_one_second_ttl(): void {
		self::invoke( 'is_rate_limited', [ 'spring-sale' ] );
		self::assertSame( 1, self::writes()[0]['ttl'] );
	}
}
