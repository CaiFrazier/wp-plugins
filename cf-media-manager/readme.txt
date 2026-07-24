=== CF Media Manager ===
Contributors: caifrazier
Tags: media library, alt text, accessibility, audit, csv export
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 3.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A Media Library management toolkit: a configurable list view with CSV export, a five-report media audit, and a bulk alt-text editor.

== Description ==

CF Media Manager is the management half of the CF media tooling. It helps you see, audit, and fix what's in your Media Library — it does not convert or deliver images (that's its sibling, **CF Media Optimizer**).

**Media Library list view**
A developer-grade list view of every attachment: 40+ columns across identity, file, content/SEO, context, timestamps, EXIF, and WP-internal groups, with search, MIME and unattached filters, sortable headers, and CSV export.

**Media audit (five reports)**
* **Ghost attachments** — attachment posts whose underlying file is missing.
* **Orphan files** — files on disk with no attachment record.
* **Unused attachments** — images nothing on the front end references.
* **Duplicate originals** — the same image uploaded more than once.
* **Oversized originals** — originals far larger than any size they're displayed at.
Each report supports per-item "ignore" and flags stale results after library changes.

**Bulk alt text**
Audit and edit the attachment-level alt text field in bulk — filter to missing or in-use images, mark images decorative (WCAG-correct empty alt), and save many at once.

= Works with CF Media Optimizer =

CF Media Manager and **CF Media Optimizer** (WebP/AVIF conversion + `<picture>` delivery) are independent — each installs and runs on its own. When both are active they cooperate: they share one "in-use" image scan instead of scanning twice, they share the decorative-image alt flag, and each links across to the other's screens for convenience. Neither requires the other.

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, or extract it into `wp-content/plugins/`.
2. Activate.
3. Go to **Media → Media Manager** for the audit and alt-text tools; the list view is under **Media → List View**.

== Frequently Asked Questions ==

= I used CF Media Manager 2.x for WebP conversion — where did that go? =

In 3.0.0, conversion and `<picture>` delivery moved to the separate **CF Media Optimizer** plugin. Install it alongside this one to keep optimizing images; your existing converted images and settings are read unchanged. This plugin now focuses purely on Media Library management.

= Does this plugin modify or delete my images? =

No. It reports and edits metadata (alt text, audit state). It never converts, rewrites, or deletes image files.

== Changelog ==

= 3.0.0 =
* **Split into two plugins.** CF Media Manager is now the management half — Library list view, five-report media audit, and bulk alt-text. WebP/AVIF conversion and `<picture>` delivery moved to the new **CF Media Optimizer** plugin.
* Shared image-usage scanning and the decorative-alt flag are now a common kernel, so a co-installed CF Media Optimizer shares one scan and one flag.
* Added convenience cross-links between the two plugins' screens.
* No management data migration required; audit and alt-text state carry over unchanged.

Earlier history (WebP/AVIF conversion, `<picture>` rewriting, the background queue) now lives with CF Media Optimizer.
