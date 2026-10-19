<?php
/**
 * Members area: a private document library for logged-in users.
 *
 * - Role "Member" (ws_member): can log in and use the members page; nothing else in the admin
 *   except their own profile (to change their password).
 * - Any logged-in user (Member, Chapter Editor, Country Admin, Editor, Administrator) can see the
 *   documents and upload new ones with a title, description and type.
 * - Files are kept outside the public media library, under uploads/ws-members/ with unguessable
 *   names, and are only served through a logged-in download link.
 * - Uploaders can delete their own documents; administrators can delete any.
 *
 * [ws_members_area]  The members page: login prompt for visitors; for members the library (documents,
 *                    meeting minutes, links, other — newest date first) and an "Upload a document"
 *                    button that opens the upload form (title, description, type, date, file or link).
 * A navigation link with the CSS class "ws-members-only" is only shown to logged-in users.
 */

defined( 'ABSPATH' ) || exit;

const WS_DOC_MAX_BYTES = 20 * MB_IN_BYTES;

function ws_doc_types() {
	return array(
		'document' => __( 'Document', 'ws-chapters' ),
		'minutes'  => __( 'Meeting minutes', 'ws-chapters' ),
		'link'     => __( 'Link', 'ws-chapters' ),
		'other'    => __( 'Other', 'ws-chapters' ),
	);
}

/** File types members may upload: extension => mime. */
function ws_doc_mimes() {
	return array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'  => 'application/vnd.ms-powerpoint',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		'txt'  => 'text/plain',
		'jpg|jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);
}

add_action( 'init', function () {
	register_post_type( 'ws_member_doc', array(
		'labels'          => array(
			'name'          => __( 'Member documents', 'ws-chapters' ),
			'singular_name' => __( 'Member document', 'ws-chapters' ),
			'all_items'     => __( 'Member documents', 'ws-chapters' ),
			'edit_item'     => __( 'Edit member document', 'ws-chapters' ),
			'search_items'  => __( 'Search member documents', 'ws-chapters' ),
			'not_found'     => __( 'No member documents yet.', 'ws-chapters' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'show_in_menu'    => true,
		'menu_icon'       => 'dashicons-lock',
		'menu_position'   => 26,
		'supports'        => array( 'title', 'author' ),
		'capability_type' => 'post',
		'capabilities'    => array(
			'create_posts'        => 'do_not_allow', // Added from the members page.
			'edit_posts'          => 'manage_options',
			'edit_others_posts'   => 'manage_options',
			'publish_posts'       => 'manage_options',
			'read_private_posts'  => 'manage_options',
			'delete_posts'        => 'manage_options',
			'delete_others_posts' => 'manage_options',
		),
		'map_meta_cap'    => true,
	) );
} );

/* -------------------------------------------------------------------------
 * The Member role
 * ---------------------------------------------------------------------- */

/** Create or refresh the Member role. Called from the one-time setup in ws-chapters.php. */
function ws_install_member_role() {
	remove_role( 'ws_member' );
	add_role( 'ws_member', __( 'Member', 'ws-chapters' ), array( 'read' => true ) );
}

/** Members land on the members page after logging in. */
add_filter( 'login_redirect', function ( $redirect, $requested, $user ) {
	if ( $user instanceof WP_User && in_array( 'ws_member', (array) $user->roles, true ) ) {
		$page = get_page_by_path( 'members' );
		if ( $page && ( ! $requested || false !== strpos( $requested, 'wp-admin' ) ) ) {
			return get_permalink( $page );
		}
	}
	return $redirect;
}, 10, 3 );

/** Members don't need the admin: only their profile (password), everything else goes to the members page. */
add_action( 'admin_init', function () {
	$user = wp_get_current_user();
	if ( ! in_array( 'ws_member', (array) $user->roles, true ) || wp_doing_ajax() ) {
		return;
	}
	global $pagenow;
	if ( 'profile.php' !== $pagenow ) {
		$page = get_page_by_path( 'members' );
		wp_safe_redirect( $page ? get_permalink( $page ) : home_url( '/' ) );
		exit;
	}
} );
add_filter( 'show_admin_bar', function ( $show ) {
	return in_array( 'ws_member', (array) wp_get_current_user()->roles, true ) ? false : $show;
} );

/* Menu links marked "ws-members-only" are only shown to logged-in users. */
add_filter( 'render_block_core/navigation-link', function ( $html, $block ) {
	$class = $block['attrs']['className'] ?? '';
	return false !== strpos( $class, 'ws-members-only' ) && ! is_user_logged_in() ? '' : $html;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Private storage
 * ---------------------------------------------------------------------- */

/** Absolute path of the members folder, created on first use with "no direct access" files. */
function ws_members_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'ws-members';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		// Apache. On nginx the random file names are what keeps files private (and downloads go through PHP).
		file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
	}
	return $dir;
}

/** Store an uploaded file ($_FILES entry). Returns array( path, name, mime, size ) or WP_Error. */
function ws_store_member_file( $file ) {
	if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
		$too_big = isset( $file['error'] ) && in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
		return new WP_Error( 'ws_doc_upload', $too_big ? __( 'The file is too large.', 'ws-chapters' ) : __( 'Please choose a file to upload.', 'ws-chapters' ) );
	}
	if ( (int) $file['size'] > WS_DOC_MAX_BYTES ) {
		/* translators: %s: maximum size */
		return new WP_Error( 'ws_doc_upload', sprintf( __( 'The file is too large (maximum %s).', 'ws-chapters' ), size_format( WS_DOC_MAX_BYTES ) ) );
	}
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], ws_doc_mimes() );
	if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
		return new WP_Error( 'ws_doc_upload', __( 'This type of file can\'t be uploaded. Use PDF, Word, Excel, PowerPoint, OpenDocument, text, JPG or PNG.', 'ws-chapters' ) );
	}
	$dir    = ws_members_dir();
	$stored = wp_generate_password( 24, false ) . '.' . $check['ext'];
	$moved  = is_uploaded_file( $file['tmp_name'] ) ? move_uploaded_file( $file['tmp_name'], $dir . '/' . $stored ) : apply_filters( 'ws_member_file_sideload', false, $file['tmp_name'], $dir . '/' . $stored );
	if ( ! $moved ) {
		return new WP_Error( 'ws_doc_upload', __( 'The file could not be saved. Please try again.', 'ws-chapters' ) );
	}
	return array(
		'path' => $stored,
		'name' => sanitize_file_name( wp_basename( $file['name'] ) ),
		'mime' => $check['type'],
		'size' => (int) $file['size'],
	);
}

