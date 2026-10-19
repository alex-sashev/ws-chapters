<?php
/**
 * Editing from the site itself: small pencil buttons on chapter and country pages.
 *
 * Signed-in people who manage a chapter or country see a pencil on its images, contact box and gallery.
 * Each pencil opens a small form; the form posts back to the same page and is saved here, after the same
 * permission check that the admin screens use (ws_front_can_edit). Visitors and members see no pencils.
 *
 * The About page is an ordinary WordPress page (its text is in the page itself), edited in the admin.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Who may edit
 * ---------------------------------------------------------------------- */

/**
 * Chapter ($type 'chapter', ID = post ID) or country ($type 'term', ID = term ID).
 * Super Admins: everything. Country Admins: their country and its chapters. Chapter Editors: their chapter.
 */
function ws_front_can_edit( $type, $id ) {
	$id = (int) $id;
	if ( ! $id || ! is_user_logged_in() ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) || current_user_can( WS_ASSET_CAP ) ) {
		return true;
	}
	if ( 'term' === $type ) {
		return ws_can_set_insignia( 'term', $id ); // Country Admins for their own country only.
	}
	$post = get_post( $id );
	if ( ! $post || 'ws_chapter' !== $post->post_type ) {
		return false;
	}
	$scope = ws_user_scope( get_current_user_id() );
	return (bool) $scope && ws_scope_allows( $scope, $post );
}

/** Image slots that can be changed from the site: slot => label. */
function ws_fe_image_slots( $type ) {
	if ( 'term' === $type ) {
		return array(
			'hero'          => __( 'Header photo', 'ws-chapters' ),
			'national_logo' => __( 'National logo', 'ws-chapters' ),
			'gallery'       => __( 'Add photos', 'ws-chapters' ),
		);
	}
	return array(
		'back_patch'   => __( 'Official chapter patch', 'ws-chapters' ),
		'chapter_logo' => __( 'Official chapter logo', 'ws-chapters' ),
		'hero'         => __( 'Header photo', 'ws-chapters' ),
		'gallery'      => __( 'Add photos', 'ws-chapters' ),
	);
}

/* -------------------------------------------------------------------------
 * Pencil buttons and forms
 * ---------------------------------------------------------------------- */

function ws_fe_pencil() {
	return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';
}

/**
 * A pencil (or a text button when $button) that opens $inner in a small form. Empty string when the
 * signed-in person may not edit this chapter or country.
 */
function ws_fe_box( $type, $id, $action, $inner, $label, $button = false ) {
	if ( ! ws_front_can_edit( $type, $id ) ) {
		return '';
	}
	$id     = (int) $id;
	// Back to this page after saving: the path relative to the site's home (the handler adds it back).
	$return = wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
	$base   = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $base && 0 === strpos( $return, $base . '/' ) ) {
		$return = substr( $return, strlen( $base ) );
	}
	$hidden = '<input type="hidden" name="ws_fe_action" value="' . esc_attr( $action ) . '">'
		. '<input type="hidden" name="ws_fe_type" value="' . esc_attr( $type ) . '">'
		. '<input type="hidden" name="ws_fe_id" value="' . $id . '">'
		. '<input type="hidden" name="ws_fe_nonce" value="' . esc_attr( wp_create_nonce( 'ws_fe_' . $type . '_' . $id ) ) . '">'
		. '<input type="hidden" name="ws_fe_return" value="' . esc_attr( $return ) . '">';
	// Uploads take their country folder from these (see upload-folders.php).
	if ( 'term' === $type ) {
		$hidden .= '<input type="hidden" name="ws_country" value="' . $id . '">';
	} else {
		$hidden .= '<input type="hidden" name="post_id" value="' . $id . '">';
	}
	return '<details class="ws-fedit' . ( $button ? ' ws-fedit--button' : '' ) . '">'
		. '<summary class="ws-fedit__btn" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '">' . ( $button ? esc_html( $label ) : ws_fe_pencil() ) . '</summary>'
		. '<form method="post" enctype="multipart/form-data" class="ws-fedit__form"><button type="button" class="ws-fedit__close" aria-label="' . esc_attr__( 'Close', 'ws-chapters' ) . '" onclick="this.closest(\'details\').removeAttribute(\'open\')">&times;</button><p class="ws-fedit__title">' . esc_html( $label ) . '</p>'
		. $hidden . $inner
		. '<p><button type="submit" class="ws-btn-gold">' . esc_html( 'image' === $action ? __( 'Upload', 'ws-chapters' ) : __( 'Save', 'ws-chapters' ) ) . '</button></p></form></details>';
}

/** Pencil for one image slot (patch, logo, header photo) placed over the image. */
function ws_fe_image_box( $type, $id, $slot ) {
	$labels = ws_fe_image_slots( $type );
	if ( ! isset( $labels[ $slot ] ) ) {
		return '';
	}
	$inner = '<input type="hidden" name="ws_fe_slot" value="' . esc_attr( $slot ) . '">'
		. '<label class="ws-fedit__label">' . esc_html__( 'Choose an image (JPG, PNG, WebP or GIF, up to 8 MB)', 'ws-chapters' )
		. '<input type="file" name="ws_fe_file[]" accept="image/jpeg,image/png,image/webp,image/gif"></label>';
	return ws_fe_box( $type, $id, 'image', $inner, $labels[ $slot ] );
}

/** Gallery: an Upload button above the photos (several photos at once). */
function ws_fe_gallery_panel( $type, $id, $ids ) {
	$inner = '<input type="hidden" name="ws_fe_slot" value="gallery">'
		. '<label class="ws-fedit__label">' . esc_html__( 'Choose photos (several at once is fine)', 'ws-chapters' )
		. '<input type="file" name="ws_fe_file[]" multiple data-ws-max-mb="8" accept="image/jpeg,image/png,image/webp,image/gif"></label>';
	return '<div class="ws-fe-toolbar">' . ws_fe_box( $type, $id, 'image', $inner, __( 'Upload photos', 'ws-chapters' ), true ) . '</div>' . ws_gallery_grid( $ids, array( $type, $id ) );
}

/** A small × on a gallery photo that removes it from this gallery (the photo file stays in the Media Library). */
function ws_fe_gallery_remove_form( $type, $id, $photo ) {
	if ( ! ws_front_can_edit( $type, $id ) ) {
		return '';
	}
	$id     = (int) $id;
	$return = wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
	$base   = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $base && 0 === strpos( $return, $base . '/' ) ) {
		$return = substr( $return, strlen( $base ) );
	}
	return '<form method="post" class="ws-fe-remove" onsubmit="return confirm(\'' . esc_js( __( 'Remove this photo from the gallery?', 'ws-chapters' ) ) . '\')">'
		. '<input type="hidden" name="ws_fe_action" value="image">'
		. '<input type="hidden" name="ws_fe_type" value="' . esc_attr( $type ) . '">'
		. '<input type="hidden" name="ws_fe_id" value="' . $id . '">'
		. '<input type="hidden" name="ws_fe_nonce" value="' . esc_attr( wp_create_nonce( 'ws_fe_' . $type . '_' . $id ) ) . '">'
		. '<input type="hidden" name="ws_fe_return" value="' . esc_attr( $return ) . '">'
		. '<input type="hidden" name="ws_fe_slot" value="gallery_remove">'
		. '<input type="hidden" name="ws_fe_remove" value="' . (int) $photo . '">'
		. '<button type="submit" class="ws-fe-remove__btn" aria-label="' . esc_attr__( 'Remove photo from gallery', 'ws-chapters' ) . '" title="' . esc_attr__( 'Remove from gallery', 'ws-chapters' ) . '">&times;</button></form>';
}

