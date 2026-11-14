<?php
/**
 * Hardcover API client: the key, the requests, the cache and the background refreshes.
 *
 * All requests are made server-side. The API token never reaches the browser.
 *
 * @package Endpaper
 */

namespace Endpaper;

use WP_Error;

defined( 'ABSPATH' ) || exit;

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
 * Returns the name something is stored under in the cache.
 *
 * Every name ends in a number that goes up when the cache is cleared, which
 * puts everything stored before out of reach. That works wherever the cache
 * is kept, also where entries cannot be looked up by the start of their name.
 *
 * @param string $name What is being stored.
 * @return string The name to store it under.
 */
function cache_key( $name ) {
	return $name . '_g' . absint( get_option( 'endpaper_cache_generation', 0 ) );
}

/**
 * Lets a visitor have the saved copy now, and has Hardcover asked in the background.
 *
 * Used when a saved copy has run past the time it counts as fresh, or when
 * there is none yet. The editor and WP-Cron get fresh data instead, and so
 * does a site where WP-Cron is switched off, since a background request would
 * never run there. A request already waiting for the same thing is not added a
 * second time. With no key saved, nothing is queued: asking Hardcover cannot
 * work, and the caller then reaches the "no key" error at once.
 *
 * @param string $kind What to refresh: book, edition, entry, list, goals or slug.
 * @param array  $args The arguments of the function that fetches it.
 * @return bool Whether the saved copy should be used for now.
 */
function refresh_later( $kind, $args ) {
	if ( '' === get_token() ) {
		return false;
	}

	if ( is_refreshing_now() || wp_doing_cron() || wp_doing_ajax() || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
		return false;
	}

	$event = array( $kind, $args );

	if ( ! wp_next_scheduled( 'endpaper_refresh', $event ) ) {
		wp_schedule_single_event( time(), 'endpaper_refresh', $event );

		// Starts it now, in a request of its own, instead of waiting for the next visitor.
		spawn_cron();
	}

	return true;
}

/**
 * Returns the error for something that is not saved yet and is being fetched in the background.
 *
 * Visitors never wait for Hardcover. When there is no saved copy at all, they
 * get this, which the blocks show as nothing, and the next visit has the copy.
 *
 * @return WP_Error The error.
 */
function get_pending_error() {
	return new WP_Error(
		'endpaper_pending',
		__( 'This is being fetched from Hardcover. Reload the page in a moment.', 'endpaper' ),
		array( 'status' => 503 )
	);
}

/**
 * Tells whether something is being fetched again at once, for a person who asked for it.
 *
 * Then nothing is left to the background, whatever kind of request it is.
 *
 * @param bool|null $set Pass true or false to change it. Leave out to only ask.
 * @return bool Whether something is being fetched again at once.
 */
function is_refreshing_now( $set = null ) {
	static $refreshing = false;

	if ( null !== $set ) {
		$refreshing = (bool) $set;
	}

	return $refreshing;
}

/**
 * Fetches something from Hardcover again, in the background, and saves it.
 *
 * @param string $kind What to refresh: book, edition, entry, list or goals.
 * @param array  $args The arguments of the function that fetches it.
 * @return void
 */
function run_refresh( $kind, $args ) {
	if ( ! is_array( $args ) ) {
		return;
	}

	if ( 'book' === $kind ) {
		get_book( ...$args );
	} elseif ( 'edition' === $kind ) {
		get_edition( ...$args );
	} elseif ( 'entry' === $kind ) {
		get_library_entry( ...$args );
	} elseif ( 'list' === $kind ) {
		get_reading_list( ...$args );
	} elseif ( 'goals' === $kind ) {
		get_goals( ...$args );
	} elseif ( 'slug' === $kind ) {
		get_book_id_by_slug( ...$args );
	}
}
add_action( 'endpaper_refresh', __NAMESPACE__ . '\run_refresh', 10, 2 );

/**
 * Returns the name a library entry is saved under.
 *
 * The token is part of it, so switching to another account starts fresh. The
 * version changes when the stored shape does.
 *
 * @param int $book_id Hardcover book ID.
 * @return string The name of the saved copy.
 */
