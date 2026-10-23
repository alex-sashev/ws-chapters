<?php
/**
 * Public forms.
 *
 * [ws_event_submit]  Signed-in members' events are published straight away under their account.
 *                    A visitor's event is saved as "Pending review"; admins and the Country Admins
 *                    of that country get an email. Publishing it is the approval.
 * [ws_join_form]     Application to join, sent to the chosen chapter. A Master Mason picks a country
 *                    and then a chapter ("Chapter — City"). One copy goes to the site admin, one to
 *                    the chapter's contact email. Every application is also kept in the admin.
 *
 * Spam protection without third-party services: a hidden honeypot field, a minimum fill-in time,
 * a signed form token and a per-visitor hourly limit.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Shared
 * ---------------------------------------------------------------------- */

/** Hidden fields every form carries: honeypot, signed start time, form id. */
function ws_form_guard_fields( $form ) {
	$t = time();
	return sprintf(
		'<input type="hidden" name="ws_form" value="%1$s"><input type="hidden" name="ws_t" value="%2$d"><input type="hidden" name="ws_sig" value="%3$s"><div class="ws-hp" aria-hidden="true"><label>Leave this empty <input type="text" name="ws_website" tabindex="-1" autocomplete="off"></label></div>',
		esc_attr( $form ),
		$t,
		esc_attr( wp_hash( $form . '|' . $t ) )
	);
}

/** True if the submission looks human; otherwise a short error message. */
function ws_form_guard_check( $form ) {
	if ( ! empty( $_POST['ws_website'] ) ) {
		return __( 'Your submission could not be accepted.', 'ws-chapters' );
	}
	$t   = isset( $_POST['ws_t'] ) ? (int) $_POST['ws_t'] : 0;
	$sig = isset( $_POST['ws_sig'] ) ? sanitize_text_field( wp_unslash( $_POST['ws_sig'] ) ) : '';
	if ( ! $t || ! hash_equals( wp_hash( $form . '|' . $t ), $sig ) || time() - $t > DAY_IN_SECONDS ) {
		return __( 'The form expired. Please reload the page and try again.', 'ws-chapters' );
	}
	if ( time() - $t < 4 ) {
		return __( 'That was quick! Please check the form and send it again.', 'ws-chapters' );
	}
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'ws_rate_' . $form . '_' . md5( $ip . wp_salt() );
	$n   = (int) get_transient( $key );
	if ( $n >= 8 ) {
		return __( 'Too many submissions from your connection. Please try again in an hour.', 'ws-chapters' );
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	return true;
}

/** A posted text field, trimmed and sanitised. */
function ws_post_field( $key, $textarea = false ) {
	if ( ! isset( $_POST[ $key ] ) ) {
		return '';
	}
	$v = wp_unslash( $_POST[ $key ] );
	return $textarea ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
}

/** Send a plain-text email; returns true if WordPress handed it to the mail system. */
function ws_send_mail( $to, $subject, $lines, $reply_to = '' ) {
	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	if ( $reply_to && is_email( $reply_to ) ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}
	return (bool) wp_mail( $to, $subject, implode( "\n", $lines ), $headers );
}

/** Field markup. */
function ws_field( $name, $label, $type = 'text', $args = array() ) {
	$args  = wp_parse_args( $args, array( 'required' => false, 'value' => '', 'help' => '', 'options' => array(), 'attrs' => '' ) );
	$id    = 'ws-f-' . $name;
	$req   = $args['required'] ? ' required' : '';
	$star  = $args['required'] ? ' <span class="ws-req" aria-hidden="true">*</span>' : '';
	$help  = $args['help'] ? '<span class="ws-help" id="' . esc_attr( $id ) . '-help">' . esc_html( $args['help'] ) . '</span>' : '';
	$desc  = $args['help'] ? ' aria-describedby="' . esc_attr( $id ) . '-help"' : '';
	$label = '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $star . '</label>';
	if ( 'textarea' === $type ) {
		$input = sprintf( '<textarea id="%1$s" name="%2$s" rows="6"%3$s%4$s %5$s>%6$s</textarea>', esc_attr( $id ), esc_attr( $name ), $req, $desc, $args['attrs'], esc_textarea( $args['value'] ) );
	} elseif ( 'select' === $type ) {
		$input = sprintf( '<select id="%1$s" name="%2$s"%3$s%4$s %5$s>%6$s</select>', esc_attr( $id ), esc_attr( $name ), $req, $desc, $args['attrs'], $args['options'] );
	} else {
		$input = sprintf( '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s"%5$s%6$s %7$s>', esc_attr( $type ), esc_attr( $id ), esc_attr( $name ), esc_attr( $args['value'] ), $req, $desc, $args['attrs'] );
	}
	return '<p class="ws-field ws-field--' . esc_attr( $type ) . '">' . $label . $input . $help . '</p>';
}

/** Country <option>s. $only_with_chapters limits the list to countries that have chapters. */
function ws_country_options( $selected = 0, $only_with_chapters = false ) {
	$out = '<option value="">' . esc_html__( '— Choose a country —', 'ws-chapters' ) . '</option>';
	foreach ( get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false, 'orderby' => 'name' ) ) as $t ) {
		if ( $only_with_chapters && ! ws_chapter_count( $t ) ) {
			continue;
		}
		$out .= sprintf( '<option value="%d"%s>%s</option>', $t->term_id, selected( (int) $selected, $t->term_id, false ), esc_html( $t->name ) );
	}
	return $out;
}

