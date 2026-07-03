<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

/**
 * Strict sanitizer for JSON-LD schema payloads.
 *
 * The old Settings::sanitize_schema_array() ran wp_json_encode → json_decode
 * which is a no-op for sanitization — it lets any JSON-safe value through
 * unchanged. An `edit_post`-capable user could therefore stuff arbitrary
 * strings (including HTML / `</script>` payloads) into postmeta that ends up
 * inside <script type="application/ld+json"> at render time.
 *
 * SchemaOutput already uses JSON_HEX_TAG | JSON_HEX_AMP when encoding, so
 * the script-context breakout vector is mitigated at the boundary. This
 * sanitizer focuses on the *other* concerns:
 *
 *   - depth bombs (deeply nested arrays that OOM PHP at decode time)
 *   - node-count bombs (10,000 nested empty arrays)
 *   - oversized string values (a megabyte of garbage inside a `description`)
 *   - URLs that aren't URLs (esc_url_raw on known URL-shaped keys)
 *   - HTML inside string property values (wp_strip_all_tags belt-and-braces)
 *   - malformed `@type` values (must look like a schema.org identifier)
 *
 * The sanitizer is intentionally lenient about *which* schema.org types are
 * accepted — there are 800+ types and a curated list would break legitimate
 * uses of less-common ones. The check is structural: any string matching
 * `[A-Za-z][A-Za-z0-9]*` after stripping the schema.org URL prefix is fine.
 * The relevant_schema_types() list lives on Util and is the UX picker, not
 * a security boundary.
 */
final class Sanitizer {

	/** Hard depth cap. Real-world JSON-LD rarely exceeds 5 levels. */
	public const MAX_DEPTH = 8;

	/**
	 * Total array entries across the whole tree (root + every nested level).
	 * Counts every scalar property, not just object nodes, so this is set well
	 * above any realistic hand-authored graph: a rich LocalBusiness with a full
	 * openingHoursSpecification, address, geo, aggregateRating, and a dozen
	 * sameAs entries is ~60 entries. 2000 leaves generous headroom while still
	 * stopping a node-count bomb. When the cap trips the current level is
	 * truncated silently — acceptable only because legitimate content never
	 * reaches it.
	 */
	public const MAX_NODE_COUNT = 2000;

	/** Per-string-value byte cap. 8 KB fits any legitimate description / FAQ answer. */
	public const MAX_STRING_BYTES = 8192;

	/**
	 * Keys whose values should be URL-escaped via esc_url_raw. Drawn from the
	 * schema.org property list of types declared as URL-valued. Site owners
	 * can extend via the `som_sanitizer_url_keys` filter.
	 */
	private const URL_KEYS = [
		'url',
		'sameAs',
		'image',
		'logo',
		'@id',
		'target',
		'mainEntityOfPage',
		'thumbnailUrl',
		'contentUrl',
		'embedUrl',
	];

	/** Identifier pattern for @type values after normalization. */
	private const TYPE_PATTERN = '/^[A-Za-z][A-Za-z0-9]{0,63}$/';

	/**
	 * Public entry: sanitize a parsed-from-JSON schema payload.
	 *
	 * Returns the cleaned payload (always an array, possibly empty). Drops
	 * keys/values that fail validation rather than rejecting the whole tree —
	 * the goal is "store what we can safely render" not "fail closed and lose
	 * the user's edits". The boundary checks at the editor save are the
	 * place to surface user-facing rejection notices.
	 *
	 * @param array $data Parsed JSON-LD payload.
	 * @return array Cleaned payload.
	 */
	public static function sanitize_schema( array $data ): array {
		$count = 0;
		$out   = self::walk( $data, 0, $count );
		return is_array( $out ) ? $out : [];
	}

	/**
	 * Recursive walker. $count is by-ref so node-count cap is global across
	 * the whole tree, not per-branch.
	 *
	 * @param mixed $value Value to sanitize.
	 * @param int   $depth Current recursion depth.
	 * @param int   $count Running node count across the whole tree.
	 * @return mixed Sanitized value, or null if the value was rejected.
	 */
	private static function walk( $value, int $depth, int &$count ) {
		if ( $depth > self::MAX_DEPTH ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$clean = [];
			foreach ( $value as $k => $v ) {
				if ( $count >= self::MAX_NODE_COUNT ) {
					break;
				}
				++$count;

				$key = self::sanitize_key( $k );
				if ( null === $key ) {
					continue;
				}

				$clean_v = self::sanitize_value_for_key( $key, $v, $depth + 1, $count );
				if ( null === $clean_v ) {
					continue;
				}
				$clean[ $key ] = $clean_v;
			}
			return $clean;
		}

		// Scalar at root — extremely unusual for JSON-LD, but handle gracefully.
		return self::sanitize_scalar( '', $value );
	}

