<?php
/**
 * Plugin Name:       CF Chunked Upload
 * Plugin URI:        https://github.com/caifrazier/cf-chunked-upload
 * Description:       Splits large files client-side and reassembles them server-side, bypassing PHP upload_max_filesize / post_max_size limits. Two surfaces: Media Library and a standalone large-file importer. Integrity-verified with per-chunk and whole-file SHA-256.
 * Version:           1.2.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Cai Frazier
 * Author URI:        https://caifrazier.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cf-chunked-upload
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CF_CHUNKED_UPLOAD_VERSION', '1.2.0' );
define( 'CF_CHUNKED_UPLOAD_FILE', __FILE__ );
define( 'CF_CHUNKED_UPLOAD_DIR', plugin_dir_path( __FILE__ ) );
define( 'CF_CHUNKED_UPLOAD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Composer's autoloader if present (dev / CI), otherwise a minimal PSR-4
 * loader for the CFChunkedUpload namespace. The .zip distribution does not
 * bundle vendor/, so the fallback path is the production path.
 */
if ( file_exists( CF_CHUNKED_UPLOAD_DIR . 'vendor/autoload.php' ) ) {
	require_once CF_CHUNKED_UPLOAD_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( $class ) {
			$map = [
				'CFChunkedUpload\\' => CF_CHUNKED_UPLOAD_DIR . 'includes/',
			];
			foreach ( $map as $prefix => $base ) {
				if ( 0 !== strpos( $class, $prefix ) ) {
					continue;
				}
				$relative = substr( $class, strlen( $prefix ) );
				$path     = $base . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_file( $path ) ) {
					require_once $path;
				}
				return;
			}
		}
	);
}

register_activation_hook(
	__FILE__,
	static function () {
		$error = \CFChunkedUpload\Plugin::check_requirements();
		if ( null === $error ) {
			return;
		}
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html( $error ),
			esc_html__( 'CF Chunked Upload — Activation blocked', 'cf-chunked-upload' ),
			[ 'back_link' => true ]
		);
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		\CFChunkedUpload\Cleanup::unschedule();
	}
);

add_action( 'plugins_loaded', [ \CFChunkedUpload\Plugin::class, 'instance' ] );
