<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

use SchemaOverrideManager\Integrations\YoastIntegration;
use SchemaOverrideManager\Integrations\RankMathIntegration;

/**
 * Detects schema already active on a page from Yoast, Rank Math, and theme output.
 * Used only in admin context for the "Existing Schema" viewer.
 */
class SchemaDetector {

	public function detect_for_post( int $post_id ): array {
		$detected = [];

		$detected['yoast']     = $this->detect_yoast( $post_id );
		$detected['rank_math'] = $this->detect_rank_math();

		return $detected;
	}

	private function detect_yoast( int $post_id ): array {
		if ( ! YoastIntegration::is_active() ) {
			return [];
		}

		// Preferred: Yoast's surfaces API produces fully-contextual schema for a post,
		// which is the only reliable path in a REST request context.
		if ( function_exists( 'YoastSEO' ) ) {
			try {
				$schema = YoastSEO()->meta->for_post( $post_id )->schema ?? null;
				if ( is_array( $schema ) && ! empty( $schema['@graph'] ) ) {
					return array_values(
						array_map(
							static function ( $node ) {
								return [
									'type' => Util::normalize_schema_type( $node['@type'] ?? '' ),
									'data' => $node,
								];
							},
							$schema['@graph']
						)
					);
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- fall through to the filter-based best-effort below.
				unset( $e );
			}
		}

		// Fallback: delegate to the YoastIntegration filter call. May return little/no data
		// outside a frontend request — the live-fetch endpoint is the accurate path.
		$nodes = YoastIntegration::get_graph_nodes();
		$types = [];
		foreach ( $nodes as $node ) {
			if ( ! empty( $node['@type'] ) ) {
				$types[] = [
					'type' => Util::normalize_schema_type( $node['@type'] ),
					'data' => $node,
				];
			}
		}
		return $types;
	}

	private function detect_rank_math(): array {
		if ( ! RankMathIntegration::is_active() ) {
			return [];
		}

		$data  = RankMathIntegration::get_json_ld();
		$types = [];
		foreach ( $data as $slug => $block ) {
			$types[] = [
				'type' => Util::normalize_schema_type( $block['@type'] ?? (string) $slug ),
				'data' => $block,
			];
		}
		return $types;
	}

	/**
	 * Known Yoast graph node types, offered as suppression checkboxes even when
	 * live filter detection returns nothing (common in the editor's REST
	 * context). Filterable via `som_yoast_emitted_types` so a site whose Yoast
	 * config emits extra types — Service, LocalBusiness, and the like — can
	 * surface them without forking the plugin.
	 *
	 * @return string[] Normalized bare type names, empty when Yoast is inactive.
	 */
	public function get_yoast_types(): array {
		if ( ! YoastIntegration::is_active() ) {
			return [];
		}

		$types = [
			'WebSite',
			'WebPage',
			'Article',
			'BreadcrumbList',
			'Person',
			'Organization',
		];

		return $this->normalize_type_list( apply_filters( 'som_yoast_emitted_types', $types ) );
	}

	/**
	 * Known Rank Math node types, offered as suppression checkboxes even when
	 * live filter detection returns nothing. Filterable via
	 * `som_rankmath_emitted_types`.
	 *
	 * @return string[] Normalized bare type names, empty when Rank Math is inactive.
	 */
	public function get_rank_math_types(): array {
		if ( ! RankMathIntegration::is_active() ) {
			return [];
		}

		$types = [
			'WebSite',
			'WebPage',
			'Article',
			'BreadcrumbList',
			'Person',
			'Organization',
			'Product',
			'FAQPage',
		];

		return $this->normalize_type_list( apply_filters( 'som_rankmath_emitted_types', $types ) );
	}

	/**
	 * Coerce a filtered type list back to a clean, unique, normalized array of
	 * bare type names — filters can hand back anything.
	 *
	 * @param mixed $types Filtered value.
	 * @return string[]
	 */
	private function normalize_type_list( $types ): array {
		if ( ! is_array( $types ) ) {
			return [];
		}
		$normalized = array_map(
			static function ( $type ): string {
				return Util::normalize_schema_type( is_string( $type ) ? $type : '' );
			},
			$types
		);
		return array_values( array_unique( array_filter( $normalized ) ) );
	}
}
