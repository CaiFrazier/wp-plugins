<?php
/**
 * Fired when the plugin is uninstalled.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Recursive directory removal scoped to our log dir. We avoid WP_Filesystem
 * because uninstall.php often runs without it bootstrapped — there's no
 * guaranteed admin/credentialed context to initialise WP_Filesystem from.
 */
function cfbme_uninstall_rmdir_recursive( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$entries = @scandir( $path );
	if ( ! is_array( $entries ) ) {
		return;
	}
	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$full = $path . '/' . $entry;
		if ( is_dir( $full ) ) {
			cfbme_uninstall_rmdir_recursive( $full );
		} else {
			wp_delete_file( $full );
		}
	}
	// WP_Filesystem is unavailable in uninstall context (see header comment);
	// rmdir() is the only realistic way to remove the now-empty log directory.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $path );
}

/**
 * Per-site cleanup. Runs once per blog on multisite, once total on single-site.
 */
function cfbme_uninstall_cleanup() {
	global $wpdb;

	// Plugin settings.
	delete_option( 'bulk_meta_editor_settings' );

	// Per-user column visibility preferences (scoped to this site's usermeta
	// rows). $wpdb->delete is the only practical way to clear a single meta_key
	// across every user — delete_user_meta would require iterating all users,
	// which on a large site is far more expensive than the targeted DELETE.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	$wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'bme_column_visibility' ] );

	// Remove the log directory in uploads/cf-bulk-meta-editor.
	$uploads = wp_upload_dir();
	if ( empty( $uploads['error'] ) ) {
		$dir = trailingslashit( $uploads['basedir'] ) . 'cf-bulk-meta-editor';
		if ( is_dir( $dir ) ) {
			cfbme_uninstall_rmdir_recursive( $dir );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids' ] ) as $blog_id ) {
		switch_to_blog( $blog_id );
		cfbme_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	cfbme_uninstall_cleanup();
}
