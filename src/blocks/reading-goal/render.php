<?php
/**
 * Server-side rendering of the Reading Goal block.
 *
 * @package Endpaper
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

use function AndersNoren\Endpaper\format_text;
use function AndersNoren\Endpaper\get_accent_color_style;
use function AndersNoren\Endpaper\get_card_style;
use function AndersNoren\Endpaper\get_goal;
use function AndersNoren\Endpaper\get_goal_amount_parts;
use function AndersNoren\Endpaper\get_goal_amount_text;
use function AndersNoren\Endpaper\get_goal_days_text;
use function AndersNoren\Endpaper\get_goal_ended_text;
use function AndersNoren\Endpaper\get_title_tag;
use function AndersNoren\Endpaper\get_token_trouble;
use function AndersNoren\Endpaper\has_card_frame;
use function AndersNoren\Endpaper\the_editor_notice;
use function AndersNoren\Endpaper\the_hardcover_credit;

defined( 'ABSPATH' ) || exit;

$endpaper_goal_id = isset( $attributes['goalId'] ) ? absint( $attributes['goalId'] ) : 0;
$endpaper_goal    = get_goal( $endpaper_goal_id );

// Visitors see nothing if the goal cannot be shown. People who can edit see why.
if ( is_wp_error( $endpaper_goal ) ) {
	the_editor_notice( $endpaper_goal->get_error_message(), '' !== get_token_trouble() );

	return;
}

if ( ! $endpaper_goal ) {
	if ( $endpaper_goal_id ) {
		the_editor_notice( __( 'This goal was not found in your Hardcover account. It may have been archived, made private or deleted. Only public goals are shown.', 'endpaper' ) );
	} else {
		the_editor_notice( __( 'Choose which of your Hardcover goals to show, in the block settings. Only public goals can be shown.', 'endpaper' ) );
	}

	return;
}

$endpaper_tag     = get_title_tag( isset( $attributes['titleLevel'] ) ? $attributes['titleLevel'] : null, 2 );
$endpaper_title   = '' !== $endpaper_goal['title'] ? $endpaper_goal['title'] : __( 'Reading goal', 'endpaper' );
$endpaper_amount  = get_goal_amount_text( $endpaper_goal );
$endpaper_parts   = get_goal_amount_parts( $endpaper_goal );
$endpaper_reached = $endpaper_goal['progress'] >= $endpaper_goal['target'];
// Rounded down, so it never says 100 before the goal is reached. A goal that is beaten stops at 100, like the bar.
$endpaper_percent = min( 100, (int) floor( $endpaper_goal['progress'] / $endpaper_goal['target'] * 100 + 0.0001 ) );

// A goal that is reached says so, in place of the days left.
$endpaper_counts_days = false;

if ( $endpaper_reached ) {
	$endpaper_status = __( 'Goal reached!', 'endpaper' );
} else {
	$endpaper_status      = ! empty( $attributes['showDays'] ) ? get_goal_days_text( $endpaper_goal ) : '';
	$endpaper_counts_days = '' !== $endpaper_status;
}

$endpaper_show_percent = ! empty( $attributes['showPercent'] );
$endpaper_is_gauge     = ! empty( $attributes['className'] ) && in_array( 'is-style-gauge', preg_split( '/\s+/', $attributes['className'] ), true );

// The page may be kept and served for days, so the days left are worked out again in the browser. The text is for until then, and for browsers without scripts.
$endpaper_status_data = $endpaper_counts_days ? ' data-end="' . esc_attr( $endpaper_goal['end'] ) . '" data-ended="' . esc_attr( get_goal_ended_text( $endpaper_goal ) ) . '"' : '';

/* translators: %s: A percentage, such as 68. */
$endpaper_percent_label = sprintf( __( '%s%% of the goal', 'endpaper' ), number_format_i18n( $endpaper_percent ) );

$endpaper_wrapper = get_block_wrapper_attributes(
	array(
		'style' => get_accent_color_style( $attributes, 'endpaper/reading-goal' ),
	)
);

