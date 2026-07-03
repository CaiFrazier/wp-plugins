<?php

namespace SchemaOverrideManager\Tests;

use PHPUnit\Framework\TestCase;
use SchemaOverrideManager\RestController;
use SchemaOverrideManager\Settings;
use SchemaOverrideManager\SchemaOutput;

/**
 * REST surface: route registration + permission gates, payload-size caps,
 * and the live-fetch endpoint's guards (SOM-P0-003 rate limit + cache,
 * SSRF host check, marker-based ours/other classification).
 */
final class RestControllerTest extends TestCase {

	private RestController $controller;

	protected function setUp(): void {
		som_test_reset_state();
		$this->controller = new RestController( new Settings() );
	}

	private function registered_paths(): array {
		return array_map(
			static fn( $r ) => $r['namespace'] . $r['route'],
			$GLOBALS['som_test_routes']
		);
	}

	private function publish_post( int $id = 5 ): void {
		$GLOBALS['som_test_posts'][ $id ] = new \WP_Post( [
			'ID'          => $id,
			'post_status' => 'publish',
		] );
		$GLOBALS['som_test_permalinks'][ $id ] = 'https://example.test/sample-page/';
	}

	// -------------------------------------------------------------------------
	// Route registration + permissions
	// -------------------------------------------------------------------------

	public function test_all_expected_routes_register_under_som_v1(): void {
		$this->controller->register_routes();
		$paths = $this->registered_paths();

		self::assertContains( 'som/v1/global-schema', $paths );
		self::assertContains( 'som/v1/global-suppression', $paths );
		self::assertContains( 'som/v1/settings', $paths );
		self::assertContains( 'som/v1/template/(?P<post_type>[a-z0-9_-]+)', $paths );
		self::assertContains( 'som/v1/page/(?P<post_id>\d+)/schema', $paths );
		self::assertContains( 'som/v1/page/(?P<post_id>\d+)/suppression', $paths );
		self::assertContains( 'som/v1/page/(?P<post_id>\d+)/preview', $paths );
		self::assertContains( 'som/v1/page/(?P<post_id>\d+)/detected', $paths );
		self::assertContains( 'som/v1/page/(?P<post_id>\d+)/detected-live', $paths );
	}

	public function test_every_route_has_a_permission_callback(): void {
		$this->controller->register_routes();

		foreach ( $GLOBALS['som_test_routes'] as $route ) {
			$method_defs = isset( $route['args']['methods'] )
				? [ $route['args'] ]
				: array_filter( $route['args'], 'is_array' );

			foreach ( $method_defs as $def ) {
				self::assertArrayHasKey(
					'permission_callback',
					$def,
					"Route {$route['namespace']}{$route['route']} is missing permission_callback"
				);
				self::assertIsCallable( $def['permission_callback'] );
			}
		}
	}

	public function test_permission_gates_honor_capabilities(): void {
		$GLOBALS['som_test_caps'] = static fn( $cap ) => 'manage_options' !== $cap;

		self::assertFalse( $this->controller->manage_options_permission() );
		self::assertTrue(
			$this->controller->edit_post_permission( new \WP_REST_Request( [ 'post_id' => 5 ] ) )
		);
	}

	// -------------------------------------------------------------------------
	// Write-endpoint payload guards
	// -------------------------------------------------------------------------

	public function test_oversized_payload_is_rejected_with_413(): void {
		$request  = new \WP_REST_Request( [], str_repeat( 'a', RestController::MAX_PAYLOAD_BYTES + 1 ) );
		$response = $this->controller->save_global_schema( $request );

		self::assertSame( 413, $response->get_status() );
	}

	public function test_non_json_payload_is_rejected_with_400(): void {
		$response = $this->controller->save_global_schema( new \WP_REST_Request( [], 'not json' ) );

		self::assertSame( 400, $response->get_status() );
	}

	public function test_valid_payload_saves_through_the_sanitizer(): void {
		$body     = json_encode( [ [ '@type' => 'WebSite', 'name' => 'Site<script>x</script>' ] ] );
		$response = $this->controller->save_global_schema( new \WP_REST_Request( [], $body ) );

		self::assertSame( 200, $response->get_status() );
		$stored = $GLOBALS['som_test_options']['som_global_schema'][0];
		self::assertStringNotContainsString( '<script>', $stored['name'] );
	}

