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

	/**
	 * Live-fetch throttling. The detected-live endpoint makes a synchronous
	 * outgoing HTTP request from a REST handler; without a cooldown a held-open
	 * editor sidebar (or two tabs) can self-DoS the site. Both filterable.
	 */
	const LIVE_FETCH_COOLDOWN_SECONDS = 5;
	const LIVE_FETCH_CACHE_TTL        = 60;

	/**
	 * Hard cap on the live-fetch response body, 2 MB in bytes. Filterable.
	 */
	const LIVE_FETCH_MAX_RESPONSE_BYTES = 2097152;

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Reject payloads larger than the configured cap. Callers should bail
	 * with the returned response if non-null.
	 *
	 * @param \WP_REST_Request $request Incoming write request.
	 * @return \WP_REST_Response|null 413 response when over cap, null otherwise.
	 */
	private function payload_too_large( \WP_REST_Request $request ): ?\WP_REST_Response {
		$max = (int) apply_filters( 'som_max_payload_bytes', self::MAX_PAYLOAD_BYTES );
		$raw = (string) $request->get_body();
		if ( strlen( $raw ) > $max ) {
			Logger::instance()->warn(
				'rest',
				'Payload over cap, rejected',
				[
					'route' => $request->get_route(),
					'bytes' => strlen( $raw ),
					'max'   => $max,
				]
			);
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
		// Live-fetch results are cached per post; a save makes them stale.
		add_action( 'save_post', [ $this, 'invalidate_live_fetch_cache' ] );
	}

	/**
	 * Drop the cached live-fetch result for a post (hooked to save_post).
	 * Autosaves are ignored, and a revision save resolves to its parent so the
	 * real post's cache — keyed on the parent id — is the one cleared.
	 *
	 * @internal
	 *
	 * @param int $post_id Saved post (may be a revision).
	 */
	public function invalidate_live_fetch_cache( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$parent = wp_is_post_revision( $post_id );
		if ( $parent ) {
			$post_id = (int) $parent;
		}
		delete_transient( $this->result_cache_key( $post_id ) );
	}

	/**
	 * Per-post live-fetch result cache key, namespaced by a global generation
	 * counter. A change to global schema / suppression / settings / a CPT
	 * template bumps the generation, so every per-post cached result is
	 * orphaned at once (they expire on their own TTL) without enumerating them.
	 *
	 * @param int $post_id Post the result is cached for.
	 */
	private function result_cache_key( int $post_id ): string {
		$generation = (int) get_option( 'som_livefetch_generation', 0 );
		return "som_livefetch_result_{$post_id}_{$generation}";
	}

	/**
	 * Invalidate every per-post live-fetch cache by advancing the generation.
	 * Called after any save that changes what the rendered page would emit.
	 */
	private function bump_live_fetch_generation(): void {
		$generation = (int) get_option( 'som_livefetch_generation', 0 );
		update_option( 'som_livefetch_generation', $generation + 1, false );
	}

	public function register_routes(): void {
		$namespace = 'som/v1';

		// Global schema.
		register_rest_route(
			$namespace,
			'/global-schema',
			[
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
			]
		);

		// Global suppression.
		register_rest_route(
			$namespace,
			'/global-suppression',
			[
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
			]
		);

		// Plugin settings.
		register_rest_route(
			$namespace,
			'/settings',
			[
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
			]
		);

		// CPT template schema.
		register_rest_route(
			$namespace,
			'/template/(?P<post_type>[a-z0-9_-]+)',
			[
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
			]
		);

		// Per-page schema.
		register_rest_route(
			$namespace,
			'/page/(?P<post_id>\d+)/schema',
			[
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
			]
		);

		// Per-page suppression.
		register_rest_route(
			$namespace,
			'/page/(?P<post_id>\d+)/suppression',
			[
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
			]
		);

		// Schema preview (computed output for a given post).
		register_rest_route(
			$namespace,
			'/page/(?P<post_id>\d+)/preview',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_schema_preview' ],
					'permission_callback' => [ $this, 'edit_post_permission' ],
				],
			]
		);

		// Detected schema from Yoast / Rank Math (filter-based, fast, may be incomplete in REST context).
		register_rest_route(
			$namespace,
			'/page/(?P<post_id>\d+)/detected',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_detected_schema' ],
					'permission_callback' => [ $this, 'edit_post_permission' ],
				],
			]
		);

		// Live-rendered JSON-LD on the page (HTTP subrequest + parse). Slower, accurate.
		register_rest_route(
			$namespace,
			'/page/(?P<post_id>\d+)/detected-live',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_live_detected_schema' ],
					'permission_callback' => [ $this, 'edit_post_permission' ],
				],
			]
		);
	}

	// --- Handlers ---

	/**
	 * GET /global-schema.
	 */
	public function get_global_schema(): \WP_REST_Response {
		return rest_ensure_response( $this->settings->get_global_schema() );
	}

	public function save_global_schema( \WP_REST_Request $request ): \WP_REST_Response {
		$bail = $this->payload_too_large( $request );
		if ( $bail ) {
			return $bail;
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_global_schema( $data );
		$this->bump_live_fetch_generation();
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_global_suppression(): \WP_REST_Response {
		return rest_ensure_response( $this->settings->get_global_suppression() );
	}

	public function save_global_suppression( \WP_REST_Request $request ): \WP_REST_Response {
		$bail = $this->payload_too_large( $request );
		if ( $bail ) {
			return $bail;
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_global_suppression( $data );
		$this->bump_live_fetch_generation();
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_settings(): \WP_REST_Response {
		return rest_ensure_response( $this->settings->get_settings() );
	}

	public function save_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$bail = $this->payload_too_large( $request );
		if ( $bail ) {
			return $bail;
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_settings( $data );
		$this->bump_live_fetch_generation();
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_template_schema( \WP_REST_Request $request ): \WP_REST_Response {
		$post_type = sanitize_key( $request->get_param( 'post_type' ) );
		return rest_ensure_response( $this->settings->get_template_schema( $post_type ) );
	}

	public function save_template_schema( \WP_REST_Request $request ): \WP_REST_Response {
		$bail = $this->payload_too_large( $request );
		if ( $bail ) {
			return $bail;
		}
		$post_type = sanitize_key( $request->get_param( 'post_type' ) );
		$data      = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_template_schema( $post_type, $data );
		$this->bump_live_fetch_generation();
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_page_schema( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		return rest_ensure_response( $this->settings->get_page_schema( $post_id ) );
	}

	public function save_page_schema( \WP_REST_Request $request ): \WP_REST_Response {
		$bail = $this->payload_too_large( $request );
		if ( $bail ) {
			return $bail;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		$data    = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_page_schema( $post_id, $data );
		// update_post_meta does not fire save_post, so clear this post's live
		// cache directly.
		delete_transient( $this->result_cache_key( $post_id ) );
		return rest_ensure_response( [ 'saved' => true ] );
	}

	public function get_page_suppression( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		return rest_ensure_response( $this->settings->get_page_suppression( $post_id ) );
	}

	public function save_page_suppression( \WP_REST_Request $request ): \WP_REST_Response {
		$bail = $this->payload_too_large( $request );
		if ( $bail ) {
			return $bail;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		$data    = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid data' ], 400 );
		}
		$this->settings->save_page_suppression( $post_id, $data );
		delete_transient( $this->result_cache_key( $post_id ) );
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

		// Result cache: repeat calls within the TTL return the last parse
		// without another subrequest. Invalidated on post save and on any
		// global change (via the generation counter in the key).
		$cached = get_transient( $this->result_cache_key( $post_id ) );
		if ( is_array( $cached ) ) {
			$cached['cached'] = true;
			return rest_ensure_response( $cached );
		}

		// Per-user cooldown between uncached fetches. The transient stores its
		// own expiry timestamp so the error can say how long to wait.
		$user_id  = get_current_user_id();
		$cooldown = get_transient( "som_livefetch_cooldown_{$user_id}" );
		if ( false !== $cooldown ) {
			$retry   = max( 1, (int) $cooldown - time() );
			$message = sprintf( 'Rate limited. Try again in %d seconds.', $retry );
			return new \WP_REST_Response(
				[
					// `message` is what wp.apiFetch surfaces to the sidebar;
					// `error` matches the shape of this controller's other errors.
					'message'     => $message,
					'error'       => $message,
					'retry_after' => $retry,
				],
				429
			);
		}

		// Arm the cooldown here — before the permalink/SSRF checks and the fetch,
		// not just before the successful path. A host-mismatch stack (WPML
		// language subdomains, a CDN/headless permalink filter) would otherwise
		// reject on the SSRF guard every time with no throttle, letting the
		// sidebar hammer this endpoint. Every non-cached call now costs a cooldown.
		$cooldown_ttl = max( 1, (int) apply_filters( 'som_livefetch_cooldown_seconds', self::LIVE_FETCH_COOLDOWN_SECONDS ) );
		set_transient( "som_livefetch_cooldown_{$user_id}", time() + $cooldown_ttl, $cooldown_ttl );

		$url = get_permalink( $post_id );

		if ( ! $url ) {
			return new \WP_REST_Response( [ 'error' => 'Post has no permalink' ], 400 );
		}

		// SSRF guard: refuse to fetch anything that doesn't sit under our own
		// home_url. A redirect plugin (or bad data) could otherwise turn this
		// endpoint into a general-purpose proxy. Compare host AND port so a
		// permalink steered to `same-host:8080` can't reach another service
		// bound to a different port on the same box.
		$home_parts = wp_parse_url( home_url() );
		$url_parts  = wp_parse_url( $url );
		$home_host  = $home_parts['host'] ?? '';
		$url_host   = $url_parts['host'] ?? '';
		$home_port  = $home_parts['port'] ?? null;
		$url_port   = $url_parts['port'] ?? null;
		if ( '' === $home_host || '' === $url_host
			|| strcasecmp( $home_host, $url_host ) !== 0
			|| $home_port !== $url_port ) {
			return new \WP_REST_Response(
				[ 'error' => 'Refusing to fetch URL outside this site host.' ],
				400
			);
		}

		// Cap the response body so a hostile or accidentally huge page can't
		// OOM the request or bloat the stored transient. Filterable.
		$max_bytes = max( 1, (int) apply_filters( 'som_livefetch_max_response_bytes', self::LIVE_FETCH_MAX_RESPONSE_BYTES ) );

		$response = wp_remote_get(
			$url,
			[
				'timeout'             => 15,
				// No redirects — if the post's permalink redirects, we treat that as
				// an error rather than silently following anywhere on the network.
				'redirection'         => 0,
				'limit_response_size' => $max_bytes,
				'sslverify'           => apply_filters( 'som_live_fetch_sslverify', true ),
				'user-agent'          => 'SchemaOverrideManager/' . SOM_VERSION . '; ' . home_url(),
				'headers'             => [
					'X-SOM-Live-Fetch' => '1',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::instance()->warn(
				'live-fetch',
				'wp_remote_get failed',
				[
					'url'   => $url,
					'error' => $response->get_error_message(),
				]
			);
			return new \WP_REST_Response(
				[ 'error' => $response->get_error_message() ],
				502
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 400 || '' === $body ) {
			Logger::instance()->warn(
				'live-fetch',
				'Bad response',
				[
					'url'    => $url,
					'status' => $code,
					'bytes'  => strlen( $body ),
				]
			);
			return new \WP_REST_Response(
				[
					'error'  => sprintf( 'Live fetch returned HTTP %d', $code ),
					'status' => $code,
				],
				502
			);
		}

		Logger::instance()->debug(
			'live-fetch',
			'Fetched live page',
			[
				'url'   => $url,
				'bytes' => strlen( $body ),
			]
		);

		$result        = Integrations\ThemeIntegration::classify_json_ld_blocks( $body, SchemaOutput::OUTPUT_MARKER );
		$result['url'] = $url;

		$cache_ttl = max( 1, (int) apply_filters( 'som_livefetch_cache_ttl', self::LIVE_FETCH_CACHE_TTL ) );
		set_transient( $this->result_cache_key( $post_id ), $result, $cache_ttl );

		return rest_ensure_response( $result );
	}

	// --- Permissions ---

	/**
	 * Gate for site-level routes.
	 */
	public function manage_options_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function edit_post_permission( \WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return current_user_can( 'edit_post', $post_id );
	}
}
