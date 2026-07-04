<?php
namespace SchemaOverrideManager\Integrations;

use SchemaOverrideManager\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Helper for theme/plugin JSON-LD that lives outside known plugin filters.
 * The active output-buffering suppression lives in Suppressor; this class is
 * the canonical HTML → JSON-LD parser used by the live-fetch endpoint and a
 * future-proofing spot for theme-specific integrations.
 */
class ThemeIntegration {

	/**
	 * Parse every <script type="application/ld+json"> block out of an HTML
	 * document and classify each typed node as ours or other (theme / another
	 * plugin). Ours carry the OUTPUT_ATTR tag attribute (1.0.1+) or, for pages
	 * cached before the attribute change, the legacy 1.0.0 payload marker.
	 *
	 * @graph wrappers are flattened so each typed node surfaces individually.
	 *
	 * @param string $html   Full page HTML.
	 * @param string $marker Legacy marker comment identifying 1.0.0 output.
	 * @return array{ours: array[], other: array[]} Each entry: [ 'type' => string, 'data' => array ].
	 */
	public static function classify_json_ld_blocks( string $html, string $marker ): array {
		$ours  = [];
		$other = [];

		preg_match_all(
			'#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$html,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			$tag     = $match[0];
			$raw     = $match[1];
			$is_ours = ( false !== strpos( $tag, \SchemaOverrideManager\SchemaOutput::OUTPUT_ATTR ) )
				|| ( false !== strpos( $raw, $marker ) );
			$decoded = json_decode( trim( str_replace( $marker, '', $raw ) ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$nodes = Util::flatten_json_ld( $decoded );

			foreach ( $nodes as $node ) {
				$type  = Util::normalize_schema_type( $node['@type'] ?? '' );
				$entry = [
					'type' => '' !== $type ? $type : '(untyped)',
					'data' => $node,
				];
				if ( $is_ours ) {
					$ours[] = $entry;
				} else {
					$other[] = $entry;
				}
			}
		}

		return [
			'ours'  => $ours,
			'other' => $other,
		];
	}
}
