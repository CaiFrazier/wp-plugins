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
		$this->load_textdomain();

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

	private function load_textdomain(): void {
		load_plugin_textdomain(
			'schema-override-manager',
			false,
			dirname( plugin_basename( SOM_FILE ) ) . '/languages'
		);
	}

	/** @internal */
	public function debug_log_suppression(): void {
		global $post;
		if ( ! $post ) {
			return;
		}
		$rules = get_post_meta( $post->ID, '_som_suppression', true );
		if ( $rules ) {
			error_log( '[SOM] Suppression rules for post ' . $post->ID . ': ' . wp_json_encode( $rules ) );
		}
	}

	public static function activate(): void {
		if ( ! get_option( 'som_settings' ) ) {
			update_option( 'som_settings', [
				'enabled_post_types'    => [ 'post', 'page' ],
				'output_priority'       => 5,
				'theme_suppression_ob'  => false,
			] );
		}
	}

	public static function deactivate(): void {
		// Intentionally empty — data is preserved on deactivation.
	}
}
