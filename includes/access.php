<?php
/**
 * Access model.
 *
 * Super Admin / Europe Admin (WordPress Administrator; Super Admin on multisite):
 *   everything — add/remove chapters and countries, all content, all insignia.
 * Country Admin (role ws_country_admin, assigned one or more countries on their user profile):
 *   their country's page, chapters, events and news; insignia of their country and its chapters.
 *   Cannot add or delete chapters or countries.
 * Chapter Editor (role ws_chapter_editor, assigned one or more chapters on their user profile):
 *   their chapter pages (text, location, contact, gallery), plus events and news for their chapters.
 *   Cannot change insignia.
 *
 * Scope is stored as user meta: ws_scope_countries (term IDs) and ws_scope_chapters (post IDs).
 * Every chapter, event and news post belongs to a country (and events/news optionally to a chapter),
 * which is what these checks compare against.
 */

defined( 'ABSPATH' ) || exit;

const WS_SCOPED_TYPES = array( 'ws_chapter', 'ws_event', 'post' );

/** Capabilities for the two scoped roles. The object-level limits are applied in map_meta_cap below. */
function ws_scoped_role_caps() {
	$content = array(
		'read'                       => true,
		'upload_files'               => true,
		'edit_ws_chapters'           => true,
		'edit_others_ws_chapters'    => true,
		'edit_published_ws_chapters' => true,
		'read_private_ws_chapters'   => true,
		// Events and news use the standard post capabilities.
		'edit_posts'                 => true,
		'edit_others_posts'          => true,
		'edit_published_posts'       => true,
		'publish_posts'              => true,
		'delete_posts'               => true,
		'delete_others_posts'        => true,
		'delete_published_posts'     => true,
	);
	return array(
		'ws_country_admin'  => array( __( 'Country Admin', 'ws-chapters' ), $content + array( 'manage_ws_country_terms' => true ) ),
		'ws_chapter_editor' => array( __( 'Chapter Editor', 'ws-chapters' ), $content ),
	);
}

/** Create or refresh the scoped roles. Called from the one-time setup in ws-chapters.php. */
function ws_install_scoped_roles() {
	foreach ( ws_scoped_role_caps() as $slug => $def ) {
		remove_role( $slug );
		add_role( $slug, $def[0], $def[1] );
	}
}

/** The user's scope, or null if they are not limited (admins and other roles). */
function ws_user_scope( $user_id ) {
	$user = get_userdata( $user_id );
	if ( ! $user || user_can( $user, WS_ASSET_CAP ) || is_super_admin( $user_id ) ) {
		return null;
	}
	$roles = (array) $user->roles;
	if ( ! array_intersect( $roles, array( 'ws_country_admin', 'ws_chapter_editor' ) ) ) {
		return null;
	}
	return array(
		'country_admin' => in_array( 'ws_country_admin', $roles, true ),
		'countries'     => array_map( 'intval', (array) get_user_meta( $user_id, 'ws_scope_countries', true ) ),
		'chapters'      => array_map( 'intval', (array) get_user_meta( $user_id, 'ws_scope_chapters', true ) ),
	);
}

/** Country term IDs and chapter ID an item belongs to. */
function ws_item_scope( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return array( 'countries' => array(), 'chapter' => 0 );
	}
	$chapter = 0;
	if ( 'ws_chapter' === $post->post_type ) {
		$chapter = $post->ID;
	} elseif ( 'ws_event' === $post->post_type ) {
		$chapter = (int) get_post_meta( $post->ID, 'host_chapter', true );
	} elseif ( 'post' === $post->post_type ) {
		$chapter = (int) get_post_meta( $post->ID, 'related_chapter', true );
	}
	$countries = wp_get_object_terms( $post->ID, 'ws_country', array( 'fields' => 'ids' ) );
	$countries = is_wp_error( $countries ) ? array() : array_map( 'intval', $countries );
	if ( $chapter && 'ws_chapter' !== $post->post_type ) {
		$c         = wp_get_object_terms( $chapter, 'ws_country', array( 'fields' => 'ids' ) );
		$countries = array_unique( array_merge( $countries, is_wp_error( $c ) ? array() : array_map( 'intval', $c ) ) );
	}
	return array( 'countries' => array_values( $countries ), 'chapter' => $chapter );
}

