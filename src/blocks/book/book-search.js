/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SearchControl, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import getBookUrl from '../../utils/book-url';

/**
 * How long to wait after the last keystroke before searching, in milliseconds.
 */
const SEARCH_DELAY = 400;

/**
 * Wraps each match of the search words in <mark>, without using HTML strings.
 *
 * @param {string} text Text to show.
 * @param {string} term What the user typed.
 * @return {Array|string} Text and <mark> elements.
 */
function highlight( text, term ) {
	const escape = ( value ) => value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const phrase = term.trim().replace( /\s+/g, ' ' );

	if ( ! phrase || getBookUrl( term ) ) {
		return text;
	}

	// The whole phrase if the text has it, otherwise each word on its own.
	const pattern = new RegExp( escape( phrase ), 'i' ).test( text )
		? escape( phrase )
		: phrase.split( ' ' ).map( escape ).join( '|' );

	// A capture group makes split() keep the matches, at odd indexes.
	return text
		.split( new RegExp( `(${ pattern })`, 'i' ) )
		.map( ( part, index ) =>
			index % 2 ? <mark key={ index }>{ part }</mark> : part
		);
}

/**
 * Search field and result list, shown until a book is picked.
 *
 * Searches go through the plugin's own REST route, which keeps the Hardcover
 * API token on the server.
 *
 * @param {Object}                   props          Component props.
 * @param {(bookId: number) => void} props.onSelect Called with the picked book's Hardcover ID.
 * @return {Element} The search element.
 */
export default function BookSearch( { onSelect } ) {
	const [ term, setTerm ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ status, setStatus ] = useState( 'idle' );
	const [ message, setMessage ] = useState( '' );
	const latestRequest = useRef( 0 );

	useEffect( () => {
		const query = term.trim();
		const requestId = ++latestRequest.current;

		if ( query.length < 3 ) {
			setResults( [] );
			setStatus( 'idle' );
			return;
		}

		const timer = setTimeout( () => {
			setStatus( 'loading' );

			apiFetch( {
				path: addQueryArgs( '/endpaper/v1/search', { q: query } ),
			} )
				.then( ( books ) => {
					// Ignore responses to searches that have since been replaced.
					if ( requestId !== latestRequest.current ) {
						return;
					}

					setResults( Array.isArray( books ) ? books : [] );
					setStatus( 'done' );
				} )
				.catch( ( error ) => {
					if ( requestId !== latestRequest.current ) {
						return;
					}

					setResults( [] );
					setMessage(
						error?.message ||
							__(
								'Something went wrong while searching.',
								'endpaper'
							)
					);
					setStatus( 'error' );
				} );
		}, SEARCH_DELAY );

		return () => clearTimeout( timer );
	}, [ term ] );

	// Enter on a link to a book on Hardcover picks that book at once, without waiting for it to be listed.
	const pickLink = ( event ) => {
		const url = getBookUrl( term );

		if ( 'Enter' !== event.key || ! url ) {
			return;
		}

		event.preventDefault();

		// A search still under way is dropped.
		const requestId = ++latestRequest.current;
		setResults( [] );
		setStatus( 'loading' );

		apiFetch( {
			path: addQueryArgs( '/endpaper/v1/search', { q: url } ),
		} )
			.then( ( books ) => {
				if ( requestId !== latestRequest.current ) {
					return;
				}

				if ( Array.isArray( books ) && books[ 0 ]?.id ) {
					onSelect( books[ 0 ].id );
					return;
				}

				setMessage(
					__(
						'That book could not be found on Hardcover.',
						'endpaper'
					)
				);
				setStatus( 'error' );
			} )
			.catch( ( error ) => {
				if ( requestId !== latestRequest.current ) {
					return;
				}

				setMessage(
					error?.message ||
						__(
							'Something went wrong while searching.',
							'endpaper'
						)
				);
				setStatus( 'error' );
			} );
	};

	return (
		// The key press comes from the search field inside.
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div className="endpaper-book-search" onKeyDown={ pickLink }>
			<SearchControl
				__nextHasNoMarginBottom
				label={ __( 'Search for a book', 'endpaper' ) }
				placeholder={ __(
					'Title, author, ISBN or Hardcover link',
					'endpaper'
				) }
				value={ term }
				onChange={ setTerm }
			/>

			{ 'loading' === status && <Spinner /> }

			{ 'error' === status && (
				<Notice status="error" isDismissible={ false }>
					{ message }
				</Notice>
			) }

			{ 'done' === status && 0 === results.length && (
				<p>{ __( 'No books found.', 'endpaper' ) }</p>
			) }

			{ results.length > 0 && (
				<ul className="endpaper-book-search__results">
					{ results.map( ( book ) => (
						<li key={ book.id }>
							<Button
								className="endpaper-book-search__result"
								onClick={ () => onSelect( book.id ) }
							>
								{ book.cover ? (
									<img
										className="endpaper-book-search__cover"
										src={ book.cover }
										alt=""
									/>
								) : (
									<span className="endpaper-book-search__cover" />
								) }
								<span className="endpaper-book-search__text">
									<strong>
										{ highlight( book.title, term ) }
									</strong>
									<span className="endpaper-book-search__details">
										{ highlight(
											( book.authors || [] ).join( ', ' ),
											term
										) }
										{ book.year
											? ( book.authors || [] ).length >
													0 && ' · '
											: '' }
										{ book.year ? book.year : '' }
									</span>
								</span>
							</Button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
