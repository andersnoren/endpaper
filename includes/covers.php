<?php
/**
 * Book covers: saving them on the site, and showing them.
 *
 * Covers are downloaded from Hardcover once and kept in a folder of the
 * plugin's own under uploads, in a few sizes, so that visitors' browsers do
 * not have to contact Hardcover. They are not added to the media library:
 * they are a copy of somebody else's images, kept for as long as a block
 * shows them, and not content for the site's owner to look after.
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * The option that lists the saved covers. It is not loaded until it is needed.
 */
define( 'ENDPAPER_COVERS_OPTION', 'endpaper_covers' );

/**
 * The option that lists the covers waiting to be saved.
 */
define( 'ENDPAPER_COVER_QUEUE_OPTION', 'endpaper_cover_queue' );

/**
 * The largest cover that is downloaded, in bytes.
 */
define( 'ENDPAPER_COVER_MAX_BYTES', 5 * MB_IN_BYTES );

/**
 * Tells whether covers are saved on the site.
 *
 * They are, unless the site says otherwise. Without it, covers are loaded
 * from Hardcover by each visitor's browser.
 *
 * @return bool Whether covers are saved on the site.
 */
function is_cover_import_enabled() {
	/**
	 * Filters whether book covers are saved on the site.
	 *
	 * @param bool $enabled Whether covers are saved. Default true.
	 */
	return (bool) apply_filters( 'endpaper_import_covers', true );
}

/**
 * Tells whether a cover that is not saved on the site may be loaded from Hardcover instead.
 *
 * It may, unless the site says otherwise: a site whose visitors' browsers
 * must never contact Hardcover. Its covers show as placeholders when they
 * cannot be saved.
 *
 * @return bool Whether covers may be loaded from Hardcover.
 */
function are_remote_covers_allowed() {
	/**
	 * Filters whether book covers that are not saved on the site may be loaded from Hardcover.
	 *
	 * @param bool $allowed Whether they may. Default true.
	 */
	return (bool) apply_filters( 'endpaper_allow_remote_covers', true );
}

/**
 * Returns the widths covers are saved in, smallest first.
 *
 * A cover is never made larger than the one Hardcover has.
 *
 * @return int[] Widths in pixels.
 */
function get_cover_widths() {
	$default = array( 160, 320, 640 );

	/**
	 * Filters the widths book covers are saved in.
	 *
	 * @param int[] $widths Widths in pixels. Default 160, 320 and 640.
	 */
	$widths = apply_filters( 'endpaper_cover_widths', $default );
	$widths = array_values( array_unique( array_filter( array_map( 'absint', (array) $widths ) ) ) );

	sort( $widths );

	return $widths ? $widths : $default;
}

/**
 * Returns the quality covers are saved with.
 *
 * Covers are shown small and are mostly flat color and type, so they can be
 * compressed harder than photographs.
 *
 * @return int A quality from 1 to 100.
 */
function get_cover_quality() {
	/**
	 * Filters the quality book covers are saved with.
	 *
	 * @param int $quality A quality from 1 (smallest file) to 100 (best image). Default 80.
	 */
	$quality = absint( apply_filters( 'endpaper_cover_quality', 80 ) );

	return min( 100, max( 1, $quality ) );
}

/**
 * Tells whether a cover may be downloaded from an address.
 *
 * Only Hardcover's own image server is accepted, over HTTPS.
 *
 * @param string $url The address of a cover.
 * @return bool Whether it may be downloaded.
 */
function is_allowed_cover_url( $url ) {
	$parts = wp_parse_url( $url );

	if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] ) {
		return false;
	}

	/**
	 * Filters the hosts book covers may be downloaded from.
	 *
	 * @param string[] $hosts Host names. Default Hardcover's image server.
	 */
	$hosts = (array) apply_filters( 'endpaper_cover_hosts', array( 'assets.hardcover.app' ) );

	return in_array( strtolower( $parts['host'] ), $hosts, true );
}

/**
 * Returns the folder covers are saved in.
 *
 * @return array The folder's "path" and "url", both empty if the site has no uploads folder.
 */
