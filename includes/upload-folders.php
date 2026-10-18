<?php
/**
 * Country folders for uploads.
 *
 * Files uploaded for a chapter, event, news post or country page are stored in
 * uploads/countries/{country}/ instead of the shared uploads/{year}/{month}/ folders.
 * Site-wide images (home page, logos) keep the WordPress default.
 * Files uploaded before this existed are moved into their country's folder once.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/** Country slug for a chapter, event or news post (its first country), or ''. */
function ws_post_country_slug( $post_id ) {
	$post = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || ! in_array( $post->post_type, array( 'ws_chapter', 'ws_event', 'post' ), true ) ) {
		return '';
	}
	$terms = get_the_terms( $post, 'ws_country' );
	return $terms && ! is_wp_error( $terms ) ? $terms[0]->slug : '';
}

/** Country of the upload in progress: the post it is uploaded to, or the country page it is uploaded from. */
function ws_upload_country_slug() {
	// phpcs:disable WordPress.Security.NonceVerification -- only picks a folder; the upload itself is checked by WordPress.
	if ( ! empty( $_REQUEST['ws_country'] ) ) {
		$term = get_term( absint( $_REQUEST['ws_country'] ), 'ws_country' );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->slug;
		}
	}
	if ( ! empty( $_REQUEST['post_id'] ) ) {
		return ws_post_country_slug( absint( $_REQUEST['post_id'] ) );
	}
	// phpcs:enable
	return '';
}

add_filter( 'upload_dir', function ( $dirs ) {
	$slug = ws_upload_country_slug();
	if ( '' === $slug ) {
		return $dirs;
	}
	$sub            = '/countries/' . $slug;
	$dirs['subdir'] = $sub;
	$dirs['path']   = $dirs['basedir'] . $sub;
	$dirs['url']    = $dirs['baseurl'] . $sub;
	return $dirs;
} );

/* On a country's edit screen, tell the media uploader which country the files are for. */
add_action( 'admin_enqueue_scripts', function () {
	$screen = get_current_screen();
	if ( ! $screen || 'ws_country' !== $screen->taxonomy || empty( $_GET['tag_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$term_id = absint( $_GET['tag_ID'] ); // phpcs:ignore WordPress.Security.NonceVerification
	wp_add_inline_script( 'media-editor', 'jQuery(function(){ if (window.wp && wp.Uploader && wp.Uploader.defaults) { wp.Uploader.defaults.multipart_params = wp.Uploader.defaults.multipart_params || {}; wp.Uploader.defaults.multipart_params.ws_country = ' . $term_id . '; } });' );
}, 20 );

/* -------------------------------------------------------------------------
 * One-time move of existing files
 * ---------------------------------------------------------------------- */

/** Map of attachment ID => list of country slugs it belongs to. */
function ws_attachment_countries() {
	$map = array();
	$add = function ( $ids, $slug ) use ( &$map ) {
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id && '' !== $slug ) {
				$map[ $id ][ $slug ] = true;
			}
		}
	};
	$ids_of = function ( $value ) {
		return array_filter( array_map( 'absint', explode( ',', (string) $value ) ) );
	};

	foreach ( get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false ) ) as $term ) {
		$add( array( get_term_meta( $term->term_id, 'national_logo', true ), get_term_meta( $term->term_id, 'hero_image', true ) ), $term->slug );
		$add( $ids_of( get_term_meta( $term->term_id, 'gallery', true ) ), $term->slug );
	}
	$posts = get_posts( array( 'post_type' => array( 'ws_chapter', 'ws_event', 'post' ), 'post_status' => 'any', 'numberposts' => -1 ) );
	foreach ( $posts as $post ) {
		$slug = ws_post_country_slug( $post->ID );
		if ( '' === $slug ) {
			continue;
		}
		$add( array( get_post_thumbnail_id( $post ), get_post_meta( $post->ID, 'back_patch', true ), get_post_meta( $post->ID, 'chapter_logo', true ) ), $slug );
		$add( $ids_of( get_post_meta( $post->ID, 'gallery', true ) ), $slug );
		$add( get_posts( array( 'post_type' => 'attachment', 'post_parent' => $post->ID, 'fields' => 'ids', 'numberposts' => -1 ) ), $slug );
	}
	return array_map( 'array_keys', $map );
}

