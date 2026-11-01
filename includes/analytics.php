<?php
/**
 * Google Analytics, with consent (1.0.18): visits are counted only for visitors who say yes.
 *
 * Off until a Measurement ID (G-…) is entered in Settings → General. Then every visitor gets a small
 * bar at the bottom: "Accept" loads Google Analytics, "Decline" keeps it out. Nothing from Google loads,
 * and no analytics cookie is set, before "Accept" (EU cookie rules). The choice is kept in the browser
 * for a year; "Cookie settings" in the footer and on the privacy policy opens the bar again.
 * Administrators are not counted. Google signals and ad personalisation are off.
 *
 * The bar is the same in every page's HTML (the page cache serves one copy to everyone); the script
 * decides whether to show it.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/** The Measurement ID, or '' while analytics is off. */
function ws_ga_id() {
	$id = strtoupper( trim( (string) get_option( 'ws_ga_id', '' ) ) );
	return preg_match( '/^G-[A-Z0-9]{4,16}$/', $id ) ? $id : '';
}

/** Analytics runs on this page: an ID is set, it is the public site, and the visitor is not an administrator. */
function ws_ga_active() {
	return '' !== ws_ga_id() && ! is_admin() && ! current_user_can( 'manage_options' ) && ! ( function_exists( 'ws_private_mode' ) && ws_private_mode() );
}

// Settings → General: the Measurement ID.
add_action( 'admin_init', function () {
	register_setting( 'general', 'ws_ga_id', array(
		'type'              => 'string',
		'sanitize_callback' => function ( $v ) {
			$v = strtoupper( trim( (string) $v ) );
			if ( '' !== $v && ! preg_match( '/^G-[A-Z0-9]{4,16}$/', $v ) ) {
				add_settings_error( 'ws_ga_id', 'ws_ga_id', __( 'The Google Analytics Measurement ID starts with G- (for example G-AB12CD34EF). It was not saved.', 'ws-chapters' ) );
				return (string) get_option( 'ws_ga_id', '' );
			}
			return $v;
		},
	) );
	add_settings_field( 'ws_ga_id', '<label for="ws_ga_id">' . esc_html__( 'Google Analytics', 'ws-chapters' ) . '</label>', function () {
		printf(
			'<input type="text" id="ws_ga_id" name="ws_ga_id" value="%s" class="regular-text code" placeholder="G-XXXXXXXXXX" spellcheck="false"><p class="description">%s</p>',
			esc_attr( (string) get_option( 'ws_ga_id', '' ) ),
			esc_html__( 'The Measurement ID of the site\'s Google Analytics data stream. Visitors are asked first, and only those who accept are counted. Leave empty to turn analytics off.', 'ws-chapters' )
		);
	}, 'general' );
} );
add_action( 'update_option_ws_ga_id', function () {
	if ( function_exists( 'ws_purge_page_cache' ) ) {
		ws_purge_page_cache(); // Cached pages would keep the old setting.
	}
} );

/** [ws_cookie_settings] A link that opens the consent bar again (shows only while analytics is on). */
function ws_cookie_settings_link( $atts = array() ) {
	if ( '' === ws_ga_id() ) {
		return '';
	}
	$atts = shortcode_atts( array( 'tag' => '' ), $atts );
	$link = '<button type="button" class="ws-consent-link" data-ws-consent>' . esc_html__( 'Cookie settings', 'ws-chapters' ) . '</button>';
	return 'li' === $atts['tag'] ? '<li>' . $link . '</li>' : $link;
}
add_action( 'init', function () {
	add_shortcode( 'ws_cookie_settings', 'ws_cookie_settings_link' );
} );

/* The consent bar and the loader, at the end of every public page. */
add_action( 'wp_footer', function () {
	if ( ! ws_ga_active() ) {
		return;
	}
	$privacy = get_privacy_policy_url();
	echo '<div class="ws-consent" role="region" aria-label="' . esc_attr__( 'Cookies', 'ws-chapters' ) . '" hidden>'
		. '<p>' . esc_html__( 'May we count your visit with Google Analytics? It helps us see which pages brothers use. It sets cookies and sends your visit to Google.', 'ws-chapters' )
		. ( $privacy ? ' <a href="' . esc_url( $privacy ) . '#cookies">' . esc_html__( 'Privacy policy', 'ws-chapters' ) . '</a>' : '' ) . '</p>'
		. '<div class="ws-consent__buttons"><button type="button" class="ws-mm__btn ws-mm__btn--small" data-ws-consent-choice="yes">' . esc_html__( 'Accept', 'ws-chapters' ) . '</button>'
		. '<button type="button" class="ws-mm__btn ws-mm__btn--small ws-consent__no" data-ws-consent-choice="no">' . esc_html__( 'Decline', 'ws-chapters' ) . '</button></div></div>';
	?>
<script>
( function () {
	var id = <?php echo wp_json_encode( ws_ga_id() ); ?>, key = 'wsConsent', year = 365 * 864e5, bar = document.querySelector( '.ws-consent' ), loaded = false;
	function read() {
		try {
			var c = JSON.parse( window.localStorage.getItem( key ) || 'null' );
			return c && Date.now() - c.t < year ? c.v : '';
		} catch ( e ) {
			return '';
		}
	}
	function load() {
		if ( loaded ) {
			return;
		}
		loaded = true;
		window.dataLayer = window.dataLayer || [];
		window.gtag = function () {
			window.dataLayer.push( arguments );
		};
		window.gtag( 'js', new Date() );
		window.gtag( 'config', id, { allow_google_signals: false, allow_ad_personalization_signals: false } );
		var s = document.createElement( 'script' );
		s.async = true;
		s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent( id );
		document.head.appendChild( s );
	}
	function forget() {
		// Withdrawn: stop and remove Google's cookies from this site.
		window[ 'ga-disable-' + id ] = true;
		document.cookie.split( ';' ).forEach( function ( c ) {
			var name = c.split( '=' )[ 0 ].trim();
			if ( /^_ga/.test( name ) ) {
				var host = location.hostname.split( '.' );
				for ( var i = 0; i < host.length - 1; i++ ) {
					document.cookie = name + '=; Max-Age=0; path=/; domain=.' + host.slice( i ).join( '.' );
				}
				document.cookie = name + '=; Max-Age=0; path=/';
			}
		} );
	}
	function choose( v ) {
		try {
			window.localStorage.setItem( key, JSON.stringify( { v: v, t: Date.now() } ) );
		} catch ( e ) {}
		bar.hidden = true;
		if ( 'yes' === v ) {
			window[ 'ga-disable-' + id ] = false;
			load();
		} else {
			forget();
		}
	}
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest && e.target.closest( '[data-ws-consent-choice], [data-ws-consent]' );
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		if ( b.hasAttribute( 'data-ws-consent' ) ) {
			bar.hidden = false;
			bar.querySelector( 'button' ).focus();
		} else {
			choose( b.getAttribute( 'data-ws-consent-choice' ) );
		}
	} );
	var v = read();
	if ( 'yes' === v ) {
		load();
	} else if ( '' === v ) {
		bar.hidden = false;
	}
}() );
</script>
	<?php
}, 20 );
