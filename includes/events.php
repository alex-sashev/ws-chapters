<?php
/**
 * Events: dates, place and the hosting chapter. Listings show the host chapter's official logo.
 *
 * [ws_events]                 Upcoming events (limit="3", past="1" for past events, newest first,
 *                             recent="1" to show the latest past events when nothing is upcoming).
 * [ws_event_details]          Date, place and host chapter of the current event (single event page).
 * [ws_events_url]             URL of the events page.
 *
 * Events use normal post permissions, so Editors can add and edit them.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	register_post_type( 'ws_event', array(
		'labels'       => array(
			'name'                  => __( 'Events', 'ws-chapters' ),
			'singular_name'         => __( 'Event', 'ws-chapters' ),
			'add_new'               => __( 'Add event', 'ws-chapters' ),
			'add_new_item'          => __( 'Add event', 'ws-chapters' ),
			'edit_item'             => __( 'Edit event', 'ws-chapters' ),
			'view_item'             => __( 'View event', 'ws-chapters' ),
			'all_items'             => __( 'All events', 'ws-chapters' ),
			'search_items'          => __( 'Search events', 'ws-chapters' ),
			'not_found'             => __( 'No events found.', 'ws-chapters' ),
			'not_found_in_trash'    => __( 'No events in the Trash.', 'ws-chapters' ),
			'featured_image'        => __( 'Event photo', 'ws-chapters' ),
			'set_featured_image'    => __( 'Set event photo', 'ws-chapters' ),
			'remove_featured_image' => __( 'Remove event photo', 'ws-chapters' ),
			'item_published'        => __( 'Event published.', 'ws-chapters' ),
			'item_updated'          => __( 'Event updated.', 'ws-chapters' ),
		),
		'public'       => true,
		'has_archive'  => true,
		'rewrite'      => array( 'slug' => 'events' ),
		'menu_icon'    => 'dashicons-calendar-alt',
		'supports'     => array( 'title', 'thumbnail', 'custom-fields' ), // Fixed form; description in the "Description" box.
		'show_in_rest' => true,
	) );

	$auth = function ( $allowed, $meta_key, $post_id ) {
		return current_user_can( 'edit_post', $post_id );
	};
	foreach ( array( 'start_date', 'end_date' ) as $key ) {
		register_post_meta( 'ws_event', $key, array(
			'type' => 'string', 'single' => true, 'default' => '', 'show_in_rest' => true,
			'sanitize_callback' => 'ws_sanitize_date', 'auth_callback' => $auth,
		) );
	}
	foreach ( array( 'venue', 'city', 'organiser' ) as $key ) {
		register_post_meta( 'ws_event', $key, array(
			'type' => 'string', 'single' => true, 'default' => '', 'show_in_rest' => true,
			'sanitize_callback' => 'sanitize_text_field', 'auth_callback' => $auth,
		) );
	}
	register_post_meta( 'ws_event', 'start_time', array(
		'type' => 'string', 'single' => true, 'default' => '', 'show_in_rest' => true,
		'sanitize_callback' => 'ws_sanitize_time', 'auth_callback' => $auth,
	) );
	register_post_meta( 'ws_event', 'contact', array(
		'type' => 'string', 'single' => true, 'default' => '', 'show_in_rest' => true,
		'sanitize_callback' => 'sanitize_textarea_field', 'auth_callback' => $auth,
	) );
	register_post_meta( 'ws_event', 'host_chapter', array(
		'type' => 'integer', 'single' => true, 'default' => 0, 'show_in_rest' => true,
		'sanitize_callback' => function ( $id ) {
			$id = absint( $id );
			return $id && 'ws_chapter' === get_post_type( $id ) ? $id : 0;
		},
		'auth_callback' => $auth,
	) );
} );

/** A 24-hour H:i time, or ''. */
function ws_sanitize_time( $value ) {
	$value = trim( (string) $value );
	return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
}

/** A Y-m-d date, or ''. */
function ws_sanitize_date( $value ) {
	$value = trim( (string) $value );
	$d     = DateTime::createFromFormat( '!Y-m-d', $value );
	return $d && $d->format( 'Y-m-d' ) === $value ? $value : '';
}

