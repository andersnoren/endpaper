<?php
/**
 * Helpers shared by the block templates.
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

defined( 'ABSPATH' ) || exit;

// The option for the style new Book blocks start with: "default" for Plain, or "card".
define( 'ENDPAPER_DEFAULT_STYLE_OPTION', 'endpaper_default_book_style' );

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
 * Reads the Block spacing setting of the block, or else the theme's block gap
 * for the Reading List block. WordPress only applies that setting itself for
 * blocks that use its layout system, so it is passed to the stylesheet as a
 * custom property on the block wrapper instead.
 *
 * @param array $attributes Block attributes.
 * @return string An inline style declaration, or an empty string.
 */
function get_block_gap_style( $attributes ) {
	$gap = isset( $attributes['style']['spacing']['blockGap'] ) ? $attributes['style']['spacing']['blockGap'] : null;

	// Without a gap on the block, the theme's gap for the block is used, from theme.json or the Styles screen.
	if ( null === $gap || '' === $gap ) {
		$gap = wp_get_global_styles( array( 'blocks', 'endpaper/reading-list', 'spacing', 'blockGap' ) );
	}

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
		'<p class="%1$s"><progress max="100" value="%2$s" aria-label="%3$s"></progress> <span><bdi>%4$s</bdi></span></p>',
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
 * Turns the text of one line of Markdown into HTML that is safe to show.
 *
 * Handles links, bold, italic and code. Everything else in the text is
 * escaped, so nothing a reviewer typed can become markup of its own.
 *
 * @param string $text Markdown on one or more lines of a block.
 * @return string HTML.
 */
function markdown_inline_to_html( $text ) {
	$text  = (string) $text;
	$links = array();

	// Links are set aside first, so that nothing in an address is taken for bold or italic, or given curly quotes.
	$text = preg_replace_callback(
		'/\[([^\]]+)\]\(([^)\s]+)\)/',
		static function ( $matches ) use ( &$links ) {
			$url = esc_url( $matches[2], array( 'http', 'https' ) );

			if ( '' === $url ) {
				return $matches[1];
			}

			$links[] = '<a href="' . $url . '" rel="nofollow noopener">' . format_text( $matches[1] ) . '</a>';

			return "\x01" . ( count( $links ) - 1 ) . "\x02";
		},
		$text
	);

	$text = format_text( $text );

	$text = preg_replace( '/\*\*(.+?)\*\*|__(.+?)__/s', '<strong>$1$2</strong>', $text );
	$text = preg_replace( '/(?<![\w*])\*(?![\s*])(.+?)(?<![\s*])\*(?![\w*])|(?<![\w_])_(?![\s_])(.+?)(?<![\s_])_(?![\w_])/s', '<em>$1$2</em>', $text );
	$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

	return (string) preg_replace_callback(
		'/\x01(\d+)\x02/',
		static function ( $matches ) use ( $links ) {
			return isset( $links[ (int) $matches[1] ] ) ? $links[ (int) $matches[1] ] : '';
		},
		$text
	);
}

/**
 * Tells whether a line of Markdown is an item in a list.
 *
 * @param string $line A line of Markdown.
 * @return string The marker that starts it, or an empty string if it is not a list item.
 */
function markdown_list_marker( $line ) {
	return preg_match( '/^\s*([-*+]|\d+[.)])\s+/', $line, $matches ) ? $matches[1] : '';
}

/**
 * Turns the lines of one block of Markdown into HTML.
 *
 * A block is what lies between two blank lines. Headings, quotes, lists and
 * paragraphs are known, which is what Hardcover's editor makes.
 *
 * @param string[] $lines The lines of the block.
 * @return string HTML.
 */
function markdown_block_to_html( $lines ) {
	if ( ! $lines ) {
		return '';
	}

	// A heading is shown as bold text, so the headings of a review do not become part of the outline of the page.
	if ( preg_match( '/^#{1,6}\s+(.+)$/', $lines[0], $matches ) ) {
		return '<p><strong>' . markdown_inline_to_html( $matches[1] ) . '</strong></p>' . markdown_block_to_html( array_slice( $lines, 1 ) );
	}

	if ( preg_match( '/^\s*([-*_])(\s*\1){2,}\s*$/', $lines[0] ) ) {
		return markdown_block_to_html( array_slice( $lines, 1 ) );
	}

	if ( preg_match( '/^\s*>/', $lines[0] ) ) {
		$inner = array_map(
			static function ( $line ) {
				return preg_replace( '/^\s*>\s?/', '', $line );
			},
			$lines
		);

		return '<blockquote><p>' . str_replace( "\n", '<br>', markdown_inline_to_html( implode( "\n", $inner ) ) ) . '</p></blockquote>';
	}

	$marker = markdown_list_marker( $lines[0] );

	if ( '' !== $marker ) {
		$items = array();

		foreach ( $lines as $line ) {
			if ( '' !== markdown_list_marker( $line ) ) {
				$items[] = preg_replace( '/^\s*([-*+]|\d+[.)])\s+/', '', $line );
			} elseif ( $items ) {
				$items[ count( $items ) - 1 ] .= ' ' . trim( $line );
			}
		}

		$tag = ctype_digit( rtrim( $marker, '.)' ) ) ? 'ol' : 'ul';

		return '<' . $tag . '>' . implode(
			'',
			array_map(
				static function ( $item ) {
					return '<li>' . markdown_inline_to_html( $item ) . '</li>';
				},
				$items
			)
		) . '</' . $tag . '>';
	}

	return '<p>' . str_replace( "\n", '<br>', markdown_inline_to_html( implode( "\n", $lines ) ) ) . '</p>';
}

/**
 * Turns a review written in Markdown into HTML that is safe to show.
 *
 * Only paragraphs, line breaks, bold, italic, code, links, quotes and lists
 * are let through, whatever the text contains.
 *
 * @param string $markdown The review, as Hardcover keeps it.
 * @return string HTML, or an empty string if there is no text.
 */
function markdown_to_html( $markdown ) {
	$markdown = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $markdown ) );
	$html     = '';

	if ( '' === $markdown ) {
		return '';
	}

	foreach ( (array) preg_split( '/\n{2,}/', $markdown ) as $block ) {
		$html .= markdown_block_to_html( explode( "\n", trim( (string) $block, "\n" ) ) );
	}

	return wp_kses(
		$html,
		array(
			'p'          => array(),
			'br'         => array(),
			'strong'     => array(),
			'em'         => array(),
			'code'       => array(),
			'blockquote' => array(),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'a'          => array(
				'href' => array(),
				'rel'  => array(),
			),
		)
	);
}