	// -------------------------------------------------------------------------
	// Live fetch: preconditions
	// -------------------------------------------------------------------------

	public function test_live_fetch_missing_post_is_404(): void {
		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 99 ] )
		);

		self::assertSame( 404, $response->get_status() );
	}

	public function test_live_fetch_unpublished_post_is_409(): void {
		$GLOBALS['som_test_posts'][5] = new \WP_Post( [ 'ID' => 5, 'post_status' => 'draft' ] );

		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		);

		self::assertSame( 409, $response->get_status() );
	}

	public function test_live_fetch_refuses_offsite_permalinks_without_fetching(): void {
		$this->publish_post( 5 );
		$GLOBALS['som_test_permalinks'][5] = 'https://evil.test/exfil';

		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		);

		self::assertSame( 400, $response->get_status() );
		self::assertSame( [], $GLOBALS['som_test_http_calls'] );
		// The cooldown IS armed on reject now, so a host-mismatch stack can't
		// hammer the endpoint with un-throttled SSRF-rejected calls.
		self::assertArrayHasKey( 'som_livefetch_cooldown_1', $GLOBALS['som_test_transients'] );
	}

	public function test_live_fetch_refuses_same_host_different_port(): void {
		$this->publish_post( 5 );
		// home_url is https://example.test (default port); permalink steered to
		// :8080 must be rejected even though the host matches.
		$GLOBALS['som_test_permalinks'][5] = 'https://example.test:8080/sample-page/';

		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		);

		self::assertSame( 400, $response->get_status() );
		self::assertSame( [], $GLOBALS['som_test_http_calls'] );
	}

	// -------------------------------------------------------------------------
	// Live fetch: SOM-P0-003 rate limit + result cache
	// -------------------------------------------------------------------------

	public function test_live_fetch_success_arms_cooldown_and_caches_result(): void {
		$this->publish_post( 5 );
		$ours = '<script type="application/ld+json">' . SchemaOutput::OUTPUT_MARKER . '{"@type":"Article"}</script>';
		$GLOBALS['som_test_http'] = [ 'code' => 200, 'body' => $ours ];

		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertCount( 1, $GLOBALS['som_test_http_calls'] );

		// Cooldown armed for the current user, result cached for the post
		// (result key is namespaced by the generation counter, default 0).
		self::assertArrayHasKey( 'som_livefetch_cooldown_1', $GLOBALS['som_test_transients'] );
		self::assertSame( 5, $GLOBALS['som_test_transient_ttls']['som_livefetch_cooldown_1'] );
		self::assertArrayHasKey( 'som_livefetch_result_5_0', $GLOBALS['som_test_transients'] );
		self::assertSame( 60, $GLOBALS['som_test_transient_ttls']['som_livefetch_result_5_0'] );
	}

	public function test_live_fetch_passes_a_response_size_limit(): void {
		$this->publish_post( 5 );
		$GLOBALS['som_test_http'] = [ 'code' => 200, 'body' => '<html></html>' ];

		$this->controller->get_live_detected_schema( new \WP_REST_Request( [ 'post_id' => 5 ] ) );

		$args = $GLOBALS['som_test_http_calls'][0]['args'];
		self::assertArrayHasKey( 'limit_response_size', $args );
		self::assertGreaterThan( 0, $args['limit_response_size'] );
	}

	public function test_cached_result_short_circuits_without_a_fetch(): void {
		$this->publish_post( 5 );
		$GLOBALS['som_test_transients']['som_livefetch_result_5_0'] = [
			'ours'  => [],
			'other' => [],
			'url'   => 'https://example.test/sample-page/',
		];

		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertTrue( $response->get_data()['cached'] );
		self::assertSame( [], $GLOBALS['som_test_http_calls'] );
	}

	public function test_active_cooldown_returns_429_with_retry_after(): void {
		$this->publish_post( 5 );
		$GLOBALS['som_test_transients']['som_livefetch_cooldown_1'] = time() + 5;

		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		);
		$data     = $response->get_data();

		self::assertSame( 429, $response->get_status() );
		self::assertSame( [], $GLOBALS['som_test_http_calls'] );
		self::assertGreaterThanOrEqual( 1, $data['retry_after'] );
		self::assertLessThanOrEqual( 5, $data['retry_after'] );
		// `message` is the key wp.apiFetch surfaces to the editor sidebar.
		self::assertStringContainsString( 'Rate limited', $data['message'] );
	}

	public function test_save_post_invalidates_the_result_cache(): void {
		$GLOBALS['som_test_transients']['som_livefetch_result_5_0'] = [ 'ours' => [] ];

		$this->controller->invalidate_live_fetch_cache( 5 );

		self::assertArrayNotHasKey( 'som_livefetch_result_5_0', $GLOBALS['som_test_transients'] );
	}

	public function test_revision_save_invalidates_the_parent_post_cache(): void {
		// A revision (id 77) of parent post 5. The parent's cache must clear.
		$GLOBALS['som_test_revisions'][77]                          = 5;
		$GLOBALS['som_test_transients']['som_livefetch_result_5_0'] = [ 'ours' => [] ];

		$this->controller->invalidate_live_fetch_cache( 77 );

		self::assertArrayNotHasKey( 'som_livefetch_result_5_0', $GLOBALS['som_test_transients'] );
	}

	public function test_global_save_bumps_generation_orphaning_stale_caches(): void {
		$this->publish_post( 5 );
		// A result cached under generation 0.
		$GLOBALS['som_test_transients']['som_livefetch_result_5_0'] = [ 'ours' => [], 'other' => [] ];

		// Saving global schema advances the generation to 1.
		$this->controller->save_global_schema(
			new \WP_REST_Request( [], json_encode( [ [ '@type' => 'WebSite' ] ] ) )
		);
		self::assertSame( 1, $GLOBALS['som_test_options']['som_livefetch_generation'] );

		// The next live fetch reads generation 1, misses the gen-0 cache, and
		// performs a real fetch instead of serving the stale result.
		$GLOBALS['som_test_http'] = [ 'code' => 200, 'body' => '<html></html>' ];
		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertCount( 1, $GLOBALS['som_test_http_calls'] );
		self::assertArrayHasKey( 'som_livefetch_result_5_1', $GLOBALS['som_test_transients'] );
	}

	public function test_init_hooks_cache_invalidation_to_save_post(): void {
		$this->controller->init();

		self::assertArrayHasKey( 'save_post', $GLOBALS['som_test_hooks'] );
	}

	// -------------------------------------------------------------------------
	// Live fetch: response handling + classification
	// -------------------------------------------------------------------------

	public function test_http_error_maps_to_502(): void {
		$this->publish_post( 5 );
		$GLOBALS['som_test_http'] = new \WP_Error( 'timeout', 'Connection timed out' );

		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		);

		self::assertSame( 502, $response->get_status() );
	}

	public function test_bad_status_code_maps_to_502(): void {
		$this->publish_post( 5 );
		$GLOBALS['som_test_http'] = [ 'code' => 500, 'body' => 'Internal Server Error' ];

		$response = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		);

		self::assertSame( 502, $response->get_status() );
	}

	public function test_classification_splits_ours_from_other_and_flattens_graphs(): void {
		$this->publish_post( 5 );
		$body = '<script type="application/ld+json">' . SchemaOutput::OUTPUT_MARKER . '{"@type":"Article"}</script>'
			. '<script type="application/ld+json">{"@graph":[{"@type":"Person"},{"@type":"WebSite"}]}</script>';
		$GLOBALS['som_test_http'] = [ 'code' => 200, 'body' => $body ];

		$data = $this->controller->get_live_detected_schema(
			new \WP_REST_Request( [ 'post_id' => 5 ] )
		)->get_data();

		self::assertSame( [ 'Article' ], array_column( $data['ours'], 'type' ) );
		self::assertSame( [ 'Person', 'WebSite' ], array_column( $data['other'], 'type' ) );
		self::assertSame( 'https://example.test/sample-page/', $data['url'] );
	}
}
