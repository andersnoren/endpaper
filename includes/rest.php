<?php
/**
 * REST API routes used by the block editor.
 *
 * The editor talks to these routes, and these routes talk to Hardcover. That
 * keeps the API token on the server.
 *
 * @package Endpaper
 */

namespace Endpaper;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Endpaper REST routes.
 *
 * @return void
 */
function register_rest_routes() {
	register_rest_route(
		'endpaper/v1',
		'/search',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\rest_search',
			'permission_callback' => __NAMESPACE__ . '\rest_permission',
			'args'                => array(
				'q'      => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'author' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'page'   => array(
					'type'              => 'integer',
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'endpaper/v1',
		'/authors',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\rest_author',
			'permission_callback' => __NAMESPACE__ . '\rest_permission',
			'args'                => array(
				'q' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'endpaper/v1',
		'/goals',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\rest_goals',
			'permission_callback' => __NAMESPACE__ . '\rest_permission',
		)
	);

	register_rest_route(
		'endpaper/v1',
		'/library-entry/(?P<book_id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\rest_library_entry',
			'permission_callback' => __NAMESPACE__ . '\rest_permission',
			'args'                => array(
				'book_id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'endpaper/v1',
		'/refresh',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => __NAMESPACE__ . '\rest_refresh',
			'permission_callback' => __NAMESPACE__ . '\rest_permission',
			'args'                => array(
				'kind'      => array(
					'type'     => 'string',
					'required' => true,
					'enum'     => array( 'entry', 'list', 'goals' ),
				),
				'book_id'   => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'status_id' => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'limit'     => array(
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'endpaper/v1',
		'/editions/(?P<book_id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => __NAMESPACE__ . '\rest_editions',
			'permission_callback' => __NAMESPACE__ . '\rest_permission',
			'args'                => array(
				'book_id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\register_rest_routes' );

/**
 * Checks that the current user may search for books.
 *
 * @return bool Whether the user can edit posts.
 */
function rest_permission() {
	return current_user_can( 'edit_posts' );
}

/**
 * Handles a book search request.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error Search results, or an error.
 */
function rest_search( $request ) {
	// An author's books, or a search.
	if ( $request->get_param( 'author' ) ) {
		return rest_ensure_response( get_author_books( $request->get_param( 'author' ), $request->get_param( 'page' ) ) );
	}

	$term = (string) $request->get_param( 'q' );

	if ( strlen( trim( $term ) ) < 3 ) {
		return rest_ensure_response( array() );
	}

	return rest_ensure_response( search_books( $term, $request->get_param( 'page' ) ) );
}

/**
 * Handles a request for the author a search term is the name of.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error The author, or an empty list if there is none, or an error.
 */
function rest_author( $request ) {
	return rest_ensure_response( search_author( (string) $request->get_param( 'q' ) ) );
}

/**
 * Handles a request for the public reading goals, for the block's goal picker.
 *
 * @return WP_REST_Response|WP_Error The goals, or an error.
 */
function rest_goals() {
	$goals = get_goals();

	if ( is_wp_error( $goals ) ) {
		return $goals;
	}

	return rest_ensure_response( $goals );
}

/**
 * Handles a request for the shelf a book is on in the site owner's library.
 *
 * The editor uses this to decide which settings to offer for a book.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error The shelf's status ID, 0 if the book is not in the library, or an error.
 */
function rest_library_entry( $request ) {
	$entry = get_library_entry( absint( $request->get_param( 'book_id' ) ) );

	if ( is_wp_error( $entry ) ) {
		return $entry;
	}

	return rest_ensure_response(
		array(
			'status_id' => isset( $entry['status_id'] ) ? absint( $entry['status_id'] ) : 0,
		)
	);
}

/**
 * Handles a request to fetch a block's data from Hardcover again.
 *
 * It is for the person's own activity: a library entry, a reading list or the
 * goals. Anyone who can edit posts can ask, so each person has to wait a few
 * seconds between requests.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error A note that it is done, or an error.
 */
function rest_refresh( $request ) {
	$limit_key = 'endpaper_refresh_limit_' . get_current_user_id();

	if ( get_transient( $limit_key ) ) {
		return new WP_Error( 'endpaper_refresh_limit', __( 'Wait a few seconds and try again.', 'endpaper' ), array( 'status' => 429 ) );
	}

	set_transient( $limit_key, 1, 10 );

	$kind = (string) $request->get_param( 'kind' );

	if ( 'entry' === $kind ) {
		$args = array( absint( $request->get_param( 'book_id' ) ) );
	} elseif ( 'list' === $kind ) {
		$args = array( absint( $request->get_param( 'status_id' ) ), absint( $request->get_param( 'limit' ) ) );
	} else {
		$args = array();
	}

	$result = refresh_now( $kind, $args );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( array( 'refreshed' => true ) );
}

/**
 * Handles a request for the editions of a book, for the cover picker.
 *
 * Along with the editions come the languages the picker lists first: that of
 * the edition in the site owner's library, of the site, and of the person
 * editing. The last two are locales, which the editor turns into names.
 *
 * @param WP_REST_Request $request The request.
 * @return WP_REST_Response|WP_Error The editions with a cover and the languages to list first, or an error.
 */
function rest_editions( $request ) {
	$book_id  = absint( $request->get_param( 'book_id' ) );
	$editions = get_editions( $book_id );

	if ( is_wp_error( $editions ) ) {
		return $editions;
	}

	// The library entry is an extra here, so a failed lookup only means its language is not listed first.
	$entry = get_library_entry( $book_id );

	return rest_ensure_response(
		array(
			'editions'  => $editions,
			'languages' => array(
				'edition' => ! is_wp_error( $entry ) && ! empty( $entry['language'] ) ? $entry['language'] : '',
				'site'    => get_locale(),
				'user'    => get_user_locale(),
			),
		)
	);
}
