<?php
/**
 * Editing news, events and library documents from the site.
 *
 * Who may edit: the person who posted it; Super Admins (all); Country Admins (everything in their country).
 * Listings show a pencil "Edit" link. The edit screen has Save changes, Cancel and Delete (Delete asks first
 * and moves the item to the Trash). Images: up to five per news item or event; the first is the main image,
 * the others form the gallery. Page: "Edit" (slug edit-content, under Members). Shortcode [ws_content_edit].
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

const WS_CX_MAX_IMAGES = 5;

/** Who may edit an item: its author, a Super Admin, a Country Admin of its country (news and events). */
function ws_cx_can_edit( $type, $id ) {
	$id   = (int) $id;
	$post = $id ? get_post( $id ) : null;
	$uid  = get_current_user_id();
	$want = array( 'news' => 'post', 'event' => 'ws_event', 'doc' => 'ws_member_doc' );
	if ( ! $post || ! $uid || ! isset( $want[ $type ] ) || $want[ $type ] !== $post->post_type ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) || current_user_can( WS_ASSET_CAP ) || (int) $post->post_author === $uid ) {
		return true;
	}
	if ( 'doc' === $type ) {
		return false; // Library documents: the uploader or a Super Admin.
	}
	$scope = ws_user_scope( $uid );
	if ( ! $scope || ! $scope['country_admin'] ) {
		return false;
	}
	$countries = 'news' === $type
		? array_map( 'intval', wp_get_object_terms( $id, 'ws_country', array( 'fields' => 'ids' ) ) )
		: ws_item_scope( $post )['countries'];
	return (bool) array_intersect( $countries, $scope['countries'] );
}

/** Address of the edit screen for an item. */
function ws_cx_edit_url( $type, $id ) {
	$page = ws_mm_page( 'edit-content' );
	return add_query_arg( array( 'ws_type' => $type, 'ws_id' => (int) $id ), $page ? get_permalink( $page ) : home_url( '/' ) );
}

/** The pencil "Edit" link for a listing, or '' for people who may not edit the item. */
function ws_cx_edit_link( $type, $id ) {
	if ( ! ws_cx_can_edit( $type, $id ) ) {
		return '';
	}
	return '<a class="ws-cx-edit" href="' . esc_url( ws_cx_edit_url( $type, $id ) ) . '">' . ws_fe_pencil() . '<span>' . esc_html__( 'Edit', 'ws-chapters' ) . '</span></a>';
}

/** Where Cancel and a successful Delete go: the item's page, or the listing it came from. */
function ws_cx_back_url( $type, $id ) {
	if ( 'news' === $type ) {
		$page = (int) get_option( 'page_for_posts' );
		return $page ? get_permalink( $page ) : home_url( '/' );
	}
	if ( 'event' === $type ) {
		return get_post_type_archive_link( 'ws_event' );
	}
	$page = ws_mm_page( 'members' );
	return $page ? get_permalink( $page ) : home_url( '/' );
}

/** The item's images in order: the main image, then the gallery. */
function ws_cx_images( $type, $id ) {
	$ids = array();
	$main = (int) get_post_thumbnail_id( $id );
	if ( $main ) {
		$ids[] = $main;
	}
	$key = 'news' === $type ? 'ws_news_gallery' : 'gallery';
	foreach ( array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $id, $key, true ) ) ) ) as $img ) {
		if ( ! in_array( $img, $ids, true ) ) {
			$ids[] = $img;
		}
	}
	return $ids;
}

