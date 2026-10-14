/**
 * Membership passkeys: "Add a passkey" on the account page and "Log in with a passkey"
 * on login forms. Binary values travel as base64url; the server checks everything.
 */
( function () {
	'use strict';
	var cfg = window.fwMbrPasskeys;
	if ( ! cfg || ! window.PublicKeyCredential || ! navigator.credentials ) {
		return;
	}

	function toBuf( s ) {
		s = s.replace( /-/g, '+' ).replace( /_/g, '/' );
		while ( s.length % 4 ) { s += '='; }
		var bin = atob( s ), out = new Uint8Array( bin.length );
		for ( var i = 0; i < bin.length; i++ ) { out[ i ] = bin.charCodeAt( i ); }
		return out.buffer;
	}

	function toB64u( buf ) {
		var bytes = new Uint8Array( buf ), bin = '';
		for ( var i = 0; i < bytes.length; i++ ) { bin += String.fromCharCode( bytes[ i ] ); }
		return btoa( bin ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}

	function post( path, body ) {
		return fetch( cfg.root + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( body || {} )
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) { throw new Error( ( j && j.message ) || cfg.texts.failed ); }
				return j;
			} );
		} );
	}

	function say( btn, text ) {
		var msg = btn.parentNode.querySelector( '.fw-mbr-passkey-msg' );
		if ( msg ) { msg.textContent = text; }
	}

	function add( btn ) {
		btn.disabled = true;
		post( 'register/options' ).then( function ( o ) {
			o.challenge = toBuf( o.challenge );
			o.user.id = toBuf( o.user.id );
			o.excludeCredentials = ( o.excludeCredentials || [] ).map( function ( c ) { return { type: c.type, id: toBuf( c.id ) }; } );
			return navigator.credentials.create( { publicKey: o } );
		} ).then( function ( cred ) {
			return post( 'register', {
				id: cred.id,
				clientDataJSON: toB64u( cred.response.clientDataJSON ),
				attestationObject: toB64u( cred.response.attestationObject )
			} );
		} ).then( function () {
			say( btn, cfg.texts.added );
			window.location.reload();
		} ).catch( function ( e ) {
			btn.disabled = false;
			say( btn, e && e.name === 'NotAllowedError' ? '' : ( e.message || cfg.texts.failed ) );
		} );
	}

	function login( btn ) {
		btn.disabled = true;
		var challengeId;
		post( 'login/options' ).then( function ( o ) {
			challengeId = o.id;
			o.options.challenge = toBuf( o.options.challenge );
			return navigator.credentials.get( { publicKey: o.options } );
		} ).then( function ( cred ) {
			return post( 'login', {
				challenge_id: challengeId,
				id: cred.id,
				clientDataJSON: toB64u( cred.response.clientDataJSON ),
				authenticatorData: toB64u( cred.response.authenticatorData ),
				signature: toB64u( cred.response.signature ),
				userHandle: cred.response.userHandle ? toB64u( cred.response.userHandle ) : '',
				redirect: btn.getAttribute( 'data-redirect' ) || ''
			} );
		} ).then( function ( r ) {
			window.location.href = r.redirect || '/';
		} ).catch( function ( e ) {
			btn.disabled = false;
			say( btn, e && e.name === 'NotAllowedError' ? '' : ( e.message || cfg.texts.failed ) );
		} );
	}

	function start() {
		document.querySelectorAll( '.fw-mbr-passkey-login, .fw-mbr-passkey-add' ).forEach( function ( el ) { el.hidden = false; } );
		document.addEventListener( 'click', function ( ev ) {
			var b = ev.target.closest( '[data-fw-mbr-passkey-add], [data-fw-mbr-passkey-login]' );
			if ( ! b ) { return; }
			ev.preventDefault();
			if ( b.hasAttribute( 'data-fw-mbr-passkey-add' ) ) { add( b ); } else { login( b ); }
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
