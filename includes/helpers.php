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
		// A number without a unit gets one, zero included: a bare 0 is not valid inside calc().
		return $matches[1] . ( isset( $matches[2] ) ? $matches[2] : 'px' );
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
 * Tells whether a block's card has been given a frame: a border, a background or a shadow.
 *
 * A framed card needs room between its edge and its contents.
 *
 * @param array $attributes Block attributes.
 * @return bool Whether the card has a frame.
 */
function has_card_frame( $attributes ) {
	$style = isset( $attributes['style'] ) && is_array( $attributes['style'] ) ? $attributes['style'] : array();

	return ! empty( $attributes['borderColor'] )
		|| ! empty( $attributes['backgroundColor'] )
		|| ! empty( $attributes['gradient'] )
		|| ! empty( $style['border'] )
		|| ! empty( $style['color']['background'] )
		|| ! empty( $style['color']['gradient'] )
		|| ! empty( $style['shadow'] );
}

/**
 * Returns a star icon, as inline SVG.
 *
 * The shape is the filled star from the WordPress icon library. It takes the
 * color of the text around it, unless the stylesheet sets another.
 *
 * @param string $type Which star: "filled", "half" or "empty". The half star is a whole star with its first half drawn on top, so the two parts can have different colors. The empty star is the same shape as the filled one, to be given a fainter color.
 * @return string SVG markup.
 */
function get_star_icon( $type = 'filled' ) {
	$star = 'M11.776 4.454a.25.25 0 01.448 0l2.069 4.192a.25.25 0 00.188.137l4.626.672a.25.25 0 01.139.426l-3.348 3.263a.25.25 0 00-.072.222l.79 4.607a.25.25 0 01-.362.263l-4.138-2.175a.25.25 0 00-.232 0l-4.138 2.175a.25.25 0 01-.363-.263l.79-4.607a.25.25 0 00-.071-.222L4.754 9.881a.25.25 0 01.139-.426l4.626-.672a.25.25 0 00.188-.137l2.069-4.192z';
	$half = 'M12.0002 16.0322C11.9598 16.0322 11.9197 16.0419 11.884 16.0605L7.74627 18.2363C7.70501 18.2581 7.65801 18.267 7.6115 18.2637C7.56507 18.2603 7.52029 18.2441 7.48259 18.2168C7.44498 18.1895 7.41522 18.1524 7.39763 18.1094C7.38003 18.0662 7.37519 18.0186 7.38298 17.9727L8.17302 13.3662C8.18011 13.3262 8.17758 13.2848 8.16521 13.2461C8.15284 13.2074 8.13071 13.172 8.10173 13.1436L4.75408 9.88086C4.72079 9.84825 4.6968 9.80698 4.68572 9.76172C4.67469 9.71654 4.67716 9.66922 4.69158 9.625C4.70603 9.5807 4.7321 9.54075 4.76775 9.51074C4.80332 9.48083 4.84677 9.4618 4.89275 9.45508L9.51873 8.7832C9.55882 8.77731 9.5973 8.76117 9.63005 8.7373C9.66267 8.71346 9.68937 8.68176 9.7072 8.64551L11.7756 4.4541C11.7962 4.41237 11.8288 4.3771 11.8683 4.35254C11.9079 4.32804 11.9536 4.31543 12.0002 4.31543V16.0322Z';

	if ( ! in_array( $type, array( 'filled', 'half', 'empty' ), true ) ) {
		$type = 'filled';
	}

	$paths = 'half' === $type
		? '<path class="endpaper-icon__rest" d="' . $star . '" /><path d="' . $half . '" />'
		: '<path d="' . $star . '" />';

	return '<svg class="endpaper-icon endpaper-icon--star-' . $type . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true" focusable="false">' . $paths . '</svg>';
}

