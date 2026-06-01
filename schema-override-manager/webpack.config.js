const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'meta-box/index': path.resolve( __dirname, 'src/meta-box/index.js' ),
		'settings/index': path.resolve( __dirname, 'src/settings/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		filename: '[name].js',
	},
};
