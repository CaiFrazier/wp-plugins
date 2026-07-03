<?php

namespace CFContentCalendar;

defined( 'ABSPATH' ) || exit;

class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	private function init(): void {
		// No load_plugin_textdomain() call: since WP 4.6, WordPress.org loads
		// translations for .org-hosted plugins automatically. wp_set_script_
		// translations() in Admin::enqueue_assets() handles the JS strings
		// independently and doesn't depend on this.
		new Admin();
		new RestController();
	}
}