/**
 * Outputs a rating as five stars.
 *
 * The stars are decoration. Screen readers get the rating as text instead.
 *
 * @param float  $rating     A rating from 0 to 5. It is rounded to the nearest half star.
 * @param string $class_name Class for the wrapping element.
 * @return void
 */
function the_star_rating( $rating, $class_name ) {
	$rating = max( 0.0, min( 5.0, round( (float) $rating * 2 ) / 2 ) );
	$stars  = '';

	for ( $star = 1; $star <= 5; $star++ ) {
		if ( $rating >= $star ) {
			$stars .= get_star_icon( 'filled' );
		} elseif ( $rating >= $star - 0.5 ) {
			$stars .= get_star_icon( 'half' );
		} else {
			$stars .= get_star_icon( 'empty' );
		}
	}

	// Whole ratings are read as "4", half ones as "4.5".
	$number = number_format_i18n( $rating, floor( $rating ) === $rating ? 0 : 1 );

	printf(
		'<span class="%1$s"><span class="endpaper-stars" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
		esc_attr( $class_name ),
		$stars, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup.
		/* translators: %s: A rating, such as 4.5. */
		esc_html( sprintf( __( 'Rated %s out of 5', 'endpaper' ), $number ) )
	);
}

/**
 * Formats a date from Hardcover the way the site shows dates.
 *
 * The format comes from the Date Format setting, and month names are translated.
 *
 * @param string $date A date as YYYY-MM-DD.
 * @return string The formatted date, or an empty string if the date is not valid.
 */
function format_date( $date ) {
	if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
		return '';
	}

	if ( ! checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] ) ) {
		return '';
	}

	// The date has no time of day, so it is formatted in UTC to keep it on the same day everywhere.
	$timezone = new \DateTimeZone( 'UTC' );
	$datetime = date_create( $date . ' 12:00:00', $timezone );
	$format   = get_option( 'date_format' );

	if ( ! $datetime ) {
		return '';
	}

	$formatted = wp_date( $format ? $format : 'F j, Y', $datetime->getTimestamp(), $timezone );

	return $formatted ? $formatted : '';
}

/**
 * Returns the Hardcover logo, as inline SVG: the mark, followed by the name.
 *
 * It takes the color of the text around it. The logo is Hardcover's
 * trademark, used to credit them as the source, with their permission.
 *
 * The mark is made from Hardcover's own logo file. That file is in several
 * shades of their color, so here the cover is solid, the spine and the back
 * cover are lighter, and the stars and the pages are cut out.
 *
 * @return string SVG markup.
 */
