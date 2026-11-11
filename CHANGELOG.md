# Changelog

Published GitHub releases for the plugins in this repository, newest first.
Dates are America/Los_Angeles. The text of each entry is the release note as published; it is not rewritten here.
Each plugin also keeps a changelog in its `readme.txt`.

## CF Post List View 1.0.1

Released 2026-10-09 12:27 PM PT (cf-post-list-view-v1.0.1).

* Respect author, private-post and custom post type permissions in REST lists and CSV exports.

WordPress 6.2+, PHP 8.1+. Updates are installed manually through Plugins → Add New → Upload Plugin.

SHA-256: `0e42f421142cb42ec397d6d9ce28bd7b1b2a04ed47d81d40ca9b47bf8dce2804`

Assets: `cf-post-list-view-1.0.1.zip`.

## CF Bulk Meta Editor 1.0.5

Released 2026-10-09 12:27 PM PT (cf-bulk-meta-editor-v1.0.5).

* Remove development files from the bundled shared library. Require PHP 8.1, matching the supported runtime.

WordPress 6.2+, PHP 8.1+. Updates are installed manually through Plugins → Add New → Upload Plugin.

SHA-256: `cf7fefd49ada289bce5650991f41237f38921f2823bd41d03ea73fc36e713d59`

Assets: `cf-bulk-meta-editor-1.0.5.zip`.

## CF Content Calendar 0.1.2

Released 2026-10-09 12:27 PM PT (cf-content-calendar-v0.1.2).

* Show a visible warning when a calendar range exceeds 500 posts.
* Use WordPress REST path middleware so loading, creating and rescheduling also work with plain permalinks.

WordPress 6.2+, PHP 8.1+. Updates are installed manually through Plugins → Add New → Upload Plugin.

SHA-256: `6dd9f374d6b5de2511a40fb250afc20893c494f59f321ab347e609b059809961`

Assets: `cf-content-calendar-0.1.2.zip`.

## CF Chunked Upload 1.2.1

Released 2026-10-09 12:27 PM PT (cf-chunked-upload-v1.2.1).

* Reject chunk writes to upload sessions owned by another user. Require PHP 8.1, matching the supported runtime.

WordPress 6.2+, PHP 8.1+. Updates are installed manually through Plugins → Add New → Upload Plugin.

SHA-256: `2ab60bc28b9b0f33c920507fff3c4e4540b584c1818a90dd39d7169e309925a6`

Assets: `cf-chunked-upload-1.2.1.zip`.

## CF Media Optimizer 3.0.3

Released 2026-10-09 12:27 PM PT (cf-media-optimizer-v3.0.3).

* Transparent PNGs no longer get a flattened AVIF. Hosts whose ImageMagick AVIF writer drops alpha (6.9.11 with libheif, as on WP Engine) encoded transparent pixels as black, so icons and logos showed as solid squares. The encoder is now probed for alpha support and every AVIF written from a transparent source is checked; when alpha is missing, the image is served as WebP only and a previously flattened AVIF is removed.
* Existing flattened AVIFs are removed on forced regeneration of the affected PNGs; opaque PNGs and JPEGs keep their AVIF.

WordPress 6.2+, PHP 8.1+. Updates are installed manually through Plugins → Add New → Upload Plugin.

SHA-256: `25531a25849478ba1da8fe12a7305625600a63222903b3f406eecbf563a8fd45`

Assets: `cf-media-optimizer-3.0.3.zip`.

## CF Media Manager 3.0.1

Released 2026-10-09 12:26 PM PT (cf-media-manager-v3.0.1).

Attachment Trash actions now use the reversible WordPress Trash operation. The action is hidden and rejected when Media Trash is disabled or retention is zero, and repeated Trash requests cannot permanently delete an attachment.

Upgrading from 2.x: install CF Media Optimizer alongside 3.x to retain conversion and picture delivery.

WordPress 6.2+, PHP 8.1+. Updates are installed manually through Plugins → Add New → Upload Plugin.

SHA-256: `1f650295d10a4fc06bd42cdbc3d432fb655ed0d0e0224ce2ee9d61e2bcbec780`

Assets: `cf-media-manager-3.0.1.zip`.

## CF Post List View 1.0.0

Released 2026-08-30 12:23 AM PT (cf-post-list-view-v1.0.0).

First release. A developer-grade list view under Posts: 40+ columns across 7 groups (identity, content, SEO meta for Yoast and Rank Math, hierarchy, taxonomies, and more), dynamic taxonomy columns per post type, search and status filters, per-post-type column preferences, and CSV export that mirrors the view. Two REST endpoints behind the edit_posts capability; no new database tables, nothing stored on uninstall.

Smoke-tested on WordPress 7.1 / PHP 8.5 across plain and pretty permalinks, with Yoast SEO, Rank Math, and no SEO plugin.

Requires WordPress 6.2+ and PHP 8.1+. GPL v2+.

Assets: `cf-post-list-view-1.0.0.zip`.

## CF Media Optimizer 3.0.0

