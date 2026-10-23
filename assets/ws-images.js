/**
 * Photo picker for every "several photos" field (news, events, edit screens, chapter galleries).
 *
 * The browser's own file box replaces the earlier choice each time it is opened. This keeps a running
 * list instead: each pick (or drag and drop) adds to it, every photo shows as a thumbnail with Remove,
 * "Make main" moves a photo to the front, and the counter shows how many more fit. The real file input
 * stays in the form and is refilled before sending, so the server code is unchanged.
 *
 * Field attributes: data-ws-max (how many in total), data-ws-max-mb (size per photo), data-ws-main
 * (the first photo is the main image, so it gets a "Main image" badge).
 */
( function () {
	'use strict';
	try {
		new DataTransfer();
	} catch ( e ) {
		return; // Old browser: the plain file box still works.
	}
	var T = window.wsImagesText || {};
	function t( key, fallback ) {
		return T[ key ] || fallback;
	}
	function fill( text, a, b ) {
		return String( text ).replace( '%1$s', a ).replace( '%2$s', b ).replace( '%s', a );
	}
	var urls = new WeakMap();
	function thumb( file ) {
		if ( ! urls.has( file ) ) {
			urls.set( file, URL.createObjectURL( file ) );
		}
		return urls.get( file );
	}

	function init( input ) {
		if ( input.dataset.wsPicker ) {
			return;
		}
		input.dataset.wsPicker = '1';
		var max = input.hasAttribute( 'data-ws-max' ) ? parseInt( input.getAttribute( 'data-ws-max' ), 10 ) || 0 : Infinity;
		var maxMb = parseFloat( input.getAttribute( 'data-ws-max-mb' ) || '0' );
		var main = input.hasAttribute( 'data-ws-main' );
		// Photos already chosen before this script started (a slow page) are kept, not dropped.
		var files = Array.prototype.slice.call( input.files || [] );

		var wrap = document.createElement( 'div' );
		wrap.className = 'ws-pick';
		var grid = document.createElement( 'ul' );
		grid.className = 'ws-pick__grid';
		var drop = document.createElement( 'button' );
		drop.type = 'button';
		drop.className = 'ws-pick__drop';
		var note = document.createElement( 'p' );
		note.className = 'ws-pick__note';
		note.setAttribute( 'aria-live', 'polite' );
		wrap.appendChild( grid );
		wrap.appendChild( drop );
		wrap.appendChild( note );
		// Outside the field's label, so clicking a thumbnail does not reopen the file box.
		var anchor = input.closest( 'label' ) || input;
		anchor.parentNode.insertBefore( wrap, anchor.nextSibling );
		input.classList.add( 'ws-pick__input' );
		input.tabIndex = -1;

		function sync() {
			var dt = new DataTransfer();
			files.forEach( function ( f ) {
				dt.items.add( f );
			} );
			input.files = dt.files;
			render();
		}

		function add( list ) {
			var msgs = [];
			var full = false;
			Array.prototype.forEach.call( list, function ( f ) {
				if ( ! /^image\/(jpeg|png|webp|gif)$/.test( f.type ) ) {
					msgs.push( fill( t( 'notImage', '%s is not a JPG, PNG, WebP or GIF image and was left out.' ), f.name ) );
					return;
				}
				if ( maxMb && f.size > maxMb * 1048576 ) {
					msgs.push( fill( t( 'tooBig', '%1$s is larger than %2$s MB and was left out.' ), f.name, maxMb ) );
					return;
				}
				var dup = files.some( function ( g ) {
					return g.name === f.name && g.size === f.size && g.lastModified === f.lastModified;
				} );
				if ( dup ) {
					return;
				}
				if ( files.length >= max ) {
					full = true;
					return;
				}
				files.push( f );
			} );
			if ( full ) {
				msgs.push( fill( t( 'full', 'You can add up to %s photos here; the rest were left out.' ), max ) );
			}
			note.textContent = msgs.join( ' ' );
			sync();
		}

		function button( text, cls, label, onClick ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = cls;
			b.textContent = text;
			if ( label ) {
				b.setAttribute( 'aria-label', label );
				b.title = label;
			}
			b.addEventListener( 'click', onClick );
			return b;
		}

		function render() {
			grid.innerHTML = '';
			files.forEach( function ( f, i ) {
				var li = document.createElement( 'li' );
				li.className = 'ws-pick__item';
				var img = document.createElement( 'img' );
				img.src = thumb( f );
				img.alt = '';
				li.appendChild( img );
				if ( main && 0 === i ) {
					var badge = document.createElement( 'span' );
					badge.className = 'ws-pick__badge';
					badge.textContent = t( 'main', 'Main image' );
					li.appendChild( badge );
				}
				li.appendChild( button( '×', 'ws-pick__remove', fill( t( 'remove', 'Remove %s' ), f.name ), function () {
					files.splice( i, 1 );
					note.textContent = '';
					sync();
				} ) );
				if ( main && i > 0 ) {
					li.appendChild( button( t( 'makeMain', 'Make main' ), 'ws-pick__main', '', function () {
						files.unshift( files.splice( i, 1 )[ 0 ] );
						sync();
					} ) );
				}
				grid.appendChild( li );
			} );
			grid.hidden = ! files.length;
			var room = files.length < max;
			drop.disabled = ! room;
			var count = max === Infinity ? '' : ' · ' + fill( t( 'count', '%1$s of %2$s' ), files.length, max );
			if ( ! room ) {
				drop.textContent = ( max ? t( 'isFull', 'Photo limit reached' ) : t( 'noRoom', 'No room for more photos: remove one first' ) ) + count;
			} else {
				drop.innerHTML = '';
				var strong = document.createElement( 'strong' );
				strong.textContent = files.length ? t( 'more', '+ Add more photos' ) : t( 'choose', '+ Choose photos' );
				var hint = document.createElement( 'span' );
				hint.textContent = t( 'drag', 'or drag them here' ) + count;
				drop.appendChild( strong );
				drop.appendChild( hint );
			}
		}

		input.addEventListener( 'change', function () {
			// The file box gives only the new choice: add it to the list instead of replacing it.
			var picked = Array.prototype.slice.call( input.files );
			add( picked );
		} );
		drop.addEventListener( 'click', function () {
			input.click();
		} );
		[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
			wrap.addEventListener( ev, function ( e ) {
				e.preventDefault();
				wrap.classList.add( 'is-over' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( ev ) {
			wrap.addEventListener( ev, function ( e ) {
				e.preventDefault();
				wrap.classList.remove( 'is-over' );
			} );
		} );
		wrap.addEventListener( 'drop', function ( e ) {
			if ( e.dataTransfer && e.dataTransfer.files.length ) {
				add( e.dataTransfer.files );
			}
		} );
		var form = input.form;
		if ( form ) {
			form.addEventListener( 'reset', function () {
				files = [];
				note.textContent = '';
				setTimeout( sync );
			} );
		}
		render();
	}

	function scan() {
		document.querySelectorAll( 'input[type=file][multiple][accept*="image/"]' ).forEach( init );
	}
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', scan );
	} else {
		scan();
	}
}() );
