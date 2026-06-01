<?php
namespace SchemaOverrideManager\Integrations;

defined( 'ABSPATH' ) || exit;

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

		$data = apply_filters( 'rank_math/json_ld', [] );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Suppresses all Rank Math JSON-LD output.
	 */
	public static function suppress_all(): void {
		add_filter( 'rank_math/json_ld', '__return_false', 20 );
	}

	/**
	 * Suppresses specific type keys from Rank Math output.
	 *
	 * @param string[] $types
	 */
	public static function suppress_types( array $types ): void {
		add_filter( 'rank_math/json_ld', function ( $data ) use ( $types ) {
			if ( ! is_array( $data ) ) {
				return $data;
			}
			foreach ( $types as $type ) {
				unset( $data[ $type ] );
			}
			return $data;
		}, 20 );
	}
}