Released 2026-08-29 06:57 PM PT (cf-media-optimizer-v3.0.0).

First standalone release. CF Media Optimizer is the conversion half of the Media Manager 3.0 split: WebP and AVIF generation with native <picture> fallback, originals untouched, plus the Adopt Legacy Variants wizard for sites migrating from other WebP plugins. Installs standalone; pairs with CF Media Manager 3.0.0 to share one in-use scan.

Requires WordPress 6.2+ and PHP 8.1+. GPL v2+.

Assets: `cf-media-optimizer-3.0.0.zip`.

## CF Content Calendar 0.1.1

Released 2026-08-29 04:32 PM PT (cf-content-calendar-v0.1.1).

First public release. Editorial calendar with month, week, and list layouts; create drafts from open dates and reschedule from the calendar. Includes a fix for stale GMT publish dates when dragging a scheduled post back to draft. Early release; expect rough edges.

Assets: `cf-content-calendar-0.1.1.zip`.

## Schema Override Manager 1.0.1

Released 2026-08-29 04:32 PM PT (schema-override-manager-v1.0.1).

Critical structured-data fix: 1.0.0 emitted an identification marker inside the JSON-LD payload, which strict parsers (including Google) rejected. The marker now lives on the script tag as a data attribute; the payload is pure JSON. Update immediately.

Assets: `schema-override-manager-1.0.1.zip`.

## CF Media Manager 3.0.0

Released 2026-08-29 04:32 PM PT (cf-media-manager-v3.0.0).

The split release: CF Media Manager is now the management half (Library list view, five-report media audit, bulk alt-text). WebP/AVIF conversion and picture delivery moved to CF Media Optimizer. No management data migration required. See readme.txt changelog.

Assets: `cf-media-manager-3.0.0.zip`.

## CF QR Redirect 1.3.1

Released 2026-08-29 04:32 PM PT (cf-qr-redirect-v1.3.1).

Security and correctness release: PCRE2 match/depth limits on regex and wildcard redirects (fail-closed), read-only role menu access fixed, raw regex sources preserved, documented 410 for unpublished slugs, full multisite network activation support. See readme.txt changelog for details.

Assets: `cf-qr-redirect-1.3.1.zip`.

## Schema Override Manager 1.0.0

Released 2026-07-02 09:57 PM PT (schema-override-manager-v1.0.0).

**Schema Override Manager 1.0.0** — View, suppress, extend, and inject JSON-LD structured data at the global, post-type template, and per-page level. Works alongside Yoast SEO, Rank Math, and theme-injected schema — without duplicating what's already there.

**Install:** download `schema-override-manager-1.0.0.zip` below and upload it in WordPress via Plugins → Add New → Upload Plugin.

Assets: `schema-override-manager-1.0.0.zip`.

## CF Bulk Meta Editor 1.0.4

Released 2026-07-02 09:27 PM PT (cf-bulk-meta-editor-v1.0.4).

* Fix: Uninstall now removes the correct log directory (`uploads/cf-bulk-meta-editor/`) — the path previously referenced the old plugin slug and cleanup silently did nothing on sites that had ever had logs written.
* Fix: Removed stale `delete_option('bulk_meta_editor_log_filename')` call in uninstall — the option was never written in any current or prior code path.
* Compliance: Added `PrefixAllGlobals` prefix allowlist (`cfbme`, `BME`, `BulkMetaEditor`) to `phpcs.xml.dist` so WordPress.org Plugin Check no longer flags `BME_*` constants as `NonPrefixedConstantFound`.
* Polish: Corrected plugin installation instructions in readme — folder slug and admin navigation path were both stale.
* Polish: Removed dead `check_edit_posts()` method from `DiagnosticsController` — all diagnostic routes require `manage_options`; the method was unreachable.

Assets: `cf-bulk-meta-editor-1.0.4.zip`.

## CF QR Redirect 1.3.0

Released 2026-07-02 09:22 PM PT (cf-qr-redirect-v1.3.0).

Hardening and correctness release closing the remaining must-fix items from the 1.1.1 code audit.

* Abuse resistance: The 404 capture recorder now throttles new-row inserts. A per-window insert rate limit (reusing the same transient guard the QR and redirect routers use for hit-counter writes) plus a global row ceiling stop a bot that enumerates unique missing paths from bloating wp_posts and wp_postmeta in a single burst. Repeat hits on a path already recorded still increment its counter as before, so the 404s that matter stay accurate.
* Performance: The redirect router short-circuits before its lookup queries when no published redirect exists. A cfqr_has_redirects flag is maintained on every redirect save, trash, untrash, and delete, so a QR-only install (or one that has not created a redirect yet) no longer pays two postmeta pivot queries on each uncached front-end request.
* Docs: The requirements and the high-volume FAQ now explain that the redirect routing tables, like the dedupe and rate-limit transients, are only cached across requests when a persistent object cache is installed.
* Security: The one-click "Create redirect" prefill now encodes its injected value with JSON_HEX_TAG and JSON_HEX_AMP, matching every other inline-script payload in the plugin.
* Fix: Multisite uninstall now cleans every site on networks with more than 100 blogs. The site query no longer stops at the default 100-site page.
* Docs: Reconciled the stated PHP and WordPress floors (PHP 8.0, WordPress 6.2) between the plugin header and the readme body, and corrected the short-code-length help text to match the sanitizer (range 6 to 12, default 8).

