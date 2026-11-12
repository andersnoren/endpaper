/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, isRTL } from '@wordpress/i18n';
import {
	AlignmentControl,
	BlockControls,
	HeadingLevelDropdown,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
/* eslint-disable @wordpress/no-unsafe-wp-apis -- The segmented control core uses for choices like these has no stable equivalent yet. */
import {
	Notice,
	PanelBody,
	Placeholder,
	RangeControl,
	Spinner,
	ToggleControl,
	ToolbarButton,
	ToolbarGroup,
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { addQueryArgs } from '@wordpress/url';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import BookSearch from './book-search';
import CardPlaceholder from './card-placeholder';
import EditionPicker from './edition-picker';
import AccentColorControl from '../../components/accent-color-control';
import CoverRadiusPanel from '../../components/cover-radius-panel';
import getEditorData from '../../utils/editor-data';
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
 * The parts of the book itself that can be shown or hidden, in display order.
 */
const DISPLAY_TOGGLES = [
	{ attribute: 'showCover', label: __( 'Cover', 'endpaper' ) },
	{ attribute: 'showAuthors', label: __( 'Authors', 'endpaper' ) },
	{ attribute: 'showMeta', label: __( 'Year and page count', 'endpaper' ) },
	{ attribute: 'showRating', label: __( 'Hardcover rating', 'endpaper' ) },
	{ attribute: 'showDescription', label: __( 'Description', 'endpaper' ) },
];

/**
 * The parts that come from the site owner's own activity on the book.
 */
const READING_TOGGLES = [
	{ attribute: 'showShelf', label: __( 'Your shelf', 'endpaper' ) },
	{ attribute: 'showUserRating', label: __( 'Your rating', 'endpaper' ) },
	{
		attribute: 'showProgress',
		onlyOnShelf: CURRENTLY_READING,
		label: __( 'Your reading progress', 'endpaper' ),
	},
	{
		attribute: 'showReview',
		label: __( 'Your review', 'endpaper' ),
		help: __(
			'Shown in place of the description, if you have written one.',
			'endpaper'
		),
	},
];

/**
 * Editor component for the Book block.
 *
 * Shows a search field until a book is picked, then a server-rendered preview.
 *
 * @param {Object}                       props               Component props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {string}                       props.clientId      The block's client ID.
 * @param {boolean}                      props.isSelected    Whether the block is selected.
 * @param {(attributes: Object) => void} props.setAttributes Attribute setter.
 * @return {Element} The editor element.
 */
export default function Edit( {
	attributes,
	clientId,
	isSelected,
	setAttributes,
} ) {
	const wrapper = useRef( null );

	// A book just picked here fades in, and focus moves to the block, so it is not lost with the search field.
	const [ justPicked, setJustPicked ] = useState( false );
	const blockProps = useBlockProps( {
		ref: wrapper,
		className: justPicked ? 'endpaper-is-new' : undefined,
		onAnimationEnd: () => setJustPicked( false ),
	} );
	const { keyTrouble, settingsUrl } = getEditorData();

	useEffect( () => {
		if ( justPicked && attributes.bookId ) {
			wrapper.current?.focus();
		}
	}, [ justPicked, attributes.bookId ] );

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

	// A link to a book that was pasted into the editor is looked up, to find the book it points to.
	const [ lookupError, setLookupError ] = useState( '' );

	useEffect( () => {
		if ( attributes.bookId || ! attributes.bookUrl ) {
			return;
		}

		let isCurrent = true;
		setLookupError( '' );

		apiFetch( {
			path: addQueryArgs( '/endpaper/v1/search', {
				q: attributes.bookUrl,
			} ),
		} )
			.then( ( books ) => {
				if ( ! isCurrent ) {
					return;
				}

				if ( Array.isArray( books ) && books[ 0 ]?.id ) {
					setAttributes( {
						bookId: books[ 0 ].id,
						bookUrl: undefined,
					} );
					return;
				}

				setLookupError(
					__(
						'That book could not be found on Hardcover.',
						'endpaper'
					)
				);
				setAttributes( { bookUrl: undefined } );
			} )
			.catch( ( error ) => {
				if ( ! isCurrent ) {
					return;
				}

				setLookupError(
					error?.message ||
						__(
							'Something went wrong while searching.',
							'endpaper'
						)
				);
				setAttributes( { bookUrl: undefined } );
			} );

		return () => {
			isCurrent = false;
		};
	}, [ attributes.bookId, attributes.bookUrl ] ); // eslint-disable-line react-hooks/exhaustive-deps -- setAttributes does not change.

	if ( ! attributes.bookId ) {
		let placeholderBody;

		if ( keyTrouble ) {
			placeholderBody = (
				<Notice status="warning" isDismissible={ false }>
					{ 'rejected' === keyTrouble
						? __(
								'Hardcover did not accept the API key.',
								'endpaper'
							)
						: __(
								'Add your Hardcover API key to search for books.',
								'endpaper'
							) }{ ' ' }
					{ settingsUrl ? (
						<a href={ settingsUrl }>
							{ __( 'Go to the Endpaper settings', 'endpaper' ) }
						</a>
					) : (
						__(
							'Ask a site administrator to check the Endpaper settings.',
							'endpaper'
						)
					) }
				</Notice>
			);
		} else if ( attributes.bookUrl ) {
			placeholderBody = (
				<p className="endpaper-book-lookup">
					<Spinner />
					{ __( 'Finding the book on Hardcover…', 'endpaper' ) }
				</p>
			);
		} else {
			placeholderBody = (
				<>
					{ lookupError && (
						<Notice status="error" isDismissible={ false }>
							{ lookupError }
						</Notice>
					) }
					<BookSearch
						focusOnMount={ isSelected }
						onSelect={ ( bookId ) => {
							setJustPicked( true );
							setAttributes( {
								bookId,
								editionId: undefined,
							} );
						} }
					/>
				</>
			);
		}

		return (
			<div { ...blockProps }>
				<Placeholder
					label={ __( 'Book', 'endpaper' ) }
					instructions={
						keyTrouble
							? undefined
							: __(
									'Search Hardcover for a book, or paste a link to its Hardcover page.',
									'endpaper'
								)
					}
				>
					{ placeholderBody }
				</Placeholder>
			</div>
		);
	}

	// A list of switches. Those that only apply to a certain shelf stay hidden until the shelf is known.
	const renderToggles = ( toggles ) =>
		toggles
			.filter(
				( { onlyOnShelf } ) => ! onlyOnShelf || onlyOnShelf === shelf
			)
			.map( ( { attribute, label, help } ) => (
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
			) );

	// How many characters of the description or the review to show.
	const renderLength = ( attribute, label ) => (
		<RangeControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			label={ label }
			help={ __( '0 shows everything.', 'endpaper' ) }
			min={ 0 }
			max={ 1000 }
			step={ 20 }
			value={ attributes[ attribute ] }
			onChange={ ( value ) =>
				setAttributes( { [ attribute ]: value || 0 } )
			}
		/>
	);

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
				<AlignmentControl
					value={ attributes.textAlign }
					onChange={ ( textAlign ) => setAttributes( { textAlign } ) }
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
						{ __( 'Change book', 'endpaper' ) }
					</ToolbarButton>
				</ToolbarGroup>
			</BlockControls>

			<InspectorControls>
				<PanelBody title={ __( 'Display', 'endpaper' ) }>
					{ renderToggles( DISPLAY_TOGGLES ) }
					{ attributes.showDescription &&
						renderLength(
							'descriptionLength',
							__( 'Description length', 'endpaper' )
						) }
				</PanelBody>

				<PanelBody
					title={ __( 'Your reading', 'endpaper' ) }
					initialOpen={ false }
				>
					<p className="endpaper-panel-note">
						{ __(
							'These show only if your activity on the book is public on Hardcover.',
							'endpaper'
						) }
					</p>
					{ renderToggles( READING_TOGGLES ) }
					{ attributes.showReview &&
						renderLength(
							'reviewLength',
							__( 'Review length', 'endpaper' )
						) }
				</PanelBody>

				<PanelBody
					title={ __( 'Hardcover', 'endpaper' ) }
					initialOpen={ false }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Link title and cover to Hardcover',
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
						checked={ !! attributes.showCredit }
						onChange={ ( showCredit ) =>
							setAttributes( { showCredit } )
						}
					/>
				</PanelBody>

				<PanelBody
					title={ __( 'Layout', 'endpaper' ) }
					initialOpen={ false }
				>
					{ attributes.showCover && (
						<>
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
						</>
					) }
					<ToggleGroupControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						isBlock
						label={ __( 'Text alignment', 'endpaper' ) }
						value={
							attributes.textAlign ||
							( isRTL() ? 'right' : 'left' )
						}
						onChange={ ( textAlign ) =>
							setAttributes( { textAlign } )
						}
					>
						<ToggleGroupControlOption
							value="left"
							label={ __( 'Left', 'endpaper' ) }
						/>
						<ToggleGroupControlOption
							value="center"
							label={ __( 'Center', 'endpaper' ) }
						/>
						<ToggleGroupControlOption
							value="right"
							label={ __( 'Right', 'endpaper' ) }
						/>
					</ToggleGroupControl>
				</PanelBody>

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
					LoadingResponsePlaceholder={ () => (
						<CardPlaceholder attributes={ attributes } />
					) }
				/>
			</div>
		</>
	);
}
