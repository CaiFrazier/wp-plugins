=== Schema Override Manager ===
Contributors: caifrazier
Tags: schema, structured data, json-ld, seo, schema.org
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

View, suppress, extend, and inject JSON-LD structured data at the global, post-type template, and per-page level.

== Description ==

Schema Override Manager gives editors full control over the structured data on their WordPress site. It works alongside Yoast SEO, Rank Math, and theme-injected schema — without duplicating what's already there.

**Three-layer architecture:**

1. **Site Identity** — sitewide Organization, LocalBusiness, and WebSite schema managed in Settings
2. **Post-Type Templates** — default schema type and properties for each CPT
3. **Per-Page Overrides** — per-post suppression rules and custom schema blocks via a meta box or Block Editor sidebar panel

**Schema detection:** The plugin detects what schema is already running on each page from Yoast SEO, Rank Math, and theme/plugin output so editors see the full picture before making changes.

**Suppression:** Surgically suppress specific schema types from Yoast, Rank Math, or theme output — globally or per page.

**Supported schema types (Phase 1):**
WebSite, Organization, LocalBusiness, WebPage, Article, FAQPage, BreadcrumbList, Person, Product, Service

**Output buffering note:** The "Suppress theme/other JSON-LD" option uses output buffering scoped to `wp_head` to strip `<script type="application/ld+json">` blocks from theme and plugin output. This option is **disabled by default** and must be explicitly enabled. It is applied only on pages where a suppression rule for "theme" is active, minimizing performance impact.

== Installation ==

1. Upload the `schema-override-manager` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins screen
3. Navigate to **Settings → Schema Override** to configure site identity schema
4. Use the meta box or Block Editor sidebar panel on any post/page to set per-page schema and suppression rules

== Frequently Asked Questions ==

= Does this plugin work without Yoast or Rank Math? =

Yes. The plugin functions independently. Yoast and Rank Math integrations activate automatically if those plugins are present.

= Will this create duplicate schema? =

No. In Extend mode the plugin deep-merges its properties with existing schema of the same type. In Replace mode it suppresses the same type from other sources before outputting its own.

= Is the output buffering approach safe? =

Output buffering for theme suppression is opt-in, scoped only to `wp_head`, and applied only on pages with an active suppression rule. It is documented clearly and disabled by default.

= What limits apply to schema payloads I save? =

Saved schema is run through a strict sanitizer before it reaches the database, so a malicious or accidentally huge paste can't escape the JSON-LD script context or blow up the renderer. The limits:

* Maximum nesting depth: 8 levels
* Maximum total nodes (array entries) across the tree: 200
* Maximum length per string value: 8 KB
* URL-shaped properties (`url`, `sameAs`, `image`, `logo`, `@id`, `target`, `mainEntityOfPage`, `thumbnailUrl`, `contentUrl`, `embedUrl`) are passed through `esc_url_raw` — `javascript:` and other non-http(s) URLs are dropped
* String property values have HTML tags stripped — JSON-LD consumers (Google, Bing) strip them in display anyway
* `@type` must look like a schema.org identifier (`https://schema.org/Foo` is normalized to `Foo`); values with HTML or special characters are dropped

Values exceeding a limit are truncated or dropped silently rather than rejecting the whole save. The `som_sanitizer_url_keys` filter lets you add additional URL-shaped property names if you use a schema type that needs them.

== Screenshots ==

1. Settings → Site Identity tab
2. Settings → Post Type Templates tab
3. Settings → Global Suppression tab
4. Per-page meta box — Existing Schema viewer
5. Per-page meta box — Schema Builder
6. Per-page meta box — Preview panel

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
