<?php

namespace CFContentCalendar;

defined( 'ABSPATH' ) || exit;

class RestController {

	const NAMESPACE = 'cf-cal/v1';

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_posts' ],
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
					'args'                => [
						'start'       => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => [ __CLASS__, 'validate_date' ],
						],
						'end'         => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => [ __CLASS__, 'validate_date' ],
						],
						'post_types'  => [
							'required' => false,
							'type'     => 'array',
							'items'    => [ 'type' => 'string' ],
							'default'  => [],
						],
						'post_status' => [
							'required' => false,
							'type'     => 'array',
							'items'    => [ 'type' => 'string' ],
							'default'  => [ 'publish', 'future', 'draft' ],
						],
						'author'      => [
							'required' => false,
							'type'     => 'integer',
							'default'  => 0,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_post' ],
					'permission_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
					'args'                => [
						'title'     => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'post_type' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						],
						'post_date' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							// Accepts a bare date (draft) or a full datetime
							// (scheduled posts carry a time-of-day).
							'validate_callback' => [ __CLASS__, 'validate_datetime' ],
						],
						'status'    => [
							'required' => false,
							'type'     => 'string',
							'default'  => 'draft',
							'enum'     => [ 'draft', 'future' ],
						],
						'author_id' => [
							'required' => false,
							'type'     => 'integer',
							'default'  => 0,
						],
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/reschedule',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'reschedule_post' ],
				'permission_callback' => static function ( \WP_REST_Request $request ) {
					return current_user_can( 'edit_post', (int) $request->get_param( 'id' ) );
				},
				'args'                => [
					'id'        => [
						'required' => true,
						'type'     => 'integer',
					],
					'post_date' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => [ __CLASS__, 'validate_datetime' ],
					],
				],
			]
		);
	}

	public function get_posts( \WP_REST_Request $request ): \WP_REST_Response {
		$start       = $request->get_param( 'start' );
		$end         = $request->get_param( 'end' );
		$post_types  = (array) $request->get_param( 'post_types' );
		$post_status = (array) $request->get_param( 'post_status' );

		$allowed_types = array_values(
			array_filter(
				array_keys( get_post_types( [ 'public' => true ] ) ),
				static function ( $t ) {
					return 'attachment' !== $t;
				}
			)
		);

		$post_types = empty( $post_types )
			? $allowed_types
			: array_values( array_intersect( $post_types, $allowed_types ) );

		if ( empty( $post_types ) ) {
			return new \WP_REST_Response( [], 200 );
		}

		$allowed_statuses = [ 'publish', 'future', 'draft', 'pending', 'private' ];
		$post_status      = array_values( array_intersect( $post_status, $allowed_statuses ) );
		if ( empty( $post_status ) ) {
			$post_status = [ 'publish', 'future', 'draft' ];
		}

		$query_args = [
			'post_type'      => $post_types,
			'post_status'    => $post_status,
			// A bounded date range, not user-facing pagination. The cap is a
			// safety ceiling for an unusually busy month; CCAL-P2-005 tracks
			// surfacing a "results truncated" notice when it is hit.
			'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page

			'date_query'     => [
				[
					// A bare `before` date string resolves to 00:00:00 even with
					// `inclusive`, which would drop every post with a time-of-day
					// on the range's last day (e.g. week view's final column).
					// Pin it to end-of-day so the whole final day is covered.
					'after'     => $start . ' 00:00:00',
					'before'    => $end . ' 23:59:59',
					'inclusive' => true,
				],
			],
			'orderby'        => 'date',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			// Enforce read capabilities. Without this, an explicit
			// post_status of 'private' would return other authors' private
			// posts to a role that has edit_others_posts but lacks
			// read_private_posts.
			'perm'           => 'readable',
		];

		// Mirror core wp-admin behaviour: a user who cannot edit others' posts
		// only sees their own. Without this the calendar would expose every
		// author's drafts to every Contributor. The author filter is honoured
		// only for users who are allowed to see other authors' posts.
		$author = (int) $request->get_param( 'author' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$query_args['author'] = get_current_user_id();
		} elseif ( $author > 0 ) {
			$query_args['author'] = $author;
		}

		$query = new \WP_Query( $query_args );

		$posts = [];
		foreach ( $query->posts as $post ) {
			$posts[] = $this->format_post( $post );
		}

		return new \WP_REST_Response( $posts, 200 );
	}

	public function create_post( \WP_REST_Request $request ): \WP_REST_Response {
		$title     = $request->get_param( 'title' );
		$post_type = $request->get_param( 'post_type' );
		$post_date = $request->get_param( 'post_date' );
		$status    = $request->get_param( 'status' );
		$author_id = (int) $request->get_param( 'author_id' );

		$allowed = array_filter(
			array_keys( get_post_types( [ 'public' => true ] ) ),
			static function ( $t ) {
				return 'attachment' !== $t;
			}
		);

		if ( ! in_array( $post_type, $allowed, true ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'invalid_post_type',
					'message' => __( 'Invalid post type.', 'cf-content-calendar' ),
				],
				400
			);
		}

		// Enforce the post type's own create capability. The route-level
		// permission_callback only guarantees the generic edit_posts cap, which
		// would let a Contributor create Pages or other CPTs they cannot touch.
		$pt_object  = get_post_type_object( $post_type );
		$create_cap = ( $pt_object && isset( $pt_object->cap->create_posts ) )
			? $pt_object->cap->create_posts
			: 'edit_posts';
		if ( ! current_user_can( $create_cap ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'insufficient_capabilities',
					'message' => __( 'You do not have permission to create this post type.', 'cf-content-calendar' ),
				],
				403
			);
		}

		if ( 'future' === $status && ! current_user_can( 'publish_posts' ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'insufficient_capabilities',
					'message' => __( 'You do not have permission to schedule posts.', 'cf-content-calendar' ),
				],
				403
			);
		}

		// A bare date is treated as midnight. Scheduling for a day that is
		// already past (or today-at-midnight, already elapsed) would make
		// wp_insert_post silently PUBLISH a backdated post instead of scheduling
		// it. Reject rather than surprise the user with a live, backdated post.
		$scheduled_time = strtotime( $post_date );
		if ( 'future' === $status
			&& ( false === $scheduled_time || $scheduled_time <= strtotime( current_time( 'mysql' ) ) ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'date_in_past',
					'message' => __( 'A scheduled post needs a date and time in the future.', 'cf-content-calendar' ),
				],
				400
			);
		}

		$post_data = [
			'post_title'  => $title,
			'post_type'   => $post_type,
			'post_status' => $status,
			'post_date'   => $post_date,
		];

		if ( $author_id > 0 && current_user_can( 'edit_others_posts' ) ) {
			$post_data['post_author'] = $author_id;
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'insert_failed',
					'message' => $post_id->get_error_message(),
				],
				500
			);
		}

		return new \WP_REST_Response( $this->format_post( get_post( $post_id ) ), 201 );
	}

	public function reschedule_post( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id   = (int) $request->get_param( 'id' );
		$post_date = $request->get_param( 'post_date' );
		$post      = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_REST_Response(
				[
					'code'    => 'not_found',
					'message' => __( 'Post not found.', 'cf-content-calendar' ),
				],
				404
			);
		}

		// The client sends a date (YYYY-MM-DD). Preserve the post's original
		// time-of-day so a post scheduled for 09:00 doesn't jump to midnight
		// when it's dragged to a different day.
		$date_part = substr( $post_date, 0, 10 );
		if ( strlen( $post_date ) > 10 ) {
			$time_part = substr( $post_date, 11, 8 );
		} elseif ( $post->post_date && '0000-00-00 00:00:00' !== $post->post_date ) {
			$time_part = substr( $post->post_date, 11, 8 );
		} else {
			$time_part = '00:00:00';
		}
		// Guard against a malformed/short stored post_date leaving a broken
		// time fragment (e.g. "2026-05-25 " with an empty time).
		if ( ! preg_match( '/^\d{2}:\d{2}:\d{2}$/', $time_part ) ) {
			$time_part = '00:00:00';
		}
		$new_post_date = $date_part . ' ' . $time_part;

		// Decide the resulting status.
		$is_future_date = strtotime( $new_post_date ) > strtotime( current_time( 'mysql' ) );
		$new_status     = $post->post_status;
		if ( 'future' === $post->post_status ) {
			// A scheduled post keeps future only while its date is still ahead.
			$new_status = $is_future_date ? 'future' : 'draft';
		} elseif ( 'publish' === $post->post_status && $is_future_date ) {
			// A published post dragged into the future is unpublished and
			// re-scheduled. The client confirms this intent before calling.
			$new_status = 'future';
		}

		// edit_date => true is REQUIRED: without it wp_insert_post() silently
		// ignores a post_date change on an existing published/scheduled post.
		$update = [
			'ID'          => $post_id,
			'post_date'   => $new_post_date,
			'edit_date'   => true,
			'post_status' => $new_status,
		];

		// Only stamp a real GMT date for statuses that actually have one. WP
		// keeps a draft's post_date_gmt at 0000-00-00 until it is scheduled or
		// published; faking it here would make sitemap/SEO plugins treat an
		// unscheduled draft as having a publish date.
		if ( 'future' === $new_status || 'publish' === $new_status ) {
			$update['post_date_gmt'] = get_gmt_from_date( $new_post_date );
		} else {
			// wp_update_post() merges $update with the post's EXISTING row
			// before saving, so a scheduled post downgrading to draft would
			// otherwise keep its old (now stale) post_date_gmt from when it
			// was still future/publish.
			$update['post_date_gmt'] = '0000-00-00 00:00:00';
		}

		$result = wp_update_post(
			$update,
			true
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'update_failed',
					'message' => $result->get_error_message(),
				],
				500
			);
		}

		return new \WP_REST_Response( $this->format_post( get_post( $post_id ) ), 200 );
	}

	private function format_post( \WP_Post $post ): array {
		// `date` is the site-local post_date, which is the only date the client
		// uses. `post_date_gmt` is deliberately omitted: it is 0000-00-00 for
		// drafts (an Invalid-Date footgun) and the client never reads it.
		return [
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'post_type' => $post->post_type,
			'date'      => $post->post_date,
			'author'    => (int) $post->post_author,
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
		];
	}

	public static function validate_date( $value ): bool {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		// Shape alone isn't enough — reject impossible calendar dates
		// (2026-13-40, 2026-02-31) before they reach wp_insert_post, which would
		// silently normalize them to a different, wrong date.
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	public static function validate_datetime( $value ): bool {
		if ( ! is_string( $value )
			|| ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})( (\d{2}):(\d{2}):(\d{2}))?$/', $value, $m ) ) {
			return false;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return false;
		}
		// Validate the optional time component's ranges when present.
		if ( isset( $m[4] ) && '' !== $m[4] ) {
			return (int) $m[5] < 24 && (int) $m[6] < 60 && (int) $m[7] < 60;
		}
		return true;
	}
}
