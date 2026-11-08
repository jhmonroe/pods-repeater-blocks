<?php
/**
 * Resolves raw (un-joined, un-wrapped) Pods field values for a block context.
 *
 * Pods' own binding callback uses `pods()->display()`, which pre-formats
 * multi-value fields into an `<ul><li>…</li></ul>` string. That's fine for a
 * paragraph but wrong inside a list-item or button, so this reads the
 * underlying array instead via `pods()->field()`.
 *
 * @package PodsRepeaterBlocks
 */

namespace PodsRepeaterBlocks;

defined( 'ABSPATH' ) || exit;

class Values {

	/**
	 * Simple per-request memo so repeating a block N times doesn't re-query
	 * the same pod field N times.
	 *
	 * @var array<string, array>
	 */
	private static array $cache = [];

	/**
	 * Resolve the pod name + item ID, mirroring how Pods' own Field block
	 * resolves context (explicit args win, else post context).
	 *
	 * @param array $source_args Binding args (field, name, id/slug).
	 * @param array $context     Block context with postId / postType.
	 * @return array{0: string|null, 1: int|string|null} [ $pod_name, $item_id ]
	 */
	public static function resolve_target( array $source_args, array $context ): array {
		// Explicit pod/item in the binding args (as the editor picker writes
		// for settings pods, e.g. {"name":"sitewide","id":"168"}).
		$pod_name = $source_args['name'] ?? '';
		$item_id  = $source_args['id'] ?? $source_args['slug'] ?? '';

		if ( $pod_name && $item_id ) {
			return [ $pod_name, $item_id ];
		}

		$context_post_id   = $context['postId'] ?? 0;
		$context_post_type = $context['postType'] ?? '';

		if ( ! $pod_name ) {
			$pod_name = $context_post_type ?: ( $context_post_id ? get_post_type( $context_post_id ) : '' );
		}

		if ( ! $item_id ) {
			$item_id = $context_post_id;
		}

		return [ $pod_name ?: null, $item_id ?: null ];
	}

	/**
	 * Get the raw value for a bound field as a list.
	 *
	 * @param array $source_args Binding args; `field` is the field name.
	 * @param array $context     Block context with postId / postType.
	 * @return array<int, string> Zero, one, or many values.
	 */
	public static function get_list( array $source_args, array $context ): array {
		$field_name = $source_args['field'] ?? '';
		if ( ! $field_name ) {
			return [];
		}

		[ $pod_name, $item_id ] = self::resolve_target( $source_args, $context );
		if ( ! $pod_name || ! $item_id ) {
			return [];
		}

		$cache_key = $pod_name . '|' . $item_id . '|' . $field_name;
		if ( array_key_exists( $cache_key, self::$cache ) ) {
			return self::$cache[ $cache_key ];
		}

		$values = [];

		$pod = pods( $pod_name, $item_id );
		if ( is_object( $pod ) && $pod->exists() ) {
			// field() returns the underlying array for repeatable fields and
			// multi-select relationships, or a scalar for single-value fields.
			$raw = $pod->field( $field_name );

			if ( self::is_value_list( $raw ) ) {
				// A relationship may return arrays of item data rather than
				// scalars; reduce each entry to something renderable.
				foreach ( $raw as $entry ) {
					$scalar = self::to_scalar( $entry );
					if ( null !== $scalar && '' !== $scalar ) {
						$values[] = $scalar;
					}
				}
			} else {
				$scalar = self::to_scalar( $raw );
				if ( null !== $scalar && '' !== $scalar ) {
					$values[] = $scalar;
				}
			}
		}

		self::$cache[ $cache_key ] = $values;

		return $values;
	}

	/**
	 * Whether a raw field value is a LIST of values rather than a single
	 * structured value.
	 *
	 * This distinction matters: Pods returns a flat list for repeatable and
	 * multi-select fields, but a single associative array describing ONE item
	 * for file/image and single relationship fields (keys like ID, guid,
	 * post_title…). Treating the latter as a list explodes one attachment
	 * into a dozen bogus "values".
	 *
	 * @param mixed $value Raw value from pods()->field().
	 */
	private static function is_value_list( $value ): bool {
		if ( ! is_array( $value ) || [] === $value ) {
			return false;
		}

		// Associative array => a single structured item, not a list.
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Reduce one field value to a renderable scalar.
	 *
	 * @param mixed $value Scalar, or a relationship/file item array/object.
	 * @return string|null
	 */
	private static function to_scalar( $value ): ?string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		// Common Pods shapes, most specific first.
		foreach ( [ 'guid', 'post_title', 'name', 'title', 'ID', 'id' ] as $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
				return (string) $value[ $key ];
			}
		}

		return null;
	}

	/**
	 * Clear the per-request memo.
	 */
	public static function flush(): void {
		self::$cache = [];
	}
}
