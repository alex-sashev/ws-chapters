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

/** A page by its name, wherever it sits (get_page_by_path misses pages under a parent page). */
function ws_mm_page( $slug ) {
	$found = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'name' => $slug, 'posts_per_page' => 1, 'no_found_rows' => true ) );
	return $found ? $found[0] : null;
}

/** The row of links between the three member pages. $current is 'library', 'directory' or 'manage'; $wide matches the wide member pages. */
function ws_mm_subnav( $current, $wide = false ) {
	$items = array(
		'library'   => array( __( 'Library', 'ws-chapters' ), ws_mm_page( 'members' ) ),
		'directory' => array( __( 'Members', 'ws-chapters' ), ws_mm_page( 'member-directory' ) ),
	);
	if ( ws_mm_can_manage() ) {
		$items['manage'] = array( __( 'Member management', 'ws-chapters' ), ws_mm_page( 'member-management' ) );
	}
	$out = '<nav class="ws-subnav' . ( $wide ? ' alignwide' : '' ) . '" aria-label="' . esc_attr__( 'Members', 'ws-chapters' ) . '">';
	foreach ( $items as $key => $item ) {
		if ( ! $item[1] ) {
			continue;
		}
		$out .= '<a href="' . esc_url( get_permalink( $item[1] ) ) . '"' . ( $key === $current ? ' class="is-active" aria-current="page"' : '' ) . '>' . esc_html( $item[0] ) . '</a>';
	}
	return $out . '</nav>';
}

function ws_mm_is_super() {
	return is_super_admin() || current_user_can( 'manage_options' );
}

/** Super Admins and Country Admins manage people. */
function ws_mm_can_manage() {
	return ws_mm_is_super() || ws_mm_is_country_admin();
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
	if ( '' !== $name ) {
		return $name;
	}
	// Invited people get a random login (ws_…) until they add their name: show their email instead.
	return $user->display_name === $user->user_login && 0 === strpos( $user->user_login, 'ws_' ) ? $user->user_email : $user->display_name;
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

/** A labelled field for the member pages: label above, the control below, all the same size. */
function ws_mm_field( $label, $control, $class = '' ) {
	return '<label class="ws-mm__field' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '"><span class="ws-mm__label">' . esc_html( $label ) . '</span>' . $control . '</label>';
}

/** Country drop-down. $first is the label of the empty choice ('' for none). */
function ws_mm_country_select( $name, array $terms, $selected, $first = '' ) {
	$html = '<select name="' . esc_attr( $name ) . '" class="ws-mm__input" data-ws-mm-country>' . ( '' !== $first ? '<option value="0">' . esc_html( $first ) . '</option>' : '' );
	foreach ( $terms as $c ) {
		$html .= '<option value="' . (int) $c->term_id . '"' . selected( (int) $selected, (int) $c->term_id, false ) . '>' . esc_html( ws_display_name( $c->name ) ) . '</option>';
	}
	return $html . '</select>';
}

/** Chapter drop-down; each choice carries its country, so the list follows the country drop-down in the same form. */
function ws_mm_chapter_select( $name, array $chapters, $selected, $first ) {
	$html = '<select name="' . esc_attr( $name ) . '" class="ws-mm__input" data-ws-mm-chapter><option value="0">' . esc_html( $first ) . '</option>';
	foreach ( $chapters as $c ) {
		$t     = ws_chapter_country( $c->ID );
		$html .= '<option value="' . (int) $c->ID . '" data-country="' . ( $t ? (int) $t->term_id : 0 ) . '"' . selected( (int) $selected, (int) $c->ID, false ) . '>' . esc_html( ws_display_name( $c->post_title ) ) . '</option>';
	}
	return $html . '</select>';
}

/** Title drop-down; a title that is not in the list shows as "Other". */
function ws_mm_title_select( $name, $selected, $first ) {
	$html = '<select name="' . esc_attr( $name ) . '" class="ws-mm__input" data-ws-mm-title><option value="">' . esc_html( $first ) . '</option>';
	foreach ( ws_mm_titles() as $tt ) {
		$html .= '<option value="' . esc_attr( $tt ) . '"' . selected( $selected, $tt, false ) . '>' . esc_html( $tt ) . '</option>';
	}
	return $html . '</select>';
}

/** Whether a stored title matches a filter choice ("Other" matches any title outside the list). */
function ws_mm_title_matches( $title, $filter ) {
	if ( '' === $filter ) {
		return true;
	}
	if ( 'Other' === $filter ) {
		return '' !== $title && ( 'Other' === $title || ! in_array( $title, ws_mm_titles(), true ) );
	}
	return $title === $filter;
}

/** Small script for the member pages: chapter list follows the country, "Other" title box, busy buttons. Printed once. */
function ws_mm_script() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	$wait = esc_js( __( 'Please wait…', 'ws-chapters' ) );
	return "<script>(function(){
function syncChapters(form){var c=form.querySelector('[data-ws-mm-country]'),ch=form.querySelector('[data-ws-mm-chapter]');if(!c||!ch)return;var v=c.value;Array.prototype.forEach.call(ch.options,function(o){if(o.value==='0')return;var show=v==='0'||o.getAttribute('data-country')===v;o.hidden=!show;o.disabled=!show;if(!show&&o.selected){ch.value='0';}});}
function syncOther(form){var t=form.querySelector('[data-ws-mm-title]'),o=form.querySelector('[data-ws-mm-other]');if(t&&o){o.hidden=t.value!=='Other';}}
document.querySelectorAll('.ws-mm form').forEach(function(f){syncChapters(f);syncOther(f);f.addEventListener('change',function(e){if(e.target.matches('[data-ws-mm-country]'))syncChapters(f);if(e.target.matches('[data-ws-mm-title]'))syncOther(f);});
if(f.method.toLowerCase()==='post'){f.addEventListener('submit',function(e){if(f.classList.contains('is-busy')){e.preventDefault();return;}if(e.defaultPrevented)return;f.classList.add('is-busy');var b=f.querySelector('button[type=submit]');if(b){b.textContent='{$wait}';}});}});
})();</script>";
}

