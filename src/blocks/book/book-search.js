/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Notice,
	Path,
	SearchControl,
	SVG,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
/* eslint-disable import/no-unresolved -- WordPress provides this package in the editor, and the build leaves it out. */
import { speak } from '@wordpress/a11y';
/* eslint-enable import/no-unresolved */

/**
 * Internal dependencies
 */
import getBookUrl from '../../utils/book-url';
import getEditorData from '../../utils/editor-data';
import { addRecentBook, getRecentBooks } from '../../utils/recent-books';

/**
 * How long to wait after the last keystroke before searching, in milliseconds.
 */
const SEARCH_DELAY = 400;

/**
 * How many pages of results can be loaded, the same as the server allows.
 */
const MAX_PAGES = 10;

/**
 * The REST path for a page of results: a search, or the books of an author.
 *
 * @param {Object}      args        What to ask for.
 * @param {string}      args.term   What was typed in the search field.
 * @param {Object|null} args.author The author whose books are listed, if one was picked.
 * @param {number}      args.page   Which page of results.
 * @return {string} The path.
 */
function getSearchPath( { term, author, page = 1 } ) {
	return addQueryArgs(
		'/endpaper/v1/search',
		author ? { author: author.id, page } : { q: term, page }
	);
}

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
 * A result's cover. A missing or broken image falls back to the plain box.
 *
 * @param {Object} props       Component props.
 * @param {string} props.cover Image URL, if the book has one.
 * @return {Element} The cover element.
 */
function Cover( { cover } ) {
	const [ failed, setFailed ] = useState( false );

	if ( ! cover || failed ) {
		return <span className="endpaper-book-search__cover" />;
	}

	return (
		<img
			className="endpaper-book-search__cover endpaper-book-search__cover--image"
			src={ cover }
			alt=""
			onError={ () => setFailed( true ) }
		/>
	);
}

/**
 * The year and the page count of a result, for the line under the title.
 *
 * @param {Object} book A search result.
 * @return {string[]} The facts the book has.
 */
function getFacts( book ) {
	const facts = [];

	if ( book.year ) {
		facts.push( String( book.year ) );
	}

	if ( book.pages ) {
		facts.push(
			sprintf(
				/* translators: %s: number of pages. */
				_n( '%s page', '%s pages', book.pages, 'endpaper' ),
				book.pages.toLocaleString(
					document.documentElement.lang || undefined
				)
			)
		);
	}

	return facts;
}

/**
 * A list of books to pick from.
 *
 * @param {Object}                 props        Component props.
 * @param {Array}                  props.books  The books to list.
 * @param {string}                 props.term   What was searched for, to highlight in the list.
 * @param {(book: Object) => void} props.onPick Called with the book that was clicked.
 * @return {Element} The list.
 */
function ResultList( { books, term, onPick } ) {
	return (
		<ul className="endpaper-book-search__results">
			{ books.map( ( book ) => (
				<li key={ book.id }>
					<Button
						className="endpaper-book-search__result"
						onClick={ () => onPick( book ) }
					>
						<Cover cover={ book.cover } />
						<span className="endpaper-book-search__text">
							<span>{ highlight( book.title, term ) }</span>
							<span className="endpaper-book-search__details">
								{ highlight(
									( book.authors || [] ).join( ', ' ),
									term
								) }
								{ getFacts( book ).length > 0 &&
									( book.authors || [] ).length > 0 &&
									' · ' }
								{ getFacts( book ).join( ' · ' ) }
							</span>
						</span>
					</Button>
				</li>
			) ) }
		</ul>
	);
}

/**
 * Search field and result list, shown until a book is picked.
 *
 * Searches go through the plugin's own REST route, which keeps the Hardcover
 * API token on the server.
 *
 * @param {Object}                   props              Component props.
 * @param {(bookId: number) => void} props.onSelect     Called with the picked book's Hardcover ID.
 * @param {boolean}                  props.focusOnMount Whether to put the cursor in the search field when it appears.
 * @return {Element} The search element.
 */
