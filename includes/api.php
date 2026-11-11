<?php
/**
 * Hardcover API client, book lookups and caching.
 *
 * All requests are made server-side. The API token never reaches the browser.
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * GraphQL fields requested for a full book record.
 */
define( 'ENDPAPER_BOOK_FIELDS', 'id title slug description pages release_year rating ratings_count image { url } cached_contributors' );

/**
 * Tidies up an API key: removes a leading "Bearer " and surrounding space.
 *
 * @param mixed $value The key as entered.
 * @return string The bare key, or an empty string if it cannot be a key.
 */
function clean_token( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$token = trim( (string) preg_replace( '/^Bearer\s+/i', '', trim( $value ) ) );

	// A key is one run of visible characters, with nothing that could break out of an HTTP header.
	if ( strlen( $token ) > 4096 || ! preg_match( '/^[\x21-\x7E]+$/', $token ) ) {
		return '';
	}

	return $token;
}

/**
 * Returns the Hardcover API key.
 *
 * It comes from the ENDPAPER_HARDCOVER_TOKEN constant in wp-config.php if
 * that is set, and otherwise from the settings screen.
 *
 * @return string The bare key, or an empty string if none is set.
 */
function get_token() {
	if ( defined( 'ENDPAPER_HARDCOVER_TOKEN' ) ) {
		$token = clean_token( ENDPAPER_HARDCOVER_TOKEN );

		if ( '' !== $token ) {
			return $token;
		}
	}

	return clean_token( get_option( 'endpaper_hardcover_token', '' ) );
}

/**
 * Sends a GraphQL request to the Hardcover API.
 *
 * @param string      $query     The GraphQL query.
 * @param array       $variables Optional. Query variables.
 * @param string|null $token     Optional. An API key to use in place of the saved one, to try it out before saving it.
 * @return array|WP_Error The "data" part of the response, or an error.
 */
