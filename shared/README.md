# CF WordPress Plugins Shared Library

`shared/` is an internal Composer package for code that is reused by multiple CF WordPress plugins. It is not an installable WordPress plugin.

Current package:

- Composer name: `caifrazier/wp-plugins-shared`
- Namespace: `CFShared\`
- PHP baseline: `>=7.4`
- Current consumers: `cf-bulk-meta-editor/` and `schema-override-manager/`

## Current Policy

Keep `CFShared` small and internal. It is allowed as repo source and as a release-time bundled dependency, but it must never become a separate install prerequisite for users.

Every public plugin zip must be independently installable. If a plugin uses `CFShared`, the release process must bundle the runtime code into that plugin's zip.

Add code here only when at least two plugins need the same behavior and the helper has a stable, plugin-agnostic contract.

Do not use `shared/` for plugin-specific settings, UI, REST routes, CPTs, or business rules.

## Components

### `CFShared\Logger`

Structured JSONL logger for plugin diagnostics.

Key behavior:

- Each plugin creates its own logger with `Logger::for_plugin()`.
- Logs are written below the site's uploads directory in a plugin-specific folder.
- Log filenames are randomized via an option.
- Warning and error entries are mirrored to `error_log()` with context values removed.
- Context is scrubbed before storage to reduce sensitive-value leakage.
- Log rotation is built in.

Typical shape:

```php
$logger = \CFShared\Logger::for_plugin(
	[
		'slug' => 'my-plugin',
		'debug_constant' => 'MY_PLUGIN_DEBUG',
	]
);

$logger->warn( 'sync', 'Remote request failed.', [ 'status' => 500 ] );
```

### `CFShared\Csv\Escaper`

CSV formula-injection defense for exported spreadsheet cells.

Use this before passing values to `fputcsv()` when a plugin exports user-controlled content. Cells beginning with `=`, `+`, `-`, `@`, tab, or carriage return are prefixed with a single quote so spreadsheet applications treat them as text.

```php
$safe_row = \CFShared\Csv\Escaper::escape_row( $row );
fputcsv( $handle, $safe_row );
```

## Tests

```bash
cd shared
composer install
composer test
```

## Release Behavior

Plugins that require `CFShared` should bundle it through Composer during release packaging. Today that applies to:

- `cf-bulk-meta-editor/`
- `schema-override-manager/`

The release helper runs `composer install --no-dev --optimize-autoloader` for those plugins before staging the zip.

## Versioning

Current package version is `1.0.0`.

Treat this package as an internal API:

- Keep backward compatibility for current consumers.
- Prefer additive changes.
- Update consumer tests when shared behavior changes.
- Do not expand this into a framework.
