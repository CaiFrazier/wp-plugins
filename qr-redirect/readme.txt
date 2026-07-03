=== CF QR Redirect ===
Contributors: caifrazier
Tags: qr code, redirect, shortlink, analytics, ga4
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted QR code generator and redirect manager. Branded short URLs on your own domain, with native GA4 attribution.

== Description ==

CF QR Redirect lets you generate branded QR codes that point at short URLs on your own domain (e.g. `yoursite.com/r/FH7B3c`) and redirect to any internal or external destination. Scans land in your existing GA4 property as `utm_source=qr` traffic — no Bitly, no QR Tiger, no third-party SaaS, no monthly fee.

= What it does =

* Registers a `cfqr_code` custom post type that's also the routing layer. Each code's slug becomes the encoded short URL.
* Generates QR codes client-side in the browser via the bundled qrcode.js library — no server-side image dependency.
* Renders a 1000×1000 PNG download per code (suitable for print up to ~3").
* Bulk-exports any selection of codes as a single ZIP via the standard WP list-table bulk-action menu.
* Two analytics modes per code:
  * **UTM Injection** (default): fast 302 redirect with `utm_source` / `utm_medium` / `utm_campaign` / `utm_content` appended to the destination URL. GA4 picks the session up via standard UTM attribution.
  * **Intermediate Page**: serves a minimal HTML page that fires a `qr_redirect` GA4 event with the short code, destination *host* (not full URL — see Privacy below), and campaign as event properties, then redirects. Best for external destinations where the destination's GA4 won't capture the scan.
* Auto-generated slugs are 8 characters from a 55-symbol alphabet that omits visually ambiguous characters (`0`, `O`, `I`, `l`, `1`). Configurable between 6 and 12.
* Inactive codes (drafts) return `410 Gone` rather than `404 Not Found` — correct HTTP semantics for a deactivated campaign.
* GA4 Measurement ID auto-detection: explicit setting → `CFQR_GA4_ID` PHP constant → Site Kit by Google's stored option.

= Why local =

QR codes printed on physical media outlive the campaigns they're created for. Hosting the redirect on your own domain means:

* You own the analytics — scans land in your GA4 property, queryable alongside everything else.
* No third-party data dependency — when Bitly raises prices, you're unaffected.
* You can change a destination without reprinting the code (302 redirects, by default).

= Requirements =

* WordPress 6.2+
* PHP 8.0+
* Modern browsers in the admin (the QR preview and bulk-ZIP export use client-side Canvas + JS).
* A persistent object cache (Redis or Memcached) is recommended, but not required, for high-volume routing. See "Will it hold up for high-volume campaigns?" in the FAQ for what it affects.

= Capability gating =

Admin actions are gated by six plugin-prefixed capabilities, all granted to the Administrator role on activation:

* `cfqr_read_codes` — view QR codes
* `cfqr_create_codes` — create new codes
* `cfqr_edit_codes` — edit existing codes (most "admin" actions check this)
* `cfqr_delete_codes` — delete codes
* `cfqr_export_codes` — bulk PNG ZIP export
* `cfqr_manage_settings` — change global plugin settings

To delegate full code-management access to Editors (without giving them settings access), call:

`get_role( 'editor' )->add_cap( 'cfqr_edit_codes' );`

…or use the standard `user_has_cap` filter. There is no plugin-specific filter — the plugin uses WP's role API directly.

= Privacy & external services =

The plugin itself does not phone home. It does not collect telemetry, ping update servers other than WordPress.org, or transmit any data to the plugin author.

When **Intermediate-page mode** is enabled on a code (this is opt-in per code, and disabled by default unless you set a GA4 Measurement ID), each scan loads Google's `gtag.js` script from `https://www.googletagmanager.com/` on the redirect's intermediate page and fires a single `qr_redirect` event into the GA4 property identified by the Measurement ID you configured. This is the standard GA4 integration; data is sent to Google, not to the plugin author. Disclose this in your site's privacy policy if you use Intermediate-page mode.

UTM-injection mode (the default) does not load any external scripts. It appends UTM parameters to the destination URL and issues a 302 redirect; analytics happen on the destination side.

= Third-party libraries =

The plugin bundles two MIT/GPL-compatible JavaScript libraries:

* **qrcode.js** by davidshimjs — MIT licensed. Source: https://github.com/davidshimjs/qrcodejs
* **JSZip** by Stuart Knightley — dual MIT / GPLv3 licensed. Source: https://github.com/Stuk/jszip

Bundled minified copies live in `assets/js/`. The unminified source for both is available at the GitHub URLs above.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install via Plugins → Add New.
2. Activate the plugin.
3. (Optional) Visit Settings → QR Redirects to configure your GA4 Measurement ID, default UTM source/medium, and other defaults.
4. Add codes from QR Codes → Add New. The short URL and QR preview are visible immediately on the Add New screen.

== Frequently Asked Questions ==

= Where do scans show up in GA4? =

In UTM-injection mode (the default): they appear as standard sessions tagged `utm_source=qr` / `utm_medium=qr-code`. You can build a custom channel grouping in GA4 → Admin → Channel Groups that matches those values to give QR traffic its own row.

In Intermediate-page mode: as `qr_redirect` events with `short_code`, `destination_host`, `campaign`, and `traffic_type` event properties. Find them in Reports → Engagement → Events, or filter explorations by event name. Only the destination hostname is sent — not the full URL — so query parameters, tokens, or signed paths in your destination are not forwarded to Google.

= Why does a code redirect to "410 Gone" instead of 404? =

Drafts and unpublished codes return 410 Gone. This is the correct HTTP status for a deliberately retired endpoint — it tells crawlers and aggregators that the resource intentionally no longer exists, which is more honest than a generic "not found."

= How do I delegate access to Editors? =

Add this to a small mu-plugin or your theme's `functions.php`:

`get_role( 'editor' )->add_cap( 'cfqr_edit_codes' );`

To revoke, call `remove_cap( 'cfqr_edit_codes' )` on the role. The other capabilities (`cfqr_read_codes`, `cfqr_create_codes`, `cfqr_delete_codes`, `cfqr_export_codes`, `cfqr_manage_settings`) can be granted/revoked the same way for finer-grained delegation.

= What's the slug character set? =

Auto-generated slugs use 55 visually unambiguous characters: digits 2-9 and letters that aren't easily confused (no `0`, `O`, `I`, `l`, or `1`). The default 8-character length gives roughly 1.5 trillion combinations — collision-free in practice and resistant to enumeration. Short URLs are *public identifiers*, not secrets; do not encode access-controlled destinations behind a guessable slug.

= Can I use a vanity slug like `SPRING26`? =

Yes — edit the URL slug in the Permalink row under the title. Reserved values (`wp-admin`, `wp-login`, the rewrite base, etc.) are auto-replaced with a random slug.

= Does the plugin work with caching plugins? =

Redirect responses send `nocache_headers()` and `X-Robots-Tag: noindex, nofollow`, so the redirect itself is not cached. The intermediate page (Mode B) is also non-cacheable. Page caches on the destination side are not affected.

= Will it generate brand-styled QR codes (logo, colors)? =

Not in 1.0. The output is the standard black-and-white QR. Brand styling requires server-side image libraries (Imagick or GD) which add hosting dependencies; a future release may add this.

= How do I export QR codes for print? =

Single code: open it and click Download PNG (1000×1000). Multiple codes: select rows in the QR Codes list, choose "Download QR PNGs (ZIP)" from the Bulk Actions menu, and a single ZIP will download.

= Does the plugin track me, the site owner? =

No. The plugin makes no outbound requests of its own and contains no telemetry. Hit counts and scan timestamps are stored only in the local WordPress database.

= Will it hold up for high-volume campaigns? =

For most campaigns, yes — the hit counter uses an atomic `UPDATE meta_value = CAST(meta_value AS UNSIGNED) + 1` so concurrent scans never lose increments. **For sustained loads above roughly 50 scans/sec on a single code**, install a persistent object cache (Redis or Memcached) on the host. The two-tap dedupe and rate-limit guards rely on WordPress transients, which are atomic-ish only when an object cache is present. On vanilla database-backed transients under heavy concurrent scans, those guards become best-effort: the worst-case outcome is "a few duplicate increments slip through during a burst," not data corruption. If you don't run an object cache and a single code is doing print-campaign volume, expect counts to be approximately right, not exactly right.

The standard redirect manager depends on the object cache the same way. Its routing decision reads two small lookup tables (an exact-match map and a pattern list), each built from a single postmeta pivot query. With a persistent object cache those tables are primed once and reused across requests. Without one they are rebuilt on every uncached front-end page load, adding two queries per request while any published redirect exists. Sites that define no redirects pay nothing either way: the router checks a lightweight flag and skips both queries entirely, so a QR-only install carries no routing overhead. If you run the redirect manager at scale, install an object cache for the same reason you would for the scan counters.

== Screenshots ==

1. Edit screen — short URL, live QR preview, destination URL, analytics-mode picker, and per-code stats.
2. List view with campaign filter, sortable hits column, and the "Download QR PNGs (ZIP)" bulk action.
3. Settings page — GA4 Integration, defaults, and routing behavior in three sections.
4. Bulk export progress — generating PNGs in the browser and bundling to a single ZIP.

== Changelog ==

= 1.3.0 =

Hardening and correctness release closing the remaining must-fix items from the 1.1.1 code audit.

* Abuse resistance: The 404 capture recorder now throttles new-row inserts. A per-window insert rate limit (reusing the same transient guard the QR and redirect routers use for hit-counter writes) plus a global row ceiling stop a bot that enumerates unique missing paths from bloating wp_posts and wp_postmeta in a single burst. Repeat hits on a path already recorded still increment its counter as before, so the 404s that matter stay accurate.
* Performance: The redirect router short-circuits before its lookup queries when no published redirect exists. A cfqr_has_redirects flag is maintained on every redirect save, trash, untrash, and delete, so a QR-only install (or one that has not created a redirect yet) no longer pays two postmeta pivot queries on each uncached front-end request.
* Docs: The requirements and the high-volume FAQ now explain that the redirect routing tables, like the dedupe and rate-limit transients, are only cached across requests when a persistent object cache is installed.
* Security: The one-click "Create redirect" prefill now encodes its injected value with JSON_HEX_TAG and JSON_HEX_AMP, matching every other inline-script payload in the plugin.
* Fix: Multisite uninstall now cleans every site on networks with more than 100 blogs. The site query no longer stops at the default 100-site page.
* Docs: Reconciled the stated PHP and WordPress floors (PHP 8.0, WordPress 6.2) between the plugin header and the readme body, and corrected the short-code-length help text to match the sanitizer (range 6 to 12, default 8).

= 1.2.0 =

* Compatibility: QR codes (`cfqr_code`) are now hidden from third-party SEO plugins so they never appear in an XML sitemap or carry an SEO metabox on the editor screen. The post type must stay public for the `/r/{slug}` rewrite to resolve, which previously led SEO plugins to treat each QR code as indexable content and list its redirect URL in their sitemap — the opposite of the `noindex` the router already sends on every scan. Covers Yoast SEO, Rank Math, All in One SEO, The SEO Framework, SEOPress, and Slim SEO. Each plugin's own exclusion filter is used, so nothing is touched when a given plugin is not installed.

= 1.1.1 =

* Security: The redirect manager now detects multi-hop redirect loops at save time. Previously only the direct self-loop (a source pointing at its own path) was caught; a chain such as A → B → A would slip through and only stop at the browser's redirect cap. The save-time check now walks the chain of existing exact-mode redirects from the proposed destination and clears it if the chain routes back to the source.
* Security: The redirect CSV importer now rejects uploads larger than 10 MB before parsing, closing a memory-exhaustion vector where a single line with no newline could be buffered whole regardless of the per-import row cap.

= 1.1.0 =

Major feature release. Adds a full standard redirect manager alongside the existing QR shortlink workflow, plus organizational and operational tooling on top.

**Standard redirect manager.** A second CPT (`cfqr_redirect`) that maps an arbitrary source path on your site to any http(s) destination with a configurable status code. Routes via `parse_request` (priority 0), so matched paths short-circuit normal WP routing.

* Four match modes — exact, wildcard (`*` with `$1..$N` capture refs), regex (raw PCRE), and query-aware (path + query subset match).
* All four status codes selectable per redirect — 301, 302, 307, 308 — with browser-cache warnings on 301/308.
* Object-cached exact-match lookup table (O(1)) plus a separately-cached pattern list (longest-source-first; query-aware → wildcard → regex within a length tie).
* Same atomic-CAST hit counter, per-fingerprint dedupe (HMAC-SHA256, 30-sec window), and per-slug rate limit (10 writes/sec) as the QR router.
* Unified destination safety check shared with the QR side — rejects credentials, localhost, private/reserved IPs, non-http(s) schemes.
* Self-redirect loop guard at both save time (exact mode) and request time (all modes, post-substitution).
* Five new granular capabilities — `cfqr_read_redirects`, `cfqr_create_redirects`, `cfqr_edit_redirects`, `cfqr_delete_redirects`, `cfqr_export_redirects`.
* Destination change audit log (append-only, last 20 entries per redirect, user/IP/timestamp).
* `Cache-Control: no-store` on every redirect response so CDNs don't cache the redirect itself across destination edits.

**Groups (taxonomy).** Flat, non-public `cfqr_redirect_group` taxonomy attached to redirects. Standard WP tag UI in the editor, Groups column with click-to-filter links, group dropdown in the list table. Capability split: `cfqr_manage_redirect_groups` to create/rename groups, `cfqr_edit_redirects` to assign existing groups.

**Scheduled enable/disable windows.** Per-redirect `active_from` and `active_until` datetime bounds (datetime-local inputs, stored as ISO 8601 UTC). Window evaluated at request time on cached rows so boundary crossings activate without needing a cache flush. Outside the window the request falls through to normal WP routing. List table shows in-window vs. out-of-window pills.

**CSV bulk import.** New "Import Redirects" submenu with streaming `fgetcsv` parser. Required headers `source`, `destination`; optional `mode`, `status`, `active`, `groups`, `label`, `active_from`, `active_until`. Per-row validation reuses the same predicates as the meta-box editor. Duplicate policy: skip (default) or update (exact mode only). 5000-row cap per file. Post-redirect flash summary with per-row error reasons. New cap `cfqr_create_redirects` gates access.

**404 capture.** New private CPT `cfqr_404` records every front-end 404. Path stored as the post slug (sha1 of normalized path) for O(1) lookup; repeat 404s atomically increment a hit counter. List table sorted by hits DESC with Path / Hits / First seen / Last seen / Last referer / Status columns. Per-row "Create redirect" action pre-fills the new-redirect editor. Bulk "Mark as ignored" suppresses individual paths from the active-attention view. Daily `wp_cron` event prunes non-ignored rows older than 90 days. Skips reserved paths (wp-admin, /r/, favicon.ico, robots.txt, etc.). New cap `cfqr_manage_404_captures` gates access.

**Other changes.**

* Activation grants the seven new capabilities to administrators (auto-migrated on first load via the existing version-bump grant logic).
* Activation schedules the 404 cleanup cron; deactivation unschedules it.
* Uninstall extends to delete `cfqr_redirect` and `cfqr_404` posts, taxonomy terms, and the seven new caps. Multisite-aware as before.
* Tests: 90 new assertions in `tests/test-redirect.php` (path normalization, sanitizers, wildcard/regex compile + capture, query-aware subset match, capture substitution, schedule window evaluator, CSV row parser/validator, 404 path hashing). Combined with the original suite that's 129 tests passing.

= 1.0.3 =

Follow-up to the 1.0.2 Plugin Check pass. Resolves one stray nonce-verification warning that survived 1.0.2: a wrapped ternary in `save_quick_edit()` placed the `$_POST` access on a continuation line, beyond the reach of the `phpcs:ignore` annotation. Collapsed to a single line. No behavior change.

= 1.0.2 =

WordPress Plugin Check (PCP) compliance pass. No behavior changes — every fix is either a sanitizer wrapper, a clarifying inline annotation, or a doc tweak.

* `$_SERVER` reads (`REQUEST_METHOD`, `REMOTE_ADDR`, `HTTP_USER_AGENT`) now go through `wp_unslash()` + `sanitize_text_field()` before any use.
* `$_POST` destination input in `save_meta()` and `save_quick_edit()` now passes through `sanitize_text_field()` before `esc_url_raw()` to satisfy the static analyzer (functionally equivalent — URLs contain none of the characters `sanitize_text_field` strips).
* Inline annotations added to document the intentional use of `wp_redirect()` (external destinations are the product), direct `$wpdb` queries (atomic UPDATE for hit counter, cached campaign filter join, slug uniqueness check, uninstall cleanup), and list-table `$_GET` filter reads (standard WP nonceless convention).
* Removed the explicit `load_plugin_textdomain()` call — WordPress 4.6+ loads translations automatically from the plugin's Text Domain header.
* Bumped "Tested up to" to 6.9.

= 1.0.1 =

Security and hardening release. Addresses findings from a static code review of 1.0.0.

* **Capabilities split.** The single `manage_qr_codes` cap is replaced by six plugin-prefixed caps: `cfqr_read_codes`, `cfqr_create_codes`, `cfqr_edit_codes`, `cfqr_delete_codes`, `cfqr_export_codes`, `cfqr_manage_settings`. Existing installs are migrated automatically — any role that held `manage_qr_codes` receives the equivalent full set on first load.
* **Settings page capability fixed.** The settings form now correctly submits for users who hold `cfqr_manage_settings` without needing `manage_options` (via `option_page_capability_cfqr_settings_group`).
* **Destination URL validation tightened.** Rejects userinfo credentials (`user:pass@host`), `localhost`, private and reserved IP literals, and URLs longer than 2 048 characters.
* **Router method restriction.** `/r/{slug}` now returns `405 Method Not Allowed` for anything other than GET/HEAD. Hit counters can no longer be incremented by POST/PUT scanners.
* **Public-endpoint write amplification reduced.** Duplicate-scan transients are no longer refreshed on every hit — first hit writes, duplicates read-only. Fingerprint hash switched to HMAC-SHA256 with `wp_salt('nonce')` so leaked transient data isn't brute-forceable back to IP+UA.
* **Referrer & header hardening.** Added `Referrer-Policy: no-referrer` to all redirect responses. Intermediate-page CSP gains `frame-ancestors 'none'`; response adds `X-Content-Type-Options: nosniff` and a baseline `Permissions-Policy`.
* **GA4 payload privacy.** Intermediate mode now sends `destination_host` to GA4 instead of the full destination URL — query parameters, tokens, signed paths, and customer identifiers are no longer forwarded to Google.
* **Slug length defaults raised.** Auto-generated slugs default to 8 characters (was 6); minimum configurable lowered from 4 to 6. The 8-character default puts the search space at ~1.5 trillion combinations.
* **Bulk-export tokens bound to user.** Each one-shot export token is now tied to the user who created it; another authenticated user cannot consume it.
* **Destination change audit log.** Every change to a code's destination URL is now recorded to `_cfqr_destination_log` post meta: `{from, to, user_id, ip, ts}`, capped at 20 entries per code. Provides an immediate in-DB audit trail for the most security-relevant event in a redirect manager.

= 1.0.0 =

Initial public release.

* Custom post type with `/r/{slug}` rewrite and `template_redirect`-based routing.
* Two analytics modes: UTM injection (default 302) and intermediate page with `qr_redirect` GA4 event.
* Single-step creation: short code is generated when the Add New screen loads.
* Bulk PNG ZIP export via the list-table bulk-action menu (client-side Canvas + JSZip; no server image library required).
* Custom `manage_qr_codes` capability granted to Administrator on activation, delegable to other roles via the standard WP role API.
* CPT capability mapping with `map_meta_cap => false` — `edit_posts` alone does not grant QR access.
* Atomic hit-counter increment via SQL UPDATE — no lost counts under concurrent scans.
* Per-fingerprint scan deduplication (hashed IP + UA + slug, 30-second window) to absorb double-tap scans, monitoring pings, and refresh-to-verify behavior.
* Per-slug rate limit on hit-count writes (10/sec) so a runaway scanner can't pin the DB.
* CSP-hardened intermediate page with per-request nonces.
* Belt-and-suspenders meta-refresh fallback (10 seconds) for the rare slow connection where the JS redirect path hasn't finished by then.
* GA4 Measurement ID auto-detection: explicit setting → `CFQR_GA4_ID` constant → Site Kit option.
* Optional GA4 Property ID setting for property-aware "Open GA4" deep links.
* Campaign filter dropdown on the QR Codes list table, backed by a 12-hour transient invalidated on save/delete/trash.
* `410 Gone` for draft/inactive codes; `X-Robots-Tag: noindex` on every redirect.
* Unified destination-safety predicate (`CFQR_URL::is_safe_destination`) used by the router, the meta-box save, and Quick Edit. Quick Edit now surfaces an admin notice when it rejects a URL instead of silently saving an empty one.
* Multisite-aware uninstall: per-blog cleanup of posts, postmeta, options, and the custom capability.
* Full i18n with `cf-qr-redirect.pot` shipped; translations welcome via translate.wordpress.org.

== Upgrade Notice ==

= 1.3.0 =

Hardening and correctness. Throttles 404-capture writes against path-enumeration abuse, skips redirect lookups when no redirects exist, and fixes multisite uninstall on networks with more than 100 sites. Recommended for all installs.

= 1.1.1 =

Security hardening. Adds multi-hop redirect loop detection at save time and a CSV import size limit. Upgrade recommended.

= 1.1.0 =

Adds a full standard redirect manager (exact/wildcard/regex/query-aware, 301/302/307/308, hit tracking), redirect groups, scheduled windows, CSV bulk import, and 404 capture with one-click redirect creation. Seven new caps auto-granted to administrators.

= 1.0.3 =

One-line follow-up to the 1.0.2 Plugin Check compliance pass. Safe drop-in.

= 1.0.2 =

WordPress Plugin Check compliance pass — no behavior changes. Safe drop-in.

= 1.0.1 =

Security and hardening release. The `manage_qr_codes` capability is renamed to a prefixed set (`cfqr_read_codes`, `cfqr_edit_codes`, `cfqr_manage_settings`, etc.) — existing roles are migrated automatically on first load. Recommended for all installs.

= 1.0.0 =

Initial public release.
