<?php
/**
 * Easier sign-in for members who do not remember their password.
 *
 * Email code: the member types their email and gets a 6-digit code and a "Sign me in" button by email. Either
 * works once, for 15 minutes. The code is there for the home-screen app on iPhone, which does not share Safari's
 * sign-in: the button opens Safari, the code can be typed in the app. The button opens a page with a button
 * instead of signing in at once, because mail scanners open the links in emails too.
 *
 * Staying signed in: "Remember me" is ticked by default and lasts 180 days (WordPress's own lasts 14).
 *
 * Passkeys: Face ID, a fingerprint or the phone's screen lock instead of a password (WebAuthn). A member adds
 * one per device on My profile; "Sign in with a passkey" then needs no email or password. The browser hands
 * over the public key, so no attestation or CBOR is read here; sign-ins are checked with OpenSSL.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

const WS_SI_MINUTES     = 15;
const WS_SI_TRIES       = 5;
const WS_REMEMBER_DAYS  = 180;

/* ---------------------------------------------------------------------------
 * Staying signed in
 * ------------------------------------------------------------------------ */

add_filter( 'auth_cookie_expiration', function ( $length, $user_id, $remember ) {
	return $remember ? max( $length, WS_REMEMBER_DAYS * DAY_IN_SECONDS ) : $length;
}, 10, 3 );

/** Sign this person in for 180 days, as if they had typed their password. */
function ws_si_sign_in( WP_User $user ) {
	wp_set_auth_cookie( $user->ID, true, is_ssl() );
	wp_set_current_user( $user->ID );
	do_action( 'wp_login', $user->user_login, $user ); // Marks invited people as joined, clears the attempt count.
}

/** Where to go after signing in: the page they came from (this site only) or the members' area. */
function ws_si_after( $to = '' ) {
	$to      = wp_validate_redirect( (string) $to, '' );
	$members = ws_mm_page( 'members' );
	return $to ? $to : ( $members ? get_permalink( $members ) : home_url( '/' ) );
}

/* ---------------------------------------------------------------------------
 * Email code
 * ------------------------------------------------------------------------ */

function ws_si_hash( $value ) {
	return hash_hmac( 'sha256', (string) $value, wp_salt( 'auth' ) );
}

/**
 * Start an email sign-in. Returns the request ID for the address (the next screen), or '' when this
 * connection asked too often. The answer looks the same whether or not the email belongs to a member.
 */
function ws_si_start( $email, $to = '' ) {
	$email = strtolower( trim( (string) $email ) );
	$ip    = 'ws_si_ip_' . md5( ws_login_ip() . wp_salt() );
	$sent  = (int) get_transient( $ip );
	if ( $sent >= 10 ) {
		return '';
	}
	// Asked again within a minute (a double click, an impatient member): the same request, no second email.
	$recent = 'ws_si_mail_' . md5( $email . wp_salt() );
	$req    = (string) get_transient( $recent );
	if ( '' !== $req && get_transient( 'ws_si_' . $req ) ) {
		return $req;
	}
	set_transient( $ip, $sent + 1, HOUR_IN_SECONDS );
	$req   = wp_generate_password( 24, false );
	$user  = is_email( $email ) ? get_user_by( 'email', $email ) : false;
	$code  = sprintf( '%06d', random_int( 0, 999999 ) );
	$token = wp_generate_password( 32, false );
	set_transient( 'ws_si_' . $req, array(
		'uid'   => $user ? $user->ID : 0,
		'code'  => ws_si_hash( $code ),
		'token' => ws_si_hash( $token ),
		'to'    => wp_validate_redirect( (string) $to, '' ),
		'tries' => 0,
		'until' => time() + WS_SI_MINUTES * MINUTE_IN_SECONDS,
	), WS_SI_MINUTES * MINUTE_IN_SECONDS );
	set_transient( $recent, $req, MINUTE_IN_SECONDS );
	if ( $user ) {
		ws_send_email( $user->user_email, __( 'Your sign-in code for Widows Sons Europe', 'ws-chapters' ), array(
			/* translators: %s: code */
			'preheader' => sprintf( __( 'Your code is %s. It works once, for 15 minutes.', 'ws-chapters' ), $code ),
			'heading'   => __( 'Your sign-in code', 'ws-chapters' ),
			'before'    => array( __( "Type this code on the Widows Sons Europe sign-in screen (in the browser or the app on your home screen):", 'ws-chapters' ) ),
			'code'      => substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ),
			'button'    => __( 'Or sign me in here', 'ws-chapters' ),
			'url'       => ws_ma_url( array( 'ws_code' => $req, 'ws_tok' => $token ) ),
			'after'     => array(
				/* translators: %d: minutes */
				sprintf( __( 'The code and the button work once, for %d minutes. You do not need a password.', 'ws-chapters' ), WS_SI_MINUTES ),
				__( 'If you did not ask for this, you can ignore this email: nobody can sign in without it.', 'ws-chapters' ),
			),
		) );
	}
	return $req;
}

