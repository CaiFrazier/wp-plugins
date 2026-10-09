# CF WordPress Plugin Support

These plugins are free professional utilities. Support is best-effort, with reproducible bug reports prioritized. Paid or custom support can be arranged separately.

## Bug Reports

Use the website form at https://caifrazier.com/plugins/support/ as the primary bug-report channel. No account is required, and the reply email is optional. The form accepts a plugin preselection through `?plugin=<directory-slug>`. Reports enter private intake and are not published as GitHub issues. Support is best-effort, with no guaranteed response time; an email address helps when follow-up is needed.

GitHub provides public source, build instructions, and change history for inspection and trust. Ordinary users do not need a GitHub account to report a bug.

Bug reports are useful when they include enough detail to reproduce the issue. Do not send passwords, API keys, private site content, or sensitive logs.

Useful bug reports include:

- Plugin name and version.
- WordPress version.
- PHP version.
- Active theme.
- Relevant active plugins, especially SEO, cache, media, schema, security, and editor plugins.
- Whether the site is single-site or multisite.
- Exact steps to reproduce.
- Expected behavior.
- Actual behavior.
- Screenshots, browser console errors, PHP errors, logs, or exported diagnostics when available.

## Triage Priority

Priority order:

1. Security issues and data-loss risks.
2. Reproducible bugs with clear steps and environment details.
3. Compatibility issues with major WordPress, PHP, SEO, cache, media, and editor stacks.
4. Feature requests.

## Plugin UI Policy

Each plugin may expose a quiet "Report a bug" link from its settings/admin screen pointing to the website form, with plugin preselection when supported.

The bug-report affordance should be small and WordPress-native. Do not add upsell boxes, onboarding modals, banners, email gates, or suite marketing to plugin screens.

## Current Baseline

Target baseline for active plugin work:

- WordPress `6.2+`
- PHP `8.1+`

If a bug is reported below that baseline, document it, but do not treat support as guaranteed.
