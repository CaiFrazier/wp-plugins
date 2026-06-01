# CF WordPress Plugin Product Principles

These principles guide the plugin area while the tools are being stabilized.

## Audience

These plugins are for professionals working inside WordPress: developers, SEO operators, content teams, agency staff, and site owners who need focused utilities without marketing friction.

## Experience

- Feel WordPress-native first.
- Use standard admin patterns, standard controls, and familiar WordPress language.
- Keep screens quiet, practical, and task-focused.
- Avoid flashy dashboards, hero panels, onboarding funnels, artificial gamification, or upsell surfaces.
- Prefer compact diagnostics and clear status messages over decorative UI.
- Make each plugin feel like a sibling through naming, tone, layout discipline, and support links, but never make one plugin depend on another.

## Branding

Use light CF branding:

- Plugin names may use the `CF` prefix.
- Admin pages may include a restrained support/bug-report link.
- Readmes and docs should share a consistent author/support posture.
- Do not add cross-plugin ads, suite banners, upgrade prompts, email capture gates, or SaaS-style onboarding.

## Independence

Every plugin must install and run from its own release zip. End users must not need:

- another CF plugin
- the repo `shared/` directory
- Composer
- npm
- source files
- a build step

Shared source code is allowed in the repo only when release zips bundle the runtime code needed by that plugin.

## Support And Bug Reports

These are free lead-generation utilities, not paid products. Public support should be clear and modest:

- Bug reports are welcome.
- Reproducible bugs are prioritized.
- Client/internal issues come first.
- Custom implementation or paid support can be offered outside the plugin UI/docs.
- Plugin screens may include a quiet "Report a bug" link, but not marketing copy or sales pressure.

## Baseline

Target baseline for active plugins:

- WordPress `6.2+`
- PHP `8.0+`

Current plugin headers/readmes/composer constraints may still need to be aligned to this baseline. Treat the baseline as the foundation target, not proof that every plugin file has already been updated.

## Release Direction

Near-term wide release means self-hosted publishing with directly distributed zip files. WordPress.org is a longer-term stretch goal after the foundations, docs, testing, and plugin quality are stronger.
