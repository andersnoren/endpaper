/**
 * WordPress dependencies
 */
import { Path, SVG } from '@wordpress/components';

/**
 * A book. The editor canvas does not load the Dashicons font, so the
 * placeholder in it needs an icon that is drawn, not a font character.
 */
export const bookIcon = (
	<SVG
		xmlns="http://www.w3.org/2000/svg"
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth="1.5"
		strokeLinecap="round"
		strokeLinejoin="round"
	>
		<Path
			fill="none"
			d="M7 4.75h11.25v14.5H7A1.75 1.75 0 0 1 5.25 17.5v-11A1.75 1.75 0 0 1 7 4.75z"
		/>
		<Path fill="none" d="M5.25 17.5A1.75 1.75 0 0 1 7 15.75h11.25" />
	</SVG>
);