/** Check a code or the emailed token. Returns the member, or an error code: 'expired', 'locked' or 'bad_code'. */
function ws_si_check( $req, $code = '', $token = '' ) {
	$key  = 'ws_si_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $req );
	$data = get_transient( $key );
	if ( ! is_array( $data ) || $data['until'] < time() ) {
		return 'expired';
	}
	$user = $data['uid'] ? get_userdata( $data['uid'] ) : false;
	if ( $user && ws_login_locked( $user->user_email ) ) {
		return 'locked';
	}
	$code = preg_replace( '/\D/', '', (string) $code );
	$ok   = ( '' !== $code && hash_equals( $data['code'], ws_si_hash( $code ) ) )
		|| ( '' !== (string) $token && hash_equals( $data['token'], ws_si_hash( $token ) ) );
	if ( ! $ok || ! $user ) {
		++$data['tries'];
		if ( $data['tries'] >= WS_SI_TRIES ) {
			delete_transient( $key );
		} else {
			set_transient( $key, $data, max( 1, $data['until'] - time() ) );
		}
		if ( $user ) {
			do_action( 'wp_login_failed', $user->user_email, new WP_Error( 'ws_bad_code' ) ); // Counts towards the sign-in limit.
		}
		return $data['tries'] >= WS_SI_TRIES ? 'expired' : 'bad_code';
	}
	delete_transient( $key );
	return array( $user, $data['to'] );
}

/* The forms, posted to the account page. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! ws_ma_is_page() ) {
		return;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- sign-in works without a session; the code is the proof.
	$action = sanitize_key( wp_unslash( $_POST['ws_ma_action'] ?? '' ) );
	if ( 'code_send' === $action ) {
		$email = sanitize_email( wp_unslash( $_POST['ws_email'] ?? $_POST['log'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			ws_ma_redirect( array( 'ws_ma' => 'need_email' ) );
		}
		$req = ws_si_start( $email, esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) ) );
		if ( '' === $req ) {
			ws_ma_redirect( array( 'ws_ma' => 'too_many' ) );
		}
		ws_ma_redirect( array( 'ws_code' => $req, 'ws_ma' => 'code_sent' ) );
	}
	if ( 'code_check' === $action || 'code_link' === $action ) {
		$req    = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['ws_code'] ?? '' ) );
		$result = ws_si_check(
			$req,
			'code_check' === $action ? (string) wp_unslash( $_POST['ws_si_code'] ?? '' ) : '',
			'code_link' === $action ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['ws_tok'] ?? '' ) ) : ''
		);
		if ( 'bad_code' === $result ) {
			ws_ma_redirect( array( 'ws_code' => $req, 'ws_ma' => 'bad_code' ) );
		}
		if ( ! is_array( $result ) ) {
			ws_ma_redirect( array( 'ws_ma' => 'locked' === $result ? 'locked' : 'code_expired' ) );
		}
		ws_si_sign_in( $result[0] );
		wp_safe_redirect( ws_si_after( $result[1] ) );
		exit;
	}
	if ( 'pk_delete' === $action && is_user_logged_in() ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ws_pk_nonce'] ?? '' ) ), 'ws_pk' ) ) {
			ws_ma_redirect( array( 'ws_ma' => 'expired_form' ) );
		}
		$keys = ws_pk_list( get_current_user_id() );
		unset( $keys[ sanitize_text_field( wp_unslash( $_POST['ws_pk_id'] ?? '' ) ) ] );
		update_user_meta( get_current_user_id(), 'ws_passkeys', $keys );
		ws_ma_redirect( array( 'ws_ma' => 'pk_removed' ) );
	}
	// phpcs:enable
}, 2 );

/** Messages for the screens below, added to the account page's own. */
add_filter( 'ws_ma_messages', function ( $msgs ) {
	return $msgs + array(
		'code_sent'    => array( 'success', __( 'If this email belongs to a member, a sign-in code is on its way. Check your inbox (and Junk/Spam).', 'ws-chapters' ) ),
		'bad_code'     => array( 'error', __( 'That code is not right. Please check the email and type it again.', 'ws-chapters' ) ),
		'code_expired' => array( 'error', __( 'This code has expired or was already used. Ask for a new one below.', 'ws-chapters' ) ),
		'need_email'   => array( 'error', __( 'Please type your email address first.', 'ws-chapters' ) ),
		'too_many'     => array( 'error', __( 'Too many codes were asked for from your connection. Please try again in an hour.', 'ws-chapters' ) ),
		'pk_added'     => array( 'success', __( 'Passkey added. Next time, choose "Sign in with a passkey" on this device.', 'ws-chapters' ) ),
		'pk_removed'   => array( 'success', __( 'Passkey removed.', 'ws-chapters' ) ),
	);
} );

