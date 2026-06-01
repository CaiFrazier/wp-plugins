# Changelog

All notable changes to CF Media Manager are documented here. Older entries
live in `readme.txt` (the WordPress.org changelog format); this file is the
in-repo working changelog and supersedes `readme.txt` for new work.

## 2.1.2 - 2026-05-29

### Fixed

- **Adopt button missing after upgrade from CF Media Optimizer.** `is_fresh_install()`
  only checked for `cf_media_manager_*` option names. Activating the renamed plugin on
  a site that had `cf-media-optimizer` options in the DB was incorrectly detected as a
  greenfield install, causing `run_install()` to prematurely set `BACKFILL_DONE = 1`
  and hide the Adopt button before the admin could use it. (`Plugin.php`)

  **Forward fix:** `is_fresh_install()` now also checks the four equivalent
  `cf_media_optimizer_*` sentinel options, so future activations on optimizer-upgrade
  sites are correctly identified as non-fresh.

  **Retroactive fix:** `Plugin::run_optimizer_migration()` runs once on boot. If it
  detects `cf_media_optimizer_*` options (old plugin was installed), it clears any
  `BACKFILL_DONE` that was set by the false detection, so the Adopt button re-appears
  automatically on already-affected sites without any admin action.

  Migration marker stored as `Options::MIGRATION_OPTIMIZER_V1` (autoloaded) — zero
  extra DB cost per request after first run. 4 new tests in `PluginTest`.

## 2.1.1 - 2026-05-28

Security hardening.

### Fixed

- **CSV formula injection (CWE-1236).** Both CSV export surfaces — the Media
  Library list view (`LibraryCsvExporter::rows()`) and the Audit detail views
  (`AuditAjax`) — now neutralise cells whose first character is a formula
  trigger (`=`, `+`, `-`, `@`, tab, CR) by prefixing a single quote. Attacker-
  influenceable fields (attachment title, caption, alt text) can no longer
  execute as a formula when the export is opened in Excel / Sheets / Numbers.
  Escaping is centralised in the shared `CFShared\Csv\Escaper` helper (now a
  Composer `path` dependency, matching Bulk Meta Editor), and the library path
  is covered by a regression test in `LibraryCsvExportTest`.

## 2.1.0 - 2026-05-27

Scope expansion release: Media Manager now ships a full Media Library
inspection surface (List View) and an audit subsystem (Audit tab) on top
of the existing WebP/AVIF conversion engine.

### Added — Media Library list view (Media → List View)

- Configurable, SQL-backed list of every attachment, with 40+ columns
  across seven categories: Identity, File Metadata, Content & SEO,
  Attachment Context, Timestamps, EXIF/Camera, WordPress Internals.
- Column selector modal with grouped, describable columns and
  per-browser `localStorage` persistence (`cfMM_library_columns_v1`).
- Search, MIME filter, unattached-only filter, sortable headers,
  pagination (25/50/100/200 per page).
- CSV export of the current view (current filters + selected columns).
- New REST endpoint `GET /wp-json/cf-media-manager/v1/library`
  (`upload_files` capability — editors can use this surface even though
  the Manager settings page requires `manage_options`).
- Folds in the standalone `cf-media-list-view` plugin, retired in this
  release. The list view becomes a `Media → List View` submenu owned by
  Media Manager.

### Added — Audit subsystem (Media Manager → Audit tab)

A new tab on the Manager admin page that hosts a dashboard of audit
reports. Every flagged item carries provenance ("receipts") — the list
of sources the report checked and what it found — so users can read
*why* before they delete. Competitive opening identified from field
research on WordPress.org support forums and WP Tavern: mainstream
cleanup plugins ship false positives that lead to "deleted my logo"
horror stories. The receipts pattern is the differentiator.

Reports shipping in 2.1.0:

