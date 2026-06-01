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
							'validate_callback' => [ __CLASS__, 'validate_date' ],
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
					'after'     => $start,
					'before'    => $end,
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
		$new_post_date = $date_part . ' ' . $time_part;

		// When moving a scheduled post: keep future status if the new date is
		// still ahead of now; otherwise it can no longer be "scheduled".
		$new_status = $post->post_status;
		if ( 'future' === $post->post_status ) {
			$new_status = ( strtotime( $new_post_date ) > strtotime( current_time( 'mysql' ) ) )
				? 'future'
				: 'draft';
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
		return [
			'id'        => $post->ID,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'post_type' => $post->post_type,
			'date'      => $post->post_date,
			'date_gmt'  => $post->post_date_gmt,
			'author'    => (int) $post->post_author,
			'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
		];
	}

	public static function validate_date( $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}

	public static function validate_datetime( $value ): bool {
		return is_string( $value )
			&& (bool) preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value );
	}
}