/** The screen after "Email me a code" (enter the code), or after the email's button (one more click). */
function ws_si_code_screen( $req, $token, $notice ) {
	$submit = function ( $label ) {
		return '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn">' . esc_html( $label ) . '</button></div>';
	};
	$head = '<div class="ws-mm ws-ma">' . $notice . '<section class="ws-mm__card ws-ma__card">';
	if ( '' !== $token ) {
		return $head . '<h3 class="ws-mm__heading">' . esc_html__( 'Sign in to Widows Sons Europe', 'ws-chapters' ) . '</h3>'
			. '<form method="post" class="ws-ma__form"><input type="hidden" name="ws_ma_action" value="code_link"><input type="hidden" name="ws_code" value="' . esc_attr( $req ) . '"><input type="hidden" name="ws_tok" value="' . esc_attr( $token ) . '">'
			. $submit( __( 'Sign me in', 'ws-chapters' ) ) . '</form></section></div>' . ws_mm_script();
	}
	return $head . '<h3 class="ws-mm__heading">' . esc_html__( 'Enter your sign-in code', 'ws-chapters' ) . '</h3>'
		/* translators: %d: minutes */
		. '<p class="ws-muted">' . esc_html( sprintf( __( 'We emailed you a 6-digit code. Type it here, or tap the button in the email. It works for %d minutes.', 'ws-chapters' ), WS_SI_MINUTES ) ) . '</p>'
		. '<form method="post" class="ws-ma__form"><input type="hidden" name="ws_ma_action" value="code_check"><input type="hidden" name="ws_code" value="' . esc_attr( $req ) . '">'
		. ws_ma_input( 'ws_si_code', __( 'Code', 'ws-chapters' ), '', 'text', 'required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" class="ws-mm__input ws-ma__code"' )
		. $submit( __( 'Sign in', 'ws-chapters' ) ) . '</form>'
		. '<p class="ws-ma__switch"><a href="' . esc_url( ws_ma_url() ) . '">' . esc_html__( 'Send a new code', 'ws-chapters' ) . '</a></p></section></div>' . ws_mm_script();
}

/**
 * The sign-in form, email first: the member types their email and taps Next, then chooses how to sign in
 * (password, emailed code or passkey). Without the script both steps simply show together. One form, so
 * password managers still see the email and password as a pair. $popup: the pop-up's look instead of the page's.
 */
