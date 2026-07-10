<?php
namespace CFForms;

defined( 'ABSPATH' ) || exit;

/**
 * Field sanitization for submitted form payloads. Front-end forms haven't
 * been built yet, so this stays type-inferring (rather than schema-driven
 * per form_id) until a real field-type contract exists.
 */
final class Sanitizer {

	const MAX_FIELD_COUNT     = 40;
	const MAX_FIELD_NAME_LEN  = 64;
	const MAX_FIELD_VALUE_LEN = 5000;

	/**
	 * Sanitize a decoded JSON payload into a flat string map.
	 *
	 * @param mixed $raw_fields Decoded JSON value expected to be an assoc array.
	 * @return array<string,string> Sanitized field map, or [] if $raw_fields is malformed.
	 */
	public static function sanitize_fields( $raw_fields ): array {
		if ( ! is_array( $raw_fields ) ) {
			return [];
		}

		$clean = [];
		$count = 0;

		foreach ( $raw_fields as $key => $value ) {
			if ( $count >= self::MAX_FIELD_COUNT ) {
				break;
			}

			$key = sanitize_key( substr( (string) $key, 0, self::MAX_FIELD_NAME_LEN ) );
			if ( '' === $key ) {
				continue;
			}

			$value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			$value = substr( $value, 0, self::MAX_FIELD_VALUE_LEN );

			$clean[ $key ] = false !== stripos( $key, 'email' )
				? sanitize_email( $value )
				: sanitize_text_field( $value );

			++$count;
		}

		return $clean;
	}

	public static function sanitize_form_id( $raw ): string {
		return sanitize_key( substr( (string) $raw, 0, 64 ) );
	}
}
