<?php
/**
 * Languages (decided 19 October 2026, started 30 October): the site's own text (menus, buttons, forms and the
 * theme's fixed pages) in English, German, Italian, French, Bulgarian, Romanian and Greek. What members write
 * (news, events, chapter and country text, the About page) stays in English in every language.
 *
 * English is at the plain address; every other language under its code: /de/chapters/, /bg/events/ ...
 * The code is taken off the address before WordPress reads it, so every page exists in every language, and
 * every link made while showing a language keeps its code (through home_url). Translations are in
 * languages/ws-chapters-<locale>.l10n.php here and in the theme's languages folder, made by tools/i18n.py.
 *
 * Loaded first by ws-chapters.php: the address has to be read before WordPress does.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/** Code => array( WordPress locale, name in that language ). English first: it is the site's own language. */
function ws_languages() {
	return array(
		'en' => array( 'en_US', 'English' ),
		'de' => array( 'de_DE', 'Deutsch' ),
		'it' => array( 'it_IT', 'Italiano' ),
		'fr' => array( 'fr_FR', 'Français' ),
		'bg' => array( 'bg_BG', 'Български' ),
		'ro' => array( 'ro_RO', 'Română' ),
		'el' => array( 'el', 'Ελληνικά' ),
	);
}

/** The language being shown: 'en' or a code from ws_languages(). */
function ws_lang() {
	return $GLOBALS['ws_lang'] ?? 'en';
}

/** The site's address without any language code, e.g. https://wseurope.org (no trailing slash). */
function ws_lang_raw_home( $scheme = null ) {
	return untrailingslashit( set_url_scheme( (string) get_option( 'home' ), $scheme ) );
}

/** The home path, e.g. "/" (or "/subfolder/"). */
function ws_lang_base() {
	return trailingslashit( (string) ( wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ?: '' ) );
}

/*
 * Read the code from the address and take it off. /de/chapters/ is served as /chapters/ in German.
 * admin-ajax answers in the language of the page that called it.
 */
