#!/usr/bin/env node
/**
 * CF WordPress plugin release builder.
 *
 * Usage: node scripts/release.mjs <plugin-directory>
 *
 * Pipeline: assert tools -> php -l -> tests -> pnpm build -> composer runtime
 * install -> regenerate .pot -> stage runtime files -> zip -> reject dev
 * artifacts in the zip listing.
 *
 * Required tools: node, php, zip, unzip. pnpm when the plugin builds assets;
 * composer when the plugin ships a runtime vendor/ or has a Composer test
 * suite installed.
 *
 * Optional tools: wp (WP-CLI). Used to regenerate languages/<domain>.pot for
 * plugins that declare `potDomain`. If wp is absent the committed .pot ships
 * unchanged (and the build fails only if no .pot exists at all).
 */
import { execFileSync, execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const pluginsRoot = path.resolve( __dirname, '..' );

const plugins = {
	'cf-bulk-meta-editor': {
		entry: 'cf-bulk-meta-editor.php',
		zipSlug: 'cf-bulk-meta-editor',
		// composer.json ships because WordPress.org Plugin Check warns when
		// vendor/ is present without an accompanying composer.json. The dev-
		// artifact guard below honours composerRuntime to allow it through.
		rootFiles: [ 'cf-bulk-meta-editor.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'composer.json' ],
		rootDirs: [ 'includes', 'languages', 'build', 'vendor' ],
		npmBuild: true,
		composerRuntime: true,
		composerTest: true,
		potDomain: 'cf-bulk-meta-editor',
	},
	'cf-chunked-upload': {
		entry: 'cf-chunked-upload.php',
		zipSlug: 'cf-chunked-upload',
		rootFiles: [ 'cf-chunked-upload.php', 'readme.txt', 'uninstall.php', 'composer.json' ],
		rootDirs: [ 'includes', 'build', 'languages', 'vendor' ],
		npmBuild: true,
		composerRuntime: true,
		composerTest: true,
		potDomain: 'cf-chunked-upload',
	},
	'cf-content-calendar': {
		entry: 'cf-content-calendar.php',
		zipSlug: 'cf-content-calendar',
		rootFiles: [ 'cf-content-calendar.php', 'uninstall.php', 'readme.txt' ],
		rootDirs: [ 'includes', 'languages', 'build' ],
		npmBuild: true,
		composerRuntime: false,
		composerTest: true,
		potDomain: 'cf-content-calendar',
	},
	'cf-post-list-view': {
		entry: 'cf-post-list-view.php',
		zipSlug: 'cf-post-list-view',
		rootFiles: [ 'cf-post-list-view.php', 'uninstall.php', 'readme.txt' ],
		rootDirs: [ 'includes', 'assets', 'languages' ],
		npmBuild: false,
		composerRuntime: false,
	},
	'qr-redirect': {
		entry: 'cf-qr-redirect.php',
		zipSlug: 'cf-qr-redirect',
		rootFiles: [ 'cf-qr-redirect.php', 'uninstall.php', 'readme.txt', 'LICENSE' ],
		rootDirs: [ 'includes', 'assets', 'languages', 'templates' ],
		npmBuild: false,
		composerRuntime: false,
		// composer.json's "test" script runs both standalone harnesses AND the
		// PHPUnit suite, so it is the single definition of "test" for this
		// plugin — same as every other plugin in the line, and the same command
		// CI runs. Listing the harnesses here as well would run them twice.
		composerTest: true,
		potDomain: 'cf-qr-redirect',
	},
	'schema-override-manager': {
		entry: 'schema-override-manager.php',
		zipSlug: 'schema-override-manager',
		// composer.json ships alongside the bundled vendor/ (shared CFShared is a
		// runtime dependency): Plugin Check warns about a vendor/ without a
		// composer.json next to it, and the dev-artifact guard honours
		// composerRuntime to allow it. Matches the cf-bulk-meta-editor pattern.
		rootFiles: [ 'schema-override-manager.php', 'uninstall.php', 'readme.txt', 'composer.json' ],
		rootDirs: [ 'includes', 'languages', 'build', 'vendor' ],
		npmBuild: true,
		composerRuntime: true,
		composerTest: true,
		potDomain: 'schema-override-manager',
	},
	'cf-media-manager': {
		entry: 'cf-media-manager.php',
		zipSlug: 'cf-media-manager',
		// composer.json ships alongside the bundled vendor/ (the shared CFShared
		// library is a runtime dependency now), matching the cf-bulk-meta-editor
		// pattern. Plugin Check warns about a vendor/ without composer.json next
		// to it, and the dev-artifact guard honours composerRuntime to allow it.
		rootFiles: [ 'cf-media-manager.php', 'cli.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'composer.json' ],
		rootDirs: [ 'includes', 'assets', 'languages', 'vendor' ],
		npmBuild: false,
		composerRuntime: true,
		composerTest: true,
		potDomain: 'cf-media-manager',
	},
	'cf-media-optimizer': {
		entry: 'cf-media-optimizer.php',
		zipSlug: 'cf-media-optimizer',
		// Like cf-media-manager, ships composer.json alongside the bundled
		// vendor/ (CFShared\Media is a runtime dependency) so Plugin Check
		// doesn't warn about a vendor/ with no composer.json beside it.
		rootFiles: [ 'cf-media-optimizer.php', 'cli.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'composer.json' ],
		rootDirs: [ 'includes', 'assets', 'languages', 'vendor' ],
		npmBuild: false,
		composerRuntime: true,
		composerTest: true,
		potDomain: 'cf-media-optimizer',
	},
};

function usage() {
	const names = Object.keys( plugins ).sort().join( ', ' );
	console.error( `Usage: node scripts/release.mjs <plugin-directory>\n\nKnown plugins: ${ names }` );
	process.exit( 1 );
}

const pluginName = process.argv[ 2 ];
if ( ! pluginName || ! plugins[ pluginName ] ) {
	usage();
}

const config = plugins[ pluginName ];
const pluginRoot = path.join( pluginsRoot, pluginName );
const entryPath = path.join( pluginRoot, config.entry );

function readVersion() {
	const contents = fs.readFileSync( entryPath, 'utf8' );
	const match = contents.match( /^\s*\*\s*Version:\s*([^\s]+)\s*$/m );
	if ( ! match ) {
		throw new Error( `Could not find Version header in ${ config.entry }` );
	}
	return match[ 1 ];
}

// Single source of truth check: the `Version:` header in the plugin entry file
// is canonical. readme.txt `Stable tag:` and package.json `version` exist for
// WP.org / npm consumers and MUST match the header at release time. Drift
// between these three is the most common pre-flight bug — catch it here
// instead of shipping a mismatched zip.
/**
 * Top-level entries that are never part of a release, in any plugin. Checked
 * only AFTER the explicit manifest, so a plugin that legitimately ships one of
 * these (composerRuntime plugins ship `vendor/` and `composer.json`) is matched
 * by its own rootFiles/rootDirs first and never reaches this list.
 */
const NEVER_SHIPPED = [
	/^\./,                                   // dotfiles and dotdirs
	/^(tests?|node_modules|src|dist|bin|vendor|releases)$/,
	/^scripts$/,                             // per-plugin build/tooling helpers
	/^(composer\.(json|lock)|package(-lock)?\.json)$/,
	/^(phpunit|phpcs)\.xml(\.dist)?$/,
	/^webpack\.config\.js$/,
	/^[A-Z_]+\.md$/,                         // README, CHANGELOG, PLAN, PRELAUNCH, TODO…
];

/**
 * Fail when the plugin directory contains a top-level entry that is neither
 * shipped nor explicitly recognised as dev-only.
 *
 * rootFiles/rootDirs are hand-maintained allowlists, which makes the zip
 * precise but silently incomplete the moment someone adds a directory and
 * forgets to register it: the build stays green and the plugin fatals on
 * activation with a class-not-found. This turns that class of mistake into a
 * loud build failure at the point the file is added, rather than a bug report
 * from a user whose site went white.
 *
 * Deliberately a two-sided decision: the fix is either "add it to the
 * manifest" or "it is dev-only" — never silence.
 */
function assertManifestCoverage() {
	const shipped = new Set( [ ...config.rootFiles, ...config.rootDirs ] );
	const unaccounted = fs
		.readdirSync( pluginRoot )
		.filter( ( entry ) => ! shipped.has( entry ) )
		.filter( ( entry ) => ! NEVER_SHIPPED.some( ( pattern ) => pattern.test( entry ) ) );

	if ( unaccounted.length > 0 ) {
		throw new Error(
			`Unregistered top-level entries in ${ config.zipSlug }:\n` +
			unaccounted.map( ( e ) => `  ${ e }` ).join( '\n' ) +
			`\n\nEvery entry must be a deliberate choice. Either add it to this ` +
			`plugin's rootFiles/rootDirs in scripts/release.mjs so it ships, or ` +
			`add its pattern to NEVER_SHIPPED if it is development-only.`
		);
	}
}

function assertVersionConsistency( pluginVersion ) {
	const errors = [];

	const readmePath = path.join( pluginRoot, 'readme.txt' );
	if ( fs.existsSync( readmePath ) ) {
		const readme = fs.readFileSync( readmePath, 'utf8' );
		const stableMatch = readme.match( /^Stable tag:\s*([^\s]+)\s*$/mi );
		if ( ! stableMatch ) {
			errors.push( `readme.txt is missing a "Stable tag:" header.` );
		} else if ( stableMatch[ 1 ] !== pluginVersion ) {
			errors.push(
				`Version drift: ${ config.entry } Version=${ pluginVersion } but readme.txt Stable tag=${ stableMatch[ 1 ] }.`
			);
		}
	}

	const packageJsonPath = path.join( pluginRoot, 'package.json' );
	if ( fs.existsSync( packageJsonPath ) ) {
		const pkg = JSON.parse( fs.readFileSync( packageJsonPath, 'utf8' ) );
		if ( pkg.version && pkg.version !== pluginVersion ) {
			errors.push(
				`Version drift: ${ config.entry } Version=${ pluginVersion } but package.json version=${ pkg.version }.`
			);
		}
	}

	if ( errors.length > 0 ) {
		throw new Error(
			`Version mismatch — bump every reference to match the plugin header before releasing:\n  - ${ errors.join( '\n  - ' ) }`
		);
	}
}

function run( command, args, cwd ) {
	console.log( `$ ${ [ command, ...args ].join( ' ' ) }` );
	execFileSync( command, args, { cwd, stdio: 'inherit' } );
}

// Like run(), but captures stdout/stderr and only surfaces them if the
// command fails. Keeps a clean run quiet even when the tool is chatty —
// e.g. an older WP-CLI phar that emits PHP deprecation notices on newer PHP.
function runCapture( command, args, cwd ) {
	console.log( `$ ${ [ command, ...args ].join( ' ' ) }` );
	try {
		execFileSync( command, args, { cwd, stdio: [ 'ignore', 'pipe', 'pipe' ] } );
	} catch ( err ) {
		if ( err.stdout ) {
			process.stdout.write( err.stdout );
		}
		if ( err.stderr ) {
			process.stderr.write( err.stderr );
		}
		throw err;
	}
}

function commandExists( command ) {
	try {
		execFileSync( 'which', [ command ], { stdio: 'ignore' } );
		return true;
	} catch {
		return false;
	}
}

function assertTool( command, reason ) {
	if ( ! commandExists( command ) ) {
		throw new Error( `Missing required tool: ${ command } (${ reason })` );
	}
}

function hasComposerTestDeps() {
	return fs.existsSync( path.join( pluginRoot, 'vendor', 'bin', 'phpunit' ) );
}

function hasComposerTestScript() {
	const composerPath = path.join( pluginRoot, 'composer.json' );
	if ( ! fs.existsSync( composerPath ) ) {
		return false;
	}
	const composer = JSON.parse( fs.readFileSync( composerPath, 'utf8' ) );
	return Boolean( composer.scripts && composer.scripts.test );
}

function assertRequiredTools() {
	assertTool( 'php', 'PHP syntax checks' );
	assertTool( 'zip', 'release archive creation' );
	assertTool( 'unzip', 'release archive inspection' );
	if ( config.npmBuild ) {
		assertTool( 'pnpm', 'plugin asset build' );
	}
	if ( config.composerRuntime || ( config.composerTest && hasComposerTestDeps() ) ) {
		assertTool( 'composer', 'Composer install/test step' );
	}
}

function copyRequiredFile( file, stageDir ) {
	const src = path.join( pluginRoot, file );
	if ( ! fs.existsSync( src ) ) {
		throw new Error( `Missing required file: ${ file }` );
	}
	fs.copyFileSync( src, path.join( stageDir, file ) );
}

function copyRequiredDir( dir, stageDir ) {
	const src = path.join( pluginRoot, dir );
	if ( ! fs.existsSync( src ) ) {
		throw new Error( `Missing required directory: ${ dir }` );
	}
	fs.cpSync( src, path.join( stageDir, dir ), {
		recursive: true,
		filter: ( item ) => {
			const base = path.basename( item );
			return base !== '.DS_Store' && base !== '.phpunit.result.cache' && base !== '.phpcs.cache';
		},
	} );
}

function collectPhpFiles( dir, files = [] ) {
	const ignoredDirs = new Set( [ 'node_modules', 'vendor', 'dist', 'build', 'coverage' ] );
	if ( ! fs.existsSync( dir ) ) {
		return files;
	}

	for ( const entry of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		if ( entry.name.startsWith( '.' ) ) {
			continue;
		}
		const entryPath = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			if ( ! ignoredDirs.has( entry.name ) ) {
				collectPhpFiles( entryPath, files );
			}
			continue;
		}
		if ( entry.isFile() && entry.name.endsWith( '.php' ) ) {
			files.push( entryPath );
		}
	}

	return files;
}

function lintPhpFiles() {
	const files = new Set();

	for ( const file of config.rootFiles ) {
		if ( file.endsWith( '.php' ) && fs.existsSync( path.join( pluginRoot, file ) ) ) {
			files.add( path.join( pluginRoot, file ) );
		}
	}

	for ( const dir of config.rootDirs ) {
		if ( 'vendor' === dir ) {
			continue;
		}
		const dirPath = path.join( pluginRoot, dir );
		for ( const file of collectPhpFiles( dirPath ) ) {
			files.add( file );
		}
	}

	const sortedFiles = [ ...files ].sort();
	if ( sortedFiles.length === 0 ) {
		console.log( 'No PHP files found for syntax check.' );
		return;
	}

	console.log( `Running php -l on ${ sortedFiles.length } file(s).` );
	for ( const file of sortedFiles ) {
		run( 'php', [ '-l', path.relative( pluginRoot, file ) ], pluginRoot );
	}
}

function runAvailableTests() {
	if ( config.composerTest && hasComposerTestScript() ) {
		if ( hasComposerTestDeps() ) {
			run( 'composer', [ 'test' ], pluginRoot );
		} else {
			console.log( 'Skipping Composer tests: vendor/bin/phpunit is not installed.' );
		}
	}

	// standaloneTest accepts a single command array, or an array of command
	// arrays when a plugin ships more than one standalone harness.
	if ( config.standaloneTest ) {
		const suites = Array.isArray( config.standaloneTest[ 0 ] )
			? config.standaloneTest
			: [ config.standaloneTest ];
		for ( const suite of suites ) {
			const [ command, ...args ] = suite;
			run( command, args, pluginRoot );
		}
	}
}

function regeneratePotFile() {
	if ( ! config.potDomain ) {
		return;
	}
	const potPath = path.join( pluginRoot, 'languages', `${ config.potDomain }.pot` );
	const langDir = path.dirname( potPath );
	if ( ! fs.existsSync( langDir ) ) {
		throw new Error(
			`Plugin declares potDomain "${ config.potDomain }" but languages/ is missing: ${ path.relative( pluginsRoot, langDir ) }`
		);
	}

	if ( ! commandExists( 'wp' ) ) {
		if ( ! fs.existsSync( potPath ) ) {
			throw new Error(
				`wp-cli (wp) not found and no committed .pot exists at ${ path.relative( pluginsRoot, potPath ) }. ` +
				`Install WP-CLI (https://wp-cli.org) to generate the translation template.`
			);
		}
		console.warn(
			`! wp-cli not found — shipping committed ${ config.potDomain }.pot without regenerating. ` +
			`Install WP-CLI for an up-to-date translation template.`
		);
		return;
	}

	runCapture(
		'wp',
		[
			'i18n',
			'make-pot',
			'.',
			path.join( 'languages', `${ config.potDomain }.pot` ),
			`--domain=${ config.potDomain }`,
			'--exclude=tests,node_modules,vendor,build,dist',
			'--skip-audit',
			// Override WP-CLI's slug-derived support URL with the designated
			// bug-report address used by the plugins.
			'--headers={"Report-Msgid-Bugs-To":"bugs@caifrazier.com"}',
		],
		pluginRoot
	);

	if ( ! fs.existsSync( potPath ) ) {
		throw new Error( `wp i18n make-pot reported success but ${ path.relative( pluginsRoot, potPath ) } is missing.` );
	}
	console.log( `Regenerated ${ path.relative( pluginsRoot, potPath ) }` );
}

/**
 * True when a path *inside a bundled vendor/ tree* is a development artifact
 * rather than runtime code. The path is relative to the vendor root, e.g.
 * `caifrazier/wp-plugins-shared/tests/bootstrap.php`.
 *
 * Single source of truth, used by BOTH the pruner that removes these files
 * from the staged tree and the guard that verifies they are gone. That is
 * deliberate: the original leak (WP9) existed because the guard exempted
 * vendor/ wholesale, so nothing enforced what shipped inside it. Two lists
 * would drift back into the same blind spot.
 *
 * Conservative by design. Third-party packages own their layout, so this
 * denies only paths that are unambiguously non-runtime everywhere. LICENSE
 * and README.md are deliberately KEPT (license text can be legally required
 * to accompany distribution), as are src/, composer.json, and the generated
 * vendor/composer/ autoloader files.
 */
function isVendorDevArtifact( vendorRelPath ) {
	const denied = [
		// Test suites and their configs.
		/(^|\/)tests?(\/|$)/i,
		/(^|\/)spec(\/|$)/i,
		/(^|\/)php(unit|cs)\.xml(\.dist)?$/,
		// Lockfiles pin dev deps and are never read at runtime.
		/(^|\/)composer\.lock$/,
		// Internal planning docs. PRELAUNCH.md is a CF-internal living
		// document; it must never reach a public download.
		/(^|\/)PRELAUNCH\.md$/,
		// CI config and any dotfile/dotdir — .github/, .gitignore,
		// .gitattributes, .editorconfig, caches. None are runtime code.
		/(^|\/)\.[^/]+(\/|$)/,
	];
	return denied.some( ( pattern ) => pattern.test( vendorRelPath ) );
}

/**
 * Remove development artifacts from a STAGED vendor/ tree.
 *
 * Operates only on the throwaway staging copy — never the working tree — so
 * the developer's installed dev dependencies are untouched. Returns the list
 * of removed paths (relative to the vendor root) for the build log.
 */
function pruneVendorDevArtifacts( vendorRoot ) {
	if ( ! fs.existsSync( vendorRoot ) ) {
		return [];
	}
	const removed = [];
	const walk = ( absDir, relDir ) => {
		for ( const dirent of fs.readdirSync( absDir, { withFileTypes: true } ) ) {
			const abs = path.join( absDir, dirent.name );
			const rel = relDir ? `${ relDir }/${ dirent.name }` : dirent.name;
			if ( isVendorDevArtifact( rel ) ) {
				fs.rmSync( abs, { recursive: true, force: true } );
				removed.push( rel );
				continue;
			}
			if ( dirent.isDirectory() ) {
				walk( abs, rel );
			}
		}
	};
	walk( vendorRoot, '' );
	return removed;
}

function assertNoDevArtifacts( zipPath ) {
	const listing = execFileSync( 'unzip', [ '-l', zipPath ], { encoding: 'utf8' } );
	// Dev-artifact patterns. These target the PLUGIN'S OWN tree — they are
	// NOT applied under a bundled vendor/, whose internal layout (its own
	// src/, composer.json, dotfiles, etc.) is the dependency's business and
	// is legitimate runtime code for composerRuntime plugins.
	//
	// composer.json: for plugins WITHOUT composerRuntime it's purely a dev
	// artifact and is denied. For plugins WITH composerRuntime, WordPress.org
	// Plugin Check warns when vendor/ is present without composer.json next
	// to it, so we ship it alongside the bundled vendor/. composer.lock is
	// always denied — it pins dev-deps and serves no runtime purpose.
	const composerJsonPattern = config.composerRuntime
		? /\/composer\.lock$/
		: /\/composer\.(json|lock)$/;
	const denied = [
		/\/node_modules\//,
		/\/src\//,
		/\/tests\//,
		// Any path segment beginning with a dot — .gitignore, .DS_Store,
		// .github/, .editorconfig, .phpcs.cache, .phpunit.result.cache, etc.
		// Release zips ship no legitimate dotfiles.
		/\/\.[^/]+(\/|$)/,
		composerJsonPattern,
		/\/package(-lock)?\.json$/,
		/\/php(cs|unit)\.xml\.dist$/,
		/\/webpack\.config\.js$/,
		/\.zip$/,
	];
	// Date column format is locale/implementation dependent: GNU unzip emits
	// YYYY-MM-DD, macOS/Info-ZIP emits MM-DD-YYYY. Match the date token
	// format-agnostically (length, any date token, HH:MM time, then name).
	const entries = listing
		.split( '\n' )
		.map( ( line ) => line.match( /^\s*\d+\s+\S+\s+\d{1,2}:\d{2}\s+(.+)$/ ) )
		.filter( Boolean )
		.map( ( match ) => match[ 1 ] );
	// A valid plugin zip always lists files. Zero parsed entries means the
	// listing format drifted and the guard would silently pass everything —
	// fail loudly instead of providing a false sense of security.
	if ( entries.length === 0 ) {
		throw new Error(
			`Could not parse any entries from "unzip -l" output — the artifact guard cannot verify the zip:\n${ listing }`
		);
	}
	const isVendor = ( entry ) => /(^|\/)vendor\//.test( entry );
	const vendorEntries = entries.filter( isVendor );
	const ownEntries = entries.filter( ( e ) => ! isVendor( e ) );

	const badLines = ownEntries.filter( ( entry ) => denied.some( ( pattern ) => pattern.test( entry ) ) );

	// vendor/ is shipped only for plugins that bundle runtime Composer deps
	// (installed --no-dev above). For every other plugin, any vendor/ entry
	// means a dev tree leaked into the zip.
	if ( ! config.composerRuntime ) {
		badLines.push( ...vendorEntries );
	} else {
		// For composerRuntime plugins the bundled tree IS legitimate runtime
		// code, but "legitimate" is not "unexamined" — this guard used to skip
		// vendor/ entirely, which is how a dependency's tests/ and an internal
		// PRELAUNCH.md shipped in four release zips (WP9). Check the vendored
		// tree against the same predicate the pruner uses, so anything the
		// pruner misses fails the build instead of reaching users.
		badLines.push(
			...vendorEntries.filter( ( entry ) => {
				const match = entry.match( /(?:^|\/)vendor\/(.+)$/ );
				return match ? isVendorDevArtifact( match[ 1 ] ) : false;
			} )
		);
	}
	if ( badLines.length > 0 ) {
		throw new Error( `Release zip contains development artifacts:\n${ badLines.join( '\n' ) }` );
	}
}

assertRequiredTools();
// Cheap and fails fast — run before tests so a forgotten directory is caught
// in seconds rather than after a full suite.
assertManifestCoverage();
lintPhpFiles();
runAvailableTests();

if ( config.npmBuild ) {
	run( 'pnpm', [ 'run', 'build' ], pluginRoot );
}

if ( config.composerRuntime ) {
	run( 'composer', [ 'install', '--no-dev', '--optimize-autoloader' ], pluginRoot );
}

// Refresh the translation template from current source before it gets staged
// into the zip via the languages/ rootDir.
regeneratePotFile();

const version = readVersion();
assertVersionConsistency( version );
const distDir = path.join( pluginRoot, 'dist' );
fs.rmSync( distDir, { recursive: true, force: true } );
fs.mkdirSync( distDir, { recursive: true } );

const tmpStage = fs.mkdtempSync( path.join( distDir, '.stage-' ) );
const stageDir = path.join( tmpStage, config.zipSlug );
fs.mkdirSync( stageDir );

try {
	for ( const file of config.rootFiles ) {
		copyRequiredFile( file, stageDir );
	}
	for ( const dir of config.rootDirs ) {
		copyRequiredDir( dir, stageDir );
	}

	// Strip dev artifacts out of the bundled dependency tree before zipping.
	// composer install --no-dev drops dev PACKAGES but keeps each retained
	// package's own tests/, phpunit config, and docs — those are files within
	// a runtime package, so Composer has no reason to touch them.
	if ( config.composerRuntime ) {
		const pruned = pruneVendorDevArtifacts( path.join( stageDir, 'vendor' ) );
		if ( pruned.length > 0 ) {
			console.log( `Pruned ${ pruned.length } dev artifact(s) from bundled vendor/:` );
			for ( const entry of pruned ) {
				console.log( `  - vendor/${ entry }` );
			}
		}
	}

	const zipName = `${ config.zipSlug }-${ version }.zip`;
	const zipPath = path.join( distDir, zipName );
	execSync( `zip -r -q "${ zipPath }" "${ config.zipSlug }"`, { cwd: tmpStage, stdio: 'inherit' } );
	assertNoDevArtifacts( zipPath );

	const sizeKb = ( fs.statSync( zipPath ).size / 1024 ).toFixed( 1 );
	console.log( `Built ${ path.relative( pluginsRoot, zipPath ) } (${ sizeKb } KB)` );
} finally {
	fs.rmSync( tmpStage, { recursive: true, force: true } );
}