/** Event archive: soonest first, upcoming only. */
add_action( 'pre_get_posts', function ( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'ws_event' ) ) {
		return;
	}
	$query->set( 'meta_key', 'start_date' );
	$query->set( 'orderby', 'meta_value' );
	$query->set( 'order', 'ASC' );
} );

/* -------------------------------------------------------------------------
 * Display
 * ---------------------------------------------------------------------- */

/** "12–14 March 2027", "30 April – 2 May 2027", or "12 March 2027". */
function ws_event_date_range( $event_id ) {
	$start = get_post_meta( $event_id, 'start_date', true );
	$end   = get_post_meta( $event_id, 'end_date', true );
	if ( ! $start ) {
		return '';
	}
	$s = strtotime( $start );
	$e = $end && $end > $start ? strtotime( $end ) : 0;
	if ( ! $e ) {
		return date_i18n( 'j F Y', $s );
	}
	if ( gmdate( 'Y-m', $s ) === gmdate( 'Y-m', $e ) ) {
		return date_i18n( 'j', $s ) . '–' . date_i18n( 'j F Y', $e );
	}
	if ( gmdate( 'Y', $s ) === gmdate( 'Y', $e ) ) {
		return date_i18n( 'j F', $s ) . ' – ' . date_i18n( 'j F Y', $e );
	}
	return date_i18n( 'j F Y', $s ) . ' – ' . date_i18n( 'j F Y', $e );
}

/** Venue and city as plain text (a venue given as a web address is left out). */
function ws_event_place( $event_id ) {
	$venue = get_post_meta( $event_id, 'venue', true );
	return implode( ', ', array_filter( array(
		wp_http_validate_url( $venue ) ? '' : $venue,
		get_post_meta( $event_id, 'city', true ),
	) ) );
}

/** "Hosted by" line for the host chapter, or "Organised by" when the organiser is not a listed chapter. */
function ws_event_organiser( $event_id ) {
	$name = get_post_meta( $event_id, 'organiser', true );
	return $name ? '<p class="ws-event-organiser">' . esc_html__( 'Organised by', 'ws-chapters' ) . ' <strong>' . esc_html( $name ) . '</strong></p>' : '';
}

/** Contact text with email addresses, web addresses and phone numbers made clickable. */
function ws_event_contact_html( $text ) {
	$html = make_clickable( esc_html( $text ) );
	$html = preg_replace_callback( '/(?<![\w\/=])\+\d[\d\s]{6,}\d/', function ( $m ) {
		return '<a href="tel:' . preg_replace( '/\s+/', '', $m[0] ) . '">' . $m[0] . '</a>';
	}, $html );
	return nl2br( str_replace( '; ', "\n", $html ) );
}

/** "Hosted by" line: the host chapter's official logo (or pending placeholder), name and flag. */
function ws_event_host( $event_id ) {
	$host = (int) get_post_meta( $event_id, 'host_chapter', true );
	if ( ! $host || 'publish' !== get_post_status( $host ) ) {
		return '';
	}
	return sprintf(
		'<a class="ws-event-host" href="%1$s"><span class="ws-event-host__logo">%2$s</span><span>%3$s <strong>%4$s</strong> %5$s</span></a>',
		esc_url( get_permalink( $host ) ),
		ws_chapter_logo( $host ),
		esc_html__( 'Hosted by', 'ws-chapters' ),
		esc_html( ws_display_name( get_the_title( $host ) ) ),
		ws_flag( ws_chapter_country( $host ) )
	);
}

function ws_event_card( $event_id ) {
	$start = get_post_meta( $event_id, 'start_date', true );
	$date  = $start
		? sprintf( '<time class="ws-event-card__date" datetime="%s"><span>%s</span>%s</time>', esc_attr( $start ), esc_html( date_i18n( 'j', strtotime( $start ) ) ), esc_html( date_i18n( 'M Y', strtotime( $start ) ) ) )
		: '<span class="ws-event-card__date"><span>—</span></span>';
	$place = get_post_meta( $event_id, 'city', true ) ?: ws_event_place( $event_id );
	$host  = ws_event_host( $event_id );
	return sprintf(
		'<article class="ws-event-card">%1$s<div class="ws-event-card__body"><h3 class="ws-event-card__title"><a href="%2$s">%3$s</a></h3><p class="ws-event-card__meta">%4$s%5$s</p>%6$s</div></article>',
		$date,
		esc_url( get_permalink( $event_id ) ),
		esc_html( get_the_title( $event_id ) ),
		esc_html( ws_event_date_range( $event_id ) ),
		$place ? ' <span aria-hidden="true">·</span> ' . esc_html( $place ) : '',
		$host ?: ws_event_organiser( $event_id )
	);
}

