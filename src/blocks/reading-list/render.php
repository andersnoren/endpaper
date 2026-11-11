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

use function AndersNoren\Endpaper\format_text;
use function AndersNoren\Endpaper\get_block_gap_style;
use function AndersNoren\Endpaper\get_cover_radius_style;
use function AndersNoren\Endpaper\get_reading_list;
use function AndersNoren\Endpaper\get_title_tag;

defined( 'ABSPATH' ) || exit;

$endpaper_status  = isset( $attributes['status'] ) ? absint( $attributes['status'] ) : 2;
$endpaper_count   = isset( $attributes['count'] ) ? absint( $attributes['count'] ) : 5;
$endpaper_layout  = isset( $attributes['layout'] ) && 'grid' === $attributes['layout'] ? 'grid' : 'list';
$endpaper_tag     = get_title_tag( isset( $attributes['titleLevel'] ) ? $attributes['titleLevel'] : null, 0 );
$endpaper_entries = get_reading_list( $endpaper_status, $endpaper_count );

// Visitors see nothing if the list fails or is empty. People who can edit see why.
$endpaper_notice = '';

if ( is_wp_error( $endpaper_entries ) ) {
	/* translators: %s: Error message. */
	$endpaper_notice = sprintf( __( 'Endpaper: %s', 'endpaper' ), $endpaper_entries->get_error_message() );
} elseif ( ! $endpaper_entries ) {
	$endpaper_notice = __( 'Endpaper: No public books with this status were found in your Hardcover library.', 'endpaper' );
}

if ( $endpaper_notice ) {
	if ( current_user_can( 'edit_posts' ) ) {
		printf(
			'<p %1$s>%2$s</p>',
			get_block_wrapper_attributes( array( 'class' => 'endpaper-reading-list--notice' ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $endpaper_notice )
		);
	}

	return;
}

$endpaper_wrapper = get_block_wrapper_attributes(
	array(
		'class' => 'endpaper-reading-list--' . $endpaper_layout,
		'style' => get_cover_radius_style( $attributes ) . get_block_gap_style( $attributes ),
	)
);
?>
<div <?php echo $endpaper_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<ul class="endpaper-reading-list__items">

		<?php foreach ( $endpaper_entries as $endpaper_entry ) : ?>
			<?php
			$endpaper_book = $endpaper_entry['book'];
			$endpaper_url  = $endpaper_book['url'];
			?>
			<li class="endpaper-reading-list__item">

				<?php if ( ! empty( $attributes['showCover'] ) && $endpaper_book['cover'] ) : ?>
					<figure class="endpaper-reading-list__cover">
						<?php if ( $endpaper_url ) : ?>
							<a href="<?php echo esc_url( $endpaper_url ); ?>" tabindex="-1" aria-hidden="true">
						<?php endif; ?>
						<img src="<?php echo esc_url( $endpaper_book['cover'] ); ?>" alt="" loading="lazy" decoding="async" />
						<?php if ( $endpaper_url ) : ?>
							</a>
						<?php endif; ?>
					</figure>
				<?php endif; ?>

				<div class="endpaper-reading-list__content">

					<<?php echo tag_escape( $endpaper_tag ); ?> class="endpaper-reading-list__title">
						<?php if ( $endpaper_url ) : ?>
							<a href="<?php echo esc_url( $endpaper_url ); ?>"><?php echo format_text( $endpaper_book['title'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_text(). ?></a>
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

					<?php if ( ! empty( $attributes['showRating'] ) && $endpaper_entry['rating'] > 0 ) : ?>
						<p class="endpaper-reading-list__rating">
							<span aria-hidden="true">★</span>
							<?php
							/* translators: %s: A rating, such as 4.5. */
							echo esc_html( sprintf( __( '%s out of 5', 'endpaper' ), number_format_i18n( $endpaper_entry['rating'], 1 ) ) );
							?>
						</p>
					<?php endif; ?>

					<?php if ( ! empty( $attributes['showProgress'] ) && 2 === $endpaper_status && null !== $endpaper_entry['progress'] ) : ?>
						<?php
						$endpaper_percent = (int) round( $endpaper_entry['progress'] );
						/* translators: %s: A percentage, such as 63. */
						$endpaper_label = sprintf( __( '%s%% read', 'endpaper' ), number_format_i18n( $endpaper_percent ) );
						?>
						<p class="endpaper-reading-list__progress">
							<progress max="100" value="<?php echo esc_attr( $endpaper_percent ); ?>" aria-label="<?php echo esc_attr( $endpaper_label ); ?>"></progress>
							<span><?php echo esc_html( $endpaper_label ); ?></span>
						</p>
					<?php endif; ?>

				</div>

			</li>
		<?php endforeach; ?>

	</ul>
</div>
