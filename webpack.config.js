const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( process.cwd(), 'assets/src/admin/index.js' ),
		widget: path.resolve( process.cwd(), 'assets/src/widget/index.js' ),
		block: path.resolve( process.cwd(), 'assets/src/block/index.js' ),
		dashboard: path.resolve( process.cwd(), 'assets/src/dashboard/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( process.cwd(), 'assets/dist' ),
		filename: '[name].js',
	},
};
