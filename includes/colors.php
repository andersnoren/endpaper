<?php
/**
 * Color helpers: working out which colors are in use, and how well they contrast.
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the colors in the active theme's palette.
 *
 * @return array Color values, keyed by slug.
 */
function get_palette_colors() {
	$palette = wp_get_global_settings( array( 'color', 'palette' ) );
	$colors  = array();

	if ( ! is_array( $palette ) ) {
		return $colors;
	}

	// Later origins win: a color the site owner defined replaces a theme color with the same slug.
	foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
		if ( empty( $palette[ $origin ] ) || ! is_array( $palette[ $origin ] ) ) {
			continue;
		}

		foreach ( $palette[ $origin ] as $color ) {
			if ( isset( $color['slug'], $color['color'] ) ) {
				$colors[ $color['slug'] ] = $color['color'];
			}
		}
	}

	return $colors;
}

/**
 * Reads the red, green and blue values from a hex or rgb() color.
 *
 * @param mixed $value A CSS color.
 * @return array|null Red, green and blue from 0 to 255, or null if the color is in another format.
 */
function parse_color( $value ) {
	if ( ! is_string( $value ) ) {
		return null;
	}

	$value = trim( $value );

	if ( preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value, $matches ) ) {
		$hex = $matches[1];

		if ( strlen( $hex ) < 6 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	if ( preg_match( '/^rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})\b/i', $value, $matches ) ) {
		return array( min( 255, (int) $matches[1] ), min( 255, (int) $matches[2] ), min( 255, (int) $matches[3] ) );
	}

	return null;
}

/**
 * Resolves a color setting to something that can be measured.
 *
 * Follows a reference to the theme's palette, whether it is written as
 * "var:preset|color|{slug}" or as "var(--wp--preset--color--{slug})".
 *
 * @param mixed $value A CSS color or a palette reference.
 * @return array|null Red, green and blue from 0 to 255, or null if the color cannot be worked out.
 */
function resolve_color( $value ) {
	if ( ! is_string( $value ) ) {
		return null;
	}

	$value = trim( $value );

	if ( preg_match( '/^var:preset\|color\|([a-zA-Z0-9_-]+)$/', $value, $matches ) || preg_match( '/^var\(\s*--wp--preset--color--([a-zA-Z0-9_-]+)\s*\)$/', $value, $matches ) ) {
		$palette = get_palette_colors();
		$slug    = $matches[1];

		if ( ! isset( $palette[ $slug ] ) ) {
			$slug = _wp_to_kebab_case( $slug );
		}

		return isset( $palette[ $slug ] ) ? parse_color( $palette[ $slug ] ) : null;
	}

	return parse_color( $value );
}

/**
 * Returns how strongly two colors contrast, as defined by WCAG.
 *
 * @param array $first  Red, green and blue from 0 to 255.
 * @param array $second Red, green and blue from 0 to 255.
 * @return float The contrast ratio, from 1 (none) to 21 (black on white).
 */
function get_contrast_ratio( $first, $second ) {
	$luminance = static function ( $color ) {
		$channels = array();

		foreach ( $color as $channel ) {
			$channel   /= 255;
			$channels[] = $channel <= 0.03928 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	};

	$lighter = max( $luminance( $first ), $luminance( $second ) );
	$darker  = min( $luminance( $first ), $luminance( $second ) );

	return ( $lighter + 0.05 ) / ( $darker + 0.05 );
}

/**
 * Returns the accent color a block ends up with, following the same order as the stylesheet.
 *
 * @param array  $attributes Block attributes.
 * @param string $block_name Block name, such as "endpaper/book".
 * @return string A CSS color or a palette reference.
 */
function get_accent_color( $attributes, $block_name ) {
	if ( isset( $attributes['accentColor'] ) && '' !== sanitize_css_color( $attributes['accentColor'] ) ) {
		return $attributes['accentColor'];
	}

	$from_theme = wp_get_global_settings( array( 'custom', 'endpaper', 'accent' ), array( 'block_name' => $block_name ) );

	if ( is_string( $from_theme ) && '' !== $from_theme ) {
		return $from_theme;
	}

	$palette = get_palette_colors();

	foreach ( array( 'accent', 'accent-1' ) as $slug ) {
		if ( isset( $palette[ $slug ] ) ) {
			return 'var:preset|color|' . $slug;
		}
	}

	return '#4f46e5';
}

/**
 * Returns the inline style that sets the color of text placed on the accent color.
 *
 * The text is either the site's background color or the text color, whichever
 * contrasts better with the accent. If the colors cannot be worked out,
 * nothing is returned and the stylesheet uses its own default.
 *
 * @param array  $attributes Block attributes.
 * @param string $block_name Block name, such as "endpaper/book".
 * @return string An inline style declaration, or an empty string.
 */
function get_on_accent_style( $attributes, $block_name ) {
	$resolved = array( 'transforms' => array( 'resolve-variables' ) );

	// The text color is the block's own if it has one, otherwise the site's.
	if ( ! empty( $attributes['textColor'] ) && is_string( $attributes['textColor'] ) ) {
		$text_source = 'var:preset|color|' . $attributes['textColor'];
	} elseif ( isset( $attributes['style']['color']['text'] ) ) {
		$text_source = $attributes['style']['color']['text'];
	} else {
		$text_source = wp_get_global_styles( array( 'color', 'text' ), $resolved );
	}

	$accent     = resolve_color( get_accent_color( $attributes, $block_name ) );
	$background = resolve_color( wp_get_global_styles( array( 'color', 'background' ), $resolved ) );
	$text       = resolve_color( $text_source );

	if ( ! $accent ) {
		return '';
	}

	// Without a known color, assume the usual: dark text on a light background.
	$background = $background ? $background : array( 255, 255, 255 );
	$text       = $text ? $text : array( 0, 0, 0 );

	if ( get_contrast_ratio( $accent, $text ) > get_contrast_ratio( $accent, $background ) ) {
		return '--endpaper-on-accent: currentcolor;';
	}

	return sprintf( '--endpaper-on-accent: #%02x%02x%02x;', $background[0], $background[1], $background[2] );
}
