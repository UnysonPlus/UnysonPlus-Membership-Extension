/**
 * Membership screen behaviour: the "/" search shortcut, the New menu, in-place
 * edit panels and auto-submitting pickers.
 *
 * Focus mode and the off-canvas sidebar belong to the app shell and are handled
 * by the framework's admin-app.js — this file must not duplicate them.
 */
( function () {
	'use strict';

	var app = document.getElementById( 'fw-app' );
	if ( ! app ) {
		return;
	}


	/* The New menu closes on an outside click or Escape. */
	var newMenu = app.querySelector( '.fw-mbr-new' );

	document.addEventListener( 'click', function ( e ) {
		if ( newMenu && newMenu.open && ! newMenu.contains( e.target ) ) {
			newMenu.open = false;
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			if ( newMenu && newMenu.open ) {
				newMenu.open = false;
				newMenu.querySelector( 'summary' ).focus();
			}
			return;
		}
		/* "/" jumps to search, unless the person is typing somewhere. */
		if ( '/' === e.key && ! e.ctrlKey && ! e.metaKey && ! e.altKey ) {
			var t = e.target;
			if ( t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( t.tagName ) ) {
				return;
			}
			var q = document.getElementById( 'fw-mbr-q' );
			if ( q ) {
				e.preventDefault();
				q.focus();
				q.select();
			}
		}
	} );

	/* Edit in place: a panel holds a read view and a hidden form. */
	app.querySelectorAll( '[data-edit-toggle]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var panel = document.getElementById( btn.getAttribute( 'data-edit-toggle' ) );
			if ( ! panel ) {
				return;
			}
			var view = panel.querySelector( '[data-view]' );
			var form = panel.querySelector( '[data-edit]' );
			var editing = form && form.hidden;
			if ( form ) {
				form.hidden = ! editing;
			}
			if ( view ) {
				view.hidden = editing;
			}
			if ( editing ) {
				panel.scrollIntoView( { block: 'nearest' } );
				var first = form.querySelector( 'input:not([type=hidden]), select, textarea' );
				if ( first ) {
					first.focus();
				}
			}
		} );
	} );

	/* Pickers that save as soon as they change (the stage on a profile). */
	app.querySelectorAll( 'form[data-autosubmit] select' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			select.form.submit();
		} );
	} );

	/* Screen Options belong to the contacts list: move them into the content
	   column there, where they sit beside the table they configure. */
	var listbar = app.querySelector( '.fw-mbr-listbar' );
	var links = document.getElementById( 'screen-meta-links' );
	var meta = document.getElementById( 'screen-meta' );
	if ( listbar && links && document.querySelector( '.fw-mbr-body input[name="tab"][value="members"]' ) ) {
		var body = app.querySelector( '.fw-mbr-body' );
		if ( meta ) {
			body.insertBefore( meta, listbar );
		}
		body.insertBefore( links, listbar );
	}
}() );
