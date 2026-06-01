<?php

namespace CFMediaManager\Tests;

use CFMediaManager\LibraryColumnRegistry;
use CFMediaManager\LibraryRestController;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the REST surface: the route registers under the plugin
 * namespace with an upload_files permission gate, columns are validated
 * against the registry, the client orderby is mapped to a safe WP_Query
 * value, and pagination / filters are forwarded into the query args.
 */
final class LibraryRestControllerTest extends TestCase {

	private LibraryRestController $controller;

	protected function setUp(): void {
		cf_media_manager_test_reset_state();
		$this->controller = new LibraryRestController();
		$this->controller->register_routes();
	}

	private function route(): array {
		self::assertNotEmpty( $GLOBALS['cf_media_manager_test_routes'] );
		return $GLOBALS['cf_media_manager_test_routes'][0];
	}

	private function request( array $params ): \WP_REST_Request {
		return new \WP_REST_Request( $params );
	}

	private function base_params( array $overrides = array() ): array {
		return array_merge( array(
			'page'     => 1,
			'per_page' => 50,
			'search'   => '',
			'mime'     => '',
			'parent'   => '',
			'orderby'  => 'id',
			'order'    => 'DESC',
			'columns'  => array(),
		), $overrides );
	}

	// -------------------------------------------------------------------------
	// Route registration + permission gate
	// -------------------------------------------------------------------------

	public function test_route_registers_under_plugin_namespace(): void {
		$route = $this->route();
		self::assertSame( 'cf-media-manager/v1', $route['namespace'] );
		self::assertSame( '/library', $route['route'] );
	}

	public function test_route_is_read_only_with_a_permission_callback(): void {
		$args = $this->route()['args'];
		self::assertSame( \WP_REST_Server::READABLE, $args['methods'] );
		self::assertArrayHasKey( 'permission_callback', $args );
		self::assertIsCallable( $args['permission_callback'] );
	}

	public function test_permission_callback_requires_upload_files_cap(): void {
		$cb = $this->route()['args']['permission_callback'];

		$GLOBALS['cf_media_manager_test_user_caps'] = array( 'upload_files' => true );
		self::assertTrue( (bool) $cb() );

		$GLOBALS['cf_media_manager_test_user_caps'] = array( 'upload_files' => false );
		self::assertFalse( (bool) $cb() );
	}

	public function test_columns_arg_sanitizes_each_entry_with_sanitize_key(): void {
		$sanitizer = $this->route()['args']['args']['columns']['sanitize_callback'];
		self::assertSame( array( 'badkey', 'title' ), $sanitizer( array( 'Bad Key!', 'title' ) ) );
	}

	// -------------------------------------------------------------------------
	// Column validation
	// -------------------------------------------------------------------------

	public function test_invalid_columns_fall_back_to_registry_defaults(): void {
		$resp = $this->controller->get_attachments(
			$this->request( $this->base_params( array( 'columns' => array( 'not_a_col', 'also_bad' ) ) ) )
		);

		self::assertSame( LibraryColumnRegistry::defaults(), $resp->get_data()['columns'] );
	}

	public function test_valid_columns_are_preserved_in_order_and_filtered(): void {
		$resp = $this->controller->get_attachments(
			$this->request( $this->base_params( array( 'columns' => array( 'title', 'bogus', 'id' ) ) ) )
		);

		self::assertSame( array( 'title', 'id' ), $resp->get_data()['columns'] );
	}

	// -------------------------------------------------------------------------
	// Query argument construction
	// -------------------------------------------------------------------------

	public function test_orderby_is_mapped_through_the_safe_orderby_map(): void {
		$this->controller->get_attachments(
			$this->request( $this->base_params( array( 'orderby' => 'date_modified', 'order' => 'ASC' ) ) )
		);

		$args = $GLOBALS['cf_media_manager_test_last_query_args'];
		self::assertSame( 'modified', $args['orderby'] );
		self::assertSame( 'ASC', $args['order'] );
	}

	public function test_unknown_orderby_defaults_to_id(): void {
		$this->controller->get_attachments(
			$this->request( $this->base_params( array( 'orderby' => 'totally_unknown' ) ) )
		);

		self::assertSame( 'ID', $GLOBALS['cf_media_manager_test_last_query_args']['orderby'] );
	}

	public function test_pagination_is_forwarded_into_query_args(): void {
		$this->controller->get_attachments(
			$this->request( $this->base_params( array( 'page' => 3, 'per_page' => 25 ) ) )
		);

		$args = $GLOBALS['cf_media_manager_test_last_query_args'];
		self::assertSame( 25, $args['posts_per_page'] );
		self::assertSame( 3, $args['paged'] );
		self::assertSame( 'attachment', $args['post_type'] );
		self::assertSame( 'inherit', $args['post_status'] );
	}

	public function test_search_mime_and_unattached_filters_are_applied(): void {
		$this->controller->get_attachments(
			$this->request( $this->base_params( array(
				'search' => 'logo',
				'mime'   => 'image',
				'parent' => 'unattached',
			) ) )
		);

		$args = $GLOBALS['cf_media_manager_test_last_query_args'];
		self::assertSame( 'logo', $args['s'] );
		self::assertSame( 'image', $args['post_mime_type'] );
		self::assertSame( 0, $args['post_parent'] );
	}

	public function test_filters_are_omitted_when_empty(): void {
		$this->controller->get_attachments( $this->request( $this->base_params() ) );

		$args = $GLOBALS['cf_media_manager_test_last_query_args'];
		self::assertArrayNotHasKey( 's', $args );
		self::assertArrayNotHasKey( 'post_mime_type', $args );
		self::assertArrayNotHasKey( 'post_parent', $args );
	}

	// -------------------------------------------------------------------------
	// Response shape
	// -------------------------------------------------------------------------

	public function test_response_reports_totals_and_resolved_rows(): void {
		$GLOBALS['cf_media_manager_test_query_posts_override'] = array(
			new \WP_Post( array( 'ID' => 10, 'post_title' => 'One' ) ),
			new \WP_Post( array( 'ID' => 11, 'post_title' => 'Two' ) ),
		);
		$GLOBALS['cf_media_manager_test_found_posts_override'] = 120;

		$data = $this->controller->get_attachments(
			$this->request( $this->base_params( array( 'per_page' => 50, 'columns' => array( 'id', 'title' ) ) ) )
		)->get_data();

		self::assertSame( 120, $data['total'] );
		self::assertSame( 3, $data['pages'] );
		self::assertCount( 2, $data['items'] );
		self::assertSame( array( 'id' => '10', 'title' => 'One' ), $data['items'][0] );
	}
}
