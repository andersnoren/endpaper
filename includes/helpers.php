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

/**
 * Turns a length from the block editor into a CSS value.
 *
 * Accepts a plain length such as "8px" or "50%", or a reference to one of the
 * theme's presets, which the editor stores as "var:preset|{type}|{slug}".
 * Anything else is rejected, so the result is safe to use in an inline style.
 *
 * @param mixed  $value       The stored value.
 * @param string $preset_type The kind of preset the value may refer to: "border-radius" or "spacing".
 * @return string A CSS value, or an empty string if the value is not valid.
 */
function sanitize_css_length( $value, $preset_type ) {
	if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
		return '';
	}

	$value = trim( (string) $value );

	if ( preg_match( '/^var:preset\|' . preg_quote( $preset_type, '/' ) . '\|([a-zA-Z0-9_-]+)$/', $value, $matches ) ) {
		return 'var(--wp--preset--' . $preset_type . '--' . _wp_to_kebab_case( $matches[1] ) . ')';
	}

	if ( preg_match( '/^(\d*\.?\d+)(px|%|em|rem|vw|vh)?$/', $value, $matches ) ) {
		// A number without a unit is only valid CSS when it is zero.
		return $matches[1] . ( isset( $matches[2] ) ? $matches[2] : ( 0.0 === (float) $matches[1] ? '' : 'px' ) );
	}

	return '';
}

/**
 * Returns the inline style that sets the corner radius of book covers.
 *
 * The radius is passed to the stylesheet as a custom property on the block
 * wrapper. Without a value in the block attributes, nothing is returned and
 * the stylesheet falls back to the theme's default.
 *
 * @param array $attributes Block attributes.
 * @return string An inline style declaration, or an empty string.
 */
function get_cover_radius_style( $attributes ) {
	$radius = isset( $attributes['coverRadius'] ) ? $attributes['coverRadius'] : null;

	if ( is_array( $radius ) ) {
		// One value per corner, in the order CSS expects them.
		$corners = array();

		foreach ( array( 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ) as $corner ) {
			$corners[] = isset( $radius[ $corner ] ) ? sanitize_css_length( $radius[ $corner ], 'border-radius' ) : '';
		}

		if ( ! array_filter( $corners, 'strlen' ) ) {
			return '';
		}

		$value = implode( ' ', array_map( static fn( $corner ) => '' === $corner ? '0' : $corner, $corners ) );
	} else {
		$value = sanitize_css_length( $radius, 'border-radius' );
	}

	return '' === $value ? '' : '--endpaper-cover-radius: ' . $value . ';';
}

/**
 * Returns the inline style that sets the gap between the items of a block.
 *
 * Reads the Block spacing setting. WordPress only applies that setting itself
 * for blocks that use its layout system, so it is passed to the stylesheet as
 * a custom property on the block wrapper instead.
 *
 * @param array $attributes Block attributes.
 * @return string An inline style declaration, or an empty string.
 */
function get_block_gap_style( $attributes ) {
	$gap   = isset( $attributes['style']['spacing']['blockGap'] ) ? $attributes['style']['spacing']['blockGap'] : null;
	$value = sanitize_css_length( $gap, 'spacing' );

	return '' === $value ? '' : '--endpaper-gap: ' . $value . ';';
}

/**
 * Outputs a reading progress bar with its label.
 *
 * @param float  $progress   Progress as a percentage, from 0 to 100.
 * @param string $class_name Class for the wrapping paragraph.
 * @return void
 */
function the_progress_bar( $progress, $class_name ) {
	$percent = (int) round( $progress );
	/* translators: %s: A percentage, such as 63. */
	$label = sprintf( __( '%s%% read', 'endpaper' ), number_format_i18n( $percent ) );

	printf(
		'<p class="%1$s"><progress max="100" value="%2$s" aria-label="%3$s"></progress> <span>%4$s</span></p>',
		esc_attr( $class_name ),
		esc_attr( $percent ),
		esc_attr( $label ),
		esc_html( $label )
	);
}

/**
 * Turns a color from the block editor into a CSS value.
 *
 * Accepts a hex color, an rgb() or hsl() color, or a reference to a color in
 * the theme's palette, which is stored as "var:preset|color|{slug}". Anything
 * else is rejected, so the result is safe to use in an inline style.
 *
 * @param mixed $value The stored value.
 * @return string A CSS color, or an empty string if the value is not valid.
 */