- **Ghost Attachments** — DB attachment rows whose underlying file is
  missing from disk. Receipts include `expected_path`, the raw
  `_wp_attached_file` postmeta, the `attached_to_post_id`, and reverse
  lookups for `_thumbnail_id` references so users see which posts still
  use the broken attachment.
- **Orphan Files** — files in `wp-content/uploads/` that no
  `_wp_attached_file` postmeta value points at. Heuristics exclude
  thumbnail variants (`name-300x200.ext`), scaled originals
  (`name-scaled.ext`), and modern siblings (`.webp` / `.avif` next to
  attached `.jpg`/`.png`/`.gif`). Bulk delete is permanent (raw files
  aren't post-scoped) and goes through `within_upload_dir()` on every
  path.
- **Unused Attachments** — the marquee report. Driven by the existing
  `InUseScanner`, which covers `post_content`, featured images, site
  logo, site icon, Divi, Elementor, Beaver Builder, Bricks, WPBakery,
  reusable blocks, block templates, widgets, ACF, and WooCommerce. The
  in-use set is snapshotted on first chunk so mid-scan post edits can't
  drift the attribution. Receipts list every source checked and which
  builders are detected on the install.
- **Duplicate Originals** — SHA-256 hash worker. Two-phase chunked scan
  (gather hashes, then emit groups). Receipts identify a "primary" copy
  to keep (in-use first, oldest tie-break) and list every other copy as
  trash candidates. Files larger than 100 MB are skipped from hashing.
- **Oversized Originals** — configurable file-size and longest-side
  pixel thresholds (`min_bytes` default 2 MB, `min_pixels_longest_side`
  default 2560 matching WP's auto-scale cutoff). Receipts include
  `triggered_by` showing which threshold tripped and `has_scaled_variant`
  surfacing WP's `metadata.original_image` retain-original case.

Dashboard:

- Card grid showing each report's count, savings estimate, last scan
  timestamp, and staleness pill (yellow border when the library has
  changed since the last scan).
- "Missing alt text" link card surfaces the count of images missing
  alt text and deep-links into the existing Accessibility tab — no
  duplicate UI for an editor that already exists.
- Per-card rescan + dashboard-wide "Rescan all" button.
- Full-pane detail view per report with URL hash routing
  (`#audit/<report_id>?page=2`), receipt expansion, paginated bulk
  actions (trash / delete / ignore / un-ignore), and a streaming
  CSV export.

Infrastructure:

- `AuditRunner` orchestrator: state machine (idle/scanning/complete/
  failed), two-transient persistence (mutable state vs final results),
  atomic concurrency lock per report, hard cap of 50,000 items per
  scan, staleness tracking via a single autoloaded option bumped on
  `add_attachment` / `attachment_updated` / `delete_attachment`.
- `IgnoredStore` hybrid persistence: postmeta for attachment-keyed
  reports, single option blob for path-keyed reports (orphan files
  have no attachment ID to hang postmeta on). Both stores swept on
  uninstall.
- New AJAX surface: `audit_dashboard`, `audit_scan_chunk`,
  `audit_scan_cancel`, `audit_detail`, `audit_bulk`, `audit_export_csv`.
  All gated through `Security::authorize_ajax()`; destructive bulk
  actions through `authorize_ajax_network()` on multisite.
- Optional `AuditReportCsvExportable` interface — every shipping
  report opts in.

### Changed

- Plugin description (visible on the Plugins admin screen) now reflects
  the new feature surface: WebP/AVIF conversion, alt-text auditor and
  editor, and the configurable Media Library list view with CSV export.
- Removed "CF" branding from in-admin UI elements (footer credit,
  notice labels). The "CF" prefix remains in the plugin listing
  (header file, readme) where branding is appropriate.
- Renamed the Manager admin page's placeholder "Library" tab to
  "Audit" since that's what its content now actually does.

### Test surface

- Total test count: 540 tests, 1,477 assertions, all green.
- New: `IgnoredStoreTest`, `AuditRunnerTest`, `GhostAttachmentsTest`,
  `OrphanFilesTest`, `UnusedAttachmentsTest`,
  `DuplicateOriginalsTest`, `OversizedOriginalsTest`,
  `AuditAjaxTest`, plus the ported `LibraryRestControllerTest` /
  `LibraryAttachmentDataTest` / `LibraryColumnRegistryTest` /
  `LibraryCsvExportTest`.

## 2.0.1 - 2026-05-26

_In progress — security hardening pass._

### Security
- **Multisite capability gate on destructive endpoints.** Added
  `Security::authorize_ajax_network()` and routed the eight endpoints with
  cross-site blast radius through it: `convert_batch`, `queue_start`,
  `save_settings`, `count_variants`, `delete_all`, `backfill_manifest`,
  `claim_variant`, and `purge_caches`. On multisite they now require
  `manage_network_options` (previously `manage_options`, a per-site
  capability that let a site-admin on subsite B trigger actions whose
  effects rippled into subsite A). Single-site behavior is unchanged.
- **SSRF hardening on the live page verifier.** Extracted the entire
  verifier fetch into a new `UrlVerifier` class with a layered defense:
  scheme allowlist (http/https only), strict same-host gate against
  `home_url()`, path policy that rejects `/wp-admin`, `/wp-login.php`,
  `/xmlrpc.php`, `/wp-json`, `?rest_route=`, and any URL carrying
  `_wpnonce`; full A + AAAA DNS resolution with every address validated
  against an IP allowlist (rejects RFC1918, loopback, link-local
  including AWS/GCP/Azure metadata IP 169.254.169.254, CGNAT, multicast,
  IPv6 ULA fc00::/7, and IPv4-mapped wrappings of any of the above); and
  CURLOPT_RESOLVE-based IP pinning that closes the DNS rebinding TOCTOU
  window between validation and TCP connect. Same-host and DNS checks
  re-run at every redirect hop.

### Changed
- `Ajax::verify_url` is now a thin AJAX adapter over `UrlVerifier::fetch`;
  the 250+ line SSRF defense is in one auditable class.

### Concurrency & correctness (Phase 2)
- **H5: Queue lock-steal verify-after-write.** `Queue::acquire_lock`'s
  steal path now does `update_option` → `wp_cache_delete(QUEUE_LOCK, 'options')`
  → re-read → token-equality check. Two workers racing to steal the same
  expired lock now resolve to a single winner; the loser does not set
  `$lock_token` (so the `finally` release can't delete the winner's lock).
- **H7: Per-attachment convert lock.** `Converter::convert_attachment`
  acquires a two-tier lock (`wp_cache_add` atomic on persistent object
  caches, transient cross-process fallback) before any work. Reentrant
  calls return a zero-state tuple with an "already in progress" reason
  instead of double-converting. Try/finally release; never deletes a
  lock the call did not take.
- **H3: Backfill overlap lock + DB-level dedupe.**
  - `Ajax::backfill_manifest` takes a `cfmm_backfill_lock` transient
    (600s TTL refreshed per chunk) at the start of every commit. A fresh-
    start click (`cursor=''`) while a run is in flight returns HTTP 409.
    Continuation chunks (`cursor != ''`) and dry-runs pass through.
    Released at the terminal cursor.
  - `VariantManifest::bulk_insert_owns` re-checks the `(post_id, meta_key)`
    pairs against postmeta before inserting and filters duplicates out via
    the new `dedupe_writes()` helper. Defends against parallel writers
    committing the same row between the in-memory snapshot and the INSERT.
- **H6: clearstatcache before delete-confirm.** `Ajax::delete_all` now
  flushes the per-request stat cache for the path between `wp_delete_file`
  and the post-call `file_exists` check, so a successful unlink isn't
  miscounted as an error.
- **H10: `file_exists` guard before `filemtime`.** `Converter::is_attachment_converted`
  verifies both source and webp exist on disk before comparing mtimes,
  so a source deleted out from under the manifest row no longer emits a
  `filemtime` warning OR returns a misleading boolean.
- **H9: PCRE-failure fallback in Rewriter.** Every regex pass over the
  full HTML buffer (`<picture>` mask, `<img>` mask, URL substitution)
  now wraps `preg_replace_callback` in a `preg_last_error()` check via
  the new `safe_preg_replace_callback()` helper. On backtrack-limit /
  recursion-limit / JIT-stack-overflow, the helper logs (under `WP_DEBUG`)
  and the caller falls back to the unmodified HTML instead of silently
  substituting the literal string "null" into the response body.

### Performance (Phase 3)
- **H4: ACF scanner query rewrite.** `InUseScanner::scan_acf_postmeta` used
  `meta_value REGEXP '^[1-9][0-9]*$'` + `CAST(meta_value AS UNSIGNED) IN
  (...)` to find ACF image-field references, which forced a full-table
  scan of `wp_postmeta` and ran a per-row regex. Rewritten to
  `meta_key NOT LIKE %s AND meta_value IN (%d, %d, ...)` via
  `$wpdb->prepare`; MySQL's implicit string-to-int conversion on the IN
  comparison handles the numeric filtering that the REGEXP was doing.
  Drops both `CAST(meta_value AS UNSIGNED)` calls and the regex.
- **M13 / M14: Pre-warm postmeta cache before N+1 loops.**
  `Disk::estimate_required_space` and `Ajax::get_conversion_counts` both
  call `get_attached_file` / `wp_get_attachment_metadata` / per-id
  `get_post_meta` per attachment. Each call was a separate SELECT on a
  cold cache (50K attachments → 100K+ queries). Now prefixed with
  `update_meta_cache( 'post', $batch_ids )` per chunk so each chunk
  collapses to one indexed `WHERE post_id IN (...)` SELECT.
- **M8: Skip legacy `LIKE` scan once backfill is done.**
  `VariantManifest::is_owned` and `forget_anywhere` had a pre-1.2.2
  fallback that ran `meta_value LIKE '%"&lt;rel&gt;"%'` against the
  serialized-array legacy meta on every public render miss — full-table
  scan on an unindexed LONGTEXT comparison, on the front-end. Both now
  gate on `! get_option( BACKFILL_DONE )`; once an admin has run the
  legacy-variant backfill, the LIKE scan never runs again.

  Known limitation: `BACKFILL_DONE` is only set when an admin actually
  completes a backfill run, so fresh installs that never click Adopt
  still pay the legacy-scan cost on render misses. The "always set on
  fresh install" optimization is a follow-up — it needs an upgrade-
  vs-fresh discriminator that doesn't exist yet (a blind `add_option`
  on activation would prevent legitimate 1.x → 2.x upgrades from ever
  seeing their legacy serialized rows).
- **M6: Builder fingerprint in scan cache key.** `InUseScanner`'s
  transient key now appends a 12-char md5 of the active builder set
  (`detect_builders()` output: divi / elementor / beaver / bricks /
  wpbakery / acf / woocommerce). Activating or deactivating a builder
  plugin produces a fresh key so the next scan automatically misses
  the cache (no race between an explicit invalidation and the new
  read). Old-fingerprint transients are bulk-cleared via new
  `activated_plugin` / `deactivated_plugin` hooks so the options
  table doesn't accumulate dead rows.

### Input correctness (Phase 4)
- **H1: CSV-string ID input.** Added `Request::post_id_list( string $key,
  int $max = 50000 ): array` that accepts both `ids[]` (array shape) and
  `ids=12,34,56` (CSV-string shape). The old `Ajax::parse_id_list` did
  `(array) $raw` on a CSV string, wrapping the whole string as a single
  element and `intval`'ing it to keep only the first id — silently
  dropping the rest. All three call sites (`convert_batch`,
  `queue_start mode=selected`, `attachment_status`) now route through
  the new helper with explicit caps (`MAX_BATCH_IDS=100` for the
  per-AJAX-call sites, `MAX_QUEUE_IDS=50000` for queue start). The old
  private `Ajax::parse_id_list` is gone.
- **H8: Purge flag fires on any successful convert.** `Ajax::convert_batch`
  used to only set `PURGE_FLAG` when `bytes_saved > 0`, missing the case
  where a force-reconvert produces same-size output (different quality)
  or where the only "conversion" was adopting an existing fresh file
  into the manifest. Now matches `Queue::process_chunk`'s condition:
  `bytes_saved > 0 || converted > 0`.
- **H2: Containment check in `bulk_insert_owns`.** Belt-and-suspenders
  filter before the postmeta INSERT: any write whose `meta_value` rel
  resolves (via `upload_dir + rel`) to a path outside the uploads tree
  is dropped, with a `WP_DEBUG`-gated log line. Defends against a
  poisoned scanner output or `_wp_attached_file` row that surfaces a
  `../` traversal in the rel string — without this filter we'd commit
  a manifest row the rewriter could later dereference to a path
  outside uploads. Implemented via new `public static
  filter_outside_upload_dir( array $writes, Paths $paths ): array`
  next to `dedupe_writes`.

### Tests
- Phase 1: 25 new SSRF + capability-gate tests.
- Phase 2: 11 new tests across `QueueTest`, `ConverterTest`, and
  `SecurityHardeningTest` covering H3 overlap + dedupe, H5 race
  resolution, and H7 reentrancy.
- Phase 3: no new behavioral tests required — every change is a
  performance refactor over an existing, test-covered code path
  (H4 rewrites a query whose semantics are pinned by the surrounding
  `is_target_attachment` filter; M13/M14 are no-op cache pre-warms;
  M8 short-circuits a fallback the new code path never reaches when
  the gate is set; M6 changes a cache key without changing what's
  cached). Existing suite still green.
- Phase 4: 16 new tests in `RequestTest` (12 for `post_id_list`
  covering CSV split, dedupe, cap, garbage input, object rejection,
  and a PHP-version-dependent `intval([...])` smuggling guard) and
  `VariantManifestTest` (4 for `filter_outside_upload_dir` covering
  traversal rejection, empty-rel rejection, leading-slash handling,
  empty-input no-op).
- Phase 5: 8 new `PathsTest` cases for `to_rel` / `to_rel_or_empty`
  (strip-prefix, outside-uploads → null, root → null, false-positive
  prefix guard, `or_empty` convenience). 6 obsolete `VariantManifestTest`
  cases removed alongside the deleted `backfill_subtree()` method —
  see Centralization & cleanup below.
- Phase 6: 14 new tests across three dedicated files filling the
  highest-value gaps from the audit:
  - **`RewriterPathologicalTest`** (6 cases): 1000-img / ~2.4MB
    Divi-style HTML smoke test, unclosed `<picture>` resilience,
    50-deep wrapper nesting, **H9 PCRE-failure fallback** (via
    forced `pcre.backtrack_limit=1`), and unit-level
    assertions on `safe_preg_replace_callback` (null on failure,
    string on success).
  - **`VariantManifestOverlapTest`** (4 cases): in-process `$wpdb`
    mock drives `backfill_subtree_bulk` end-to-end through
    `bulk_insert_owns → filter_existing_pairs → dedupe_writes →
    wpdb::insert`. Asserts the baseline two-INSERT path, the
    one-overlap-dropped path, the all-overlap-zero-inserts path,
    and the H2 containment filter ordering. **Caught a real
    `$base_len`-undefined bug from the Phase 5.1 refactor** —
    see Fixed below.
  - **`UninstallTest`** (4 cases): exercises the multisite uninstall
    branch via a small refactor of `uninstall.php` (top-level
    dispatch extracted into `cf_media_manager_run_uninstall()` for
    testability). Asserts per-blog `switch_to_blog → cleanup →
    restore` ordering, network-level `delete_site_option` sweep
    runs once per option, and that unrelated options are NOT
    deleted.

**Total: 302 tests, 572 assertions.**

### Remediation pass (Tiers A–D, post-audit)

Eleven items from the deferred-concerns sweep, executed top-down by
risk tier.

**Tier A — real bugs / risk closure**
- **A1: `save_quality` on network gate.** The Phase-1 capability
  migration explicitly listed eight endpoints but omitted
  `save_quality`. Same blast radius as `save_settings`; now on the
  network gate too.
- **A3: H7 atomic option-level convert lock.** Replaced the
  transient check-then-set fallback with `add_option`-atomic
  acquire + verify-after-write steal pattern (mirrors
  `Queue::acquire_lock`). Cross-process reentrancy now genuinely
  closed instead of merely narrowed.
- **A2: H3 post-INSERT convergence DELETE.** The spec called for a
  UNIQUE index on `wp_postmeta(post_id, meta_key)` — pivoted away
  because that would break `add_post_meta`'s multi-value
  semantics for other plugins (postmeta is shared). The new
  approach: after each chunk's INSERTs, run a scoped DELETE-JOIN
  filtered to our `_cf_media_manager_owns_` prefix + the chunk's
  post_id set, keeping the earliest meta_id per pair. Eventually-
  consistent uniqueness without touching anyone else's rows.

**Tier B — test coverage backfill**
- **B1: `AltTextManagerTest`.** Extended the smoke-only file to 19
  tests / 55 assertions. Covers all four filter modes, pagination,
  per_page clamping, validation paths, decorative-flag round-trip,
  and the `missing` server-computed flag. Required adding a
  minimal `WP_Query` shim + `get_post_type` / `wp_get_attachment_image_src`
  family to the bootstrap.
- **B2: `DiskTest` containment + prewarm coverage.** Existing
  `DiskTest` was actually well-covered (11 cases for estimate +
  check) but the `Paths $paths` containment-check parameter was
  uncovered. Added 4 cases: rejects sources outside uploads,
  rejects size variants outside uploads, allows outside paths
  when `$paths` is null (backward compat), confirms Phase-3
  meta-cache prewarm is a no-op behaviorally.
- **B3: M6 builder fingerprint tests.** Six new `InUseScannerTest`
  cases — cache key prefix shape, determinism for same builder
  set, fingerprint change when builder activates (via `did_action`
  shim flipping Elementor on/off), `get()` writes under
  fingerprinted key, `invalidate_cache` deletes only current-fp
  entry, wildcard sweep targets the right LIKE pattern.

**Tier C — performance polish**
- **C1: `BACKFILL_DONE` autoload + fresh-install setter.** New
  `Plugin::run_install()` called from the activation hook
  auto-sets `BACKFILL_DONE` (autoload=true) when no plugin-owned
  options exist — i.e. fresh installs. Greenfield sites now skip
  the legacy LIKE-scan from day one. Upgrade-from-1.x installs
  are detected (any sentinel option present) and the auto-set
  is suppressed so legacy manifest data isn't silently hidden.
  Existing backfill-complete writes also flipped to autoload=true.
- **C2: M6 hook narrowing.** Stored `LAST_BUILDER_FP` option lets
  `invalidate_all_fingerprinted_caches` short-circuit when the
  current builder fingerprint matches the last-stored one — so
  activating/deactivating a non-builder plugin (security plugin,
  SMTP, etc.) no longer triggers the wildcard transient DELETE.

**Tier D — repo hygiene**
- **D1: Removed deprecated `imagedestroy()`** in `Converter.php`
  (no-op since PHP 8.0, deprecated on 8.5+). Cleans up the
  PHPUnit stderr noise that's been there since the suite started.
- **D2: `PRELAUNCH.md` updated to 2.0.1.** Snapshot table, test
  counts, deferred follow-ups section added.
- **D3: 2.0.1 changelog mirrored into `readme.txt`** for
  WordPress.org. Upgrade Notice added.

**Final total: 334 tests, 647 assertions.**

### Fixed during Phase 6
- **`$base_len` undefined in `VariantManifest::backfill_subtree_bulk`.**
  The Phase 5.1 refactor that replaced the local `$base_dir`/`$base_len`
  with `Paths::to_rel()` missed a downstream use inside the source-
  sibling lookup loop (lines 444-448). Now uses
  `$this->paths->to_rel( $candidate_abs )` with a `null` guard.
  Discovered by `VariantManifestOverlapTest::test_baseline_*` — the
  end-to-end test exercised a code path the existing unit tests
  didn't cover.

### Centralization & cleanup (Phase 5)

### Centralization & cleanup (Phase 5)
- **5.1: `Paths::to_rel()` + `to_rel_or_empty()`.** The
  `rtrim+strpos+substr` upload-relative idiom was duplicated across
  `Ajax::diagnose_variant` (3 sites), `Ajax::claim_variant`,
  `VariantManifest::bulk_insert_owns`, and a private
  `VariantManifest::to_rel`. Centralized into two `Paths` methods
  (`to_rel` returns `?string`, `to_rel_or_empty` returns `string`).
  All sites migrated; `VariantManifest::to_rel` is now a one-line
  delegate. PathsTest coverage added.
- **5.2: `cfPost(action, data)` in `admin.js`.** Centralizes the
  `$.post( cfMediaManager.ajaxUrl, { action: 'cf_media_manager_<slug>',
  nonce: cfMediaManager.nonce, ... } )` boilerplate. Replaces 20 call
  sites. Net effect: ~12 lines removed from `admin.js`, but more
  importantly the nonce attachment is now structural — a new endpoint
  can no longer be added that forgets it. The 3 `pollIncremental()`
  callers were updated to pass bare slugs (`'count_variants'`) instead
  of full action names (`'cf_media_manager_count_variants'`).
  `escapeHtml` was *not* extracted to a shared `util.js`: the spec
  assumed it was duplicated between `admin.js` and `notice.js`, but
  `notice.js` only uses `textContent` and has no `escapeHtml`. No
  shared util to extract.
- **5.4 / L3: Removed unused `VariantManifest::backfill_subtree()`.**
  The non-bulk callable-based backfill API had no production callers
  (only `backfill_subtree_bulk` is wired up in the AJAX endpoint).
  The 6 dedicated tests are removed too. Equivalent semantics —
  adopt-guard, multi-source claim, dry-run, non-recursive — live in
  `backfill_subtree_bulk`'s inline implementation, but lack dedicated
  test coverage because the bulk path's INSERT side requires a `$wpdb`
  mock the bootstrap doesn't provide today. **Follow-up:** add a
  `$wpdb` shim so `backfill_subtree_bulk` semantics can be exercised
  end-to-end.
- **5.4 / L5: Fixed stale `Options::QUEUE_LOCK` doc comment.**
  Comment said "transient — short-lived lock"; the actual storage is
  an autoload=false option with an `add_option`-atomic acquire and a
  `{token, expires}` struct. Comment now matches reality.
- **5.4 / L7: `try/finally` around CLI quality override.** `wp
  cf-media-manager convert --quality=78` temporarily writes the
  `quality` option before the run and restores it after. A Ctrl-C,
  fatal error, or thrown exception mid-run would previously leave
  the site's stored quality stuck at the per-run value; the
  restore is now in a `finally` so it fires on every exit path.
- **5.4 / L9: Removed unreachable `elseif ( wp_doing_ajax() )` in
  `Plugin::boot()`.** `is_admin()` returns true for admin-ajax /
  admin-post requests too, so the branch was dead. The intent
  ("AJAX handlers also need registration") is preserved as a
  comment on the surviving `is_admin()` branch.