/** Contact details box: the fields of ws_contact_fields() with their current values. */
function ws_fe_contact_box( $type, $id, $get ) {
	$inner = '<div class="ws-fedit__grid">';
	foreach ( ws_contact_fields() as $key => $field ) {
		$inner .= '<label class="ws-fedit__label">' . esc_html( $field[0] )
			. '<input type="' . esc_attr( $field[1] ) . '" name="ws_c_' . esc_attr( $key ) . '" value="' . esc_attr( (string) $get( $key ) ) . '"></label>';
	}
	$inner .= '</div>';
	return ws_fe_box( $type, $id, 'contact', $inner, __( 'Edit contact details', 'ws-chapters' ) );
}

/* -------------------------------------------------------------------------
 * Saving
 * ---------------------------------------------------------------------- */

/** Upload the chosen images as media (country folder by the upload-folders rules). Returns IDs or WP_Error. */
function ws_fe_store_images( $field, $parent ) {
	if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ]['name'] ?? null ) ) {
		return new WP_Error( 'ws_fe', __( 'Please choose an image.', 'ws-chapters' ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$raw = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the handler.
	$ids = array();
	foreach ( array_keys( $raw['name'] ) as $i ) {
		if ( UPLOAD_ERR_NO_FILE === (int) $raw['error'][ $i ] ) {
			continue;
		}
		$file = array(
			'name'     => sanitize_file_name( $raw['name'][ $i ] ),
			'type'     => $raw['type'][ $i ],
			'tmp_name' => $raw['tmp_name'][ $i ],
			'error'    => (int) $raw['error'][ $i ],
			'size'     => (int) $raw['size'][ $i ],
		);
		if ( UPLOAD_ERR_OK !== $file['error'] || $file['size'] > 8 * MB_IN_BYTES ) {
			return new WP_Error( 'ws_fe', sprintf( __( 'The image "%s" is too large or could not be uploaded (8 MB each at most).', 'ws-chapters' ), $file['name'] ) );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], ws_event_image_mimes() );
		if ( empty( $check['type'] ) ) {
			return new WP_Error( 'ws_fe', sprintf( __( '"%s" is not an image. Please use JPG, PNG, WebP or GIF.', 'ws-chapters' ), $file['name'] ) );
		}
		$id = media_handle_sideload( $file, $parent, null, array( 'test_form' => false, 'mimes' => ws_event_image_mimes() ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$ids[] = (int) $id;
	}
	return $ids ? $ids : new WP_Error( 'ws_fe', __( 'Please choose an image.', 'ws-chapters' ) );
}

/** Save one submitted form after its nonce and permission checks. Returns a message for the notice. */
function ws_fe_save( $type, $id, $action ) {
	if ( 'contact' === $action ) {
		foreach ( array_keys( ws_contact_fields() ) as $key ) {
			if ( isset( $_POST[ 'ws_c_' . $key ] ) ) {
				$value = ws_sanitize_contact( $key, wp_unslash( $_POST[ 'ws_c_' . $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				'term' === $type ? update_term_meta( $id, $key, $value ) : update_post_meta( $id, $key, $value );
			}
		}
		return __( 'Contact details saved.', 'ws-chapters' );
	}

	if ( 'rename' === $action ) {
		$name = trim( sanitize_text_field( wp_unslash( $_POST['ws_chapter_name'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'chapter' !== $type || 'ws_chapter' !== get_post_type( $id ) ) {
			return new WP_Error( 'ws_fe', __( 'That name cannot be changed here.', 'ws-chapters' ) );
		}
		if ( '' === $name || mb_strlen( $name ) > 80 ) {
			return new WP_Error( 'ws_fe', __( 'Please enter a chapter name (up to 80 characters).', 'ws-chapters' ) );
		}
		// The address follows the name; WordPress redirects the old address to the new one.
		$saved = wp_update_post( array( 'ID' => $id, 'post_title' => $name, 'post_name' => sanitize_title( $name ) ), true );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$GLOBALS['ws_fe_return_override'] = get_permalink( $id );
		/* translators: %s: chapter name */
		return sprintf( __( 'The chapter is now called %s.', 'ws-chapters' ), $name );
	}

	if ( 'trash_chapter' === $action ) {
		$chapter = absint( $_POST['ws_chapter'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$super   = current_user_can( 'manage_options' ) || current_user_can( WS_ASSET_CAP );
		if ( 'chapter' === $type && $super && $chapter === $id ) {
			wp_trash_post( $chapter ); // A chapter without a country: Super Admins only.
			return __( 'The chapter is closed. It can be restored from the Trash in the WordPress admin.', 'ws-chapters' );
		}
		if ( 'term' !== $type || ! ws_can_manage_country_chapters( $id ) || ! ws_chapter_in_country( $chapter, $id ) ) {
			return new WP_Error( 'ws_fe', __( 'You can only remove chapters of your own country.', 'ws-chapters' ) );
		}
		wp_trash_post( $chapter ); // In the Trash: it can be restored from the admin.
		return __( 'The chapter is closed. It can be restored from the Trash in the WordPress admin.', 'ws-chapters' );
	}

	if ( 'about' === $action ) {
		$text = wp_kses_post( wp_unslash( $_POST['ws_about'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'term' === $type ) {
			$saved = wp_update_term( $id, 'ws_country', array( 'description' => $text ) );
			return is_wp_error( $saved ) ? $saved : __( 'About text saved.', 'ws-chapters' );
		}
		if ( 'ws_chapter' !== get_post_type( $id ) ) {
			return new WP_Error( 'ws_fe', __( 'That text cannot be changed here.', 'ws-chapters' ) );
		}
		wp_update_post( array( 'ID' => $id, 'post_content' => $text ) );
		return __( 'About text saved.', 'ws-chapters' );
	}

	// Image.
	$slot = sanitize_key( wp_unslash( $_POST['ws_fe_slot'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'gallery_remove' === $slot ) {
		$photo = absint( $_POST['ws_fe_remove'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$key   = 'term' === $type ? get_term_meta( $id, 'gallery', true ) : get_post_meta( $id, 'gallery', true );
		$left  = array_values( array_diff( ws_gallery_ids( $key ), array( $photo ) ) );
		$value = ws_sanitize_gallery( implode( ',', $left ) );
		'term' === $type ? update_term_meta( $id, 'gallery', $value ) : update_post_meta( $id, 'gallery', $value );
		return __( 'Photo removed from the gallery.', 'ws-chapters' );
	}
	if ( ! isset( ws_fe_image_slots( $type )[ $slot ] ) ) {
		return new WP_Error( 'ws_fe', __( 'That image cannot be changed here.', 'ws-chapters' ) );
	}
	$ids = ws_fe_store_images( 'ws_fe_file', 'chapter' === $type ? $id : 0 );
	if ( is_wp_error( $ids ) ) {
		return $ids;
	}
	if ( 'gallery' === $slot ) {
		$key = 'term' === $type ? get_term_meta( $id, 'gallery', true ) : get_post_meta( $id, 'gallery', true );
		$all = ws_sanitize_gallery( implode( ',', array_merge( ws_gallery_ids( $key ), $ids ) ) );
		'term' === $type ? update_term_meta( $id, 'gallery', $all ) : update_post_meta( $id, 'gallery', $all );
	} elseif ( 'term' === $type ) {
		update_term_meta( $id, 'hero' === $slot ? 'hero_image' : 'national_logo', $ids[0] );
	} elseif ( 'hero' === $slot ) {
		set_post_thumbnail( $id, $ids[0] );
	} else {
		update_post_meta( $id, $slot, $ids[0] ); // back_patch or chapter_logo.
	}
	return __( 'Image saved.', 'ws-chapters' );
}

/** Handle a pencil form when it is posted to any page. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['ws_fe_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$type   = sanitize_key( wp_unslash( $_POST['ws_fe_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$id     = absint( $_POST['ws_fe_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$action = sanitize_key( wp_unslash( $_POST['ws_fe_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$nonce  = sanitize_text_field( wp_unslash( $_POST['ws_fe_nonce'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! in_array( $type, array( 'chapter', 'term' ), true ) || ! $id || ! wp_verify_nonce( $nonce, 'ws_fe_' . $type . '_' . $id ) ) {
		wp_die( esc_html__( 'This form has expired. Please reload the page and try again.', 'ws-chapters' ), 403 );
	}
	if ( ! ws_front_can_edit( $type, $id ) ) {
		wp_die( esc_html__( 'You do not have permission to change this.', 'ws-chapters' ), 403 );
	}
	if ( 'term' === $type && ! get_term( $id, 'ws_country' ) ) {
		wp_die( esc_html__( 'Country not found.', 'ws-chapters' ), 404 );
	}
	if ( 'chapter' === $type && 'ws_chapter' !== get_post_type( $id ) ) {
		wp_die( esc_html__( 'Chapter not found.', 'ws-chapters' ), 404 );
	}

	$result = ws_fe_save( $type, $id, $action );
	$ok     = ! is_wp_error( $result );
	set_transient( 'ws_fe_msg_' . get_current_user_id(), array( 'ok' => $ok, 'text' => $ok ? $result : $result->get_error_message() ), MINUTE_IN_SECONDS );

	$return = wp_unslash( (string) ( $_POST['ws_fe_return'] ?? '/' ) );
	if ( '' === $return || '/' !== $return[0] || '/' === ( $return[1] ?? '' ) ) {
		$return = '/'; // Only back to a path on this site.
	}
	$url = home_url( $return );
	// A chapter renamed on its own page has a new address: go there (from the country page, stay on it).
	if ( ! empty( $GLOBALS['ws_fe_return_override'] ) && false !== strpos( $return, '/chapters/' ) ) {
		$url = $GLOBALS['ws_fe_return_override'];
	}
	wp_safe_redirect( add_query_arg( 'ws_fe', $ok ? 'saved' : 'error', remove_query_arg( 'ws_fe', $url ) ) );
	exit;
}, 1 );

/* While a save is running, the button says so and cannot be pressed twice (people were clicking again when nothing seemed to happen). */
add_action( 'wp_footer', function () {
	if ( ! is_user_logged_in() ) {
		return;
	}
	echo '<script>(function(){document.addEventListener("submit",function(e){if(e.defaultPrevented){return;}var f=e.target;if(!f.classList||!f.classList.contains("ws-fedit__form")&&!f.classList.contains("ws-fe-remove")&&!f.classList.contains("ws-fe-trash--news")&&!f.closest(".ws-fe-news")){return;}if(f.getAttribute("data-busy")){e.preventDefault();return;}f.setAttribute("data-busy","1");var b=f.querySelector("button[type=submit]");if(b){var isRemove=f.classList.contains("ws-fe-remove"),isNews=f.classList.contains("ws-fe-trash--news");b.disabled=true;b.textContent=isNews?"Deleting…":(isRemove?"…":"Please wait…");}f.classList.add("is-busy");});})();</script>';
} );

/* A page showing a saved message must not be kept in the page cache, or the message comes back on refresh.
   Headers have to be set before the page is output, so this runs at the start of the request. */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['ws_fe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	nocache_headers();
	do_action( 'litespeed_control_set_nocache', 'saved message' ); // LiteSpeed: do not cache this page.
}, 0 );

/** A short notice after saving or failing (one-off, from the transient above). */
add_action( 'wp_footer', function () {
	if ( empty( $_GET['ws_fe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$msg = get_transient( 'ws_fe_msg_' . get_current_user_id() );
	delete_transient( 'ws_fe_msg_' . get_current_user_id() );
	if ( ! $msg ) {
		return;
	}
	echo '<div class="ws-fe-notice ' . ( $msg['ok'] ? 'ws-fe-notice--ok' : 'ws-fe-notice--error' ) . '" role="status">' . esc_html( $msg['text'] ) . '</div>'
		// It goes away by itself after a few seconds, or on click, so it never sticks to the page.
		. '<script>(function(){var n=document.querySelector(".ws-fe-notice");if(!n)return;var h=function(){n.remove();};n.addEventListener("click",h);setTimeout(h,4000);})();</script>';
} );

/* -------------------------------------------------------------------------
 * Chapter page: About text, Submit an event, Submit news or activity
 * ---------------------------------------------------------------------- */

/** Plain text of the chapter's About page text, for the edit box (block markup removed). */
function ws_fe_about_text( $id ) {
	$raw = (string) get_post_field( 'post_content', $id );
	$raw = preg_replace( '/<!--.*?-->/s', '', $raw );
	return trim( html_entity_decode( wp_strip_all_tags( $raw ), ENT_QUOTES, 'UTF-8' ) );
}

/** Edit box for the About text; empty for people who may not edit the chapter. */
function ws_fe_about_box( $id ) {
	$inner = '<label class="ws-fedit__label">' . esc_html__( 'About the chapter (leave an empty line between paragraphs)', 'ws-chapters' )
		. '<textarea name="ws_about" rows="12">' . esc_textarea( ws_fe_about_text( $id ) ) . '</textarea></label>';
	return '<div class="ws-fe-toolbar">' . ws_fe_box( 'chapter', $id, 'about', $inner, __( 'Edit the About text', 'ws-chapters' ), true ) . '</div>';
}

/** "Submit an event": opens the public event form, with this chapter (or, on a country page, this country) preselected. */
function ws_fe_submit_event_button( $chapter_id, $country_id = 0 ) {
	$page = get_page_by_path( 'submit-event' );
	$url  = $page ? get_permalink( $page ) : home_url( '/submit-event/' );
	$url  = $chapter_id ? add_query_arg( 'chapter', (int) $chapter_id, $url ) : add_query_arg( 'country', (int) $country_id, $url );
	return '<p class="ws-fe-submit"><a class="ws-btn-gold" href="' . esc_url( $url . '#ws-event-form' ) . '">' . esc_html__( 'Submit an event', 'ws-chapters' ) . '</a></p>';
}

/** Country About text: the Overview shows the country's term description; editable here. */
function ws_fe_country_about_box( $term_id ) {
	$term = get_term( (int) $term_id, 'ws_country' );
	if ( ! $term || is_wp_error( $term ) ) {
		return '';
	}
	$text  = trim( html_entity_decode( wp_strip_all_tags( (string) $term->description ), ENT_QUOTES, 'UTF-8' ) );
	$inner = '<label class="ws-fedit__label">' . esc_html__( 'About the country (leave an empty line between paragraphs)', 'ws-chapters' )
		. '<textarea name="ws_about" rows="12">' . esc_textarea( $text ) . '</textarea></label>';
	return '<div class="ws-fe-toolbar">' . ws_fe_box( 'term', $term->term_id, 'about', $inner, __( 'Edit the About text', 'ws-chapters' ), true ) . '</div>';
}

/** "Submit news" on a country page: a news form for this country (no chapter, so posted by the country). */
function ws_fe_country_news_form( $term_id ) {
	if ( ! is_user_logged_in() ) {
		return ''; // Visitors can read news but cannot submit it: sign in first.
	}
	$logged = true;
	$inner  = ws_form_guard_fields( 'country_news' )
		. '<input type="hidden" name="ws_country" value="' . (int) $term_id . '">'
		. '<label class="ws-fedit__label">' . esc_html__( 'Title', 'ws-chapters' ) . '<input type="text" name="ws_news_title" required></label>'
		. '<label class="ws-fedit__label">' . esc_html__( 'The news', 'ws-chapters' ) . '<textarea name="ws_news_body" rows="6" required></textarea></label>'
		. '<label class="ws-fedit__label">' . esc_html__( 'Photos (up to 5, JPG, PNG, WebP or GIF, 8 MB each)', 'ws-chapters' ) . '<input type="file" name="ws_news_images[]" multiple data-ws-max="5" data-ws-max-mb="8" data-ws-main accept="image/jpeg,image/png,image/webp,image/gif"></label>';
	$inner .= '<label class="ws-fedit__label"><input type="checkbox" name="ws_consent" value="1" required> ' . esc_html__( 'I acknowledge that this will be shared on the website.', 'ws-chapters' ) . '</label>';
	return ws_fe_form_box( $inner, __( 'Submit news', 'ws-chapters' ), 'Published straight away.' );
}

/** "Submit news or activity" form on the chapter's News tab. Posts to the same page. */
function ws_fe_news_form( $chapter_id ) {
	if ( ! is_user_logged_in() ) {
		return ''; // Visitors can read news but cannot submit it: sign in first.
	}
	$logged = true;
	$inner  = ws_form_guard_fields( 'chapter_news' )
		. '<input type="hidden" name="ws_chapter" value="' . (int) $chapter_id . '">'
		. '<label class="ws-fedit__label">' . esc_html__( 'Title', 'ws-chapters' ) . '<input type="text" name="ws_news_title" required></label>'
		. '<label class="ws-fedit__label">' . esc_html__( 'What happened or what is coming up', 'ws-chapters' ) . '<textarea name="ws_news_body" rows="6" required></textarea></label>'
		. '<label class="ws-fedit__label">' . esc_html__( 'Photos (up to 5, JPG, PNG, WebP or GIF, 8 MB each)', 'ws-chapters' ) . '<input type="file" name="ws_news_images[]" multiple data-ws-max="5" data-ws-max-mb="8" data-ws-main accept="image/jpeg,image/png,image/webp,image/gif"></label>';
	$inner .= '<label class="ws-fedit__label"><input type="checkbox" name="ws_consent" value="1" required> ' . esc_html__( 'I acknowledge that this will be shared on the website.', 'ws-chapters' ) . '</label>';
	return ws_fe_form_box( $inner, __( 'Submit news or activity', 'ws-chapters' ), 'Published straight away.' );
}

/* -------------------------------------------------------------------------
 * "Submit news or activity" on the News page: the poster chooses Widows Sons Europe, a country or a chapter.
 * Signed-in users only; published straight away.
 * ---------------------------------------------------------------------- */

/** The News page button and form (signed-in users only). */
function ws_fe_site_news_form() {
	if ( ! is_user_logged_in() ) {
		return '';
	}
	$options = '<option value="0">' . esc_html__( 'Widows Sons Europe', 'ws-chapters' ) . '</option>';
	$options .= '<optgroup label="' . esc_attr__( 'Countries', 'ws-chapters' ) . '">';
	foreach ( get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false, 'orderby' => 'name' ) ) as $t ) {
		$options .= '<option value="c' . (int) $t->term_id . '">' . esc_html( ws_display_name( $t->name ) ) . '</option>';
	}
	$options .= '</optgroup><optgroup label="' . esc_attr__( 'Chapters', 'ws-chapters' ) . '">';
	foreach ( get_posts( array( 'post_type' => 'ws_chapter', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) ) as $c ) {
		$options .= '<option value="h' . (int) $c->ID . '">' . esc_html( ws_chapter_name( $c ) ) . '</option>';
	}
	$options .= '</optgroup>';
	$inner = ws_form_guard_fields( 'site_news' )
		. '<label class="ws-fedit__label">' . esc_html__( 'Posted by', 'ws-chapters' ) . '<select name="ws_news_scope" required>' . $options . '</select></label>'
		. '<label class="ws-fedit__label">' . esc_html__( 'Title', 'ws-chapters' ) . '<input type="text" name="ws_news_title" required></label>'
		. '<label class="ws-fedit__label">' . esc_html__( 'The news or activity', 'ws-chapters' ) . '<textarea name="ws_news_body" rows="6" required></textarea></label>'
		. '<label class="ws-fedit__label">' . esc_html__( 'Photos (up to 5, JPG, PNG, WebP or GIF, 8 MB each)', 'ws-chapters' ) . '<input type="file" name="ws_news_images[]" multiple data-ws-max="5" data-ws-max-mb="8" data-ws-main accept="image/jpeg,image/png,image/webp,image/gif"></label>'
		. '<label class="ws-fedit__label"><input type="checkbox" name="ws_consent" value="1" required> ' . esc_html__( 'I acknowledge that this will be shared on the website.', 'ws-chapters' ) . '</label>';
	return '<div class="ws-fe-site-news">' . ws_fe_form_box( $inner, __( 'Submit news or activity', 'ws-chapters' ), 'Published straight away.' ) . '</div>';
}

add_shortcode( 'ws_site_news_submit', 'ws_fe_site_news_form' );

/** Save the News page form: a post for Widows Sons Europe, a country or a chapter. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'site_news' !== ( $_POST['ws_form'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$guard = ws_form_guard_check( 'site_news' );
	$scope = sanitize_text_field( wp_unslash( $_POST['ws_news_scope'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$chapter_id = 0;
	$term_id    = 0;
	$error      = null;
	if ( true !== $guard ) {
		$error = $guard;
	} elseif ( ! is_user_logged_in() ) {
		$error = __( 'Please sign in to submit news.', 'ws-chapters' );
	} elseif ( '' === ws_post_field( 'ws_news_title' ) || '' === ws_post_field( 'ws_news_body', true ) || empty( $_POST['ws_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$error = __( 'Please fill in the title, the text and the agreement.', 'ws-chapters' );
	} elseif ( preg_match( '/^h(\d+)$/', $scope, $m ) ) {
		$chapter_id = (int) $m[1];
		$chapter    = get_post( $chapter_id );
		if ( ! $chapter || 'ws_chapter' !== $chapter->post_type || 'publish' !== $chapter->post_status ) {
			$error = __( 'Chapter not found.', 'ws-chapters' );
		} else {
			$terms   = get_the_terms( $chapter_id, 'ws_country' );
			$term_id = $terms && ! is_wp_error( $terms ) ? (int) $terms[0]->term_id : 0;
		}
	} elseif ( preg_match( '/^c(\d+)$/', $scope, $m ) ) {
		$term_id = (int) $m[1];
		if ( ! term_exists( $term_id, 'ws_country' ) ) {
			$error = __( 'Country not found.', 'ws-chapters' );
		}
	} elseif ( '0' !== $scope && '' !== $scope ) {
		$error = __( 'Please choose who the news is from.', 'ws-chapters' );
	}

	$ok = null === $error;
	if ( $ok ) {
		$post_id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'publish', // Signed-in users' news is published straight away.
			'post_title'   => ws_post_field( 'ws_news_title' ),
			'post_content' => wpautop( ws_post_field( 'ws_news_body', true ) ),
			'post_author'  => get_current_user_id(),
		), true );
		if ( is_wp_error( $post_id ) ) {
			$ok    = false;
			$error = __( 'Sorry, something went wrong. Please try again later.', 'ws-chapters' );
		} else {
			if ( $chapter_id ) {
				update_post_meta( $post_id, 'related_chapter', $chapter_id );
			}
			if ( $term_id ) {
				wp_set_object_terms( $post_id, array( $term_id ), 'ws_country' );
			}
			// Images go to the country's folder when the news has a country (upload-folders.php reads ws_country).
			if ( $term_id ) {
				$_REQUEST['ws_country'] = $term_id;
			}
			$images = ws_fe_news_images( $post_id );
			unset( $_REQUEST['ws_country'] );
			if ( $images ) {
				set_post_thumbnail( $post_id, $images[0] );
				update_post_meta( $post_id, 'ws_news_gallery', implode( ',', array_slice( $images, 1 ) ) ); // Gallery: shown under the text in a 2 x 2 grid.
			}
		}
	}

	$msg = $ok ? __( 'Published.', 'ws-chapters' ) : $error;
	set_transient( 'ws_fe_msg_' . get_current_user_id(), array( 'ok' => $ok, 'text' => $msg ), MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'ws_fe', $ok ? 'saved' : 'error', remove_query_arg( 'ws_fe', home_url( '/news/' ) ) ) );
	exit;
}, 2 );

/** A public button that opens a form (not tied to a chapter edit permission). */
function ws_fe_form_box( $inner, $label, $note, $submit = '' ) {
	return '<details class="ws-fedit ws-fedit--button ws-fe-news"><summary class="ws-fedit__btn" aria-label="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</summary>'
		. '<form method="post" enctype="multipart/form-data" class="ws-fedit__form"><button type="button" class="ws-fedit__close" aria-label="' . esc_attr__( 'Close', 'ws-chapters' ) . '" onclick="this.closest(\'details\').removeAttribute(\'open\')">&times;</button><p class="ws-fedit__title">' . esc_html( $label ) . '</p>'
		. '<p class="ws-help">' . esc_html( $note ) . '</p>' . $inner
		. '<p><button type="submit" class="ws-btn-gold">' . esc_html( '' !== $submit ? $submit : __( 'Send', 'ws-chapters' ) ) . '</button></p></form></details>';
}

/** Handle the news form: create the post, its country and chapter, and its images. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'chapter_news' !== ( $_POST['ws_form'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$chapter_id = absint( $_POST['ws_chapter'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$chapter    = $chapter_id ? get_post( $chapter_id ) : null;
	$guard      = ws_form_guard_check( 'chapter_news' );
	$error      = null;
	if ( true !== $guard ) {
		$error = $guard;
	} elseif ( ! $chapter || 'ws_chapter' !== $chapter->post_type || 'publish' !== $chapter->post_status ) {
		$error = __( 'Chapter not found.', 'ws-chapters' );
	} elseif ( '' === ws_post_field( 'ws_news_title' ) || '' === ws_post_field( 'ws_news_body', true ) || empty( $_POST['ws_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$error = __( 'Please fill in the title, the text and the agreement.', 'ws-chapters' );
	} elseif ( ! is_user_logged_in() ) {
		$error = __( 'Please sign in to submit news.', 'ws-chapters' );
	}

	$ok = null === $error;
	if ( $ok ) {
		$publish = true; // Signed-in users' news is published straight away, with no approval step.
		$post_id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => ws_post_field( 'ws_news_title' ),
			'post_content' => wpautop( ws_post_field( 'ws_news_body', true ) ),
			'post_author'  => get_current_user_id(),
		), true );
		if ( is_wp_error( $post_id ) ) {
			$ok    = false;
			$error = __( 'Sorry, something went wrong. Please try again later.', 'ws-chapters' );
		} else {
			update_post_meta( $post_id, 'related_chapter', $chapter_id );
			$country = ws_chapter_country( $chapter_id );
			if ( $country ) {
				wp_set_object_terms( $post_id, array( (int) $country->term_id ), 'ws_country' );
			}
			if ( ! is_user_logged_in() ) {
				update_post_meta( $post_id, '_ws_submitted_by', ws_post_field( 'ws_news_name' ) );
				update_post_meta( $post_id, '_ws_submitted_email', sanitize_email( ws_post_field( 'ws_news_email' ) ) );
			}
			// Images go to the chapter's country folder (upload-folders.php reads post_id).
			$_REQUEST['post_id'] = $chapter_id;
			$images              = ws_fe_news_images( $post_id );
			unset( $_REQUEST['post_id'] );
			if ( $images ) {
				set_post_thumbnail( $post_id, $images[0] );
				update_post_meta( $post_id, 'ws_news_gallery', implode( ',', array_slice( $images, 1 ) ) ); // Gallery: shown under the text in a 2 x 2 grid.
			}
			if ( ! $publish ) {
				ws_send_mail( get_option( 'admin_email' ), sprintf( /* translators: %s: chapter name */ __( 'News to review: %s', 'ws-chapters' ), $chapter->post_title ), array(
					__( 'A news item was sent from a chapter page and is waiting for review.', 'ws-chapters' ), '',
					__( 'Title:', 'ws-chapters' ) . ' ' . ws_post_field( 'ws_news_title' ),
					__( 'Chapter:', 'ws-chapters' ) . ' ' . $chapter->post_title,
					__( 'Review it here:', 'ws-chapters' ) . ' ' . admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				), is_user_logged_in() ? '' : sanitize_email( ws_post_field( 'ws_news_email' ) ) );
			}
		}
	}

	$msg = $ok
		? ( $publish ?? false ? __( 'Published.', 'ws-chapters' ) : __( 'Thank you. Your news has been sent for review.', 'ws-chapters' ) )
		: $error;
	set_transient( 'ws_fe_msg_' . get_current_user_id(), array( 'ok' => $ok, 'text' => $msg ), MINUTE_IN_SECONDS );
	$return = $chapter ? get_permalink( $chapter ) : home_url( '/' );
	wp_safe_redirect( add_query_arg( 'ws_fe', $ok ? 'saved' : 'error', remove_query_arg( 'ws_fe', $return ) ) . '#news' );
	exit;
}, 2 );

/** Upload the news photos as media for this post (the chapter's country folder). Returns IDs. */
function ws_fe_news_images( $post_id ) {
	if ( empty( $_FILES['ws_news_images'] ) || ! is_array( $_FILES['ws_news_images']['name'] ?? null ) ) {
		return array();
	}
	$files = $_FILES['ws_news_images']; // phpcs:ignore WordPress.Security.NonceVerification -- guarded by the handler.
	$ids   = array();
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	foreach ( array_slice( array_keys( $files['name'] ), 0, 5 ) as $i ) {
		if ( UPLOAD_ERR_OK !== (int) $files['error'][ $i ] || (int) $files['size'][ $i ] > 8 * MB_IN_BYTES ) {
			continue;
		}
		$file = array( 'name' => sanitize_file_name( $files['name'][ $i ] ), 'type' => $files['type'][ $i ], 'tmp_name' => $files['tmp_name'][ $i ], 'error' => 0, 'size' => (int) $files['size'][ $i ] );
		if ( empty( wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], ws_event_image_mimes() )['type'] ) ) {
			continue;
		}
		$id = media_handle_sideload( $file, $post_id, null, array( 'test_form' => false, 'mimes' => ws_event_image_mimes() ) );
		if ( ! is_wp_error( $id ) ) {
			$ids[] = (int) $id;
		}
	}
	return $ids;
}

/** Handle the country news form: a post for the country (no chapter), pending for non-admins. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'country_news' !== ( $_POST['ws_form'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$term_id = absint( $_POST['ws_country'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$term    = $term_id ? get_term( $term_id, 'ws_country' ) : null;
	$guard   = ws_form_guard_check( 'country_news' );
	$error   = null;
	if ( true !== $guard ) {
		$error = $guard;
	} elseif ( ! $term || is_wp_error( $term ) ) {
		$error = __( 'Country not found.', 'ws-chapters' );
	} elseif ( '' === ws_post_field( 'ws_news_title' ) || '' === ws_post_field( 'ws_news_body', true ) || empty( $_POST['ws_consent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$error = __( 'Please fill in the title, the text and the agreement.', 'ws-chapters' );
	} elseif ( ! is_user_logged_in() ) {
		$error = __( 'Please sign in to submit news.', 'ws-chapters' );
	}

	$ok      = null === $error;
	$publish = false;
	if ( $ok ) {
		$publish = true; // Signed-in users' news is published straight away, with no approval step.
		$post_id = wp_insert_post( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => ws_post_field( 'ws_news_title' ),
			'post_content' => wpautop( ws_post_field( 'ws_news_body', true ) ),
			'post_author'  => get_current_user_id(),
		), true );
		if ( is_wp_error( $post_id ) ) {
			$ok    = false;
			$error = __( 'Sorry, something went wrong. Please try again later.', 'ws-chapters' );
		} else {
			wp_set_object_terms( $post_id, array( $term_id ), 'ws_country' );
			if ( ! is_user_logged_in() ) {
				update_post_meta( $post_id, '_ws_submitted_by', ws_post_field( 'ws_news_name' ) );
				update_post_meta( $post_id, '_ws_submitted_email', sanitize_email( ws_post_field( 'ws_news_email' ) ) );
			}
			// Images go to this country's folder (upload-folders.php reads ws_country).
			$_REQUEST['ws_country'] = $term_id;
			$images                 = ws_fe_news_images( $post_id );
			unset( $_REQUEST['ws_country'] );
			if ( $images ) {
				set_post_thumbnail( $post_id, $images[0] );
				update_post_meta( $post_id, 'ws_news_gallery', implode( ',', array_slice( $images, 1 ) ) ); // Gallery: shown under the text in a 2 x 2 grid.
			}
			if ( ! $publish ) {
				ws_send_mail( get_option( 'admin_email' ), sprintf( /* translators: %s: country name */ __( 'News to review: %s', 'ws-chapters' ), $term->name ), array(
					__( 'A news item was sent from a country page and is waiting for review.', 'ws-chapters' ), '',
					__( 'Title:', 'ws-chapters' ) . ' ' . ws_post_field( 'ws_news_title' ),
					__( 'Country:', 'ws-chapters' ) . ' ' . $term->name,
					__( 'Review it here:', 'ws-chapters' ) . ' ' . admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
				), is_user_logged_in() ? '' : sanitize_email( ws_post_field( 'ws_news_email' ) ) );
			}
		}
	}

	$msg = $ok ? ( $publish ? __( 'Published.', 'ws-chapters' ) : __( 'Thank you. Your news has been sent for review.', 'ws-chapters' ) ) : $error;
	set_transient( 'ws_fe_msg_' . get_current_user_id(), array( 'ok' => $ok, 'text' => $msg ), MINUTE_IN_SECONDS );
	$return = $term ? get_term_link( $term ) : home_url( '/' );
	wp_safe_redirect( add_query_arg( 'ws_fe', $ok ? 'saved' : 'error', remove_query_arg( 'ws_fe', is_wp_error( $return ) ? home_url( '/' ) : $return ) ) . '#news' );
	exit;
}, 2 );

/* -------------------------------------------------------------------------
 * Country Chapters list: create a chapter, remove one (to the Trash)
 * ---------------------------------------------------------------------- */

/**
 * Who may create and remove chapters of a country from the site: Super Admins, and Country Admins for that
 * country only. Chapter Editors may not. (In the admin, Country Admins only edit chapters: this is the
 * site-side rule, checked on the server for every request.)
 */
function ws_can_manage_country_chapters( $term_id ) {
	$term_id = (int) $term_id;
	if ( ! $term_id || ! is_user_logged_in() ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) || current_user_can( WS_ASSET_CAP ) ) {
		return true;
	}
	$scope = ws_user_scope( get_current_user_id() );
	return (bool) $scope && $scope['country_admin'] && in_array( $term_id, $scope['countries'], true );
}

