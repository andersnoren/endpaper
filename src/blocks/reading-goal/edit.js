/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	BlockControls,
	HeadingLevelDropdown,
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';

/**
 * Internal dependencies
 */
import AccentColorControl from '../../components/accent-color-control';
import BlockIcon from '../../components/block-icon';
import RefreshButton from '../../components/refresh-button';
import getPreviewAttributes from '../../utils/get-preview-attributes';
import hasFrame from '../../utils/has-frame';
import metadata from './block.json';
import './editor.scss';

/**
 * The levels the title can have. 0 is a paragraph, the others are heading levels.
 */
const TITLE_LEVELS = [ 0, 2, 3, 4, 5, 6 ];

/**
 * Editor component for the Reading Goal block.
 *
 * The goal itself comes from the server, so the editor shows a server-rendered
 * preview. The goals to pick from come from the plugin's own REST route.
 *
 * @param {Object}                       props               Component props.
 * @param {Object}                       props.attributes    Block attributes.
 * @param {string}                       props.clientId      The block's client ID.
 * @param {(attributes: Object) => void} props.setAttributes Attribute setter.
 * @return {Element} The editor element.
 */
export default function Edit( { attributes, clientId, setAttributes } ) {
	const { goalId } = attributes;
	const blockProps = useBlockProps( {
		className: hasFrame( attributes )
			? 'endpaper-reading-goal--framed'
			: undefined,
	} );
	const [ goals, setGoals ] = useState( null );
	const [ error, setError ] = useState( '' );

	// Counts how many times the data has been fetched again, so that the preview starts over each time.
	const [ refreshCount, setRefreshCount ] = useState( 0 );

	useEffect( () => {
		let current = true;

		apiFetch( { path: '/endpaper/v1/goals' } )
			.then( ( result ) => current && setGoals( result ) )
			.catch(
				( failure ) =>
					current &&
					setError(
						failure?.message ||
							__( 'The goals could not be loaded.', 'endpaper' )
					)
			);

		return () => {
			current = false;
		};
	}, [] );

	// With a single goal there is nothing to choose, so it is picked right away. The block keeps showing it if more goals are added later.
	useEffect( () => {
		if ( ! goalId && goals?.length === 1 ) {
			setAttributes( { goalId: goals[ 0 ].id } );
		}
	}, [ goalId, goals, setAttributes ] );

	const options = ( goals || [] ).map( ( goal ) => ( {
		value: goal.id,
		label:
			goal.title ||
			/* translators: %d: A goal's number on Hardcover. */
			sprintf( __( 'Goal %d', 'endpaper' ), goal.id ),
	} ) );

	const pick = ( value ) =>
		setAttributes( { goalId: parseInt( value, 10 ) || 0 } );

	let body;

	if ( error ) {
		body = (
			<Notice status="warning" isDismissible={ false }>
				{ error }
			</Notice>
		);
	} else if ( null === goals ) {
		body = (
			<Placeholder
				icon={ <BlockIcon name="reading-goal" /> }
				label={ metadata.title }
			>
				<Spinner />
			</Placeholder>
		);
	} else if ( ! goalId && goals.length > 1 ) {
		body = (
			<Placeholder
				icon={ <BlockIcon name="reading-goal" /> }
				label={ metadata.title }
				instructions={ __(
					'Choose which of your Hardcover goals to show.',
					'endpaper'
				) }
			>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Goal', 'endpaper' ) }
					hideLabelFromVision
					value=""
					options={ [
						{
							value: '',
							label: __( 'Choose a goal…', 'endpaper' ),
						},
						...options,
					] }
					onChange={ pick }
				/>
			</Placeholder>
		);
	} else {
		const previewAttributes = getPreviewAttributes( attributes );

		// A new style has different markup, so the preview starts over. The preview debounces changes and drops one if the editor draws again within half a second, which a change of style does.
		body = (
			<ServerSideRender
				key={ `${ previewAttributes.className || 'default' }-${ refreshCount }` }
				block={ metadata.name }
				attributes={ previewAttributes }
			/>
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

			{ !! goalId && (
				<RefreshButton
					request={ { kind: 'goals' } }
					setAttributes={ setAttributes }
					onRefreshed={ () =>
						setRefreshCount( ( count ) => count + 1 )
					}
				/>
			) }

			<InspectorControls>
				<PanelBody title={ __( 'Goal', 'endpaper' ) }>
					{ options.length > 0 && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Goal', 'endpaper' ) }
							help={ __(
								'Only shows goals that are public on Hardcover.',
								'endpaper'
							) }
							value={ goalId || '' }
							options={ options }
							onChange={ pick }
						/>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Display', 'endpaper' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Title', 'endpaper' ) }
						checked={ !! attributes.showTitle }
						onChange={ ( showTitle ) =>
							setAttributes( { showTitle } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Days left', 'endpaper' ) }
						checked={ !! attributes.showDays }
						onChange={ ( showDays ) =>
							setAttributes( { showDays } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Percentage', 'endpaper' ) }
						checked={ !! attributes.showPercent }
						onChange={ ( showPercent ) =>
							setAttributes( { showPercent } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Hardcover logo', 'endpaper' ) }
						help={ __(
							'Credits Hardcover with their logo, under the goal.',
							'endpaper'
						) }
						checked={ !! attributes.showCredit }
						onChange={ ( showCredit ) =>
							setAttributes( { showCredit } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			<AccentColorControl
				clientId={ clientId }
				value={ attributes.accentColor }
				onChange={ ( accentColor ) => setAttributes( { accentColor } ) }
			/>

			<div { ...blockProps }>{ body }</div>
		</>
	);
}
