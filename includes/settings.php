<?php
/**
 * The settings: the option for the Hardcover API key and how it is saved, the notices about it, the menu entry, and the privacy text. The screen itself is in settings-page.php.
 *
 * @package Endpaper
 */

namespace Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * Name of the option the API key is stored in.
 */
define( 'ENDPAPER_TOKEN_OPTION', 'endpaper_hardcover_token' );

/**
 * Where a person creates an API key on Hardcover.
 */
define( 'ENDPAPER_NEW_KEY_URL', 'https://hardcover.app/account/api/keys/new' );

/**
 * Where to ask for help with the plugin: its own forum, not Hardcover.
 */
define( 'ENDPAPER_SUPPORT_URL', 'https://wordpress.org/support/plugin/endpaper/' );

/**
 * Where to review the plugin on WordPress.org.
 */
define( 'ENDPAPER_REVIEW_URL', 'https://wordpress.org/support/plugin/endpaper/reviews/#new-post' );

/**
 * Where feature requests go: the issues of the plugin's repository.
 */
define( 'ENDPAPER_ISSUES_URL', 'https://github.com/andersnoren/endpaper/issues' );

/**
 * Returns the URL of the settings screen.
 *
 * @return string The URL.
 */
function get_settings_url() {
	return admin_url( 'options-general.php?page=endpaper' );
}

/**
 * Tells whether the API key is set in wp-config.php, which takes precedence over the settings screen.
 *
 * @return bool Whether the ENDPAPER_HARDCOVER_TOKEN constant holds a key.
 */
function is_token_in_config() {
	return defined( 'ENDPAPER_HARDCOVER_TOKEN' ) && '' !== clean_token( ENDPAPER_HARDCOVER_TOKEN );
}

/**
 * Registers the setting, and makes sure it is not loaded on every page view.
 *
 * WordPress loads most options into memory on every request. The API key is
 * only needed when Hardcover is asked for something, so the option is created
 * with autoloading off. The setting is registered without a default value on
 * purpose: with one, WordPress would create the option again on the first
 * save, and turn autoloading back on.
 *
 * @return void
 */
