<?php
namespace SchemaOverrideManager\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Helper for theme/plugin JSON-LD that lives outside known plugin filters.
 * The active output-buffering suppression lives in Suppressor; this class is
 * just the static parser used by the live-fetch endpoint and a future-proofing
 * spot for theme-specific integrations.
 */
class ThemeIntegration {

	/**
	 * Parse all <script type="application/ld+json"> blocks out of an HTML document.
	 * Used by SchemaDetector / RestController for the live-fetch path.
	 *
	 * @param string $html
	 * @return array[] Each entry: [ 'type' => string, 'data' => array ]
	 */
	public static function parse_json_ld_from_html( string $html ): array {
		$blocks = [];
		preg_match_all(
			'#<script\s+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$html,
			$matches
		);

		foreach ( $matches[1] as $json_string ) {
			$decoded = json_decode( trim( $json_string ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$type = $decoded['@type'] ?? null;
			if ( $type ) {
				$blocks[] = [
					'type' => $type,
					'data' => $decoded,
				];
			}
		}

		return $blocks;
	}
}
