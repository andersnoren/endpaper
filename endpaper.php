<?php
/**
 * Plugin Name:       Endpaper – Book Blocks for Hardcover
 * Description:       Show books from Hardcover in your posts and pages: a single book as a card, the books you are reading from your own library, or a reading goal. An unofficial plugin, not maintained or endorsed by Hardcover.
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

namespace Endpaper;

defined( 'ABSPATH' ) || exit;

define( 'ENDPAPER_VERSION', '1.0.0' );
define( 'ENDPAPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ENDPAPER_API_URL', 'https://api.hardcover.app/v1/graphql' );

require_once ENDPAPER_DIR . 'includes/api.php';
require_once ENDPAPER_DIR . 'includes/helpers.php';
require_once ENDPAPER_DIR . 'includes/covers.php';
require_once ENDPAPER_DIR . 'includes/colors.php';
require_once ENDPAPER_DIR . 'includes/patterns.php';
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
			add_editor_data( $block_type );
		}
	}
}
add_action( 'init', __NAMESPACE__ . '\register_blocks' );

/**
 * Registers the Card style of the Book block.
 *
 * The style comes with its settings as data, so they show in the site
 * editor under Styles, Blocks, Book, and a theme can change them there or in
 * its own style variation file. Themes that cannot use these settings get the
 * same values from the block's stylesheet, so keep the two in step.
 *
 * @return void
 */
function register_block_styles() {
	register_block_style(
		'endpaper/book',
		array(
			'name'       => 'card',
			'label'      => __( 'Card', 'endpaper' ),
			'style_data' => array(
				'color'   => array(
					'background' => 'color-mix(in srgb, currentcolor 5%, transparent)',
				),
				'border'  => array(
					'radius' => '0.75em',
				),
				'spacing' => array(
					'padding' => '1.5em',
				),
			),
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\register_block_styles', 11 );

/**
 * Tells a block's editor script whether the API key works.
 *
 * The block shows a message with a link to the settings, instead of a search
 * that cannot work, when there is no usable key. The link is only given to
 * people who can open the settings.
 *
 * @param \WP_Block_Type $block_type The registered block.
 * @return void
 */
function add_editor_data( $block_type ) {
	$handle = $block_type->editor_script_handles[0] ?? '';

	if ( ! $handle ) {
		return;
	}

	// Looked up when the editor loads, not on every request.
	add_action(
		'enqueue_block_editor_assets',
		function () use ( $handle ) {
			$data = array(
				'keyTrouble'       => get_token_trouble(),
				'settingsUrl'      => current_user_can( 'manage_options' ) ? get_settings_url() : '',
				'defaultBookStyle' => get_default_book_style(),
			);

			wp_add_inline_script( $handle, 'window.endpaperEditor = ' . wp_json_encode( $data ) . ';', 'before' );
		}
	);
}

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
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\unschedule_cover_jobs' );