$endpaper_card_style = get_card_style( $attributes );
?>
<div <?php echo $endpaper_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<div class="endpaper-reading-goal__card<?php echo has_card_frame( $attributes ) ? ' endpaper-reading-goal__card--framed' : ''; ?>"<?php echo $endpaper_card_style ? ' style="' . esc_attr( $endpaper_card_style ) . '"' : ''; ?>>

		<?php if ( ! empty( $attributes['showTitle'] ) ) : ?>
			<<?php echo tag_escape( $endpaper_tag ); ?> class="endpaper-reading-goal__title">
				<?php echo format_text( $endpaper_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in format_text(). ?>
			</<?php echo tag_escape( $endpaper_tag ); ?>>
		<?php endif; ?>

		<?php if ( $endpaper_is_gauge ) : ?>
			<?php // The arc is drawn so that its whole length counts as 100, whatever its size. ?>
			<div class="endpaper-reading-goal__gauge" style="--endpaper-chars: <?php echo esc_attr( mb_strlen( $endpaper_parts['count'] ) ); ?>">
				<svg class="endpaper-reading-goal__arc" viewBox="0 0 256 134" aria-hidden="true" focusable="false">
					<path class="endpaper-reading-goal__arc-track" d="M6 128A122 122 0 0 1 250 128" pathLength="100" />
					<?php if ( $endpaper_percent > 0 ) : ?>
						<path class="endpaper-reading-goal__arc-fill" d="M6 128A122 122 0 0 1 250 128" pathLength="100" stroke-dasharray="<?php echo esc_attr( $endpaper_percent ); ?> 100" />
					<?php endif; ?>
				</svg>
				<p class="endpaper-reading-goal__count"><span class="endpaper-reading-goal__number"><?php echo esc_html( $endpaper_parts['count'] ); ?></span></p>
			</div>
			<p class="endpaper-reading-goal__label"><?php echo esc_html( $endpaper_parts['label'] ); ?></p>

			<?php if ( $endpaper_show_percent || $endpaper_status ) : ?>
				<div class="endpaper-reading-goal__meta">
					<?php if ( $endpaper_show_percent ) : ?>
						<p class="endpaper-reading-goal__percent"><bdi><?php echo esc_html( number_format_i18n( $endpaper_percent ) . '%' ); ?></bdi></p>
					<?php endif; ?>
					<?php if ( $endpaper_status ) : ?>
						<p class="endpaper-reading-goal__status"<?php echo $endpaper_status_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>><?php echo esc_html( $endpaper_status ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		<?php else : ?>
			<div class="endpaper-reading-goal__summary">
				<div class="endpaper-reading-goal__figures">
					<p class="endpaper-reading-goal__amount"><?php echo esc_html( $endpaper_amount ); ?></p>
					<?php if ( $endpaper_show_percent ) : ?>
						<p class="endpaper-reading-goal__percent" aria-hidden="true"><bdi><?php echo esc_html( '(' . number_format_i18n( $endpaper_percent ) . '%)' ); ?></bdi></p>
					<?php endif; ?>
				</div>
				<?php if ( $endpaper_status ) : ?>
					<p class="endpaper-reading-goal__status"<?php echo $endpaper_status_data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>><?php echo esc_html( $endpaper_status ); ?></p>
				<?php endif; ?>
			</div>

			<progress class="endpaper-reading-goal__bar" max="100" value="<?php echo esc_attr( $endpaper_percent ); ?>" aria-label="<?php echo esc_attr( $endpaper_percent_label ); ?>"></progress>
		<?php endif; ?>

		<?php // Hardcover's logo is a credit, so it is only shown if that is switched on. ?>
		<?php if ( ! empty( $attributes['showCredit'] ) ) : ?>
			<p class="endpaper-reading-goal__attribution">
				<?php the_hardcover_credit( '', 'endpaper-reading-goal__credit' ); ?>
			</p>
		<?php endif; ?>

	</div>
</div>