export default function BookSearch( { onSelect, focusOnMount = false } ) {
	const [ term, setTerm ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ status, setStatus ] = useState( 'idle' );
	const [ message, setMessage ] = useState( '' );
	const [ hasMore, setHasMore ] = useState( false );
	const [ loadingMore, setLoadingMore ] = useState( false );
	// The author a search term is the name of, and the author whose books are listed instead of the search.
	const [ authorMatch, setAuthorMatch ] = useState( null );
	const [ author, setAuthor ] = useState( null );
	const latestRequest = useRef( 0 );
	const loadedPages = useRef( 1 );
	const focusAfterLoad = useRef( null );
	const focusAfterSwitch = useRef( null );
	const savedSearch = useRef( null );
	const wrapper = useRef( null );
	// A full page of results means there may be more, and is also how many
	// placeholder rows to show, so the list does not change height.
	const { searchResults } = getEditorData();
	const [ recent, setRecent ] = useState( getRecentBooks );

	useEffect( () => {
		if ( focusOnMount ) {
			wrapper.current?.querySelector( 'input[type="search"]' )?.focus();
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps -- Only when the search field first appears.

	// Picks a book from a list, and remembers it for next time.
	const pick = ( book ) => {
		setRecent( addRecentBook( book ) );
		onSelect( book.id );
	};

	const showRecent =
		term.trim().length < 3 && 'idle' === status && recent.length > 0;

	useEffect( () => {
		const query = term.trim();
		const requestId = ++latestRequest.current;

		loadedPages.current = 1;
		savedSearch.current = null;
		setAuthor( null );
		setAuthorMatch( null );
		setHasMore( false );
		setLoadingMore( false );

		if ( query.length < 3 ) {
			setResults( [] );
			setStatus( 'idle' );
			return;
		}

		const timer = setTimeout( () => {
			setStatus( 'loading' );

			// A name of two words or more may be an author, which is asked about at the same time, so the list does not move when it arrives.
			const mayBeAuthor =
				query.split( /\s+/ ).length > 1 && ! getBookUrl( query );

			Promise.all( [
				apiFetch( { path: getSearchPath( { term: query } ) } ),
				mayBeAuthor
					? apiFetch( {
							path: addQueryArgs( '/endpaper/v1/authors', {
								q: query,
							} ),
						} ).catch( () => null )
					: null,
			] )
				.then( ( [ books, found ] ) => {
					// Ignore responses to searches that have since been replaced.
					if ( requestId !== latestRequest.current ) {
						return;
					}

					const list = Array.isArray( books ) ? books : [];

					setAuthorMatch( found?.id ? found : null );
					setResults( list );
					setHasMore( list.length >= searchResults );
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
	}, [ term, searchResults ] );

	// Tell screen reader users what the search is doing.
	useEffect( () => {
		if ( 'loading' === status ) {
			speak( __( 'Searching…', 'endpaper' ) );
		} else if ( 'done' === status ) {
			speak(
				results.length
					? sprintf(
							/* translators: %d: number of books found. */
							_n(
								'%d book found.',
								'%d books found.',
								results.length,
								'endpaper'
							),
							results.length
						)
					: __( 'No books found.', 'endpaper' )
			);
		}
	}, [ status, results.length ] );

	// Adds the next page of results to the list. When the button was used
	// with the keyboard, focus moves to the first new book, so the next Tab or
	// arrow key continues from there, not from the button that moved down.
	const loadMore = ( event ) => {
		if ( loadingMore ) {
			return;
		}

		const requestId = latestRequest.current;
		const page = loadedPages.current + 1;
		const viaKeyboard = 0 === event?.detail;
		const listed = results.length;

		setLoadingMore( true );

		apiFetch( {
			path: getSearchPath( { term: term.trim(), author, page } ),
		} )
			.then( ( books ) => {
				// Ignore the response if the search has since changed.
				if ( requestId !== latestRequest.current ) {
					return;
				}

				const list = Array.isArray( books ) ? books : [];

				loadedPages.current = page;

				if ( viaKeyboard ) {
					focusAfterLoad.current = listed;
				}

				setResults( ( current ) => {
					const known = new Set( current.map( ( book ) => book.id ) );

					return [
						...current,
						...list.filter( ( book ) => ! known.has( book.id ) ),
					];
				} );
				setHasMore( list.length >= searchResults && page < MAX_PAGES );
				setLoadingMore( false );
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
				setLoadingMore( false );
			} );
	};

	// Lists the books by the author a search found, in place of the search results.
	const openAuthor = ( match ) => {
		const requestId = ++latestRequest.current;

		savedSearch.current = {
			results,
			hasMore,
			pages: loadedPages.current,
		};
		loadedPages.current = 1;
		focusAfterSwitch.current = '.endpaper-book-search__back';

		setAuthor( match );
		setResults( [] );
		setHasMore( false );
		setLoadingMore( false );
		setStatus( 'loading' );

		apiFetch( {
			path: getSearchPath( { term: term.trim(), author: match } ),
		} )
			.then( ( books ) => {
				if ( requestId !== latestRequest.current ) {
					return;
				}

				const list = Array.isArray( books ) ? books : [];

				setResults( list );
				setHasMore( list.length >= searchResults );
				setStatus( 'done' );
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

	// Goes back from an author's books to the search results they were found in.
	const closeAuthor = () => {
		const saved = savedSearch.current;

		// A request for the author's books that has not arrived is dropped.
		latestRequest.current++;
		savedSearch.current = null;
		focusAfterSwitch.current = '.endpaper-book-search__author';

		setAuthor( null );
		setLoadingMore( false );
		setResults( saved ? saved.results : [] );
		setHasMore( saved ? saved.hasMore : false );
		loadedPages.current = saved ? saved.pages : 1;
		setStatus( 'done' );
	};

	// After switching between the search and an author's books, focus goes to the control for going the other way.
	useEffect( () => {
		if ( ! focusAfterSwitch.current || 'loading' === status ) {
			return;
		}

		wrapper.current?.querySelector( focusAfterSwitch.current )?.focus();
		focusAfterSwitch.current = null;
	}, [ author, status ] );

	// Moves focus to the first book that was just added, if the list asked for it.
	useEffect( () => {
		const index = focusAfterLoad.current;

		if ( null === index ) {
			return;
		}

		focusAfterLoad.current = null;

		wrapper.current
			?.querySelectorAll(
				'.endpaper-book-search__results:not(.endpaper-book-search__results--loading) .endpaper-book-search__result'
			)
			?.[ index ]?.focus();
	}, [ results ] );

	// Arrow keys move between the search field and the results.
	const moveFocus = ( event ) => {
		if ( 'ArrowDown' !== event.key && 'ArrowUp' !== event.key ) {
			return;
		}

		const items = Array.from(
			wrapper.current?.querySelectorAll(
				'.endpaper-book-search__back, .endpaper-book-search__author, .endpaper-book-search__results:not(.endpaper-book-search__results--loading) button, .endpaper-book-search__more'
			) || []
		);

		if ( ! items.length ) {
			return;
		}

		const index = items.indexOf( event.target );
		const next = 'ArrowDown' === event.key ? index + 1 : index - 1;

		if ( -1 === index && 'ArrowUp' === event.key ) {
			return;
		}

		event.preventDefault();

		if ( next < 0 ) {
			wrapper.current.querySelector( 'input[type="search"]' )?.focus();
		} else if ( next < items.length ) {
			items[ next ].focus();
		}
	};

	// Enter on a link to a book on Hardcover picks that book at once, without waiting for it to be listed.
	const pickLink = ( event ) => {
		moveFocus( event );

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
					addRecentBook( books[ 0 ] );
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
		<div
			className="endpaper-book-search"
			ref={ wrapper }
			onKeyDown={ pickLink }
		>
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

			{ author && (
				<div className="endpaper-book-search__scope">
					<Button
						variant="tertiary"
						className="endpaper-book-search__back"
						onClick={ closeAuthor }
					>
						{ __( 'Back to results', 'endpaper' ) }
					</Button>
					<h3 className="endpaper-book-search__heading">
						{ sprintf(
							/* translators: %s: an author's name. */
							__( 'Books by %s', 'endpaper' ),
							author.name
						) }
					</h3>
				</div>
			) }

			{ ! author && 'done' === status && authorMatch && (
				<Button
					className="endpaper-book-search__result endpaper-book-search__author"
					onClick={ () => openAuthor( authorMatch ) }
				>
					<span className="endpaper-book-search__text">
						<span>
							{ sprintf(
								/* translators: %s: an author's name. */
								__( 'Books by %s', 'endpaper' ),
								authorMatch.name
							) }
						</span>
						<span className="endpaper-book-search__details">
							{ authorMatch.books
								? sprintf(
										/* translators: %s: number of books. */
										_n(
											'Author · %s book',
											'Author · %s books',
											authorMatch.books,
											'endpaper'
										),
										authorMatch.books.toLocaleString(
											document.documentElement.lang ||
												undefined
										)
									)
								: __( 'Author', 'endpaper' ) }
						</span>
					</span>
					<SVG
						className="endpaper-book-search__chevron"
						xmlns="http://www.w3.org/2000/svg"
						viewBox="0 0 24 24"
						width="24"
						height="24"
						aria-hidden="true"
						focusable="false"
					>
						<Path d="M10.6 6L9.4 7l4.6 5-4.6 5 1.2 1L16 12z" />
					</SVG>
				</Button>
			) }

			{ 'loading' === status && (
				<ul
					className="endpaper-book-search__results endpaper-book-search__results--loading"
					aria-hidden="true"
				>
					{ Array.from( { length: searchResults }, ( _, row ) => (
						<li
							key={ row }
							className="endpaper-book-search__result"
						>
							<span className="endpaper-book-search__cover" />
							<span className="endpaper-book-search__text">
								<span className="endpaper-book-search__line" />
								<span className="endpaper-book-search__line endpaper-book-search__line--short" />
							</span>
						</li>
					) ) }
				</ul>
			) }

			{ 'error' === status && (
				<Notice status="error" isDismissible={ false }>
					{ message }
				</Notice>
			) }

			{ 'done' === status && 0 === results.length && (
				<p className="endpaper-book-search__empty">
					{ author
						? __( 'No books found for this author.', 'endpaper' )
						: __(
								'No books found. Try the author’s name or an ISBN, or paste a link to the book’s Hardcover page.',
								'endpaper'
							) }
				</p>
			) }

			{ 'loading' !== status && results.length > 0 && (
				<ResultList
					books={ results }
					term={ author ? '' : term }
					onPick={ pick }
				/>
			) }

			{ 'loading' !== status && results.length > 0 && hasMore && (
				<Button
					variant="secondary"
					className="endpaper-book-search__more"
					onClick={ loadMore }
					isBusy={ loadingMore }
					aria-disabled={ loadingMore }
				>
					{ loadingMore
						? __( 'Loading…', 'endpaper' )
						: __( 'Load more', 'endpaper' ) }
				</Button>
			) }

			{ showRecent && (
				<>
					<h3 className="endpaper-book-search__heading">
						{ __( 'Recent books', 'endpaper' ) }
					</h3>
					<ResultList books={ recent } term="" onPick={ pick } />
				</>
			) }
		</div>
	);
}