function api_request( $query, $variables = array(), $token = null ) {
	$token = null === $token ? get_token() : clean_token( $token );

	if ( '' === $token ) {
		return new WP_Error(
			'endpaper_no_token',
			__( 'No Hardcover API key is saved. Add one under Settings, Endpaper.', 'endpaper' ),
			array( 'status' => 400 )
		);
	}

	$response = wp_remote_post(
		ENDPAPER_API_URL,
		array(
			'timeout'    => 15,
			'user-agent' => 'Endpaper/' . ENDPAPER_VERSION . ' (WordPress plugin)',
			'headers'    => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'       => wp_json_encode(
				array(
					'query'     => $query,
					'variables' => (object) $variables,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 401 === $code ) {
		return new WP_Error(
			'endpaper_unauthorized',
			__( 'Hardcover did not accept the API key. It may have expired or been deleted.', 'endpaper' ),
			array( 'status' => 401 )
		);
	}

	if ( 429 === $code ) {
		return new WP_Error(
			'endpaper_throttled',
			__( 'Hardcover is rate limiting requests. Try again in a minute.', 'endpaper' ),
			array( 'status' => 429 )
		);
	}

	if ( ! empty( $body['errors'][0]['message'] ) ) {
		return new WP_Error(
			'endpaper_api_error',
			sanitize_text_field( $body['errors'][0]['message'] ),
			array( 'status' => 502 )
		);
	}

	if ( 200 !== $code || ! is_array( $body ) ) {
		return new WP_Error(
			'endpaper_http_error',
			/* translators: %d: HTTP status code. */
			sprintf( __( 'Hardcover returned an unexpected response (HTTP %d).', 'endpaper' ), $code ),
			array( 'status' => 502 )
		);
	}

	return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
}

/**
 * Converts a raw book record from the API into the shape Endpaper uses.
 *
 * @param array $raw Raw book record.
 * @return array Normalized book.
 */
function normalize_book( $raw ) {
	$authors      = array();
	$contributors = array();

	if ( isset( $raw['cached_contributors'] ) && is_array( $raw['cached_contributors'] ) ) {
		foreach ( $raw['cached_contributors'] as $contributor ) {
			if ( empty( $contributor['author']['name'] ) ) {
				continue;
			}

			$name           = sanitize_text_field( $contributor['author']['name'] );
			$contributors[] = $name;

			// An empty contribution means the person is a main author.
			if ( empty( $contributor['contribution'] ) || 'Author' === $contributor['contribution'] ) {
				$authors[] = $name;
			}
		}
	}

	if ( empty( $authors ) ) {
		$authors = $contributors;
	}

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
		'authors'       => array_values( array_unique( $authors ) ),
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
		'endpaper_book_' . $book['id'],
		array(
			'fetched' => time(),
			'book'    => $book,
		),
		MONTH_IN_SECONDS
	);
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

	$cached = get_transient( 'endpaper_book_' . $book_id );
	$stale  = is_array( $cached ) && isset( $cached['fetched'], $cached['book'] ) ? $cached['book'] : null;

	if ( $stale && ( time() - (int) $cached['fetched'] ) < WEEK_IN_SECONDS ) {
		return $stale;
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
		set_transient( 'endpaper_api_backoff', $data->get_error_message(), 5 * MINUTE_IN_SECONDS );

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

	$cache_key = 'endpaper_editions_v2_' . $book_id;
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$data = api_request(
		'query EndpaperEditions($book_id: Int!) { editions(where: {book_id: {_eq: $book_id}}, order_by: {users_count: desc}, limit: 250) { id book_id title edition_format release_date image { url } language { language } } }',
		array( 'book_id' => $book_id )
	);

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$editions = array();
	$covers   = array();

	foreach ( isset( $data['editions'] ) && is_array( $data['editions'] ) ? $data['editions'] : array() as $raw_edition ) {
		$edition = normalize_edition( is_array( $raw_edition ) ? $raw_edition : array() );

		// Leave out editions without a cover, and editions that share a cover with one already listed.
		if ( ! $edition['id'] || ! $edition['cover'] || isset( $covers[ $edition['cover'] ] ) ) {
			continue;
		}

		$covers[ $edition['cover'] ] = true;
		$editions[]                  = $edition;
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

	$cache_key = 'endpaper_edition_' . $edition_id;
	$cached    = get_transient( $cache_key );
	$stale     = is_array( $cached ) && isset( $cached['fetched'], $cached['edition'] ) ? $cached['edition'] : null;

	if ( null !== $stale && ( time() - (int) $cached['fetched'] ) < WEEK_IN_SECONDS ) {
		return $stale;
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
		set_transient( 'endpaper_api_backoff', $data->get_error_message(), 5 * MINUTE_IN_SECONDS );

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

	$data = api_request(
		'query EndpaperSearch($query: String!) { search(query: $query, query_type: "Book", per_page: 8, page: 1) { results } }',
		array( 'query' => $term )
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

		$authors = isset( $document['author_names'] ) && is_array( $document['author_names'] ) ? $document['author_names'] : array();

		$books[] = array(
			'id'      => $book_id,
			'title'   => isset( $document['title'] ) ? sanitize_text_field( $document['title'] ) : '',
			'authors' => array_values( array_map( 'sanitize_text_field', $authors ) ),
			'year'    => isset( $document['release_year'] ) ? absint( $document['release_year'] ) : 0,
			'cover'   => isset( $document['image']['url'] ) ? esc_url_raw( $document['image']['url'] ) : '',
		);
	}

	return $books;
}

/**
 * Returns the reading statuses a reading list can show.
 *
 * The keys are Hardcover's status IDs.
 *
 * @return array Status labels, keyed by status ID.
 */
function get_reading_statuses() {
	return array(
		2 => __( 'Currently Reading', 'endpaper' ),
		3 => __( 'Read', 'endpaper' ),
		1 => __( 'Want to Read', 'endpaper' ),
	);
}

/**
 * Returns the labels of the shelves a book can be on.
 *
 * The keys are Hardcover's status IDs. "Ignored" (6) is left out on purpose.
 *
 * @return array Shelf labels, keyed by status ID.
 */
function get_shelf_labels() {
	return array(
		1 => __( 'Want to Read', 'endpaper' ),
		2 => __( 'Currently Reading', 'endpaper' ),
		3 => __( 'Read', 'endpaper' ),
		4 => __( 'Paused', 'endpaper' ),
		5 => __( 'Did Not Finish', 'endpaper' ),
	);
}

/**
 * Converts a raw library entry from the API into the shape Endpaper uses.
 *
 * @param array $raw Raw library entry.
 * @return array Normalized entry, without the book it belongs to.
 */
function normalize_library_entry( $raw ) {
	$read     = isset( $raw['user_book_reads'][0] ) && is_array( $raw['user_book_reads'][0] ) ? $raw['user_book_reads'][0] : array();
	$progress = isset( $read['progress'] ) && is_numeric( $read['progress'] ) ? (float) $read['progress'] : null;

	if ( null !== $progress ) {
		$progress = max( 0.0, min( 100.0, $progress ) );
	}

	// The day the book was finished: the one Hardcover keeps on the entry, or else the one on the latest read.
	$finished = '';

	foreach ( array( isset( $raw['last_read_date'] ) ? $raw['last_read_date'] : null, isset( $read['finished_at'] ) ? $read['finished_at'] : null ) as $date ) {
		if ( is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $date, $matches ) ) {
			$finished = $matches[0];
			break;
		}
	}

	return array(
		'status_id' => isset( $raw['status_id'] ) ? absint( $raw['status_id'] ) : 0,
		'rating'    => isset( $raw['rating'] ) && is_numeric( $raw['rating'] ) ? (float) $raw['rating'] : 0.0,
		'progress'  => $progress,
		'finished'  => $finished,
		// The cover of the edition the entry is for, which can differ from the cover Hardcover shows for the book.
		'cover'     => isset( $raw['edition']['image']['url'] ) && is_string( $raw['edition']['image']['url'] ) ? esc_url_raw( $raw['edition']['image']['url'] ) : '',
		// The language of that edition, as Hardcover names it. The cover picker lists it first.
		'language'  => isset( $raw['edition']['language']['language'] ) ? sanitize_text_field( $raw['edition']['language']['language'] ) : '',
	);
}

/**
 * Converts a raw library entry, with its book, into the shape Endpaper uses.
 *
 * @param array $raw Raw library entry.
 * @return array Normalized entry.
 */
function normalize_reading_list_entry( $raw ) {
	$entry         = normalize_library_entry( $raw );
	$entry['book'] = normalize_book( isset( $raw['book'] ) && is_array( $raw['book'] ) ? $raw['book'] : array() );

	// Show the cover of the edition that was read, if it has one.
	if ( $entry['cover'] ) {
		$entry['book']['cover'] = $entry['cover'];
	}

	return $entry;
}

/**
 * Returns the library entry for one book: which shelf it is on, how far along it is, and the cover of its edition.
 *
 * The library is that of the person the API token belongs to. Only an entry
 * that is public on Hardcover is returned.
 *
 * @param int $book_id Hardcover book ID.
 * @return array|WP_Error Normalized entry, an empty array if the book is not in the library, or an error.
 */
function get_library_entry( $book_id ) {
	$book_id = absint( $book_id );

	if ( ! $book_id ) {
		return array();
	}

	// The token is part of the key, so switching to another account starts fresh. The version changes when the stored shape does.
	$cache_key = 'endpaper_entry_v3_' . substr( md5( get_token() ), 0, 10 ) . '_' . $book_id;
	$cached    = get_transient( $cache_key );
	$stale     = is_array( $cached ) && isset( $cached['fetched'], $cached['entry'] ) ? $cached['entry'] : null;

	if ( null !== $stale && ( time() - (int) $cached['fetched'] ) < HOUR_IN_SECONDS ) {
		return $stale;
	}

	// After a failed request, wait a few minutes before asking Hardcover again.
	$backoff = get_transient( 'endpaper_api_backoff' );

	if ( $backoff ) {
		return null !== $stale ? $stale : new WP_Error( 'endpaper_backoff', (string) $backoff, array( 'status' => 503 ) );
	}

	$data = api_request(
		'query EndpaperLibraryEntry($book_id: Int!) { me { user_books(where: {book_id: {_eq: $book_id}, privacy_setting_id: {_eq: 1}}, limit: 1) { status_id rating edition { image { url } language { language } } user_book_reads(order_by: {id: desc}, limit: 1) { progress } } } }',
		array( 'book_id' => $book_id )
	);

	if ( is_wp_error( $data ) ) {
		set_transient( 'endpaper_api_backoff', $data->get_error_message(), 5 * MINUTE_IN_SECONDS );

		return null !== $stale ? $stale : $data;
	}

	// A book that is not in the library is stored as an empty entry, so it is not looked up again on every page view.
	$entry = isset( $data['me'][0]['user_books'][0] ) && is_array( $data['me'][0]['user_books'][0] )
		? normalize_library_entry( $data['me'][0]['user_books'][0] )
		: array();

	set_transient(
		$cache_key,
		array(
			'fetched' => time(),
			'entry'   => $entry,
		),
		WEEK_IN_SECONDS
	);

	return $entry;
}

/**
 * Returns books from the library of the person the API token belongs to.
 *
 * Only entries that are public on Hardcover are returned, so a book that is
 * private there never ends up on the site.
 *
 * @param int $status_id Hardcover status ID. See get_reading_statuses().
 * @param int $limit     Maximum number of books to return.
 * @return array|WP_Error List of normalized entries, or an error.
 */
function get_reading_list( $status_id, $limit ) {
	$status_id = absint( $status_id );
	$limit     = max( 1, min( 20, absint( $limit ) ) );

	if ( ! array_key_exists( $status_id, get_reading_statuses() ) ) {
		return new WP_Error( 'endpaper_invalid_status', __( 'Unknown reading status.', 'endpaper' ), array( 'status' => 400 ) );
	}

	// The token is part of the key, so switching to another account starts fresh. The version changes when the stored shape does.
	$cache_key = 'endpaper_list_v3_' . substr( md5( get_token() ), 0, 10 ) . '_' . $status_id . '_' . $limit;
	$cached    = get_transient( $cache_key );
	$stale     = is_array( $cached ) && isset( $cached['fetched'], $cached['entries'] ) ? $cached['entries'] : null;

	if ( null !== $stale && ( time() - (int) $cached['fetched'] ) < HOUR_IN_SECONDS ) {
		return $stale;
	}

	// After a failed request, wait a few minutes before asking Hardcover again.
	$backoff = get_transient( 'endpaper_api_backoff' );

	if ( $backoff ) {
		return null !== $stale ? $stale : new WP_Error( 'endpaper_backoff', (string) $backoff, array( 'status' => 503 ) );
	}

	// Finished books are ordered by when they were last read. The others by when they last changed.
	$order_by = 3 === $status_id
		? '[{last_read_date: desc_nulls_last}, {date_added: desc}]'
		: '[{updated_at: desc}]';

	$data = api_request(
		'query EndpaperReadingList($status_id: Int!, $limit: Int!) { me { user_books(where: {status_id: {_eq: $status_id}, privacy_setting_id: {_eq: 1}}, order_by: ' . $order_by . ', limit: $limit) { rating last_read_date edition { image { url } } book { id title slug pages release_year image { url } cached_contributors } user_book_reads(order_by: {id: desc}, limit: 1) { progress finished_at } } } }',
		array(
			'status_id' => $status_id,
			'limit'     => $limit,
		)
	);

	if ( is_wp_error( $data ) ) {
		set_transient( 'endpaper_api_backoff', $data->get_error_message(), 5 * MINUTE_IN_SECONDS );

		return null !== $stale ? $stale : $data;
	}

	$raw_entries = isset( $data['me'][0]['user_books'] ) && is_array( $data['me'][0]['user_books'] ) ? $data['me'][0]['user_books'] : array();
	$entries     = array();

	foreach ( $raw_entries as $raw_entry ) {
		$entry = normalize_reading_list_entry( $raw_entry );

		if ( $entry['book']['id'] ) {
			$entries[] = $entry;
		}
	}

	set_transient(
		$cache_key,
		array(
			'fetched' => time(),
			'entries' => $entries,
		),
		WEEK_IN_SECONDS
	);

	return $entries;
}
