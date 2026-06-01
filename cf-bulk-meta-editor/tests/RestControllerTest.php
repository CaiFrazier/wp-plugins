<?php

namespace BulkMetaEditor\Tests;

use BulkMetaEditor\RestController;
use BulkMetaEditor\Settings;
use CFShared\Logger as SharedLogger;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the REST surface: every route registers with a permission_callback,
 * the two capability gates (edit_posts and manage_options) honor the current
 * user's caps, and the namespace + paths match what the front-end expects.
 */
final class RestControllerTest extends TestCase {

	private RestController $controller;

	protected function setUp(): void {
		bme_test_reset_state();

		$settings = new Settings();
		$logger   = SharedLogger::for_plugin( [
			'slug'               => 'cf-bulk-meta-editor',
			'threshold_resolver' => static fn() => SharedLogger::LEVEL_ERROR,
		] );

		$this->controller = new RestController( $settings, $logger );
		$this->controller->register_routes();
	}

	private function registered_paths(): array {
		return array_map(
			static fn( $r ) => $r['namespace'] . $r['route'],
			$GLOBALS['bme_test_routes']
		);
	}

	private function route_for( string $path ): ?array {
		foreach ( $GLOBALS['bme_test_routes'] as $r ) {
			if ( $r['namespace'] . $r['route'] === $path ) {
				return $r;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Route registration
	// -------------------------------------------------------------------------

	public function test_all_expected_routes_register_under_the_plugin_namespace(): void {
		$paths = $this->registered_paths();

		self::assertContains( 'bulk-meta-editor/v1/post-types',        $paths );
		self::assertContains( 'bulk-meta-editor/v1/posts',             $paths );
		self::assertContains( 'bulk-meta-editor/v1/posts/batch',       $paths );
		self::assertContains( 'bulk-meta-editor/v1/settings',          $paths );
		self::assertContains( 'bulk-meta-editor/v1/column-visibility', $paths );
		self::assertContains( 'bulk-meta-editor/v1/export',            $paths );
	}

	public function test_every_route_has_a_permission_callback(): void {
		foreach ( $GLOBALS['bme_test_routes'] as $route ) {
			$args = $route['args'];
			// Routes can be a single method definition or a list of method defs.
			$method_defs = isset( $args['methods'] )
				? [ $args ]
				: array_filter( $args, 'is_array' );

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

	public function test_export_route_is_post_only(): void {
		$route = $this->route_for( 'bulk-meta-editor/v1/export' );

		self::assertNotNull( $route );
		// Bug BME-P0-001 was that this used to be 'GET, POST' which silently
		// degraded to GET-only. Lock in the corrected single-method form.
		self::assertSame( \WP_REST_Server::CREATABLE, $route['args']['methods'] );
	}

	public function test_posts_batch_has_both_read_and_write_methods(): void {
		$route = $this->route_for( 'bulk-meta-editor/v1/posts/batch' );

		self::assertNotNull( $route );
		$methods = array_column( $route['args'], 'methods' );
		self::assertContains( \WP_REST_Server::READABLE,  $methods );
		self::assertContains( \WP_REST_Server::CREATABLE, $methods );
	}

	public function test_settings_route_uses_manage_options_gate(): void {
		$route = $this->route_for( 'bulk-meta-editor/v1/settings' );

		self::assertNotNull( $route );
		foreach ( $route['args'] as $def ) {
			if ( ! isset( $def['permission_callback'] ) ) {
				continue;
			}
			self::assertSame( [ $this->controller, 'check_manage_options' ], $def['permission_callback'] );
		}
	}

	// -------------------------------------------------------------------------
	// Capability gates
	// -------------------------------------------------------------------------

	public function test_check_edit_posts_passes_when_current_user_has_cap(): void {
		$GLOBALS['bme_test_caps'] = static fn( $cap ) => 'edit_posts' === $cap;

		self::assertTrue( $this->controller->check_edit_posts() );
	}

	public function test_check_edit_posts_denies_when_current_user_lacks_cap(): void {
		$GLOBALS['bme_test_caps'] = static fn( $cap ) => 'edit_posts' !== $cap;

		self::assertFalse( $this->controller->check_edit_posts() );
	}

	public function test_check_manage_options_passes_only_when_user_has_cap(): void {
		$GLOBALS['bme_test_caps'] = static fn( $cap ) => 'manage_options' === $cap;
		self::assertTrue( $this->controller->check_manage_options() );

		$GLOBALS['bme_test_caps'] = static fn( $cap ) => 'manage_options' !== $cap;
		self::assertFalse( $this->controller->check_manage_options() );
	}
}
