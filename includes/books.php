<?php
/**
 * Books: looking up a book, its editions and covers, and searching Hardcover.
 *
 * @package Endpaper
 */

namespace Endpaper;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * GraphQL fields requested for a full book record.
 */
define( 'ENDPAPER_BOOK_FIELDS', 'id title slug description pages release_year rating ratings_count image { url } cached_contributors' );

// How many searches a person can make in a minute that are not answered from the cache.
define( 'ENDPAPER_SEARCH_LIMIT', 20 );

/**
 * Picks the main authors from a book's contributors.
 *
 * Illustrators, translators and the like are left out. A contribution that is
 * empty or says Author means the person is a main author. If nobody is, all of
 * the contributors are returned, so a book is never shown without a name.
 *
 * @param mixed $contributors A list of contributions from the API, each with an author and a contribution.
 * @return string[] The names of the authors.
 */
function get_main_authors( $contributors ) {
	$authors = array();
	$all     = array();

	if ( is_array( $contributors ) ) {
		foreach ( $contributors as $contributor ) {
			if ( ! is_array( $contributor ) || empty( $contributor['author']['name'] ) ) {
				continue;
			}

			$name  = sanitize_text_field( $contributor['author']['name'] );
			$all[] = $name;

			if ( empty( $contributor['contribution'] ) || 'Author' === $contributor['contribution'] ) {
				$authors[] = $name;
			}
		}
	}

	return array_values( array_unique( $authors ? $authors : $all ) );
}

/**
 * Converts a raw book record from the API into the shape Endpaper uses.
 *
 * @param array $raw Raw book record.
 * @return array Normalized book.
 */
function normalize_book( $raw ) {
	$authors = get_main_authors( isset( $raw['cached_contributors'] ) ? $raw['cached_contributors'] : array() );

	$slug = isset( $raw['slug'] ) ? sanitize_title( $raw['slug'] ) : '';

	return array(
		'id'            => isset( $raw['id'] ) ? absint( $raw['id'] ) : 0,
		'title'         => isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '',
		'slug'          => $slug,
		'url'           => '' !== $slug ? 'https://hardcover.app/books/' . $slug : '',
		'description'   => isset( $raw['description'] ) ? trim( wp_strip_all_tags( (string) $raw['description'] ) ) : '',
		'pages'         => isset( $raw['pages'] ) ? absint( $raw['pages'] ) : 0,
		'year'          => isset( $raw['release_year'] ) ? absint( $raw['release_year'] ) : 0,
		'rating'        => isset( $raw['rating'] ) ? (float) $raw['rating'] : 0.0,
		'ratings_count' => isset( $raw['ratings_count'] ) ? absint( $raw['ratings_count'] ) : 0,
		'cover'         => isset( $raw['image']['url'] ) ? esc_url_raw( $raw['image']['url'] ) : '',
		'authors'       => $authors,
	);
}

/**
 * Stores a normalized book in the cache.
 *
 * The transient lives for a month, but a book counts as fresh for a week only.
 * The extra time keeps a stale copy around to show if Hardcover is unreachable.
 *
 * @param array $book Normalized book.
 * @return void
 */
function cache_book( $book ) {
	if ( empty( $book['id'] ) ) {
		return;
	}

	set_transient(
		cache_key( 'endpaper_book_' . $book['id'] ),
		array(
			'fetched' => time(),
			'book'    => $book,
		),
		MONTH_IN_SECONDS
	);
}

/**
 * Returns when the saved copy of a book runs out.
 *
 * Until then the book can be shown without Hardcover. After that it cannot.
 *
 * @param int $book_id Hardcover book ID.
 * @return int The time the copy is dropped, as a Unix timestamp, or 0 if there is no copy.
 */
function get_book_end_time( $book_id ) {
	$cached = get_transient( cache_key( 'endpaper_book_' . absint( $book_id ) ) );

	return is_array( $cached ) && isset( $cached['fetched'] ) ? (int) $cached['fetched'] + MONTH_IN_SECONDS : 0;
}

