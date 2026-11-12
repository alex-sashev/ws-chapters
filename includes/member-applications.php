<?php
/**
 * Applications to join (1.0.25): the Applications page under Members, between Member management and
 * Website feedback. Super Admins see every application, Country Admins those for their countries and
 * Chapter Editors those for their chapters.
 *
 * Each application waiting for an answer can be
 * - accepted with "Send invitation": a pop-up asks for the role, country and chapter (filled in from the
 *   application, with the same choices and checks as Invite a member), then the account is created with the
 *   applicant's name and phone and the invitation email goes out;
 * - rejected with "Reject application": a pop-up asks for a message, which is emailed to the applicant
 *   (a reply goes to the person who rejected it).
 * Answered applications stay listed, folded, with who answered, when, and how.
 *
 * Shortcode: [ws_member_applications]. Applications themselves are made by the Join form (forms.php).
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/** 'new', 'invited' or 'rejected'. */
function ws_app_status( $id ) {
	return (string) get_post_meta( $id, '_ws_status', true ) ?: 'new';
}

function ws_app_field( $id, $key ) {
	return (string) get_post_meta( $id, '_ws_' . $key, true );
}

/** Whether the current user may see and answer this application: everyone's for a Super Admin, their country's for a Country Admin, their chapter's for a Chapter Editor. */
function ws_app_may_handle( $id ) {
	$post = get_post( $id );
	if ( ! $post || 'ws_application' !== $post->post_type || ! is_user_logged_in() ) {
		return false;
	}
	if ( ws_mm_is_super() ) {
		return true;
	}
	if ( ws_mm_is_chapter_editor() ) {
		return in_array( (int) ws_app_field( $id, 'chapter' ), ws_mm_editor_chapters(), true );
	}
	if ( ws_mm_is_country_admin() ) {
		return in_array( (int) ws_app_field( $id, 'country' ), ws_mm_manageable_countries(), true );
	}
	return false;
}

/** The applications the current user may see, newest first. */
function ws_app_visible() {
	$ids = get_posts( array( 'post_type' => 'ws_application', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'date', 'order' => 'DESC', 'fields' => 'ids', 'no_found_rows' => true ) );
	return array_values( array_filter( $ids, 'ws_app_may_handle' ) );
}

/** Applications waiting for the current user's answer (for the count on the tab). */
function ws_app_pending_count() {
	return count( array_filter( ws_app_visible(), function ( $id ) {
		return 'new' === ws_app_status( $id );
	} ) );
}

function ws_app_name( $id ) {
	return trim( ws_app_field( $id, 'first_name' ) . ' ' . ws_app_field( $id, 'last_name' ) );
}

