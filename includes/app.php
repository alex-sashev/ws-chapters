<?php
/**
 * The site as an app on the home screen (a Progressive Web App): members add it from the browser, and it
 * opens full screen with the Widows Sons icon, like an app from the store.
 *
 * ?ws_app=manifest  the app's name, colours and icons
 * ?ws_app=sw        the service worker: shows a "you are offline" page when there is no connection. It
 *                   keeps no copy of any page, so nothing personal stays on the phone.
 * ?ws_app=offline   that page
 *
 * Android and computers get an "Add to home screen" button; iPhone, which has none, gets the two steps
 * (Share, then "Add to Home Screen"). It is offered on My profile and once in a small bar for signed-in
 * members on a phone.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

const WS_APP_COLOR = '#0e0d0b';

/** The app's files, at the address without a language code: one app for every language. */
function ws_app_url( $what ) {
	return add_query_arg( 'ws_app', $what, ws_lang_raw_home() . '/' );
}
function ws_app_icon_url( $file ) {
	return plugin_dir_url( __DIR__ ) . 'assets/app/' . $file;
}

/* Served before private mode, which would answer "coming soon": the app's files are not private. */
add_action( 'template_redirect', function () {
	$what = isset( $_GET['ws_app'] ) ? sanitize_key( wp_unslash( $_GET['ws_app'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $what ) {
		return;
	}
	$path  = ws_lang_base();
	$start = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'manifest' === $what ) {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=86400' );
		echo wp_json_encode( array(
			'id'               => $path,
			'name'             => 'Widows Sons Europe',
			'short_name'       => 'WS Europe',
			'description'      => __( 'Widows Sons Europe: chapters, events, news and the members\' area.', 'ws-chapters' ),
			'start_url'        => add_query_arg( 'source', 'app', $path . ( isset( ws_languages()[ $start ] ) && 'en' !== $start ? $start . '/' : '' ) ), // Opens in the language it was added from.
			'scope'            => $path,
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'background_color' => WS_APP_COLOR,
			'theme_color'      => WS_APP_COLOR,
			'icons'            => array(
				array( 'src' => ws_app_icon_url( 'icon-192.png' ), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => ws_app_icon_url( 'icon-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				array( 'src' => ws_app_icon_url( 'icon-maskable-512.png' ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
			),
		), JSON_UNESCAPED_SLASHES );
		exit;
	}
	if ( 'sw' === $what ) {
		header( 'Content-Type: text/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'Service-Worker-Allowed: ' . $path );
		$offline = wp_json_encode( ws_app_url( 'offline' ), JSON_UNESCAPED_SLASHES );
		echo "/* Widows Sons Europe app: an offline page, nothing else is kept. */\n"
			. "var CACHE = 'ws-app-1', OFFLINE = " . $offline . ";\n"
			. "self.addEventListener('install', function (e) { e.waitUntil(caches.open(CACHE).then(function (c) { return c.add(OFFLINE); }).then(function () { return self.skipWaiting(); })); });\n"
			. "self.addEventListener('activate', function (e) { e.waitUntil(caches.keys().then(function (keys) { return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); })); }).then(function () { return self.clients.claim(); })); });\n"
			. "self.addEventListener('fetch', function (e) { if (e.request.mode !== 'navigate') { return; } e.respondWith(fetch(e.request).catch(function () { return caches.match(OFFLINE); })); });\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
	if ( 'offline' === $what ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Widows Sons Europe</title><style>'
			. 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0e0d0b;color:#efe9dc;font:17px/1.6 system-ui,-apple-system,sans-serif;text-align:center;padding:24px;box-sizing:border-box}'
			. 'img{width:96px;height:auto;margin-bottom:16px}h1{font-family:Georgia,serif;color:#c9a64b;letter-spacing:.08em;text-transform:uppercase;font-size:22px;margin:0 0 8px}'
			. 'button{margin-top:16px;background:none;border:1px solid #c9a64b;color:#c9a64b;padding:10px 22px;font:inherit;letter-spacing:.06em}'
			. '</style></head><body><main><img src="' . esc_url( ws_app_icon_url( 'icon-192.png' ) ) . '" alt=""><h1>' . esc_html__( 'You are offline', 'ws-chapters' ) . '</h1>'
			. '<p>' . esc_html__( 'Widows Sons Europe needs an internet connection. Please check it and try again.', 'ws-chapters' ) . '</p>'
			. '<button type="button" onclick="location.reload()">' . esc_html__( 'Try again', 'ws-chapters' ) . '</button></main></body></html>';
		exit;
	}
}, -5 );

add_action( 'wp_head', function () {
	echo '<link rel="manifest" href="' . esc_url( 'en' === ws_lang() ? ws_app_url( 'manifest' ) : add_query_arg( 'lang', ws_lang(), ws_app_url( 'manifest' ) ) ) . '">' . "\n"
		. '<meta name="theme-color" content="' . esc_attr( WS_APP_COLOR ) . '">' . "\n"
		. '<meta name="mobile-web-app-capable" content="yes">' . "\n"
		. '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
		. '<meta name="apple-mobile-web-app-title" content="WS Europe">' . "\n"
		. '<meta name="apple-mobile-web-app-status-bar-style" content="black">' . "\n";
}, 2 );

/* The script for passkeys and the app: on every page (the sign-in pop-up is on every page). */
add_action( 'wp_enqueue_scripts', function () {
	$dir = plugin_dir_path( __DIR__ );
	wp_enqueue_script( 'ws-signin', plugin_dir_url( __DIR__ ) . 'assets/ws-signin.js', array(), filemtime( $dir . 'assets/ws-signin.js' ), true );
	wp_localize_script( 'ws-signin', 'wsSignIn', array(
		'ajax'     => admin_url( 'admin-ajax.php' ),
		'sw'       => 'demo' === get_option( 'ws_site_setup_done' ) ? '' : ws_app_url( 'sw' ), // The Playground demo runs on its own service worker.
		'scope'    => ws_lang_base(),
		'cancel'   => __( 'No passkey was used. If you have not added one yet, sign in with an email code first, then add a passkey on My profile. A passkey works on the device where you added it (or with that phone, by scanning the QR code).', 'ws-chapters' ),
		'addCancel' => __( 'No passkey was added.', 'ws-chapters' ),
		'failed'   => __( 'That did not work. Please try again, or use an email code.', 'ws-chapters' ),
		'noKey'    => __( 'No passkey for this site was found on this device. Sign in with an email code, then add a passkey on My profile.', 'ws-chapters' ),
		'exists'   => __( 'This device already has a passkey for your account.', 'ws-chapters' ),
		'oldBrowser' => __( 'This browser cannot add passkeys. Please update it, or use Safari or Chrome.', 'ws-chapters' ),
		'device'   => __( 'Passkey', 'ws-chapters' ),
		'confirm'  => __( 'Are you sure?', 'ws-chapters' ),
	) );
} );

/** "Add to your home screen", for My profile and the bar. */
function ws_app_install_steps() {
	return '<div class="ws-app__how">'
		. '<button type="button" class="ws-mm__btn" data-ws-install hidden>' . esc_html__( 'Add to home screen', 'ws-chapters' ) . '</button>'
		. '<p class="ws-app__ios" hidden>' . esc_html__( 'On iPhone or iPad: open this site in Safari, tap the Share button (the square with an arrow), then "Add to Home Screen".', 'ws-chapters' ) . '</p>'
		. '<p class="ws-app__other" hidden>' . esc_html__( 'In your browser\'s menu, choose "Install app" or "Add to Home screen".', 'ws-chapters' ) . '</p>'
		. '<p class="ws-app__done" hidden>' . esc_html__( 'You are using the app. ✓', 'ws-chapters' ) . '</p>'
		. '</div>';
}

function ws_app_install_card() {
	return '<section class="ws-mm__card ws-app" id="app"><h3 class="ws-mm__heading">' . esc_html__( 'Widows Sons Europe on your home screen', 'ws-chapters' ) . '</h3>'
		. '<p class="ws-muted">' . esc_html__( 'Add the site to your phone\'s home screen: it opens full screen with our emblem, like an app, and you stay signed in.', 'ws-chapters' ) . '</p>'
		. ws_app_install_steps() . '</section>';
}

/* Once, for signed-in members on a phone that is not using the app yet: a small bar they can close. */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() || is_admin() ) {
		return;
	}
	echo '<div class="ws-app-bar" hidden><img src="' . esc_url( ws_app_icon_url( 'icon-192.png' ) ) . '" alt="" width="40" height="40">'
		. '<div><strong>' . esc_html__( 'Get the app', 'ws-chapters' ) . '</strong>' . ws_app_install_steps() . '</div>' // phpcs:ignore WordPress.Security.EscapeOutput
		. '<button type="button" class="ws-app-bar__close" aria-label="' . esc_attr__( 'Close', 'ws-chapters' ) . '">&times;</button></div>';
} );