/**
 * Returns how many characters of text a piece of Markdown shows.
 *
 * @param string $markdown Markdown.
 * @return int The number of characters, without markup.
 */
function markdown_text_length( $markdown ) {
	return mb_strlen( trim( html_entity_decode( wp_strip_all_tags( markdown_to_html( $markdown ) ), ENT_QUOTES, 'UTF-8' ) ) );
}

/**
 * Cuts a block of Markdown that is too long, without leaving a marker open.
 *
 * @param string $block  One block of Markdown.
 * @param int    $length The most characters of text to keep.
 * @return string The block, cut.
 */
function cut_markdown_block( $block, $length ) {
	$lines = explode( "\n", $block );

	// A heading is kept as it is.
	if ( preg_match( '/^#{1,6}\s/', $lines[0] ) ) {
		return $block;
	}

	// A list keeps the items that fit, and cuts the first one if not even that fits.
	if ( '' !== markdown_list_marker( $lines[0] ) ) {
		$kept = array();
		$used = 0;

		foreach ( $lines as $line ) {
			$size = markdown_text_length( $line );

			if ( $used + $size > $length ) {
				break;
			}

			$kept[] = $line;
			$used  += $size;
		}

		if ( $kept ) {
			return implode( "\n", $kept );
		}

		$first = preg_replace( '/^(\s*(?:[-*+]|\d+[.)])\s+)(.*)$/', '$2', $lines[0] );
		$mark  = (string) preg_replace( '/^(\s*(?:[-*+]|\d+[.)])\s+).*$/', '$1', $lines[0] );

		return $mark . close_markdown_marks( trim_to_sentence( $first, $length ) );
	}

	// A quote is cut as text and put in a quote again.
	if ( preg_match( '/^\s*>/', $lines[0] ) ) {
		$text = '';

		foreach ( $lines as $line ) {
			$text .= ' ' . preg_replace( '/^\s*>\s?/', '', $line );
		}

		return '> ' . close_markdown_marks( trim_to_sentence( trim( $text ), $length ) );
	}

	return close_markdown_marks( trim_to_sentence( $block, $length ) );
}

