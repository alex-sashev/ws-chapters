<?php
/**
 * Plugin Name: Widows Sons Chapters
 * Description: Chapter records with separate official back patch and chapter logo, plus national logos per country.
 * Version:     1.0.0
 * Text Domain: ws-chapters
 * License:     GPL-2.0-or-later
 *
 * See docs/chapter-visual-identity.md. Official insignia are stored and shown as uploaded:
 * nothing here crops, recolours or substitutes them.
 */

defined( 'ABSPATH' ) || exit;

const WS_ASSET_CAP = 'manage_ws_chapter_assets';

/** Chapter image fields: meta key => [label, placeholder text]. */
function ws_chapter_asset_fields() {
	return array(
		'back_patch'   => array( __( 'Back patch', 'ws-chapters' ), __( 'Official back patch pending', 'ws-chapters' ) ),
		'chapter_logo' => array( __( 'Chapter logo', 'ws-chapters' ), __( 'Official chapter logo pending', 'ws-chapters' ) ),
	);
}

/* -------------------------------------------------------------------------
 * Data model
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	register_post_type( 'ws_chapter', array(
		'labels'       => array(
			'name'          => __( 'Chapters', 'ws-chapters' ),
			'singular_name' => __( 'Chapter', 'ws-chapters' ),
			'add_new_item'  => __( 'Add chapter', 'ws-chapters' ),
			'edit_item'     => __( 'Edit chapter', 'ws-chapters' ),
		),
		'public'       => true,
		'has_archive'  => true,
		'rewrite'      => array( 'slug' => 'chapters' ),
		'menu_icon'    => 'dashicons-shield',
		'supports'     => array( 'title', 'editor', 'excerpt', 'custom-fields' ),
		'show_in_rest' => true,
	) );

	register_taxonomy( 'ws_country', 'ws_chapter', array(
		'labels'            => array(
			'name'          => __( 'Countries', 'ws-chapters' ),
			'singular_name' => __( 'Country', 'ws-chapters' ),
		),
		'hierarchical'      => true,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => 'country' ),
	) );

	$auth = function () {
		return current_user_can( WS_ASSET_CAP );
	};

	foreach ( array_keys( ws_chapter_asset_fields() ) as $key ) {
		register_post_meta( 'ws_chapter', $key, array(
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'show_in_rest'      => true,
			'sanitize_callback' => 'ws_sanitize_asset_id',
			'auth_callback'     => $auth,
		) );
	}

	register_term_meta( 'ws_country', 'national_logo', array(
		'type'              => 'integer',
		'single'            => true,
		'default'           => 0,
		'show_in_rest'      => true,
		'sanitize_callback' => 'ws_sanitize_asset_id',
		'auth_callback'     => $auth,
	) );

	add_image_size( 'ws-chapter-card', 480, 480, false ); // Fit-inside, never cropped.

	// One-time setup (works for mu-plugin installs too, which have no activation hook):
	// grant the asset capability to Administrators and register the /chapters/ URLs.
	if ( get_option( 'ws_chapters_setup_version' ) !== '1' ) {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( WS_ASSET_CAP );
		}
		flush_rewrite_rules( false );
		update_option( 'ws_chapters_setup_version', '1' );
	}
}, 20 );

/** Accept only image attachments in a format that keeps quality/transparency; anything else clears the field. */
function ws_sanitize_asset_id( $value ) {
	$id      = absint( $value );
	$allowed = array( 'image/png', 'image/webp', 'image/jpeg' );
	if ( $id && 'attachment' === get_post_type( $id ) && in_array( get_post_mime_type( $id ), $allowed, true ) ) {
		return $id;
	}
	return 0;
}

/* -------------------------------------------------------------------------
 * Front-end helpers
 * ---------------------------------------------------------------------- */

/**
 * Render an official asset or the neutral pending placeholder.
 *
 * @param int    $attachment_id Attachment ID (0 if not provided).
 * @param string $context       'profile' serves the original upload; 'card' serves the fit-inside thumbnail.
 * @param string $placeholder   Text shown when no asset is set.
 * @param string $alt           Alt text for the image.
 */
function ws_render_asset( $attachment_id, $context, $placeholder, $alt ) {
	$class = 'ws-insignia ws-insignia--' . sanitize_html_class( $context );

	if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
		return sprintf(
			'<div class="%1$s ws-insignia--pending" role="img" aria-label="%2$s"><span>%2$s</span></div>',
			esc_attr( $class ),
			esc_html( $placeholder )
		);
	}

	if ( 'profile' === $context ) {
		// Original file, not WordPress's "-scaled" copy, so detail and proportions are untouched.
		$meta = wp_get_attachment_metadata( $attachment_id );
		$src  = wp_get_original_image_url( $attachment_id ) ?: wp_get_attachment_url( $attachment_id );
		return sprintf(
			'<img class="%s" src="%s" alt="%s" width="%d" height="%d" loading="lazy" decoding="async">',
			esc_attr( $class ),
			esc_url( $src ),
			esc_attr( $alt ),
			(int) ( $meta['width'] ?? 0 ),
			(int) ( $meta['height'] ?? 0 )
		);
	}

	return wp_get_attachment_image( $attachment_id, 'ws-chapter-card', false, array(
		'class' => $class,
		'alt'   => $alt,
	) );
}

