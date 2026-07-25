<?php

namespace BulkMetaEditor;

defined( 'ABSPATH' ) || exit;

use CFShared\Logger as SharedLogger;

class DiagnosticsController {

	private Settings $settings;
	private SharedLogger $logger;

	public function __construct( Settings $settings, SharedLogger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/diagnostics/environment',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_environment' ],
				'permission_callback' => [ $this, 'check_admin' ],
			]
		);

		register_rest_route(
			RestController::REST_NAMESPACE,
			'/diagnostics/log',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_log' ],
					'permission_callback' => [ $this, 'check_admin' ],
					'args'                => [
						'lines' => [
							'type'    => 'integer',
							'default' => 200,
						],
						'level' => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'clear_log' ],
					'permission_callback' => [ $this, 'check_admin' ],
				],
			]
		);

		register_rest_route(
			RestController::REST_NAMESPACE,
			'/diagnostics/client-log',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'ingest_client_log' ],
				// Admins only — only admins benefit from client log mirroring,
				// and a non-admin Editor would otherwise be able to flood the
				// server log file with arbitrary noise.
				'permission_callback' => [ $this, 'check_admin' ],
			]
		);

		register_rest_route(
			RestController::REST_NAMESPACE,
			'/diagnostics/self-test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'run_self_test' ],
				'permission_callback' => [ $this, 'check_admin' ],
				'args'                => [
					'post_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);
	}

	public function check_admin(): bool {
		$ok = current_user_can( 'manage_options' );
		if ( ! $ok ) {
			$this->logger->warn( 'diag', 'permission denied: manage_options' );
		}
		return $ok;
	}

	public function get_environment(): \WP_REST_Response {
		global $wp_version;

		$active = (array) get_option( 'active_plugins', [] );
		if ( is_multisite() ) {
			$active = array_unique( array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) ) );
		}

		$seo_plugins = $this->detect_seo_plugins( $active );

		$opts = $this->settings->get();

		return rest_ensure_response(
			[
				'plugin_version'      => CFBME_VERSION,
				'wp_version'          => $wp_version,
				'php_version'         => PHP_VERSION,
				'is_multisite'        => is_multisite(),
				'memory_limit'        => ini_get( 'memory_limit' ),
				'max_execution_time'  => ini_get( 'max_execution_time' ),
				'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
				'post_max_size'       => ini_get( 'post_max_size' ),
				'rest_url'            => rest_url(),
				'admin_url'           => admin_url(),
				'site_url'            => site_url(),
				'log_path'            => $this->logger->log_path(),
				'log_writable'        => $this->logger->log_path() ? wp_is_writable( dirname( $this->logger->log_path() ) ) : false,
				'bme_debug_const'     => defined( 'CFBME_DEBUG' ) ? (bool) CFBME_DEBUG : null,
				'seo_plugins'         => $seo_plugins,
				'settings'            => $opts,
				'allowed_keys'        => $this->settings->get_allowed_meta_keys(),
				'enabled_post_types'  => $opts['enabled_types'],
			]
		);
	}

	public function get_log( \WP_REST_Request $request ): \WP_REST_Response {
		$lines = (int) $request->get_param( 'lines' );
		$level = sanitize_text_field( (string) $request->get_param( 'level' ) );
		$level = '' === $level ? null : $level;
		return rest_ensure_response( $this->logger->read_tail( $lines, $level ) );
	}

	public function clear_log(): \WP_REST_Response {
		$ok = $this->logger->clear();
		$this->logger->info( 'diag', 'log cleared', [ 'success' => $ok ] );
		return rest_ensure_response( [ 'success' => $ok ] );
	}

	/**
	 * Ingest a batch of client-side log entries.
	 * Each entry: { level, channel, message, context }.
	 *
	 * Hard caps the batch size and per-entry context size so a misbehaving
	 * admin tab cannot fill disk by accident.
	 */
	public function ingest_client_log( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return rest_ensure_response( [ 'received' => 0 ] );
		}

		// Cap batch to 100 entries; logger client also batches 25.
		$body  = array_slice( $body, 0, 100 );
		$count = 0;

		foreach ( $body as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$level   = isset( $entry['level'] ) && is_string( $entry['level'] ) ? $entry['level'] : 'info';
			$channel = isset( $entry['channel'] ) ? sanitize_text_field( (string) $entry['channel'] ) : 'js';
			$message = isset( $entry['message'] ) ? sanitize_text_field( (string) $entry['message'] ) : '';
			$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : [];

			// Cap context payload so a single entry cannot exceed ~10 KB.
			$encoded = wp_json_encode( $context );
			if ( is_string( $encoded ) && strlen( $encoded ) > 10 * 1024 ) {
				$context = [
					'_truncated' => true,
					'_size'      => strlen( $encoded ),
				];
			}
			$context['_source'] = 'client';

			$method = in_array( $level, [ 'debug', 'info', 'warn', 'error' ], true ) ? $level : 'info';
			$this->logger->{$method}( 'client.' . $channel, $message, $context );
			++$count;
		}

		return rest_ensure_response( [ 'received' => $count ] );
	}

	/**
	 * Self-test: write a known value to the configured meta_title_key on a
	 * specific post, read it back, then restore the original value. Reports
	 * exactly where the round-trip diverged if it did.
	 */
	public function run_self_test( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$opts    = $this->settings->get();
		$key     = $opts['meta_title_key'] ?: '_bme_self_test';
		$marker  = '__bme_self_test_' . wp_generate_uuid4();

		$post = get_post( $post_id );
		if ( ! $post ) {
			return rest_ensure_response(
				[
					'ok'    => false,
					'stage' => 'load_post',
					'error' => 'Post not found',
				]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return rest_ensure_response(
				[
					'ok'    => false,
					'stage' => 'capability',
					'error' => 'Current user cannot edit this post',
				]
			);
		}

		$before = get_post_meta( $post_id, $key, true );
		$write  = update_post_meta( $post_id, $key, $marker );
		$after  = get_post_meta( $post_id, $key, true );

		// Restore original (best-effort).
		if ( '' === $before ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $before );
		}

		$ok = ( $after === $marker );

		$this->logger->info(
			'diag',
			'self-test',
			[
				'post_id'         => $post_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- structured-log context field, not a meta_query clause.
				'meta_key'        => $key,
				'before'          => is_string( $before ) ? substr( $before, 0, 120 ) : null,
				'marker'          => $marker,
				'after_observed'  => is_string( $after ) ? substr( $after, 0, 120 ) : null,
				'update_returned' => $write,
				'ok'              => $ok,
			]
		);

		return rest_ensure_response(
			[
				'ok'              => $ok,
				'stage'           => $ok ? 'verified' : 'roundtrip_mismatch',
				'post_id'         => $post_id,
				'post_title'      => get_the_title( $post ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- REST response field naming the configured meta key for the operator, not a meta_query clause.
				'meta_key'        => $key,
				'wrote'           => $marker,
				'observed'        => $after,
				'update_returned' => $write,
				'value_restored'  => true,
				'note'            => $ok
					? 'Round-trip succeeded. The configured meta key is writable and reads back the exact value.'
					: 'Wrote to the meta key but read back a different value. Another plugin or filter is modifying writes to this key.',
			]
		);
	}

	/**
	 * Detect known SEO plugins from active_plugins so we can surface
	 * potential conflicts in the diagnostics page.
	 */
	private function detect_seo_plugins( array $active ): array {
		$signatures = [
			'yoast'        => [ 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' ],
			'rankmath'     => [ 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' ],
			'aioseo'       => [ 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' ],
			'seoframework' => [ 'autodescription/autodescription.php' ],
			'squirrly'     => [ 'squirrly-seo/squirrly.php' ],
			'seopress'     => [ 'wp-seopress/seopress.php', 'wp-seopress-pro/seopress-pro.php' ],
		];

		$found = [];
		foreach ( $signatures as $slug => $paths ) {
			foreach ( $paths as $path ) {
				if ( in_array( $path, $active, true ) ) {
					$found[] = [
						'slug' => $slug,
						'path' => $path,
					];
					break;
				}
			}
		}
		return $found;
	}
}
