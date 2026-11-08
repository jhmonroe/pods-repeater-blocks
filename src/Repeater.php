<?php
/**
 * Expands a single bound child block into one child per value.
 *
 * HOW IT WORKS
 * ------------
 * The Block Bindings API resolves one scalar per attribute, so a multi-value
 * Pods field bound to a single `core/list-item` or `core/button` can only
 * render one item. There is no core "repeat this block" primitive.
 *
 * So this works in two coordinated halves:
 *
 * 1. `render_block_data` on the PARENT block (core/list, core/buttons):
 *    if a direct child has a `pods/bindings-field` binding whose field holds
 *    N values, that child's parsed-block array is duplicated N times. Each
 *    clone is stamped with a private `_podsRepeaterIndex` attribute.
 *
 * 2. `block_bindings_source_value` on each CHILD render: the index stamped on
 *    the clone selects which of the N values that particular clone resolves
 *    to, instead of Pods' default `display()` output (which would otherwise
 *    emit the whole `<ul>` inside every single item).
 *
 * Single-value fields are left completely untouched and fall through to
 * Pods' normal behaviour.
 *
 * @package PodsRepeaterBlocks
 */

namespace PodsRepeaterBlocks;

use WP_Block;

defined( 'ABSPATH' ) || exit;

class Repeater {

	/**
	 * Parent block => the child block name that should be repeated inside it.
	 *
	 * @var array<string, string>
	 */
	private const CONTAINERS = [
		'core/list'       => 'core/list-item',
		'core/buttons'    => 'core/button',
		'core/gallery'    => 'core/image',
		// Only `url` is bindable on navigation-link by default — the link
		// label is NOT a bindable attribute in core, so repeated nav items
		// render with empty text unless the `block_bindings_supported_attributes`
		// filter (WP 6.9+) is used to add `label`.
		'core/navigation' => 'core/navigation-link',
	];

	/**
	 * Private attribute used to tell a cloned child which value index it owns.
	 */
	private const INDEX_ATTR = '_podsRepeaterIndex';

	public function hook(): void {
		add_filter( 'render_block_data', [ $this, 'expand_children' ], 10, 3 );
		add_filter( 'block_bindings_source_value', [ $this, 'resolve_indexed_value' ], 10, 5 );
	}

	/**
	 * Duplicate a bound child block once per field value.
	 *
	 * @param array         $parsed_block The block being rendered.
	 * @param array         $source_block Pre-filter copy of the block.
	 * @param WP_Block|null $parent_block Parent block instance, if nested.
	 * @return array
	 */
	public function expand_children( $parsed_block, $source_block, $parent_block ) {
		$block_name = $parsed_block['blockName'] ?? '';

		if ( ! isset( self::CONTAINERS[ $block_name ] ) ) {
			return $parsed_block;
		}

		if ( empty( $parsed_block['innerBlocks'] ) || ! is_array( $parsed_block['innerBlocks'] ) ) {
			return $parsed_block;
		}

		// Guard against re-entry: once expanded, the clones carry an index.
		foreach ( $parsed_block['innerBlocks'] as $child ) {
			if ( isset( $child['attrs'][ self::INDEX_ATTR ] ) ) {
				return $parsed_block;
			}
		}

		$expected_child = self::CONTAINERS[ $block_name ];
		$context        = $this->resolve_context( $parent_block );

		if ( empty( $context['postId'] ) ) {
			return $parsed_block;
		}

		$new_inner_blocks  = [];
		$new_inner_content = [];
		$changed           = false;

		// innerContent interleaves literal HTML chunks (strings) with nulls
		// marking inner-block positions; it must stay in sync with innerBlocks.
		$child_index = 0;
		foreach ( $parsed_block['innerContent'] as $chunk ) {
			if ( is_string( $chunk ) ) {
				$new_inner_content[] = $chunk;
				continue;
			}

			$child = $parsed_block['innerBlocks'][ $child_index ] ?? null;
			++$child_index;

			if ( ! $child ) {
				$new_inner_content[] = null;
				continue;
			}

			$binding = $this->find_binding( $child, $expected_child );

			if ( ! $binding ) {
				$new_inner_blocks[]  = $child;
				$new_inner_content[] = null;
				continue;
			}

			$values = Values::get_list( $binding['args'], $context );

			// 0 or 1 values: leave the original block alone entirely.
			if ( count( $values ) < 2 ) {
				$new_inner_blocks[]  = $child;
				$new_inner_content[] = null;
				continue;
			}

			foreach ( array_keys( $values ) as $i ) {
				$clone = $child;
				$clone['attrs'][ self::INDEX_ATTR ] = $i;

				$new_inner_blocks[]  = $clone;
				$new_inner_content[] = null;
			}

			$changed = true;
		}

		if ( ! $changed ) {
			return $parsed_block;
		}

		$parsed_block['innerBlocks']  = $new_inner_blocks;
		$parsed_block['innerContent'] = $new_inner_content;

		return $parsed_block;
	}

