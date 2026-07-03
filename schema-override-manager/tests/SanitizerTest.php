<?php

namespace SchemaOverrideManager\Tests;

use PHPUnit\Framework\TestCase;
use SchemaOverrideManager\Sanitizer;

/**
 * Round-trips valid and hostile payloads through the sanitizer: depth bombs,
 * node-count bombs, oversized strings, HTML injection, URL-shaped keys, and
 * malformed @type identifiers (SOM-P0-001 coverage).
 */
final class SanitizerTest extends TestCase {

	protected function setUp(): void {
		som_test_reset_state();
	}

	public function test_valid_article_round_trips_unchanged(): void {
		$schema = [
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => 'A perfectly normal headline',
			'datePublished' => '2026-07-01',
			'wordCount'     => 1200,
			'isFree'        => true,
			'author'        => [
				'@type' => 'Person',
				'name'  => 'Cai Frazier',
				'url'   => 'https://example.test/about',
			],
		];

		self::assertSame( $schema, Sanitizer::sanitize_schema( $schema ) );
	}

	public function test_depth_bomb_is_cut_at_max_depth(): void {
		$bomb = 'leaf';
		for ( $i = 0; $i < Sanitizer::MAX_DEPTH + 4; $i++ ) {
			$bomb = [ 'nested' => $bomb ];
		}

		$out = Sanitizer::sanitize_schema( $bomb );

		$depth = 0;
		while ( is_array( $out ) && isset( $out['nested'] ) ) {
			$out = $out['nested'];
			$depth++;
		}
		self::assertLessThanOrEqual( Sanitizer::MAX_DEPTH, $depth );
	}

	public function test_node_count_bomb_is_truncated(): void {
		$bomb = [];
		for ( $i = 0; $i < Sanitizer::MAX_NODE_COUNT + 100; $i++ ) {
			$bomb[ 'key' . $i ] = 'value';
		}

		$out = Sanitizer::sanitize_schema( $bomb );

		self::assertLessThanOrEqual( Sanitizer::MAX_NODE_COUNT, count( $out ) );
	}

	public function test_oversized_string_is_truncated(): void {
		$out = Sanitizer::sanitize_schema( [
			'description' => str_repeat( 'a', Sanitizer::MAX_STRING_BYTES + 500 ),
		] );

		self::assertSame( Sanitizer::MAX_STRING_BYTES, strlen( $out['description'] ) );
	}

	public function test_html_is_stripped_from_free_text(): void {
		$out = Sanitizer::sanitize_schema( [
			'name' => 'Hello <script>alert(1)</script><b>World</b>',
		] );

		self::assertStringNotContainsString( '<', $out['name'] );
		self::assertStringContainsString( 'Hello', $out['name'] );
		self::assertStringContainsString( 'World', $out['name'] );
	}

	public function test_url_keys_reject_javascript_scheme(): void {
		$out = Sanitizer::sanitize_schema( [
			'@type' => 'Organization',
			'url'   => 'javascript:alert(1)',
			'logo'  => 'https://example.test/logo.png',
		] );

		self::assertArrayNotHasKey( 'url', $out );
		self::assertSame( 'https://example.test/logo.png', $out['logo'] );
	}

	public function test_type_url_form_is_normalized(): void {
		$out = Sanitizer::sanitize_schema( [ '@type' => 'https://schema.org/Article' ] );

		self::assertSame( 'Article', $out['@type'] );
	}

	public function test_malformed_type_is_dropped(): void {
		$out = Sanitizer::sanitize_schema( [
			'@type' => 'Article<script>',
			'name'  => 'still here',
		] );

		self::assertArrayNotHasKey( '@type', $out );
		self::assertSame( 'still here', $out['name'] );
	}

	public function test_keys_with_html_special_chars_are_dropped(): void {
		$out = Sanitizer::sanitize_schema( [
			'<script>' => 'evil',
			"bad\x01key" => 'evil',
			'goodKey'  => 'fine',
		] );

		self::assertSame( [ 'goodKey' => 'fine' ], $out );
	}

	public function test_oversized_keys_are_dropped(): void {
		$out = Sanitizer::sanitize_schema( [
			str_repeat( 'k', 300 ) => 'gone',
			'kept'                 => 'here',
		] );

		self::assertSame( [ 'kept' => 'here' ], $out );
	}

	public function test_null_values_are_dropped_scalars_preserved(): void {
		$out = Sanitizer::sanitize_schema( [
			'nothing' => null,
			'count'   => 7,
			'ratio'   => 0.5,
			'flag'    => false,
		] );

		self::assertArrayNotHasKey( 'nothing', $out );
		self::assertSame( 7, $out['count'] );
		self::assertSame( 0.5, $out['ratio'] );
		self::assertFalse( $out['flag'] );
	}

	public function test_numeric_keys_from_json_lists_are_preserved(): void {
		$out = Sanitizer::sanitize_schema( [
			'sameAs' => [
				'https://example.test/a',
				'https://example.test/b',
			],
		] );

		self::assertCount( 2, $out['sameAs'] );
	}
}
