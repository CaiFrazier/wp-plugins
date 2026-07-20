<?php
/**
 * Plugin Name:       CF QR Redirect
 * Plugin URI:        https://github.com/CaiFrazier/wp-plugins/tree/main/qr-redirect
 * Description:       Self-hosted QR code generator and redirect manager with native GA4 analytics integration. Generates branded QR codes pointing to /r/{slug} short URLs on your own domain, plus a standard source→destination redirect manager.
 * Version:           1.3.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Cai Frazier
 * Author URI:        https://github.com/caifrazier
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cf-qr-redirect
 * Domain Path:       /languages
 *
 * @package CFQR
 *
 * CF QR Redirect — Copyright (C) 2026 Cai Frazier.
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later
 * version. See https://www.gnu.org/licenses/gpl-2.0.html for the full license.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CFQR_VERSION', '1.3.1' );
define( 'CFQR_PLUGIN_FILE', __FILE__ );
define( 'CFQR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CFQR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CFQR_POST_TYPE', 'cfqr_code' );
define( 'CFQR_REWRITE_SLUG', 'r' );
define( 'CFQR_OPTION_KEY', 'cfqr_settings' );
define( 'CFQR_MENU_SLUG', 'cfqr' );
// Standard redirect manager — a second CPT for arbitrary source→destination
// mappings. Separate from CFQR_POST_TYPE because the UX differs (slug locks on
// publish for QR codes; redirects need editable source paths) and routing
// happens at a different request stage (parse_request vs. template_redirect).
define( 'CFQR_REDIRECT_POST_TYPE', 'cfqr_redirect' );
// Granular, plugin-prefixed capabilities. Using 'manage_qr_codes' (unprefixed)
// risked colliding with an unrelated plugin granting the same generic name, and
// bundled everything — code editing, deletion, export, and global settings —
// into a single undifferentiated cap. The new caps allow granting only what a
// delegated user actually needs. All six are granted to administrators on
// activation; site owners can delegate individual caps through the standard
// WordPress role capability API.
define( 'CFQR_CAP_READ', 'cfqr_read_codes' );
define( 'CFQR_CAP_CREATE', 'cfqr_create_codes' );
define( 'CFQR_CAP_EDIT', 'cfqr_edit_codes' );
define( 'CFQR_CAP_DELETE', 'cfqr_delete_codes' );
define( 'CFQR_CAP_EXPORT', 'cfqr_export_codes' );
define( 'CFQR_CAP_SETTINGS', 'cfqr_manage_settings' );
// Convenience alias: the "general" cap used where a single check suffices
// (meta-box access, save handlers). Equals CFQR_CAP_EDIT.
define( 'CFQR_CAP', CFQR_CAP_EDIT );

// Capabilities for the standard redirect manager. Granular and prefixed for
// the same reasons the QR caps are — sites that want to delegate "edit a
// redirect destination" to an editor role can grant exactly that, with no
// fallback to edit_posts. cfqr_manage_settings is shared with the QR side.
define( 'CFQR_REDIRECT_CAP_READ', 'cfqr_read_redirects' );
define( 'CFQR_REDIRECT_CAP_CREATE', 'cfqr_create_redirects' );
define( 'CFQR_REDIRECT_CAP_EDIT', 'cfqr_edit_redirects' );
define( 'CFQR_REDIRECT_CAP_DELETE', 'cfqr_delete_redirects' );
// Managing redirect groups (taxonomy) is split from editing redirects so a
// delegated role can be allowed to tag existing redirects without being able
// to rename or create new groups.
define( 'CFQR_REDIRECT_CAP_MANAGE_GROUPS', 'cfqr_manage_redirect_groups' );
// 404 capture is gated separately from redirects because reviewing 404s is
// often an audit/analytics task that doesn't necessarily imply create rights.
define( 'CFQR_REDIRECT_CAP_MANAGE_404', 'cfqr_manage_404_captures' );

// Custom post type for 404 captures. Private CPT, slug = sha1(path).
define( 'CFQR_404_POST_TYPE', 'cfqr_404' );
// Custom taxonomy for grouping redirects.
define( 'CFQR_REDIRECT_TAXONOMY', 'cfqr_redirect_group' );

// Pinned version string for the bundled qrcode.js library (davidshimjs/qrcodejs
// v1.0.0). Decoupled from CFQR_VERSION so plugin updates don't bust the cached
// asset for an unchanged file.
define( 'CFQR_QRCODE_LIB_VERSION', '1.0.0' );

/**
 * Wrapper so the entire plugin queries one symbol when checking access.
 */
function cfqr_user_can() {
	return current_user_can( CFQR_CAP );
}

/**
 * Grant all CFQR capabilities to the administrator role. Idempotent;
 * safe to call from activation and from the bootstrap upgrade check.
 */
