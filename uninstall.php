<?php
/**
 * Runs when the plugin is deleted: removes the API key and everything cached.
 *
 * @package Endpaper
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin's data from the current site.
 *
 * @return void
 */
function endpaper_remove_site_data() {
	global $wpdb;

	delete_option( 'endpaper_hardcover_token' );

	foreach ( array( '_transient_endpaper_', '_transient_timeout_endpaper_' ) as $prefix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients cannot be deleted by prefix any other way.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
	}
}

if ( is_multisite() ) {
	// Each site in a network has its own key and its own cache.
	$endpaper_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $endpaper_site_ids as $endpaper_site_id ) {
		switch_to_blog( $endpaper_site_id );
		endpaper_remove_site_data();
		restore_current_blog();
	}
} else {
	endpaper_remove_site_data();
}
