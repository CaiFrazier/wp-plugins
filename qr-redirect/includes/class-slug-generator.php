<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * Random short code generator and validator.
 *
 * @package CFQR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CFQR_Slug_Generator {

	/**
	 * Visually unambiguous character set: excludes 0, O, I, l, 1.
	 */
	const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';

	/**
	 * Generate a fresh slug that does not collide with any existing post of any type.
	 * Tries up to $max_attempts times before failing.
	 */
	public static function generate( $length = null, $max_attempts = 8 ) {
		if ( null === $length ) {
			$length = (int) CFQR_Settings::get( 'short_code_length' );
		}
		$length = max( 4, min( 12, (int) $length ) );

		for ( $i = 0; $i < $max_attempts; $i++ ) {
			$candidate = self::random_string( $length );
			if ( self::is_available( $candidate ) ) {
				return $candidate;
			}
		}

		// Fall back to a longer slug to escape the collision space.
		return self::random_string( $length + 2 );
	}

	/**
	 * Generate a single random string of the given length using the alphabet.
	 * Uses random_bytes (cryptographic) when available.
	 */
	private static function random_string( $length ) {
		$alphabet  = self::ALPHABET;
		$alpha_len = strlen( $alphabet );
		$out       = '';

		try {
			$bytes = random_bytes( $length * 2 );
		} catch ( Exception $e ) {
			$bytes = '';
			for ( $i = 0; $i < $length * 2; $i++ ) {
				$bytes .= chr( wp_rand( 0, 255 ) );
			}
		}

		$byte_len = strlen( $bytes );
		$out_len  = 0;
		$idx      = 0;
		while ( $out_len < $length && $idx < $byte_len ) {
			$byte = ord( $bytes[ $idx++ ] );
			// Reject bytes that would bias the distribution.
			if ( $byte < ( 256 - ( 256 % $alpha_len ) ) ) {
				$out .= $alphabet[ $byte % $alpha_len ];
				++$out_len;
			}
		}

		// Pad with non-cryptographic randomness if we ran out of bytes (extremely rare).
		while ( $out_len < $length ) {
			$out .= $alphabet[ wp_rand( 0, $alpha_len - 1 ) ];
			++$out_len;
		}

		return $out;
	}

	/**
	 * Check whether a slug is free for use across the entire posts table.
	 * (CPT slug uniqueness is enforced by WP, but we want to avoid colliding
	 * with pages, posts, or any other public content as well.)
	 */
	public static function is_available( $slug ) {
		global $wpdb;
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return false;
		}
		// Direct query is required: WP has no canonical "is this post_name in use across
		// all post types and statuses?" helper, and the result must be authoritative
		// (no cache layer) to prevent slug-collision races on simultaneous saves.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_status NOT IN ('trash','auto-draft') LIMIT 1",
				$slug
			)
		);
		return null === $existing;
	}

	/**
	 * Validate a user-supplied vanity slug against the allowed character set.
	 * Returns true when valid, false otherwise.
	 */
	public static function is_valid( $slug ) {
		if ( ! is_string( $slug ) || '' === $slug ) {
			return false;
		}
		$len = strlen( $slug );
		if ( $len < 3 || $len > 64 ) {
			return false;
		}
		// Allow alphanumeric and hyphens for vanity slugs.
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9\-]*[A-Za-z0-9]$/', $slug );
	}
}
