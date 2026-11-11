<?php
/**
 * The settings screen, where the Hardcover API key is entered.
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

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
}
add_action( 'admin_init', __NAMESPACE__ . '\register_settings' );

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

	foreach ( array( 'endpaper_list_v3_' . $hash . '_', 'endpaper_entry_v3_' . $hash . '_' ) as $prefix ) {
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

/**
 * Outputs the notice that says whether the key works.
 *
 * @return void
 */
function the_connection_notice() {
	$status = get_connection_status();

	if ( 'none' === $status['state'] ) {
		printf(
			'<div class="notice notice-info inline"><p>%s</p></div>',
			esc_html__( 'No API key yet. The blocks cannot show anything until one is saved.', 'endpaper' )
		);

		return;
	}

	if ( 'error' === $status['state'] ) {
		printf(
			'<div class="notice notice-error inline"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Not connected.', 'endpaper' ),
			esc_html( $status['message'] )
		);

		return;
	}

	if ( '' !== $status['username'] ) {
		$message = sprintf(
			/* translators: %s: A Hardcover username. */
			__( 'Connected to Hardcover as %s.', 'endpaper' ),
			'@' . $status['username']
		);
	} else {
		$message = __( 'Connected to Hardcover.', 'endpaper' );
	}

	printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $message ) );
}

/**
 * Returns the permissions an API key needs, and what each is for.
 *
 * @return array What each permission is for, by its name on Hardcover.
 */
function get_key_scopes() {
	return array(
		'read:catalog'        => __( 'Looking up and searching for books.', 'endpaper' ),
		'read:library:public' => __( 'Your reading lists, shelves and progress. Public entries only.', 'endpaper' ),
		'read:me:content'     => __( 'Your username, to confirm whose key it is.', 'endpaper' ),
		'read:goals:public'   => __( 'Your reading goals, for a block to come.', 'endpaper' ),
		'read:lists:public'   => __( 'Your lists, for a block to come.', 'endpaper' ),
	);
}

/**
 * Returns the link to Hardcover's form for a new API key, with the permissions Endpaper needs already ticked.
 *
 * @return string The URL.
 */
function get_new_key_url() {
	return ENDPAPER_NEW_KEY_URL . '?scope=' . rawurlencode( implode( ' ', array_keys( get_key_scopes() ) ) );
}

/**
 * Outputs the settings screen.
 *
 * The saved key is never output, not even in part. When one is saved, the
 * field shows a row of dots, always the same number, so it looks filled in
 * without giving away the key or its length.
 *
 * @return void
 */