/** Whether a scoped user may work on this item. */
function ws_scope_allows( $scope, $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}
	$item = ws_item_scope( $post );
	if ( $scope['country_admin'] && array_intersect( $item['countries'], $scope['countries'] ) ) {
		return true;
	}
	if ( $item['chapter'] && in_array( $item['chapter'], $scope['chapters'], true ) ) {
		return true;
	}
	// Their own new draft that has no country or chapter yet (it gets one on save).
	$unassigned = ! $item['countries'] && ! $item['chapter'];
	return $unassigned && (int) $post->post_author === get_current_user_id() && in_array( $post->post_status, array( 'auto-draft', 'draft' ), true );
}

/** Who may set official insignia: admins everywhere; Country Admins for their country and its chapters. */
function ws_can_set_insignia( $type, $id ) {
	if ( current_user_can( WS_ASSET_CAP ) ) {
		return true;
	}
	$scope = ws_user_scope( get_current_user_id() );
	if ( ! $scope || ! $scope['country_admin'] ) {
		return false;
	}
	if ( 'term' === $type ) {
		return in_array( (int) $id, $scope['countries'], true );
	}
	$item = ws_item_scope( $id );
	return 'ws_chapter' === get_post_type( $id ) && (bool) array_intersect( $item['countries'], $scope['countries'] );
}

add_filter( 'map_meta_cap', function ( $caps, $cap, $user_id, $args ) {
	$scope = null;

	// Posts: chapters, events, news.
	if ( in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post', 'read_post' ), true ) && ! empty( $args[0] ) ) {
		$post = get_post( $args[0] );
		if ( $post && in_array( $post->post_type, WS_SCOPED_TYPES, true ) && 'read_post' !== $cap ) {
			$scope = ws_user_scope( $user_id );
			if ( $scope && ! ws_scope_allows( $scope, $post ) ) {
				return array( 'do_not_allow' );
			}
		}
	}

	// Country pages: Country Admins may edit their own country (description, photos, contact), nobody else's.
	if ( 'edit_term' === $cap && ! empty( $args[0] ) ) {
		$term = get_term( $args[0] );
		if ( $term && ! is_wp_error( $term ) && 'ws_country' === $term->taxonomy ) {
			$scope = ws_user_scope( $user_id );
			if ( $scope ) {
				return $scope['country_admin'] && in_array( (int) $term->term_id, $scope['countries'], true )
					? array( 'manage_ws_country_terms' )
					: array( 'do_not_allow' );
			}
		}
	}
	return $caps;
}, 20, 4 );

/**
 * Keep scoped users' content inside their scope: if a Country Admin picks another country, or a
 * Chapter Editor another chapter, it is put back to their own. Runs after the meta boxes have saved.
 */
function ws_enforce_scope( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || wp_is_post_revision( $post_id ) || ! in_array( $post->post_type, array( 'ws_event', 'post' ), true ) ) {
		return;
	}
	$scope = ws_user_scope( get_current_user_id() );
	if ( ! $scope || 'auto-draft' === $post->post_status ) {
		return;
	}
	$key     = 'ws_event' === $post->post_type ? 'host_chapter' : 'related_chapter';
	$chapter = (int) get_post_meta( $post_id, $key, true );

	if ( $scope['country_admin'] ) {
		$countries = wp_get_object_terms( $post_id, 'ws_country', array( 'fields' => 'ids' ) );
		$countries = is_wp_error( $countries ) ? array() : array_map( 'intval', $countries );
		$allowed   = array_values( array_intersect( $countries, $scope['countries'] ) );
		if ( ! $allowed && $scope['countries'] ) {
			$allowed = array( $scope['countries'][0] );
		}
		wp_set_object_terms( $post_id, $allowed, 'ws_country' );
		if ( $chapter && ! array_intersect( ws_item_scope( $chapter )['countries'], $allowed ) ) {
			delete_post_meta( $post_id, $key );
		}
		return;
	}

	// Chapter Editor: must be linked to one of their chapters; country follows the chapter.
	if ( ! in_array( $chapter, $scope['chapters'], true ) ) {
		$chapter = $scope['chapters'][0] ?? 0;
		$chapter ? update_post_meta( $post_id, $key, $chapter ) : delete_post_meta( $post_id, $key );
	}
	if ( $chapter ) {
		wp_set_object_terms( $post_id, ws_item_scope( $chapter )['countries'], 'ws_country' );
	}
}
add_action( 'save_post', 'ws_enforce_scope', 99 );
add_action( 'rest_after_insert_post', function ( $post ) { ws_enforce_scope( $post->ID ); } );
add_action( 'rest_after_insert_ws_event', function ( $post ) { ws_enforce_scope( $post->ID ); } );

