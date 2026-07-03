=== CF Media Manager ===
Contributors: caifrazier
Tags: webp, avif, image optimization, performance, picture
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Convert JPEG/PNG uploads to WebP and AVIF, then serve them through <picture> wrapping with native browser fallback. No nginx or .htaccess required.

== Description ==

CF Media Manager generates `.webp` and (when supported) `.avif` versions of every JPEG and PNG in your media library, then transparently wraps `<img>` tags in `<picture>` elements so browsers automatically pick the best supported format. Originals are never modified.

**How it works**

1. On upload, the plugin generates `.webp` (and `.avif` if Imagick supports it) for the original and every WordPress thumbnail size.
2. On every front-end request, the plugin's output buffer wraps upload-directory `<img>` tags in `<picture><source type="image/avif"><source type="image/webp"><img></picture>`. The original `<img>` stays untouched as the fallback.
3. URLs outside `<img>` tags (CSS background-image, OG meta, JSON blobs) are still rewritten to WebP.
4. If a variant is missing for any reason, the original URL is served unchanged. Nothing 404s.

**Features**

* WebP **and** AVIF output — AVIF when Imagick has the libavif coder
* `<picture>` wrapping with full srcset and sizes preservation, plus `data-no-webp` opt-out
* Background queue using Action Scheduler when available, WP-Cron otherwise — close the tab and the conversion keeps running
* Bulk conversion (foreground or background) with live progress, force-reconvert, and a per-image picker
* Configurable output quality (1–100, default 82) applied to both formats
* Master enable/disable for HTML rewriting
* Serve modern formats only to logged-out visitors
* Per-page blacklist or whitelist using URL path patterns with wildcards
* Open Graph and Twitter Card image rewriting via filter hooks for Yoast, RankMath, and SEOPress
* WebP status column in the Media Library list view
* Live page verifier — fetches a URL on your site and reports the WebP/AVIF coverage percentage (correctly excludes legacy URLs that are valid `<picture>` fallbacks)
* WP-CLI commands: `wp cf-media-manager status`, `wp cf-media-manager convert`, `wp cf-media-manager queue start|status|cancel`, `wp cf-media-manager doctor`
* One-click cache purge for Divi, WP Engine, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, SG Optimizer, Autoptimize, Cache Enabler, Hummingbird, Borlabs Cache, and the Cloudflare official plugin
* Server-side hard caps: 100 IDs per AJAX call, 25-megapixel image-bomb cap, 30 MB source filesize cap, 50,000-ID queue cap, 8 KB pattern textarea limit, realpath containment to the uploads directory, manual same-host redirect validation on the live verifier
* Variant ownership tracking — by default, the plugin only overwrites or deletes `.webp`/`.avif` files recorded in its own manifest, so a user-uploaded WebP that shares a basename with a JPEG sibling is safe from re-encode and Delete All. The optional "Adopt legacy variants" action skips files registered as Media Library attachments but cannot distinguish a manually placed third-party WebP from a legacy plugin-generated one, so admins are asked to confirm a dry-run count before any adoption commits.

**Requires**

PHP 8.0+ and either the Imagick PHP extension or GD compiled with WebP support. The plugin auto-detects what's available; Imagick is preferred when both are present.

**AVIF:** AVIF is enabled by default on hosts where Imagick is built with libavif/libheif support. On hosts where it isn't available (most shared hosting), the plugin automatically falls back to WebP only — no configuration needed. You can toggle AVIF generation in the plugin settings.

== Installation ==

1. Upload the `cf-media-manager` folder to `/wp-content/plugins/`, or install via the Plugins screen.
2. Activate the plugin.
3. Go to **Media → Media Manager**.
4. Click **Convert All** (foreground) or **Convert in Background** (queued — closes-tab safe).
5. After the run completes, purge your page cache so cached pages reload with the new markup.

New uploads are converted automatically — you only need to run a bulk conversion once on the existing library.

== Frequently Asked Questions ==

= Does this delete or modify my originals? =

No. Originals are never touched. The plugin only writes `.webp` and `.avif` files alongside the originals.

= What about browsers that don't support WebP or AVIF? =

The plugin wraps `<img>` tags in `<picture>` elements with `<source type="image/avif">` and `<source type="image/webp">` ahead of the original `<img>`. Browsers select the first source they support; anything that supports neither gets the original `<img>` automatically. No fallback configuration required.

= Why is my AVIF section disabled? =

Your Imagick build does not have the libavif coder. You'll still get WebP. To enable AVIF, your host needs to install ImageMagick with libavif support.

= Can I close the tab while a bulk conversion runs? =

Yes — click **Convert in Background**. The job is queued through Action Scheduler if available (bundled with WooCommerce, etc.), otherwise WP-Cron. The plugin processes 25 attachments per tick. Re-opening the admin page resumes the live progress display.

= Why does my page still show old image URLs? =

If you use a page cache (WP Engine, Cloudflare, WP Rocket, etc.), cached pages were generated before the conversion ran. After conversion completes, the plugin shows a banner with a one-click **Purge All Caches Now** button.

= How do I opt a specific image out? =

Add `data-no-webp` to the `<img>` tag. The Rewriter leaves it untouched.

= Imagick vs GD — which does this plugin use? =

Imagick when available (better WebP quality, correct PNG alpha handling, AVIF support), GD as fallback for WebP only. The admin page shows which engine is active. The conversion log warns when Imagick was tried and silently failed to a GD fallback.

= Can I exclude specific pages? =

Yes. Use the **Page filter** setting under HTML Rewriting. Choose blacklist or whitelist mode and enter URL paths one per line. Use `*` as a wildcard, e.g. `/legacy-page/`, `/shop/*`, `*/old-gallery/*`.

