/*
 * The settings screen. The Copy block buttons are hidden until the browser can
 * copy to the clipboard, which it can only do on a secure page.
 */
( function () {
	'use strict';

	if ( ! navigator.clipboard || ! window.isSecureContext ) {
		return;
	}

	document.querySelectorAll( '.endpaper-copy' ).forEach( function ( button ) {
		const label = button.querySelector( '.endpaper-copy__label' );
		const original = label.textContent;
		let timer;

		button.hidden = false;
		button.setAttribute( 'aria-live', 'polite' );

		button.addEventListener( 'click', function () {
			navigator.clipboard
				.writeText( button.dataset.copy )
				.then( function () {
					clearTimeout( timer );
					label.textContent = button.dataset.copied;

					timer = setTimeout( function () {
						label.textContent = original;
					}, 2000 );
				} );
		} );
	} );
} )();
