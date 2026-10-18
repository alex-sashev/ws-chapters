<?php
/**
 * Chapters and events are edited with a fixed form, not the block editor.
 *
 * The layout of a chapter page (header photo, back patch, logo, contact, tabs) comes from the theme
 * template and is the same for every chapter. People managing a chapter only fill in the fields:
 * name, text, photos, insignia, contact and location. They cannot add, remove or move page sections.
 */

defined( 'ABSPATH' ) || exit;

const WS_FORM_TYPES = array(
	'ws_chapter' => array( 'about', 'About the chapter', 'Who you are, when the chapter was founded, where you meet, your rides and charity work. Plain text: leave an empty line between paragraphs.' ),
	'ws_event'   => array( 'description', 'Description', 'Programme, route, registration and anything riders need to know. Plain text: leave an empty line between paragraphs. Web and email addresses become links.' ),
);

add_filter( 'use_block_editor_for_post_type', function ( $use, $post_type ) {
	return isset( WS_FORM_TYPES[ $post_type ] ) ? false : $use;
}, 10, 2 );

/** Stored content (plain text, or older block/HTML content) as plain text for the form. */
function ws_content_to_text( $content ) {
	$content = preg_replace( '/<!--.*?-->/s', '', (string) $content );
	$content = str_replace( array( '</p>', '<br />', '<br/>', '<br>' ), array( "\n\n", "\n", "\n", "\n" ), $content );
	$content = html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( "/\n{3,}/", "\n\n", $content ) );
}