function get_entry_cache_key( $book_id ) {
	return cache_key( 'endpaper_entry_v7_' . substr( md5( get_token() ), 0, 10 ) . '_' . absint( $book_id ) );
}

/**
 * Returns the name a reading list is saved under.
 *
 * @param int $status_id Hardcover status ID.
 * @param int $limit     Maximum number of books in the list.
 * @return string The name of the saved copy.
 */
function get_list_cache_key( $status_id, $limit ) {
	return cache_key( 'endpaper_list_v3_' . substr( md5( get_token() ), 0, 10 ) . '_' . absint( $status_id ) . '_' . absint( $limit ) );
}

/**
 * Returns the name the reading goals are saved under.
 *
 * @return string The name of the saved copy.
 */
function get_goals_cache_key() {
	return cache_key( 'endpaper_goals_v1_' . substr( md5( get_token() ), 0, 10 ) );
}

/**
 * Fetches something from Hardcover again at once, for a person who asked for it in the editor.
 *
 * The saved copy is marked as old and not removed, so it is still there to
 * show if Hardcover cannot be reached. A wait after an earlier failure is
 * ended, since the person asked. Only the person's own activity can be
 * fetched again this way: a library entry, a reading list or the goals.
 *
 * @param string $kind What to fetch again: entry, list or goals.
 * @param array  $args The arguments of the function that fetches it.
 * @return true|WP_Error True once it is fetched, or an error.
 */
function refresh_now( $kind, $args ) {
	$args = is_array( $args ) ? array_values( $args ) : array();

	if ( 'entry' === $kind && ! empty( $args[0] ) ) {
		$cache_key = get_entry_cache_key( $args[0] );
	} elseif ( 'list' === $kind && isset( $args[0], $args[1] ) ) {
		$cache_key = get_list_cache_key( $args[0], max( 1, min( 20, absint( $args[1] ) ) ) );
	} elseif ( 'goals' === $kind ) {
		$cache_key = get_goals_cache_key();
	} else {
		return new WP_Error( 'endpaper_refresh_unknown', __( 'There is nothing to update.', 'endpaper' ), array( 'status' => 400 ) );
	}

	$cached = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		$cached['fetched'] = 0;
		set_transient( $cache_key, $cached, WEEK_IN_SECONDS );
	}

	delete_transient( 'endpaper_api_backoff' );

	is_refreshing_now( true );

	if ( 'entry' === $kind ) {
		$result = get_library_entry( $args[0] );
	} elseif ( 'list' === $kind ) {
		$result = get_reading_list( $args[0], $args[1] );
	} else {
		$result = get_goals();
	}

	is_refreshing_now( false );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// A failed request leaves the old copy to be shown, and a note of what went wrong.
	$backoff = get_transient( 'endpaper_api_backoff' );

	if ( $backoff ) {
		return new WP_Error( 'endpaper_refresh_failed', (string) $backoff, array( 'status' => 502 ) );
	}

	return true;
}

/**
 * Clears everything Endpaper has fetched from Hardcover, covers included, so that it is fetched again.
 *
 * The API key stays, and so does the note of whether Hardcover accepts it.
 *
 * @return void
 */
function clear_cache() {
	global $wpdb;

	update_option( 'endpaper_cache_generation', absint( get_option( 'endpaper_cache_generation', 0 ) ) + 1 );
	delete_transient( 'endpaper_api_backoff' );

	// The saved covers go too. They are saved again when a block next shows them.
	delete_all_covers();

	// With an external object cache the old entries are not in the database. They are out of reach now, and expire by themselves.
	if ( wp_using_ext_object_cache() ) {
		return;
	}

	foreach ( array( 'book', 'edition', 'entry', 'list', 'slug', 'connection' ) as $kind ) {
		foreach ( array( '_transient_', '_transient_timeout_' ) as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients cannot be deleted by prefix any other way.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix . 'endpaper_' . $kind ) . '%' ) );
		}
	}
}

/**
 * Tells whether Hardcover has turned down the saved API key.
 *
 * That is noted when it happens, and cleared the next time the key is
 * accepted or replaced. See api_request().
 *
 * @return bool Whether the key in use was rejected the last time it was tried.
 */
function is_token_rejected() {
	$token = get_token();

	return '' !== $token && get_transient( 'endpaper_key_rejected' ) === substr( md5( $token ), 0, 10 );
}

