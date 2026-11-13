/**
 * Tells whether a block has been given a frame: a border, a background or a
 * shadow. A framed block needs room between its edge and its contents. This
 * is the same test as has_card_frame() in PHP.
 *
 * @param {Object} attributes Block attributes.
 * @return {boolean} Whether the block has a frame.
 */
export default function hasFrame( attributes ) {
	const { style = {} } = attributes;

	return !! (
		attributes.borderColor ||
		attributes.backgroundColor ||
		attributes.gradient ||
		( style.border && Object.keys( style.border ).length ) ||
		style.color?.background ||
		style.color?.gradient ||
		style.shadow
	);
}
