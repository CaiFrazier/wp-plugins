<?php
/**
 * Plugin Name:       CF Content Calendar
 * Plugin URI:        https://github.com/caifrazier/cf-content-calendar
 * Description:       A modern editorial calendar. Drag to reschedule, create drafts from empty day slots, and see your full content picture across post types.
 * Version:           0.1.1
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Cai Frazier
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cf-content-calendar
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CFCAL_VERSION', '0.1.1' );
define( 'CFCAL_FILE', __FILE__ );
define( 'CFCAL_DIR', plugin_dir_path( __FILE__ ) );
define( 'CFCAL_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( CFCAL_DIR . 'vendor/autoload.php' ) ) {
	require_once CFCAL_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class_name ) {
			if ( 0 === strpos( $class_name, 'CFContentCalendar\\' ) ) {
				$relative = substr( $class_name, strlen( 'CFContentCalendar\\' ) );
				$path     = CFCAL_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $path ) ) {
					require_once $path;
				}
			}
		}
	);
}

function cfcal_environment_ok(): bool {
	return file_exists( CFCAL_DIR . 'build/calendar.asset.php' );
}

function cfcal_missing_assets_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>CF Content Calendar</strong>: build assets are missing. Run <code>npm install &amp;&amp; npm run build</code> inside the plugin directory.</p></div>';
}

register_activation_hook(
	CFCAL_FILE,
	static function () {
		if ( cfcal_environment_ok() ) {
			return;
		}
		deactivate_plugins( plugin_basename( CFCAL_FILE ), true );
		wp_die(
			'CF Content Calendar cannot activate: build assets are missing. Run <code>npm install &amp;&amp; npm run build</code> first.',
			'Plugin activation failed',
			[ 'back_link' => true ]
		);
	}
);

if ( ! cfcal_environment_ok() ) {
	add_action( 'admin_notices', 'cfcal_missing_assets_notice' );
	return;
}

add_action( 'plugins_loaded', [ \CFContentCalendar\Plugin::class, 'instance' ] );
