/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	BlockControls,
	HeadingLevelDropdown,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
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
import AccentColorControl from '../../components/accent-color-control';
import CoverRadiusPanel from '../../components/cover-radius-panel';
import getPreviewAttributes from '../../utils/get-preview-attributes';
import metadata from './block.json';
import './editor.scss';

/**
 * The levels the book titles can have. 0 is a paragraph, the others are heading levels.
 */
const TITLE_LEVELS = [ 0, 2, 3, 4, 5, 6 ];

/**
 * Hardcover's status IDs for the lists the block can show.
 */
const WANT_TO_READ = 1;
const CURRENTLY_READING = 2;
const READ = 3;

const STATUS_OPTIONS = [
	{ value: CURRENTLY_READING, label: __( 'Currently Reading', 'endpaper' ) },
	{ value: READ, label: __( 'Read', 'endpaper' ) },
	{ value: WANT_TO_READ, label: __( 'Want to Read', 'endpaper' ) },
];

const LAYOUT_OPTIONS = [
	{ value: 'list', label: __( 'List', 'endpaper' ) },
	{ value: 'grid', label: __( 'Grid', 'endpaper' ) },
];

/**
 * The parts of each book that can be shown or hidden, in display order.
 *
 * Some only apply to one list: progress to the books being read, the rating
 * and the date to the finished ones.
 */
const DISPLAY_TOGGLES = [
	{ attribute: 'showCover', label: __( 'Cover', 'endpaper' ) },
	{ attribute: 'showAuthors', label: __( 'Authors', 'endpaper' ) },
	{
		attribute: 'showProgress',
		onlyForStatus: CURRENTLY_READING,
		label: __( 'Reading progress', 'endpaper' ),
	},
	{
		attribute: 'showRating',
		onlyForStatus: READ,
		label: __( 'Your rating', 'endpaper' ),
	},
	{
		attribute: 'showDate',
		onlyForStatus: READ,
		label: __( 'Date finished', 'endpaper' ),
	},
	{
		attribute: 'linkToHardcover',
		label: __( 'Link to Hardcover', 'endpaper' ),
		help: __(
			'Links the title and the cover to the book on Hardcover.',
			'endpaper'
		),
	},
];

/**
 * Turns the Block spacing setting into a CSS value.
 *
 * The setting is either a plain length, or a reference to one of the theme's
 * spacing presets, stored as "var:preset|spacing|{slug}".
 *
 * @param {string|undefined} blockGap The stored setting.
 * @return {string|undefined} A CSS value, or undefined if nothing is set.
 */
function getGapValue( blockGap ) {
	if ( typeof blockGap !== 'string' || ! blockGap ) {
		return undefined;
	}

	const preset = blockGap.match( /^var:preset\|spacing\|(.+)$/ );

	return preset ? `var(--wp--preset--spacing--${ preset[ 1 ] })` : blockGap;
}

/**
 * Editor component for the Reading List block.
 *
 * The list itself comes from the server, so the editor shows a server-rendered preview.
 *
 * @param {Object}                       props               Component props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {string}                       props.clientId      The block's client ID.
 * @param {(attributes: Object) => void} props.setAttributes Attribute setter.
 * @return {Element} The editor element.
 */
export default function Edit( { attributes, clientId, setAttributes } ) {
	// The preview is rendered without the Block spacing setting, so the gap is set here.
	const blockProps = useBlockProps( {
		style: {
			'--endpaper-gap': getGapValue(
				attributes.style?.spacing?.blockGap
			),
		},
	} );

	return (
		<>
			<BlockControls group="block">
				<HeadingLevelDropdown
					value={ attributes.titleLevel }
					options={ TITLE_LEVELS }
					onChange={ ( titleLevel ) =>
						setAttributes( { titleLevel } )
					}
				/>
			</BlockControls>

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
					{ DISPLAY_TOGGLES.filter(
						( { onlyForStatus } ) =>
							! onlyForStatus ||
							onlyForStatus === attributes.status
					).map( ( { attribute, label, help } ) => (
						<ToggleControl
							key={ attribute }
							__nextHasNoMarginBottom
							label={ label }
							help={ help }
							checked={ !! attributes[ attribute ] }
							onChange={ ( value ) =>
								setAttributes( { [ attribute ]: value } )
							}
						/>
					) ) }
				</PanelBody>
			</InspectorControls>

			<AccentColorControl
				clientId={ clientId }
				value={ attributes.accentColor }
				onChange={ ( accentColor ) => setAttributes( { accentColor } ) }
			/>

			{ attributes.showCover && (
				<CoverRadiusPanel
					value={ attributes.coverRadius }
					onChange={ ( coverRadius ) =>
						setAttributes( { coverRadius } )
					}
				/>
			) }

			<div { ...blockProps }>
				<ServerSideRender
					block={ metadata.name }
					attributes={ getPreviewAttributes( attributes ) }
				/>
			</div>
		</>
	);
}
