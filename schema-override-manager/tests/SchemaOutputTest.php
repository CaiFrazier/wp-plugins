<?php

namespace SchemaOverrideManager\Tests;

use PHPUnit\Framework\TestCase;
use SchemaOverrideManager\Settings;
use SchemaOverrideManager\SchemaOutput;

/**
 * Three-layer merge coverage via the public compute_preview() path:
 * global → CPT template → per-page, in extend and replace modes, plus
 * `_som_scope` handling and @context placement.
 */
final class SchemaOutputTest extends TestCase {

	private SchemaOutput $output;

	protected function setUp(): void {
		som_test_reset_state();

		$GLOBALS['som_test_posts'][5] = new \WP_Post( [
			'ID'        => 5,
			'post_type' => 'page',
		] );

		$this->output = new SchemaOutput( new Settings() );
	}

	private function set_layers( array $global = [], array $template = [], array $page = [] ): void {
		$GLOBALS['som_test_options']['som_global_schema']       = $global;
		$GLOBALS['som_test_options']['som_template_page']       = $template;
		$GLOBALS['som_test_postmeta'][5]['_som_schema']         = $page;
	}

	// -------------------------------------------------------------------------
	// Layer merging
	// -------------------------------------------------------------------------

	public function test_extend_mode_deep_merges_all_three_layers(): void {
		$this->set_layers(
			[ [ '@type' => 'Article', 'publisher' => [ 'name' => 'Global Pub' ], 'inLanguage' => 'en' ] ],
			[ [ '@type' => 'Article', 'publisher' => [ 'logo' => 'https://example.test/l.png' ] ] ],
			[ [ '@type' => 'Article', '_som_mode' => 'extend', 'headline' => 'Page headline' ] ]
		);

		$blocks  = $this->output->compute_preview( 5 );
		$article = $blocks['Article'];

		// Deep merge keeps sibling keys from every layer.
		self::assertSame( 'Global Pub', $article['publisher']['name'] );
		self::assertSame( 'https://example.test/l.png', $article['publisher']['logo'] );
		self::assertSame( 'en', $article['inLanguage'] );
		self::assertSame( 'Page headline', $article['headline'] );
	}

	public function test_replace_mode_discards_lower_layers(): void {
		$this->set_layers(
			[ [ '@type' => 'Article', 'inLanguage' => 'en' ] ],
			[ [ '@type' => 'Article', 'publisher' => [ 'name' => 'Template Pub' ] ] ],
			[ [ '@type' => 'Article', '_som_mode' => 'replace', 'headline' => 'Only me' ] ]
		);

		$article = $this->output->compute_preview( 5 )['Article'];

		self::assertSame( 'Only me', $article['headline'] );
		self::assertArrayNotHasKey( 'inLanguage', $article );
		self::assertArrayNotHasKey( 'publisher', $article );
	}

	public function test_extend_replaces_list_valued_property_wholesale(): void {
		// Regression: deep_merge used to merge lists by index, leaving stale
		// tail elements. Extending sameAs [a,b,c] with [x] must yield [x].
		$this->set_layers(
			[ [ '@type' => 'Organization', 'sameAs' => [ 'https://a', 'https://b', 'https://c' ] ] ],
			[],
			[ [ '@type' => 'Organization', '_som_mode' => 'extend', 'sameAs' => [ 'https://x' ] ] ]
		);

		$org = $this->output->compute_preview( 5 )['Organization'];

		self::assertSame( [ 'https://x' ], $org['sameAs'] );
	}

	public function test_extend_still_deep_merges_nested_objects(): void {
		// The list fix must not regress associative-object deep merge.
		$this->set_layers(
			[ [ '@type' => 'Organization', 'address' => [ 'streetAddress' => '1 Main', 'addressLocality' => 'Town' ] ] ],
			[],
			[ [ '@type' => 'Organization', '_som_mode' => 'extend', 'address' => [ 'postalCode' => '98402' ] ] ]
		);

		$addr = $this->output->compute_preview( 5 )['Organization']['address'];

		self::assertSame( '1 Main', $addr['streetAddress'] );
		self::assertSame( 'Town', $addr['addressLocality'] );
		self::assertSame( '98402', $addr['postalCode'] );
	}

	public function test_multi_typed_at_type_is_preserved_not_dropped(): void {
		// Regression: an array @type used to normalize to '' and the whole block
		// was silently dropped at output. It must now render, array intact.
		$this->set_layers(
			[ [ '@type' => [ 'Store', 'LocalBusiness' ], 'name' => 'Shop' ] ],
			[],
			[]
		);

		$blocks = $this->output->compute_preview( 5 );

		self::assertCount( 1, $blocks );
		$block = reset( $blocks );
		self::assertSame( [ 'Store', 'LocalBusiness' ], $block['@type'] );
		self::assertSame( 'Shop', $block['name'] );
	}

	public function test_url_form_array_type_elements_are_normalized(): void {
		$this->set_layers(
			[ [ '@type' => [ 'https://schema.org/Store', 'LocalBusiness' ], 'name' => 'Shop' ] ],
			[],
			[]
		);

		$blocks = $this->output->compute_preview( 5 );
		$block  = reset( $blocks );

		self::assertSame( [ 'Store', 'LocalBusiness' ], $block['@type'] );
	}

	public function test_distinct_ids_of_same_type_coexist(): void {
		// Two Person nodes with different @id must both survive (keyed by @id),
		// instead of the second clobbering the first.
		$this->set_layers(
			[
				[ '@type' => 'Person', '@id' => 'https://example.test/#a', 'name' => 'Author A' ],
				[ '@type' => 'Person', '@id' => 'https://example.test/#b', 'name' => 'Author B' ],
			],
			[],
			[]
		);

		$blocks = $this->output->compute_preview( 5 );
		$names  = array_column( array_values( $blocks ), 'name' );

		self::assertCount( 2, $blocks );
		self::assertContains( 'Author A', $names );
		self::assertContains( 'Author B', $names );
	}

