<?php
/**
 * The member's own account, on the site itself (not the WordPress admin): sign in, choose a password from
 * the invitation email, ask for a new password, and edit "My profile" (name, road name, phone, title, photo).
 *
 * Page: "My profile" (slug member-account, under Members), shortcode [ws_member_account].
 * The road name is WordPress's Nickname field; the title is the same one the managers set.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/** Address of the account page, with optional query arguments. */
function ws_ma_url( $args = array() ) {
	$page = ws_mm_page( 'member-account' );
	return add_query_arg( $args, $page ? get_permalink( $page ) : home_url( '/' ) );
}

function ws_ma_is_page() {
	$page = ws_mm_page( 'member-account' );
	return $page && is_page( $page->ID );
}

/** Messages shown after a redirect, by code (the code is in the address, so nothing personal is). */
function ws_ma_messages() {
	return array(
		'bad_login' => array( 'error', __( 'The email or password is not right. Please try again, or ask for a new password below.', 'ws-chapters' ) ),
		'locked'    => array( 'error', __( 'Too many sign-in attempts. Please wait 15 minutes and try again, or ask for a new password below.', 'ws-chapters' ) ),
		'expired'   => array( 'error', __( 'This link has expired or was already used. Enter your email below and we will send you a new one.', 'ws-chapters' ) ),
		'short'     => array( 'error', __( 'Please choose a password of at least 8 characters.', 'ws-chapters' ) ),
		'mismatch'  => array( 'error', __( 'The two passwords are not the same. Please type them again.', 'ws-chapters' ) ),
		'sent'      => array( 'success', __( 'If this email belongs to a member, a link to choose a new password is on its way. It expires in 7 days.', 'ws-chapters' ) ),
		'welcome'   => array( 'success', __( 'Your password is set and you are signed in. Please complete your profile.', 'ws-chapters' ) ),
		'saved'     => array( 'success', __( 'Your profile is saved.', 'ws-chapters' ) ),
		'title'     => array( 'error', __( 'For "Other", please type your title.', 'ws-chapters' ) ),
		'expired_form' => array( 'error', __( 'The form expired. Please try again.', 'ws-chapters' ) ),
	);
}

function ws_ma_redirect( $args ) {
	wp_safe_redirect( ws_ma_url( $args ) );
	exit;
}

/* The account page stays reachable while the site is private: people sign in and set their password there. */
add_filter( 'ws_private_mode_open', function ( $open ) {
	return $open || ws_ma_is_page();
} );

/* Sign-in and "forgot password" links on the site go to the account page (the admin keeps WordPress's own). */
add_filter( 'login_url', function ( $url, $redirect ) {
	if ( is_admin() || ! ws_mm_page( 'member-account' ) || false !== strpos( $url, 'interim-login' ) ) {
		return $url;
	}
	return ws_ma_url( $redirect ? array( 'redirect_to' => rawurlencode( $redirect ) ) : array() );
}, 10, 2 );
add_filter( 'lostpassword_url', function ( $url ) {
	return is_admin() || ! ws_mm_page( 'member-account' ) ? $url : ws_ma_url( array( 'ws_forgot' => 1 ) );
} );

