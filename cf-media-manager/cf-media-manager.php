<?php
/**
 * Plugin Name:       CF Media Manager
 * Plugin URI:        https://github.com/caifrazier/cf-media-manager
 * Description:       Media Library management toolkit: a fully configurable list view with 40+ columns and CSV export, a five-report media audit engine, and a bulk alt text auditor and editor. Pairs with CF Media Optimizer for WebP/AVIF delivery.
 * Version:           3.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Cai Frazier
 * Author URI:        https://caifrazier.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cf-media-manager
 * Domain Path:       /languages
 *
 * See readme.txt for the changelog.
 */

defined( 'ABSPATH' ) || exit;

define( 'CF_MEDIA_MANAGER_FILE', __FILE__ );
define( 'CF_MEDIA_MANAGER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF_MEDIA_MANAGER_URL', plugin_dir_url( __FILE__ ) );

// Single source of truth for the plugin version: the `Version:` header above.
// Everything in code that needs the version reads CF_MEDIA_MANAGER_VERSION; the release
// script asserts that readme.txt Stable tag and package.json version match
// the header so the three files cannot drift.
if ( ! function_exists( 'get_file_data' ) ) {
	require_once ABSPATH . 'wp-includes/functions.php';
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- name carries the full plugin slug; immediately unset() below.
$cf_media_manager_header_data = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
define( 'CF_MEDIA_MANAGER_VERSION', $cf_media_manager_header_data['Version'] );
unset( $cf_media_manager_header_data );

/**
 * Load Composer's autoloader if present, otherwise fall back to a minimal
 * PSR-4 loader covering both the CFMediaManager namespace (includes/) and the
 * shared CFShared library (vendor/). Production .zip distributions bundle
 * vendor/ via the release script, so the Composer autoloader is the normal
 * production path; the fallback covers raw git checkouts without composer.
 */
if ( file_exists( CF_MEDIA_MANAGER_DIR . 'vendor/autoload.php' ) ) {
	require_once CF_MEDIA_MANAGER_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			if ( 0 === strpos( $class, 'CFMediaManager\\' ) ) {
				$relative = substr( $class, strlen( 'CFMediaManager\\' ) );
				$path     = CF_MEDIA_MANAGER_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $path ) ) {
					require_once $path;
				}
				return;
			}
			if ( 0 === strpos( $class, 'CFShared\\' ) ) {
				$relative = substr( $class, strlen( 'CFShared\\' ) );
				$path     = CF_MEDIA_MANAGER_DIR . 'vendor/caifrazier/wp-plugins-shared/src/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $path ) ) {
					require_once $path;
				}
			}
		}
	);
}

// Fail loud at activation when host requirements aren't met. The runtime
// admin notice still fires for installs that bypass activation (multisite
// network-activate, wp-cli activate without --skip-activation-hooks, etc.)
// but this catches the common case before the user runs anything that
// would silently no-op.
register_activation_hook(
	__FILE__,
	static function () {
		$error = \CFMediaManager\Plugin::check_requirements();
		if ( null === $error ) {
			return;
		}
		// Reverse the activation that just succeeded so WP doesn't list the
		// plugin as active while we wp_die'ing the user.
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html( $error ),
			esc_html__( 'CF Media Manager — Activation blocked', 'cf-media-manager' ),
			[ 'back_link' => true ]
		);
	}
);

add_action( 'plugins_loaded', [ \CFMediaManager\Plugin::class, 'instance' ] );

// WP-CLI integration — only when running under wp-cli.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/cli.php';
}
