<?php

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Typed `$_POST` accessors with WordPress-correct sanitization.
 *
 * The standard pattern repeated across every AJAX endpoint was:
 *
 *     $x = isset( $_POST['x'] ) ? sanitize_text_field( wp_unslash( $_POST['x'] ) ) : '';
 *
 * Four call-site mistakes hide in that pattern: missing the `isset`,
 * missing `wp_unslash`, swapping the order of `wp_unslash` and `sanitize_*`
 * (WP best practice is unslash-then-sanitize so sanitizers see the
 * canonical value), and forgetting to cast to the expected type. None
 * are caught by PHPCS reliably. Routing every read through this class
 * makes the right pattern the only pattern.
 *
 * Every accessor reads from `$_POST` (these helpers are AJAX-specific —
 * GET reads have different security considerations and aren't covered
 * here). Nonce + capability verification happens at the security gate
 * before these helpers are called.
 */
final class Request {

	/**
	 * Sanitized single-line string. Use for general text inputs, URLs,
	 * filenames passed by the client, etc. — anything that should NOT
	 * contain newlines or HTML.
	 */
	public static function post_string( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	/**
	 * Slug-like string: lowercase ASCII letters, numbers, hyphens and
	 * underscores only. Use for enum-style inputs like filter mode,
	 * scope, action variant — values the server matches against a
	 * known allow-list anyway, where any other character is noise.
	 */
	public static function post_key( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		return sanitize_key( wp_unslash( (string) $_POST[ $key ] ) );
	}

	/**
	 * Multi-line text — keeps newlines, sanitizes everything else.
	 * Use for textareas (filter patterns, alt text bodies, etc.).
	 */
	public static function post_textarea( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		return sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	/**
	 * Integer with int-cast. Default returned when the key is missing or
	 * non-numeric — (int) on a non-numeric string returns 0, which is
	 * usually the right "missing/invalid" sentinel.
	 */
	public static function post_int( string $key, int $default = 0 ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		return (int) $_POST[ $key ];
	}

	/**
	 * Boolean from any truthy-ish value. Treats '0', 0, '', false, null,
	 * and absent keys as false. Matches the existing `! empty( $_POST['x'] )`
	 * idiom that runs through most of the legacy code.
	 */
	public static function post_bool( string $key, bool $default = false ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		return ! empty( $_POST[ $key ] );
	}

	/**
	 * Array of ints. Use for `ids[]`-style payloads where the client
	 * sends a list of attachment IDs. Non-array input yields the default;
	 * non-numeric elements are dropped via intval-then-positive filter.
	 *
	 * @return int[]
	 */
	public static function post_int_array( string $key, array $default = array() ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return $default;
		}
		$out = array();
		// The (int) cast in the loop below IS the sanitization — non-numeric
		// strings cast to 0 and are filtered by the > 0 guard. Caller has
		// run Security::authorize_ajax so the nonce check is satisfied.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = wp_unslash( $_POST[ $key ] );
		foreach ( (array) $raw as $v ) {
			$n = (int) $v;
			if ( $n > 0 ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	/**
	 * Attachment-ID list accepting BOTH array (`ids[]=12&ids[]=34`) and
	 * CSV-string (`ids=12,34,56` or even `ids=12 34 56`) shapes. The CSV
	 * shape exists because some admin UI flows (and the WP-CLI command)
	 * pre-format the id list as a comma-separated string; without this
	 * helper they were going through Ajax::parse_id_list with a `(array)`
	 * coercion that wrapped the WHOLE STRING into a single-element array,
	 * resulting in `intval("12,34,56") === 12` — silently dropping the
	 * other ids. (See H1 in the v2.0.1 audit.)
	 *
	 * Output is intval'd, filtered for positives, deduped, reindexed, and
	 * hard-capped at $max — even when the UI imposes a tighter cap, this
	 * defends against hostile / buggy clients that bypass the UI.
	 *
	 * @return int[]
	 */
	public static function post_id_list( string $key, int $max = 50000 ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		if ( ! isset( $_POST[ $key ] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intval-cast on every element below is the sanitization for an int list.
		$raw = wp_unslash( $_POST[ $key ] );

		// Normalize to an array of scalars. CSV-string shape splits on
		// comma and whitespace; array shape (ids[]) passes through.
		if ( is_string( $raw ) ) {
			$parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
			$raw   = is_array( $parts ) ? $parts : array();
		} elseif ( ! is_array( $raw ) ) {
			// Anything else (object, null, bool) — refuse, return empty.
			return array();
		}

		// Defense in depth: intval() applied to a non-scalar (array, object)
		// is PHP-version-dependent — PHP 8.0–8.4 returns 0, 8.5+ returns 1,
		// 7.x returns 1. An attacker sending `ids[][]=anything` could
		// silently smuggle attachment id 1 on PHP 8.5+. Filter to scalars
		// before the int-cast so non-scalar input becomes "no id at all".
		$ids = array_values(
			array_unique(
				array_filter(
					array_map(
						'intval',
						array_filter( $raw, 'is_scalar' )
					),
					static function ( $n ) {
						return $n > 0;
					}
				)
			)
		);

		if ( $max > 0 && count( $ids ) > $max ) {
			$ids = array_slice( $ids, 0, $max );
		}
		return $ids;
	}

	/**
	 * Raw URL — minimally sanitized so callers can run their own
	 * validation. `esc_url_raw` strips low-byte control characters and
	 * percent-encodes forbidden characters but doesn't enforce that the
	 * URL points anywhere in particular.
	 */
	public static function post_url( string $key, string $default = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- caller has run Security::authorize_ajax.
		return esc_url_raw( wp_unslash( (string) $_POST[ $key ] ) );
	}

	private function __construct() {}
}