= Is there anything I should configure on the server for safety? =

The plugin only opens JPEG and PNG files (verified by `getimagesize()` and a 25-megapixel pixel-count cap, plus a 30 MB filesize cap, before any decoder runs), so untrusted MVG/MSL/SVG/PS coders never see plugin-supplied bytes. As defense in depth, hosts should ship a hardened ImageMagick `policy.xml` that disables the historically-vulnerable coders. Most managed WordPress hosts already do this; on a self-managed server, ensure `/etc/ImageMagick-*/policy.xml` (or the equivalent for your build) contains rights="none" entries for at least `MVG`, `MSL`, `LABEL`, `URL`, `HTTPS`, `HTTP`, `FTP`, `EPHEMERAL`, `PS`, `PS2`, `PS3`, `EPS`, `PDF`, and `XPS`. This is a server-level setting and is not specific to this plugin, but it neutralizes a broad class of ImageMagick CVEs.

= Is there a WP-CLI interface? =

Yes:

    wp cf-media-manager status
    wp cf-media-manager convert
    wp cf-media-manager convert --force
    wp cf-media-manager convert --ids=12,34,56
    wp cf-media-manager convert --quality=78
    wp cf-media-manager queue start
    wp cf-media-manager queue start --force
    wp cf-media-manager queue status
    wp cf-media-manager queue cancel
    wp cf-media-manager doctor
    wp cf-media-manager doctor --fix

== Screenshots ==

1. The main admin page showing bulk conversion, AVIF status, quality settings, the page filter, and the live verifier.
2. WebP status column in the Media Library list view.
3. Cache management section with auto-detected page caches.
4. Background queue card with cancel/dismiss controls.

== Changelog ==

= 2.3.0 =
* **Fix: unused-attachment scan false positives.** The in-use scanner now examines content in `future` (scheduled), `private`, `pending`, and `draft` states, not just `publish`, so an image used only on not-yet-public content is no longer classed as unused. It also resolves ACF gallery / repeater fields (serialized ID arrays) and URL-stored ACF fields, and strips WordPress's `-scaled` and `-e{timestamp}` filename suffixes so scaled and edited-image URLs resolve back to the parent attachment. The Unused Attachments report copy now states plainly that the scan is best-effort: verify before deleting. Items are still sent to Trash (recoverable), never permanently deleted.
* **Fix: `<picture>` sources no longer advertise a partial srcset.** When a WebP/AVIF variant was missing for one descriptor in an image's `srcset`, the `<source>` used to list fewer resolutions than the original `<img>`, so a browser could pick a lower resolution than was actually available. A per-format `<source>` srcset is now emitted only when the whole ladder exists; otherwise it falls back to the single primary variant or to no `<source>`, leaving the full resolution set on the `<img>`.
* **Fix: "force rescan" now actually forces a rescan, and report thresholds are reachable.** The Audit tab's scan config is threaded through to each report, so a forced rescan refreshes the in-use scan and the Oversized Originals size and dimension thresholds apply instead of always using the defaults.
* **Change: heavy scans are gentler on large libraries.** The Orphan Files walk no longer follows symlinks (a symlink loop or a link pointing outside uploads can no longer surface external files or hang the walk) and is bounded so a runaway uploads tree cannot exhaust memory. The in-use scan can now run in the background through the same cron / Action Scheduler model the converter queue uses, warming its cache off the request path on sites that use the audit reports.
* **Housekeeping.** Corrected the "Tested up to" WordPress version.

= 2.2.1 =
* **Fix: doubled periods in the per-attachment convert "reasons" message.** When `convert_batch` returned reasons that already ended in a period, `formatReasonSuffix` joined them into strings like "… .; ….." Each reason now has any trailing period stripped before the list is joined, so the suffix reads cleanly as " — reason1; reason2.". Display-only; no change to conversion behavior.

= 2.2.0 =
* **New: render-time alt-text fallback.** Page builders — Divi's image module above all — store a per-instance alt in the layout, captured when the image was inserted, and never re-read the attachment field afterward. So alt text set in the Media Library or this plugin's Accessibility tab never reaches the page; the image ships with `alt=""`. A new setting (Convert → Settings → "Alt text fallback", on by default) fills any empty or missing `alt` on an uploads-folder image from that attachment's alt text at render time, riding the existing rewrite output pass. It only ever adds an accessible name — never overrides an author-set alt — and skips images flagged decorative, `aria-hidden="true"`, `role="presentation"`, or carrying `data-no-alt`. Resolves builder-hard-coded `.webp`/`.avif` URLs back to their source attachment.
* **New: "Save all changes" on the Accessibility tab.** Edit alt text across the whole page of results and commit every changed row in a single request. Edited rows are highlighted and the button shows a live count; unchanged rows are never re-written.
* **New: click-to-enlarge image preview on the Accessibility tab.** The thumbnail is now a button that opens the full-size image in a popup, so you can actually see what you are describing instead of squinting at a cropped 60×60 square. Close with the ✕, the backdrop, or Escape.
* **New: one-click "Delete conflicting variant attachment" in Diagnose Attachment.** When a `.webp`/`.avif` was imported as its own Media Library attachment (a common migration artifact), it occupies the destination slot and blocks conversion — and neither Adopt nor Claim can resolve it, because both refuse to seize a file that belongs to a real attachment. The diagnostic now offers a guarded one-click delete of that duplicate (re-derived from the source, force-deleted to free the slot, and refused automatically if the duplicate is referenced on the front end).
* **Change: "Claim all untracked variants" is always available.** The bulk-claim button (formerly "Adopt legacy variants…") was permanently hidden after the first run, leaving no way to claim variants that show up later — e.g. a batch of `.webp` files brought in by a media import or left by a previously-removed plugin. It now stays on the Convert tab and is safe to re-run anytime, so a site with dozens or hundreds of newly-unowned variants can be claimed in one pass instead of one-by-one through Diagnose. Renamed for clarity; the underlying claim/adopt behavior is unchanged (orphan files claimed, attachment-owned files skipped).
* **Fix: misleading "Adopt legacy variants" skip message.** When conversion was blocked by an unowned WebP, the message told you to run a button that is hidden after the first backfill — and that, even if run, would deliberately skip the file. It now points to the always-available Diagnose Attachment tool, which reports the exact cause and offers the matching fix.

