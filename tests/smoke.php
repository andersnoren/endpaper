<?php
/**
 * A smoke test: loads the plugin in a real WordPress and exercises its main paths.
 *
 * Run it with WP-CLI, with the plugin active:
 *
 *     wp eval-file wp-content/plugins/endpaper/tests/smoke.php
 *
 * Use a site made for testing: the test removes the plugin's API key and its
 * cache. Hardcover is never contacted. Its answers are faked below. Any PHP notice,
 * warning or deprecation counts as a failure, which is what makes this useful
 * for checking the oldest PHP version the plugin supports.
 *
 * @package Endpaper
 */

use function AndersNoren\Endpaper\format_date;
use function AndersNoren\Endpaper\get_connection_status;
use function AndersNoren\Endpaper\get_reading_list;
use function AndersNoren\Endpaper\get_token;
use function AndersNoren\Endpaper\render_settings_page;
use function AndersNoren\Endpaper\sanitize_token_setting;
use function AndersNoren\Endpaper\search_books;
use function AndersNoren\Endpaper\trim_to_sentence;

defined( 'ABSPATH' ) || exit;

// Every notice, warning and deprecation stops the test.
set_error_handler(
	static function ( $severity, $message, $file, $line ) {
		throw new ErrorException( $message, 0, $severity, $file, $line );
	}
);

// WP-CLI runs this file inside a function, so the list of failures is kept as a true global.
$GLOBALS['endpaper_failures'] = array();

/**
 * Records a check.
 *
 * @param string $label     What is being checked.
 * @param bool   $condition Whether it holds.
 * @return void
 */
function endpaper_check( $label, $condition ) {
	echo ( $condition ? '  ok    ' : '  FAIL  ' ) . $label . "\n";

	if ( ! $condition ) {
		$GLOBALS['endpaper_failures'][] = $label;
	}
}

// Fake answers from Hardcover, so nothing leaves this machine.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( $url, 'api.hardcover.app' ) ) {
			return $pre;
		}

		$respond = static function ( $code, $body ) {
			return array(
				'response' => array(
					'code'    => $code,
					'message' => '',
				),
				'body'     => wp_json_encode( $body ),
				'headers'  => array(),
				'cookies'  => array(),
			);
		};

		$auth    = isset( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '';
		$request = json_decode( $args['body'], true );
		$query   = isset( $request['query'] ) ? $request['query'] : '';

		if ( 'Bearer smoke-test-key' !== $auth ) {
			return $respond( 401, array( 'error' => 'Unauthorized' ) );
		}

		$book = array(
			'id'                  => 42,
			'title'               => "The Stand's \"Complete\" Edition",
			'slug'                => 'the-stand',
			'description'         => 'A patient escapes from a biological testing facility. He carries a mutated strain of flu. Within weeks, most of the world is dead.',
			'pages'               => 1344,
			'release_year'        => 1978,
			'rating'              => 4.24,
			'ratings_count'       => 1992,
			'image'               => array( 'url' => 'https://assets.hardcover.app/cover.jpg' ),
			'cached_contributors' => array(
				array(
					'author'       => array( 'name' => 'Stephen King' ),
					'contribution' => null,
				),
			),
		);

		if ( false !== strpos( $query, 'EndpaperConnection' ) ) {
			return $respond( 200, array( 'data' => array( 'me' => array( array( 'username' => 'smoke' ) ) ) ) );
		}

		if ( false !== strpos( $query, 'EndpaperSearch' ) ) {
			return $respond( 200, array( 'data' => array( 'search' => array( 'results' => array( 'hits' => array( array( 'document' => $book ) ) ) ) ) ) );
		}

		if ( false !== strpos( $query, 'EndpaperLibraryEntry' ) ) {
			return $respond(
				200,
				array(
					'data' => array(
						'me' => array(
							array(
								'user_books' => array(
									array(
										'status_id'       => 2,
										'rating'          => null,
										'edition'         => array( 'image' => array( 'url' => 'https://assets.hardcover.app/edition-entry.jpg' ) ),
										'user_book_reads' => array( array( 'progress' => 68.4 ) ),
									),
								),
							),
						),
					),
				)
			);
		}

		if ( false !== strpos( $query, 'EndpaperReadingList' ) ) {
			// The first book has an edition with its own cover, the second has no cover at all, the third has an edition without one.
			$entry = static function ( $rating, $cover, $edition_cover = '' ) use ( $book ) {
				$copy          = $book;
				$copy['image'] = $cover ? $book['image'] : null;

				return array(
					'rating'          => $rating,
					'last_read_date'  => '2015-11-30',
					'edition'         => array( 'image' => $edition_cover ? array( 'url' => $edition_cover ) : null ),
					'book'            => $copy,
					'user_book_reads' => array(
						array(
							'progress'    => 40,
							'finished_at' => null,
						),
					),
				);
			};

			return $respond( 200, array( 'data' => array( 'me' => array( array( 'user_books' => array( $entry( 4.5, true, 'https://assets.hardcover.app/edition-list.jpg' ), $entry( 3, false ), $entry( null, true ) ) ) ) ) ) );
		}

		return $respond( 200, array( 'data' => array( 'books' => array( $book ) ) ) );
	},
	10,
	3
);

