<?php
/**
 * Plugin Name:       CF Forms
 * Plugin URI:        https://github.com/caifrazier/cf-forms
 * Description:       Backend infrastructure for site forms: a REST submission endpoint, spam-resistant validation, entry storage, and admin notification email. No front-end form markup; bring your own HTML and POST to the endpoint.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Cai Frazier
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cf-forms
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CFF_VERSION', '0.1.0' );
define( 'CFF_FILE', __FILE__ );
define( 'CFF_DIR', plugin_dir_path( __FILE__ ) );
define( 'CFF_URL', plugin_dir_url( __FILE__ ) );

// Explicit requires (no Composer autoloader at runtime): the plugin ships no
// third-party runtime dependencies, so loading the handful of classes directly
// removes an entire failure mode (a missing vendor/ dir). composer.json remains
// for dev tooling (phpcs / phpunit) only.
require_once CFF_DIR . 'includes/Settings.php';
require_once CFF_DIR . 'includes/Sanitizer.php';
require_once CFF_DIR . 'includes/RateLimiter.php';
require_once CFF_DIR . 'includes/EntryPostType.php';
require_once CFF_DIR . 'includes/Mailer.php';
require_once CFF_DIR . 'includes/RestController.php';
require_once CFF_DIR . 'includes/Admin.php';
require_once CFF_DIR . 'includes/Plugin.php';

/**
 * Seed default settings on activation, then register the CPT once so its admin
 * menu is reachable immediately after activation without a permalink resave.
 */
function cff_activate(): void {
	if ( ! get_option( 'cff_settings' ) ) {
		update_option(
			'cff_settings',
			[
				'notification_email'  => get_option( 'admin_email' ),
				'rate_limit_max'      => 10,
				'rate_limit_window'   => HOUR_IN_SECONDS,
				'min_elapsed_seconds' => 3,
			]
		);
	}

	CFForms\EntryPostType::register();
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'cff_activate' );

function cff_deactivate(): void {
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'cff_deactivate' );
// Uninstall (uninstall.php) removes stored entries and settings; deactivation preserves data.

add_action(
	'plugins_loaded',
	function () {
		CFForms\Plugin::instance()->init();
	}
);
