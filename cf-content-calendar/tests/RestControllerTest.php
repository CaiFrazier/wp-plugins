<?php

use CFContentCalendar\RestController;
use PHPUnit\Framework\TestCase;

class RestControllerTest extends TestCase {

	protected function setUp(): void {
		cfcal_test_reset_state();
		// Instantiating the controller registers the rest_api_init hook.
		new RestController();
	}

	// -------------------------------------------------------------------------
	// Validation helpers (pure — no shims needed)
	// -------------------------------------------------------------------------

	public function test_validate_date_accepts_valid_dates(): void {
		$this->assertTrue( RestController::validate_date( '2026-01-15' ) );
		$this->assertTrue( RestController::validate_date( '2026-12-31' ) );
		$this->assertTrue( RestController::validate_date( '2000-02-29' ) );
	}

	public function test_validate_date_rejects_invalid_formats(): void {
		$this->assertFalse( RestController::validate_date( '01-15-2026' ) );
		$this->assertFalse( RestController::validate_date( '2026-1-1' ) );
		$this->assertFalse( RestController::validate_date( '2026/01/15' ) );
		$this->assertFalse( RestController::validate_date( 'not-a-date' ) );
		$this->assertFalse( RestController::validate_date( '' ) );
		$this->assertFalse( RestController::validate_date( 42 ) );
	}

	public function test_validate_date_rejects_impossible_calendar_dates(): void {
		// Well-shaped but not real dates must be rejected before they reach WP.
		$this->assertFalse( RestController::validate_date( '2026-13-40' ) );
		$this->assertFalse( RestController::validate_date( '2026-02-31' ) );
		$this->assertFalse( RestController::validate_date( '2026-00-00' ) );
		$this->assertFalse( RestController::validate_date( '2027-02-29' ) ); // non-leap
	}

	public function test_validate_datetime_rejects_impossible_date_and_time(): void {
		$this->assertFalse( RestController::validate_datetime( '2026-02-31 09:00:00' ) );
		$this->assertFalse( RestController::validate_datetime( '2026-05-15 25:00:00' ) );
		$this->assertFalse( RestController::validate_datetime( '2026-05-15 09:60:00' ) );
	}

	public function test_validate_datetime_accepts_date_only(): void {
		$this->assertTrue( RestController::validate_datetime( '2026-05-15' ) );
	}

	public function test_validate_datetime_accepts_date_and_time(): void {
		$this->assertTrue( RestController::validate_datetime( '2026-05-15 14:30:00' ) );
		$this->assertTrue( RestController::validate_datetime( '2026-01-01 00:00:00' ) );
	}

	public function test_validate_datetime_rejects_iso8601(): void {
		$this->assertFalse( RestController::validate_datetime( '2026-05-15T14:30:00' ) );
		$this->assertFalse( RestController::validate_datetime( '2026-05-15T14:30:00Z' ) );
	}

	public function test_validate_datetime_rejects_invalid_formats(): void {
		$this->assertFalse( RestController::validate_datetime( '05/15/2026' ) );
		$this->assertFalse( RestController::validate_datetime( '' ) );
		$this->assertFalse( RestController::validate_datetime( null ) );
	}

	// -------------------------------------------------------------------------
	// Route registration
	// -------------------------------------------------------------------------

	public function test_registers_posts_route_and_reschedule_route(): void {
		// Trigger rest_api_init hook manually using the registered callback.
		foreach ( $GLOBALS['cfcal_test_hooks']['rest_api_init'] as $entry ) {
			call_user_func( $entry[0] );
		}

		$namespaces = array_column( $GLOBALS['cfcal_test_routes'], 'namespace' );
		$routes     = array_column( $GLOBALS['cfcal_test_routes'], 'route' );

		$this->assertContains( 'cf-cal/v1', $namespaces );
		$this->assertContains( '/posts', $routes );
		$this->assertContains( '/posts/(?P<id>\d+)/reschedule', $routes );
	}

