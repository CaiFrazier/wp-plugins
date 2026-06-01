<?php
/**
 * Plugin uninstall routine.
 *
 * @package CFChunkedUpload
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	require_once __DIR__ . '/includes/UploadSession.php';
	require_once __DIR__ . '/includes/Paths.php';
}

// Wrapped in an IIFE so none of the locals below leak into the global scope
// (WordPress.org Plugin Check flags unprefixed global variables).
( static function () {
	/**
	 * Per-blog cleanup. On multisite the settings option, cron event, and the
	 * cf_cu_* options (job-status transients, finalize locks) are all stored in
	 * the per-blog wp_options table, so each must be cleared inside a
	 * switch_to_blog() context.
	 */
	$cleanup_blog = static function () {
		global $wpdb;

		delete_option( 'cf_chunked_upload_settings' );
		wp_clear_scheduled_hook( 'cf_chunked_upload_cleanup' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup by key prefix; no cache to maintain at teardown.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cf_cu_job_%' OR option_name LIKE '_transient_timeout_cf_cu_job_%'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup by key prefix; no cache to maintain at teardown.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cf_cu_finalize_lock_%'" );
	};

	if ( is_multisite() ) {
		$site_ids = get_sites( [ 'fields' => 'ids' ] );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			$cleanup_blog();
			restore_current_blog();
		}
	} else {
		$cleanup_blog();
	}

	// Action Scheduler is network-wide (single actionscheduler_* table), so this
	// runs once outside the per-blog loop.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'cf_chunked_upload_finalize', [], 'cf-chunked-upload' );
	}

	// The chunks temp directory lives under WP_CONTENT_DIR (shared across all
	// sites on multisite), NOT under wp-content/uploads/ — see Paths::chunks_root.
	// Only transient session data, safe to remove silently. cf-imports/ is left
	// alone because it holds the user's actual imported files.
	$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
	$chunks_dir  = rtrim( $content_dir, '/' ) . '/cf-chunks';
	if ( is_dir( $chunks_dir ) ) {
		\CFChunkedUpload\UploadSession::rrmdir( $chunks_dir );
	}
} )();