/**
 * Removes a bold, italic, code or link marker that a cut left open at the end.
 *
 * @param string $markdown Markdown that may have been cut.
 * @return string Markdown without an open marker.
 */
function close_markdown_marks( $markdown ) {
	// A link cut in the middle is dropped.
	$markdown = (string) preg_replace( '/\[[^\]]*$|\[[^\]]*\]\([^)]*$/', '', $markdown );

	foreach ( array( '**', '__', '*', '`' ) as $mark ) {
		$text = '**' === $mark || '__' === $mark ? $markdown : str_replace( array( '**', '__' ), '', $markdown );

		if ( 1 === substr_count( $text, $mark ) % 2 ) {
			$position = strrpos( $markdown, $mark );

			if ( false !== $position ) {
				$markdown = substr( $markdown, 0, $position ) . substr( $markdown, $position + strlen( $mark ) );
			}
		}
	}

	return trim( $markdown );
}

/**
 * Cuts a review written in Markdown to a length, on a whole block where it can.
 *
 * Blocks are kept whole as long as they fit. The block where the limit falls
 * is cut on a sentence, and is left out if hardly any room is left.
 *
 * @param string $markdown The review.
 * @param int    $length   The most characters of text to show. 0 shows everything.
 * @return string Markdown.
 */
function trim_markdown( $markdown, $length ) {
	$markdown = trim( str_replace( array( "\r\n", "\r" ), "\n", (string) $markdown ) );
	$length   = absint( $length );

	if ( ! $length || markdown_text_length( $markdown ) <= $length ) {
		return $markdown;
	}

	$kept = array();
	$used = 0;

	foreach ( (array) preg_split( '/\n{2,}/', $markdown ) as $block ) {
		$size = markdown_text_length( $block );

		if ( $used + $size <= $length ) {
			$kept[] = $block;
			$used  += $size;
			continue;
		}

		$room = $length - $used;

		if ( ! $kept || $room >= 40 ) {
			$kept[] = cut_markdown_block( $block, max( 1, $room ) );
		}

		break;
	}

	return implode( "\n\n", $kept );
}

/**
 * Tells whether a block has been given a frame: a border, a background or a shadow.
 *
 * A framed block needs room between its edge and its contents.
 *
 * @param array $attributes Block attributes.
 * @return bool Whether the block has a frame.
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
 * Formats a number from a reading goal: whole, or with one decimal for part of an hour.
 *
 * @param float $value The number.
 * @return string The number, with separators for the site's language.
 */
function format_goal_number( $value ) {
	$decimals = abs( $value - round( $value ) ) < 0.05 ? 0 : 1;

	return number_format_i18n( $value, $decimals );
}

/**
 * Returns the count and the rest of the text for how far along a goal is.
 *
 * "34" and "of 50 books read", so the count can be shown on its own, big.
 *
 * @param array $goal A normalized goal.
 * @return array The count and the label.
 */
function get_goal_amount_parts( $goal ) {
	$target = format_goal_number( $goal['target'] );
	$number = (int) round( $goal['target'] );

	if ( 'page' === $goal['metric'] ) {
		/* translators: %s: The goal, in pages. This follows the number of pages read, as in "34 of 50 pages read". */
		$text = _n( 'of %s page read', 'of %s pages read', $number, 'endpaper' );
	} elseif ( 'hour' === $goal['metric'] ) {
		/* translators: %s: The goal, in hours. This follows the number of hours listened, as in "34 of 50 hours listened". */
		$text = _n( 'of %s hour listened', 'of %s hours listened', $number, 'endpaper' );
	} else {
		/* translators: %s: The goal, in books. This follows the number of books read, as in "34 of 50 books read". */
		$text = _n( 'of %s book read', 'of %s books read', $number, 'endpaper' );
	}

	return array(
		'count' => format_goal_number( $goal['progress'] ),
		'label' => sprintf( $text, $target ),
	);
}

