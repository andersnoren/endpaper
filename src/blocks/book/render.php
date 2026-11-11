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

defined( 'ABSPATH' ) || exit;

$endpaper_book_id = isset( $attributes['bookId'] ) ? absint( $attributes['bookId'] ) : 0;

if ( ! $endpaper_book_id ) {
	return;
}

$endpaper_book = endpaper_get_book( $endpaper_book_id );

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

$endpaper_url   = $endpaper_book['url'];
$endpaper_lines = isset( $attributes['descriptionLines'] ) ? absint( $attributes['descriptionLines'] ) : 4;
$endpaper_meta  = array();

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
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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

			<h3 class="endpaper-book__title">
				<?php if ( $endpaper_url ) : ?>
					<a href="<?php echo esc_url( $endpaper_url ); ?>"><?php echo esc_html( $endpaper_book['title'] ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $endpaper_book['title'] ); ?>
				<?php endif; ?>
			</h3>

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

			<?php if ( ! empty( $attributes['showDescription'] ) && $endpaper_book['description'] ) : ?>
				<p
					class="endpaper-book__description<?php echo $endpaper_lines ? ' is-clamped' : ''; ?>"
					<?php if ( $endpaper_lines ) : ?>
						style="--endpaper-lines: <?php echo esc_attr( $endpaper_lines ); ?>;"
					<?php endif; ?>
				>
					<?php echo esc_html( $endpaper_book['description'] ); ?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $attributes['showLink'] ) && $endpaper_url ) : ?>
				<p class="endpaper-book__link">
					<a href="<?php echo esc_url( $endpaper_url ); ?>"><?php esc_html_e( 'View on Hardcover', 'endpaper' ); ?></a>
				</p>
			<?php endif; ?>

		</div>

	</div>
</div>
