<?php
/**
 * Plugin Name:       CF QR Redirect
 * Plugin URI:        https://github.com/caifrazier/cf-qr-redirect
 * Description:       Self-hosted QR code generator and redirect manager with native GA4 analytics integration. Generates branded QR codes pointing to /r/{slug} short URLs on your own domain, plus a standard source→destination redirect manager.
 * Version:           1.2.0
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

define( 'CFQR_VERSION', '1.2.0' );
define( 'CFQR_PLUGIN_FILE', __FILE__ );
define( 'CFQR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CFQR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CFQR_POST_TYPE', 'cfqr_code' );
define( 'CFQR_REWRITE_SLUG', 'r' );
define( 'CFQR_OPTION_KEY', 'cfqr_settings' );
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
// activation; site owners can delegate individual caps:
// get_role( 'editor' )->add_cap( CFQR_CAP_EDIT );
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
define( 'CFQR_REDIRECT_CAP_EXPORT', 'cfqr_export_redirects' );
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
		CFQR_REDIRECT_CAP_EXPORT,
		CFQR_REDIRECT_CAP_MANAGE_GROUPS,
		CFQR_REDIRECT_CAP_MANAGE_404,
	);
	foreach ( $caps as $cap ) {
		if ( ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
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
 * Add a Settings link to the plugin row on the Plugins screen, alongside
 * Deactivate.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$url  = admin_url( 'edit.php?post_type=' . CFQR_POST_TYPE . '&page=cfqr-settings' );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cf-qr-redirect' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}
);

/**
 * Bootstrap the plugin once WordPress has loaded.
 */
function cfqr_bootstrap() {
	// Translations are loaded automatically by WordPress 4.6+ from the plugin's
	// Text Domain header — no manual load_plugin_textdomain() call needed.

	// Re-grant caps on every version bump so upgrades pick up new capabilities
	// without needing to deactivate/reactivate.
	$installed = get_option( 'cfqr_installed_version', '' );
	if ( CFQR_VERSION !== $installed ) {
		cfqr_grant_admin_cap();
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
}
add_action( 'plugins_loaded', 'cfqr_bootstrap' );

/**
 * On activation: register CPT, grant the custom cap to administrators, and
 * flush rewrite rules so /r/{slug} resolves immediately.
 */
function cfqr_activate() {
	cfqr_grant_admin_cap();
	CFQR_CPT::register();
	CFQR_Redirect_CPT::register();
	CFQR_Redirect_404::register();
	CFQR_Redirect_404::schedule_cleanup();
	flush_rewrite_rules();
	update_option( 'cfqr_installed_version', CFQR_VERSION );
}
register_activation_hook( __FILE__, 'cfqr_activate' );

/**
 * On deactivation: flush rewrite rules so /r/{slug} stops resolving.
 * Data and capability cleanup happen in uninstall.php only — deactivation must
 * be reversible without losing role configuration.
 */
function cfqr_deactivate() {
	flush_rewrite_rules();
	CFQR_Redirect_404::unschedule_cleanup();
}
register_deactivation_hook( __FILE__, 'cfqr_deactivate' );