	public function test_posts_route_registers_readable_and_creatable_methods(): void {
		foreach ( $GLOBALS['cfcal_test_hooks']['rest_api_init'] as $entry ) {
			call_user_func( $entry[0] );
		}

		$post_route = null;
		foreach ( $GLOBALS['cfcal_test_routes'] as $r ) {
			if ( '/posts' === $r['route'] ) {
				$post_route = $r;
				break;
			}
		}

		$this->assertNotNull( $post_route );
		// Both GET and POST are registered in a single route entry as an array of method specs.
		$methods = array_column( $post_route['args'], 'methods' );
		$this->assertContains( WP_REST_Server::READABLE, $methods );
		$this->assertContains( WP_REST_Server::CREATABLE, $methods );
	}

	// -------------------------------------------------------------------------
	// get_posts
	// -------------------------------------------------------------------------

	public function test_get_posts_returns_formatted_posts(): void {
		$post           = new WP_Post();
		$post->ID       = 1;
		$post->post_title  = 'Hello World';
		$post->post_status = 'publish';
		$post->post_type   = 'post';
		$post->post_date   = '2026-05-10 09:00:00';
		$post->post_date_gmt = '2026-05-10 09:00:00';
		$post->post_author = 1;

		$GLOBALS['cfcal_test_query_posts'] = [ $post ];

		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [] );
		$request->set_param( 'post_status', [ 'publish', 'future', 'draft' ] );

		$controller = new RestController();
		$response   = $controller->get_posts( $request );

