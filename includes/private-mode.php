<?php
/**
 * Private mode for the development stage: while it is on, only signed-in users see the site.
 * Everyone else gets a short "coming soon" page (HTTP 503, not indexed by search engines) with a sign-in link,
 * and the REST API answers only signed-in users.
 *
 * Switch it off in Settings → Reading → "Private while in development" when the site opens to the public.
 * A new live site starts private; the Playground demo stays public.
 */

defined( 'ABSPATH' ) || exit;

function ws_private_mode() {
	return '1' === (string) get_option( 'ws_private_mode', '0' );
}

/** Empty LiteSpeed's page cache, so pages cached for visitors are not served past the switch. */
function ws_purge_page_cache() {
	do_action( 'litespeed_purge_all' );
}

// A live site (set up by site-setup.php, not the demo) starts private.
add_action( 'init', function () {
	$setup = (string) get_option( 'ws_site_setup_done', '' );
	if ( '' !== $setup && 'demo' !== $setup && false === get_option( 'ws_private_mode' ) ) {
		add_option( 'ws_private_mode', '1' );
		ws_purge_page_cache();
	}
}, 31 );

add_action( 'update_option_ws_private_mode', 'ws_purge_page_cache' );

add_action( 'template_redirect', function () {
	if ( ! ws_private_mode() || is_user_logged_in() ) {
		return;
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Retry-After: 86400' );
	status_header( 503 );
	$name  = esc_html( get_bloginfo( 'name' ) );
	$login = esc_url( wp_login_url( home_url( add_query_arg( array() ) ) ) );
	$logo  = esc_url( get_theme_file_uri( 'assets/ws-logo.png' ) );
	echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
		. '<title>' . $name . ' – coming soon</title><style>'
		. 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0e0d0b;color:#efe9dc;font:17px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;text-align:center;padding:24px;box-sizing:border-box}'
		. 'img{width:120px;height:auto;margin-bottom:24px}h1{font-family:Georgia,serif;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:#c9a64b;margin:0 0 8px;font-size:28px}'
		. 'p{margin:0 0 24px;color:#b9b2a3}a{display:inline-block;border:1px solid #c9a64b;color:#c9a64b;padding:10px 22px;text-decoration:none;letter-spacing:.06em}a:hover{background:#c9a64b;color:#0e0d0b}'
		. '</style></head><body><main><img src="' . $logo . '" alt=""><h1>' . $name . '</h1>'
		. '<p>' . esc_html__( 'Our new website is being prepared. Please come back soon.', 'ws-chapters' ) . '</p>'
		. '<a href="' . $login . '">' . esc_html__( 'Sign in', 'ws-chapters' ) . '</a></main></body></html>';
	exit;
}, 0 );

add_filter( 'rest_authentication_errors', function ( $result ) {
	if ( null === $result && ws_private_mode() && ! is_user_logged_in() ) {
		return new WP_Error( 'ws_private', __( 'This site is private while it is being prepared.', 'ws-chapters' ), array( 'status' => 401 ) );
	}
	return $result;
} );

// Settings → Reading: the switch.
add_action( 'admin_init', function () {
	register_setting( 'reading', 'ws_private_mode', array(
		'type'              => 'string',
		'sanitize_callback' => function ( $v ) {
			return $v ? '1' : '0';
		},
	) );
	add_settings_field( 'ws_private_mode', __( 'Private while in development', 'ws-chapters' ), function () {
		printf(
			'<label><input type="checkbox" name="ws_private_mode" value="1" %s> %s</label><p class="description">%s</p>',
			checked( ws_private_mode(), true, false ),
			esc_html__( 'Only signed-in users can see the site', 'ws-chapters' ),
			esc_html__( 'Visitors see a "coming soon" page. Untick this when the site opens to the public.', 'ws-chapters' )
		);
	}, 'reading' );
} );

// A reminder in the admin bar while the site is private.
add_action( 'admin_bar_menu', function ( $bar ) {
	if ( ws_private_mode() && current_user_can( 'manage_options' ) ) {
		$bar->add_node( array( 'id' => 'ws-private', 'title' => __( 'Private site', 'ws-chapters' ), 'href' => admin_url( 'options-reading.php' ) ) );
	}
}, 100 );
