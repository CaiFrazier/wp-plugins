# CLAUDE.md — CF WordPress Plugins

Orientation for Claude (and other AI agents) working in this repo. `README.md` covers the plugins, `TESTING.md` the test setup, and `PRODUCT_PRINCIPLES.md` the design rules. Each plugin's `readme.txt` is the source of truth for its version and changelog.

## WordPress must-use plugins: none new

Do not create WordPress must-use plugins (`wp-content/mu-plugins/`), and do not ship one from any plugin in this repo.

- **Heavier features** (their own routes, admin screens, stored data, or files) go in a proper, versioned plugin: the product plugin they belong to, or a new standalone plugin.
- **Small fixes** (CSS, a redirect, schema, an accessibility patch, a single filter) are consolidated into the plugin they belong to, or into the site's existing site plugin or theme when the fix is site-specific rather than product behavior.
- **Existing MU files on sites are being migrated** into those homes. Don't add new ones in the meantime.
