<?php
/**
 * Plugin Name: Widows Sons Chapters
 * Description: Chapter records with separate official back patch and chapter logo, Grand Chapters with national logos and flags, a map of Europe, and events.
 * Version:     2.13.0
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
		'back_patch'   => array( __( 'Back patch', 'ws-chapters' ), __( 'Official chapter patch not yet provided', 'ws-chapters' ) ),
		'chapter_logo' => array( __( 'Chapter logo', 'ws-chapters' ), __( 'Official chapter logo not yet provided', 'ws-chapters' ) ),
	);
}

/* -------------------------------------------------------------------------
 * Data model
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	register_post_type( 'ws_chapter', array(
		'labels'       => array(
			'name'                  => __( 'Chapters', 'ws-chapters' ),
			'singular_name'         => __( 'Chapter', 'ws-chapters' ),
			'add_new'               => __( 'Add chapter', 'ws-chapters' ),
			'add_new_item'          => __( 'Add chapter', 'ws-chapters' ),
			'edit_item'             => __( 'Edit chapter', 'ws-chapters' ),
			'new_item'              => __( 'New chapter', 'ws-chapters' ),
			'view_item'             => __( 'View chapter', 'ws-chapters' ),
			'all_items'             => __( 'All chapters', 'ws-chapters' ),
			'search_items'          => __( 'Search chapters', 'ws-chapters' ),
			'not_found'             => __( 'No chapters found.', 'ws-chapters' ),
			'not_found_in_trash'    => __( 'No chapters in the Trash.', 'ws-chapters' ),
			'featured_image'        => __( 'Header photo', 'ws-chapters' ),
			'set_featured_image'    => __( 'Set header photo', 'ws-chapters' ),
			'remove_featured_image' => __( 'Remove header photo', 'ws-chapters' ),
			'use_featured_image'    => __( 'Use as header photo', 'ws-chapters' ),
			'item_published'        => __( 'Chapter published.', 'ws-chapters' ),
			'item_updated'          => __( 'Chapter updated.', 'ws-chapters' ),
		),
		'public'       => true,
		'has_archive'  => true,
		'rewrite'      => array( 'slug' => 'chapters' ),
		'menu_icon'    => 'dashicons-shield',
		// A fixed form, not the block editor: the page layout comes from the theme template and can't be changed
		// here. Text goes in the "About" box (see includes/edit-form.php). Featured image = header photo.
		'supports'     => array( 'title', 'thumbnail', 'custom-fields' ),
		'show_in_rest' => true,
		// Own capabilities so only administrators can add or remove chapters (see ws_chapter_role_caps()).
		'capability_type' => array( 'ws_chapter', 'ws_chapters' ),
		'map_meta_cap'    => true,
		'capabilities'    => array( 'create_posts' => 'create_ws_chapters' ),
	) );

	// Countries: the national Grand Chapters. Internal name stays ws_country.
	// Chapters, events and news all belong to a country, which drives filtering and Country Admin permissions.
	register_taxonomy( 'ws_country', array( 'ws_chapter', 'ws_event', 'post' ), array(
		'labels'            => array(
			'name'              => __( 'Countries', 'ws-chapters' ),
			'singular_name'     => __( 'Country', 'ws-chapters' ),
			'menu_name'         => __( 'Countries', 'ws-chapters' ),
			'all_items'         => __( 'All countries', 'ws-chapters' ),
			'edit_item'         => __( 'Edit country', 'ws-chapters' ),
			'view_item'         => __( 'View country', 'ws-chapters' ),
			'update_item'       => __( 'Update country', 'ws-chapters' ),
			'add_new_item'      => __( 'Add country', 'ws-chapters' ),
			'new_item_name'     => __( 'New country name', 'ws-chapters' ),
			'search_items'      => __( 'Search countries', 'ws-chapters' ),
			'parent_item'       => __( 'Parent country', 'ws-chapters' ),
			'parent_item_colon' => __( 'Parent country:', 'ws-chapters' ),
			'not_found'         => __( 'No countries found.', 'ws-chapters' ),
			'back_to_items'     => __( '&larr; Go to countries', 'ws-chapters' ),
		),
		'description'       => __( 'National Grand Chapters', 'ws-chapters' ),
		'hierarchical'      => true,
		'show_admin_column' => true,
		'capabilities'      => array(
			'manage_terms' => 'manage_ws_country_terms', // Admins and Country Admins can open the screen...
			'edit_terms'   => 'manage_ws_countries',     // ...but only admins add countries; Country Admins edit their own (see access.php).
			'delete_terms' => 'manage_ws_countries',
			'assign_terms' => 'edit_posts',
		),
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => 'country' ),
	) );

	$auth = function ( $allowed, $meta_key, $post_id ) {
		return ws_can_set_insignia( 'post', $post_id );
	};
	$auth_term = function ( $allowed, $meta_key, $term_id ) {
		return ws_can_set_insignia( 'term', $term_id );
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
		'auth_callback'     => $auth_term,
	) );

	// Flag: two-letter ISO country code (e.g. "bg"). Set by whoever manages Grand Chapters.
	register_term_meta( 'ws_country', 'country_code', array(
		'type'              => 'string',
		'single'            => true,
		'default'           => '',
		'show_in_rest'      => true,
		'sanitize_callback' => 'ws_sanitize_country_code',
		'auth_callback'     => function () {
			return current_user_can( 'manage_ws_countries' );
		},
	) );

	// Location, used by the European map. Anyone who can edit the chapter can set it.
	$can_edit_chapter = function ( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	};
	register_post_meta( 'ws_chapter', 'city', array(
		'type'              => 'string',
		'single'            => true,
		'default'           => '',
		'show_in_rest'      => true,
		'sanitize_callback' => 'sanitize_text_field',
		'auth_callback'     => $can_edit_chapter,
	) );
	foreach ( array( 'latitude' => 90, 'longitude' => 180 ) as $key => $limit ) {
		register_post_meta( 'ws_chapter', $key, array(
			'type'              => 'number',
			'single'            => true,
			'show_in_rest'      => true,
			'sanitize_callback' => function ( $value ) use ( $limit ) {
				return ws_sanitize_coordinate( $value, $limit );
			},
			'auth_callback'     => $can_edit_chapter,
		) );
	}

	add_image_size( 'ws-chapter-card', 480, 480, false ); // Fit-inside, never cropped.

	// One-time setup (works for mu-plugin installs too, which have no activation hook):
	// grant role capabilities and register the /chapters/ URLs.
	if ( get_option( 'ws_chapters_setup_version' ) !== '8' ) {
		foreach ( ws_chapter_role_caps() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( $role ) {
				foreach ( $caps as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
		foreach ( ws_default_grand_chapters() as $name => $code ) {
			$term = term_exists( $name, 'ws_country' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'ws_country' );
			}
			if ( ! is_wp_error( $term ) && '' === (string) get_term_meta( (int) $term['term_id'], 'country_code', true ) ) {
				update_term_meta( (int) $term['term_id'], 'country_code', $code );
			}
		}
		flush_rewrite_rules( false );
		ws_install_scoped_roles();
		ws_install_member_role();
		update_option( 'ws_chapters_setup_version', '8' ); // 5: /events/ URLs. 6: Country Admin / Chapter Editor roles, /country/ URLs. 7: Member role. 8: Useful links.
	}
}, 20 );

/** Starting list of Widows Sons Grand Chapters in Europe (Oct 2026) => flag code. Added once if missing; admins edit from there. */
function ws_default_grand_chapters() {
	return array(
		'Austria' => 'at', 'Belgium' => 'be', 'Bulgaria' => 'bg', 'Croatia' => 'hr', 'Cyprus' => 'cy',
		'Estonia' => 'ee', 'Finland' => 'fi', 'France' => 'fr', 'Germany' => 'de', 'Gibraltar' => 'gi',
		'Greece' => 'gr', 'Hungary' => 'hu', 'Iceland' => 'is', 'Ireland' => 'ie', 'Italy' => 'it',
		'Italy Military Traveler' => 'it', 'Latvia' => 'lv', 'Luxembourg' => 'lu', 'Malta' => 'mt',
		'Netherlands' => 'nl', 'Portugal' => 'pt', 'Romania' => 'ro',
		'Spain' => 'es', 'Sweden' => 'se', 'Switzerland' => 'ch', 'United Kingdom' => 'gb',
	);
}

