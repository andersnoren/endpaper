/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import metadata from './block.json';

/**
 * The reading statuses a list can show. The values are Hardcover's status IDs.
 */
const STATUS_OPTIONS = [
	{ value: 2, label: __( 'Currently Reading', 'endpaper' ) },
	{ value: 3, label: __( 'Read', 'endpaper' ) },
	{ value: 1, label: __( 'Want to Read', 'endpaper' ) },
];

const LAYOUT_OPTIONS = [
	{ value: 'list', label: __( 'List', 'endpaper' ) },
	{ value: 'grid', label: __( 'Grid', 'endpaper' ) },
];

/**
 * The parts of each book that can be shown or hidden, in display order.
 */
const DISPLAY_TOGGLES = [
	{ attribute: 'showCover', label: __( 'Cover', 'endpaper' ) },
	{ attribute: 'showAuthors', label: __( 'Authors', 'endpaper' ) },
	{ attribute: 'showRating', label: __( 'Your rating', 'endpaper' ) },
	{ attribute: 'showProgress', label: __( 'Reading progress', 'endpaper' ) },
];

/**
 * Editor component for the Reading List block.
 *
 * The list itself comes from the server, so the editor shows a server-rendered preview.
 *
 * @param {Object}                       props               Component props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Attribute setter.
 * @return {Element} The editor element.
 */
export default function Edit( { attributes, setAttributes } ) {
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Books', 'endpaper' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Status', 'endpaper' ) }
						help={ __(
							'Only books that are public on Hardcover are shown.',
							'endpaper'
						) }
						value={ attributes.status }
						options={ STATUS_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { status: parseInt( value, 10 ) } )
						}
					/>
					<RangeControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Number of books', 'endpaper' ) }
						min={ 1 }
						max={ 20 }
						value={ attributes.count }
						onChange={ ( value ) =>
							setAttributes( { count: value || 1 } )
						}
					/>
				</PanelBody>

				<PanelBody title={ __( 'Display', 'endpaper' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Layout', 'endpaper' ) }
						value={ attributes.layout }
						options={ LAYOUT_OPTIONS }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
					/>
					{ DISPLAY_TOGGLES.map( ( { attribute, label } ) => (
						<ToggleControl
							key={ attribute }
							__nextHasNoMarginBottom
							label={ label }
							checked={ !! attributes[ attribute ] }
							onChange={ ( value ) =>
								setAttributes( { [ attribute ]: value } )
							}
						/>
					) ) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
					skipBlockSupportAttributes
				/>
			</div>
		</>
	);
}
