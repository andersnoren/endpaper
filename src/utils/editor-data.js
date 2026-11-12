/**
 * What the plugin tells the editor about the site, set by PHP in
 * `window.endpaperEditor` before the block script runs.
 *
 * - keyTrouble: "missing" or "rejected" when the API key cannot be used.
 * - settingsUrl: the Endpaper settings screen, only for people who can open it.
 *
 * @return {{keyTrouble: string, settingsUrl: string}} The values, empty when unknown.
 */
export default function getEditorData() {
	const data = window.endpaperEditor || {};

	return {
		keyTrouble: data.keyTrouble || '',
		settingsUrl: data.settingsUrl || '',
	};
}