function sanitize_css_color( $value ) {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$value = trim( $value );

	if ( preg_match( '/^var:preset\|color\|([a-zA-Z0-9_-]+)$/', $value, $matches ) ) {
		return 'var(--wp--preset--color--' . _wp_to_kebab_case( $matches[1] ) . ')';
	}

	if ( preg_match( '/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
		return $value;
	}

	if ( preg_match( '/^(?:rgb|hsl)a?\([0-9.,%\/\s]+(?:deg)?[0-9.,%\/\s]*\)$/', $value ) ) {
		return $value;
	}

	return '';
}

/**
 * Returns the inline style that sets a block's accent color.
 *
 * The color is passed to the stylesheet as a custom property on the block
 * wrapper. Without a value in the block attributes, nothing is returned and
 * the stylesheet falls back to the theme's accent color.
 *
 * @param array $attributes Block attributes.
 * @return string An inline style declaration, or an empty string.
 */
function get_accent_color_style( $attributes ) {
	$value = sanitize_css_color( isset( $attributes['accentColor'] ) ? $attributes['accentColor'] : null );

	return '' === $value ? '' : '--endpaper-accent: ' . $value . ';';
}

/**
 * Shortens text to a maximum length, ending on a whole sentence where possible.
 *
 * Line breaks are turned into spaces, so the result is a single paragraph.
 *
 * @param string $text       Plain text.
 * @param int    $max_length Maximum number of characters. 0 returns the full text.
 * @return string The shortened text.
 */
function trim_to_sentence( $text, $max_length ) {
	$text       = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	$max_length = absint( $max_length );

	if ( ! $max_length || mb_strlen( $text ) <= $max_length ) {
		return $text;
	}

	$cut = mb_substr( $text, 0, $max_length );

	// Look for the last place a sentence ends: closing punctuation, followed by a space or the end of the cut.
	if ( preg_match_all( '/[.!?…]["\'”’)]?(?=\s|$)/u', $cut, $matches, PREG_OFFSET_CAPTURE ) ) {
		$last     = end( $matches[0] );
		$sentence = substr( $cut, 0, $last[1] + strlen( $last[0] ) );

		// Only use it if it keeps a reasonable share of the text.
		if ( mb_strlen( $sentence ) >= $max_length * 0.4 ) {
			return $sentence;
		}
	}

	// Otherwise end on a whole word.
	$words = (string) preg_replace( '/\s+\S*$/u', '', $cut );

	return rtrim( $words, ' ,;:–—-' ) . '…';
}

/**
 * Returns the inline styles for a block's card: its border, padding, background and shadow.
 *
 * These come from the standard block settings, but they belong on the card
 * inside the block, not on the block's wrapper. WordPress is told not to
 * output them itself, and they are applied here instead.
 *
 * @param array $attributes Block attributes.
 * @return string CSS declarations, or an empty string.
 */
function get_card_style( $attributes ) {
	$style  = isset( $attributes['style'] ) && is_array( $attributes['style'] ) ? $attributes['style'] : array();
	$styles = array();

	$border = isset( $style['border'] ) && is_array( $style['border'] ) ? $style['border'] : array();

	if ( ! empty( $attributes['borderColor'] ) ) {
		$border['color'] = 'var:preset|color|' . $attributes['borderColor'];
	}

	if ( $border ) {
		$styles['border'] = $border;
	}

	if ( isset( $style['spacing']['padding'] ) ) {
		$styles['spacing'] = array( 'padding' => $style['spacing']['padding'] );
	}

	$color = array();

	if ( ! empty( $attributes['backgroundColor'] ) ) {
		$color['background'] = 'var:preset|color|' . $attributes['backgroundColor'];
	} elseif ( isset( $style['color']['background'] ) ) {
		$color['background'] = $style['color']['background'];
	}

	if ( ! empty( $attributes['gradient'] ) ) {
		$color['gradient'] = 'var:preset|gradient|' . $attributes['gradient'];
	} elseif ( isset( $style['color']['gradient'] ) ) {
		$color['gradient'] = $style['color']['gradient'];
	}

	if ( $color ) {
		$styles['color'] = $color;
	}

	if ( isset( $style['shadow'] ) ) {
		$styles['shadow'] = $style['shadow'];
	}

	if ( ! $styles ) {
		return '';
	}

	$result = wp_style_engine_get_styles( $styles );

	return isset( $result['css'] ) ? $result['css'] : '';
}

/**
 * Returns the star icon, as inline SVG.
 *
 * This is the filled star from the WordPress icon library. It takes the
 * color of the text around it.
 *
 * @return string SVG markup.
 */
function get_star_icon() {
	return '<svg class="endpaper-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M11.776 4.454a.25.25 0 01.448 0l2.069 4.192a.25.25 0 00.188.137l4.626.672a.25.25 0 01.139.426l-3.348 3.263a.25.25 0 00-.072.222l.79 4.607a.25.25 0 01-.362.263l-4.138-2.175a.25.25 0 00-.232 0l-4.138 2.175a.25.25 0 01-.363-.263l.79-4.607a.25.25 0 00-.071-.222L4.754 9.881a.25.25 0 01.139-.426l4.626-.672a.25.25 0 00.188-.137l2.069-4.192z" /></svg>';
}