/**
 * Tells why the API key cannot be used, if it cannot.
 *
 * Nothing is asked of Hardcover here: this goes by whether a key is saved,
 * and by what happened the last time it was used.
 *
 * @return string "missing" if there is no key, "rejected" if Hardcover turned it down, or an empty string if nothing is known to be wrong.
 */
function get_token_trouble() {
	if ( '' === get_token() ) {
		return 'missing';
	}

	return is_token_rejected() ? 'rejected' : '';
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
	// Only what happens to the saved key is noted, not to one that is being tried out.
	$is_saved = null === $token;
	$token    = $is_saved ? get_token() : clean_token( $token );

	if ( '' === $token ) {
		return new WP_Error(
			'endpaper_no_token',
			__( 'No Hardcover API key is saved. Add one under Settings → Endpaper.', 'endpaper' ),
			array( 'status' => 400 )
		);
	}

	$response = wp_remote_post(
		ENDPAPER_API_URL,
		array(
			'timeout'    => get_request_timeout(),
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
		// Note that the key was turned down, so that the site's administrators can be told. Without an end date, it stays until it is cleared.
		if ( $is_saved ) {
			set_transient( 'endpaper_key_rejected', substr( md5( $token ), 0, 10 ) );
		}

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

	// Hardcover took the key, so an earlier rejection no longer stands.
	if ( $is_saved && false !== get_transient( 'endpaper_key_rejected' ) ) {
		delete_transient( 'endpaper_key_rejected' );
	}

	// This request has waited for Hardcover already, so it may save a new cover or two as well. See may_import_cover_now().
	did_fetch_from_hardcover( true );

	return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
}

/**
 * Returns how many seconds a request to Hardcover may take.
 *
 * @return int Seconds, from 1 to 60.
 */
function get_request_timeout() {
	/**
	 * Filters how long a request to Hardcover may take before it is given up, in seconds.
	 *
	 * @param int $seconds Default 15.
	 */
	return max( 1, min( 60, absint( apply_filters( 'endpaper_request_timeout', 15 ) ) ) );
}

/**
 * Returns how many books a search in the editor shows.
 *
 * @return int Number of books, from 1 to 20.
 */
function get_search_results_count() {
	/**
	 * Filters how many books a search in the editor shows.
	 *
	 * @param int $count Default 7.
	 */
	return max( 1, min( 20, absint( apply_filters( 'endpaper_search_results', 7 ) ) ) );
}

/**
 * Notes that a request failed, so that Hardcover is left alone for a while.
 *
 * @param string $message Why the request failed.
 * @return void
 */
function set_backoff( $message ) {
	/**
	 * Filters how long to wait after a failed request before asking Hardcover again, in seconds.
	 *
	 * @param int $seconds Default 300 (five minutes).
	 */
	$seconds = max( 30, absint( apply_filters( 'endpaper_backoff', 5 * MINUTE_IN_SECONDS ) ) );

	set_transient( 'endpaper_api_backoff', $message, $seconds );
}

/**
 * Returns how long a saved copy counts as fresh, in seconds.
 *
 * After that, visitors still get the saved copy, and a new one is fetched in the background.
 *
 * @param string $kind What is saved: book, edition, entry, list or goals.
 * @return int Seconds, at least a minute.
 */
function get_cache_lifetime( $kind ) {
	$defaults = array(
		'book'    => WEEK_IN_SECONDS,
		'edition' => WEEK_IN_SECONDS,
		'entry'   => HOUR_IN_SECONDS,
		'list'    => HOUR_IN_SECONDS,
		'goals'   => HOUR_IN_SECONDS,
	);

	$default = isset( $defaults[ $kind ] ) ? $defaults[ $kind ] : HOUR_IN_SECONDS;

	/**
	 * Filters how long a saved copy of Hardcover data counts as fresh.
	 *
	 * @param int    $seconds Default: a week for books and editions, an hour for the rest.
	 * @param string $kind    book, edition, entry (shelf, rating and progress), list or goals.
	 */
	return max( MINUTE_IN_SECONDS, absint( apply_filters( 'endpaper_cache_lifetime', $default, $kind ) ) );
}
