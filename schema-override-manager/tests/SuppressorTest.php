<?php

namespace SchemaOverrideManager\Tests;

use PHPUnit\Framework\TestCase;
use SchemaOverrideManager\Settings;
use SchemaOverrideManager\Suppressor;
use SchemaOverrideManager\SchemaOutput;

/**
 * Exercises the suppression pipeline through its public surface:
 * register_suppression_hooks() with rules injected via options/postmeta, then
 * invoking the recorded filter callbacks with sample Yoast/Rank Math payloads.
 * Covers merge_rules (global+page union), replace-mode implied suppression
 * with write-time @type normalization (SOM-P1-013), and the OB strip.
 */
final class SuppressorTest extends TestCase {

	private Suppressor $suppressor;

	protected function setUp(): void {
		som_test_reset_state();
		$this->suppressor = new Suppressor( new Settings() );
	}

	/** First callback registered on a filter hook, or null. */
	private function filter_callback( string $hook ): ?callable {
		return $GLOBALS['som_test_filters'][ $hook ][0][0] ?? null;
	}

	private function singular_post( int $id = 5 ): void {
		$GLOBALS['som_test_is_singular']       = true;
		$GLOBALS['som_test_queried_object_id'] = $id;
	}

	// -------------------------------------------------------------------------
	// Rule merging (global + per-page)
	// -------------------------------------------------------------------------

	public function test_global_and_page_type_rules_union_into_one_filter(): void {
		$this->singular_post();
		$GLOBALS['som_test_options']['som_global_suppression'] = [ 'yoast_types' => [ 'Article' ] ];
		$GLOBALS['som_test_postmeta'][5]['_som_suppression']   = [ 'yoast_types' => [ 'Person' ] ];

		$this->suppressor->register_suppression_hooks();
		$cb = $this->filter_callback( 'wpseo_schema_graph' );

		self::assertNotNull( $cb );
		$out   = $cb( [
			[ '@type' => 'Article' ],
			[ '@type' => 'Person' ],
			[ '@type' => 'WebSite' ],
		] );
		$types = array_column( $out, '@type' );

		self::assertSame( [ 'WebSite' ], $types );
	}

	public function test_suppress_all_from_either_layer_wins(): void {
		$this->singular_post();
		$GLOBALS['som_test_options']['som_global_suppression'] = [ 'yoast_all' => false ];
		$GLOBALS['som_test_postmeta'][5]['_som_suppression']   = [ 'yoast_all' => true ];

		$this->suppressor->register_suppression_hooks();

		self::assertSame( '__return_empty_array', $this->filter_callback( 'wpseo_json_ld_output' ) );
	}

	public function test_no_rules_registers_no_filters(): void {
		$this->suppressor->register_suppression_hooks();

		self::assertArrayNotHasKey( 'wpseo_schema_graph', $GLOBALS['som_test_filters'] );
		self::assertArrayNotHasKey( 'wpseo_json_ld_output', $GLOBALS['som_test_filters'] );
		self::assertArrayNotHasKey( 'rank_math/json_ld', $GLOBALS['som_test_filters'] );
	}

	// -------------------------------------------------------------------------
	// Comparison-time normalization (compat layer for pre-existing rows)
	// -------------------------------------------------------------------------

	public function test_url_form_types_in_stored_rules_still_suppress(): void {
		$this->singular_post();
		// Simulates a rule row stored before write-time normalization existed.
		$GLOBALS['som_test_options']['som_global_suppression'] = [
			'yoast_types' => [ 'https://schema.org/Article' ],
		];

		$this->suppressor->register_suppression_hooks();
		$out = $this->filter_callback( 'wpseo_schema_graph' )( [ [ '@type' => 'Article' ] ] );

		self::assertSame( [], $out );
	}

	public function test_multi_typed_graph_nodes_match_any_of_their_types(): void {
		$this->singular_post();
		$GLOBALS['som_test_options']['som_global_suppression'] = [ 'yoast_types' => [ 'FAQPage' ] ];

		$this->suppressor->register_suppression_hooks();
		$out = $this->filter_callback( 'wpseo_schema_graph' )( [
			[ '@type' => [ 'WebPage', 'FAQPage' ] ],
			[ '@type' => 'Article' ],
		] );

		self::assertCount( 1, $out );
	}

	// -------------------------------------------------------------------------
	// Replace-mode implied suppression (SOM-P1-013)
	// -------------------------------------------------------------------------

	public function test_replace_mode_block_suppresses_the_same_type_from_yoast(): void {
		$this->singular_post();
		$GLOBALS['som_test_postmeta'][5]['_som_schema'] = [
			[ '@type' => 'Article', '_som_mode' => 'replace' ],
		];

		$this->suppressor->register_suppression_hooks();
		$out = $this->filter_callback( 'wpseo_schema_graph' )( [ [ '@type' => 'Article' ] ] );

		self::assertSame( [], $out );
	}

	public function test_replace_mode_url_form_type_is_normalized_before_push(): void {
		$this->singular_post();
		$GLOBALS['som_test_postmeta'][5]['_som_schema'] = [
			[ '@type' => 'https://schema.org/Article', '_som_mode' => 'replace' ],
		];

		$this->suppressor->register_suppression_hooks();
		$out = $this->filter_callback( 'wpseo_schema_graph' )( [ [ '@type' => 'Article' ] ] );

		self::assertSame( [], $out );
	}

