const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		editor: path.resolve( __dirname, 'src/editor/index.js' ),
		settings: path.resolve( __dirname, 'src/settings/index.js' ),
		diagnostics: path.resolve( __dirname, 'src/diagnostics/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