function ws_si_login_form( $to, $popup = false ) {
	$btn   = $popup ? 'ws-btn-gold' : 'ws-mm__btn';
	$field = function ( $name, $label, $type, $extra ) use ( $popup ) {
		return $popup
			? '<label>' . esc_html( $label ) . '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" ' . $extra . '></label>'
			: ws_ma_input( $name, $label, '', $type, $extra );
	};
	$action = ws_mm_page( 'member-account' ) ? ws_ma_url() : wp_login_url();
	return '<form method="post" action="' . esc_url( $action ) . '" class="' . ( $popup ? 'ws-login__form' : 'ws-ma__form' ) . ' ws-si-form" data-ws-steps>'
		. '<input type="hidden" name="ws_ma_action" value="login"><input type="hidden" name="redirect_to" value="' . esc_attr( $to ) . '">'
		. '<div class="ws-si-step" data-ws-step="1">'
		. $field( 'log', __( 'Email', 'ws-chapters' ), 'email', 'required autocomplete="username"' )
		. '<button type="button" class="' . esc_attr( $btn ) . ' ws-si-next" data-ws-next hidden>' . esc_html__( 'Next', 'ws-chapters' ) . '</button>'
		. '</div>'
		. '<div class="ws-si-step" data-ws-step="2">'
		. '<p class="ws-si-who" hidden><span data-ws-who></span><button type="button" class="ws-si-change" data-ws-change>' . esc_html__( 'Change', 'ws-chapters' ) . '</button></p>'
		. '<p class="ws-si-choose" hidden>' . esc_html__( 'How would you like to sign in?', 'ws-chapters' ) . '</p>'
		. $field( 'pwd', __( 'Password', 'ws-chapters' ), 'password', 'required autocomplete="current-password"' )
		. '<label class="' . ( $popup ? 'ws-login__remember' : 'ws-ma__remember' ) . '"><input type="checkbox" name="rememberme" value="forever" checked> ' . esc_html__( 'Keep me signed in on this device', 'ws-chapters' ) . '</label>'
		. '<button type="submit" class="' . esc_attr( $btn ) . ' ws-si-login">' . esc_html__( 'Log in with password', 'ws-chapters' ) . '</button>'
		. ws_si_buttons( $btn )
		. '<p class="ws-si-forgot"><a href="' . esc_url( wp_lostpassword_url( $to ) ) . '">' . esc_html__( 'Forgot your password?', 'ws-chapters' ) . '</a></p>'
		. '</div></form>';
}

/** The extra buttons under a sign-in form: email code, and passkey (shown by the script where the browser can). */
function ws_si_buttons( $btn_class ) {
	return '<div class="ws-si">'
		. '<p class="ws-si__or"><span>' . esc_html__( 'or, without a password', 'ws-chapters' ) . '</span></p>'
		. '<button type="submit" name="ws_ma_action" value="code_send" formnovalidate class="' . esc_attr( $btn_class ) . ' ws-si__alt">' . esc_html__( 'Email me a sign-in code', 'ws-chapters' ) . '</button>'
		. '<button type="button" class="' . esc_attr( $btn_class ) . ' ws-si__alt" data-ws-passkey-login hidden>' . esc_html__( 'Sign in with a passkey', 'ws-chapters' ) . '</button>'
		. '<p class="ws-si__hint" data-ws-passkey-hint hidden>' . esc_html__( 'Passkeys work after you add one: sign in with an email code, then go to My profile and tap "Add a passkey on this device".', 'ws-chapters' ) . '</p>'
		. '<p class="ws-si__msg" role="alert" hidden></p>'
		. '</div>';
}

/* ---------------------------------------------------------------------------
 * Passkeys
 * ------------------------------------------------------------------------ */

function ws_b64u( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}
function ws_b64u_dec( $str ) {
	$str = strtr( (string) $str, '-_', '+/' );
	return (string) base64_decode( $str . str_repeat( '=', ( 4 - strlen( $str ) % 4 ) % 4 ), true );
}

/** The site's passkey identity: its domain, and the address the browser reports. */
function ws_pk_rp_id() {
	return (string) wp_parse_url( home_url(), PHP_URL_HOST );
}
function ws_pk_origin() {
	$u = wp_parse_url( home_url() );
	return ( $u['scheme'] ?? 'https' ) . '://' . $u['host'] . ( isset( $u['port'] ) ? ':' . $u['port'] : '' );
}

/** A member's passkeys: credential ID => array( key (PEM), alg, name, count, added, used ). */
function ws_pk_list( $uid ) {
	$keys = get_user_meta( $uid, 'ws_passkeys', true );
	return is_array( $keys ) ? $keys : array();
}