function register_settings() {
	if ( false === get_option( ENDPAPER_TOKEN_OPTION, false ) ) {
		add_option( ENDPAPER_TOKEN_OPTION, '', '', false );
	}

	register_setting(
		'endpaper',
		ENDPAPER_TOKEN_OPTION,
		array(
			'type'              => 'string',
			'sanitize_callback' => __NAMESPACE__ . '\sanitize_token_setting',
			'show_in_rest'      => false,
		)
	);

	register_setting(
		'endpaper_appearance',
		ENDPAPER_DEFAULT_STYLE_OPTION,
		array(
			'type'              => 'string',
			'default'           => 'default',
			'sanitize_callback' => __NAMESPACE__ . '\sanitize_default_style_setting',
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );

/**
 * Makes sure the default style is one the Book block has.
 *
 * @param mixed $value The submitted style.
 * @return string The name of the style, or "default" for the Plain style.
 */
function sanitize_default_style_setting( $value ) {
	return is_string( $value ) && isset( get_book_styles()[ $value ] ) ? $value : 'default';
}

/**
 * Decides what to store when the settings form is saved.
 *
 * The form never shows the saved key, so an empty field means "keep the key",
 * not "remove it". Removing is a separate checkbox.
 *
 * @param mixed $value The submitted key.
 * @return string The key to store.
 */
function sanitize_token_setting( $value ) {
	$current = clean_token( get_option( ENDPAPER_TOKEN_OPTION, '' ) );

	// With the key in wp-config.php the field is locked, and nothing changes.
	if ( is_token_in_config() ) {
		return $current;
	}

	// WordPress has checked the form's nonce before this runs.
	if ( ! empty( $_POST['endpaper_remove_token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		forget_account( $current );

		return '';
	}

	$value = is_string( $value ) ? trim( $value ) : '';

	if ( '' === $value ) {
		return $current;
	}

	$token = clean_token( $value );

	if ( '' === $token ) {
		add_settings_error(
			'endpaper',
			'endpaper_invalid_token',
			'' !== $current
				? __( 'That does not look like a Hardcover API key, so it was not saved. The key that was saved before is still in use.', 'endpaper' )
				: __( 'That does not look like a Hardcover API key, so it was not saved. Copy the whole key and try again.', 'endpaper' )
		);

		return $current;
	}

	if ( $token === $current ) {
		return $current;
	}

	// Try the key before it replaces one that may be working. Only a clear "no" from Hardcover stops it: if Hardcover cannot be reached, the key is saved and checked later.
	$check = api_request( 'query EndpaperConnection { me { username } }', array(), $token );

	if ( is_wp_error( $check ) && 'endpaper_unauthorized' === $check->get_error_code() ) {
		add_settings_error(
			'endpaper',
			'endpaper_rejected_token',
			'' !== $current
				? __( 'Hardcover did not accept that key, so it was not saved. The key that was saved before is still in use.', 'endpaper' )
				: __( 'Hardcover did not accept that key, so it was not saved. Check that you copied the whole key, and that it has not expired.', 'endpaper' )
		);

		return $current;
	}

	forget_account( $current );
	forget_account( $token );

	return $token;
}

/**
 * Removes what is cached for a key: the connection check, the reading lists and the shelves.
 *
 * Without the key the cached data can no longer be reached anyway, because
 * the key is part of the name it is stored under. This clears it out. Books
 * are kept: they are public catalog data, not tied to an account.
 *
 * @param string $token The API key.
 * @return void
 */
function forget_account( $token ) {
	global $wpdb;

	delete_transient( 'endpaper_api_backoff' );
	delete_transient( 'endpaper_key_rejected' );

	if ( '' === $token ) {
		return;
	}

	$hash = substr( md5( $token ), 0, 10 );

	delete_transient( cache_key( 'endpaper_connection_' . $hash ) );

	// With an external object cache the entries are not in the database. They expire within a week.
	if ( wp_using_ext_object_cache() ) {
		return;
	}

	foreach ( array( 'endpaper_list_v3_' . $hash . '_', 'endpaper_entry_v7_' . $hash . '_' ) as $prefix ) {
		foreach ( array( '_transient_', '_transient_timeout_' ) as $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients cannot be deleted by prefix any other way.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $kind . $prefix ) . '%' ) );
		}
	}
}

/**
 * Checks the API key with Hardcover, and finds out whose it is.
 *
 * The answer is remembered for five minutes, so the settings screen does not
 * ask Hardcover every time it is opened.
 *
 * @return array The state ("none", "connected" or "error"), with the username or the error message.
 */
function get_connection_status() {
	$token = get_token();

	if ( '' === $token ) {
		return array( 'state' => 'none' );
	}

	$cache_key = cache_key( 'endpaper_connection_' . substr( md5( $token ), 0, 10 ) );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) && isset( $cached['state'] ) ) {
		return $cached;
	}

	$data = api_request( 'query EndpaperConnection { me { username } }' );

	if ( is_wp_error( $data ) ) {
		$status = array(
			'state'   => 'error',
			'message' => $data->get_error_message(),
		);
	} else {
		$status = array(
			'state'    => 'connected',
			'username' => isset( $data['me'][0]['username'] ) ? sanitize_text_field( $data['me'][0]['username'] ) : '',
		);
	}

	set_transient( $cache_key, $status, 5 * MINUTE_IN_SECONDS );

	return $status;
}

/**
 * Describes what is wrong with the API key, if Hardcover has turned it down.
 *
 * @return string The description, or an empty string if nothing is known to be wrong.
 */
function get_key_problem_message() {
	return is_token_rejected()
		? __( 'Hardcover no longer accepts the API key. It may have expired or been deleted. The books on your site will not update until a new key is saved.', 'endpaper' )
		: '';
}

/**
 * Tells the site's administrators when the API key has stopped working.
 *
 * The notice is on every admin screen but the plugin's own, which says the
 * same in its own words. It goes away by itself once a working key is saved.
 *
 * @return void
 */
function the_key_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = get_current_screen();

	if ( $screen && 'settings_page_endpaper' === $screen->id ) {
		return;
	}

	$message = get_key_problem_message();

	if ( '' === $message ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s %3$s</p></div>',
		esc_html__( 'Endpaper:', 'endpaper' ),
		esc_html( $message ),
		is_token_in_config()
			? esc_html__( 'The key is set in wp-config.php.', 'endpaper' )
			: '<a href="' . esc_url( get_settings_url() ) . '">' . esc_html__( 'Go to the Endpaper settings', 'endpaper' ) . '</a>'
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\the_key_notice' );

/**
 * Sends the person who just activated the plugin to the settings screen, to add an API key.
 *
 * Not when a key is already saved, and not when several plugins were
 * activated at once: that would take them away from the list they were
 * working through.
 *
 * @return void
 */
function redirect_after_activation() {
	if ( ! get_transient( 'endpaper_activation_redirect' ) ) {
		return;
	}

	delete_transient( 'endpaper_activation_redirect' );

	// Only read, to tell a bulk activation from a single one. Nothing is changed based on it.
	$is_bulk = isset( $_GET['activate-multi'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( $is_bulk || wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// With a key in place there is nothing to set up.
	if ( '' !== get_token() ) {
		return;
	}

	wp_safe_redirect( get_settings_url() );
	exit;
}
add_action( 'admin_init', __NAMESPACE__ . '\redirect_after_activation' );

/**
 * Clears the cache when the button on the settings screen is used.
 *
 * Not while the API key is missing or turned down: the books on the site are
 * then shown from the cache, and would be gone until a key works again.
 *
 * @return void
 */
function handle_clear_cache() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'endpaper' ), 403 );
	}

	check_admin_referer( 'endpaper_clear_cache' );

	$result = 'kept';

	if ( '' === get_token_trouble() ) {
		clear_cache();
		$result = 'cleared';
	}

	wp_safe_redirect( add_query_arg( 'endpaper_cache', $result, get_settings_url() ) );
	exit;
}
add_action( 'admin_post_endpaper_clear_cache', __NAMESPACE__ . '\handle_clear_cache' );