/** Is this chapter (published or draft) in the country? */
function ws_chapter_in_country( $chapter_id, $term_id ) {
	$post = get_post( (int) $chapter_id );
	if ( ! $post || 'ws_chapter' !== $post->post_type ) {
		return false;
	}
	$item = ws_item_scope( $post );
	return in_array( (int) $term_id, $item['countries'], true );
}

/**
 * Red "Close chapter" button on the chapter's own page: Super Admins for every chapter (also one without a
 * country), Country Admins for the chapters of their country. Asks for confirmation first; the chapter goes to
 * the Trash and the person lands on the country page (or the all-chapters page for a chapter without a country).
 */
function ws_fe_trash_chapter_form( $chapter_id, $term_id ) {
	$chapter_id = (int) $chapter_id;
	$term_id    = (int) $term_id;
	$super      = is_user_logged_in() && ( current_user_can( 'manage_options' ) || current_user_can( WS_ASSET_CAP ) );
	$in_country = $term_id && ws_chapter_in_country( $chapter_id, $term_id );
	if ( 'ws_chapter' !== get_post_type( $chapter_id ) || ! ( $super || ( $in_country && ws_can_manage_country_chapters( $term_id ) ) ) ) {
		return '';
	}
	$name    = ws_chapter_name( $chapter_id );
	/* translators: %s: chapter name */
	$confirm = sprintf( __( 'Close %s? It is removed from the site and goes to the Trash, where it can be restored from the WordPress admin.', 'ws-chapters' ), $name );
	// After closing, back to the country page (the chapter's own page is gone).
	$back   = $in_country ? get_term_link( $term_id, 'ws_country' ) : get_post_type_archive_link( 'ws_chapter' );
	$return = ( ! $back || is_wp_error( $back ) ) ? '/' : (string) wp_parse_url( $back, PHP_URL_PATH );
	$base   = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $base && 0 === strpos( $return, $base . '/' ) ) {
		$return = substr( $return, strlen( $base ) );
	}
	// A chapter in a country is closed through its country; one without a country through itself (Super Admins only).
	$type = $in_country ? 'term' : 'chapter';
	$id   = $in_country ? $term_id : $chapter_id;
	return '<form method="post" class="ws-fe-trash ws-fe-trash--page" onsubmit="' . esc_attr( 'return confirm(' . wp_json_encode( $confirm ) . ');' ) . '">'
		. '<input type="hidden" name="ws_fe_action" value="trash_chapter">'
		. '<input type="hidden" name="ws_fe_type" value="' . $type . '">'
		. '<input type="hidden" name="ws_fe_id" value="' . $id . '">'
		. '<input type="hidden" name="ws_fe_nonce" value="' . esc_attr( wp_create_nonce( 'ws_fe_' . $type . '_' . $id ) ) . '">'
		. '<input type="hidden" name="ws_fe_return" value="' . esc_attr( $return ) . '">'
		. '<input type="hidden" name="ws_chapter" value="' . $chapter_id . '">'
		/* translators: %s: chapter name */
		. '<button type="submit" class="ws-fe-delete-btn" aria-label="' . esc_attr( sprintf( __( 'Close %s', 'ws-chapters' ), $name ) ) . '">' . esc_html__( 'Close chapter', 'ws-chapters' ) . '</button></form>';
}

