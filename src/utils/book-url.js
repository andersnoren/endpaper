const BOOK_URL =
	/^https?:\/\/(?:www\.)?hardcover\.app\/books\/[a-z0-9_-]+(?:[/?#]\S*)?$/i;

/**
 * Returns the link to a book on Hardcover that a piece of text is, or an empty string.
 *
 * @param {string} text The text.
 * @return {string} The link, or an empty string.
 */
export default function getBookUrl( text ) {
	const url = ( text || '' ).trim();

	return BOOK_URL.test( url ) ? url : '';
}
