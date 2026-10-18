<?php
/**
 * Useful links: a list of external websites (Widows Sons international, national sites, partners),
 * managed by administrators.
 *
 * [ws_useful_links]  Cards like the news cards: the website address where the date would be, then the
 *                    title, the description and a "Visit website" link. Order: the Order number, then title.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_post_type( 'ws_link', array(
		'labels'          => array(
			'name'               => __( 'Useful links', 'ws-chapters' ),
			'singular_name'      => __( 'Useful link', 'ws-chapters' ),
			'add_new'            => __( 'Add link', 'ws-chapters' ),
			'add_new_item'       => __( 'Add useful link', 'ws-chapters' ),
			'edit_item'          => __( 'Edit useful link', 'ws-chapters' ),
			'all_items'          => __( 'All useful links', 'ws-chapters' ),
			'search_items'       => __( 'Search useful links', 'ws-chapters' ),
			'not_found'          => __( 'No useful links yet.', 'ws-chapters' ),
			'not_found_in_trash' => __( 'No useful links in the Trash.', 'ws-chapters' ),
			'item_published'     => __( 'Link published.', 'ws-chapters' ),
			'item_updated'       => __( 'Link updated.', 'ws-chapters' ),
		),
		'public'          => false,
		'show_ui'         => true,
		'menu_icon'       => 'dashicons-admin-links',
		'menu_position'   => 27,
		'supports'        => array( 'title', 'page-attributes' ),
		'show_in_rest'    => false,
		// Administrators only.
		'capability_type' => array( 'ws_link', 'ws_links' ),
		'map_meta_cap'    => true,
	) );
} );

/** Capabilities that let administrators manage useful links (added during setup). */
function ws_link_caps() {
	return array( 'edit_ws_links', 'edit_others_ws_links', 'edit_published_ws_links', 'publish_ws_links', 'delete_ws_links', 'delete_others_ws_links', 'delete_published_ws_links', 'read_private_ws_links', 'edit_private_ws_links', 'delete_private_ws_links' );
}

/* Simple form: web address and description. */
add_filter( 'use_block_editor_for_post_type', function ( $use, $type ) {
	return 'ws_link' === $type ? false : $use;
}, 10, 2 );

add_action( 'edit_form_after_title', function ( $post ) {
	if ( 'ws_link' !== $post->post_type ) {
		return;
	}
	wp_nonce_field( 'ws_link', 'ws_link_nonce' );
	printf(
		'<div class="ws-form-text"><p><label for="ws_link_url"><strong>%1$s</strong></label><br><input type="url" id="ws_link_url" name="ws_link_url" class="widefat" placeholder="https://" value="%2$s" required></p><p><label for="ws_link_desc"><strong>%3$s</strong></label><br><textarea id="ws_link_desc" name="ws_link_desc" rows="4" class="widefat">%4$s</textarea></p><p class="description">%5$s</p></div>',
		esc_html__( 'Web address', 'ws-chapters' ),
		esc_attr( get_post_meta( $post->ID, '_ws_url', true ) ),
		esc_html__( 'Description', 'ws-chapters' ),
		esc_textarea( get_post_meta( $post->ID, '_ws_desc', true ) ),
		esc_html__( 'Shown on the Useful links page as a card. Use "Order" on the right to change the position (lower numbers first).', 'ws-chapters' )
	);
} );

add_action( 'save_post_ws_link', function ( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['ws_link_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_link_nonce'] ), 'ws_link' ) ) {
		return;
	}
	if ( isset( $_POST['ws_link_url'] ) ) {
		update_post_meta( $post_id, '_ws_url', esc_url_raw( wp_unslash( $_POST['ws_link_url'] ), array( 'http', 'https' ) ) );
	}
	if ( isset( $_POST['ws_link_desc'] ) ) {
		update_post_meta( $post_id, '_ws_desc', sanitize_textarea_field( wp_unslash( $_POST['ws_link_desc'] ) ) );
	}
} );

add_action( 'add_meta_boxes_ws_link', function () {
	remove_meta_box( 'slugdiv', 'ws_link', 'normal' );
} );

add_filter( 'manage_ws_link_posts_columns', function ( $cols ) {
	return array_slice( $cols, 0, 2 ) + array( 'ws_url' => __( 'Web address', 'ws-chapters' ), 'ws_order' => __( 'Order', 'ws-chapters' ) ) + $cols;
} );
add_action( 'manage_ws_link_posts_custom_column', function ( $col, $id ) {
	if ( 'ws_url' === $col ) {
		$url = get_post_meta( $id, '_ws_url', true );
		echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>' : '—';
	} elseif ( 'ws_order' === $col ) {
		echo (int) get_post_field( 'menu_order', $id );
	}
}, 10, 2 );

/** The website address as shown on the card: host and path, without https:// and www. */
function ws_link_display( $url ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	$path = rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
	return preg_replace( '#^www\.#', '', $host ) . ( strlen( $path ) > 1 ? $path : '' );
}

function ws_useful_links() {
	$links = get_posts( array(
		'post_type'      => 'ws_link',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
		'no_found_rows'  => true,
	) );
	if ( ! $links ) {
		return '<p class="ws-muted">' . esc_html__( 'No links yet.', 'ws-chapters' ) . '</p>';
	}
	$out = '<div class="ws-link-grid">';
	foreach ( $links as $l ) {
		$url = get_post_meta( $l->ID, '_ws_url', true );
		if ( ! $url ) {
			continue;
		}
		$out .= sprintf(
			'<article class="ws-news-card ws-link-card"><a class="ws-link-card__url" href="%1$s" target="_blank" rel="noopener">%2$s</a><h3><a href="%1$s" target="_blank" rel="noopener">%3$s</a></h3><p>%4$s</p><a class="ws-link-arrow" href="%1$s" target="_blank" rel="noopener">%5$s <span class="screen-reader-text">%6$s</span></a></article>',
			esc_url( $url ),
			esc_html( ws_link_display( $url ) ),
			esc_html( $l->post_title ),
			nl2br( esc_html( get_post_meta( $l->ID, '_ws_desc', true ) ) ),
			esc_html__( 'Visit website →', 'ws-chapters' ),
			esc_html__( '(opens in a new tab)', 'ws-chapters' )
		);
	}
	return $out . '</div>';
}

add_action( 'init', function () {
	add_shortcode( 'ws_useful_links', 'ws_useful_links' );
} );
