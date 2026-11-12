/**
 * WordPress dependencies
 */
import { Path, SVG } from '@wordpress/components';

/**
 * The Dashicons book, the icon the Book block has in the inserter and the
 * toolbar, drawn as an SVG. The editor canvas does not load the Dashicons
 * font, so the placeholder in it cannot use the font character.
 */
export const bookIcon = (
	<SVG xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
		<Path d="M16.02 3.01H18.01V18.98H5Q3.75 18.98 2.87 18.11Q1.99 17.23 1.99 16.02V3.98Q1.99 2.77 2.87 1.89Q3.75 1.02 5 1.02H13.98V15H5Q4.57 15 4.28 15.29Q3.98 15.59 3.98 16Q3.98 16.41 4.28 16.7Q4.57 16.99 5 16.99H16.02Z" />
	</SVG>
);