/**
 * Returns the text for how far along a goal is, such as "34 of 50 books read".
 *
 * @param array $goal A normalized goal.
 * @return string The text.
 */
function get_goal_amount_text( $goal ) {
	$parts = get_goal_amount_parts( $goal );

	return $parts['count'] . ' ' . $parts['label'];
}

/**
 * Returns the text for a goal whose time has run out, such as "Ended November 30, 2026".
 *
 * @param array $goal A normalized goal.
 * @return string The text.
 */
function get_goal_ended_text( $goal ) {
	$date = format_date( $goal['end'] );

	/* translators: %s: A date, such as November 30, 2015. */
	return '' === $date ? __( 'Ended', 'endpaper' ) : sprintf( __( 'Ended %s', 'endpaper' ), $date );
}

/**
 * Returns how much of the time a goal runs for is left, as a text such as "48 days left".
 *
 * Days are counted in the site's time zone, from today to the last day of the goal.
 *
 * @param array $goal A normalized goal.
 * @return string The text, or an empty string if the goal has no valid end date.
 */
function get_goal_days_text( $goal ) {
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $goal['end'] ) ) {
		return '';
	}

	$timezone = wp_timezone();
	$end      = date_create_immutable( $goal['end'] . ' 00:00:00', $timezone );

	if ( ! $end ) {
		return '';
	}

	$today = current_datetime()->setTime( 0, 0 );
	$days  = (int) $today->diff( $end )->format( '%r%a' );

	if ( $days > 0 ) {
		/* translators: %s: Number of days. */
		return sprintf( _n( '%s day left', '%s days left', $days, 'endpaper' ), number_format_i18n( $days ) );
	}

	if ( 0 === $days ) {
		return __( 'Ends today', 'endpaper' );
	}

	return get_goal_ended_text( $goal );
}

/**
 * Returns the Hardcover logo, as inline SVG: the mark, followed by the name.
 *
 * It takes the color of the text around it. The logo is Hardcover's
 * trademark, used to credit them as the source, with their permission.
 *
 * The paths are those of Hardcover's own one-color logo file, unchanged.
 * Only the fill is different: the color of the text, in place of white.
 *
 * @return string SVG markup.
 */
