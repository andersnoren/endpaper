/**
 * The books most recently picked in this browser, shown when a search is empty.
 *
 * They are kept in the browser's local storage, so they are per person and
 * never reach the server. Storage can be blocked or full, so every access is
 * wrapped, and a failure only means there is nothing to show.
 */

const STORAGE_KEY = 'endpaper-recent-books';
const MAX_BOOKS = 5;

/**
 * Gets the recently picked books, the latest first.
 *
 * @return {Array} Books with an id, title, authors, year and cover.
 */
export function getRecentBooks() {
	try {
		const books = JSON.parse( window.localStorage.getItem( STORAGE_KEY ) );

		return Array.isArray( books )
			? books.filter( ( book ) => book?.id && book?.title )
			: [];
	} catch {
		return [];
	}
}

/**
 * Remembers a picked book, moving it to the front if it is already there.
 *
 * @param {Object} book A search result.
 * @return {Array} The books now remembered.
 */
export function addRecentBook( book ) {
	if ( ! book?.id || ! book?.title ) {
		return getRecentBooks();
	}

	const books = [
		{
			id: book.id,
			title: book.title,
			authors: book.authors || [],
			year: book.year || 0,
			cover: book.cover || '',
		},
		...getRecentBooks().filter( ( { id } ) => id !== book.id ),
	].slice( 0, MAX_BOOKS );

	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( books ) );
	} catch {
		// Nothing to do: the list just is not kept.
	}

	return books;
}
