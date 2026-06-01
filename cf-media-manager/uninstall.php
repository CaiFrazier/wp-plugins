<?php
/**
 * CF Media Manager — uninstall cleanup.
 *
 * WordPress runs this file when the plugin is deleted from the Plugins screen.
 * Active or deactivated states do NOT trigger this file — uninstall only.
 *
 * We remove every option the plugin writes to the database (queue state,
 * settings, the post-conversion purge flag). Generated .webp / .avif files
 * are left in place by default — the admin can enable "Delete generated files
 * on uninstall" in settings to have them removed automatically. We never touch
 * user-uploaded originals under any circumstance.
 *
 * Multisite-aware: per-site option rows are cleared on each blog; site-meta
 * rows (delete_site_option) are cleared once at the network level.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Bootstrap just enough of the plugin to read the canonical option list.
// Falls back to a hard-coded list if the bootstrap file is missing for any
// reason (defense in depth — uninstall should never fail loud).
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the variable name carries the full plugin slug.
$cf_media_manager_options = array(
	'cf_media_manager_quality',
	'cf_media_manager_rewrite',
	'cf_media_manager_rewrite_scope',
	'cf_media_manager_filter_mode',
	'cf_media_manager_filter_patterns',
	'cf_media_manager_batch_size',
	'cf_media_manager_pending_purge',
	'cf_media_manager_enable_avif',
	'cf_media_manager_queue_state',
	'cf_media_manager_queue_lock',
	'cf_media_manager_backfill_done',
	'cf_media_manager_delete_variants_on_uninstall',
);

if ( file_exists( __DIR__ . '/includes/Options.php' ) ) {
	require_once __DIR__ . '/includes/Options.php';
	if ( class_exists( '\\CFMediaManager\\Options' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- carries the full plugin slug.
		$cf_media_manager_options = \CFMediaManager\Options::all();
	}
}

/**
 * Delete all WebP/AVIF variants owned by this plugin under the uploads tree.
 *
 * Uses the same VariantScanner + VariantManifest walk as the admin "Delete All"
 * action, so only manifest-owned files are removed. Runs synchronously — for
 * very large libraries this may approach the host's max_execution_time, but
 * uninstall is a one-time operation so pagination is not worth the complexity.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- function name carries the full plugin slug.
function cf_media_manager_delete_owned_variants(): void {
	$needed = [ 'Paths', 'VariantScanner', 'VariantManifest' ];
	foreach ( $needed as $cf_media_manager_class ) {
		$cf_media_manager_file = __DIR__ . "/includes/{$cf_media_manager_class}.php";
		if ( ! file_exists( $cf_media_manager_file ) ) {
			return;
		}
		require_once $cf_media_manager_file;
	}

	if ( ! class_exists( '\\CFMediaManager\\Paths' )
		|| ! class_exists( '\\CFMediaManager\\VariantScanner' )
		|| ! class_exists( '\\CFMediaManager\\VariantManifest' ) ) {
		return;
	}

	$upload   = wp_upload_dir();
	$paths    = new \CFMediaManager\Paths( $upload['basedir'], $upload['baseurl'] );
	$scanner  = new \CFMediaManager\VariantScanner( $paths );
	$manifest = new \CFMediaManager\VariantManifest( $paths );

	$cursor = '';
	do {
		$resolved = $scanner->next_subtree( $cursor );
		if ( null !== $resolved['subtree'] ) {
			$owned_set     = $manifest->build_owned_paths_set();
			$non_recursive = ( $resolved['scope'] ?? 'subtree' ) === 'root';
			foreach ( $scanner->walk_variants( $resolved['subtree'], $owned_set, $non_recursive ) as $entry ) {
				if ( ! empty( $entry['owned'] ) ) {
					wp_delete_file( $entry['path'] );
				}
			}
		}
		$cursor = $resolved['next_cursor'] ?? null;
	} while ( null !== $cursor );
}

/**
 * Per-site cleanup. Runs once per blog on multisite, once total on single-site.
 *
 * @param array $cf_media_manager_options Option keys to remove.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- function name carries the full plugin slug.
function cf_media_manager_uninstall_cleanup( array $cf_media_manager_options ) {
	// Delete generated variants before wiping options, while the option is
	// still readable. Only runs if the admin opted in via the settings checkbox.
	if ( get_option( 'cf_media_manager_delete_variants_on_uninstall', false ) ) {
		cf_media_manager_delete_owned_variants();
	}

	foreach ( $cf_media_manager_options as $cf_media_manager_opt ) {
		delete_option( $cf_media_manager_opt );
	}

	// Drop the per-attachment variant manifest postmeta. The generated
	// .webp/.avif files themselves remain on disk by policy (see header) —
	// only the bookkeeping is removed.
	//
	// Both formats: the new exact-key format (`_cf_media_manager_owns_<md5>`, one row
	// per variant) and the legacy serialized-array key (pre-1.2.2 internal
	// builds).
	global $wpdb;
	if ( isset( $wpdb ) && is_object( $wpdb ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_cf_media_manager_owns_' ) . '%'
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_cf_media_manager_variants' ) );
	}

	// Drop any pending background queue actions (Action Scheduler + WP-Cron).
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'cf_media_manager_process_queue_chunk', [], 'cf-media-manager' );
	}
	wp_clear_scheduled_hook( 'cf_media_manager_process_queue_chunk' );
}

/**
 * Top-level dispatch. Extracted into a function so the test suite can
 * exercise the multisite branching without re-executing the procedural
 * file. The actual `WP_UNINSTALL_PLUGIN` invocation at the bottom of
 * this file calls it once.
 *
 * @param array $options Option keys to clean up.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- function name carries the full plugin slug.
function cf_media_manager_run_uninstall( array $options ): void {
	if ( is_multisite() ) {
		foreach ( get_sites( array( 'fields' => 'ids' ) ) as $cf_media_manager_blog_id ) {
			switch_to_blog( $cf_media_manager_blog_id );
			cf_media_manager_uninstall_cleanup( $options );
			restore_current_blog();
		}
		// Network-level site_meta rows.
		foreach ( $options as $cf_media_manager_opt ) {
			delete_site_option( $cf_media_manager_opt );
		}
	} else {
		cf_media_manager_uninstall_cleanup( $options );
	}
}

cf_media_manager_run_uninstall( $cf_media_manager_options );
