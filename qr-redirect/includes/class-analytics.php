<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Analytics: UTM parameter injection and intermediate-page rendering.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Analytics {

	/**
	 * Append UTM parameters to a destination URL based on a code's meta.
	 * Existing query params on the destination are preserved.
	 */
	public static function build_utm_url( $destination, $post ) {
		$source   = get_post_meta( $post->ID, CFQR_CPT::META_UTM_SOURCE, true );
		$medium   = get_post_meta( $post->ID, CFQR_CPT::META_UTM_MEDIUM, true );
		$campaign = get_post_meta( $post->ID, CFQR_CPT::META_CAMPAIGN, true );

		if ( '' === $source ) {
			$source = CFQR_Settings::get( 'default_utm_source' );
		}
		if ( '' === $medium ) {
			$medium = CFQR_Settings::get( 'default_utm_medium' );
		}

		$args = array(
			'utm_source'  => $source,
			'utm_medium'  => $medium,
			'utm_content' => $post->post_name,
		);

		if ( '' !== $campaign ) {
			// Campaign is already slugified on save (see CFQR_Admin::save_meta), so no
			// further transformation is needed here — pass the stored value through.
			$args['utm_campaign'] = $campaign;
		}

		// add_query_arg url-encodes values internally; do NOT pre-encode.
		return add_query_arg( $args, $destination );
	}

	/**
	 * Render the intermediate HTML page that fires a GA4 event then redirects.
	 * This terminates the request via exit.
	 */
	public static function render_intermediate( $destination, $post ) {
		$ga4 = CFQR_Settings::resolve_ga4_id();

		// If GA4 isn't configured, fall back to UTM mode silently. Use wp_redirect
		// (not wp_safe_redirect) — the destination has already been validated by
		// CFQR_Router::is_safe_destination() as http(s) with a host, and
		// wp_safe_redirect refuses cross-host redirects, which is the entire
		// point of QR codes. Without this, Intermediate-mode codes pointing at
		// external destinations produced a blank page on every scan when no GA4
		// ID was configured.
		if ( '' === $ga4 ) {
			$utm_url = self::build_utm_url( $destination, $post );
			$status  = (int) CFQR_Settings::get( 'redirect_status' );
			if ( ! in_array( $status, array( 301, 302 ), true ) ) {
				$status = 302;
			}
			// External destinations are the product feature — see CFQR_Router for context.
			wp_redirect( $utm_url, $status ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			exit;
		}

		$campaign = (string) get_post_meta( $post->ID, CFQR_CPT::META_CAMPAIGN, true );

		// Variables consumed by templates/intermediate.php.
		$context = array(
			'ga4_id'      => $ga4,
			'short_code'  => $post->post_name,
			'destination' => $destination,
			'campaign'    => $campaign,
		);

		// Send security/no-cache headers before printing.
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()', true );
		// Conservative CSP: allow inline script only via nonce, plus the GA endpoint.
		// The template builds a per-request nonce; we expose it via the context array.
		try {
			$context['nonce'] = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			// random_bytes can throw on systems without a CSPRNG. Fall back rather than fail open.
			$context['nonce'] = wp_generate_password( 32, false, false );
		}
		header(
			"Content-Security-Policy: default-src 'none'; "
			. "script-src 'self' 'nonce-" . $context['nonce'] . "' https://www.googletagmanager.com https://www.google-analytics.com; "
			. 'img-src https://www.google-analytics.com data:; '
			. 'connect-src https://www.google-analytics.com https://www.googletagmanager.com; '
			. "style-src 'unsafe-inline'; "
			. "base-uri 'none'; "
			. "form-action 'none'; "
			. "frame-ancestors 'none'"
		);

		$template = CFQR_PLUGIN_DIR . 'templates/intermediate.php';
		if ( file_exists( $template ) ) {
			// Make context available to the template via extracted variables.
			$ga4_id      = $context['ga4_id'];
			$short_code  = $context['short_code'];
			$destination = $context['destination'];
			$campaign    = $context['campaign'];
			$nonce       = $context['nonce'];
			include $template;
		}
		exit;
	}
}
