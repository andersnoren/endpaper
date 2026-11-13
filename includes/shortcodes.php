<?php
/**
 * Shortcodes, for themes and places where blocks cannot be used.
 *
 * Each shortcode renders the block of the same kind, so they look and behave
 * the same. They only choose what to show: how it looks is the theme's job.
 *
 * @package Endpaper
 */

namespace Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a shortcode attribute as true or false.
 *
 * @param mixed $value The attribute, as written in the shortcode.
 * @return bool|null Whether it says yes, or null if it says nothing.
 */
function parse_shortcode_bool( $value ) {
	if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
		return null;
	}

	return ! in_array( strtolower( trim( (string) $value ) ), array( '0', 'false', 'no', 'off' ), true );
}

/**
 * Turns shortcode attributes into block attributes.
 *
 * @param array $atts    The shortcode attributes.
 * @param array $map     Shortcode attribute name => array( block attribute, type ). The type is bool, int or string.
 * @return array The block attributes that were set.
 */
function get_shortcode_block_attributes( $atts, $map ) {
	$attributes = array();

	foreach ( $map as $name => $definition ) {
		if ( ! isset( $atts[ $name ] ) || '' === $atts[ $name ] ) {
			continue;
		}

		list( $attribute, $type ) = $definition;

		if ( 'bool' === $type ) {
			$value = parse_shortcode_bool( $atts[ $name ] );

			if ( null !== $value ) {
				$attributes[ $attribute ] = $value;
			}
		} elseif ( 'int' === $type ) {
			$attributes[ $attribute ] = absint( $atts[ $name ] );
		} else {
			$attributes[ $attribute ] = sanitize_text_field( $atts[ $name ] );
		}
	}

	return $attributes;
}

/**
 * Renders a block from a shortcode.
 *
 * @param string $block_name The block's name.
 * @param array  $attributes The block's attributes.
 * @return string The block's HTML.
 */
function render_shortcode_block( $block_name, $attributes ) {
	return render_block(
		array(
			'blockName'    => $block_name,
			'attrs'        => $attributes,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
}

/**
 * Renders [endpaper_book].
 *
 * @param array|string $atts The shortcode attributes.
 * @return string The HTML.
 */
function shortcode_book( $atts ) {
	$atts = (array) $atts;
	$map  = array(
		'cover'       => array( 'showCover', 'bool' ),
		'authors'     => array( 'showAuthors', 'bool' ),
		'meta'        => array( 'showMeta', 'bool' ),
		'rating'      => array( 'showRating', 'bool' ),
		'my_rating'   => array( 'showUserRating', 'bool' ),
		'shelf'       => array( 'showShelf', 'bool' ),
		'progress'    => array( 'showProgress', 'bool' ),
		'description' => array( 'showDescription', 'bool' ),
		'review'      => array( 'showReview', 'bool' ),
		'credit'      => array( 'showCredit', 'bool' ),
		'link'        => array( 'linkToHardcover', 'bool' ),
	);

	$book_id = isset( $atts['id'] ) ? absint( $atts['id'] ) : 0;

	if ( ! $book_id && ! empty( $atts['url'] ) ) {
		if ( ! preg_match( '#hardcover\.app/books/([a-z0-9_-]+)#i', (string) $atts['url'], $matches ) ) {
			ob_start();
			the_editor_notice( __( 'The URL in the shortcode is not a Hardcover book page.', 'endpaper' ) );

			return (string) ob_get_clean();
		}

		$book_id = get_book_id_by_slug( strtolower( $matches[1] ) );

		if ( is_wp_error( $book_id ) ) {
			ob_start();
			the_editor_notice( $book_id->get_error_message(), '' !== get_token_trouble() );

			return (string) ob_get_clean();
		}
	}

	if ( ! $book_id ) {
		return '';
	}

	$attributes           = get_shortcode_block_attributes( $atts, $map );
	$attributes['bookId'] = $book_id;

	return render_shortcode_block( 'endpaper/book', $attributes );
}

/**
 * Renders [endpaper_reading_list].
 *
 * @param array|string $atts The shortcode attributes.
 * @return string The HTML.
 */
function shortcode_reading_list( $atts ) {
	$atts     = (array) $atts;
	$statuses = array(
		'currently-reading' => 2,
		'read'              => 3,
		'want-to-read'      => 1,
	);
	$map      = array(
		'count'    => array( 'count', 'int' ),
		'cover'    => array( 'showCover', 'bool' ),
		'authors'  => array( 'showAuthors', 'bool' ),
		'rating'   => array( 'showRating', 'bool' ),
		'date'     => array( 'showDate', 'bool' ),
		'progress' => array( 'showProgress', 'bool' ),
		'credit'   => array( 'showCredit', 'bool' ),
		'link'     => array( 'linkToHardcover', 'bool' ),
	);

	$attributes = get_shortcode_block_attributes( $atts, $map );
	$status     = isset( $atts['status'] ) ? strtolower( trim( (string) $atts['status'] ) ) : 'currently-reading';

	if ( isset( $statuses[ $status ] ) ) {
		$attributes['status'] = $statuses[ $status ];
	}

	return render_shortcode_block( 'endpaper/reading-list', $attributes );
}

/**
 * Renders [endpaper_reading_goal].
 *
 * Without a goal ID it shows the goal that is running now, or else the latest.
 *
 * @param array|string $atts The shortcode attributes.
 * @return string The HTML.
 */
function shortcode_reading_goal( $atts ) {
	$atts = (array) $atts;
	$map  = array(
		'title'   => array( 'showTitle', 'bool' ),
		'days'    => array( 'showDays', 'bool' ),
		'percent' => array( 'showPercent', 'bool' ),
		'credit'  => array( 'showCredit', 'bool' ),
	);

	$attributes = get_shortcode_block_attributes( $atts, $map );
	$goal_id    = isset( $atts['goal'] ) ? absint( $atts['goal'] ) : 0;

	if ( ! $goal_id ) {
		$current = get_current_goal();
		$goal_id = is_array( $current ) ? $current['id'] : 0;
	}

	$attributes['goalId'] = $goal_id;

	return render_shortcode_block( 'endpaper/reading-goal', $attributes );
}

/**
 * Registers the shortcodes.
 *
 * @return void
 */
function register_shortcodes() {
	add_shortcode( 'endpaper_book', __NAMESPACE__ . '\shortcode_book' );
	add_shortcode( 'endpaper_reading_list', __NAMESPACE__ . '\shortcode_reading_list' );
	add_shortcode( 'endpaper_reading_goal', __NAMESPACE__ . '\shortcode_reading_goal' );
}
add_action( 'init', __NAMESPACE__ . '\register_shortcodes' );