function render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$in_config = is_token_in_config();
	$has_token = '' !== get_token();

	$scopes = get_key_scopes();

	// Without a key that works, the cache is what keeps the books on the site.
	$key_trouble = '' !== get_token_trouble();

	// Only read, to say what the button on this screen did. Nothing is changed based on it.
	$cache_result = isset( $_GET['endpaper_cache'] ) ? sanitize_key( wp_unslash( $_GET['endpaper_cache'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Endpaper', 'endpaper' ); ?></h1>

		<p><?php esc_html_e( 'Endpaper shows books from Hardcover. To do that it needs an API key from your Hardcover account.', 'endpaper' ); ?></p>

		<?php if ( 'cleared' === $cache_result ) : ?>
			<div class="notice notice-success inline is-dismissible"><p><?php esc_html_e( 'The cache was cleared. Everything is fetched from Hardcover again the next time it is shown.', 'endpaper' ); ?></p></div>
		<?php elseif ( 'kept' === $cache_result ) : ?>
			<div class="notice notice-warning inline is-dismissible"><p><?php esc_html_e( 'The cache was not cleared, because there is no working API key.', 'endpaper' ); ?></p></div>
		<?php endif; ?>

		<?php the_connection_notice(); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'endpaper' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="endpaper-token"><?php esc_html_e( 'Hardcover API key', 'endpaper' ); ?></label></th>
					<td>
						<?php if ( $in_config ) : ?>
							<input type="text" id="endpaper-token" class="regular-text" value="<?php esc_attr_e( 'Set in wp-config.php', 'endpaper' ); ?>" disabled />
							<p class="description">
								<?php
								printf(
									/* translators: %s: The name of a PHP constant. */
									esc_html__( 'The key comes from the %s constant in wp-config.php. To change it here, remove the constant first.', 'endpaper' ),
									'<code>ENDPAPER_HARDCOVER_TOKEN</code>'
								);
								?>
							</p>
						<?php else : ?>
							<input type="password" id="endpaper-token" name="<?php echo esc_attr( ENDPAPER_TOKEN_OPTION ); ?>" class="regular-text" value="" autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr( $has_token ? str_repeat( '•', 24 ) : '' ); ?>" aria-describedby="endpaper-token-description" />
							<p class="description" id="endpaper-token-description">
								<?php
								echo esc_html(
									$has_token
										? __( 'The saved key is not shown. Paste a new key to replace it, or leave the field empty to keep it.', 'endpaper' )
										: __( 'Paste the key from Hardcover. It is stored on this site and only ever sent to Hardcover.', 'endpaper' )
								);
								?>
							</p>
							<?php if ( $has_token ) : ?>
								<p>
									<label>
										<input type="checkbox" name="endpaper_remove_token" value="1" />
										<?php esc_html_e( 'Remove the saved key', 'endpaper' ); ?>
									</label>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php
			if ( ! $in_config ) {
				submit_button();
			}
			?>
		</form>

		<h2><?php esc_html_e( 'Cache', 'endpaper' ); ?></h2>

		<p><?php esc_html_e( 'Endpaper saves what it fetches from Hardcover, so your pages stay fast and Hardcover is not asked more often than needed. Reading lists, shelves and progress are fetched again every hour, and the details of a book every week. Clear the cache to fetch everything again the next time it is shown, for example after changing something on Hardcover.', 'endpaper' ); ?></p>

		<?php if ( $key_trouble ) : ?>
			<p><?php esc_html_e( 'The cache cannot be cleared without a working API key. The books on your site are shown from it, and would disappear.', 'endpaper' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="endpaper_clear_cache" />
			<?php wp_nonce_field( 'endpaper_clear_cache' ); ?>
			<?php submit_button( __( 'Clear the cache', 'endpaper' ), 'secondary', 'endpaper-clear-cache', true, $key_trouble ? array( 'disabled' => 'disabled' ) : null ); ?>
		</form>

		<h2><?php esc_html_e( 'Creating a key', 'endpaper' ); ?></h2>

		<ol>
			<li><?php esc_html_e( 'Open the form for a new key on Hardcover with the button below. The permissions Endpaper needs are already ticked.', 'endpaper' ); ?></li>
			<li><?php esc_html_e( 'Give the key a name, and choose when it expires. With “Never” you will not have to replace it.', 'endpaper' ); ?></li>
			<li><?php esc_html_e( 'Create the key, copy it, and paste it into the field above.', 'endpaper' ); ?></li>
		</ol>

		<p>
			<a class="button button-secondary" href="<?php echo esc_url( get_new_key_url() ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Create a key on Hardcover', 'endpaper' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'endpaper' ); ?></span>
			</a>
		</p>

		<h2><?php esc_html_e( 'What the key allows', 'endpaper' ); ?></h2>

		<p><?php esc_html_e( 'The button asks for these permissions and nothing else:', 'endpaper' ); ?></p>

		<table class="widefat striped">
			<tbody>
				<?php foreach ( $scopes as $endpaper_scope => $endpaper_purpose ) : ?>
					<tr>
						<td><code><?php echo esc_html( $endpaper_scope ); ?></code></td>
						<td><?php echo esc_html( $endpaper_purpose ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description">
			<?php esc_html_e( 'A key cannot be changed once it is created, which is why the last two are included now. None of these let Endpaper change anything on Hardcover, or read anything you have not made public.', 'endpaper' ); ?>
		</p>

		<h2><?php esc_html_e( 'How your key is kept safe', 'endpaper' ); ?></h2>

		<ul class="ul-disc">
			<li><?php esc_html_e( 'It is only sent to Hardcover. Your server makes all the requests, so the key never reaches your visitors’ browsers, the page source or the editor.', 'endpaper' ); ?></li>
			<li><?php esc_html_e( 'It is never shown again. Once saved, nobody can read it back from this screen, administrators included.', 'endpaper' ); ?></li>
			<li><?php esc_html_e( 'It can only read. The permissions above do not let anyone change your Hardcover account, or see anything you have not made public.', 'endpaper' ); ?></li>
			<li><?php esc_html_e( 'It is removed with the plugin. Deleting Endpaper deletes the key.', 'endpaper' ); ?></li>
		</ul>

		<p><?php esc_html_e( 'The key is stored in your site’s database as it is, not encrypted, which is how WordPress stores settings. Anyone who can read the database, or a backup of it, can read the key. If you think it has got out, delete it on Hardcover and create a new one: the old key stops working at once.', 'endpaper' ); ?></p>

		<p>
			<?php
			printf(
				/* translators: 1: The name of a PHP constant, 2: A file name. */
				esc_html__( 'To keep the key out of the database, define it as %1$s in %2$s instead. This screen then stops asking for it.', 'endpaper' ),
				'<code>ENDPAPER_HARDCOVER_TOKEN</code>',
				'<code>wp-config.php</code>'
			);
			?>
		</p>
	</div>
	<?php
}
