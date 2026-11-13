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

use function Endpaper\format_date;
use function Endpaper\api_request;
use function Endpaper\cache_key;
use function Endpaper\clear_cache;
use function Endpaper\can_store_covers;
use function Endpaper\cover_records;
use function Endpaper\delete_all_covers;
use function Endpaper\get_cover;
use function Endpaper\get_goal;
use function Endpaper\get_goal_days_text;
use function Endpaper\get_goals;
use function Endpaper\get_cover_dir;
use function Endpaper\get_cover_key;
use function Endpaper\get_cover_stats;
use function Endpaper\get_cover_queue_status;
use function Endpaper\get_covers_site_health_result;
use function Endpaper\import_cover;
use function Endpaper\prune_covers;
use function Endpaper\queue_cover;
use function Endpaper\save_queued_covers;
use function Endpaper\get_book_end_time;
use function Endpaper\get_connection_status;
use function Endpaper\get_editions;
use function Endpaper\get_key_problem_message;
use function Endpaper\get_privacy_policy_text;
use function Endpaper\get_site_health_result;
use function Endpaper\get_book_styles;
use function Endpaper\get_default_book_style;
use function Endpaper\get_reading_list;
use function Endpaper\get_token;
use function Endpaper\is_token_rejected;
use function Endpaper\render_settings_page;
use function Endpaper\sanitize_default_style_setting;
use function Endpaper\sanitize_token_setting;
use function Endpaper\search_books;
use function Endpaper\the_key_notice;
use function Endpaper\trim_to_sentence;

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

		$GLOBALS['endpaper_api_requests'] = ( isset( $GLOBALS['endpaper_api_requests'] ) ? $GLOBALS['endpaper_api_requests'] : 0 ) + 1;

		if ( false !== strpos( $query, 'EndpaperSearch' ) ) {
			$GLOBALS['endpaper_search_requests'] = ( isset( $GLOBALS['endpaper_search_requests'] ) ? $GLOBALS['endpaper_search_requests'] : 0 ) + 1;
		}

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
			$edition = static function ( $id, $cover, $look = array() ) {
				return array(
					'id'             => $id,
					'book_id'        => 42,
					'title'          => 'The Stand',
					'edition_format' => 'Paperback',
					'release_date'   => '1990-05-01',
					'image'          => $cover ? array( 'url' => 'https://assets.hardcover.app/' . $cover ) + $look : null,
					'language'       => array( 'language' => 'English' ),
				);
			};

			$red  = array( 'width' => 1400, 'height' => 2100, 'color' => '#aa1111', 'colors' => array( '#aa1111', '#eeeeee' ) );
			$blue = array( 'width' => 1400, 'height' => 2100, 'color' => '#1111aa', 'colors' => array( '#1111aa', '#eeeeee' ) );

			// The same picture as $blue, uploaded again smaller and saved again, so its colors are a little off.
			$blue_again = array( 'width' => 700, 'height' => 1050, 'color' => '#1414ad', 'colors' => array( '#1414ad', '#f1f1f1' ) );

			// Seven editions: two with covers of their own, one without a cover, one that shares a cover, one that is the same picture uploaded again, one that is the same size but another picture, and one with nothing known but its address.
			return $respond( 200, array( 'data' => array( 'editions' => array( $edition( 101, 'edition-101.jpg', $red ), $edition( 102, null ), $edition( 103, 'edition-103.jpg', $blue ), $edition( 104, 'edition-101.jpg', $red ), $edition( 105, 'edition-105.jpg', $blue_again ), $edition( 106, 'edition-106.jpg', array( 'width' => 1400, 'height' => 2100, 'color' => '#11aa11', 'colors' => array( '#11aa11' ) ) ), $edition( 107, 'edition-107.jpg' ) ) ) ) );
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
								'username'   => 'smoke-reader',
								'user_books' => array(
									array(
										'status_id'           => 2,
										'rating'              => 3.5,
										'review_raw'          => 'A quiet, sharp book. The ending stayed with me.',
										'review_markdown'     => empty( $GLOBALS['endpaper_review_plain'] ) ? "## Heading\n\nA quiet, sharp book. The **ending** stayed with me.\n\n> Quote.\n\n1. One\n2. Two" : '',
										'review_has_spoilers' => ! empty( $GLOBALS['endpaper_review_spoilers'] ),
										'reviewed_at'         => '2026-10-25T14:03:11.000000+00:00',
										'edition'             => array(
											'image'    => array( 'url' => 'https://assets.hardcover.app/edition-entry.jpg' ),
											'language' => array( 'language' => 'Swedish' ),
										),
										'user_book_reads'     => array( array( 'progress' => 68.4 ) ),
									),
								),
							),
						),
					),
				)
			);
		}

		if ( false !== strpos( $query, 'EndpaperGoals' ) ) {
			$goal = static function ( $id, $title, $target, $metric, $progress, $privacy = 1, $archived = false ) {
				return array(
					'id'                 => $id,
					'description'        => $title,
					'goal'               => $target,
					'metric'             => $metric,
					'progress'           => $progress,
					'start_date'         => '2026-01-01',
					'end_date'           => '2099-12-31',
					'privacy_setting_id' => $privacy,
					'archived'           => $archived,
				);
			};

			// Two public goals, one that is beaten, and three that must not show: private, archived, and in a unit that is not known.
			return $respond(
				200,
				array(
					'data' => array(
						'me' => array(
							array(
								'goals' => array(
									$goal( 1, 'Books <b>this year</b>', 50, 'book', 34 ),
									$goal( 2, 'Pages', 10000, 'page', 10508 ),
									$goal( 3, 'Private', 10, 'book', 3, 3 ),
									$goal( 4, 'Archived', 10, 'book', 3, 1, true ),
									$goal( 5, 'Odd', 10, 'chapter', 3 ),
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
	require_once WP_PLUGIN_DIR . '/endpaper/includes/settings-page.php';
	require_once WP_PLUGIN_DIR . '/endpaper/includes/site-health.php';
	require_once WP_PLUGIN_DIR . '/endpaper/includes/class-cli-command.php';

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

	search_books( 'The Stand' );
	endpaper_check( 'the same search again is answered from the cache', 1 === $GLOBALS['endpaper_search_requests'] );

	wp_set_current_user( 1 );
	$endpaper_limited = null;

	for ( $endpaper_i = 0; $endpaper_i <= ENDPAPER_SEARCH_LIMIT; $endpaper_i++ ) {
		$endpaper_limited = search_books( 'limit test ' . $endpaper_i );
	}

	endpaper_check( 'a person who searches too much is asked to wait, and the request is not sent', is_wp_error( $endpaper_limited ) && 'endpaper_search_limit' === $endpaper_limited->get_error_code() && 1 + ENDPAPER_SEARCH_LIMIT === $GLOBALS['endpaper_search_requests'] );

	delete_transient( 'endpaper_search_limit_1' );
	wp_set_current_user( 0 );

	echo "Refreshing in the background\n";
	$endpaper_book_key = Endpaper\cache_key( 'endpaper_book_42' );
	delete_transient( $endpaper_book_key );
	wp_unschedule_hook( 'endpaper_refresh' );
	$endpaper_requests = $GLOBALS['endpaper_api_requests'];

	$endpaper_nothing_saved = Endpaper\get_book( 42 );
	endpaper_check( 'a visitor does not wait for a book that is not saved: nothing is requested, and a fetch is queued', is_wp_error( $endpaper_nothing_saved ) && 'endpaper_pending' === $endpaper_nothing_saved->get_error_code() && $endpaper_requests === $GLOBALS['endpaper_api_requests'] && false !== wp_next_scheduled( 'endpaper_refresh', array( 'book', array( 42 ) ) ) );

	wp_unschedule_hook( 'endpaper_refresh' );
	set_current_screen( 'dashboard' );
	Endpaper\get_book( 42 );
	set_current_screen( 'front' );
	$endpaper_book_cached            = get_transient( $endpaper_book_key );
	$endpaper_book_cached['fetched'] = time() - 2 * WEEK_IN_SECONDS;
	set_transient( $endpaper_book_key, $endpaper_book_cached, MONTH_IN_SECONDS );
	$endpaper_requests = $GLOBALS['endpaper_api_requests'];

	$endpaper_old_book = Endpaper\get_book( 42 );
	Endpaper\get_book( 42 );
	endpaper_check( 'a visitor gets the saved book at once, and a refresh is queued once', is_array( $endpaper_old_book ) && $endpaper_requests === $GLOBALS['endpaper_api_requests'] && false !== wp_next_scheduled( 'endpaper_refresh', array( 'book', array( 42 ) ) ) && 1 === count( array_filter( _get_cron_array(), static function ( $hooks ) { return isset( $hooks['endpaper_refresh'] ); } ) ) );

	add_filter( 'wp_doing_cron', '__return_true' );
	do_action( 'endpaper_refresh', 'book', array( 42 ) );
	remove_filter( 'wp_doing_cron', '__return_true' );
	$endpaper_book_cached = get_transient( $endpaper_book_key );
	endpaper_check( 'the background job asks Hardcover and saves the book again', $endpaper_requests + 1 === $GLOBALS['endpaper_api_requests'] && time() - $endpaper_book_cached['fetched'] < 60 );

	$endpaper_book_cached['fetched'] = time() - 2 * WEEK_IN_SECONDS;
	set_transient( $endpaper_book_key, $endpaper_book_cached, MONTH_IN_SECONDS );
	wp_unschedule_hook( 'endpaper_refresh' );
	$endpaper_requests = $GLOBALS['endpaper_api_requests'];
	set_current_screen( 'dashboard' );
	Endpaper\get_book( 42 );
	set_current_screen( 'front' );
	endpaper_check( 'in the admin, the book is fetched at once and nothing is queued', $endpaper_requests + 1 === $GLOBALS['endpaper_api_requests'] && false === wp_next_scheduled( 'endpaper_refresh', array( 'book', array( 42 ) ) ) );

	// The block tests fetch what they show at once, as the editor does. A visitor's first view of something that is not saved is covered above.
	set_current_screen( 'dashboard' );

	echo "The Book block\n";
	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showShelf":true,"showProgress":true} /-->' );
	endpaper_check( 'it renders a card', false !== strpos( $endpaper_html, 'endpaper-book__content' ) );
	endpaper_check( 'the book has no frame unless it is given one, and then the block itself has it', false === strpos( $endpaper_html, 'endpaper-book--framed' ) && 1 === preg_match( '/<div [^>]*border-width:1px[^>]*endpaper-book--framed/', do_blocks( '<!-- wp:endpaper/book {"bookId":42,"style":{"border":{"width":"1px"}}} /-->' ) ) );
	endpaper_check( 'the title is escaped', false === strpos( $endpaper_html, '"Complete"' ) && false !== strpos( $endpaper_html, 'Complete' ) );
	endpaper_check( 'it shows the reading progress', false !== strpos( $endpaper_html, '<progress' ) );
	endpaper_check( 'it shows the owner’s rating as stars, next to the progress', false !== strpos( $endpaper_html, 'endpaper-book__my-rating' ) && false !== strpos( $endpaper_html, 'endpaper-icon--star-half' ) && false !== strpos( $endpaper_html, 'Rated 3.5 out of 5' ) );

	$endpaper_html_plain = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showUserRating":false} /-->' );
	endpaper_check( 'the owner’s rating can be switched off', false === strpos( $endpaper_html_plain, 'endpaper-book__my-rating' ) );
	endpaper_check( 'it uses the cover of the edition in the library', false !== strpos( $endpaper_html, 'edition-entry.jpg' ) && false === strpos( $endpaper_html, 'assets.hardcover.app/cover.jpg' ) );
	endpaper_check( 'it leaves out the Hardcover logo unless that is switched on', false === strpos( $endpaper_html, 'endpaper-logo' ) && false === strpos( $endpaper_html, 'Powered by Hardcover' ) );

	endpaper_check( 'the review is not shown unless it is switched on', false === strpos( $endpaper_html, 'A quiet, sharp book' ) );

	$endpaper_html_review = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showReview":true} /-->' );
	endpaper_check( 'the review can take the place of the description', false !== strpos( $endpaper_html_review, 'A quiet, sharp book' ) && false !== strpos( $endpaper_html_review, 'endpaper-book__description--review' ) && false === strpos( $endpaper_html_review, '<details' ) );
	endpaper_check( 'the review links to its page on Hardcover', false !== strpos( $endpaper_html_review, '/reviews/@smoke-reader"' ) && false !== strpos( $endpaper_html_review, 'Read on Hardcover' ) );
	endpaper_check( 'the day the review was written is shown', false !== strpos( $endpaper_html_review, 'datetime="2026-10-25"' ) && false !== strpos( $endpaper_html_review, '>Reviewed </span>' ) );
	endpaper_check( 'the review link is left out without the link to Hardcover, and without a review', false === strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showReview":true,"linkToHardcover":false} /-->' ), '/reviews/@' ) && false === strpos( $endpaper_html, '/reviews/@' ) );
	endpaper_check( 'the review can be shown without the description', false !== strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showReview":true,"showDescription":false} /-->' ), 'A quiet, sharp book' ) );
	endpaper_check( 'the review keeps its headings, bold text, quotes and lists', false !== strpos( $endpaper_html_review, '<strong>ending</strong>' ) && false !== strpos( $endpaper_html_review, '<blockquote>' ) && false !== strpos( $endpaper_html_review, '<ol><li>One</li>' ) && false !== strpos( $endpaper_html_review, '<p><strong>Heading</strong></p>' ) );
	$endpaper_html_short = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showReview":true,"reviewLength":20} /-->' );
	endpaper_check( 'the review is cut to its own length, on a whole block', false !== strpos( $endpaper_html_short, 'Heading' ) && false === strpos( $endpaper_html_short, 'A quiet' ) && false === strpos( $endpaper_html_short, '<blockquote>' ) );
	endpaper_check( 'a review is cut inside a block when it is long enough, without leaving bold text open', false === strpos( Endpaper\markdown_to_html( Endpaper\trim_markdown( 'First **bold words here** and more words that go on and on.', 20 ) ), '**' ) );
	endpaper_check( 'markup in a review is escaped, and links must be web addresses', false === strpos( Endpaper\markdown_to_html( '<script>alert(1)</script> [x](javascript:alert(1)) <img src=x onerror=alert(1)>' ), '<script' ) && false === strpos( Endpaper\markdown_to_html( '[x](javascript:alert(1))' ), 'javascript:' ) && false !== strpos( Endpaper\markdown_to_html( '[Hardcover](https://hardcover.app/)' ), '<a href="https://hardcover.app/"' ) );

	$GLOBALS['endpaper_review_plain'] = true;
	delete_transient( Endpaper\get_entry_cache_key( 42 ) );
	$endpaper_html_plain_review = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showReview":true} /-->' );
	endpaper_check( 'without Markdown, the review is shown as plain text', false !== strpos( $endpaper_html_plain_review, 'A quiet, sharp book. The ending stayed with me.' ) && false === strpos( $endpaper_html_plain_review, '<blockquote>' ) );
	$GLOBALS['endpaper_review_plain'] = false;
	delete_transient( Endpaper\get_entry_cache_key( 42 ) );

	$GLOBALS['endpaper_review_spoilers'] = true;
	delete_transient( Endpaper\get_entry_cache_key( 42 ) );
	$endpaper_html_spoilers = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showReview":true} /-->' );
	endpaper_check( 'a review with spoilers is closed behind a summary', false !== strpos( $endpaper_html_spoilers, '<details class="endpaper-book__spoilers">' ) && false !== strpos( $endpaper_html_spoilers, 'This review contains spoilers' ) );
	endpaper_check( 'the description is not put behind a summary when the review is off', false === strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' ), '<details' ) );
	$GLOBALS['endpaper_review_spoilers'] = false;
	delete_transient( Endpaper\get_entry_cache_key( 42 ) );

	$endpaper_html_credit = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showCredit":true} /-->' );
	endpaper_check( 'it credits Hardcover when that is switched on', false !== strpos( $endpaper_html_credit, 'endpaper-logo' ) && false !== strpos( $endpaper_html_credit, 'Powered by Hardcover' ) );

	$endpaper_html_bare = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showMeta":false,"showRating":false} /-->' );
	endpaper_check( 'the footer is left out when it would be empty', false === strpos( $endpaper_html_bare, 'endpaper-book__footer' ) );
	endpaper_check( 'the key is not in the output', false === strpos( $endpaper_html, 'smoke-test-key' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"showCover":false,"showMeta":false,"showRating":false,"showDescription":false,"linkToHardcover":false,"titleLevel":0} /-->' );
	endpaper_check( 'it renders with everything switched off', false !== strpos( $endpaper_html, 'endpaper-book__title' ) && false === strpos( $endpaper_html, 'endpaper-book__cover' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42,"coverSize":"large"} /-->' );
	endpaper_check( 'the cover can be made larger, and smaller', false !== strpos( $endpaper_html, 'endpaper-book--cover-large' ) && false !== strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42,"coverSize":"small"} /-->' ), 'endpaper-book--cover-small' ) && false !== strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' ), 'endpaper-book--cover-medium' ) );

	endpaper_check( 'the cover can be put on the right, and on top', false !== strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42,"coverPosition":"right"} /-->' ), 'endpaper-book--cover-right' ) && false !== strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42,"coverPosition":"top"} /-->' ), 'endpaper-book--cover-top' ) && false !== strpos( $endpaper_html, 'endpaper-book--cover-left' ) );

	endpaper_check( 'the text can be centered or aligned to the end, and bad values are ignored', false !== strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42,"textAlign":"center"} /-->' ), 'endpaper-book--align-center' ) && false !== strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42,"textAlign":"right"} /-->' ), 'endpaper-book--align-end' ) && false === strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42,"textAlign":"bogus"} /-->' ), 'endpaper-book--align-' ) && false === strpos( $endpaper_html, 'endpaper-book--align-' ) );

	echo "Edition covers\n";
	$endpaper_editions = get_editions( 42 );
	endpaper_check( 'editions without a cover, or with one already listed, are left out, also when it was uploaded again', is_array( $endpaper_editions ) && array( 101, 103, 106, 107 ) === wp_list_pluck( $endpaper_editions, 'id' ) );
	endpaper_check( 'an edition has a format, year and language', 'Paperback' === $endpaper_editions[0]['format'] && 1990 === $endpaper_editions[0]['year'] && 'English' === $endpaper_editions[0]['language'] );

	// The route is for people who can edit, so ask as the administrator.
	wp_set_current_user( 1 );
	$endpaper_response = rest_do_request( new WP_REST_Request( 'GET', '/endpaper/v1/editions/42' ) )->get_data();
	endpaper_check( 'the editor gets the editions and the languages to list first', 4 === count( $endpaper_response['editions'] ) && 'Swedish' === $endpaper_response['languages']['edition'] && get_locale() === $endpaper_response['languages']['site'] );

	// Fetching again marks the saved copy as old and asks Hardcover at once. A second request in a row has to wait.
	$endpaper_requests = $GLOBALS['endpaper_api_requests'];
	$endpaper_refresh  = new WP_REST_Request( 'POST', '/endpaper/v1/refresh' );
	$endpaper_refresh->set_body_params(
		array(
			'kind'    => 'entry',
			'book_id' => 42,
		)
	);
	$endpaper_response = rest_do_request( $endpaper_refresh );
	endpaper_check( 'a block can be fetched again, and Hardcover is asked once', 200 === $endpaper_response->get_status() && $endpaper_requests + 1 === $GLOBALS['endpaper_api_requests'] );
	endpaper_check( 'a second request in a row has to wait', 429 === rest_do_request( $endpaper_refresh )->get_status() && $endpaper_requests + 1 === $GLOBALS['endpaper_api_requests'] );
	delete_transient( 'endpaper_refresh_limit_1' );
	$endpaper_unknown = new WP_REST_Request( 'POST', '/endpaper/v1/refresh' );
	$endpaper_unknown->set_body_params( array( 'kind' => 'covers' ) );
	endpaper_check( 'only the person’s own activity can be fetched again', 400 === rest_do_request( $endpaper_unknown )->get_status() );
	wp_set_current_user( 0 );
	endpaper_check( 'people who cannot edit cannot ask for it', in_array( rest_do_request( $endpaper_refresh )->get_status(), array( 401, 403 ), true ) );
	wp_set_current_user( 1 );

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
	endpaper_check( 'the grid renders, framed', false !== strpos( $endpaper_html, 'endpaper-reading-list--grid' ) && false !== strpos( $endpaper_html, 'endpaper-reading-list--framed' ) );
	endpaper_check( 'a rating is shown as stars', false !== strpos( $endpaper_html, 'endpaper-icon--star-half' ) && false !== strpos( $endpaper_html, 'Rated 4.5 out of 5' ) );
	endpaper_check( 'a book without a cover gets a placeholder', false !== strpos( $endpaper_html, 'endpaper-cover-placeholder' ) );
	endpaper_check( 'the cover of the edition in the library is used', 1 === substr_count( $endpaper_html, 'edition-list.jpg' ) );
	endpaper_check( 'an edition without a cover falls back to the cover of the book', 1 === substr_count( $endpaper_html, 'assets.hardcover.app/cover.jpg' ) );
	endpaper_check( 'a spacing of zero gets a unit', false !== strpos( $endpaper_html, '--endpaper-gap: 0px' ) );

	$endpaper_gap_theme = static function ( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version' => 3,
				'styles'  => array(
					'blocks' => array(
						'endpaper/reading-list' => array( 'spacing' => array( 'blockGap' => '3rem' ) ),
					),
				),
			)
		);
	};
	add_filter( 'wp_theme_json_data_user', $endpaper_gap_theme );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	$endpaper_themed  = do_blocks( '<!-- wp:endpaper/reading-list {"status":3,"layout":"grid"} /-->' );
	$endpaper_own_gap = do_blocks( '<!-- wp:endpaper/reading-list {"status":3,"layout":"grid","style":{"spacing":{"blockGap":"10px"}}} /-->' );
	remove_filter( 'wp_theme_json_data_user', $endpaper_gap_theme );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	endpaper_check( 'a theme can set the gap of the list, and a gap on the block takes precedence', false !== strpos( $endpaper_themed, '--endpaper-gap: 3rem' ) && false !== strpos( $endpaper_own_gap, '--endpaper-gap: 10px' ) && false === strpos( $endpaper_own_gap, '--endpaper-gap: 3rem' ) );
	endpaper_check( 'the Hardcover logo is left out unless that is switched on', false === strpos( $endpaper_html, 'endpaper-logo' ) && false === strpos( $endpaper_html, 'endpaper-reading-list__attribution' ) );

	$endpaper_html_credit = do_blocks( '<!-- wp:endpaper/reading-list {"status":3,"showCredit":true} /-->' );
	endpaper_check( 'the list credits Hardcover when that is switched on', false !== strpos( $endpaper_html_credit, 'endpaper-logo' ) && false !== strpos( $endpaper_html_credit, 'Powered by Hardcover' ) );

	echo "The Reading Goal block\n";

	$endpaper_goals = get_goals();
	endpaper_check( 'only public goals in a known unit are listed', ! is_wp_error( $endpaper_goals ) && array( 1, 2 ) === wp_list_pluck( $endpaper_goals, 'id' ) );
	endpaper_check( 'a goal is found by its ID', 2 === get_goal( 2 )['id'] && null === get_goal( 3 ) && null === get_goal( 0 ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1,"className":"is-style-bar","showPercent":true} /-->' );
	endpaper_check( 'a goal shows its title, count and percentage', false !== strpos( $endpaper_html, '34 of 50 books read' ) && false !== strpos( $endpaper_html, '68%' ) && false !== strpos( $endpaper_html, 'value="68"' ) );
	endpaper_check( 'the title is escaped', false === strpos( $endpaper_html, '<b>this year</b>' ) );
	endpaper_check( 'the days left carry the end date and the ended text for the browser', 1 === preg_match( '/__status" data-end="\d{4}-\d{2}-\d{2}" data-ended="Ended [^"]+"/', $endpaper_html ) );
	endpaper_check( 'the visible percentage is hidden from screen readers, which get it from the bar', false !== strpos( $endpaper_html, '__percent" aria-hidden="true"' ) && 1 === preg_match( '/<progress[^>]+aria-label="\d+% of the goal"/', $endpaper_html ) );
	endpaper_check( 'the days left are shown', 1 === preg_match( '/[\d,.\x{00a0}]+ days left/u', $endpaper_html ) );
	endpaper_check( 'the title is a level 2 heading, and can be set to another level or a paragraph', false !== strpos( $endpaper_html, '<h2 class="endpaper-reading-goal__title">' ) && false !== strpos( do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1,"titleLevel":4} /-->' ), '<h4 class="endpaper-reading-goal__title">' ) && false !== strpos( do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1,"titleLevel":0} /-->' ), '<p class="endpaper-reading-goal__title">' ) );
	endpaper_check( 'the title and the days can be left out', false === strpos( do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1,"showTitle":false,"showDays":false} /-->' ), 'endpaper-reading-goal__title' ) && false === strpos( do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1,"showDays":false} /-->' ), 'days left' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":2,"className":"is-style-bar","showPercent":true} /-->' );
	endpaper_check( 'a goal that is beaten stops at 100%, like the bar', false !== strpos( $endpaper_html, '10,508 of 10,000 pages read' ) && false !== strpos( $endpaper_html, 'Goal reached' ) && false === strpos( $endpaper_html, 'days left' ) && false === strpos( $endpaper_html, '105%' ) && false !== strpos( $endpaper_html, '(100%)' ) && false !== strpos( $endpaper_html, 'value="100"' ) );

	endpaper_check( 'the percentage is left out unless that is switched on', false === strpos( do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1} /-->' ), '__percent' ) );

	echo "The shortcodes\n";
	delete_transient( Endpaper\cache_key( 'endpaper_slug_the-stand' ) );
	$endpaper_requests = $GLOBALS['endpaper_api_requests'];
	$endpaper_html     = do_shortcode( '[endpaper_book url="https://hardcover.app/books/the-stand" shelf="yes" review="no"]' );
	endpaper_check( 'the book shortcode finds the book from its Hardcover URL, and shows what it is told to', false !== strpos( $endpaper_html, 'Stephen King' ) && false === strpos( $endpaper_html, '/reviews/@' ) );
	$endpaper_requests = $GLOBALS['endpaper_api_requests'];
	do_shortcode( '[endpaper_book url="https://hardcover.app/books/the-stand"]' );
	endpaper_check( 'the book behind a URL is looked up once, then remembered', $endpaper_requests === $GLOBALS['endpaper_api_requests'] && 42 === absint( get_transient( Endpaper\cache_key( 'endpaper_slug_the-stand' ) ) ) );
	endpaper_check( 'a book can be given by ID, and parts can be left out', false === strpos( do_shortcode( '[endpaper_book id="42" cover="false" authors="0"]' ), 'Stephen King' ) && false === strpos( do_shortcode( '[endpaper_book id="42" cover="false"]' ), 'endpaper-book__cover' ) );

	wp_set_current_user( 0 );
	endpaper_check( 'a URL that is not a book page, and a shortcode without a book, show nothing to visitors', '' === trim( do_shortcode( '[endpaper_book url="https://example.com/"]' ) ) && '' === trim( do_shortcode( '[endpaper_book]' ) ) );

	delete_transient( Endpaper\cache_key( 'endpaper_slug_other-book' ) );
	wp_unschedule_hook( 'endpaper_refresh' );
	set_current_screen( 'front' );
	$endpaper_requests = $GLOBALS['endpaper_api_requests'];
	$endpaper_html     = do_shortcode( '[endpaper_book url="hardcover.app/books/other-book"]' );
	endpaper_check( 'a visitor does not wait for a URL that is not known yet: nothing is shown or requested, and a lookup is queued', '' === trim( $endpaper_html ) && $endpaper_requests === $GLOBALS['endpaper_api_requests'] && false !== wp_next_scheduled( 'endpaper_refresh', array( 'slug', array( 'other-book' ) ) ) );
	set_current_screen( 'dashboard' );
	wp_set_current_user( 1 );

	$endpaper_html = do_shortcode( '[endpaper_reading_list status="read" count="3" date="no"]' );
	endpaper_check( 'the reading list shortcode takes the status as a word, and the count', false !== strpos( do_shortcode( '[endpaper_reading_list status="currently-reading"]' ), 'endpaper-reading-list' ) && false !== strpos( $endpaper_html, 'endpaper-reading-list' ) && false !== strpos( do_shortcode( '[endpaper_reading_list status="want-to-read"]' ), 'endpaper-reading-list' ) );
	endpaper_check( 'the reading goal shortcode shows the goal that is running, or the one with the ID', false !== strpos( do_shortcode( '[endpaper_reading_goal]' ), '__number">34</span>' ) && false !== strpos( do_shortcode( '[endpaper_reading_goal goal="2"]' ), '__number">10,508</span>' ) && false === strpos( do_shortcode( '[endpaper_reading_goal title="no"]' ), 'endpaper-reading-goal__title' ) );
	endpaper_check( 'the reading goal shortcode can be a bar, and anything but bar is the gauge', false !== strpos( do_shortcode( '[endpaper_reading_goal style="bar"]' ), '<progress' ) && false === strpos( do_shortcode( '[endpaper_reading_goal style="bogus"]' ), '<progress' ) && false !== strpos( do_shortcode( '[endpaper_reading_goal style="gauge"]' ), 'endpaper-reading-goal__gauge' ) );
	endpaper_check( 'the current goal is the one that is running now', 1 === Endpaper\get_current_goal()['id'] );

	$endpaper_html = do_shortcode( '[endpaper_book id="42"]' );
	endpaper_check( 'a shortcode in a classic theme loads the styles of the block', wp_style_is( 'endpaper-book-style', 'enqueued' ) || wp_style_is( 'endpaper-book-style', 'registered' ) );
	endpaper_check( 'a shortcode takes no class, so the look is the block\'s own', 0 === preg_match( '/class="[^"]*\bmine\b/', do_shortcode( '[endpaper_book id="42" class="mine"]' ) ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1} /-->' );
	endpaper_check( 'a goal is a gauge unless it has the Bar style', false !== strpos( $endpaper_html, 'endpaper-reading-goal__gauge' ) && false !== strpos( $endpaper_html, 'endpaper-reading-goal--gauge' ) && false === strpos( $endpaper_html, '<progress' ) && false !== strpos( do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1,"className":"is-style-bar"} /-->' ), '<progress' ) && false === strpos( do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1,"className":"is-style-bar"} /-->' ), 'endpaper-reading-goal--gauge' ) );

	$endpaper_headings = static function ( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version' => 3,
				'styles'  => array(
					'elements' => array(
						'heading' => array(
							'typography' => array(
								'fontFamily'    => '"Playfair Display", serif',
								'fontWeight'    => '400',
								'letterSpacing' => '-0.02em',
							),
						),
					),
				),
			)
		);
	};
	add_filter( 'wp_theme_json_data_user', $endpaper_headings );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1} /-->' );
	remove_filter( 'wp_theme_json_data_user', $endpaper_headings );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	endpaper_check( 'the big number takes the font of the theme\'s headings', false !== strpos( $endpaper_html, '--endpaper-heading-font-family: &quot;Playfair Display&quot;, serif;' ) || false !== strpos( $endpaper_html, '--endpaper-heading-font-family: "Playfair Display", serif;' ) );
	// WordPress 7.1 leaves the semicolon off the last value in a style attribute, so the value may end in a quote.
	endpaper_check( 'and its weight and letter spacing', false !== strpos( $endpaper_html, '--endpaper-heading-font-weight: 400;' ) && 1 === preg_match( '/--endpaper-heading-letter-spacing: -0\.02em[;"]/', $endpaper_html ) );
	$endpaper_block_headings = static function ( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version' => 3,
				'styles'  => array(
					'elements' => array(
						'heading' => array(
							'typography' => array(
								'fontFamily' => 'Georgia, serif',
								'fontWeight' => '400',
							),
						),
					),
					'blocks'   => array(
						'endpaper/reading-goal' => array(
							'elements' => array(
								'heading' => array(
									'typography' => array( 'fontWeight' => '800' ),
								),
							),
						),
					),
				),
			)
		);
	};
	add_filter( 'wp_theme_json_data_user', $endpaper_block_headings );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	$endpaper_block_style = \Endpaper\get_heading_typography_style( 'endpaper/reading-goal' );
	$endpaper_other_style = \Endpaper\get_heading_typography_style( 'endpaper/book' );
	remove_filter( 'wp_theme_json_data_user', $endpaper_block_headings );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	endpaper_check( 'a theme can change the heading typography for one block, on top of the global one', false !== strpos( $endpaper_block_style, '--endpaper-heading-font-family: Georgia, serif;' ) && false !== strpos( $endpaper_block_style, '--endpaper-heading-font-weight: 800;' ) && false !== strpos( $endpaper_other_style, '--endpaper-heading-font-weight: 400;' ) );

	$endpaper_heading_color = static function ( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version' => 3,
				'styles'  => array(
					'elements' => array( 'heading' => array( 'color' => array( 'text' => '#111111' ) ) ),
					'blocks'   => array( 'endpaper/reading-goal' => array( 'elements' => array( 'heading' => array( 'color' => array( 'text' => '#aa0000' ) ) ) ) ),
				),
			)
		);
	};
	add_filter( 'wp_theme_json_data_user', $endpaper_heading_color );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	$endpaper_goal_color = \Endpaper\get_heading_color_style( array(), 'endpaper/reading-goal' );
	$endpaper_book_color = \Endpaper\get_heading_color_style( array(), 'endpaper/book' );
	remove_filter( 'wp_theme_json_data_user', $endpaper_heading_color );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	$endpaper_goal_plain  = do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1} /-->' );
	$endpaper_goal_framed = do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1,"style":{"border":{"width":"1px"}}} /-->' );
	endpaper_check( 'the goal has no frame unless it is given one, and then the block itself has it', false === strpos( $endpaper_goal_plain, 'endpaper-reading-goal--framed' ) && 1 === preg_match( '/<div [^>]*border-width:1px[^>]*endpaper-reading-goal--framed/', $endpaper_goal_framed ) );
	endpaper_check( 'the goal has no inner card element', false === strpos( $endpaper_goal_framed, 'endpaper-reading-goal__card' ) );
	endpaper_check( 'a heading color set for one block in theme.json is passed to its titles, the theme\'s global one is not', '--endpaper-heading-color: #aa0000;' === $endpaper_goal_color && '' === $endpaper_book_color );
	endpaper_check( 'a heading color set on the block is passed to its titles', '--endpaper-heading-color: #00aa00;' === \Endpaper\get_heading_color_style( array( 'style' => array( 'elements' => array( 'heading' => array( 'color' => array( 'text' => '#00aa00' ) ) ) ) ), 'endpaper/book' ) );
	endpaper_check( 'a heading color preset is passed as its variable', '--endpaper-heading-color: var(--wp--preset--color--accent-1);' === \Endpaper\get_heading_color_style( array( 'style' => array( 'elements' => array( 'heading' => array( 'color' => array( 'text' => 'var:preset|color|accent-1' ) ) ) ) ), 'endpaper/book' ) );
	endpaper_check( 'without a heading color on the block, nothing is set and titles inherit', '' === \Endpaper\get_heading_color_style( array(), 'endpaper/book' ) );

	// A heading font that is a preset is resolved to the font itself, whatever the theme in use.
	$endpaper_preset = static function ( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version'  => 3,
				'settings' => array(
					'typography' => array(
						'fontFamilies' => array(
							array(
								'slug'       => 'endpaper-test',
								'name'       => 'Endpaper test',
								'fontFamily' => '"Playfair Display", serif',
							),
						),
					),
				),
			)
		);
	};
	$endpaper_preset_heading = static function ( $theme_json ) {
		return $theme_json->update_with(
			array(
				'version' => 3,
				'styles'  => array(
					'elements' => array(
						'heading' => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|endpaper-test' ) ),
					),
				),
			)
		);
	};
	add_filter( 'wp_theme_json_data_theme', $endpaper_preset );
	add_filter( 'wp_theme_json_data_user', $endpaper_preset_heading );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	$endpaper_theme_style = \Endpaper\get_heading_typography_style( 'endpaper/reading-goal' );
	remove_filter( 'wp_theme_json_data_theme', $endpaper_preset );
	remove_filter( 'wp_theme_json_data_user', $endpaper_preset_heading );
	WP_Theme_JSON_Resolver::clean_cached_data();
	\Endpaper\clear_global_style_cache();
	endpaper_check( 'a heading font that is a preset is resolved to the font itself, not a variable', false !== strpos( $endpaper_theme_style, 'Playfair Display' ) && false === strpos( $endpaper_theme_style, 'var(' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":2,"className":"is-style-gauge","showPercent":true} /-->' );
	endpaper_check( 'the gauge style shows the count big, with the rest under it', false !== strpos( $endpaper_html, 'endpaper-reading-goal__gauge' ) && false !== strpos( $endpaper_html, '__number">10,508</span>' ) && false !== strpos( $endpaper_html, '__label">of 10,000 pages read<' ) && false !== strpos( $endpaper_html, '--endpaper-chars: 6' ) && false === strpos( $endpaper_html, '<progress' ) );
	endpaper_check( 'the arc of a goal that is beaten is full', false !== strpos( $endpaper_html, 'stroke-dasharray="100 100"' ) && false !== strpos( $endpaper_html, '__percent"><bdi>100%</bdi>' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":1,"className":"is-style-gauge"} /-->' );
	endpaper_check( 'the gauge leaves out the percentage unless that is switched on, and keeps the days left for the browser', false === strpos( $endpaper_html, '__percent' ) && false !== strpos( $endpaper_html, 'stroke-dasharray="68 100"' ) && 1 === preg_match( '/__status" data-end="\d{4}-\d{2}-\d{2}"/', $endpaper_html ) );

	endpaper_check( 'days are counted to the end date', 'Ends today' === get_goal_days_text( array( 'end' => current_datetime()->format( 'Y-m-d' ) ) ) && '1 day left' === get_goal_days_text( array( 'end' => current_datetime()->modify( '+1 day' )->format( 'Y-m-d' ) ) ) );
	endpaper_check( 'a goal that has ended says so', 0 === strpos( get_goal_days_text( array( 'end' => '2020-01-01' ) ), 'Ended' ) );

	$endpaper_editor_id = get_current_user_id();
	wp_set_current_user( 0 );
	endpaper_check( 'a private goal shows nothing to visitors', '' === trim( do_blocks( '<!-- wp:endpaper/reading-goal {"goalId":3} /-->' ) ) );
	endpaper_check( 'with two goals and none picked, visitors see nothing', '' === trim( do_blocks( '<!-- wp:endpaper/reading-goal /-->' ) ) );
	wp_set_current_user( $endpaper_editor_id );

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

		endpaper_check( 'the cover is saved on the site, in the sizes it is large enough for, without two that are nearly alike', array( 160, 400 ) === array_keys( $endpaper_files ) && 2 === count( glob( $endpaper_cover_dir['path'] . '/' . $endpaper_cover_key . '-*' ) ) );
		endpaper_check( 'the block shows the saved cover, with its sizes', false !== strpos( $endpaper_html, $endpaper_cover_dir['url'] . '/' . $endpaper_cover_key . '-' ) && false !== strpos( $endpaper_html, ' 160w, ' ) && false !== strpos( $endpaper_html, ' 400w"' ) && false !== strpos( $endpaper_html, 'width="400" height="600"' ) && false === strpos( $endpaper_html, 'assets.hardcover.app' ) );
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

	echo "Patterns\n";
	$endpaper_patterns = WP_Block_Patterns_Registry::get_instance();
	endpaper_check( 'the two patterns are registered, in a category of their own', $endpaper_patterns->is_registered( 'endpaper/currently-reading' ) && $endpaper_patterns->is_registered( 'endpaper/recently-read' ) && WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( 'endpaper' ) );
	$endpaper_html = do_blocks( $endpaper_patterns->get_registered( 'endpaper/recently-read' )['content'] );
	endpaper_check( 'a pattern renders a heading and a list, with the titles one level down', false !== strpos( $endpaper_html, '<h2 class="wp-block-heading">Recently read</h2>' ) && false !== strpos( $endpaper_html, 'endpaper-reading-list--list' ) && false !== strpos( $endpaper_html, '<h3' ) );

	echo "Covers waiting for scheduled tasks\n";
	$endpaper_had_import = has_filter( 'endpaper_import_covers', '__return_false' );
	$endpaper_had_remote = has_filter( 'endpaper_allow_remote_covers', '__return_false' );
	remove_filter( 'endpaper_import_covers', '__return_false' );
	remove_filter( 'endpaper_allow_remote_covers', '__return_false' );
	wp_set_current_user( 1 );
	update_option( 'endpaper_cover_queue', array( 'waitkey' => 'https://assets.hardcover.app/mock/wait.png' ), false );
	wp_clear_scheduled_hook( 'endpaper_save_queued_covers' );

	wp_schedule_single_event( time(), 'endpaper_save_queued_covers' );
	$endpaper_queue = get_cover_queue_status();
	endpaper_check( 'a cover that was queued just now is waiting, not stuck', 1 === $endpaper_queue['waiting'] && ! $endpaper_queue['stuck'] );

	wp_clear_scheduled_hook( 'endpaper_save_queued_covers' );
	wp_schedule_single_event( time() - 20 * MINUTE_IN_SECONDS, 'endpaper_save_queued_covers' );
	$endpaper_queue = get_cover_queue_status();
	endpaper_check( 'a job that is twenty minutes late means the scheduled tasks are not running', $endpaper_queue['stuck'] );

	if ( can_store_covers() ) {
		$endpaper_health = get_covers_site_health_result();
		ob_start();
		render_settings_page();
		$endpaper_html = ob_get_clean();
		endpaper_check( 'Site Health recommends looking into it, and the settings screen says so', 'recommended' === $endpaper_health['status'] && false !== strpos( $endpaper_health['description'], 'scheduled tasks' ) && false !== strpos( $endpaper_html, 'notice-warning' ) );
	}

	delete_option( 'endpaper_cover_queue' );
	wp_clear_scheduled_hook( 'endpaper_save_queued_covers' );
	endpaper_check( 'with nothing waiting, nothing is reported', ! get_cover_queue_status()['stuck'] );

	if ( $endpaper_had_import ) {
		add_filter( 'endpaper_import_covers', '__return_false' );
	}

	if ( $endpaper_had_remote ) {
		add_filter( 'endpaper_allow_remote_covers', '__return_false' );
	}

	wp_set_current_user( 0 );

	echo "Clearing the cache\n";
	do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' );
	$endpaper_generation = (int) get_option( 'endpaper_cache_generation', 0 );
	endpaper_check( 'the book is in the cache', false !== get_transient( cache_key( 'endpaper_book_42' ) ) );
	clear_cache();
	endpaper_check( 'clearing it puts everything out of reach', (int) get_option( 'endpaper_cache_generation' ) === $endpaper_generation + 1 && false === get_transient( cache_key( 'endpaper_book_42' ) ) && 0 === get_book_end_time( 42 ) );
	endpaper_check( 'the key is kept', 'smoke-test-key' === get_token() );
	endpaper_check( 'the block fetches the book again', false !== strpos( do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' ), 'endpaper-book__content' ) && false !== get_transient( cache_key( 'endpaper_book_42' ) ) );

	echo "The settings screen\n";
	wp_set_current_user( 1 );
	ob_start();
	render_settings_page();
	$endpaper_html = ob_get_clean();
	endpaper_check( 'it renders for an administrator', false !== strpos( $endpaper_html, 'endpaper-token' ) && false !== strpos( $endpaper_html, 'read:library:public' ) );
	endpaper_check( 'it lists both blocks with their copy markup, and the patterns', false !== strpos( $endpaper_html, 'data-copy="&lt;!-- wp:endpaper/book /--&gt;"' ) && false !== strpos( $endpaper_html, 'data-copy="&lt;!-- wp:endpaper/reading-list /--&gt;"' ) && false !== strpos( $endpaper_html, '/reading list' ) && false !== strpos( $endpaper_html, 'Currently reading' ) && false !== strpos( $endpaper_html, 'Recently read' ) );
	endpaper_check( 'it never outputs the saved key', false === strpos( $endpaper_html, 'smoke-test-key' ) );

	delete_option( ENDPAPER_DEFAULT_STYLE_OPTION );
	endpaper_check( 'new Book blocks start as Plain until a style is chosen', 'default' === get_default_book_style() );
	endpaper_check( 'only the styles the block has can be saved as the default', 'card' === sanitize_default_style_setting( 'card' ) && 'default' === sanitize_default_style_setting( 'is-style-card' ) && 'default' === sanitize_default_style_setting( array( 'card' ) ) );

	update_option( ENDPAPER_DEFAULT_STYLE_OPTION, 'card' );
	ob_start();
	render_settings_page();
	$endpaper_html = ob_get_clean();
	endpaper_check( 'the settings screen offers both styles, with the chosen one selected', 'card' === get_default_book_style() && 1 === preg_match( '/value="card"\s+checked/', $endpaper_html ) && 1 === preg_match( '/value="default"\s*\/>/', $endpaper_html ) );
	register_block_style( 'endpaper/book', array( 'name' => 'smoke-outline', 'label' => 'Smoke outline' ) );
	endpaper_check( 'styles registered by a theme or plugin are listed', 'Smoke outline' === ( get_book_styles()['smoke-outline'] ?? '' ) && 'Plain' === get_book_styles()['default'] );
	endpaper_check( 'and can be saved and chosen as the default', 'smoke-outline' === sanitize_default_style_setting( 'smoke-outline' ) );
	update_option( ENDPAPER_DEFAULT_STYLE_OPTION, 'smoke-outline' );
	ob_start();
	render_settings_page();
	$endpaper_html = ob_get_clean();
	endpaper_check( 'the settings screen offers them, with the chosen one selected', 'smoke-outline' === get_default_book_style() && 1 === preg_match( '/value="smoke-outline"\s+checked/', $endpaper_html ) && false !== strpos( $endpaper_html, 'Smoke outline' ) );
	unregister_block_style( 'endpaper/book', 'smoke-outline' );
	endpaper_check( 'a stored style that is no longer registered falls back to Plain', 'default' === get_default_book_style() && 'default' === sanitize_default_style_setting( 'smoke-outline' ) );
	delete_option( ENDPAPER_DEFAULT_STYLE_OPTION );
	endpaper_check( 'the suggested privacy text says that covers are loaded from Hardcover, when they are', false !== strpos( get_privacy_policy_text(), 'receives your IP address' ) );
	remove_filter( 'endpaper_import_covers', '__return_false' );
	endpaper_check( 'and that nothing is sent, when covers are saved on the site', ! can_store_covers() || false !== strpos( get_privacy_policy_text(), 'Nothing about you is sent to Hardcover' ) );
	add_filter( 'endpaper_import_covers', '__return_false' );
	endpaper_check( 'Site Health says when covers are not saved', 'Endpaper is set not to save book covers' === get_covers_site_health_result()['label'] );
	endpaper_check( 'it explains where covers are kept, and what is sent to Hardcover', false !== strpos( $endpaper_html, 'uploads/endpaper/covers' ) && false !== strpos( $endpaper_html, 'endpaper_cover_widths' ) && false !== strpos( $endpaper_html, 'Do my visitors’ browsers contact Hardcover?' ) );
	endpaper_check( 'it has a button to clear the cache', false !== strpos( $endpaper_html, 'value="endpaper_clear_cache"' ) && false === strpos( $endpaper_html, 'disabled' ) );
	endpaper_check( 'it says the plugin is unofficial, and where to get help', false !== strpos( $endpaper_html, 'Endpaper is an unofficial plugin' ) && false !== strpos( $endpaper_html, 'not maintained or endorsed by Hardcover' ) && false !== strpos( $endpaper_html, 'wordpress.org/support/plugin/endpaper/' ) );
	endpaper_check( 'it explains the shortcodes, with the statuses named like Hardcover\'s addresses', false !== strpos( $endpaper_html, '<code>[endpaper_book url=' ) && false !== strpos( $endpaper_html, '<code>want-to-read</code>' ) && false !== strpos( $endpaper_html, '<code>[endpaper_reading_goal]</code>' ) && false === strpos( $endpaper_html, '`' ) );
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
	endpaper_check( 'visitors still get the book, and no messages', false !== strpos( $endpaper_html, 'endpaper-book__content' ) && false === strpos( $endpaper_html, 'endpaper-notice' ) );

	wp_set_current_user( 1 );
	$endpaper_html = do_blocks( '<!-- wp:endpaper/book {"bookId":42} /-->' );
	endpaper_check( 'people who can edit are told the book will go', false !== strpos( $endpaper_html, 'endpaper-book__content' ) && false !== strpos( $endpaper_html, 'shown from a saved copy' ) && false !== strpos( $endpaper_html, wp_date( 'Y' ) ) );
	endpaper_check( 'the message says it is private, and links to the settings', false !== strpos( $endpaper_html, 'Only people who can edit this site see this message.' ) && false !== strpos( $endpaper_html, 'options-general.php?page=endpaper' ) );

	$endpaper_html = do_blocks( '<!-- wp:endpaper/reading-list {"status":3,"align":"wide"} /-->' );
	endpaper_check( 'a list that cannot be shown says why, at the normal width', false !== strpos( $endpaper_html, 'endpaper-notice' ) && false === strpos( $endpaper_html, 'alignwide' ) );

	remove_filter( 'endpaper_import_covers', '__return_false' );
	delete_all_covers();

	echo "Translations\n";
	foreach ( array( 'endpaper-book-editor-script', 'endpaper-reading-list-editor-script', 'endpaper-reading-goal-editor-script', 'endpaper-reading-goal-view-script' ) as $endpaper_handle ) {
		$endpaper_script = wp_scripts()->registered[ $endpaper_handle ] ?? null;
		endpaper_check( $endpaper_handle . ' loads translations', $endpaper_script && 'endpaper' === $endpaper_script->textdomain );
	}
} catch ( Throwable $endpaper_error ) {
	$endpaper_message                = get_class( $endpaper_error ) . ': ' . $endpaper_error->getMessage() . ' in ' . $endpaper_error->getFile() . ':' . $endpaper_error->getLine();
	$GLOBALS['endpaper_failures'][] = $endpaper_message;
	echo '  FAIL  ' . $endpaper_message . "\n";
}

restore_error_handler();

$endpaper_card      = WP_Block_Styles_Registry::get_instance()->get_registered( 'endpaper/book', 'card' );
$endpaper_card_dupe = array_filter(
	WP_Block_Type_Registry::get_instance()->get_registered( 'endpaper/book' )->styles,
	static fn( $style ) => 'card' === $style['name']
);

endpaper_check( 'the WP-CLI command to clear the cache exists', method_exists( '\\Endpaper\\CLI_Command', 'clear_cache' ) );

// Without WP-CLI, only that the command exists is checked.
if ( class_exists( 'WP_CLI' ) ) {
	$endpaper_generation = (int) get_option( 'endpaper_cache_generation', 0 );
	( new \Endpaper\CLI_Command() )->clear_cache( array(), array( 'force' => true ) );
	endpaper_check( 'the command clears the cache', (int) get_option( 'endpaper_cache_generation', 0 ) === $endpaper_generation + 1 );
}

endpaper_check( 'the Card style is registered with its settings as data', is_array( $endpaper_card ) && '0.75em' === $endpaper_card['style_data']['border']['radius'] && '1.5em' === $endpaper_card['style_data']['spacing']['padding'] );
$endpaper_lifetime = static fn( $kind ) => \Endpaper\get_cache_lifetime( $kind );
$endpaper_short    = static fn() => 5;
$endpaper_two_days = static fn() => 2 * DAY_IN_SECONDS;
$endpaper_default  = array( $endpaper_lifetime( 'book' ), $endpaper_lifetime( 'list' ) );
add_filter( 'endpaper_cache_lifetime', $endpaper_short );
$endpaper_floor = $endpaper_lifetime( 'list' );
remove_filter( 'endpaper_cache_lifetime', $endpaper_short );
add_filter( 'endpaper_cache_lifetime', $endpaper_two_days );
$endpaper_long = $endpaper_lifetime( 'goals' );
remove_filter( 'endpaper_cache_lifetime', $endpaper_two_days );

$endpaper_value = static fn( $value ) => static fn() => $value;
$endpaper_try   = static function ( $hook, $value, $function ) use ( $endpaper_value ) {
	$callback = $endpaper_value( $value );
	add_filter( $hook, $callback );
	$result = $function();
	remove_filter( $hook, $callback );
	return $result;
};
$endpaper_ns = '\\Endpaper\\';

endpaper_check(
	'the request timeout, the number of search results and the wait after a failure can be filtered, within limits',
	15 === ( $endpaper_ns . 'get_request_timeout' )()
		&& 30 === $endpaper_try( 'endpaper_request_timeout', 30, $endpaper_ns . 'get_request_timeout' )
		&& 60 === $endpaper_try( 'endpaper_request_timeout', 600, $endpaper_ns . 'get_request_timeout' )
		&& 8 === ( $endpaper_ns . 'get_search_results_count' )()
		&& 12 === $endpaper_try( 'endpaper_search_results', 12, $endpaper_ns . 'get_search_results_count' )
		&& 20 === $endpaper_try( 'endpaper_search_results', 99, $endpaper_ns . 'get_search_results_count' )
		&& 1 === $endpaper_try( 'endpaper_search_results', 0, $endpaper_ns . 'get_search_results_count' )
);

( $endpaper_ns . 'set_backoff' )( 'down' );
$endpaper_default_wait = (int) get_option( '_transient_timeout_endpaper_api_backoff' ) - time();
$endpaper_try( 'endpaper_backoff', 60, fn() => ( $endpaper_ns . 'set_backoff' )( 'down' ) );
$endpaper_short_wait = (int) get_option( '_transient_timeout_endpaper_api_backoff' ) - time();
delete_transient( 'endpaper_api_backoff' );
endpaper_check( 'the wait after a failed request is five minutes unless filtered', $endpaper_default_wait > 295 && $endpaper_default_wait <= 300 && $endpaper_short_wait > 55 && $endpaper_short_wait <= 60 );

endpaper_check( 'how long saved data counts as fresh can be filtered, and never goes under a minute', array( WEEK_IN_SECONDS, HOUR_IN_SECONDS ) === $endpaper_default && MINUTE_IN_SECONDS === $endpaper_floor && 2 * DAY_IN_SECONDS === $endpaper_long );
endpaper_check( 'the Card style is not also listed in block.json, so the editor does not show it twice', ! $endpaper_card_dupe );

if ( $GLOBALS['endpaper_failures'] ) {
	echo "\n" . count( $GLOBALS['endpaper_failures'] ) . " check(s) failed.\n";
	exit( 1 );
}

echo "\nAll checks passed.\n";
