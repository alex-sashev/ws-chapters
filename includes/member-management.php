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
	return array( 'Member', 'President', 'Chapter President', 'Vice President', 'Sergeant of Arms', 'Secretary', 'Treasurer', 'Road Captain', 'Other' );
}

/** A page by its name, wherever it sits (get_page_by_path misses pages under a parent page). */
function ws_mm_page( $slug ) {
	$found = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'name' => $slug, 'posts_per_page' => 1, 'no_found_rows' => true ) );
	return $found ? $found[0] : null;
}

/** The row of links between the member pages. $current is 'library', 'directory', 'account' or 'manage'; $wide matches the wide member pages. */
function ws_mm_subnav( $current, $wide = false ) {
	$items = array(
		'library'   => array( __( 'Library', 'ws-chapters' ), ws_mm_page( 'members' ) ),
		'directory' => array( __( 'Members', 'ws-chapters' ), ws_mm_page( 'member-directory' ) ),
		'account'   => array( __( 'My profile', 'ws-chapters' ), ws_mm_page( 'member-account' ) ),
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
	if ( in_array( 'administrator', (array) $target->roles, true ) ) {
		return false; // Super Admins are never removed from here (only title and chapter, below).
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

/** Who may set someone's chapter (and so country): a Super Admin for anyone, themselves included; a Country Admin for their people. */
function ws_mm_may_place( $target_id ) {
	return ( ws_mm_is_super() && get_userdata( $target_id ) ) || ws_mm_may_manage_user( $target_id );
}

/** Who may set a title: Super Admin (anyone, themselves included), Country Admin of the person's country, Chapter Editor of the person's chapter. */
function ws_mm_may_set_title( $target_id ) {
	if ( ws_mm_may_place( $target_id ) ) {
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

/** Road name: the WordPress nickname. */
function ws_mm_road_name( $user ) {
	$nick = trim( (string) get_user_meta( $user->ID, 'nickname', true ) );
	// Invited people start with a random login (ws_…) as nickname: that is not a road name.
	return ( $nick === $user->user_login && 0 === strpos( $nick, 'ws_' ) ) || $nick === ws_mm_person_name( $user ) ? '' : $nick;
}

/** A dash for an empty cell, marked so it stands out. */
function ws_mm_cell( $text, $missing = '' ) {
	return '' !== $text ? esc_html( $text ) : '<span class="ws-mm__missing">' . esc_html( '' !== $missing ? $missing : '—' ) . '</span>';
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
	return get_users( array( 'role__in' => array( 'ws_member', 'ws_chapter_editor', 'ws_country_admin', 'administrator' ), 'orderby' => 'display_name', 'order' => 'ASC' ) );
}

/** A labelled field for the member pages: label above, the control below, all the same size. */
function ws_mm_field( $label, $control, $class = '' ) {
	return '<label class="ws-mm__field' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '"><span class="ws-mm__label">' . esc_html( $label ) . '</span>' . $control . '</label>';
}

/** Country drop-down. $first is the label of the empty choice ('' for none). */
function ws_mm_country_select( $name, array $terms, $selected, $first = '' ) {
	$html = '<select name="' . esc_attr( $name ) . '" class="ws-mm__input" data-ws-mm-country>' . ( '' !== $first ? '<option value="">' . esc_html( $first ) . '</option>' : '' );
	foreach ( $terms as $c ) {
		$html .= '<option value="' . (int) $c->term_id . '"' . selected( (int) $selected, (int) $c->term_id, false ) . '>' . esc_html( ws_display_name( $c->name ) ) . '</option>';
	}
	return $html . '</select>';
}

/** Chapter drop-down; each choice carries its country, so the list follows the country drop-down in the same form. */
function ws_mm_chapter_select( $name, array $chapters, $selected, $first ) {
	$html = '<select name="' . esc_attr( $name ) . '" class="ws-mm__input" data-ws-mm-chapter><option value="">' . esc_html( $first ) . '</option>';
	foreach ( $chapters as $c ) {
		$t     = ws_chapter_country( $c->ID );
		$html .= '<option value="' . (int) $c->ID . '" data-country="' . ( $t ? (int) $t->term_id : 0 ) . '"' . selected( (int) $selected, (int) $c->ID, false ) . '>' . esc_html( ws_display_name( $c->post_title ) ) . '</option>';
	}
	return $html . '</select>';
}

/** Title drop-down. A title of their own (typed under "Other") shows as itself, e.g. "Guardian of Membership". */
function ws_mm_title_select( $name, $selected, $first ) {
	$html = '<select name="' . esc_attr( $name ) . '" class="ws-mm__input" data-ws-mm-title><option value="">' . esc_html( $first ) . '</option>';
	foreach ( ws_mm_titles() as $tt ) {
		if ( 'Other' === $tt && '' !== $selected && ! in_array( $selected, ws_mm_titles(), true ) ) {
			$html .= '<option value="' . esc_attr( $selected ) . '" selected>' . esc_html( $selected ) . '</option>';
		}
		$html .= '<option value="' . esc_attr( $tt ) . '"' . selected( $selected, $tt, false ) . '>' . esc_html( 'Other' === $tt ? __( 'Other (type it)', 'ws-chapters' ) : $tt ) . '</option>';
	}
	return $html . '</select>';
}

/** The text box for "Other", shown only while "Other" is chosen. */
function ws_mm_title_other_input( $name ) {
	return '<input type="text" name="' . esc_attr( $name ) . '" class="ws-mm__input" data-ws-mm-other maxlength="60" placeholder="' . esc_attr__( 'e.g. Guardian of Membership', 'ws-chapters' ) . '" aria-label="' . esc_attr__( 'Your own title', 'ws-chapters' ) . '">';
}

/**
 * The title to store from a posted choice: a listed title, the typed text for "Other", or the person's
 * current title kept as it is. '' clears it. Returns false when the choice is not allowed.
 */
function ws_mm_resolve_title( $choice, $other, $current ) {
	if ( '' === $choice || ( in_array( $choice, ws_mm_titles(), true ) && 'Other' !== $choice ) || ( '' !== $current && $choice === $current ) ) {
		return $choice;
	}
	if ( 'Other' === $choice ) {
		$other = trim( $other );
		return '' !== $other && mb_strlen( $other ) <= 60 ? $other : false;
	}
	return false;
}

/** Titles to filter by: the list, plus the people's own titles in use. */
function ws_mm_title_choices( array $people ) {
	$list = array_values( array_diff( ws_mm_titles(), array( 'Other' ) ) );
	foreach ( $people as $u ) {
		$t = (string) get_user_meta( $u->ID, 'ws_title', true );
		if ( '' !== $t && ! in_array( $t, $list, true ) ) {
			$list[] = $t;
		}
	}
	return $list;
}

function ws_mm_title_matches( $title, $filter ) {
	return '' === $filter || $title === $filter;
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
function syncChapters(form){var c=form.querySelector('[data-ws-mm-country]'),ch=form.querySelector('[data-ws-mm-chapter]');if(!c||!ch)return;var v=c.value;Array.prototype.forEach.call(ch.options,function(o){if(o.value==='')return;var show=v===''||o.getAttribute('data-country')===v;o.hidden=!show;o.disabled=!show;if(!show&&o.selected){ch.value='';}});}
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
		$road  = ws_mm_road_name( $u );
		$phone = (string) get_user_meta( $u->ID, 'ws_phone', true );
		if ( '' !== $search && false === mb_stripos( $name . ' ' . $road . ' ' . $u->user_email, $search ) ) {
			continue;
		}
		++$count;
		$rows .= '<tr>'
			. '<td><div class="ws-mm__person">' . ws_mm_avatar_html( $u->ID, $name ) . '<strong>' . esc_html( $name ) . '</strong></div></td>'
			. '<td data-label="' . esc_attr__( 'Road name', 'ws-chapters' ) . '">' . ( $road ? '<span class="ws-mm__road">' . esc_html( $road ) . '</span>' : ws_mm_cell( '' ) ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Title', 'ws-chapters' ) . '">' . ws_mm_cell( $t ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Country', 'ws-chapters' ) . '">' . ws_mm_cell( ws_mm_country_names( $u->ID ) ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Chapter', 'ws-chapters' ) . '">' . ws_mm_cell( ws_mm_chapter_names( $u->ID ) ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Email', 'ws-chapters' ) . '"><a href="mailto:' . esc_attr( $u->user_email ) . '">' . esc_html( $u->user_email ) . '</a></td>'
			. '<td data-label="' . esc_attr__( 'Phone', 'ws-chapters' ) . '">' . ( $phone ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>' : '' ) . '</td>'
			. '</tr>';
	}
	$title_filter = '<select name="ws_mm_title" class="ws-mm__input"><option value="">' . esc_html__( 'All titles', 'ws-chapters' ) . '</option>';
	foreach ( ws_mm_title_choices( ws_mm_people() ) as $tt ) {
		$title_filter .= '<option value="' . esc_attr( $tt ) . '"' . selected( $title, $tt, false ) . '>' . esc_html( $tt ) . '</option>';
	}
	$title_filter .= '</select>';
	$terms    = (array) get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false ) );
	$chapters = ws_mm_chapters_in( array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) );
	$filtered = $country || $chapter || '' !== $title || '' !== $search;
	$filter   = '<form method="get" class="ws-mm__bar">'
		. ws_mm_field( __( 'Search', 'ws-chapters' ), '<input type="search" name="ws_mm_q" class="ws-mm__input" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name, road name or email', 'ws-chapters' ) . '">', 'ws-mm__field--wide' )
		. ws_mm_field( __( 'Country', 'ws-chapters' ), ws_mm_country_select( 'ws_mm_country', $terms, $country, __( 'All countries', 'ws-chapters' ) ) )
		. ws_mm_field( __( 'Chapter', 'ws-chapters' ), ws_mm_chapter_select( 'ws_mm_chapter', $chapters, $chapter, __( 'All chapters', 'ws-chapters' ) ) )
		. ws_mm_field( __( 'Title', 'ws-chapters' ), $title_filter )
		. '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn">' . esc_html__( 'Filter', 'ws-chapters' ) . '</button>'
		. ( $filtered ? '<a class="ws-mm__clear" href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Clear', 'ws-chapters' ) . '</a>' : '' ) . '</div></form>';
	/* translators: %d: number of members */
	$summary = '<p class="ws-mm__count">' . esc_html( sprintf( _n( '%d member', '%d members', $count, 'ws-chapters' ), $count ) ) . '</p>';
	$head    = '<thead><tr><th>' . esc_html__( 'Name', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Road name', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Title', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Country', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Chapter', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Email', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Phone', 'ws-chapters' ) . '</th></tr></thead>';
	return ws_mm_subnav( 'directory', true ) . '<div class="ws-mm alignwide">' . $filter . $summary . '<div class="ws-mm__scroll"><table class="ws-mm__table ws-mm__table--directory">' . $head . '<tbody>' . ( $rows ?: '<tr><td colspan="7" class="ws-mm__empty">' . esc_html__( 'No members match.', 'ws-chapters' ) . '</td></tr>' ) . '</tbody></table></div></div>' . ws_mm_script();
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
		$msg = $ok ? sprintf( __( 'Invitation sent to %s. If it has not arrived within a few minutes, ask them to look in Junk/Spam, then use "Resend invitation".', 'ws-chapters' ), sanitize_email( wp_unslash( $_POST['ws_mm_email'] ?? '' ) ) ) : $msg; // phpcs:ignore WordPress.Security.NonceVerification
	} elseif ( 'remove' === $_POST['ws_mm_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		$uid = absint( $_POST['ws_mm_user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! ws_mm_may_manage_user( $uid ) ) {
			$msg = __( 'You cannot remove this person.', 'ws-chapters' );
		} else {
			// Permanent: the account, email and profile are deleted. Everything they added (news, events,
			// documents, photos) stays on the site and is credited to the person who removed them.
			$u    = get_userdata( $uid );
			$name = ws_mm_person_name( $u );
			ws_mm_log( 'removed', $uid, $name . ' <' . $u->user_email . '>' );
			$photo = (int) get_user_meta( $uid, 'ws_avatar', true );
			if ( $photo ) {
				wp_delete_attachment( $photo, true ); // Their profile photo is personal: it goes too.
			}
			require_once ABSPATH . 'wp-admin/includes/user.php';
			$ok = wp_delete_user( $uid, get_current_user_id() );
			/* translators: %s: person's name */
			$msg = $ok ? sprintf( __( '%s has been removed permanently. What they added to the site stays, now credited to you.', 'ws-chapters' ), $name ) : __( 'Sorry, this person could not be removed. Please try again.', 'ws-chapters' );
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
	} elseif ( 'place' === $_POST['ws_mm_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		$uid  = absint( $_POST['ws_mm_user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$chap = absint( $_POST['ws_mm_chapter'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$t    = $chap ? ws_chapter_country( $chap ) : null;
		if ( ! ws_mm_may_place( $uid ) || ! $t || ! array_key_exists( $chap, ws_mm_chapters_in( ws_mm_manageable_countries() ) ) ) {
			$msg = __( 'You cannot set this chapter.', 'ws-chapters' );
		} else {
			update_user_meta( $uid, 'ws_scope_chapters', array( $chap ) );
			$countries = array( (int) $t->term_id );
			if ( in_array( 'ws_country_admin', (array) get_userdata( $uid )->roles, true ) ) {
				$countries = array_values( array_unique( array_merge( ws_mm_user_ids( $uid ), $countries ) ) ); // A Country Admin keeps the countries they run.
			}
			update_user_meta( $uid, 'ws_scope_countries', $countries );
			ws_mm_log( 'chapter', $uid, get_the_title( $chap ) );
			$ok = true;
			/* translators: 1: person's name, 2: chapter */
			$msg = sprintf( __( '%1$s is now in %2$s.', 'ws-chapters' ), ws_mm_person_name( get_userdata( $uid ) ), ws_display_name( get_the_title( $chap ) ) );
		}
	} elseif ( 'title' === $_POST['ws_mm_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		$uid   = absint( $_POST['ws_mm_user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$title = ws_mm_resolve_title(
			sanitize_text_field( wp_unslash( $_POST['ws_mm_title'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			sanitize_text_field( wp_unslash( $_POST['ws_mm_title_other'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification
			(string) get_user_meta( $uid, 'ws_title', true )
		);
		if ( ! ws_mm_may_set_title( $uid ) ) {
			$msg = __( 'You cannot set this title.', 'ws-chapters' );
		} elseif ( false === $title ) {
			$msg = __( 'For "Other", please type the title.', 'ws-chapters' );
		} elseif ( '' === $title ) {
			delete_user_meta( $uid, 'ws_title' );
			ws_mm_log( 'title', $uid, '-' );
			$ok  = true;
			$msg = __( 'Title removed.', 'ws-chapters' );
		} else {
			update_user_meta( $uid, 'ws_title', $title );
			ws_mm_log( 'title', $uid, $title );
			$ok  = true;
			/* translators: %s: person's name */
			$msg = sprintf( __( 'Title saved for %s.', 'ws-chapters' ), ws_mm_person_name( get_userdata( $uid ) ) );
		}
	}
	if ( $ok ) {
		do_action( 'litespeed_purge_all' ); // Lists of people may be cached anywhere on the site.
	}
	// Back to the page the form was on. (wp_get_referer() is empty when the form posts to its own page, which sent people to the home page.)
	$back = get_queried_object_id() ? get_permalink( get_queried_object_id() ) : '';
	wp_safe_redirect( ws_mm_flash_url( $back ? $back : ( wp_get_referer() ?: home_url( '/' ) ), $ok, (string) $msg ) );
	exit;
}, 3 );

/**
 * The address to come back to with a result message. The message is kept for a few minutes under a one-off
 * key that is part of the address, so it shows even where pages are cached and never shows twice.
 */
function ws_mm_flash_url( $url, $ok, $text ) {
	$key = strtolower( wp_generate_password( 12, false, false ) );
	set_transient( 'ws_flash_' . $key, array( 'ok' => (bool) $ok, 'text' => $text, 'user' => get_current_user_id() ), 10 * MINUTE_IN_SECONDS );
	return add_query_arg( 'ws_done', $key, remove_query_arg( 'ws_done', $url ) );
}

/** The result message for this page view (as a notice), once. */
function ws_mm_flash_notice() {
	$key = isset( $_GET['ws_done'] ) ? sanitize_key( wp_unslash( $_GET['ws_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$msg = '' !== $key ? get_transient( 'ws_flash_' . $key ) : false;
	if ( ! $msg || (int) $msg['user'] !== get_current_user_id() ) {
		return '';
	}
	delete_transient( 'ws_flash_' . $key );
	return ws_form_notice( $msg['ok'] ? 'success' : 'error', esc_html( $msg['text'] ) );
}

/* The member pages are never served from a page cache: they differ per person and carry fresh form keys. */
function ws_mm_is_member_page() {
	foreach ( array( 'members', 'member-directory', 'member-management', 'member-account' ) as $slug ) {
		$p = ws_mm_page( $slug );
		if ( $p && is_page( $p->ID ) ) {
			return true;
		}
	}
	return false;
}
add_action( 'template_redirect', function () {
	if ( isset( $_GET['ws_done'] ) || ws_mm_is_member_page() ) { // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'member page' );
	}
}, 1 );

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
	if ( ! $chaps ) {
		return __( 'Please choose a chapter. Everyone belongs to a country and a chapter.', 'ws-chapters' );
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
	update_user_meta( $uid, 'ws_title', 'Member' ); // They can change it on their profile.
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
	$link = ws_ma_url( array( 'ws_key' => $key, 'ws_login' => rawurlencode( $user->user_login ) ) );
	$sent = ws_send_email( $user->user_email, __( 'Your invitation to Widows Sons Europe', 'ws-chapters' ), array(
		'preheader' => __( "You're invited to the Widows Sons Europe members' area.", 'ws-chapters' ),
		'heading'   => __( 'Welcome, Brother', 'ws-chapters' ),
		'before'    => array(
			__( "You have been invited to the Widows Sons Europe members' area: the member list of all European chapters, the shared library and your own profile.", 'ws-chapters' ),
			__( 'Choose your password to get started. You will sign in with this email address.', 'ws-chapters' ),
		),
		'button'    => __( 'Set my password', 'ws-chapters' ),
		'url'       => $link,
		'after'     => array(
			/* translators: %d: days */
			sprintf( __( 'This link expires in %d days. If it has expired, ask your Country Admin to send a new one.', 'ws-chapters' ), WS_MM_INVITE_DAYS ),
			__( 'After that you land on your profile: add your first and last name, road name, phone and title.', 'ws-chapters' ),
		),
	) );
	return $sent ? null : __( 'The invitation email could not be sent. Check the mail settings, then resend.', 'ws-chapters' );
}

/* Any email the site could not hand over to the mail service (Brevo) shows in Recent activity, with the reason. */
add_action( 'wp_mail_failed', function ( $error ) {
	$data = $error->get_error_data();
	$to   = is_array( $data ) && ! empty( $data['to'] ) ? (array) $data['to'] : array();
	$user = $to ? get_user_by( 'email', $to[0] ) : null;
	ws_mm_log( 'mail_failed', $user ? $user->ID : 0, trim( implode( ', ', $to ) . ' — ' . $error->get_error_message(), ' —' ) );
} );

// Invitation links last WS_MM_INVITE_DAYS days (WordPress default is one day). Applies to all password reset links.
add_filter( 'password_reset_expiration', function () {
	return WS_MM_INVITE_DAYS * DAY_IN_SECONDS;
} );

add_shortcode( 'ws_member_manage', function () {
	if ( ! is_user_logged_in() || ! ws_mm_can_manage() ) {
		return '';
	}
	$out   = ws_mm_subnav( 'manage', true ) . '<div class="ws-mm alignwide">';
	$out  .= ws_mm_flash_notice();
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
		. ws_mm_field( __( 'Chapter', 'ws-chapters' ), str_replace( '<select ', '<select required ', ws_mm_chapter_select( 'ws_mm_chapter', ws_mm_chapters_in( $mine ), 0, __( 'Choose a chapter', 'ws-chapters' ) ) ) )
		. '<p class="ws-mm__hint ws-muted">' . esc_html__( 'Everyone belongs to a country and a chapter.', 'ws-chapters' ) . '</p>'
		. '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn">' . esc_html__( 'Send invitation', 'ws-chapters' ) . '</button></div></form></section>';

	$rows            = '';
	$labels          = ws_mm_role_labels();
	$chapter_choices = ws_mm_chapters_in( $mine );
	foreach ( ws_mm_people() as $u ) {
		$can_title  = ws_mm_may_set_title( $u->ID );
		$can_manage = ws_mm_may_manage_user( $u->ID );
		$can_place  = ws_mm_may_place( $u->ID );
		if ( ! $can_title && ! $can_manage && ! $can_place ) {
			continue;
		}
		$name    = ws_mm_person_name( $u );
		$road    = ws_mm_road_name( $u );
		$title   = (string) get_user_meta( $u->ID, 'ws_title', true );
		$role    = '';
		foreach ( (array) $u->roles as $r ) {
			$role = 'administrator' === $r ? __( 'Super Admin', 'ws-chapters' ) : ( $labels[ $r ] ?? $role );
		}
		if ( get_current_user_id() === $u->ID ) {
			$role .= ' · ' . __( 'you', 'ws-chapters' );
		}
		$pending = ws_mm_is_pending( $u->ID );
		$hidden  = '<input type="hidden" name="ws_mm_user" value="' . (int) $u->ID . '">';
		$tcell   = esc_html( $title );
		if ( $can_title ) {
			$tcell = '<form method="post" class="ws-mm__inline">' . $nonce . $hidden . '<input type="hidden" name="ws_mm_action" value="title">'
				. ws_mm_title_select( 'ws_mm_title', $title, __( 'No title', 'ws-chapters' ) )
				. ws_mm_title_other_input( 'ws_mm_title_other' )
				. '<button type="submit" class="ws-mm__btn ws-mm__btn--small">' . esc_html__( 'Save', 'ws-chapters' ) . '</button></form>';
		}
		$my_chaps = array_values( array_filter( array_map( 'intval', (array) get_user_meta( $u->ID, 'ws_scope_chapters', true ) ) ) );
		$ccell    = ws_mm_cell( ws_mm_chapter_names( $u->ID ), __( 'Missing', 'ws-chapters' ) );
		if ( $can_place && count( $my_chaps ) <= 1 ) {
			// The chapter sets the country too.
			$ccell = '<form method="post" class="ws-mm__inline">' . $nonce . $hidden . '<input type="hidden" name="ws_mm_action" value="place">'
				. str_replace( '<select ', '<select required aria-label="' . esc_attr__( 'Chapter', 'ws-chapters' ) . '" ', ws_mm_chapter_select( 'ws_mm_chapter', $chapter_choices, $my_chaps ? $my_chaps[0] : 0, __( 'Choose a chapter', 'ws-chapters' ) ) )
				. '<button type="submit" class="ws-mm__btn ws-mm__btn--small">' . esc_html__( 'Save', 'ws-chapters' ) . '</button></form>';
		}
		$actions = '';
		if ( $can_manage && $pending ) {
			$actions .= '<form method="post" class="ws-mm__inline">' . $nonce . $hidden . '<input type="hidden" name="ws_mm_action" value="resend"><button type="submit" class="ws-mm__btn ws-mm__btn--small ws-mm__btn--ghost">' . esc_html__( 'Resend invitation', 'ws-chapters' ) . '</button></form>';
		}
		if ( $can_manage ) {
			$actions .= '<form method="post" class="ws-mm__inline" onsubmit="return confirm(\'' . esc_js( __( 'Remove this person permanently? Their account, email and profile are deleted and cannot be brought back. What they added to the site stays, credited to you.', 'ws-chapters' ) ) . '\')">' . $nonce . $hidden . '<input type="hidden" name="ws_mm_action" value="remove"><button type="submit" class="ws-mm__btn ws-mm__btn--small ws-mm__btn--danger">' . esc_html__( 'Remove member', 'ws-chapters' ) . '</button></form>';
		}
		$rows .= '<tr>'
			. '<td><div class="ws-mm__person">' . ws_mm_avatar_html( $u->ID, $name ) . '<span><strong>' . esc_html( $name ) . '</strong>' . ( $road ? '<span class="ws-mm__road">' . esc_html( $road ) . '</span>' : '' ) . '<a class="ws-mm__email" href="mailto:' . esc_attr( $u->user_email ) . '">' . esc_html( $u->user_email ) . '</a><span class="ws-muted">' . esc_html( $role ) . '</span></span></div></td>'
			. '<td data-label="' . esc_attr__( 'Title', 'ws-chapters' ) . '">' . $tcell . '</td>'
			. '<td data-label="' . esc_attr__( 'Country', 'ws-chapters' ) . '">' . ws_mm_cell( ws_mm_country_names( $u->ID ), __( 'Missing', 'ws-chapters' ) ) . '</td>'
			. '<td data-label="' . esc_attr__( 'Chapter', 'ws-chapters' ) . '">' . $ccell . '</td>'
			. '<td data-label="' . esc_attr__( 'Status', 'ws-chapters' ) . '"><span class="ws-mm__status ws-mm__status--' . ( $pending ? 'invited' : 'active' ) . '">' . ( $pending ? esc_html__( 'Invited', 'ws-chapters' ) : esc_html__( 'Active', 'ws-chapters' ) ) . '</span></td>'
			. '<td data-label="' . esc_attr__( 'Actions', 'ws-chapters' ) . '"><div class="ws-mm__row-actions">' . $actions . '</div></td>'
			. '</tr>';
	}
	$head = '<thead><tr><th>' . esc_html__( 'Name, road name and email', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Title', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Country', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Chapter', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Status', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Actions', 'ws-chapters' ) . '</th></tr></thead>';
	$out .= '<section class="ws-mm__section"><h3 class="ws-mm__heading">' . esc_html__( 'People you manage', 'ws-chapters' ) . '</h3>'
		. '<div class="ws-mm__scroll"><table class="ws-mm__table ws-mm__table--manage">' . $head . '<tbody>' . ( $rows ?: '<tr><td colspan="6" class="ws-mm__empty">' . esc_html__( 'Nobody yet. Invite someone above.', 'ws-chapters' ) . '</td></tr>' ) . '</tbody></table></div></section>';
	if ( $super ) {
		$log  = array_reverse( array_slice( (array) get_option( 'ws_mm_log', array() ), -50 ) );
		$lrow = '';
		$acts = array( 'invited' => __( 'Invited', 'ws-chapters' ), 'resent' => __( 'Invitation sent again', 'ws-chapters' ), 'removed' => __( 'Removed permanently', 'ws-chapters' ), 'title' => __( 'Title', 'ws-chapters' ), 'chapter' => __( 'Chapter', 'ws-chapters' ), 'profile' => __( 'Own profile', 'ws-chapters' ), 'mail_failed' => __( 'Email NOT sent', 'ws-chapters' ) );
		foreach ( $log as $e ) {
			$by     = get_userdata( (int) $e['by'] );
			$target = get_userdata( (int) $e['target'] );
			$detail = '-' === $e['detail'] ? __( 'removed', 'ws-chapters' ) : $e['detail'];
			$lrow  .= '<tr><td>' . esc_html( mysql2date( 'j M Y H:i', $e['time'] ) ) . '</td><td>' . esc_html( $by ? ws_mm_person_name( $by ) : '?' ) . '</td><td>' . esc_html( $acts[ $e['action'] ] ?? $e['action'] ) . ( $detail ? ' <span class="ws-muted">(' . esc_html( $detail ) . ')</span>' : '' ) . '</td><td>' . esc_html( $target ? ws_mm_person_name( $target ) : __( '(deleted)', 'ws-chapters' ) ) . '</td><td>' . ( $target ? '<a href="mailto:' . esc_attr( $target->user_email ) . '">' . esc_html( $target->user_email ) . '</a>' : '' ) . '</td></tr>';
		}
		// Super Admins only; folded away until opened.
		$out .= '<details class="ws-mm__section ws-mm__fold"><summary class="ws-mm__heading">' . esc_html__( 'Recent activity', 'ws-chapters' ) . ' <span class="ws-muted">(' . count( $log ) . ')</span></summary><div class="ws-mm__scroll"><table class="ws-mm__table ws-mm__table--log"><thead><tr><th>' . esc_html__( 'When', 'ws-chapters' ) . '</th><th>' . esc_html__( 'By', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Action', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Person', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Email', 'ws-chapters' ) . '</th></tr></thead><tbody>' . ( $lrow ?: '<tr><td colspan="5" class="ws-mm__empty">' . esc_html__( 'No activity yet.', 'ws-chapters' ) . '</td></tr>' ) . '</tbody></table></div></details>';
	}
	return $out . '</div>' . ws_mm_script();
} );

/* The member's own details, filled in after the invitation: phone here; the road name is WordPress's Nickname field, first and last name are WordPress's own. */
function ws_mm_profile_fields( $user ) {
	if ( ! is_object( $user ) || ! array_intersect( (array) $user->roles, array( 'ws_member', 'ws_chapter_editor', 'ws_country_admin' ) ) ) {
		return;
	}
	?>
	<h2><?php esc_html_e( 'Widows Sons details', 'ws-chapters' ); ?></h2>
	<table class="form-table" role="presentation">
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

/**
 * The member pages under Members: member list, member management and My profile. Added on install and whenever
 * one is missing after a WS_MM_PAGES change; an existing page is never changed.
 */
const WS_MM_PAGES = '1.0';
add_action( 'init', function () {
	if ( WS_MM_PAGES === get_option( 'ws_mm_pages' ) || ! post_type_exists( 'page' ) ) {
		return;
	}
	$parent = ws_mm_page( 'members' );
	if ( ! $parent ) {
		return; // The Members page comes first (site setup); try again on a later page load.
	}
	update_option( 'ws_mm_pages', WS_MM_PAGES, false );
	$pages = array(
		'member-directory'  => array( __( 'Member directory', 'ws-chapters' ), '[ws_member_directory]' ),
		'member-management' => array( __( 'Member management', 'ws-chapters' ), '[ws_member_manage]' ),
		'member-account'    => array( __( 'My profile', 'ws-chapters' ), '[ws_member_account]' ),
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
			'post_parent'  => $parent->ID,
			'post_content' => '<!-- wp:html -->' . $def[1] . '<!-- /wp:html -->',
		) );
	}
}, 45 );

/* On the profile screen, WordPress's "Nickname" is the road name. */
add_filter( 'gettext', function ( $translation, $text, $domain ) {
	if ( 'default' === $domain && 'Nickname' === $text && is_admin() && in_array( $GLOBALS['pagenow'] ?? '', array( 'profile.php', 'user-edit.php', 'user-new.php' ), true ) ) {
		return 'Road name (nickname)';
	}
	return $translation;
}, 10, 3 );