= 2.1.3 =
* **Fix: a crashed conversion worker could wedge an attachment for up to 5 minutes.** When a php-fpm worker was killed mid-conversion (a native libheif/libaom AVIF crash, or an OOM-kill — neither catchable in PHP), the per-attachment lock's `finally` release never ran, leaking both the object-cache and DB-option lock. The object-cache layer also short-circuited *before* the stale-lock recovery could run, so every retry returned "Conversion already in progress for this attachment" until the full TTL expired. The lock now self-heals: staleness — an expired TTL **or** a dead owner PID (checked via `posix_kill`) — is evaluated before the cache fast-path, so a leaked lock is reclaimed on the very next attempt instead of waiting out the timeout.
* **Fix: an AVIF encode crash no longer crash-loops an attachment.** AVIF encoding runs through libheif/libaom, which can segfault or be OOM-killed at the C level — a process death no `try/catch` can trap. A per-source circuit-breaker now arms immediately before the AVIF encode and disarms only when the encode returns control to PHP; if it hard-crashes, the next run skips AVIF for that source (WebP still converts, so the attachment finishes) and the breaker auto-expires so a transient failure is retried later.

= 2.1.2 =
* **Fix: Adopt button missing after upgrade from CF Media Optimizer.** `is_fresh_install()` only checked for `cf_media_manager_*` option names, so activating on a site that had the previous `cf-media-optimizer` build was mis-detected as a brand-new install and `BACKFILL_DONE` was set prematurely — permanently hiding the Adopt button before the admin could run it. Legacy `cf_media_optimizer_*` options are now recognised as sentinels, and a one-time boot migration clears the incorrectly-set flag on already-affected sites so the button re-appears automatically.

= 2.1.1 =
* **Security: CSV formula-injection hardening (CWE-1236).** Both CSV exports (Media Library list view and Audit detail views) now neutralise cells whose first character is a formula trigger (`=`, `+`, `-`, `@`, tab, CR) by prefixing a single quote. User-editable fields like attachment titles, captions, and alt text can no longer execute as formulas when the export is opened in Excel, Sheets, or Numbers. Escaping is centralised in the shared `CFShared\Csv\Escaper` helper.

= 2.1.0 =
* **Media Library list view** at Media → List View. Configurable list of every attachment with 40+ columns across 7 categories (Identity, File, Content/SEO, Context, Timestamps, EXIF, WP Internals), column selector modal, search, MIME filter, unattached filter, sortable headers, CSV export, REST endpoint at `cf-media-manager/v1/library`. Folds in the standalone `cf-media-list-view` plugin.
* **Audit subsystem** at Media Manager → Audit tab. Five reports: Ghost Attachments (DB rows missing files), Orphan Files (files missing DB rows), Unused Attachments (driven by the existing InUseScanner — the marquee report), Duplicate Originals (SHA-256 grouping), Oversized Originals (configurable size + dimension thresholds). Every flagged item carries a "why" provenance block so users can read what the report checked before acting.
* **Missing alt-text dashboard card** with a deep-link to the Accessibility tab — no duplicate UI.
* **CSV export** on every audit detail view via the new optional `AuditReportCsvExportable` interface.
* **Branding cleanup**: removed "CF" from in-admin UI labels (footer, post-conversion notice).
* Plugin description updated to reflect the expanded feature set.
* Test surface: 540 tests, 1,477 assertions, all green.