/** Download link (only works for logged-in users). */
function ws_doc_download_url( $doc_id ) {
	return add_query_arg( array( 'ws_doc' => (int) $doc_id, '_wsn' => wp_create_nonce( 'ws_doc_' . $doc_id ) ), home_url( '/' ) );
}

/* Serve a document to logged-in users. */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['ws_doc'] ) ) {
		return;
	}
	$id = absint( $_GET['ws_doc'] );
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	if ( ! wp_verify_nonce( sanitize_key( $_GET['_wsn'] ?? '' ), 'ws_doc_' . $id ) || 'ws_member_doc' !== get_post_type( $id ) || 'private' !== get_post_status( $id ) ) {
		wp_die( esc_html__( 'This document link is not valid. Go back to the members page and try again.', 'ws-chapters' ), '', array( 'response' => 403 ) );
	}
	$path = ws_members_dir() . '/' . basename( (string) get_post_meta( $id, '_ws_file', true ) );
	if ( ! is_file( $path ) ) {
		wp_die( esc_html__( 'The file is missing.', 'ws-chapters' ), '', array( 'response' => 404 ) );
	}
	nocache_headers();
	header( 'Content-Type: ' . ( get_post_meta( $id, '_ws_mime', true ) ?: 'application/octet-stream' ) );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'Content-Disposition: attachment; filename="' . rawurlencode( get_post_meta( $id, '_ws_file_name', true ) ?: basename( $path ) ) . '"' );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
} );

/* Remove the stored file when a document is deleted. */
add_action( 'before_delete_post', function ( $post_id ) {
	if ( 'ws_member_doc' === get_post_type( $post_id ) ) {
		$file = basename( (string) get_post_meta( $post_id, '_ws_file', true ) );
		if ( $file && is_file( ws_members_dir() . '/' . $file ) ) {
			wp_delete_file( ws_members_dir() . '/' . $file );
		}
	}
} );