/** Upcoming (default) or past events. An event is upcoming until its last day has passed. */
function ws_get_events( $past = false, $limit = -1 ) {
	$today = current_time( 'Y-m-d' );
	$posts = get_posts( array(
		'post_type'      => 'ws_event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_key'       => 'start_date',
		'orderby'        => 'meta_value',
		'order'          => $past ? 'DESC' : 'ASC',
		'no_found_rows'  => true,
	) );
	$posts = array_values( array_filter( $posts, function ( $p ) use ( $today, $past ) {
		$last = get_post_meta( $p->ID, 'end_date', true ) ?: get_post_meta( $p->ID, 'start_date', true );
		return $past ? ( $last && $last < $today ) : ( ! $last || $last >= $today );
	} ) );
	return $limit > 0 ? array_slice( $posts, 0, $limit ) : $posts;
}

add_action( 'init', function () {
	add_shortcode( 'ws_events', function ( $atts ) {
		$atts   = shortcode_atts( array( 'limit' => -1, 'past' => 0, 'recent' => 0 ), (array) $atts );
		$past   = (bool) $atts['past'];
		$events = ws_get_events( $past, (int) $atts['limit'] );
		if ( ! $events && ! $past && $atts['recent'] ) {
			$recent = ws_get_events( true, (int) $atts['limit'] );
			if ( $recent ) {
				return '<p class="ws-muted ws-events-note">' . esc_html__( 'No upcoming events announced yet. Recent events:', 'ws-chapters' ) . '</p><div class="ws-event-list">' . implode( '', array_map( 'ws_event_card', wp_list_pluck( $recent, 'ID' ) ) ) . '</div>';
			}
		}
		if ( ! $events ) {
			return '<p class="ws-chapter-empty">' . ( $past ? esc_html__( 'No past events yet.', 'ws-chapters' ) : esc_html__( 'No upcoming events announced yet.', 'ws-chapters' ) ) . '</p>';
		}
		return '<div class="ws-event-list">' . implode( '', array_map( 'ws_event_card', wp_list_pluck( $events, 'ID' ) ) ) . '</div>';
	} );

	add_shortcode( 'ws_events_url', function () {
		return esc_url( get_post_type_archive_link( 'ws_event' ) );
	} );

	add_shortcode( 'ws_event_details', function () {
		$id = get_the_ID();
		if ( ! $id || 'ws_event' !== get_post_type( $id ) ) {
			return '';
		}
		$time  = get_post_meta( $id, 'start_time', true );
		$venue = get_post_meta( $id, 'venue', true );
		$where = esc_html( ws_event_place( $id ) );
		if ( wp_http_validate_url( $venue ) ) {
			$where .= ( $where ? '<br>' : '' ) . '<a href="' . esc_url( $venue ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $venue ) ) ) . '</a>';
		}
		$rows = array_filter( array(
			__( 'When', 'ws-chapters' )         => esc_html( ws_event_date_range( $id ) ) . ( $time ? ' · ' . esc_html( sprintf( __( 'from %s', 'ws-chapters' ), $time ) ) : '' ),
			__( 'Where', 'ws-chapters' )        => $where,
			__( 'Organised by', 'ws-chapters' ) => esc_html( get_post_meta( $id, 'organiser', true ) ),
			__( 'Contact', 'ws-chapters' )      => ws_event_contact_html( get_post_meta( $id, 'contact', true ) ),
		) );
		$out = '<dl class="ws-event-details">';
		foreach ( $rows as $label => $value ) {
			$out .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
		}
		return $out . '</dl>' . ws_event_host( $id );
	} );
} );

/* -------------------------------------------------------------------------
 * Admin
 * ---------------------------------------------------------------------- */

