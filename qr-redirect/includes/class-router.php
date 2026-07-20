<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Routing layer: intercept /r/{slug} requests and redirect.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Router {

	// Window in seconds during which a repeat scan from the same fingerprint
	// (hashed IP + UA + slug) is treated as a duplicate and not counted.
	const DEDUPE_TTL = 30;

	// Hard cap on hit-count writes per slug per second. Protects against a
	// scanner that hammers /r/{slug} in a tight loop from generating one DB
	// write per request. Excess scans still redirect — only the counter
	// suppresses.
	const RATE_LIMIT_WRITES_PER_SEC = 10;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * Inspect the parsed query and act if this is a singular cfqr_code request.
	 */
	public static function maybe_redirect() {
		$post = self::resolve_requested_code();
		if ( ! $post ) {
			return;
		}

		// Only serve GET and HEAD. Scanners don't POST; anything else is a bot,
		// fuzzer, or mis-configured tool. Rejecting early also prevents non-GET
		// requests from incrementing hit counters.
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			status_header( 405 );
			header( 'Allow: GET, HEAD', true );
			exit;
		}

		// Draft / pending / private codes are deactivated; signal 410 Gone.
		if ( 'publish' !== $post->post_status ) {
			self::send_gone();
			exit;
		}

		$destination = (string) get_post_meta( $post->ID, CFQR_CPT::META_DESTINATION, true );
		$destination = trim( $destination );

		// Reject empty destinations and unsafe URI schemes.
		if ( ! self::is_safe_destination( $destination ) ) {
			self::send_gone();
			exit;
		}

		// Two-stage suppression of inflated counts:
		// 1. Per-fingerprint dedupe — if we already saw this IP+UA+slug in
		// the last DEDUPE_TTL seconds, treat it as the same scan. Filters
		// out double-tap scans, monitoring tools, and "did it work?"
		// reloads.
		// 2. Per-slug rate limit — if more than N scans hit the same slug in
		// the same second, only the first N produce DB writes. Protects
		// shared hosts from runaway scanners.
		// Excess scans still redirect; only the counter is suppressed.
		if ( ! self::is_duplicate_scan( $post ) && ! self::is_rate_limited( $post->post_name ) ) {
			self::record_hit( $post->ID );
		}

		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		nocache_headers();

		$mode = (string) get_post_meta( $post->ID, CFQR_CPT::META_ANALYTICS_MODE, true );
		if ( '' === $mode ) {
			$mode = (string) CFQR_Settings::get( 'default_analytics_mode' );
		}

		if ( 'intermediate' === $mode ) {
			CFQR_Analytics::render_intermediate( $destination, $post );
			// render_intermediate exits.
		}

		// Default: UTM mode.
		$target = CFQR_Analytics::build_utm_url( $destination, $post );
		$status = (int) CFQR_Settings::get( 'redirect_status' );
		if ( ! in_array( $status, array( 301, 302 ), true ) ) {
			$status = 302;
		}
		// External destinations are the product feature. wp_safe_redirect() refuses
		// cross-host targets and is not appropriate here. The destination is already
		// validated by CFQR_URL::is_safe_destination() above.
		wp_redirect( $target, $status ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Resolve the code requested through the plugin rewrite namespace.
	 *
	 * WordPress does not expose unpublished posts through is_singular() for a
	 * logged-out request. Resolve the rewrite query variable directly when the
	 * normal queried object is unavailable so known draft, private, pending,
	 * and trashed codes can return the documented 410 response. Unknown slugs
	 * still fall through to WordPress's normal 404 handling.
	 *
	 * @return WP_Post|null Requested QR code, or null for an unrelated/unknown request.
	 */
	private static function resolve_requested_code() {
		if ( is_singular( CFQR_POST_TYPE ) ) {
			$post = get_queried_object();
			return $post && CFQR_POST_TYPE === $post->post_type ? $post : null;
		}

		$slug = get_query_var( CFQR_POST_TYPE, '' );
		if ( ! is_string( $slug ) || '' === $slug || false !== strpos( $slug, '/' ) ) {
			return null;
		}

		$post = get_page_by_path( $slug, OBJECT, CFQR_POST_TYPE );
		return $post && CFQR_POST_TYPE === $post->post_type ? $post : null;
	}

	/**
	 * Update hit counter and last-hit timestamp. Done via shutdown to keep
	 * the redirect itself as fast as possible (the headers have already been
	 * flushed by then in most setups).
	 *
	 * In steady state this is a single UPDATE that bumps the counter atomically
	 * (CAST + 1 — no read-modify-write race) AND stamps the timestamp via a
	 * CASE expression. First-scan paths still pay for row seeding via
	 * add_post_meta so the UPDATE has rows to operate on.
	 */
	private static function record_hit( $post_id ) {
		$post_id = (int) $post_id;
		add_action(
			'shutdown',
			function () use ( $post_id ) {
				global $wpdb;
				$now = gmdate( 'c' );

				// Seed missing meta rows so the single UPDATE below has something
				// to operate on. Steady-state scans skip this entirely.
				if ( '' === (string) get_post_meta( $post_id, CFQR_CPT::META_HIT_COUNT, true ) ) {
					add_post_meta( $post_id, CFQR_CPT::META_HIT_COUNT, 0, true );
				}
				if ( '' === (string) get_post_meta( $post_id, CFQR_CPT::META_LAST_HIT_AT, true ) ) {
					add_post_meta( $post_id, CFQR_CPT::META_LAST_HIT_AT, '', true );
				}

				// Combined UPDATE: increments the hit counter atomically and sets
				// the last-hit timestamp in one statement. Previous implementation
				// did two writes (UPDATE for counter, update_post_meta for stamp).
				// Direct query is required for the atomic CAST(meta_value AS UNSIGNED) + 1
				// — there is no postmeta API equivalent and a read/modify/write would
				// race under concurrent scans.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = CASE
						WHEN meta_key = %s THEN CAST(meta_value AS UNSIGNED) + 1
						WHEN meta_key = %s THEN %s
					END
					WHERE post_id = %d AND meta_key IN (%s, %s)",
						CFQR_CPT::META_HIT_COUNT,
						CFQR_CPT::META_LAST_HIT_AT,
						$now,
						$post_id,
						CFQR_CPT::META_HIT_COUNT,
						CFQR_CPT::META_LAST_HIT_AT
					)
				);
				wp_cache_delete( $post_id, 'post_meta' );
			},
			1
		);
	}

	/**
	 * Build a keyed fingerprint for the current scan and return true if we've
	 * seen the same fingerprint inside the dedupe window.
	 *
	 * Uses HMAC-SHA256 with a WordPress nonce salt so the fingerprint is not
	 * brute-forceable from leaked transient data (an unsalted SHA256 of IPv4 +
	 * common UA + known slug is a small search space). The transient is written
	 * once on first hit and never refreshed on duplicates — refreshing on every
	 * duplicate hit caused one transient write per request even for bots, which
	 * defeated the intent of reducing DB pressure.
	 */
	private static function is_duplicate_scan( $post ) {
		// These values are immediately fed into hash_hmac() and never echoed, stored,
		// or interpolated into output — wp_unslash is sufficient. sanitize_text_field
		// would strip characters that legitimately appear in UAs (newlines do not, but
		// the linter doesn't differentiate); the HMAC absorbs whatever we pass.
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
		// No IP and no UA — no fingerprint to dedupe against, count it.
		if ( '' === $ip && '' === $ua ) {
			return false;
		}
		$fingerprint = substr(
			hash_hmac(
				'sha256',
				$ip . '|' . $ua . '|' . (string) $post->post_name,
				wp_salt( 'nonce' )
			),
			0,
			32
		);
		$key         = 'cfqr_seen_' . $fingerprint;
		if ( false !== get_transient( $key ) ) {
			// Already seen — do NOT refresh the TTL. Refreshing caused a DB
			// write on every duplicate request, which is what we're trying to avoid.
			return true;
		}
		set_transient( $key, 1, self::DEDUPE_TTL );
		return false;
	}

	/**
	 * Per-slug rate limit. Each slug gets a 1-second-TTL counter; once the
	 * counter exceeds RATE_LIMIT_WRITES_PER_SEC, further hits in the same
	 * second short-circuit before the DB write. This is a soft limit (uses
	 * the object cache where available) and resets every second.
	 */
	private static function is_rate_limited( $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return false;
		}
		$key   = 'cfqr_rate_' . md5( $slug );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_WRITES_PER_SEC ) {
			return true;
		}
		set_transient( $key, $count + 1, 1 );
		return false;
	}

	/**
	 * Whitelist destinations. Delegates to {@see CFQR_URL::is_safe_destination()}
	 * so the router, meta-box save, and Quick Edit save share one predicate.
	 */
	private static function is_safe_destination( $url ) {
		return CFQR_URL::is_safe_destination( $url );
	}

	/**
	 * Send a 410 Gone response with a minimal HTML body.
	 */
	private static function send_gone() {
		status_header( 410 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Content-Type: text/html; charset=utf-8' );
		printf(
			'<!doctype html><html><head><meta charset="utf-8"><title>%1$s</title><meta name="robots" content="noindex,nofollow"></head><body><h1>%2$s</h1></body></html>',
			esc_html__( '410 Gone', 'cf-qr-redirect' ),
			esc_html__( 'This QR code is no longer active.', 'cf-qr-redirect' )
		);
	}
}
