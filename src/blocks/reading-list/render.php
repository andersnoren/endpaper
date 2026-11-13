<?php
/**
 * Server-side rendering of the Reading List block.
 *
 * @package Endpaper
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use function AndersNoren\Endpaper\format_date;
use function AndersNoren\Endpaper\format_text;
use function AndersNoren\Endpaper\get_accent_color_style;
use function AndersNoren\Endpaper\get_heading_color_style;
use function AndersNoren\Endpaper\get_block_gap_style;
use function AndersNoren\Endpaper\get_cover_radius_style;
use function AndersNoren\Endpaper\get_reading_list;
use function AndersNoren\Endpaper\get_title_tag;
use function AndersNoren\Endpaper\get_token_trouble;
use function AndersNoren\Endpaper\has_card_frame;
use function AndersNoren\Endpaper\the_cover_image;
use function AndersNoren\Endpaper\the_editor_notice;
use function AndersNoren\Endpaper\the_hardcover_credit;
use function AndersNoren\Endpaper\the_progress_bar;
use function AndersNoren\Endpaper\the_star_rating;

defined( 'ABSPATH' ) || exit;

$endpaper_status  = isset( $attributes['status'] ) ? absint( $attributes['status'] ) : 2;
$endpaper_count   = isset( $attributes['count'] ) ? absint( $attributes['count'] ) : 5;
$endpaper_layout  = isset( $attributes['layout'] ) && 'grid' === $attributes['layout'] ? 'grid' : 'list';
$endpaper_columns = isset( $attributes['columns'] ) ? min( 6, max( 1, absint( $attributes['columns'] ) ) ) : 4;
$endpaper_tag     = get_title_tag( isset( $attributes['titleLevel'] ) ? $attributes['titleLevel'] : null, 0 );
$endpaper_entries = get_reading_list( $endpaper_status, $endpaper_count );

// How wide a cover is shown, roughly, so the browser can pick the right file: a thumbnail in a list, or a share of the width in a grid, which is the whole width at most on a phone and never more than a few hundred pixels on a large screen.
$endpaper_cover_sizes = '72px';

if ( 'grid' === $endpaper_layout ) {
	$endpaper_cover_sizes = sprintf(
		'(max-width: 600px) %1$dvw, (max-width: 1200px) %2$dvw, %3$dpx',
		1 === $endpaper_columns ? 100 : 45,
		(int) round( 100 / $endpaper_columns ),
		$endpaper_columns >= 4 ? 300 : 640
	);
}

// Visitors see nothing if the list fails or is empty. People who can edit see why.
if ( is_wp_error( $endpaper_entries ) ) {
	the_editor_notice( $endpaper_entries->get_error_message(), '' !== get_token_trouble() );

	return;
}

if ( ! $endpaper_entries ) {
	the_editor_notice( __( 'No books with this status were found in your Hardcover library. Only books with public activity are shown.', 'endpaper' ) );

	return;
}

// Each list has its own extras: progress for the books being read, the rating and the date for the finished ones.
$endpaper_with_progress = 2 === $endpaper_status && ! empty( $attributes['showProgress'] );
$endpaper_with_rating   = 3 === $endpaper_status && ! empty( $attributes['showRating'] );
$endpaper_with_date     = 3 === $endpaper_status && ! empty( $attributes['showDate'] );

$endpaper_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'endpaper-reading-list--' . $endpaper_layout . ( ! empty( $attributes['showCover'] ) ? ' endpaper-reading-list--has-covers' : '' ) . ( has_card_frame( $attributes ) ? ' endpaper-reading-list--framed' : '' ),
		'style' => ( 'grid' === $endpaper_layout ? '--endpaper-columns:' . $endpaper_columns . ';' : '' ) . get_cover_radius_style( $attributes ) . get_block_gap_style( $attributes ) . get_accent_color_style( $attributes, 'endpaper/reading-list' ) . get_heading_color_style( $attributes, 'endpaper/reading-list' ),
	)
);

?>
<div <?php echo $endpaper_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<ul class="endpaper-reading-list__items">

		<?php foreach ( $endpaper_entries as $endpaper_entry ) : ?>
			<?php
			$endpaper_book     = $endpaper_entry['book'];
			$endpaper_link     = ! empty( $attributes['linkToHardcover'] ) && $endpaper_book['url'] ? $endpaper_book['url'] : '';
			$endpaper_rating   = $endpaper_with_rating && $endpaper_entry['rating'] > 0 ? $endpaper_entry['rating'] : 0;
			$endpaper_finished = $endpaper_with_date && ! empty( $endpaper_entry['finished'] ) ? format_date( $endpaper_entry['finished'] ) : '';
			?>
			<li class="endpaper-reading-list__item">

				<?php if ( ! empty( $attributes['showCover'] ) ) : ?>
					<figure class="endpaper-reading-list__cover">
						<?php if ( $endpaper_link ) : ?>
							<a href="<?php echo esc_url( $endpaper_link ); ?>" tabindex="-1" aria-hidden="true">
						<?php endif; ?>
						<?php the_cover_image( $endpaper_book['cover'], $endpaper_cover_sizes ); ?>
						<?php if ( $endpaper_link ) : ?>
							</a>
						<?php endif; ?>
					</figure>
				<?php endif; ?>

				<div class="endpaper-reading-list__content">

					<div class="endpaper-reading-list__header">
						<<?php echo tag_escape( $endpaper_tag ); ?> class="endpaper-reading-list__title">
							<?php if ( $endpaper_link ) : ?>
								<a href="<?php echo esc_url( $endpaper_link ); ?>"><?php echo format_text( $endpaper_book['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_text(). ?></a>
							<?php else : ?>
								<?php echo format_text( $endpaper_book['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_text(). ?>
							<?php endif; ?>
						</<?php echo tag_escape( $endpaper_tag ); ?>>

						<?php if ( ! empty( $attributes['showAuthors'] ) && $endpaper_book['authors'] ) : ?>
							<p class="endpaper-reading-list__authors">
								<?php
								/* translators: %s: List of author names. */
								echo esc_html( sprintf( __( 'by %s', 'endpaper' ), wp_sprintf( '%l', $endpaper_book['authors'] ) ) );
								?>
							</p>
						<?php endif; ?>
					</div>

					<?php if ( $endpaper_with_progress && null !== $endpaper_entry['progress'] ) : ?>
						<?php the_progress_bar( $endpaper_entry['progress'], 'endpaper-reading-list__progress' ); ?>
					<?php endif; ?>

					<?php if ( $endpaper_rating || $endpaper_finished ) : ?>
						<p class="endpaper-reading-list__read">
							<?php if ( $endpaper_rating ) : ?>
								<?php the_star_rating( $endpaper_rating, 'endpaper-reading-list__rating' ); ?>
							<?php endif; ?>
							<?php if ( $endpaper_finished ) : ?>
								<span class="endpaper-reading-list__finished">
								<span aria-hidden="true"><?php echo esc_html( $endpaper_finished ); ?></span>
								<span class="screen-reader-text">
									<?php
									/* translators: %s: A date, such as November 30, 2015. */
									echo esc_html( sprintf( __( 'Finished %s', 'endpaper' ), $endpaper_finished ) );
									?>
								</span>
							</span>
							<?php endif; ?>
						</p>
					<?php endif; ?>

				</div>

			</li>
		<?php endforeach; ?>

	</ul>
	<?php // Hardcover's logo is a credit, so it is only shown if that is switched on. ?>
	<?php if ( ! empty( $attributes['showCredit'] ) ) : ?>
		<p class="endpaper-reading-list__attribution">
			<?php the_hardcover_credit( '', 'endpaper-reading-list__credit' ); ?>
		</p>
	<?php endif; ?>
</div>
