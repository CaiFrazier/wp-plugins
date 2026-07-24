<?php
/**
 * Plugin Name:       CF Bulk Meta Editor
 * Plugin URI:        https://github.com/caifrazier/cf-bulk-meta-editor
 * Description:       Spreadsheet-style editor for SEO meta titles, descriptions, and arbitrary postmeta fields across all post types.
 * Version:           1.0.4
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Cai Frazier
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cf-bulk-meta-editor
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'BME_VERSION', '1.0.4' );
define( 'BME_FILE', __FILE__ );
define( 'BME_DIR', plugin_dir_path( __FILE__ ) );
define( 'BME_URL', plugin_dir_url( __FILE__ ) );

/**
 * Use Composer's autoloader if it has been generated; otherwise fall back to a
 * minimal PSR-4 loader covering both the BulkMetaEditor namespace and CFShared
 * (resolved against a sibling ../shared/ checkout — only useful for monorepo
 * dev). Production .zip distributions always bundle vendor/ via the release
 * script, so the fallback is purely a dev convenience.
 */
if ( file_exists( BME_DIR . 'vendor/autoload.php' ) ) {
	require_once BME_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			if ( 0 === strpos( $class, 'BulkMetaEditor\\' ) ) {
				$relative = substr( $class, strlen( 'BulkMetaEditor\\' ) );
				$path     = BME_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $path ) ) {
					require_once $path;
				}
				return;
			}
			if ( 0 === strpos( $class, 'CFShared\\' ) ) {
				$relative = substr( $class, strlen( 'CFShared\\' ) );
				$path     = dirname( BME_DIR ) . '/shared/src/' . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $path ) ) {
					require_once $path;
				}
			}
		}
	);
}

/**
 * Pre-flight: refuse to load if the Composer autoloader or the built JS assets
 * are missing. A bare-source upload (without `composer install` + `npm run
 * build`) would otherwise fatal on `CFShared\Logger not found` the moment
 * `plugins_loaded` fires, taking the whole site down. We catch it at the
 * activation boundary AND on every request so a half-deployed plugin
 * degrades to a "couldn't activate" notice instead of a WSOD.
 *
 * Checked here (not via add_action) because the autoloader resolution above
 * has already happened and a later hook would be too late to prevent the
 * class-load fatal.
 */
function cfbme_environment_ok(): bool {
	return file_exists( BME_DIR . 'vendor/autoload.php' )
		&& file_exists( BME_DIR . 'build/editor.asset.php' );
}

function cfbme_environment_error_message(): string {
	$missing = [];
	if ( ! file_exists( BME_DIR . 'vendor/autoload.php' ) ) {
		$missing[] = 'vendor/autoload.php (run <code>composer install --no-dev</code>)';
	}
	if ( ! file_exists( BME_DIR . 'build/editor.asset.php' ) ) {
		$missing[] = 'build/ assets (run <code>npm install &amp;&amp; npm run build</code>)';
	}
	return 'CF Bulk Meta Editor cannot start because the following are missing: '
		. implode( ', ', $missing )
		. '. Re-upload the plugin with these directories bundled, or run the build commands on the server.';
}

register_activation_hook(
	BME_FILE,
	static function () {
		if ( cfbme_environment_ok() ) {
			return;
		}
		// Roll back the activation before WP redirects back to the plugins screen.
		deactivate_plugins( plugin_basename( BME_FILE ), true );
		wp_die(
			wp_kses_post( cfbme_environment_error_message() ),
			'Plugin activation failed',
			[ 'back_link' => true ]
		);
	}
);

if ( ! cfbme_environment_ok() ) {
	// Plugin somehow ended up active without a complete deploy (manual file edit,
	// staging sync gone wrong, partial restore). Surface a notice on each admin
	// load instead of letting the next line fatal the site.
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				wp_kses_post( cfbme_environment_error_message() )
			);
		}
	);
	return;
}

add_action( 'plugins_loaded', [ \BulkMetaEditor\Plugin::class, 'instance' ] );