/* -------------------------------------------------------------------------
 * Members page
 * ---------------------------------------------------------------------- */

/** Whether the current user may delete this document. */
function ws_can_delete_doc( $doc ) {
	return current_user_can( 'manage_options' ) || ( is_user_logged_in() && (int) $doc->post_author === get_current_user_id() );
}

/** Handle the upload and delete actions; returns a notice array or null. */
function ws_members_handle_post() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_user_logged_in() ) {
		return null;
	}
	if ( isset( $_POST['ws_doc_delete'] ) ) {
		$id  = absint( $_POST['ws_doc_delete'] );
		$doc = get_post( $id );
		if ( ! $doc || 'ws_member_doc' !== $doc->post_type || ! wp_verify_nonce( sanitize_key( $_POST['ws_doc_nonce'] ?? '' ), 'ws_doc_delete_' . $id ) || ! ws_can_delete_doc( $doc ) ) {
			return array( 'error', __( 'That document could not be deleted.', 'ws-chapters' ) );
		}
		wp_delete_post( $id, true );
		/* translators: %s: document title */
		return array( 'success', sprintf( __( 'Deleted "%s".', 'ws-chapters' ), $doc->post_title ) );
	}
	if ( ! isset( $_POST['ws_doc_upload'] ) ) {
		return null;
	}
	if ( ! wp_verify_nonce( sanitize_key( $_POST['ws_doc_nonce'] ?? '' ), 'ws_doc_upload' ) ) {
		return array( 'error', __( 'The form expired. Please reload the page and try again.', 'ws-chapters' ) );
	}
	$title = ws_post_field( 'ws_doc_title' );
	$desc  = ws_post_field( 'ws_doc_description', true );
	$type  = ws_post_field( 'ws_doc_type' );
	$date  = ws_sanitize_date( ws_post_field( 'ws_doc_date' ) );
	$url   = esc_url_raw( ws_post_field( 'ws_doc_url' ), array( 'http', 'https' ) );
	$missing = array();
	if ( '' === $title ) {
		$missing[] = __( 'Title', 'ws-chapters' );
	}
	if ( '' === $desc ) {
		$missing[] = __( 'Description', 'ws-chapters' );
	}
	if ( ! isset( ws_doc_types()[ $type ] ) ) {
		$missing[] = __( 'Type', 'ws-chapters' );
	}
	if ( '' === $date ) {
		$missing[] = __( 'Date', 'ws-chapters' );
	}
	if ( 'link' === $type && ! wp_http_validate_url( $url ) ) {
		$missing[] = __( 'Link (a web address starting with https://)', 'ws-chapters' );
	}
	if ( $missing ) {
		return array( 'error', __( 'Please fill in:', 'ws-chapters' ) . ' ' . implode( ', ', $missing ) . '.' );
	}
	$stored = null;
	if ( 'link' !== $type ) {
		$stored = ws_store_member_file( $_FILES['ws_doc_file'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( is_wp_error( $stored ) ) {
			return array( 'error', $stored->get_error_message() );
		}
	}
	$id = wp_insert_post( array(
		'post_type'    => 'ws_member_doc',
		'post_status'  => 'private',
		'post_title'   => $title,
		'post_content' => $desc,
		'post_author'  => get_current_user_id(),
	) );
	if ( ! $id || is_wp_error( $id ) ) {
		if ( $stored ) {
			wp_delete_file( ws_members_dir() . '/' . $stored['path'] );
		}
		return array( 'error', __( 'The document could not be saved. Please try again.', 'ws-chapters' ) );
	}
	if ( $stored ) {
		update_post_meta( $id, '_ws_file', $stored['path'] );
		update_post_meta( $id, '_ws_file_name', $stored['name'] );
		update_post_meta( $id, '_ws_mime', $stored['mime'] );
		update_post_meta( $id, '_ws_size', $stored['size'] );
	} else {
		update_post_meta( $id, '_ws_url', $url );
	}
	update_post_meta( $id, '_ws_doc_type', $type );
	update_post_meta( $id, '_ws_doc_date', $date );
	/* translators: %s: document title */
	return array( 'success', sprintf( __( 'Added "%s". It is now visible to all members.', 'ws-chapters' ), $title ) );
}

/** One library entry: a table row. */
function ws_doc_item( $doc ) {
	$types  = ws_doc_types();
	$type   = get_post_meta( $doc->ID, '_ws_doc_type', true );
	$url    = get_post_meta( $doc->ID, '_ws_url', true );
	$name   = get_post_meta( $doc->ID, '_ws_file_name', true );
	$date   = get_post_meta( $doc->ID, '_ws_doc_date', true ) ?: get_the_date( 'Y-m-d', $doc );
	$author = get_userdata( (int) $doc->post_author );
	$is_url = (bool) $url;
	$href   = $is_url ? $url : ws_doc_download_url( $doc->ID );
	$target = $is_url ? ' target="_blank" rel="noopener"' : '';
	$badge  = $is_url ? __( 'LINK', 'ws-chapters' ) : ( strtoupper( pathinfo( (string) $name, PATHINFO_EXTENSION ) ) ?: 'FILE' );
	$extra  = $is_url ? preg_replace( '#^www\.#', '', (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: $url ) ) : size_format( (int) get_post_meta( $doc->ID, '_ws_size', true ) );
	$delete = ws_cx_edit_link( 'doc', $doc->ID ); // Pencil "Edit"; delete is on the edit screen.
	return '<tr class="ws-doc--' . esc_attr( $type ) . '">'
		. '<td class="ws-doc__when"><time datetime="' . esc_attr( $date ) . '">' . esc_html( date_i18n( 'j M Y', strtotime( $date ) ) ) . '</time></td>'
		. '<td><div class="ws-doc__cell"><span class="ws-doc__icon" aria-hidden="true">' . esc_html( $badge ) . '</span><span><a class="ws-doc__title" href="' . esc_url( $href ) . '"' . $target . '>' . esc_html( $doc->post_title ) . '</a>'
		. ( '' !== trim( $doc->post_content ) ? '<span class="ws-doc__desc">' . nl2br( esc_html( $doc->post_content ) ) . '</span>' : '' ) . '</span></div></td>'
		. '<td data-label="' . esc_attr__( 'Type', 'ws-chapters' ) . '"><span class="ws-doc__type">' . esc_html( $types[ $type ] ?? '' ) . '</span></td>'
		. '<td data-label="' . esc_attr__( 'Added by', 'ws-chapters' ) . '">' . esc_html( $author ? ws_mm_person_name( $author ) : '—' ) . '</td>'
		. '<td data-label="' . esc_attr__( 'Size', 'ws-chapters' ) . '" class="ws-muted">' . esc_html( $extra ) . '</td>'
		. '<td><div class="ws-mm__row-actions"><a class="ws-doc-icon-btn" href="' . esc_url( $href ) . '"' . $target . ' aria-label="' . esc_attr( $is_url ? __( 'Open link', 'ws-chapters' ) : __( 'Download', 'ws-chapters' ) ) . '" title="' . esc_attr( $is_url ? __( 'Open link', 'ws-chapters' ) : __( 'Download', 'ws-chapters' ) ) . '">' . ( $is_url ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v6H4V6h6"/></svg>' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>' ) . '</a>' . $delete . '</div></td>'
		. '</tr>';
}

function ws_members_area() {
	$nav = is_user_logged_in() ? ws_mm_subnav( 'library', true ) : '';
	return $nav . ws_members_area_body();
}

function ws_members_area_body() {
	if ( ! is_user_logged_in() ) {
		return '<div class="ws-members-locked"><h2>' . esc_html__( 'Members only', 'ws-chapters' ) . '</h2><p>' . esc_html__( 'This area is for Widows Sons members. Please log in with your email address and password.', 'ws-chapters' ) . '</p><p><a class="ws-btn-gold" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'ws-chapters' ) . '</a></p></div>';
	}
	$notice = ws_members_handle_post();
	$types  = ws_doc_types();
	// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
	$filter = isset( $_GET['type'] ) && isset( $types[ $_GET['type'] ] ) ? sanitize_key( $_GET['type'] ) : '';
	$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	// phpcs:enable
	$failed = $notice && 'error' === $notice[0] && isset( $_POST['ws_doc_upload'] ); // phpcs:ignore WordPress.Security.NonceVerification

	$out = '<div class="ws-mm alignwide ws-members">';
	if ( $notice ) {
		$out .= ws_form_notice( $notice[0], esc_html( $notice[1] ) );
	}

	// 1. The library.
	$args = array( 'post_type' => 'ws_member_doc', 'post_status' => 'private', 'posts_per_page' => 500 );
	if ( $filter ) {
		$args['meta_query'] = array( array( 'key' => '_ws_doc_type', 'value' => $filter ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	$docs = get_posts( $args );
	if ( '' !== $search ) {
		$docs = array_values( array_filter( $docs, function ( $d ) use ( $search ) {
			return false !== mb_stripos( $d->post_title . ' ' . $d->post_content, $search );
		} ) );
	}
	// Newest document date first (falls back to when it was added).
	usort( $docs, function ( $a, $b ) {
		$da = ( get_post_meta( $a->ID, '_ws_doc_date', true ) ?: get_the_date( 'Y-m-d', $a ) ) . $a->post_date;
		$db = ( get_post_meta( $b->ID, '_ws_doc_date', true ) ?: get_the_date( 'Y-m-d', $b ) ) . $b->post_date;
		return strcmp( $db, $da );
	} );
	$type_select = '<select name="type" class="ws-mm__input"><option value="">' . esc_html__( 'All types', 'ws-chapters' ) . '</option>';
	foreach ( $types as $k => $label ) {
		$type_select .= '<option value="' . esc_attr( $k ) . '"' . selected( $filter, $k, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$type_select .= '</select>';
	$out .= '<form method="get" class="ws-mm__bar ws-library-bar">'
		. ws_mm_field( __( 'Search', 'ws-chapters' ), '<input type="search" name="q" class="ws-mm__input" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Title or description', 'ws-chapters' ) . '">', 'ws-mm__field--wide' )
		. ws_mm_field( __( 'Type', 'ws-chapters' ), $type_select )
		. '<div class="ws-mm__actions"><button type="submit" class="ws-mm__btn">' . esc_html__( 'Filter', 'ws-chapters' ) . '</button>'
		. ( $filter || '' !== $search ? '<a class="ws-mm__clear" href="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Clear', 'ws-chapters' ) . '</a>' : '' ) . '</div>'
		. '<div class="ws-mm__actions ws-library-bar__upload"><a class="ws-mm__btn ws-mm__btn--ghost ws-upload-toggle" href="#ws-doc-upload" data-ws-upload-toggle>' . esc_html__( 'Upload a document', 'ws-chapters' ) . '</a></div></form>';
	/* translators: %d: number of documents */
	$out .= '<p class="ws-mm__count">' . esc_html( sprintf( _n( '%d document', '%d documents', count( $docs ), 'ws-chapters' ), count( $docs ) ) ) . '</p>';
	$out .= '<div class="ws-mm__scroll"><table class="ws-mm__table ws-mm__table--library"><thead><tr><th>' . esc_html__( 'Date', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Document', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Type', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Added by', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Size', 'ws-chapters' ) . '</th><th>' . esc_html__( 'Actions', 'ws-chapters' ) . '</th></tr></thead><tbody>'
		. ( $docs ? implode( '', array_map( 'ws_doc_item', $docs ) ) : '<tr><td colspan="6" class="ws-mm__empty">' . esc_html__( 'No documents yet.', 'ws-chapters' ) . '</td></tr>' )
		. '</tbody></table></div>';

	// 2. The upload form, opened by the button (and kept open after an error).
	$v    = function ( $k ) use ( $failed ) {
		return $failed ? ws_post_field( $k, 'ws_doc_description' === $k ) : '';
	};
	$opts = '<option value="">' . esc_html__( '— Choose —', 'ws-chapters' ) . '</option>';
	foreach ( $types as $k => $label ) {
		$opts .= '<option value="' . esc_attr( $k ) . '"' . selected( $v( 'ws_doc_type' ), $k, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$out .= '<details class="ws-upload ws-mm__card" id="ws-doc-upload"' . ( $failed ? ' open' : '' ) . '><summary>' . esc_html__( 'Upload a document', 'ws-chapters' ) . '</summary>';
	$out .= '<form class="ws-form ws-doc-upload" method="post" enctype="multipart/form-data" action="#ws-doc-upload" data-ws-doc-form><fieldset><legend>' . esc_html__( 'Upload a document', 'ws-chapters' ) . '</legend>';
	$out .= '<input type="hidden" name="ws_doc_upload" value="1"><input type="hidden" name="ws_doc_nonce" value="' . esc_attr( wp_create_nonce( 'ws_doc_upload' ) ) . '">';
	$out .= ws_field( 'ws_doc_title', __( 'Title', 'ws-chapters' ), 'text', array( 'required' => true, 'value' => $v( 'ws_doc_title' ) ) );
	$out .= ws_field( 'ws_doc_description', __( 'Description', 'ws-chapters' ), 'textarea', array( 'required' => true, 'value' => $v( 'ws_doc_description' ), 'attrs' => 'rows="3"', 'help' => __( 'What is in the document, so members can find it.', 'ws-chapters' ) ) );
	$out .= '<div class="ws-field-row">';
	$out .= ws_field( 'ws_doc_type', __( 'Type', 'ws-chapters' ), 'select', array( 'required' => true, 'options' => $opts, 'attrs' => 'data-ws-doc-type' ) );
	$out .= ws_field( 'ws_doc_date', __( 'Date', 'ws-chapters' ), 'date', array( 'required' => true, 'value' => $v( 'ws_doc_date' ) ?: current_time( 'Y-m-d' ), 'help' => __( 'E.g. the date of the meeting or of the document.', 'ws-chapters' ) ) );
	$out .= '</div>';
	$out .= '<div data-ws-doc-file>' . ws_field( 'ws_doc_file', __( 'File', 'ws-chapters' ), 'file', array(
		'attrs' => 'accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.txt,.jpg,.jpeg,.png"',
		/* translators: %s: maximum size */
		'help'  => sprintf( __( 'PDF, Word, Excel, PowerPoint, OpenDocument, text, JPG or PNG, up to %s.', 'ws-chapters' ), size_format( WS_DOC_MAX_BYTES ) ),
	) ) . '</div>';
	$out .= '<div data-ws-doc-url>' . ws_field( 'ws_doc_url', __( 'Link', 'ws-chapters' ), 'url', array( 'value' => $v( 'ws_doc_url' ), 'attrs' => 'placeholder="https://"', 'help' => __( 'For the Link type: the web address (e.g. a shared folder or an online document).', 'ws-chapters' ) ) ) . '</div>';
	$out .= '</fieldset><p><button type="submit" class="ws-btn-gold">' . esc_html__( 'Add to the library', 'ws-chapters' ) . '</button></p></form></details>';

	wp_enqueue_script( 'ws-members', plugin_dir_url( __DIR__ ) . 'assets/ws-members.js', array(), filemtime( plugin_dir_path( __DIR__ ) . 'assets/ws-members.js' ), true );
	return $out . '</div>';
}

add_action( 'init', function () {
	add_shortcode( 'ws_members_area', 'ws_members_area' );
} );

/* Admin list: type, file and uploader columns. */
add_filter( 'manage_ws_member_doc_posts_columns', function ( $cols ) {
	return array_slice( $cols, 0, 2 ) + array( 'ws_type' => __( 'Type', 'ws-chapters' ), 'ws_file' => __( 'File or link', 'ws-chapters' ), 'ws_date' => __( 'Document date', 'ws-chapters' ) ) + $cols;
} );
add_action( 'manage_ws_member_doc_posts_custom_column', function ( $col, $id ) {
	if ( 'ws_date' === $col ) {
		$d = get_post_meta( $id, '_ws_doc_date', true );
		echo esc_html( $d ? date_i18n( 'j F Y', strtotime( $d ) ) : '' );
	} elseif ( 'ws_type' === $col ) {
		echo esc_html( ws_doc_types()[ get_post_meta( $id, '_ws_doc_type', true ) ] ?? '' );
	} elseif ( 'ws_file' === $col ) {
		$url = get_post_meta( $id, '_ws_url', true );
		echo $url
			? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>'
			: '<a href="' . esc_url( ws_doc_download_url( $id ) ) . '">' . esc_html( get_post_meta( $id, '_ws_file_name', true ) ) . '</a>';
	}
}, 10, 2 );

/* Never show members' documents to search engines or in site search. */
add_filter( 'wp_sitemaps_post_types', function ( $types ) {
	unset( $types['ws_member_doc'] );
	return $types;
} );