function cfqr_grant_admin_cap() {
	$role = get_role( 'administrator' );
	if ( ! $role ) {
		return;
	}
	$caps = array(
		CFQR_CAP_READ,
		CFQR_CAP_CREATE,
		CFQR_CAP_EDIT,
		CFQR_CAP_DELETE,
		CFQR_CAP_EXPORT,
		CFQR_CAP_SETTINGS,
		CFQR_REDIRECT_CAP_READ,
		CFQR_REDIRECT_CAP_CREATE,
		CFQR_REDIRECT_CAP_EDIT,
		CFQR_REDIRECT_CAP_DELETE,
		CFQR_REDIRECT_CAP_MANAGE_GROUPS,
		CFQR_REDIRECT_CAP_MANAGE_404,
	);
	foreach ( $caps as $cap ) {
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}
}

/**
 * Remove capabilities retired without a production feature.
 *
 * The redirect export capability shipped before redirect export existed. It
 * must be removed from every role during upgrades, not merely omitted from new
 * administrator grants.
 */
function cfqr_remove_retired_caps() {
	if ( ! function_exists( 'wp_roles' ) ) {
		return;
	}

	foreach ( wp_roles()->roles as $role_name => $_data ) {
		$role = get_role( $role_name );
		if ( $role && $role->has_cap( 'cfqr_export_redirects' ) ) {
			$role->remove_cap( 'cfqr_export_redirects' );
		}
	}
}

/**
 * Repair regex sources that the pre-1.3.1 path sanitizer prefixed with "/".
 *
 * The repair helper intentionally recognizes only anchors and leading inline
 * option groups. A normal pattern beginning with a slash may be intentional
 * and cannot be changed safely.
 */