	/**
	 * Feed each cloned child its own value from the multi-value field.
	 *
	 * @param mixed    $value          Value Pods' callback produced.
	 * @param string   $name           Binding source name.
	 * @param array    $source_args    Binding args.
	 * @param WP_Block $block_instance Block instance being rendered.
	 * @param string   $attribute_name Bound attribute.
	 * @return mixed
	 */
	public function resolve_indexed_value( $value, $name, $source_args, $block_instance, $attribute_name ) {
		if ( SOURCE_NAME !== $name || ! $block_instance instanceof WP_Block ) {
			return $value;
		}

		$index = $block_instance->attributes[ self::INDEX_ATTR ] ?? null;
		if ( null === $index ) {
			return $value;
		}

		$values = Values::get_list( (array) $source_args, (array) $block_instance->context );
		if ( ! isset( $values[ $index ] ) ) {
			return $value;
		}

		return $values[ $index ];
	}

	/**
	 * Find a Pods binding on a child block, if it is the expected child type.
	 *
	 * Prefers the attribute that carries the item's visible text, so a button
	 * bound on both `url` and `text` repeats on a consistent field.
	 *
	 * @param array  $child          Parsed child block.
	 * @param string $expected_child Required block name.
	 * @return array{attribute: string, args: array}|null
	 */
	private function find_binding( array $child, string $expected_child ): ?array {
		if ( ( $child['blockName'] ?? '' ) !== $expected_child ) {
			return null;
		}

		$bindings = $child['attrs']['metadata']['bindings'] ?? null;
		if ( ! is_array( $bindings ) ) {
			return null;
		}

		// Check text-bearing attributes first, then anything else bound.
		$preferred = [ 'content', 'text', 'url' ];
		$ordered   = array_merge(
			array_intersect( $preferred, array_keys( $bindings ) ),
			array_diff( array_keys( $bindings ), $preferred )
		);

		foreach ( $ordered as $attribute ) {
			$binding = $bindings[ $attribute ] ?? null;

			if ( ! is_array( $binding ) ) {
				continue;
			}

			if ( ( $binding['source'] ?? '' ) !== SOURCE_NAME ) {
				continue;
			}

			if ( empty( $binding['args']['field'] ) ) {
				continue;
			}

			return [
				'attribute' => $attribute,
				'args'      => $binding['args'],
			];
		}

		return null;
	}

	/**
	 * Work out the post context for the container block.
	 *
	 * Note: we deliberately do NOT construct a throwaway WP_Block to read
	 * context from — WP_Block only populates `->context` for keys the block
	 * type declares in `uses_context`, and core/list and core/buttons declare
	 * none, so it would always come back empty.
	 *
	 * @param WP_Block|null $parent_block Parent instance, when nested.
	 * @return array{postId?: int, postType?: string}
	 */
	private function resolve_context( $parent_block ): array {
		$context = [];

		if ( $parent_block instanceof WP_Block ) {
			// Inherit loop context (e.g. inside a Query Loop / post template).
			foreach ( [ 'postId', 'postType' ] as $key ) {
				if ( ! empty( $parent_block->context[ $key ] ) ) {
					$context[ $key ] = $parent_block->context[ $key ];
				}
			}
		}

		if ( empty( $context['postId'] ) ) {
			$post_id = get_the_ID();
			if ( $post_id ) {
				$context['postId']   = $post_id;
				$context['postType'] = get_post_type( $post_id );
			}
		}

		return $context;
	}
}
