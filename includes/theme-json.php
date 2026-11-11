<?php
/**
 * Default styles for the blocks, added to the site's global styles.
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * Gives the blocks a default margin above and below.
 *
 * The margin is added to the styles that blocks themselves contribute to
 * theme.json. That is the lowest layer above WordPress's own defaults, so a
 * theme can change it in its theme.json, under styles.blocks, and so can the
 * Styles screen and the Margin setting of a single block.
 *
 * @param \WP_Theme_JSON_Data $theme_json The styles contributed by blocks.
 * @return \WP_Theme_JSON_Data The styles, with the margins added.
 */
function add_default_block_styles( $theme_json ) {
	$styles = array(
		'spacing' => array(
			'margin' => array(
				'top'    => '1.5em',
				'bottom' => '1.5em',
			),
		),
	);

	return $theme_json->update_with(
		array(
			'version' => 3,
			'styles'  => array(
				'blocks' => array(
					'endpaper/book'         => $styles,
					'endpaper/reading-list' => $styles,
				),
			),
		)
	);
}
add_filter( 'wp_theme_json_data_blocks', __NAMESPACE__ . '\add_default_block_styles' );