	public function test_same_type_same_id_still_merges(): void {
		$this->set_layers(
			[ [ '@type' => 'Person', '@id' => 'https://example.test/#a', 'name' => 'A', 'jobTitle' => 'Dev' ] ],
			[],
			[ [ '@type' => 'Person', '@id' => 'https://example.test/#a', '_som_mode' => 'extend', 'email' => 'a@x.test' ] ]
		);

		$blocks = $this->output->compute_preview( 5 );
		$person = reset( $blocks );

		self::assertCount( 1, $blocks );
		self::assertSame( 'Dev', $person['jobTitle'] );
		self::assertSame( 'a@x.test', $person['email'] );
	}

	public function test_type_is_normalized_across_layers_so_url_form_merges(): void {
		$this->set_layers(
			[ [ '@type' => 'https://schema.org/Article', 'inLanguage' => 'en' ] ],
			[],
			[ [ '@type' => 'Article', '_som_mode' => 'extend', 'headline' => 'H' ] ]
		);

		$blocks = $this->output->compute_preview( 5 );

		// One merged block, not two blocks with different type spellings.
		self::assertCount( 1, $blocks );
		self::assertSame( 'en', $blocks['Article']['inLanguage'] );
		self::assertSame( 'H', $blocks['Article']['headline'] );
	}

	public function test_internal_mode_and_scope_keys_never_reach_output(): void {
		$this->set_layers(
			[ [ '@type' => 'WebSite', '_som_scope' => 'all', 'name' => 'Site' ] ],
			[],
			[ [ '@type' => 'Article', '_som_mode' => 'replace', 'headline' => 'H' ] ]
		);

		foreach ( $this->output->compute_preview( 5 ) as $block ) {
			self::assertArrayNotHasKey( '_som_scope', $block );
			self::assertArrayNotHasKey( '_som_mode', $block );
		}
	}

	public function test_untyped_blocks_are_skipped(): void {
		$this->set_layers( [ [ 'name' => 'no type' ] ], [], [] );

		self::assertSame( [], $this->output->compute_preview( 5 ) );
	}

	// -------------------------------------------------------------------------
	// _som_scope
	// -------------------------------------------------------------------------

	public function test_home_scoped_global_block_is_excluded_off_the_front_page(): void {
		$this->set_layers( [ [ '@type' => 'Organization', '_som_scope' => 'home' ] ], [], [] );
		$GLOBALS['som_test_is_front_page'] = false;

		self::assertSame( [], $this->output->compute_preview( 5 ) );
	}

	public function test_home_scoped_global_block_emits_on_the_front_page(): void {
		$this->set_layers( [ [ '@type' => 'Organization', '_som_scope' => 'home' ] ], [], [] );
		$GLOBALS['som_test_is_front_page'] = true;

		self::assertArrayHasKey( 'Organization', $this->output->compute_preview( 5 ) );
	}

	public function test_singular_scoped_block_requires_a_post_context(): void {
		$this->set_layers( [ [ '@type' => 'Organization', '_som_scope' => 'singular' ] ], [], [] );

		self::assertArrayHasKey( 'Organization', $this->output->compute_preview( 5 ) );
	}

	// -------------------------------------------------------------------------
	// @context placement
	// -------------------------------------------------------------------------

	public function test_single_block_carries_its_own_context(): void {
		$this->set_layers( [ [ '@type' => 'WebSite', 'name' => 'Site' ] ], [], [] );

		$blocks = $this->output->compute_preview( 5 );

		self::assertSame( 'https://schema.org', $blocks['WebSite']['@context'] );
	}

	public function test_multiple_blocks_have_per_block_context_stripped(): void {
		$this->set_layers(
			[
				[ '@type' => 'WebSite', '@context' => 'https://schema.org' ],
				[ '@type' => 'Organization', '@context' => 'https://schema.org' ],
			],
			[],
			[]
		);

		$blocks = $this->output->compute_preview( 5 );

		self::assertCount( 2, $blocks );
		foreach ( $blocks as $block ) {
			self::assertArrayNotHasKey( '@context', $block );
		}
	}

	// -------------------------------------------------------------------------
	// Emitted output
	// -------------------------------------------------------------------------

	/**
	 * Regression: 1.0.0 placed the OUTPUT_MARKER comment inside the JSON
	 * payload, which made every emitted block invalid JSON for strict parsers
	 * (Google's structured-data parser, crawlers). The marker must live on the
	 * script tag as an attribute; the payload must parse as pure JSON.
	 */
	public function test_emitted_payload_is_strict_json_with_marker_on_the_tag(): void {
		$GLOBALS['som_test_is_singular']         = true;
		$GLOBALS['som_test_queried_object_id']   = 5;
		$this->set_layers( [], [], [ [ '@type' => 'Article', '_som_mode' => 'extend', 'headline' => 'Strict JSON' ] ] );

		ob_start();
		$this->output->output_schema();
		$emitted = ob_get_clean();

		self::assertMatchesRegularExpression(
			'#<script type="application/ld\+json" ' . preg_quote( SchemaOutput::OUTPUT_ATTR, '#' ) . '="1">#',
			$emitted
		);
		self::assertStringNotContainsString( SchemaOutput::OUTPUT_MARKER, $emitted );

		preg_match( '#<script[^>]*>(.*?)</script>#s', $emitted, $m );
		$decoded = json_decode( $m[1], true );
		self::assertIsArray( $decoded, 'Emitted JSON-LD payload must be strictly parseable JSON' );
		self::assertSame( 'Strict JSON', $decoded['headline'] );
	}
}
