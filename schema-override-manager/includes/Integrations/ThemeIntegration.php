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
	 * document and classify each typed node as ours (carries $marker) or
	 * other (theme / another plugin). @graph wrappers are flattened so each
	 * typed node surfaces individually.
	 *
	 * @param string $html   Full page HTML.
	 * @param string $marker Marker comment identifying this plugin's output.
	 * @return array{ours: array[], other: array[]} Each entry: [ 'type' => string, 'data' => array ].
	 */
	public static function classify_json_ld_blocks( string $html, string $marker ): array {
		$ours  = [];
		$other = [];

		preg_match_all(
			'#<script\s+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$html,
			$matches
		);

		foreach ( $matches[1] as $raw ) {
			$is_ours = ( false !== strpos( $raw, $marker ) );
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
