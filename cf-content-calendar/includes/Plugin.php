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
		load_plugin_textdomain(
			'cf-content-calendar',
			false,
			dirname( plugin_basename( CFCAL_FILE ) ) . '/languages'
		);

		new Admin();
		new RestController();
	}
}