/** Country Admins only see their own countries in the country picker. */
add_filter( 'get_terms_args', function ( $args, $taxonomies ) {
	if ( ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $args;
	}
	if ( in_array( 'ws_country', (array) $taxonomies, true ) && count( (array) $taxonomies ) === 1 ) {
		$scope = ws_user_scope( get_current_user_id() );
		if ( $scope && $scope['country_admin'] && empty( $args['include'] ) && empty( $args['object_ids'] ) ) {
			$args['include'] = $scope['countries'] ?: array( 0 );
		}
	}
	return $args;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Admin: assign scope on the user profile (administrators only)
 * ---------------------------------------------------------------------- */

function ws_scope_fields( $user ) {
	if ( ! current_user_can( 'promote_users' ) ) {
		return;
	}
	$countries = get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false, 'include' => array() ) );
	$chapters  = get_posts( array( 'post_type' => 'ws_chapter', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
	$mine_c    = array_map( 'intval', (array) get_user_meta( $user->ID, 'ws_scope_countries', true ) );
	$mine_ch   = array_map( 'intval', (array) get_user_meta( $user->ID, 'ws_scope_chapters', true ) );
	wp_nonce_field( 'ws_scope', 'ws_scope_nonce' );
	echo '<h2>' . esc_html__( 'Widows Sons access', 'ws-chapters' ) . '</h2>';
	echo '<p class="description">' . esc_html__( 'Widows Sons access — assign allowed countries to Country Admins and allowed chapters to Chapter Editors.', 'ws-chapters' ) . '</p>';
	echo '<table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Countries', 'ws-chapters' ) . '</th><td><div style="columns:3;max-width:640px">';
	foreach ( (array) $countries as $t ) {
		printf( '<label style="display:block"><input type="checkbox" name="ws_scope_countries[]" value="%d"%s> %s</label>', $t->term_id, checked( in_array( $t->term_id, $mine_c, true ), true, false ), esc_html( $t->name ) );
	}
	echo '</div></td></tr><tr><th>' . esc_html__( 'Chapters', 'ws-chapters' ) . '</th><td><div style="columns:3;max-width:640px">';
	foreach ( $chapters as $c ) {
		printf( '<label style="display:block"><input type="checkbox" name="ws_scope_chapters[]" value="%d"%s> %s</label>', $c->ID, checked( in_array( $c->ID, $mine_ch, true ), true, false ), esc_html( $c->post_title ) );
	}
	echo '</div></td></tr></table>';
}
add_action( 'show_user_profile', 'ws_scope_fields' );
add_action( 'edit_user_profile', 'ws_scope_fields' );

function ws_save_scope_fields( $user_id ) {
	if ( ! current_user_can( 'promote_users' ) || ! isset( $_POST['ws_scope_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_scope_nonce'] ), 'ws_scope' ) ) {
		return;
	}
	update_user_meta( $user_id, 'ws_scope_countries', array_map( 'absint', (array) ( $_POST['ws_scope_countries'] ?? array() ) ) );
	update_user_meta( $user_id, 'ws_scope_chapters', array_map( 'absint', (array) ( $_POST['ws_scope_chapters'] ?? array() ) ) );
}
add_action( 'personal_options_update', 'ws_save_scope_fields' );
add_action( 'edit_user_profile_update', 'ws_save_scope_fields' );

/** Country Admins get a direct "My country" link to edit their country page. */
add_action( 'admin_menu', function () {
	$scope = ws_user_scope( get_current_user_id() );
	if ( ! $scope || ! $scope['country_admin'] ) {
		return;
	}
	foreach ( $scope['countries'] as $term_id ) {
		$term = get_term( $term_id, 'ws_country' );
		if ( $term && ! is_wp_error( $term ) ) {
			/* translators: %s: country name */
			add_menu_page( sprintf( __( 'Country: %s', 'ws-chapters' ), $term->name ), sprintf( __( 'Country: %s', 'ws-chapters' ), $term->name ), 'manage_ws_country_terms', 'term.php?taxonomy=ws_country&tag_ID=' . $term_id, '', 'dashicons-flag', 25 );
		}
	}
} );
