<?php
/**
 * LibraryRestController — REST API endpoint that powers the Media Library
 * list view table.
 *
 * Route: GET /wp-json/cf-media-manager/v1/library
 *
 * Capability gate: `upload_files` — intentionally more permissive than the
 * Media Manager settings page so editors and content managers can audit the
 * media library without being granted manage_options.
 */

namespace CFMediaManager;

defined( 'ABSPATH' ) || exit;

class LibraryRestController {

	const REST_NAMESPACE = 'cf-media-manager/v1';
	const ROUTE          = '/library';

	/**
	 * Orderby values accepted from the client mapped to WP_Query orderby strings.
	 */
	const ORDERBY_MAP = array(
		'id'                 => 'ID',
		'title'              => 'title',
		'slug'               => 'name',
		'date_uploaded'      => 'date',
		'date_uploaded_gmt'  => 'date',
		'date_modified'      => 'modified',
		'date_modified_gmt'  => 'modified',
		'menu_order'         => 'menu_order',
	);

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_attachments' ),
				'permission_callback' => static fn() => current_user_can( 'upload_files' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 200,
						'sanitize_callback' => 'absint',
					),
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'mime'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'parent'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'orderby'  => array(
						'type'    => 'string',
						'default' => 'id',
						'enum'    => array_keys( self::ORDERBY_MAP ),
					),
					'order'    => array(
						'type'    => 'string',
						'default' => 'DESC',
						'enum'    => array( 'ASC', 'DESC' ),
					),
					'columns'  => array(
						'type'              => 'array',
						'items'             => array( 'type' => 'string' ),
						'default'           => array(),
						'sanitize_callback' => static fn( $v ) => array_map( 'sanitize_key', (array) $v ),
					),
				),
			)
		);
	}

	public function get_attachments( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );
		$search   = (string) $request->get_param( 'search' );
		$mime     = (string) $request->get_param( 'mime' );
		$parent   = (string) $request->get_param( 'parent' );
		$orderby  = (string) $request->get_param( 'orderby' );
		$order    = (string) $request->get_param( 'order' );
		$columns  = (array) $request->get_param( 'columns' );

		// Validate columns against registry; fall back to defaults.
		$valid   = LibraryColumnRegistry::valid_keys();
		$columns = array_values( array_intersect( $columns, $valid ) );
		if ( empty( $columns ) ) {
			$columns = LibraryColumnRegistry::defaults();
		}

		$wp_orderby = self::ORDERBY_MAP[ $orderby ] ?? 'ID';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $wp_orderby,
			'order'          => $order,
			'no_found_rows'  => false,
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		if ( '' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}

		if ( 'unattached' === $parent ) {
			$args['post_parent'] = 0;
		}

		$query = new \WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$items[] = LibraryAttachmentData::for_post( $post, $columns );
			}
		}

		return new \WP_REST_Response(
			array(
				'items'    => $items,
				'total'    => (int) $query->found_posts,
				'pages'    => (int) $query->max_num_pages,
				'page'     => $page,
				'per_page' => $per_page,
				'columns'  => $columns,
			)
		);
	}
}
