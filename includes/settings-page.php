<?php
/**
 * The settings screen: its page, styles and the text on it.
 *
 * @package Endpaper
 */

namespace Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * Returns the blocks to list on the settings screen.
 *
 * The icons are the ones the blocks have in the editor: a block with a path
 * in assets/icons.json, under its icon name, is drawn from that SVG path on a
 * 20 by 20 grid, and any other falls back to a Dashicon of that name. The
 * same file gives the editor its icons.
 *
 * @return array The blocks, each with a name, icon, title, description and shortcut.
 */
function get_settings_blocks() {
	return array(
		array(
			'name'        => 'endpaper/book',
			'icon'        => 'book',
			'title'       => __( 'Book', 'endpaper' ),
			'description' => __( 'One book, with its cover, description and rating on Hardcover, and your own shelf, rating and reading progress.', 'endpaper' ),
			'shortcut'    => '/book',
		),
		array(
			'name'        => 'endpaper/reading-list',
			'icon'        => 'reading-list',
			'title'       => __( 'Reading List', 'endpaper' ),
			'description' => __( 'The books you are currently reading, want to read or have read, as a list or a grid.', 'endpaper' ),
			'shortcut'    => '/reading list',
		),
		array(
			'name'        => 'endpaper/reading-goal',
			'icon'        => 'reading-goal',
			'title'       => __( 'Reading Goal', 'endpaper' ),
			'description' => __( 'How far along you are on one of your reading goals, in books, pages or hours.', 'endpaper' ),
			'shortcut'    => '/reading goal',
		),
	);
}

/**
 * Returns the patterns to list on the settings screen, with the titles and descriptions they are registered with.
 *
 * @return array The patterns, each with a title and a description.
 */
function get_settings_patterns() {
	$registry = \WP_Block_Patterns_Registry::get_instance();
	$patterns = array();

	foreach ( array( 'endpaper/currently-reading', 'endpaper/recently-read' ) as $name ) {
		$pattern = $registry->get_registered( $name );

		if ( $pattern ) {
			$patterns[] = array(
				'title'       => $pattern['title'],
				'description' => isset( $pattern['description'] ) ? $pattern['description'] : '',
			);
		}
	}

	return $patterns;
}

/**
 * Loads the styles for the settings screen.
 *
 * @param string $hook_suffix The current admin screen.
 * @return void
 */
