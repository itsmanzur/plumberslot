const base = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...base,
	moduleNameMapper: {
		...( base.moduleNameMapper || {} ),
		'^preact$': '<rootDir>/node_modules/preact/dist/preact.js',
		'^preact/hooks$': '<rootDir>/node_modules/preact/hooks/dist/hooks.js',
	},
};
