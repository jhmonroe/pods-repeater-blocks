<?php
/**
 * Enqueues the editor notice script.
 *
 * @package PodsRepeaterBlocks
 */

namespace PodsRepeaterBlocks;

defined( 'ABSPATH' ) || exit;

class Editor {

	public function hook(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		wp_enqueue_script(
			'pods-repeater-blocks-editor',
			PODS_REPEATER_BLOCKS_URL . 'assets/editor.js',
			[
				'wp-hooks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-compose',
				'wp-data',
				'wp-i18n',
			],
			PODS_REPEATER_BLOCKS_VER,
			true
		);
	}
}
