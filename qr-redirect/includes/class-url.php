<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL validation helpers shared across the plugin.
 *
 * Centralises destination-safety checks so the router, meta-box save handler,
 * and Quick Edit save handler all agree on what constitutes a "valid"
 * destination. Validation rejects credentials in userinfo, localhost, private
 * and reserved IP literals, URLs longer than 2 048 characters, and any scheme
 * other than http/https. Every write path (save_meta, save_quick_edit) and
 * the router use this single predicate, so a destination that is accepted at
 * save time is guaranteed to be accepted at scan time.
 */
class CFQR_URL {

	/**
	 * Validate a destination URL before storing or redirecting to it.
	 *
	 * Rejects: empty, >2048 chars, non-http(s) schemes, userinfo credentials,
	 * missing host, localhost, and private/reserved IP literals. Does NOT
	 * perform an allowlist check — add one at the call site if the domain must
	 * be restricted.
	 *
	 * Not an SSRF guard: the plugin never fetches destination URLs server-side,
	 * but tightening here prevents localhost/intranet redirects and makes the
	 * validator reusable if server-side features are ever added.
	 *
	 * @param string $url Raw URL string.
	 * @return bool True if the URL is a safe redirect target.
	 */
	public static function is_safe_destination( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url || strlen( $url ) > 2048 ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		// Reject embedded credentials (https://user:pass@host — phishing vector).
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );

		if ( 'localhost' === $host ) {
			return false;
		}

		// Reject raw IP literals that resolve to private or reserved ranges.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$public = filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if ( ! $public ) {
				return false;
			}
		}

		return true;
	}
}
