# CF WordPress Plugins

This directory contains independently installable WordPress plugins plus one shared PHP library used by plugins that need common infrastructure.

Current release strategy:

- Self-hosted release zips are the first wide-release target.
- WordPress.org publishing is a longer-term stretch goal.
- Each plugin must install from its own zip with no prerequisite CF plugin, `shared/` checkout, Composer install, npm install, or build step required by the end user.
- Plugins are free lead-generation utilities.
- Branding should be light and WordPress-native: sibling tools, no flashy screens, no upsells, no marketing getting in the way.
- Public bug reports are welcome; support is best-effort unless tied to client/internal work.
- Target foundation baseline is WordPress 6.2+ and PHP 8.0+.
- Multisite support is not a launch promise unless a plugin has been explicitly tested for it.

| Plugin | Directory | Version | Build | Runtime vendor? | Status |
|---|---|---:|---|---|---|
| CF Bulk Meta Editor | `cf-bulk-meta-editor/` | 1.0.0 | `npm run build` | Yes, uses `CFShared` | Active |
| CF Chunked Upload | `cf-chunked-upload/` | 1.0.0 | `npm run build` | No | Active |
| CF Content Calendar | `cf-content-calendar/` | 0.1.0 | `npm run build` | No | Alpha |
| CF Media List View | `cf-media-list-view/` | 1.0.0 | None | No | Alpha |
| CF Post List View | `cf-post-list-view/` | 1.0.0 | None | No | Alpha |
| CF QR Redirect | `qr-redirect/` | 1.0.3 | None | No | Active |
| Schema Override Manager | `schema-override-manager/` | 1.0.0 | `npm run build` | Yes, uses `CFShared` | Alpha |
| CF Media Manager | `cf-media-manager/` | 2.0.1-dev | None | No | Active (multisite-ready) |
| Shared library | `shared/` | 1.0.0 | None | n/a | Internal Composer package |

The `shared/` package currently provides `CFShared\Logger` and `CFShared\Csv\Escaper`. It is consumed by CF Bulk Meta Editor and Schema Override Manager through Composer path repositories during development/release, but it must never be a separate install prerequisite for users.

## Source Layout

Plugin roots are the canonical source. Do not keep a second plugin copy inside a plugin-local `src/` directory.

Use `src/` only for JavaScript/CSS source that is compiled into `build/`:

- `cf-bulk-meta-editor/src/`
- `cf-chunked-upload/src/`
- `cf-content-calendar/src/`
- `schema-override-manager/src/`

Generated and dependency directories stay out of git:

- `node_modules/`
- `vendor/`
- `build/`
- `dist/`
- `.DS_Store`
- `.phpunit.result.cache`
- root-level `*.zip`

## Bootstrap

From a plugin directory:

```bash
composer install
npm install
npm run build
```

Only run the commands a plugin needs:

- Composer is needed for PHPUnit, PHPCS, and plugins with `composer.json`.
- npm is needed only for plugins with `package.json`.
- `npm run build` is needed only for plugins that compile `src/` to `build/`.

## Tests

Run plugin tests from the plugin directory:

| Directory | Command |
|---|---|
| `cf-bulk-meta-editor/` | `composer test` |
| `cf-chunked-upload/` | `composer test` and `npm run test:js` |
| `cf-content-calendar/` | `composer test` |
| `cf-media-list-view/` | `composer test` |
| `qr-redirect/` | `php tests/test-suite.php` |
| `shared/` | `composer test` |
| `cf-media-manager/` | `composer test` |

Schema Override Manager does not currently define a PHPUnit suite in this tree.

For a shallow all-plugin syntax check from the repo root:

```bash
find . -path '*/vendor/*' -prune -o -path '*/node_modules/*' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Release Zips

Use the repo helper instead of manually zipping plugin folders:

```bash
node scripts/release.mjs <plugin-directory>
```

Examples:

```bash
node scripts/release.mjs cf-bulk-meta-editor
node scripts/release.mjs qr-redirect
node scripts/release.mjs cf-media-manager
```

The helper reads the WordPress plugin header version, stages an explicit allowlist, writes `dist/<zip-slug>-<version>.zip`, and rejects archives containing development artifacts.

Release behavior:

- Runs `npm run build` for plugins that declare a build step.
- Runs `php -l` against package PHP files before staging.
- Runs available plugin tests when the local test dependencies are already installed.
- Runs `composer install --no-dev --optimize-autoloader` for plugins that must ship Composer runtime dependencies.
- Includes `vendor/` only for CF Bulk Meta Editor and Schema Override Manager.
- Excludes `node_modules/`, `src/`, `tests/`, dotfiles, lockfiles, config files, and old zip artifacts.

Release zips are the public installation artifacts. A built zip should contain everything WordPress needs at runtime.

Required local tools: `node`, `npm`, `composer`, `php`, `zip`, and `unzip`. `wp` is optional for fresh WordPress activation smoke tests.

The release helper intentionally keeps using the system `zip`/`unzip` tools for now. They are already available on the target local environment, make archive inspection straightforward, and avoid adding another release-time npm dependency.

## Per-Plugin Notes

- `cf-bulk-meta-editor/`: renamed from the older `bulk-meta-editor/` directory. The plugin header, entrypoint, and current directory use the `cf-` slug.
- `cf-chunked-upload/`: has no `uninstall.php` today; release packaging follows the current code.
- `cf-media-list-view/` and `cf-post-list-view/`: no build step; static assets ship from `assets/`.
- `qr-redirect/`: plain PHP/JS plugin. The root plugin files are canonical.
- `schema-override-manager/`: requires bundled Composer runtime dependencies because it imports `CFShared`. Intended to become public later, after real-world battle testing.
- `cf-media-manager/`: plain PHP/JS plugin with WP-CLI integration in `cli.php`. The root plugin files are canonical. 2.0.1 closed a multi-phase security/correctness/perf audit: SSRF-hardened live page verifier (extracted into `UrlVerifier`), multisite capability gates on every destructive endpoint, atomic per-attachment convert lock, post-INSERT convergence DELETE for the variant manifest, and PCRE-failure fallback in the front-end rewriter. Multisite uninstall is covered by `UninstallTest`; the cap-gate split is `Security::authorize_ajax` for read-only / per-user endpoints vs. `Security::authorize_ajax_network` for the nine endpoints with cross-site blast radius.

## Roadmap

`PRODUCT_PRINCIPLES.md` captures product posture, branding, support, and baseline direction. `SUPPORT.md` captures bug-report and support expectations. `TODO.md` is the top-level wide-release checklist. `TESTING.md` defines local test and smoke-test workflow. `ROADMAP.md` tracks plugin ideas and product direction. Each plugin's `PRELAUNCH.md` tracks local release risks. `shared/PRELAUNCH.md` tracks cross-cutting release, CI, i18n, and repo hygiene work.
