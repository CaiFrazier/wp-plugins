# WordPress.org submission preparation

These are unpublished submission candidates. The GitHub release tags and production downloads remain at their published versions.

| Plugin | Published | Candidate | Proposed directory slug |
| --- | --- | --- | --- |
| CF Media Manager | 3.0.1 | 3.0.2 | cf-media-manager |
| CF Media Optimizer | 3.0.3 | 3.0.4 | cf-media-optimizer |
| CF Chunked Upload | 1.2.1 | 1.2.2 | cf-chunked-upload |
| CF Content Calendar | 0.1.2 | 0.1.3 | cf-content-calendar |
| CF Bulk Meta Editor | 1.0.5 | 1.0.6 | cf-bulk-meta-editor |
| CF Post List View | 1.0.1 | 1.0.2 | cf-post-list-view |
| CF QR Redirect | 1.3.1 | 1.3.2 | cf-qr-redirect |
| Schema Override Manager | 1.0.1 | 1.0.2 | schema-override-manager |

All candidates declare PHP 8.1+, WordPress 6.2+, and Tested up to 7.1. The current official WordPress download and version API returned 7.1.3 when checked on October 9, 2026.

## Rebuild and validate

1. Install Composer development dependencies in each plugin that has composer.json. Install JavaScript workspace dependencies with pnpm.
2. From the repository root, run `node scripts/release.mjs <plugin-directory>`. QR's source directory is `qr-redirect`; its ZIP directory is `cf-qr-redirect`.
3. Install each exact generated ZIP into a disposable local WordPress installation. Install and activate the official `plugin-check` plugin there.
4. Run `wp plugin check /absolute/path/to/wordpress/wp-content/plugins/<slug> --slug=<slug> --require=/absolute/path/to/wordpress/wp-content/plugins/plugin-check/cli.php --format=json`. The absolute package path matters: a bare slug can resolve to a development source directory when run from this repository.
5. Run the existing `tests/integration/release-safety.php` fixture with its CF_WORDPRESS_ROOT, CF_MANAGER_ROOT, CF_PLV_ROOT, and CF_TRASH_MODE environment variables against that local installation. The fixture refuses non-local WordPress environments.

The builder verifies version consistency, PHP syntax, compiled assets, regenerated translation templates, and development-file exclusion. It retains full license notices and production Composer dependencies. Readmes include public GitHub source/build links, the primary website bug-report form, data storage, and external-service disclosures. The form requires no account and accepts an optional reply email; reports enter private best-effort intake. Plugin-specific support URLs use `?plugin=<directory-slug>`. Screenshot captions without corresponding directory assets have been removed. Screenshots, banners, and icons are optional directory presentation assets and can be added in SVN after approval.

## Changes beyond metadata

Post List View now computes child counts with WordPress query caching and the same permission scope as its list and CSV. Its output stream close has a precise scanner annotation; translations use automatic WordPress textdomain loading. Media Manager's existing direct-access guard is positioned where Plugin Check can detect it. Schema Override Manager's PHP globals use CFSOM/cfsom; persisted option and meta keys and already-published filter contracts remain stable. Scanner annotations explain existing external integration hooks and legacy filters rather than renaming those contracts.

QR's optional Google Analytics intermediate page is documented as an external service. It requires an explicitly configured analytics mode and GA4 property. The readme lists data sent to Google, data stored locally, links Google's terms/privacy policy, and explains that its standalone page does not inherit a theme consent manager. The default UTM redirect has no external script.

## Validation and owner actions before uploading

Each exact candidate ZIP was installed into local WordPress 7.1.3 and passed official Plugin Check 2.1.0 with zero errors and zero warnings. Installed files were compared byte for byte against ZIP contents. Full build test suites ran 912 PHPUnit tests with two Media Optimizer AVIF skips because the local codec is unavailable. Permission and core Trash integration checks passed in all three supported Trash configurations. Browser acceptance verified a Bulk Meta Editor metadata edit/save/database readback and Calendar month/week/list/filter state. QR JSZip PNG archive roundtrip passed.

Bundled AG Grid was updated to 36.2.0, Zustand to 5.0.15, and JSZip to 3.10.2. The grid now registers community modules and uses a controlled React cell editor as required by the updated AG Grid API. The existing Alpine CSS is retained using legacy theme mode. Full upstream dependency licenses are included.

All eight proposed slugs returned Plugin not found from the public directory API on October 9, 2026. This does not reserve a slug or guarantee assignment; reserved or pending names are not visible through this query.

The owner confirmed `caifrazier` as the WordPress.org username; it is retained in Contributors. Use a company-controlled, monitored email for the submitting account. Verify proposed slugs before approval, since approved slugs cannot be renamed. A Plugin Check pass establishes automated readiness, not directory approval: the WordPress.org team still performs a manual review. Submit only after choosing to make these candidates public; upload complete ZIPs through the authenticated developer form. After approval, publish through the assigned SVN repositories and keep their stable versions current.

Official references:

- https://wordpress.org/plugins/developers/
- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/plugins/wordpress-org/common-issues/
- https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- https://wordpress.org/plugins/plugin-check/
- https://developer.wordpress.org/plugins/wordpress-org/planning-submitting-and-maintaining-plugins/

## Website support metadata update

The website support readme update retains the same unpublished candidate versions. Only each packaged readme changes; all other ZIP entries remain byte-identical to the previously tested candidate. The final packages are installed again and run through official Plugin Check. The audit packet records new SHA256 hashes and installed-byte parity, while the original PHP, JavaScript and browser acceptance evidence remains applicable to the unchanged runtime.
