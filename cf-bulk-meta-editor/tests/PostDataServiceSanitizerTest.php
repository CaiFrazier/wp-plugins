<?php

namespace BulkMetaEditor\Tests;

use BulkMetaEditor\PostDataService;
use BulkMetaEditor\Settings;
use CFShared\Logger as SharedLogger;
use PHPUnit\Framework\TestCase;

/**
 * Per-column sanitizer dispatch (BME-P1-007). Each custom column carries a
 * sanitizer strategy; save_batch must route the value through the matching
 * transform. Built-in columns (meta_title, meta_desc) always use 'textarea'
 * to preserve pre-1.0.0 behavior.
 */
final class PostDataServiceSanitizerTest extends TestCase {

	private Settings $settings;
	private PostDataService $service;

	protected function setUp(): void {
		bme_test_reset_state();
		$GLOBALS['bme_test_post_types'] = [
			'post' => (object) [ 'name' => 'post', 'label' => 'Posts', 'public' => true ],
		];

		$this->settings = new Settings();
		// One column per strategy so each test can write to its own key.
		$this->settings->update( [
			'custom_columns' => [
				[ 'key' => '_c_text',     'label' => 'T',  'sanitizer' => 'text' ],
				[ 'key' => '_c_textarea', 'label' => 'TA', 'sanitizer' => 'textarea' ],
				[ 'key' => '_c_html',     'label' => 'H',  'sanitizer' => 'html' ],
				[ 'key' => '_c_url',      'label' => 'U',  'sanitizer' => 'url' ],
				[ 'key' => '_c_number',   'label' => 'N',  'sanitizer' => 'number' ],
				[ 'key' => '_c_raw',      'label' => 'R',  'sanitizer' => 'raw' ],
			],
		] );

		$logger = SharedLogger::for_plugin( [
			'slug'               => 'cf-bulk-meta-editor',
			'threshold_resolver' => static fn() => SharedLogger::LEVEL_ERROR,
		] );
		$this->service = new PostDataService( $this->settings, $logger );
	}

	private function save_one( string $col_key, string $value ): array {
		return $this->service->save_batch( [ [ 'id' => 1, 'key' => $col_key, 'value' => $value ] ] );
	}

	// -------------------------------------------------------------------------
	// Per-strategy round-trips
	// -------------------------------------------------------------------------

	public function test_text_sanitizer_strips_tags_and_collapses_whitespace(): void {
		$this->save_one( 'custom___c_text', "  hello   <b>world</b>\nsecond line  " );
		// sanitize_text_field shim collapses newlines/tabs to space + strips tags.
		self::assertSame( 'hello   world second line', $GLOBALS['bme_test_postmeta'][1]['_c_text'] );
	}

	public function test_textarea_sanitizer_strips_tags_but_preserves_newlines(): void {
		$this->save_one( 'custom___c_textarea', "line one\n<b>line two</b>\nline three" );
		// strip_tags keeps inner text; newlines preserved.
		self::assertSame( "line one\nline two\nline three", $GLOBALS['bme_test_postmeta'][1]['_c_textarea'] );
	}

	public function test_html_sanitizer_keeps_safe_tags_strips_script(): void {
		$input = '<p>Hello <a href="https://example.com">link</a></p><script>alert(1)</script>';
		$this->save_one( 'custom___c_html', $input );
		$stored = $GLOBALS['bme_test_postmeta'][1]['_c_html'];

		self::assertStringContainsString( '<p>Hello', $stored );
		self::assertStringContainsString( '<a href="https://example.com">link</a>', $stored );
		self::assertStringNotContainsString( '<script', $stored );
		self::assertStringNotContainsString( 'alert(1)', $stored );
	}

	public function test_html_sanitizer_strips_inline_event_handlers(): void {
		$this->save_one( 'custom___c_html', '<a href="https://x.test" onclick="alert(1)">x</a>' );
		$stored = $GLOBALS['bme_test_postmeta'][1]['_c_html'];

		self::assertStringNotContainsString( 'onclick', $stored );
	}

	public function test_url_sanitizer_keeps_valid_http_urls(): void {
		$this->save_one( 'custom___c_url', '  https://example.com/path?q=1  ' );
		self::assertSame( 'https://example.com/path?q=1', $GLOBALS['bme_test_postmeta'][1]['_c_url'] );
	}