		$this->assertSame( 200, $response->status );
		$this->assertCount( 1, $response->data );
		$this->assertSame( 1, $response->data[0]['id'] );
		$this->assertSame( 'Hello World', $response->data[0]['title'] );
		$this->assertSame( 'publish', $response->data[0]['status'] );
		$this->assertArrayHasKey( 'edit_link', $response->data[0] );
	}

	public function test_get_posts_strips_attachment_from_type_list(): void {
		// If the client requests 'attachment' as a post type, it must be silently removed.
		$GLOBALS['cfcal_test_query_posts'] = [];

		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [ 'post', 'attachment' ] );
		$request->set_param( 'post_status', [ 'publish' ] );

		$controller = new RestController();
		$response   = $controller->get_posts( $request );

		// Only 'post' is an allowed registered type; 'attachment' is filtered out.
		$this->assertSame( 200, $response->status );
		// WP_Query shim returns empty array — we just confirm no error response.
		$this->assertIsArray( $response->data );
	}

	public function test_get_posts_returns_empty_when_all_types_invalid(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [ 'attachment', 'nonexistent_type' ] );
		$request->set_param( 'post_status', [ 'publish' ] );

		$controller = new RestController();
		$response   = $controller->get_posts( $request );

		$this->assertSame( 200, $response->status );
		$this->assertSame( [], $response->data );
	}

	public function test_get_posts_allow_lists_statuses(): void {
		$GLOBALS['cfcal_test_query_posts'] = [];

		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [] );
		// 'trash' is not in the allowed list.
		$request->set_param( 'post_status', [ 'publish', 'trash' ] );

		$controller = new RestController();
		$response   = $controller->get_posts( $request );

		$this->assertSame( 200, $response->status );
	}

	public function test_get_posts_restricts_to_own_author_without_edit_others(): void {
		$GLOBALS['cfcal_test_caps'] = static function ( $cap ) {
			return 'edit_others_posts' !== $cap;
		};
		$GLOBALS['cfcal_test_user_id'] = 7;

		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [] );
		$request->set_param( 'post_status', [ 'draft' ] );
		$request->set_param( 'author', 99 ); // attempt to view another author

		$controller = new RestController();
		$controller->get_posts( $request );

		// The requested author=99 must be overridden with the user's own ID.
		$this->assertSame( 7, $GLOBALS['cfcal_test_last_query_args']['author'] );
	}

	public function test_get_posts_honors_author_filter_for_privileged_user(): void {
		$GLOBALS['cfcal_test_caps'] = true; // can edit_others_posts

		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [] );
		$request->set_param( 'post_status', [ 'publish' ] );
		$request->set_param( 'author', 42 );

		$controller = new RestController();
		$controller->get_posts( $request );

		$this->assertSame( 42, $GLOBALS['cfcal_test_last_query_args']['author'] );
	}

	public function test_get_posts_date_query_covers_the_full_last_day(): void {
		// Regression: a bare `before` date resolves to 00:00:00 and drops posts
		// with a time-of-day on the range's last day. Bounds must span the day.
		$GLOBALS['cfcal_test_query_posts'] = [];

		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [] );
		$request->set_param( 'post_status', [ 'publish' ] );

		$controller = new RestController();
		$controller->get_posts( $request );

		$clause = $GLOBALS['cfcal_test_last_query_args']['date_query'][0];
		$this->assertSame( '2026-05-01 00:00:00', $clause['after'] );
		$this->assertSame( '2026-05-31 23:59:59', $clause['before'] );
	}

	public function test_format_post_omits_date_gmt(): void {
		$post              = new WP_Post();
		$post->ID          = 1;
		$post->post_title  = 'X';
		$post->post_status = 'draft';
		$post->post_type   = 'post';
		$post->post_date   = '2026-05-10 09:00:00';
		$post->post_date_gmt = '0000-00-00 00:00:00';

		$GLOBALS['cfcal_test_query_posts'] = [ $post ];

		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [] );
		$request->set_param( 'post_status', [ 'draft' ] );

		$controller = new RestController();
		$response   = $controller->get_posts( $request );

		// date_gmt (0000-00-00 for drafts) is deliberately not exposed.
		$this->assertArrayNotHasKey( 'date_gmt', $response->data[0] );
		$this->assertSame( '2026-05-10 09:00:00', $response->data[0]['date'] );
	}

	public function test_get_posts_sets_perm_readable(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [] );
		$request->set_param( 'post_status', [ 'publish', 'private' ] );

		$controller = new RestController();
		$controller->get_posts( $request );

		// 'perm' => 'readable' makes WP_Query enforce read_private_posts etc.
		$this->assertSame( 'readable', $GLOBALS['cfcal_test_last_query_args']['perm'] );
	}

	public function test_get_posts_no_author_arg_when_filter_is_zero_and_privileged(): void {
		$GLOBALS['cfcal_test_caps'] = true;

		$request = new WP_REST_Request();
		$request->set_param( 'start', '2026-05-01' );
		$request->set_param( 'end', '2026-05-31' );
		$request->set_param( 'post_types', [] );
		$request->set_param( 'post_status', [ 'publish' ] );
		$request->set_param( 'author', 0 );

		$controller = new RestController();
		$controller->get_posts( $request );

		$this->assertArrayNotHasKey( 'author', $GLOBALS['cfcal_test_last_query_args'] );
	}

	// -------------------------------------------------------------------------
	// create_post
	// -------------------------------------------------------------------------

	public function test_create_post_returns_201_on_success(): void {
		$new_post              = new WP_Post();
		$new_post->ID          = 99;
		$new_post->post_title  = 'New Draft';
		$new_post->post_status = 'draft';
		$new_post->post_type   = 'post';
		$new_post->post_date   = '2026-05-20 00:00:00';

		$GLOBALS['cfcal_test_insert_result'] = 99;
		$GLOBALS['cfcal_test_posts'][99]     = $new_post;

		$request = new WP_REST_Request();
		$request->set_param( 'title', 'New Draft' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'post_date', '2026-05-20' );
		$request->set_param( 'status', 'draft' );
		$request->set_param( 'author_id', 0 );

		$controller = new RestController();
		$response   = $controller->create_post( $request );

		$this->assertSame( 201, $response->status );
		$this->assertSame( 99, $response->data['id'] );
		$this->assertSame( 'New Draft', $response->data['title'] );
	}

	public function test_create_post_rejects_invalid_post_type(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'title', 'Test' );
		$request->set_param( 'post_type', 'nonexistent_type' );
		$request->set_param( 'post_date', '2026-05-20' );
		$request->set_param( 'status', 'draft' );
		$request->set_param( 'author_id', 0 );

		$controller = new RestController();
		$response   = $controller->create_post( $request );

		$this->assertSame( 400, $response->status );
		$this->assertSame( 'invalid_post_type', $response->data['code'] );
	}

	public function test_create_post_rejects_attachment_post_type(): void {
		$request = new WP_REST_Request();
		$request->set_param( 'title', 'Test' );
		$request->set_param( 'post_type', 'attachment' );
		$request->set_param( 'post_date', '2026-05-20' );
		$request->set_param( 'status', 'draft' );
		$request->set_param( 'author_id', 0 );

		$controller = new RestController();
		$response   = $controller->create_post( $request );

		$this->assertSame( 400, $response->status );
	}

	public function test_create_post_rejects_future_status_without_publish_cap(): void {
		$GLOBALS['cfcal_test_caps'] = static function ( $cap ) {
			return 'publish_posts' !== $cap;
		};

		$request = new WP_REST_Request();
		$request->set_param( 'title', 'Scheduled' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'post_date', '2026-06-01' );
		$request->set_param( 'status', 'future' );
		$request->set_param( 'author_id', 0 );

		$controller = new RestController();
		$response   = $controller->create_post( $request );

		$this->assertSame( 403, $response->status );
		$this->assertSame( 'insufficient_capabilities', $response->data['code'] );
	}

	public function test_create_post_allows_future_status_with_publish_cap(): void {
		$GLOBALS['cfcal_test_caps'] = true; // All caps granted.

		$scheduled              = new WP_Post();
		$scheduled->ID          = 100;
		$scheduled->post_title  = 'Scheduled';
		$scheduled->post_status = 'future';
		$scheduled->post_type   = 'post';
		$scheduled->post_date   = '2026-06-01 00:00:00';

		$GLOBALS['cfcal_test_insert_result'] = 100;
		$GLOBALS['cfcal_test_posts'][100]    = $scheduled;

		$request = new WP_REST_Request();
		$request->set_param( 'title', 'Scheduled' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'post_date', '2026-06-01' );
		$request->set_param( 'status', 'future' );
		$request->set_param( 'author_id', 0 );

		$controller = new RestController();
		$response   = $controller->create_post( $request );

		$this->assertSame( 201, $response->status );
		$this->assertSame( 'future', $response->data['status'] );
	}

	public function test_create_post_rejects_scheduling_in_the_past(): void {
		// current_time shim is 2026-05-15 12:00:00; scheduling for an earlier
		// day would make WP silently publish a backdated post.
		$GLOBALS['cfcal_test_caps'] = true;

		$request = new WP_REST_Request();
		$request->set_param( 'title', 'Backdated' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'post_date', '2026-05-01 09:00:00' );
		$request->set_param( 'status', 'future' );
		$request->set_param( 'author_id', 0 );

		$controller = new RestController();
		$response   = $controller->create_post( $request );

		$this->assertSame( 400, $response->status );
		$this->assertSame( 'date_in_past', $response->data['code'] );
	}

	public function test_create_post_returns_500_on_wp_error(): void {
		$GLOBALS['cfcal_test_insert_result'] = new WP_Error( 'db_error', 'Database insert failed.' );

		$request = new WP_REST_Request();
		$request->set_param( 'title', 'Broken' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'post_date', '2026-05-20' );
		$request->set_param( 'status', 'draft' );
		$request->set_param( 'author_id', 0 );

		$controller = new RestController();
		$response   = $controller->create_post( $request );

		$this->assertSame( 500, $response->status );
		$this->assertSame( 'insert_failed', $response->data['code'] );
	}

	public function test_create_post_enforces_per_post_type_capability(): void {
		// User has generic edit_posts but NOT the page create capability.
		$GLOBALS['cfcal_test_caps'] = static function ( $cap ) {
			return 'edit_pages' !== $cap;
		};

		$request = new WP_REST_Request();
		$request->set_param( 'title', 'Sneaky Page' );
		$request->set_param( 'post_type', 'page' );
		$request->set_param( 'post_date', '2026-05-20' );
		$request->set_param( 'status', 'draft' );
		$request->set_param( 'author_id', 0 );

		$controller = new RestController();
		$response   = $controller->create_post( $request );

		$this->assertSame( 403, $response->status );
		$this->assertSame( 'insufficient_capabilities', $response->data['code'] );
	}

	// -------------------------------------------------------------------------
	// reschedule_post
	// -------------------------------------------------------------------------

	public function test_reschedule_post_updates_date_and_returns_200(): void {
		$post              = new WP_Post();
		$post->ID          = 5;
		$post->post_title  = 'Reschedulable';
		$post->post_status = 'future';
		$post->post_date   = '2026-05-20 09:00:00';

		$GLOBALS['cfcal_test_posts'][5] = $post;
		$GLOBALS['cfcal_test_current_time'] = '2026-05-15 12:00:00';

		$request = new WP_REST_Request();
		$request->set_param( 'id', 5 );
		$request->set_param( 'post_date', '2026-05-22 09:00:00' );

		$controller = new RestController();
		$response   = $controller->reschedule_post( $request );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 5, $response->data['id'] );
		// New date was applied.
		$this->assertSame( '2026-05-22 09:00:00', $GLOBALS['cfcal_test_posts'][5]->post_date );
	}

	public function test_reschedule_post_downgrades_future_to_draft_when_date_is_past(): void {
		$post              = new WP_Post();
		$post->ID          = 6;
		$post->post_status = 'future';
		$post->post_date   = '2026-05-20 09:00:00';

		$GLOBALS['cfcal_test_posts'][6]     = $post;
		$GLOBALS['cfcal_test_current_time'] = '2026-05-25 12:00:00'; // "now" is after the new date

		$request = new WP_REST_Request();
		$request->set_param( 'id', 6 );
		$request->set_param( 'post_date', '2026-05-10 09:00:00' ); // date in the past

		$controller = new RestController();
		$response   = $controller->reschedule_post( $request );

		$this->assertSame( 200, $response->status );
		// Status should downgrade to draft since the target date is in the past.
		$this->assertSame( 'draft', $GLOBALS['cfcal_test_posts'][6]->post_status );
	}

	public function test_reschedule_passes_edit_date_flag(): void {
		// Without edit_date => true, real WP ignores the post_date change on a
		// published post. The bootstrap shim models that, so this asserts the
		// flag is actually sent.
		$post              = new WP_Post();
		$post->ID          = 20;
		$post->post_status = 'publish';
		$post->post_date   = '2026-04-01 08:00:00';

		$GLOBALS['cfcal_test_posts'][20] = $post;

		$request = new WP_REST_Request();
		$request->set_param( 'id', 20 );
		$request->set_param( 'post_date', '2026-04-10' );

		$controller = new RestController();
		$controller->reschedule_post( $request );

		$this->assertTrue( ! empty( $GLOBALS['cfcal_test_last_update']['edit_date'] ) );
		// The date actually moved (proves edit_date took effect in the shim).
		$this->assertSame( '2026-04-10 08:00:00', $GLOBALS['cfcal_test_posts'][20]->post_date );
	}

	public function test_reschedule_preserves_time_of_day_when_client_sends_date_only(): void {
		$post              = new WP_Post();
		$post->ID          = 21;
		$post->post_status = 'future';
		$post->post_date   = '2026-05-20 14:30:00';

		$GLOBALS['cfcal_test_posts'][21]     = $post;
		$GLOBALS['cfcal_test_current_time']  = '2026-05-15 12:00:00';

		$request = new WP_REST_Request();
		$request->set_param( 'id', 21 );
		$request->set_param( 'post_date', '2026-05-25' ); // date only

		$controller = new RestController();
		$controller->reschedule_post( $request );

		// 14:30:00 carried over from the original post_date.
		$this->assertSame( '2026-05-25 14:30:00', $GLOBALS['cfcal_test_posts'][21]->post_date );
	}

	public function test_reschedule_draft_does_not_write_post_date_gmt(): void {
		$post              = new WP_Post();
		$post->ID          = 30;
		$post->post_status = 'draft';
		$post->post_date   = '2026-05-20 10:00:00';

		$GLOBALS['cfcal_test_posts'][30] = $post;

		$request = new WP_REST_Request();
		$request->set_param( 'id', 30 );
		$request->set_param( 'post_date', '2026-05-25' );

		$controller = new RestController();
		$controller->reschedule_post( $request );

		// A draft must keep WP's 0000-00-00 GMT convention — no gmt key sent.
		$this->assertArrayNotHasKey( 'post_date_gmt', $GLOBALS['cfcal_test_last_update'] );
		$this->assertTrue( $GLOBALS['cfcal_test_last_update']['edit_date'] );
	}

	public function test_reschedule_future_writes_post_date_gmt(): void {
		$post              = new WP_Post();
		$post->ID          = 31;
		$post->post_status = 'future';
		$post->post_date   = '2026-05-20 10:00:00';

		$GLOBALS['cfcal_test_posts'][31]    = $post;
		$GLOBALS['cfcal_test_current_time'] = '2026-05-15 12:00:00';

		$request = new WP_REST_Request();
		$request->set_param( 'id', 31 );
		$request->set_param( 'post_date', '2026-05-28' ); // still future

		$controller = new RestController();
		$controller->reschedule_post( $request );

		$this->assertArrayHasKey( 'post_date_gmt', $GLOBALS['cfcal_test_last_update'] );
	}

	public function test_reschedule_post_returns_404_for_missing_post(): void {
		$GLOBALS['cfcal_test_posts'] = []; // no posts

		$request = new WP_REST_Request();
		$request->set_param( 'id', 999 );
		$request->set_param( 'post_date', '2026-05-22 09:00:00' );

		$controller = new RestController();
		$response   = $controller->reschedule_post( $request );

		$this->assertSame( 404, $response->status );
		$this->assertSame( 'not_found', $response->data['code'] );
	}

	public function test_reschedule_post_returns_500_on_wp_error(): void {
		$post              = new WP_Post();
		$post->ID          = 7;
		$post->post_status = 'draft';

		$GLOBALS['cfcal_test_posts'][7]     = $post;
		$GLOBALS['cfcal_test_update_result'] = new WP_Error( 'update_failed', 'Update failed.' );

		$request = new WP_REST_Request();
		$request->set_param( 'id', 7 );
		$request->set_param( 'post_date', '2026-05-22 09:00:00' );

		$controller = new RestController();
		$response   = $controller->reschedule_post( $request );

		$this->assertSame( 500, $response->status );
		$this->assertSame( 'update_failed', $response->data['code'] );
	}

	public function test_reschedule_published_post_to_past_date_keeps_it_published(): void {
		// Moving a published post to an earlier date is a normal back-date edit;
		// it stays published. (current_time shim: 2026-05-15 12:00:00.)
		$post              = new WP_Post();
		$post->ID          = 8;
		$post->post_status = 'publish';
		$post->post_date   = '2026-04-01 09:00:00';

		$GLOBALS['cfcal_test_posts'][8] = $post;

		$request = new WP_REST_Request();
		$request->set_param( 'id', 8 );
		$request->set_param( 'post_date', '2026-04-15 09:00:00' );

		$controller = new RestController();
		$response   = $controller->reschedule_post( $request );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'publish', $GLOBALS['cfcal_test_posts'][8]->post_status );
	}

	public function test_reschedule_published_post_into_the_future_schedules_it(): void {
		// Per product decision: dragging a published post to a future date
		// unpublishes and re-schedules it (the client confirms first).
		$post              = new WP_Post();
		$post->ID          = 9;
		$post->post_status = 'publish';
		$post->post_date   = '2026-04-01 09:00:00';

		$GLOBALS['cfcal_test_posts'][9]     = $post;
		$GLOBALS['cfcal_test_current_time'] = '2026-05-15 12:00:00';

		$request = new WP_REST_Request();
		$request->set_param( 'id', 9 );
		$request->set_param( 'post_date', '2026-06-20' ); // future

		$controller = new RestController();
		$response   = $controller->reschedule_post( $request );

		$this->assertSame( 200, $response->status );
		$this->assertSame( 'future', $GLOBALS['cfcal_test_posts'][9]->post_status );
		// A scheduled post gets a real GMT date.
		$this->assertArrayHasKey( 'post_date_gmt', $GLOBALS['cfcal_test_last_update'] );
	}
}
