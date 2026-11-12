/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';

/**
 * Keeps the images already on screen when the server-rendered preview is
 * replaced.
 *
 * The preview is swapped in as new HTML, so each image is made again and
 * blinks while it loads, even from the cache. When an image with the same
 * address arrives, the one already loaded takes its place and gets the new
 * one's attributes, so the preview updates without the images flashing.
 *
 * @param {Object} ref Ref to the element that holds the preview.
 */
export default function useKeepImages( ref ) {
	useEffect( () => {
		const element = ref.current;

		if ( ! element || ! window.MutationObserver ) {
			return undefined;
		}

		// The images on screen, by address.
		let known = new Map();

		const keep = () => {
			const current = new Map();

			element.querySelectorAll( 'img' ).forEach( ( fresh ) => {
				const src = fresh.currentSrc || fresh.src;
				const old = known.get( src );

				if ( old && old !== fresh && old.complete ) {
					Array.from( old.attributes ).forEach( ( { name } ) => {
						if ( ! fresh.hasAttribute( name ) ) {
							old.removeAttribute( name );
						}
					} );
					Array.from( fresh.attributes ).forEach(
						( { name, value } ) => {
							if ( old.getAttribute( name ) !== value ) {
								old.setAttribute( name, value );
							}
						}
					);
					fresh.replaceWith( old );
					current.set( src, old );
				} else {
					current.set( src, fresh );
				}
			} );

			known = current;
		};

		const observer = new window.MutationObserver( keep );
		observer.observe( element, { childList: true, subtree: true } );
		keep();

		return () => observer.disconnect();
	}, [ ref ] );
}