	public function test_replace_mode_array_type_suppresses_every_listed_type(): void {
		$this->singular_post();
		$GLOBALS['som_test_postmeta'][5]['_som_schema'] = [
			[ '@type' => [ 'Article', 'BlogPosting' ], '_som_mode' => 'replace' ],
		];

		$this->suppressor->register_suppression_hooks();
		$out = $this->filter_callback( 'wpseo_schema_graph' )( [
			[ '@type' => 'Article' ],
			[ '@type' => 'BlogPosting' ],
			[ '@type' => 'WebSite' ],
		] );

		self::assertSame( [ [ '@type' => 'WebSite' ] ], $out );
	}

	public function test_extend_mode_blocks_do_not_suppress(): void {
		$this->singular_post();
		$GLOBALS['som_test_postmeta'][5]['_som_schema'] = [
			[ '@type' => 'Article', '_som_mode' => 'extend' ],
		];

		$this->suppressor->register_suppression_hooks();

		self::assertArrayNotHasKey( 'wpseo_schema_graph', $GLOBALS['som_test_filters'] );
	}

	// -------------------------------------------------------------------------
	// Rank Math
	// -------------------------------------------------------------------------

	public function test_rank_math_entries_removed_by_key_or_node_type(): void {
		$this->singular_post();
		$GLOBALS['som_test_options']['som_global_suppression'] = [
			'rank_math_types' => [ 'Article', 'Person' ],
		];

		$this->suppressor->register_suppression_hooks();
		$out = $this->filter_callback( 'rank_math/json_ld' )( [
			// Removed: key normalizes to a suppressed type.
			'Article'     => [ 'headline' => 'x' ],
			// Removed: node @type matches even though the key doesn't.
			'richSnippet' => [ '@type' => 'Person' ],
			// Kept.
			'BreadcrumbList' => [ '@type' => 'BreadcrumbList' ],
		] );

		self::assertSame( [ 'BreadcrumbList' ], array_keys( $out ) );
	}

	public function test_rank_math_removes_multi_typed_node_by_any_type(): void {
		$this->singular_post();
		$GLOBALS['som_test_options']['som_global_suppression'] = [
			'rank_math_types' => [ 'LocalBusiness' ],
		];

		$this->suppressor->register_suppression_hooks();
		$out = $this->filter_callback( 'rank_math/json_ld' )( [
			// Slug key doesn't match, but the node's array @type includes a
			// suppressed type — must be removed (regression: array @type was skipped).
			'richSnippet' => [ '@type' => [ 'Store', 'LocalBusiness' ] ],
			'breadcrumb'  => [ '@type' => 'BreadcrumbList' ],
		] );

		self::assertSame( [ 'breadcrumb' ], array_keys( $out ) );
	}

	public function test_rank_math_all_registers_return_false(): void {
		$this->singular_post();
		$GLOBALS['som_test_options']['som_global_suppression'] = [ 'rank_math_all' => true ];

		$this->suppressor->register_suppression_hooks();

		self::assertSame( '__return_false', $this->filter_callback( 'rank_math/json_ld' ) );
	}

	// -------------------------------------------------------------------------
	// Theme suppression (output-buffer strip)
	// -------------------------------------------------------------------------

	private function enable_theme_suppression(): void {
		$GLOBALS['som_test_options']['som_settings'] = [
			'enabled_post_types'   => [ 'post', 'page' ],
			'output_priority'      => 5,
			'theme_suppression_ob' => true,
		];
		$GLOBALS['som_test_options']['som_global_suppression'] = [ 'theme_all' => true ];
	}

	public function test_ob_strip_removes_foreign_json_ld_and_keeps_ours(): void {
		$this->enable_theme_suppression();
		$this->suppressor->register_suppression_hooks();

		// Both wp_head actions registered (start at 1, strip at 999).
		self::assertArrayHasKey( 'wp_head', $GLOBALS['som_test_hooks'] );

		$ours    = '<script type="application/ld+json">' . SchemaOutput::OUTPUT_MARKER . '{"@type":"Article"}</script>';
		$foreign = '<script type="application/ld+json">{"@type":"Person"}</script>';

		ob_start();
		$this->suppressor->start_ob();
		echo '<title>Head</title>' . $ours . $foreign;
		$this->suppressor->end_ob_and_strip();
		$emitted = ob_get_clean();

		self::assertStringContainsString( '<title>Head</title>', $emitted );
		self::assertStringContainsString( SchemaOutput::OUTPUT_MARKER, $emitted );
		self::assertStringNotContainsString( '"Person"', $emitted );
	}

	public function test_ob_strip_bails_on_stack_mismatch_instead_of_eating_a_foreign_buffer(): void {
		$this->enable_theme_suppression();
		$this->suppressor->register_suppression_hooks();

		ob_start();
		$this->suppressor->start_ob();
		echo 'our buffered content';
		ob_start(); // A foreign plugin pushes its own buffer between our hooks.
		echo 'foreign buffered content';

		$this->suppressor->end_ob_and_strip();

		// Bail path: neither buffer was popped. Clean up and verify contents
		// were left exactly where they were.
		self::assertSame( 'foreign buffered content', ob_get_clean() );
		self::assertSame( 'our buffered content', ob_get_clean() );
		ob_end_clean();
	}
}