/** Chapter <option>s grouped by country; each option carries its country for the filter script. */
function ws_chapter_options( $selected = 0, $placeholder = '' ) {
	$out = '<option value="">' . esc_html( $placeholder ?: __( '— Choose a chapter —', 'ws-chapters' ) ) . '</option>';
	foreach ( get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false, 'orderby' => 'name' ) ) as $t ) {
		$chapters = ws_get_chapters( $t->slug );
		if ( ! $chapters ) {
			continue;
		}
		$out .= '<optgroup label="' . esc_attr( $t->name ) . '" data-country="' . (int) $t->term_id . '">';
		foreach ( $chapters as $c ) {
			$city  = get_post_meta( $c->ID, 'city', true );
			$label = ws_chapter_name( $c ) . ( $city ? ' — ' . $city : '' );
			$out  .= sprintf( '<option value="%1$d" data-country="%2$d"%3$s>%4$s</option>', $c->ID, $t->term_id, selected( (int) $selected, $c->ID, false ), esc_html( $label ) );
		}
		$out .= '</optgroup>';
	}
	return $out;
}

function ws_form_notice( $type, $message ) {
	return '<div class="ws-form-notice ws-form-notice--' . esc_attr( $type ) . '" role="' . ( 'error' === $type ? 'alert' : 'status' ) . '">' . wp_kses_post( $message ) . '</div>';
}

function ws_consent_field( $text ) {
	return '<p class="ws-field ws-field--check"><label><input type="checkbox" name="ws_consent" value="1" required> ' . wp_kses_post( $text ) . ' <span class="ws-req" aria-hidden="true">*</span></label></p>';
}

function ws_privacy_link() {
	$url = get_privacy_policy_url();
	return $url ? '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html__( 'privacy policy', 'ws-chapters' ) . '</a>' : esc_html__( 'privacy policy', 'ws-chapters' );
}

/** Script that narrows the chapter list to the chosen country. */
function ws_enqueue_form_script() {
	wp_enqueue_script( 'ws-forms', plugin_dir_url( __DIR__ ) . 'assets/ws-forms.js', array(), filemtime( plugin_dir_path( __DIR__ ) . 'assets/ws-forms.js' ), true );
}

/* -------------------------------------------------------------------------
 * Event submission
 * ---------------------------------------------------------------------- */

/**
 * Handle a posted event; returns array( 'ok' => bool, 'message' => string ).
 * Signed-in members: published straight away under their account. Visitors: saved as pending for review.
 */
