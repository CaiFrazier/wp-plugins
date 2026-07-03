<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- uninstall-time cleanup; prefix sweeps have no API equivalent and caching is irrelevant when the plugin's data is being removed.

/**
 * Per-site cleanup. Runs once per blog on multisite, once total on single-site.
 */
function som_uninstall_cleanup() {
	global $wpdb;

	// Remove all plugin options.
	$options = [
		'som_global_schema',
		'som_global_suppression',
		'som_settings',
		'som_livefetch_generation',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove CPT template options by prefix. Iterating registered post types
	// misses non-public CPTs and any CPT whose plugin is deactivated at
	// uninstall time; a LIKE sweep catches every som_template_* row.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'som_template_' ) . '%'
		)
	);

	// Remove all postmeta.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
			'_som_schema',
			'_som_suppression'
		)
	);

	// Live-fetch cooldown/result transients (and their timeout rows). Swept
	// directly because the post/user ids in the keys are unknown here.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_som_livefetch_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_som_livefetch_' ) . '%'
		)
	);

	// CFShared logger cleanup: the randomized-filename option plus the log
	// directory under uploads (log files, rotations, and access-guard files).
	delete_option( 'schema-override-manager_log_filename' );
	som_uninstall_remove_log_dir();
}

/**
 * Remove uploads/schema-override-manager/ and its contents. The logger only
 * ever writes flat files there (log-*.jsonl + rotations, .htaccess, index.php,
 * web.config), so no recursion is needed.
 */
function som_uninstall_remove_log_dir() {
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return;
	}

	$dir = trailingslashit( $uploads['basedir'] ) . 'schema-override-manager';
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$entries = scandir( $dir );
	if ( false === $entries ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		// Remove regular files and symlinks (unlink removes the link itself, not
		// its target). A real subdirectory is left alone; the rmdir below will
		// then fail and the dir is left in place rather than us recursing into
		// something the logger never created.
		if ( is_file( $path ) || is_link( $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort uninstall cleanup; WP_Filesystem may not be initialized here.
			@unlink( $path );
		}
	}

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort uninstall cleanup; WP_Filesystem may not be initialized here.
	@rmdir( $dir );
}

if ( is_multisite() ) {
	// number => 0 removes the default 100-site page cap so every site on a
	// large network is cleaned.
	$som_blog_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $som_blog_ids as $som_blog_id ) {
		switch_to_blog( $som_blog_id );
		som_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	som_uninstall_cleanup();
}
