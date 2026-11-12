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
use function AndersNoren\Endpaper\get_goal_amount_html;
use function AndersNoren\Endpaper\get_goal_days_text;
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

$endpaper_tag     = get_title_tag( isset( $attributes['titleLevel'] ) ? $attributes['titleLevel'] : null, 0 );
$endpaper_title   = '' !== $endpaper_goal['title'] ? $endpaper_goal['title'] : __( 'Reading goal', 'endpaper' );
$endpaper_amount  = get_goal_amount_html( $endpaper_goal );
$endpaper_reached = $endpaper_goal['progress'] >= $endpaper_goal['target'];
// The percentage is rounded down, so it never says 100 before the goal is reached.
$endpaper_percent = (int) floor( $endpaper_goal['progress'] / $endpaper_goal['target'] * 100 + 0.0001 );

// A goal that is reached says so, in place of the days left.
if ( $endpaper_reached ) {
	$endpaper_status = __( 'Goal reached', 'endpaper' );
} else {
	$endpaper_status = ! empty( $attributes['showDays'] ) ? get_goal_days_text( $endpaper_goal ) : '';
}

// The number can go past 100 when the goal is beaten. The bar stops at full.
$endpaper_bar = min( 100, $endpaper_percent );

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

		<div class="endpaper-reading-goal__summary">
			<p class="endpaper-reading-goal__amount"><?php echo $endpaper_amount; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_goal_amount_html(). ?></p>
			<?php if ( $endpaper_status ) : ?>
				<p class="endpaper-reading-goal__status"><?php echo esc_html( $endpaper_status ); ?></p>
			<?php endif; ?>
			<p class="endpaper-reading-goal__percent<?php echo $endpaper_reached ? ' endpaper-reading-goal__percent--reached' : ''; ?>"><bdi><?php echo esc_html( number_format_i18n( $endpaper_percent ) . '%' ); ?></bdi></p>
		</div>

		<progress class="endpaper-reading-goal__bar" max="100" value="<?php echo esc_attr( $endpaper_bar ); ?>" aria-label="<?php echo esc_attr( $endpaper_percent_label ); ?>"></progress>

		<?php // Hardcover's logo is a credit, so it is only shown if that is switched on. ?>
		<?php if ( ! empty( $attributes['showCredit'] ) ) : ?>
			<p class="endpaper-reading-goal__attribution">
				<?php the_hardcover_credit( '', 'endpaper-reading-goal__credit' ); ?>
			</p>
		<?php endif; ?>

	</div>
</div>
