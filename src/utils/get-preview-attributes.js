/**
 * Returns the attributes to render a block's preview with.
 *
 * The block's wrapper in the editor already has the typography, margin and
 * extra class name applied, so those are left out to avoid applying them
 * twice. The settings that belong on the card, such as border and padding,
 * are kept, since only the server-rendered preview can apply them.
 *
 * @param {Object} attributes Block attributes.
 * @return {Object} The attributes for the preview.
 */
export default function getPreviewAttributes( attributes ) {
	const { className, fontFamily, fontSize, style = {}, ...rest } = attributes;
	const { elements, spacing = {}, typography, ...restStyle } = style;

	return {
		...rest,
		style: { ...restStyle, spacing: { padding: spacing.padding } },
	};
}
