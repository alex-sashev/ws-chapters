/**
 * Events page: List / Month / Week. List is the page as it is; Month and Week are drawn by FullCalendar,
 * fetched the first time one of them is picked. The view is kept in the address (?view=month), so a link opens it.
 * On a phone, Month shows as a day-by-day list of the month (a month grid is too small to read there).
 */
( function () {
	'use strict';
	var root = document.querySelector( '.ws-views' );
	if ( ! root ) {
		return;
	}
	var cfg = window.wsCalendar || {};
	var list = root.querySelector( '.ws-views__list' );
	var box = root.querySelector( '.ws-views__cal' );
	var buttons = root.querySelectorAll( '[data-ws-view]' );
	var events = [];
	try {
		events = JSON.parse( root.getAttribute( 'data-ws-events' ) || '[]' );
	} catch ( e ) {}
	var cal = null;
	var loading = null;

	function small() {
		return window.matchMedia( '(max-width: 640px)' ).matches;
	}
	function fcView( view ) {
		if ( 'week' === view ) {
			return small() ? 'listWeek' : 'dayGridWeek';
		}
		return small() ? 'listMonth' : 'dayGridMonth';
	}
	function load() {
		if ( window.FullCalendar ) {
			return Promise.resolve();
		}
		if ( ! loading ) {
			loading = new Promise( function ( resolve, reject ) {
				var s = document.createElement( 'script' );
				s.src = cfg.lib;
				s.integrity = cfg.sri;
				s.crossOrigin = 'anonymous';
				s.onload = resolve;
				s.onerror = reject;
				document.head.appendChild( s );
			} );
		}
		return loading;
	}
	// First upcoming event (or today): the calendar opens on that month, not on an empty one.
	function startDate() {
		var today = new Date().toISOString().slice( 0, 10 );
		var next = events.map( function ( e ) {
			return e.start;
		} ).filter( function ( d ) {
			return d >= today;
		} ).sort()[ 0 ];
		return next || today;
	}
	function build( view ) {
		cal = new window.FullCalendar.Calendar( box, {
			initialView: fcView( view ),
			initialDate: startDate(),
			firstDay: 1,
			locale: cfg.locale || 'en',
			height: 'auto',
			buttonText: { today: cfg.today || 'Today' },
			headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
			noEventsContent: cfg.none || 'No events in this period.',
			dayMaxEventRows: 4,
			events: events.map( function ( e ) {
				return { title: e.title, start: e.start, end: e.end, url: e.url, allDay: true, extendedProps: { place: e.place, host: e.host } };
			} ),
			eventDidMount: function ( info ) {
				var p = info.event.extendedProps;
				info.el.title = info.event.title + ( p.place ? ' · ' + p.place : '' ) + ( p.host ? ' · ' + p.host : '' );
			}
		} );
		cal.render();
	}
	function show( view, push ) {
		buttons.forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-ws-view' ) === view ? 'true' : 'false' );
		} );
		if ( push ) {
			var url = new URL( window.location.href );
			if ( 'list' === view ) {
				url.searchParams.delete( 'view' );
			} else {
				url.searchParams.set( 'view', view );
			}
			window.history.replaceState( null, '', url );
		}
		if ( 'list' === view ) {
			list.hidden = false;
			box.hidden = true;
			return;
		}
		load().then( function () {
			list.hidden = true;
			box.hidden = false;
			if ( cal ) {
				cal.changeView( fcView( view ) );
			} else {
				build( view );
			}
		} ).catch( function () {
			box.hidden = false;
			box.textContent = cfg.failed || 'The calendar could not be loaded.';
		} );
	}
	buttons.forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			show( b.getAttribute( 'data-ws-view' ), true );
		} );
	} );
	var start = new URL( window.location.href ).searchParams.get( 'view' );
	if ( 'month' === start || 'week' === start ) {
		show( start, false );
	}
}() );