/* The forms. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['ws_ma_action'] ) || ! ws_ma_is_page() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- sign-in and password forms work without a session; the profile form is checked below.
	$action = sanitize_key( $_POST['ws_ma_action'] );

	if ( 'login' === $action ) {
		$user = wp_signon( array(
			'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
			'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- passwords are not altered.
			'remember'      => ! empty( $_POST['rememberme'] ),
		), is_ssl() );
		if ( is_wp_error( $user ) ) {
			ws_ma_redirect( array( 'ws_ma' => 'ws_locked' === $user->get_error_code() ? 'locked' : 'bad_login' ) );
		}
		$to = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) ), '' );
		$members = ws_mm_page( 'members' );
		wp_safe_redirect( $to ? $to : ( $members ? get_permalink( $members ) : home_url( '/' ) ) );
		exit;
	}

	if ( 'setpass' === $action ) {
		$login = sanitize_user( wp_unslash( $_POST['ws_login'] ?? '' ) );
		$key   = preg_replace( '/[^a-zA-Z0-9]/', '', (string) wp_unslash( $_POST['ws_key'] ?? '' ) );
		$user  = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			ws_ma_redirect( array( 'ws_ma' => 'expired', 'ws_forgot' => 1 ) );
		}
		$p1 = (string) wp_unslash( $_POST['pass1'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$p2 = (string) wp_unslash( $_POST['pass2'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( strlen( $p1 ) < 8 || $p1 !== $p2 ) {
			ws_ma_redirect( array( 'ws_ma' => strlen( $p1 ) < 8 ? 'short' : 'mismatch', 'ws_key' => $key, 'ws_login' => rawurlencode( $login ) ) );
		}
		reset_password( $user, $p1 ); // Also runs after_password_reset, which marks an invited person as joined.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		ws_ma_redirect( array( 'ws_ma' => 'welcome' ) );
	}

	if ( 'forgot' === $action ) {
		$email = sanitize_email( wp_unslash( $_POST['ws_email'] ?? '' ) );
		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
		if ( $user ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				ws_send_email( $user->user_email, __( 'Your new password for Widows Sons Europe', 'ws-chapters' ), array(
					'preheader' => __( "Choose a new password for the Widows Sons Europe members' area.", 'ws-chapters' ),
					'heading'   => __( 'Choose a new password', 'ws-chapters' ),
					'before'    => array( __( "Someone (hopefully you) asked for a new password for the Widows Sons Europe members' area.", 'ws-chapters' ) ),
					'button'    => __( 'Choose my new password', 'ws-chapters' ),
					'url'       => ws_ma_url( array( 'ws_key' => $key, 'ws_login' => rawurlencode( $user->user_login ) ) ),
					'after'     => array(
						/* translators: %d: days */
						sprintf( __( 'This link expires in %d days.', 'ws-chapters' ), WS_MM_INVITE_DAYS ),
						__( 'If you did not ask for this, you can ignore this email: your password stays the same.', 'ws-chapters' ),
					),
				) );
			}
		}
		ws_ma_redirect( array( 'ws_ma' => 'sent' ) ); // Same answer either way: the form does not tell who is a member.
	}
	// phpcs:enable

	if ( 'profile' === $action && is_user_logged_in() ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ws_ma_nonce'] ?? '' ) ), 'ws_ma_profile' ) ) {
			ws_ma_redirect( array( 'ws_ma' => 'expired_form' ) );
		}
		$uid   = get_current_user_id();
		$title = ws_mm_resolve_title(
			sanitize_text_field( wp_unslash( $_POST['ws_title'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['ws_title_other'] ?? '' ) ),
			(string) get_user_meta( $uid, 'ws_title', true )
		);
		if ( false === $title ) {
			ws_ma_redirect( array( 'ws_ma' => 'title' ) );
		}
		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$road  = sanitize_text_field( wp_unslash( $_POST['nickname'] ?? '' ) );
		$data  = array( 'ID' => $uid, 'first_name' => $first, 'last_name' => $last );
		if ( '' !== $road ) {
			$data['nickname']     = $road;
			$data['display_name'] = $road; // Shown on the site by road name, as before.
		}
		wp_update_user( $data );
		update_user_meta( $uid, 'ws_phone', sanitize_text_field( wp_unslash( $_POST['ws_phone'] ?? '' ) ) );
		if ( '' === $title ) {
			delete_user_meta( $uid, 'ws_title' );
		} else {
			update_user_meta( $uid, 'ws_title', $title );
		}
		ws_mm_save_avatar( $uid ); // Only when a photo was chosen.
		$p1 = (string) wp_unslash( $_POST['pass1'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $p1 ) {
			$p2 = (string) wp_unslash( $_POST['pass2'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( strlen( $p1 ) < 8 || $p1 !== $p2 ) {
				ws_ma_redirect( array( 'ws_ma' => strlen( $p1 ) < 8 ? 'short' : 'mismatch' ) );
			}
			wp_set_password( $p1, $uid );
			wp_set_auth_cookie( $uid, true, is_ssl() ); // Setting a password signs out; sign back in.
		}
		ws_mm_mark_joined( get_userdata( $uid ) );
		ws_mm_log( 'profile', $uid, $title );
		do_action( 'litespeed_purge_all' );
		ws_ma_redirect( array( 'ws_ma' => 'saved' ) );
	}
}, 2 );

/** A labelled text field in the member-page style. */
function ws_ma_input( $name, $label, $value = '', $type = 'text', $extra = '' ) {
	return ws_mm_field( $label, '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" class="ws-mm__input" value="' . esc_attr( $value ) . '" ' . $extra . '>' );
}

add_shortcode( 'ws_member_account', function () {
	// phpcs:disable WordPress.Security.NonceVerification -- read-only.
	$code   = isset( $_GET['ws_ma'] ) ? sanitize_key( wp_unslash( $_GET['ws_ma'] ) ) : '';
	$key    = isset( $_GET['ws_key'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', (string) wp_unslash( $_GET['ws_key'] ) ) : '';
	$login  = isset( $_GET['ws_login'] ) ? sanitize_user( rawurldecode( wp_unslash( $_GET['ws_login'] ) ) ) : '';
	$forgot = ! empty( $_GET['ws_forgot'] );
	$to     = isset( $_GET['redirect_to'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['redirect_to'] ) ) ) : '';
	// phpcs:enable
	$msgs   = ws_ma_messages();
	$notice = isset( $msgs[ $code ] ) ? ws_form_notice( $msgs[ $code ][0], esc_html( $msgs[ $code ][1] ) ) : '';
	$submit = function ( $label ) {
		return '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn">' . esc_html( $label ) . '</button></div>';
	};

	// 1. From the invitation (or a new-password email): choose a password.
	if ( '' !== $key && '' !== $login ) {
		$user = check_password_reset_key( $key, $login );
		if ( is_wp_error( $user ) ) {
			$notice = ws_form_notice( 'error', esc_html( $msgs['expired'][1] ) );
			$forgot = true;
		} else {
			return '<div class="ws-mm ws-ma">' . $notice . '<section class="ws-mm__card ws-ma__card"><h3 class="ws-mm__heading">' . esc_html__( 'Welcome to Widows Sons Europe', 'ws-chapters' ) . '</h3>'
				/* translators: %s: email address */
				. '<p class="ws-muted">' . esc_html( sprintf( __( 'Choose a password for %s. You will use your email and this password to sign in.', 'ws-chapters' ), $user->user_email ) ) . '</p>'
				. '<form method="post" class="ws-ma__form"><input type="hidden" name="ws_ma_action" value="setpass"><input type="hidden" name="ws_key" value="' . esc_attr( $key ) . '"><input type="hidden" name="ws_login" value="' . esc_attr( $login ) . '">'
				. ws_ma_input( 'pass1', __( 'New password (at least 8 characters)', 'ws-chapters' ), '', 'password', 'required minlength="8" autocomplete="new-password"' )
				. ws_ma_input( 'pass2', __( 'Type it again', 'ws-chapters' ), '', 'password', 'required minlength="8" autocomplete="new-password"' )
				. $submit( __( 'Set password and continue', 'ws-chapters' ) ) . '</form></section></div>' . ws_mm_script();
		}
	}

	// 2. Signed in: My profile.
	if ( is_user_logged_in() ) {
		$u     = wp_get_current_user();
		$title = (string) get_user_meta( $u->ID, 'ws_title', true );
		$road  = ws_mm_road_name( $u );
		$photo = ws_mm_avatar_html( $u->ID, ws_mm_person_name( $u ) );
		$out   = ws_mm_subnav( 'account', true ) . '<div class="ws-mm alignwide ws-ma">' . $notice
			. '<section class="ws-mm__card"><h3 class="ws-mm__heading">' . esc_html__( 'My profile', 'ws-chapters' ) . '</h3>'
			. '<p class="ws-muted">' . esc_html__( 'This is how other members see you in the member list.', 'ws-chapters' ) . '</p>'
			. '<form method="post" enctype="multipart/form-data" class="ws-mm__grid ws-ma__profile"><input type="hidden" name="ws_ma_action" value="profile"><input type="hidden" name="ws_ma_nonce" value="' . esc_attr( wp_create_nonce( 'ws_ma_profile' ) ) . '">'
			. ws_ma_input( 'first_name', __( 'First name', 'ws-chapters' ), $u->first_name, 'text', 'required autocomplete="given-name"' )
			. ws_ma_input( 'last_name', __( 'Last name', 'ws-chapters' ), $u->last_name, 'text', 'required autocomplete="family-name"' )
			. ws_ma_input( 'nickname', __( 'Road name', 'ws-chapters' ), $road, 'text', 'required' )
			. ws_ma_input( 'ws_phone', __( 'Phone', 'ws-chapters' ), (string) get_user_meta( $u->ID, 'ws_phone', true ), 'tel', 'autocomplete="tel" placeholder="+359 88 123 4567"' )
			. ws_mm_field( __( 'Title', 'ws-chapters' ), ws_mm_title_select( 'ws_title', $title, __( 'No title', 'ws-chapters' ) ) . ws_mm_title_other_input( 'ws_title_other' ) )
			. ws_mm_field( __( 'Email (to sign in)', 'ws-chapters' ), '<input type="email" class="ws-mm__input" value="' . esc_attr( $u->user_email ) . '" disabled>' )
			. ws_mm_field( __( 'Country', 'ws-chapters' ), '<input type="text" class="ws-mm__input" value="' . esc_attr( ws_mm_country_names( $u->ID ) ) . '" disabled>' )
			. ws_mm_field( __( 'Chapter', 'ws-chapters' ), '<input type="text" class="ws-mm__input" value="' . esc_attr( ws_mm_chapter_names( $u->ID ) ) . '" disabled>' )
			. '<div class="ws-ma__photo">' . $photo . ws_mm_field( __( 'Photo (JPG, PNG, WebP or GIF, up to 4 MB)', 'ws-chapters' ), '<input type="file" name="ws_avatar_file" class="ws-mm__input ws-ma__file" accept="image/jpeg,image/png,image/webp,image/gif">' ) . '</div>'
			. '<p class="ws-mm__hint ws-muted">' . esc_html__( 'Your country and chapter are set by your Country Admin. To change your email, ask them.', 'ws-chapters' ) . '</p>'
			. '<details class="ws-ma__password"><summary>' . esc_html__( 'Change password', 'ws-chapters' ) . '</summary><div class="ws-mm__grid">'
			. ws_ma_input( 'pass1', __( 'New password (at least 8 characters)', 'ws-chapters' ), '', 'password', 'minlength="8" autocomplete="new-password"' )
			. ws_ma_input( 'pass2', __( 'Type it again', 'ws-chapters' ), '', 'password', 'minlength="8" autocomplete="new-password"' )
			. '</div></details>'
			. $submit( __( 'Save my profile', 'ws-chapters' ) ) . '</form></section>'
			. '<p class="ws-ma__logout"><a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'ws-chapters' ) . '</a></p></div>';
		return $out . ws_mm_script();
	}

	// 3. Signed out: forgot password, or sign in.
	if ( $forgot ) {
		return '<div class="ws-mm ws-ma">' . $notice . '<section class="ws-mm__card ws-ma__card"><h3 class="ws-mm__heading">' . esc_html__( 'Forgot your password?', 'ws-chapters' ) . '</h3>'
			. '<p class="ws-muted">' . esc_html__( 'Enter the email you were invited with. We will send you a link to choose a new password.', 'ws-chapters' ) . '</p>'
			. '<form method="post" class="ws-ma__form"><input type="hidden" name="ws_ma_action" value="forgot">'
			. ws_ma_input( 'ws_email', __( 'Email', 'ws-chapters' ), '', 'email', 'required autocomplete="email"' )
			. $submit( __( 'Send me a link', 'ws-chapters' ) ) . '</form>'
			. '<p class="ws-ma__switch"><a href="' . esc_url( ws_ma_url() ) . '">' . esc_html__( 'Back to sign in', 'ws-chapters' ) . '</a></p></section></div>' . ws_mm_script();
	}
	return '<div class="ws-mm ws-ma">' . $notice . '<section class="ws-mm__card ws-ma__card"><h3 class="ws-mm__heading">' . esc_html__( 'Member login', 'ws-chapters' ) . '</h3>'
		. '<form method="post" class="ws-ma__form"><input type="hidden" name="ws_ma_action" value="login"><input type="hidden" name="redirect_to" value="' . esc_attr( $to ) . '">'
		. ws_ma_input( 'log', __( 'Email', 'ws-chapters' ), '', 'email', 'required autocomplete="username"' )
		. ws_ma_input( 'pwd', __( 'Password', 'ws-chapters' ), '', 'password', 'required autocomplete="current-password"' )
		. '<label class="ws-ma__remember"><input type="checkbox" name="rememberme" value="forever"> ' . esc_html__( 'Remember me', 'ws-chapters' ) . '</label>'
		. $submit( __( 'Log in', 'ws-chapters' ) ) . '</form>'
		. '<p class="ws-ma__switch"><a href="' . esc_url( ws_ma_url( array( 'ws_forgot' => 1 ) ) ) . '">' . esc_html__( 'Forgot your password?', 'ws-chapters' ) . '</a></p></section></div>' . ws_mm_script();
} );