function get_hardcover_logo() {
	return '<svg class="endpaper-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 75 16" width="75" height="16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M10.45 2.22 12.46 9.72C12.55 10.06 12.35 10.41 12 10.5L9.6 11.15C9.6 11.15 9.59 11.15 9.59 11.15L6.58 11.96C6.14 12.08 5.88 12.53 6 12.97L6 12.97L3.61 4.06C3.52 3.72 3.72 3.36 4.06 3.27L9.66 1.77C10.01 1.68 10.36 1.88 10.45 2.22ZM7.31 6.36 7.24 7.29C7.24 7.35 7.21 7.4 7.16 7.43L6.41 7.98C6.26 8.09 6.33 8.32 6.51 8.34L7.43 8.44C7.49 8.45 7.54 8.48 7.57 8.53L8.1 9.29C8.21 9.45 8.45 9.38 8.47 9.19L8.54 8.27C8.54 8.21 8.57 8.16 8.62 8.12L9.37 7.58C9.52 7.47 9.45 7.23 9.27 7.21L8.35 7.11C8.29 7.11 8.24 7.08 8.21 7.03L7.68 6.26C7.57 6.11 7.33 6.17 7.31 6.36ZM8.41 4.37 8.38 4.66C8.38 4.72 8.35 4.77 8.3 4.81L8.05 4.99C7.9 5.1 7.97 5.33 8.15 5.35L8.46 5.38C8.52 5.39 8.57 5.42 8.6 5.47L8.77 5.71C8.87 5.87 9.12 5.8 9.14 5.61L9.16 5.32C9.16 5.26 9.19 5.21 9.24 5.17L9.49 4.99C9.64 4.88 9.57 4.65 9.39 4.63L9.09 4.6C9.03 4.59 8.97 4.56 8.94 4.51L8.78 4.27C8.67 4.11 8.42 4.18 8.41 4.37Z"/><path opacity="0.6" d="M5.15 12.84C5.15 12.84 5.15 12.83 5.15 12.83L3.01 4.88C2.83 4.2 3.24 3.49 3.92 3.31L4.2 3.24L4.2 3.24L4.06 3.27C3.72 3.36 3.52 3.72 3.61 4.06L6 12.97H6C6.11 13.4 6.56 13.66 7 13.55L12.29 12.13L11.86 10.54L9.59 11.15C9.59 11.15 9.6 11.15 9.6 11.15L12 10.5C12.34 10.41 12.54 10.07 12.46 9.73L12.75 10.8L12.84 11.17L12.84 11.17L13.09 12.1C13.19 12.44 12.98 12.79 12.64 12.89L6.9 14.43C6.21 14.61 5.51 14.2 5.33 13.52Z"/><path d="M19.0593 12.3019V3.57461H21.1536V7.07745H24.568V3.57461H26.6581V12.3019H24.568V8.79478H21.1536V12.3019H19.0593Z"/><path d="M29.208 12.4127C28.7934 12.4127 28.4253 12.3431 28.1038 12.2039C27.785 12.0618 27.5326 11.8488 27.3464 11.5647C27.1631 11.2777 27.0714 10.9184 27.0714 10.4865C27.0714 10.1229 27.1349 9.81609 27.2618 9.56609C27.3887 9.31609 27.5636 9.11296 27.7865 8.95671C28.0093 8.80046 28.266 8.68256 28.5565 8.60302C28.847 8.52063 29.1573 8.46523 29.4873 8.43683C29.8568 8.40273 30.1544 8.36722 30.38 8.33029C30.6057 8.29052 30.7693 8.23512 30.8708 8.1641C30.9752 8.09023 31.0273 7.98654 31.0273 7.85302V7.83171C31.0273 7.61296 30.9526 7.44393 30.8031 7.32461C30.6536 7.20529 30.4519 7.14563 30.1981 7.14563C29.9245 7.14563 29.7045 7.20529 29.5381 7.32461C29.3716 7.44393 29.2659 7.6087 29.2207 7.81893L27.3126 7.75075C27.369 7.35302 27.5143 6.9979 27.7484 6.6854C27.9853 6.37006 28.3097 6.12291 28.7215 5.94393C29.1361 5.76211 29.634 5.6712 30.215 5.6712C30.6296 5.6712 31.0118 5.72092 31.3616 5.82035C31.7113 5.91694 32.016 6.05898 32.2755 6.24648C32.535 6.43114 32.7352 6.65842 32.8763 6.9283C33.0201 7.19819 33.092 7.50643 33.092 7.85302V12.3019H31.1458V11.39H31.095C30.9794 11.6115 30.8313 11.799 30.6508 11.9525C30.4731 12.1059 30.263 12.2209 30.0204 12.2976C29.7806 12.3743 29.5098 12.4127 29.208 12.4127ZM29.8469 11.049C30.0697 11.049 30.27 11.0036 30.4477 10.9127C30.6282 10.8218 30.7721 10.6968 30.8793 10.5377C30.9864 10.3757 31.04 10.1882 31.04 9.97518V9.35302C30.9808 9.38427 30.9089 9.41268 30.8243 9.43825C30.7425 9.46381 30.6522 9.48796 30.5535 9.51069C30.4548 9.53342 30.3532 9.5533 30.2488 9.57035C30.1445 9.58739 30.0444 9.60302 29.9485 9.61722C29.7538 9.64847 29.5874 9.69677 29.4492 9.76211C29.3138 9.82745 29.2095 9.91268 29.1361 10.0178C29.0656 10.1201 29.0303 10.2422 29.0303 10.3843C29.0303 10.6002 29.1065 10.765 29.2588 10.8786C29.4139 10.9922 29.61 11.049 29.8469 11.049Z"/><path d="M33.7614 12.3019V5.75643H35.7711V6.94961H35.8388C35.9572 6.51779 36.1504 6.19677 36.4184 5.98654C36.6863 5.77347 36.998 5.66694 37.3534 5.66694C37.4493 5.66694 37.548 5.67404 37.6496 5.68825C37.7511 5.69961 37.8456 5.71808 37.9331 5.74364V7.55472C37.8343 7.52063 37.7046 7.49364 37.5438 7.47376C37.3859 7.45387 37.2448 7.44393 37.1207 7.44393C36.8753 7.44393 36.6539 7.49933 36.4565 7.61012C36.2618 7.71808 36.1081 7.87006 35.9953 8.06609C35.8853 8.25927 35.8303 8.48654 35.8303 8.7479V12.3019H33.7614Z"/><path d="M40.2165 12.3956C39.737 12.3956 39.3012 12.2706 38.9091 12.0206C38.5171 11.7706 38.204 11.3956 37.9699 10.8956C37.7358 10.3956 37.6187 9.77489 37.6187 9.03342C37.6187 8.26353 37.74 7.63001 37.9826 7.13285C38.2251 6.63569 38.5425 6.26779 38.9345 6.02915C39.3294 5.79052 39.7539 5.6712 40.208 5.6712C40.5493 5.6712 40.8413 5.73086 41.0838 5.85018C41.3264 5.96666 41.5267 6.11722 41.6846 6.30188C41.8426 6.48654 41.9625 6.6783 42.0443 6.87717H42.0866V3.57461H44.1555V12.3019H42.1077V11.2408H42.0443C41.9568 11.4425 41.8327 11.6314 41.6719 11.8076C41.5112 11.9837 41.3095 12.1257 41.0669 12.2337C40.8272 12.3417 40.5437 12.3956 40.2165 12.3956ZM40.9357 10.7721C41.1868 10.7721 41.4012 10.701 41.5788 10.559C41.7565 10.4141 41.8933 10.211 41.9892 9.94961C42.0851 9.68825 42.1331 9.38143 42.1331 9.02915C42.1331 8.6712 42.0851 8.36296 41.9892 8.10444C41.8962 7.84592 41.7594 7.64705 41.5788 7.50785C41.4012 7.36864 41.1868 7.29904 40.9357 7.29904C40.6791 7.29904 40.4619 7.37006 40.2842 7.51211C40.1065 7.65415 39.9711 7.85444 39.878 8.11296C39.7878 8.37148 39.7426 8.67688 39.7426 9.02915C39.7426 9.38143 39.7892 9.68825 39.8822 9.94961C39.9753 10.211 40.1093 10.4141 40.2842 10.559C40.4619 10.701 40.6791 10.7721 40.9357 10.7721Z"/><path d="M47.9018 12.4255C47.2163 12.4255 46.6283 12.2834 46.1375 11.9993C45.6495 11.7152 45.2744 11.3203 45.012 10.8147C44.7497 10.3061 44.6186 9.71808 44.6186 9.05046C44.6186 8.38001 44.7497 7.79194 45.012 7.28626C45.2772 6.77773 45.6537 6.38143 46.1417 6.09734C46.6325 5.81325 47.2178 5.6712 47.8975 5.6712C48.4983 5.6712 49.0215 5.78058 49.4672 5.99933C49.9157 6.21808 50.2654 6.52773 50.5165 6.9283C50.7703 7.32603 50.9043 7.79336 50.9184 8.33029H48.9849C48.9454 7.99506 48.8326 7.73228 48.6464 7.54194C48.4631 7.3516 48.2233 7.25643 47.9271 7.25643C47.6874 7.25643 47.4773 7.32461 47.2967 7.46097C47.1162 7.5945 46.9752 7.79336 46.8736 8.05756C46.7749 8.31893 46.7256 8.64279 46.7256 9.02915C46.7256 9.41552 46.7749 9.74222 46.8736 10.0093C46.9752 10.2735 47.1162 10.4738 47.2967 10.6101C47.4773 10.7436 47.6874 10.8104 47.9271 10.8104C48.1189 10.8104 48.2882 10.7692 48.4349 10.6868C48.5843 10.6044 48.707 10.4837 48.8029 10.3246C48.8988 10.1627 48.9595 9.96666 48.9849 9.73654H50.9184C50.8987 10.2763 50.7647 10.7479 50.5165 11.1513C50.2711 11.5547 49.9256 11.8686 49.4799 12.0931C49.0371 12.3147 48.511 12.4255 47.9018 12.4255Z"/><path d="M54.4151 12.4255C53.7325 12.4255 53.1458 12.2848 52.655 12.0036C52.167 11.7195 51.7905 11.3246 51.5253 10.8189C51.263 10.3104 51.1319 9.72092 51.1319 9.05046C51.1319 8.37717 51.263 7.78768 51.5253 7.282C51.7905 6.77347 52.167 6.37859 52.655 6.09734C53.1458 5.81325 53.7325 5.6712 54.4151 5.6712C55.0976 5.6712 55.6829 5.81325 56.1709 6.09734C56.6617 6.37859 57.0382 6.77347 57.3005 7.282C57.5657 7.78768 57.6983 8.37717 57.6983 9.05046C57.6983 9.72092 57.5657 10.3104 57.3005 10.8189C57.0382 11.3246 56.6617 11.7195 56.1709 12.0036C55.6829 12.2848 55.0976 12.4255 54.4151 12.4255ZM54.4277 10.853C54.676 10.853 54.8861 10.7763 55.0582 10.6229C55.2302 10.4695 55.3614 10.2564 55.4516 9.9837C55.5447 9.71097 55.5913 9.39563 55.5913 9.03768C55.5913 8.67404 55.5447 8.35586 55.4516 8.08313C55.3614 7.81041 55.2302 7.59734 55.0582 7.44393C54.8861 7.29052 54.676 7.21381 54.4277 7.21381C54.1711 7.21381 53.9539 7.29052 53.7762 7.44393C53.6013 7.59734 53.4673 7.81041 53.3742 8.08313C53.284 8.35586 53.2389 8.67404 53.2389 9.03768C53.2389 9.39563 53.284 9.71097 53.3742 9.9837C53.4673 10.2564 53.6013 10.4695 53.7762 10.6229C53.9539 10.7763 54.1711 10.853 54.4277 10.853Z"/><path d="M64.257 5.75643L62.0273 12.3019H59.658L57.4326 5.75643H59.6115L60.8088 10.265H60.8765L62.0781 5.75643H64.257Z"/><path d="M67.283 12.4255C66.6032 12.4255 66.0165 12.2905 65.5229 12.0206C65.0321 11.7479 64.6542 11.3601 64.389 10.8573C64.1267 10.3516 63.9956 9.75075 63.9956 9.05472C63.9956 8.37859 64.1281 7.78768 64.3933 7.282C64.6584 6.77347 65.0321 6.37859 65.5145 6.09734C65.9968 5.81325 66.5652 5.6712 67.2195 5.6712C67.6821 5.6712 68.1052 5.74364 68.4888 5.88853C68.8724 6.03342 69.2038 6.2479 69.4831 6.532C69.7623 6.81609 69.9795 7.16694 70.1346 7.58455C70.2898 7.99933 70.3673 8.47518 70.3673 9.01211V9.532H64.7191V8.32177H68.4423C68.4394 8.10018 68.3873 7.90273 68.2857 7.72944C68.1842 7.55614 68.0446 7.4212 67.8669 7.32461C67.692 7.22518 67.4903 7.17546 67.2618 7.17546C67.0306 7.17546 66.8232 7.22802 66.6399 7.33313C66.4566 7.43541 66.3113 7.57603 66.2041 7.75501C66.0969 7.93114 66.0405 8.13143 66.0349 8.35586V9.58739C66.0349 9.85444 66.0871 10.0888 66.1914 10.2905C66.2958 10.4894 66.4439 10.6442 66.6357 10.755C66.8275 10.8658 67.0559 10.9212 67.3211 10.9212C67.5044 10.9212 67.6708 10.8956 67.8203 10.8445C67.9698 10.7934 68.0982 10.7181 68.2053 10.6186C68.3125 10.5192 68.3929 10.3971 68.4465 10.2522L70.3462 10.3076C70.2672 10.7365 70.0937 11.1101 69.8258 11.4283C69.5606 11.7436 69.2123 11.9894 68.7807 12.1655C68.3492 12.3388 67.8499 12.4255 67.283 12.4255Z"/><path d="M70.8283 12.3019V5.75643H72.838V6.94961H72.9057C73.0242 6.51779 73.2174 6.19677 73.4853 5.98654C73.7533 5.77347 74.065 5.66694 74.4204 5.66694C74.5163 5.66694 74.615 5.67404 74.7165 5.68825C74.8181 5.69961 74.9126 5.71808 75 5.74364V7.55472C74.9013 7.52063 74.7715 7.49364 74.6108 7.47376C74.4528 7.45387 74.3118 7.44393 74.1877 7.44393C73.9423 7.44393 73.7208 7.49933 73.5234 7.61012C73.3288 7.71808 73.1751 7.87006 73.0622 8.06609C72.9522 8.25927 72.8972 8.48654 72.8972 8.7479V12.3019H70.8283Z"/></svg>';
}