function enqueue_settings_styles( $hook_suffix ) {
	if ( 'settings_page_endpaper' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_style( 'endpaper-settings', plugins_url( 'assets/settings.css', ENDPAPER_DIR . 'endpaper.php' ), array(), ENDPAPER_VERSION );
	wp_enqueue_script( 'endpaper-settings', plugins_url( 'assets/settings.js', ENDPAPER_DIR . 'endpaper.php' ), array(), ENDPAPER_VERSION, array( 'in_footer' => true ) );
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_settings_styles' );

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
		'read:goals:public'   => __( 'Your reading goals, for the Reading Goal block.', 'endpaper' ),
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
 * The screen is in the order people need it. Without a key, how to create
 * one comes first. With a key, that folds away, unless Hardcover has turned
 * the key down, and the cache gets its section.
 * With a working key the screen also lists the blocks, as the next step.
 * Help holds the questions, and About Endpaper the credit and the links out.
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

	// Checked first, so that a key Hardcover has just turned down is known about below.
	get_connection_status();

	// Without a key that works, the cache is what keeps the books on the site.
	$key_trouble = '' !== get_token_trouble();

	// A key that Hardcover has turned down has to be replaced, so how to create one is shown.
	$key_rejected = 'rejected' === get_token_trouble();

	$cover_stats = get_cover_stats();
	$cover_queue = get_cover_queue_status();
	$cover_dir   = get_cover_dir();

	// The covers' folder as it is best recognized: from the site's own folder on.
	$cover_path = '' !== $cover_dir['path'] ? str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $cover_dir['path'] ) ) : 'wp-content/uploads/endpaper/covers';

	// Only read, to say what the button on this screen did. Nothing is changed based on it.
	$cache_result = isset( $_GET['endpaper_cache'] ) ? sanitize_key( wp_unslash( $_GET['endpaper_cache'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	?>
	<div class="wrap endpaper-settings">
		<h1><?php esc_html_e( 'Endpaper', 'endpaper' ); ?></h1>

		<p class="endpaper-settings__intro"><?php esc_html_e( 'Share what you are reading. Endpaper brings the books, shelves and ratings from your Hardcover library to your site, in blocks that take on the look of your theme.', 'endpaper' ); ?></p>

		<p class="endpaper-settings__disclosure"><?php esc_html_e( 'Endpaper is an unofficial plugin. It is not maintained or endorsed by Hardcover.', 'endpaper' ); ?></p>

		<?php if ( 'cleared' === $cache_result ) : ?>
			<div class="notice notice-success inline is-dismissible"><p><?php esc_html_e( 'The cache was cleared. Everything, book covers included, is fetched from Hardcover again the next time it is shown.', 'endpaper' ); ?></p></div>
		<?php elseif ( 'kept' === $cache_result ) : ?>
			<div class="notice notice-warning inline is-dismissible"><p><?php esc_html_e( 'The cache was not cleared, because there is no working API key.', 'endpaper' ); ?></p></div>
		<?php endif; ?>

		<div class="endpaper-settings__column">

			<section class="endpaper-card endpaper-card--connection" aria-labelledby="endpaper-connection-title">
				<h2 id="endpaper-connection-title"><?php esc_html_e( 'Connection', 'endpaper' ); ?></h2>

				<?php the_connection_notice(); ?>

				<?php if ( ! $has_token ) : ?>
					<?php the_key_steps(); ?>
				<?php endif; ?>

				<form method="post" action="options.php">
					<?php settings_fields( 'endpaper' ); ?>

					<div class="endpaper-field">
						<label class="endpaper-field__label" for="endpaper-token"><?php esc_html_e( 'Hardcover API key', 'endpaper' ); ?></label>

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
								<p class="endpaper-field__option">
									<label>
										<input type="checkbox" name="endpaper_remove_token" value="1" />
										<?php esc_html_e( 'Remove the saved key', 'endpaper' ); ?>
									</label>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</div>

					<?php
					if ( ! $in_config ) {
						submit_button( $has_token ? null : __( 'Save the key', 'endpaper' ) );
					}
					?>
				</form>

				<?php if ( $has_token ) : ?>
					<details class="endpaper-disclosure"<?php echo $key_rejected ? ' open' : ''; ?>>
						<summary><?php esc_html_e( 'Create a new key', 'endpaper' ); ?></summary>
						<?php the_key_steps(); ?>
					</details>
				<?php endif; ?>
			</section>

			<?php if ( ! $key_trouble ) : ?>
				<section class="endpaper-card" aria-labelledby="endpaper-blocks-title">
					<h2 id="endpaper-blocks-title"><?php esc_html_e( 'Blocks in Endpaper', 'endpaper' ); ?></h2>

					<p><?php esc_html_e( 'Add these blocks to any post, page or template. Type the shortcut on an empty line in the editor, search for the block by name, or copy it here and paste it in.', 'endpaper' ); ?></p>

					<ul class="endpaper-block-grid">
						<?php foreach ( get_settings_blocks() as $endpaper_block ) : ?>
							<li class="endpaper-block-box">
								<?php $endpaper_icon_path = get_icon_path( $endpaper_block['icon'] ); ?>
								<?php if ( '' !== $endpaper_icon_path ) : ?>
									<svg class="endpaper-block-box__icon" width="28" height="28" viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="<?php echo esc_attr( $endpaper_icon_path ); ?>" /></svg>
								<?php else : ?>
									<span class="dashicons dashicons-<?php echo esc_attr( $endpaper_block['icon'] ); ?>" aria-hidden="true"></span>
								<?php endif; ?>
								<h3><?php echo esc_html( $endpaper_block['title'] ); ?></h3>
								<p><?php echo esc_html( $endpaper_block['description'] ); ?></p>
								<div class="endpaper-block-box__footer">
									<code><?php echo esc_html( $endpaper_block['shortcut'] ); ?></code>
									<button type="button" class="button endpaper-copy" data-copy="<?php echo esc_attr( '<!-- wp:' . $endpaper_block['name'] . ' /-->' ); ?>" data-copied="<?php esc_attr_e( 'Copied', 'endpaper' ); ?>" hidden>
										<span class="endpaper-copy__label"><?php esc_html_e( 'Copy block', 'endpaper' ); ?></span>
										<span class="screen-reader-text">
											<?php
											/* translators: %s: The name of a block. */
											echo esc_html( sprintf( __( '(%s)', 'endpaper' ), $endpaper_block['title'] ) );
											?>
										</span>
									</button>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>

					<details class="endpaper-disclosure">
						<summary><?php esc_html_e( 'Ready-made patterns', 'endpaper' ); ?></summary>

						<p><?php esc_html_e( 'Find them in the block inserter, under Patterns, in the Endpaper category.', 'endpaper' ); ?></p>

						<ul class="endpaper-patterns">
							<?php foreach ( get_settings_patterns() as $endpaper_pattern ) : ?>
								<li>
									<strong><?php echo esc_html( $endpaper_pattern['title'] ); ?></strong>
									<span><?php echo esc_html( $endpaper_pattern['description'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</details>
				</section>

				<section class="endpaper-card" aria-labelledby="endpaper-appearance-title">
					<h2 id="endpaper-appearance-title"><?php esc_html_e( 'Appearance', 'endpaper' ); ?></h2>

					<form method="post" action="options.php">
						<?php settings_fields( 'endpaper_appearance' ); ?>

						<?php
						// Plain and Card have a drawing. A style from a theme or another plugin has its name only.
						$endpaper_drawn_styles = array(
							'default' => array(
								'label'       => __( 'Plain', 'endpaper' ),
								'description' => __( 'The book sits directly on the page, with no background or frame.', 'endpaper' ),
							),
							'card'    => array(
								'label'       => __( 'Card', 'endpaper' ),
								'description' => __( 'The book sits on a light background with rounded corners.', 'endpaper' ),
							),
						);
						$endpaper_styles       = array();

						// Our own styles come first, then the ones added by themes and plugins.
						$endpaper_all_styles = get_book_styles();
						$endpaper_all_styles = array_intersect_key( array_replace( array_flip( array_keys( $endpaper_drawn_styles ) ), $endpaper_all_styles ), $endpaper_all_styles );

						foreach ( $endpaper_all_styles as $endpaper_name => $endpaper_label ) {
							$endpaper_styles[ $endpaper_name ] = isset( $endpaper_drawn_styles[ $endpaper_name ] )
								? $endpaper_drawn_styles[ $endpaper_name ]
								: array(
									'label'       => $endpaper_label,
									'description' => __( 'A style added by your theme or a plugin.', 'endpaper' ),
									'drawn'       => false,
								);
						}
						?>

						<fieldset class="endpaper-style-choice">
							<legend class="endpaper-field__label"><?php esc_html_e( 'Default Book style', 'endpaper' ); ?></legend>

							<div class="endpaper-style-choice__options">
								<?php foreach ( $endpaper_styles as $endpaper_style => $endpaper_style_data ) : ?>
									<label class="endpaper-style-choice__option">
										<span class="endpaper-style-choice__name">
											<input type="radio" name="<?php echo esc_attr( ENDPAPER_DEFAULT_STYLE_OPTION ); ?>" value="<?php echo esc_attr( $endpaper_style ); ?>"<?php checked( get_default_book_style(), $endpaper_style ); ?> />
											<?php echo esc_html( $endpaper_style_data['label'] ); ?>
										</span>
										<?php if ( ! isset( $endpaper_style_data['drawn'] ) ) : ?>
											<span class="endpaper-style-choice__preview" aria-hidden="true">
												<span class="endpaper-style-choice__drawing endpaper-style-choice__drawing--<?php echo esc_attr( $endpaper_style ); ?>">
													<span class="endpaper-style-choice__cover"></span>
													<span class="endpaper-style-choice__lines"><span></span><span></span><span></span><span></span></span>
												</span>
											</span>
										<?php endif; ?>
										<span class="endpaper-style-choice__text"><?php echo esc_html( $endpaper_style_data['description'] ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>

							<p class="description"><?php esc_html_e( 'The style new Book blocks start with. Blocks that already have a style keep it, and you can change the style of any block in the editor.', 'endpaper' ); ?></p>
						</fieldset>

						<?php submit_button(); ?>
					</form>
				</section>

				<section class="endpaper-card" aria-labelledby="endpaper-cache-title">
					<h2 id="endpaper-cache-title"><?php esc_html_e( 'Cache', 'endpaper' ); ?></h2>

					<p><?php esc_html_e( 'Endpaper saves what it fetches from Hardcover, book covers included, so your pages stay fast. Reading lists, shelves and progress are fetched again every hour, and the details of a book every week. Clear the cache to fetch everything again now, for example after changing something on Hardcover.', 'endpaper' ); ?></p>

					<p class="endpaper-cache-status">
						<?php
						if ( ! is_cover_import_enabled() && ! are_remote_covers_allowed() ) {
							esc_html_e( 'Book covers are switched off on this site.', 'endpaper' );
						} elseif ( ! is_cover_import_enabled() ) {
							esc_html_e( 'Book covers are not saved on this site. They are loaded from Hardcover.', 'endpaper' );
						} elseif ( ! can_store_covers() && ! are_remote_covers_allowed() ) {
							esc_html_e( 'Book covers cannot be saved, because their folder cannot be written to. Until that is fixed, books are shown without covers. Site Health has the details.', 'endpaper' );
						} elseif ( ! can_store_covers() ) {
							esc_html_e( 'Book covers cannot be saved, because their folder cannot be written to. Until that is fixed, they are loaded from Hardcover. Site Health has the details.', 'endpaper' );
						} elseif ( $cover_stats['covers'] > 0 ) {
							printf(
								/* translators: 1: A number of book covers, 2: A file size, such as 3 MB. */
								esc_html( _n( '%1$s book cover is saved on this site, taking up %2$s.', '%1$s book covers are saved on this site, taking up %2$s.', $cover_stats['covers'], 'endpaper' ) ),
								esc_html( number_format_i18n( $cover_stats['covers'] ) ),
								esc_html( size_format( $cover_stats['bytes'], $cover_stats['bytes'] >= MB_IN_BYTES ? 1 : 0 ) )
							);
						} else {
							esc_html_e( 'No book covers are saved on this site yet.', 'endpaper' );
						}
						?>
					</p>

					<?php if ( $cover_queue['stuck'] ) : ?>
						<div class="notice notice-warning inline">
							<p><?php echo esc_html( get_cover_wait_text( $cover_queue['waiting'] ) ); ?></p>
						</div>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="endpaper_clear_cache" />
						<?php wp_nonce_field( 'endpaper_clear_cache' ); ?>
						<?php submit_button( __( 'Clear the cache', 'endpaper' ), 'secondary', 'endpaper-clear-cache' ); ?>
					</form>
				</section>
			<?php endif; ?>

			<section class="endpaper-card" aria-labelledby="endpaper-help-title">
				<h2 id="endpaper-help-title"><?php esc_html_e( 'Help', 'endpaper' ); ?></h2>

				<h3><?php esc_html_e( 'About your key', 'endpaper' ); ?></h3>

				<details class="endpaper-disclosure">
					<summary><?php esc_html_e( 'What does the key allow?', 'endpaper' ); ?></summary>

					<p><?php esc_html_e( 'The button for creating a key asks for these permissions and nothing else:', 'endpaper' ); ?></p>

					<table class="widefat striped endpaper-scopes">
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
				</details>

				<details class="endpaper-disclosure">
					<summary><?php esc_html_e( 'How is my key kept safe?', 'endpaper' ); ?></summary>

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
				</details>

				<?php
				the_faq_item(
					__( 'What happens when my key expires?', 'endpaper' ),
					array(
						__( 'Hardcover keys can have an end date. When Hardcover stops accepting the key, the blocks keep showing what they last fetched, for as long as the cached version lasts. You will see a notice here in the admin, and Site Health reports it too.', 'endpaper' ),
						__( 'Save a new key and everything picks up again.', 'endpaper' ),
					)
				);
				?>

				<h3><?php esc_html_e( 'Showing books', 'endpaper' ); ?></h3>

				<?php
				the_faq_item(
					__( 'Why is a book, my shelf or my rating missing?', 'endpaper' ),
					array(
						__( 'Endpaper only shows what is public. Your shelf, rating and progress for a book appear when your activity on that book is public on Hardcover, and the Reading List leaves out books where it is not.', 'endpaper' ),
						__( 'After changing it on Hardcover, clear the cache to see the change right away.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'I changed something on Hardcover. Why has my site not updated?', 'endpaper' ),
					array(
						__( 'Endpaper saves what it fetches for a while, so a change can take some time to appear. To fetch everything again now, use the “Clear the cache” button on this screen.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'Can I choose which cover a book has?', 'endpaper' ),
					array(
						__( 'Yes. A book shows the cover of the edition in your library, if it is there, and otherwise Hardcover’s default cover for the book. To pick another edition’s cover, select the Book block and open “Cover” in the block settings.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'Can I change how the blocks look?', 'endpaper' ),
					array(
						__( 'Yes. The blocks take their fonts and colors from your theme, and they support the color, typography, spacing, border and shadow settings in the editor. Theme authors can set defaults in theme.json.', 'endpaper' ),
					)
				);
				?>

				<h3><?php esc_html_e( 'Shortcodes', 'endpaper' ); ?></h3>

				<?php
				the_faq_item(
					__( 'Can I use Endpaper without the block editor?', 'endpaper' ),
					array(
						__( 'Yes. Each block has a shortcode that shows the same thing, for classic themes, widgets and templates. Put it in a Shortcode block, a classic editor or a text widget. A shortcode only chooses what to show: how it looks is up to your theme.', 'endpaper' ),
						__( 'Options that show or hide a part take yes or no, for example `cover="no"`.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'How do I show a book?', 'endpaper' ),
					array(
						__( '`[endpaper_book url="https://hardcover.app/books/the-stand"]` shows the book at that Hardcover address. The first time, the book is looked up in the background, so it shows from the next page view.', 'endpaper' ),
						__( 'Options: `cover`, `authors`, `meta`, `rating`, `my_rating`, `shelf`, `progress`, `description`, `review`, `credit`, `link`.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'How do I show what I am reading?', 'endpaper' ),
					array(
						__( '`[endpaper_reading_list status="currently-reading" count="5"]` shows books from your Hardcover account. The status is `currently-reading`, `read` or `want-to-read`.', 'endpaper' ),
						__( 'Options: `count`, `cover`, `authors`, `rating`, `date`, `progress`, `credit`, `link`.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'How do I show my reading goal?', 'endpaper' ),
					array(
						__( '`[endpaper_reading_goal]` shows the goal that is running now. To show another one, add its number: `[endpaper_reading_goal goal="123"]`. Only public goals can be shown.', 'endpaper' ),
						__( 'Options: `goal`, `title`, `days`, `percent`, `credit`.', 'endpaper' ),
					)
				);
				?>

				<h3><?php esc_html_e( 'Book covers', 'endpaper' ); ?></h3>

				<details class="endpaper-disclosure">
					<summary><?php esc_html_e( 'Where are book covers stored?', 'endpaper' ); ?></summary>
					<p>
						<?php
						printf(
							/* translators: %s: The path of a folder. */
							esc_html__( 'In a folder of Endpaper’s own, %s. Each cover is downloaded from Hardcover once and shown from your site after that. Covers are not added to your media library.', 'endpaper' ),
							'<code>' . esc_html( $cover_path ) . '</code>'
						);
						?>
					</p>
					<p><?php esc_html_e( 'A cover is removed when no block has shown it for a month. All covers are removed when you clear the cache, and when you delete the plugin.', 'endpaper' ); ?></p>
				</details>

				<?php
				the_faq_item(
					__( 'Which sizes and format are covers saved in?', 'endpaper' ),
					array(
						__( 'Three sizes: 160, 320 and 640 pixels wide, and never larger than the cover Hardcover has. Each visitor’s browser picks the smallest one that looks sharp. The file from Hardcover is not kept.', 'endpaper' ),
						__( 'Covers are saved as WebP where your server can write it, and as JPEG where it cannot. If your site is set up to save its images in another format, such as AVIF, covers follow that.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'Why is a cover a gray box?', 'endpaper' ),
					array(
						__( 'The cover is being saved. That happens in the background, usually within a minute, so that your visitors do not wait for it. The box is also shown for a book that has no cover on Hardcover, and when Hardcover could not be reached. In that case Endpaper tries again after an hour.', 'endpaper' ),
					)
				);
				?>

				<details class="endpaper-disclosure">
					<summary><?php esc_html_e( 'Can developers change how covers are saved?', 'endpaper' ); ?></summary>
					<p><?php esc_html_e( 'Yes, with these filters:', 'endpaper' ); ?></p>
					<ul class="ul-disc">
						<li><code>endpaper_cover_widths</code> <?php esc_html_e( 'The widths covers are saved in, in pixels.', 'endpaper' ); ?></li>
						<li><code>endpaper_cover_quality</code> <?php esc_html_e( 'The quality they are saved with, from 1 to 100. It is 80 to begin with.', 'endpaper' ); ?></li>
						<li><code>endpaper_import_covers</code> <?php esc_html_e( 'Return false to load covers from Hardcover and save nothing.', 'endpaper' ); ?></li>
						<li><code>endpaper_allow_remote_covers</code> <?php esc_html_e( 'Return false to never load a cover from Hardcover. A cover that cannot be saved is then a gray box.', 'endpaper' ); ?></li>
					</ul>
					<p>
						<?php
						printf(
							/* translators: %s: The name of a WordPress filter. */
							esc_html__( 'The format follows WordPress’s own %s filter.', 'endpaper' ),
							'<code>image_editor_output_format</code>'
						);
						?>
					</p>
				</details>

				<h3><?php esc_html_e( 'Privacy', 'endpaper' ); ?></h3>

				<?php
				the_faq_item(
					__( 'What does Endpaper send to Hardcover?', 'endpaper' ),
					array(
						__( 'Your API key, with each request. What you type when you search for a book in the editor. And requests for the books, covers, reading lists, shelves and progress that your blocks show.', 'endpaper' ),
						__( 'Your site’s server makes these requests. Nothing about your visitors is sent in them.', 'endpaper' ),
					)
				);

				?>
				<details class="endpaper-disclosure">
					<summary><?php esc_html_e( 'Do my visitors’ browsers contact Hardcover?', 'endpaper' ); ?></summary>
					<p><?php esc_html_e( 'No. Covers are shown from your own site, and nothing else is loaded from Hardcover. A visitor only reaches Hardcover by following a link to it.', 'endpaper' ); ?></p>
					<p><?php esc_html_e( 'There is one exception. If your site cannot save covers, they are loaded from Hardcover instead, and Hardcover then sees each visitor’s IP address and browser details, though not which page they are reading. Site Health tells you if that is the case.', 'endpaper' ); ?></p>
					<p>
						<?php
						printf(
							/* translators: %s: The name of a filter. */
							esc_html__( 'To rule the exception out, a developer can return false from the %s filter. Covers are then never loaded from Hardcover, and one that cannot be saved is shown as a gray box.', 'endpaper' ),
							'<code>endpaper_allow_remote_covers</code>'
						);
						?>
					</p>
				</details>
				<?php

				the_faq_item(
					__( 'Does anything load from Hardcover in the editor?', 'endpaper' ),
					array(
						__( 'Yes. When you choose a cover for a Book block, the covers to choose from are loaded from Hardcover by your own browser. That only happens in the editor, for the person editing.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'Does Endpaper use cookies or track visitors?', 'endpaper' ),
					array(
						__( 'No. It sets no cookies, and stores nothing about your visitors.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'What does Endpaper store on my site?', 'endpaper' ),
					array(
						__( 'Your API key, a copy of what it has fetched from Hardcover, and the book covers. All of it is removed when you delete the plugin.', 'endpaper' ),
					)
				);
				?>

				<h3><?php esc_html_e( 'About the plugin', 'endpaper' ); ?></h3>

				<details class="endpaper-disclosure">
					<summary><?php esc_html_e( 'Is this an official Hardcover plugin?', 'endpaper' ); ?></summary>
					<p>
						<?php
						printf(
							/* translators: %s: A link to the plugin's support forum. */
							esc_html__( 'No. Endpaper is created independently, using Hardcover’s public API. Hardcover does not maintain or endorse it. For questions about the plugin, use the %s.', 'endpaper' ),
							'<a href="' . esc_url( ENDPAPER_SUPPORT_URL ) . '">' . esc_html__( 'Endpaper support forum on WordPress.org', 'endpaper' ) . '</a>'
						);
						?>
					</p>
				</details>
				<?php
				the_faq_item(
					__( 'Will the blocks slow my site down?', 'endpaper' ),
					array(
						__( 'No. Endpaper saves what it fetches on your site, so your pages do not wait for Hardcover each time they are viewed. If Hardcover cannot be reached, the blocks show the last copy they have.', 'endpaper' ),
					)
				);

				the_faq_item(
					__( 'What happens when I delete the plugin?', 'endpaper' ),
					array(
						__( 'The API key and everything the plugin has stored, the book covers included, are removed. Book, Reading List and Reading Goal blocks left in your posts stop showing.', 'endpaper' ),
					)
				);
				?>

				<h3><?php esc_html_e( 'Other questions', 'endpaper' ); ?></h3>

				<p>
					<?php
					printf(
						/* translators: %s: A link to the plugin's support forum. */
						esc_html__( 'Did not find your answer? Ask in the %s.', 'endpaper' ),
						'<a href="' . esc_url( ENDPAPER_SUPPORT_URL ) . '">' . esc_html__( 'Endpaper support forum on WordPress.org', 'endpaper' ) . '</a>'
					);
					?>
				</p>
			</section>

			<section class="endpaper-card" aria-labelledby="endpaper-about-title">
				<h2 id="endpaper-about-title"><?php esc_html_e( 'About Endpaper', 'endpaper' ); ?></h2>

				<p>
					<?php
					printf(
						/* translators: %s: The name of the plugin's author, linked to his website. */
						esc_html__( 'Endpaper is a free, open source plugin created by %s.', 'endpaper' ),
						'<a href="https://andersnoren.se">Anders Norén</a>'
					);
					?>
					<?php
					printf(
						/* translators: %s: Hardcover, linked to their website. */
						esc_html__( 'It is made possible by %s and their wonderful API.', 'endpaper' ),
						'<a href="https://hardcover.app">Hardcover</a>'
					);
					?>
				</p>

				<p>
					<?php
					printf(
						/* translators: %s: A link to the plugin's issues on GitHub. */
						esc_html__( 'Have an idea for a feature? %s.', 'endpaper' ),
						'<a href="' . esc_url( ENDPAPER_ISSUES_URL ) . '">' . esc_html__( 'Suggest it on GitHub', 'endpaper' ) . '</a>'
					);
					?>
				</p>

				<div class="endpaper-review">
					<p><strong><?php esc_html_e( 'Enjoying Endpaper?', 'endpaper' ); ?></strong> <?php esc_html_e( 'A review on WordPress.org helps other readers find it.', 'endpaper' ); ?></p>
					<a class="button button-secondary" href="<?php echo esc_url( ENDPAPER_REVIEW_URL ); ?>"><?php esc_html_e( 'Leave a review', 'endpaper' ); ?></a>
				</div>
			</section>

		</div>
	</div>
	<?php
}

/**
 * Outputs a question on the settings screen, with an answer that opens when it is clicked.
 *
 * @param string   $question   The question.
 * @param string[] $paragraphs The answer, one paragraph at a time. Text between backticks is shown as code.
 * @return void
 */
function the_faq_item( $question, $paragraphs ) {
	echo '<details class="endpaper-disclosure"><summary>' . esc_html( $question ) . '</summary>';

	foreach ( $paragraphs as $paragraph ) {
		// Text between backticks is code.
		echo '<p>' . preg_replace( '/`([^`]+)`/', '<code>$1</code>', esc_html( $paragraph ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above, and only code tags are added.
	}

	echo '</details>';
}

/**
 * Outputs how to create an API key, with the button that opens Hardcover's form.
 *
 * @return void
 */
function the_key_steps() {
	?>
	<ol>
		<li><?php esc_html_e( 'Open the form for a new key on Hardcover with the button below. The permissions Endpaper needs are already ticked.', 'endpaper' ); ?></li>
		<li><?php esc_html_e( 'Give the key a name, and choose when it expires. With “Never” you will not have to replace it.', 'endpaper' ); ?></li>
		<li><?php esc_html_e( 'Create the key, copy it, and paste it into the field on this screen.', 'endpaper' ); ?></li>
	</ol>

	<p>
		<a class="button button-secondary" href="<?php echo esc_url( get_new_key_url() ); ?>" target="_blank" rel="noopener">
			<?php esc_html_e( 'Create a key on Hardcover', 'endpaper' ); ?>
			<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'endpaper' ); ?></span>
		</a>
	</p>
	<?php
}
