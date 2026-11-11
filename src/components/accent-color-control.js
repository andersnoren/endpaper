/**
 * WordPress dependencies
 */
/* eslint-disable @wordpress/no-unsafe-wp-apis -- The components behind core's own Color panel have no stable equivalents yet. */
import {
	InspectorControls,
	__experimentalColorGradientSettingsDropdown as ColorGradientSettingsDropdown,
	__experimentalUseMultipleOriginColorsAndGradients as useMultipleOriginColorsAndGradients,
} from '@wordpress/block-editor';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { __ } from '@wordpress/i18n';

/**
 * How a color from the theme's palette is stored: by its slug, so the block
 * follows the palette if the theme changes the color later.
 */
const PRESET_PATTERN = /^var:preset\|color\|(.+)$/;

/**
 * Accent color setting, added to the block's Color panel. Shared by all blocks.
 *
 * Without a value, the block uses the accent color the theme sets, or the
 * plugin's own default if the theme sets none.
 *
 * @param {Object}                            props          Component props.
 * @param {string}                            props.clientId The block's client ID.
 * @param {string|undefined}                  props.value    A palette reference or a CSS color.
 * @param {(value: string|undefined) => void} props.onChange Called with the new color, or undefined on reset.
 * @return {Element|null} The control, or null if the theme offers no color settings.
 */
export default function AccentColorControl( { clientId, value, onChange } ) {
	const colorGradientSettings = useMultipleOriginColorsAndGradients();

	if ( ! colorGradientSettings.hasColorsOrGradients ) {
		return null;
	}

	const palette = ( colorGradientSettings.colors || [] ).flatMap(
		( origin ) => origin.colors || []
	);
	const presetSlug = value?.match( PRESET_PATTERN )?.[ 1 ];
	const colorValue = presetSlug
		? palette.find( ( { slug } ) => slug === presetSlug )?.color
		: value;

	return (
		<InspectorControls group="color">
			<ColorGradientSettingsDropdown
				__experimentalIsRenderedInSidebar
				panelId={ clientId }
				settings={ [
					{
						label: __( 'Accent', 'endpaper' ),
						colorValue,
						onColorChange: ( color ) => {
							const preset = palette.find(
								( item ) => item.color === color
							);

							onChange(
								preset
									? `var:preset|color|${ preset.slug }`
									: color || undefined
							);
						},
						isShownByDefault: true,
						resetAllFilter: () => {
							onChange( undefined );
						},
						enableAlpha: true,
						clearable: true,
					},
				] }
				{ ...colorGradientSettings }
			/>
		</InspectorControls>
	);
}
