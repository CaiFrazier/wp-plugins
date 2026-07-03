<?php

namespace SchemaOverrideManager\Tests;

use PHPUnit\Framework\TestCase;
use SchemaOverrideManager\Settings;

final class SettingsTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		som_test_reset_state();
		$this->settings = new Settings();
	}

	// -------------------------------------------------------------------------
	// Defaults + corrupted-option fallbacks
	// -------------------------------------------------------------------------

	public function test_get_settings_returns_documented_defaults(): void {
		$out = $this->settings->get_settings();

		self::assertSame( [ 'post', 'page' ], $out['enabled_post_types'] );
		self::assertSame( 5, $out['output_priority'] );
		self::assertFalse( $out['theme_suppression_ob'] );
	}

	public function test_non_array_option_value_falls_back_to_default(): void {
		$GLOBALS['som_test_options']['som_global_schema'] = 'corrupted-string';

		self::assertSame( [], $this->settings->get_global_schema() );
	}

	// -------------------------------------------------------------------------
	// Settings sanitization
	// -------------------------------------------------------------------------

	public function test_save_settings_casts_and_whitelists(): void {
		$this->settings->save_settings( [
			'enabled_post_types'   => [ 'post', 'My CPT!' ],
			'output_priority'      => '7',
			'theme_suppression_ob' => '1',
			'unknown_key'          => 'dropped',
		] );

		$stored = $GLOBALS['som_test_options']['som_settings'];

		self::assertSame( [ 'post', 'mycpt' ], $stored['enabled_post_types'] );
		self::assertSame( 7, $stored['output_priority'] );
		self::assertTrue( $stored['theme_suppression_ob'] );
		self::assertArrayNotHasKey( 'unknown_key', $stored );
	}

	// -------------------------------------------------------------------------
	// Suppression sanitization (SOM-P1-013: write-time @type normalization)
	// -------------------------------------------------------------------------

	public function test_suppression_types_are_normalized_deduped_at_save(): void {
		$this->settings->save_global_suppression( [
			'yoast_all'   => 1,
			'yoast_types' => [
				'https://schema.org/Article',
				'Article',
				'schema:Person',
				'<b>Organization</b>',
				'',
			],
		] );

		$stored = $GLOBALS['som_test_options']['som_global_suppression'];

		self::assertTrue( $stored['yoast_all'] );
		self::assertSame( [ 'Article', 'Person', 'Organization' ], $stored['yoast_types'] );
	}

	public function test_suppression_drops_unknown_keys(): void {
		$this->settings->save_page_suppression( 5, [
			'yoast_all'  => true,
			'evil_key'   => 'nope',
			'theme_all'  => 0,
		] );

		$stored = $GLOBALS['som_test_postmeta'][5]['_som_suppression'];

		self::assertTrue( $stored['yoast_all'] );
		self::assertFalse( $stored['theme_all'] );
		self::assertArrayNotHasKey( 'evil_key', $stored );
	}

	// -------------------------------------------------------------------------
	// Schema writes route through the strict Sanitizer
	// -------------------------------------------------------------------------

	public function test_save_page_schema_strips_hostile_content(): void {
		$this->settings->save_page_schema( 5, [
			[
				'@type' => 'Article',
				'name'  => 'ok</script><script>alert(1)</script>',
				'url'   => 'javascript:alert(1)',
			],
		] );

		$stored = $GLOBALS['som_test_postmeta'][5]['_som_schema'][0];

		self::assertSame( 'Article', $stored['@type'] );
		self::assertStringNotContainsString( '<script>', $stored['name'] );
		self::assertArrayNotHasKey( 'url', $stored );
	}

	// -------------------------------------------------------------------------
	// Per-request memo cache
	// -------------------------------------------------------------------------

	public function test_getter_memoizes_within_the_instance(): void {
		$GLOBALS['som_test_options']['som_global_schema'] = [ [ '@type' => 'WebSite' ] ];
		$first = $this->settings->get_global_schema();

		// Mutating the backing store is invisible until the cache is invalidated.
		$GLOBALS['som_test_options']['som_global_schema'] = [ [ '@type' => 'Person' ] ];

		self::assertSame( $first, $this->settings->get_global_schema() );
	}

	public function test_save_invalidates_the_memo(): void {
		$GLOBALS['som_test_options']['som_global_schema'] = [ [ '@type' => 'WebSite' ] ];
		$this->settings->get_global_schema();

		$this->settings->save_global_schema( [ [ '@type' => 'Person' ] ] );

		self::assertSame( 'Person', $this->settings->get_global_schema()[0]['@type'] );
	}
}
