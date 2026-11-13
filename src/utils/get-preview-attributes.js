/**
 * Returns the attributes to render a block's preview with.
 *
 * The block's wrapper in the editor already has the settings that belong on
 * the block itself applied: typography, margin, extra class name, and the
 * frame (border, background, padding and shadow). Those are left out of the
 * preview, to avoid applying them twice. The style, such as is-style-gauge,
 * is kept, since it decides what the server renders, and so are the text and
 * heading colors, which the preview's own text and titles need. The time of
 * the last update from Hardcover is left out, since it only marks the post as
 * changed and does not change what is shown.
 *
 * @param {Object} attributes Block attributes.
 * @return {Object} The attributes for the preview.
 */
export default function getPreviewAttributes( attributes ) {
	const {
		className,
		fontFamily,
		fontSize,
		refreshedAt,
		style = {},
		...rest
	} = attributes;
	const {
		elements = {},
		spacing,
		typography,
		border,
		shadow,
		color = {},
		...restStyle
	} = style;
	const { background, gradient, ...textColor } = color;
	const styleClassName = getStyleClassName( className );

	for ( const name of [ 'borderColor', 'backgroundColor', 'gradient' ] ) {
		delete rest[ name ];
	}

	return {
		...rest,
		...( styleClassName && { className: styleClassName } ),
		style: {
			...restStyle,
			...( elements.heading && {
				elements: { heading: elements.heading },
			} ),
			color: textColor,
		},
	};
}

/**
 * Returns the block style class names from a class name string.
 *
 * @param {string} className The block's class names.
 * @return {string} The is-style-* classes.
 */
function getStyleClassName( className ) {
	return ( className || '' )
		.split( /\s+/ )
		.filter( ( name ) => name.startsWith( 'is-style-' ) )
		.join( ' ' );
}
