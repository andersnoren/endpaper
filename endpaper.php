<?php
/**
 * Plugin Name:       Endpaper
 * Description:       Book blocks powered by Hardcover. Proof of concept.
 * Version:           0.1.0
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

define( 'ENDPAPER_VERSION', '0.1.0' );
define( 'ENDPAPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENDPAPER_API_URL', 'https://api.hardcover.app/v1/graphql' );

require_once ENDPAPER_DIR . 'includes/api.php';
require_once ENDPAPER_DIR . 'includes/helpers.php';
require_once ENDPAPER_DIR . 'includes/colors.php';
require_once ENDPAPER_DIR . 'includes/rest.php';

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
		register_block_type( dirname( $block ) );
	}
}
add_action( 'init', __NAMESPACE__ . '\register_blocks' );
