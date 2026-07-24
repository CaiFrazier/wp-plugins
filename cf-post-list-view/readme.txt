=== CF Post List View ===
Contributors:      caifrazier
Tags:              posts, pages, list view, developer tools, seo
Requires at least: 6.2
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.0.0
License:           GPL-2.0-or-later
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
* PHP 7.4+
* The `edit_posts` capability is required to access the page

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

== Screenshots ==

1. The list view with default columns and post type selector showing Pages.
2. Column selector modal with SEO meta and hierarchy groups expanded.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade steps required.
