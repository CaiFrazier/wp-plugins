=== CF Media Optimizer ===
Contributors: caifrazier
Tags: webp, avif, image optimization, picture, performance
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 3.0.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converts JPEG/PNG uploads to WebP and AVIF and serves them through <picture> with native browser fallback. Originals are never modified.

== Description ==

CF Media Optimizer is the delivery half of the former CF Media Manager. It does one job: get modern image formats onto your pages without touching your originals or your server config.

* Converts JPEG and PNG attachments to **WebP** and, where the host supports it, **AVIF**.
* Serves them by wrapping `<img>` tags in `<picture>` elements with the original as the native fallback — no nginx or `.htaccess` rules required.
* Converts on upload automatically, and offers bulk conversion (all images, or only images actually referenced on the front end).
* Background queue via Action Scheduler (WP-Cron fallback) so large libraries convert without tying up a browser tab.
* Per-page rewrite filters, quality control, favicon rewriting, and a render-time alt-text fallback.
* Cache-purge helpers and a live page verifier to confirm the rewrite reached production.

**Originals are never modified.** Generated variants live alongside them and can be deleted at any time from the Convert tab or on uninstall.

= Works with CF Media Manager =

CF Media Optimizer and its sibling **CF Media Manager** (Media Library list view, media audit reports, and bulk alt-text) are independent — each installs and runs on its own. When both are active they cooperate: they share one "in-use" image scan instead of scanning twice, and each links across to the other's screens for convenience. Neither requires the other.

= Source code and development =

Source: https://github.com/CaiFrazier/wp-plugins/tree/prep/wordpress-org-20261009/cf-media-optimizer

To rebuild, clone the repository, install the plugin's development dependencies with Composer and pnpm where a package.json is present, and run `node scripts/release.mjs cf-media-optimizer` from the repository root. The builder lints PHP, runs installed tests, builds JavaScript, installs production PHP dependencies, and creates the distributable ZIP. JavaScript source lives in `src/` for built plugins; plain scripts are in `assets/`. Bundled dependencies and their license terms are listed in THIRD-PARTY-NOTICES.txt.

= Data and external services =

Image conversion runs on your server. Settings, generated-variant ownership, and queues stay in WordPress. The live-page verifier fetches only the public URL explicitly requested by an authorized administrator; that request sends the verifier user agent to the requested host. There is no telemetry or author-operated service. WebP encoding requires GD or Imagick; AVIF is optional and depends on your encoder.

= Support =

Report reproducible bugs at https://github.com/CaiFrazier/wp-plugins/issues. Include the plugin version, WordPress/PHP versions, and steps to reproduce. Remove credentials and private content from reports.

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, or extract it into `wp-content/plugins/`.
2. Activate. A host without a WebP encoder (Imagick with the WEBP coder, or GD with `imagewebp()`) is blocked at activation with an explanatory message.
3. Go to **Media → Media Optimizer** to run a bulk conversion, or just upload images — new uploads convert automatically.

== Frequently Asked Questions ==

= Does this change or delete my original images? =

No. Originals are never modified. Only new `.webp`/`.avif` files are written, and only those files are removed if you delete variants.

= I upgraded from CF Media Manager 2.x — will my converted images keep working? =

Yes. CF Media Optimizer reads the same conversion settings and per-image ownership records that CF Media Manager 2.x wrote, so existing variants keep serving with no re-conversion.

= Do I still need CF Media Manager? =

Only if you want the Media Library list view, audit reports, or the bulk alt-text editor. Optimization is fully self-contained in this plugin.

== Changelog ==

= 3.0.4 =
* Prepare WordPress.org metadata, source/build documentation, license notices, and privacy disclosures.

= 3.0.3 =
* Transparent PNGs no longer get a flattened AVIF. Hosts whose ImageMagick AVIF writer drops alpha (6.9.11 with libheif, as on WP Engine) encoded transparent pixels as black, so icons and logos showed as solid squares. The encoder is now probed for alpha support and every AVIF written from a transparent source is checked; when alpha is missing, the image is served as WebP only and a previously flattened AVIF is removed.
* Existing flattened AVIFs are removed on forced regeneration of the affected PNGs; opaque PNGs and JPEGs keep their AVIF.

= 3.0.2 =
* Version generated image URLs using file modification time and size so rebuilt variants bypass old browser/CDN cache entries. Applies to picture sources, responsive sizes, CSS, and SEO URLs; originals remain unchanged.

= 3.0.1 =
* Convert embedded ICC profiles to sRGB before metadata stripping, preserving colors in WebP and AVIF.
* Safely skip profiled inputs when only GD is available or color-managed conversion fails.
* Existing affected derivatives require forced regeneration; originals are preserved.

= 3.0.0 =
* Split from CF Media Manager 2.3.0: this plugin is now the dedicated delivery half (WebP/AVIF conversion + `<picture>` rewriting). The management toolkit (Library list view, audit, bulk alt-text) ships as the separate CF Media Manager plugin.
* Shared image-usage scanning moved into a common kernel so a co-installed CF Media Manager shares one scan.
* Existing conversion settings and variant ownership are read unchanged — no re-conversion on upgrade.

== Upgrade Notice ==

= 3.0.4 =
WordPress.org submission preparation. Existing settings and original media files are preserved.
