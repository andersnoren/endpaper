<?php
/**
 * Site Health tests: the state of the API key, and of the covers stored on the site.
 *
 * @package Endpaper
 */

namespace Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the state of the API key to the Site Health screen.
 *
 * @param array $tests The Site Health tests.
 * @return array The tests, with Endpaper's added.
 */
function add_site_health_test( $tests ) {
	$tests['direct']['endpaper_api_key'] = array(
		'label' => __( 'Endpaper API key', 'endpaper' ),
		'test'  => __NAMESPACE__ . '\get_site_health_result',
	);

	$tests['direct']['endpaper_covers'] = array(
		'label' => __( 'Endpaper book covers', 'endpaper' ),
		'test'  => __NAMESPACE__ . '\get_covers_site_health_result',
	);

	return $tests;
}
add_filter( 'site_status_tests', __NAMESPACE__ . '\add_site_health_test' );

/**
 * Reports the state of the API key to the Site Health screen.
 *
 * @return array The result, in the shape Site Health expects.
 */
function get_site_health_result() {
	$message = get_key_problem_message();
	$result  = array(
		'label'       => __( 'Endpaper has a Hardcover API key', 'endpaper' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Endpaper', 'endpaper' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'Endpaper needs an API key from Hardcover to show books. Hardcover accepted the key the last time it was used.', 'endpaper' ) . '</p>',
		'actions'     => '',
		'test'        => 'endpaper_api_key',
	);

	if ( '' === get_token() ) {
		$result['label']       = __( 'Endpaper has no Hardcover API key', 'endpaper' );
		$result['status']      = 'recommended';
		$result['description'] = '<p>' . esc_html__( 'Endpaper needs an API key from Hardcover to show books. Until one is saved, its blocks show nothing.', 'endpaper' ) . '</p>';
	} elseif ( '' !== $message ) {
		$result['label']       = __( 'Endpaper’s Hardcover API key has stopped working', 'endpaper' );
		$result['status']      = 'critical';
		$result['description'] = '<p>' . esc_html( $message ) . '</p>';
	}

	if ( 'good' !== $result['status'] && ! is_token_in_config() ) {
		$result['actions'] = '<p><a href="' . esc_url( get_settings_url() ) . '">' . esc_html__( 'Go to the Endpaper settings', 'endpaper' ) . '</a></p>';
	}

	return $result;
}

/**
 * Returns the text that says covers are waiting for scheduled tasks to run.
 *
 * @param int $waiting The number of covers waiting.
 * @return string The text, not escaped.
 */
function get_cover_wait_text( $waiting ) {
	$text = sprintf(
		/* translators: %s: A number of book covers. */
		_n( '%s book cover is waiting to be saved, but this site’s scheduled tasks do not seem to be running. Until they do, it is shown as a gray box.', '%s book covers are waiting to be saved, but this site’s scheduled tasks do not seem to be running. Until they do, they are shown as gray boxes.', $waiting, 'endpaper' ),
		number_format_i18n( $waiting )
	);

	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		$text .= ' ' . __( 'WP-Cron is switched off on this site, so a job on the server has to run wp-cron.php at regular intervals.', 'endpaper' );
	}

	return $text;
}

/**
 * Reports in Site Health whether book covers can be saved on the site.
 *
 * When they cannot, they are loaded from Hardcover by each visitor's
 * browser, which the site's owner should know about.
 *
 * @return array The result, in the shape Site Health expects.
 */
function get_covers_site_health_result() {
	$dir    = get_cover_dir();
	$result = array(
		'label'       => __( 'Endpaper saves book covers on your site', 'endpaper' ),
		'status'      => 'good',
		'badge'       => array(
			'label' => __( 'Endpaper', 'endpaper' ),
			'color' => 'blue',
		),
		'description' => '<p>' . esc_html__( 'Book covers are downloaded from Hardcover once and shown from your own site, so your visitors’ browsers do not contact Hardcover.', 'endpaper' ) . '</p>',
		'actions'     => '',
		'test'        => 'endpaper_covers',
	);

	if ( ! is_cover_import_enabled() && ! are_remote_covers_allowed() ) {
		$result['label']       = __( 'Endpaper is set not to show book covers', 'endpaper' );
		$result['description'] = '<p>' . esc_html__( 'Both saving book covers and loading them from Hardcover have been switched off in code, so no covers are shown.', 'endpaper' ) . '</p>';
	} elseif ( ! is_cover_import_enabled() ) {
		$result['label']       = __( 'Endpaper is set not to save book covers', 'endpaper' );
		$result['description'] = '<p>' . esc_html__( 'Saving book covers has been switched off in code. Covers are loaded from Hardcover by your visitors’ browsers, so Hardcover sees their IP address and browser details.', 'endpaper' ) . '</p>';
	} elseif ( ! can_store_covers() && ! are_remote_covers_allowed() ) {
		$result['label']       = __( 'Endpaper cannot show book covers', 'endpaper' );
		$result['status']      = 'recommended';
		$result['description'] = '<p>' . sprintf(
			/* translators: %s: The path of a folder. */
			esc_html__( 'The folder %s cannot be created or written to, and this site is set not to load covers from Hardcover. Until the folder can be written to, books are shown without their covers.', 'endpaper' ),
			'<code>' . esc_html( '' !== $dir['path'] ? $dir['path'] : 'wp-content/uploads/endpaper/covers' ) . '</code>'
		) . '</p>';
	} elseif ( ! can_store_covers() ) {
		$result['label']       = __( 'Endpaper cannot save book covers', 'endpaper' );
		$result['status']      = 'recommended';
		$result['description'] = '<p>' . sprintf(
			/* translators: %s: The path of a folder. */
			esc_html__( 'The folder %s cannot be created or written to. Until it can, covers are loaded from Hardcover by your visitors’ browsers, so Hardcover sees their IP address and browser details.', 'endpaper' ),
			'<code>' . esc_html( '' !== $dir['path'] ? $dir['path'] : 'wp-content/uploads/endpaper/covers' ) . '</code>'
		) . '</p>';
	}

	$queue = get_cover_queue_status();

	if ( 'good' === $result['status'] && $queue['stuck'] ) {
		$result['label']       = __( 'Endpaper is waiting for scheduled tasks to run', 'endpaper' );
		$result['status']      = 'recommended';
		$result['description'] = '<p>' . esc_html( get_cover_wait_text( $queue['waiting'] ) ) . '</p>';
		$result['actions']     = sprintf(
			'<p><a href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a></p>',
			esc_url( 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/' ),
			esc_html__( 'How to run scheduled tasks from the server', 'endpaper' ),
			esc_html__( '(opens in a new tab)', 'endpaper' )
		);
	}

	return $result;
}
