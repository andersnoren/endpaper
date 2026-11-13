<?php
/**
 * Library: the reading statuses, a book's place in the reader's library, and the Reading List.
 *
 * @package Endpaper
 */

namespace Endpaper;

use WP_Error;

defined( 'ABSPATH' ) || exit;

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
		// The owner's own review, as plain text. Hardcover keeps no line breaks in it. The spoiler flag is the owner's own and is not always set.
		'review'    => isset( $raw['review_raw'] ) && is_string( $raw['review_raw'] ) ? trim( wp_strip_all_tags( $raw['review_raw'] ) ) : '',
		'spoilers'  => ! empty( $raw['review_has_spoilers'] ),
		// The day the review was written, as Hardcover has it. Reviews imported from elsewhere can have a date that is not right.
		'reviewed'  => isset( $raw['reviewed_at'] ) && is_string( $raw['reviewed_at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw['reviewed_at'], $review_date ) ? $review_date[0] : '',
		// The same review in Markdown, which keeps its headings, quotes, lists and bold text. It is cut to a length that no review reaches.
		'markdown'  => isset( $raw['review_markdown'] ) && is_string( $raw['review_markdown'] ) ? trim( mb_substr( $raw['review_markdown'], 0, 20000 ) ) : '',
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
	$cache_key = get_entry_cache_key( $book_id );
	$cached    = get_transient( $cache_key );
	$stale     = is_array( $cached ) && isset( $cached['fetched'], $cached['entry'] ) ? $cached['entry'] : null;

	if ( null !== $stale && ( time() - (int) $cached['fetched'] ) < get_cache_lifetime( 'entry' ) ) {
		return $stale;
	}

	if ( refresh_later( 'entry', array( $book_id ) ) ) {
		return null !== $stale ? $stale : get_pending_error();
	}

	// After a failed request, wait a few minutes before asking Hardcover again.
	$backoff = get_transient( 'endpaper_api_backoff' );

	if ( $backoff ) {
		return null !== $stale ? $stale : new WP_Error( 'endpaper_backoff', (string) $backoff, array( 'status' => 503 ) );
	}

	$data = api_request(
		'query EndpaperLibraryEntry($book_id: Int!) { me { username user_books(where: {book_id: {_eq: $book_id}, privacy_setting_id: {_eq: 1}}, limit: 1) { status_id rating review_raw review_markdown review_has_spoilers reviewed_at edition { image { url } language { language } } user_book_reads(order_by: {id: desc}, limit: 1) { progress } } } }',
		array( 'book_id' => $book_id )
	);

	if ( is_wp_error( $data ) ) {
		set_backoff( $data->get_error_message() );

		return null !== $stale ? $stale : $data;
	}

	// A book that is not in the library is stored as an empty entry, so it is not looked up again on every page view.
	$entry = isset( $data['me'][0]['user_books'][0] ) && is_array( $data['me'][0]['user_books'][0] )
		? normalize_library_entry( $data['me'][0]['user_books'][0] )
		: array();

	// The name of the profile the key belongs to. It is public on Hardcover and is part of the address of a review.
	if ( $entry ) {
		$entry['username'] = isset( $data['me'][0]['username'] ) && is_string( $data['me'][0]['username'] ) ? (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $data['me'][0]['username'] ) : '';
	}

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
	$cache_key = get_list_cache_key( $status_id, $limit );
	$cached    = get_transient( $cache_key );
	$stale     = is_array( $cached ) && isset( $cached['fetched'], $cached['entries'] ) ? $cached['entries'] : null;

	if ( null !== $stale && ( time() - (int) $cached['fetched'] ) < get_cache_lifetime( 'list' ) ) {
		return $stale;
	}

	if ( refresh_later( 'list', array( $status_id, $limit ) ) ) {
		return null !== $stale ? $stale : get_pending_error();
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
		set_backoff( $data->get_error_message() );

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