function get_cover_dir() {
	$uploads = wp_upload_dir( null, false );

	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return array(
			'path' => '',
			'url'  => '',
		);
	}

	return array(
		'path' => $uploads['basedir'] . '/endpaper/covers',
		'url'  => set_url_scheme( $uploads['baseurl'] . '/endpaper/covers' ),
	);
}

/**
 * Tells whether covers can be saved: whether their folder is there and can be written to.
 *
 * The folder is created the first time it is needed. An empty index file
 * goes in it, so that its contents cannot be listed on servers that list
 * folders.
 *
 * @return bool Whether covers can be saved.
 */
function can_store_covers() {
	static $can = null;

	if ( null !== $can ) {
		return $can;
	}

	$dir = get_cover_dir();

	if ( '' === $dir['path'] ) {
		$can = false;

		return $can;
	}

	if ( ! is_dir( $dir['path'] ) && wp_mkdir_p( $dir['path'] ) ) {
		foreach ( array( dirname( $dir['path'] ), $dir['path'] ) as $folder ) {
			if ( ! file_exists( $folder . '/index.html' ) ) {
				// An empty file. Nothing is lost if it cannot be created.
				touch( $folder . '/index.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- An empty file in the plugin's own folder under uploads.
			}
		}
	}

	$can = is_dir( $dir['path'] ) && wp_is_writable( $dir['path'] );

	return $can;
}

/**
 * Returns what is known about the saved covers, and optionally replaces it.
 *
 * Each cover is listed under a key made from its address on Hardcover, with
 * that address ("src"), its files by width ("files"), when a block last
 * showed it ("used"), or when saving it last failed ("failed").
 *
 * @param array|null $replace Optional. A new list to save.
 * @param bool       $fresh   Optional. Whether to read the list from the database again.
 * @return array The list.
 */
function cover_records( $replace = null, $fresh = false ) {
	static $records = null;

	if ( is_array( $replace ) ) {
		$records = $replace;

		update_option( ENDPAPER_COVERS_OPTION, $records, false );

		return $records;
	}

	if ( $fresh ) {
		wp_cache_delete( ENDPAPER_COVERS_OPTION, 'options' );

		$records = null;
	}

	if ( null === $records ) {
		$records = get_option( ENDPAPER_COVERS_OPTION, array() );
		$records = is_array( $records ) ? $records : array();
	}

	return $records;
}

/**
 * Returns the key a cover is filed under.
 *
 * @param string $url The cover's address on Hardcover.
 * @return string Twenty characters.
 */
function get_cover_key( $url ) {
	return substr( md5( $url ), 0, 20 );
}

/**
 * Tells whether a request has fetched anything from Hardcover, and notes when it does.
 *
 * Such a request is slow already, and is usually the one in which a new
 * book turns up, so its covers are saved right away. See may_import_cover_now().
 *
 * @param bool $note Optional. Whether to note that something was fetched.
 * @return bool Whether something was fetched during this request.
 */
function did_fetch_from_hardcover( $note = false ) {
	static $did = false;

	if ( $note ) {
		$did = true;
	}

	return $did;
}

/**
 * Tells whether a cover may be saved during this request, and keeps the time.
 *
 * Saving a cover takes a moment, so visitors are not made to wait for it:
 * their covers are saved in the background. It is done right away for people
 * who can edit, so a block they have just added shows its cover, and in
 * requests that have fetched from Hardcover anyway. Either way only for a
 * few seconds in all. What does not fit waits for the background.
 *
 * @param float $spent Optional. Seconds just spent saving a cover.
 * @return bool Whether there is time left to save a cover now.
 */
function may_import_cover_now( $spent = 0.0 ) {
	static $total = 0.0;

	$total += $spent;

	if ( $total >= 3 ) {
		return false;
	}

	return wp_doing_cron() || did_fetch_from_hardcover() || current_user_can( 'edit_posts' );
}

/**
 * Returns what is needed to show a cover.
 *
 * @param string $url The cover's address on Hardcover.
 * @return array|null The image's "src", and for a saved cover its "srcset", "width" and
 *                    "height". "remote" is true for a cover that is loaded from Hardcover.
 *                    Null if there is nothing to show yet, or at all.
 */
function get_cover( $url ) {
	$url = is_string( $url ) ? $url : '';

	if ( '' === $url ) {
		return null;
	}

	// Covers that cannot be saved are loaded from Hardcover, as are those from an address that is not known. Unless the site does not allow that: then there is nothing to show.
	if ( ! is_cover_import_enabled() || ! is_allowed_cover_url( $url ) || ! can_store_covers() ) {
		if ( ! are_remote_covers_allowed() ) {
			return null;
		}

		return array(
			'src'    => $url,
			'remote' => true,
		);
	}

	$key     = get_cover_key( $url );
	$records = cover_records();
	$record  = isset( $records[ $key ] ) ? $records[ $key ] : array();

	if ( ! empty( $record['files'] ) ) {
		$image = get_saved_cover_image( $record );

		if ( $image ) {
			note_cover_use( $key );

			return $image;
		}
	}

	// Hardcover could not be reached, or did not have the image. Try again in an hour.
	if ( ! empty( $record['failed'] ) && time() - $record['failed'] < HOUR_IN_SECONDS ) {
		return null;
	}

	if ( may_import_cover_now() ) {
		$started = microtime( true );
		$record  = import_cover( $url );

		may_import_cover_now( microtime( true ) - $started );

		return $record ? get_saved_cover_image( $record ) : null;
	}

	queue_cover( $url );

	return null;
}

/**
 * Returns the image attributes for a saved cover.
 *
 * @param array $record What is known about the cover. See cover_records().
 * @return array|null The "src", "srcset", "width" and "height", or null if the files are gone.
 */
function get_saved_cover_image( $record ) {
	$dir   = get_cover_dir();
	$files = isset( $record['files'] ) && is_array( $record['files'] ) ? $record['files'] : array();

	ksort( $files );

	// The files can go missing, for example on hosts that do not keep uploads. One is checked, not all.
	$first = reset( $files );

	if ( ! $first || empty( $first['file'] ) || ! file_exists( $dir['path'] . '/' . $first['file'] ) ) {
		return null;
	}

	$srcset  = array();
	$default = null;

	foreach ( $files as $width => $file ) {
		$srcset[] = $dir['url'] . '/' . $file['file'] . ' ' . absint( $width ) . 'w';

		// The image for browsers that do not choose for themselves: the middle one, or the original that took its place.
		if ( null === $default || $width < 1.3 * 320 ) {
			$default = $file;
		}
	}

	return array(
		'src'    => $dir['url'] . '/' . $default['file'],
		'srcset' => implode( ', ', $srcset ),
		'width'  => absint( $default['width'] ),
		'height' => absint( $default['height'] ),
	);
}

/**
 * Downloads a cover from Hardcover and saves it in each size.
 *
 * The file from Hardcover is not kept. Whatever goes wrong, it is noted, so
 * that the cover is not tried again on every page view.
 *
 * @param string $url The cover's address on Hardcover.
 * @return array|false What is now known about the cover, or false if it could not be saved.
 */
function import_cover( $url ) {
	if ( ! is_allowed_cover_url( $url ) || ! can_store_covers() ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$key   = get_cover_key( $url );
	$dir   = get_cover_dir();
	$files = array();
	$temp  = wp_tempnam( 'endpaper-cover' );

	if ( $temp ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'stream'              => true,
				'filename'            => $temp,
				'limit_response_size' => ENDPAPER_COVER_MAX_BYTES + 1,
			)
		);

		$size = file_exists( $temp ) ? (int) filesize( $temp ) : 0;

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) && $size > 0 && $size <= ENDPAPER_COVER_MAX_BYTES ) {
			delete_cover_files( $key );

			$files = save_cover_sizes( $temp, $dir['path'] . '/' . $key );
		}

		wp_delete_file( $temp );
	}

	// Read again, in case covers were saved elsewhere in the meantime.
	$records = cover_records( null, true );

	if ( $files ) {
		$records[ $key ] = array(
			'src'   => $url,
			'files' => $files,
			'used'  => time(),
		);
	} else {
		$records[ $key ] = array(
			'src'    => $url,
			'failed' => time(),
		);
	}

	cover_records( $records );

	return $files ? $records[ $key ] : false;
}