add_shortcode( 'ws_member_directory', function () {
	if ( ! is_user_logged_in() ) {
		return '<p class="ws-muted">' . esc_html__( 'Please sign in to see the member list.', 'ws-chapters' ) . '</p>';
	}
	// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
	$country = isset( $_GET['ws_mm_country'] ) ? absint( $_GET['ws_mm_country'] ) : 0;
	$chapter = isset( $_GET['ws_mm_chapter'] ) ? absint( $_GET['ws_mm_chapter'] ) : 0;
	$title   = isset( $_GET['ws_mm_title'] ) ? sanitize_text_field( wp_unslash( $_GET['ws_mm_title'] ) ) : '';
	$search  = isset( $_GET['ws_mm_q'] ) ? sanitize_text_field( wp_unslash( $_GET['ws_mm_q'] ) ) : '';
	// phpcs:enable
	$rows  = '';
	$count = 0;
	foreach ( ws_mm_people() as $u ) {
		if ( $country && ! in_array( $country, ws_mm_user_ids( $u->ID ), true ) ) {
			continue;
		}
		if ( $chapter && ! in_array( $chapter, array_map( 'intval', (array) get_user_meta( $u->ID, 'ws_scope_chapters', true ) ), true ) ) {
			continue;
		}
		$t = (string) get_user_meta( $u->ID, 'ws_title', true );
		if ( ! ws_mm_title_matches( $t, $title ) ) {
			continue;
		}
		$name  = ws_mm_person_name( $u );
		$road  = (string) get_user_meta( $u->ID, 'ws_road_name', true );
		$phone = (string) get_user_meta( $u->ID, 'ws_phone', true );
		if ( '' !== $search && false === mb_stripos( $name . ' ' . $road . ' ' . $u->user_email, $search ) ) {
			continue;
		}
		++$count;
		$rows .= '<tr>'
			. '<td><div class="ws-mm__person">' . ws_mm_avatar_html( $u->ID, $name ) . '<span><strong>' . esc_html( $name ) . '</strong>' . ( $road ? '<span class="ws-muted">&ldquo;' . esc_html( $road ) . '&rdquo;</span>' : '' ) . '</span></div></td>'
			. '<td data-label="' . esc_attr__( 'Country', 'ws-chapters' ) . '">' . esc_html( ws_mm_country_names( $u->ID ) ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Chapter', 'ws-chapters' ) . '">' . esc_html( ws_mm_chapter_names( $u->ID ) ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Title', 'ws-chapters' ) . '">' . esc_html( $t ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Email', 'ws-chapters' ) . '"><a href="mailto:' . esc_attr( $u->user_email ) . '">' . esc_html( $u->user_email ) . '</a></td>'
			. '<td data-label="' . esc_attr__( 'Phone', 'ws-chapters' ) . '">' . ( $phone ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>' : '' ) . '</td>'
			. '</tr>';
	}
	$terms    = (array) get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false ) );
	$chapters = ws_mm_chapters_in( array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) );
	$filtered = $country || $chapter || '' !== $title || '' !== $search;
	$filter   = '<form method="get" class="ws-mm__bar">'
		. ws_mm_field( __( 'Search', 'ws-chapters' ), '<input type="search" name="ws_mm_q" class="ws-mm__input" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name, road name or email', 'ws-chapters' ) . '">', 'ws-mm__field--wide' )
		. ws_mm_field( __( 'Country', 'ws-chapters' ), ws_mm_country_select( 'ws_mm_country', $terms, $country, __( 'All countries', 'ws-chapters' ) ) )
		. ws_mm_field( __( 'Chapter', 'ws-chapters' ), ws_mm_chapter_select( 'ws_mm_chapter', $chapters, $chapter, __( 'All chapters', 'ws-chapters' ) ) )
		. ws_mm_field( __( 'Title', 'ws-chapters' ), ws_mm_title_select( 'ws_mm_title', $title, __( 'All titles', 'ws-chapters' ) ) )
		. '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn">' . esc_html__( 'Filter', 'ws-chapters' ) . '</button>'
		. ( $filtered ? '<a class="ws-mm__clear" href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Clear', 'ws-chapters' ) . '</a>' : '' ) . '</div></form>';
	/* translators: %d: number of members */
	$summary = '<p class="ws-mm__count">' . esc_html( sprintf( _n( '%d member', '%d members', $count, 'ws-chapters' ), $count ) ) . '</p>';
	$head    = '<thead><tr><th>' . esc_html__( 'Name', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Country', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Chapter', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Title', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Email', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Phone', 'ws-chapters' ) . '</th></tr></thead>';
	return ws_mm_subnav( 'directory', true ) . '<div class="ws-mm alignwide">' . $filter . $summary . '<div class="ws-mm__scroll"><table class="ws-mm__table ws-mm__table--directory">' . $head . '<tbody>' . ( $rows ?: '<tr><td colspan="6" class="ws-mm__empty">' . esc_html__( 'No members match.', 'ws-chapters' ) . '</td></tr>' ) . '</tbody></table></div></div>' . ws_mm_script();
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
		/* translators: %s: email address */
		$msg = $ok ? sprintf( __( 'Invitation sent to %s.', 'ws-chapters' ), sanitize_email( wp_unslash( $_POST['ws_mm_email'] ?? '' ) ) ) : $msg; // phpcs:ignore WordPress.Security.NonceVerification
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
		if ( ! ws_mm_may_manage_user( $uid ) || ! ws_mm_is_pending( $uid ) ) {
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
		if ( ! ws_mm_may_set_title( $uid ) || ( '' !== $title && ! in_array( $title, ws_mm_titles(), true ) ) ) {
			$msg = __( 'You cannot set this title.', 'ws-chapters' );
		} elseif ( '' === $title ) {
			delete_user_meta( $uid, 'ws_title' );
			ws_mm_log( 'title', $uid, '-' );
			$ok  = true;
			$msg = __( 'Title removed.', 'ws-chapters' );
		} else {
			$other = $title === 'Other' ? sanitize_text_field( wp_unslash( $_POST['ws_mm_title_other'] ?? '' ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			update_user_meta( $uid, 'ws_title', $title === 'Other' && $other ? $other : $title );
			ws_mm_log( 'title', $uid, $title === 'Other' && $other ? $other : $title );
			$ok  = true;
			/* translators: %s: person's name */
			$msg = sprintf( __( 'Title saved for %s.', 'ws-chapters' ), ws_mm_person_name( get_userdata( $uid ) ) );
		}
	}
	set_transient( 'ws_mm_msg_' . get_current_user_id(), array( 'ok' => $ok, 'text' => $msg ), 5 * MINUTE_IN_SECONDS );
	// Back to the page the form was on. (wp_get_referer() is empty when the form posts to its own page, which sent people to the home page.)
	$back = get_queried_object_id() ? get_permalink( get_queried_object_id() ) : '';
	wp_safe_redirect( $back ? $back : ( wp_get_referer() ?: home_url( '/' ) ) );
	exit;
}, 3 );

/* The page that shows a result message is never served from the page cache. */
add_action( 'template_redirect', function () {
	if ( is_user_logged_in() && get_transient( 'ws_mm_msg_' . get_current_user_id() ) ) {
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'member management message' );
	}
}, 4 );

