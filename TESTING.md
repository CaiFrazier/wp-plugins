# CF WordPress Plugin Testing

This file defines the local verification pattern for the plugin suite. It is intentionally lightweight: the PHPUnit suites use WordPress function shims where possible, and live WordPress smoke tests cover behavior that depends on core, admin screens, rewrite rules, media handling, and real plugin integrations.

## Required Local Tools

- `php` for syntax checks and PHPUnit.
- `composer` for PHP dependencies, PHPUnit, and PHPCS.
- `node` and `npm` for plugins that compile assets from `src/`.
- `zip` and `unzip` for release packaging.
- `wp` (WP-CLI) is optional but recommended. It is used for activation/deactivation smoke tests and, in the release builder, to regenerate `languages/<domain>.pot` from current source. Without it the release ships the committed `.pot` unchanged and warns; it fails only if a plugin declares a translation domain but has no committed `.pot` at all.

## Install Dependencies

Install only what the plugin needs:

```bash
cd <plugin-directory>
composer install
npm install
```

Plugins without `package.json` do not need npm. Plugins without `composer.json` do not need Composer.

## Repo-Wide PHP Syntax Check

From the repo root:

```bash
find . \
  -path '*/vendor/*' -prune -o \
  -path '*/node_modules/*' -prune -o \
  -path '*/dist/*' -prune -o \
  -name '*.php' -print0 | xargs -0 -n1 php -l
```

The release helper also runs `php -l` on the packaged plugin's PHP files before building a zip.

## Per-Plugin Test Commands

Run these from each plugin directory after installing dependencies.

| Directory | PHP tests | JS tests/build |
|---|---|---|
| `cf-bulk-meta-editor/` | `composer test` | `npm run build` |
| `cf-chunked-upload/` | `composer test` | `npm run test:js`; `npm run build` |
| `cf-content-calendar/` | `composer test` | `npm run build` |
| `cf-media-list-view/` | `composer test` | n/a |
| `cf-post-list-view/` | Not present yet | n/a |
| `qr-redirect/` | `composer test` | n/a |
| `schema-override-manager/` | Not present yet | `npm run build` |
| `shared/` | `composer test` | n/a |
| `cf-media-manager/` | `composer test` | n/a |

The release helper runs Composer tests only when `vendor/bin/phpunit` already exists. It does not install dev dependencies during release packaging.

## PHPCS

Shared rules live at `phpcs.shared.xml`. Plugins with Composer tooling should expose:

```bash
composer lint
```

Current goal: every plugin with Composer tooling should have a local `phpcs.xml.dist` or a documented reason it does not.

## Release Zip Dry Run

From the repo root:

```bash
node scripts/release.mjs cf-media-manager
node scripts/release.mjs qr-redirect
node scripts/release.mjs cf-bulk-meta-editor
node scripts/release.mjs cf-media-list-view
node scripts/release.mjs cf-content-calendar
node scripts/release.mjs cf-post-list-view
node scripts/release.mjs schema-override-manager
node scripts/release.mjs cf-chunked-upload
```

The helper runs `php -l`, the available test suite, the npm build and runtime Composer install where applicable, then regenerates the plugin's `.pot` from current source (WP-CLI), stages only runtime files, zips, and rejects the build if the zip listing contains development artifacts (`src/`, `tests/`, `node_modules/`, non-runtime `vendor/`, any dotfile/dotdir, lockfiles, `*.xml.dist`, `webpack.config.js`, stray `*.zip`).

It fails when runtime files are missing, the version header is missing, PHP syntax fails, required local tools are unavailable, the `.pot` cannot be produced (no WP-CLI and no committed template), or the generated zip contains development artifacts.

## Fresh WordPress Smoke Test

Use a disposable WordPress install. Do not test first activation on a production site.

1. Build the release zip.
2. Install and activate the zip.
3. Confirm no fatal error, PHP warning, missing asset warning, or broken admin screen.
4. Exercise the plugin's main workflow with realistic sample data.
5. Deactivate and reactivate.
6. Uninstall if supported, then verify expected options, CPT data, files, logs, caps, cron events, and temp directories are removed or intentionally preserved.

## Multisite Smoke Test

Minimum multisite checks:

1. Network is enabled and at least two sites exist.
2. Plugin activates on one site without affecting another site.
3. Network activation either works cleanly or is explicitly blocked with a clear admin message.
4. Options, uploaded files, logs, caps, and scheduled events stay site-scoped unless intentionally network-scoped.
5. Uninstall does not delete data from unrelated sites.

### CF Media Manager — multisite-specific

The 2.0.1 audit added cross-site capability gates. To smoke-test:

1. As a non-network-admin on subsite B, attempt `convert_batch`, `queue_start`, `save_settings`, `save_quality`, `count_variants`, `delete_all`, `backfill_manifest`, `claim_variant`, and `purge_caches`. All nine should reject with a 403 and a "requires network-admin capability" message.
2. As a network admin, the same actions should succeed on whichever subsite is current.
3. Trigger `Adopt legacy variants` on one subsite; confirm the resulting manifest rows land only against that subsite's `wp_postmeta` table.
4. Uninstall the plugin network-wide and verify each subsite's options are removed AND that `delete_site_option` cleared the network-level rows (covered by `UninstallTest` against the bootstrap shims; verify the real-WP behavior matches).

## Plugin-Specific Smoke Notes

- Media Manager: upload JPEG and PNG files, verify generated variants, AVIF fallback behavior, frontend rewrite output, queue state transitions, delete-all behavior, and cache purge notices. As of 2.0.1: also smoke the Alt Text Manager (Accessibility tab) for both list filters and inline save; the Live Page Verifier on a real same-host URL (the SSRF-hardened `UrlVerifier`); a multisite-network admin clicking `Adopt legacy variants` from a subsite (should be blocked unless network-admin); and a CTRL-C of `wp cf-media-manager convert --quality=78` mid-run (quality must restore via `try/finally`, L7).
- QR Redirect: create a code, test `/r/{slug}`, inactive `410`, UTM injection, intermediate mode, PNG export, ZIP export, and custom capabilities.
- Bulk Meta Editor: verify Yoast, Rank Math, and allow-listed custom meta editing; confirm disallowed meta is rejected and CSV export escapes formulas.
- Media List View: verify EXIF columns, search, MIME filter, unattached filter, pagination, CSV export, and a user with `upload_files` but not `manage_options`.
- Content Calendar: verify month/week/list views, drag rescheduling, scheduled-post time preservation, author scoping, and REST nonce behavior.
- Post List View: verify posts, pages, CPTs, taxonomy columns, SEO-plugin fields, hierarchy columns, filters, pagination, and CSV export.
- Schema Override Manager: test current Yoast, Rank Math, schema-heavy themes, WooCommerce, events plugins, suppression rules, and live fetch rate limiting before public release.
- Chunked Upload: test media upload below/above threshold, importer destination, cancel cleanup, hash mismatch, MIME mismatch, interrupted upload behavior, and stale-session cleanup.
