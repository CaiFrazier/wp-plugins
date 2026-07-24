<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Token-bucket rate limiter for the /chunk endpoint.
 *
 * One bucket per user, backed by WP transients. Transient eviction resets the
 * bucket to capacity — this is acceptable: a reset is temporarily MORE
 * permissive, not less. Persistent object cache (Redis/Memcached) makes each
 * check a memory read/write; DB-backed transients are still fast enough for a
 * per-chunk gate.
 *
 * Burst capacity: 20 % of the per-minute limit, minimum 6. A user can send
 * that many chunks back-to-back, then is throttled to the configured rate
 * until the bucket refills.
 *
 * Setting chunks_per_minute = 0 disables rate limiting entirely (trusted
 * setups, single-user installs).
 */
final class RateLimit {

	const TRANSIENT_PREFIX = 'cf_cu_rate_';

	/**
	 * Transient TTL. Long enough that a user sending chunks at the configured
	 * rate never sees their bucket vanish mid-upload; short enough that
	 * abandoned buckets are reclaimed automatically.
	 */
	const TRANSIENT_TTL = 300; // 5 minutes

	/**
	 * Check the user's token bucket and consume one token if available.
	 * Returns true when the request is allowed, false when rate-limited (429).
	 *
	 * @param int $user_id    WP user id. 0 = unauthenticated; always allowed —
	 *                        the route's capability gate handles auth, not us.
	 * @param int $per_minute Chunks per minute. 0 = unlimited.
	 * @return bool
	 */
	public static function check_chunk( int $user_id, int $per_minute ): bool {
		if ( $per_minute <= 0 || $user_id <= 0 ) {
			return true;
		}

		$capacity = max( 6, (int) floor( $per_minute / 5 ) );
		$key      = self::TRANSIENT_PREFIX . $user_id;
		$now      = microtime( true );

		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['tokens'], $state['last'] ) ) {
			// First request: start at capacity, immediately consume one token.
			set_transient(
				$key,
				[
					'tokens' => (float) ( $capacity - 1 ),
					'last'   => $now,
				],
				self::TRANSIENT_TTL
			);
			return true;
		}

		// Refill proportionally to elapsed time, capped at capacity.
		$elapsed = max( 0.0, $now - (float) $state['last'] );
		$refill  = $elapsed * ( $per_minute / 60.0 );
		$tokens  = min( (float) $capacity, (float) $state['tokens'] + $refill );

		if ( $tokens < 1.0 ) {
			// Advance last-seen even on rejection so a silent gap after
			// throttling doesn't grant a disproportionate refill burst.
			set_transient(
				$key,
				[
					'tokens' => $tokens,
					'last'   => $now,
				],
				self::TRANSIENT_TTL
			);
			return false;
		}

		set_transient(
			$key,
			[
				'tokens' => $tokens - 1.0,
				'last'   => $now,
			],
			self::TRANSIENT_TTL
		);
		return true;
	}
}