function ws_chapter_back_patch( $chapter_id = null ) {
	$chapter_id = $chapter_id ?: get_the_ID();
	$fields     = ws_chapter_asset_fields();
	/* translators: %s: chapter name */
	$alt = sprintf( __( 'Official back patch of %s', 'ws-chapters' ), get_the_title( $chapter_id ) );
	return ws_render_asset( (int) get_post_meta( $chapter_id, 'back_patch', true ), 'profile', $fields['back_patch'][1], $alt );
}

function ws_chapter_logo( $chapter_id = null ) {
	$chapter_id = $chapter_id ?: get_the_ID();
	$fields     = ws_chapter_asset_fields();
	/* translators: %s: chapter name */
	$alt = sprintf( __( 'Official logo of %s', 'ws-chapters' ), get_the_title( $chapter_id ) );
	return ws_render_asset( (int) get_post_meta( $chapter_id, 'chapter_logo', true ), 'card', $fields['chapter_logo'][1], $alt );
}

function ws_country_logo( $term_id ) {
	$term = get_term( $term_id, 'ws_country' );
	$name = $term && ! is_wp_error( $term ) ? $term->name : '';
	/* translators: %s: country / national organisation name */
	$alt = sprintf( __( 'National logo of %s', 'ws-chapters' ), $name );
	return ws_render_asset( (int) get_term_meta( $term_id, 'national_logo', true ), 'card', __( 'Official national logo pending', 'ws-chapters' ), $alt );
}

/** Image URLs for the interactive map / headless use, alongside the raw IDs already in REST. */
add_action( 'rest_api_init', function () {
	register_rest_field( 'ws_chapter', 'insignia', array(
		'get_callback' => function ( $post ) {
			$out = array();
			foreach ( array_keys( ws_chapter_asset_fields() ) as $key ) {
				$id          = (int) get_post_meta( $post['id'], $key, true );
				$out[ $key ] = $id ? array(
					'original' => wp_get_original_image_url( $id ) ?: wp_get_attachment_url( $id ),
					'card'     => wp_get_attachment_image_url( $id, 'ws-chapter-card' ),
				) : null; // null = pending; the map shows its placeholder.
			}
			return $out;
		},
		'schema'       => array( 'type' => 'object', 'context' => array( 'view', 'edit' ) ),
	) );
} );

add_action( 'wp_head', function () {
	?>
	<style id="ws-insignia">
		.ws-insignia { display: block; max-width: 100%; height: auto; object-fit: contain; background: transparent; }
		.ws-insignia--pending {
			display: flex; align-items: center; justify-content: center; text-align: center;
			aspect-ratio: 1; min-height: 120px; padding: 1rem; box-sizing: border-box;
			border: 2px dashed #b5b5b5; border-radius: 8px; color: #6b6b6b; background: #f4f4f4;
			font-size: .875rem; line-height: 1.3;
		}
	</style>
	<?php
} );

/* -------------------------------------------------------------------------
 * Admin: independent upload / replace controls
 * ---------------------------------------------------------------------- */

/** One Select / Replace / Remove control bound to a hidden input holding the attachment ID. */
function ws_asset_control( $name, $label, $attachment_id, $can_edit ) {
	$preview = $attachment_id ? wp_get_attachment_image( $attachment_id, 'medium', false, array( 'style' => 'max-width:100%;height:auto' ) ) : '';
	?>
	<div class="ws-asset-control" data-ws-asset>
		<p><strong><?php echo esc_html( $label ); ?></strong></p>
		<div class="ws-asset-preview" style="background:repeating-conic-gradient(#eee 0 25%,#fff 0 50%) 0 0/16px 16px;padding:8px;min-height:60px">
			<?php echo $preview ? $preview : '<em>' . esc_html__( 'Not provided yet — placeholder will be shown.', 'ws-chapters' ) . '</em>'; ?>
		</div>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $attachment_id; ?>">
		<?php if ( $can_edit ) : ?>
			<p>
				<button type="button" class="button ws-asset-select"><?php echo $attachment_id ? esc_html__( 'Replace', 'ws-chapters' ) : esc_html__( 'Upload / select', 'ws-chapters' ); ?></button>
				<button type="button" class="button-link-delete ws-asset-remove" <?php echo $attachment_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'ws-chapters' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Upload the official file as received (PNG or WebP for transparency, high resolution). Do not crop or edit it.', 'ws-chapters' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Only authorised administrators can change official insignia.', 'ws-chapters' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'add_meta_boxes_ws_chapter', function () {
	add_meta_box( 'ws_chapter_insignia', __( 'Official insignia', 'ws-chapters' ), function ( $post ) {
		wp_nonce_field( 'ws_chapter_insignia', 'ws_chapter_insignia_nonce' );
		$can = current_user_can( WS_ASSET_CAP );
		echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">';
		foreach ( ws_chapter_asset_fields() as $key => $field ) {
			ws_asset_control( 'ws_' . $key, $field[0], (int) get_post_meta( $post->ID, $key, true ), $can );
		}
		echo '</div>';
	}, 'ws_chapter', 'normal', 'high' );
} );