/* The text box, right under the name. */
add_action( 'edit_form_after_title', function ( $post ) {
	if ( ! isset( WS_FORM_TYPES[ $post->post_type ] ) ) {
		return;
	}
	list( $key, $label, $help ) = WS_FORM_TYPES[ $post->post_type ];
	wp_nonce_field( 'ws_form_text', 'ws_form_text_nonce' );
	printf(
		'<div class="ws-form-intro"><p>%1$s</p></div><div class="ws-form-text"><label for="ws_form_text"><strong>%2$s</strong></label><textarea id="ws_form_text" name="ws_form_text" rows="10" class="widefat">%3$s</textarea><p class="description">%4$s</p></div>',
		'ws_chapter' === $post->post_type
			? esc_html__( 'Fill in the fields below. The page layout is the same for every chapter and is set by the site design, so you only need to add your text, photos, insignia and contact details.', 'ws-chapters' )
			: esc_html__( 'Fill in the fields below. The event page layout is set by the site design.', 'ws-chapters' ),
		esc_html( __( $label, 'ws-chapters' ) ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		esc_textarea( ws_content_to_text( $post->post_content ) ),
		esc_html( __( $help, 'ws-chapters' ) ) // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	);
} );

/* Save the text box into the post content, as plain text (no layout, no embedded blocks). */
add_filter( 'wp_insert_post_data', function ( $data, $postarr ) {
	if ( ! isset( WS_FORM_TYPES[ $data['post_type'] ] ) || ! isset( $_POST['ws_form_text_nonce'], $_POST['ws_form_text'] ) ) {
		return $data;
	}
	if ( ! wp_verify_nonce( sanitize_key( $_POST['ws_form_text_nonce'] ), 'ws_form_text' ) ) {
		return $data;
	}
	if ( ! empty( $postarr['ID'] ) && ! current_user_can( 'edit_post', $postarr['ID'] ) ) {
		return $data;
	}
	$data['post_content'] = wp_slash( sanitize_textarea_field( wp_unslash( $_POST['ws_form_text'] ) ) );
	return $data;
}, 10, 2 );

/* Plain-text content gets paragraphs (already by the_content) and clickable links on the site. */
add_filter( 'the_content', function ( $content ) {
	$post = get_post();
	return $post && isset( WS_FORM_TYPES[ $post->post_type ] ) ? make_clickable( $content ) : $content;
}, 20 );

/* Hide boxes that would let people fiddle with internals. */
add_action( 'add_meta_boxes', function ( $post_type ) {
	if ( ! isset( WS_FORM_TYPES[ $post_type ] ) ) {
		return;
	}
	remove_meta_box( 'postcustom', $post_type, 'normal' );
	remove_meta_box( 'slugdiv', $post_type, 'normal' );
	remove_meta_box( 'pageparentdiv', $post_type, 'side' );
}, 99 );

/* No per-page template choice: every chapter (and event) uses the same layout. */
foreach ( array_keys( WS_FORM_TYPES ) as $ws_type ) {
	add_filter( "theme_{$ws_type}_templates", '__return_empty_array' );
}
add_filter( 'get_post_metadata', function ( $value, $object_id, $meta_key ) {
	return '_wp_page_template' === $meta_key && isset( WS_FORM_TYPES[ get_post_type( $object_id ) ] ) ? array( 'default' ) : $value;
}, 10, 3 );

/* Form styling, and click-the-image-to-replace for insignia, header photo and gallery. */
add_action( 'admin_enqueue_scripts', function () {
	$screen = get_current_screen();
	if ( ! $screen || ( ! isset( WS_FORM_TYPES[ $screen->post_type ] ) && 'ws_country' !== $screen->taxonomy ) ) {
		return;
	}
	wp_register_style( 'ws-edit-form', false, array(), '1' );
	wp_enqueue_style( 'ws-edit-form' );
	wp_add_inline_style( 'ws-edit-form', '
		.ws-form-intro { margin: 16px 0 8px; padding: 10px 14px; background: #fff8e5; border-left: 4px solid #C9A24A; }
		.ws-form-intro p { margin: 0; }
		.ws-form-text { margin: 12px 0 20px; }
		.ws-form-text textarea { margin-top: 6px; font-size: 15px; line-height: 1.5; }
		[data-ws-asset] .ws-asset-preview, [data-ws-gallery-control] .ws-gallery-thumbs { cursor: pointer; outline: 1px dashed #c3c4c7; outline-offset: -1px; }
		[data-ws-asset] .ws-asset-preview:hover, [data-ws-gallery-control] .ws-gallery-thumbs:hover { outline: 2px solid #C9A24A; }
		[data-ws-gallery-control] .ws-gallery-thumbs { min-height: 72px; padding: 6px; }
		[data-ws-gallery-control] .ws-gallery-thumbs:empty::after { content: "Click to add photos"; color: #646970; font-style: italic; }
		#postimagediv .inside img { cursor: pointer; }
	' );
	if ( wp_script_is( 'media-editor', 'registered' ) ) {
		wp_add_inline_script( 'media-editor', <<<'JS'
jQuery(function ($) {
	// Clicking the picture (or the empty placeholder) opens the same picker as the Upload / Replace button.
	$(document).on('click', '[data-ws-asset] .ws-asset-preview', function () {
		$(this).closest('[data-ws-asset]').find('.ws-asset-select').trigger('click');
	});
	$(document).on('click', '[data-ws-gallery-control] .ws-gallery-thumbs', function () {
		$(this).closest('[data-ws-gallery-control]').find('.ws-gallery-edit').trigger('click');
	});
	$('[data-ws-asset] .ws-asset-preview, [data-ws-gallery-control] .ws-gallery-thumbs').attr('title', 'Click to upload or replace');
});
JS
		);
	}
}, 20 );

/* "Chapter updated." instead of WordPress's generic "Post updated." */
add_filter( 'post_updated_messages', function ( $messages ) {
	$names = array( 'ws_chapter' => __( 'Chapter', 'ws-chapters' ), 'ws_event' => __( 'Event', 'ws-chapters' ) );
	foreach ( $names as $type => $name ) {
		$post = get_post();
		$link = $post ? sprintf( ' <a href="%s">%s</a>', esc_url( get_permalink( $post ) ), esc_html__( 'View page', 'ws-chapters' ) ) : '';
		/* translators: %s: Chapter or Event */
		$messages[ $type ] = array(
			1  => sprintf( __( '%s updated.', 'ws-chapters' ), $name ) . $link,
			4  => sprintf( __( '%s updated.', 'ws-chapters' ), $name ),
			6  => sprintf( __( '%s published.', 'ws-chapters' ), $name ) . $link,
			7  => sprintf( __( '%s saved.', 'ws-chapters' ), $name ),
			8  => sprintf( __( '%s submitted.', 'ws-chapters' ), $name ),
			10 => sprintf( __( '%s draft updated.', 'ws-chapters' ), $name ),
		);
	}
	return $messages;
} );