/**
 * Who may do what with chapters. On multisite the Super Admin has every capability anyway.
 * - Administrators: add, publish, edit, delete chapters; manage countries; set official insignia.
 * - Editors: edit the text/location of existing chapters only. No adding, deleting or insignia.
 * - Country Admins and Chapter Editors: see includes/access.php.
 */
function ws_chapter_role_caps() {
	$edit = array( 'edit_ws_chapters', 'edit_others_ws_chapters', 'edit_published_ws_chapters', 'edit_private_ws_chapters', 'read_private_ws_chapters' );
	return array(
		'administrator' => array_merge( $edit, array(
			'create_ws_chapters', 'publish_ws_chapters',
			'delete_ws_chapters', 'delete_others_ws_chapters', 'delete_published_ws_chapters', 'delete_private_ws_chapters',
			'manage_ws_countries', 'manage_ws_country_terms', WS_ASSET_CAP,
		), ws_link_caps() ),
		'editor'        => $edit,
	);
}

/** Accept only image attachments in a format that keeps quality/transparency; anything else clears the field. */
function ws_sanitize_asset_id( $value ) {
	$id      = absint( $value );
	$allowed = array( 'image/png', 'image/webp', 'image/jpeg' );
	if ( $id && 'attachment' === get_post_type( $id ) && in_array( get_post_mime_type( $id ), $allowed, true ) ) {
		return $id;
	}
	return 0;
}

