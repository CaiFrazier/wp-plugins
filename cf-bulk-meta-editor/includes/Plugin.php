<?php

namespace BulkMetaEditor;

defined( 'ABSPATH' ) || exit;

use CFShared\Logger as SharedLogger;

class Plugin {

	private static ?self $instance = null;

	private Settings $settings;
	private SharedLogger $logger;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	public function logger(): SharedLogger {
		return $this->logger;
	}

	public function settings(): Settings {
		return $this->settings;
	}

	private function init(): void {
		// load_plugin_textdomain() has been discouraged since WP 4.6: WordPress
		// auto-loads translations for plugins whose Text Domain header matches
		// their folder slug ("cf-bulk-meta-editor"), which ours does as of 1.0.2.
		// No explicit load call is needed.
		$this->settings = new Settings();
		$this->logger   = Logger::instance();

		// Apply log threshold from settings. resolve_threshold() runs before this
		// for the very first log line, but Settings is initialised here which
		// gives us the canonical level for the rest of the request.
		$opts = $this->settings->get();
		if ( ! ( defined( 'BME_DEBUG' ) && BME_DEBUG ) ) {
			$this->logger->set_threshold( $opts['debug_mode'] ? ( $opts['log_level'] ?? 'debug' ) : 'warn' );
		}

		new Admin( $this->settings, $this->logger );
		new RestController( $this->settings, $this->logger );
		new DiagnosticsController( $this->settings, $this->logger );

		add_action( 'init', [ $this, 'register_meta' ], 20 );

		// Trace meta writes so we can detect interference from other SEO plugins
		// (Yoast/Rank Math sometimes filter our writes through their own logic).
		// updated_post_meta + added_post_meta fire on EVERY postmeta write —
		// Woo orders, ACF saves, every plugin — so this is a hot path on every
		// admin/REST request. Gate behind debug mode (constant OR setting) so
		// production sites pay zero overhead and only operators chasing a bug
		// take the per-write hashmap lookup hit. The diagnostics page surfaces
		// these traces from the log and notes the requirement.
		$trace_enabled = ( defined( 'BME_DEBUG' ) && BME_DEBUG )
			|| ! empty( $opts['debug_mode'] );
		if ( $trace_enabled && ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) ) {
			add_action( 'updated_post_meta', [ $this, 'trace_meta_updated' ], 10, 4 );
			add_action( 'added_post_meta', [ $this, 'trace_meta_added' ], 10, 4 );
		}

		$this->logger->info(
			'plugin',
			'Bulk Meta Editor initialised',
			[
				'version'    => BME_VERSION,
				'wp'         => get_bloginfo( 'version' ),
				'php'        => PHP_VERSION,
				'is_admin'   => is_admin(),
				'doing_rest' => defined( 'REST_REQUEST' ) && REST_REQUEST,
			]
		);
	}

	/**
	 * Register configured meta keys with WP's metadata system.
	 *
	 * Our own /posts/batch endpoint already enforces edit_post per row before
	 * calling update_post_meta(), so this registration is defense-in-depth: it
	 * sets a correct auth_callback if anyone (us or another plugin) ever hits
	 * a core REST endpoint that writes these keys. show_in_rest is false so we
	 * do not unintentionally widen the public surface.
	 */
	public function register_meta(): void {
		$keys          = $this->settings->get_allowed_meta_keys();
		$enabled_types = $this->settings->get()['enabled_types'];

		foreach ( $enabled_types as $post_type ) {
			foreach ( $keys as $key ) {
				register_post_meta(
					$post_type,
					$key,
					[
						'type'          => 'string',
						'single'        => true,
						'show_in_rest'  => false,
						'auth_callback' => static function ( $allowed, $meta_key, $object_id ) {
							return current_user_can( 'edit_post', $object_id );
						},
					]
				);
			}
		}

		$this->logger->debug(
			'plugin',
			'register_post_meta complete',
			[
				'keys'          => $keys,
				'enabled_types' => $enabled_types,
			]
		);
	}

	public function trace_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ): void {
		$this->trace_meta( 'updated_post_meta', $meta_id, $object_id, $meta_key, $meta_value );
	}

	public function trace_meta_added( $meta_id, $object_id, $meta_key, $meta_value ): void {
		$this->trace_meta( 'added_post_meta', $meta_id, $object_id, $meta_key, $meta_value );
	}

	private function trace_meta( string $hook, $meta_id, $object_id, $meta_key, $meta_value ): void {
		// Only trace the keys we care about; anything else is noise.
		$allowed = $this->settings->get_allowed_meta_keys();
		if ( ! in_array( $meta_key, $allowed, true ) ) {
			return;
		}
		$this->logger->debug(
			'meta',
			$hook,
			[
				'meta_id'    => (int) $meta_id,
				'object_id'  => (int) $object_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- structured-log context field, not a meta_query clause.
				'meta_key'   => $meta_key,
				'value_size' => is_string( $meta_value ) ? strlen( $meta_value ) : null,
				'value_type' => gettype( $meta_value ),
			]
		);
	}
}
