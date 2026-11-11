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
 * Returns the Hardcover API token.
 *
 * The token is read from the ENDPAPER_HARDCOVER_TOKEN constant, with or
 * without a leading "Bearer ".
 *
 * @return string The bare token, or an empty string if none is set.
 */
function get_token() {
	$token = defined( 'ENDPAPER_HARDCOVER_TOKEN' ) ? trim( (string) ENDPAPER_HARDCOVER_TOKEN ) : '';

	return trim( (string) preg_replace( '/^Bearer\s+/i', '', $token ) );
}

/**
 * Sends a GraphQL request to the Hardcover API.
 *
 * @param string $query     The GraphQL query.
 * @param array  $variables Optional. Query variables.
 * @return array|WP_Error The "data" part of the response, or an error.
 */
function api_request( $query, $variables = array() ) {
	$token = get_token();

	if ( '' === $token ) {
		return new WP_Error(
			'endpaper_no_token',
			__( 'No Hardcover API token found. Add the ENDPAPER_HARDCOVER_TOKEN constant to wp-config.php.', 'endpaper' ),
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
			__( 'Hardcover rejected the API token. It may have expired or been reset.', 'endpaper' ),
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
