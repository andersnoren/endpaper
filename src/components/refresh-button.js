/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { ToolbarButton, ToolbarGroup } from '@wordpress/components';
/* eslint-disable import/no-unresolved -- WordPress provides this package in the editor, and the build leaves it out. */
import { useDispatch } from '@wordpress/data';
/* eslint-enable import/no-unresolved */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { BlockControls } from '@wordpress/block-editor';

/**
 * A toolbar button that fetches the block's data from Hardcover again.
 *
 * It is for the site owner's own activity, such as a shelf, a rating or
 * reading progress. The saved copy on the server is brought up to date, and
 * the block then draws its preview again.
 *
 * @param {Object}     props             Component props.
 * @param {Object}     props.request     What to fetch again: a kind, and the IDs that go with it.
 * @param {() => void} props.onRefreshed Called once the data is fetched, so the preview can be drawn again.
 * @return {Element} The toolbar button.
 */
export default function RefreshButton( { request, onRefreshed } ) {
	const [ isBusy, setIsBusy ] = useState( false );
	const { createErrorNotice, createSuccessNotice } =
		useDispatch( 'core/notices' );

	const refresh = () => {
		setIsBusy( true );

		apiFetch( {
			path: '/endpaper/v1/refresh',
			method: 'POST',
			data: request,
		} )
			.then( () => {
				onRefreshed();
				createSuccessNotice(
					__( 'Updated from Hardcover.', 'endpaper' ),
					{
						type: 'snackbar',
					}
				);
			} )
			.catch( ( error ) => {
				createErrorNotice(
					error?.message ||
						__( 'Could not update from Hardcover.', 'endpaper' ),
					{ type: 'snackbar' }
				);
			} )
			.finally( () => setIsBusy( false ) );
	};

	return (
		<BlockControls>
			<ToolbarGroup>
				<ToolbarButton
					icon="update"
					label={ __( 'Update from Hardcover', 'endpaper' ) }
					onClick={ refresh }
					disabled={ isBusy }
					isBusy={ isBusy }
				/>
			</ToolbarGroup>
		</BlockControls>
	);
}
