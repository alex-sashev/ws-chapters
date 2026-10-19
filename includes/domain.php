<?php
/**
 * Main address: wseurope.org. The older widowssonseurope.org (and www. forms) redirect to it.
 *
 * The switch happens once, on the first visit to https://wseurope.org (so it only happens when that
 * address already works over HTTPS): the WordPress address moves to https://wseurope.org and links
 * stored in pages and settings are updated. Email addresses (info@widowssonseurope.org) stay as they are.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

const WS_MAIN_HOST = 'wseurope.org';
const WS_OLD_HOST  = 'widowssonseurope.org';

/** Host of the current request, lower case, without port. */
function ws_request_host() {
	return strtolower( preg_replace( '/:\d+$/', '', (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
}

/** Replace old-address links in a value that may be serialized. */
function ws_replace_old_links( $value ) {
	$from = array( 'https://' . WS_OLD_HOST, 'http://' . WS_OLD_HOST, 'https://www.' . WS_OLD_HOST, 'http://www.' . WS_OLD_HOST, '\/\/' . WS_OLD_HOST );
	$to   = array( 'https://' . WS_MAIN_HOST, 'https://' . WS_MAIN_HOST, 'https://' . WS_MAIN_HOST, 'https://' . WS_MAIN_HOST, '\/\/' . WS_MAIN_HOST );
	if ( is_string( $value ) ) {
		$data = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		if ( false !== $data || 'b:0;' === $value ) {
			return serialize( ws_replace_old_links( $data ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		return str_replace( $from, $to, $value );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = ws_replace_old_links( $v );
		}
	}
	return $value;
}

/* The one-time switch, on the first HTTPS visit to the new address. */
add_action( 'init', function () {
	if ( WS_MAIN_HOST !== ws_request_host() || ! is_ssl() || WS_OLD_HOST !== wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) ) {
		return;
	}
	global $wpdb;
	$like = '%' . $wpdb->esc_like( WS_OLD_HOST ) . '%';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s", $like ) ) as $row ) {
		$new = ws_replace_old_links( $row->post_content );
		if ( $new !== $row->post_content ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => $row->ID ) );
			clean_post_cache( (int) $row->ID );
		}
	}
	foreach ( array( array( $wpdb->postmeta, 'meta_id' ), array( $wpdb->termmeta, 'meta_id' ), array( $wpdb->usermeta, 'umeta_id' ) ) as list( $table, $key ) ) {
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT {$key} AS id, meta_value FROM {$table} WHERE meta_value LIKE %s", $like ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$new = ws_replace_old_links( $row->meta_value );
			if ( $new !== $row->meta_value ) {
				$wpdb->update( $table, array( 'meta_value' => $new ), array( $key => $row->id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			}
		}
	}
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT IN ('home','siteurl')", $like ) ) as $row ) {
		$new = ws_replace_old_links( $row->option_value );
		if ( $new !== $row->option_value ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $new ), array( 'option_name' => $row->option_name ) );
		}
	}
	// phpcs:enable
	update_option( 'home', 'https://' . WS_MAIN_HOST );
	update_option( 'siteurl', 'https://' . WS_MAIN_HOST );
	update_option( 'ws_domain_switched', gmdate( 'c' ), false );
	wp_cache_flush();
	do_action( 'litespeed_purge_all' );
}, 0 );

/* The old address (widowssonseurope.org, www.) is redirected by Cloudflare, not here: a redirect in WordPress
   looped once Hostinger's main domain became wseurope.org. */
