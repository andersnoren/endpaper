<?php
/**
 * Server-side rendering of the Book block.
 *
 * @package Endpaper
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use function AndersNoren\Endpaper\format_text;
use function AndersNoren\Endpaper\get_book;
use function AndersNoren\Endpaper\get_cover_radius_style;
use function AndersNoren\Endpaper\get_library_entry;
use function AndersNoren\Endpaper\get_shelf_labels;
use function AndersNoren\Endpaper\get_title_tag;
use function AndersNoren\Endpaper\the_progress_bar;

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

// Without the link setting, nothing in the block links to Hardcover: not the title, the cover or the link below.
$endpaper_url   = ! empty( $attributes['showLink'] ) ? $endpaper_book['url'] : '';
$endpaper_tag   = get_title_tag( isset( $attributes['titleLevel'] ) ? $attributes['titleLevel'] : null, 2 );
$endpaper_lines = isset( $attributes['descriptionLines'] ) ? absint( $attributes['descriptionLines'] ) : 4;
$endpaper_meta  = array();

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

if ( $endpaper_book['year'] ) {
	$endpaper_meta[] = (string) $endpaper_book['year'];
}

if ( $endpaper_book['pages'] ) {
	$endpaper_meta[] = sprintf(
		/* translators: %s: Number of pages. */
		_n( '%s page', '%s pages', $endpaper_book['pages'], 'endpaper' ),
		number_format_i18n( $endpaper_book['pages'] )
	);
}
?>
<div <?php echo get_block_wrapper_attributes( array( 'style' => get_cover_radius_style( $attributes ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="endpaper-book__inner">

		<?php if ( ! empty( $attributes['showCover'] ) && $endpaper_book['cover'] ) : ?>
			<figure class="endpaper-book__cover">
				<?php if ( $endpaper_url ) : ?>
					<a href="<?php echo esc_url( $endpaper_url ); ?>" tabindex="-1" aria-hidden="true">
				<?php endif; ?>
				<img src="<?php echo esc_url( $endpaper_book['cover'] ); ?>" alt="" loading="lazy" decoding="async" />
				<?php if ( $endpaper_url ) : ?>
					</a>
				<?php endif; ?>
			</figure>
		<?php endif; ?>

		<div class="endpaper-book__content">

			<<?php echo tag_escape( $endpaper_tag ); ?> class="endpaper-book__title">
				<?php if ( $endpaper_url ) : ?>
					<a href="<?php echo esc_url( $endpaper_url ); ?>"><?php echo format_text( $endpaper_book['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_text(). ?></a>
				<?php else : ?>
					<?php echo format_text( $endpaper_book['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_text(). ?>
				<?php endif; ?>
			</<?php echo tag_escape( $endpaper_tag ); ?>>

			<?php if ( ! empty( $attributes['showAuthors'] ) && $endpaper_book['authors'] ) : ?>
				<p class="endpaper-book__authors">
					<?php
					/* translators: %s: List of author names. */
					echo esc_html( sprintf( __( 'by %s', 'endpaper' ), wp_sprintf( '%l', $endpaper_book['authors'] ) ) );
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $attributes['showMeta'] ) && $endpaper_meta ) : ?>
				<p class="endpaper-book__meta"><?php echo esc_html( implode( ' · ', $endpaper_meta ) ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $attributes['showRating'] ) && $endpaper_book['rating'] > 0 ) : ?>
				<p class="endpaper-book__rating">
					<span class="endpaper-book__star" aria-hidden="true">★</span>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: Average rating, 2: Number of ratings. */
							_n( '%1$s out of 5 on Hardcover (%2$s rating)', '%1$s out of 5 on Hardcover (%2$s ratings)', $endpaper_book['ratings_count'], 'endpaper' ),
							number_format_i18n( $endpaper_book['rating'], 2 ),
							number_format_i18n( $endpaper_book['ratings_count'] )
						)
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $endpaper_show_shelf ) : ?>
				<p class="endpaper-book__shelf"><?php echo esc_html( $endpaper_shelf ); ?></p>
			<?php endif; ?>

			<?php if ( $endpaper_show_progress ) : ?>
				<?php the_progress_bar( $endpaper_entry['progress'], 'endpaper-book__progress' ); ?>
			<?php endif; ?>

			<?php if ( ! empty( $attributes['showDescription'] ) && $endpaper_book['description'] ) : ?>
				<p
					class="endpaper-book__description<?php echo $endpaper_lines ? ' is-clamped' : ''; ?>"
					<?php if ( $endpaper_lines ) : ?>
						style="--endpaper-lines: <?php echo esc_attr( $endpaper_lines ); ?>;"
					<?php endif; ?>
				>
					<?php echo format_text( $endpaper_book['description'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_text(). ?>
				</p>
			<?php endif; ?>

			<?php if ( $endpaper_url ) : ?>
				<p class="endpaper-book__link">
					<a href="<?php echo esc_url( $endpaper_url ); ?>"><?php esc_html_e( 'View on Hardcover', 'endpaper' ); ?></a>
				</p>
			<?php endif; ?>

		</div>

	</div>
</div>