/** The random ID passkeys carry for a member (not their user number or email). */
function ws_pk_handle( $uid, $create = true ) {
	$handle = (string) get_user_meta( $uid, 'ws_pk_handle', true );
	if ( '' === $handle && $create ) {
		$handle = ws_b64u( random_bytes( 16 ) );
		update_user_meta( $uid, 'ws_pk_handle', $handle );
	}
	return $handle;
}

function ws_pk_json( $data, $ok = true ) {
	nocache_headers();
	$ok ? wp_send_json_success( $data ) : wp_send_json_error( array( 'message' => $data ), 400 );
}

/** A fresh challenge, kept for 5 minutes for one use. */
function ws_pk_challenge( $uid ) {
	$id  = wp_generate_password( 24, false );
	$raw = random_bytes( 32 );
	set_transient( 'ws_pk_ch_' . $id, array( 'c' => ws_b64u( $raw ), 'uid' => $uid ), 5 * MINUTE_IN_SECONDS );
	return array( $id, ws_b64u( $raw ) );
}
function ws_pk_take_challenge( $id ) {
	$key  = 'ws_pk_ch_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $id );
	$data = get_transient( $key );
	delete_transient( $key );
	return is_array( $data ) ? $data : null;
}

/** Check what the browser signed: its type, our challenge and our address. Returns '' or an error. */
function ws_pk_check_client( $client_json, $type, $challenge ) {
	$c = json_decode( $client_json, true );
	if ( ! is_array( $c ) || ( $c['type'] ?? '' ) !== $type || ! hash_equals( $challenge, (string) ( $c['challenge'] ?? '' ) ) || ( $c['origin'] ?? '' ) !== ws_pk_origin() ) {
		return 'client';
	}
	return '';
}

/** Check the authenticator data: for this site, with the person present. Returns the signature count, or false. */
function ws_pk_check_auth_data( $auth ) {
	if ( strlen( $auth ) < 37 || ! hash_equals( hash( 'sha256', ws_pk_rp_id(), true ), substr( $auth, 0, 32 ) ) || ! ( ord( $auth[32] ) & 1 ) ) {
		return false;
	}
	return unpack( 'N', substr( $auth, 33, 4 ) )[1];
}

function ws_pk_pem( $spki ) {
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
}

