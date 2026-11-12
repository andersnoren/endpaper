/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Works out the days left again, in the visitor's browser.
 *
 * The server writes the days left into the page, and a page that is kept
 * and served for days would go on showing the number of the day it was kept.
 * The end date and the text for a goal that has ended are in the markup.
 */
document
	.querySelectorAll( '.endpaper-reading-goal__status[data-end]' )
	.forEach( ( element ) => {
		const [ year, month, day ] = element.dataset.end
			.split( '-' )
			.map( Number );
		const today = new Date();
		today.setHours( 0, 0, 0, 0 );

		// Rounded, as a day is 23 or 25 hours long where the clocks change.
		const days = Math.round(
			( new Date( year, month - 1, day ) - today ) / 86400000
		);

		if ( days > 0 ) {
			element.textContent = sprintf(
				/* translators: %s: Number of days. */
				_n( '%s day left', '%s days left', days, 'endpaper' ),
				days.toLocaleString(
					document.documentElement.lang || undefined
				)
			);
		} else if ( 0 === days ) {
			element.textContent = __( 'Ends today', 'endpaper' );
		} else {
			element.textContent = element.dataset.ended;
		}
	} );