/**
 * Saves a downloaded image in each of the cover sizes.
 *
 * The format is WebP where the server can write it, and JPEG where it
 * cannot. A site that has asked for another format for its images, with
 * the image_editor_output_format filter, gets that.
 *
 * @param string $source The path of the downloaded file.
 * @param string $target The path to save to, without a width or an extension.
 * @return array The saved files by width, each with its "file" name, "width" and "height". Empty if none could be saved.
 */
function save_cover_sizes( $source, $target ) {
	$mime = wp_get_image_mime( $source );

	if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ), true ) ) {
		return array();
	}

	$editor = wp_get_image_editor( $source, array( 'mime_type' => $mime ) );

	if ( is_wp_error( $editor ) ) {
		return array();
	}

	$original = $editor->get_size();
	$format   = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'image/webp' : 'image/jpeg';

	/** This filter is documented in wp-includes/class-wp-image-editor.php */
	$wanted = apply_filters( 'image_editor_output_format', array(), $source, $mime ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- A core filter, asked what the site wants.

	if ( is_array( $wanted ) && ! empty( $wanted[ $mime ] ) && wp_image_editor_supports( array( 'mime_type' => $wanted[ $mime ] ) ) ) {
		$format = $wanted[ $mime ];
	}

	// Never larger than the original. If it is smaller than every size, it is saved as it is.
	$widths = array();

	foreach ( get_cover_widths() as $width ) {
		$widths[] = min( $width, (int) $original['width'] );
	}

	$widths = array_values( array_unique( $widths ) );
	$count  = count( $widths );

	// An original that is only a little wider than a size takes that size's place, so two files that are nearly alike are not both saved.
	if ( $count > 1 && $widths[ $count - 1 ] === (int) $original['width'] && $widths[ $count - 1 ] < 1.3 * $widths[ $count - 2 ] ) {
		array_splice( $widths, $count - 2, 1 );
	}

	$extension = wp_get_default_extension_for_mime_type( $format );
	$files     = array();

	// Core sets the quality again when it changes the format, so the quality is given through its filter.
	$set_quality = static function () {
		return get_cover_quality();
	};

	add_filter( 'wp_editor_set_quality', $set_quality, 999 );

	foreach ( $widths as $width ) {
		$editor = wp_get_image_editor( $source, array( 'mime_type' => $mime ) );

		if ( is_wp_error( $editor ) ) {
			continue;
		}

		if ( $width < (int) $original['width'] ) {
			$editor->resize( $width, null, false );
		}

		$saved = $editor->save( $target . '-' . $width . '.' . ( $extension ? $extension : 'jpg' ), $format );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			continue;
		}

		$files[ $width ] = array(
			'file'   => wp_basename( $saved['path'] ),
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
		);
	}

	remove_filter( 'wp_editor_set_quality', $set_quality, 999 );

	return $files;
}

