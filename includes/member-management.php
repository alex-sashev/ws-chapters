<?php
/**
 * Member management: the member directory (for all signed-in members) and the management page
 * (Super Admin and Country Admin). Invitations are sent by email; people set their own password.
 *
 * Shortcodes: [ws_member_directory] (contact book), [ws_member_manage] (invite, remove, titles).
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

const WS_MM_INVITE_DAYS = 7;

function ws_mm_role_labels() {
	return array( 'ws_member' => __( 'Member', 'ws-chapters' ), 'ws_chapter_editor' => __( 'Chapter Editor', 'ws-chapters' ), 'ws_country_admin' => __( 'Country Admin', 'ws-chapters' ) );
}

function ws_mm_titles() {
	return array( 'President', 'Chapter President', 'Vice President', 'Sergeant of Arms', 'Secretary', 'Treasurer', 'Road Captain', 'Other' );
}

function ws_mm_is_super() {
	return is_super_admin() || current_user_can( 'manage_options' );
}

function ws_mm_is_country_admin() {
	return in_array( 'ws_country_admin', (array) wp_get_current_user()->roles, true );
}

/** Country IDs the current user may manage: all for a Super Admin, their own for a Country Admin. */
function ws_mm_manageable_countries() {
	if ( ws_mm_is_super() ) {
		return array_map( 'intval', get_terms( array( 'taxonomy' => 'ws_country', 'fields' => 'ids', 'hide_empty' => false ) ) );
	}
	$scope = ws_user_scope( get_current_user_id() );
	return $scope ? $scope['countries'] : array();
}

function ws_mm_user_ids( $key ) {
	return array_map( 'intval', (array) get_user_meta( $key, 'ws_scope_countries', true ) );
}

/** Chapter IDs (posts) of a country list. */
function ws_mm_chapters_in( array $countries ) {
	$out = array();
	foreach ( get_posts( array( 'post_type' => 'ws_chapter', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) ) as $c ) {
		$t = ws_chapter_country( $c->ID );
		if ( $t && in_array( (int) $t->term_id, $countries, true ) ) {
			$out[ $c->ID ] = $c;
		}
	}
	return $out;
}

/** Whether the current user may act on this person (remove, retitle). */
function ws_mm_may_manage_user( $target_id ) {
	$target = get_userdata( $target_id );
	if ( ! $target || (int) $target_id === get_current_user_id() ) {
		return false;
	}
	if ( ws_mm_is_super() ) {
		return true;
	}
	if ( ! ws_mm_is_country_admin() ) {
		return false;
	}
	$roles = (array) $target->roles;
	if ( array_intersect( $roles, array( 'ws_country_admin', 'administrator' ) ) ) {
		return false; // Country Admins cannot touch other Country Admins or Super Admins.
	}
	return (bool) array_intersect( ws_mm_user_ids( $target_id ), ws_mm_manageable_countries() );
}

/** Who may set a title: Super Admin, Country Admin of the person's country, Chapter Editor of the person's chapter. */
function ws_mm_may_set_title( $target_id ) {
	if ( ws_mm_may_manage_user( $target_id ) ) {
		return true;
	}
	$scope = ws_user_scope( get_current_user_id() );
	return (bool) ( $scope && array_intersect( (array) get_user_meta( $target_id, 'ws_scope_chapters', true ), $scope['chapters'] ) );
}

function ws_mm_log( $action, $target, $detail = '' ) {
	$log   = (array) get_option( 'ws_mm_log', array() );
	$log[] = array( 'time' => gmdate( 'c' ), 'by' => get_current_user_id(), 'action' => $action, 'target' => (int) $target, 'detail' => $detail );
	update_option( 'ws_mm_log', array_slice( $log, -500 ), false );
}

function ws_mm_avatar_html( $uid, $name ) {
	$att = (int) get_user_meta( $uid, 'ws_avatar', true );
	if ( $att && wp_attachment_is_image( $att ) ) {
		return wp_get_attachment_image( $att, 'thumbnail', false, array( 'class' => 'ws-mm__avatar', 'alt' => '' ) );
	}
	$initials = '';
	foreach ( preg_split( '/\s+/', trim( $name ) ) as $part ) {
		$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
	}
	return '<span class="ws-mm__avatar ws-mm__avatar--initials" aria-hidden="true">' . esc_html( mb_substr( $initials, 0, 2 ) ) . '</span>';
}

