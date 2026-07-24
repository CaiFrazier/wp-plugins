<?php
/**
 * Plugin Name:       Schema Override Manager
 * Plugin URI:        https://github.com/caifrazier/schema-override-manager
 * Description:       View, suppress, extend, and inject JSON-LD structured data at the global, post-type template, and per-page level. Works alongside Yoast, Rank Math, and theme-injected schema.
 * Version:           1.0.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Cai Frazier
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       schema-override-manager
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SOM_VERSION', '1.0.1' );
define( 'SOM_FILE', __FILE__ );
define( 'SOM_DIR', plugin_dir_path( __FILE__ ) );
define( 'SOM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Seed default settings on activation. Kept self-contained (no autoloaded
 * classes) and registered BEFORE the vendor check below, so activation still
 * seeds defaults even if `vendor/` is somehow missing — otherwise the plugin
 * could run permanently unconfigured (no enabled post types → appears dead).
 */
function som_activate(): void {
	if ( ! get_option( 'som_settings' ) ) {
		update_option(
			'som_settings',
			[
				'enabled_post_types'   => [ 'post', 'page' ],
				'output_priority'      => 5,
				'theme_suppression_ob' => false,
			]
		);
	}
}

register_activation_hook( __FILE__, 'som_activate' );
// Deactivation intentionally preserves data; nothing to do.

if ( file_exists( SOM_DIR . 'vendor/autoload.php' ) ) {
	require_once SOM_DIR . 'vendor/autoload.php';
} else {
	// vendor/ is required because SOM depends on the shared CFShared package.
	// In dev: run `composer install`. In distribution zips: vendor/ is committed.
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p><strong>Schema Override Manager:</strong> '
			. esc_html__( 'composer dependencies are not installed. Run `composer install` in the plugin directory.', 'schema-override-manager' )
			. '</p></div>';
		}
	);
	return;
}

add_action(
	'plugins_loaded',
	function () {
		SchemaOverrideManager\Plugin::instance()->init();
	}
);
