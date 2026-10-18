<?php
/**
 * One-time repair for images whose file is missing from the uploads folder.
 *
 * For each image record without its file: look for the file in the other upload folders
 * (shared date folders and country folders) and point the record at it; if it is one of the
 * photos bundled with the plugin, copy that photo back. Country photos whose records are gone
 * entirely (Bulgaria's header photo and gallery) are imported again from the bundled copies.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/** Absolute paths of every file named $name in the uploads folder (one level of year/month or countries/{slug}). */
function ws_find_upload_file( $name ) {
	$base  = trailingslashit( wp_upload_dir( null, false )['basedir'] );
	$found = array_merge( (array) glob( $base . '*/*/' . $name ), (array) glob( $base . $name ) );
	return array_values( array_filter( $found, 'is_file' ) );
}

/** Copy a bundled plugin photo into the uploads folder for $slug (or the shared folder) and return the new image ID. */
function ws_import_bundled_photo( $file, $title, $slug = '' ) {
	$src = dirname( __DIR__ ) . '/playground/media/' . $file;
	$up  = wp_upload_dir( null, false );
	$dir = $slug ? trailingslashit( $up['basedir'] ) . 'countries/' . $slug : $up['path'];
	if ( ! is_file( $src ) || ! wp_mkdir_p( $dir ) ) {
		return 0;
	}
	$dest = $dir . '/' . wp_unique_filename( $dir, $file );
	if ( ! copy( $src, $dest ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $title, 'post_status' => 'inherit' ), $dest );
	if ( ! $id || is_wp_error( $id ) ) {
		return 0;
	}
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
	update_post_meta( $id, '_wp_attachment_image_alt', $title );
	return (int) $id;
}

/** Is this an image record whose file exists? */
function ws_image_ok( $id ) {
	$id = (int) $id;
	return $id && 'attachment' === get_post_type( $id ) && is_file( (string) get_attached_file( $id ) );
}

add_action( 'init', function () {
	if ( '1' === get_option( 'ws_media_repair_done' ) || ! post_type_exists( 'ws_chapter' ) || wp_doing_ajax() ) {
		return;
	}
	update_option( 'ws_media_repair_done', '1', false );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$log     = array();
	$bundled = dirname( __DIR__ ) . '/playground/media/';
	$up      = wp_upload_dir( null, false );

	// 1. Image records whose file is missing.
	$images = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $images as $id ) {
		$path = (string) get_attached_file( $id );
		if ( '' === $path || is_file( $path ) ) {
			continue;
		}
		$name  = basename( $path );
		$found = ws_find_upload_file( $name );
		if ( $found ) {
			$rel = ltrim( str_replace( wp_normalize_path( $up['basedir'] ), '', wp_normalize_path( $found[0] ) ), '/' );
			update_post_meta( $id, '_wp_attached_file', $rel );
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $found[0] ) );
			$log[ $id ] = 'relinked ' . $rel;
			continue;
		}
		$orig = preg_replace( '/-scaled(\.[a-z]+)$/i', '$1', $name );
		if ( is_file( $bundled . $orig ) && wp_mkdir_p( dirname( $path ) ) && copy( $bundled . $orig, dirname( $path ) . '/' . $orig ) ) {
			update_post_meta( $id, '_wp_attached_file', ltrim( str_replace( wp_normalize_path( $up['basedir'] ), '', wp_normalize_path( dirname( $path ) . '/' . $orig ) ), '/' ) );
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, dirname( $path ) . '/' . $orig ) );
			$log[ $id ] = 'restored from bundle';
			continue;
		}
		$log[ $id ] = 'missing';
	}

	// 2. Bulgaria's National Tour photos, if their records are gone.
	$titles = array(
		'tour-01-group-service-station.jpg' => 'National Tour 2025: the group at the start',
		'tour-02-church-blessing.jpg'       => 'National Tour 2025: blessing at the monastery',
		'tour-03-evening-dinner.jpg'        => 'National Tour 2025: evening gathering',
		'tour-04-three-men-bar.jpg'         => 'National Tour 2025: with friends on the road',
	);
	$bg = get_term_by( 'slug', 'bulgaria', 'ws_country' );
	if ( $bg ) {
		$hero    = (int) get_term_meta( $bg->term_id, 'hero_image', true );
		$gallery = array_filter( array_map( 'absint', explode( ',', (string) get_term_meta( $bg->term_id, 'gallery', true ) ) ) );
		$broken  = ( $hero && ! ws_image_ok( $hero ) ) || ( $gallery && ! array_filter( $gallery, 'ws_image_ok' ) );
		if ( $broken ) {
			$ids = array();
			foreach ( $titles as $file => $title ) {
				$ids[ $file ] = ws_import_bundled_photo( $file, $title, 'bulgaria' );
			}
			$ids = array_filter( $ids );
			if ( $ids ) {
				if ( $hero && ! ws_image_ok( $hero ) ) {
					update_term_meta( $bg->term_id, 'hero_image', $ids['tour-02-church-blessing.jpg'] ?? reset( $ids ) );
				}
				if ( $gallery && ! array_filter( $gallery, 'ws_image_ok' ) ) {
					update_term_meta( $bg->term_id, 'gallery', implode( ',', $ids ) );
				}
				$ch = get_page_by_path( 'bulgaria', OBJECT, 'ws_chapter' );
				if ( $ch ) {
					$thumb = (int) get_post_thumbnail_id( $ch );
					if ( $thumb && ! ws_image_ok( $thumb ) ) {
						set_post_thumbnail( $ch->ID, $ids['tour-04-three-men-bar.jpg'] ?? reset( $ids ) );
					}
					$cg = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $ch->ID, 'gallery', true ) ) ) );
					if ( $cg && ! array_filter( $cg, 'ws_image_ok' ) ) {
						update_post_meta( $ch->ID, 'gallery', implode( ',', $ids ) );
					}
				}
				$log['bulgaria'] = 'tour photos imported again: ' . implode( ',', $ids );
			}
		}
	}
	update_option( 'ws_media_repair_log', $log, false );
	do_action( 'litespeed_purge_all' );
}, 100 );
