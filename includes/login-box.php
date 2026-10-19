<?php
/**
 * Member login pop-up: the login icon and "Member login" links open a small box with the sign-in form
 * (or, when signed in, the name and a Log out button). The links still go to the login page without script.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/** The pop-up markup and its script, printed once at the bottom of every page. */
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	$back = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // Back here after signing in.
	if ( is_user_logged_in() ) {
		$user  = wp_get_current_user();
		$inner = '<p class="ws-login__hello">' . sprintf( /* translators: %s: name */ esc_html__( 'Signed in as %s.', 'ws-chapters' ), esc_html( $user->display_name ) ) . '</p>'
			. '<p><a class="ws-btn-gold" href="' . esc_url( home_url( '/members/' ) ) . '">' . esc_html__( 'Members area', 'ws-chapters' ) . '</a></p>'
			. '<p><a href="' . esc_url( ws_ma_url() ) . '">' . esc_html__( 'My profile', 'ws-chapters' ) . '</a></p>'
			. '<p><a class="ws-login__logout" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'ws-chapters' ) . '</a></p>';
	} else {
		$action = ws_mm_page( 'member-account' ) ? ws_ma_url() : wp_login_url();
		$inner  = '<form method="post" action="' . esc_url( $action ) . '" class="ws-login__form"><input type="hidden" name="ws_ma_action" value="login">'
			. '<label>' . esc_html__( 'Email', 'ws-chapters' ) . '<input type="email" name="log" autocomplete="username" required></label>'
			. '<label>' . esc_html__( 'Password', 'ws-chapters' ) . '<input type="password" name="pwd" autocomplete="current-password" required></label>'
			. '<label class="ws-login__remember"><input type="checkbox" name="rememberme" value="forever"> ' . esc_html__( 'Remember me', 'ws-chapters' ) . '</label>'
			. '<input type="hidden" name="redirect_to" value="' . esc_url( $back ) . '">'
			. '<p><button type="submit" class="ws-btn-gold">' . esc_html__( 'Log in', 'ws-chapters' ) . '</button></p>'
			. '<p><a href="' . esc_url( wp_lostpassword_url( $back ) ) . '">' . esc_html__( 'Forgot your password?', 'ws-chapters' ) . '</a></p>'
			. '</form>';
	}
	echo '<div class="ws-login" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Member login', 'ws-chapters' ) . '" hidden>'
		. '<div class="ws-login__box"><button type="button" class="ws-login__close" aria-label="' . esc_attr__( 'Close', 'ws-chapters' ) . '">&times;</button>'
		. '<h2 class="ws-login__title">' . esc_html__( 'Member login', 'ws-chapters' ) . '</h2>' . $inner . '</div></div>'
		. '<script>(function(){var box=document.querySelector(".ws-login");if(!box)return;'
		. 'function open(e){if(e){e.preventDefault();}box.hidden=false;var f=box.querySelector("input");if(f){f.focus();}}'
		. 'function close(){box.hidden=true;}'
		. 'document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("[data-ws-login]");if(a){open(e);return;}if(e.target===box||(e.target.closest&&e.target.closest(".ws-login__close"))){close();}});'
		. 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&!box.hidden){close();}});'
		. '})();</script>';
} );
