<?php
namespace SchemaOverrideManager\Integrations;

use SchemaOverrideManager\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical Rank Math integration surface. Suppressor delegates here, and
 * downstream extensions should treat these static methods as the API rather
 * than re-registering the underlying Rank Math filters themselves.
 */
class RankMathIntegration {

	public static function is_active(): bool {
		return class_exists( 'RankMath' ) || function_exists( 'rank_math' );
	}

	/**
	 * Returns the schema data Rank Math would produce for the current page.
	 */
	public static function get_json_ld(): array {
		if ( ! self::is_active() ) {
			return [];
		}

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Rank Math's own hook name.
		$data = apply_filters( 'rank_math/json_ld', [] );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Suppresses all Rank Math JSON-LD output.
	 */
	public static function suppress_all(): void {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Rank Math's own hook name.
		add_filter( 'rank_math/json_ld', '__return_false', 20 );
	}

	/**
	 * Suppresses specific types from Rank Math output.
	 *
	 * Rank Math keys its data array by short slug. An entry is removed when
	 * either its key or its node's own @type normalizes to a suppressed type,
	 * so both slug-keyed and fully-qualified entries are covered.
	 *
	 * @param string[] $types Type names to suppress (any spelling variant).
	 */
	public static function suppress_types( array $types ): void {
		$suppressed = array_filter( array_map( [ Util::class, 'normalize_schema_type' ], $types ) );
		if ( empty( $suppressed ) ) {
			return;
		}

		add_filter(
			'rank_math/json_ld',
			function ( $data ) use ( $suppressed ) {
				if ( ! is_array( $data ) ) {
					return $data;
				}
				foreach ( array_keys( $data ) as $key ) {
					$key_norm = Util::normalize_schema_type( (string) $key );
					if ( in_array( $key_norm, $suppressed, true ) ) {
						unset( $data[ $key ] );
						continue;
					}
					if ( is_array( $data[ $key ] ) && isset( $data[ $key ]['@type'] ) ) {
						// @type may be a string or an array of strings (multi-typed
						// node) — match if any of its types is suppressed, mirroring
						// the Yoast integration.
						$raw   = $data[ $key ]['@type'];
						$types = is_array( $raw ) ? $raw : [ $raw ];
						foreach ( $types as $t ) {
							if ( in_array( Util::normalize_schema_type( $t ), $suppressed, true ) ) {
								unset( $data[ $key ] );
								break;
							}
						}
					}
				}
				return $data;
			},
			20
		);
	}
}
