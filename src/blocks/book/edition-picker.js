/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Notice, SelectControl, Spinner } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * How many covers to show at first, and how many more each time more are asked for.
 */
const PAGE_SIZE = 30;

/**
 * Describes an edition in a few words, for people who cannot see its cover.
 *
 * @param {Object} edition An edition, as returned by the REST route.
 * @return {string} The edition's format, year and language, where known.
 */
function getEditionLabel( edition ) {
	const parts = [ edition.format, edition.year, edition.language ].filter(
		Boolean
	);

	return parts.length ? parts.join( ', ' ) : edition.title;
}

/**
 * Returns the English name of a locale's language, which is how Hardcover names languages.
 *
 * @param {string} locale A WordPress locale, such as sv_SE.
 * @return {string} The language's name, such as Swedish, or an empty string if it is not known.
 */
function getLanguageName( locale ) {
	const code = String( locale || '' ).split( /[_-]/ )[ 0 ];

	if ( ! code ) {
		return '';
	}

	try {
		const name = new Intl.DisplayNames( [ 'en' ], { type: 'language' } ).of(
			code
		);

		// An unknown code comes back unchanged.
		return name && name !== code ? name : '';
	} catch {
		return '';
	}
}

/**
 * Puts the languages of a book's editions in the order they are listed in.
 *
 * First the language of the edition in the site owner's library, then the
 * site's language, then that of the person editing, then the rest by name.
 *
 * @param {Object[]} editions  The editions.
 * @param {Object}   languages The languages to list first, as returned by the REST route.
 * @return {string[]} The language names, without duplicates.
 */
function getLanguages( editions, languages ) {
	const available = new Set(
		editions.map( ( edition ) => edition.language ).filter( Boolean )
	);

	const first = [
		languages?.edition,
		getLanguageName( languages?.site ),
		getLanguageName( languages?.user ),
	].filter( ( language ) => available.has( language ) );

	const rest = [ ...available ].sort( ( a, b ) => a.localeCompare( b ) );

	return [ ...new Set( [ ...first, ...rest ] ) ];
}

/**
 * A grid of edition covers to pick from, with a language filter.
 *
 * The editions come from the plugin's own REST route, which keeps the
 * Hardcover API token on the server. They are only fetched when this is
 * shown, which is when its panel is opened.
 *
 * @param {Object}                               props          Component props.
 * @param {number}                               props.bookId   Hardcover ID of the book.
 * @param {number|undefined}                     props.value    Hardcover ID of the picked edition, if any.
 * @param {(editionId: number|undefined)=> void} props.onChange Called with the picked edition's ID, or nothing to go back to the default.
 * @return {Element} The picker element.
 */
export default function EditionPicker( { bookId, value, onChange } ) {
	const [ editions, setEditions ] = useState( [] );
	const [ languages, setLanguages ] = useState( [] );
	const [ language, setLanguage ] = useState( '' );
	const [ shown, setShown ] = useState( PAGE_SIZE );
	const [ status, setStatus ] = useState( 'loading' );
	const [ message, setMessage ] = useState( '' );

	useEffect( () => {
		let isCurrent = true;

		setStatus( 'loading' );

		apiFetch( { path: `/endpaper/v1/editions/${ bookId }` } )
			.then( ( result ) => {
				if ( ! isCurrent ) {
					return;
				}

				const loaded = Array.isArray( result?.editions )
					? result.editions
					: [];
				const ordered = getLanguages( loaded, result?.languages );

				// Start on the language of the picked edition, so that it is in view, or else on that of the edition in the library.
				const picked = loaded.find(
					( edition ) => edition.id === value
				);
				const start = picked
					? picked.language
					: result?.languages?.edition;

				setEditions( loaded );
				setLanguages( ordered );
				setLanguage( ordered.includes( start ) ? start : '' );
				setShown( PAGE_SIZE );
				setStatus( 'done' );
			} )
			.catch( ( error ) => {
				if ( isCurrent ) {
					setMessage(
						error?.message ||
							__(
								'The editions could not be loaded.',
								'endpaper'
							)
					);
					setStatus( 'error' );
				}
			} );

		return () => {
			isCurrent = false;
		};
		// The picked edition only decides where the filter starts, so picking another one does not load the list again.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ bookId ] );

	const matching = useMemo(
		() =>
			language
				? editions.filter(
						( edition ) => edition.language === language
					)
				: editions,
		[ editions, language ]
	);

	return (
		<div className="endpaper-edition-picker">
			<p className="endpaper-edition-picker__help">
				{ __(
					'The block shows the cover from your Hardcover library, or the book’s default cover. You can pick another cover below.',
					'endpaper'
				) }
			</p>

			{ status === 'loading' && <Spinner /> }

			{ status === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ message }
				</Notice>
			) }

			{ status === 'done' && editions.length === 0 && (
				<p>
					{ __( 'No editions with a cover were found.', 'endpaper' ) }
				</p>
			) }

			{ status === 'done' && languages.length > 1 && (
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					className="endpaper-edition-picker__language"
					label={ __( 'Language', 'endpaper' ) }
					value={ language }
					options={ [
						{ label: __( 'All languages', 'endpaper' ), value: '' },
						...languages.map( ( name ) => ( {
							label: name,
							value: name,
						} ) ),
					] }
					onChange={ ( next ) => {
						setLanguage( next );
						setShown( PAGE_SIZE );
					} }
				/>
			) }

			{ status === 'done' && matching.length > 0 && (
				<ul className="endpaper-edition-picker__list">
					{ matching.slice( 0, shown ).map( ( edition ) => (
						<li key={ edition.id }>
							<Button
								className="endpaper-edition-picker__edition"
								label={ getEditionLabel( edition ) }
								showTooltip
								isPressed={ edition.id === value }
								onClick={ () => onChange( edition.id ) }
							>
								<img
									src={ edition.cover }
									alt=""
									loading="lazy"
								/>
							</Button>
						</li>
					) ) }
				</ul>
			) }

			<div className="endpaper-edition-picker__actions">
				{ status === 'done' && matching.length > shown && (
					<Button
						variant="secondary"
						__next40pxDefaultSize
						onClick={ () => setShown( shown + PAGE_SIZE ) }
					>
						{ __( 'Show more', 'endpaper' ) }
					</Button>
				) }

				{ !! value && (
					<Button
						variant="secondary"
						__next40pxDefaultSize
						onClick={ () => onChange( undefined ) }
					>
						{ __( 'Use the default cover', 'endpaper' ) }
					</Button>
				) }
			</div>
		</div>
	);
}
