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
 * [ws_members_area]  The members page: login prompt for visitors, library + upload form for members.
 * A navigation link with the CSS class "ws-members-only" is only shown to logged-in users.
 */

defined( 'ABSPATH' ) || exit;

const WS_DOC_MAX_BYTES = 20 * MB_IN_BYTES;

function ws_doc_types() {
	return array(
		'minutes'  => __( 'Meeting minutes', 'ws-chapters' ),
		'document' => __( 'Document', 'ws-chapters' ),
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
	if ( $missing ) {
		return array( 'error', __( 'Please fill in:', 'ws-chapters' ) . ' ' . implode( ', ', $missing ) . '.' );
	}
	$stored = ws_store_member_file( $_FILES['ws_doc_file'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( is_wp_error( $stored ) ) {
		return array( 'error', $stored->get_error_message() );
	}
	$id = wp_insert_post( array(
		'post_type'    => 'ws_member_doc',
		'post_status'  => 'private',
		'post_title'   => $title,
		'post_content' => $desc,
		'post_author'  => get_current_user_id(),
	) );
	if ( ! $id || is_wp_error( $id ) ) {
		wp_delete_file( ws_members_dir() . '/' . $stored['path'] );
		return array( 'error', __( 'The document could not be saved. Please try again.', 'ws-chapters' ) );
	}
	update_post_meta( $id, '_ws_file', $stored['path'] );
	update_post_meta( $id, '_ws_file_name', $stored['name'] );
	update_post_meta( $id, '_ws_mime', $stored['mime'] );
	update_post_meta( $id, '_ws_size', $stored['size'] );
	update_post_meta( $id, '_ws_doc_type', $type );
	/* translators: %s: document title */
	return array( 'success', sprintf( __( 'Uploaded "%s". It is now visible to all members.', 'ws-chapters' ), $title ) );
}

function ws_members_area() {
	if ( ! is_user_logged_in() ) {
		return '<div class="ws-members-locked"><h2>' . esc_html__( 'Members only', 'ws-chapters' ) . '</h2><p>' . esc_html__( 'This area is for Widows Sons members. Please log in with your email address and password.', 'ws-chapters' ) . '</p><p><a class="ws-btn-gold" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'ws-chapters' ) . '</a></p></div>';
	}
	$notice = ws_members_handle_post();
	$types  = ws_doc_types();
	$filter = isset( $_GET['type'] ) && isset( $types[ $_GET['type'] ] ) ? sanitize_key( $_GET['type'] ) : '';
	$user   = wp_get_current_user();

	$out  = '<div class="ws-members">';
	$out .= '<p class="ws-members__hello">' . sprintf(
		/* translators: %s: road name */
		esc_html__( 'Welcome, %s.', 'ws-chapters' ),
		'<strong>' . esc_html( $user->display_name ) . '</strong>'
	) . ' <a href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '">' . esc_html__( 'Log out', 'ws-chapters' ) . '</a></p>';
	if ( $notice ) {
		$out .= ws_form_notice( $notice[0], esc_html( $notice[1] ) );
	}

	// Library.
	$args = array( 'post_type' => 'ws_member_doc', 'post_status' => 'private', 'posts_per_page' => 200, 'orderby' => 'date', 'order' => 'DESC' );
	if ( $filter ) {
		$args['meta_query'] = array( array( 'key' => '_ws_doc_type', 'value' => $filter ) );
	}
	$docs  = get_posts( $args );
	$base  = remove_query_arg( 'type' );
	$out  .= '<div class="ws-section-head"><h2>' . esc_html__( 'Documents', 'ws-chapters' ) . '</h2><a href="#ws-doc-upload">' . esc_html__( 'Upload a document ↓', 'ws-chapters' ) . '</a></div>';
	$out  .= '<nav class="ws-doc-filter" aria-label="' . esc_attr__( 'Document types', 'ws-chapters' ) . '"><a href="' . esc_url( $base ) . '"' . ( $filter ? '' : ' aria-current="true"' ) . '>' . esc_html__( 'All', 'ws-chapters' ) . '</a>';
	foreach ( $types as $k => $label ) {
		$out .= '<a href="' . esc_url( add_query_arg( 'type', $k, $base ) ) . '"' . ( $filter === $k ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
	}
	$out .= '</nav>';
	if ( ! $docs ) {
		$out .= '<p class="ws-muted">' . esc_html__( 'No documents yet.', 'ws-chapters' ) . '</p>';
	} else {
		$out .= '<ul class="ws-doc-list">';
		foreach ( $docs as $doc ) {
			$type   = get_post_meta( $doc->ID, '_ws_doc_type', true );
			$name   = get_post_meta( $doc->ID, '_ws_file_name', true );
			$ext    = strtoupper( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
			$author = get_userdata( (int) $doc->post_author );
			$delete = ws_can_delete_doc( $doc ) ? sprintf(
				'<form method="post" class="ws-doc__delete" onsubmit="return confirm(%1$s)"><input type="hidden" name="ws_doc_delete" value="%2$d"><input type="hidden" name="ws_doc_nonce" value="%3$s"><button type="submit">%4$s</button></form>',
				esc_attr( wp_json_encode( __( 'Delete this document for everyone?', 'ws-chapters' ) ) ),
				$doc->ID,
				esc_attr( wp_create_nonce( 'ws_doc_delete_' . $doc->ID ) ),
				esc_html__( 'Delete', 'ws-chapters' )
			) : '';
			$out .= sprintf(
				'<li class="ws-doc"><div class="ws-doc__icon" aria-hidden="true">%1$s</div><div class="ws-doc__body"><h3 class="ws-doc__title"><a href="%2$s">%3$s</a></h3><p class="ws-doc__desc">%4$s</p><p class="ws-doc__meta"><span class="ws-doc__type">%5$s</span> · %6$s · %7$s%8$s</p></div><div class="ws-doc__actions"><a class="ws-btn-outline" href="%2$s">%9$s</a>%10$s</div></li>',
				esc_html( $ext ?: 'FILE' ),
				esc_url( ws_doc_download_url( $doc->ID ) ),
				esc_html( $doc->post_title ),
				nl2br( esc_html( $doc->post_content ) ),
				esc_html( $types[ $type ] ?? '' ),
				/* translators: 1: road name, 2: date */
				esc_html( sprintf( __( 'Uploaded by %1$s on %2$s', 'ws-chapters' ), $author ? $author->display_name : '—', get_the_date( 'j F Y', $doc ) ) ),
				esc_html( size_format( (int) get_post_meta( $doc->ID, '_ws_size', true ) ) ),
				'',
				esc_html__( 'Download', 'ws-chapters' ),
				$delete
			);
		}
		$out .= '</ul>';
	}

	// Upload form.
	$opts = '<option value="">' . esc_html__( '— Choose —', 'ws-chapters' ) . '</option>';
	foreach ( $types as $k => $label ) {
		$opts .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
	}
	$out .= '<form class="ws-form ws-doc-upload" id="ws-doc-upload" method="post" enctype="multipart/form-data" action="#ws-doc-upload"><fieldset><legend>' . esc_html__( 'Upload a document', 'ws-chapters' ) . '</legend>';
	$out .= '<input type="hidden" name="ws_doc_upload" value="1"><input type="hidden" name="ws_doc_nonce" value="' . esc_attr( wp_create_nonce( 'ws_doc_upload' ) ) . '">';
	$out .= ws_field( 'ws_doc_title', __( 'Title', 'ws-chapters' ), 'text', array( 'required' => true ) );
	$out .= ws_field( 'ws_doc_description', __( 'Description', 'ws-chapters' ), 'textarea', array( 'required' => true, 'attrs' => 'rows="3"', 'help' => __( 'What is in the document, so members can find it.', 'ws-chapters' ) ) );
	$out .= '<div class="ws-field-row">';
	$out .= ws_field( 'ws_doc_type', __( 'Type', 'ws-chapters' ), 'select', array( 'required' => true, 'options' => $opts ) );
	$out .= ws_field( 'ws_doc_file', __( 'File', 'ws-chapters' ), 'file', array(
		'required' => true,
		'attrs'    => 'accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.txt,.jpg,.jpeg,.png"',
		/* translators: %s: maximum size */
		'help'     => sprintf( __( 'PDF, Word, Excel, PowerPoint, OpenDocument, text, JPG or PNG, up to %s.', 'ws-chapters' ), size_format( WS_DOC_MAX_BYTES ) ),
	) );
	$out .= '</div></fieldset><p><button type="submit" class="ws-btn-gold">' . esc_html__( 'Upload', 'ws-chapters' ) . '</button></p></form>';
	return $out . '</div>';
}

add_action( 'init', function () {
	add_shortcode( 'ws_members_area', 'ws_members_area' );
} );

/* Admin list: type, file and uploader columns. */
add_filter( 'manage_ws_member_doc_posts_columns', function ( $cols ) {
	return array_slice( $cols, 0, 2 ) + array( 'ws_type' => __( 'Type', 'ws-chapters' ), 'ws_file' => __( 'File', 'ws-chapters' ) ) + $cols;
} );
add_action( 'manage_ws_member_doc_posts_custom_column', function ( $col, $id ) {
	if ( 'ws_type' === $col ) {
		echo esc_html( ws_doc_types()[ get_post_meta( $id, '_ws_doc_type', true ) ] ?? '' );
	} elseif ( 'ws_file' === $col ) {
		echo '<a href="' . esc_url( ws_doc_download_url( $id ) ) . '">' . esc_html( get_post_meta( $id, '_ws_file_name', true ) ) . '</a>';
	}
}, 10, 2 );

/* Never show members' documents to search engines or in site search. */
add_filter( 'wp_sitemaps_post_types', function ( $types ) {
	unset( $types['ws_member_doc'] );
	return $types;
} );
