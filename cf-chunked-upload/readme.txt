=== CF Chunked Upload ===
Contributors: caifrazier
Tags: uploads, media, large files, chunked upload
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload large files in WordPress by splitting each file into smaller browser-side chunks and reassembling them on the server.

== Description ==

CF Chunked Upload helps when your host rejects large uploads because of PHP request-size limits (such as `upload_max_filesize` and `post_max_size`).

The plugin solves this by:

* splitting files in the browser into smaller chunk requests,
* validating and storing chunks server-side,
* and reassembling the final file in a background task.

It provides two upload surfaces:

* **Media Library integration** for large media files.
* **Tools → Import Large File** for direct filesystem imports.

== Installation ==

1. In WordPress admin, go to **Plugins → Add New → Upload Plugin**.
2. Choose the plugin ZIP file.
3. Click **Install Now**.
4. Click **Activate Plugin**.
5. Open **Media → Chunked Upload** to review settings.

== Frequently Asked Questions ==

= Does this raise my PHP upload limit? =

No. It works around request-size limits by sending smaller requests that fit within your host limits.

= Why is my file still failing even with this plugin? =

Your configured chunk size may still be above a host or proxy ceiling. Lower chunk size and retry. If the host has strict request body limits, both chunk and finalize steps must fit.

= I use Nginx. Do I need to do anything extra? =

Yes. `.htaccess` protections do not apply on Nginx. Add a deny rule for the temporary chunks directory, for example:

`location ~ /wp-content/cf-chunks/ { deny all; return 403; }`

= WP-Cron is disabled on my site. Will this work? =

Finalization runs on the background task runner. If WP-Cron is replaced by a system cron that calls `wp-cron.php`, finalization works. If WP-Cron is disabled and nothing replaces it, large-file finalization will not complete.

= Where are imported files stored, and what happens to them if I deactivate the plugin? =

Imported files are saved to `wp-content/cf-imports/` (or your configured directory). Deactivating or deleting the plugin does not remove that directory or its contents. Delete it manually from your server if desired.

= Does this work with plugin/theme ZIP installation? =

Not yet. Plugin/theme ZIP installation support is deferred.

== Screenshots ==

1. Settings screen with host-limit diagnostics.
2. Import Large File screen with active and completed uploads.

== Changelog ==

= 1.2.0 =
* Security: The per-user storage quota is now enforced against the actual bytes received by the server, not the file size the browser reports. A client that understated or zeroed out the declared size could previously write real chunk data to disk without it counting toward the quota. The quota is now re-checked on every chunk, so an over-limit upload is stopped as it happens rather than only at session start.
* Security: Added a disk-space guard to the chunk endpoint. Chunk uploads are refused when free disk space would fall below a configurable minimum (default 512 MB), and an optional per-session size ceiling can cap a single upload independently of the quota. This closes a denial-of-service path where a large upload could exhaust the disk before the finalize-time check ran.
* Compatibility: Corrected the "Tested up to" WordPress version, which previously named a release that does not exist.
* Two new settings: "Per-session maximum (GB)" and "Minimum free disk (MB)".

= 1.1.4 =
* Fix: Plugin Check no longer reports `NonPrefixedConstantFound` warnings for `CF_CHUNKED_UPLOAD_*` constants. Root cause was a missing `PrefixAllGlobals` prefix declaration in `phpcs.xml.dist` — Plugin Check runs PHPCS with `--ignore-annotations`, so the inline `phpcs:disable` workaround added in 1.1.3 had no effect. The fix declares the canonical prefix allowlist (`cf_chunked_upload`, `CFChunkedUpload`, `CF_CHUNKED_UPLOAD`) in the ruleset and removes the now-redundant suppression comments. No functional changes.

= 1.1.3 =
* Maintenance: Annotated the plugin's prefixed global constants so WordPress.org Plugin Check no longer mis-flags them. No functional changes.

= 1.1.2 =
* Compatibility: Declared "Tested up to" WordPress 7.0 and bundled composer.json alongside the shipped vendor autoloader for WordPress.org Plugin Check compliance.
* Maintenance: Internal version constant synced with the plugin header.

= 1.1.1 =
* Security: The standalone importer now always rejects server-interpreted file types (`.php` and variants, `.phtml`, `.phar`, `.asp`/`.aspx`, `.jsp`, `.cgi`, `.shtml`, `.htaccess`, `.htpasswd`) regardless of the configured extension allowlist — including the default empty allowlist, which previously accepted any extension. Every dotted segment of the filename is checked, so the `evil.php.jpg` double-extension bypass is caught. Enforced both at chunk-receive time and on the final assembled name. The Media Library surface is unaffected (it already validates against WordPress MIME types).

= 1.1.0 =
* Security: Added per-user token-bucket rate limiting on the chunk endpoint (configurable, default 60 chunks/min).
* Security: Added per-user active-session storage quota (configurable, default 50 GB).
* Security: PHP fileinfo extension (`finfo`) is now a hard activation requirement for authoritative MIME verification.
* Security: The `/finalize` endpoint is now idempotent — a retried POST returns the original job id instead of scheduling duplicate assembly.
* Security: Added `.assembling` marker so the cleanup cron cannot reap a session during the WP-Cron queue delay window.
* Two new settings: "Chunks per minute" (rate limit) and "Per-user quota (GB)".

= 1.0.0 =
* Initial WordPress.org launch release.
* Media Library large-file chunking and server-side reassembly.
* Standalone importer for filesystem destination uploads.
* Background finalization, cleanup jobs, and integrity verification.

== Upgrade Notice ==

= 1.2.0 =
Security hardening. Closes a quota bypass via a spoofed file size and adds a disk-space guard against upload-driven disk exhaustion. Upgrade recommended.

= 1.1.3 =
Maintenance release for WordPress.org Plugin Check compliance. No functional changes.

= 1.1.2 =
Maintenance release for WordPress.org Plugin Check compliance and WordPress 7.0 compatibility. No functional changes.

= 1.1.1 =
Security hardening. The importer now blocks executable/server-interpreted file types by default. Upgrade recommended.

= 1.1.0 =
Security hardening release. Adds rate limiting, per-user storage quotas, and tightens activation requirements. Upgrade recommended.

= 1.0.0 =
Initial stable release.