/** Two lowercase letters (ISO 3166-1 alpha-2, e.g. "bg"), or ''. "uk" is accepted as "gb". */
function ws_sanitize_country_code( $value ) {
	$code = strtolower( trim( (string) $value ) );
	$code = 'uk' === $code ? 'gb' : $code;
	return preg_match( '/^[a-z]{2}$/', $code ) ? $code : '';
}

/** A latitude/longitude in range, or '' (unset). Accepts a comma as decimal separator. */
function ws_sanitize_coordinate( $value, $limit ) {
	$value = str_replace( ',', '.', trim( (string) $value ) );
	if ( '' === $value || ! is_numeric( $value ) || abs( (float) $value ) > $limit ) {
		return '';
	}
	return round( (float) $value, 6 );
}

/** Whether an attachment is in use as a back patch, chapter logo or national logo. */
function ws_is_insignia_attachment( $attachment_id ) {
	global $wpdb;
	$id = (int) $attachment_id;
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key IN ('back_patch','chapter_logo') AND meta_value = %d
		 UNION SELECT 1 FROM {$wpdb->termmeta} WHERE meta_key = 'national_logo' AND meta_value = %d LIMIT 1",
		$id,
		$id
	) );
}

/**
 * Official insignia files can only be edited (e.g. cropped in the media editor) or deleted
 * by users who manage insignia, so an Editor can't alter a patch through the media library.
 */
add_filter( 'map_meta_cap', function ( $caps, $cap, $user_id, $args ) {
	if ( in_array( $cap, array( 'edit_post', 'delete_post' ), true ) && ! empty( $args[0] )
		&& 'attachment' === get_post_type( $args[0] ) && ws_is_insignia_attachment( $args[0] ) ) {
		$caps[] = WS_ASSET_CAP;
	}
	return $caps;
}, 10, 4 );

/* -------------------------------------------------------------------------
 * Front-end helpers
 * ---------------------------------------------------------------------- */

/**
 * Default image for missing insignia: the Widows Sons emblem (filterable; return '' for the neutral
 * "pending" box instead).
 */
function ws_default_emblem_url() {
	return (string) apply_filters( 'ws_default_emblem_url', plugin_dir_url( __FILE__ ) . 'assets/ws-emblem.png' );
}

/**
 * Render an official asset, or the Widows Sons emblem / pending placeholder when none is set.
 *
 * @param int    $attachment_id Attachment ID (0 if not provided).
 * @param string $context       'profile' serves the original upload; 'card' serves the fit-inside thumbnail.
 * @param string $placeholder   Text shown when no asset is set.
 * @param string $alt           Alt text for the image.
 */
