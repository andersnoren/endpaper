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
	Placeholder,
	RangeControl,
	ToggleControl,
	ToolbarButton,
	ToolbarGroup,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import BookSearch from './book-search';
import AccentColorControl from '../../components/accent-color-control';
import CoverRadiusPanel from '../../components/cover-radius-panel';
import metadata from './block.json';
import './editor.scss';

/**
 * The levels the title can have. 0 is a paragraph, the others are heading levels.
 */
const TITLE_LEVELS = [ 0, 2, 3, 4, 5, 6 ];

/**
 * The parts of the book that can be shown or hidden, in display order.
 */
const DISPLAY_TOGGLES = [
	{ attribute: 'showCover', label: __( 'Cover', 'endpaper' ) },
	{ attribute: 'showAuthors', label: __( 'Authors', 'endpaper' ) },
	{ attribute: 'showMeta', label: __( 'Year and page count', 'endpaper' ) },
	{ attribute: 'showRating', label: __( 'Hardcover rating', 'endpaper' ) },
	{
		attribute: 'showShelf',
		label: __( 'Your shelf', 'endpaper' ),
		help: __(
			'Shows which of your Hardcover shelves the book is on, such as Currently Reading. Only if the book is public in your library.',
			'endpaper'
		),
	},
	{
		attribute: 'showProgress',
		label: __( 'Your reading progress', 'endpaper' ),
		help: __(
			'Shown while the book is on your Currently Reading shelf.',
			'endpaper'
		),
	},
	{ attribute: 'showDescription', label: __( 'Description', 'endpaper' ) },
	{
		attribute: 'showLink',
		label: __( 'Credit and link to Hardcover', 'endpaper' ),
		help: __(
			'Adds “Powered by Hardcover” under the card, linked to the book on Hardcover.',
			'endpaper'
		),
	},
];

/**
 * Returns the attributes to render the preview with.
 *
 * The block's wrapper in the editor already has the typography, margin and
 * extra class name applied, so those are left out to avoid applying them
 * twice. The settings that belong on the card, such as border and padding,
 * are kept, since only the server-rendered preview can apply them.
 *
 * @param {Object} attributes Block attributes.
 * @return {Object} The attributes for the preview.
 */
function getPreviewAttributes( attributes ) {
	const { className, fontFamily, fontSize, style = {}, ...rest } = attributes;
	const { elements, spacing = {}, typography, ...restStyle } = style;

	return {
		...rest,
		style: { ...restStyle, spacing: { padding: spacing.padding } },
	};
}

/**
 * Editor component for the Book block.
 *
 * Shows a search field until a book is picked, then a server-rendered preview.
 *
 * @param {Object}                       props               Component props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {string}                       props.clientId      The block's client ID.
 * @param {(attributes: Object) => void} props.setAttributes Attribute setter.
 * @return {Element} The editor element.
 */
export default function Edit( { attributes, clientId, setAttributes } ) {
	const blockProps = useBlockProps();

	if ( ! attributes.bookId ) {
		return (
			<div { ...blockProps }>
				<Placeholder
					icon="book"
					label={ __( 'Book', 'endpaper' ) }
					instructions={ __(
						'Search Hardcover for a book, or paste a link to its Hardcover page.',
						'endpaper'
					) }
				>
					<BookSearch
						onSelect={ ( bookId ) => setAttributes( { bookId } ) }
					/>
				</Placeholder>
			</div>
		);
	}

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

			<BlockControls>
				<ToolbarGroup>
					<ToolbarButton
						onClick={ () => setAttributes( { bookId: undefined } ) }
					>
						{ __( 'Replace', 'endpaper' ) }
					</ToolbarButton>
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Display', 'endpaper' ) }>
					{ DISPLAY_TOGGLES.map( ( { attribute, label, help } ) => (
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

					{ attributes.showDescription && (
						<RangeControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Description length', 'endpaper' ) }
							help={ __(
								'The most characters to show. The text ends on a whole sentence where it can. Set to 0 to show the full description.',
								'endpaper'
							) }
							min={ 0 }
							max={ 1000 }
							step={ 20 }
							value={ attributes.descriptionLength }
							onChange={ ( value ) =>
								setAttributes( {
									descriptionLength: value || 0,
								} )
							}
						/>
					) }
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
