<?php
namespace SchemaOverrideManager;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;
	private SchemaOutput $output;
	private Suppressor $suppressor;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		// No load_plugin_textdomain() call: since WP 4.6, WordPress.org loads
		// translations for .org-hosted plugins automatically based on the Text
		// Domain header. wp_set_script_translations() (Admin.php, MetaBox.php)
		// handles JS strings independently and doesn't depend on this.

		$this->settings   = new Settings();
		$this->suppressor = new Suppressor( $this->settings );
		$this->output     = new SchemaOutput( $this->settings );

		if ( is_admin() ) {
			$admin = new Admin( $this->settings );
			$admin->init();

			$meta_box = new MetaBox( $this->settings );
			$meta_box->init();
		}

		// REST routes must register on REST_REQUEST too — `is_admin()` is false
		// for /wp-json/ requests, including the ones the Block Editor sidebar makes
		// when saving schema/suppression data.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$rest = new RestController( $this->settings );
			$rest->init();
		}

		$this->suppressor->init();
		$this->output->init();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			add_action( 'wp_footer', [ $this, 'debug_log_suppression' ], 999 );
		}
	}

	/**
	 * Log the current post's suppression rules to the PHP error log.
	 * Gated on the plugin-specific SOM_DEBUG constant (in addition to the
	 * WP_DEBUG gate on hook registration) so a WP_DEBUG-on production site
	 * doesn't leak rule contents into error.log.
	 *
	 * @internal
	 */
	public function debug_log_suppression(): void {
		if ( ! defined( 'SOM_DEBUG' ) || ! SOM_DEBUG ) {
			return;
		}
		global $post;
		if ( ! $post ) {
			return;
		}
		$rules = get_post_meta( $post->ID, '_som_suppression', true );
		if ( $rules ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in debug output, double-gated on WP_DEBUG + SOM_DEBUG.
			error_log( '[SOM] Suppression rules for post ' . $post->ID . ': ' . wp_json_encode( $rules ) );
		}
	}
}
