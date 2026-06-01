<?php

namespace BulkMetaEditor;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION_KEY        = 'bulk_meta_editor_settings';
	const USER_META_COLUMNS = 'bme_column_visibility';

	/**
	 * Sanitizer strategies a custom column can choose. Each strategy maps to
	 * a different transform on cell values at save time. `textarea` is the
	 * default (matches pre-1.0.0 behavior).
	 *
	 *   text     — single-line strings (sanitize_text_field); collapses whitespace, strips tags
	 *   textarea — multi-line strings (sanitize_textarea_field); strips tags, keeps newlines
	 *   html     — safe HTML (wp_kses_post); allows links/formatting, blocks script/style
	 *   url      — URLs only (esc_url_raw); empty string if input isn't a valid URL
	 *   number   — numeric strings; non-digit chars stripped, leading +/- kept
	 *   raw      — NO sanitization. Use for JSON blobs / structured data. The
	 *              64 KB cap still applies. Power users only.
	 */
	const SANITIZERS        = [ 'text', 'textarea', 'html', 'url', 'number', 'raw' ];
	const DEFAULT_SANITIZER = 'textarea';

	private ?array $post_types_cache = null;
	private ?array $settings_cache   = null;

	const PRESETS = [
		'yoast'        => [
			'title' => '_yoast_wpseo_title',
			'desc'  => '_yoast_wpseo_metadesc',
		],
		'rankmath'     => [
			'title' => 'rank_math_title',
			'desc'  => 'rank_math_description',
		],
		'aioseo'       => [
			'title' => '_aioseo_title',
			'desc'  => '_aioseo_description',
		],
		'seoframework' => [
			'title' => '_genesis_title',
			'desc'  => '_genesis_description',
		],
	];

	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_settings(): void {
		register_setting(
			'bulk_meta_editor',
			self::OPTION_KEY,
			[
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => $this->defaults(),
			]
		);
	}

	public function defaults(): array {
		return [
			'meta_title_key'   => '',
			'meta_desc_key'    => '',
			'custom_columns'   => [],
			'enabled_types'    => array_keys( $this->get_public_post_types() ),
			'default_per_page' => 50,
			// Off by default. Enable from Settings → Diagnostic Logging, or hard-on
			// via define( 'BME_DEBUG', true ) in wp-config.php.
			'debug_mode'       => false,
			'log_level'        => 'warn',
		];
	}

	public function get(): array {
		if ( null !== $this->settings_cache ) {
			return $this->settings_cache;
		}
		$saved                = get_option( self::OPTION_KEY, [] );
		$this->settings_cache = array_merge( $this->defaults(), is_array( $saved ) ? $saved : [] );
		return $this->settings_cache;
	}

	public function update( array $data ): bool {
		$ok = update_option( self::OPTION_KEY, $this->sanitize( $data ) );
		// Invalidate caches so subsequent get() reflects the new value.
		$this->settings_cache   = null;
		$this->post_types_cache = null;
		return $ok;
	}

	public function sanitize( $data ): array {
		if ( ! is_array( $data ) ) {
			return $this->defaults();
		}

		$clean = $this->defaults();

		if ( isset( $data['meta_title_key'] ) ) {
			$clean['meta_title_key'] = $this->sanitize_meta_key( $data['meta_title_key'] );
		}
		if ( isset( $data['meta_desc_key'] ) ) {
			$clean['meta_desc_key'] = $this->sanitize_meta_key( $data['meta_desc_key'] );
		}
		if ( isset( $data['custom_columns'] ) && is_array( $data['custom_columns'] ) ) {
			$cols = [];
			foreach ( $data['custom_columns'] as $col ) {
				$key = isset( $col['key'] ) ? $this->sanitize_meta_key( $col['key'] ) : '';
				if ( '' === $key ) {
					continue;
				}
				$sanitizer = isset( $col['sanitizer'] ) && in_array( $col['sanitizer'], self::SANITIZERS, true )
					? $col['sanitizer']
					: self::DEFAULT_SANITIZER;
				$cols[]    = [
					'key'       => $key,
					'label'     => sanitize_text_field( $col['label'] ?? $key ),
					'sanitizer' => $sanitizer,
				];
			}
			$clean['custom_columns'] = $cols;
		}
		if ( isset( $data['enabled_types'] ) && is_array( $data['enabled_types'] ) ) {
			$valid_types            = array_keys( $this->get_public_post_types() );
			$enabled                = array_filter( $data['enabled_types'], 'is_string' );
			$clean['enabled_types'] = array_values( array_intersect( $enabled, $valid_types ) );
		}
		if ( isset( $data['default_per_page'] ) ) {
			// Ceiling matches PostDataService::get_posts_for_picker(): keep them
			// aligned so a value the editor picks here is the value the picker
			// actually honours. 200 is a sensible UX cap — anything larger
			// makes a single picker page sluggish on shared hosting.
			$clean['default_per_page'] = max( 10, min( 200, (int) $data['default_per_page'] ) );
		}
		if ( array_key_exists( 'debug_mode', $data ) ) {
			$clean['debug_mode'] = (bool) $data['debug_mode'];
		}
		if ( isset( $data['log_level'] ) && in_array( $data['log_level'], [ 'debug', 'info', 'warn', 'error' ], true ) ) {
			$clean['log_level'] = $data['log_level'];
		}

		return $clean;
	}

	/**
	 * Validate a postmeta key without lowercasing it. Allows the same character
	 * set WordPress allows (alphanumeric, underscore, hyphen, colon, period).
	 */
	public function sanitize_meta_key( $key ): string {
		$key = is_string( $key ) ? trim( $key ) : '';
		if ( '' === $key ) {
			return '';
		}
		// Allow underscores (Yoast etc.), hyphens, colons, periods, alphanumerics.
		if ( ! preg_match( '/^[A-Za-z0-9_\-:.]+$/', $key ) ) {
			return '';
		}
		return $key;
	}

	public function get_public_post_types(): array {
		if ( null !== $this->post_types_cache ) {
			return $this->post_types_cache;
		}
		$types = get_post_types( [ 'public' => true ], 'objects' );
		$out   = [];
		foreach ( $types as $type ) {
			$out[ $type->name ] = $type->label;
		}
		$this->post_types_cache = $out;
		return $out;
	}

	public function get_allowed_meta_keys(): array {
		$settings = $this->get();
		$keys     = [];
		if ( ! empty( $settings['meta_title_key'] ) ) {
			$keys[] = $settings['meta_title_key'];
		}
		if ( ! empty( $settings['meta_desc_key'] ) ) {
			$keys[] = $settings['meta_desc_key'];
		}
		foreach ( $settings['custom_columns'] as $col ) {
			if ( ! empty( $col['key'] ) ) {
				$keys[] = $col['key'];
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Map column key (e.g. "meta_title", "custom__sku") to its underlying
	 * postmeta key. Returns null if the column key is not allowed.
	 */
	public function column_to_meta_key( string $column_key ): ?string {
		$settings = $this->get();
		if ( 'meta_title' === $column_key && $settings['meta_title_key'] ) {
			return $settings['meta_title_key'];
		}
		if ( 'meta_desc' === $column_key && $settings['meta_desc_key'] ) {
			return $settings['meta_desc_key'];
		}
		if ( 0 === strpos( $column_key, 'custom__' ) ) {
			$meta_key = substr( $column_key, strlen( 'custom__' ) );
			foreach ( $settings['custom_columns'] as $col ) {
				if ( $col['key'] === $meta_key ) {
					return $meta_key;
				}
			}
		}
		return null;
	}

	/**
	 * Resolve the per-column sanitizer strategy. Built-in title/description
	 * columns always use 'textarea' (their content is plain text for SEO
	 * meta tags). Custom columns honor the user-chosen sanitizer; legacy
	 * configs without a sanitizer field default to 'textarea'.
	 *
	 * Returns DEFAULT_SANITIZER for unknown column keys so the caller can
	 * still proceed safely if the allow-list check has already passed.
	 */
	public function column_to_sanitizer( string $column_key ): string {
		if ( 'meta_title' === $column_key || 'meta_desc' === $column_key ) {
			return 'textarea';
		}
		if ( 0 === strpos( $column_key, 'custom__' ) ) {
			$meta_key = substr( $column_key, strlen( 'custom__' ) );
			foreach ( $this->get()['custom_columns'] as $col ) {
				if ( $col['key'] === $meta_key ) {
					return $col['sanitizer'] ?? self::DEFAULT_SANITIZER;
				}
			}
		}
		return self::DEFAULT_SANITIZER;
	}

	public function get_column_visibility( int $user_id ): array {
		$saved = get_user_meta( $user_id, self::USER_META_COLUMNS, true );
		return is_array( $saved ) ? $saved : [];
	}

	public function set_column_visibility( int $user_id, array $visibility ): void {
		$clean = [];
		foreach ( $visibility as $key => $val ) {
			if ( is_string( $key ) && '' !== $key ) {
				$clean[ sanitize_text_field( $key ) ] = (bool) $val;
			}
		}
		update_user_meta( $user_id, self::USER_META_COLUMNS, $clean );
	}
}
