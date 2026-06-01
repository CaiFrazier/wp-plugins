<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

class RestController {

	/**
	 * Maximum accepted JSON payload size for write endpoints (bytes).
	 * Filterable. Generous default — JSON-LD blocks rarely exceed a few KB even
	 * with rich Article + FAQPage + BreadcrumbList combinations.
	 */
	const MAX_PAYLOAD_BYTES = 524288; // 512 KB

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Reject payloads larger than the configured cap. Callers should bail
	 * with the returned response if non-null.
	 */
	private function payload_too_large( \WP_REST_Request $request ): ?\WP_REST_Response {
		$max = (int) apply_filters( 'som_max_payload_bytes', self::MAX_PAYLOAD_BYTES );
		$raw = (string) $request->get_body();
		if ( strlen( $raw ) > $max ) {
			Logger::instance()->warn( 'rest', 'Payload over cap, rejected', [
				'route' => $request->get_route(),
				'bytes' => strlen( $raw ),
				'max'   => $max,
			] );
			return new \WP_REST_Response(
				[
					'error' => sprintf(
						'Payload exceeds %d-byte limit (got %d).',
						$max,
						strlen( $raw )
					),
				],
				413
			);
		}
		return null;
	}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$namespace = 'som/v1';

		// Global schema.
		register_rest_route( $namespace, '/global-schema', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_global_schema' ],
				'permission_callback' => [ $this, 'manage_options_permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_global_schema' ],
				'permission_callback' => [ $this, 'manage_options_permission' ],
			],
		] );

		// Global suppression.
		register_rest_route( $namespace, '/global-suppression', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_global_suppression' ],
				'permission_callback' => [ $this, 'manage_options_permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_global_suppression' ],
				'permission_callback' => [ $this, 'manage_options_permission' ],
			],
		] );

		// Plugin settings.
		register_rest_route( $namespace, '/settings', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_settings' ],
				'permission_callback' => [ $this, 'manage_options_permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_settings' ],
				'permission_callback' => [ $this, 'manage_options_permission' ],
			],
		] );

		// CPT template schema.
		register_rest_route( $namespace, '/template/(?P<post_type>[a-z0-9_-]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_template_schema' ],
				'permission_callback' => [ $this, 'manage_options_permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_template_schema' ],
				'permission_callback' => [ $this, 'manage_options_permission' ],
			],
		] );

		// Per-page schema.
		register_rest_route( $namespace, '/page/(?P<post_id>\d+)/schema', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_page_schema' ],
				'permission_callback' => [ $this, 'edit_post_permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_page_schema' ],
				'permission_callback' => [ $this, 'edit_post_permission' ],
			],
		] );

		// Per-page suppression.
		register_rest_route( $namespace, '/page/(?P<post_id>\d+)/suppression', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_page_suppression' ],
				'permission_callback' => [ $this, 'edit_post_permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_page_suppression' ],
				'permission_callback' => [ $this, 'edit_post_permission' ],
			],
		] );

		// Schema preview (computed output for a given post).
		register_rest_route( $namespace, '/page/(?P<post_id>\d+)/preview', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_schema_preview' ],
				'permission_callback' => [ $this, 'edit_post_permission' ],
			],
		] );

		// Detected schema from Yoast / Rank Math (filter-based, fast, may be incomplete in REST context).
		register_rest_route( $namespace, '/page/(?P<post_id>\d+)/detected', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_detected_schema' ],
				'permission_callback' => [ $this, 'edit_post_permission' ],
			],
		] );

		// Live-rendered JSON-LD on the page (HTTP subrequest + parse). Slower, accurate.
		register_rest_route( $namespace, '/page/(?P<post_id>\d+)/detected-live', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_live_detected_schema' ],
				'permission_callback' => [ $this, 'edit_post_permission' ],
			],
		] );
	}

	// --- Handlers ---

	public function get_global_schema(): \WP_REST_Response {
		return rest_ensure_response( $this->settings->get_global_schema() );
	}

	public function save_global_schema( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $bail = $this->payload_too_large( $request ) ) {
			return $bail;
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_global_schema( $data );
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_global_suppression(): \WP_REST_Response {
		return rest_ensure_response( $this->settings->get_global_suppression() );
	}

	public function save_global_suppression( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $bail = $this->payload_too_large( $request ) ) {
			return $bail;
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_global_suppression( $data );
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( $this->settings->get_settings() );
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $bail = $this->payload_too_large( $request ) ) {
			return $bail;
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_settings( $data );
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_template_schema( \WP_REST_Request $request ): \WP_REST_Response {
		$post_type = sanitize_key( $request->get_param( 'post_type' ) );
		return rest_ensure_response( $this->settings->get_template_schema( $post_type ) );
	}

	public function save_template_schema( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $bail = $this->payload_too_large( $request ) ) {
			return $bail;
		}
		$post_type = sanitize_key( $request->get_param( 'post_type' ) );
		$data      = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_template_schema( $post_type, $data );
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_page_schema( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		return rest_ensure_response( $this->settings->get_page_schema( $post_id ) );
	}

	public function save_page_schema( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $bail = $this->payload_too_large( $request ) ) {
			return $bail;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		$data    = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_page_schema( $post_id, $data );
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_page_suppression( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		return rest_ensure_response( $this->settings->get_page_suppression( $post_id ) );
	}

	public function save_page_suppression( \WP_REST_Request $request ): \WP_REST_Response {
		if ( $bail = $this->payload_too_large( $request ) ) {
			return $bail;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		$data    = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_page_suppression( $post_id, $data );
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_schema_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$output  = new SchemaOutput( $this->settings );
		return rest_ensure_response( $output->compute_preview( $post_id ) );
	}

	public function get_detected_schema( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id  = (int) $request->get_param( 'post_id' );
		$detector = new SchemaDetector();
		return rest_ensure_response( $detector->detect_for_post( $post_id ) );
	}

	public function get_live_detected_schema( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
		}

		if ( 'publish' !== $post->post_status ) {
			return new \WP_REST_Response(
				[
					'error' => sprintf(
						'Live fetch is only available for published posts. Current status: %s.',
						$post->post_status
					),
				],
				409
			);
		}

		$url = get_permalink( $post_id );

		if ( ! $url ) {
			return new \WP_REST_Response( [ 'error' => 'Post has no permalink' ], 400 );
		}

		// SSRF guard: refuse to fetch anything that doesn't sit under our own home_url.
		// A redirect plugin (or bad data) could otherwise turn this endpoint into a
		// general-purpose proxy fetching arbitrary URLs.
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $home_host || ! $url_host || strcasecmp( $home_host, $url_host ) !== 0 ) {
			return new \WP_REST_Response(
				[ 'error' => 'Refusing to fetch URL outside this site host.' ],
				400
			);
		}

		$response = wp_remote_get( $url, [
			'timeout'     => 15,
			// No redirects — if the post's permalink redirects, we treat that as
			// an error rather than silently following anywhere on the network.
			'redirection' => 0,
			'sslverify'   => apply_filters( 'som_live_fetch_sslverify', true ),
			'user-agent'  => 'SchemaOverrideManager/' . SOM_VERSION . '; ' . home_url(),
			'headers'     => [
				'X-SOM-Live-Fetch' => '1',
			],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::instance()->warn( 'live-fetch', 'wp_remote_get failed', [
				'url'   => $url,
				'error' => $response->get_error_message(),
			] );
			return new \WP_REST_Response(
				[ 'error' => $response->get_error_message() ],
				502
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 400 || '' === $body ) {
			Logger::instance()->warn( 'live-fetch', 'Bad response', [
				'url'    => $url,
				'status' => $code,
				'bytes'  => strlen( $body ),
			] );
			return new \WP_REST_Response(
				[
					'error'  => sprintf( 'Live fetch returned HTTP %d', $code ),
					'status' => $code,
				],
				502
			);
		}

		Logger::instance()->debug( 'live-fetch', 'Fetched live page', [
			'url'   => $url,
			'bytes' => strlen( $body ),
		] );

		$result        = $this->classify_live_blocks( $body, SchemaOutput::OUTPUT_MARKER );
		$result['url'] = $url;

		return rest_ensure_response( $result );
	}

	private function classify_live_blocks( string $html, string $marker ): array {
		$ours  = [];
		$other = [];

		preg_match_all(
			'#<script\s+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$html,
			$matches
		);

		foreach ( $matches[1] as $raw ) {
			$is_ours = ( false !== strpos( $raw, $marker ) );
			$decoded = json_decode( trim( str_replace( $marker, '', $raw ) ), true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			// Flatten @graph wrappers so each typed node surfaces individually.
			$nodes = Util::flatten_json_ld( $decoded );

			foreach ( $nodes as $node ) {
				$type  = Util::normalize_schema_type( $node['@type'] ?? '' );
				$entry = [
					'type' => $type ?: '(untyped)',
					'data' => $node,
				];
				if ( $is_ours ) {
					$ours[] = $entry;
				} else {
					$other[] = $entry;
				}
			}
		}

		return [ 'ours' => $ours, 'other' => $other ];
	}

	// --- Permissions ---

	public function manage_options_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function edit_post_permission( \WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return current_user_can( 'edit_post', $post_id );
	}
}