/**
 * Deletes the files of a cover.
 *
 * @param string $key The key the cover is filed under.
 * @return void
 */
function delete_cover_files( $key ) {
	$dir = get_cover_dir();

	if ( '' === $dir['path'] || ! preg_match( '/^[a-f0-9]{20}$/', $key ) ) {
		return;
	}

	$files = glob( $dir['path'] . '/' . $key . '-*.*' );

	foreach ( $files ? $files : array() as $file ) {
		wp_delete_file( $file );
	}
}

/**
 * Notes that a block has shown a cover, so that it is kept.
 *
 * The notes are written down once, when the page is done, and only for
 * covers that have not been noted in the last day.
 *
 * @param string $key Optional. The key the cover is filed under. Without it, the notes are written down.
 * @return void
 */
function note_cover_use( $key = '' ) {
	static $used = array();

	if ( '' !== $key ) {
		if ( ! $used ) {
			add_action( 'shutdown', __NAMESPACE__ . '\note_cover_use', 10, 0 );
		}

		$used[ $key ] = true;

		return;
	}

	$records = cover_records();
	$changed = false;

	foreach ( array_keys( $used ) as $used_key ) {
		if ( isset( $records[ $used_key ] ) && ( empty( $records[ $used_key ]['used'] ) || time() - $records[ $used_key ]['used'] > DAY_IN_SECONDS ) ) {
			$records[ $used_key ]['used'] = time();
			$changed                      = true;
		}
	}

	if ( $changed ) {
		cover_records( $records );
	}

	$used = array();
}

