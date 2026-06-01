<?php
/**
 * Plugin Name:       Schema Override Manager
 * Plugin URI:        https://github.com/caifrazier/schema-override-manager
 * Description:       View, suppress, extend, and inject JSON-LD structured data at the global, post-type template, and per-page level. Works alongside Yoast, Rank Math, and theme-injected schema.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Cai Frazier
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       schema-override-manager
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SOM_VERSION', '1.0.0' );
define( 'SOM_FILE', __FILE__ );
define( 'SOM_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOM_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( SOM_DIR . 'vendor/autoload.php' ) ) {
	require_once SOM_DIR . 'vendor/autoload.php';
} else {
	// vendor/ is required because SOM depends on the shared CFShared package.
	// In dev: run `composer install`. In distribution zips: vendor/ is committed.
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Schema Override Manager:</strong> '
			. esc_html__( 'composer dependencies are not installed. Run `composer install` in the plugin directory.', 'schema-override-manager' )
			. '</p></div>';
	} );
	return;
}

add_action( 'plugins_loaded', function () {
	SchemaOverrideManager\Plugin::instance()->init();
} );

register_activation_hook( __FILE__, [ 'SchemaOverrideManager\\Plugin', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'SchemaOverrideManager\\Plugin', 'deactivate' ] );