function ws_render_asset( $attachment_id, $context, $placeholder, $alt ) {
	$class = 'ws-insignia ws-insignia--' . sanitize_html_class( $context );

	if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
		// Until a chapter or country uploads its own, the Widows Sons emblem stands in.
		$emblem = ws_default_emblem_url();
		if ( $emblem ) {
			return sprintf(
				'<img class="%1$s ws-insignia--default" src="%2$s" alt="%3$s" title="%4$s" loading="lazy" decoding="async">',
				esc_attr( $class ),
				esc_url( $emblem ),
				esc_attr__( 'Widows Sons emblem', 'ws-chapters' ),
				esc_attr( $placeholder )
			);
		}
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
	$alt = sprintf( __( 'Official patch of %s', 'ws-chapters' ), get_the_title( $chapter_id ) );
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

require __DIR__ . '/includes/access.php';
require __DIR__ . '/includes/accounts.php';
require __DIR__ . '/includes/profile-fields.php';
require __DIR__ . '/includes/location.php';
require __DIR__ . '/includes/display.php';
require __DIR__ . '/includes/events.php';
require __DIR__ . '/includes/pages.php';
require __DIR__ . '/includes/edit-form.php';
require __DIR__ . '/includes/forms.php';
require __DIR__ . '/includes/members.php';
require __DIR__ . '/includes/links.php';
require __DIR__ . '/includes/retention.php';
require __DIR__ . '/includes/site-setup.php';

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
			<?php echo $preview ? $preview : '<em>' . esc_html__( 'No official file uploaded. The Widows Sons emblem will be displayed until the chapter uploads its own.', 'ws-chapters' ) . '</em>'; ?>
		</div>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $attachment_id; ?>">
		<?php if ( $can_edit ) : ?>
			<p>
				<button type="button" class="button ws-asset-select"><?php echo $attachment_id ? esc_html__( 'Replace', 'ws-chapters' ) : esc_html__( 'Upload / select', 'ws-chapters' ); ?></button>
				<button type="button" class="button-link-delete ws-asset-remove" <?php echo $attachment_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'ws-chapters' ); ?></button>
			</p>
			<p class="description"><?php esc_html_e( 'Upload the official chapter-supplied insignia at high resolution (prefer PNG or WebP with transparency). Do not edit, recolour or crop official artwork.', 'ws-chapters' ); ?></p>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Only administrators with the relevant permissions may modify official insignia.', 'ws-chapters' ); ?></p>
		<?php endif; ?>
	</div>
	<?php
}

add_action( 'add_meta_boxes_ws_chapter', function () {
	add_meta_box( 'ws_chapter_insignia', __( 'Official insignia', 'ws-chapters' ), function ( $post ) {
		wp_nonce_field( 'ws_chapter_insignia', 'ws_chapter_insignia_nonce' );
		$can = ws_can_set_insignia( 'post', $post->ID );
		echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px">';
		foreach ( ws_chapter_asset_fields() as $key => $field ) {
			ws_asset_control( 'ws_' . $key, $field[0], (int) get_post_meta( $post->ID, $key, true ), $can );
		}
		echo '</div>';
	}, 'ws_chapter', 'normal', 'high' );

	add_meta_box( 'ws_chapter_location', __( 'Location (for the map)', 'ws-chapters' ), function ( $post ) {
		wp_nonce_field( 'ws_chapter_location', 'ws_chapter_location_nonce' );
		$fields = array(
			'city'      => __( 'City', 'ws-chapters' ),
			'latitude'  => __( 'Latitude (optional)', 'ws-chapters' ),
			'longitude' => __( 'Longitude (optional)', 'ws-chapters' ),
		);
		echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px">';
		foreach ( $fields as $key => $label ) {
			printf(
				'<p><label for="ws_%1$s"><strong>%2$s</strong></label><br><input type="text" class="widefat" id="ws_%1$s" name="ws_%1$s" value="%3$s"></p>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( get_post_meta( $post->ID, $key, true ) )
			);
		}
		$pos    = ws_chapter_position( $post->ID );
		$source = array(
			'manual'  => __( 'Pin is at the coordinates entered above.', 'ws-chapters' ),
			'city'    => __( 'Pin is at the city, looked up automatically.', 'ws-chapters' ),
			'capital' => __( 'No city yet: the pin is on the capital of the chapter\'s country.', 'ws-chapters' ),
		);
		echo '</div><p class="description">' . esc_html__( 'Enter the city and save: the map position is found automatically. Only fill in latitude/longitude to place the pin somewhere exact (e.g. right-click the meeting place in Google Maps to copy them). Without a city, the pin goes on the capital of the chapter\'s country.', 'ws-chapters' ) . '</p>';
		if ( $pos ) {
			echo '<p><strong>' . esc_html( $source[ $pos[2] ] ?? '' ) . '</strong></p>';
		}
	}, 'ws_chapter', 'normal', 'default' );
} );

