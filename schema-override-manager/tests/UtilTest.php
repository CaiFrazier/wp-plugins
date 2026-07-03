<?php

namespace SchemaOverrideManager\Tests;

use PHPUnit\Framework\TestCase;
use SchemaOverrideManager\Util;

final class UtilTest extends TestCase {

	protected function setUp(): void {
		som_test_reset_state();
	}

	// -------------------------------------------------------------------------
	// normalize_schema_type
	// -------------------------------------------------------------------------

	public function test_normalize_strips_https_schema_org_prefix(): void {
		self::assertSame( 'Article', Util::normalize_schema_type( 'https://schema.org/Article' ) );
	}

	public function test_normalize_strips_http_schema_org_prefix(): void {
		self::assertSame( 'Article', Util::normalize_schema_type( 'http://schema.org/Article' ) );
	}

	public function test_normalize_strips_schema_colon_prefix(): void {
		self::assertSame( 'Person', Util::normalize_schema_type( 'schema:Person' ) );
	}

	public function test_normalize_passes_bare_names_through(): void {
		self::assertSame( 'FAQPage', Util::normalize_schema_type( 'FAQPage' ) );
	}

	public function test_normalize_trims_whitespace(): void {
		self::assertSame( 'Article', Util::normalize_schema_type( '  Article  ' ) );
	}

	public function test_normalize_returns_empty_for_non_strings(): void {
		self::assertSame( '', Util::normalize_schema_type( null ) );
		self::assertSame( '', Util::normalize_schema_type( [ 'Article' ] ) );
		self::assertSame( '', Util::normalize_schema_type( 42 ) );
		self::assertSame( '', Util::normalize_schema_type( '' ) );
	}

	// -------------------------------------------------------------------------
	// flatten_json_ld
	// -------------------------------------------------------------------------

	public function test_flatten_yields_single_typed_node(): void {
		$node = [ '@type' => 'Article', 'name' => 'A' ];

		self::assertSame( [ $node ], Util::flatten_json_ld( $node ) );
	}

	public function test_flatten_descends_into_graph(): void {
		$payload = [
			'@context' => 'https://schema.org',
			'@graph'   => [
				[ '@type' => 'Article', 'name' => 'A' ],
				[ '@type' => 'Person', 'name' => 'P' ],
			],
		];

		$nodes = Util::flatten_json_ld( $payload );
		$types = array_column( $nodes, '@type' );

		self::assertSame( [ 'Article', 'Person' ], $types );
	}

	public function test_flatten_yields_graph_wrapper_when_it_has_its_own_type(): void {
		$payload = [
			'@type'  => 'WebPage',
			'@graph' => [
				[ '@type' => 'Article' ],
			],
		];

		$nodes = Util::flatten_json_ld( $payload );
		$types = array_column( $nodes, '@type' );

		self::assertContains( 'Article', $types );
		self::assertContains( 'WebPage', $types );
		// The wrapper is yielded without its @graph children re-nested.
		foreach ( $nodes as $node ) {
			self::assertArrayNotHasKey( '@graph', $node );
		}
	}

	public function test_flatten_handles_root_level_lists(): void {
		$payload = [
			[ '@type' => 'Article' ],
			[ '@type' => 'Person' ],
		];

		self::assertCount( 2, Util::flatten_json_ld( $payload ) );
	}

	public function test_flatten_stops_at_depth_guard(): void {
		// Build a @graph nesting 12 levels deep; the guard cuts at 10.
		$node = [ '@type' => 'Thing' ];
		for ( $i = 0; $i < 12; $i++ ) {
			$node = [ '@graph' => [ $node ] ];
		}

		self::assertSame( [], Util::flatten_json_ld( $node ) );
	}

	public function test_flatten_skips_untyped_nodes(): void {
		self::assertSame( [], Util::flatten_json_ld( [ 'name' => 'no type here' ] ) );
	}
}
