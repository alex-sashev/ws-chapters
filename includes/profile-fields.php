<?php
/**
 * Profile fields for country and chapter pages: contact details, social links, photo gallery,
 * country hero photo. News posts can be linked to a chapter.
 *
 * Chapter hero photo = the chapter's featured image. Country "About" text = the country description.
 */

defined( 'ABSPATH' ) || exit;

/** Contact fields shared by countries and chapters: key => [label, type]. */
function ws_contact_fields() {
	return array(
		'email'     => array( __( 'Email', 'ws-chapters' ), 'email' ),
		'phone'     => array( __( 'Phone', 'ws-chapters' ), 'tel' ),
		'address'   => array( __( 'Address / meeting place', 'ws-chapters' ), 'text' ),
		'website'   => array( __( 'Website', 'ws-chapters' ), 'url' ),
		'facebook'  => array( __( 'Facebook page', 'ws-chapters' ), 'url' ),
		'instagram' => array( __( 'Instagram', 'ws-chapters' ), 'url' ),
	);
}

function ws_sanitize_contact( $key, $value ) {
	$value = trim( (string) $value );
	if ( 'email' === $key ) {
		return sanitize_email( $value );
	}
	if ( 'phone' === $key ) {
		return trim( preg_replace( '/[^0-9+()\s.\-\/]/', '', $value ) );
	}
	if ( 'address' === $key ) {
		return sanitize_text_field( $value );
	}
	return esc_url_raw( $value );
}

/** Comma-separated list of image attachment IDs. */
function ws_sanitize_gallery( $value ) {
	$ids = array_filter( array_map( 'absint', explode( ',', (string) $value ) ) );
	$ids = array_filter( $ids, 'wp_attachment_is_image' );
	return implode( ',', array_unique( $ids ) );
}

function ws_gallery_ids( $value ) {
	return array_values( array_filter( array_map( 'absint', explode( ',', (string) $value ) ) ) );
}

add_action( 'init', function () {
	$post_auth = function ( $allowed, $key, $id ) {
		return current_user_can( 'edit_post', $id );
	};
	$term_auth = function ( $allowed, $key, $id ) {
		return current_user_can( 'edit_term', $id );
	};
	foreach ( ws_contact_fields() as $key => $field ) {
		$args = array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => function ( $v ) use ( $key ) {
				return ws_sanitize_contact( $key, $v );
			},
		);
		register_post_meta( 'ws_chapter', $key, $args + array( 'auth_callback' => $post_auth ) );
		register_term_meta( 'ws_country', $key, $args + array( 'auth_callback' => $term_auth ) );
	}
	$gallery = array( 'type' => 'string', 'single' => true, 'default' => '', 'show_in_rest' => true, 'sanitize_callback' => 'ws_sanitize_gallery' );
	register_post_meta( 'ws_chapter', 'gallery', $gallery + array( 'auth_callback' => $post_auth ) );
	register_term_meta( 'ws_country', 'gallery', $gallery + array( 'auth_callback' => $term_auth ) );
	register_term_meta( 'ws_country', 'hero_image', array(
		'type' => 'integer', 'single' => true, 'default' => 0, 'show_in_rest' => true,
		'sanitize_callback' => 'ws_sanitize_asset_id', 'auth_callback' => $term_auth,
	) );
	register_post_meta( 'post', 'related_chapter', array(
		'type' => 'integer', 'single' => true, 'default' => 0, 'show_in_rest' => true,
		'sanitize_callback' => function ( $id ) {
			$id = absint( $id );
			return $id && 'ws_chapter' === get_post_type( $id ) ? $id : 0;
		},
		'auth_callback' => $post_auth,
	) );
}, 11 );

/* -------------------------------------------------------------------------
 * Admin controls
 * ---------------------------------------------------------------------- */

function ws_contact_inputs( $values ) {
	echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 16px">';
	foreach ( ws_contact_fields() as $key => $field ) {
		printf(
			'<p style="margin:0"><label for="ws_%1$s"><strong>%2$s</strong></label><br><input type="%3$s" id="ws_%1$s" name="ws_%1$s" value="%4$s" class="widefat"></p>',
			esc_attr( $key ),
			esc_html( $field[0] ),
			$field[1],
			esc_attr( $values[ $key ] ?? '' )
		);
	}
	echo '</div>';
}