/**
 * Puts a cover in line to be saved in the background.
 *
 * The line is written down once, when the page is done.
 *
 * @param string $url Optional. The cover's address on Hardcover. Without it, the line is written down.
 * @return void
 */
function queue_cover( $url = '' ) {
	static $waiting = array();

	if ( '' !== $url ) {
		if ( ! $waiting ) {
			add_action( 'shutdown', __NAMESPACE__ . '\queue_cover', 10, 0 );
		}

		$waiting[ get_cover_key( $url ) ] = $url;

		return;
	}

	if ( ! $waiting ) {
		return;
	}

	$queue = get_option( ENDPAPER_COVER_QUEUE_OPTION, array() );
	$queue = is_array( $queue ) ? $queue : array();
	$added = array_diff_key( $waiting, $queue );

	if ( $added ) {
		update_option( ENDPAPER_COVER_QUEUE_OPTION, $queue + $added, false );
	}

	if ( ! wp_next_scheduled( 'endpaper_save_queued_covers' ) ) {
		wp_schedule_single_event( time(), 'endpaper_save_queued_covers' );
	}

	$waiting = array();
}

/**
 * Tells how many covers are waiting to be saved, and whether they seem stuck.
 *
 * The job that saves them is queued the moment a cover is first needed. When it
 * is still waiting ten minutes later, WordPress's scheduled tasks are not running.
 *
 * @return array The number of covers waiting, and whether the job is overdue.
 */
function get_cover_queue_status() {
	$queue = get_option( ENDPAPER_COVER_QUEUE_OPTION, array() );
	$queue = is_array( $queue ) ? $queue : array();

	// A cover the editor has saved or given up on in the meantime is not waiting.
	$records = cover_records();
	$waiting = 0;

	foreach ( array_keys( $queue ) as $key ) {
		if ( empty( $records[ $key ]['files'] ) && empty( $records[ $key ]['failed'] ) ) {
			++$waiting;
		}
	}

	$next = wp_next_scheduled( 'endpaper_save_queued_covers' );

	return array(
		'waiting' => $waiting,
		'stuck'   => $waiting > 0 && $next && $next < time() - 10 * MINUTE_IN_SECONDS,
	);
}

/**
 * Saves the covers that are waiting, for up to twenty seconds, and comes back for the rest.
 *
 * @return void
 */
function save_queued_covers() {
	$queue = get_option( ENDPAPER_COVER_QUEUE_OPTION, array() );
	$queue = is_array( $queue ) ? $queue : array();
	$start = time();

	foreach ( $queue as $key => $url ) {
		if ( time() - $start > 20 ) {
			break;
		}

		$records = cover_records();

		// Saved or tried already, perhaps by someone who can edit.
		if ( empty( $records[ $key ]['files'] ) && empty( $records[ $key ]['failed'] ) ) {
			import_cover( $url );
		}

		unset( $queue[ $key ] );
	}

	if ( $queue ) {
		update_option( ENDPAPER_COVER_QUEUE_OPTION, $queue, false );
		wp_schedule_single_event( time() + 30, 'endpaper_save_queued_covers' );
	} else {
		delete_option( ENDPAPER_COVER_QUEUE_OPTION );
	}
}
add_action( 'endpaper_save_queued_covers', __NAMESPACE__ . '\save_queued_covers' );

/**
 * Removes the covers no block has shown for a month, and files that belong to no cover.
 *
 * @return void
 */
