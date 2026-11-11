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

defined( 'ABSPATH' ) || exit;

define( 'ENDPAPER_VERSION', '0.1.0' );
define( 'ENDPAPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENDPAPER_API_URL', 'https://api.hardcover.app/v1/graphql' );

require_once ENDPAPER_DIR . 'includes/api.php';
require_once ENDPAPER_DIR . 'includes/rest.php';

/**
 * Registers the Endpaper blocks.
 *
 * @return void
 */
function endpaper_register_blocks() {
	register_block_type( ENDPAPER_DIR . 'blocks/book' );
}
add_action( 'init', 'endpaper_register_blocks' );
