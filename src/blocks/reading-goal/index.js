/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import BlockIcon from '../../components/block-icon';
import Edit from './edit';
import metadata from './block.json';
import './style.scss';

// The block is dynamic: it is rendered on the server by render.php, so there is no save function.
registerBlockType( metadata.name, {
	icon: <BlockIcon name="reading-goal" />,
	edit: Edit,
} );