/**
 * Rename a chapter: a text button on the chapter's page, a pencil on its row in the country's Chapters list.
 * Same people as every other chapter edit (ws_front_can_edit): Super Admins, the country's Country Admins,
 * the chapter's Chapter Editors.
 */
function ws_fe_rename_box( $chapter_id, $label = '' ) {
	$inner = '<label class="ws-fedit__label">' . esc_html__( 'Chapter name', 'ws-chapters' )
		. '<input type="text" name="ws_chapter_name" value="' . esc_attr( get_the_title( $chapter_id ) ) . '" maxlength="80" required></label>'
		. '<p class="ws-muted">' . esc_html__( 'Type the full name; it is shown exactly as typed.', 'ws-chapters' ) . '</p>';
	return ws_fe_box( 'chapter', $chapter_id, 'rename', $inner, '' !== $label ? $label : __( 'Rename the chapter', 'ws-chapters' ), true );
}

/**
 * "Create chapter" for Country Admins of this country (and Super Admins); empty for everyone else.
 * With $term_id 0 (the all-chapters page) the form asks for the country: every country for Super Admins,
 * their own countries for Country Admins.
 */
function ws_fe_create_chapter_form( $term_id = 0 ) {
	if ( $term_id ) {
		if ( ! ws_can_manage_country_chapters( $term_id ) ) {
			return '';
		}
		$country = '<input type="hidden" name="ws_country" value="' . (int) $term_id . '">';
	} else {
		$options = '';
		$used    = array();
		foreach ( (array) get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false ) ) as $t ) {
			if ( ! $t instanceof WP_Term ) {
				continue;
			}
			$used[] = ws_country_code( $t );
			if ( ws_can_manage_country_chapters( $t->term_id ) ) {
				$options .= '<option value="' . (int) $t->term_id . '">' . esc_html( ws_display_name( $t->name ) ) . '</option>';
			}
		}
		if ( '' === $options ) {
			return '';
		}
		// Super Admins: every other country in the world too; it is added to the site with the chapter.
		$world = '';
		if ( current_user_can( 'manage_options' ) || current_user_can( WS_ASSET_CAP ) ) {
			foreach ( ws_world_countries() as $code => $name ) {
				if ( ! in_array( $code, $used, true ) ) {
					$world .= '<option value="new:' . esc_attr( $code ) . '">' . esc_html( $name ) . '</option>';
				}
			}
		}
		$country = '<label class="ws-fedit__label">' . esc_html__( 'Country', 'ws-chapters' ) . '<select name="ws_country" required><option value="">' . esc_html__( '— Choose the country —', 'ws-chapters' ) . '</option>'
			. ( '' !== $world ? '<optgroup label="' . esc_attr__( 'On the site', 'ws-chapters' ) . '">' . $options . '</optgroup><optgroup label="' . esc_attr__( 'Other countries (added with the chapter)', 'ws-chapters' ) . '">' . $world . '</optgroup>' : $options )
			. '</select></label>';
	}
	$inner = ws_form_guard_fields( 'country_chapter' )
		. $country
		. '<label class="ws-fedit__label">' . esc_html__( 'Full chapter name, shown exactly as typed (for example Widows Sons Sofia)', 'ws-chapters' ) . '<input type="text" name="ws_chapter_name" maxlength="80" required></label>'
		. '<label class="ws-fedit__label">' . esc_html__( 'City', 'ws-chapters' ) . '<input type="text" name="ws_chapter_city" required></label>';
	return '<div class="ws-fe-create-chapter">' . ws_fe_form_box( $inner, __( '+ Create new chapter', 'ws-chapters' ), $term_id ? __( 'The chapter is added to this country. Its details, patch and logo can then be added from its page.', 'ws-chapters' ) : __( 'The chapter is added to the country you choose. Its details, patch and logo can then be added from its page.', 'ws-chapters' ), __( 'Create chapter', 'ws-chapters' ) ) . '</div>';
}

