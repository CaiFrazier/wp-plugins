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
	 * Attribute placed on our <script> tag so the theme-suppression output
	 * buffer and the live detector can identify and preserve our own JSON-LD.
	 * The marker lives on the tag, NOT inside the payload — a comment inside
	 * the payload makes the JSON-LD invalid for strict parsers (Google's
	 * structured-data parser, crawlers), which rejects the entire block.
	 */
	const OUTPUT_ATTR = 'data-som-output';

	/**
	 * Legacy payload marker emitted by 1.0.0. Detection still honors it so
	 * pages cached with the old output survive the transition; output no
	 * longer uses it (it corrupted the JSON payload).
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
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD payload encoded with JSON_HEX_TAG|JSON_HEX_AMP; HTML-escaping would corrupt it.
			echo '<script type="application/ld+json" ' . self::OUTPUT_ATTR . '="1">' . $json . '</script>' . "\n";
			Logger::instance()->debug(
				'output',
				'Emitted JSON-LD',
				[
					'block_count' => count( $blocks ),
					'types'       => array_keys( $blocks ),
					'post_id'     => $post_id,
					'bytes'       => strlen( $json ),
				]
			);
		}
	}

	/**
	 * Collect all schema blocks for the current request, merging layers.
	 *
	 * Blocks are keyed by @id when present (so multiple nodes of the same type
	 * — two Persons, an Organization plus a sub-brand — coexist), otherwise by
	 * primary @type. A multi-typed @type (array) is preserved in the output;
	 * only the primary (first) type is used for keying.
	 *
	 * @param int|null $post_id Singular post context, or null off singular pages.
	 * @return array<string, array> Keyed by @id or primary @type.
	 */
	private function collect_schema_blocks( ?int $post_id ): array {
		$blocks = [];

		// Layer 1: global / site identity.
		// Each block may carry an internal `_som_scope` field controlling where it emits:
		// 'all'      (default) — every page (matches Yoast Organization/WebSite norms)
		// 'home'     — only on the home / front page
		// 'singular' — only on singular posts/pages
		// The flag has no UI yet but is honored by SchemaOutput so a future toggle
		// requires no storage migration.
		$global = $this->settings->get_global_schema();
		foreach ( $global as $block ) {
			$type = $this->normalize_block_type( $block['@type'] ?? '' );
			if ( null === $type ) {
				continue;
			}
			if ( ! $this->scope_matches( $block['_som_scope'] ?? 'all', $post_id ) ) {
				continue;
			}
			unset( $block['_som_scope'] );
			$block['@type']                              = $type;
			$blocks[ $this->block_key( $type, $block ) ] = $block;
		}

		// Layer 2: CPT template (if on a singular post). Templates are inherently
		// singular-scoped, no scope check needed.
		if ( $post_id ) {
			$post_type = get_post_type( $post_id );
			if ( $post_type ) {
				$template = $this->settings->get_template_schema( $post_type );
				foreach ( $template as $block ) {
					$type = $this->normalize_block_type( $block['@type'] ?? '' );
					if ( null === $type ) {
						continue;
					}
					unset( $block['_som_scope'] );
					$block['@type'] = $type;
					$key            = $this->block_key( $type, $block );
					$blocks[ $key ] = isset( $blocks[ $key ] )
						? $this->deep_merge( $blocks[ $key ], $block )
						: $block;
				}
			}
		}

		// Layer 3: per-page overrides — already scoped to this singular page.
		if ( $post_id ) {
			$page_blocks = $this->settings->get_page_schema( $post_id );
			foreach ( $page_blocks as $block ) {
				$type = $this->normalize_block_type( $block['@type'] ?? '' );
				if ( null === $type ) {
					continue;
				}
				$mode = $block['_som_mode'] ?? 'extend';
				unset( $block['_som_mode'], $block['_som_scope'] );
				$block['@type'] = $type;
				$key            = $this->block_key( $type, $block );

				if ( 'replace' === $mode ) {
					$blocks[ $key ] = $block;
				} else {
					$blocks[ $key ] = isset( $blocks[ $key ] )
						? $this->deep_merge( $blocks[ $key ], $block )
						: $block;
				}
			}
		}

		// Ensure @context on standalone blocks.
		if ( count( $blocks ) === 1 ) {
			$key            = array_key_first( $blocks );
			$blocks[ $key ] = array_merge( [ '@context' => 'https://schema.org' ], $blocks[ $key ] );
		} else {
			foreach ( $blocks as $key => $block ) {
				unset( $blocks[ $key ]['@context'] );
			}
		}

		return $blocks;
	}

	/**
	 * Normalize a block's @type to bare name(s), preserving the string-or-array
	 * shape so a multi-typed node survives to output. Returns null when nothing
	 * usable remains (block should be skipped).
	 *
	 * @param mixed $raw Raw @type (string or array of strings).
	 * @return string|string[]|null
	 */
	private function normalize_block_type( $raw ) {
		if ( is_array( $raw ) ) {
			$types = array_values( array_filter( array_map( [ Util::class, 'normalize_schema_type' ], $raw ) ) );
			if ( empty( $types ) ) {
				return null;
			}
			return count( $types ) === 1 ? $types[0] : $types;
		}
		$type = Util::normalize_schema_type( $raw );
		return '' === $type ? null : $type;
	}

	/**
	 * Merge/dedup key for a block: its @id when present (letting multiple nodes
	 * of the same type coexist), else its primary type.
	 *
	 * @param string|string[] $normalized_type Output of normalize_block_type().
	 * @param array           $block           The block (may carry @id).
	 */
	private function block_key( $normalized_type, array $block ): string {
		$primary = is_array( $normalized_type ) ? ( $normalized_type[0] ?? '' ) : $normalized_type;
		if ( isset( $block['@id'] ) && is_string( $block['@id'] ) && '' !== $block['@id'] ) {
			return $primary . '|' . $block['@id'];
		}
		return $primary;
	}

	private function get_current_post_id(): ?int {
		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			return $post_id > 0 ? $post_id : null;
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

	/**
	 * Recursively merge $override onto $base. Associative arrays (JSON-LD
	 * objects) are merged key-by-key; a list-shaped array (sequential integer
	 * keys, e.g. `sameAs` or an `image` set) is treated as an atomic value and
	 * REPLACED wholesale. Merging lists by index would leave stale tail
	 * elements — extending `sameAs: [a, b, c]` with `[x]` must yield `[x]`, not
	 * `[x, b, c]`.
	 *
	 * @param array $base     Lower-layer schema.
	 * @param array $override Higher-layer schema merged on top.
	 * @return array Merged result.
	 */
	private function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] )
				&& $this->is_assoc( $value ) && $this->is_assoc( $base[ $key ] ) ) {
				$base[ $key ] = $this->deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * True when the array is associative (an object), false for a JSON list
	 * (sequential 0..n-1 integer keys) or an empty array.
	 *
	 * @param array $arr Array to classify.
	 */
	private function is_assoc( array $arr ): bool {
		if ( [] === $arr ) {
			return false;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Public helper for the REST preview endpoint.
	 *
	 * @param int $post_id Post to compute the merged schema for.
	 * @return array<string, array> Merged blocks keyed by @type slug.
	 */
	public function compute_preview( int $post_id ): array {
		return $this->collect_schema_blocks( $post_id );
	}
}