/**
 * Outputs the credit to Hardcover: their logo, linked to Hardcover.
 *
 * The logo has no words that a screen reader can read, so the link carries
 * the credit as hidden text.
 *
 * @param string $url        Where the credit links to: a book on Hardcover, or Hardcover itself.
 * @param string $class_name Class for the link.
 * @return void
 */
function the_hardcover_credit( $url, $class_name ) {
	printf(
		'<a class="%1$s" href="%2$s">%3$s<span class="screen-reader-text">%4$s</span></a>',
		esc_attr( $class_name ),
		esc_url( $url ? $url : 'https://hardcover.app/' ),
		get_hardcover_logo(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup.
		esc_html__( 'Powered by Hardcover', 'endpaper' )
	);
}

/**
 * Outputs a message for the people who look after the site, in place of a block or next to it.
 *
 * Visitors never see it. It says so itself, since it shows up on the public
 * page, where it could be taken for something everyone sees.
 *
 * @param string $message          The message.
 * @param bool   $link_to_settings Optional. Whether to add a link to the plugin's settings, for those who may change them.
 * @return void
 */
function the_editor_notice( $message, $link_to_settings = false ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$link = $link_to_settings && current_user_can( 'manage_options' )
		? ' <a href="' . esc_url( admin_url( 'options-general.php?page=endpaper' ) ) . '">' . esc_html__( 'Go to the Endpaper settings', 'endpaper' ) . '</a>'
		: '';

	printf(
		'<div class="endpaper-notice"><p><strong>%1$s</strong> %2$s%3$s</p><p class="endpaper-notice__private">%4$s</p></div>',
		esc_html__( 'Endpaper:', 'endpaper' ),
		esc_html( $message ),
		$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		esc_html__( 'Only people who can edit this site see this message.', 'endpaper' )
	);
}
