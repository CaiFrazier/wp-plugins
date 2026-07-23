<?php
namespace CFForms;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		$this->settings = new Settings();

		// register_post_type() must run on WP's 'init' hook, not here: 'plugins_loaded'
		// (which calls this method) fires too early for taxonomies/rewrite rules.
		add_action( 'init', [ EntryPostType::class, 'register' ] );
		add_action( 'before_delete_post', [ EntryPostType::class, 'delete_attachment' ], 10, 2 );

		if ( is_admin() ) {
			$admin = new Admin( $this->settings );
			$admin->init();
		}

		// REST routes must register on every request type, not just is_admin():
		// /wp-json/ requests (including submissions from logged-out visitors) never
		// satisfy is_admin().
		$rest = new RestController( $this->settings );
		$rest->init();
	}
}