function cfqr_migrate_regex_sources() {
	$redirect_ids = get_posts(
		array(
			'post_type'      => CFQR_REDIRECT_POST_TYPE,
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	$changed      = false;

	foreach ( $redirect_ids as $redirect_id ) {
		$mode = (string) get_post_meta( $redirect_id, CFQR_Redirect_CPT::META_MATCH_MODE, true );
		if ( CFQR_Redirect_CPT::MATCH_REGEX !== $mode ) {
			continue;
		}
		$source   = (string) get_post_meta( $redirect_id, CFQR_Redirect_CPT::META_SOURCE_PATH, true );
		$repaired = CFQR_Redirect_CPT::repair_legacy_regex_source( $source );
		if ( $repaired !== $source ) {
			update_post_meta( $redirect_id, CFQR_Redirect_CPT::META_SOURCE_PATH, $repaired );
			$changed = true;
		}
	}

	if ( $changed ) {
		CFQR_Redirect_Router::invalidate_cache();
	}
}

require_once CFQR_PLUGIN_DIR . 'includes/class-settings.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-slug-generator.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-url.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-cpt.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-seo-compat.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-analytics.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-router.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-admin.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-redirect-cpt.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-redirect-router.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-redirect-admin.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-redirect-import.php';
require_once CFQR_PLUGIN_DIR . 'includes/class-redirect-404.php';

/**
 * Supply the plugin post type before WordPress builds the admin menu.
 *
 * Core post.php links identify an item by post ID but omit post_type. WordPress
 * builds and authorizes the admin menu before post.php loads that item, so a
 * delegated user with only plugin capabilities can otherwise be redirected to
 * their profile before an edit, trash, restore, or delete action is handled.
 * Deriving the type from the stored post preserves core nonce and capability
 * checks while giving the early admin bootstrap the context it needs.
 */
function cfqr_prime_admin_post_type() {
	$script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
	if ( ! is_admin() || 'post.php' !== wp_basename( $script_name ) || isset( $_REQUEST['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request context; post.php verifies action nonces.
		return;
	}

	$post_id = 0;
	if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only lookup used before core action handling.
		$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	} elseif ( isset( $_POST['post_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies the save/action nonce after admin bootstrap.
		$post_id = absint( wp_unslash( $_POST['post_ID'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	if ( ! $post_id ) {
		return;
	}

	$post_type = get_post_type( $post_id );
	if ( ! in_array( $post_type, array( CFQR_POST_TYPE, CFQR_REDIRECT_POST_TYPE, CFQR_404_POST_TYPE ), true ) ) {
		return;
	}

	$_REQUEST['post_type'] = $post_type;
}
// init runs after core rebuilds $_REQUEST and before wp-admin authorizes menus.
add_action( 'init', 'cfqr_prime_admin_post_type', 0 );

/**
 * Add a Settings link to the plugin row on the Plugins screen, alongside
 * Deactivate.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url  = admin_url( 'admin.php?page=cfqr-settings' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cf-qr-redirect' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}
);

/**
 * Apply versioned upgrades to the current site after CPT registration.
 */
function cfqr_upgrade_site() {
	// Re-grant caps on every version bump so upgrades pick up new capabilities
	// without needing to deactivate/reactivate.
	$installed = get_option( 'cfqr_installed_version', '' );
	if ( CFQR_VERSION !== $installed ) {
		cfqr_grant_admin_cap();
		cfqr_remove_retired_caps();
		cfqr_migrate_regex_sources();
		// 1.0.1 migration: rename the old unprefixed 'manage_qr_codes' cap to the
		// new prefixed set on every role that held it. Any role that had the single
		// generic cap gets the equivalent full set of new caps so access is preserved.
		if ( function_exists( 'wp_roles' ) ) {
			$new_caps = array( CFQR_CAP_READ, CFQR_CAP_CREATE, CFQR_CAP_EDIT, CFQR_CAP_DELETE, CFQR_CAP_EXPORT, CFQR_CAP_SETTINGS );
			foreach ( wp_roles()->roles as $role_name => $_data ) {
				$role = get_role( $role_name );
				if ( $role && $role->has_cap( 'manage_qr_codes' ) ) {
					$role->remove_cap( 'manage_qr_codes' );
					foreach ( $new_caps as $cap ) {
						$role->add_cap( $cap );
					}
				}
			}
		}
		update_option( 'cfqr_installed_version', CFQR_VERSION );
	}
}

/**
 * Bootstrap the plugin once WordPress has loaded.
 */
function cfqr_bootstrap() {
	// Translations are loaded automatically by WordPress 4.6+ from the plugin's
	// Text Domain header — no manual load_plugin_textdomain() call needed.

	CFQR_CPT::init();
	CFQR_SEO_Compat::init();
	CFQR_Router::init();
	CFQR_Redirect_CPT::init();
	CFQR_Redirect_Router::init();
	CFQR_Redirect_404::init();
	if ( is_admin() ) {
		CFQR_Admin::init();
		CFQR_Redirect_Admin::init();
		CFQR_Redirect_Import::init();
	}

	// Redirect migrations query the private CPT, which is registered at the
	// default init priority. Run upgrades afterward so those rows are visible.
	add_action( 'init', 'cfqr_upgrade_site', 20 );
}
add_action( 'plugins_loaded', 'cfqr_bootstrap' );

/**
 * Refresh the site-specific rewrite context and persist its rules.
 *
 * Switch_to_blog() changes options and tables but does not reinitialize the
 * global WP_Rewrite instance. Without this step, network lifecycle operations
 * can write the previous site's permalink configuration to another site.
 */
function cfqr_flush_site_rewrite_rules() {
	global $wp_rewrite;

	if ( $wp_rewrite instanceof WP_Rewrite ) {
		$wp_rewrite->init();
	}
	flush_rewrite_rules();
}

/**
 * Apply activation state to the current site.
 */
function cfqr_activate_site() {
	cfqr_grant_admin_cap();
	cfqr_remove_retired_caps();
	cfqr_migrate_regex_sources();
	CFQR_Redirect_404::schedule_cleanup();
	cfqr_flush_site_rewrite_rules();
	update_option( 'cfqr_installed_version', CFQR_VERSION );
}

/**
 * Run a lifecycle callback on the current site or every site in a network.
 *
 * @param callable $callback     Site-scoped lifecycle callback.
 * @param bool     $network_wide Whether the plugin is being changed network-wide.
 */
function cfqr_run_site_lifecycle( $callback, $network_wide ) {
	if ( ! is_multisite() || ! $network_wide ) {
		call_user_func( $callback );
		return;
	}

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		call_user_func( $callback );
		restore_current_blog();
	}
}

/**
 * On activation: register CPTs, initialize every affected site, and flush
 * rewrite rules so /r/{slug} resolves immediately.
 *
 * @param bool $network_wide Whether the plugin was network activated.
 */
function cfqr_activate( $network_wide = false ) {
	CFQR_CPT::register();
	CFQR_Redirect_CPT::register();
	CFQR_Redirect_404::register();
	cfqr_run_site_lifecycle( 'cfqr_activate_site', $network_wide );
}
register_activation_hook( __FILE__, 'cfqr_activate' );

/**
 * Apply deactivation state to the current site.
 */
function cfqr_deactivate_site() {
	CFQR_Redirect_404::unschedule_cleanup();
	cfqr_flush_site_rewrite_rules();
}

/**
 * On deactivation: remove rewrite rules and scheduled cleanup on every
 * affected site. Data and capability cleanup happen in uninstall.php only;
 * deactivation must
 * be reversible without losing role configuration.
 *
 * @param bool $network_wide Whether the plugin was network deactivated.
 */
function cfqr_deactivate( $network_wide = false ) {
	if ( post_type_exists( CFQR_POST_TYPE ) ) {
		unregister_post_type( CFQR_POST_TYPE );
	}
	cfqr_run_site_lifecycle( 'cfqr_deactivate_site', $network_wide );
}
register_deactivation_hook( __FILE__, 'cfqr_deactivate' );

/**
 * Initialize sites created after a network activation.
 *
 * @param WP_Site $new_site Newly initialized site.
 */
function cfqr_initialize_network_site( $new_site ) {
	$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
	if ( ! isset( $network_plugins[ plugin_basename( CFQR_PLUGIN_FILE ) ] ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	cfqr_activate_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'cfqr_initialize_network_site', 200 );
