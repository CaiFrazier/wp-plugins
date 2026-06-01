<?php

namespace BulkMetaEditor;

defined( 'ABSPATH' ) || exit;

use CFShared\Logger as SharedLogger;

class RestController {

	const REST_NAMESPACE = 'bulk-meta-editor/v1';

	private Settings $settings;
	private SharedLogger $logger;
	private PostDataService $post_data;

	public function __construct( Settings $settings, SharedLogger $logger ) {
		$this->settings  = $settings;
		$this->logger    = $logger;
		$this->post_data = new PostDataService( $settings, $logger );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/post-types',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => $this->wrap( 'get_post_types' ),
				'permission_callback' => [ $this, 'check_edit_posts' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => $this->wrap( 'get_posts' ),
				'permission_callback' => [ $this, 'check_edit_posts' ],
				'args'                => [
					'post_type' => [
						'type'     => 'string',
						'required' => true,
					],
					'page'      => [
						'type'    => 'integer',
						'default' => 1,
					],
					'per_page'  => [
						'type'    => 'integer',
						'default' => 50,
					],
					'status'    => [
						'type'    => 'string',
						'default' => 'any',
					],
					'search'    => [
						'type'    => 'string',
						'default' => '',
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/batch',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => $this->wrap( 'get_posts_batch' ),
					'permission_callback' => [ $this, 'check_edit_posts' ],
					'args'                => [
						'ids' => [
							'type'     => 'array',
							'items'    => [ 'type' => 'integer' ],
							'required' => true,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $this->wrap( 'save_posts_batch' ),
					'permission_callback' => [ $this, 'check_edit_posts' ],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => $this->wrap( 'get_settings' ),
					'permission_callback' => [ $this, 'check_manage_options' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $this->wrap( 'update_settings' ),
					'permission_callback' => [ $this, 'check_manage_options' ],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/column-visibility',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => $this->wrap( 'get_column_visibility' ),
					'permission_callback' => [ $this, 'check_edit_posts' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => $this->wrap( 'update_column_visibility' ),
					'permission_callback' => [ $this, 'check_edit_posts' ],
				],
			]
		);

		// CSV export is POST-only. Large id arrays go in the JSON body (out of
		// access logs, browser history, and referrer headers); the front-end
		// consumes the response as a Blob and triggers an in-page download.
		// A previous revision registered 'GET, POST' as a fallback for
		// window.location.href, but register_rest_route splits that string on
		// ',' and the leading-space ' POST' token never matched — so the GET
		// fallback was effectively dead code. We now register POST cleanly.
		register_rest_route(
			self::REST_NAMESPACE,
			'/export',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'export_csv' ], // streams; cannot be wrapped
				'permission_callback' => [ $this, 'check_edit_posts' ],
				'args'                => [
					'ids'       => [
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
						'required' => false,
					],
					'columns'   => [
						'type'    => 'array',
						'items'   => [ 'type' => 'string' ],
						'default' => [],
					],
					'post_type' => [
						'type'    => 'string',
						'default' => 'post',
					],
				],
			]
		);
	}

	/**
	 * Wrap a callback in entry/exit logging and uncaught-exception capture.
	 * Cannot be used on streaming endpoints (export) since those exit() out.
	 */
	private function wrap( string $method ): callable {
		return function ( \WP_REST_Request $request ) use ( $method ) {
			$started = microtime( true );
			$route   = $request->get_route();

			$this->logger->info(
				'rest',
				"→ {$method}",
				[
					'route'  => $route,
					'method' => $request->get_method(),
					'params' => $this->safe_params( $request ),
				]
			);

			try {
				$response = $this->{$method}( $request );
			} catch ( \Throwable $e ) {
				$this->logger->error(
					'rest',
					"✗ {$method} threw",
					[
						'route'   => $route,
						'message' => $e->getMessage(),
						'file'    => $e->getFile(),
						'line'    => $e->getLine(),
					]
				);
				return new \WP_Error( 'bme_internal', $e->getMessage(), [ 'status' => 500 ] );
			}

			// WP_Error::get_error_data() can return null (when no data was set),
			// a scalar, or an array. Subscripting null with ['status'] warns
			// under PHP 7.4 and throws in PHP 8.1+ strict modes — pull the
			// data into a local and array-check it before indexing.
			if ( is_wp_error( $response ) ) {
				$err_data = $response->get_error_data();
				$status   = ( is_array( $err_data ) && isset( $err_data['status'] ) ) ? (int) $err_data['status'] : 500;
			} else {
				$status = method_exists( $response, 'get_status' ) ? $response->get_status() : 200;
			}

			$this->logger->info(
				'rest',
				"← {$method}",
				[
					'route'       => $route,
					'status'      => $status,
					'duration_ms' => round( ( microtime( true ) - $started ) * 1000, 2 ),
					'is_wp_error' => is_wp_error( $response ),
				]
			);

			return $response;
		};
	}

	private function safe_params( \WP_REST_Request $request ): array {
		$params = $request->get_params();
		// Trim arrays that could be enormous (huge id lists in batch reads).
		foreach ( [ 'ids', 'columns' ] as $k ) {
			if ( isset( $params[ $k ] ) && is_array( $params[ $k ] ) && count( $params[ $k ] ) > 50 ) {
				$params[ $k . '_count' ] = count( $params[ $k ] );
				$params[ $k ]            = array_slice( $params[ $k ], 0, 50 );
			}
		}
		return $params;
	}

	public function check_edit_posts(): bool {
		$ok = current_user_can( 'edit_posts' );
		if ( ! $ok ) {
			$this->logger->warn(
				'rest',
				'permission denied: edit_posts',
				[
					'user_id' => get_current_user_id(),
				]
			);
		}
		return $ok;
	}

	public function check_manage_options(): bool {
		$ok = current_user_can( 'manage_options' );
		if ( ! $ok ) {
			$this->logger->warn(
				'rest',
				'permission denied: manage_options',
				[
					'user_id' => get_current_user_id(),
				]
			);
		}
		return $ok;
	}

	public function get_post_types( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = $this->settings->get();
		$types    = $this->settings->get_public_post_types();
		$out      = [];

		foreach ( $types as $slug => $label ) {
			if ( ! in_array( $slug, $settings['enabled_types'], true ) ) {
				continue;
			}
			$pto = get_post_type_object( $slug );
			if ( ! $pto || ! current_user_can( $pto->cap->edit_posts ) ) {
				continue;
			}
			$out[] = [
				'slug'  => $slug,
				'label' => $label,
			];
		}

		return rest_ensure_response( $out );
	}

	public function get_posts( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->post_data->get_posts_for_picker( $request->get_params() ) );
	}

	public function get_posts_batch( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		return rest_ensure_response( $this->post_data->get_rows( $ids ) );
	}

	public function save_posts_batch( \WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$edits = is_array( $body ) ? $body : [];

		if ( empty( $edits ) ) {
			return new \WP_Error(
				'no_edits',
				__( 'No edits provided.', 'cf-bulk-meta-editor' ),
				[ 'status' => 400 ]
			);
		}

		return rest_ensure_response( $this->post_data->save_batch( $edits ) );
	}

	public function get_settings( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			[
				'settings'  => $this->settings->get(),
				'postTypes' => $this->settings->get_public_post_types(),
				'presets'   => Settings::PRESETS,
			]
		);
	}

