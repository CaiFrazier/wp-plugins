# CF WordPress Plugins — Design Principles

The principles behind these plugins.

## Audience

These plugins are for people who work inside WordPress every day: developers, SEO operators, content teams, agency staff, and site owners who want focused utilities without marketing friction.

## Experience

- Feel WordPress-native first — standard admin patterns, standard controls, familiar WordPress language.
- Keep screens quiet, practical, and task-focused.
- Avoid flashy dashboards, hero panels, onboarding funnels, gamification, or upsell surfaces.
- Prefer compact diagnostics and clear status messages over decorative UI.
- Make each plugin feel like a sibling through naming, tone, and layout discipline — but never make one plugin depend on another.

## Branding

- Plugin names may use the `CF` prefix.
- Admin pages may include a restrained support/bug-report link.
- Readmes and docs share a consistent author and support posture.
- No cross-plugin ads, suite banners, upgrade prompts, email-capture gates, or SaaS-style onboarding.

## Independence

Every plugin installs and runs from its own release zip. Users never need another CF plugin, the repo's `shared/` directory, Composer, npm, source files, or a build step. Shared code is allowed in the repo only when the release zip bundles the runtime it needs.

## Baseline

Target baseline for active plugins:

- WordPress `6.2+`
- PHP `8.0+`

## Support

Bug reports are welcome, and reproducible issues are prioritized — see [SUPPORT.md](SUPPORT.md). Paid or custom implementation work is handled separately, outside the plugins themselves.
