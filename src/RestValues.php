<?php
/**
 * Exposes raw (un-flattened) multi-value Pods field values to the editor.
 *
 * WHY THIS EXISTS
 * ---------------
 * Pods registers its fields as single-value post meta, so the standard REST
 * `meta` object flattens a multi-value field down to its FIRST value only
 * (e.g. a checklist holding "test 1" + "test 2" arrives as just "test 1").
 * The editor therefore cannot tell a single-value field from a multi-value
 * one, which makes an accurate repeater preview impossible.
 *
 * Registering our own REST field sidesteps that: the raw arrays land inside
 * `getEditedEntityRecord()` alongside `meta`, readable synchronously by the
 * editor with no extra fetch or data-store resolver.
 *
 * It is also independent of the Pods "manually edit custom fields" /
 * "register meta fields" settings, because we populate the values ourselves
 * rather than relying on Pods' own `register_meta()` calls.
 *
 * @package PodsRepeaterBlocks
 */

namespace PodsRepeaterBlocks;

defined( 'ABSPATH' ) || exit;

class RestValues {

	/**
	 * REST field name added to each Pods-backed post type.
	 */
	public const FIELD = 'pods_repeater_values';

	public function hook(): void {
		add_action( 'rest_api_init', [ $this, 'register' ] );
		// The editor's entity record is primed from the same controllers, so
		// registering on init as well keeps parity for internal REST calls.
		add_action( 'init', [ $this, 'register' ], 20 );
	}

	/**
	 * Register the REST field for every post-type pod.
	 */
	public function register(): void {
		foreach ( $this->post_type_pods() as $post_type ) {
			register_rest_field(
				$post_type,
				self::FIELD,
				[
					'get_callback' => [ $this, 'get_values' ],
					'schema'       => [
						'description' => __( 'Raw Pods field values, with multi-value fields kept as arrays.', 'pods-repeater-blocks' ),
						'type'        => 'object',
						'context'     => [ 'view', 'edit' ],
						'readonly'    => true,
					],
				]
			);
		}
	}

	/**
	 * Build the raw value map for one post.
	 *
	 * Only multi-value results are included — single-value fields are already
	 * available via `meta` and would just bloat every REST response.
	 *
	 * @param array $post Post data from the REST controller.
	 * @return array<string, array>
	 */
	public function get_values( $post ): array {
		$post_id   = $post['id'] ?? 0;
		$post_type = $post['type'] ?? '';

		if ( ! $post_id || ! $post_type ) {
			return [];
		}

		$pod = pods( $post_type, $post_id );
		if ( ! is_object( $pod ) || ! $pod->exists() ) {
			return [];
		}

		$fields = $pod->fields();
		if ( ! $fields ) {
			return [];
		}

		// `fields()` also returns WordPress object fields (post_title, guid,
		// _thumbnail_id, …). Only user-defined custom fields are relevant,
		// and underscore-prefixed keys are protected meta in any case.
		$pod_data      = $pod->pod_data;
		$custom_fields = $pod_data ? $pod_data->get_fields() : [];
		$allowed       = [];

		foreach ( $custom_fields as $custom_field ) {
			$custom_name = is_object( $custom_field )
				? $custom_field->get_name()
				: ( $custom_field['name'] ?? '' );

			if ( $custom_name && '_' !== $custom_name[0] ) {
				$allowed[ $custom_name ] = true;
			}
		}

		if ( ! $allowed ) {
			return [];
		}

		$values = [];

		foreach ( $allowed as $name => $unused ) {
			$list = Values::get_list(
				[ 'field' => $name ],
				[
					'postId'   => $post_id,
					'postType' => $post_type,
				]
			);

			// Only ship fields that actually hold more than one value.
			if ( count( $list ) > 1 ) {
				$values[ $name ] = $list;
			}
		}

		return $values;
	}

	/**
	 * Names of all post types that have a Pod attached.
	 *
	 * @return string[]
	 */
	private function post_type_pods(): array {
		if ( ! function_exists( 'pods_api' ) ) {
			return [];
		}

		$pods = pods_api()->load_pods( [ 'type' => 'post_type' ] );
		if ( ! $pods ) {
			return [];
		}

		$names = [];

		foreach ( $pods as $pod ) {
			$name = is_object( $pod ) ? $pod->get_name() : ( $pod['name'] ?? '' );
			if ( $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}
}
