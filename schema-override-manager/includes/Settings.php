<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

class Settings {

	/**
	 * Per-instance memo cache. SchemaOutput + Suppressor both call several
	 * getters during a single request — caching here saves the repeat
	 * get_option / get_post_meta hits without depending on a persistent cache.
	 *
	 * @var array<string, mixed>
	 */
	private array $cache = [];

	public function get_global_schema(): array {
		return $this->cached( 'global_schema', function () {
			return $this->get_option( 'som_global_schema', [] );
		} );
	}

	public function save_global_schema( array $data ): bool {
		unset( $this->cache['global_schema'] );
		return update_option( 'som_global_schema', $this->sanitize_schema_array( $data ) );
	}

	public function get_global_suppression(): array {
		return $this->cached( 'global_suppression', function () {
			return $this->get_option( 'som_global_suppression', [] );
		} );
	}

	public function save_global_suppression( array $data ): bool {
		unset( $this->cache['global_suppression'] );
		return update_option( 'som_global_suppression', $this->sanitize_suppression( $data ) );
	}

	public function get_template_schema( string $post_type ): array {
		$slug = sanitize_key( $post_type );
		return $this->cached( "template_{$slug}", function () use ( $slug ) {
			return $this->get_option( 'som_template_' . $slug, [] );
		} );
	}

	public function save_template_schema( string $post_type, array $data ): bool {
		$slug = sanitize_key( $post_type );
		unset( $this->cache[ "template_{$slug}" ] );
		return update_option(
			'som_template_' . $slug,
			$this->sanitize_schema_array( $data )
		);
	}

	public function get_settings(): array {
		return $this->cached( 'settings', function () {
			return $this->get_option( 'som_settings', [
				'enabled_post_types'   => [ 'post', 'page' ],
				'output_priority'      => 5,
				'theme_suppression_ob' => false,
			] );
		} );
	}

	public function save_settings( array $data ): bool {
		unset( $this->cache['settings'] );
		$clean = [
			'enabled_post_types'   => array_map( 'sanitize_key', (array) ( $data['enabled_post_types'] ?? [] ) ),
			'output_priority'      => (int) ( $data['output_priority'] ?? 5 ),
			'theme_suppression_ob' => (bool) ( $data['theme_suppression_ob'] ?? false ),
		];
		return update_option( 'som_settings', $clean );
	}

	public function get_page_schema( int $post_id ): array {
		return $this->cached( "page_schema_{$post_id}", function () use ( $post_id ) {
			$raw = get_post_meta( $post_id, '_som_schema', true );
			return is_array( $raw ) ? $raw : [];
		} );
	}

	public function save_page_schema( int $post_id, array $data ): void {
		unset( $this->cache[ "page_schema_{$post_id}" ] );
		update_post_meta( $post_id, '_som_schema', $this->sanitize_schema_array( $data ) );
	}

	public function get_page_suppression( int $post_id ): array {
		return $this->cached( "page_suppression_{$post_id}", function () use ( $post_id ) {
			$raw = get_post_meta( $post_id, '_som_suppression', true );
			return is_array( $raw ) ? $raw : [];
		} );
	}

	public function save_page_suppression( int $post_id, array $data ): void {
		unset( $this->cache[ "page_suppression_{$post_id}" ] );
		update_post_meta( $post_id, '_som_suppression', $this->sanitize_suppression( $data ) );
	}

	private function cached( string $key, callable $producer ) {
		if ( ! array_key_exists( $key, $this->cache ) ) {
			$this->cache[ $key ] = $producer();
		}
		return $this->cache[ $key ];
	}

	private function get_option( string $key, $default = [] ) {
		$value = get_option( $key, $default );
		return is_array( $value ) ? $value : $default;
	}

	/**
	 * Strict deep-sanitize for schema payloads. Delegates to {@see Sanitizer}
	 * so the same depth / node-count / string-length / URL / @type rules
	 * apply at every write site (global option, template option, postmeta).
	 *
	 * The previous implementation here was a wp_json_encode → json_decode
	 * round-trip — that's a no-op for sanitization and let HTML, malformed
	 * URLs, and oversized values through to the rendered <script> tag.
	 */
	private function sanitize_schema_array( array $data ): array {
		return Sanitizer::sanitize_schema( $data );
	}

	private function sanitize_suppression( array $data ): array {
		$allowed_keys = [
			'yoast_all', 'yoast_types',
			'rank_math_all', 'rank_math_types',
			'theme_all',
		];

		$clean = [];
		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}
			if ( in_array( $key, [ 'yoast_all', 'rank_math_all', 'theme_all' ], true ) ) {
				$clean[ $key ] = (bool) $data[ $key ];
			} else {
				$clean[ $key ] = array_map( 'sanitize_text_field', (array) $data[ $key ] );
			}
		}
		return $clean;
	}
}
