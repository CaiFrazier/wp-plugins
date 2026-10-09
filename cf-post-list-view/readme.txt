=== CF Post List View ===
Contributors:      caifrazier
Tags:              posts, pages, list view, developer tools, seo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A developer-focused list view for any registered post type: adjustable columns, SEO meta fields, hierarchy data, taxonomy terms, and CSV export.

== Description ==

CF Post List View adds a **List View** tab to the WordPress Posts menu. It works across posts, pages, and any registered custom post type — select the post type from the toolbar dropdown.

Unlike the native post list table, it lets you add columns covering SEO metadata, hierarchy, taxonomy terms, author details, and WordPress internals, all from a grouped column selector modal.

**Column categories:**

* **Identity** — ID, title, slug, full URL, relative path, GUID
* **Content** — word count, character count, excerpt, has featured image, featured image URL, page template
* **SEO Meta** — Yoast title, Yoast description, Yoast robots, Rank Math title, Rank Math description, Rank Math robots, canonical URL. Reads postmeta directly — no SEO plugin required; degrades gracefully when keys are absent.
* **Hierarchy & Structure** — parent ID, parent title, menu order, depth (ancestor count), child count
* **Status & Timestamps** — post status, published date, modified date, scheduled date, comment count, comment status, ping status, sticky flag, password-protected flag
* **Author & Taxonomy** — author ID, login, and display name, plus one column per registered taxonomy for the selected post type (injected dynamically)
* **WordPress Internals** — post type, all postmeta keys present on the post (for CPT debugging)

**Features:**

* Live search on title (debounced 350 ms)
* Filter by post status (published, draft, pending, scheduled, private, trash)
* Post type selector — any public, UI-visible post type except attachments
* Sortable headers: ID, title, slug, published date, modified date, menu order, comment count, parent ID
* Adjustable per-page count (25 / 50 / 100 / 200)
* CSV export that respects the current filters and active column selection
* Column preferences saved to `localStorage` per post type — persist across page loads per browser
* No build step, no SaaS, no API key

**Requirements:**

* WordPress 6.2+
* PHP 8.1+
* The `edit_posts` capability is required to access the page

= Source code and development =

Source: https://github.com/CaiFrazier/wp-plugins/tree/prep/wordpress-org-20261009/cf-post-list-view

To rebuild, clone the repository, install the plugin's development dependencies with Composer and pnpm where a package.json is present, and run `node scripts/release.mjs cf-post-list-view` from the repository root. The builder lints PHP, runs installed tests, builds JavaScript, installs production PHP dependencies, and creates the distributable ZIP. JavaScript source lives in `src/` for built plugins; plain scripts are in `assets/`. Bundled dependencies and their license terms are listed in THIRD-PARTY-NOTICES.txt.

= Data and external services =

The list reads posts and metadata from your WordPress database. CSV files download to the requesting user. Access follows the selected post type and the current user permissions. No external service or telemetry is used.

= Support =

Report bugs through https://caifrazier.com/plugins/support/?plugin=cf-post-list-view. No account is required. Include the plugin name/version, WordPress and PHP versions, relevant theme/plugins, and steps to reproduce with the expected and actual behavior. A reply email is optional. Reports are private intake and support is best-effort. Remove credentials and private site content before sending. GitHub hosts the public source and build instructions above.

== Installation ==

1. Upload the `cf-post-list-view` directory to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu.
3. Navigate to **Posts → List View**.

== Frequently Asked Questions ==

= Where are column preferences saved? =

In the browser's `localStorage`, under the key `cfPlv_columns_{postType}_v1`. Preferences are stored per post type and per browser. Switching browsers or devices resets to the default column set until you configure it again.

= Can I use this for custom post types? =

Yes. The post type dropdown lists every public, UI-visible post type registered on your site (except Media/attachments). Selecting a CPT also discovers and adds its registered taxonomies as optional columns.

= SEO meta columns show empty values — why? =

The SEO meta columns read postmeta keys directly. If neither Yoast SEO nor Rank Math is installed, or if the meta title/description for a post hasn't been set explicitly in those plugins, the columns will be empty. This is expected — empty means "no override set."

= Can I sort by word count or depth? =

No. Word count and depth are computed at render time and are not stored in a sortable database column. Export to CSV and sort there for large datasets.

= Does this affect the native posts list table? =

No. The plugin adds a separate submenu page. The native list table is untouched.


== Changelog ==

= 1.0.2 =
* Prepare WordPress.org metadata, source/build documentation, license notices, and privacy disclosures. Count only readable child posts through WordPress queries and rely on automatic translation loading.

= 1.0.1 =
* Respect author, private-post and custom post type permissions in REST lists and CSV exports.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade steps required.
