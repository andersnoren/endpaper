/**
 * WordPress dependencies
 */
import { isRTL } from '@wordpress/i18n';

/**
 * Returns the text alignment as the block renders it: start, center or end,
 * with left and right turned around for right-to-left languages.
 *
 * @param {string} textAlign The block's Text align setting.
 * @return {string} start, center, end, or an empty string.
 */
function getTextAlign( textAlign ) {
	if ( 'left' === textAlign || 'right' === textAlign ) {
		return isRTL() === ( 'left' === textAlign ) ? 'end' : 'start';
	}

	return [ 'start', 'center', 'end' ].includes( textAlign ) ? textAlign : '';
}

/**
 * What the card looks like while Hardcover's answer is on its way: the same
 * box, with gray bars where the text goes. It uses the block's own classes, so
 * the cover position and size match and nothing jumps when the book arrives.
 *
 * @param {Object} props            Component props.
 * @param {Object} props.attributes The block's attributes.
 * @return {Element} The placeholder.
 */
export default function CardPlaceholder( { attributes } ) {
	const position = attributes.coverPosition || 'left';
	const size = attributes.coverSize || 'medium';
	const align = getTextAlign( attributes.textAlign );

	return (
		<div
			className={ `wp-block-endpaper-book endpaper-book-placeholder endpaper-book--cover-${ position } endpaper-book--cover-${ size }${ align ? ` endpaper-book--align-${ align }` : '' }` }
			aria-hidden="true"
		>
			{ false !== attributes.showCover && (
				<div className="endpaper-book__cover">
					<div className="endpaper-book-placeholder__cover" />
				</div>
			) }
			<div className="endpaper-book__content">
				<div className="endpaper-book-placeholder__text">
					<span className="endpaper-book-placeholder__line endpaper-book-placeholder__line--title" />
					<span className="endpaper-book-placeholder__line endpaper-book-placeholder__line--short" />
					<span className="endpaper-book-placeholder__line" />
					<span className="endpaper-book-placeholder__line" />
					<span className="endpaper-book-placeholder__line endpaper-book-placeholder__line--short" />
				</div>
				<span className="endpaper-book-placeholder__line endpaper-book-placeholder__line--meta" />
			</div>
		</div>
	);
}
