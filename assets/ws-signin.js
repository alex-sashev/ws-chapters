/**
 * Passkeys (sign in, add one on My profile) and the home-screen app (service worker, "Add to home screen").
 * Buttons stay hidden where the browser cannot do what they offer.
 */
( function () {
	'use strict';
	var cfg = window.wsSignIn || {};

	/* ---- The app ---- */
	if ( 'serviceWorker' in navigator && cfg.sw ) {
		window.addEventListener( 'load', function () {
			navigator.serviceWorker.register( cfg.sw, { scope: cfg.scope || '/' } ).catch( function () {} );
		} );
	}
	var standalone = window.matchMedia( '(display-mode: standalone)' ).matches || true === window.navigator.standalone;
	var ios = /iphone|ipad|ipod/i.test( navigator.userAgent ) || ( 'MacIntel' === navigator.platform && navigator.maxTouchPoints > 1 );
	var phone = window.matchMedia( '(max-width: 820px)' ).matches && ( 'ontouchstart' in window );
	var prompt = null;

	function showAll( sel, on ) {
		document.querySelectorAll( sel ).forEach( function ( el ) {
			el.hidden = ! on;
		} );
	}
	function installState() {
		showAll( '.ws-app__done', standalone );
		showAll( '[data-ws-install]', ! standalone && !! prompt );
		showAll( '.ws-app__ios', ! standalone && ios );
		showAll( '.ws-app__other', ! standalone && ! ios && ! prompt );
	}
	window.addEventListener( 'beforeinstallprompt', function ( e ) {
		e.preventDefault();
		prompt = e;
		installState();
	} );
	window.addEventListener( 'appinstalled', function () {
		prompt = null;
		hideBar( true );
		showAll( '[data-ws-install], .ws-app__other', false );
	} );
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest && e.target.closest( '[data-ws-install]' );
		if ( b && prompt ) {
			prompt.prompt();
			prompt.userChoice.then( function () {
				prompt = null;
				installState();
			} );
		}
	} );

	var bar = document.querySelector( '.ws-app-bar' );
	function remembered() {
		try {
			return !! window.localStorage.getItem( 'wsAppBar' );
		} catch ( e ) {
			return false;
		}
	}
	function hideBar( forever ) {
		if ( bar ) {
			bar.hidden = true;
		}
		if ( forever ) {
			try {
				window.localStorage.setItem( 'wsAppBar', '1' );
			} catch ( e ) {}
		}
	}
	if ( bar ) {
		bar.querySelector( '.ws-app-bar__close' ).addEventListener( 'click', function () {
			hideBar( true );
		} );
		bar.hidden = standalone || ! phone || remembered() || !! document.getElementById( 'app' );
	}
	installState();

	/* ---- Passkeys ---- */
	function enc( buf ) {
		var s = '';
		new Uint8Array( buf ).forEach( function ( b ) {
			s += String.fromCharCode( b );
		} );
		return btoa( s ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}
	function dec( str ) {
		str = String( str ).replace( /-/g, '+' ).replace( /_/g, '/' );
		var bin = atob( str + '==='.slice( ( str.length + 3 ) % 4 ) );
		var out = new Uint8Array( bin.length );
		for ( var i = 0; i < bin.length; i++ ) {
			out[ i ] = bin.charCodeAt( i );
		}
		return out.buffer;
	}
	function post( data ) {
		var body = new URLSearchParams();
		body.append( 'action', 'ws_passkey' );
		Object.keys( data ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				throw new Error( ( j && j.data && j.data.message ) || cfg.failed );
			}
			return j.data;
		} );
	}
	function say( from, text ) {
		var box = from.closest( '.ws-si, .ws-pk' );
		var msg = box && box.querySelector( '.ws-si__msg' );
		if ( msg ) {
			msg.textContent = text;
			msg.hidden = ! text;
		}
	}
	function busy( btn, on ) {
		btn.disabled = on;
		btn.classList.toggle( 'is-busy', on );
	}
	var canPasskey = !! ( window.PublicKeyCredential && navigator.credentials && navigator.credentials.get );
	var canAdd = canPasskey && window.AuthenticatorAttestationResponse && 'getPublicKey' in window.AuthenticatorAttestationResponse.prototype;
	showAll( '[data-ws-passkey-login]', canPasskey );
	showAll( '[data-ws-passkey-add]', canAdd );
	showAll( '.ws-pk__none', ! canAdd );

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '[data-ws-passkey-login]' );
		if ( ! btn ) {
			return;
		}
		var form = btn.form || btn.closest( 'form' );
		var to = form && form.querySelector( '[name=redirect_to]' );
		say( btn, '' );
		busy( btn, true );
		var id;
		post( { step: 'login_options' } ).then( function ( o ) {
			id = o.id;
			var pk = o.publicKey;
			pk.challenge = dec( pk.challenge );
			return navigator.credentials.get( { publicKey: pk } );
		} ).then( function ( cred ) {
			var r = cred.response;
			return post( {
				step: 'login',
				id: id,
				credId: enc( cred.rawId ),
				clientDataJSON: enc( r.clientDataJSON ),
				authenticatorData: enc( r.authenticatorData ),
				signature: enc( r.signature ),
				userHandle: r.userHandle ? enc( r.userHandle ) : '',
				to: to ? to.value : ''
			} );
		} ).then( function ( d ) {
			window.location.href = d.redirect;
		} ).catch( function ( err ) {
			busy( btn, false );
			say( btn, err && 'NotAllowedError' === err.name ? cfg.cancel : ( err && err.message ) || cfg.failed );
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '[data-ws-passkey-add]' );
		if ( ! btn ) {
			return;
		}
		var nonce = btn.getAttribute( 'data-nonce' );
		say( btn, '' );
		busy( btn, true );
		var id;
		post( { step: 'register_options', nonce: nonce } ).then( function ( o ) {
			id = o.id;
			var pk = o.publicKey;
			pk.challenge = dec( pk.challenge );
			pk.user.id = dec( pk.user.id );
			pk.excludeCredentials = ( pk.excludeCredentials || [] ).map( function ( c ) {
				return { type: c.type, id: dec( c.id ) };
			} );
			return navigator.credentials.create( { publicKey: pk } );
		} ).then( function ( cred ) {
			var r = cred.response;
			var name = ( ios ? 'iPhone / iPad' : ( /android/i.test( navigator.userAgent ) ? 'Android' : ( /mac/i.test( navigator.platform ) ? 'Mac' : ( /win/i.test( navigator.platform ) ? 'Windows' : cfg.device ) ) ) );
			return post( {
				step: 'register',
				nonce: nonce,
				id: id,
				credId: enc( cred.rawId ),
				clientDataJSON: enc( r.clientDataJSON ),
				authenticatorData: enc( r.getAuthenticatorData() ),
				publicKey: enc( r.getPublicKey() ),
				alg: r.getPublicKeyAlgorithm(),
				name: name
			} );
		} ).then( function ( d ) {
			window.location.href = d.redirect;
		} ).catch( function ( err ) {
			busy( btn, false );
			var text = ( err && err.message ) || cfg.failed;
			if ( err && 'InvalidStateError' === err.name ) {
				text = cfg.exists;
			} else if ( err && 'NotAllowedError' === err.name ) {
				text = cfg.cancel;
			}
			say( btn, text );
		} );
	} );

	// Remove a passkey: ask first.
	document.addEventListener( 'submit', function ( e ) {
		var b = e.target.querySelector && e.target.querySelector( '.ws-pk__remove[data-ws-confirm]' );
		if ( b && ! window.confirm( b.getAttribute( 'data-ws-confirm' ) ) ) {
			e.preventDefault();
		}
	} );
}() );