/* People who have signed in at least once are no longer shown as "Invited". */
function ws_mm_mark_joined( $user ) {
	if ( $user instanceof WP_User && get_user_meta( $user->ID, 'ws_invited', true ) && ! get_user_meta( $user->ID, 'ws_joined', true ) ) {
		update_user_meta( $user->ID, 'ws_joined', gmdate( 'c' ) );
	}
}
add_action( 'after_password_reset', 'ws_mm_mark_joined' );
add_action( 'wp_login', function ( $login, $user ) {
	ws_mm_mark_joined( $user );
}, 10, 2 );

function ws_mm_is_pending( $uid ) {
	// session_tokens: people who signed in before "ws_joined" was recorded.
	return get_user_meta( $uid, 'ws_invited', true ) && ! get_user_meta( $uid, 'ws_joined', true ) && ! get_user_meta( $uid, 'session_tokens', true );
}

/** Create the account and send the invitation. Returns null on success, or an error message. */
function ws_mm_invite() {
	$email = sanitize_email( wp_unslash( $_POST['ws_mm_email'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$role  = sanitize_text_field( wp_unslash( $_POST['ws_mm_role'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$cid   = absint( $_POST['ws_mm_country'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$chaps = array_filter( array( absint( $_POST['ws_mm_chapter'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! is_email( $email ) ) {
		return __( 'Please enter a valid email address.', 'ws-chapters' );
	}
	if ( email_exists( $email ) ) {
		/* translators: %s: email address */
		return sprintf( __( '%s already has an account. No invitation was sent.', 'ws-chapters' ), $email );
	}
	$allowed_roles = ws_mm_is_super() ? array( 'ws_member', 'ws_chapter_editor', 'ws_country_admin' ) : array( 'ws_member', 'ws_chapter_editor' );
	if ( ! in_array( $role, $allowed_roles, true ) ) {
		return __( 'You cannot invite that kind of member.', 'ws-chapters' );
	}
	if ( ! $cid ) {
		return __( 'Please choose a country.', 'ws-chapters' );
	}
	if ( ! in_array( $cid, ws_mm_manageable_countries(), true ) ) {
		return __( 'You can only add people to your own country.', 'ws-chapters' );
	}
	$chapters_ok = array_keys( ws_mm_chapters_in( array( $cid ) ) );
	if ( array_diff( $chaps, $chapters_ok ) ) {
		return __( 'That chapter is not in the chosen country.', 'ws-chapters' );
	}
	if ( 'ws_chapter_editor' === $role && ! $chaps ) {
		return __( 'Please choose the chapter for a Chapter Editor.', 'ws-chapters' );
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
	if ( $err ) {
		/* translators: %s: email address */
		return sprintf( __( 'The account for %s was created, but the invitation email could not be sent. Check the mail settings, then use "Resend invitation" below.', 'ws-chapters' ), $email );
	}
	return null;
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
	$out   = ws_mm_subnav( 'manage', true ) . '<div class="ws-mm alignwide">';
	$flash = get_transient( 'ws_mm_msg_' . get_current_user_id() );
	if ( $flash ) {
		delete_transient( 'ws_mm_msg_' . get_current_user_id() );
		$out .= ws_form_notice( $flash['ok'] ? 'success' : 'error', esc_html( $flash['text'] ) );
	}
	$nonce = '<input type="hidden" name="ws_mm_nonce" value="' . esc_attr( wp_create_nonce( 'ws_mm' ) ) . '">';
	$super = ws_mm_is_super();
	$mine  = ws_mm_manageable_countries();
	$roles = $super ? ws_mm_role_labels() : array_intersect_key( ws_mm_role_labels(), array( 'ws_member' => 1, 'ws_chapter_editor' => 1 ) );
	$terms = $mine ? (array) get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false, 'include' => $mine ) ) : array();
	$rhtml = '<select name="ws_mm_role" class="ws-mm__input">';
	foreach ( $roles as $k => $label ) {
		$rhtml .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
	}
	$rhtml .= '</select>';
	// One country: chosen already. Several: the person must pick one.
	$country_first = count( $terms ) > 1 ? __( 'Choose a country', 'ws-chapters' ) : '';
	$out .= '<section class="ws-mm__card"><h3 class="ws-mm__heading">' . esc_html__( 'Invite a member', 'ws-chapters' ) . '</h3>'
		. '<p class="ws-muted">' . esc_html__( 'Enter the email, role, country and chapter. The person sets their own password from the invitation email, which expires in 7 days.', 'ws-chapters' ) . '</p>'
		. '<form method="post" class="ws-mm__grid">' . $nonce . '<input type="hidden" name="ws_mm_action" value="invite">'
		. ws_mm_field( __( 'Email', 'ws-chapters' ), '<input type="email" name="ws_mm_email" class="ws-mm__input" required placeholder="name@example.com">' )
		. ws_mm_field( __( 'Role', 'ws-chapters' ), $rhtml )
		. ws_mm_field( __( 'Country', 'ws-chapters' ), str_replace( '<select ', '<select required ', ws_mm_country_select( 'ws_mm_country', $terms, 0, $country_first ) ) )
		. ws_mm_field( __( 'Chapter', 'ws-chapters' ), ws_mm_chapter_select( 'ws_mm_chapter', ws_mm_chapters_in( $mine ), 0, __( 'No chapter', 'ws-chapters' ) ) )
		. '<p class="ws-mm__hint ws-muted">' . esc_html__( 'A Chapter Editor needs a chapter. For members the chapter is optional.', 'ws-chapters' ) . '</p>'
		. '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn">' . esc_html__( 'Send invitation', 'ws-chapters' ) . '</button></div></form></section>';

	$rows   = '';
	$labels = ws_mm_role_labels();
	foreach ( ws_mm_people() as $u ) {
		$can_title  = ws_mm_may_set_title( $u->ID );
		$can_manage = ws_mm_may_manage_user( $u->ID );
		if ( ! $can_title && ! $can_manage ) {
			continue;
		}
		$name    = ws_mm_person_name( $u );
		$road    = (string) get_user_meta( $u->ID, 'ws_road_name', true );
		$title   = (string) get_user_meta( $u->ID, 'ws_title', true );
		$custom  = '' !== $title && ! in_array( $title, ws_mm_titles(), true );
		$role    = '';
		foreach ( (array) $u->roles as $r ) {
			$role = $labels[ $r ] ?? $role;
		}
		$pending = ws_mm_is_pending( $u->ID );
		$hidden  = '<input type="hidden" name="ws_mm_user" value="' . (int) $u->ID . '">';
		$tcell   = esc_html( $title );
		if ( $can_title ) {
			$tcell = '<form method="post" class="ws-mm__inline">' . $nonce . $hidden . '<input type="hidden" name="ws_mm_action" value="title">'
				. ws_mm_title_select( 'ws_mm_title', $custom ? 'Other' : $title, __( 'No title', 'ws-chapters' ) )
				. '<input type="text" name="ws_mm_title_other" class="ws-mm__input" data-ws-mm-other value="' . esc_attr( $custom ? $title : '' ) . '" placeholder="' . esc_attr__( 'Title', 'ws-chapters' ) . '" aria-label="' . esc_attr__( 'Other title', 'ws-chapters' ) . '">'
				. '<button type="submit" class="ws-mm__btn ws-mm__btn--small">' . esc_html__( 'Save', 'ws-chapters' ) . '</button></form>';
		}
		$actions = '';
		if ( $can_manage && $pending ) {
			$actions .= '<form method="post" class="ws-mm__inline">' . $nonce . $hidden . '<input type="hidden" name="ws_mm_action" value="resend"><button type="submit" class="ws-mm__btn ws-mm__btn--small ws-mm__btn--ghost">' . esc_html__( 'Resend invitation', 'ws-chapters' ) . '</button></form>';
		}
		if ( $can_manage ) {
			$actions .= '<form method="post" class="ws-mm__inline" onsubmit="return confirm(\'' . esc_js( __( 'Remove access for this person? Their account and posts are kept.', 'ws-chapters' ) ) . '\')">' . $nonce . $hidden . '<input type="hidden" name="ws_mm_action" value="remove"><button type="submit" class="ws-mm__btn ws-mm__btn--small ws-mm__btn--danger">' . esc_html__( 'Remove access', 'ws-chapters' ) . '</button></form>';
		}
		$rows .= '<tr>'
			. '<td><div class="ws-mm__person">' . ws_mm_avatar_html( $u->ID, $name ) . '<span><strong>' . esc_html( $name ) . '</strong>' . ( $road ? '<span class="ws-muted">&ldquo;' . esc_html( $road ) . '&rdquo;</span>' : '' ) . '<span class="ws-muted">' . esc_html( $role ) . '</span></span></div></td>'
			. '<td data-label="' . esc_attr__( 'Email', 'ws-chapters' ) . '"><a href="mailto:' . esc_attr( $u->user_email ) . '">' . esc_html( $u->user_email ) . '</a></td>'
			. '<td data-label="' . esc_attr__( 'Country', 'ws-chapters' ) . '">' . esc_html( ws_mm_country_names( $u->ID ) ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Chapter', 'ws-chapters' ) . '">' . esc_html( ws_mm_chapter_names( $u->ID ) ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Title', 'ws-chapters' ) . '">' . $tcell . '</td>'
			. '<td data-label="' . esc_attr__( 'Status', 'ws-chapters' ) . '"><span class="ws-mm__status ws-mm__status--' . ( $pending ? 'invited' : 'active' ) . '">' . ( $pending ? esc_html__( 'Invited', 'ws-chapters' ) : esc_html__( 'Active', 'ws-chapters' ) ) . '</span></td>'
			. '<td data-label="' . esc_attr__( 'Actions', 'ws-chapters' ) . '"><div class="ws-mm__row-actions">' . $actions . '</div></td>'
			. '</tr>';
	}
	$head = '<thead><tr><th>' . esc_html__( 'Name', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Email', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Country', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Chapter', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Title', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Status', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Actions', 'ws-chapters' ) . '</th></tr></thead>';
	$out .= '<section class="ws-mm__section"><h3 class="ws-mm__heading">' . esc_html__( 'People you manage', 'ws-chapters' ) . '</h3>'
		. '<div class="ws-mm__scroll"><table class="ws-mm__table ws-mm__table--manage">' . $head . '<tbody>' . ( $rows ?: '<tr><td colspan="7" class="ws-mm__empty">' . esc_html__( 'Nobody yet. Invite someone above.', 'ws-chapters' ) . '</td></tr>' ) . '</tbody></table></div></section>';
	if ( $super ) {
		$log  = array_reverse( array_slice( (array) get_option( 'ws_mm_log', array() ), -20 ) );
		$lrow = '';
		foreach ( $log as $e ) {
			$by     = get_userdata( (int) $e['by'] );
			$target = get_userdata( (int) $e['target'] );
			$lrow  .= '<tr><td>' . esc_html( mysql2date( 'j M Y H:i', $e['time'] ) ) . '</td><td>' . esc_html( $by ? $by->display_name : '?' ) . '</td><td>' . esc_html( $e['action'] ) . ( $e['detail'] ? ' <span class="ws-muted">(' . esc_html( $e['detail'] ) . ')</span>' : '' ) . '</td><td>' . esc_html( $target ? ws_mm_person_name( $target ) : '?' ) . '</td></tr>';
		}
		$out .= '<section class="ws-mm__section"><h3 class="ws-mm__heading">' . esc_html__( 'Recent activity', 'ws-chapters' ) . '</h3><div class="ws-mm__scroll"><table class="ws-mm__table ws-mm__table--log"><thead><tr><th>' . esc_html__( 'When', 'ws-chapters' ) . '</th><th>' . esc_html__( 'By', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Action', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Person', 'ws-chapters' ) . '</th></tr></thead><tbody>' . ( $lrow ?: '<tr><td colspan="4" class="ws-mm__empty">' . esc_html__( 'No activity yet.', 'ws-chapters' ) . '</td></tr>' ) . '</tbody></table></div></section>';
	}
	return $out . '</div>' . ws_mm_script();
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
	$parent = ws_mm_page( 'members' );
	$pages  = array(
		'member-directory'  => array( __( 'Member directory', 'ws-chapters' ), '[ws_member_directory]' ),
		'member-management' => array( __( 'Member management', 'ws-chapters' ), '[ws_member_manage]' ),
	);
	foreach ( $pages as $slug => $def ) {
		if ( ws_mm_page( $slug ) ) {
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

/* One-time cleanup: the header menu keeps only "Members". The two member sub-pages are reached from the sub-row on the Members page, so their header links are removed (the pages stay). Never re-added. */
add_action( 'init', function () {
	if ( get_option( 'ws_mm_menu_cleared' ) ) {
		return;
	}
	$nav = get_posts( array( 'post_type' => 'wp_navigation', 'post_status' => 'publish', 'numberposts' => 1, 'title' => 'Main menu' ) );
	if ( ! $nav ) {
		return; // Try again on a later page load.
	}
	update_option( 'ws_mm_menu_cleared', gmdate( 'c' ), false );
	$lines = explode( "\n", $nav[0]->post_content );
	$keep  = array();
	foreach ( $lines as $line ) {
		if ( false !== strpos( $line, 'wp:navigation-link' ) && preg_match( '/"label":\s*"Member (directory|management)"/', $line ) ) {
			continue;
		}
		$keep[] = $line;
	}
	if ( count( $keep ) !== count( $lines ) ) {
		wp_update_post( array( 'ID' => $nav[0]->ID, 'post_content' => implode( "\n", $keep ) ) );
	}
}, 46 );
