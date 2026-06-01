<?php

namespace BulkMetaEditor\Tests;

use BulkMetaEditor\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		bme_test_reset_state();
		// Two public types registered by default. Custom types tests override.
		$GLOBALS['bme_test_post_types'] = [
			'post' => (object) [ 'name' => 'post', 'label' => 'Posts', 'public' => true ],
			'page' => (object) [ 'name' => 'page', 'label' => 'Pages', 'public' => true ],
		];
	}

	public function test_sanitize_returns_defaults_when_input_is_not_array(): void {
		$settings = new Settings();
		$out      = $settings->sanitize( 'not-an-array' );

		self::assertSame( '', $out['meta_title_key'] );
		self::assertSame( '', $out['meta_desc_key'] );
		self::assertSame( [], $out['custom_columns'] );
		self::assertSame( 50, $out['default_per_page'] );
		self::assertFalse( $out['debug_mode'] );
		self::assertSame( 'warn', $out['log_level'] );
	}

	public function test_sanitize_accepts_valid_meta_keys(): void {
		$settings = new Settings();
		$out      = $settings->sanitize( [
			'meta_title_key' => '_yoast_wpseo_title',
			'meta_desc_key'  => 'rank_math_description',
		] );

		self::assertSame( '_yoast_wpseo_title', $out['meta_title_key'] );
		self::assertSame( 'rank_math_description', $out['meta_desc_key'] );
	}

	public function test_sanitize_rejects_meta_keys_with_disallowed_characters(): void {
		$settings = new Settings();
		$out      = $settings->sanitize( [
			'meta_title_key' => 'evil key with spaces',
			'meta_desc_key'  => 'has/slash',
		] );

		self::assertSame( '', $out['meta_title_key'] );
		self::assertSame( '', $out['meta_desc_key'] );
	}

	public function test_sanitize_meta_key_allows_colons_and_periods_for_namespaced_keys(): void {
		// e.g. some plugins emit keys like `acf.field_xyz` or `foo:bar`.
		$settings = new Settings();
		self::assertSame( 'acf.field_xyz', $settings->sanitize_meta_key( 'acf.field_xyz' ) );
		self::assertSame( 'foo:bar', $settings->sanitize_meta_key( 'foo:bar' ) );
	}

	public function test_sanitize_drops_custom_columns_with_empty_keys(): void {
		$settings = new Settings();
		$out      = $settings->sanitize( [
			'custom_columns' => [
				[ 'key' => '_sku', 'label' => 'SKU' ],
				[ 'key' => '', 'label' => 'No key' ],
				[ 'key' => 'bad key!', 'label' => 'Invalid' ],
				[ 'key' => '_price', 'label' => 'Price' ],
			],
		] );

		self::assertCount( 2, $out['custom_columns'] );
		self::assertSame( '_sku', $out['custom_columns'][0]['key'] );
		self::assertSame( '_price', $out['custom_columns'][1]['key'] );
	}

	public function test_sanitize_intersects_enabled_types_with_registered_public_types(): void {
		$settings = new Settings();
		$out      = $settings->sanitize( [
			'enabled_types' => [ 'post', 'fake_type', 'page', 42 /* non-string */ ],
		] );

		self::assertSame( [ 'post', 'page' ], $out['enabled_types'] );
	}

	public function test_sanitize_clamps_default_per_page_to_legal_range(): void {
		$settings = new Settings();

		self::assertSame( 10,  $settings->sanitize( [ 'default_per_page' => -5 ] )['default_per_page'] );
		self::assertSame( 10,  $settings->sanitize( [ 'default_per_page' => 0 ] )['default_per_page'] );
		self::assertSame( 50,  $settings->sanitize( [ 'default_per_page' => 50 ] )['default_per_page'] );
		// Ceiling lowered from 500 → 200 in 1.0.1 to match the picker clamp.
		self::assertSame( 200, $settings->sanitize( [ 'default_per_page' => 99999 ] )['default_per_page'] );
	}

	public function test_sanitize_rejects_unknown_log_levels(): void {
		$settings = new Settings();
		$out      = $settings->sanitize( [ 'log_level' => 'TRACE' ] );

		self::assertSame( 'warn', $out['log_level'] );
	}

	public function test_column_to_meta_key_maps_built_in_aliases_when_configured(): void {
		$settings = new Settings();
		$settings->update( [
			'meta_title_key' => '_yoast_wpseo_title',
			'meta_desc_key'  => '_yoast_wpseo_metadesc',
		] );

		self::assertSame( '_yoast_wpseo_title', $settings->column_to_meta_key( 'meta_title' ) );
		self::assertSame( '_yoast_wpseo_metadesc', $settings->column_to_meta_key( 'meta_desc' ) );
	}

	public function test_column_to_meta_key_rejects_columns_outside_the_allowlist(): void {
		$settings = new Settings();
		$settings->update( [
			'custom_columns' => [ [ 'key' => '_sku', 'label' => 'SKU' ] ],
		] );

		// In the allow-list:
		self::assertSame( '_sku', $settings->column_to_meta_key( 'custom___sku' ) );
		// Not in the allow-list — this is the security gate:
		self::assertNull( $settings->column_to_meta_key( 'custom___somekey' ) );
		self::assertNull( $settings->column_to_meta_key( 'meta_title' ) );
		self::assertNull( $settings->column_to_meta_key( 'random_garbage' ) );
	}

	// -------------------------------------------------------------------------
	// Per-column sanitizer field (BME-P1-007)
	// -------------------------------------------------------------------------

	public function test_sanitize_accepts_valid_sanitizer_strategies(): void {
		$settings = new Settings();
		$out      = $settings->sanitize( [
			'custom_columns' => [
				[ 'key' => '_sku',   'label' => 'SKU',   'sanitizer' => 'text' ],
				[ 'key' => '_html',  'label' => 'Body',  'sanitizer' => 'html' ],
				[ 'key' => '_link',  'label' => 'Link',  'sanitizer' => 'url' ],
				[ 'key' => '_qty',   'label' => 'Qty',   'sanitizer' => 'number' ],
				[ 'key' => '_blob',  'label' => 'Blob',  'sanitizer' => 'raw' ],
			],
		] );

		self::assertSame( 'text',     $out['custom_columns'][0]['sanitizer'] );
		self::assertSame( 'html',     $out['custom_columns'][1]['sanitizer'] );
		self::assertSame( 'url',      $out['custom_columns'][2]['sanitizer'] );
		self::assertSame( 'number',   $out['custom_columns'][3]['sanitizer'] );
		self::assertSame( 'raw',      $out['custom_columns'][4]['sanitizer'] );
	}

	public function test_sanitize_defaults_unknown_sanitizer_to_textarea(): void {
		$settings = new Settings();
		$out      = $settings->sanitize( [
			'custom_columns' => [
				[ 'key' => '_a', 'label' => 'A', 'sanitizer' => 'invalid_value' ],
				[ 'key' => '_b', 'label' => 'B' /* no sanitizer field */ ],
			],
		] );

		self::assertSame( 'textarea', $out['custom_columns'][0]['sanitizer'] );
		self::assertSame( 'textarea', $out['custom_columns'][1]['sanitizer'] );
	}

	public function test_column_to_sanitizer_resolves_per_column_strategy(): void {
		$settings = new Settings();
		$settings->update( [
			'custom_columns' => [
				[ 'key' => '_html', 'label' => 'Body', 'sanitizer' => 'html' ],
				[ 'key' => '_url',  'label' => 'Link', 'sanitizer' => 'url' ],
			],
		] );

		self::assertSame( 'html', $settings->column_to_sanitizer( 'custom___html' ) );
		self::assertSame( 'url',  $settings->column_to_sanitizer( 'custom___url' ) );
	}

	public function test_column_to_sanitizer_always_returns_textarea_for_built_in_columns(): void {
		// Built-ins must not honor a per-column sanitizer setting — they're
		// fixed at textarea because SEO meta values are plain text.
		$settings = new Settings();
		self::assertSame( 'textarea', $settings->column_to_sanitizer( 'meta_title' ) );
		self::assertSame( 'textarea', $settings->column_to_sanitizer( 'meta_desc' ) );
	}

	public function test_column_to_sanitizer_defaults_to_textarea_for_unknown_column_keys(): void {
		$settings = new Settings();
		self::assertSame( 'textarea', $settings->column_to_sanitizer( 'custom___not_configured' ) );
		self::assertSame( 'textarea', $settings->column_to_sanitizer( 'garbage_input' ) );
	}

	public function test_get_allowed_meta_keys_dedupes_across_sources(): void {
		$settings = new Settings();
		$settings->update( [
			'meta_title_key' => '_seo_title',
			'meta_desc_key'  => '_seo_desc',
			'custom_columns' => [
				[ 'key' => '_seo_title', 'label' => 'Duplicate' ],
				[ 'key' => '_sku',       'label' => 'SKU' ],
			],
		] );

		$keys = $settings->get_allowed_meta_keys();
		sort( $keys );
		self::assertSame( [ '_seo_desc', '_seo_title', '_sku' ], $keys );
	}
}
