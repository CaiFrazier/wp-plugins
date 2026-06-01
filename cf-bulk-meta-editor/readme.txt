=== CF Bulk Meta Editor ===
Contributors: caifrazier
Tags: seo, bulk edit, meta, csv, spreadsheet
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A spreadsheet-style editor for SEO meta titles, descriptions, and custom postmeta across all post types.

== Description ==

CF Bulk Meta Editor replaces the tedious one-by-one workflow for managing SEO metadata. Pick posts via the inline picker panel, choose which columns to display, edit inline, and save in batch.

**Key features:**

* Works with any SEO plugin — configure which postmeta keys map to "meta title" and "meta description", with one-click presets for Yoast SEO, Rank Math, AIOSEO, and The SEO Framework
* AG Grid-powered spreadsheet with virtual scrolling, inline editing, and column pinning
* Character count indicators for meta title (60 char) and meta description (160 char), color-coded green / yellow / red
* Post picker with search, status filter, select-all, and adjustable pagination
* Dirty-row highlighting and per-row revert / save
* Column visibility toggle, persisted per user
* CSV export of all loaded rows or a custom selection
* Supports all public post types; disable unwanted types in settings
* Zero external HTTP requests at runtime; no tracking

== Installation ==

1. Upload the `cf-bulk-meta-editor` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu
3. Open **CF Bulk Meta Editor** in the sidebar and click the **Settings** tab to configure your SEO plugin's meta keys
4. Return to the **Editor** tab to start editing

== Frequently Asked Questions ==

= Which SEO plugins are supported? =

Any plugin that stores data as postmeta. Built-in presets cover Yoast SEO, Rank Math, AIOSEO, and The SEO Framework. You can also enter any custom meta key manually.

= Can I edit post content? =

The grid shows a read-only content excerpt. Full content editing is out of scope — use the native block editor for that.

= Is there a limit on how many posts I can load? =

The picker paginates the post list, but you can select across pages before loading. Very large selections (1000+) may be slow depending on server resources.

= Are changes logged? =

Not at this time. Use the per-row revert button to undo changes before saving.

== Screenshots ==

1. Main grid with inline editing and character count indicators
2. Post picker panel with search and status filter
3. Column selector dropdown
4. Settings page with meta key mapping and custom columns

== Changelog ==

= 1.0.4 =
* Fix: Uninstall now removes the correct log directory (`uploads/cf-bulk-meta-editor/`) — the path previously referenced the old plugin slug and cleanup silently did nothing on sites that had ever had logs written.
* Fix: Removed stale `delete_option('bulk_meta_editor_log_filename')` call in uninstall — the option was never written in any current or prior code path.
* Compliance: Added `PrefixAllGlobals` prefix allowlist (`cfbme`, `BME`, `BulkMetaEditor`) to `phpcs.xml.dist` so WordPress.org Plugin Check no longer flags `BME_*` constants as `NonPrefixedConstantFound`.
* Polish: Corrected plugin installation instructions in readme — folder slug and admin navigation path were both stale.
* Polish: Removed dead `check_edit_posts()` method from `DiagnosticsController` — all diagnostic routes require `manage_options`; the method was unreachable.

= 1.0.3 =
* Compliance: WordPress.org Plugin Check warnings on `'meta_key'` literals inside logger context arrays + REST responses suppressed with inline `phpcs:ignore` annotations. Each suppression carries a one-line rationale (these are structured-log fields, never `meta_query` clauses). Suppressions are inline because Plugin Check runs its own PHPCS configuration and does not load `phpcs.xml.dist`.
* Compliance: release zip now ships `composer.json` alongside the bundled `vendor/` directory so Plugin Check no longer flags the orphan dependency tree. `composer.lock` remains excluded (dev-only). The shared release script gained a `composerRuntime`-aware exception in its dev-artifact guard.
* Polish: composer package renamed `caifrazier/bulk-meta-editor` → `caifrazier/cf-bulk-meta-editor` for consistency with the WP slug.

= 1.0.2 =
* Compliance: text domain renamed from `bulk-meta-editor` to `cf-bulk-meta-editor` so it matches the plugin folder slug per WordPress.org Plugin Check requirements. Translation `.pot` regenerated.
* Compliance: global function prefixes harmonised — `bme_environment_ok`, `bme_environment_error_message`, `bme_uninstall_rmdir_recursive`, `bme_uninstall_cleanup` are now `cfbme_*`.
* Compliance: dropped `load_plugin_textdomain()` call — WordPress 4.6+ loads translations automatically when the Text Domain matches the slug.
* Compliance: `unlink()` in the uninstall log-dir cleanup replaced with `wp_delete_file()`; `is_writable()` in the diagnostics environment dump replaced with `wp_is_writable()`.
* Tested up to WordPress 7.0.

= 1.0.1 =
* Hardening: `X-Content-Type-Options: nosniff` on CSV export.
* Hardening: CSV download filename now passes through `sanitize_file_name()`.
* Fix: `default_per_page` setting ceiling lowered from 500 to 200 to match the picker's clamp — values above 200 were silently downsized at read time.
* Polish: window-global destructures in the React bundles default safely so the JS no longer crashes at import time if loaded outside the editor/settings/diagnostics screens.

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.4 =
Bug fix: uninstall log directory cleanup now targets the correct path. Plugin Check compliance fix. Upgrade recommended.

= 1.0.3 =
WordPress.org Plugin Check warnings cleared. Drop-in upgrade — no behavior change.

= 1.0.2 =
WordPress.org Plugin Check compliance: text domain renamed to match folder slug, global function prefixes harmonised. Drop-in upgrade — no DB migration, no user-visible behavior change.

= 1.0.1 =
Hardening + per-page ceiling fix. Recommended for all installs.

= 1.0.0 =
Initial release.
