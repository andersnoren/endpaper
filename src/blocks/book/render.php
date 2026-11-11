<?php
/**
 * Server-side rendering of the Book block.
 *
 * The block is a card with the book in it, and a line crediting Hardcover
 * underneath. The border, padding, background and shadow settings apply to
 * the card, not to the block as a whole.
 *
 * @package Endpaper
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use function AndersNoren\Endpaper\format_text;
use function AndersNoren\Endpaper\get_accent_color_style;
use function AndersNoren\Endpaper\get_book;
use function AndersNoren\Endpaper\get_card_style;
use function AndersNoren\Endpaper\get_cover_radius_style;
use function AndersNoren\Endpaper\get_library_entry;
use function AndersNoren\Endpaper\get_on_accent_style;
use function AndersNoren\Endpaper\get_shelf_labels;
use function AndersNoren\Endpaper\get_star_icon;
use function AndersNoren\Endpaper\get_title_tag;
use function AndersNoren\Endpaper\the_progress_bar;
use function AndersNoren\Endpaper\trim_to_sentence;

defined( 'ABSPATH' ) || exit;

$endpaper_book_id = isset( $attributes['bookId'] ) ? absint( $attributes['bookId'] ) : 0;

if ( ! $endpaper_book_id ) {
	return;
}

$endpaper_book = get_book( $endpaper_book_id );

if ( is_wp_error( $endpaper_book ) ) {
	// Visitors see nothing. People who can edit see what went wrong.
	if ( current_user_can( 'edit_posts' ) ) {
		printf(
			'<p %1$s>%2$s</p>',
			get_block_wrapper_attributes( array( 'class' => 'endpaper-book--error' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			/* translators: %s: Error message. */
			esc_html( sprintf( __( 'Endpaper: %s', 'endpaper' ), $endpaper_book->get_error_message() ) )
		);
	}

	return;
}

$endpaper_tag = get_title_tag( isset( $attributes['titleLevel'] ) ? $attributes['titleLevel'] : null, 2 );

// The credit line under the card is always shown, and is the only link to Hardcover. It goes to the book's page there.
$endpaper_url = $endpaper_book['url'] ? $endpaper_book['url'] : 'https://hardcover.app/';

// The shelf and progress come from the site owner's own library. They are extras, so a failed lookup never hides the book.
$endpaper_entry = array();

if ( ! empty( $attributes['showShelf'] ) || ! empty( $attributes['showProgress'] ) ) {
	$endpaper_entry = get_library_entry( $endpaper_book_id );

	if ( is_wp_error( $endpaper_entry ) ) {
		$endpaper_entry = array();
	}
}

$endpaper_shelves = get_shelf_labels();
$endpaper_shelf   = $endpaper_entry && isset( $endpaper_shelves[ $endpaper_entry['status_id'] ] ) ? $endpaper_shelves[ $endpaper_entry['status_id'] ] : '';

$endpaper_show_progress = ! empty( $attributes['showProgress'] ) && $endpaper_entry && 2 === $endpaper_entry['status_id'] && null !== $endpaper_entry['progress'];
$endpaper_show_shelf    = ! empty( $attributes['showShelf'] ) && $endpaper_shelf;

// The progress bar already says the book is being read, so the Currently Reading label is left out next to it.
if ( $endpaper_show_progress ) {
	$endpaper_show_shelf = false;
}

$endpaper_description = '';

if ( ! empty( $attributes['showDescription'] ) ) {
	$endpaper_description = trim_to_sentence(
		$endpaper_book['description'],
		isset( $attributes['descriptionLength'] ) ? absint( $attributes['descriptionLength'] ) : 280
	);
}

// The facts shown in the row at the bottom of the card.
$endpaper_facts = array();

if ( ! empty( $attributes['showMeta'] ) ) {
	if ( $endpaper_book['year'] ) {
		$endpaper_facts[] = (string) $endpaper_book['year'];
	}

	if ( $endpaper_book['pages'] ) {
		$endpaper_facts[] = sprintf(
			/* translators: %s: Number of pages. */
			_n( '%s page', '%s pages', $endpaper_book['pages'], 'endpaper' ),
			number_format_i18n( $endpaper_book['pages'] )
		);
	}
}