/** Gallery picker: hidden input of IDs + thumbnails, edited with the media library. */
function ws_gallery_control( $name, $value ) {
	echo '<div data-ws-gallery-control><input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"><div class="ws-gallery-thumbs" style="display:flex;flex-wrap:wrap;gap:6px;margin:6px 0">';
	foreach ( ws_gallery_ids( $value ) as $id ) {
		echo wp_get_attachment_image( $id, 'thumbnail', false, array( 'style' => 'width:72px;height:72px;object-fit:cover' ) );
	}
	echo '</div><button type="button" class="button ws-gallery-edit">' . esc_html__( 'Choose photos', 'ws-chapters' ) . '</button> <button type="button" class="button-link-delete ws-gallery-clear">' . esc_html__( 'Clear', 'ws-chapters' ) . '</button></div>';
}

function ws_save_contact_and_gallery( $save, $source ) {
	foreach ( array_keys( ws_contact_fields() ) as $key ) {
		if ( isset( $source[ 'ws_' . $key ] ) ) {
			$save( $key, ws_sanitize_contact( $key, wp_unslash( $source[ 'ws_' . $key ] ) ) );
		}
	}
	if ( isset( $source['ws_gallery'] ) ) {
		$save( 'gallery', ws_sanitize_gallery( wp_unslash( $source['ws_gallery'] ) ) );
	}
}

/* Chapter: contact & gallery box. */
add_action( 'add_meta_boxes_ws_chapter', function () {
	add_meta_box( 'ws_chapter_contact', __( 'Contact details & gallery', 'ws-chapters' ), function ( $post ) {
		wp_nonce_field( 'ws_chapter_contact', 'ws_chapter_contact_nonce' );
		$values = array();
		foreach ( array_keys( ws_contact_fields() ) as $key ) {
			$values[ $key ] = get_post_meta( $post->ID, $key, true );
		}
		ws_contact_inputs( $values );
		echo '<p><strong>' . esc_html__( 'Photo gallery', 'ws-chapters' ) . '</strong></p>';
		ws_gallery_control( 'ws_gallery', get_post_meta( $post->ID, 'gallery', true ) );
		echo '<p class="description">' . esc_html__( 'Featured image: used as the chapter page header.', 'ws-chapters' ) . '</p>';
	}, 'ws_chapter', 'normal', 'default' );
} );

add_action( 'save_post_ws_chapter', function ( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['ws_chapter_contact_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_chapter_contact_nonce'] ), 'ws_chapter_contact' ) ) {
		return;
	}
	ws_save_contact_and_gallery( function ( $k, $v ) use ( $post_id ) {
		update_post_meta( $post_id, $k, $v );
	}, $_POST );
} );

/* Country: hero photo, contact & gallery on the edit screen. */
add_action( 'ws_country_edit_form_fields', function ( $term ) {
	if ( ! current_user_can( 'edit_term', $term->term_id ) ) {
		return;
	}
	wp_nonce_field( 'ws_country_profile', 'ws_country_profile_nonce' );
	$values = array();
	foreach ( array_keys( ws_contact_fields() ) as $key ) {
		$values[ $key ] = get_term_meta( $term->term_id, $key, true );
	}
	echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Header photo', 'ws-chapters' ) . '</th><td>';
	ws_asset_control( 'ws_hero_image', __( 'Header photo', 'ws-chapters' ), (int) get_term_meta( $term->term_id, 'hero_image', true ), true );
	echo '<p class="description">' . esc_html__( 'A wide landscape photo for the top of the country page.', 'ws-chapters' ) . '</p></td></tr>';
	echo '<tr class="form-field"><th scope="row">' . esc_html__( 'Contact details', 'ws-chapters' ) . '</th><td>';
	ws_contact_inputs( $values );
	echo '</td></tr><tr class="form-field"><th scope="row">' . esc_html__( 'Photo gallery', 'ws-chapters' ) . '</th><td>';
	ws_gallery_control( 'ws_gallery', get_term_meta( $term->term_id, 'gallery', true ) );
	echo '</td></tr>';
}, 20 );

