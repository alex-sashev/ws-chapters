<?php
/**
 * Editing from the site itself: small pencil buttons on chapter and country pages.
 *
 * Signed-in people who manage a chapter or country see a pencil on its images, contact box and gallery.
 * Each pencil opens a small form; the form posts back to the same page and is saved here, after the same
 * permission check that the admin screens use (ws_front_can_edit). Visitors and members see no pencils.
 *
 * Also: the About page text is a normal WordPress page (moved out of the theme pattern once), so Super Admins
 * can edit it; the page shows an edit link for anyone who may edit it.
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
	$return = wp_unslash( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) ); // Back to this page after saving.
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
		. '<form method="post" enctype="multipart/form-data" class="ws-fedit__form"><p class="ws-fedit__title">' . esc_html( $label ) . '</p>'
		. $hidden . $inner
		. '<p><button type="submit" class="ws-btn-gold">' . esc_html__( 'Save', 'ws-chapters' ) . '</button></p></form></details>';
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
		. '<input type="file" name="ws_fe_file[]" multiple accept="image/jpeg,image/png,image/webp,image/gif"></label>';
	return '<div class="ws-fe-toolbar">' . ws_fe_box( $type, $id, 'image', $inner, __( 'Upload photos', 'ws-chapters' ), true ) . '</div>' . ws_gallery_grid( $ids );
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

	// Image.
	$slot = sanitize_key( wp_unslash( $_POST['ws_fe_slot'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
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
	wp_safe_redirect( add_query_arg( 'ws_fe', $ok ? 'saved' : 'error', remove_query_arg( 'ws_fe', home_url( $return ) ) ) );
	exit;
}, 1 );

/** A short notice after saving or failing (one-off, from the transient above). */
add_action( 'wp_footer', function () {
	if ( empty( $_GET['ws_fe'] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$msg = get_transient( 'ws_fe_msg_' . get_current_user_id() );
	delete_transient( 'ws_fe_msg_' . get_current_user_id() );
	if ( ! $msg ) {
		return;
	}
	echo '<div class="ws-fe-notice ' . ( $msg['ok'] ? 'ws-fe-notice--ok' : 'ws-fe-notice--error' ) . '" role="status">' . esc_html( $msg['text'] ) . '</div>';
} );

/* -------------------------------------------------------------------------
 * About page: an ordinary page, with a link to edit it
 * ---------------------------------------------------------------------- */

/**
 * Once: the About page used to show the theme's pattern file (text inside the theme). Copy that text into the
 * page itself, so it can be edited like any other page. Existing edits are never overwritten.
 */
add_action( 'init', function () {
	if ( get_option( 'ws_about_migrated' ) || ! post_type_exists( 'page' ) ) {
		return;
	}
	$page = get_page_by_path( 'about' );
	if ( ! $page || false === strpos( $page->post_content, 'ws-europe/page-about' ) ) {
		if ( $page ) {
			update_option( 'ws_about_migrated', gmdate( 'c' ), false );
		}
		return;
	}
	$file = get_theme_file_path( 'patterns/page-about.php' );
	if ( ! is_readable( $file ) ) {
		return; // Try again on a later page load.
	}
	$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$end = strpos( $src, '?>' );
	$new = trim( false === $end ? $src : substr( $src, $end + 2 ) );
	if ( '' === $new ) {
		return;
	}
	wp_update_post( array( 'ID' => $page->ID, 'post_content' => $new ) );
	update_option( 'ws_about_migrated', gmdate( 'c' ), false );
}, 30 );

/** Under the About page text: an edit link for people who may edit the page (Super Admins). */
add_filter( 'the_content', function ( $content ) {
	$id = get_the_ID();
	if ( ! $id || ! is_main_query() || ! in_the_loop() || ! is_page( 'about' ) || ! current_user_can( 'edit_post', $id ) ) {
		return $content;
	}
	return $content . '<p class="ws-fe-page-edit"><a class="ws-fedit__btn ws-fedit__btn--link" href="' . esc_url( get_edit_post_link( $id ) ) . '" title="' . esc_attr__( 'Edit this page', 'ws-chapters' ) . '" aria-label="' . esc_attr__( 'Edit this page', 'ws-chapters' ) . '">' . ws_fe_pencil() . ' <span>' . esc_html__( 'Edit this page', 'ws-chapters' ) . '</span></a></p>';
} );
