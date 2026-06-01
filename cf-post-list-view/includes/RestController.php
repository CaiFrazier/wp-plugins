<?php
/**
 * RestController — registers the cf-plv/v1 REST routes.
 *
 * Routes:
 *   GET /cf-plv/v1/posts       — paginated, filterable post list
 *   GET /cf-plv/v1/post-types  — available public post types + their taxonomies
 *
 * @package CF_Post_List_View
 */

namespace CFPostListView;

class RestController {

	const NAMESPACE       = 'cf-plv/v1';
	const ROUTE           = '/posts';
	const POST_TYPES_ROUTE = '/post-types';

	/**
	 * Maps client-facing column keys to WP_Query orderby strings.
	 * Only sortable columns appear here.
	 */
	const ORDERBY_MAP = [
		'id'             => 'ID',
		'title'          => 'post_title',
		'slug'           => 'post_name',
		'date_published' => 'date',
		'date_modified'  => 'modified',
		'menu_order'     => 'menu_order',
		'comment_count'  => 'comment_count',
		'post_parent_id' => 'post_parent',
	];

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_posts' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->posts_args(),
			]
		);

		register_rest_route(
			self::NAMESPACE,
			self::POST_TYPES_ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_post_types' ],
				'permission_callback' => [ $this, 'check_permission' ],
			]
		);
	}

	public function check_permission(): bool {
		return current_user_can( 'edit_posts' );
	}

	// -------------------------------------------------------------------------
	// GET /cf-plv/v1/posts
	// -------------------------------------------------------------------------

	public function get_posts( \WP_REST_Request $request ): \WP_REST_Response {
		$page        = (int) $request->get_param( 'page' );
		$per_page    = (int) $request->get_param( 'per_page' );
		$search      = (string) $request->get_param( 'search' );
		$post_type   = (string) $request->get_param( 'post_type' );
		$post_status = (string) $request->get_param( 'post_status' );
		$orderby_key = (string) $request->get_param( 'orderby' );
		$order       = strtoupper( (string) $request->get_param( 'order' ) );
		$columns_raw = $request->get_param( 'columns' );

		// Validate and sanitize columns.
		$columns = [];
		if ( is_array( $columns_raw ) ) {
			foreach ( $columns_raw as $col ) {
				$col = sanitize_key( $col );
				if ( $col && ColumnRegistry::is_valid( $col ) ) {
					$columns[] = $col;
				}
			}
		}
		if ( empty( $columns ) ) {
			$columns = ColumnRegistry::defaults();
		}

		// Validate post type — must be a public post type (not attachment).
		$valid_types = array_keys( $this->get_public_post_types() );
		if ( ! in_array( $post_type, $valid_types, true ) ) {
			$post_type = 'post';
		}

		// Orderby.
		$orderby = isset( self::ORDERBY_MAP[ $orderby_key ] )
			? self::ORDERBY_MAP[ $orderby_key ]
			: 'date';
		$order = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';

		// Post status filter.
		$allowed_statuses = [ 'publish', 'draft', 'pending', 'future', 'private', 'trash' ];
		if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
			// Default: show all non-trash statuses the user can read.
			$post_status = [ 'publish', 'draft', 'pending', 'future', 'private' ];
		}

		$args = [
			'post_type'      => $post_type,
			'post_status'    => $post_status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $order,
		];

		if ( $search ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );
		$items = [];

		foreach ( $query->posts as $post ) {
			if ( ! ( $post instanceof \WP_Post ) ) {
				continue;
			}
			$items[] = PostData::for_post( $post, $columns );
		}

		return new \WP_REST_Response( [
			'items'     => $items,
			'total'     => (int) $query->found_posts,
			'pages'     => (int) $query->max_num_pages,
			'page'      => $page,
			'per_page'  => $per_page,
			'post_type' => $post_type,
			'columns'   => $columns,
		] );
	}

	// -------------------------------------------------------------------------
	// GET /cf-plv/v1/post-types
	// -------------------------------------------------------------------------

	public function get_post_types(): \WP_REST_Response {
		$types  = $this->get_public_post_types();
		$result = [];

		foreach ( $types as $key => $type_obj ) {
			$taxonomies = [];
			foreach ( get_object_taxonomies( $key, 'objects' ) as $tax_key => $tax_obj ) {
				$taxonomies[] = [
					'key'          => $tax_key,
					'label'        => $tax_obj->label,
					'hierarchical' => (bool) $tax_obj->hierarchical,
				];
			}

			$result[] = [
				'key'        => $key,
				'label'      => $type_obj->label,
				'singular'   => $type_obj->labels->singular_name,
				'taxonomies' => $taxonomies,
			];
		}

		return new \WP_REST_Response( $result );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns public, UI-visible post types excluding 'attachment'.
	 *
	 * @return \WP_Post_Type[]
	 */
	private function get_public_post_types(): array {
		$types = get_post_types(
			[
				'public'  => true,
				'show_ui' => true,
			],
			'objects'
		);

		unset( $types['attachment'] );

		return $types;
	}

	/**
	 * Argument schema for the /posts route.
	 *
	 * @return array
	 */
	private function posts_args(): array {
		return [
			'page'        => [
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $v ) {
					return is_numeric( $v ) && (int) $v >= 1;
				},
			],
			'per_page'    => [
				'default'           => 50,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $v ) {
					return is_numeric( $v ) && (int) $v >= 1 && (int) $v <= 200;
				},
			],
			'search'      => [
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'post_type'   => [
				'default'           => 'post',
				'sanitize_callback' => 'sanitize_key',
			],
			'post_status' => [
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			],
			'orderby'     => [
				'default'           => 'date_published',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static function ( $v ) {
					return array_key_exists( $v, RestController::ORDERBY_MAP );
				},
			],
			'order'       => [
				'default'           => 'DESC',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $v ) {
					return in_array( strtoupper( $v ), [ 'ASC', 'DESC' ], true );
				},
			],
			'columns'     => [
				'default' => [],
				'type'    => 'array',
				'items'   => [ 'type' => 'string' ],
			],
		];
	}
}
