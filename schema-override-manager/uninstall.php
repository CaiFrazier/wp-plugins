<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

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
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Remove CPT template options.
	$post_types = get_post_types( [ 'public' => true ] );
	foreach ( $post_types as $post_type ) {
		delete_option( 'som_template_' . $post_type );
	}

	// Remove all postmeta.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_som_schema', '_som_suppression')" );
}

if ( is_multisite() ) {
	foreach ( get_sites( [ 'fields' => 'ids' ] ) as $blog_id ) {
		switch_to_blog( $blog_id );
		som_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	som_uninstall_cleanup();
}
