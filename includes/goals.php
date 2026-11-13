<?php
/**
 * Reading goals from Hardcover.
 *
 * @package Endpaper
 */

namespace Endpaper;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the units a reading goal can be counted in, as Hardcover names them.
 *
 * @return array The units.
 */
function get_goal_metrics() {
	return array( 'book', 'page', 'hour' );
}

/**
 * Turns a goal from Hardcover into the shape the blocks use.
 *
 * @param array $raw A goal from the API.
 * @return array The goal, with a title, a unit, a target, the progress and the dates it runs between.
 */
function normalize_goal( $raw ) {
	$metric = isset( $raw['metric'] ) && in_array( $raw['metric'], get_goal_metrics(), true ) ? $raw['metric'] : '';
	$start  = isset( $raw['start_date'] ) && is_string( $raw['start_date'] ) ? $raw['start_date'] : '';
	$end    = isset( $raw['end_date'] ) && is_string( $raw['end_date'] ) ? $raw['end_date'] : '';

	return array(
		'id'       => isset( $raw['id'] ) ? absint( $raw['id'] ) : 0,
		'title'    => isset( $raw['description'] ) && is_string( $raw['description'] ) ? sanitize_text_field( $raw['description'] ) : '',
		'metric'   => $metric,
		'target'   => isset( $raw['goal'] ) && is_numeric( $raw['goal'] ) ? (float) $raw['goal'] : 0.0,
		'progress' => isset( $raw['progress'] ) && is_numeric( $raw['progress'] ) ? max( 0.0, (float) $raw['progress'] ) : 0.0,
		'start'    => $start,
		'end'      => $end,
	);
}

/**
 * Returns the reading goals of the person the API token belongs to.
 *
 * Only goals that are public on Hardcover are returned, and not the ones that
 * are archived. The goals are filtered here, not in the query, so the query
 * only uses what is known to work.
 *
 * @return array|WP_Error List of normalized goals, newest first, or an error.
 */
function get_goals() {
	// The token is part of the key, so switching to another account starts fresh. The version changes when the stored shape does.
	$cache_key = get_goals_cache_key();
	$cached    = get_transient( $cache_key );
	$stale     = is_array( $cached ) && isset( $cached['fetched'], $cached['goals'] ) ? $cached['goals'] : null;

	if ( null !== $stale && ( time() - (int) $cached['fetched'] ) < get_cache_lifetime( 'goals' ) ) {
		return $stale;
	}

	if ( refresh_later( 'goals', array() ) ) {
		return null !== $stale ? $stale : get_pending_error();
	}

	// After a failed request, wait a few minutes before asking Hardcover again.
	$backoff = get_transient( 'endpaper_api_backoff' );

	if ( $backoff ) {
		return null !== $stale ? $stale : new WP_Error( 'endpaper_backoff', (string) $backoff, array( 'status' => 503 ) );
	}

	$data = api_request( 'query EndpaperGoals { me { goals(order_by: {end_date: desc}) { id description goal metric progress start_date end_date privacy_setting_id archived } } }' );

	if ( is_wp_error( $data ) ) {
		set_backoff( $data->get_error_message() );

		return null !== $stale ? $stale : $data;
	}

	$raw_goals = isset( $data['me'][0]['goals'] ) && is_array( $data['me'][0]['goals'] ) ? $data['me'][0]['goals'] : array();
	$goals     = array();

	foreach ( $raw_goals as $raw_goal ) {
		if ( ! is_array( $raw_goal ) || 1 !== (int) ( isset( $raw_goal['privacy_setting_id'] ) ? $raw_goal['privacy_setting_id'] : 0 ) || ! empty( $raw_goal['archived'] ) ) {
			continue;
		}

		$goal = normalize_goal( $raw_goal );

		// A goal in a unit this plugin cannot word, or without a target, has nothing to show.
		if ( $goal['id'] && '' !== $goal['metric'] && $goal['target'] > 0 ) {
			$goals[] = $goal;
		}
	}

	set_transient(
		$cache_key,
		array(
			'fetched' => time(),
			'goals'   => $goals,
		),
		WEEK_IN_SECONDS
	);

	return $goals;
}

/**
 * Returns one of the reading goals.
 *
 * Without an ID, the goal is the only one there is. With more goals than one,
 * the person has to pick, so there is none to return.
 *
 * @param int $goal_id The goal's ID, or 0 for the only goal.
 * @return array|null|WP_Error The goal, null if there is no such goal, or an error.
 */
function get_goal( $goal_id ) {
	$goal_id = absint( $goal_id );
	$goals   = get_goals();

	if ( is_wp_error( $goals ) ) {
		return $goals;
	}

	if ( ! $goal_id ) {
		return 1 === count( $goals ) ? $goals[0] : null;
	}

	foreach ( $goals as $goal ) {
		if ( $goal['id'] === $goal_id ) {
			return $goal;
		}
	}

	return null;
}

/**
 * Returns the goal that is running now, or else the latest one.
 *
 * Used where nobody picks a goal, such as the shortcode.
 *
 * @return array|null|WP_Error The goal, null if there are none, or an error.
 */
function get_current_goal() {
	$goals = get_goals();

	if ( is_wp_error( $goals ) || ! $goals ) {
		return is_wp_error( $goals ) ? $goals : null;
	}

	$today = gmdate( 'Y-m-d' );

	foreach ( $goals as $goal ) {
		if ( ( '' === $goal['start'] || $goal['start'] <= $today ) && ( '' === $goal['end'] || $goal['end'] >= $today ) ) {
			return $goal;
		}
	}

	return $goals[0];
}
