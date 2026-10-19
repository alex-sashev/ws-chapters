<?php
/**
 * Country folders for uploads.
 *
 * Files uploaded for a chapter, event, news post or country page are stored in
 * uploads/countries/{country}/ instead of the shared uploads/{year}/{month}/ folders.
 * Site-wide images (home page, logos) keep the WordPress default.
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
