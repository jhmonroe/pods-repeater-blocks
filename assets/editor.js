/**
 * Editor affordance for Pods repeater expansion.
 *
 * The canvas cannot show N real items: the Block Bindings API resolves one
 * value per attribute for one block instance, and faking N children would
 * make them non-editable. Instead of a misleading preview, this tells the
 * user plainly how many items the block will produce on the front end.
 *
 * Degrades in three steps, mirroring the Pods Block Bindings plugin:
 *   1. Raw values present (via the `pods_repeater_values` REST field)
 *      -> show the real item count.
 *   2. Bound to a Pods field but no multi-value data for this post
 *      -> say it renders a single item.
 *   3. Not bound to a Pods field at all -> render nothing extra.
 */
( function ( wp ) {
	'use strict';

	const { addFilter } = wp.hooks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, BlockControls } = wp.blockEditor;
	const { PanelBody, Notice, ToolbarGroup, ToolbarButton } = wp.components;
	const { createHigherOrderComponent } = wp.compose;
	const { useSelect } = wp.data;
	const { __, sprintf, _n } = wp.i18n;

	const SOURCE_NAME = 'pods/bindings-field';
	const REST_FIELD = 'pods_repeater_values';

	/**
	 * Parent block -> child block that gets repeated inside it.
	 * Must stay in sync with Repeater::CONTAINERS in PHP.
	 */
	const CONTAINERS = {
		'core/list': 'core/list-item',
		'core/buttons': 'core/button',
		'core/gallery': 'core/image',
		'core/navigation': 'core/navigation-link',
	};

	// Attribute preference order, matching Repeater::find_binding() in PHP.
	const PREFERRED_ATTRS = [ 'content', 'text', 'url', 'alt', 'caption' ];

	/**
	 * Find the Pods binding on a container's first matching child.
	 *
	 * @param {Array}  innerBlocks   Child blocks.
	 * @param {string} expectedChild Required child block name.
	 * @return {{field: string, args: Object}|null} The bound field, if any.
	 */
	function findBinding( innerBlocks, expectedChild ) {
		for ( const child of innerBlocks || [] ) {
			if ( child.name !== expectedChild ) {
				continue;
			}

			const bindings = child.attributes?.metadata?.bindings;
			if ( ! bindings ) {
				continue;
			}

			const keys = Object.keys( bindings );
			const ordered = [
				...PREFERRED_ATTRS.filter( ( a ) => keys.includes( a ) ),
				...keys.filter( ( a ) => ! PREFERRED_ATTRS.includes( a ) ),
			];

			for ( const attr of ordered ) {
				const binding = bindings[ attr ];
				if ( binding?.source === SOURCE_NAME && binding?.args?.field ) {
					return {
						field: binding.args.field,
						args: binding.args,
					};
				}
			}
		}

		return null;
	}

	const withRepeaterNotice = createHigherOrderComponent( ( BlockEdit ) => {
		return ( props ) => {
			const expectedChild = CONTAINERS[ props.name ];

			if ( ! expectedChild ) {
				return el( BlockEdit, props );
			}

			// Hooks must run unconditionally, so this select always happens
			// for container blocks even when no Pods binding is present.
			const info = useSelect(
				( select ) => {
					const { getBlocks, getBlockParentsByBlockName, getBlockAttributes } =
						select( 'core/block-editor' );
					const binding = findBinding(
						getBlocks( props.clientId ),
						expectedChild
					);

					if ( ! binding ) {
						return null;
					}

					// Explicit pod/item in the binding args wins (settings pods).
					if ( binding.args?.name && binding.args?.id ) {
						return {
							field: binding.field,
							count: null,
							scope: 'explicit',
						};
					}

					let postId = null;
					let postType = null;

					// A Query Loop supplies the real context, not the post
					// being edited. Walk up for an enclosing query first.
					const queryParents =
						getBlockParentsByBlockName?.(
							props.clientId,
							'core/query'
						) || [];

					if ( queryParents.length ) {
						const queryAttrs = getBlockAttributes(
							queryParents[ queryParents.length - 1 ]
						);
						postType = queryAttrs?.query?.postType || null;

						if ( postType ) {
							// Preview against the first post the loop would show.
							const posts = select( 'core' ).getEntityRecords(
								'postType',
								postType,
								{ per_page: 1, _fields: 'id' }
							);
							postId = posts?.[ 0 ]?.id || null;
						}
					}

					if ( ! postId ) {
						const { getCurrentPostId, getCurrentPostType } =
							select( 'core/editor' ) || {};

						if ( getCurrentPostId && getCurrentPostType ) {
							postId = getCurrentPostId();
							postType = getCurrentPostType();
						}
					}

					if ( ! postId || ! postType ) {
						return { field: binding.field, count: null };
					}

					const record = select( 'core' ).getEditedEntityRecord(
						'postType',
						postType,
						postId
					);

					const values = record?.[ REST_FIELD ]?.[ binding.field ];

					return {
						field: binding.field,
						count: Array.isArray( values ) ? values.length : null,
						scope: queryParents.length ? 'query' : 'post',
					};
				},
				[ props.clientId, props.name, expectedChild ]
			);

			if ( ! info ) {
				return el( BlockEdit, props );
			}

			let message;

			if ( info.count && info.count > 1 ) {
				message =
					info.scope === 'query'
						? sprintf(
								/* translators: 1: number of items, 2: Pods field name. */
								_n(
									'Repeats %1$d item per post on the front end, from the Pods field “%2$s”. Counted from the first post in this query.',
									'Repeats %1$d items per post on the front end, from the Pods field “%2$s”. Counted from the first post in this query.',
									info.count,
									'pods-repeater-blocks'
								),
								info.count,
								info.field
						  )
						: sprintf(
								/* translators: 1: number of items, 2: Pods field name. */
								_n(
									'Repeats %1$d item on the front end, from the Pods field “%2$s”.',
									'Repeats %1$d items on the front end, from the Pods field “%2$s”.',
									info.count,
									'pods-repeater-blocks'
								),
								info.count,
								info.field
						  );
			} else if ( info.scope === 'explicit' ) {
				message = sprintf(
					/* translators: %s: Pods field name. */
					__(
						'Bound to the Pods field “%s” on a specific item. It repeats on the front end if that field holds multiple values.',
						'pods-repeater-blocks'
					),
					info.field
				);
			} else {
				message = sprintf(
					/* translators: %s: Pods field name. */
					__(
						'Bound to the Pods field “%s”. This item has a single value, so one item renders.',
						'pods-repeater-blocks'
					),
					info.field
				);
			}

			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Pods Repeater', 'pods-repeater-blocks' ),
							initialOpen: true,
						},
						el(
							Notice,
							{ status: 'info', isDismissible: false },
							message
						),
						el(
							'p',
							{ style: { fontSize: '12px', opacity: 0.75, margin: '8px 0 0' } },
							__(
								'The editor shows one item because bound blocks stay individually editable. The full set renders on the front end.',
								'pods-repeater-blocks'
							)
						)
					)
				),
				// Some container blocks (e.g. core/buttons) render no
				// InspectorControls slot of their own, so the panel above can
				// have nowhere to mount. The toolbar badge always shows.
				el(
					BlockControls,
					{ group: 'other' },
					el(
						ToolbarGroup,
						null,
						el(
							ToolbarButton,
							{
								icon: 'controls-repeat',
								label: message,
								showTooltip: true,
								onClick: () => {},
							},
							info.count && info.count > 1
								? sprintf(
										/* translators: %d: number of repeated items. */
										__( '×%d', 'pods-repeater-blocks' ),
										info.count
								  )
								: __( '×1', 'pods-repeater-blocks' )
						)
					)
				)
			);
		};
	}, 'withRepeaterNotice' );

	addFilter(
		'editor.BlockEdit',
		'pods-repeater-blocks/notice',
		withRepeaterNotice
	);
} )( window.wp );