( function () {
	$codes = implode( '|', array_keys( ws_languages() ) );
	$base  = ws_lang_base();
	$uri   = (string) ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( preg_match( '#^' . preg_quote( $base, '#' ) . '(' . $codes . ')(/|\?|$)#', $uri, $m ) ) {
		$rest = (string) substr( $uri, strlen( $base . $m[1] ) );
		$rest = ltrim( $rest, '/' );
		if ( 'en' === $m[1] ) {
			$GLOBALS['ws_lang_redirect'] = $rest; // /en/... is the plain address: sent there below.
			return;
		}
		$_SERVER['REQUEST_URI'] = $base . $rest;
		$GLOBALS['ws_lang']     = $m[1];
		if ( ! empty( $_SERVER['PHP_SELF'] ) && 0 === strpos( (string) $_SERVER['PHP_SELF'], $base . $m[1] . '/' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$_SERVER['PHP_SELF'] = $base . substr( (string) $_SERVER['PHP_SELF'], strlen( $base . $m[1] . '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		// Some servers also pass the path separately; WordPress reads that too.
		foreach ( array( 'PATH_INFO', 'ORIG_PATH_INFO' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && preg_match( '#^/' . $m[1] . '(/|$)#', (string) $_SERVER[ $key ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$_SERVER[ $key ] = '/' . ltrim( (string) substr( (string) $_SERVER[ $key ], strlen( $m[1] ) + 1 ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			}
		}
		return;
	}
	if ( wp_doing_ajax() && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$ref = (string) wp_parse_url( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( preg_match( '#^' . preg_quote( $base, '#' ) . '(' . $codes . ')(/|$)#', $ref, $m ) && 'en' !== $m[1] ) {
			$GLOBALS['ws_lang'] = $m[1];
		}
	}
} )();

add_action( 'template_redirect', function () {
	if ( isset( $GLOBALS['ws_lang_redirect'] ) ) {
		wp_safe_redirect( ws_lang_raw_home() . '/' . $GLOBALS['ws_lang_redirect'], 301 );
		exit;
	}
}, 0 );

/* WordPress's own words (dates, "Search", "Next page") and ours, in the language shown. Admin screens keep the user's language. */
add_filter( 'locale', function ( $locale ) {
	return 'en' === ws_lang() || is_admin() ? $locale : ws_languages()[ ws_lang() ][0];
} );

add_action( 'init', function () {
	load_plugin_textdomain( 'ws-chapters', false, dirname( plugin_basename( __DIR__ ) ) . '/languages' );
}, 0 );

/* Links keep the language: home_url() (and so every permalink) gets the code, except files and the APIs. */
add_filter( 'home_url', function ( $url, $path, $scheme ) {
	if ( 'en' === ws_lang() || 'rest' === $scheme || ! empty( $GLOBALS['ws_lang_parsing'] ) || is_admin() && ! wp_doing_ajax() ) {
		return $url;
	}
	if ( preg_match( '#^/?(wp-json|wp-admin|wp-content|wp-includes|wp-login\.php|xmlrpc\.php|wp-sitemap)#', (string) $path ) ) {
		return $url;
	}
	$raw = ws_lang_raw_home( wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( 0 !== strpos( $url, $raw ) ) {
		return $url;
	}
	return $raw . '/' . ws_lang() . substr( $url, strlen( $raw ) );
}, 10, 3 );

/* While WordPress reads the address it compares it with the plain home address (with the code it would find no page). */
add_filter( 'do_parse_request', function ( $parse ) {
	$GLOBALS['ws_lang_parsing'] = true;
	return $parse;
}, 999 );
add_action( 'parse_request', function () {
	$GLOBALS['ws_lang_parsing'] = false;
}, -999 );

/* WordPress would "correct" /de/chapters/ to itself in a loop (it reads the address without the code). */
add_filter( 'redirect_canonical', function ( $redirect ) {
	return 'en' === ws_lang() ? $redirect : false;
} );

/** A link to a page of this site, in the language shown (links to files, the admin and other sites are left as they are). */
function ws_lang_localize_url( $url ) {
	if ( 'en' === ws_lang() ) {
		return $url;
	}
	$raw  = ws_lang_raw_home();
	$host = preg_replace( '#^https?:#', '', $raw );
	$rel  = preg_replace( '#^https?:#', '', (string) $url );
	if ( 0 !== strpos( $rel, $host . '/' ) && $rel !== $host ) {
		return $url;
	}
	$rest = ltrim( (string) substr( $rel, strlen( $host ) ), '/' );
	if ( preg_match( '#^(' . implode( '|', array_keys( ws_languages() ) ) . ')(/|$)|^(wp-|xmlrpc)#', $rest ) ) {
		return $url;
	}
	return $raw . '/' . ws_lang() . '/' . $rest;
}

/* Menu items saved as fixed addresses (Home, Chapters, Events) follow the language too. */
foreach ( array( 'core/navigation-link', 'core/navigation-submenu', 'core/home-link' ) as $ws_block ) {
	add_filter( 'render_block_' . $ws_block, function ( $html ) {
		if ( 'en' === ws_lang() ) {
			return $html;
		}
		return (string) preg_replace_callback( '#href="([^"]+)"#', function ( $m ) {
			return 'href="' . esc_url( ws_lang_localize_url( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) ) . '"';
		}, $html );
	}, 20 );
}
unset( $ws_block );

/** This page's address in another language (the request without its code, then the code added back). */
function ws_lang_url( $code ) {
	$base = ws_lang_base();
	$path = (string) ( $_SERVER['REQUEST_URI'] ?? '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- already without the code.
	$path = remove_query_arg( array( 'ws_ma', 'ws_fe', 'ws_code', 'ws_tok', 'nc' ), $path );
	$rest = ltrim( (string) substr( $path, strlen( $base ) - 1 ), '/' );
	return ws_lang_raw_home() . '/' . ( 'en' === $code ? '' : $code . '/' ) . $rest; // The raw home already holds the base path.
}

/* Search engines: each page names its versions in the other languages. */
add_action( 'wp_head', function () {
	if ( is_404() || is_search() || is_admin() ) {
		return;
	}
	foreach ( ws_languages() as $code => $lang ) {
		echo '<link rel="alternate" hreflang="' . esc_attr( 'el' === $code ? 'el' : $code ) . '" href="' . esc_url( ws_lang_url( $code ) ) . '">' . "\n";
	}
	echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( ws_lang_url( 'en' ) ) . '">' . "\n";
}, 3 );

add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'ws-lang-' . ws_lang();
	return $classes;
} );

/**
 * [ws_languages] The language menu in the header: the current code, opening a list of the languages,
 * each in its own name, leading to the same page in that language.
 */
function ws_language_switcher() {
	$current = ws_lang();
	$items   = '';
	foreach ( ws_languages() as $code => $lang ) {
		$items .= '<li><a href="' . esc_url( ws_lang_url( $code ) ) . '" hreflang="' . esc_attr( $code ) . '" lang="' . esc_attr( $code ) . '"' . ( $code === $current ? ' aria-current="true"' : '' ) . '>'
			. '<span class="ws-lang__code">' . esc_html( strtoupper( $code ) ) . '</span>' . esc_html( $lang[1] ) . '</a></li>';
	}
	return '<details class="ws-lang"><summary aria-label="' . esc_attr__( 'Language', 'ws-chapters' ) . '">'
		. '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3z"/></svg>'
		. '<span>' . esc_html( strtoupper( $current ) ) . '</span></summary><ul>' . $items . '</ul></details>'
		. '<script>(function(){document.addEventListener("click",function(e){document.querySelectorAll(".ws-lang[open]").forEach(function(d){if(!d.contains(e.target)){d.removeAttribute("open");}});});})();</script>';
}
add_action( 'init', function () {
	add_shortcode( 'ws_languages', 'ws_language_switcher' );
} );
