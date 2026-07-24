<?php
/**
 * CF Media Manager — uninstall cleanup.
 *
 * WordPress runs this file when the plugin is deleted from the Plugins screen.
 * Active or deactivated states do NOT trigger this file — uninstall only.
 *
 * CF Media Manager 3.0.0 is the management half of the former 2.3.0 bundle, so
 * it owns only the audit-report bookkeeping options. What it deliberately does
 * NOT remove:
 *   - Converted-variant files / options / ownership postmeta — those belong to
 *     CF Media Optimizer and are cleaned up by that plugin's uninstall.
 *   - The `_cf_media_manager_decorative` alt-flag postmeta — a shared dataset a
 *     co-installed CF Media Optimizer reads for its render-time alt fallback;
 *     removing it here would degrade the sibling. It is user data (marking an
 *     image decorative) and is harmless to leave.
 *   - The shared InUseScanner state (cf_media_in_use_*) — a co-installed
 *     CF Media Optimizer may still be using it.
 *
 * Multisite-aware: per-site option rows are cleared on each blog; site-meta
 * rows (delete_site_option) are cleared once at the network level.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the variable name carries the full plugin slug.
$cf_media_manager_options = array(
	'cf_media_manager_audit_ignored_paths',
	'cf_media_manager_audit_stale_since',
);

if ( file_exists( __DIR__ . '/includes/Options.php' ) ) {
	require_once __DIR__ . '/includes/Options.php';
	if ( class_exists( '\\CFMediaManager\\Options' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- carries the full plugin slug.
		$cf_media_manager_options = \CFMediaManager\Options::all();
	}
}

/**
 * Per-site cleanup. Runs once per blog on multisite, once total on single-site.
 *
 * @param array $cf_media_manager_options Option keys to remove.
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- function name carries the full plugin slug.
function cf_media_manager_uninstall_cleanup( array $cf_media_manager_options ) {
	foreach ( $cf_media_manager_options as $cf_media_manager_opt ) {
		delete_option( $cf_media_manager_opt );
	}

	// Per-user "explainer dismissed" flag, if any were ever set.
	delete_metadata( 'user', 0, 'cf_media_manager_explainer_dismissed', '', true );
}

/**
 * Top-level dispatch. Extracted into a function so the test suite can exercise
 * the multisite branching without re-executing the procedural file.
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
		foreach ( $options as $cf_media_manager_opt ) {
			delete_site_option( $cf_media_manager_opt );
		}
	} else {
		cf_media_manager_uninstall_cleanup( $options );
	}
}

cf_media_manager_run_uninstall( $cf_media_manager_options );
