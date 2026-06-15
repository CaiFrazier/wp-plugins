# CF Accessibility Remediation — Strategy & Design

Working name: **CF Accessibility Remediation** (alternatives: CF A11y Fixes, CF Remediator).
Status: design draft. No code yet.
Distribution: **internal agency tool**, installed per client site. Not a public/wp.org release, not a competitor to overlay products.
Baseline: GPL-2.0-or-later, WP 6.2+, PHP 8.0+ (same as the rest of the repo).

---

## 1. What this is, in one paragraph

A WordPress plugin the agency installs on a client site to apply a curated set of programmatic, render-time accessibility fixes: the WordPress and template-level corrections that are best made globally in code rather than by hand on every page. It is the *fix* layer of the agency's accessibility workflow. It does not audit (Cartographer does that) and it does not adjust the visitor's view (the discarded widget idea). Most remediation on any given site is still manual. This plugin handles the structural and backend subset that a human shouldn't be repeating page by page, records what it changed, and exposes a tightly scoped set of options the agency turns on per client when a fix is in scope.

---

## 2. How it fits the agency workflow

Three roles, kept separate:

| Layer | Tool | Job |
|---|---|---|
| Audit | **Cartographer** (separate) | Find and report WCAG failures |
| Programmatic fix | **CF Accessibility Remediation** (this) | Apply the globally-fixable subset at render time, log what was changed |
| Manual fix | Agency dev work | Everything heuristics can't safely do: alt-text quality, content structure, custom components, keyboard logic |

The compliance assertion is **agency to client**, not website to reader. This plugin contributes to that assertion in two ways: it makes a defined class of fixes consistently across the whole site, and it produces a remediation manifest the agency can hand the client as part of the evidence package (alongside the Cartographer before/after audit, the manual remediation log, and the accessibility statement). The plugin itself claims nothing automatically.

This corrects the earlier direction. A front-end visitor widget is the wrong tool for this goal and raises litigation risk for client sectors like credit unions and dental practices, both heavy ADA web-suit targets in 2024–2025. That idea is dropped. If a visitor preference panel is ever wanted it is a separate, clearly non-compliance product.

---

## 3. Design constraints

1. **Non-destructive.** Fixes are applied at render time through filters and an optional output pass. The plugin never rewrites stored post content. Deactivation returns the site to its prior output with nothing to clean up. This keeps every change reversible and avoids corrupting client databases.
2. **Opt-in, per fix, per site.** Everything defaults to off. The agency enables only the fixes that are in scope for that client, matching "present options for the user if we deem it within scope." No fix runs because it seemed like a good idea.
3. **Idempotent and conflict-aware.** A fix must detect when the theme or another plugin already satisfies it (an existing `<main>`, an existing skip link) and do nothing rather than double up.
4. **Surgical before sweeping.** Prefer a targeted WordPress filter over a full-page DOM rewrite. The DOM pass exists only for fixes that have no clean filter hook, and it sits behind its own master switch.
5. **Auditable.** Every applied fix is recorded with counts and scope, so the agency can show the client exactly what changed.
6. **Aligned to Cartographer.** Fix descriptions reference the same WCAG success criteria Cartographer reports against, so a finding maps cleanly to an available fix.

---

## 4. Fix catalog

Each fix lists the WCAG success criterion it targets, the mechanism, and the risk tier. Risk tiers: **low** (targeted filter, deterministic), **medium** (DOM pass, deterministic), **high** (heuristic, can be wrong, preview required).

### 4.1 Document and global (low risk, targeted filters)

| Fix | WCAG | Mechanism |
|---|---|---|
| Set/correct `<html lang>` | 3.1.1 | Filter `language_attributes` |
| Normalize viewport meta: strip `user-scalable=no`, raise `maximum-scale` to >=5 | 1.4.4, 1.4.10 | Replace the viewport `<meta>` in `wp_head` |
| Inject skip-to-content link + target | 2.4.1 | `wp_body_open` output + scoped CSS, target the main landmark |
| Conservative `:focus-visible` outline | 2.4.7 | Enqueue scoped CSS, opt-in, written to not clobber theme focus styles |
| `prefers-reduced-motion` guard CSS | best practice | Optional enqueue |