add_action( 'save_post_ws_chapter', function ( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['ws_chapter_insignia_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_chapter_insignia_nonce'] ), 'ws_chapter_insignia' ) ) {
		return;
	}
	if ( ! current_user_can( WS_ASSET_CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( array_keys( ws_chapter_asset_fields() ) as $key ) {
		if ( isset( $_POST[ 'ws_' . $key ] ) ) {
			update_post_meta( $post_id, $key, ws_sanitize_asset_id( wp_unslash( $_POST[ 'ws_' . $key ] ) ) );
		}
	}
} );

/* National logo on the country term screens. */
add_action( 'ws_country_add_form_fields', function () {
	wp_nonce_field( 'ws_country_logo', 'ws_country_logo_nonce' );
	echo '<div class="form-field">';
	ws_asset_control( 'ws_national_logo', __( 'National logo', 'ws-chapters' ), 0, current_user_can( WS_ASSET_CAP ) );
	echo '</div>';
} );

add_action( 'ws_country_edit_form_fields', function ( $term ) {
	wp_nonce_field( 'ws_country_logo', 'ws_country_logo_nonce' );
	echo '<tr class="form-field"><th scope="row">' . esc_html__( 'National logo', 'ws-chapters' ) . '</th><td>';
	ws_asset_control( 'ws_national_logo', __( 'Stored separately from chapter insignia', 'ws-chapters' ), (int) get_term_meta( $term->term_id, 'national_logo', true ), current_user_can( WS_ASSET_CAP ) );
	echo '</td></tr>';
} );

$ws_save_country_logo = function ( $term_id ) {
	if ( ! isset( $_POST['ws_country_logo_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_country_logo_nonce'] ), 'ws_country_logo' ) ) {
		return;
	}
	if ( ! current_user_can( WS_ASSET_CAP ) || ! isset( $_POST['ws_national_logo'] ) ) {
		return;
	}
	update_term_meta( $term_id, 'national_logo', ws_sanitize_asset_id( wp_unslash( $_POST['ws_national_logo'] ) ) );
};
add_action( 'created_ws_country', $ws_save_country_logo );
add_action( 'edited_ws_country', $ws_save_country_logo );

/* Media-library picker for every [data-ws-asset] control. */
add_action( 'admin_enqueue_scripts', function () {
	$screen = get_current_screen();
	if ( ! $screen || ( 'ws_chapter' !== $screen->post_type && 'ws_country' !== $screen->taxonomy ) || ! current_user_can( WS_ASSET_CAP ) ) {
		return;
	}
	wp_enqueue_media();
	wp_add_inline_script( 'media-editor', <<<'JS'
jQuery(function ($) {
	$(document).on('click', '[data-ws-asset] .ws-asset-select', function (e) {
		e.preventDefault();
		var box = $(this).closest('[data-ws-asset]');
		var frame = wp.media({
			title: box.find('strong').first().text(),
			library: { type: ['image/png', 'image/webp', 'image/jpeg'] },
			multiple: false
		});
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			var url = (a.sizes && a.sizes.medium ? a.sizes.medium : a).url;
			box.find('input[type=hidden]').val(a.id);
			box.find('.ws-asset-preview').empty().append($('<img>', { src: url, alt: '', css: { maxWidth: '100%', height: 'auto' } }));
			box.find('.ws-asset-select').text(wp.i18n ? wp.i18n.__('Replace') : 'Replace');
			box.find('.ws-asset-remove').prop('hidden', false);
		});
		frame.open();
	});
	$(document).on('click', '[data-ws-asset] .ws-asset-remove', function (e) {
		e.preventDefault();
		var box = $(this).closest('[data-ws-asset]');
		box.find('input[type=hidden]').val(0);
		box.find('.ws-asset-preview').html('<em>Not provided yet — placeholder will be shown.</em>');
		$(this).prop('hidden', true);
	});
});
JS
	);
} );
