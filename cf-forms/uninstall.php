<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- uninstall-time cleanup; a post-type sweep has no API equivalent and caching is irrelevant when the plugin's data is being removed.

/**
 * Per-site cleanup. Runs once per blog on multisite, once total on single-site.
 */
function cff_uninstall_cleanup() {
	global $wpdb;

	delete_option( 'cff_settings' );

	$entry_ids = $wpdb->get_col(
		$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'cff_entry' )
	);

	foreach ( $entry_ids as $entry_id ) {
		$attachment = get_post_meta( (int) $entry_id, '_cff_attachment_path', true );
		if ( is_string( $attachment ) && '' !== $attachment ) {
			wp_delete_file( $attachment );
		}
		wp_delete_post( (int) $entry_id, true );
	}

	// Rate-limit transients (and their timeout rows). Swept by prefix because
	// the hashed IPs in the keys aren't otherwise enumerable here.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_cff_rl_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_cff_rl_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	// number => 0 removes the default 100-site page cap so every site on a
	// large network is cleaned.
	$cff_blog_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $cff_blog_ids as $cff_blog_id ) {
		switch_to_blog( $cff_blog_id );
		cff_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	cff_uninstall_cleanup();
}
