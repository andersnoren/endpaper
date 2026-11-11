/**
 * WordPress dependencies
 */
/* eslint-disable @wordpress/no-unsafe-wp-apis -- The radius control and the tools panel that core's own Border panel uses have no stable equivalents yet. */
import {
	InspectorControls,
	__experimentalBorderRadiusControl as BorderRadiusControl,
	useSettings,
} from '@wordpress/block-editor';
import {
	__experimentalToolsPanel as ToolsPanel,
	__experimentalToolsPanelItem as ToolsPanelItem,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { __ } from '@wordpress/i18n';

/**
 * Whether a radius has been set.
 *
 * @param {string|Object|undefined} value One radius for all corners, or one per corner.
 * @return {boolean} Whether at least one corner has a value.
 */
function hasRadius( value ) {
	if ( ! value ) {
		return false;
	}

	if ( typeof value === 'string' ) {
		return true;
	}

	return Object.values( value ).some( ( corner ) => !! corner );
}

/**
 * Sidebar panel for the corner radius of book covers. Shared by all blocks.
 *
 * It is shown on the Styles tab, and uses the same control as the Radius
 * setting in core's Border panel: units, the theme's radius presets, and
 * separate values per corner. Without a value, the covers use the radius the
 * theme sets, or 4px if the theme sets none.
 *
 * @param {Object}                                   props          Component props.
 * @param {string|Object|undefined}                  props.value    One radius for all corners, or one per corner.
 * @param {(value: string|Object|undefined) => void} props.onChange Called with the new radius, or undefined on reset.
 * @return {Element} The panel element.
 */
export default function CoverRadiusPanel( { value, onChange } ) {
	const [ radiusSizes ] = useSettings( 'border.radiusSizes' );
	const reset = () => onChange( undefined );

	return (
		<InspectorControls group="styles">
			<ToolsPanel label={ __( 'Cover', 'endpaper' ) } resetAll={ reset }>
				<ToolsPanelItem
					label={ __( 'Radius', 'endpaper' ) }
					hasValue={ () => hasRadius( value ) }
					onDeselect={ reset }
					isShownByDefault
				>
					<BorderRadiusControl
						values={ value }
						presets={ radiusSizes }
						onChange={ ( radius ) =>
							onChange( hasRadius( radius ) ? radius : undefined )
						}
					/>
				</ToolsPanelItem>
			</ToolsPanel>
		</InspectorControls>
	);
}