try {
	global $wpdb;

	echo 'PHP ' . PHP_VERSION . ', WordPress ' . get_bloginfo( 'version' ) . "\n";

	// The settings screen's code is only loaded in the admin, along with the functions it uses.
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once WP_PLUGIN_DIR . '/endpaper/includes/settings.php';

	// Start clean: no key, and nothing cached from an earlier run.
	delete_option( 'endpaper_hardcover_token' );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_endpaper\_%' OR option_name LIKE '\_transient\_timeout\_endpaper\_%'" );
	wp_cache_flush();

	echo "Without a key\n";
	endpaper_check( 'no key is reported', '' === get_token() );
	endpaper_check( 'a list request fails cleanly', is_wp_error( get_reading_list( 3, 5 ) ) );
	endpaper_check( 'a block shows nothing to visitors', '' === trim( do_blocks( '<!-- wp:endpaper/reading-list {"status":3} /-->' ) ) );

	echo "Saving a key\n";
	endpaper_check( 'text that cannot be a key is not saved', '' === sanitize_token_setting( 'not a key' ) );
	endpaper_check( 'a key Hardcover rejects is not saved', '' === sanitize_token_setting( 'wrong-key' ) );
	endpaper_check( 'a valid key is saved, without "Bearer"', 'smoke-test-key' === sanitize_token_setting( ' Bearer smoke-test-key ' ) );

	update_option( 'endpaper_hardcover_token', 'smoke-test-key' );

	$endpaper_status = get_connection_status();
	endpaper_check( 'the connection check names the account', 'connected' === $endpaper_status['state'] && 'smoke' === $endpaper_status['username'] );

	echo "Helpers\n";
	endpaper_check( 'a date is formatted', '' !== format_date( '2015-11-30' ) && '' === format_date( '2015-02-30' ) );
	endpaper_check( 'a description ends on a whole sentence', 'First one. Second one.' === trim_to_sentence( 'First one. Second one. A third that is too long to fit.', 30 ) );

	$endpaper_results = search_books( 'the stand' );
	endpaper_check( 'the search returns a book', is_array( $endpaper_results ) && 1 === count( $endpaper_results ) );

	echo "The Book block\n";
	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showShelf":true,"showProgress":true} /-->' );
	endpaper_check( 'it renders a card', false !== strpos( $endpaper_html, 'endpaper-book__card' ) );
	endpaper_check( 'the title is escaped', false === strpos( $endpaper_html, '"Complete"' ) && false !== strpos( $endpaper_html, 'Complete' ) );
	endpaper_check( 'it shows the reading progress', false !== strpos( $endpaper_html, '<progress' ) );
	endpaper_check( 'it uses the cover of the edition in the library', false !== strpos( $endpaper_html, 'edition-entry.jpg' ) && false === strpos( $endpaper_html, 'assets.hardcover.app/cover.jpg' ) );
	endpaper_check( 'it credits Hardcover', false !== strpos( $endpaper_html, 'endpaper-logo' ) && false !== strpos( $endpaper_html, 'Powered by Hardcover' ) );
	endpaper_check( 'the key is not in the output', false === strpos( $endpaper_html, 'smoke-test-key' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showCover":false,"showMeta":false,"showRating":false,"showDescription":false,"linkToHardcover":false,"titleLevel":0} /-->' );
	endpaper_check( 'it renders with everything switched off', false !== strpos( $endpaper_html, 'endpaper-book__title' ) && false === strpos( $endpaper_html, 'endpaper-book__cover' ) );

	echo "The Reading List block\n";

	foreach ( array( 1, 2, 3 ) as $endpaper_list_status ) {
		$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-list {"status":' . $endpaper_list_status . '} /-->' );
		endpaper_check( 'status ' . $endpaper_list_status . ' renders three books', 3 === substr_count( $endpaper_html, 'endpaper-reading-list__item"' ) );
	}

	$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-list {"status":3,"layout":"grid","titleLevel":3,"style":{"border":{"width":"1px"},"spacing":{"blockGap":"0"}}} /-->' );
	endpaper_check( 'the grid renders, framed', false !== strpos( $endpaper_html, 'endpaper-reading-list--grid' ) && false !== strpos( $endpaper_html, 'endpaper-reading-list__card--framed' ) );
	endpaper_check( 'a rating is shown as stars', false !== strpos( $endpaper_html, 'endpaper-icon--star-half' ) && false !== strpos( $endpaper_html, 'Rated 4.5 out of 5' ) );
	endpaper_check( 'a book without a cover gets a placeholder', false !== strpos( $endpaper_html, 'endpaper-cover-placeholder' ) );
	endpaper_check( 'the cover of the edition in the library is used', 1 === substr_count( $endpaper_html, 'edition-list.jpg' ) );
	endpaper_check( 'an edition without a cover falls back to the cover of the book', 1 === substr_count( $endpaper_html, 'assets.hardcover.app/cover.jpg' ) );
	endpaper_check( 'a spacing of zero gets a unit', false !== strpos( $endpaper_html, '--endpaper-gap: 0px' ) );

	echo "The settings screen\n";
	wp_set_current_user( 1 );
	ob_start();
	render_settings_page();
	$endpaper_html = ob_get_clean();
	endpaper_check( 'it renders for an administrator', false !== strpos( $endpaper_html, 'endpaper-token' ) && false !== strpos( $endpaper_html, 'read:library:public' ) );
	endpaper_check( 'it never outputs the saved key', false === strpos( $endpaper_html, 'smoke-test-key' ) );

	delete_option( 'endpaper_hardcover_token' );

	echo "Translations\n";
	foreach ( array( 'endpaper-book-editor-script', 'endpaper-reading-list-editor-script' ) as $endpaper_handle ) {
		$endpaper_script = wp_scripts()->registered[ $endpaper_handle ] ?? null;
		endpaper_check( $endpaper_handle . ' loads translations for the editor', $endpaper_script && 'endpaper' === $endpaper_script->textdomain );
	}
} catch ( Throwable $endpaper_error ) {
	$endpaper_message                = get_class( $endpaper_error ) . ': ' . $endpaper_error->getMessage() . ' in ' . $endpaper_error->getFile() . ':' . $endpaper_error->getLine();
	$GLOBALS['endpaper_failures'][] = $endpaper_message;
	echo '  FAIL  ' . $endpaper_message . "\n";
}

restore_error_handler();

if ( $GLOBALS['endpaper_failures'] ) {
	echo "\n" . count( $GLOBALS['endpaper_failures'] ) . " check(s) failed.\n";
	exit( 1 );
}

echo "\nAll checks passed.\n";