/** Validate and store newly chosen images (field ws_cx_new). Returns attachment IDs, or a WP_Error. */
function ws_cx_upload_new( $id, $limit ) {
	$raw = $_FILES['ws_cx_new'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification -- checked by the handler.
	if ( ! $raw || ! is_array( $raw['name'] ?? null ) ) {
		return array();
	}
	$chosen = array();
	foreach ( array_keys( $raw['name'] ) as $i ) {
		if ( UPLOAD_ERR_NO_FILE === (int) $raw['error'][ $i ] ) {
			continue;
		}
		$chosen[] = $i;
	}
	if ( ! $chosen ) {
		return array();
	}
	if ( count( $chosen ) > $limit ) {
		/* translators: %d: number of images allowed */
		return new WP_Error( 'ws_cx', sprintf( __( 'At most %d images in total. Remove some first.', 'ws-chapters' ), WS_CX_MAX_IMAGES ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$ids = array();
	foreach ( $chosen as $i ) {
		if ( UPLOAD_ERR_OK !== (int) $raw['error'][ $i ] || (int) $raw['size'][ $i ] > 8 * MB_IN_BYTES ) {
			return new WP_Error( 'ws_cx', sprintf( __( 'The image "%s" is too large or could not be uploaded (8 MB each at most).', 'ws-chapters' ), $raw['name'][ $i ] ) );
		}
		$file = array( 'name' => sanitize_file_name( $raw['name'][ $i ] ), 'type' => $raw['type'][ $i ], 'tmp_name' => $raw['tmp_name'][ $i ], 'error' => 0, 'size' => (int) $raw['size'][ $i ] );
		if ( empty( wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], ws_event_image_mimes() )['type'] ) ) {
			return new WP_Error( 'ws_cx', sprintf( __( '"%s" is not an image. Please use JPG, PNG, WebP or GIF.', 'ws-chapters' ), $file['name'] ) );
		}
		$_REQUEST['post_id'] = $id; // Country folder for this item (upload-folders.php).
		$att                 = media_handle_sideload( $file, $id, null, array( 'test_form' => false, 'mimes' => ws_event_image_mimes() ) );
		unset( $_REQUEST['post_id'] );
		if ( ! is_wp_error( $att ) ) {
			$ids[] = (int) $att;
		}
	}
	return $ids;
}

/**
 * Apply the image manager: keep, remove and reorder the current images, then add new ones.
 * Position 1 becomes the main image; the rest are stored as the gallery.
 */
function ws_cx_save_images( $type, $id ) {
	$current = ws_cx_images( $type, $id );
	$remove  = array_map( 'absint', (array) ( $_POST['ws_img_remove'] ?? array() ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$order   = (array) ( $_POST['ws_img_order'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification
	$keep    = array();
	foreach ( $current as $idx => $img ) {
		if ( in_array( $img, $remove, true ) ) {
			continue;
		}
		$pos    = isset( $order[ $img ] ) ? (int) $order[ $img ] : $idx + 1;
		$keep[] = array( $pos, $idx, $img );
	}
	usort( $keep, function ( $a, $b ) {
		return $a[0] === $b[0] ? $a[1] - $b[1] : $a[0] - $b[0];
	} );
	$kept = array_column( $keep, 2 );
	$new  = ws_cx_upload_new( $id, WS_CX_MAX_IMAGES - count( $kept ) );
	if ( is_wp_error( $new ) ) {
		return $new;
	}
	$all = array_merge( $kept, $new );
	if ( $all ) {
		set_post_thumbnail( $id, $all[0] );
	} else {
		delete_post_thumbnail( $id );
	}
	$gallery = implode( ',', array_slice( $all, 1 ) );
	$key     = 'news' === $type ? 'ws_news_gallery' : 'gallery';
	'' === $gallery ? delete_post_meta( $id, $key ) : update_post_meta( $id, $key, $gallery );
	return true;
}

/** The image list with position boxes and Remove tick boxes, plus the box for new images. */
function ws_cx_image_fields( $type, $id ) {
	$have = ws_cx_images( $type, $id );
	$out  = '<fieldset class="ws-cx-images"><legend>' . esc_html__( 'Images (up to 5: the first is the main image, the rest are the gallery)', 'ws-chapters' ) . '</legend><ul class="ws-cx-images__list">';
	foreach ( $have as $i => $img ) {
		$out .= '<li class="ws-cx-img"><figure>' . wp_get_attachment_image( $img, 'thumbnail' ) . '</figure>'
			. '<label>' . esc_html__( 'Position', 'ws-chapters' ) . ' <input type="number" min="1" max="' . WS_CX_MAX_IMAGES . '" name="ws_img_order[' . (int) $img . ']" value="' . ( $i + 1 ) . '" class="ws-mm__input ws-cx-img__pos"></label>'
			. '<label class="ws-cx-img__remove"><input type="checkbox" name="ws_img_remove[]" value="' . (int) $img . '"> ' . esc_html__( 'Remove', 'ws-chapters' ) . '</label></li>';
	}
	$out .= '</ul><label class="ws-cx-images__add">' . esc_html__( 'Add images', 'ws-chapters' )
		. '<input type="file" name="ws_cx_new[]" multiple data-ws-max="' . max( 0, WS_CX_MAX_IMAGES - count( $have ) ) . '" data-ws-max-mb="8"' . ( $have ? '' : ' data-ws-main' ) . ' accept="image/jpeg,image/png,image/webp,image/gif"></label></fieldset>';
	return $out;
}

/* Save and delete from the edit screen (checked for permission and nonce first). */
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['ws_cx_form'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$type = sanitize_key( wp_unslash( $_POST['ws_cx_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$id   = absint( $_POST['ws_cx_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$act  = sanitize_key( wp_unslash( $_POST['ws_cx_form'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$nonce = sanitize_text_field( wp_unslash( $_POST['ws_cx_nonce'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! wp_verify_nonce( $nonce, 'ws_cx_' . $act . '_' . $type . '_' . $id ) || ! ws_cx_can_edit( $type, $id ) ) {
		wp_die( esc_html__( 'You cannot change this item, or the form has expired.', 'ws-chapters' ), 403 );
	}

	if ( 'delete' === $act ) {
		wp_trash_post( $id );
		do_action( 'litespeed_purge_all' );
		$text = __( 'The item has been deleted. It can be restored from the Trash in the WordPress admin.', 'ws-chapters' );
		wp_safe_redirect( ws_mm_flash_url( ws_cx_back_url( $type, $id ), true, $text ) );
		exit;
	}

	$title = trim( ws_post_field( 'ws_cx_title' ) );
	$error = '' === $title ? __( 'Please add a title.', 'ws-chapters' ) : null;
	if ( null === $error && 'news' === $type ) {
		$date = ws_sanitize_date( ws_post_field( 'ws_cx_date' ) );
		$body = ws_post_field( 'ws_cx_body', true );
		if ( '' === $body ) {
			$error = __( 'Please add the text.', 'ws-chapters' );
		} else {
			$when = $date ? $date . ' ' . current_time( 'H:i:s' ) : current_time( 'mysql' );
			wp_update_post( array( 'ID' => $id, 'post_title' => $title, 'post_content' => wpautop( $body ), 'post_date' => $when, 'post_date_gmt' => get_gmt_from_date( $when ) ) );
		}
	} elseif ( null === $error && 'event' === $type ) {
		$start = ws_sanitize_date( ws_post_field( 'ws_cx_start' ) );
		$end   = ws_sanitize_date( ws_post_field( 'ws_cx_end' ) );
		if ( '' === $start ) {
			$error = __( 'Please add the start date.', 'ws-chapters' );
		} elseif ( $end && $end < $start ) {
			$error = __( 'The end date is before the start date.', 'ws-chapters' );
		} else {
			wp_update_post( array( 'ID' => $id, 'post_title' => $title, 'post_content' => wp_kses_post( ws_post_field( 'ws_cx_body', true ) ) ) );
			update_post_meta( $id, 'start_date', $start );
			update_post_meta( $id, 'end_date', $end );
			update_post_meta( $id, 'start_time', ws_sanitize_time( ws_post_field( 'ws_cx_time' ) ) );
			update_post_meta( $id, 'city', ws_post_field( 'ws_cx_city' ) );
			update_post_meta( $id, 'venue', ws_post_field( 'ws_cx_venue' ) );
			update_post_meta( $id, 'organiser', ws_post_field( 'ws_cx_organiser' ) );
			update_post_meta( $id, 'contact', ws_post_field( 'ws_cx_contact', true ) );
			// Organising chapter: shown as "Hosted by" with its logo and flag; none = "Organised by" the name above.
			$host = absint( ws_post_field( 'ws_cx_chapter' ) );
			'ws_chapter' === get_post_type( $host ) ? update_post_meta( $id, 'host_chapter', $host ) : delete_post_meta( $id, 'host_chapter' );
		}
	} elseif ( null === $error && 'doc' === $type ) {
		$date = ws_sanitize_date( ws_post_field( 'ws_cx_date' ) );
		$kind = ws_post_field( 'ws_cx_kind' );
		$url  = esc_url_raw( ws_post_field( 'ws_cx_url' ), array( 'http', 'https' ) );
		if ( ! isset( ws_doc_types()[ $kind ] ) || '' === $date ) {
			$error = __( 'Please choose a type and a date.', 'ws-chapters' );
		} elseif ( 'link' === $kind && ! wp_http_validate_url( $url ) ) {
			$error = __( 'Please add a web address starting with https://', 'ws-chapters' );
		} else {
			wp_update_post( array( 'ID' => $id, 'post_title' => $title, 'post_content' => ws_post_field( 'ws_cx_body', true ) ) );
			update_post_meta( $id, '_ws_doc_type', $kind );
			update_post_meta( $id, '_ws_doc_date', $date );
			if ( 'link' === $kind ) {
				update_post_meta( $id, '_ws_url', $url );
			} elseif ( ! empty( $_FILES['ws_cx_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$stored = ws_store_member_file( $_FILES['ws_cx_file'] ); // phpcs:ignore WordPress.Security.NonceVerification
				if ( is_wp_error( $stored ) ) {
					$error = $stored->get_error_message();
				} else {
					$old = (string) get_post_meta( $id, '_ws_file', true );
					if ( $old ) {
						wp_delete_file( ws_members_dir() . '/' . $old );
					}
					update_post_meta( $id, '_ws_file', $stored['path'] );
					update_post_meta( $id, '_ws_file_name', $stored['name'] );
					update_post_meta( $id, '_ws_mime', $stored['mime'] );
					update_post_meta( $id, '_ws_size', $stored['size'] );
					delete_post_meta( $id, '_ws_url' );
				}
			} elseif ( 'link' !== $kind ) {
				update_post_meta( $id, '_ws_url', '' );
			}
		}
	}

	if ( null === $error && 'doc' !== $type ) {
		$images = ws_cx_save_images( $type, $id );
		if ( is_wp_error( $images ) ) {
			$error = $images->get_error_message();
		}
	}

	$ok   = null === $error;
	$text = $ok ? __( 'Your changes are saved.', 'ws-chapters' ) : $error;
	if ( $ok ) {
		do_action( 'litespeed_purge_all' );
	}
	wp_safe_redirect( ws_mm_flash_url( ws_cx_edit_url( $type, $id ), $ok, $text ) );
	exit;
}, 2 );

/** The edit screen. */
add_shortcode( 'ws_content_edit', function () {
	if ( ! is_user_logged_in() ) {
		return '<p class="ws-muted">' . esc_html__( 'Please sign in to edit.', 'ws-chapters' ) . '</p>';
	}
	// phpcs:disable WordPress.Security.NonceVerification -- read-only.
	$type = isset( $_GET['ws_type'] ) ? sanitize_key( wp_unslash( $_GET['ws_type'] ) ) : '';
	$id   = isset( $_GET['ws_id'] ) ? absint( $_GET['ws_id'] ) : 0;
	// phpcs:enable
	if ( ! ws_cx_can_edit( $type, $id ) ) {
		return '<p class="ws-muted">' . esc_html__( 'You cannot edit this item.', 'ws-chapters' ) . '</p>';
	}
	$post  = get_post( $id );
	$nonce = wp_create_nonce( 'ws_cx_save_' . $type . '_' . $id );
	$back  = ws_cx_back_url( $type, $id );
	$f     = function ( $label, $html ) {
		return '<p class="ws-mm__field"><span class="ws-mm__label">' . esc_html( $label ) . '</span>' . $html . '</p>';
	};
	$text  = function ( $name, $value, $required = false ) {
		return '<input type="text" class="ws-mm__input" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . '>';
	};
	$date  = function ( $name, $value ) {
		return '<input type="date" class="ws-mm__input" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
	};
	$area  = function ( $name, $value, $rows = 6 ) {
		return '<textarea class="ws-mm__input ws-mm__textarea" name="' . esc_attr( $name ) . '" rows="' . (int) $rows . '">' . esc_textarea( $value ) . '</textarea>';
	};

	$fields = '';
	if ( 'news' === $type ) {
		$fields = $f( __( 'Title', 'ws-chapters' ), $text( 'ws_cx_title', $post->post_title, true ) )
			. $f( __( 'Date', 'ws-chapters' ), $date( 'ws_cx_date', get_the_date( 'Y-m-d', $post ) ) )
			. $f( __( 'The news', 'ws-chapters' ), $area( 'ws_cx_body', wp_strip_all_tags( $post->post_content ), 10 ) )
			. ws_cx_image_fields( $type, $id );
	} elseif ( 'event' === $type ) {
		$fields = $f( __( 'Event name', 'ws-chapters' ), $text( 'ws_cx_title', $post->post_title, true ) )
			. '<div class="ws-mm__grid">'
			. $f( __( 'Start date', 'ws-chapters' ), $date( 'ws_cx_start', get_post_meta( $id, 'start_date', true ) ) )
			. $f( __( 'End date', 'ws-chapters' ), $date( 'ws_cx_end', get_post_meta( $id, 'end_date', true ) ) )
			. $f( __( 'Start time', 'ws-chapters' ), '<input type="time" class="ws-mm__input" name="ws_cx_time" value="' . esc_attr( get_post_meta( $id, 'start_time', true ) ) . '">' )
			. $f( __( 'City', 'ws-chapters' ), $text( 'ws_cx_city', get_post_meta( $id, 'city', true ) ) )
			. $f( __( 'Venue or meeting point', 'ws-chapters' ), $text( 'ws_cx_venue', get_post_meta( $id, 'venue', true ) ) )
			. $f( __( 'Organising chapter', 'ws-chapters' ), '<select class="ws-mm__input" name="ws_cx_chapter">' . ws_chapter_options( (int) get_post_meta( $id, 'host_chapter', true ), __( '— Not a listed chapter —', 'ws-chapters' ) ) . '</select>' )
			. $f( __( 'Or organiser name', 'ws-chapters' ), $text( 'ws_cx_organiser', get_post_meta( $id, 'organiser', true ) ) )
			. '</div>'
			. $f( __( 'Description', 'ws-chapters' ), $area( 'ws_cx_body', wp_strip_all_tags( $post->post_content ), 8 ) )
			. $f( __( 'Contact details for riders', 'ws-chapters' ), $area( 'ws_cx_contact', get_post_meta( $id, 'contact', true ), 3 ) )
			. ws_cx_image_fields( $type, $id );
	} else {
		$kind  = (string) get_post_meta( $id, '_ws_doc_type', true );
		$opts  = '';
		foreach ( ws_doc_types() as $k => $label ) {
			$opts .= '<option value="' . esc_attr( $k ) . '"' . selected( $kind, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$fields = $f( __( 'Title', 'ws-chapters' ), $text( 'ws_cx_title', $post->post_title, true ) )
			. '<div class="ws-mm__grid">'
			. $f( __( 'Type', 'ws-chapters' ), '<select class="ws-mm__input" name="ws_cx_kind">' . $opts . '</select>' )
			. $f( __( 'Document date', 'ws-chapters' ), $date( 'ws_cx_date', get_post_meta( $id, '_ws_doc_date', true ) ) )
			. '</div>'
			. $f( __( 'Description', 'ws-chapters' ), $area( 'ws_cx_body', $post->post_content, 4 ) )
			. $f( __( 'Web address (for the Link type)', 'ws-chapters' ), '<input type="url" class="ws-mm__input" name="ws_cx_url" placeholder="https://" value="' . esc_attr( get_post_meta( $id, '_ws_url', true ) ) . '">' )
			. $f( __( 'Replace the file (optional)', 'ws-chapters' ), '<input type="file" class="ws-mm__input" name="ws_cx_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.txt,.jpg,.jpeg,.png">' . '<span class="ws-muted">' . esc_html( (string) get_post_meta( $id, '_ws_file_name', true ) ) . '</span>' );
	}

	$heading = array( 'news' => __( 'Edit news', 'ws-chapters' ), 'event' => __( 'Edit event', 'ws-chapters' ), 'doc' => __( 'Edit document', 'ws-chapters' ) );
	$out  = '<div class="ws-mm alignwide ws-cx">' . ws_mm_flash_notice();
	$out .= '<section class="ws-mm__card"><h3 class="ws-mm__heading">' . esc_html( $heading[ $type ] ) . '</h3>';
	$out .= '<form method="post" enctype="multipart/form-data" class="ws-cx__form">'
		. '<input type="hidden" name="ws_cx_form" value="save"><input type="hidden" name="ws_cx_type" value="' . esc_attr( $type ) . '"><input type="hidden" name="ws_cx_id" value="' . $id . '"><input type="hidden" name="ws_cx_nonce" value="' . esc_attr( $nonce ) . '">'
		. $fields
		. '<div class="ws-mm__actions ws-cx__buttons"><button type="submit" class="ws-mm__btn">' . esc_html__( 'Save changes', 'ws-chapters' ) . '</button>'
		. '<a class="ws-mm__btn ws-mm__btn--ghost" href="' . esc_url( $back ) . '">' . esc_html__( 'Go back', 'ws-chapters' ) . '</a>'
		. '<button type="submit" form="ws-cx-delete-form" class="ws-cx__delete-btn">' . esc_html__( 'Delete', 'ws-chapters' ) . '</button></div>'
		. '</form>';
	// One confirmation; after OK the button says "Deleting…" and further clicks are ignored while the page reloads.
	$out .= '<form method="post" id="ws-cx-delete-form" class="ws-cx__delete-form" data-ws-confirm="' . esc_attr( __( 'Delete this for everyone? It moves to the Trash and can be restored from the WordPress admin.', 'ws-chapters' ) ) . '" data-ws-busy="' . esc_attr__( 'Deleting…', 'ws-chapters' ) . '">'
		. '<input type="hidden" name="ws_cx_form" value="delete"><input type="hidden" name="ws_cx_type" value="' . esc_attr( $type ) . '"><input type="hidden" name="ws_cx_id" value="' . $id . '"><input type="hidden" name="ws_cx_nonce" value="' . esc_attr( wp_create_nonce( 'ws_cx_delete_' . $type . '_' . $id ) ) . '">'
		. '</form>';
	$out .= '<script>(function(){var f=document.getElementById("ws-cx-delete-form");if(!f){return;}f.addEventListener("submit",function(e){if(f.getAttribute("data-busy")){e.preventDefault();e.stopImmediatePropagation();return;}if(!window.confirm(f.getAttribute("data-ws-confirm"))){e.preventDefault();e.stopImmediatePropagation();return;}f.setAttribute("data-busy","1");var b=document.querySelector("button[form=ws-cx-delete-form]");if(b){b.textContent=f.getAttribute("data-ws-busy");b.setAttribute("aria-disabled","true");b.style.cursor="progress";b.style.opacity=".7";}},true);})();</script>';
	return $out . '</section></div>';
} );

/* Edit screen page: under Members, created on install (member-management.php). */

/* News articles: the main image is shown at the top by the theme; the gallery follows the text as a 2 x 2 grid. */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$edit = ws_cx_edit_link( 'news', get_the_ID() );
	$ids = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( get_the_ID(), 'ws_news_gallery', true ) ) ) );
	if ( ! $ids ) {
		return $content . $edit;
	}
	$grid = '<div class="ws-news-gallery">';
	foreach ( array_slice( $ids, 0, 4 ) as $img ) {
		$grid .= '<a class="ws-news-gallery__item" href="' . esc_url( wp_get_attachment_url( $img ) ) . '" target="_blank" rel="noopener">' . wp_get_attachment_image( $img, 'large', false, array( 'class' => 'ws-news-gallery__img', 'alt' => '' ) ) . '</a>';
	}
	return $content . $grid . '</div>' . $edit;
}, 20 );
