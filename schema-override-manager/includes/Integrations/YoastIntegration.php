<?php
namespace SchemaOverrideManager\Integrations;

defined( 'ABSPATH' ) || exit;

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
	 * @param string[] $types
	 */
	public static function suppress_types( array $types ): void {
		add_filter( 'wpseo_schema_graph', function ( $pieces ) use ( $types ) {
			return array_values( array_filter( $pieces, function ( $piece ) use ( $types ) {
				return ! in_array( $piece['@type'] ?? '', $types, true );
			} ) );
		}, 20 );
	}
}
