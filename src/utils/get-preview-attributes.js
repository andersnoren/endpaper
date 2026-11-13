/**
 * Returns the attributes to render a block's preview with.
 *
 * The block's wrapper in the editor already has the typography, margin and
 * extra class name applied, so those are left out to avoid applying them
 * twice. The style, such as is-style-gauge, is kept, since it decides what
 * the server renders. The settings that belong on the card, such as border and
 * padding, are kept too, unless the block's own wrapper has them (`framed`
 * is false), where leaving them in would frame the preview twice. The time of
 * the last update from Hardcover is left out, since it only marks the post as
 * changed and does not change what is shown.
 *
 * @param {Object}  attributes Block attributes.
 * @param {boolean} framed     Whether the preview has the card's settings. True by default.
 * @return {Object} The attributes for the preview.
 */
export default function getPreviewAttributes( attributes, framed = true ) {
	const {
		className,
		fontFamily,
		fontSize,
		refreshedAt,
		style = {},
		...rest
	} = attributes;
	const { elements = {}, spacing = {}, typography, ...restStyle } = style;
	const headingElement = elements.heading
		? { elements: { heading: elements.heading } }
		: {};

	if ( ! framed ) {
		const { border, shadow, color = {}, ...unframedStyle } = restStyle;
		const { background, gradient, ...textColor } = color;

		for ( const name of [ 'borderColor', 'backgroundColor', 'gradient' ] ) {
			delete rest[ name ];
		}

		return {
			...rest,
			...( getStyleClassName( className ) && {
				className: getStyleClassName( className ),
			} ),
			style: { ...unframedStyle, ...headingElement, color: textColor },
		};
	}

	const styleClassName = getStyleClassName( className );

	return {
		...rest,
		...( styleClassName && { className: styleClassName } ),
		style: {
			...restStyle,
			...headingElement,
			spacing: { padding: spacing.padding },
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