/**
 * Move one attachment (with its resized copies) into uploads/countries/{slug}/ and update
 * WordPress's records and any links to it in page content. Returns true when moved.
 */
function ws_move_attachment_to_country( $id, $slug ) {
	$up   = wp_upload_dir( null, false );
	$rel  = (string) get_post_meta( $id, '_wp_attached_file', true );
	$sub  = 'countries/' . $slug;
	if ( '' === $rel || 0 === strpos( $rel, $sub . '/' ) || preg_match( '#^(https?:)?//#', $rel ) ) {
		return false;
	}
	$old_dir = trailingslashit( $up['basedir'] ) . ( '.' === dirname( $rel ) ? '' : dirname( $rel ) . '/' );
	$new_dir = trailingslashit( $up['basedir'] ) . $sub . '/';
	$meta    = wp_get_attachment_metadata( $id );
	$names   = array( basename( $rel ) );
	if ( is_array( $meta ) ) {
		foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$names[] = $size['file'];
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$names[] = $meta['original_image'];
		}
	}
	$names = array_unique( $names );
	if ( ! file_exists( $old_dir . $names[0] ) || ! wp_mkdir_p( $new_dir ) ) {
		return false;
	}
	foreach ( $names as $name ) {
		if ( file_exists( $new_dir . $name ) ) {
			return false; // Name already taken in the country folder: leave this file where it is.
		}
	}
	$moved = array();
	foreach ( $names as $name ) {
		if ( ! file_exists( $old_dir . $name ) ) {
			continue;
		}
		if ( ! @rename( $old_dir . $name, $new_dir . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			foreach ( $moved as $back ) {
				@rename( $new_dir . $back, $old_dir . $back ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			return false;
		}
		$moved[] = $name;
	}

	update_post_meta( $id, '_wp_attached_file', $sub . '/' . $names[0] );
	if ( is_array( $meta ) ) {
		$meta['file'] = $sub . '/' . $names[0];
		wp_update_attachment_metadata( $id, $meta );
	}

	// Links to the file (or its resized copies) inside page and post content.
	global $wpdb;
	$old_url = trailingslashit( $up['baseurl'] ) . ( '.' === dirname( $rel ) ? '' : dirname( $rel ) . '/' );
	$new_url = trailingslashit( $up['baseurl'] ) . $sub . '/';
	foreach ( $moved as $name ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", $old_url . $name, $new_url . $name, '%' . $wpdb->esc_like( $old_url . $name ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	clean_post_cache( $id );
	return true;
}

/* Runs once (version-stamped). Files shared by several countries stay in the shared folder. */
add_action( 'init', function () {
	if ( '1' === get_option( 'ws_upload_folders_done' ) || ! post_type_exists( 'ws_chapter' ) || wp_doing_ajax() ) {
		return;
	}
	update_option( 'ws_upload_folders_done', '1', false ); // Set first, so a slow or failed run is never repeated on every page load.
	$log = array();
	foreach ( ws_attachment_countries() as $id => $slugs ) {
		if ( 1 === count( $slugs ) && 'attachment' === get_post_type( $id ) ) {
			$log[ $id ] = ws_move_attachment_to_country( $id, $slugs[0] ) ? $slugs[0] : 'kept';
		} else {
			$log[ $id ] = 'shared';
		}
	}
	update_option( 'ws_upload_folders_log', $log, false );
	do_action( 'litespeed_purge_all' ); // Page cache may still point at the old file addresses.
}, 99 );