### 4.2 Landmarks and structure (medium risk, DOM pass)

| Fix | WCAG | Mechanism |
|---|---|---|
| Wrap primary content in `<main id>` if absent | 1.3.1, 2.4.1 | DOM pass wraps a configurable content selector; skips if a `<main>` already exists |
| Remove positive `tabindex` values | 2.4.3 | DOM pass sets offending `tabindex` to `0` or removes |
| Add `title` to embed `<iframe>`s (YouTube, Vimeo, maps) | 4.1.2 | DOM pass derives a title from src/context; skips if titled |

### 4.3 Navigation and links

| Fix | WCAG | Mechanism | Risk |
|---|---|---|---|
| Add `<label>`/`aria-label` to the core search form | 1.3.1, 4.1.2 | Filter `get_search_form` | low |
| Label icon-only / empty links and buttons | 4.1.2 | DOM pass derives an accessible name from `title`/nearby text where unambiguous, otherwise flags only | high |
| Append visually-hidden "(opens in new tab)" to `target=_blank` links | best practice | DOM pass or `the_content` filter | medium |
| Add screen-reader context to ambiguous link text ("read more") | 2.4.4 | `the_content`/excerpt filter, pattern-based, opt-in | high |

### 4.4 Out of scope for this plugin

- **Image alt-text quality.** A human decision; bulk content-side alt editing belongs with CF Bulk Meta Editor, not here. This plugin may at most enforce `alt=""` on known-decorative theme images.
- **Heading order / single-H1.** Not safely auto-fixable. Cartographer flags it, a human fixes it.
- **Color contrast values.** Authoring concern (CF Color Tools), not a render-time rewrite.
- **Plugin-generated form field labels** beyond the core search form, until proven safe per form system. High heuristic risk; start flagged, not fixed.

---

## 5. Technical design

### 5.1 Two mechanisms

**Targeted filters (preferred).** Most Section 4.1 and some 4.3 fixes hook a specific WordPress output (`language_attributes`, `wp_head`, `wp_body_open`, `get_search_form`, `the_content`). Deterministic, cheap, low blast radius.

**Output-buffer DOM pass (guarded).** Fixes that have no clean hook (wrapping `<main>`, iframe titles, positive `tabindex`, empty-link labeling) need the rendered HTML. Pattern:

- Hook `template_redirect`, start `ob_start()` with a callback.
- In the callback, run only on front-end HTML responses. Skip admin, REST, AJAX, feeds, sitemaps, `Content-Type` that is not `text/html`, and any configured URL/post-type exclusions.
- Parse with a UTF-8-safe DOM parser, run the enabled fix pipeline, serialize back.
- Each fix in the pipeline is individually toggled and idempotent.

Known pitfalls to handle explicitly: `DOMDocument` mangles HTML5 and entities if used naively, so guard encoding and consider a more robust HTML5-aware parser; full-page parsing has a performance cost, so the pass stays behind a master switch and can be limited to specific templates; page-cache and optimization plugins can capture output before or after the pass, so cache compatibility is a test requirement, not an assumption.

### 5.2 Non-destructive guarantee

No fix writes to `wp_posts` or `wp_postmeta`. All changes happen on the response. Deactivating the plugin or disabling a fix removes its effect on the next request. This is the core safety property and the reason the agency can apply, reverse, and re-scope fixes without risk to client content.

### 5.3 Fix registry

Each fix is a small class implementing a common interface: a stable id, the WCAG SC it maps to, a risk tier, an `is_enabled()` check against settings, an `apply()` (filter-based) or `transform(DOMNode)` (DOM-pass) method, and a `report()` that returns counts for the manifest. New fixes register into a central list. This keeps the catalog extensible and makes the settings UI and manifest generate themselves from the registry.

### 5.4 Remediation manifest

