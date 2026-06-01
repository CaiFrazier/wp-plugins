<?php
/**
 * Uninstall — called when the plugin is deleted via the WP admin.
 *
 * CF Post List View stores nothing server-side:
 * - Column preferences are stored in localStorage (browser-side, per user/device).
 * - No custom DB tables.
 * - No options or user meta.
 *
 * Nothing to clean up.
 *
 * @package CF_Post_List_View
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