/**
 * Returns a book by its Hardcover ID, from the cache when possible.
 *
 * @param int $book_id Hardcover book ID.
 * @return array|WP_Error Normalized book, or an error.
 */
function get_book( $book_id ) {
	$book_id = absint( $book_id );

	if ( ! $book_id ) {
		return new WP_Error( 'endpaper_invalid_book', __( 'No book selected.', 'endpaper' ), array( 'status' => 400 ) );
	}

	$cached = get_transient( cache_key( 'endpaper_book_' . $book_id ) );
	$stale  = is_array( $cached ) && isset( $cached['fetched'], $cached['book'] ) ? $cached['book'] : null;

	if ( $stale && ( time() - (int) $cached['fetched'] ) < get_cache_lifetime( 'book' ) ) {
		return $stale;
	}

	// A visitor does not wait for Hardcover: they get the saved copy, or nothing for now, and the background fetches it.
	if ( refresh_later( 'book', array( $book_id ) ) ) {
		return $stale ? $stale : get_pending_error();
	}

	// After a failed request, wait a few minutes before asking Hardcover again.
	$backoff = get_transient( 'endpaper_api_backoff' );

	if ( $backoff ) {
		return $stale ? $stale : new WP_Error( 'endpaper_backoff', (string) $backoff, array( 'status' => 503 ) );
	}

	$data = api_request(
		'query EndpaperBook($id: Int!) { books(where: {id: {_eq: $id}}, limit: 1) { ' . ENDPAPER_BOOK_FIELDS . ' } }',
		array( 'id' => $book_id )
	);

	if ( is_wp_error( $data ) ) {
		set_backoff( $data->get_error_message() );

		return $stale ? $stale : $data;
	}

	if ( empty( $data['books'][0] ) ) {
		return $stale ? $stale : new WP_Error( 'endpaper_not_found', __( 'That book could not be found on Hardcover.', 'endpaper' ), array( 'status' => 404 ) );
	}

	$book = normalize_book( $data['books'][0] );
	cache_book( $book );

	return $book;
}

/**
 * Converts a raw edition from the API into the shape Endpaper uses.
 *
 * @param array $raw Raw edition.
 * @return array Normalized edition.
 */
function normalize_edition( $raw ) {
	$year = isset( $raw['release_date'] ) && is_string( $raw['release_date'] ) && preg_match( '/^\d{4}/', $raw['release_date'], $matches ) ? absint( $matches[0] ) : 0;

	return array(
		'id'       => isset( $raw['id'] ) ? absint( $raw['id'] ) : 0,
		'book_id'  => isset( $raw['book_id'] ) ? absint( $raw['book_id'] ) : 0,
		'title'    => isset( $raw['title'] ) ? sanitize_text_field( $raw['title'] ) : '',
		'format'   => isset( $raw['edition_format'] ) ? sanitize_text_field( $raw['edition_format'] ) : '',
		'language' => isset( $raw['language']['language'] ) ? sanitize_text_field( $raw['language']['language'] ) : '',
		'year'     => $year,
		'cover'    => isset( $raw['image']['url'] ) && is_string( $raw['image']['url'] ) ? esc_url_raw( $raw['image']['url'] ) : '',
	);
}

/**
 * Returns what a cover looks like, in numbers: its proportions and its colors.
 *
 * @param array $image The image as Hardcover describes it: its size and colors.
 * @return array|null The proportions and the colors as red, green and blue, or null if Hardcover does not say.
 */
