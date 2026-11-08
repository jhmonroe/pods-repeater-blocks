<?php
/**
 * Plugin Name:       Pods Repeater Blocks
 * Description:       Expands a single list-item or button bound to a multi-value Pods field (repeatable fields, multi-select / checkbox relationships) into one item per value on the front end.
 * Version:           1.0.0
 * Requires Plugins:  pods
 * Requires PHP:      7.4
 * Author:            Custom
 * License:           GPL-2.0-or-later
 * Text Domain:       pods-repeater-blocks
 *
 * Companion to the "Pods Block Bindings" plugin (which supplies the editor
 * field picker). This one handles the front-end rendering problem that the
 * Block Bindings API cannot solve on its own: a binding resolves to exactly
 * ONE scalar for ONE attribute, so a multi-value Pods field bound to a
 * core/list-item or core/button can only ever produce a single item.
 *
 * @package PodsRepeaterBlocks
 */

namespace PodsRepeaterBlocks;

defined( 'ABSPATH' ) || exit;

define( 'PODS_REPEATER_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PODS_REPEATER_BLOCKS_URL', plugin_dir_url( __FILE__ ) );
define( 'PODS_REPEATER_BLOCKS_VER', '1.0.0' );

/**
 * The binding source name this plugin expands. Matches the source Pods
 * itself registers in src/Pods/WP/Bindings.php.
 */
const SOURCE_NAME = 'pods/bindings-field';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! function_exists( 'pods' ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\missing_pods_notice' );
			return;
		}

		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\missing_wp_notice' );
			return;
		}

		require_once PODS_REPEATER_BLOCKS_DIR . 'src/Values.php';
		require_once PODS_REPEATER_BLOCKS_DIR . 'src/Repeater.php';
		require_once PODS_REPEATER_BLOCKS_DIR . 'src/RestValues.php';
		require_once PODS_REPEATER_BLOCKS_DIR . 'src/Editor.php';

		( new Repeater() )->hook();
		( new RestValues() )->hook();
		( new Editor() )->hook();
	},
	25 // after Pods (and after pods-block-bindings, which uses 20).
);

function missing_pods_notice(): void {
	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'Pods Repeater Blocks requires the Pods plugin to be installed and active.', 'pods-repeater-blocks' );
	echo '</p></div>';
}

function missing_wp_notice(): void {
	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'Pods Repeater Blocks requires WordPress 6.7 or later (Block Bindings value filter).', 'pods-repeater-blocks' );
	echo '</p></div>';
}