	/**
	 * Sanitize a value with awareness of which key it lives under. URL-shaped
	 * keys get esc_url_raw; @type gets the identifier check; @context is left
	 * as-is (it's almost always the literal "https://schema.org" string).
	 *
	 * @param string $key   Parent key the value lives under.
	 * @param mixed  $value Value to sanitize.
	 * @param int    $depth Current recursion depth.
	 * @param int    $count Running node count across the whole tree.
	 * @return mixed Sanitized value, or null if rejected.
	 */
	private static function sanitize_value_for_key( string $key, $value, int $depth, int &$count ) {
		// Nested object or list — recurse.
		if ( is_array( $value ) ) {
			return self::walk( $value, $depth, $count );
		}

		return self::sanitize_scalar( $key, $value );
	}

	/**
	 * Sanitize a scalar value (string, int, float, bool). $key informs the
	 * choice of sanitizer (URL vs free-text vs @type identifier).
	 *
	 * @param string $key   Parent key the value lives under.
	 * @param mixed  $value Scalar to sanitize.
	 * @return mixed Sanitized scalar, or null if rejected.
	 */
	private static function sanitize_scalar( string $key, $value ) {
		// JSON-LD `null` is structural noise after sanitization — drop it.
		if ( null === $value ) {
			return null;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			// Objects, resources, anything else: drop.
			return null;
		}

		// String value. Per-key special handling first.
		if ( '@type' === $key ) {
			$normalized = Util::normalize_schema_type( $value );
			if ( '' === $normalized || ! preg_match( self::TYPE_PATTERN, $normalized ) ) {
				// Drop the @type rather than emit a malformed identifier. The
				// surrounding node loses its semantic typing but the rest of
				// the data survives.
				return null;
			}
			return $normalized;
		}

		if ( '@context' === $key ) {
			// Context is almost always the schema.org URL or an array of URLs.
			// esc_url_raw covers both the string and the per-element case
			// (array case goes through walk() before reaching here).
			$url = esc_url_raw( $value );
			return '' !== $url ? $url : null;
		}

		if ( in_array( $key, self::url_keys(), true ) ) {
			$url = esc_url_raw( $value );
			return '' !== $url ? $url : null;
		}

		// Free-text string. Truncate first (before tag-strip so we don't
		// waste CPU stripping tags from a megabyte of garbage), then strip
		// all HTML defensively. Real JSON-LD descriptions are plain text;
		// search-engine consumers strip HTML in display anyway.
		if ( strlen( $value ) > self::MAX_STRING_BYTES ) {
			$value = substr( $value, 0, self::MAX_STRING_BYTES );
		}
		$stripped = wp_strip_all_tags( $value );
		return '' !== $stripped ? $stripped : null;
	}

	/**
	 * Sanitize an array key. Allows JSON-LD reserved keys (@type, @id, etc.),
	 * sanitize_text_field for everything else, drops keys that look unsafe.
	 *
	 * @param mixed $key Raw array key.
	 * @return string|null Sanitized key, or null to drop it.
	 */
	private static function sanitize_key( $key ): ?string {
		if ( is_int( $key ) ) {
			// Numeric keys come from JSON arrays — preserve as-is.
			return (string) $key;
		}
		if ( ! is_string( $key ) ) {
			return null;
		}
		$key = trim( $key );
		if ( '' === $key ) {
			return null;
		}
		// JSON-LD reserved keys all start with '@'. Allow these plus any
		// camelCase / snake_case property name. Reject anything containing
		// control chars or HTML-special characters.
		if ( preg_match( '/[<>\x00-\x1f]/', $key ) ) {
			return null;
		}
		// Cap key length to avoid memory blowups from pathological inputs.
		if ( strlen( $key ) > 256 ) {
			return null;
		}
		return $key;
	}

	/**
	 * URL-shaped key list, filterable so site owners can extend without
	 * forking the plugin.
	 *
	 * @return string[]
	 */
	private static function url_keys(): array {
		$keys = self::URL_KEYS;
		if ( function_exists( 'apply_filters' ) ) {
			$keys = apply_filters( 'som_sanitizer_url_keys', $keys );
			$keys = is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : self::URL_KEYS;
		}
		return $keys;
	}
}
