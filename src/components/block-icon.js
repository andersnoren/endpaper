/**
 * WordPress dependencies
 */
import { Path, SVG } from '@wordpress/components';

/**
 * Internal dependencies
 */
import icons from '../../assets/icons.json';

/**
 * A block's icon, drawn from the paths in assets/icons.json.
 *
 * The same file gives the settings screen its icons, so the two cannot drift
 * apart. The icon takes the text color of wherever it is shown.
 *
 * @param {Object} props      Component props.
 * @param {string} props.name The icon's name in assets/icons.json.
 * @return {Element} The icon.
 */
export default function BlockIcon( { name } ) {
	return (
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
				d={ icons[ name ] }
			/>
		</SVG>
	);
}
