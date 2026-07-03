<?php
namespace SchemaOverrideManager\Integrations;

use SchemaOverrideManager\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical Yoast SEO integration surface. Suppressor delegates here, and
 * downstream extensions should treat these static methods as the API rather
 * than re-registering the underlying Yoast filters themselves.
 */
class YoastIntegration {

	public static function is_active(): bool {
		return class_exists( 'WPSEO_Options' ) || defined( 'WPSEO_VERSION' );
	}

	/**
	 * Returns the schema graph nodes Yoast would produce for the current page.
	 */
	public static function get_graph_nodes(): array {
		if ( ! self::is_active() ) {
			return [];
		}

		$nodes = apply_filters( 'wpseo_schema_graph', [] );
		return is_array( $nodes ) ? $nodes : [];
	}

	/**
	 * Suppresses all Yoast JSON-LD output.
	 */
	public static function suppress_all(): void {
		add_filter( 'wpseo_json_ld_output', '__return_empty_array', 20 );
	}

	/**
	 * Suppresses specific @type nodes from the Yoast graph.
	 *
	 * Types are normalized (URL / prefix forms → bare name) on both sides of
	 * the comparison: the incoming suppression list and each graph node's own
	 * type, which may be a string or an array of strings (multi-typed nodes).
	 *
	 * @param string[] $types Type names to suppress (any spelling variant).
	 */
	public static function suppress_types( array $types ): void {
		$suppressed = array_filter( array_map( [ Util::class, 'normalize_schema_type' ], $types ) );
		if ( empty( $suppressed ) ) {
			return;
		}

		add_filter(
			'wpseo_schema_graph',
			function ( $pieces ) use ( $suppressed ) {
				if ( ! is_array( $pieces ) ) {
					return $pieces;
				}
				return array_values(
					array_filter(
						$pieces,
						function ( $piece ) use ( $suppressed ) {
							$raw   = $piece['@type'] ?? '';
							$types = is_array( $raw ) ? $raw : [ $raw ];
							foreach ( $types as $t ) {
								if ( in_array( Util::normalize_schema_type( $t ), $suppressed, true ) ) {
									return false;
								}
							}
							return true;
						}
					)
				);
			},
			20
		);
	}
}
