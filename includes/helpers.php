<?php
/**
 * Helpers shared by the block templates.
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the HTML tag to use for a book title.
 *
 * @param mixed $level   Heading level from the block attributes: 2 to 6, or 0 for a paragraph.
 * @param int   $fallback Level to use when the given one is not valid.
 * @return string The tag name: "p", or "h2" to "h6".
 */
function get_title_tag( $level, $fallback ) {
	$level = is_numeric( $level ) ? (int) $level : $fallback;

	if ( 0 === $level ) {
		return 'p';
	}

	if ( $level < 2 || $level > 6 ) {
		$level = $fallback;
	}

	return 0 === $level ? 'p' : 'h' . $level;
}

/**
 * Prepares text from Hardcover for display.
 *
 * Applies WordPress's typography, which turns straight quotes and apostrophes
 * into curly ones, and escapes the result. Hardcover's text mixes both styles.
 *
 * @param string $text Plain text.
 * @return string Text that is safe to output as HTML.
 */
function format_text( $text ) {
	return esc_html( wptexturize( (string) $text ) );
}
