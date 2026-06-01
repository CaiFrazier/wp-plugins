/**
 * Multi-entry build on top of @wordpress/scripts' default config. Each entry
 * maps to build/<name>.js + <name>.asset.php, which Admin.php enqueues by
 * screen (settings / importer / media-hook).
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		settings: './src/settings.js',
		importer: './src/importer.js',
		'media-hook': './src/media-hook.js',
	},
};
