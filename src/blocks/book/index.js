/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import Edit from './edit';
import metadata from './block.json';
import './style.scss';

// The block is dynamic: it is rendered on the server by render.php, so there is no save function.
registerBlockType( metadata.name, {
	edit: Edit,
} );
