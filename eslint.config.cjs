/**
 * ESLint configuration.
 *
 * Extends the default config from @wordpress/scripts. The packages listed
 * below are provided by WordPress at runtime and are not installed locally,
 * so the import rules need to be told that they exist.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		settings: {
			'import/core-modules': [
				'@wordpress/api-fetch',
				'@wordpress/block-editor',
				'@wordpress/blocks',
				'@wordpress/components',
				'@wordpress/element',
				'@wordpress/i18n',
				'@wordpress/server-side-render',
				'@wordpress/url',
			],
		},
	},
];