function ws_mm_person_name( $user ) {
	$name = trim( $user->first_name . ' ' . $user->last_name );
	return '' !== $name ? $name : $user->display_name;
}

function ws_mm_country_names( $uid ) {
	$names = array();
	foreach ( ws_mm_user_ids( $uid ) as $tid ) {
		$t = get_term( $tid, 'ws_country' );
		if ( $t && ! is_wp_error( $t ) ) {
			$names[] = ws_display_name( $t->name );
		}
	}
	return implode( ', ', $names );
}

function ws_mm_chapter_names( $uid ) {
	$names = array();
	foreach ( (array) get_user_meta( $uid, 'ws_scope_chapters', true ) as $cid ) {
		if ( 'ws_chapter' === get_post_type( (int) $cid ) ) {
			$names[] = ws_display_name( get_the_title( (int) $cid ) );
		}
	}
	return implode( ', ', $names );
}

/** Everyone with a Widows Sons role: the contact book. */
function ws_mm_people() {
	return get_users( array( 'role__in' => array( 'ws_member', 'ws_chapter_editor', 'ws_country_admin' ), 'orderby' => 'display_name', 'order' => 'ASC' ) );
}

add_shortcode( 'ws_member_directory', function () {
	if ( ! is_user_logged_in() ) {
		return '<p class="ws-muted">' . esc_html__( 'Please sign in to see the member list.', 'ws-chapters' ) . '</p>';
	}
	$country = isset( $_GET['ws_mm_country'] ) ? absint( $_GET['ws_mm_country'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$title   = isset( $_GET['ws_mm_title'] ) ? sanitize_text_field( wp_unslash( $_GET['ws_mm_title'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$rows    = '';
	foreach ( ws_mm_people() as $u ) {
		if ( $country && ! in_array( $country, ws_mm_user_ids( $u->ID ), true ) ) {
			continue;
		}
		$t = (string) get_user_meta( $u->ID, 'ws_title', true );
		if ( '' !== $title && $t !== $title ) {
			continue;
		}
		$name  = ws_mm_person_name( $u );
		$road  = (string) get_user_meta( $u->ID, 'ws_road_name', true );
		$rows .= '<tr><td>' . ws_mm_avatar_html( $u->ID, $name ) . '</td>'
			. '<td><strong>' . esc_html( $name ) . '</strong>' . ( $road ? '<br><span class="ws-muted">&ldquo;' . esc_html( $road ) . '&rdquo;</span>' : '' ) . '</td>'
			. '<td>' . esc_html( ws_mm_country_names( $u->ID ) ) . '<br><span class="ws-muted">' . esc_html( ws_mm_chapter_names( $u->ID ) ) . '</span></td>'
			. '<td>' . esc_html( $t ) . '</td>'
			. '<td><a href="mailto:' . esc_attr( $u->user_email ) . '">' . esc_html( $u->user_email ) . '</a><br>' . esc_html( (string) get_user_meta( $u->ID, 'ws_phone', true ) ) . '</td></tr>';
	}
	$countries = get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false ) );
	$filter    = '<form method="get" class="ws-mm__filters"><select name="ws_mm_country"><option value="0">' . esc_html__( 'All countries', 'ws-chapters' ) . '</option>';
	foreach ( (array) $countries as $c ) {
		$filter .= '<option value="' . (int) $c->term_id . '"' . selected( $country, $c->term_id, false ) . '>' . esc_html( ws_display_name( $c->name ) ) . '</option>';
	}
	$filter .= '</select><select name="ws_mm_title"><option value="">' . esc_html__( 'All titles', 'ws-chapters' ) . '</option>';
	foreach ( ws_mm_titles() as $tt ) {
		$filter .= '<option value="' . esc_attr( $tt ) . '"' . selected( $title, $tt, false ) . '>' . esc_html( $tt ) . '</option>';
	}
	$filter .= '</select><button type="submit" class="ws-btn-outline">' . esc_html__( 'Filter', 'ws-chapters' ) . '</button></form>';
	return '<div class="ws-mm">' . $filter . '<div class="ws-mm__scroll"><table class="ws-mm__table"><thead><tr><th></th><th>' . esc_html__( 'Name and road name', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Country and chapter', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Title', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Email and phone', 'ws-chapters' ) . '</th></tr></thead><tbody>' . ( $rows ?: '<tr><td colspan="5">' . esc_html__( 'No members match.', 'ws-chapters' ) . '</td></tr>' ) . '</tbody></table></div></div>';
} );

/** Save the management forms (invite, remove, title). */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['ws_mm_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$msg = null;
	$ok  = false;
	if ( ! is_user_logged_in() || ! ws_mm_can_manage() || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ws_mm_nonce'] ?? '' ) ), 'ws_mm' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$msg = __( 'You do not have permission to do that.', 'ws-chapters' );
	} elseif ( 'invite' === $_POST['ws_mm_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		$msg = ws_mm_invite();
		$ok  = null === $msg;
		$msg = $ok ? __( 'Invitation sent.', 'ws-chapters' ) : $msg;
	} elseif ( 'remove' === $_POST['ws_mm_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		$uid = absint( $_POST['ws_mm_user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! ws_mm_may_manage_user( $uid ) ) {
			$msg = __( 'You cannot remove this person.', 'ws-chapters' );
		} else {
			$u = get_userdata( $uid );
			$u->set_role( '' ); // Removes access; the account and its content are kept.
			foreach ( array( 'ws_scope_countries', 'ws_scope_chapters' ) as $k ) {
				delete_user_meta( $uid, $k );
			}
			ws_mm_log( 'removed', $uid );
			$ok  = true;
			$msg = __( 'Access removed. The account is kept and can be added back later.', 'ws-chapters' );
		}
	} elseif ( 'resend' === $_POST['ws_mm_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		$uid = absint( $_POST['ws_mm_user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! ws_mm_may_manage_user( $uid ) || ! get_user_meta( $uid, 'ws_invited', true ) ) {
			$msg = __( 'You cannot resend this invitation.', 'ws-chapters' );
		} else {
			$msg = ws_mm_send_invite( get_userdata( $uid ) );
			$ok  = null === $msg;
			$msg = $ok ? __( 'Invitation sent again.', 'ws-chapters' ) : $msg;
			ws_mm_log( 'resent', $uid );
		}
	} elseif ( 'title' === $_POST['ws_mm_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		$uid   = absint( $_POST['ws_mm_user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$title = sanitize_text_field( wp_unslash( $_POST['ws_mm_title'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! ws_mm_may_set_title( $uid ) || ! in_array( $title, ws_mm_titles(), true ) ) {
			$msg = __( 'You cannot set this title.', 'ws-chapters' );
		} else {
			$other = $title === 'Other' ? sanitize_text_field( wp_unslash( $_POST['ws_mm_title_other'] ?? '' ) ) : '';
			update_user_meta( $uid, 'ws_title', $title === 'Other' && $other ? $other : $title );
			ws_mm_log( 'title', $uid, $title );
			$ok  = true;
			$msg = __( 'Title saved.', 'ws-chapters' );
		}
	}
	set_transient( 'ws_mm_msg_' . get_current_user_id(), array( 'ok' => $ok, 'text' => $msg ), MINUTE_IN_SECONDS );
	wp_safe_redirect( remove_query_arg( 'ws_mm', wp_get_referer() ?: home_url( '/' ) ) );
	exit;
}, 3 );

/** Create the account and send the invitation. Returns null on success, or an error message. */
function ws_mm_invite() {
	$email = sanitize_email( wp_unslash( $_POST['ws_mm_email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$role  = sanitize_text_field( wp_unslash( $_POST['ws_mm_role'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$cid   = absint( $_POST['ws_mm_country'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$chaps = array_map( 'absint', (array) ( $_POST['ws_mm_chapters'] ?? array() ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! is_email( $email ) ) {
		return __( 'Please enter a valid email address.', 'ws-chapters' );
	}
	if ( email_exists( $email ) ) {
		return __( 'This email is already in use.', 'ws-chapters' );
	}
	$allowed_roles = ws_mm_is_super() ? array( 'ws_member', 'ws_chapter_editor', 'ws_country_admin' ) : array( 'ws_member', 'ws_chapter_editor' );
	if ( ! in_array( $role, $allowed_roles, true ) ) {
		return __( 'You cannot invite that kind of member.', 'ws-chapters' );
	}
	if ( ! in_array( $cid, ws_mm_manageable_countries(), true ) ) {
		return __( 'You can only add people to your own country.', 'ws-chapters' );
	}
	$chapters_ok = array_keys( ws_mm_chapters_in( array( $cid ) ) );
	$chaps       = array_values( array_intersect( $chaps, $chapters_ok ) );
	if ( 'ws_chapter_editor' === $role && ! $chaps ) {
		return __( 'Choose at least one chapter for a Chapter Editor.', 'ws-chapters' );
	}
	$uid = wp_insert_user( array(
		'user_login' => 'ws_' . wp_generate_password( 10, false, false ),
		'user_email' => $email,
		'user_pass'  => wp_generate_password( 32, true, true ), // Random; the person sets their own through the invitation.
		'role'       => $role,
	) );
	if ( is_wp_error( $uid ) ) {
		return __( 'Sorry, the invitation could not be created. Please try again.', 'ws-chapters' );
	}
	update_user_meta( $uid, 'ws_scope_countries', array( $cid ) );
	update_user_meta( $uid, 'ws_scope_chapters', $chaps );
	update_user_meta( $uid, 'ws_invited', gmdate( 'c' ) );
	$err = ws_mm_send_invite( get_userdata( $uid ) );
	ws_mm_log( 'invited', $uid, $role );
	return $err ? $err : null;
}

/** Send (or send again) the invitation email with a fresh link. Returns null, or an error message. */
function ws_mm_send_invite( $user ) {
	if ( ! $user ) {
		return __( 'That person was not found.', 'ws-chapters' );
	}
	$key = get_password_reset_key( $user );
	if ( is_wp_error( $key ) ) {
		return __( 'The invitation could not be created. Please try again.', 'ws-chapters' );
	}
	$link = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
	$sent = wp_mail( $user->user_email, __( 'Your invitation to Widows Sons Europe', 'ws-chapters' ), sprintf(
		/* translators: 1: link, 2: days */
		__( "You have been invited to the Widows Sons Europe members' area.\n\nSet your password here (the link expires in %2\$d days):\n%1\$s\n\nAfter signing in, add your road name, phone, first and last name on your profile.", 'ws-chapters' ),
		$link,
		WS_MM_INVITE_DAYS
	) );
	return $sent ? null : __( 'The invitation email could not be sent. Check the mail settings, then resend.', 'ws-chapters' );
}

// Invitation links last WS_MM_INVITE_DAYS days (WordPress default is one day). Applies to all password reset links.
add_filter( 'password_reset_expiration', function () {
	return WS_MM_INVITE_DAYS * DAY_IN_SECONDS;
} );

add_shortcode( 'ws_member_manage', function () {
	if ( ! is_user_logged_in() || ! ws_mm_can_manage() ) {
		return '';
	}
	$out   = '';
	$flash = get_transient( 'ws_mm_msg_' . get_current_user_id() );
	if ( $flash ) {
		delete_transient( 'ws_mm_msg_' . get_current_user_id() );
		$out .= ws_form_notice( $flash['ok'] ? 'success' : 'error', esc_html( $flash['text'] ) );
	}
	$nonce   = '<input type="hidden" name="ws_mm_nonce" value="' . esc_attr( wp_create_nonce( 'ws_mm' ) ) . '">';
	$super   = ws_mm_is_super();
	$mine    = ws_mm_manageable_countries();
	$roles   = $super ? ws_mm_role_labels() : array_intersect_key( ws_mm_role_labels(), array( 'ws_member' => 1, 'ws_chapter_editor' => 1 ) );
	$cshtml  = '';
	foreach ( $mine ? get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false, 'include' => $mine ) ) : array() as $c ) {
		$cshtml .= '<option value="' . (int) $c->term_id . '">' . esc_html( ws_display_name( $c->name ) ) . '</option>';
	}
	$rhtml = '';
	foreach ( $roles as $k => $label ) {
		$rhtml .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
	}
	$chap_inputs = '';
	foreach ( ws_mm_chapters_in( $mine ) as $c ) {
		$chap_inputs .= '<label class="ws-mm__check"><input type="checkbox" name="ws_mm_chapters[]" value="' . (int) $c->ID . '"> ' . esc_html( ws_display_name( $c->post_title ) ) . '</label>';
	}
	$out .= '<section class="ws-mm__invite"><h3>' . esc_html__( 'Invite a member', 'ws-chapters' ) . '</h3>'
		. '<p class="ws-muted">' . esc_html__( 'Enter the email, role and country. The person sets their own password from the invitation, which expires in 7 days.', 'ws-chapters' ) . '</p>'
		. '<form method="post" class="ws-mm__form">' . $nonce . '<input type="hidden" name="ws_mm_action" value="invite">'
		. '<label>' . esc_html__( 'Email', 'ws-chapters' ) . '<input type="email" name="ws_mm_email" required></label>'
		. '<label>' . esc_html__( 'Role', 'ws-chapters' ) . '<select name="ws_mm_role">' . $rhtml . '</select></label>'
		. '<label>' . esc_html__( 'Country', 'ws-chapters' ) . '<select name="ws_mm_country">' . $cshtml . '</select></label>'
		. '<fieldset><legend>' . esc_html__( 'Chapters (for Chapter Editors)', 'ws-chapters' ) . '</legend>' . $chap_inputs . '</fieldset>'
		. '<p><button type="submit" class="ws-btn-gold">' . esc_html__( 'Send invitation', 'ws-chapters' ) . '</button></p></form></section>';

	$out .= '<section class="ws-mm__people"><h3>' . esc_html__( 'People you manage', 'ws-chapters' ) . '</h3><ul class="ws-mm__list">';
	foreach ( ws_mm_people() as $u ) {
		if ( ! ws_mm_may_set_title( $u->ID ) && ! ws_mm_may_manage_user( $u->ID ) ) {
			continue;
		}
		$name   = ws_mm_person_name( $u );
		$title  = (string) get_user_meta( $u->ID, 'ws_title', true );
		$tsel   = '<option value="">' . esc_html__( 'No title', 'ws-chapters' ) . '</option>';
		foreach ( ws_mm_titles() as $tt ) {
			$tsel .= '<option value="' . esc_attr( $tt ) . '"' . selected( $title === $tt, true, false ) . '>' . esc_html( $tt ) . '</option>';
		}
		$out .= '<li class="ws-mm__row"><span>' . esc_html( $name ) . ' <span class="ws-muted">' . esc_html( $u->user_email ) . '</span></span>';
		if ( ws_mm_may_set_title( $u->ID ) ) {
			$out .= '<form method="post" class="ws-mm__inline">' . $nonce . '<input type="hidden" name="ws_mm_action" value="title"><input type="hidden" name="ws_mm_user" value="' . (int) $u->ID . '"><select name="ws_mm_title">' . $tsel . '</select> <input type="text" name="ws_mm_title_other" placeholder="' . esc_attr__( 'If Other', 'ws-chapters' ) . '"> <button type="submit" class="ws-btn-outline">' . esc_html__( 'Save title', 'ws-chapters' ) . '</button></form>';
		}
		if ( ws_mm_may_manage_user( $u->ID ) && get_user_meta( $u->ID, 'ws_invited', true ) ) {
			$out .= '<form method="post" class="ws-mm__inline">' . $nonce . '<input type="hidden" name="ws_mm_action" value="resend"><input type="hidden" name="ws_mm_user" value="' . (int) $u->ID . '"><button type="submit" class="ws-btn-outline">' . esc_html__( 'Resend invitation', 'ws-chapters' ) . '</button></form>';
		}
		if ( ws_mm_may_manage_user( $u->ID ) ) {
			$out .= '<form method="post" class="ws-mm__inline" onsubmit="return confirm(\'' . esc_js( __( 'Remove access for this person? Their account and posts are kept.', 'ws-chapters' ) ) . '\')">' . $nonce . '<input type="hidden" name="ws_mm_action" value="remove"><input type="hidden" name="ws_mm_user" value="' . (int) $u->ID . '"><button type="submit" class="ws-btn-outline">' . esc_html__( 'Remove access', 'ws-chapters' ) . '</button></form>';
		}
		$out .= '</li>';
	}
	$out .= '</ul></section>';
	if ( $super ) {
		$log = array_reverse( array_slice( (array) get_option( 'ws_mm_log', array() ), -20 ) );
		$out .= '<section class="ws-mm__log"><h3>' . esc_html__( 'Recent activity', 'ws-chapters' ) . '</h3><ul>';
		foreach ( $log as $e ) {
			$by     = get_userdata( (int) $e['by'] );
			$target = get_userdata( (int) $e['target'] );
			$out   .= '<li>' . esc_html( mysql2date( 'j M Y H:i', $e['time'] ) ) . ' · ' . esc_html( $by ? $by->display_name : '?' ) . ' · ' . esc_html( $e['action'] ) . ' · ' . esc_html( $target ? ws_mm_person_name( $target ) : '?' ) . ( $e['detail'] ? ' (' . esc_html( $e['detail'] ) . ')' : '' ) . '</li>';
		}
		$out .= '</ul></section>';
	}
	return $out;
} );

/* The member's own details, filled in after the invitation: road name, phone, first and last name (on their profile). */
function ws_mm_profile_fields( $user ) {
	if ( ! is_object( $user ) || ! array_intersect( (array) $user->roles, array( 'ws_member', 'ws_chapter_editor', 'ws_country_admin' ) ) ) {
		return;
	}
	?>
	<h2><?php esc_html_e( 'Widows Sons details', 'ws-chapters' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr><th><label for="ws_road_name"><?php esc_html_e( 'Road name', 'ws-chapters' ); ?></label></th><td><input type="text" id="ws_road_name" name="ws_road_name" class="regular-text" value="<?php echo esc_attr( get_user_meta( $user->ID, 'ws_road_name', true ) ); ?>"></td></tr>
		<tr><th><label for="ws_phone"><?php esc_html_e( 'Phone', 'ws-chapters' ); ?></label></th><td><input type="text" id="ws_phone" name="ws_phone" class="regular-text" value="<?php echo esc_attr( get_user_meta( $user->ID, 'ws_phone', true ) ); ?>"></td></tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'ws_mm_profile_fields' );
add_action( 'edit_user_profile', 'ws_mm_profile_fields' );

function ws_mm_save_profile_fields( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	if ( isset( $_POST['ws_road_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		update_user_meta( $user_id, 'ws_road_name', sanitize_text_field( wp_unslash( $_POST['ws_road_name'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	if ( isset( $_POST['ws_phone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		update_user_meta( $user_id, 'ws_phone', sanitize_text_field( wp_unslash( $_POST['ws_phone'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
}
add_action( 'personal_options_update', 'ws_mm_save_profile_fields' );

/* Avatar: each member uploads their own photo (no Gravatar). */
function ws_mm_avatar_field( $user ) {
	if ( ! is_object( $user ) || ! array_intersect( (array) $user->roles, array( 'ws_member', 'ws_chapter_editor', 'ws_country_admin' ) ) ) {
		return;
	}
	echo '<table class="form-table" role="presentation"><tr><th><label for="ws_avatar_file">' . esc_html__( 'Photo', 'ws-chapters' ) . '</label></th><td>';
	echo wp_kses_post( ws_mm_avatar_html( $user->ID, ws_mm_person_name( $user ) ) ) . '<br>';
	echo '<input type="file" id="ws_avatar_file" name="ws_avatar_file" accept="image/jpeg,image/png,image/webp,image/gif"> <span class="description">' . esc_html__( 'JPG, PNG, WebP or GIF, up to 4 MB.', 'ws-chapters' ) . '</span>';
	echo '</td></tr></table>';
}
add_action( 'show_user_profile', 'ws_mm_avatar_field' );
add_action( 'edit_user_profile', 'ws_mm_avatar_field' );
add_action( 'user_edit_form_tag', function () {
	echo ' enctype="multipart/form-data"';
} );

function ws_mm_save_avatar( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) || empty( $_FILES['ws_avatar_file']['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$file = $_FILES['ws_avatar_file']; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! empty( $file['error'] ) || (int) $file['size'] > 4 * MB_IN_BYTES ) {
		return;
	}
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' ) );
	if ( empty( $check['type'] ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$id = media_handle_upload( 'ws_avatar_file', 0, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' ) ) );
	if ( ! is_wp_error( $id ) ) {
		update_user_meta( $user_id, 'ws_avatar', (int) $id );
	}
}
add_action( 'personal_options_update', 'ws_mm_save_avatar' );
add_action( 'edit_user_profile_update', 'ws_mm_save_avatar' );
add_action( 'edit_user_profile_update', 'ws_mm_save_profile_fields' );

/* One-time: the two member pages, under the Members page. Existing pages are never changed. */
add_action( 'init', function () {
	if ( get_option( 'ws_mm_pages_done' ) || ! post_type_exists( 'page' ) ) {
		return;
	}
	update_option( 'ws_mm_pages_done', gmdate( 'c' ), false );
	$parent = get_page_by_path( 'members' );
	$pages  = array(
		'member-directory'  => array( __( 'Member directory', 'ws-chapters' ), '[ws_member_directory]' ),
		'member-management' => array( __( 'Member management', 'ws-chapters' ), '[ws_member_manage]' ),
	);
	foreach ( $pages as $slug => $def ) {
		if ( get_page_by_path( $slug ) ) {
			continue;
		}
		wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $def[0],
			'post_name'    => $slug,
			'post_parent'  => $parent ? $parent->ID : 0,
			'post_content' => '<!-- wp:html -->' . $def[1] . '<!-- /wp:html -->',
		) );
	}
}, 45 );

/* Menu: the two member pages sit after the Members link. The management link shows only to Super Admins and Country Admins. */
add_filter( 'render_block_core/navigation-link', function ( $html, $block ) {
	$class = $block['attrs']['className'] ?? '';
	return false !== strpos( $class, 'ws-mm-admin-only' ) && ! ws_mm_can_manage() ? '' : $html;
}, 11, 2 );

add_action( 'init', function () {
	if ( get_option( 'ws_mm_menu_done' ) ) {
		return;
	}
	$nav = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 1, 'title' => 'Main menu' ) );
	$dir = get_page_by_path( 'member-directory' );
	$man = get_page_by_path( 'member-management' );
	if ( ! $nav || ! $dir || ! $man ) {
		return; // Try again on a later page load.
	}
	update_option( 'ws_mm_menu_done', gmdate( 'c' ), false );
	$links = '<!-- wp:navigation-link ' . wp_json_encode( array( 'label' => __( 'Member directory', 'ws-chapters' ), 'url' => get_permalink( $dir ), 'kind' => 'post-type', 'type' => 'page', 'id' => $dir->ID, 'isTopLevelLink' => true, 'className' => 'ws-members-only' ) ) . ' /-->'
		. "\n<!-- wp:navigation-link " . wp_json_encode( array( 'label' => __( 'Member management', 'ws-chapters' ), 'url' => get_permalink( $man ), 'kind' => 'post-type', 'type' => 'page', 'id' => $man->ID, 'isTopLevelLink' => true, 'className' => 'ws-members-only ws-mm-admin-only' ) ) . ' /-->';
	$content = $nav[0]->post_content;
	if ( false !== strpos( $content, 'ws-mm-admin-only' ) || false !== strpos( $content, 'Member directory' ) ) {
		return;
	}
	$lines = explode( "\n", $content );
	$out   = array();
	foreach ( $lines as $line ) {
		$out[] = $line;
		if ( false !== strpos( $line, 'Members' ) && false !== strpos( $line, 'wp:navigation-link' ) ) {
			$out[] = $links;
		}
	}
	if ( count( $out ) === count( $lines ) ) {
		$out[] = $links; // No Members link found: add the entries at the end.
	}
	wp_update_post( array( 'ID' => $nav[0]->ID, 'post_content' => implode( "\n", $out ) ) );
}, 46 );