/* The two answers. Posted with ws_app_action, so Member management's own handler leaves them alone. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['ws_app_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$id     = absint( $_POST['ws_app'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$action = sanitize_key( wp_unslash( $_POST['ws_app_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$ok     = false;
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ws_mm_nonce'] ?? '' ) ), 'ws_mm' ) || ! ws_mm_can_manage() || ! ws_app_may_handle( $id ) ) {
		$msg = __( 'You do not have permission to do that.', 'ws-chapters' );
	} elseif ( 'new' !== ws_app_status( $id ) ) {
		$msg = __( 'This application has already been answered.', 'ws-chapters' );
	} elseif ( 'invite' === $action ) {
		// The same checks and email as Invite a member; the address is the applicant's.
		$email                 = ws_app_field( $id, 'email' );
		$_POST['ws_mm_email'] = $email;
		$msg                   = ws_mm_invite();
		$user                  = null === $msg ? get_user_by( 'email', $email ) : null;
		if ( $user ) {
			$first = ws_app_field( $id, 'first_name' );
			$last  = ws_app_field( $id, 'last_name' );
			wp_update_user( array( 'ID' => $user->ID, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim( $first . ' ' . $last ) ) );
			update_user_meta( $user->ID, 'ws_phone', ws_app_field( $id, 'phone' ) );
			update_post_meta( $id, '_ws_status', 'invited' );
			update_post_meta( $id, '_ws_user', $user->ID );
			update_post_meta( $id, '_ws_answered_by', get_current_user_id() );
			update_post_meta( $id, '_ws_answered_at', gmdate( 'c' ) );
			ws_mm_log( 'app_invited', $user->ID, ws_app_name( $id ) );
			$ok = true;
			/* translators: 1: applicant name, 2: email address */
			$msg = sprintf( __( 'Invitation sent to %1$s (%2$s). The application is marked as invited.', 'ws-chapters' ), ws_app_name( $id ), $email );
		}
	} elseif ( 'reject' === $action ) {
		$text = trim( sanitize_textarea_field( wp_unslash( $_POST['ws_app_message'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $text ) {
			$msg = __( 'Please write a message to the applicant.', 'ws-chapters' );
		} else {
			$me      = wp_get_current_user();
			$chapter = (int) ws_app_field( $id, 'chapter' );
			$content = array(
				'preheader' => __( 'An answer to your application to join Widows Sons.', 'ws-chapters' ),
				'heading'   => __( 'Your application', 'ws-chapters' ),
				'before'    => array(
					/* translators: %s: applicant's first name */
					sprintf( __( 'Dear Brother %s,', 'ws-chapters' ), ws_app_field( $id, 'first_name' ) ),
					/* translators: %s: chapter name */
					sprintf( __( 'Thank you for your application to join %s. Here is our answer:', 'ws-chapters' ), $chapter ? ws_chapter_name( $chapter ) : 'Widows Sons' ),
					$text,
				),
				'after'     => array( ws_mm_person_name( $me ) . ( $chapter ? "\n" . ws_chapter_name( $chapter ) : '' ) ),
			);
			$sent = wp_mail(
				ws_app_field( $id, 'email' ),
				__( 'Your application to Widows Sons', 'ws-chapters' ),
				ws_email_html( $content ),
				array( 'Content-Type: text/html; charset=UTF-8', 'Reply-To: ' . ws_mm_person_name( $me ) . ' <' . $me->user_email . '>' )
			);
			if ( ! $sent ) {
				$msg = __( 'The email could not be sent, so the application is still waiting. Please try again.', 'ws-chapters' );
			} else {
				update_post_meta( $id, '_ws_status', 'rejected' );
				update_post_meta( $id, '_ws_reject_message', $text );
				update_post_meta( $id, '_ws_answered_by', get_current_user_id() );
				update_post_meta( $id, '_ws_answered_at', gmdate( 'c' ) );
				ws_mm_log( 'app_rejected', 0, ws_app_name( $id ) . ' <' . ws_app_field( $id, 'email' ) . '>' );
				$ok = true;
				/* translators: %s: applicant name */
				$msg = sprintf( __( 'The application of %s was rejected and your message was emailed to the applicant.', 'ws-chapters' ), ws_app_name( $id ) );
			}
		}
	} else {
		$msg = __( 'You do not have permission to do that.', 'ws-chapters' );
	}
	$back = get_queried_object_id() ? get_permalink( get_queried_object_id() ) : home_url( '/' );
	wp_safe_redirect( ws_mm_flash_url( $back, $ok, (string) $msg ) );
	exit;
}, 2 );

/** One application's details, for the table and the pop-ups. */
function ws_app_details( $id ) {
	$chapter = (int) ws_app_field( $id, 'chapter' );
	$term    = get_term( (int) ws_app_field( $id, 'country' ), 'ws_country' );
	return array(
		'name'    => ws_app_name( $id ),
		'email'   => ws_app_field( $id, 'email' ),
		'phone'   => ws_app_field( $id, 'phone' ),
		'lodge'   => ws_app_field( $id, 'lodge' ),
		'message' => ws_app_field( $id, 'message' ),
		'country' => $term && ! is_wp_error( $term ) ? $term->name : '',
		'chapter' => $chapter && get_post( $chapter ) ? ws_chapter_name( $chapter ) : '',
		'date'    => get_the_date( 'j M Y', $id ),
	);
}

add_shortcode( 'ws_member_applications', function () {
	if ( ! is_user_logged_in() || ! ws_mm_can_manage() ) {
		return '';
	}
	$out   = ws_mm_subnav( 'applications', true ) . '<div class="ws-mm alignwide">' . ws_mm_flash_notice();
	$nonce = '<input type="hidden" name="ws_mm_nonce" value="' . esc_attr( wp_create_nonce( 'ws_mm' ) ) . '">';

	// The choices of Invite a member, for the invitation pop-up.
	$roles = ws_mm_is_super() ? ws_mm_role_labels() : array_intersect_key( ws_mm_role_labels(), array( 'ws_member' => 1, 'ws_chapter_editor' => 1 ) );
	$chaps = ws_mm_manageable_chapters();
	$mine  = array_values( array_unique( array_filter( array_map( function ( $c ) {
		$t = ws_chapter_country( $c->ID );
		return $t ? (int) $t->term_id : 0;
	}, $chaps ) ) ) );
	$terms = $mine ? (array) get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false, 'include' => $mine ) ) : array();

	$open     = '';
	$answered = '';
	$dialogs  = '';
	foreach ( ws_app_visible() as $id ) {
		$d      = ws_app_details( $id );
		$status = ws_app_status( $id );
		$person = '<div class="ws-mm__person"><span><strong>' . esc_html( $d['name'] ) . '</strong>'
			. '<a class="ws-mm__email" href="mailto:' . esc_attr( $d['email'] ) . '">' . esc_html( $d['email'] ) . '</a>'
			. ( $d['phone'] ? '<a class="ws-mm__email" href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $d['phone'] ) ) . '">' . esc_html( $d['phone'] ) . '</a>' : '' ) . '</span></div>';
		$message = '' !== $d['message'] ? '<details class="ws-app__msg"><summary>' . esc_html__( 'Message', 'ws-chapters' ) . '</summary><p>' . nl2br( esc_html( $d['message'] ) ) . '</p></details>' : ws_mm_cell( '' );
		$where   = '<td data-label="' . esc_attr__( 'Lodge', 'ws-chapters' ) . '">' . ws_mm_cell( $d['lodge'] ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Chapter', 'ws-chapters' ) . '">' . ws_mm_cell( $d['chapter'] ) . ( $d['country'] ? '<span class="ws-muted ws-app__country">' . esc_html( $d['country'] ) . '</span>' : '' ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Received', 'ws-chapters' ) . '">' . esc_html( $d['date'] ) . '</td>';

		if ( 'new' !== $status ) {
			$by    = get_userdata( (int) get_post_meta( $id, '_ws_answered_by', true ) );
			$when  = mysql2date( 'j M Y', (string) get_post_meta( $id, '_ws_answered_at', true ) );
			$label = 'invited' === $status ? __( 'Invited', 'ws-chapters' ) : __( 'Rejected', 'ws-chapters' );
			$note  = 'rejected' === $status ? '<details class="ws-app__msg"><summary>' . esc_html__( 'Message sent', 'ws-chapters' ) . '</summary><p>' . nl2br( esc_html( (string) get_post_meta( $id, '_ws_reject_message', true ) ) ) . '</p></details>' : '';
			$answered .= '<tr><td>' . $person . '</td>' . $where
				. '<td data-label="' . esc_attr__( 'Answer', 'ws-chapters' ) . '"><span class="ws-mm__status ws-mm__status--' . ( 'invited' === $status ? 'active' : 'rejected' ) . '">' . esc_html( $label ) . '</span>'
				/* translators: 1: person, 2: date */
				. '<span class="ws-muted ws-app__by">' . esc_html( sprintf( __( 'by %1$s, %2$s', 'ws-chapters' ), $by ? ws_mm_person_name( $by ) : '?', $when ) ) . '</span>' . $note . '</td></tr>';
			continue;
		}

		$invite_id = 'ws-app-invite-' . $id;
		$reject_id = 'ws-app-reject-' . $id;
		$rhtml     = '<select name="ws_mm_role" class="ws-mm__input">';
		foreach ( $roles as $k => $label ) {
			$rhtml .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		$rhtml .= '</select>';
		$app_country = (int) ws_app_field( $id, 'country' );
		$app_chapter = (int) ws_app_field( $id, 'chapter' );
		$close       = '<form method="dialog" class="ws-app-dialog__close-form"><button type="submit" class="ws-app-dialog__close" aria-label="' . esc_attr__( 'Close', 'ws-chapters' ) . '">&times;</button></form>';
		$hidden      = $nonce . '<input type="hidden" name="ws_app" value="' . (int) $id . '">';

		$dialogs .= '<dialog class="ws-app-dialog" id="' . $invite_id . '" aria-labelledby="' . $invite_id . '-t">' . $close
			. '<h3 class="ws-mm__heading" id="' . $invite_id . '-t">' . esc_html__( 'Send invitation', 'ws-chapters' ) . '</h3>'
			/* translators: 1: applicant name, 2: email address */
			. '<p class="ws-muted">' . esc_html( sprintf( __( 'The invitation goes to %1$s at %2$s. Their name and phone are taken from the application.', 'ws-chapters' ), $d['name'], $d['email'] ) ) . '</p>'
			. '<form method="post" class="ws-mm__grid ws-app-dialog__grid">' . $hidden . '<input type="hidden" name="ws_app_action" value="invite">'
			. ws_mm_field( __( 'Role', 'ws-chapters' ), $rhtml )
			. ws_mm_field( __( 'Country', 'ws-chapters' ), str_replace( '<select ', '<select required ', ws_mm_country_select( 'ws_mm_country', $terms, $app_country, count( $terms ) > 1 ? __( 'Choose a country', 'ws-chapters' ) : '' ) ) )
			. ws_mm_field( __( 'Chapter', 'ws-chapters' ), str_replace( '<select ', '<select required ', ws_mm_chapter_select( 'ws_mm_chapter', $chaps, $app_chapter, __( 'Choose a chapter', 'ws-chapters' ) ) ) )
			. '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn">' . esc_html__( 'Send invitation', 'ws-chapters' ) . '</button></div></form></dialog>';

		$dialogs .= '<dialog class="ws-app-dialog" id="' . $reject_id . '" aria-labelledby="' . $reject_id . '-t">' . $close
			. '<h3 class="ws-mm__heading" id="' . $reject_id . '-t">' . esc_html__( 'Reject application', 'ws-chapters' ) . '</h3>'
			/* translators: 1: applicant name, 2: email address */
			. '<p class="ws-muted">' . esc_html( sprintf( __( 'Your message is emailed to %1$s at %2$s. A reply comes to you.', 'ws-chapters' ), $d['name'], $d['email'] ) ) . '</p>'
			. '<form method="post" class="ws-app-dialog__form">' . $hidden . '<input type="hidden" name="ws_app_action" value="reject">'
			. ws_mm_field( __( 'Message to the applicant', 'ws-chapters' ), '<textarea name="ws_app_message" class="ws-mm__input" rows="6" maxlength="3000" required></textarea>' )
			. '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn ws-mm__btn--danger">' . esc_html__( 'Reject and send message', 'ws-chapters' ) . '</button></div></form></dialog>';

		$open .= '<tr><td>' . $person . '</td>' . $where
			. '<td data-label="' . esc_attr__( 'Message', 'ws-chapters' ) . '">' . $message . '</td>'
			. '<td data-label="' . esc_attr__( 'Actions', 'ws-chapters' ) . '"><div class="ws-mm__row-actions">'
			. '<button type="button" class="ws-mm__btn ws-mm__btn--small" onclick="document.getElementById(\'' . $invite_id . '\').showModal()">' . esc_html__( 'Send invitation', 'ws-chapters' ) . '</button>'
			. '<button type="button" class="ws-mm__btn ws-mm__btn--small ws-mm__btn--danger" onclick="document.getElementById(\'' . $reject_id . '\').showModal()">' . esc_html__( 'Reject application', 'ws-chapters' ) . '</button>'
			. '</div></td></tr>';
	}

	$head  = '<th>' . esc_html__( 'Applicant', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Lodge', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Chapter', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Received', 'ws-chapters' ) . '</th>';
	$out  .= '<section class="ws-mm__section"><h3 class="ws-mm__heading">' . esc_html__( 'Waiting for an answer', 'ws-chapters' ) . '</h3>'
		. '<p class="ws-muted">' . esc_html( ws_mm_is_super() ? __( 'Applications sent with the Join form, for every chapter.', 'ws-chapters' ) : ( ws_mm_is_chapter_editor() ? __( 'Applications sent with the Join form for your chapter.', 'ws-chapters' ) : __( 'Applications sent with the Join form for your country.', 'ws-chapters' ) ) ) . '</p>'
		. '<div class="ws-mm__scroll"><table class="ws-mm__table ws-mm__table--apps"><thead><tr>' . $head . '<th>' . esc_html__( 'Message', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Actions', 'ws-chapters' ) . '</th></tr></thead><tbody>'
		. ( $open ?: '<tr><td colspan="6" class="ws-mm__empty">' . esc_html__( 'No applications are waiting.', 'ws-chapters' ) . '</td></tr>' ) . '</tbody></table></div></section>';
	if ( $answered ) {
		$out .= '<details class="ws-mm__section ws-mm__fold"><summary class="ws-mm__heading">' . esc_html__( 'Answered', 'ws-chapters' ) . '</summary>'
			. '<div class="ws-mm__scroll"><table class="ws-mm__table ws-mm__table--apps"><thead><tr>' . $head . '<th>' . esc_html__( 'Answer', 'ws-chapters' ) . '</th></tr></thead><tbody>' . $answered . '</tbody></table></div></details>';
	}
	return $out . $dialogs . '</div>' . ws_mm_script();
} );
