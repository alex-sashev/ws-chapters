<?php
/**
 * Website feedback: a simple form for signed-in people to send comments about the website.
 * The comments are emailed to the website team and are not stored on the site.
 *
 * Page: "Website feedback" (slug member-feedback, under Members). Shortcode [ws_member_feedback].
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/** Where feedback goes. */
const WS_FEEDBACK_TO = array( 'sashev@gmail.com', 'info@wseurope.org' );

/** Send the feedback form. Runs before the page is shown, so a refresh cannot send it twice. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'member_feedback' !== ( $_POST['ws_form'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$page = ws_mm_page( 'member-feedback' );
	$back = $page ? get_permalink( $page ) : home_url( '/' );
	$user = wp_get_current_user();
	$guard = ws_form_guard_check( 'member_feedback' );
	$subject  = ws_post_field( 'ws_fb_subject' );
	$comments = ws_post_field( 'ws_fb_comments', true );
	$link     = esc_url_raw( ws_post_field( 'ws_fb_page' ), array( 'http', 'https' ) );
	$error    = null;
	if ( ! is_user_logged_in() ) {
		$error = __( 'Please sign in to send feedback.', 'ws-chapters' );
	} elseif ( true !== $guard ) {
		$error = $guard;
	} elseif ( '' === $subject || '' === $comments ) {
		$error = __( 'Please add a subject and your comments.', 'ws-chapters' );
	} elseif ( '' !== ws_post_field( 'ws_fb_page' ) && ! wp_http_validate_url( $link ) ) {
		$error = __( 'The page address must start with https:// (or leave it empty).', 'ws-chapters' );
	}
	$ok = null === $error;
	if ( $ok ) {
		$name = ws_mm_person_name( $user );
		$sent = ws_send_mail(
			WS_FEEDBACK_TO,
			'[Website feedback] ' . $subject,
			array(
				__( 'New feedback from the Widows Sons Europe website.', 'ws-chapters' ),
				'',
				__( 'From:', 'ws-chapters' ) . ' ' . $name . ' <' . $user->user_email . '>',
				__( 'Date:', 'ws-chapters' ) . ' ' . wp_date( 'j F Y, H:i' ),
				__( 'Page:', 'ws-chapters' ) . ' ' . ( $link ?: '—' ),
				'',
				__( 'Comments:', 'ws-chapters' ),
				$comments,
			),
			$user->user_email
		);
		if ( ! $sent ) {
			$ok    = false;
			$error = __( 'Sorry, your feedback could not be sent. Please try again in a few minutes.', 'ws-chapters' );
		}
	}
	$text = $ok ? __( 'Thank you for your feedback! Your suggestion has been submitted successfully.', 'ws-chapters' ) : $error;
	wp_safe_redirect( ws_mm_flash_url( $back, $ok, $text ) );
	exit;
}, 2 );

add_shortcode( 'ws_member_feedback', function () {
	if ( ! is_user_logged_in() ) {
		return '<p class="ws-muted">' . esc_html__( 'Please sign in to send feedback.', 'ws-chapters' ) . '</p>';
	}
	$out = ws_mm_subnav( 'feedback', true ) . '<div class="ws-mm alignwide ws-feedback">' . ws_mm_flash_notice();
	$out .= '<section class="ws-mm__card"><h3 class="ws-mm__heading">' . esc_html__( 'Website feedback', 'ws-chapters' ) . '</h3>'
		. '<p class="ws-muted">' . esc_html__( 'Tell us what works, what does not, and what you would change on the website. Your message is emailed to the website team, and we read every one. Please do not include passwords or other people\'s private details.', 'ws-chapters' ) . '</p>'
		. '<form method="post" class="ws-mm__grid" onsubmit="this.querySelector(\'button[type=submit]\').disabled=true;">'
		. ws_form_guard_fields( 'member_feedback' )
		. '<input type="hidden" name="ws_form" value="member_feedback">'
		. ws_mm_field( __( 'Subject', 'ws-chapters' ), '<input type="text" name="ws_fb_subject" class="ws-mm__input" maxlength="120" required placeholder="' . esc_attr__( 'For example: Chapter page photos', 'ws-chapters' ) . '">', 'ws-mm__field--wide' )
		. ws_mm_field( __( 'Your comments', 'ws-chapters' ), '<textarea name="ws_fb_comments" class="ws-mm__input ws-mm__textarea" rows="6" maxlength="4000" required></textarea>', 'ws-mm__field--wide' )
		. ws_mm_field( __( 'Page address (optional)', 'ws-chapters' ), '<input type="url" name="ws_fb_page" class="ws-mm__input" placeholder="https://">', 'ws-mm__field--wide' )
		. '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn">' . esc_html__( 'Send feedback', 'ws-chapters' ) . '</button></div>'
		. '</form></section></div>';
	return $out;
} );