A per-site record of which fixes are enabled and, per request class or on demand, what they changed (for example: skip link injected, `<main>` wrapped on N template types, 14 iframe titles added, 3 positive tabindex removed, lang set to `en-US`). Stored in one options/log row, viewable and exportable from the admin. This is the agency-to-client evidence artifact.

### 5.5 Configuration

- A single admin page listing every registered fix as a toggle, grouped by Section 4 category, each showing its description, WCAG SC, risk tier, and scope controls (include/exclude by post type or URL pattern).
- **Dry-run mode** per fix: report what *would* change without altering output, so the agency previews a high-risk fix before enabling it.
- **Config export/import (JSON):** a baseline profile the agency tunes per client, so a standard remediation posture can be templated.
- **Master kill switch** and a separate switch for the DOM pass.
- One options row for settings, one for the manifest log, for a clean uninstall.

### 5.6 Cartographer seam

Loose coupling. The plugin operates standalone with the agency enabling fixes from Cartographer's report by hand. Optional later integration: import a Cartographer findings export (JSON) to map each finding to an available fix, show coverage (which findings this plugin can address vs. which need manual work), and produce a combined before/after view. The findings schema is an open question until Cartographer's output format is pinned, so the import is a v0.3 item, not a v0.1 dependency.

---

## 6. File layout

Follows CF conventions; closer to schema-override-manager's `includes/` structure than to the flat cf-color-tools layout, because of the fix registry and admin UI.

```
cf-accessibility-remediation/
  cf-accessibility-remediation.php   // header, constants, bootstrap
  uninstall.php                      // delete settings + manifest rows
  README.md                          // internal agency doc (setup, scoping, per-client profiles)
  DESIGN.md                          // this file
  includes/
    Plugin.php                       // bootstrap, hook registration
    FixRegistry.php                  // registers and runs fixes
    Fixes/                           // one class per fix
    DomPass.php                      // output-buffer pipeline runner
    Manifest.php                     // change log + export
    Settings.php                     // admin page, scope controls, dry-run, import/export
    Util.php
  assets/
    css/                             // focus-visible, skip-link, admin UI
    js/                              // admin UI only
  languages/
```

No build step required for runtime. JS is admin-only and can stay vanilla.

---

## 7. Risks and open questions

- **DOM pass fidelity and performance.** The biggest technical risk. Mitigation: prefer filters, keep the pass behind its own switch, make it template-scopable, use an HTML5-aware parser, and test against the agency's standard cache/optimization stack.
- **Heuristic fixes being wrong.** Empty-link labeling and link-text rewriting can produce bad names. Mitigation: high-risk tier, dry-run required, prefer flagging over guessing when context is ambiguous. A wrong accessible name can be worse than a missing one.
- **Conflict with themes/plugins.** Idempotency checks must be real (detect existing `<main>`, skip link, lang). Add nothing that already exists.
- **Scope discipline.** The tool catches a defined subset. The agency must not let its existence imply the site is done. The README should state plainly that manual remediation remains primary and this handles the global/structural backend layer only.
- **Cartographer findings schema.** Undefined here; gates the v0.3 import feature, nothing earlier.
- **Multisite / per-client deployment.** Confirm how the agency ships and version-pins this across client sites (single-site installs vs. a managed bundle). Note for ops, not a blocker.

---

## 8. Build plan

1. **v0.1 — framework + low-risk filters.** Bootstrap, fix registry, settings UI, manifest, dry-run scaffolding. Fixes: html lang, viewport normalize, skip link, focus-visible, search-form label.
2. **v0.2 — DOM pass engine.** Guarded output-buffer runner plus `<main>` wrap, iframe titles, positive-tabindex removal.
3. **v0.3 — Cartographer import + reporting.** Findings import, finding-to-fix mapping, combined coverage report. Config export/import.
4. **v0.4 — heuristic helpers.** Empty-link labeling and new-tab/link-text helpers, all high-risk-tier with mandatory preview.
5. **v1.0 — hardening.** Cache-plugin compatibility, performance pass, multisite check, agency README, manifest export for client documentation.

Each step is usable on its own and adds no dependency on another CF plugin.
