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

/**
 * Fits the count in a gauge to the room inside the arc.
 *
 * The stylesheet makes a guess from the number of characters, as it cannot
 * know how wide the digits of the theme's font are. Here the count is
 * measured at the biggest size it may have, and made smaller if it is wider
 * than the arc has room for. That is 60% of the gauge, 26.5% at most in height.
 *
 * @param {Element} gauge The gauge.
 */
const fitCount = ( gauge ) => {
	const count = gauge.querySelector( '.endpaper-reading-goal__count' );
	const number = gauge.querySelector( '.endpaper-reading-goal__number' );
	const width = gauge.clientWidth;

	if ( ! count || ! number || ! width ) {
		return;
	}

	const biggest = width * 0.265;

	count.style.fontSize = `${ biggest }px`;

	const needed = number.getBoundingClientRect().width;
	const room = width * 0.6;

	count.style.fontSize = `${ biggest * Math.min( 1, room / needed ) }px`;
};

document
	.querySelectorAll( '.endpaper-reading-goal__gauge' )
	.forEach( ( gauge ) => {
		fitCount( gauge );

		// The font may load after the script has run, and the gauge may be resized.
		document.fonts?.ready.then( () => fitCount( gauge ) );

		if ( window.ResizeObserver ) {
			new window.ResizeObserver( () => fitCount( gauge ) ).observe(
				gauge
			);
		}
	} );
