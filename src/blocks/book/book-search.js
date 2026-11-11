/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SearchControl, Spinner } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * How long to wait after the last keystroke before searching, in milliseconds.
 */
const SEARCH_DELAY = 400;

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

	return (
		<div className="endpaper-book-search">
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
									<strong>{ book.title }</strong>
									<span className="endpaper-book-search__details">
										{ [
											( book.authors || [] ).join( ', ' ),
											book.year || '',
										]
											.filter( Boolean )
											.join( ' · ' ) }
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
