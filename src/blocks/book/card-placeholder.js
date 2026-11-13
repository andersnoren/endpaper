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

	return (
		<div
			className={ `wp-block-endpaper-book endpaper-book-placeholder endpaper-book--cover-${ position } endpaper-book--cover-${ size }` }
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
