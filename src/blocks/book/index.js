/**
 * WordPress dependencies
 */
import { createBlock, registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import Edit from './edit';
import metadata from './block.json';
import './style.scss';

const BOOK_URL =
	/^https?:\/\/(?:www\.)?hardcover\.app\/books\/[a-z0-9_-]+(?:[/?#]\S*)?$/i;

/**
 * Returns the link to a book on Hardcover that a piece of text is, or an empty string.
 *
 * @param {string} text The text.
 * @return {string} The link, or an empty string.
 */
function getBookUrl( text ) {
	const url = ( text || '' ).trim();

	return BOOK_URL.test( url ) ? url : '';
}

// The block is dynamic: it is rendered on the server by render.php, so there is no save function.
registerBlockType( metadata.name, {
	edit: Edit,
	transforms: {
		from: [
			{
				// A link to a book, pasted on a line of its own. It goes before the Embed block, which would otherwise take it.
				type: 'raw',
				priority: 1,
				isMatch: ( node ) =>
					node.nodeName === 'P' &&
					'' !== getBookUrl( node.textContent ),
				transform: ( node ) =>
					createBlock( metadata.name, {
						bookUrl: getBookUrl( node.textContent ),
					} ),
			},
		],
	},
} );