	public function update_settings( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'invalid_body',
				__( 'Invalid request body.', 'cf-bulk-meta-editor' ),
				[ 'status' => 400 ]
			);
		}
		$before = $this->settings->get();
		$this->settings->update( $body );
		$after = $this->settings->get();

		$this->logger->info(
			'settings',
			'updated',
			[
				'changed_keys' => array_keys(
					array_diff_assoc(
						$this->flatten( $after ),
						$this->flatten( $before )
					)
				),
			]
		);

		return rest_ensure_response( $after );
	}

	public function get_column_visibility( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->settings->get_column_visibility( get_current_user_id() ) );
	}

	public function update_column_visibility( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		$this->settings->set_column_visibility(
			get_current_user_id(),
			is_array( $body ) ? $body : []
		);
		return rest_ensure_response( $this->settings->get_column_visibility( get_current_user_id() ) );
	}

	public function export_csv( \WP_REST_Request $request ): void {
		// Route is POST-only; payload lives in the JSON body. The
		// $request->get_param() fall-through is kept as defensive plumbing in
		// case args declared in the route are populated via a different source
		// in some WP version, but in practice get_json_params() supplies all
		// three keys.
		$body    = $request->get_json_params();
		$ids     = is_array( $body ) && isset( $body['ids'] ) ? (array) $body['ids'] : (array) $request->get_param( 'ids' );
		$columns = is_array( $body ) && isset( $body['columns'] ) ? (array) $body['columns'] : (array) $request->get_param( 'columns' );
		$pt      = is_array( $body ) && isset( $body['post_type'] ) ? (string) $body['post_type'] : (string) $request->get_param( 'post_type' );

		$ids       = array_map( 'intval', $ids );
		$post_type = sanitize_key( $pt );

		$this->logger->info(
			'export',
			'csv stream start',
			[
				'method'        => $request->get_method(),
				'ids_count'     => count( $ids ),
				'columns_count' => count( $columns ),
				'post_type'     => $post_type,
			]
		);

		$exporter = new CsvExporter( $this->post_data, $this->logger );
		$exporter->stream( $ids, $columns, $post_type );
		exit;
	}

	private function flatten( array $arr, string $prefix = '' ): array {
		$out = [];
		foreach ( $arr as $k => $v ) {
			$key = $prefix ? $prefix . '.' . $k : (string) $k;
			if ( is_array( $v ) ) {
				$out += $this->flatten( $v, $key );
			} else {
				$out[ $key ] = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
			}
		}
		return $out;
	}
}
