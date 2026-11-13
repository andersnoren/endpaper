/**
 * What the plugin tells the editor about the site, set by PHP in
 * `window.endpaperEditor` before the block script runs.
 *
 * - keyTrouble: "missing" or "rejected" when the API key cannot be used.
 * - settingsUrl: the Endpaper settings screen, only for people who can open it.
 * - defaultBookStyle: the name of the style new Book blocks start with, or
 *   "default" for the Plain style.
 * - searchResults: how many books one page of search results holds.
 *
 * @return {{keyTrouble: string, settingsUrl: string, defaultBookStyle: string, searchResults: number}} The values, empty when unknown.
 */
export default function getEditorData() {
	const data = window.endpaperEditor || {};

	return {
		keyTrouble: data.keyTrouble || '',
		settingsUrl: data.settingsUrl || '',
		defaultBookStyle: data.defaultBookStyle || 'default',
		searchResults: Number( data.searchResults ) || 8,
	};
}