/** Verify a sign-in. Returns the member, or an error message. Separate from the request, so it can be tested. */
function ws_pk_verify_login( $challenge, $cred_id, $client_json, $auth, $signature, $handle ) {
	if ( ws_pk_check_client( $client_json, 'webauthn.get', $challenge ) ) {
		return __( 'This passkey answer is not for this site. Please try again.', 'ws-chapters' );
	}
	$count = ws_pk_check_auth_data( $auth );
	if ( false === $count ) {
		return __( 'This passkey is not for this site.', 'ws-chapters' );
	}
	$users = '' === $handle ? array() : get_users( array( 'meta_key' => 'ws_pk_handle', 'meta_value' => $handle, 'number' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	$user  = $users ? $users[0] : null;
	$keys  = $user ? ws_pk_list( $user->ID ) : array();
	if ( ! $user || ! isset( $keys[ $cred_id ] ) ) {
		return __( 'This passkey is not known here (it may have been removed). Sign in with an email code and add it again on My profile.', 'ws-chapters' );
	}
	$key = $keys[ $cred_id ];
	if ( 1 !== openssl_verify( $auth . hash( 'sha256', $client_json, true ), $signature, $key['key'], OPENSSL_ALGO_SHA256 ) ) {
		return __( 'The passkey could not be checked. Please try again.', 'ws-chapters' );
	}
	if ( $count && $key['count'] && $count <= $key['count'] ) {
		return __( 'This passkey looks copied, so it was refused. Please sign in with an email code.', 'ws-chapters' );
	}
	$keys[ $cred_id ]['count'] = $count;
	$keys[ $cred_id ]['used']  = time();
	update_user_meta( $user->ID, 'ws_passkeys', $keys );
	return $user;
}

/** Verify and store a new passkey for the signed-in member. Returns '' or an error message. */
function ws_pk_verify_register( $uid, $challenge, $cred_id, $client_json, $auth, $spki, $alg, $name ) {
	if ( ws_pk_check_client( $client_json, 'webauthn.create', $challenge ) || false === ws_pk_check_auth_data( $auth ) ) {
		return __( 'This passkey could not be checked for this site. Please try again.', 'ws-chapters' );
	}
	if ( ! in_array( (int) $alg, array( -7, -257 ), true ) || '' === $cred_id || strlen( $cred_id ) > 1400 ) {
		return __( 'This device offers a kind of passkey the site cannot use.', 'ws-chapters' );
	}
	$pem = ws_pk_pem( $spki );
	if ( ! openssl_pkey_get_public( $pem ) ) {
		return __( 'This passkey could not be read. Please try again.', 'ws-chapters' );
	}
	$keys = ws_pk_list( $uid );
	if ( count( $keys ) >= 10 ) {
		return __( 'You already have 10 passkeys. Remove one first.', 'ws-chapters' );
	}
	$keys[ $cred_id ] = array(
		'key'   => $pem,
		'alg'   => (int) $alg,
		'name'  => '' !== $name ? $name : __( 'Passkey', 'ws-chapters' ),
		'count' => (int) ws_pk_check_auth_data( $auth ),
		'added' => time(),
		'used'  => 0,
	);
	update_user_meta( $uid, 'ws_passkeys', $keys );
	return '';
}

/* The browser's side, through admin-ajax (it stays open while the site is private, unlike the REST API). */
function ws_pk_ajax() {
	// phpcs:disable WordPress.Security.NonceVerification -- signing in has no session yet; adding a passkey checks its nonce below.
	$step = sanitize_key( wp_unslash( $_POST['step'] ?? '' ) );
	$in   = function ( $k ) {
		return (string) wp_unslash( $_POST[ $k ] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- base64url, decoded and checked.
	};
	if ( 'login_options' === $step ) {
		list( $id, $c ) = ws_pk_challenge( 0 );
		ws_pk_json( array( 'id' => $id, 'publicKey' => array( 'challenge' => $c, 'rpId' => ws_pk_rp_id(), 'timeout' => 120000, 'userVerification' => 'preferred', 'allowCredentials' => array() ) ) );
	}
	if ( 'login' === $step ) {
		$ch = ws_pk_take_challenge( $in( 'id' ) );
		if ( ! $ch ) {
			ws_pk_json( __( 'This took too long. Please try again.', 'ws-chapters' ), false );
		}
		$user = ws_pk_verify_login( $ch['c'], preg_replace( '/[^A-Za-z0-9_-]/', '', $in( 'credId' ) ), ws_b64u_dec( $in( 'clientDataJSON' ) ), ws_b64u_dec( $in( 'authenticatorData' ) ), ws_b64u_dec( $in( 'signature' ) ), preg_replace( '/[^A-Za-z0-9_-]/', '', ws_b64u_dec( $in( 'userHandle' ) ) ) );
		if ( ! $user instanceof WP_User ) {
			ws_pk_json( $user, false );
		}
		ws_si_sign_in( $user );
		ws_pk_json( array( 'redirect' => ws_si_after( esc_url_raw( $in( 'to' ) ) ) ) );
	}
	if ( ! is_user_logged_in() || ! wp_verify_nonce( sanitize_text_field( $in( 'nonce' ) ), 'ws_pk' ) ) {
		ws_pk_json( __( 'Please reload the page and try again.', 'ws-chapters' ), false );
	}
	$uid = get_current_user_id();
	if ( 'register_options' === $step ) {
		$u               = wp_get_current_user();
		list( $id, $c )  = ws_pk_challenge( $uid );
		$exclude         = array();
		foreach ( array_keys( ws_pk_list( $uid ) ) as $cred ) {
			$exclude[] = array( 'type' => 'public-key', 'id' => $cred );
		}
		ws_pk_json( array( 'id' => $id, 'publicKey' => array(
			'challenge'              => $c,
			'rp'                     => array( 'name' => 'Widows Sons Europe', 'id' => ws_pk_rp_id() ),
			'user'                   => array( 'id' => ws_b64u( ws_pk_handle( $uid ) ), 'name' => $u->user_email, 'displayName' => ws_mm_person_name( $u ) ),
			'pubKeyCredParams'       => array( array( 'type' => 'public-key', 'alg' => -7 ), array( 'type' => 'public-key', 'alg' => -257 ) ),
			'authenticatorSelection' => array( 'residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'preferred' ),
			'excludeCredentials'     => $exclude,
			'attestation'            => 'none',
			'timeout'                => 120000,
		) ) );
	}
	if ( 'register' === $step ) {
		$ch = ws_pk_take_challenge( $in( 'id' ) );
		if ( ! $ch || (int) $ch['uid'] !== $uid ) {
			ws_pk_json( __( 'This took too long. Please try again.', 'ws-chapters' ), false );
		}
		$err = ws_pk_verify_register( $uid, $ch['c'], preg_replace( '/[^A-Za-z0-9_-]/', '', $in( 'credId' ) ), ws_b64u_dec( $in( 'clientDataJSON' ) ), ws_b64u_dec( $in( 'authenticatorData' ) ), ws_b64u_dec( $in( 'publicKey' ) ), (int) $in( 'alg' ), sanitize_text_field( $in( 'name' ) ) );
		if ( '' !== $err ) {
			ws_pk_json( $err, false );
		}
		ws_pk_json( array( 'redirect' => ws_ma_url( array( 'ws_ma' => 'pk_added' ) ) ) );
	}
	ws_pk_json( 'Unknown step.', false );
	// phpcs:enable
}
add_action( 'wp_ajax_ws_passkey', 'ws_pk_ajax' );
add_action( 'wp_ajax_nopriv_ws_passkey', 'ws_pk_ajax' );

/** "Passkeys" on My profile: the member's passkeys with Remove, and "Add a passkey on this device". */
function ws_pk_profile_card() {
	$uid  = get_current_user_id();
	$rows = '';
	foreach ( ws_pk_list( $uid ) as $id => $k ) {
		$rows .= '<li class="ws-pk__item"><span><strong>' . esc_html( $k['name'] ) . '</strong> <span class="ws-muted">'
			/* translators: 1: date added, 2: date last used */
			. esc_html( sprintf( __( 'added %1$s · last used %2$s', 'ws-chapters' ), wp_date( 'j M Y', $k['added'] ), $k['used'] ? wp_date( 'j M Y', $k['used'] ) : __( 'never', 'ws-chapters' ) ) ) . '</span></span>'
			. '<form method="post"><input type="hidden" name="ws_ma_action" value="pk_delete"><input type="hidden" name="ws_pk_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="ws_pk_nonce" value="' . esc_attr( wp_create_nonce( 'ws_pk' ) ) . '">'
			. '<button type="submit" class="ws-pk__remove" data-ws-confirm="' . esc_attr__( 'Remove this passkey? That device will need an email code or password next time.', 'ws-chapters' ) . '">' . esc_html__( 'Remove', 'ws-chapters' ) . '</button></form></li>';
	}
	return '<section class="ws-mm__card ws-pk" id="passkeys"><h3 class="ws-mm__heading">' . esc_html__( 'Sign in without a password', 'ws-chapters' ) . '</h3>'
		. '<p class="ws-muted">' . esc_html__( 'Add a passkey on each phone or computer you use. Next time, choose "Sign in with a passkey" and confirm with Face ID, your fingerprint or the screen lock. No password to remember, and nothing anyone can guess.', 'ws-chapters' ) . '</p>'
		. ( $rows ? '<ul class="ws-pk__list">' . $rows . '</ul>' : '' )
		. '<p class="ws-pk__actions"><button type="button" class="ws-mm__btn" data-ws-passkey-add data-nonce="' . esc_attr( wp_create_nonce( 'ws_pk' ) ) . '" hidden>' . esc_html__( 'Add a passkey on this device', 'ws-chapters' ) . '</button></p>'
		. '<p class="ws-si__msg" role="alert" hidden></p>'
		. '<p class="ws-muted ws-pk__none" hidden>' . esc_html__( 'This browser cannot use passkeys. You can still sign in with an email code.', 'ws-chapters' ) . '</p>'
		. '</section>';
}