$endpaper_show_rating = ! empty( $attributes['showRating'] ) && $endpaper_book['rating'] > 0;

$endpaper_wrapper = get_block_wrapper_attributes(
	array(
		'style' => get_cover_radius_style( $attributes ) . get_accent_color_style( $attributes ) . get_on_accent_style( $attributes, 'endpaper/book' ),
	)
);

$endpaper_card_style = get_card_style( $attributes );
?>
<div <?php echo $endpaper_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="endpaper-book__card"<?php echo $endpaper_card_style ? ' style="' . esc_attr( $endpaper_card_style ) . '"' : ''; ?>>

		<?php if ( ! empty( $attributes['showCover'] ) && $endpaper_book['cover'] ) : ?>
			<figure class="endpaper-book__cover">
				<img src="<?php echo esc_url( $endpaper_book['cover'] ); ?>" alt="" loading="lazy" decoding="async" />
			</figure>
		<?php endif; ?>

		<div class="endpaper-book__content">
			<div class="endpaper-book__main">

				<div class="endpaper-book__header">
					<<?php echo tag_escape( $endpaper_tag ); ?> class="endpaper-book__title"><?php echo format_text( $endpaper_book['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_text(). ?></<?php echo tag_escape( $endpaper_tag ); ?>>

					<?php if ( ! empty( $attributes['showAuthors'] ) && $endpaper_book['authors'] ) : ?>
						<p class="endpaper-book__authors">
							<?php
							echo wp_kses(
								sprintf(
									/* translators: %s: List of author names. */
									__( 'By %s', 'endpaper' ),
									'<strong>' . esc_html( wp_sprintf( '%l', $endpaper_book['authors'] ) ) . '</strong>'
								),
								array( 'strong' => array() )
							);
							?>
						</p>
					<?php endif; ?>
				</div>

				<?php if ( $endpaper_show_progress ) : ?>
					<?php the_progress_bar( $endpaper_entry['progress'], 'endpaper-book__progress' ); ?>
				<?php endif; ?>

				<?php if ( $endpaper_show_shelf ) : ?>
					<p class="endpaper-book__shelf"><span><?php echo esc_html( $endpaper_shelf ); ?></span></p>
				<?php endif; ?>

				<?php if ( $endpaper_description ) : ?>
					<p class="endpaper-book__description"><?php echo format_text( $endpaper_description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_text(). ?></p>
				<?php endif; ?>

			</div>

			<?php if ( $endpaper_facts || $endpaper_show_rating ) : ?>
				<p class="endpaper-book__meta">
					<?php foreach ( $endpaper_facts as $endpaper_fact ) : ?>
						<span class="endpaper-book__fact"><?php echo esc_html( $endpaper_fact ); ?></span>
					<?php endforeach; ?>

					<?php if ( $endpaper_show_rating ) : ?>
						<span class="endpaper-book__fact endpaper-book__rating">
							<span aria-hidden="true"><?php echo esc_html( number_format_i18n( $endpaper_book['rating'], 1 ) ); ?><?php echo get_star_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG markup. ?></span>
							<span class="endpaper-book__rating-count" aria-hidden="true">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: Number of ratings. */
										_n( '(%s rating)', '(%s ratings)', $endpaper_book['ratings_count'], 'endpaper' ),
										number_format_i18n( $endpaper_book['ratings_count'] )
									)
								);
								?>
							</span>
							<span class="screen-reader-text">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: Average rating, 2: Number of ratings. */
										_n( 'Rated %1$s out of 5 on Hardcover, from %2$s rating', 'Rated %1$s out of 5 on Hardcover, from %2$s ratings', $endpaper_book['ratings_count'], 'endpaper' ),
										number_format_i18n( $endpaper_book['rating'], 1 ),
										number_format_i18n( $endpaper_book['ratings_count'] )
									)
								);
								?>
							</span>
						</span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>

	</div>

	<p class="endpaper-book__attribution">
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: Link to Hardcover. */
				__( 'Powered by %s', 'endpaper' ),
				'<a href="' . esc_url( $endpaper_url ) . '">Hardcover</a>'
			),
			array( 'a' => array( 'href' => array() ) )
		);
		?>
	</p>
</div>
