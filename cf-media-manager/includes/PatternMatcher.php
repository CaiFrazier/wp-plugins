<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Match request paths against a newline-separated pattern list.
 *
 * Patterns support * as a wildcard. Lines starting with # are comments.
 * Empty lines are ignored. Matching is case-insensitive and anchored to
 * the full path.
 *
 * Pure: does not read $_SERVER. The caller passes the path. This makes the
 * matcher trivially unit-testable.
 */
final class PatternMatcher {

	/**
	 * Returns true if $path matches any pattern in $patterns_text.
	 */
	public static function matches( string $path, string $patterns_text ): bool {
		$path = $path === '' ? '/' : $path;

		foreach ( explode( "\n", $patterns_text ) as $raw ) {
			$pattern = trim( $raw );
			if ( $pattern === '' || $pattern[0] === '#' ) {
				continue;
			}
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
			if ( preg_match( $regex, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Convenience: matches the current request path. Falls back to '/' if
	 * REQUEST_URI is missing or unparseable.
	 */
	public static function matches_current_request( string $patterns_text ): bool {
		// REQUEST_URI is a server-supplied value but we only use the path
		// component for pattern matching, never echo or write it. wp_unslash
		// + sanitize_text_field satisfy the PHPCS sanitization rule; the
		// downstream parsing is what actually defends us.
		$raw  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		return self::matches( $path, $patterns_text );
	}
}
