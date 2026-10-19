<?php
/**
 * Clear the page cache when something visible is saved (logo, photos, contact details, pop-up text),
 * so the change shows straight away instead of after the cache expires.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

/** Meta keys whose change shows on the site. */
function ws_visible_meta_keys() {
	return array_merge(
		array( 'back_patch', 'chapter_logo', 'gallery', 'national_logo', 'hero_image', 'related_chapter' ),
		array_keys( ws_contact_fields() )
	);
}

/** Empty the LiteSpeed page cache (a no-op if the plugin is not active). Runs at most once per request. */
function ws_purge_visible_cache() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	// Clear the page cache when the request ends, so the save itself is not slowed down.
	add_action( 'shutdown', function () {
		do_action( 'litespeed_purge_all' );
	}, 1 );
}

// Theme changes: the logo and site icon from the Customizer, and the theme's own settings.
add_action( 'updated_option', function ( $option ) {
	if ( in_array( $option, array( 'site_logo', 'site_icon', 'blogname' ), true ) || 0 === strpos( (string) $option, 'theme_mods_' ) ) {
		ws_purge_visible_cache();
	}
}, 10, 1 );

// Chapter and event photos, contact details, galleries and country images.
$ws_purge_meta = function ( $meta_id, $object_id, $meta_key ) {
	if ( in_array( $meta_key, ws_visible_meta_keys(), true ) ) {
		ws_purge_visible_cache();
	}
};
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta' ) as $hook ) {
	add_action( $hook, $ws_purge_meta, 10, 3 );
}

// Featured images (photos shown at the top of a page) and the country / chapter pages themselves.
add_action( 'updated_post_meta', function ( $meta_id, $post_id, $meta_key ) {
	if ( '_thumbnail_id' === $meta_key ) {
		ws_purge_visible_cache();
	}
}, 10, 3 );
add_action( 'save_post', function () {
	ws_purge_visible_cache();
}, 99 );
add_action( 'edited_term', function () {
	ws_purge_visible_cache();
}, 99 );
