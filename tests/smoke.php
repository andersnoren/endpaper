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
use function AndersNoren\Endpaper\api_request;
use function AndersNoren\Endpaper\cache_key;
use function AndersNoren\Endpaper\clear_cache;
use function AndersNoren\Endpaper\can_store_covers;
use function AndersNoren\Endpaper\cover_records;
use function AndersNoren\Endpaper\delete_all_covers;
use function AndersNoren\Endpaper\get_cover;
use function AndersNoren\Endpaper\get_cover_dir;
use function AndersNoren\Endpaper\get_cover_key;
use function AndersNoren\Endpaper\get_cover_stats;
use function AndersNoren\Endpaper\get_covers_site_health_result;
use function AndersNoren\Endpaper\import_cover;
use function AndersNoren\Endpaper\prune_covers;
use function AndersNoren\Endpaper\queue_cover;
use function AndersNoren\Endpaper\save_queued_covers;
use function AndersNoren\Endpaper\get_book_end_time;
use function AndersNoren\Endpaper\get_connection_status;
use function AndersNoren\Endpaper\get_editions;
use function AndersNoren\Endpaper\get_key_problem_message;
use function AndersNoren\Endpaper\get_site_health_result;
use function AndersNoren\Endpaper\get_reading_list;
use function AndersNoren\Endpaper\get_token;
use function AndersNoren\Endpaper\is_token_rejected;
use function AndersNoren\Endpaper\render_settings_page;
use function AndersNoren\Endpaper\sanitize_token_setting;
use function AndersNoren\Endpaper\search_books;
use function AndersNoren\Endpaper\the_key_notice;
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
		// A cover: a small image made here, written to where the download was to go.
		if ( false !== strpos( $url, 'assets.hardcover.app' ) ) {
			if ( false !== strpos( $url, 'missing' ) || empty( $args['filename'] ) || ! function_exists( 'imagejpeg' ) ) {
				return new WP_Error( 'endpaper_smoke', 'No such cover.' );
			}

			$image = imagecreatetruecolor( 400, 600 );
			imagefill( $image, 0, 0, imagecolorallocate( $image, 178, 58, 48 ) );
			imagejpeg( $image, $args['filename'], 90 );

			$GLOBALS['endpaper_cover_downloads'] = isset( $GLOBALS['endpaper_cover_downloads'] ) ? $GLOBALS['endpaper_cover_downloads'] + 1 : 1;

			return array(
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'body'     => '',
				'headers'  => array(),
				'cookies'  => array(),
			);
		}

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

		if ( false !== strpos( $query, 'EndpaperEditions' ) ) {
			$edition = static function ( $id, $cover ) {
				return array(
					'id'             => $id,
					'book_id'        => 42,
					'title'          => 'The Stand',
					'edition_format' => 'Paperback',
					'release_date'   => '1990-05-01',
					'image'          => $cover ? array( 'url' => 'https://assets.hardcover.app/' . $cover ) : null,
					'language'       => array( 'language' => 'English' ),
				);
			};

			// Four editions: two with covers of their own, one without a cover, and one that shares a cover.
			return $respond( 200, array( 'data' => array( 'editions' => array( $edition( 101, 'edition-101.jpg' ), $edition( 102, null ), $edition( 103, 'edition-103.jpg' ), $edition( 104, 'edition-101.jpg' ) ) ) ) );
		}

		if ( false !== strpos( $query, 'EndpaperEdition(' ) ) {
			$variables = json_decode( $args['body'], true )['variables'];

			// Edition 103 belongs to book 42. Edition 900 belongs to another book. Anything else does not exist.
			$editions = array(
				103 => 42,
				900 => 7,
			);

			return $respond(
				200,
				array(
					'data' => array(
						'editions' => isset( $editions[ $variables['id'] ] ) ? array(
							array(
								'id'      => $variables['id'],
								'book_id' => $editions[ $variables['id'] ],
								'image'   => array( 'url' => 'https://assets.hardcover.app/edition-' . $variables['id'] . '.jpg' ),
							),
						) : array(),
					),
				)
			);
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
										'rating'          => 3.5,
										'edition'         => array(
											'image'    => array( 'url' => 'https://assets.hardcover.app/edition-entry.jpg' ),
											'language' => array( 'language' => 'Swedish' ),
										),
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

	// Covers are loaded from Hardcover until the part about saving them, so the checks before it can tell them apart by their addresses.
	delete_all_covers();
	add_filter( 'endpaper_import_covers', '__return_false' );

	echo "Without a key\n";
	endpaper_check( 'no key is reported', '' === get_token() );
	endpaper_check( 'a list request fails cleanly', is_wp_error( get_reading_list( 3, 5 ) ) );
	endpaper_check( 'a block shows nothing to visitors', '' === trim( do_blocks( '<!-- wp:endpaper/reading-list {"status":3} /-->' ) ) );

	echo "Saving a key\n";
	endpaper_check( 'text that cannot be a key is not saved', '' === sanitize_token_setting( 'not a key' ) );
	endpaper_check( 'a key Hardcover rejects is not saved', '' === sanitize_token_setting( 'wrong-key' ) );
	endpaper_check( 'a valid key is saved, without "Bearer"', 'smoke-test-key' === sanitize_token_setting( ' Bearer smoke-test-key ' ) );

	echo "A key that stops working\n";
	update_option( 'endpaper_hardcover_token', 'key-that-expired' );
	api_request( 'query EndpaperConnection { me { username } }' );
	endpaper_check( 'a rejected key is noted', is_token_rejected() && '' !== get_key_problem_message() );
	endpaper_check( 'Site Health reports it as critical', 'critical' === get_site_health_result()['status'] );

	ob_start();
	wp_set_current_user( 1 );
	the_key_notice();
	$endpaper_html = ob_get_clean();
	wp_set_current_user( 0 );
	endpaper_check( 'administrators get a notice, without the key in it', false !== strpos( $endpaper_html, 'notice-error' ) && false === strpos( $endpaper_html, 'key-that-expired' ) );

	delete_transient( 'endpaper_api_backoff' );
	update_option( 'endpaper_hardcover_token', 'smoke-test-key' );
	api_request( 'query EndpaperConnection { me { username } }' );
	endpaper_check( 'a key that works clears the note', ! is_token_rejected() && '' === get_key_problem_message() && 'good' === get_site_health_result()['status'] );


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
	endpaper_check( 'it shows the owner’s rating as stars, next to the progress', false !== strpos( $endpaper_html, 'endpaper-book__my-rating' ) && false !== strpos( $endpaper_html, 'endpaper-icon--star-half' ) && false !== strpos( $endpaper_html, 'Rated 3.5 out of 5' ) );

	$endpaper_html_plain = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showUserRating":false} /-->' );
	endpaper_check( 'the owner’s rating can be switched off', false === strpos( $endpaper_html_plain, 'endpaper-book__my-rating' ) );
	endpaper_check( 'it uses the cover of the edition in the library', false !== strpos( $endpaper_html, 'edition-entry.jpg' ) && false === strpos( $endpaper_html, 'assets.hardcover.app/cover.jpg' ) );
	endpaper_check( 'it leaves out the Hardcover logo unless that is switched on', false === strpos( $endpaper_html, 'endpaper-logo' ) && false === strpos( $endpaper_html, 'Powered by Hardcover' ) );

	$endpaper_html_credit = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showCredit":true} /-->' );
	endpaper_check( 'it credits Hardcover when that is switched on', false !== strpos( $endpaper_html_credit, 'endpaper-logo' ) && false !== strpos( $endpaper_html_credit, 'Powered by Hardcover' ) );

	$endpaper_html_bare = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showMeta":false,"showRating":false} /-->' );
	endpaper_check( 'the footer is left out when it would be empty', false === strpos( $endpaper_html_bare, 'endpaper-book__footer' ) );
	endpaper_check( 'the key is not in the output', false === strpos( $endpaper_html, 'smoke-test-key' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showCover":false,"showMeta":false,"showRating":false,"showDescription":false,"linkToHardcover":false,"titleLevel":0} /-->' );
	endpaper_check( 'it renders with everything switched off', false !== strpos( $endpaper_html, 'endpaper-book__title' ) && false === strpos( $endpaper_html, 'endpaper-book__cover' ) );

	echo "Edition covers\n";
	$endpaper_editions = get_editions( 42 );
	endpaper_check( 'editions without a cover, or with one already listed, are left out', is_array( $endpaper_editions ) && array( 101, 103 ) === wp_list_pluck( $endpaper_editions, 'id' ) );
	endpaper_check( 'an edition has a format, year and language', 'Paperback' === $endpaper_editions[0]['format'] && 1990 === $endpaper_editions[0]['year'] && 'English' === $endpaper_editions[0]['language'] );

	// The route is for people who can edit, so ask as the administrator.
	wp_set_current_user( 1 );
	$endpaper_response = rest_do_request( new WP_REST_Request( 'GET', '/endpaper/v1/editions/42' ) )->get_data();
	endpaper_check( 'the editor gets the editions and the languages to list first', 2 === count( $endpaper_response['editions'] ) && 'Swedish' === $endpaper_response['languages']['edition'] && get_locale() === $endpaper_response['languages']['site'] );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"editionId":103} /-->' );
	endpaper_check( 'a picked edition decides the cover', false !== strpos( $endpaper_html, 'edition-103.jpg' ) && false === strpos( $endpaper_html, 'edition-entry.jpg' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"editionId":900} /-->' );
	endpaper_check( 'an edition of another book is ignored', false === strpos( $endpaper_html, 'edition-900.jpg' ) && false !== strpos( $endpaper_html, 'edition-entry.jpg' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"editionId":555} /-->' );
	endpaper_check( 'an edition that no longer exists is ignored', false !== strpos( $endpaper_html, 'edition-entry.jpg' ) );

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
	endpaper_check( 'the Hardcover logo is left out unless that is switched on', false === strpos( $endpaper_html, 'endpaper-logo' ) && false === strpos( $endpaper_html, 'endpaper-reading-list__attribution' ) );

	$endpaper_html_credit = do_blocks( '<!-- wp:endpaper/reading-list {"status":3,"showCredit":true} /-->' );
	endpaper_check( 'the list credits Hardcover when that is switched on', false !== strpos( $endpaper_html_credit, 'endpaper-logo' ) && false !== strpos( $endpaper_html_credit, 'Powered by Hardcover' ) );

	echo "Saving covers\n";
	wp_set_current_user( 0 );
	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' );
	endpaper_check( 'a cover that is not saved is loaded from Hardcover, without a referrer', false !== strpos( $endpaper_html, 'src="https://assets.hardcover.app/edition-entry.jpg"' ) && false !== strpos( $endpaper_html, 'referrerpolicy="no-referrer"' ) );

	add_filter( 'endpaper_allow_remote_covers', '__return_false' );
	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' );
	endpaper_check( 'a site can rule out loading covers from Hardcover, and gets a placeholder', false === strpos( $endpaper_html, 'assets.hardcover.app' ) && false !== strpos( $endpaper_html, 'endpaper-cover-placeholder' ) && 'Endpaper is set not to show book covers' === get_covers_site_health_result()['label'] );
	remove_filter( 'endpaper_allow_remote_covers', '__return_false' );

	remove_filter( 'endpaper_import_covers', '__return_false' );

	if ( ! can_store_covers() || ! wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ) ) {
		echo "  skip  this server cannot save images, so the rest of this part is left out\n";
	} else {
		$endpaper_cover_dir = get_cover_dir();
		$endpaper_cover_url = 'https://assets.hardcover.app/edition-entry.jpg';
		$endpaper_cover_key = get_cover_key( $endpaper_cover_url );

		$GLOBALS['endpaper_cover_downloads'] = 0;

		$endpaper_html    = do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' );
		$endpaper_records = cover_records();
		$endpaper_files   = isset( $endpaper_records[ $endpaper_cover_key ]['files'] ) ? $endpaper_records[ $endpaper_cover_key ]['files'] : array();

		endpaper_check( 'the cover is saved on the site, in each size it is large enough for', array( 160, 320, 400 ) === array_keys( $endpaper_files ) && 3 === count( glob( $endpaper_cover_dir['path'] . '/' . $endpaper_cover_key . '-*' ) ) );
		endpaper_check( 'the block shows the saved cover, with its sizes', false !== strpos( $endpaper_html, $endpaper_cover_dir['url'] . '/' . $endpaper_cover_key . '-320.' ) && false !== strpos( $endpaper_html, ' 160w, ' ) && false !== strpos( $endpaper_html, 'width="320" height="480"' ) && false === strpos( $endpaper_html, 'assets.hardcover.app' ) );
		endpaper_check( 'the folder cannot be listed', file_exists( $endpaper_cover_dir['path'] . '/index.html' ) );

		do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' );
		endpaper_check( 'a saved cover is not downloaded again', 1 === $GLOBALS['endpaper_cover_downloads'] );

		endpaper_check( 'a cover that cannot be downloaded gets a placeholder, and is not tried again at once', null === get_cover( 'https://assets.hardcover.app/missing.jpg' ) && null === get_cover( 'https://assets.hardcover.app/missing.jpg' ) && ! empty( cover_records()[ get_cover_key( 'https://assets.hardcover.app/missing.jpg' ) ]['failed'] ) );
		endpaper_check( 'only Hardcover’s image server is downloaded from', false === import_cover( 'https://example.com/cover.jpg' ) && false === import_cover( 'http://assets.hardcover.app/cover.jpg' ) );

		queue_cover( 'https://assets.hardcover.app/edition-queued.jpg' );
		queue_cover();
		endpaper_check( 'a cover can wait to be saved in the background', 1 === count( (array) get_option( 'endpaper_cover_queue' ) ) && false !== wp_next_scheduled( 'endpaper_save_queued_covers' ) );
		save_queued_covers();
		endpaper_check( 'the background job saves it', ! empty( cover_records()[ get_cover_key( 'https://assets.hardcover.app/edition-queued.jpg' ) ]['files'] ) && false === get_option( 'endpaper_cover_queue' ) );

		$endpaper_records                                = cover_records();
		$endpaper_records[ $endpaper_cover_key ]['used'] = time() - 2 * MONTH_IN_SECONDS;
		cover_records( $endpaper_records );
		prune_covers();
		endpaper_check( 'a cover no block has shown for a month is removed', ! isset( cover_records()[ $endpaper_cover_key ] ) && 0 === count( glob( $endpaper_cover_dir['path'] . '/' . $endpaper_cover_key . '-*' ) ) && 1 === get_cover_stats()['covers'] );

		clear_cache();
		endpaper_check( 'clearing the cache removes the saved covers', 0 === get_cover_stats()['covers'] && 0 === count( glob( $endpaper_cover_dir['path'] . '/*-*' ) ) );
	}

	wp_clear_scheduled_hook( 'endpaper_save_queued_covers' );
	add_filter( 'endpaper_import_covers', '__return_false' );

	echo "Clearing the cache\n";
	do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' );
	$endpaper_generation = (int) get_option( 'endpaper_cache_generation', 0 );
	endpaper_check( 'the book is in the cache', false !== get_transient( cache_key( 'endpaper_book_42' ) ) );
	clear_cache();
	endpaper_check( 'clearing it puts everything out of reach', (int) get_option( 'endpaper_cache_generation' ) === $endpaper_generation + 1 && false === get_transient( cache_key( 'endpaper_book_42' ) ) && 0 === get_book_end_time( 42 ) );
	endpaper_check( 'the key is kept', 'smoke-test-key' === get_token() );
	endpaper_check( 'the block fetches the book again', false !== strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' ), 'endpaper-book__card' ) && false !== get_transient( cache_key( 'endpaper_book_42' ) ) );

	echo "The settings screen\n";
	wp_set_current_user( 1 );
	ob_start();
	render_settings_page();
	$endpaper_html = ob_get_clean();
	endpaper_check( 'it renders for an administrator', false !== strpos( $endpaper_html, 'endpaper-token' ) && false !== strpos( $endpaper_html, 'read:library:public' ) );
	endpaper_check( 'it never outputs the saved key', false === strpos( $endpaper_html, 'smoke-test-key' ) );
	endpaper_check( 'Site Health says when covers are not saved', 'Endpaper is set not to save book covers' === get_covers_site_health_result()['label'] );
	endpaper_check( 'it explains where covers are kept, and what is sent to Hardcover', false !== strpos( $endpaper_html, 'uploads/endpaper/covers' ) && false !== strpos( $endpaper_html, 'endpaper_cover_widths' ) && false !== strpos( $endpaper_html, 'Do my visitors’ browsers contact Hardcover?' ) );
	endpaper_check( 'it has a button to clear the cache', false !== strpos( $endpaper_html, 'value="endpaper_clear_cache"' ) && false === strpos( $endpaper_html, 'disabled' ) );
	endpaper_check( 'it says the plugin is unofficial, and where to get help', false !== strpos( $endpaper_html, 'Endpaper is an unofficial plugin' ) && false !== strpos( $endpaper_html, 'not maintained or endorsed by Hardcover' ) && false !== strpos( $endpaper_html, 'wordpress.org/support/plugin/endpaper/' ) );
	endpaper_check( 'it credits the author and Hardcover, and links to GitHub and the reviews', false !== strpos( $endpaper_html, 'https://andersnoren.se' ) && false !== strpos( $endpaper_html, 'made possible by <a href="https://hardcover.app">Hardcover</a>' ) && false !== strpos( $endpaper_html, 'github.com/andersnoren/endpaper/issues' ) && false !== strpos( $endpaper_html, 'wordpress.org/support/plugin/endpaper/reviews/#new-post' ) );
	endpaper_check( 'with a key, it lists the blocks', false !== strpos( $endpaper_html, 'endpaper-blocks' ) && false !== strpos( $endpaper_html, '/reading list' ) );
	endpaper_check( 'with a key, how to create one is folded away', false === strpos( $endpaper_html, '<details class="endpaper-disclosure" open>' ) );
	endpaper_check( 'it links to the form for a new key, with the permissions ticked', false !== strpos( $endpaper_html, 'hardcover.app/account/api/keys/new?scope=read%3Acatalog%20read%3Alibrary%3Apublic%20read%3Ame%3Acontent' ) );

	delete_option( 'endpaper_hardcover_token' );

	ob_start();
	render_settings_page();
	$endpaper_html = ob_get_clean();
	endpaper_check( 'without a key, there is no list of blocks or button to clear the cache, and how to create a key is shown', false === strpos( $endpaper_html, 'value="endpaper_clear_cache"' ) && false === strpos( $endpaper_html, 'endpaper-blocks' ) && false !== strpos( $endpaper_html, 'Create a key on Hardcover' ) );

	update_option( 'endpaper_hardcover_token', 'key-that-expired' );
	ob_start();
	render_settings_page();
	$endpaper_html = ob_get_clean();
	endpaper_check( 'with a key Hardcover turns down, how to create a new one is open', false !== strpos( $endpaper_html, '<details class="endpaper-disclosure" open>' ) && false === strpos( $endpaper_html, 'value="endpaper_clear_cache"' ) );
	delete_option( 'endpaper_hardcover_token' );
	delete_transient( 'endpaper_key_rejected' );

	echo "After the key is removed\n";
	delete_transient( 'endpaper_api_backoff' );
	wp_set_current_user( 0 );
	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42} /--><!-- wp:endpaper/reading-list {"status":3} /-->' );
	endpaper_check( 'visitors still get the book, and no messages', false !== strpos( $endpaper_html, 'endpaper-book__card' ) && false === strpos( $endpaper_html, 'endpaper-notice' ) );

	wp_set_current_user( 1 );
	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' );
	endpaper_check( 'people who can edit are told the book will go', false !== strpos( $endpaper_html, 'endpaper-book__card' ) && false !== strpos( $endpaper_html, 'shown from a saved copy' ) && false !== strpos( $endpaper_html, wp_date( 'Y' ) ) );
	endpaper_check( 'the message says it is private, and links to the settings', false !== strpos( $endpaper_html, 'Only people who can edit this site see this message.' ) && false !== strpos( $endpaper_html, 'options-general.php?page=endpaper' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-list {"status":3,"align":"wide"} /-->' );
	endpaper_check( 'a list that cannot be shown says why, at the normal width', false !== strpos( $endpaper_html, 'endpaper-notice' ) && false === strpos( $endpaper_html, 'alignwide' ) );

	remove_filter( 'endpaper_import_covers', '__return_false' );
	delete_all_covers();

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
