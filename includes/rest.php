<?php
/**
 * REST API routes used by the block editor.
 *
 * The editor talks to these routes, and these routes talk to Hardcover. That
 * keeps the API token on the server.
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

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
	$term = (string) $request->get_param( 'q' );

	if ( strlen( trim( $term ) ) < 3 ) {
		return rest_ensure_response( array() );
	}

	return rest_ensure_response( search_books( $term ) );
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
