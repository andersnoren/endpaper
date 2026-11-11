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
