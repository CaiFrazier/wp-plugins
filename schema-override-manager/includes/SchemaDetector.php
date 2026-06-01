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
		$detected['rank_math'] = $this->detect_rank_math( $post_id );

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
					return array_values( array_map(
						static function ( $node ) {
							return [
								'type' => Util::normalize_schema_type( $node['@type'] ?? '' ),
								'data' => $node,
							];
						},
						$schema['@graph']
					) );
				}
			} catch ( \Throwable $e ) {
				// Fall through to filter-based best-effort below.
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

	private function detect_rank_math( int $post_id ): array {
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
	 * Returns schema type slugs detectable from Yoast for use in suppression checkboxes.
	 */
	public function get_yoast_types(): array {
		if ( ! class_exists( 'WPSEO_Options' ) && ! defined( 'WPSEO_VERSION' ) ) {
			return [];
		}

		// Common Yoast graph node types.
		return [
			'WebSite',
			'WebPage',
			'Article',
			'BreadcrumbList',
			'Person',
			'Organization',
		];
	}

	/**
	 * Returns schema type slugs detectable from Rank Math for use in suppression checkboxes.
	 */
	public function get_rank_math_types(): array {
		if ( ! class_exists( 'RankMath' ) && ! function_exists( 'rank_math' ) ) {
			return [];
		}

		return [
			'WebSite',
			'WebPage',
			'Article',
			'BreadcrumbList',
			'Person',
			'Organization',
			'Product',
			'FAQPage',
		];
	}
}
