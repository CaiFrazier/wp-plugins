<?php
/**
 * Plugin Name:       CF Post List View
 * Plugin URI:        https://github.com/caifrazier/cf-post-list-view
 * Description:       A developer-focused list view for any registered post type: adjustable columns, sortable headers, live search, SEO meta fields, hierarchy data, taxonomy terms, and CSV export.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Cai Frazier
 * Author URI:        https://caifrazier.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cf-post-list-view
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CF_PLV_VERSION', '1.0.0' );
define( 'CF_PLV_FILE', __FILE__ );
define( 'CF_PLV_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF_PLV_URL', plugin_dir_url( __FILE__ ) );

// ---------------------------------------------------------------------------
// Autoload
// ---------------------------------------------------------------------------

require_once CF_PLV_DIR . 'includes/ColumnRegistry.php';
require_once CF_PLV_DIR . 'includes/PostData.php';
require_once CF_PLV_DIR . 'includes/CsvExporter.php';
require_once CF_PLV_DIR . 'includes/RestController.php';
require_once CF_PLV_DIR . 'includes/Admin.php';

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'cf-post-list-view',
			false,
			dirname( plugin_basename( CF_PLV_FILE ) ) . '/languages'
		);

		// REST routes.
		add_action(
			'rest_api_init',
			static function () {
				( new CFPostListView\RestController() )->register_routes();
			}
		);

		// Admin UI.
		if ( is_admin() ) {
			( new CFPostListView\Admin() )->register();
		}
	}
);
