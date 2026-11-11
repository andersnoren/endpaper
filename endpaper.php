<?php
/**
 * Plugin Name:       Endpaper
 * Description:       Show books from Hardcover in your posts and pages: a single book as a card, or the books you are reading from your own library.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Anders Norén
 * Author URI:        https://andersnoren.se
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       endpaper
 *
 * @package Endpaper
 */

namespace AndersNoren\Endpaper;

defined( 'ABSPATH' ) || exit;

define( 'ENDPAPER_VERSION', '1.0.0' );
define( 'ENDPAPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENDPAPER_API_URL', 'https://api.hardcover.app/v1/graphql' );

require_once ENDPAPER_DIR . 'includes/api.php';
require_once ENDPAPER_DIR . 'includes/helpers.php';
require_once ENDPAPER_DIR . 'includes/colors.php';
require_once ENDPAPER_DIR . 'includes/rest.php';
require_once ENDPAPER_DIR . 'includes/theme-json.php';

if ( is_admin() ) {
	require_once ENDPAPER_DIR . 'includes/settings.php';
}

/**
 * Registers the Endpaper blocks.
 *
 * Every folder in build/blocks/ with a block.json file is registered as a block.
 * The build folder is generated from src/ by `npm run build`.
 *
 * @return void
 */
function register_blocks() {
	$blocks = glob( ENDPAPER_DIR . 'build/blocks/*/block.json' );

	if ( ! $blocks ) {
		return;
	}

	foreach ( $blocks as $block ) {
		$block_type = register_block_type( dirname( $block ) );

		if ( $block_type ) {
			set_block_style_versions( $block_type, dirname( $block ) . '/index.asset.php' );
		}
	}
}
add_action( 'init', __NAMESPACE__ . '\register_blocks' );

/**
 * Gives a block's stylesheets the version of its latest build.
 *
 * WordPress versions a block's stylesheets by the version in block.json,
 * which only changes when someone remembers to change it. Browsers would
 * then keep showing an old stylesheet after an update. The build writes a
 * new version to index.asset.php every time anything changes, so that is
 * used instead.
 *
 * @param \WP_Block_Type $block_type The registered block.
 * @param string         $asset_file Path to the block's index.asset.php.
 * @return void
 */
function set_block_style_versions( $block_type, $asset_file ) {
	if ( ! is_readable( $asset_file ) ) {
		return;
	}

	$asset = include $asset_file;

	if ( ! is_array( $asset ) || empty( $asset['version'] ) ) {
		return;
	}

	$styles = wp_styles();

	foreach ( array_merge( $block_type->style_handles, $block_type->editor_style_handles ) as $handle ) {
		if ( isset( $styles->registered[ $handle ] ) ) {
			$styles->registered[ $handle ]->ver = $asset['version'];
		}
	}
}

/**
 * Notes that the plugin was just activated, so the next screen can be the settings screen.
 *
 * Not when it is activated for a whole network or from the command line,
 * where nobody is waiting to be sent anywhere.
 *
 * @param bool $network_wide Whether the plugin is being activated for all sites in a network.
 * @return void
 */
function set_activation_redirect( $network_wide ) {
	if ( $network_wide || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	set_transient( 'endpaper_activation_redirect', 1, 30 );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\set_activation_redirect' );
