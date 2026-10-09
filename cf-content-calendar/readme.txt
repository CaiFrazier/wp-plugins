=== CF Content Calendar ===
Contributors: caifrazier
Tags: calendar, editorial calendar, content, scheduling, posts
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modern editorial calendar. Drag to reschedule, create drafts from empty day slots, and see your full content picture across post types.

== Description ==

CF Content Calendar adds a full editorial calendar to your WordPress admin under Posts → Calendar. Schedule and manage content without leaving the calendar view.

**Views**

* Month — standard calendar grid with chips for scheduled and published content.
* Week — 7-column day view for sites that publish multiple times per day.
* List — chronological list of upcoming and recent posts grouped by day.

**Scheduling from the calendar**

Click any empty day slot to create a new draft or scheduled post inline — no new tab, no modal. Drag any chip to a different day to reschedule it. The calendar updates immediately; a notice appears if the save fails.

**Filters**

Toggle visibility by post type, status (published, scheduled, draft), and author.

**No custom database tables.** All data comes from WordPress's standard posts table.

= Source code and development =

Source: https://github.com/CaiFrazier/wp-plugins/tree/prep/wordpress-org-20261009/cf-content-calendar

To rebuild, clone the repository, install the plugin's development dependencies with Composer and pnpm where a package.json is present, and run `node scripts/release.mjs cf-content-calendar` from the repository root. The builder lints PHP, runs installed tests, builds JavaScript, installs production PHP dependencies, and creates the distributable ZIP. JavaScript source lives in `src/` for built plugins; plain scripts are in `assets/`. Bundled dependencies and their license terms are listed in THIRD-PARTY-NOTICES.txt.

= Data and external services =

The calendar reads and edits posts in the existing WordPress database. Requests use the local WordPress REST API. No external service or telemetry is used.

= Support =

Report reproducible bugs at https://github.com/CaiFrazier/wp-plugins/issues. Include the plugin version, WordPress/PHP versions, and steps to reproduce. Remove credentials and private content from reports.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/cf-content-calendar/`.
2. Activate the plugin through the Plugins screen.
3. Navigate to Posts → Calendar.

For development: run `composer install` and `npm install && npm run build` inside the plugin directory before activating.

== Changelog ==

= 0.1.3 =
* Update bundled libraries to current stable versions.
* Prepare WordPress.org metadata, source/build documentation, license notices, and privacy disclosures.

= 0.1.2 =
* Show a visible warning when a calendar range exceeds 500 posts.
* Use WordPress REST path middleware so loading, creating and rescheduling also work with plain permalinks.

= 0.1.1 =
* Fix: Dragging a scheduled post to a past date (downgrading it to draft) no longer leaves a stale GMT publish date behind. Found during a live smoke test — the old date could have misled sitemap/SEO plugins into treating an unscheduled draft as published.

= 0.1.0 =
* Initial release.