/** Handle the Create chapter form: the chapter belongs to this country only. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'country_chapter' !== ( $_POST['ws_form'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$guard   = ws_form_guard_check( 'country_chapter' );
	$term_id = 0;
	$term    = null;
	$error   = null;
	if ( true !== $guard ) {
		$error = $guard;
	} elseif ( '' === ws_post_field( 'ws_chapter_name' ) || '' === ws_post_field( 'ws_chapter_city' ) ) {
		$error = __( 'Please add the chapter name and the city.', 'ws-chapters' );
	} else {
		// A country not yet on the site ("new:us") is added here, for Super Admins only.
		$term_id = ws_resolve_new_chapter_country( sanitize_text_field( wp_unslash( $_POST['ws_country'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$term    = $term_id ? get_term( $term_id, 'ws_country' ) : null;
		if ( ! ws_can_manage_country_chapters( $term_id ) || ! $term || is_wp_error( $term ) ) {
			$error = __( 'You can only add chapters to your own country.', 'ws-chapters' );
		}
	}
	$ok = null === $error;
	if ( $ok ) {
		$chapter_id = wp_insert_post( array(
			'post_type'    => 'ws_chapter',
			'post_status'  => 'publish',
			'post_title'   => ws_post_field( 'ws_chapter_name' ),
			'post_content' => '<!-- wp:paragraph --><p>' . esc_html__( 'This chapter profile is being prepared. Its history, riding activities and charitable initiatives will appear when confirmed by its representatives.', 'ws-chapters' ) . '</p><!-- /wp:paragraph -->',
		), true );
		if ( is_wp_error( $chapter_id ) ) {
			$ok    = false;
			$error = __( 'Sorry, something went wrong. Please try again later.', 'ws-chapters' );
		} else {
			wp_set_object_terms( $chapter_id, array( (int) $term_id ), 'ws_country' ); // This country only.
			update_post_meta( $chapter_id, 'city', ws_post_field( 'ws_chapter_city' ) );
			ws_locate_chapter( $chapter_id ); // Its pin goes on its city.
			do_action( 'litespeed_purge_all' );
		}
	}
	$msg = $ok ? __( 'Chapter created. Its details, patch and logo can be added from its page.', 'ws-chapters' ) : $error;
	set_transient( 'ws_fe_msg_' . get_current_user_id(), array( 'ok' => $ok, 'text' => $msg ), MINUTE_IN_SECONDS );
	$return = $term ? get_term_link( $term ) : home_url( '/' );
	wp_safe_redirect( add_query_arg( 'ws_fe', $ok ? 'saved' : 'error', remove_query_arg( 'ws_fe', is_wp_error( $return ) ? home_url( '/' ) : $return ) ) . '#chapters' );
	exit;
}, 2 );

/* -------------------------------------------------------------------------
 * News: delete an article (to the Trash)
 * ---------------------------------------------------------------------- */

