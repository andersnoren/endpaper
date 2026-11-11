/**
 * Book block, editor script.
 *
 * Written without JSX so the proof of concept needs no build step.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment, useEffect, useRef, useState } = wp.element;
	const { __ } = wp.i18n;
	const { BlockControls, InspectorControls, useBlockProps } = wp.blockEditor;
	const {
		Button,
		Notice,
		PanelBody,
		Placeholder,
		RangeControl,
		SearchControl,
		Spinner,
		ToggleControl,
		ToolbarButton,
		ToolbarGroup,
	} = wp.components;
	const { addQueryArgs } = wp.url;
	const apiFetch = wp.apiFetch;
	const ServerSideRender = wp.serverSideRender;

	/**
	 * Search field and result list, shown until a book is picked.
	 *
	 * @param {Object}   props          Component props.
	 * @param {Function} props.onSelect Called with the picked book's ID.
	 */
	function BookSearch( { onSelect } ) {
		const [ term, setTerm ] = useState( '' );
		const [ results, setResults ] = useState( [] );
		const [ status, setStatus ] = useState( 'idle' );
		const [ message, setMessage ] = useState( '' );
		const latestRequest = useRef( 0 );

		useEffect( () => {
			const query = term.trim();
			const requestId = ++latestRequest.current;

			if ( query.length < 3 ) {
				setResults( [] );
				setStatus( 'idle' );
				return;
			}

			// Wait until typing pauses before asking the server.
			const timer = setTimeout( () => {
				setStatus( 'loading' );

				apiFetch( {
					path: addQueryArgs( '/endpaper/v1/search', { q: query } ),
				} )
					.then( ( books ) => {
						if ( requestId !== latestRequest.current ) {
							return;
						}
						setResults( Array.isArray( books ) ? books : [] );
						setStatus( 'done' );
					} )
					.catch( ( error ) => {
						if ( requestId !== latestRequest.current ) {
							return;
						}
						setResults( [] );
						setMessage(
							error && error.message
								? error.message
								: __( 'Something went wrong while searching.', 'endpaper' )
						);
						setStatus( 'error' );
					} );
			}, 400 );

			return () => clearTimeout( timer );
		}, [ term ] );

		return el(
			'div',
			{ className: 'endpaper-book-search' },
			el( SearchControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'Search for a book', 'endpaper' ),
				placeholder: __( 'Title, author, ISBN or Hardcover link', 'endpaper' ),
				value: term,
				onChange: setTerm,
			} ),
			'loading' === status && el( Spinner ),
			'error' === status &&
				el( Notice, { status: 'error', isDismissible: false }, message ),
			'done' === status &&
				0 === results.length &&
				el( 'p', null, __( 'No books found.', 'endpaper' ) ),
			results.length > 0 &&
				el(
					'ul',
					{ className: 'endpaper-book-search__results' },
					results.map( ( book ) =>
						el(
							'li',
							{ key: book.id },
							el(
								Button,
								{
									className: 'endpaper-book-search__result',
									onClick: () => onSelect( book.id ),
								},
								book.cover
									? el( 'img', {
											className: 'endpaper-book-search__cover',
											src: book.cover,
											alt: '',
									  } )
									: el( 'span', { className: 'endpaper-book-search__cover' } ),
								el(
									'span',
									{ className: 'endpaper-book-search__text' },
									el( 'strong', null, book.title ),
									el(
										'span',
										{ className: 'endpaper-book-search__details' },
										[ ( book.authors || [] ).join( ', ' ), book.year || '' ]
											.filter( Boolean )
											.join( ' · ' )
									)
								)
							)
						)
					)
				)
		);
	}

	/**
	 * Block edit component.
	 *
	 * @param {Object}   props               Component props.
	 * @param {Object}   props.attributes    Block attributes.
	 * @param {Function} props.setAttributes Attribute setter.
	 */
	function Edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps();

		if ( ! attributes.bookId ) {
			return el(
				'div',
				blockProps,
				el(
					Placeholder,
					{
						icon: 'book',
						label: __( 'Book', 'endpaper' ),
						instructions: __(
							'Search Hardcover for a book, or paste a link to its Hardcover page.',
							'endpaper'
						),
					},
					el( BookSearch, {
						onSelect: ( bookId ) => setAttributes( { bookId } ),
					} )
				)
			);
		}

		const toggle = ( attribute, label ) =>
			el( ToggleControl, {
				__nextHasNoMarginBottom: true,
				label,
				checked: !! attributes[ attribute ],
				onChange: ( value ) => setAttributes( { [ attribute ]: value } ),
			} );

		return el(
			Fragment,
			null,
			el(
				BlockControls,
				null,
				el(
					ToolbarGroup,
					null,
					el(
						ToolbarButton,
						{ onClick: () => setAttributes( { bookId: undefined } ) },
						__( 'Replace', 'endpaper' )
					)
				)
			),
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Display', 'endpaper' ) },
					toggle( 'showCover', __( 'Cover', 'endpaper' ) ),
					toggle( 'showAuthors', __( 'Authors', 'endpaper' ) ),
					toggle( 'showMeta', __( 'Year and page count', 'endpaper' ) ),
					toggle( 'showRating', __( 'Hardcover rating', 'endpaper' ) ),
					toggle( 'showDescription', __( 'Description', 'endpaper' ) ),
					attributes.showDescription &&
						el( RangeControl, {
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							label: __( 'Description lines', 'endpaper' ),
							help: __( 'Set to 0 to show the full description.', 'endpaper' ),
							min: 0,
							max: 12,
							value: attributes.descriptionLines,
							onChange: ( value ) =>
								setAttributes( { descriptionLines: value || 0 } ),
						} ),
					toggle( 'showLink', __( 'Link to Hardcover', 'endpaper' ) )
				)
			),
			el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block: 'endpaper/book',
					attributes,
					skipBlockSupportAttributes: true,
				} )
			)
		);
	}

	registerBlockType( 'endpaper/book', {
		edit: Edit,
		save: () => null,
	} );
} )( window.wp );
