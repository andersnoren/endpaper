/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import getEditorData from '../utils/editor-data';

/**
 * A warning about the API key, with a link to the settings.
 *
 * It looks like the notices the server draws in the editor, and says the
 * same things. The link opens in a new tab, so the post stays open. People who
 * cannot open the settings are told to ask someone who can.
 *
 * @param {Object}  props          Component props.
 * @param {Element} props.children What is wrong with the key.
 * @return {Element} The notice.
 */
export default function SettingsNotice( { children } ) {
	const { settingsUrl } = getEditorData();

	return (
		<Notice status="warning" isDismissible={ false }>
			{ children }{ ' ' }
			{ settingsUrl ? (
				<a
					href={ settingsUrl }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __( 'Go to the Endpaper settings.', 'endpaper' ) }
				</a>
			) : (
				__(
					'Ask a site administrator to check the Endpaper settings.',
					'endpaper'
				)
			) }
		</Notice>
	);
}