/**
 * Who may delete a news article: its author, a Super Admin, or a Country Admin of the article's country.
 * Everyone else is refused, on the server, whatever the button shows.
 */
function ws_can_delete_news( $post_id ) {
	$post = get_post( (int) $post_id );
	$uid  = get_current_user_id();
	if ( ! $post || 'post' !== $post->post_type || ! $uid ) {
		return false;
	}
	if ( (int) $post->post_author === $uid ) {
		return true;
	}
	if ( current_user_can( 'manage_options' ) || current_user_can( WS_ASSET_CAP ) ) {
		return true;
	}
	$scope = ws_user_scope( $uid );
	if ( ! $scope || ! $scope['country_admin'] ) {
		return false;
	}
	$terms = wp_get_object_terms( $post->ID, 'ws_country', array( 'fields' => 'ids' ) );
	return ! is_wp_error( $terms ) && (bool) array_intersect( array_map( 'intval', $terms ), $scope['countries'] );
}

/** Trash button for a news article, shown only to people who may delete it. $text = a labelled button. */
function ws_fe_delete_news_form( $post_id, $text = false ) {
	if ( ! ws_can_delete_news( $post_id ) ) {
		return '';
	}
	$post_id = (int) $post_id;
	$title   = get_the_title( $post_id );
	/* translators: %s: article title */
	$confirm = sprintf( __( 'Move "%s" to the Trash? You can restore it from the WordPress admin.', 'ws-chapters' ), $title );
	$return  = wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
	$base    = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
	if ( '' !== $base && 0 === strpos( $return, $base . '/' ) ) {
		$return = substr( $return, strlen( $base ) );
	}
	$label  = sprintf( __( 'Delete %s', 'ws-chapters' ), $title );
	$button = $text
		? '<button type="submit" class="ws-btn-outline ws-fe-trash__text">' . esc_html__( 'Delete this article', 'ws-chapters' ) . '</button>'
		: '<button type="submit" class="ws-fe-trash__btn" title="' . esc_attr( $label ) . '" aria-label="' . esc_attr( $label ) . '"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6l1 14h10l1-14"/></svg></button>';
	return '<form method="post" class="ws-fe-trash ws-fe-trash--news' . ( $text ? ' ws-fe-trash--text' : '' ) . '" onsubmit="' . esc_attr( 'return confirm(' . wp_json_encode( $confirm ) . ');' ) . '">'
		. '<input type="hidden" name="ws_news_delete" value="' . $post_id . '">'
		. '<input type="hidden" name="ws_news_nonce" value="' . esc_attr( wp_create_nonce( 'ws_delete_news_' . $post_id ) ) . '">'
		. '<input type="hidden" name="ws_fe_return" value="' . esc_attr( $return ) . '">'
		. $button . '</form>';
}