function get_cover_look( $image ) {
	if ( empty( $image['width'] ) || empty( $image['height'] ) || empty( $image['color'] ) || empty( $image['colors'] ) || ! is_array( $image['colors'] ) ) {
		return null;
	}

	$colors = array();

	foreach ( array_merge( array( $image['color'] ), $image['colors'] ) as $hex ) {
		if ( is_string( $hex ) && preg_match( '/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $matches ) ) {
			$colors[] = array( hexdec( $matches[1] ), hexdec( $matches[2] ), hexdec( $matches[3] ) );
		}
	}

	return $colors ? array(
		'ratio'  => absint( $image['width'] ) / max( 1, absint( $image['height'] ) ),
		'colors' => $colors,
	) : null;
}

/**
 * Tells whether every color of one cover has a close match among those of another.
 *
 * @param array $from Colors, each as red, green and blue.
 * @param array $to   Colors to look for a match among.
 * @return bool Whether all of them match.
 */
function are_colors_matched( $from, $to ) {
	foreach ( $from as $color ) {
		$found = false;

		foreach ( $to as $other ) {
			if ( abs( $color[0] - $other[0] ) <= 20 && abs( $color[1] - $other[1] ) <= 20 && abs( $color[2] - $other[2] ) <= 20 ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return false;
		}
	}

	return true;
}

/**
 * Tells whether two covers are the same picture.
 *
 * The same artwork is often uploaded again for another edition, in another
 * size or saved again, so it has another address and slightly different
 * colors. Two covers are the same when their proportions are within two
 * percent of each other, and every color in one has a close match in the other.
 *
 * @param array|null $a A cover's look, from get_cover_look().
 * @param array|null $b Another cover's look.
 * @return bool Whether they are the same. Always false if either is not known.
 */
function is_same_cover( $a, $b ) {
	if ( ! $a || ! $b || abs( $a['ratio'] - $b['ratio'] ) / max( $a['ratio'], $b['ratio'] ) > 0.02 ) {
		return false;
	}

	return are_colors_matched( $a['colors'], $b['colors'] ) && are_colors_matched( $b['colors'], $a['colors'] );
}

/**
 * Returns the editions of a book that have a cover, the most read first.
 *
 * Used by the editor, to pick a cover from. Up to 250 editions are asked
 * for, so that less read ones, such as translations, are among them. A failed request is not held
 * against the other lookups: nothing on the site depends on this list.
 *
 * @param int $book_id Hardcover book ID.
 * @return array|WP_Error List of normalized editions, or an error.
 */
function get_editions( $book_id ) {
	$book_id = absint( $book_id );

	if ( ! $book_id ) {
		return new WP_Error( 'endpaper_invalid_book', __( 'No book selected.', 'endpaper' ), array( 'status' => 400 ) );
	}

	$cache_key = cache_key( 'endpaper_editions_v3_' . $book_id );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$data = api_request(
		'query EndpaperEditions($book_id: Int!) { editions(where: {book_id: {_eq: $book_id}}, order_by: {users_count: desc}, limit: 250) { id book_id title edition_format release_date image { url width height color colors } language { language } } }',
		array( 'book_id' => $book_id )
	);

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$editions = array();
	$covers   = array();
	$looks    = array();

	foreach ( isset( $data['editions'] ) && is_array( $data['editions'] ) ? $data['editions'] : array() as $raw_edition ) {
		$edition = normalize_edition( is_array( $raw_edition ) ? $raw_edition : array() );

		// Leave out editions without a cover, and editions that share a cover with one already listed.
		if ( ! $edition['id'] || ! $edition['cover'] ) {
			continue;
		}

		// An address already listed is the same cover. So is a picture that looks the same as one already listed.
		$look = get_cover_look( isset( $raw_edition['image'] ) && is_array( $raw_edition['image'] ) ? $raw_edition['image'] : array() );

		if ( isset( $covers[ $edition['cover'] ] ) ) {
			continue;
		}

		foreach ( $looks as $listed ) {
			if ( is_same_cover( $look, $listed ) ) {
				continue 2;
			}
		}

		$covers[ $edition['cover'] ] = true;

		if ( $look ) {
			$looks[] = $look;
		}

		$editions[] = $edition;
	}

	set_transient( $cache_key, $editions, DAY_IN_SECONDS );

	return $editions;
}

/**
 * Returns one edition by its Hardcover ID, from the cache when possible.
 *
 * Used to show the cover of an edition picked in the editor.
 *
 * @param int $edition_id Hardcover edition ID.
 * @return array|WP_Error Normalized edition, or an error.
 */
function get_edition( $edition_id ) {
	$edition_id = absint( $edition_id );

	if ( ! $edition_id ) {
		return new WP_Error( 'endpaper_invalid_edition', __( 'No edition selected.', 'endpaper' ), array( 'status' => 400 ) );
	}

	$cache_key = cache_key( 'endpaper_edition_' . $edition_id );
	$cached    = get_transient( $cache_key );
	$stale     = is_array( $cached ) && isset( $cached['fetched'], $cached['edition'] ) ? $cached['edition'] : null;

	if ( null !== $stale && ( time() - (int) $cached['fetched'] ) < get_cache_lifetime( 'edition' ) ) {
		return $stale;
	}

	if ( refresh_later( 'edition', array( $edition_id ) ) ) {
		return null !== $stale ? $stale : get_pending_error();
	}

	// After a failed request, wait a few minutes before asking Hardcover again.
	$backoff = get_transient( 'endpaper_api_backoff' );

	if ( $backoff ) {
		return null !== $stale ? $stale : new WP_Error( 'endpaper_backoff', (string) $backoff, array( 'status' => 503 ) );
	}

	$data = api_request(
		'query EndpaperEdition($id: Int!) { editions(where: {id: {_eq: $id}}, limit: 1) { id book_id title edition_format release_date image { url } } }',
		array( 'id' => $edition_id )
	);

	if ( is_wp_error( $data ) ) {
		set_backoff( $data->get_error_message() );

		return null !== $stale ? $stale : $data;
	}

	// An edition that no longer exists is stored as an empty one, so it is not looked up again on every page view.
	$edition = normalize_edition( isset( $data['editions'][0] ) && is_array( $data['editions'][0] ) ? $data['editions'][0] : array() );

	set_transient(
		$cache_key,
		array(
			'fetched' => time(),
			'edition' => $edition,
		),
		MONTH_IN_SECONDS
	);

	return $edition;
}

/**
 * Returns a book by its Hardcover slug.
 *
 * @param string $slug Book slug, as used in hardcover.app/books/{slug}.
 * @return array|WP_Error Normalized book, or an error.
 */
function get_book_by_slug( $slug ) {
	$data = api_request(
		'query EndpaperBookBySlug($slug: String!) { books(where: {slug: {_eq: $slug}}, limit: 1) { ' . ENDPAPER_BOOK_FIELDS . ' } }',
		array( 'slug' => $slug )
	);

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	if ( empty( $data['books'][0] ) ) {
		return new WP_Error( 'endpaper_not_found', __( 'That book could not be found on Hardcover.', 'endpaper' ), array( 'status' => 404 ) );
	}

	$book = normalize_book( $data['books'][0] );
	cache_book( $book );

	return $book;
}

/**
 * Returns the ID of the book at a Hardcover URL, for the shortcode.
 *
 * The ID is saved, so a visitor never waits: the first time, Hardcover is
 * asked in the background and the shortcode shows nothing until it has answered.
 *
 * @param string $slug Book slug, as used in hardcover.app/books/{slug}.
 * @return int|WP_Error The book's ID, or an error.
 */
function get_book_id_by_slug( $slug ) {
	$slug = sanitize_title( $slug );
	$key  = cache_key( 'endpaper_slug_' . $slug );
	$id   = absint( get_transient( $key ) );

	if ( $id ) {
		return $id;
	}

	if ( refresh_later( 'slug', array( $slug ) ) ) {
		return get_pending_error();
	}

	$backoff = get_transient( 'endpaper_api_backoff' );

	if ( $backoff ) {
		return new WP_Error( 'endpaper_backoff', (string) $backoff, array( 'status' => 503 ) );
	}

	$book = get_book_by_slug( $slug );

	if ( is_wp_error( $book ) ) {
		return $book;
	}

	set_transient( $key, $book['id'], MONTH_IN_SECONDS );

	return absint( $book['id'] );
}

/**
 * Searches Hardcover for books.
 *
 * Accepts a free-text search term or a hardcover.app book URL.
 *
 * @param string $term Search term or book URL.
 * @return array|WP_Error List of book summaries (id, title, authors, year, cover), or an error.
 */
function search_books( $term ) {
	$term = trim( (string) $term );

	if ( preg_match( '#hardcover\.app/books/([a-z0-9_-]+)#i', $term, $matches ) ) {
		$book = get_book_by_slug( strtolower( $matches[1] ) );

		if ( is_wp_error( $book ) ) {
			return $book;
		}

		return array(
			array(
				'id'      => $book['id'],
				'title'   => $book['title'],
				'authors' => $book['authors'],
				'year'    => $book['year'],
				'cover'   => $book['cover'],
			),
		);
	}

	// The same search, typed again within the hour, is answered from the cache.
	$per_page  = get_search_results_count();
	$cache_key = cache_key( 'endpaper_search_' . $per_page . '_' . md5( strtolower( $term ) ) );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	// Anyone who can edit posts can search with the site's key, so the searches that reach Hardcover are limited for each person.
	$user_id = get_current_user_id();

	if ( $user_id ) {
		$limit_key = 'endpaper_search_limit_' . $user_id;
		$searches  = (int) get_transient( $limit_key );

		if ( $searches >= ENDPAPER_SEARCH_LIMIT ) {
			return new WP_Error( 'endpaper_search_limit', __( 'Too many searches. Wait a minute and try again.', 'endpaper' ), array( 'status' => 429 ) );
		}

		set_transient( $limit_key, $searches + 1, MINUTE_IN_SECONDS );
	}

	$data = api_request(
		'query EndpaperSearch($query: String!, $per_page: Int!) { search(query: $query, query_type: "Book", per_page: $per_page, page: 1) { results } }',
		array(
			'query'    => $term,
			'per_page' => $per_page,
		)
	);

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$results = isset( $data['search']['results'] ) ? $data['search']['results'] : array();

	if ( is_string( $results ) ) {
		$results = json_decode( $results, true );
	}

	$hits  = isset( $results['hits'] ) && is_array( $results['hits'] ) ? $results['hits'] : array();
	$books = array();

	foreach ( $hits as $hit ) {
		$document = isset( $hit['document'] ) && is_array( $hit['document'] ) ? $hit['document'] : $hit;
		$book_id  = isset( $document['id'] ) ? absint( $document['id'] ) : 0;

		if ( ! $book_id ) {
			continue;
		}

		// Only the main authors, like the block shows. The list of names has everyone, such as illustrators, so it is the fallback.
		$authors = get_main_authors( isset( $document['contributions'] ) ? $document['contributions'] : array() );

		if ( ! $authors && isset( $document['author_names'] ) && is_array( $document['author_names'] ) ) {
			$authors = $document['author_names'];
		}

		$books[] = array(
			'id'      => $book_id,
			'title'   => isset( $document['title'] ) ? sanitize_text_field( $document['title'] ) : '',
			'authors' => array_values( array_map( 'sanitize_text_field', $authors ) ),
			'year'    => isset( $document['release_year'] ) ? absint( $document['release_year'] ) : 0,
			'pages'   => isset( $document['pages'] ) ? absint( $document['pages'] ) : 0,
			'cover'   => isset( $document['image']['url'] ) ? esc_url_raw( $document['image']['url'] ) : '',
		);
	}

	set_transient( $cache_key, $books, HOUR_IN_SECONDS );

	return $books;
}
