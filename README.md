# CF WordPress Plugins

A collection of free, focused WordPress utility plugins by Cai Frazier, plus a small shared PHP library. Each plugin is independent, installs from its own zip, and is built to feel WordPress-native — standard admin patterns, no dashboards, no upsells, no marketing in the way.

## Plugins

| Plugin | Folder | What it does |
|---|---|---|
| CF Media Manager | [`cf-media-manager/`](cf-media-manager/) | Converts JPEG/PNG uploads to WebP and AVIF and serves them through `<picture>` with native browser fallback. Originals are never modified. |
| CF Bulk Meta Editor | [`cf-bulk-meta-editor/`](cf-bulk-meta-editor/) | Spreadsheet-style editor for SEO meta titles, descriptions, and custom postmeta across all post types. Presets for Yoast, Rank Math, AIOSEO, and The SEO Framework. |
| CF QR Redirect | [`qr-redirect/`](qr-redirect/) | Self-hosted QR codes and a redirect manager — branded short URLs on your own domain with native GA4 attribution. |
| CF Chunked Upload | [`cf-chunked-upload/`](cf-chunked-upload/) | Uploads large files by splitting them into browser-side chunks and reassembling them on the server. |
| CF Post List View | [`cf-post-list-view/`](cf-post-list-view/) | A developer-focused list view for any post type — adjustable columns, SEO fields, hierarchy, taxonomy terms, and CSV export. |
| CF Content Calendar | [`cf-content-calendar/`](cf-content-calendar/) | An editorial calendar: drag to reschedule, create drafts from empty day slots, and see content across post types. |
| Schema Override Manager | [`schema-override-manager/`](schema-override-manager/) | View, suppress, extend, and inject JSON-LD structured data at the global, template, and per-page level. |

Each plugin's own `readme.txt` is the source of truth for its current version, full feature list, and changelog.

## Installing

Every plugin installs and runs from its own release zip with no prerequisites — no other plugin, no Composer or npm step, no build. Download a plugin's zip from the [Releases](../../releases) page (or build one locally — see [Development](#development)), then install it in WordPress via **Plugins → Add New → Upload Plugin**.

## Design

These are utilities for people who work in WordPress every day — developers, SEO and content teams, and site owners. They aim to:

- Feel WordPress-native: standard admin screens, controls, and language.
- Stay quiet and task-focused — no hero dashboards, onboarding funnels, upsell boxes, or email gates.
- Work independently; no plugin depends on another.

See [PRODUCT_PRINCIPLES.md](PRODUCT_PRINCIPLES.md) for the full design philosophy and [SUPPORT.md](SUPPORT.md) for how to report a bug.

## Requirements

- WordPress 6.2+
- PHP 8.0+

Some plugins run on lower versions — check the header in each plugin's main file or `readme.txt`.

## Shared library

`shared/` (`CFShared`) is a small PHP library — currently `CFShared\Logger` and `CFShared\Csv\Escaper` — reused by a few plugins through a Composer path repository during development. It is **not** a separate install: the release build bundles the runtime code each consuming plugin needs into that plugin's own zip, so users never install or manage it. It is not an installable WordPress plugin.

## Development

### Source layout

Plugin roots are the canonical source. Use a plugin's `src/` only for JavaScript/CSS that compiles into `build/`:

- `cf-bulk-meta-editor/src/`
- `cf-chunked-upload/src/`
- `cf-content-calendar/src/`
- `schema-override-manager/src/`

Generated and dependency directories stay out of git: `node_modules/`, `vendor/`, `build/`, `dist/`, and release zips.

### Bootstrap

Install JavaScript dependencies once from the repository root:

```bash
pnpm install
```

This repo uses pnpm workspaces, so JS dependencies live in one root
content-addressed install instead of being duplicated in each plugin directory.

From a plugin directory, run only what that plugin needs:

```bash
composer install   # plugins with a composer.json (PHPUnit, PHPCS, runtime deps)
pnpm build         # plugins that compile src/ into build/
```

### Tests

Run from each plugin directory:

| Plugin | Command |
|---|---|
| `cf-bulk-meta-editor/` | `composer test` |
| `cf-chunked-upload/` | `composer test`, `npm run test:js` |
| `cf-content-calendar/` | `composer test` |
| `qr-redirect/` | `composer test` |
| `cf-media-manager/` | `composer test` |
| `shared/` | `composer test` |

Shallow PHP syntax check across every plugin, from the repo root:

```bash
find . -path '*/vendor/*' -prune -o -path '*/node_modules/*' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Building release zips

```bash
node scripts/release.mjs <plugin-directory>
```

The helper reads the plugin header version, runs `php -l` and any installed tests, runs `npm run build` and `composer install --no-dev` where applicable, regenerates the `.pot`, stages an explicit runtime allowlist, writes `dist/<slug>-<version>.zip`, and rejects any archive that still contains development artifacts. Required tools: `node`, `npm`, `composer`, `php`, `zip`, `unzip` (`wp`/WP-CLI is optional, used for `.pot` regeneration).

See [TESTING.md](TESTING.md) for the full local test and smoke-test workflow.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
