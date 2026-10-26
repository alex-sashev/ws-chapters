<?php
/**
 * Sign-in protection.
 *
 * Limited sign-in attempts: after 5 wrong passwords for one account, or 20 from one connection, within
 * 15 minutes, sign-in is refused for 15 minutes (the right password too). This covers every way in:
 * the member login, the pop-up, wp-login.php and the admin. A successful sign-in clears the account's count.
 *
 * XML-RPC is off: nothing on the site uses it, and it let one request try hundreds of passwords.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

const WS_LOGIN_MAX_ACCOUNT = 5;
const WS_LOGIN_MAX_IP      = 20;
const WS_LOGIN_WINDOW      = 15 * MINUTE_IN_SECONDS;

/** The visitor's connection. Behind Cloudflare the server sees Cloudflare, so its header carries the visitor. */
function ws_login_ip() {
	$ip = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/** One key per account, whether it is typed as an email or a username. */
function ws_login_account_key( $login ) {
	$login = strtolower( trim( (string) $login ) );
	if ( '' === $login ) {
		return '';
	}
	$user = is_email( $login ) ? get_user_by( 'email', $login ) : get_user_by( 'login', $login );
	return 'ws_login_a_' . md5( ( $user ? 'u' . $user->ID : $login ) . wp_salt() );
}

function ws_login_ip_key() {
	$ip = ws_login_ip();
	return '' === $ip ? '' : 'ws_login_i_' . md5( $ip . wp_salt() );
}

/** True while this account or connection has used up its attempts. */
function ws_login_locked( $login ) {
	$a = ws_login_account_key( $login );
	$i = ws_login_ip_key();
	return ( $a && (int) get_transient( $a ) >= WS_LOGIN_MAX_ACCOUNT ) || ( $i && (int) get_transient( $i ) >= WS_LOGIN_MAX_IP );
}

// Last in line, so it overrides even a right password while locked.
add_filter( 'authenticate', function ( $user, $login ) {
	if ( '' !== (string) $login && ws_login_locked( $login ) ) {
		/* translators: %d: minutes */
		return new WP_Error( 'ws_locked', sprintf( __( 'Too many sign-in attempts. Please wait %d minutes, or ask for a new password.', 'ws-chapters' ), WS_LOGIN_WINDOW / MINUTE_IN_SECONDS ) );
	}
	return $user;
}, 99, 2 );

add_action( 'wp_login_failed', function ( $login, $error = null ) {
	if ( $error instanceof WP_Error && 'ws_locked' === $error->get_error_code() ) {
		return; // Already locked: the wait does not start again.
	}
	foreach ( array( ws_login_account_key( $login ), ws_login_ip_key() ) as $key ) {
		if ( $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, WS_LOGIN_WINDOW );
		}
	}
}, 10, 2 );

add_action( 'wp_login', function ( $login ) {
	$key = ws_login_account_key( $login );
	if ( $key ) {
		delete_transient( $key );
	}
} );

/* XML-RPC off: no methods, no pingback address in the page headers. */
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', '__return_empty_array' );
add_filter( 'wp_headers', function ( $headers ) {
	unset( $headers['X-Pingback'] );
	return $headers;
} );
remove_action( 'wp_head', 'rsd_link' );