Assets: `cf-qr-redirect-1.3.0.zip`.

## CF Media Manager 2.3.0

Released 2026-07-02 09:21 PM PT (cf-media-manager-v2.3.0).

* **Fix: unused-attachment scan false positives.** The in-use scanner now examines content in `future` (scheduled), `private`, `pending`, and `draft` states, not just `publish`, so an image used only on not-yet-public content is no longer classed as unused. It also resolves ACF gallery / repeater fields (serialized ID arrays) and URL-stored ACF fields, and strips WordPress's `-scaled` and `-e{timestamp}` filename suffixes so scaled and edited-image URLs resolve back to the parent attachment. The Unused Attachments report copy now states plainly that the scan is best-effort: verify before deleting. Items are still sent to Trash (recoverable), never permanently deleted.
* **Fix: `<picture>` sources no longer advertise a partial srcset.** When a WebP/AVIF variant was missing for one descriptor in an image's `srcset`, the `<source>` used to list fewer resolutions than the original `<img>`, so a browser could pick a lower resolution than was actually available. A per-format `<source>` srcset is now emitted only when the whole ladder exists; otherwise it falls back to the single primary variant or to no `<source>`, leaving the full resolution set on the `<img>`.
* **Fix: "force rescan" now actually forces a rescan, and report thresholds are reachable.** The Audit tab's scan config is threaded through to each report, so a forced rescan refreshes the in-use scan and the Oversized Originals size and dimension thresholds apply instead of always using the defaults.
* **Change: heavy scans are gentler on large libraries.** The Orphan Files walk no longer follows symlinks (a symlink loop or a link pointing outside uploads can no longer surface external files or hang the walk) and is bounded so a runaway uploads tree cannot exhaust memory. The in-use scan can now run in the background through the same cron / Action Scheduler model the converter queue uses, warming its cache off the request path on sites that use the audit reports.
* **Housekeeping.** Corrected the "Tested up to" WordPress version.

Assets: `cf-media-manager-2.3.0.zip`.

## CF Chunked Upload 1.2.0

Released 2026-07-02 09:21 PM PT (cf-chunked-upload-v1.2.0).

* Security: The per-user storage quota is now enforced against the actual bytes received by the server, not the file size the browser reports. A client that understated or zeroed out the declared size could previously write real chunk data to disk without it counting toward the quota. The quota is now re-checked on every chunk, so an over-limit upload is stopped as it happens rather than only at session start.
* Security: Added a disk-space guard to the chunk endpoint. Chunk uploads are refused when free disk space would fall below a configurable minimum (default 512 MB), and an optional per-session size ceiling can cap a single upload independently of the quota. This closes a denial-of-service path where a large upload could exhaust the disk before the finalize-time check ran.
* Compatibility: Corrected the "Tested up to" WordPress version, which previously named a release that does not exist.
* Two new settings: "Per-session maximum (GB)" and "Minimum free disk (MB)".

Assets: `cf-chunked-upload-1.2.0.zip`.

## CF Chunked Upload 1.1.4

Released 2026-06-01 10:21 PM PT (cf-chunked-upload-v1.1.4).

**CF Chunked Upload 1.1.4** — Uploads large files by splitting them into browser-side chunks and reassembling them on the server.

**Install:** download `cf-chunked-upload-1.1.4.zip` below and upload it in WordPress via **Plugins → Add New → Upload Plugin**. No other plugin, Composer, or npm step required.

See the plugin's `readme.txt` for the full feature list and changelog.

Assets: `cf-chunked-upload-1.1.4.zip`.

## CF QR Redirect 1.1.1

Released 2026-06-01 10:21 PM PT (cf-qr-redirect-v1.1.1).

**CF QR Redirect 1.1.1** — Self-hosted QR codes and a redirect manager: branded short URLs on your own domain with native GA4 attribution.

**Install:** download `cf-qr-redirect-1.1.1.zip` below and upload it in WordPress via **Plugins → Add New → Upload Plugin**. No other plugin, Composer, or npm step required.

See the plugin's `readme.txt` for the full feature list and changelog.

Assets: `cf-qr-redirect-1.1.1.zip`.

## CF Media Manager 2.1.2

Released 2026-06-01 10:21 PM PT (cf-media-manager-v2.1.2).

**CF Media Manager 2.1.2** — Converts JPEG/PNG uploads to WebP and AVIF and serves them through `<picture>` with native browser fallback. Originals are never modified.

**Install:** download `cf-media-manager-2.1.2.zip` below and upload it in WordPress via **Plugins → Add New → Upload Plugin**. No other plugin, Composer, or npm step required.

See the plugin's `readme.txt` for the full feature list and changelog.

Assets: `cf-media-manager-2.1.2.zip`.
