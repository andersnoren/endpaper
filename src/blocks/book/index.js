/**
 * WordPress dependencies
 */
import { createBlock, registerBlockType } from '@wordpress/blocks';
/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- WordPress provides these packages in the editor, and the build leaves them out. */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useDispatch } from '@wordpress/data';
/* eslint-enable import/no-extraneous-dependencies, import/no-unresolved */
import { useEffect } from '@wordpress/element';
import { store as blockEditorStore } from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import Edit from './edit';
import getBookUrl from '../../utils/book-url';
import metadata from './block.json';
import './style.scss';

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

/**
 * Puts a Book block in place of an Embed block that is given a link to a book on Hardcover.
 *
 * Hardcover has no embeds, so the Embed block could only turn the link into
 * a plain link. This is for the Embed block added from the inserter, where the
 * link is typed or pasted into its field.
 */
const withBookLink = createHigherOrderComponent(
	( BlockEdit ) =>
		function EndpaperBookLink( props ) {
			const { replaceBlocks } = useDispatch( blockEditorStore );
			const url =
				props.name === 'core/embed'
					? getBookUrl( props.attributes?.url )
					: '';

			useEffect( () => {
				if ( url ) {
					replaceBlocks(
						props.clientId,
						createBlock( metadata.name, { bookUrl: url } )
					);
				}
			}, [ url, props.clientId, replaceBlocks ] );

			return <BlockEdit { ...props } />;
		},
	'withBookLink'
);

addFilter( 'editor.BlockEdit', 'endpaper/book-link', withBookLink );
