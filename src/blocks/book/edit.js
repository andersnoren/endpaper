/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	BlockControls,
	HeadingLevelDropdown,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
/* eslint-disable @wordpress/no-unsafe-wp-apis -- The segmented control core uses for choices like these has no stable equivalent yet. */
import {
	PanelBody,
	Placeholder,
	RangeControl,
	ToggleControl,
	ToolbarButton,
	ToolbarGroup,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import BookSearch from './book-search';
import EditionPicker from './edition-picker';
import AccentColorControl from '../../components/accent-color-control';
import CoverRadiusPanel from '../../components/cover-radius-panel';
import getPreviewAttributes from '../../utils/get-preview-attributes';
import metadata from './block.json';
import './editor.scss';

/**
 * The levels the title can have. 0 is a paragraph, the others are heading levels.
 */
const TITLE_LEVELS = [ 0, 2, 3, 4, 5, 6 ];

/**
 * Hardcover's status ID for the Currently Reading shelf.
 */
const CURRENTLY_READING = 2;

/**
 * The parts of the book that can be shown or hidden, in display order.
 */
const DISPLAY_TOGGLES = [
	{ attribute: 'showCover', label: __( 'Cover', 'endpaper' ) },
	{ attribute: 'showAuthors', label: __( 'Authors', 'endpaper' ) },
	{ attribute: 'showMeta', label: __( 'Year and page count', 'endpaper' ) },
	{ attribute: 'showRating', label: __( 'Hardcover rating', 'endpaper' ) },
	{ attribute: 'showShelf', label: __( 'Your shelf', 'endpaper' ) },
	{ attribute: 'showUserRating', label: __( 'Your rating', 'endpaper' ) },
	{
		attribute: 'showProgress',
		onlyOnShelf: CURRENTLY_READING,
		label: __( 'Your reading progress', 'endpaper' ),
	},
	{ attribute: 'showDescription', label: __( 'Description', 'endpaper' ) },
];

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

	// The shelf the book is on in the site owner's library, once known. Some settings only apply to certain shelves.
	const [ shelf, setShelf ] = useState( null );

	useEffect( () => {
		setShelf( null );

		if ( ! attributes.bookId ) {
			return;
		}

		let isCurrent = true;

		apiFetch( {
			path: `/endpaper/v1/library-entry/${ attributes.bookId }`,
		} )
			.then( ( entry ) => {
				if ( isCurrent ) {
					setShelf( entry?.status_id || 0 );
				}
			} )
			.catch( () => {
				// Without an answer, the shelf stays unknown and the settings that depend on it stay hidden.
			} );

		return () => {
			isCurrent = false;
		};
	}, [ attributes.bookId ] );

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
						onSelect={ ( bookId ) =>
							setAttributes( { bookId, editionId: undefined } )
						}
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
						onClick={ () =>
							setAttributes( {
								bookId: undefined,
								editionId: undefined,
							} )
						}
					>
						{ __( 'Replace', 'endpaper' ) }
					</ToolbarButton>
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Display', 'endpaper' ) }>
					<p className="endpaper-panel-note">
						{ __(
							'Your shelf, rating and progress show only if your activity on the book is public on Hardcover.',
							'endpaper'
						) }
					</p>
					{ DISPLAY_TOGGLES.filter(
						( { onlyOnShelf } ) =>
							! onlyOnShelf || onlyOnShelf === shelf
					).map( ( { attribute, label } ) => (
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
							label={ __( 'Description length', 'endpaper' ) }
							help={ __(
								'The most characters to show, ending on a whole sentence. 0 shows everything.',
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

					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Link to Hardcover', 'endpaper' ) }
						help={ __(
							'Links the title and cover to the book on Hardcover.',
							'endpaper'
						) }
						checked={ !! attributes.linkToHardcover }
						onChange={ ( linkToHardcover ) =>
							setAttributes( { linkToHardcover } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Hardcover logo', 'endpaper' ) }
						help={ __(
							'Credits Hardcover with their logo, linked to the book there.',
							'endpaper'
						) }
						checked={ !! attributes.showCredit }
						onChange={ ( showCredit ) =>
							setAttributes( { showCredit } )
						}
					/>
				</PanelBody>

				{ attributes.showCover && (
					<PanelBody title={ __( 'Layout', 'endpaper' ) }>
						<ToggleGroupControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							isBlock
							label={ __( 'Cover position', 'endpaper' ) }
							value={ attributes.coverPosition || 'left' }
							onChange={ ( coverPosition ) =>
								setAttributes( { coverPosition } )
							}
						>
							<ToggleGroupControlOption
								value="left"
								label={ __( 'Left', 'endpaper' ) }
							/>
							<ToggleGroupControlOption
								value="right"
								label={ __( 'Right', 'endpaper' ) }
							/>
							<ToggleGroupControlOption
								value="top"
								label={ __( 'Top', 'endpaper' ) }
							/>
						</ToggleGroupControl>
						<ToggleGroupControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							isBlock
							label={ __( 'Cover size', 'endpaper' ) }
							value={ attributes.coverSize || 'medium' }
							onChange={ ( coverSize ) =>
								setAttributes( { coverSize } )
							}
						>
							<ToggleGroupControlOption
								value="small"
								label={ __( 'Small', 'endpaper' ) }
							/>
							<ToggleGroupControlOption
								value="medium"
								label={ __( 'Medium', 'endpaper' ) }
							/>
							<ToggleGroupControlOption
								value="large"
								label={ __( 'Large', 'endpaper' ) }
							/>
						</ToggleGroupControl>
					</PanelBody>
				) }

				{ attributes.showCover && (
					<PanelBody
						title={ __( 'Cover', 'endpaper' ) }
						initialOpen={ false }
					>
						<EditionPicker
							bookId={ attributes.bookId }
							value={ attributes.editionId }
							onChange={ ( editionId ) =>
								setAttributes( { editionId } )
							}
						/>
					</PanelBody>
				) }
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