/** Move the article to the Trash, after the nonce and permission checks. */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['ws_news_delete'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$post_id = absint( $_POST['ws_news_delete'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$nonce   = sanitize_text_field( wp_unslash( $_POST['ws_news_nonce'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $post_id || ! wp_verify_nonce( $nonce, 'ws_delete_news_' . $post_id ) ) {
		wp_die( esc_html__( 'This form has expired. Please reload the page and try again.', 'ws-chapters' ), 403 );
	}
	if ( ! ws_can_delete_news( $post_id ) ) {
		wp_die( esc_html__( 'You do not have permission to delete this article.', 'ws-chapters' ), 403 );
	}
	$ok = (bool) wp_trash_post( $post_id );
	set_transient( 'ws_fe_msg_' . get_current_user_id(), array( 'ok' => $ok, 'text' => $ok ? __( 'The article was moved to the Trash.', 'ws-chapters' ) : __( 'Sorry, the article could not be deleted.', 'ws-chapters' ) ), MINUTE_IN_SECONDS );
	$return = wp_unslash( (string) ( $_POST['ws_fe_return'] ?? '/' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( '' === $return || '/' !== $return[0] || '/' === ( $return[1] ?? '' ) ) {
		$return = '/';
	}
	wp_safe_redirect( add_query_arg( 'ws_fe', $ok ? 'saved' : 'error', remove_query_arg( 'ws_fe', home_url( $return ) ) ) );
	exit;
}, 1 );

/** On a single news article: the delete button under the text (for those who may delete it). */
add_filter( 'the_content', function ( $content ) {
	$id = get_the_ID();
	if ( ! $id || ! is_main_query() || ! in_the_loop() || ! is_single() || 'post' !== get_post_type( $id ) ) {
		return $content;
	}
	return $content;
} );

/* News cards from the block templates (the News page and Latest news on the home page): add the button under each excerpt. */
add_filter( 'render_block_core/post-excerpt', function ( $html ) {
	$id = get_the_ID();
	if ( ! $id || 'post' !== get_post_type( $id ) || is_single() ) {
		return $html;
	}
	return $html . ws_cx_edit_link( 'news', $id ); // Pencil "Edit" (edit screen has Save, Cancel and Delete).
} );