add_action( 'edited_ws_country', function ( $term_id ) {
	if ( ! isset( $_POST['ws_country_profile_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_country_profile_nonce'] ), 'ws_country_profile' ) || ! current_user_can( 'edit_term', $term_id ) ) {
		return;
	}
	ws_save_contact_and_gallery( function ( $k, $v ) use ( $term_id ) {
		update_term_meta( $term_id, $k, $v );
	}, $_POST );
	if ( isset( $_POST['ws_hero_image'] ) ) {
		update_term_meta( $term_id, 'hero_image', ws_sanitize_asset_id( wp_unslash( $_POST['ws_hero_image'] ) ) );
	}
} );

/* News: link a post to a chapter. */
add_action( 'add_meta_boxes_post', function () {
	add_meta_box( 'ws_related_chapter', __( 'Posted by', 'ws-chapters' ), function ( $post ) {
		wp_nonce_field( 'ws_related_chapter', 'ws_related_chapter_nonce' );
		$current  = (int) get_post_meta( $post->ID, 'related_chapter', true );
		$chapters = get_posts( array( 'post_type' => 'ws_chapter', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<select name="ws_related_chapter" style="width:100%"><option value="0">' . esc_html__( '— No chapter (country or Widows Sons Europe) —', 'ws-chapters' ) . '</option>';
		foreach ( $chapters as $c ) {
			if ( current_user_can( 'edit_post', $c->ID ) || $c->ID === $current ) {
				printf( '<option value="%d"%s>%s</option>', $c->ID, selected( $current, $c->ID, false ), esc_html( $c->post_title ) );
			}
		}
		echo '</select><p class="description">' . esc_html__( 'Pick a chapter to post as that chapter (it also shows on the chapter page). With no chapter, the country chosen under Countries is shown. With no chapter and no country, the news is posted by Widows Sons Europe.', 'ws-chapters' ) . '</p>';
	}, 'post', 'side', 'default' );
} );

add_action( 'save_post_post', function ( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['ws_related_chapter_nonce'], $_POST['ws_related_chapter'] ) && wp_verify_nonce( sanitize_key( $_POST['ws_related_chapter_nonce'] ), 'ws_related_chapter' ) ) {
		update_post_meta( $post_id, 'related_chapter', absint( $_POST['ws_related_chapter'] ) ); // Sanitized by register_post_meta.
	}
}, 10 );

/* Media picker for galleries (and the hero photo control, which reuses the insignia picker). */
add_action( 'admin_enqueue_scripts', function () {
	$screen = get_current_screen();
	if ( ! $screen || ( 'ws_chapter' !== $screen->post_type && 'ws_country' !== $screen->taxonomy ) || ! current_user_can( 'upload_files' ) ) {
		return;
	}
	wp_enqueue_media();
	wp_add_inline_script( 'media-editor', <<<'JS'
jQuery(function ($) {
	$(document).on('click', '[data-ws-gallery-control] .ws-gallery-edit', function (e) {
		e.preventDefault();
		var box = $(this).closest('[data-ws-gallery-control]'), input = box.find('input[type=hidden]');
		var frame = wp.media({ title: 'Photo gallery', library: { type: 'image' }, multiple: 'add' });
		frame.on('open', function () {
			var sel = frame.state().get('selection');
			(input.val() || '').split(',').filter(Boolean).forEach(function (id) { var a = wp.media.attachment(id); a.fetch(); sel.add(a); });
		});
		frame.on('select', function () {
			var items = frame.state().get('selection').toJSON();
			input.val(items.map(function (a) { return a.id; }).join(','));
			var thumbs = box.find('.ws-gallery-thumbs').empty();
			items.forEach(function (a) {
				var url = (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail : a).url;
				thumbs.append($('<img>', { src: url, alt: '', css: { width: 72, height: 72, objectFit: 'cover' } }));
			});
		});
		frame.open();
	});
	$(document).on('click', '[data-ws-gallery-control] .ws-gallery-clear', function (e) {
		e.preventDefault();
		var box = $(this).closest('[data-ws-gallery-control]');
		box.find('input[type=hidden]').val('');
		box.find('.ws-gallery-thumbs').empty();
	});
});
JS
	);
} );
