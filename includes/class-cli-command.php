<?php
/**
 * WP-CLI commands for Endpaper.
 *
 * @package Endpaper
 */

namespace Endpaper;

defined( 'ABSPATH' ) || exit;

/**
 * Manages Endpaper from the command line.
 */
class CLI_Command {

	/**
	 * Clears everything fetched from Hardcover, covers included, so that it is fetched again.
	 *
	 * The API key stays. Nothing is cleared while the key is missing or turned
	 * down by Hardcover, since the books on the site are shown from the cache
	 * and would be gone until a key works again. This is the same as the
	 * "Clear cache" button on the settings screen.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Clear the cache even if there is no working key.
	 *
	 * ## EXAMPLES
	 *
	 *     # Fetch everything from Hardcover again.
	 *     wp endpaper clear-cache
	 *
	 * @subcommand clear-cache
	 *
	 * @param array $args       Positional arguments. None are used.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function clear_cache( $args, $assoc_args ) {
		$trouble = get_token_trouble();

		if ( '' !== $trouble && empty( $assoc_args['force'] ) ) {
			\WP_CLI::error(
				'missing' === $trouble
					? 'There is no API key, so the books on the site would be gone until one is saved. Use --force to clear the cache anyway.'
					: 'Hardcover has turned down the API key, so the books on the site would be gone until a working one is saved. Use --force to clear the cache anyway.'
			);
		}

		\Endpaper\clear_cache();

		\WP_CLI::success( 'The cache is cleared. Books are fetched from Hardcover again as they are shown.' );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'endpaper', __NAMESPACE__ . '\CLI_Command' );
}