function prune_covers() {
	$records = cover_records( null, true );
	$kept    = array();
	$names   = array();

	foreach ( $records as $key => $record ) {
		if ( empty( $record['files'] ) ) {
			// A note of a failed download. Kept for the hour it stops another try, then dropped.
			if ( ! empty( $record['failed'] ) && time() - $record['failed'] < HOUR_IN_SECONDS ) {
				$kept[ $key ] = $record;
			}

			continue;
		}

		if ( ! empty( $record['used'] ) && time() - $record['used'] < MONTH_IN_SECONDS ) {
			$kept[ $key ] = $record;

			foreach ( $record['files'] as $file ) {
				$names[ $file['file'] ] = true;
			}

			continue;
		}

		delete_cover_files( $key );
	}

	if ( count( $kept ) !== count( $records ) ) {
		cover_records( $kept );
	}

	$dir   = get_cover_dir();
	$files = '' !== $dir['path'] ? glob( $dir['path'] . '/*-*.*' ) : array();

	foreach ( $files ? $files : array() as $file ) {
		if ( ! isset( $names[ wp_basename( $file ) ] ) ) {
			wp_delete_file( $file );
		}
	}
}
add_action( 'endpaper_prune_covers', __NAMESPACE__ . '\prune_covers' );

/**
 * Sees to it that the covers are gone through once a day.
 *
 * @return void
 */
function schedule_cover_pruning() {
	if ( ! wp_next_scheduled( 'endpaper_prune_covers' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'endpaper_prune_covers' );
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\schedule_cover_pruning' );

/**
 * Stops the plugin's background jobs.
 *
 * @return void
 */
function unschedule_cover_jobs() {
	wp_clear_scheduled_hook( 'endpaper_prune_covers' );
	wp_clear_scheduled_hook( 'endpaper_save_queued_covers' );
	wp_unschedule_hook( 'endpaper_refresh' );
}

/**
 * Deletes every saved cover. They are saved again when a block next shows them.
 *
 * @return void
 */
function delete_all_covers() {
	$dir   = get_cover_dir();
	$files = '' !== $dir['path'] ? glob( $dir['path'] . '/*-*.*' ) : array();

	foreach ( $files ? $files : array() as $file ) {
		wp_delete_file( $file );
	}

	cover_records( array() );
	delete_option( ENDPAPER_COVER_QUEUE_OPTION );
}

/**
 * Counts the saved covers and the space they take.
 *
 * @return array The number of "covers", and their "bytes" on disk.
 */
function get_cover_stats() {
	$dir   = get_cover_dir();
	$count = 0;
	$bytes = 0;

	foreach ( cover_records() as $record ) {
		if ( empty( $record['files'] ) ) {
			continue;
		}

		++$count;

		foreach ( $record['files'] as $file ) {
			$path   = $dir['path'] . '/' . $file['file'];
			$bytes += file_exists( $path ) ? (int) filesize( $path ) : 0;
		}
	}

	return array(
		'covers' => $count,
		'bytes'  => $bytes,
	);
}

/**
 * Outputs a cover, or a placeholder where there is none to show.
 *
 * A cover that is being saved gets the placeholder too, until it is there.
 * The image has no alternative text, since the title is next to it.
 *
 * @param string $url   The cover's address on Hardcover.
 * @param string $sizes How wide the cover is shown, as the sizes attribute of an image.
 * @return void
 */
function the_cover_image( $url, $sizes ) {
	$cover = get_cover( $url );

	if ( ! $cover ) {
		echo '<span class="endpaper-cover-placeholder"></span>';

		return;
	}

	if ( ! empty( $cover['remote'] ) ) {
		// Loaded from Hardcover, without telling them which page it is on.
		printf( '<img src="%s" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" />', esc_url( $cover['src'] ) );

		return;
	}

	printf(
		'<img src="%1$s" srcset="%2$s" sizes="%3$s" width="%4$d" height="%5$d" alt="" loading="lazy" decoding="async" />',
		esc_url( $cover['src'] ),
		esc_attr( $cover['srcset'] ),
		esc_attr( $sizes ),
		absint( $cover['width'] ),
		absint( $cover['height'] )
	);
}