add_action( 'save_post_ws_chapter', function ( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['ws_chapter_location_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_chapter_location_nonce'] ), 'ws_chapter_location' ) ) {
		return;
	}
	foreach ( array( 'city', 'latitude', 'longitude' ) as $key ) {
		if ( ! isset( $_POST[ 'ws_' . $key ] ) ) {
			continue;
		}
		$value = sanitize_meta( $key, wp_unslash( $_POST[ 'ws_' . $key ] ), 'post', 'ws_chapter' );
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
} );

add_action( 'save_post_ws_chapter', function ( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['ws_chapter_insignia_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_chapter_insignia_nonce'] ), 'ws_chapter_insignia' ) ) {
		return;
	}
	if ( ! ws_can_set_insignia( 'post', $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	foreach ( array_keys( ws_chapter_asset_fields() ) as $key ) {
		if ( isset( $_POST[ 'ws_' . $key ] ) ) {
			update_post_meta( $post_id, $key, ws_sanitize_asset_id( wp_unslash( $_POST[ 'ws_' . $key ] ) ) );
		}
	}
} );

/* National logo and flag on the Grand Chapter screens. */
add_action( 'ws_country_add_form_fields', function () {
	wp_nonce_field( 'ws_country_logo', 'ws_country_logo_nonce' );
	echo '<div class="form-field">';
	ws_asset_control( 'ws_national_logo', __( 'National logo', 'ws-chapters' ), 0, current_user_can( WS_ASSET_CAP ) );
	echo '</div><div class="form-field"><label for="ws_country_code">' . esc_html__( 'Flag (country code)', 'ws-chapters' ) . '</label>';
	echo '<input type="text" id="ws_country_code" name="ws_country_code" maxlength="2" style="width:5em" placeholder="bg">';
	echo '<p>' . esc_html__( 'Two-letter country code, e.g. bg, de, gb. Used to show the flag.', 'ws-chapters' ) . '</p></div>';
} );

add_action( 'ws_country_edit_form_fields', function ( $term ) {
	wp_nonce_field( 'ws_country_logo', 'ws_country_logo_nonce' );
	echo '<tr class="form-field"><th scope="row">' . esc_html__( 'National logo', 'ws-chapters' ) . '</th><td>';
	ws_asset_control( 'ws_national_logo', __( 'National logo', 'ws-chapters' ), (int) get_term_meta( $term->term_id, 'national_logo', true ), ws_can_set_insignia( 'term', $term->term_id ) );
	echo '</td></tr>';
	$code = (string) get_term_meta( $term->term_id, 'country_code', true );
	echo '<tr class="form-field"><th scope="row"><label for="ws_country_code">' . esc_html__( 'Flag (country code)', 'ws-chapters' ) . '</label></th><td>';
	printf( '<input type="text" id="ws_country_code" name="ws_country_code" maxlength="2" style="width:5em" value="%s"> %s', esc_attr( $code ), ws_flag( $term ) );
	echo '<p class="description">' . esc_html__( 'Two-letter country code, e.g. bg, de, gb. Used to show the flag.', 'ws-chapters' ) . '</p></td></tr>';
} );

$ws_save_country_logo = function ( $term_id ) {
	if ( ! isset( $_POST['ws_country_logo_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_country_logo_nonce'] ), 'ws_country_logo' ) ) {
		return;
	}
	if ( isset( $_POST['ws_country_code'] ) && current_user_can( 'manage_ws_countries' ) ) {
		update_term_meta( $term_id, 'country_code', ws_sanitize_country_code( wp_unslash( $_POST['ws_country_code'] ) ) );
	}
	if ( ws_can_set_insignia( 'term', $term_id ) && isset( $_POST['ws_national_logo'] ) ) {
		update_term_meta( $term_id, 'national_logo', ws_sanitize_asset_id( wp_unslash( $_POST['ws_national_logo'] ) ) );
	}
};
add_action( 'created_ws_country', $ws_save_country_logo );
add_action( 'edited_ws_country', $ws_save_country_logo );

/* Media-library picker for every [data-ws-asset] control. */
add_action( 'admin_enqueue_scripts', function () {
	$screen = get_current_screen();
	if ( ! $screen || ( 'ws_chapter' !== $screen->post_type && 'ws_country' !== $screen->taxonomy ) || ! current_user_can( 'upload_files' ) ) {
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
		box.find('.ws-asset-preview').html('<em>No official file uploaded. The Widows Sons emblem will be displayed until the chapter uploads its own.</em>');
		$(this).prop('hidden', true);
	});
});
JS
	);
} );