/**
 * Returns what Endpaper suggests for the site's privacy policy.
 *
 * It depends on where covers come from. Normally they are shown from the
 * site itself, and visitors' browsers never contact Hardcover. On a site
 * that cannot save them, they are loaded from Hardcover, which is something
 * a privacy policy should say.
 *
 * @return string HTML, in the shape the privacy policy guide expects.
 */
function get_privacy_policy_text() {
	$covers_from_hardcover = are_remote_covers_allowed() && ( ! is_cover_import_enabled() || ! can_store_covers() );
	$policy_url            = 'https://hardcover.app/pages/privacy-policy-2';

	$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Endpaper shows books from Hardcover, a book tracking service. It collects nothing about your visitors, sets no cookies, and sends nothing about your visitors to Hardcover.', 'endpaper' ) . '</p>';

	if ( $covers_from_hardcover ) {
		$content .= '<p class="privacy-policy-tutorial">' . esc_html__( 'On this site, book covers are loaded from Hardcover’s servers, because they are not saved on the site itself. Your visitors’ browsers contact Hardcover to fetch them, so your privacy policy should say so. Site Health explains why covers are not saved.', 'endpaper' ) . '</p>';
	} else {
		$content .= '<p class="privacy-policy-tutorial">' . esc_html__( 'On this site, book covers are saved on the site itself, so your visitors’ browsers do not contact Hardcover. You may still want to mention that books link to Hardcover.', 'endpaper' ) . '</p>';
	}

	$content .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'endpaper' ) . '</strong> ';

	if ( $covers_from_hardcover ) {
		$content .= sprintf(
			/* translators: %s: The address of Hardcover's privacy policy. */
			esc_html__( 'This site shows books from Hardcover, a book tracking service. The covers of those books are loaded from Hardcover’s servers. When you view a page with a book on it, your browser requests the cover from Hardcover, which receives your IP address and details about your browser. Hardcover’s privacy policy is at %s.', 'endpaper' ),
			esc_url( $policy_url )
		);
	} else {
		$content .= sprintf(
			/* translators: %s: The address of Hardcover's privacy policy. */
			esc_html__( 'This site shows books from Hardcover, a book tracking service. Nothing about you is sent to Hardcover when you view these books: the book covers are stored on this site. Titles and covers may link to Hardcover. If you follow such a link, Hardcover’s privacy policy applies, which is at %s.', 'endpaper' ),
			esc_url( $policy_url )
		);
	}

	$content .= '</p>';

	return $content;
}

/**
 * Offers the suggested text in the privacy policy guide, under Settings → Privacy.
 *
 * @return void
 */
function add_privacy_policy_content() {
	if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
		wp_add_privacy_policy_content( __( 'Endpaper', 'endpaper' ), wp_kses_post( get_privacy_policy_text() ) );
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\add_privacy_policy_content' );

/**
 * Adds the settings screen to the Settings menu.
 *
 * @return void
 */
function add_settings_page() {
	add_options_page(
		__( 'Endpaper', 'endpaper' ),
		__( 'Endpaper', 'endpaper' ),
		'manage_options',
		'endpaper',
		__NAMESPACE__ . '\render_settings_page'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\add_settings_page' );

/**
 * Adds a Settings link to the plugin's row on the Plugins screen.
 *
 * @param array $links The plugin's action links.
 * @return array The links, with Settings first.
 */
function add_settings_link( $links ) {
	array_unshift( $links, '<a href="' . esc_url( get_settings_url() ) . '">' . esc_html__( 'Settings', 'endpaper' ) . '</a>' );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( ENDPAPER_DIR . 'endpaper.php' ), __NAMESPACE__ . '\add_settings_link' );
