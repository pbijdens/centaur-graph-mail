/**
 * Editor side of the centaur/outlook-calendar block. The block is rendered on the server (also as the
 * editor preview), so nothing is saved in the post content except the attributes.
 */
( function ( blocks, element, blockEditor, components, ServerSideRender, i18n ) {
	const el = element.createElement;
	const __ = i18n.__;

	blocks.registerBlockType( 'centaur/outlook-calendar', {
		edit( { attributes, setAttributes } ) {
			const toggle = ( name, label, help ) =>
				el( components.ToggleControl, {
					label,
					help,
					checked: attributes[ name ],
					onChange: ( value ) => setAttributes( { [ name ]: value } ),
					__nextHasNoMarginBottom: true,
				} );


			return el(
				element.Fragment,
				null,
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Display', 'centaur-graph-mail' ) },
						toggle(
							'showMonths',
							__( 'Show month calendars', 'centaur-graph-mail' ),
							__( 'This month and next, with the days that have appointments marked.', 'centaur-graph-mail' )
						),
						toggle( 'showLocation', __( 'Show location', 'centaur-graph-mail' ) ),
						toggle(
							'showCategories',
							__( 'Show categories', 'centaur-graph-mail' ),
							__( 'As tags in the category color, below the title.', 'centaur-graph-mail' )
						),
						toggle( 'showDescription', __( 'Show description', 'centaur-graph-mail' ) ),
						el(
							'p',
							{ className: 'components-base-control__help' },
							__( 'Calendars and ignored appointments are configured on Settings → Graph Mail.', 'centaur-graph-mail' )
						)
					)
				),
				el(
					'div',
					blockEditor.useBlockProps(),
					el( ServerSideRender, { block: 'centaur/outlook-calendar', attributes } )
				)
			);
		},
		save: () => null,
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.serverSideRender, window.wp.i18n );
