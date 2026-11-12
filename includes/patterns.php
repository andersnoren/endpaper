<?php
/**
 * Block patterns: ready-made sections built from the plugin's blocks.
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the patterns, and the category they are listed under.
 *
 * Each is a heading with a Reading List under it. The book titles are one
 * heading level below, so the page keeps a sound outline.
 *
 * @return void
 */
function register_patterns() {
	register_block_pattern_category(
		'endpaper',
		array(
			'label'       => __( 'Endpaper', 'endpaper' ),
			'description' => __( 'Books from your Hardcover library.', 'endpaper' ),
		)
	);

	$patterns = array(
		'currently-reading' => array(
			'title'       => __( 'Currently reading', 'endpaper' ),
			'description' => __( 'A heading and the books you are reading now, with how far you have read.', 'endpaper' ),
			'heading'     => __( 'Currently reading', 'endpaper' ),
			'list'        => array(
				'status'     => 2,
				'count'      => 3,
				'titleLevel' => 3,
			),
		),
		'recently-read'     => array(
			'title'       => __( 'Recently read', 'endpaper' ),
			'description' => __( 'A heading and the books you last finished, with your ratings.', 'endpaper' ),
			'heading'     => __( 'Recently read', 'endpaper' ),
			'list'        => array(
				'status'     => 3,
				'count'      => 3,
				'titleLevel' => 3,
			),
		),
	);

	foreach ( $patterns as $name => $pattern ) {
		register_block_pattern(
			'endpaper/' . $name,
			array(
				'title'         => $pattern['title'],
				'description'   => $pattern['description'],
				'categories'    => array( 'endpaper' ),
				'keywords'      => array( 'hardcover', 'books', 'reading' ),
				'viewportWidth' => 600,
				'content'       => '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $pattern['heading'] ) . '</h2><!-- /wp:heading -->' . "\n\n" . '<!-- wp:endpaper/reading-list ' . wp_json_encode( $pattern['list'] ) . ' /--></div><!-- /wp:group -->',
			)
		);
	}
}
add_action( 'init', __NAMESPACE__ . '\register_patterns' );