function get_hardcover_logo() {
	return '<svg class="endpaper-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 506 85" width="506" height="85" fill="currentColor" aria-hidden="true" focusable="false"><path d="M53.2842 0C55.8886 7.28927e-05 57.9998 2.11598 58 4.72559V62.0605C58 62.0815 57.9993 62.1031 57.999 62.124L58 80.2744C58.0001 82.8843 55.8886 84.9999 53.2842 85H9.43066C4.22236 84.9998 -1.48274e-05 80.7689 0 75.5498V9.4502C6.50708e-06 4.23118 4.22246 0.000193561 9.43066 0H53.2842ZM11.8594 66.7861C8.51318 66.786 5.80067 69.5046 5.80078 72.8574C5.80088 76.2106 8.51329 78.9285 11.8594 78.9287H52.2002V66.7861H11.8594ZM30.5918 28.2676C30.1221 26.9389 28.247 26.9388 27.7773 28.2676L25.4971 34.7168C25.3516 35.1281 25.0341 35.4549 24.6279 35.6123L18.2871 38.0693C17.0142 38.5624 17.0142 40.3672 18.2871 40.8604L24.6279 43.3164C25.0341 43.4736 25.3516 43.8009 25.4971 44.2119L27.7773 50.6621C28.2472 51.9906 30.122 51.9906 30.5918 50.6621L32.8711 44.2119C33.0166 43.8007 33.3342 43.4738 33.7402 43.3164L40.0811 40.8604C41.3541 40.3674 41.3544 38.5626 40.082 38.0693L33.7402 35.6123C33.3342 35.4549 33.0164 35.128 32.8711 34.7168L30.5918 28.2676ZM42.1914 16.124C41.7216 14.7956 39.8468 14.7956 39.377 16.124L38.6631 18.1426C38.5177 18.5536 38.2008 18.8807 37.7949 19.0381L35.6865 19.8545C34.4139 20.3476 34.4139 22.1523 35.6865 22.6455L37.7949 23.4619C38.2009 23.6193 38.5177 23.9471 38.6631 24.3584L39.377 26.376C39.8466 27.7047 41.7217 27.7047 42.1914 26.376L42.9053 24.3584C43.0506 23.9472 43.3674 23.6193 43.7734 23.4619L45.8818 22.6455C47.1542 22.1523 47.1546 20.3477 45.8818 19.8545L43.7734 19.0381C43.3676 18.8807 43.0507 18.5536 42.9053 18.1426L42.1914 16.124Z" fill-rule="evenodd" clip-rule="evenodd"/><path d="M302.774 26.4805C307.111 26.4805 310.888 27.2644 314.104 28.832C317.342 30.3997 319.867 32.6185 321.679 35.4893C323.511 38.3396 324.478 41.6891 324.58 45.5371H310.623C310.338 43.1346 309.523 41.2508 308.18 39.8867C306.856 38.5229 305.126 37.8408 302.988 37.8408C301.258 37.8408 299.741 38.3294 298.438 39.3066C297.135 40.2635 296.117 41.6888 295.384 43.582C294.671 45.4551 294.315 47.777 294.315 50.5459C294.315 53.3145 294.671 55.6557 295.384 57.5693C296.117 59.4627 297.135 60.8987 298.438 61.876C299.741 62.8329 301.258 63.3115 302.988 63.3115C304.373 63.3115 305.595 63.0162 306.653 62.4258C307.732 61.8354 308.617 60.9699 309.31 59.8301C310.002 58.6696 310.44 57.2643 310.623 55.6152H324.58C324.438 59.4834 323.47 62.8629 321.679 65.7539C319.907 68.645 317.413 70.8955 314.196 72.5039C311 74.0919 307.202 74.8857 302.805 74.8857C297.858 74.8857 293.613 73.8678 290.07 71.832C286.548 69.7961 283.84 66.9658 281.946 63.3418C280.053 59.6974 279.106 55.4828 279.106 50.6982C279.106 45.8933 280.053 41.6787 281.946 38.0547C283.86 34.4103 286.578 31.5702 290.101 29.5342C293.643 27.4982 297.868 26.4805 302.774 26.4805Z"/><path d="M351.539 26.4805C356.466 26.4805 360.692 27.4982 364.214 29.5342C367.756 31.5498 370.474 34.3801 372.367 38.0244C374.281 41.6485 375.238 45.873 375.238 50.6982C375.238 55.5032 374.281 59.7277 372.367 63.3721C370.474 66.996 367.756 69.8263 364.214 71.8623C360.692 73.8779 356.466 74.8857 351.539 74.8857C346.612 74.8857 342.377 73.8779 338.835 71.8623C335.313 69.8263 332.594 66.9961 330.681 63.3721C328.787 59.7277 327.841 55.5031 327.841 50.6982C327.841 45.8731 328.787 41.6484 330.681 38.0244C332.594 34.38 335.313 31.5498 338.835 29.5342C342.377 27.4983 346.612 26.4805 351.539 26.4805ZM351.631 37.5352C349.778 37.5352 348.21 38.0852 346.928 39.1846C345.665 40.284 344.698 41.8111 344.026 43.7656C343.375 45.7201 343.05 48.0005 343.05 50.6064C343.05 53.1718 343.375 55.4322 344.026 57.3867C344.698 59.3412 345.665 60.8684 346.928 61.9678C348.21 63.0671 349.778 63.6162 351.631 63.6162C353.422 63.6162 354.94 63.0671 356.182 61.9678C357.424 60.8684 358.37 59.3411 359.021 57.3867C359.693 55.4322 360.029 53.1718 360.029 50.6064C360.029 48.0004 359.693 45.7201 359.021 43.7656C358.37 41.8111 357.424 40.284 356.182 39.1846C354.94 38.0851 353.423 37.5352 351.631 37.5352Z" fill-rule="evenodd" clip-rule="evenodd"/><path d="M447.405 26.4805C450.744 26.4805 453.798 26.9998 456.567 28.0381C459.336 29.0764 461.729 30.6135 463.744 32.6494C465.76 34.6853 467.328 37.1996 468.447 40.1924C469.567 43.1649 470.127 46.5758 470.127 50.4238V54.1494H438.854V54.5459C438.854 56.4597 439.231 58.1394 439.984 59.585C440.738 61.0101 441.806 62.12 443.19 62.9141C444.575 63.708 446.224 64.1054 448.138 64.1055C449.461 64.1055 450.663 63.9221 451.742 63.5557C452.821 63.1892 453.747 62.649 454.521 61.9365C455.294 61.2239 455.875 60.3489 456.262 59.3105L469.974 59.707C469.404 62.7813 468.152 65.459 466.218 67.7393C464.304 69.9992 461.789 71.7601 458.674 73.0225C455.559 74.2643 451.955 74.8857 447.863 74.8857C442.957 74.8857 438.721 73.9186 435.158 71.9844C431.616 70.0299 428.887 67.251 426.974 63.6475C425.08 60.0234 424.134 55.7167 424.134 50.7285C424.134 45.883 425.091 41.6484 427.005 38.0244C428.919 34.38 431.616 31.5498 435.098 29.5342C438.579 27.4982 442.682 26.4805 447.405 26.4805ZM447.711 37.2607C446.041 37.2607 444.544 37.6373 443.221 38.3906C441.897 39.1236 440.849 40.1315 440.075 41.4141C439.341 42.612 438.938 43.966 438.864 45.4756H456.231C456.211 43.8876 455.834 42.4723 455.102 41.2305C454.369 39.9887 453.361 39.0223 452.078 38.3301C450.816 37.6175 449.36 37.2608 447.711 37.2607Z" fill-rule="evenodd" clip-rule="evenodd"/><path d="M169.979 26.4805C172.971 26.4805 175.73 26.8364 178.254 27.5488C180.779 28.2411 182.977 29.2598 184.851 30.6035C186.724 31.9269 188.17 33.5551 189.188 35.4893C190.226 37.4234 190.745 39.6324 190.745 42.1162V74H176.697V67.4648H176.33C175.495 69.0527 174.427 70.3968 173.124 71.4961C171.841 72.5955 170.324 73.42 168.573 73.9697C166.843 74.5194 164.888 74.7939 162.71 74.7939C159.717 74.7939 157.059 74.2955 154.738 73.2979C152.438 72.2799 150.616 70.7526 149.272 68.7168C147.949 66.6605 147.287 64.0849 147.287 60.9902C147.287 58.3842 147.745 56.1852 148.661 54.3936C149.577 52.6019 150.84 51.1462 152.448 50.0264C154.057 48.9066 155.91 48.0613 158.007 47.4912C160.104 46.9008 162.344 46.5044 164.726 46.3008C167.392 46.0565 169.54 45.8018 171.169 45.5371C172.798 45.2521 173.979 44.8547 174.712 44.3457C175.465 43.8164 175.842 43.073 175.842 42.1162V41.9639C175.842 40.3962 175.302 39.1852 174.223 38.3301C173.144 37.475 171.688 37.0469 169.855 37.0469C167.881 37.0469 166.293 37.475 165.092 38.3301C163.891 39.1852 163.127 40.3655 162.801 41.8721L149.027 41.3838C149.435 38.5334 150.484 35.9886 152.174 33.749C153.884 31.4891 156.225 29.7172 159.197 28.4346C162.19 27.1315 165.784 26.4805 169.979 26.4805ZM175.934 52.8662C175.506 53.0902 174.987 53.2943 174.376 53.4775C173.786 53.6608 173.133 53.8332 172.421 53.9961C171.708 54.1589 170.976 54.3017 170.223 54.4238C169.469 54.546 168.746 54.658 168.054 54.7598C166.649 54.9837 165.448 55.3306 164.45 55.7988C163.473 56.2671 162.72 56.8776 162.19 57.6309C161.682 58.3637 161.427 59.239 161.427 60.2568C161.427 61.8042 161.977 62.9854 163.076 63.7998C164.196 64.6142 165.611 65.0215 167.321 65.0215C168.93 65.0215 170.376 64.6954 171.658 64.0439C172.961 63.3925 173.999 62.4964 174.772 61.3564C175.546 60.1959 175.934 58.8522 175.934 57.3252V52.8662Z" fill-rule="evenodd" clip-rule="evenodd"/><path d="M274.044 74H259.263V66.3955H258.805C258.174 67.8409 257.277 69.1948 256.117 70.457C254.957 71.7193 253.501 72.7381 251.75 73.5117C250.019 74.2854 247.973 74.6718 245.611 74.6719C242.15 74.6719 239.004 73.776 236.174 71.9844C233.344 70.1927 231.084 67.5051 229.395 63.9219C227.705 60.3386 226.859 55.89 226.859 50.5762C226.859 45.0587 227.735 40.5181 229.486 36.9551C231.237 33.3923 233.528 30.7561 236.357 29.0459C239.208 27.3357 242.272 26.4805 245.55 26.4805C248.013 26.4805 250.121 26.9076 251.872 27.7627C253.623 28.5974 255.068 29.6768 256.208 31C257.348 32.3234 258.214 33.6979 258.805 35.123H259.109V11.4551H274.044V74ZM250.803 38.1465C248.95 38.1465 247.382 38.6559 246.1 39.6738C244.817 40.6918 243.84 42.1269 243.168 43.9795C242.516 45.8322 242.19 48.0213 242.19 50.5459C242.19 53.0704 242.526 55.2696 243.198 57.1426C243.87 59.0155 244.837 60.4715 246.1 61.5098C247.382 62.5276 248.95 63.0361 250.803 63.0361C252.615 63.0361 254.163 62.5278 255.445 61.5098C256.728 60.4715 257.715 59.0155 258.407 57.1426C259.099 55.2696 259.445 53.0704 259.445 50.5459C259.445 47.9806 259.099 45.7707 258.407 43.918C257.735 42.0655 256.748 40.6401 255.445 39.6426C254.163 38.6449 252.615 38.1465 250.803 38.1465Z" fill-rule="evenodd" clip-rule="evenodd"/><path d="M102.852 36.5586H127.497V11.4551H142.583V74H127.497V48.8662H102.852V74H87.7344V11.4551H102.852V36.5586Z"/><path d="M223.225 26.4492C223.917 26.4492 224.629 26.5008 225.362 26.6025C226.095 26.684 226.778 26.8158 227.409 26.999V39.9785C226.697 39.7342 225.759 39.541 224.599 39.3984C223.459 39.256 222.441 39.1846 221.545 39.1846C219.774 39.1846 218.175 39.582 216.75 40.376C215.345 41.1496 214.236 42.2389 213.422 43.6436C212.628 45.028 212.23 46.6572 212.23 48.5303V74H197.297V27.0908H211.803V35.6426H212.292C213.147 32.5479 214.541 30.2469 216.476 28.7402C218.41 27.2133 220.659 26.4493 223.225 26.4492Z"/><path d="M399.411 59.4023H399.899L408.573 27.0908H424.301L408.207 74H391.104L375.04 27.0908H390.769L399.411 59.4023Z"/><path d="M501.103 26.4492C501.795 26.4492 502.507 26.5008 503.24 26.6025C503.973 26.684 504.655 26.8158 505.286 26.999V39.9785C504.574 39.7342 503.637 39.5409 502.477 39.3984C501.337 39.2559 500.319 39.1846 499.423 39.1846C497.652 39.1846 496.053 39.582 494.628 40.376C493.223 41.1497 492.113 42.2387 491.299 43.6436C490.505 45.028 490.108 46.6572 490.108 48.5303V74H475.174V27.0908H489.681V35.6426H490.169C491.024 32.548 492.419 30.2469 494.353 28.7402C496.287 27.2132 498.537 26.4492 501.103 26.4492Z"/></svg>';
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

/**
 * Returns the path of one of the plugin's own icons.
 *
 * The paths are kept in assets/icons.json, which the editor reads as well, so
 * a block has the same icon in both places. They are drawn on a 20 by 20 grid.
 *
 * @param string $name The icon's name.
 * @return string The SVG path data, or an empty string if there is no such icon.
 */
function get_icon_path( $name ) {
	static $icons = null;

	if ( null === $icons ) {
		$file  = ENDPAPER_DIR . 'assets/icons.json';
		$icons = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file in the plugin, not a remote address.
		$icons = is_array( $icons ) ? $icons : array();
	}

	return isset( $icons[ $name ] ) && is_string( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * Returns the styles the Book block has, with their labels.
 *
 * That is Plain and Card, and any style a theme or another plugin has registered for the block in PHP,
 * including the style variations in a theme's styles folder. A style that is only registered in
 * JavaScript cannot be seen from here.
 *
 * @return array Labels, keyed by the name of the style. "default" is Plain.
 */
function get_book_styles() {
	$styles = array();
	$type   = \WP_Block_Type_Registry::get_instance()->get_registered( 'endpaper/book' );

	if ( $type && is_array( $type->styles ) ) {
		foreach ( $type->styles as $style ) {
			if ( ! empty( $style['name'] ) ) {
				$styles[ $style['name'] ] = isset( $style['label'] ) ? $style['label'] : $style['name'];
			}
		}
	}

	foreach ( \WP_Block_Styles_Registry::get_instance()->get_registered_styles_for_block( 'endpaper/book' ) as $name => $style ) {
		if ( is_string( $name ) && '' !== $name ) {
			$styles[ $name ] = isset( $style['label'] ) ? $style['label'] : $name;
		}
	}

	if ( ! isset( $styles['default'] ) ) {
		$styles = array( 'default' => __( 'Plain', 'endpaper' ) ) + $styles;
	}

	return $styles;
}

/**
 * Returns the style new Book blocks start with.
 *
 * A style that is not registered any more, such as one from a theme that has been switched, counts as Plain.
 *
 * @return string The name of the style, or "default" for the Plain style.
 */
function get_default_book_style() {
	$style = get_option( ENDPAPER_DEFAULT_STYLE_OPTION, 'default' );

	return is_string( $style ) && isset( get_book_styles()[ $style ] ) ? $style : 'default';
}

/**
 * Returns the typography of the theme's headings, as CSS variables.
 *
 * Big text, such as the count in the middle of the gauge, is not a heading, but looks better in the
 * font of the theme's headings. The values come from the global styles, so they follow
 * styles.elements.heading in theme.json and in the Styles screen. A theme can change them for one block
 * under styles.blocks, in elements.heading.typography, which takes precedence.
 *
 * @param string $block_name Block name, such as "endpaper/reading-goal".
 * @return string Inline style declarations, or an empty string.
 */
function get_heading_typography_style( $block_name ) {
	$options    = array( 'transforms' => array( 'resolve-variables' ) );
	$typography = wp_get_global_styles( array( 'elements', 'heading', 'typography' ), $options );
	$for_block  = wp_get_global_styles( array( 'blocks', $block_name, 'elements', 'heading', 'typography' ), $options );

	$typography = is_array( $typography ) ? $typography : array();

	if ( is_array( $for_block ) ) {
		$typography = array_merge( $typography, $for_block );
	}

	$properties = array(
		'fontFamily'    => 'font-family',
		'fontWeight'    => 'font-weight',
		'fontStyle'     => 'font-style',
		'letterSpacing' => 'letter-spacing',
	);
	$style      = '';

	foreach ( $properties as $key => $property ) {
		if ( ! isset( $typography[ $key ] ) || ! is_scalar( $typography[ $key ] ) ) {
			continue;
		}

		$value = trim( wp_strip_all_tags( (string) $typography[ $key ] ) );

		// Only plain values: no declarations, functions that load things, or leftover variables.
		if ( '' === $value || preg_match( '/[;{}<>\\\\]|url\\(|expression\\(|var\\(/i', $value ) ) {
			continue;
		}

		$style .= '--endpaper-heading-' . $property . ': ' . $value . ';';
	}

	return $style;
}
