/**
 * Membership panel for WordPress blocks: who sees the block, for which plans, and what
 * everyone else gets. Saved as block attributes; FW_Membership_Blocks applies them when
 * the block renders. Plain script on the wp.* globals, no build step.
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = ( wp.blockEditor || wp.editor ).InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var CheckboxControl = wp.components.CheckboxControl;
	var t = data.l10n;

	function skip( name ) {
		return ! name || 0 === name.indexOf( 'unysonplus/' );
	}

	/* Client-registered blocks need the attributes too, or the editor drops them. */
	wp.hooks.addFilter( 'blocks.registerBlockType', 'unysonplus/membership/attributes', function ( settings, name ) {
		if ( skip( name ) ) {
			return settings;
		}
		settings.attributes = Object.assign( {}, settings.attributes, {
			fwMbrShow: { type: 'string', 'default': 'everyone' },
			fwMbrPlans: { type: 'array', 'default': [], items: { type: 'number' } },
			fwMbrDenied: { type: 'string', 'default': 'nothing' }
		} );
		return settings;
	} );

	var withPanel = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( skip( props.name ) || ! props.isSelected ) {
				return el( BlockEdit, props );
			}
			var a = props.attributes;
			var show = a.fwMbrShow || 'everyone';
			var plans = ( a.fwMbrPlans || [] ).map( Number );
			var usesPlans = 'plans' === show || 'not_plans' === show;

			var planBoxes = data.plans.length
				? data.plans.map( function ( p ) {
					return el( CheckboxControl, {
						key: p.id,
						label: p.name,
						checked: plans.indexOf( Number( p.id ) ) !== -1,
						onChange: function ( on ) {
							var next = plans.filter( function ( id ) { return id !== Number( p.id ); } );
							if ( on ) { next.push( Number( p.id ) ); }
							props.setAttributes( { fwMbrPlans: next } );
						}
					} );
				} )
				: [ el( 'p', { key: 'none' }, t.noPlans ) ];

			return el( Fragment, null,
				el( BlockEdit, props ),
				el( InspectorControls, null,
					el( PanelBody, { title: t.panel, initialOpen: 'everyone' !== show },
						el( SelectControl, {
							label: t.show,
							value: show,
							options: data.choices,
							onChange: function ( v ) { props.setAttributes( { fwMbrShow: v } ); }
						} ),
						usesPlans ? el( 'fieldset', null, el( 'legend', { style: { marginBottom: '8px', fontWeight: 600 } }, t.plans ), planBoxes ) : null,
						'everyone' !== show ? el( SelectControl, {
							label: t.denied,
							value: a.fwMbrDenied || 'nothing',
							options: [ { value: 'nothing', label: t.nothing }, { value: 'prompt', label: t.prompt } ],
							onChange: function ( v ) { props.setAttributes( { fwMbrDenied: v } ); }
						} ) : null
					)
				)
			);
		};
	}, 'withMembershipPanel' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'unysonplus/membership/panel', withPanel );

	/* A small marker on restricted blocks in the editor, so they are easy to spot. */
	wp.hooks.addFilter( 'editor.BlockListBlock', 'unysonplus/membership/marker', wp.compose.createHigherOrderComponent( function ( BlockListBlock ) {
		return function ( props ) {
			var show = props.attributes && props.attributes.fwMbrShow;
			if ( skip( props.name ) || ! show || 'everyone' === show ) {
				return el( BlockListBlock, props );
			}
			return el( BlockListBlock, Object.assign( {}, props, {
				className: ( props.className ? props.className + ' ' : '' ) + 'fw-mbr-block-restricted',
				wrapperProps: Object.assign( {}, props.wrapperProps, { title: t.restricted } )
			} ) );
		};
	}, 'withMembershipMarker' ) );
}( window.wp, window.fwMembershipBlocks ) );
