<?php
/**
 * Accounts: log in with email address and password; the WordPress "username" is called "Road name".
 *
 * - Only email + password logs in (username + password is switched off).
 * - Everywhere the admin says "Username", it says "Road name".
 * - A new user's public display name defaults to their road name.
 */

defined( 'ABSPATH' ) || exit;

/* Email-only login: drop WordPress's username check, keep its email check. */
add_action( 'init', function () {
	remove_filter( 'authenticate', 'wp_authenticate_username_password', 20 );
} );

/**
 * Wording on the login, password-reset, user and profile screens. Plain strings on purpose: running them
 * through __() here would re-enter the gettext filter below and recurse forever.
 */
function ws_account_wording() {
	return array(
		'Username or Email Address'                    => 'Email address',
		'Username'                                     => 'Road name',
		'Username (required)'                          => 'Road name (required)',
		'Usernames cannot be changed.'                 => 'Road names cannot be changed.',
		'Please enter a username.'                     => 'Please enter a road name.',
		'Sorry, that username already exists!'         => 'Sorry, that road name is already taken.',
		'This username is invalid because it uses illegal characters. Please enter a valid username.' => 'This road name uses characters that are not allowed. Use letters, numbers, spaces, and . - _ @ only.',
		'Please type your username or email address. You will receive an email message with instructions on how to reset your password.' => 'Enter your email address. You will receive an email with a link to set a new password.',
		'<strong>Error:</strong> The username field is empty.' => '<strong>Error:</strong> Please enter your email address.',
	);
}

add_filter( 'gettext', function ( $translation, $text, $domain ) {
	if ( 'default' !== $domain ) {
		return $translation;
	}
	static $map = null;
	if ( null === $map ) {
		$map = array(); // Guard against re-entry while the map is being built.
		$map = ws_account_wording();
	}
	return $map[ $text ] ?? $translation;
}, 10, 3 );

/* Users list column. */
add_filter( 'manage_users_columns', function ( $cols ) {
	if ( isset( $cols['username'] ) ) {
		$cols['username'] = __( 'Road name', 'ws-chapters' );
	}
	return $cols;
} );

/* The login field is an email field (right keyboard on phones, browser autofill). */
add_action( 'login_footer', function () {
	?>
	<script>
	(function () {
		var f = document.getElementById('user_login');
		if (f) { f.type = 'email'; f.autocomplete = 'email'; f.setAttribute('inputmode', 'email'); }
	})();
	</script>
	<?php
} );

/* Typed a road name instead of an email address? Say so plainly. */
add_filter( 'authenticate', function ( $user, $login ) {
	if ( $user instanceof WP_User || '' === (string) $login || is_email( $login ) ) {
		return $user;
	}
	return new WP_Error( 'ws_email_login', __( '<strong>Error:</strong> Please log in with your email address, not your road name.', 'ws-chapters' ) );
}, 30, 2 );

/* A new user is shown on the site by their road name (unless a display name was set). */
add_action( 'user_register', function ( $user_id ) {
	$user = get_userdata( $user_id );
	if ( $user && ( '' === $user->display_name || $user->display_name === $user->user_email ) ) {
		wp_update_user( array( 'ID' => $user_id, 'display_name' => $user->user_login, 'nickname' => $user->user_login ) );
	}
} );

/*
 * Privacy: no third-party calls the privacy policy doesn't list. Avatars would load from Gravatar
 * (sending a hash of each user's email); emoji images would load from WordPress.org's CDN.
 */
add_filter( 'pre_option_show_avatars', '__return_zero' );
add_action( 'init', function () {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
} );
