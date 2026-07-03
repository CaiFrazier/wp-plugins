<?php

namespace CFChunkedUpload;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user storage quota for active upload sessions.
 *
 * Tracks the ACTUAL bytes on disk across all in-flight sessions for a given
 * user. ChunkReceiver reserves the new bytes each chunk adds (the delta since
 * the session was last accounted) as the chunk is received; the reservation is
 * released when a session is deleted by any path (finalize, cancel, cleanup).
 * The client-declared fileSize is never trusted for accounting; a spoofed or
 * zero fileSize cannot understate a user's real footprint (SEC-9).
 *
 * The accounting is best-effort. Concurrent chunk requests from the same user
 * can race the check-then-increment and briefly allow slightly over-quota
 * uploads (the worst-case over-run is bounded by the user's concurrency). This
 * is an accepted trade-off — truly atomic quotas require database-level locking
 * that is disproportionate for this use case.
 *
 * A limit_bytes of 0 disables enforcement (unlimited). user_id of 0 (unknown
 * or unauthenticated) is always allowed — the route's capability gate handles
 * auth. A file_bytes of 0 or less reserves nothing (no new bytes this chunk).
 */
final class UserQuota {

	const OPTION_PREFIX = 'cf_cu_quota_';

	/**
	 * Check whether the user has room for $file_bytes more and reserve them.
	 * Returns false (→ HTTP 507) when the limit would be exceeded. Called per
	 * chunk with the delta of ACTUAL bytes that chunk adds; a delta of 0 or less
	 * (an idempotent retry, no new bytes) reserves nothing and is always allowed.
	 *
	 * @param int $user_id    WP user id.
	 * @param int $file_bytes New bytes to reserve (the actual delta this chunk adds).
	 * @param int $limit      Ceiling in bytes. 0 = unlimited.
	 * @return bool True when space was reserved; false when over quota.
	 */
	public static function check_and_reserve( int $user_id, int $file_bytes, int $limit ): bool {
		if ( $limit <= 0 || $user_id <= 0 || $file_bytes <= 0 ) {
			return true;
		}

		$key     = self::OPTION_PREFIX . $user_id;
		$current = (int) get_option( $key, 0 );

		if ( $current + $file_bytes > $limit ) {
			return false;
		}

		update_option( $key, $current + $file_bytes, false );
		return true;
	}

	/**
	 * Release reserved bytes when a session is deleted. Safe to call with 0
	 * for either argument — no-ops cleanly. The counter is floored at 0 so
	 * any drift from a double-release cannot make it negative.
	 *
	 * @param int $user_id    WP user id.
	 * @param int $file_bytes Bytes to release (the session's reserved actual bytes).
	 * @return void
	 */
	public static function release( int $user_id, int $file_bytes ): void {
		if ( $user_id <= 0 || $file_bytes <= 0 ) {
			return;
		}
		$key     = self::OPTION_PREFIX . $user_id;
		$current = (int) get_option( $key, 0 );
		update_option( $key, max( 0, $current - $file_bytes ), false );
	}
}