add_action( 'add_meta_boxes_ws_event', function () {
	add_meta_box( 'ws_event_details', __( 'Event details', 'ws-chapters' ), function ( $post ) {
		wp_nonce_field( 'ws_event_details', 'ws_event_details_nonce' );
		$v = function ( $key ) use ( $post ) {
			return esc_attr( get_post_meta( $post->ID, $key, true ) );
		};
		echo '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px">';
		printf( '<p><label for="ws_start_date"><strong>%s</strong></label><br><input type="date" id="ws_start_date" name="ws_start_date" value="%s" class="widefat"></p>', esc_html__( 'Start date', 'ws-chapters' ), $v( 'start_date' ) );
		printf( '<p><label for="ws_end_date"><strong>%s</strong></label><br><input type="date" id="ws_end_date" name="ws_end_date" value="%s" class="widefat"></p>', esc_html__( 'End date (optional)', 'ws-chapters' ), $v( 'end_date' ) );
		printf( '<p><label for="ws_start_time"><strong>%s</strong></label><br><input type="time" id="ws_start_time" name="ws_start_time" value="%s" class="widefat"></p>', esc_html__( 'Start time (optional)', 'ws-chapters' ), $v( 'start_time' ) );
		printf( '<p><label for="ws_organiser"><strong>%s</strong></label><br><input type="text" id="ws_organiser" name="ws_organiser" value="%s" class="widefat"></p>', esc_html__( 'Organising chapter / club', 'ws-chapters' ), $v( 'organiser' ) );
		printf( '<p><label for="ws_venue"><strong>%s</strong></label><br><input type="text" id="ws_venue" name="ws_venue" value="%s" class="widefat"></p>', esc_html__( 'Venue (address or website)', 'ws-chapters' ), $v( 'venue' ) );
		printf( '<p><label for="ws_city"><strong>%s</strong></label><br><input type="text" id="ws_city" name="ws_city" value="%s" class="widefat"></p>', esc_html__( 'City, country', 'ws-chapters' ), $v( 'city' ) );
		echo '</div>';
		printf( '<p><label for="ws_contact"><strong>%s</strong></label><br><textarea id="ws_contact" name="ws_contact" rows="3" class="widefat">%s</textarea></p>', esc_html__( 'Contact details', 'ws-chapters' ), esc_textarea( get_post_meta( $post->ID, 'contact', true ) ) );

		$host     = (int) get_post_meta( $post->ID, 'host_chapter', true );
		$chapters = get_posts( array( 'post_type' => 'ws_chapter', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<p><label for="ws_host_chapter"><strong>' . esc_html__( 'Host chapter', 'ws-chapters' ) . '</strong></label><br><select id="ws_host_chapter" name="ws_host_chapter"><option value="0">' . esc_html__( '— None —', 'ws-chapters' ) . '</option>';
		foreach ( $chapters as $c ) {
			printf( '<option value="%d"%s>%s</option>', $c->ID, selected( $host, $c->ID, false ), esc_html( $c->post_title ) );
		}
		echo '</select></p><p class="description">' . esc_html__( "The host chapter's official logo is shown with the event.", 'ws-chapters' ) . '</p>';
	}, 'ws_event', 'normal', 'high' );
} );

add_action( 'save_post_ws_event', function ( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['ws_event_details_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ws_event_details_nonce'] ), 'ws_event_details' ) ) {
		return;
	}
	foreach ( array( 'start_date', 'end_date', 'start_time', 'venue', 'city', 'organiser', 'contact', 'host_chapter' ) as $key ) {
		if ( isset( $_POST[ 'ws_' . $key ] ) ) {
			update_post_meta( $post_id, $key, wp_unslash( $_POST[ 'ws_' . $key ] ) ); // Sanitized by register_post_meta.
		}
	}
} );

add_filter( 'manage_ws_event_posts_columns', function ( $cols ) {
	return array_slice( $cols, 0, 2 ) + array( 'ws_when' => __( 'When', 'ws-chapters' ), 'ws_host' => __( 'Host chapter', 'ws-chapters' ) ) + $cols;
} );
add_action( 'manage_ws_event_posts_custom_column', function ( $col, $post_id ) {
	if ( 'ws_when' === $col ) {
		echo esc_html( ws_event_date_range( $post_id ) );
	} elseif ( 'ws_host' === $col ) {
		$host = (int) get_post_meta( $post_id, 'host_chapter', true );
		echo $host ? esc_html( get_the_title( $host ) ) : '—';
	}
}, 10, 2 );
