/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Describes an edition in a few words, for people who cannot see its cover.
 *
 * @param {Object} edition An edition, as returned by the REST route.
 * @return {string} The edition's format, year and language, where known.
 */
function getEditionLabel( edition ) {
	const parts = [ edition.format, edition.year, edition.language ].filter(
		Boolean
	);

	return parts.length ? parts.join( ', ' ) : edition.title;
}

/**
 * A grid of edition covers to pick from.
 *
 * The editions come from the plugin's own REST route, which keeps the
 * Hardcover API token on the server. They are only fetched when this is
 * shown, which is when its panel is opened.
 *
 * @param {Object}                               props          Component props.
 * @param {number}                               props.bookId   Hardcover ID of the book.
 * @param {number|undefined}                     props.value    Hardcover ID of the picked edition, if any.
 * @param {(editionId: number|undefined)=> void} props.onChange Called with the picked edition's ID, or nothing to go back to the default.
 * @return {Element} The picker element.
 */
export default function EditionPicker( { bookId, value, onChange } ) {
	const [ editions, setEditions ] = useState( [] );
	const [ status, setStatus ] = useState( 'loading' );
	const [ message, setMessage ] = useState( '' );

	useEffect( () => {
		let isCurrent = true;

		setStatus( 'loading' );

		apiFetch( { path: `/endpaper/v1/editions/${ bookId }` } )
			.then( ( result ) => {
				if ( isCurrent ) {
					setEditions( Array.isArray( result ) ? result : [] );
					setStatus( 'done' );
				}
			} )
			.catch( ( error ) => {
				if ( isCurrent ) {
					setMessage(
						error?.message ||
							__(
								'The editions could not be loaded.',
								'endpaper'
							)
					);
					setStatus( 'error' );
				}
			} );

		return () => {
			isCurrent = false;
		};
	}, [ bookId ] );

	return (
		<div className="endpaper-edition-picker">
			<p className="endpaper-edition-picker__help">
				{ __(
					'The block shows the cover of the edition in your Hardcover library, or else the book’s main cover. Pick another edition to use its cover.',
					'endpaper'
				) }
			</p>

			{ status === 'loading' && <Spinner /> }

			{ status === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ message }
				</Notice>
			) }

			{ status === 'done' && editions.length === 0 && (
				<p>
					{ __( 'No editions with a cover were found.', 'endpaper' ) }
				</p>
			) }

			{ status === 'done' && editions.length > 0 && (
				<ul className="endpaper-edition-picker__list">
					{ editions.map( ( edition ) => (
						<li key={ edition.id }>
							<Button
								className="endpaper-edition-picker__edition"
								label={ getEditionLabel( edition ) }
								showTooltip
								isPressed={ edition.id === value }
								onClick={ () => onChange( edition.id ) }
							>
								<img
									src={ edition.cover }
									alt=""
									loading="lazy"
								/>
							</Button>
						</li>
					) ) }
				</ul>
			) }

			{ !! value && (
				<Button
					variant="secondary"
					__next40pxDefaultSize
					onClick={ () => onChange( undefined ) }
				>
					{ __( 'Use the default cover', 'endpaper' ) }
				</Button>
			) }
		</div>
	);
}
