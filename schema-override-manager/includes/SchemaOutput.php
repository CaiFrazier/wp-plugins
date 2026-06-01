<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

class SchemaOutput {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function init(): void {
		$config   = $this->settings->get_settings();
		$priority = (int) ( $config['output_priority'] ?? 5 );
		add_action( 'wp_head', [ $this, 'output_schema' ], $priority );
	}

	/**
	 * Marker comment placed inside our <script> tag so the theme-suppression
	 * output buffer can identify and preserve our own JSON-LD.
	 */
	const OUTPUT_MARKER = '/* som-output */';

	public function output_schema(): void {
		$post_id = $this->get_current_post_id();
		$blocks  = $this->collect_schema_blocks( $post_id );

		if ( empty( $blocks ) ) {
			return;
		}

		// JSON_HEX_TAG escapes < and > to < / > so a stored value like
		// "name": "foo</script><script>alert(1)" cannot break out of our <script> tag.
		// JSON parsers consume the unicode escapes correctly, so this is safe to apply
		// unconditionally and is the standard hardening for inline JSON payloads.
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$flags |= JSON_PRETTY_PRINT;
		}

		if ( count( $blocks ) === 1 ) {
			$json = wp_json_encode( reset( $blocks ), $flags );
		} else {
			$graph = [
				'@context' => 'https://schema.org',
				'@graph'   => array_values( $blocks ),
			];
			$json  = wp_json_encode( $graph, $flags );
		}

		if ( $json ) {
			echo '<script type="application/ld+json">' . self::OUTPUT_MARKER . $json . '</script>' . "\n";
			Logger::instance()->debug( 'output', 'Emitted JSON-LD', [
				'block_count' => count( $blocks ),
				'types'       => array_keys( $blocks ),
				'post_id'     => $post_id,
				'bytes'       => strlen( $json ),
			] );
		}
	}

	/**
	 * Collect all schema blocks for the current request, merging layers.
	 *
	 * @return array<string, array> Keyed by @type slug.
	 */
	private function collect_schema_blocks( ?int $post_id ): array {
		$blocks = [];

		// Layer 1: global / site identity.
		// Each block may carry an internal `_som_scope` field controlling where it emits:
		//   'all'      (default) — every page (matches Yoast Organization/WebSite norms)
		//   'home'     — only on the home / front page
		//   'singular' — only on singular posts/pages
		// The flag has no UI yet but is honored by SchemaOutput so a future toggle
		// requires no storage migration.
		$global = $this->settings->get_global_schema();
		foreach ( $global as $block ) {
			$type = Util::normalize_schema_type( $block['@type'] ?? '' );
			if ( '' === $type ) {
				continue;
			}
			if ( ! $this->scope_matches( $block['_som_scope'] ?? 'all', $post_id ) ) {
				continue;
			}
			unset( $block['_som_scope'] );
			$block['@type']  = $type;
			$blocks[ $type ] = $block;
		}

		// Layer 2: CPT template (if on a singular post). Templates are inherently
		// singular-scoped, no scope check needed.
		if ( $post_id ) {
			$post_type = get_post_type( $post_id );
			if ( $post_type ) {
				$template = $this->settings->get_template_schema( $post_type );
				foreach ( $template as $block ) {
					$type = Util::normalize_schema_type( $block['@type'] ?? '' );
					if ( '' === $type ) {
						continue;
					}
					unset( $block['_som_scope'] );
					$block['@type'] = $type;
					$blocks[ $type ] = isset( $blocks[ $type ] )
						? $this->deep_merge( $blocks[ $type ], $block )
						: $block;
				}
			}
		}

		// Layer 3: per-page overrides — already scoped to this singular page.
		if ( $post_id ) {
			$page_blocks = $this->settings->get_page_schema( $post_id );
			foreach ( $page_blocks as $block ) {
				$type = Util::normalize_schema_type( $block['@type'] ?? '' );
				if ( '' === $type ) {
					continue;
				}
				$mode = $block['_som_mode'] ?? 'extend';
				unset( $block['_som_mode'], $block['_som_scope'] );
				$block['@type'] = $type;

				if ( 'replace' === $mode ) {
					$blocks[ $type ] = $block;
				} else {
					$blocks[ $type ] = isset( $blocks[ $type ] )
						? $this->deep_merge( $blocks[ $type ], $block )
						: $block;
				}
			}
		}

		// Ensure @context on standalone blocks.
		if ( count( $blocks ) === 1 ) {
			$type            = array_key_first( $blocks );
			$blocks[ $type ] = array_merge( [ '@context' => 'https://schema.org' ], $blocks[ $type ] );
		} else {
			foreach ( $blocks as $type => $block ) {
				unset( $blocks[ $type ]['@context'] );
			}
		}

		return $blocks;
	}

	private function get_current_post_id(): ?int {
		if ( is_singular() ) {
			return (int) get_queried_object_id() ?: null;
		}
		return null;
	}

	private function scope_matches( string $scope, ?int $post_id ): bool {
		switch ( $scope ) {
			case 'home':
				return is_front_page() || is_home();
			case 'singular':
				return null !== $post_id;
			case 'all':
			default:
				return true;
		}
	}

	private function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Public helper for the REST preview endpoint.
	 */
	public function compute_preview( int $post_id ): array {
		return $this->collect_schema_blocks( $post_id );
	}
}