	public function test_url_sanitizer_blocks_javascript_scheme(): void {
		$this->save_one( 'custom___c_url', 'javascript:alert(1)' );
		// esc_url_raw shim returns empty for unsafe schemes; update_post_meta
		// then writes empty string. The cell is effectively cleared.
		self::assertSame( '', $GLOBALS['bme_test_postmeta'][1]['_c_url'] );
	}

	public function test_number_sanitizer_strips_non_digit_chars(): void {
		$this->save_one( 'custom___c_number', '$1,234.56 USD' );
		self::assertSame( '1234.56', $GLOBALS['bme_test_postmeta'][1]['_c_number'] );
	}

	public function test_number_sanitizer_returns_empty_for_garbage_input(): void {
		$this->save_one( 'custom___c_number', 'abc' );
		self::assertSame( '', $GLOBALS['bme_test_postmeta'][1]['_c_number'] );
	}

	public function test_number_sanitizer_keeps_leading_negative_and_decimal(): void {
		$this->save_one( 'custom___c_number', '-42.5°C' );
		self::assertSame( '-42.5', $GLOBALS['bme_test_postmeta'][1]['_c_number'] );
	}

	public function test_raw_sanitizer_stores_value_verbatim_including_html(): void {
		$payload = '{"k":"v","x":["<b>1</b>","\\n"]}';
		$this->save_one( 'custom___c_raw', $payload );
		self::assertSame( $payload, $GLOBALS['bme_test_postmeta'][1]['_c_raw'] );
	}

	public function test_raw_sanitizer_still_respects_64KB_cap(): void {
		// Even the raw escape hatch must not let a hostile editor stuff 10 MB
		// into a single cell — the 64 KB limit is enforced downstream.
		$value   = str_repeat( 'x', PostDataService::MAX_VALUE_BYTES + 1 );
		$results = $this->save_one( 'custom___c_raw', $value );

		self::assertFalse( $results[0]['success'] );
		self::assertSame( 'value_too_large', $results[0]['error'] );
		self::assertArrayNotHasKey( 1, $GLOBALS['bme_test_postmeta'] );
	}

	// -------------------------------------------------------------------------
	// Built-in columns and legacy configs default to textarea
	// -------------------------------------------------------------------------

	public function test_built_in_meta_title_column_always_uses_textarea(): void {
		$this->settings->update( [ 'meta_title_key' => '_yoast_wpseo_title' ] );

		$this->save_one( 'meta_title', "with\n<b>tags</b>\nnewlines" );
		// Tags stripped (inner text kept), newlines preserved — textarea behavior.
		self::assertSame( "with\ntags\nnewlines", $GLOBALS['bme_test_postmeta'][1]['_yoast_wpseo_title'] );
	}

	public function test_legacy_column_without_sanitizer_key_defaults_to_textarea(): void {
		// Simulate an old settings record (pre-sanitizer field) by writing
		// directly to the option store, bypassing Settings::sanitize.
		$GLOBALS['bme_test_options'][ Settings::OPTION_KEY ] = [
			'meta_title_key'   => '',
			'meta_desc_key'    => '',
			'custom_columns'   => [ [ 'key' => '_legacy', 'label' => 'Legacy' /* no sanitizer */ ] ],
			'enabled_types'    => [ 'post' ],
			'default_per_page' => 50,
			'debug_mode'       => false,
			'log_level'        => 'warn',
		];
		$fresh   = new Settings();
		$logger  = SharedLogger::for_plugin( [
			'slug'               => 'cf-bulk-meta-editor',
			'threshold_resolver' => static fn() => SharedLogger::LEVEL_ERROR,
		] );
		$service = new PostDataService( $fresh, $logger );

		$service->save_batch( [
			[ 'id' => 1, 'key' => 'custom___legacy', 'value' => "hi\n<b>html</b>" ],
		] );

		// Legacy default = textarea: tags stripped (inner text kept), newlines preserved.
		self::assertSame( "hi\nhtml", $GLOBALS['bme_test_postmeta'][1]['_legacy'] );
	}
}
