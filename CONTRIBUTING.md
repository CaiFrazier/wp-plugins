# Contributing

Thanks for your interest in the CF WordPress plugins.

## Licensing of contributions

This repository is licensed under GPL v2 or later ([`LICENSE`](./LICENSE)). By submitting a contribution, you agree that your contribution is licensed under the same terms.

## Before you open a pull request

Keep changes focused. Update or add tests when behavior changes. Follow the design principles in [`PRODUCT_PRINCIPLES.md`](./PRODUCT_PRINCIPLES.md): plugins stay independent, WordPress-native, and free of upsells or suite marketing.

Install only what the plugin you are changing needs. From that plugin's directory:

```bash
composer install   # plugins with a composer.json
pnpm build         # plugins that compile src/ into build/
```

JavaScript dependencies are installed once from the repository root with `pnpm install`. See the [README](./README.md#development) for the source layout and other development commands.

## Tests and lint

The local verification pattern is documented in [`TESTING.md`](./TESTING.md). In short:

- From a plugin directory, run that plugin's test command (usually `composer test`) and, when the plugin compiles assets, its build or JS tests.
- From the repository root, a PHP syntax check of plugin sources is documented in TESTING.md.
- Plugins with Composer tooling should expose `composer lint` using [`phpcs.shared.xml`](./phpcs.shared.xml).

The full suite should pass for the plugins you touch.

## Releases

Release zips are built from the repository root with `node scripts/release.mjs <plugin-directory>`. TESTING.md describes the dry run and what the helper includes or rejects. Users install a plugin from its own zip; they do not need this repository, Composer, or npm.

## Bug reports

See [`SUPPORT.md`](./SUPPORT.md) for what a useful bug report includes. Security reports are described in [`SECURITY.md`](./SECURITY.md).
