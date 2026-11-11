/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import {
	BlockControls,
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
import metadata from './block.json';
import './editor.scss';

/**
 * The parts of the book that can be shown or hidden, in display order.
 */
const DISPLAY_TOGGLES = [
	{ attribute: 'showCover', label: __( 'Cover', 'endpaper' ) },
	{ attribute: 'showAuthors', label: __( 'Authors', 'endpaper' ) },
	{ attribute: 'showMeta', label: __( 'Year and page count', 'endpaper' ) },
	{ attribute: 'showRating', label: __( 'Hardcover rating', 'endpaper' ) },
	{ attribute: 'showDescription', label: __( 'Description', 'endpaper' ) },
	{ attribute: 'showLink', label: __( 'Link to Hardcover', 'endpaper' ) },
];

/**
 * Editor component for the Book block.
 *
 * Shows a search field until a book is picked, then a server-rendered preview.
 *
 * @param {Object}                       props               Component props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {(attributes: Object) => void} props.setAttributes Attribute setter.
 * @return {Element} The editor element.
 */
export default function Edit( { attributes, setAttributes } ) {
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

					{ attributes.showDescription && (
						<RangeControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Description lines', 'endpaper' ) }
							help={ __(
								'Set to 0 to show the full description.',
								'endpaper'
							) }
							min={ 0 }
							max={ 12 }
							value={ attributes.descriptionLines }
							onChange={ ( value ) =>
								setAttributes( {
									descriptionLines: value || 0,
								} )
							}
						/>
					) }
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
