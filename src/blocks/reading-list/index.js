/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Edit from './edit';
import metadata from './block.json';
import './style.scss';

/**
 * Whether a block instance matches a variation: it does when the status is the same.
 *
 * @param {Object} blockAttributes     Attributes of the block instance.
 * @param {Object} variationAttributes Attributes of the variation.
 * @return {boolean} Whether the variation is active.
 */
const isActive = ( blockAttributes, variationAttributes ) =>
	blockAttributes.status === variationAttributes.status;

// The block is dynamic: it is rendered on the server by render.php, so there is no save function.
registerBlockType( metadata.name, {
	edit: Edit,
	variations: [
		{
			name: 'currently-reading',
			title: __( 'Currently Reading', 'endpaper' ),
			description: __(
				'Show the books you are reading right now, from your Hardcover library.',
				'endpaper'
			),
			attributes: { status: 2 },
			isActive,
		},
		{
			name: 'recently-read',
			title: __( 'Recently Read', 'endpaper' ),
			description: __(
				'Show the books you finished most recently, from your Hardcover library.',
				'endpaper'
			),
			attributes: { status: 3 },
			isActive,
		},
		{
			name: 'want-to-read',
			title: __( 'Want to Read', 'endpaper' ),
			description: __(
				'Show books you want to read, from your Hardcover library.',
				'endpaper'
			),
			attributes: { status: 1 },
			isActive,
		},
	],
} );