= 2.0.1 =
* **Security: multisite capability gates.** Nine destructive AJAX endpoints (`convert_batch`, `queue_start`, `save_settings`, `save_quality`, `count_variants`, `delete_all`, `backfill_manifest`, `claim_variant`, `purge_caches`) now require `manage_network_options` on multisite. Single-site behavior unchanged. Previously a site-admin on subsite B could trigger writes affecting subsite A.
* **Security: SSRF hardening on the live-page verifier.** Extracted the entire fetch surface into a new `UrlVerifier` class with layered defense: scheme allowlist (http/https), strict same-host gate, path policy that blocks `/wp-admin`, `/wp-login.php`, `/xmlrpc.php`, `/wp-json`, `?rest_route=`, and any URL carrying `_wpnonce`; full A + AAAA DNS resolution with every address validated against an IP allowlist (rejects RFC1918, loopback, 169.254.169.254 metadata IP, IPv6 ULA `fc00::/7`, IPv4-mapped wrappings of any of the above); CURLOPT_RESOLVE IP-pinning that closes the DNS rebinding TOCTOU window.
* **Concurrency: H5 queue lock-steal verify-after-write.** Two workers racing to steal the same expired chunk lock now resolve to a single winner.
* **Concurrency: H7 per-attachment convert lock is now option-level atomic.** Replaced the transient check-then-set fallback with `add_option`-atomic acquire + verify-after-write steal pattern (mirrors `Queue::acquire_lock`). Cross-process reentrancy can no longer double-convert.
* **Concurrency: H3 backfill overlap lock + post-INSERT convergence DELETE.** A new transient lock rejects concurrent backfill clicks (HTTP 409). After each chunk's INSERTs, a scoped DELETE-JOIN converges duplicate `(post_id, meta_key)` rows under our `_cf_media_manager_owns_` prefix to a single entry per pair — closes a TOCTOU window that the in-memory dedupe snapshot couldn't see.
* **Correctness: H6 stat-cache flush before delete-confirm.** `delete_all` now flushes the per-request stat cache between `wp_delete_file` and `file_exists`, so successful unlinks aren't miscounted as errors.
* **Correctness: H10 file_exists guard before filemtime.** `Converter::is_attachment_converted` checks both source and webp exist before reading mtimes.
* **Correctness: H9 PCRE-failure fallback in Rewriter.** Every regex pass over the HTML buffer is wrapped in a `preg_last_error()` check. On backtrack-limit / recursion-limit / JIT-stack-overflow the rewriter falls back to the unmodified HTML instead of emitting the literal string "null" into the response body.
* **Correctness: H2 containment filter in `bulk_insert_owns`.** Belt-and-suspenders against poisoned scanner output — any write whose resolved absolute path falls outside the uploads tree is dropped before the INSERT.
* **Performance: H4 ACF scanner query rewrite.** Dropped the per-row REGEXP + double CAST in `scan_acf_postmeta`; new query uses `meta_key NOT LIKE %s AND meta_value IN (%d, ...)` with full `$wpdb->prepare`. Big win on large `wp_postmeta` tables.
* **Performance: M13/M14 pre-warm postmeta cache.** `Disk::estimate_required_space` and `Ajax::get_conversion_counts` now `update_meta_cache( 'post', $batch_ids )` per chunk so the per-attachment metadata lookups don't issue one SELECT each.
* **Performance: M8 legacy LIKE scan gated on BACKFILL_DONE.** The pre-1.2.2 fallback `LIKE` scan in `VariantManifest::is_owned` is skipped once the admin runs Adopt. Fresh installs now also auto-set BACKFILL_DONE at activation (autoload=true), so greenfield sites never hit the legacy path.
* **Performance: M6 builder fingerprint in scan cache key + narrowed plugin-activation hook.** `InUseScanner` transient key now includes an md5 of the active builder set; activating/deactivating a builder produces a fresh key. The `activated_plugin` / `deactivated_plugin` wildcard transient-sweep is short-circuited when the builder fingerprint hasn't changed (no DB work on non-builder plugin events).
* **Input: H1 CSV-string id input accepted.** New `Request::post_id_list()` accepts both `ids[]` array shape and `ids=12,34,56` CSV. Fixes a silent bug where the legacy parse dropped every id after the first on CSV input. Non-scalar smuggling guard prevents PHP 8.5+ `intval([...])=1` exploits.
* **Input: H8 purge flag on any successful convert.** `Ajax::convert_batch` now sets the post-conversion purge flag whenever `converted > 0`, not only when `bytes_saved > 0` (matches `Queue::process_chunk`'s condition).
* **Centralization: `Paths::to_rel()` / `to_rel_or_empty()`.** Replaces the duplicated `$base_dir + strpos + substr` idiom at 5 sites across `Ajax` and `VariantManifest`.
* **Centralization: `cfPost(action, data)` JS helper.** Replaces 20 `$.post( cfMediaManager.ajaxUrl, { action: 'cf_media_manager_X', nonce: cfMediaManager.nonce, ... } )` call sites in `admin.js`. Nonce attachment is now structural — a new endpoint can no longer omit it.
* **Cleanup.** Deleted the unused non-bulk `backfill_subtree()` method; fixed stale `Options::QUEUE_LOCK` doc comment (it's an option, not a transient); removed an unreachable `elseif` branch in `Plugin::boot()`; wrapped the CLI `--quality` override in `try/finally`; removed the deprecated `imagedestroy()` GD call (no-op since PHP 8.0, deprecated on 8.5+).
* **Tests.** Suite grew from 233 to 334+ tests / 647+ assertions, including SSRF, multisite-cap, lock-steal-race, reentrancy, dedupe, containment, pathological-HTML, PCRE-failure, multisite-uninstall, AltTextManager, Disk-containment, and builder-fingerprint coverage.

= 2.0.0c =
* **Rewriter now wraps `<img>` tags with root-relative and protocol-relative `src` attributes.** Previously the rewriter required the `src` to start with the full absolute upload URL (`https://site.com/wp-content/uploads/...`). Hand-coded HTML inside Divi Code Modules, Elementor HTML widgets, WPBakery raw HTML blocks, and similar page-builder content typically uses root-relative URLs (`/wp-content/uploads/...`) — best practice for hand-coded markup since they survive HTTP/HTTPS toggles and domain swaps — and these were silently dropped. The rewriter now accepts all three URL forms: absolute, protocol-relative (`//host/path`), and root-relative (`/path`). Cross-host URLs and URLs outside the uploads tree are still rejected.
* **Same fix applied to `Paths::url_to_path()` and `substitute_remaining_urls()`.** `url_to_path` (which the rewriter's `variant_exists` check calls) now resolves root-relative URLs to their on-disk paths, so `<img srcset>` entries with root-relative URLs also get WebP/AVIF wrapping. The URL-substitution regex used for non-`<img>` contexts (CSS `background-image`, JSON blobs, etc.) was broadened to match all three forms.
* **New `Paths::normalize_upload_url()` helper** with full test coverage for the three URL forms and rejection cases (cross-host, outside-uploads, malformed).

= 2.0.0b =
* **Fixes Adopt silently failing to write manifest rows.** The 2.0.0a bulk-insert path used a single multi-row `INSERT VALUES (?,?,?), (?,?,?), ...` per chunk with 1500 placeholders in one `wpdb::prepare()` call. WordPress 6.x's stricter prepare() validation rejected that template on some hosts; the prepare returned empty, `wpdb::query()` ran nothing, and Adopt reported "Done" while writing zero rows. The new code uses `wpdb::insert()` per row — slower by ~3x than a working bulk insert would be, but bulletproof. Combined with the pre-built lookup maps from 2.0.0a (which were the actual big speedup), Adopt is still ~100x faster than the legacy path.
* **New Diagnose Attachment tool (Convert tab).** Paste an attachment ID, get a full report: where the source is on disk, whether the WebP exists at the destination, whether it's owned in the manifest, what the lookup maps say. Verdict line tells you exactly why Adopt skipped a specific file. When the report shows the WebP is on disk but the manifest row is missing, a one-click "Claim this WebP" button fixes that single attachment without re-running the full Adopt pass.
* **Default quality changed from 82 to 80.** 80 matches the default of common image-processing libraries (Sharp etc.) and removes the "why 82?" cognitive friction. Visually indistinguishable from 82 on photographs; ~1-3% smaller files. Existing installs keep whatever value is stored in the `cf_media_manager_quality` option — only new installs see the new default.
* **WP_DEBUG-gated logging on insert failure** in `bulk_insert_owns` so the next time writes silently disappear we have a breadcrumb in error_log instead of having to reason about it from symptoms.

= 2.0.0a =
* **Adopt legacy variants is dramatically faster and more reliable.** The backfill no longer calls WordPress's `attachment_url_to_postid()` per file — it now pre-builds two in-memory hash maps in one query each (`_wp_attached_file` rows → originals, `_wp_attachment_metadata.sizes[]` rows → size variants) and resolves source paths via O(1) lookups. On a 5,000-attachment site this turns roughly half-a-million DB queries into a few hundred. **Also fixes the "unowned WebP exists in the destination slot" issue on sites where another image-optimizer, CDN, or media-offload plugin was hooking `attachment_url_to_postid` and silently returning 0 for paths that genuinely belonged to attachments** — variants those plugins masked are now claimed correctly.
* **Single-pass adopt with inner chunking.** The previous two-pass (dry-run + commit) flow ran the full resolution work twice. The new code commits in one pass and breaks each subtree into 1,000-file inner chunks, so even a 50,000-file year folder fits inside `max_execution_time`. The progress label shows `(subtree N/M, K files)` so admins see continuous movement.
* **Batched manifest writes.** `add_post_meta` per file is replaced with a single bulk `INSERT` per inner chunk (500 rows per SQL packet, well under `max_allowed_packet`).
* New `AttachmentLookup` helper class (`includes/AttachmentLookup.php`) exposes the prebuilt maps statically; future bulk operations on attachment metadata can reuse it.

= 2.0.0 =
* **Plugin renamed to CF Media Manager (was CF WebP Converter).** Reflects expanded scope — beyond WebP/AVIF conversion to a general-purpose media management toolkit. Folder, namespace, option keys, AJAX actions, hooks, postmeta prefix, admin slug, and text domain all changed in lockstep. **Clean break with no migration shim** — this is effectively a new plugin. Delete the old `cf-webp` plugin from the Plugins screen (originals are never touched; previously-generated `.webp`/`.avif` variants stay on disk; the new plugin will discover and adopt them via **Adopt legacy variants**) and install fresh.
* **Tabbed admin UI.** Convert / Accessibility / Library / Settings. The conversion tools (bulk conversion, specific images, cache management, live page verifier) live under Convert. All configuration (HTML rewriting toggle and behavior, quality, page filter, on-uninstall behavior) moved to Settings. Tab selection persists across page loads.
* **Alt Text Manager (new — Accessibility tab).** Bulk audit and inline editor for image alt text. Reads/writes the standard WordPress `_wp_attachment_image_alt` field so updates propagate everywhere themes resolve attachment alt. Four filter modes — all images, missing alt only, in-use only, in-use + missing alt (the default; the highest-leverage view for a real accessibility pass on a client site). Per-row decorative checkbox writes an opt-in `_cf_media_manager_decorative` flag so intentionally-empty alt on purely decorative imagery doesn't get false-flagged as missing.
* **In-use detection now covers reusable blocks (`wp_block`), block theme templates (`wp_template`, `wp_template_part`), widget areas (text, image, block, gallery, custom HTML), ACF image fields, and WooCommerce product galleries.** Previous versions silently missed images referenced through any of these surfaces. Improvement flows through to both the "In-use only" bulk conversion scope and the new alt text in-use filter. ACF and WooCommerce sources are gated on plugin detection.
* **Library Audit tab placeholder.** Orphan finder, oversized originals report, and unused size variant cleanup land in a follow-up release.

= 1.2.8 =
* **Settings page reorganized into a two-column layout.** Actions and tools (Bulk Conversion, Convert Specific Images, Cache Management, Live Page Verifier) now live in the wider 2/3 column on the left; configuration (HTML Rewriting, Quality) lives in the narrower 1/3 column on the right. The page no longer requires deep scrolling to reach the verifier or cache controls. Stacks to a single column on viewports under 1100px so settings drop below actions instead of getting squeezed.
* **Engine and AVIF badges demoted to the footer.** They were front-and-center for diagnostic reasons during development; in practice admins set up the plugin once and never need to see them again. Now they live in a small muted strip right next to the version number and bug-report link.

= 1.2.7 =
* **Max source filesize is now admin-configurable.** Default raised from 30 MB to **50 MB**. Configure in Settings → "Max source filesize" (1–200 MB). Reducing high-filesize source images is the point of the plugin; the cap exists only to bound per-request decoder memory. The hard ceiling (200 MB) cannot be bypassed even by a hand-edited option, and pairs with PHP's `memory_limit` for image operations — raise that alongside the cap on hosts with high-megapixel photography workflows.
* **Skip and failure reasons surfaced in the batch UI.** Every skipped or failed attachment now carries a translated, human-readable reason: foreign WebP exists (run Adopt), source exceeds size cap (with actual size and configured cap), decompression-bomb pixel cap (with actual dimensions), Imagick/GD encoder threw (with exception message), source missing/unreadable, etc. No more opaque "ID 12345 skipped." — the warning line includes the exact cause so admins can decide whether to raise the cap, adopt legacy variants, or fix the file.

= 1.2.6 =
* **Adopt legacy variants now handles two cases the previous resolver got wrong.** (1) Basename collisions — when both `logo.jpg` (attachment A) and `logo.png` (attachment B) exist, the foreign `logo.webp` was previously claimed under whichever the resolver hit first, leaving the other attachment permanently blocked by the foreign-variant guard at convert time. The backfill now claims the variant under every attachment whose source candidate exists on disk. (2) Size variants — WordPress only stores the original filename in `_wp_attached_file`, so URL→ID lookup for `logo-300x100.png` returned 0 and the size-variant webp was never adopted. The resolver now strips the `-WxH` suffix, verifies the candidate parent has that size registered in `wp_attachment_metadata.sizes[]`, and claims accordingly.

= 1.2.5 =
* **Favicon rewriting is now an opt-in toggle.** Default is off (the recommended posture introduced in 1.2.4). Settings → "Favicon rewriting" surfaces the trade-off and lets sites that have verified every consumer (no iOS home-screen install path, no legacy desktop browser support) opt back into rewriting favicon and touch-icon `<link>` hrefs to WebP.
* **Live page verifier no longer counts favicon PNGs as failures.** PNG/ICO favicons inside `<link rel="icon">`, `<link rel="apple-touch-icon">`, `<link rel="mask-icon">`, etc. are now reported as a separate "kept by design" bucket instead of inflating the "JPEG/PNG outside &lt;picture&gt;" legacy count. The modern-format percentage now reflects only the images the rewriter is actually responsible for.
* **Single source of truth for the plugin version.** `CF_MEDIA_MANAGER_VERSION` is now derived from the plugin file's `Version:` header at boot. The release script asserts that `readme.txt` Stable tag and `package.json` version match the header; mismatches fail the build instead of shipping a drifted zip.

= 1.2.4 =
* **Favicons are no longer rewritten to WebP.** `<link rel="icon">`, `<link rel="shortcut icon">`, `<link rel="apple-touch-icon">` (and `-precomposed`), `<link rel="mask-icon">`, and `<link rel="fluid-icon">` are now masked from URL substitution. iOS does not honor `.webp` for `apple-touch-icon`, and the multi-format `.ico` + PNG (32×32, 192×192) + Apple touch icon declaration is still the recommended pattern — WebP belongs alongside those, not in place of them.

= 1.2.3 =
* **Plugin Check cleanup.** Cleared all remaining Plugin Check findings: missing `translators:` comments on placeholder strings, Domain Path resolution (`languages/` now ships in the zip), `unlink()` → `wp_delete_file()`, `parse_url()` → `wp_parse_url()`, `suppress_filters` removed from the counts query, `load_plugin_textdomain` removed (auto-loaded by WP 4.6+), `error_log()` gated behind `WP_DEBUG`, narrowly-scoped `phpcs:ignore` annotations on intentional direct-DB and third-party hook integrations. No user-visible behavior change.
* **Tested up to WordPress 6.9.**

= 1.2.2 =
* **Manifest lookup is now indexed.** Pre-1.2.2 the front-end rewriter called an unindexed `LIKE` against a serialized-array `meta_value` on every candidate WebP/AVIF URL — a real performance regression on uncached / personalized / logged-in renders. Ownership is now stored as one row per variant under a hashed `meta_key` (`_cf_media_manager_owns_<md5>`); reverse lookups are exact-key indexed seeks. An object-cache layer in front coalesces repeats so the typical hot request makes zero DB queries after warm-up. Pre-1.2.2 manifests are still readable, but admins should re-run **Adopt legacy variants** once after upgrade to migrate to the new format.
* **Status agrees with the rewriter.** `is_attachment_converted()` is now manifest-aware too. A foreign same-basename `.webp` no longer flips the Media Library "converted" column to true while the rewriter silently refuses to serve it.
* **Queue cancel is durable.** Clicking Cancel now mints a new `run_id` on the cancelled state. A worker mid-chunk that started before the click can no longer save its post-chunk state over the cancellation.
* **Backfill is exposed in the UI.** A new **Adopt legacy variants…** button (visible until the migration completes) walks the uploads tree, reports the planned adoption count, asks for confirmation, then commits. Also available as `wp cf-media-manager backfill [--dry-run]`. Adoption skips files registered as Media Library attachments; manually placed third-party files cannot be distinguished from legacy plugin variants and will be adopted, which the dry-run prompt makes explicit.
* **Null-byte rejection at the filesystem boundary.** `Paths::within_upload_dir()` now rejects null bytes and literal `..` segments before calling `realpath()`, so poisoned attachment metadata can't crash a PHP 8 endpoint with `ValueError`.
* **Tighter pre-stat containment** for the derived variant path in `Converter::convert_and_measure()`.

= 1.2.1 =
* **Rewriter is now variant-ownership-aware.** The front-end output buffer no longer serves a `.webp` / `.avif` sitting next to a JPG/PNG unless the variant manifest confirms the plugin generated it — closes the content-substitution gap where a user-uploaded `logo.webp` could be wrapped into a `<picture>` source for an unrelated `logo.jpg`.
* **Backfill adopt-guard.** The one-time legacy-variant backfill refuses to claim files that are themselves registered Media Library attachments, so adopting a pre-1.2 install can no longer mark a legitimate user-uploaded WebP as "ours" and expose it to Delete All.
* **Queue `run_id` guard.** Every queue start mints a unique run id; workers refuse to write back stale state if the option has been replaced by a fresh run mid-chunk. `release_lock()` no longer deletes locks held by a peer worker.
* **Pre-stat containment tightened.** Every attachment-derived path passes `within_upload_dir()` before `is_readable`, `file_exists`, `filemtime`, or `filesize` — applied in `Converter::convert`, `Converter::is_attachment_converted`, and `Disk::estimate_required_space`.
* **Queue-id cap applied earlier.** `MAX_QUEUE_IDS` (50,000) is now enforced before disk-space estimation, so a 300K-attachment library no longer walks 300K filesizes just to be truncated downstream.
* **Upload-root scanning.** The variant scanner walks files at the upload root in a non-recursive first pass — for installs that disabled WordPress's year/month folder organization, or libraries imported flat.
* **Readme.** Stable tag, MP cap docs (50 → 25), and feature list updated to reflect 1.2.x.

= 1.2.0 =
* **Variant ownership manifest.** Generated `.webp`/`.avif` files are now tracked per-attachment in postmeta (`_cf_media_manager_variants`). The converter refuses to overwrite variants the plugin didn't write; Delete All only unlinks plugin-owned files and reports untracked ones separately. The front-end rewriter only swaps in WebP/AVIF that the manifest claims, so a user-uploaded `logo.webp` sitting next to `logo.jpg` is never substituted into a `<picture>` source for that JPG. Legacy installs adopt their existing variants via a one-time admin backfill action.
* **SSRF hardening on the live verifier.** Switched to `wp_safe_remote_get` with `reject_unsafe_urls`; added explicit private / loopback / CGNAT IP rejection on every redirect hop.
* **Admin JS XSS hygiene.** The conversion log sink now uses `document.createTextNode` instead of string concatenation; the queue-status CSS class is allow-listed instead of trusting the server-supplied status verbatim.
* **Queue race fix.** Chunk processing now acquires an atomic option-row lock with TTL-based stale recovery, and every run is stamped with a `run_id` so an orphan worker can't write stale counters over a freshly started run.
* **Resource controls.** 30 MB source filesize cap; the image-bomb pixel cap dropped from 50 MP to 25 MP; `wp_raise_memory_limit('image')` before decode; per-handle Imagick `setResourceLimit` (memory, map, area, time, thread).
* **Pagination.** `get_conversion_counts` pages 1K rows at a time instead of loading every attachment ID into memory; queue start caps selected IDs at 50,000 before disk estimation runs.
* **Pre-stat containment.** Every attachment-derived path passes `within_upload_dir()` before any `file_exists`, `filemtime`, `is_readable`, or `filesize`.
* **Multisite cache purge gating.** The cross-site purge requires `manage_network_options` on multisite (`manage_options` is per-site and the wrong gate).
* **URL → path correctness.** `Paths::url_to_path` strips query strings and fragments and percent-decodes before filesystem mapping; rejects null bytes and percent-encoded `..` sequences.
* **Output buffer scoping.** Rewriter skips feeds, REST, AJAX, XML-RPC, JSON, JSONP, sitemap, robots, trackback, and WP-CLI contexts.
* **Upload-root scanning.** The variant scanner now scans regular files at the upload root in addition to top-level subdirectories, for installs that disable year/month folder organization or imported libraries that flattened the tree.

= 1.1.0 =
* **In-use scanner now supports Bricks and WPBakery.** Bricks page / header / footer postmeta (`_bricks_page_content_2` and variants) and `bricks_template` CPT are scanned; WPBakery `[vc_single_image image="N"]` and `[vc_gallery images="1,2,3"]` shortcode attributes plus `_vc_post_settings` are extracted when WPBakery is detected. Divi, Elementor, and Beaver Builder support is unchanged.
* **New `wp cf-media-manager doctor` command** — finds attachments whose `post_mime_type` row disagrees with their stored file extension (the cause of the bulk-conversion counter undercounting on sites that imported media via migrators or CDN tools). `--fix` repairs the rows in place.
* **Status header now narrates the active scope correctly.** Switching to "In-use only" no longer produces "282 of 282 converted. 4 to convert." contradictions — the prefix and trailing clause both describe the in-use subset.
* **Plugins-screen Settings link.** The plugin row on `Plugins → Installed Plugins` now has a quick "Settings" action link.
* **HTML Rewriting master switch is now a big, accessible toggle** instead of a stock checkbox.
* **In-use / Background-queue explainer is permanently dismissable** (per-user). Click the × in the corner once and it stays gone.
* **Security hardening.** `Paths::url_to_path()` now realpath-validates every returned path — not only when the URL contains `..` — closing a defense-in-depth gap on symlink-based escapes inside the uploads tree.
* **Docs.** Added an FAQ note recommending an ImageMagick `policy.xml` that disables MVG, MSL, LABEL, URL, HTTPS/HTTP/FTP, EPHEMERAL, PS/PS2/PS3, EPS, PDF, and XPS coders as defense in depth.

= 1.0.1 =
* Bulk Conversion now has an **In-use only** scope that targets only images referenced on the front end — a major speed-up on sites where the media library has accumulated stale uploads.
* In-use detection scans published `post_content`, featured images, custom logo, site icon, and (when active) Divi Library / Divi Theme Builder layouts, Elementor templates and `_elementor_data`, Beaver Builder layouts and `_fl_builder_data`, Bricks templates plus `_bricks_page_content_2` / header / footer postmeta, and WPBakery shortcode attributes (`image="N"`, `images="1,2,3"`) plus `_vc_post_settings`. URLs are resolved back to attachment IDs via a single indexed query.
* Result is cached for 12 hours and invalidated automatically on post save, attachment add/delete, theme switch, and Customizer save.
* New **Rescan** button in the UI for an explicit refresh.
* Plain-English explainer added under the Bulk Conversion section describing what *In-use only* means and how the background queue actually works (Action Scheduler vs WP-Cron, tab-close safety).

= 1.0.0 =
* Initial public release.
* WebP and (when Imagick has libavif) AVIF generation for every JPEG/PNG in the media library.
* `<picture>` wrapping with srcset/sizes preservation and native browser fallback.
* Background queue (Action Scheduler when available, WP-Cron otherwise) — close-the-tab safe.
* Bulk conversion with live progress, force-reconvert, and per-image picker.
* Page filter (blacklist/whitelist) with URL-path wildcards.
* OG and Twitter Card image rewriting via filter hooks for Yoast, RankMath, and SEOPress.
* WebP status column in the Media Library.
* Live page verifier reporting WebP/AVIF coverage percentage.
* WP-CLI: `wp cf-media-manager status`, `wp cf-media-manager convert`, `wp cf-media-manager queue start|status|cancel`.
* One-click cache purge for Divi, WP Engine, WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, SG Optimizer, Autoptimize, Cache Enabler, Hummingbird, Borlabs Cache, and the Cloudflare official plugin.
* Hardened: SSRF defense on the verifier, realpath containment on every filesystem write, 50-megapixel image-bomb cap, server-side hard caps on batch size and pattern textarea, multisite-aware uninstall.
* Full i18n with `.pot` shipped; translations welcome via translate.wordpress.org.

== Upgrade Notice ==

= 2.2.1 =
Display-only fix: removes doubled periods in the per-attachment convert "reasons" message. No behavior or settings changes.

= 2.2.0 =
Adds a render-time alt-text fallback (on by default) that fills empty/missing image alt from the attachment field — fixing page-builder images (Divi, etc.) that ignore it. Also adds "Save all" + a full-image preview to the Accessibility tab and a one-click fix for duplicate .webp/.avif attachments that block conversion. Purely additive; disable the fallback under Convert → Settings if needed. No breaking changes.

= 2.1.0 =
Major scope expansion: Media Library list view (40+ columns, CSV export, REST endpoint) and a five-report audit subsystem with the "receipts" pattern. Folds in the standalone cf-media-list-view plugin. No breaking changes.

= 2.0.1 =
Security + reliability release. Multisite capability gates on destructive endpoints, SSRF-hardened live page verifier, atomic per-attachment convert lock, PCRE-failure fallback, ~10× speedup on ACF in-use scanner. No breaking changes.

= 1.2.8 =
Settings page reorganized: actions and tools on the left (2/3 column), configuration on the right (1/3 column). Engine/AVIF status demoted to the page footer. No functional changes.

= 1.2.7 =
Max source filesize is now configurable (default raised to 50 MB, hard ceiling 200 MB). Skipped/failed conversions now report a precise human-readable reason instead of just "skipped."

= 1.2.6 =
Bug fix for **Adopt legacy variants**: it now handles basename collisions (`logo.jpg` and `logo.png` as separate attachments sharing `logo.webp`) and size variants (webps generated for `-300x100`, `-150x150`, etc.). Run Adopt again after upgrading to claim any variants that were previously skipped.

= 1.2.5 =
Favicon rewriting is now an opt-in setting (default off). The live page verifier no longer counts favicon PNGs as legacy failures — they now appear in a separate "kept by design" bucket.

= 1.2.4 =
Favicon link tags (`rel="icon"`, `apple-touch-icon`, `mask-icon`, etc.) are no longer rewritten to `.webp`. Keep your `.ico` + PNG + Apple touch icon declarations; WebP belongs alongside them, not in place of them.

= 1.2.3 =
Plugin Check cleanup — clears all remaining lint findings and ships the `languages/` folder. No user-visible behavior change.

= 1.2.2 =
Performance + reliability follow-up. Manifest lookup is now indexed and object-cached. Dashboard status agrees with the rewriter. Queue cancel is durable. Backfill exposed as a UI button and `wp cf-media-manager backfill`. Run backfill once after upgrade.

= 1.2.1 =
Closes a content-substitution gap from 1.2.0: the rewriter no longer serves a `.webp`/`.avif` the plugin didn't generate. Adds backfill adopt-guard, queue run_id protection, and tighter pre-stat containment.

= 1.2.0 =
Security and reliability release. Adds variant ownership tracking so the plugin never overwrites or deletes a `.webp`/`.avif` it didn't generate. SSRF, queue-race, output-buffer, and resource-limit hardening throughout.

= 1.1.0 =
Adds Bricks and WPBakery to the In-use scanner. New `wp cf-media-manager doctor` CLI command for mime/extension repair. Settings shortcut on the Plugins screen, dismissable explainer card, HTML-rewriting toggle, URL→path resolution hardening.

= 1.0.1 =
Adds an "In-use only" bulk-conversion scope that detects images referenced in posts, featured images, and Divi / Elementor / Beaver Builder / Bricks / WPBakery layouts — skipping stale media-library uploads that aren't linked anywhere.

= 1.0.0 =
Initial public release.
