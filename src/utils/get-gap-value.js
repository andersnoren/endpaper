/**
 * Turns the Block spacing setting into a CSS value.
 *
 * The setting is either a plain length, or a reference to one of the theme's
 * spacing presets, stored as "var:preset|spacing|{slug}".
 *
 * @param {string|undefined} blockGap The stored setting.
 * @return {string|undefined} A CSS value, or undefined if nothing is set.
 */
export default function getGapValue( blockGap ) {
	if ( typeof blockGap !== 'string' || ! blockGap ) {
		return undefined;
	}

	const preset = blockGap.match( /^var:preset\|spacing\|(.+)$/ );

	if ( preset ) {
		return `var(--wp--preset--spacing--${ preset[ 1 ] })`;
	}

	// A number without a unit gets one, zero included: a bare 0 is not valid inside calc().
	return /^\d*\.?\d+$/.test( blockGap ) ? `${ blockGap }px` : blockGap;
}