function ws_handle_event_submission() {
	$guard = ws_form_guard_check( 'event' );
	if ( true !== $guard ) {
		return array( 'ok' => false, 'message' => $guard );
	}
	$member = is_user_logged_in();
	$f = array(
		'title'       => ws_post_field( 'ws_title' ),
		'start'       => ws_sanitize_date( ws_post_field( 'ws_start_date' ) ),
		'end'         => ws_sanitize_date( ws_post_field( 'ws_end_date' ) ),
		'time'        => ws_sanitize_time( ws_post_field( 'ws_start_time' ) ),
		'country'     => (int) ws_post_field( 'ws_form_country' ),
		'city'        => ws_post_field( 'ws_city' ),
		'venue'       => ws_post_field( 'ws_venue' ),
		'chapter'     => (int) ws_post_field( 'ws_form_chapter' ),
		'organiser'   => ws_post_field( 'ws_organiser' ),
		'description' => ws_post_field( 'ws_description', true ),
		'contact'     => ws_post_field( 'ws_contact', true ),
		'name'        => ws_post_field( 'ws_name' ),
		'email'       => sanitize_email( ws_post_field( 'ws_email' ) ),
	);
	if ( $member ) { // Known from the account.
		$f['name']  = ws_mm_person_name( wp_get_current_user() );
		$f['email'] = wp_get_current_user()->user_email;
	}
	$missing = array();
	foreach ( array( 'title' => __( 'Event name', 'ws-chapters' ), 'start' => __( 'Start date', 'ws-chapters' ), 'country' => __( 'Country', 'ws-chapters' ), 'city' => __( 'City', 'ws-chapters' ), 'description' => __( 'Description', 'ws-chapters' ), 'contact' => __( 'Contact details for riders', 'ws-chapters' ), 'name' => __( 'Your name', 'ws-chapters' ) ) as $k => $label ) {
		if ( empty( $f[ $k ] ) ) {
			$missing[] = $label;
		}
	}
	if ( ! is_email( $f['email'] ) ) {
		$missing[] = __( 'Your email (a valid address)', 'ws-chapters' );
	}
	if ( empty( $_POST['ws_consent'] ) ) {
		$missing[] = __( 'Agreement to the privacy policy', 'ws-chapters' );
	}
	$images = ws_event_image_files();
	if ( is_wp_error( $images ) ) {
		return array( 'ok' => false, 'message' => esc_html( $images->get_error_message() ) );
	}
	$term = $f['country'] ? get_term( $f['country'], 'ws_country' ) : null;
	if ( $f['country'] && ( ! $term || is_wp_error( $term ) ) ) {
		$missing[] = __( 'Country', 'ws-chapters' );
	}
	if ( $missing ) {
		return array( 'ok' => false, 'message' => __( 'Please fill in:', 'ws-chapters' ) . ' ' . esc_html( implode( ', ', array_unique( $missing ) ) ) . '.' );
	}
	if ( $f['end'] && $f['end'] < $f['start'] ) {
		return array( 'ok' => false, 'message' => __( 'The end date is before the start date.', 'ws-chapters' ) );
	}
	if ( $f['chapter'] && 'ws_chapter' !== get_post_type( $f['chapter'] ) ) {
		$f['chapter'] = 0;
	}

	$id = wp_insert_post( array(
		'post_type'    => 'ws_event',
		'post_status'  => $member ? 'publish' : 'pending',
		'post_title'   => $f['title'],
		'post_content' => $f['description'],
		'post_author'  => $member ? get_current_user_id() : 0,
	), true );
	if ( is_wp_error( $id ) ) {
		return array( 'ok' => false, 'message' => __( 'Sorry, something went wrong saving your event. Please try again later.', 'ws-chapters' ) );
	}
	$meta = array( 'start_date' => $f['start'], 'end_date' => $f['end'], 'start_time' => $f['time'], 'city' => $f['city'] . ( $term ? ', ' . $term->name : '' ), 'venue' => $f['venue'], 'contact' => $f['contact'] );
	$meta['organiser'] = $f['organiser'] ?: ( $f['chapter'] ? ws_chapter_name( $f['chapter'] ) : '' );
	foreach ( array_filter( $meta ) as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	if ( $f['chapter'] ) {
		update_post_meta( $id, 'host_chapter', $f['chapter'] );
	}
	if ( ! $member ) { // A member is the event's author; a visitor's name and email are kept privately for the review.
		update_post_meta( $id, '_ws_submitted_by', $f['name'] );
		update_post_meta( $id, '_ws_submitted_email', $f['email'] );
	}
	wp_set_object_terms( $id, array( $f['country'] ), 'ws_country' );

	// Images: stored in the event's country folder (uploads/countries/{country}/), the first one becomes the poster.
	$image_ids = ws_save_event_images( $images, $id );

	if ( $member ) {
		do_action( 'litespeed_purge_all' ); // The calendar, country and chapter pages show it now.
		return array(
			'ok'      => true,
			'message' => esc_html__( 'Your event is published in the event calendar.', 'ws-chapters' ) . ' <a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html__( 'See the event', 'ws-chapters' ) . '</a>',
		);
	}

	// Tell the reviewers: site admins, plus Country Admins for this country.
	$to = array( get_option( 'admin_email' ) );
	foreach ( get_users( array( 'role' => 'ws_country_admin' ) ) as $u ) {
		if ( in_array( $f['country'], array_map( 'intval', (array) get_user_meta( $u->ID, 'ws_scope_countries', true ) ), true ) ) {
			$to[] = $u->user_email;
		}
	}
	ws_send_mail(
		array_unique( array_filter( $to ) ),
		/* translators: %s: event name */
		sprintf( __( 'New event to review: %s', 'ws-chapters' ), $f['title'] ),
		array(
			__( 'A new event was submitted on the website and is waiting for review.', 'ws-chapters' ),
			'',
			__( 'Event:', 'ws-chapters' ) . ' ' . $f['title'],
			__( 'When:', 'ws-chapters' ) . ' ' . $f['start'] . ( $f['end'] ? ' – ' . $f['end'] : '' ) . ( $f['time'] ? ', ' . $f['time'] : '' ),
			__( 'Where:', 'ws-chapters' ) . ' ' . implode( ', ', array_filter( array( $f['venue'], $f['city'], $term ? $term->name : '' ) ) ),
			__( 'Organiser:', 'ws-chapters' ) . ' ' . ( $meta['organiser'] ?: '—' ),
			__( 'Submitted by:', 'ws-chapters' ) . ' ' . $f['name'] . ' <' . $f['email'] . '>',
			/* translators: %d: number of images */
			sprintf( __( 'Images: %d', 'ws-chapters' ), count( $image_ids ) ),
			'',
			__( 'Review and publish it here:', 'ws-chapters' ),
			admin_url( 'post.php?post=' . $id . '&action=edit' ),
		),
		$f['email']
	);
	return array( 'ok' => true, 'message' => __( 'Thank you! Your event has been sent for review. It will appear in the event calendar once an admin approves it.', 'ws-chapters' ) );
}

const WS_EVENT_MAX_IMAGES   = 5;
const WS_EVENT_MAX_IMAGE_MB = 8;

/** The images sent with the event form, checked (count, size, type). Returns a list of $_FILES-style entries or WP_Error. */
function ws_event_image_files() {
	$raw = $_FILES['ws_event_images'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification -- checked by ws_form_guard_check().
	if ( ! $raw || ! is_array( $raw['name'] ?? null ) ) {
		return array();
	}
	$files = array();
	foreach ( array_keys( $raw['name'] ) as $i ) {
		if ( UPLOAD_ERR_NO_FILE === (int) $raw['error'][ $i ] ) {
			continue;
		}
		$file = array(
			'name'     => $raw['name'][ $i ],
			'type'     => $raw['type'][ $i ],
			'tmp_name' => $raw['tmp_name'][ $i ],
			'error'    => (int) $raw['error'][ $i ],
			'size'     => (int) $raw['size'][ $i ],
		);
		if ( UPLOAD_ERR_OK !== $file['error'] || $file['size'] > WS_EVENT_MAX_IMAGE_MB * MB_IN_BYTES ) {
			/* translators: 1: file name, 2: size in MB */
			return new WP_Error( 'ws_image', sprintf( __( 'The image "%1$s" could not be uploaded. Images can be up to %2$d MB each.', 'ws-chapters' ), $file['name'], WS_EVENT_MAX_IMAGE_MB ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], ws_event_image_mimes() );
		if ( empty( $check['type'] ) ) {
			/* translators: %s: file name */
			return new WP_Error( 'ws_image', sprintf( __( '"%s" is not an image. Please use JPG, PNG, WebP or GIF.', 'ws-chapters' ), $file['name'] ) );
		}
		$files[] = $file;
	}
	if ( count( $files ) > WS_EVENT_MAX_IMAGES ) {
		/* translators: %d: number of images */
		return new WP_Error( 'ws_image', sprintf( __( 'Please send at most %d images.', 'ws-chapters' ), WS_EVENT_MAX_IMAGES ) );
	}
	return $files;
}

function ws_event_image_mimes() {
	return array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif' );
}

/**
 * Save the checked images as media of the event. The event's country decides the folder
 * (see upload-folders.php, which reads post_id). First image = poster (featured image), the rest = event photos.
 */
function ws_save_event_images( $files, $event_id ) {
	if ( ! $files ) {
		return array();
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$_REQUEST['post_id'] = $event_id; // Country folder for this event.
	$ids                 = array();
	foreach ( $files as $file ) {
		$id = media_handle_sideload( $file, $event_id, null, array( 'test_form' => false, 'mimes' => ws_event_image_mimes() ) );
		if ( ! is_wp_error( $id ) ) {
			$ids[] = (int) $id;
		}
	}
	unset( $_REQUEST['post_id'] );
	if ( $ids ) {
		set_post_thumbnail( $event_id, $ids[0] );
		if ( count( $ids ) > 1 ) {
			update_post_meta( $event_id, 'gallery', implode( ',', array_slice( $ids, 1 ) ) );
		}
	}
	return $ids;
}

function ws_event_submit_form() {
	// "Submit an event" on a chapter page links here with ?chapter=ID: that chapter and its country are preselected.
	$preset_chapter = isset( $_GET['chapter'] ) ? absint( $_GET['chapter'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$preset_country = 0;
	if ( $preset_chapter && 'ws_chapter' === get_post_type( $preset_chapter ) ) {
		$c              = ws_chapter_country( $preset_chapter );
		$preset_country = $c ? $c->term_id : 0;
	} else {
		$preset_chapter = 0;
		$country_id     = isset( $_GET['country'] ) ? absint( $_GET['country'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $country_id && term_exists( $country_id, 'ws_country' ) ) {
			$preset_country = $country_id; // "Submit an event" on a country page: country only.
		}
	}
	$result = null;
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'event' === ( $_POST['ws_form'] ?? '' ) ) {
		$result = ws_handle_event_submission();
	}
	if ( $result && $result['ok'] ) {
		return ws_form_notice( 'success', $result['message'] ); // Built from escaped parts (and a link for members).
	}
	ws_enqueue_form_script();
	$v = function ( $k ) use ( $result ) {
		return $result ? ws_post_field( $k, in_array( $k, array( 'ws_description', 'ws_contact' ), true ) ) : '';
	};
	$out  = $result ? ws_form_notice( 'error', $result['message'] ) : '';
	// Back to the page the person came from (or the events page if there is no earlier page).
	$out .= '<p class="ws-back"><a href="' . esc_url( get_post_type_archive_link( 'ws_event' ) ) . '" class="ws-link-arrow" data-ws-back>&larr; ' . esc_html__( 'Back', 'ws-chapters' ) . '</a></p>'
		. '<script>(function(){document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("[data-ws-back]");if(!a)return;if(document.referrer&&new URL(document.referrer).host===location.host&&history.length>1){e.preventDefault();history.back();}});})();</script>';
	$out .= '<form class="ws-form" method="post" action="#ws-event-form" id="ws-event-form" enctype="multipart/form-data" data-ws-chapter-filter novalidate>';
	$out .= ws_form_guard_fields( 'event' );
	$out .= '<fieldset><legend>' . esc_html__( 'The event', 'ws-chapters' ) . '</legend>';
	$out .= ws_field( 'ws_title', __( 'Event name', 'ws-chapters' ), 'text', array( 'required' => true, 'value' => $v( 'ws_title' ) ) );
	$out .= '<div class="ws-field-row">';
	$out .= ws_field( 'ws_start_date', __( 'Start date', 'ws-chapters' ), 'date', array( 'required' => true, 'value' => $v( 'ws_start_date' ) ) );
	$out .= ws_field( 'ws_end_date', __( 'End date', 'ws-chapters' ), 'date', array( 'value' => $v( 'ws_end_date' ), 'help' => __( 'If it runs over several days.', 'ws-chapters' ), 'attrs' => 'data-ws-msg="' . esc_attr__( 'The end date is before the start date.', 'ws-chapters' ) . '"' ) );
	$out .= ws_field( 'ws_start_time', __( 'Start time', 'ws-chapters' ), 'time', array( 'value' => $v( 'ws_start_time' ) ) );
	$out .= '</div><div class="ws-field-row">';
	$out .= ws_field( 'ws_form_country', __( 'Country', 'ws-chapters' ), 'select', array( 'required' => true, 'options' => ws_country_options( (int) ( $v( 'ws_form_country' ) ?: $preset_country ) ), 'attrs' => 'data-ws-country' ) );
	$out .= ws_field( 'ws_city', __( 'City', 'ws-chapters' ), 'text', array( 'required' => true, 'value' => $v( 'ws_city' ) ) );
	$out .= '</div>';
	$out .= ws_field( 'ws_venue', __( 'Venue or meeting point', 'ws-chapters' ), 'text', array( 'value' => $v( 'ws_venue' ), 'help' => __( 'Address, or a website for the venue.', 'ws-chapters' ) ) );
	$out .= '<div class="ws-field-row">';
	$out .= ws_field( 'ws_form_chapter', __( 'Organising chapter', 'ws-chapters' ), 'select', array( 'options' => ws_chapter_options( (int) ( $v( 'ws_form_chapter' ) ?: $preset_chapter ), __( '— Not a listed chapter —', 'ws-chapters' ) ), 'attrs' => 'data-ws-chapter' ) );
	$out .= ws_field( 'ws_organiser', __( 'Or organiser name', 'ws-chapters' ), 'text', array( 'value' => $v( 'ws_organiser' ), 'help' => __( 'Club, lodge or group, if not a chapter above.', 'ws-chapters' ) ) );
	$out .= '</div>';
	$out .= ws_field( 'ws_description', __( 'Description', 'ws-chapters' ), 'textarea', array( 'required' => true, 'value' => $v( 'ws_description' ), 'help' => __( 'Programme, route, costs, how to register.', 'ws-chapters' ) ) );
	$out .= ws_field( 'ws_contact', __( 'Contact details for riders', 'ws-chapters' ), 'textarea', array( 'required' => true, 'value' => $v( 'ws_contact' ), 'help' => __( 'Shown on the event page: who to contact, email and/or phone.', 'ws-chapters' ), 'attrs' => 'rows="3"' ) );
	$out .= '<p class="ws-field ws-field--file"><label for="ws_event_images">' . esc_html__( 'Poster and photos', 'ws-chapters' ) . '</label>'
		. '<input type="file" id="ws_event_images" name="ws_event_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-ws-max="' . WS_EVENT_MAX_IMAGES . '" data-ws-max-mb="' . WS_EVENT_MAX_IMAGE_MB . '" data-ws-main aria-describedby="ws_event_images-help">'
		/* translators: 1: number of images, 2: size in MB */
		. '<span class="ws-help" id="ws_event_images-help">' . esc_html( sprintf( __( 'Optional. Up to %1$d images (JPG, PNG, WebP or GIF), %2$d MB each. The first image is shown as the event poster.', 'ws-chapters' ), WS_EVENT_MAX_IMAGES, WS_EVENT_MAX_IMAGE_MB ) ) . '</span></p>';
	$out .= '</fieldset>';
	if ( is_user_logged_in() ) {
		// Signed-in members: no name or email to type, and the event is published straight away.
		/* translators: %s: the member's name */
		$out .= '<p class="ws-muted">' . esc_html( sprintf( __( 'Posting as %s. Your event is published as soon as you send it.', 'ws-chapters' ), ws_mm_person_name( wp_get_current_user() ) ) ) . '</p>';
		$out .= ws_consent_field( esc_html__( 'I acknowledge that this event and its contact details will be shared on the website.', 'ws-chapters' ) );
		$out .= '<p><button type="submit" class="ws-btn-gold">' . esc_html__( 'Publish the event', 'ws-chapters' ) . '</button></p></form>';
		return $out;
	}
	$out .= '<fieldset><legend>' . esc_html__( 'About you (not published)', 'ws-chapters' ) . '</legend><div class="ws-field-row">';
	$out .= ws_field( 'ws_name', __( 'Your name', 'ws-chapters' ), 'text', array( 'required' => true, 'value' => $v( 'ws_name' ), 'attrs' => 'autocomplete="name"' ) );
	$out .= ws_field( 'ws_email', __( 'Your email', 'ws-chapters' ), 'email', array( 'required' => true, 'value' => $v( 'ws_email' ), 'attrs' => 'autocomplete="email"', 'help' => __( 'In case the admins have questions.', 'ws-chapters' ) ) );
	$out .= '</div></fieldset>';
	/* translators: %s: link to the privacy policy */
	$out .= ws_consent_field( sprintf( __( 'I agree that the details above are stored to review this event, and that the event and its contact details are published if approved. See our %s.', 'ws-chapters' ), ws_privacy_link() ) );
	$out .= '<p><button type="submit" class="ws-btn-gold">' . esc_html__( 'Send for review', 'ws-chapters' ) . '</button></p></form>';
	return $out;
}

/* Admin: who submitted a pending event, and a menu bubble with the number waiting. */
add_action( 'add_meta_boxes_ws_event', function ( $post ) {
	if ( ! get_post_meta( $post->ID, '_ws_submitted_email', true ) ) {
		return;
	}
	add_meta_box( 'ws_event_submitter', __( 'Submitted via the website', 'ws-chapters' ), function ( $post ) {
		$name  = get_post_meta( $post->ID, '_ws_submitted_by', true );
		$email = get_post_meta( $post->ID, '_ws_submitted_email', true );
		echo '<p>' . esc_html( $name ) . '<br><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a></p>';
		if ( 'pending' === $post->post_status ) {
			echo '<p class="description">' . esc_html__( 'Check the details, then click Publish to approve. To reject it, move it to the Trash.', 'ws-chapters' ) . '</p>';
		}
	}, 'ws_event', 'side', 'high' );
} );

add_action( 'admin_menu', function () {
	global $menu;
	$n = (int) wp_count_posts( 'ws_event' )->pending;
	if ( ! $n ) {
		return;
	}
	foreach ( (array) $menu as $i => $item ) {
		if ( 'edit.php?post_type=ws_event' === ( $item[2] ?? '' ) ) {
			$menu[ $i ][0] .= ' <span class="awaiting-mod"><span class="pending-count">' . $n . '</span></span>';
		}
	}
}, 99 );

/** Link to the Join page, with a chapter (or only a country) pre-selected. '' if there is no Join page. */
function ws_join_url( $chapter_id = 0, $country_id = 0 ) {
	$page = get_page_by_path( 'join' );
	if ( ! $page || 'publish' !== $page->post_status ) {
		return '';
	}
	if ( $chapter_id ) {
		return add_query_arg( 'chapter', (int) $chapter_id, get_permalink( $page ) ) . '#ws-join-form';
	}
	return $country_id ? add_query_arg( 'country', (int) $country_id, get_permalink( $page ) ) . '#ws-join-form' : get_permalink( $page );
}

/* -------------------------------------------------------------------------
 * Membership application
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	// Applications are kept for the record; only administrators can see them.
	register_post_type( 'ws_application', array(
		'labels'          => array(
			'name'          => __( 'Applications', 'ws-chapters' ),
			'singular_name' => __( 'Application', 'ws-chapters' ),
			'all_items'     => __( 'Applications', 'ws-chapters' ),
			'edit_item'     => __( 'Application', 'ws-chapters' ),
			'search_items'  => __( 'Search applications', 'ws-chapters' ),
			'not_found'     => __( 'No applications yet.', 'ws-chapters' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => 'edit.php?post_type=ws_chapter',
		'supports'        => array( 'title' ),
		'capability_type' => 'post',
		'capabilities'    => array(
			'create_posts'       => 'do_not_allow',
			'edit_posts'         => 'manage_options',
			'edit_others_posts'  => 'manage_options',
			'delete_posts'       => 'manage_options',
			'delete_others_posts'=> 'manage_options',
			'read_private_posts' => 'manage_options',
			'edit_post'          => 'manage_options',
			'delete_post'        => 'manage_options',
			'read_post'          => 'manage_options',
		),
		'map_meta_cap'    => false,
	) );
} );

/** The fields of an application, in order: key => label. */
function ws_application_fields() {
	return array(
		'first_name' => __( 'First name', 'ws-chapters' ),
		'last_name'  => __( 'Last name', 'ws-chapters' ),
		'phone'      => __( 'Phone', 'ws-chapters' ),
		'email'      => __( 'Email', 'ws-chapters' ),
		'lodge'      => __( 'Lodge (name and number)', 'ws-chapters' ),
		'country'    => __( 'Country', 'ws-chapters' ),
		'chapter'    => __( 'Chapter', 'ws-chapters' ),
		'message'    => __( 'Message', 'ws-chapters' ),
	);
}

function ws_handle_application() {
	$guard = ws_form_guard_check( 'join' );
	if ( true !== $guard ) {
		return array( 'ok' => false, 'message' => $guard );
	}
	$f = array(
		'first_name' => ws_post_field( 'ws_first_name' ),
		'last_name'  => ws_post_field( 'ws_last_name' ),
		'phone'      => ws_post_field( 'ws_phone' ),
		'email'      => sanitize_email( ws_post_field( 'ws_email' ) ),
		'lodge'      => ws_post_field( 'ws_lodge' ),
		'country'    => (int) ws_post_field( 'ws_form_country' ),
		'chapter'    => (int) ws_post_field( 'ws_form_chapter' ),
		'message'    => ws_post_field( 'ws_message', true ),
	);
	$missing = array();
	foreach ( array( 'first_name', 'last_name', 'phone', 'lodge' ) as $k ) {
		if ( '' === $f[ $k ] ) {
			$missing[] = ws_application_fields()[ $k ];
		}
	}
	if ( ! is_email( $f['email'] ) ) {
		$missing[] = __( 'Email (a valid address)', 'ws-chapters' );
	}
	$term    = $f['country'] ? get_term( $f['country'], 'ws_country' ) : null;
	$chapter = $f['chapter'] ? get_post( $f['chapter'] ) : null;
	if ( ! $term || is_wp_error( $term ) ) {
		$missing[] = __( 'Country', 'ws-chapters' );
	}
	if ( ! $chapter || 'ws_chapter' !== $chapter->post_type || 'publish' !== $chapter->post_status ) {
		$missing[] = __( 'Chapter', 'ws-chapters' );
	} elseif ( $term && ! is_wp_error( $term ) && ! has_term( $term->term_id, 'ws_country', $chapter ) ) {
		$missing[] = __( 'a chapter in the chosen country', 'ws-chapters' );
	}
	if ( empty( $_POST['ws_consent'] ) ) {
		$missing[] = __( 'Agreement to the privacy policy', 'ws-chapters' );
	}
	if ( $missing ) {
		return array( 'ok' => false, 'message' => __( 'Please fill in:', 'ws-chapters' ) . ' ' . esc_html( implode( ', ', array_unique( $missing ) ) ) . '.' );
	}

	$chapter_name  = ws_chapter_name( $chapter );
	$city          = get_post_meta( $chapter->ID, 'city', true );
	$chapter_email = sanitize_email( get_post_meta( $chapter->ID, 'email', true ) );
	$full_name     = $f['first_name'] . ' ' . $f['last_name'];

	$lines = array(
		/* translators: %s: chapter name */
		sprintf( __( 'New application to join %s, sent from the Widows Sons Europe website.', 'ws-chapters' ), $chapter_name ),
		'',
		__( 'Name:', 'ws-chapters' ) . ' ' . $full_name,
		__( 'Phone:', 'ws-chapters' ) . ' ' . $f['phone'],
		__( 'Email:', 'ws-chapters' ) . ' ' . $f['email'],
		__( 'Lodge:', 'ws-chapters' ) . ' ' . $f['lodge'],
		__( 'Country:', 'ws-chapters' ) . ' ' . $term->name,
		__( 'Chapter:', 'ws-chapters' ) . ' ' . $chapter_name . ( $city ? ' — ' . $city : '' ),
	);
	if ( $f['message'] ) {
		$lines[] = '';
		$lines[] = __( 'Message:', 'ws-chapters' );
		$lines[] = $f['message'];
	}
	$lines[] = '';
	$lines[] = __( 'Reply to this email to answer the applicant directly.', 'ws-chapters' );

	// Keep a record first, so nothing is lost if an email fails.
	$id = wp_insert_post( array(
		'post_type'   => 'ws_application',
		'post_status' => 'private',
		/* translators: 1: applicant name, 2: chapter name */
		'post_title'  => sprintf( __( '%1$s → %2$s', 'ws-chapters' ), $full_name, $chapter_name ),
	) );
	if ( $id && ! is_wp_error( $id ) ) {
		foreach ( $f as $k => $v ) {
			update_post_meta( $id, '_ws_' . $k, $v );
		}
		wp_set_object_terms( $id, array( $term->term_id ), 'ws_country' );
	}

	/* translators: 1: applicant name, 2: chapter name */
	$subject   = sprintf( __( 'Application to join: %1$s → %2$s', 'ws-chapters' ), $full_name, $chapter_name );
	$to_chapter = $chapter_email ? ws_send_mail( $chapter_email, $subject, $lines, $f['email'] ) : false;
	$admin_lines = $lines;
	if ( ! $chapter_email ) {
		$admin_lines[] = '';
		$admin_lines[] = __( 'NOTE: this chapter has no contact email on the website yet, so it did not receive a copy. Please forward this application to the chapter.', 'ws-chapters' );
	} elseif ( ! $to_chapter ) {
		$admin_lines[] = '';
		/* translators: %s: chapter email */
		$admin_lines[] = sprintf( __( 'NOTE: sending the copy to the chapter (%s) failed. Please forward this application.', 'ws-chapters' ), $chapter_email );
	} else {
		/* translators: %s: chapter email */
		$admin_lines[] = sprintf( __( 'A copy was sent to the chapter at %s.', 'ws-chapters' ), $chapter_email );
	}
	ws_send_mail( get_option( 'admin_email' ), $subject, $admin_lines, $f['email'] );
	if ( $id && ! is_wp_error( $id ) ) {
		update_post_meta( $id, '_ws_sent_to', $chapter_email && $to_chapter ? $chapter_email : '' );
	}

	return array(
		'ok'      => true,
		/* translators: %s: chapter name */
		'message' => sprintf( __( 'Thank you, Brother. Your application has been sent to %s. The chapter will contact you.', 'ws-chapters' ), $chapter_name ),
		'chapter' => $chapter_name,
	);
}

/** Thank-you panel shown after an application is sent, with ways to carry on browsing. */
function ws_join_thank_you( $chapter_name ) {
	$links = array(
		array( get_post_type_archive_link( 'ws_chapter' ), __( 'Explore the chapters', 'ws-chapters' ), 'ws-btn-gold' ),
		array( get_post_type_archive_link( 'ws_event' ), __( 'See upcoming events', 'ws-chapters' ), 'ws-btn-outline' ),
		array( home_url( '/' ), __( 'Back to the home page', 'ws-chapters' ), 'ws-btn-outline' ),
	);
	$buttons = '';
	foreach ( $links as $l ) {
		if ( $l[0] ) {
			$buttons .= '<a class="' . esc_attr( $l[2] ) . '" href="' . esc_url( $l[0] ) . '">' . esc_html( $l[1] ) . '</a>';
		}
	}
	return '<section class="ws-thank-you" id="ws-join-form" role="status" tabindex="-1">'
		. '<p class="ws-thank-you__kicker">' . esc_html__( 'Application sent', 'ws-chapters' ) . '</p>'
		. '<h2 class="ws-thank-you__title">' . esc_html__( 'Thank you, Brother', 'ws-chapters' ) . '</h2>'
		/* translators: %s: chapter name */
		. '<p>' . esc_html( sprintf( __( 'Your application has been sent to %s. The chapter will get in touch with you soon by email or phone.', 'ws-chapters' ), $chapter_name ) ) . '</p>'
		. '<p>' . esc_html__( 'A copy has also gone to the Widows Sons Europe team. While you wait, take a look around:', 'ws-chapters' ) . '</p>'
		. '<div class="ws-thank-you__actions">' . $buttons . '</div>'
		. '</section>';
}

function ws_join_form() {
	$result = null;
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && 'join' === ( $_POST['ws_form'] ?? '' ) ) {
		$result = ws_handle_application();
	}
	if ( $result && $result['ok'] ) {
		return ws_join_thank_you( $result['chapter'] );
	}
	ws_enqueue_form_script();
	$v = function ( $k ) use ( $result ) {
		return $result ? ws_post_field( $k, 'ws_message' === $k ) : '';
	};
	$preset_chapter = isset( $_GET['chapter'] ) ? absint( $_GET['chapter'] ) : 0; // "Apply to join this chapter" links.
	$preset_country = isset( $_GET['country'] ) ? absint( $_GET['country'] ) : 0; // "Apply to join" on a country page: the applicant still picks the chapter.
	if ( $preset_country && ! term_exists( $preset_country, 'ws_country' ) ) {
		$preset_country = 0;
	}
	if ( $preset_chapter && 'ws_chapter' === get_post_type( $preset_chapter ) ) {
		$c              = ws_chapter_country( $preset_chapter );
		$preset_country = $c ? $c->term_id : $preset_country;
	}
	$out  = $result ? ws_form_notice( 'error', $result['message'] ) : '';
	$out .= '<form class="ws-form" method="post" action="#ws-join-form" id="ws-join-form" data-ws-chapter-filter data-ws-require-country novalidate>';
	$out .= ws_form_guard_fields( 'join' );
	$out .= '<fieldset><legend>' . esc_html__( 'About you', 'ws-chapters' ) . '</legend><div class="ws-field-row">';
	$out .= ws_field( 'ws_first_name', __( 'First name', 'ws-chapters' ), 'text', array( 'required' => true, 'value' => $v( 'ws_first_name' ), 'attrs' => 'autocomplete="given-name"' ) );
	$out .= ws_field( 'ws_last_name', __( 'Last name', 'ws-chapters' ), 'text', array( 'required' => true, 'value' => $v( 'ws_last_name' ), 'attrs' => 'autocomplete="family-name"' ) );
	$out .= '</div><div class="ws-field-row">';
	$out .= ws_field( 'ws_phone', __( 'Phone', 'ws-chapters' ), 'tel', array( 'required' => true, 'value' => $v( 'ws_phone' ), 'attrs' => 'autocomplete="tel"' ) );
	$out .= ws_field( 'ws_email', __( 'Email', 'ws-chapters' ), 'email', array( 'required' => true, 'value' => $v( 'ws_email' ), 'attrs' => 'autocomplete="email"' ) );
	$out .= '</div>';
	$out .= ws_field( 'ws_lodge', __( 'Your lodge', 'ws-chapters' ), 'text', array( 'required' => true, 'value' => $v( 'ws_lodge' ), 'help' => __( 'Lodge name and number, and its Grand Lodge.', 'ws-chapters' ) ) );
	$out .= '</fieldset><fieldset><legend>' . esc_html__( 'The chapter you would like to join', 'ws-chapters' ) . '</legend><div class="ws-field-row">';
	$out .= ws_field( 'ws_form_country', __( 'Country', 'ws-chapters' ), 'select', array( 'required' => true, 'options' => ws_country_options( (int) ( $v( 'ws_form_country' ) ?: $preset_country ), true ), 'attrs' => 'data-ws-country' ) );
	$out .= ws_field( 'ws_form_chapter', __( 'Chapter', 'ws-chapters' ), 'select', array( 'required' => true, 'options' => ws_chapter_options( (int) ( $v( 'ws_form_chapter' ) ?: $preset_chapter ) ), 'attrs' => 'data-ws-chapter', 'help' => __( 'Choose the chapter closest to you. Your application goes straight to that chapter.', 'ws-chapters' ) ) );
	$out .= '</div>';
	$out .= ws_field( 'ws_message', __( 'Anything you would like to tell the chapter', 'ws-chapters' ), 'textarea', array( 'value' => $v( 'ws_message' ), 'attrs' => 'rows="4"' ) );
	$out .= '</fieldset>';
	/* translators: %s: link to the privacy policy */
	$out .= ws_consent_field( sprintf( __( 'I am a Mason in good standing with my lodge and agree that my details are sent to the chosen chapter and the Widows Sons Europe admins to handle my application. See our %s.', 'ws-chapters' ), ws_privacy_link() ) );
	$out .= '<p><button type="submit" class="ws-btn-gold">' . esc_html__( 'Send application', 'ws-chapters' ) . '</button></p></form>';
	return $out;
}

/* Admin: show an application's details. */
add_action( 'add_meta_boxes_ws_application', function () {
	add_meta_box( 'ws_application_details', __( 'Application', 'ws-chapters' ), function ( $post ) {
		echo '<table class="widefat striped"><tbody>';
		foreach ( ws_application_fields() as $k => $label ) {
			$val = get_post_meta( $post->ID, '_ws_' . $k, true );
			if ( 'country' === $k && $val ) {
				$t   = get_term( (int) $val, 'ws_country' );
				$val = $t && ! is_wp_error( $t ) ? $t->name : $val;
			} elseif ( 'chapter' === $k && $val ) {
				$val = ws_chapter_name( (int) $val );
			}
			$html = esc_html( $val );
			if ( 'email' === $k && $val ) {
				$html = '<a href="mailto:' . esc_attr( $val ) . '">' . esc_html( $val ) . '</a>';
			} elseif ( 'phone' === $k && $val ) {
				$html = '<a href="tel:' . esc_attr( preg_replace( '/\s+/', '', $val ) ) . '">' . esc_html( $val ) . '</a>';
			}
			echo '<tr><th style="width:12rem">' . esc_html( $label ) . '</th><td>' . nl2br( $html ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		$sent = get_post_meta( $post->ID, '_ws_sent_to', true );
		echo '<tr><th>' . esc_html__( 'Sent to chapter', 'ws-chapters' ) . '</th><td>' . ( $sent ? esc_html( $sent ) : esc_html__( 'No — the chapter has no email on the website (or sending failed). Forward it manually.', 'ws-chapters' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Received', 'ws-chapters' ) . '</th><td>' . esc_html( get_the_date( 'j F Y, H:i', $post ) ) . '</td></tr>';
		echo '</tbody></table>';
	}, 'ws_application', 'normal', 'high' );
	remove_meta_box( 'submitdiv', 'ws_application', 'side' );
} );

add_action( 'init', function () {
	add_shortcode( 'ws_event_submit', 'ws_event_submit_form' );
	add_shortcode( 'ws_join_form', 'ws_join_form' );
} );
