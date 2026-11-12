/**
 * WordPress dependencies
 */
import { createBlock, registerBlockType } from '@wordpress/blocks';
/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- WordPress provides these packages in the editor, and the build leaves them out. */
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { useDispatch } from '@wordpress/data';
/* eslint-enable import/no-extraneous-dependencies, import/no-unresolved */
import { Path, SVG } from '@wordpress/components';
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
	// The block's own icon, drawn to take the text color of wherever it is shown.
	icon: (
		<SVG
			width="20"
			height="20"
			viewBox="0 0 20 20"
			xmlns="http://www.w3.org/2000/svg"
		>
			<Path
				fillRule="evenodd"
				clipRule="evenodd"
				fill="currentColor"
				d="M14.943 1C15.5268 1.00002 16 1.44809 16 2.00071V14.1422C16 14.1467 15.9998 14.1512 15.9998 14.1557L16 17.9993C16 18.552 15.5268 19 14.943 19H5.11377C3.94639 19 3 18.104 3 16.9988V3.00122C3 1.89601 3.94641 1.00004 5.11377 1H14.943ZM5.65814 15.1429C4.90813 15.1429 4.30015 15.7186 4.30018 16.4286C4.3002 17.1387 4.90815 17.7143 5.65814 17.7143H14.7V15.1429H5.65814Z"
			/>
		</SVG>
	),
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
