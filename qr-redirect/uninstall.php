<?php
/**
 * Part of CF QR Redirect.
 * Licensed under the GNU GPL v2 or later — see the LICENSE block in cf-qr-redirect.php.
 *
 * CF QR Redirect — uninstall cleanup.
 * Runs only when the user deletes the plugin from the WP Plugins screen.
 *
 * Removes: all cfqr_code posts, all _cfqr_* post meta, the cfqr_settings option,
 * the cfqr_installed_version option, and strips all cfqr_* capabilities (plus
 * the legacy manage_qr_codes cap) from every role. Multisite-aware: cleanup
 * runs once per blog.
 *
 * @package CFQR
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Per-site cleanup. Runs once per blog on multisite, once total on single-site.
 */
function cfqr_uninstall_cleanup() {
	global $wpdb;

	$option_key = 'cfqr_settings';

	// Delete every QR code, redirect, and 404 capture (and their meta as a
	// side effect of wp_delete_post). Direct query: uninstall runs once and
	// caching here serves no purpose. The three post type values are plugin
	// constants — not user input — so each one is a literal %s placeholder.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s, %s)",
			'cfqr_code',
			'cfqr_redirect',
			'cfqr_404'
		)
	);

	if ( ! empty( $post_ids ) ) {
		foreach ( $post_ids as $pid ) {
			wp_delete_post( (int) $pid, true );
		}
	}

	// Belt-and-suspenders: drop any orphan _cfqr_* meta rows. Use esc_like so the
	// underscores in the meta-key prefix aren't treated as MySQL LIKE wildcards.
	$like = $wpdb->esc_like( '_cfqr_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$like
		)
	);

	// Remove the plugin's option rows.
	delete_option( $option_key );
	delete_option( 'cfqr_installed_version' );

	// Strip all CFQR capabilities from every role. Covers both the new prefixed
	// caps introduced in 1.0.1 and the old unprefixed 'manage_qr_codes' cap
	// from 1.0.0 installs that were never migrated.
	$cfqr_caps = array(
		'cfqr_read_codes',
		'cfqr_create_codes',
		'cfqr_edit_codes',
		'cfqr_delete_codes',
		'cfqr_export_codes',
		'cfqr_manage_settings',
		'cfqr_read_redirects',
		'cfqr_create_redirects',
		'cfqr_edit_redirects',
		'cfqr_delete_redirects',
		'cfqr_export_redirects',
		'cfqr_manage_redirect_groups',
		'cfqr_manage_404_captures',
		'manage_qr_codes', // Legacy cap from 1.0.x.
	);
	if ( function_exists( 'wp_roles' ) ) {
		foreach ( wp_roles()->roles as $role_name => $_data ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $cfqr_caps as $cap ) {
				if ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	// Delete taxonomy terms. Term relationships are removed automatically when
	// the underlying cfqr_redirect posts are deleted above; this clears the
	// term rows themselves so the term table doesn't keep orphan groups.
	if ( taxonomy_exists( 'cfqr_redirect_group' ) ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'cfqr_redirect_group',
				'hide_empty' => false,
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				wp_delete_term( (int) $term->term_id, 'cfqr_redirect_group' );
			}
		}
	}

	// Drop the daily 404-cleanup cron event if scheduled.
	$cron = wp_next_scheduled( 'cfqr_404_cleanup' );
	if ( $cron ) {
		wp_unschedule_event( $cron, 'cfqr_404_cleanup' );
	}

	// Flush caches so subsequent loads don't see ghost data.
	wp_cache_flush();
}

if ( is_multisite() ) {
	// 'number' => 0 lifts the default 100-site cap so every blog on a large
	// network is cleaned. get_sites() with fields=ids returns a flat id list.
	$cfqr_blog_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $cfqr_blog_ids as $cfqr_site_id ) {
		switch_to_blog( $cfqr_site_id );
		cfqr_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	cfqr_uninstall_cleanup();
}
