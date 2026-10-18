<?php
/**
 * Page sections for the country and chapter pages and the Europe map panel.
 *
 * [ws_europe_map]        Home/Chapters: "Find Widows Sons across Europe" — country list + gold map.
 * [ws_country_page]      Country page body: hero, tabs (Overview, Chapters, Events, News, Gallery, Contact).
 * [ws_chapter_profile]   Chapter page body: hero, back patch + logo + contact, tabs.
 * [ws_news country="" chapter="" limit="3"]   News cards.
 */

defined( 'ABSPATH' ) || exit;

const WS_TOPOJSON_URL     = 'https://cdn.jsdelivr.net/npm/topojson-client@3.1.0/dist/topojson-client.min.js';
const WS_TOPOJSON_SRI     = 'sha384-Ukv1p/xTma6P4/2bY5KzWBw+ydSpXmhCMtyciIQVDJ1RmOxtCYNMF1uXT9T63H67';
const WS_WORLD_ATLAS_URL  = 'https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-50m.json';
const WS_WORLD_ATLAS_SRI  = 'sha384-Aw4s9pX1PTPntIYkZ/qV9IYiF5Gv8eTl6Dd/TT56zfO1Wwd+owFwYUuuXNUMrWkc';

/** ISO 3166-1 alpha-2 => numeric, as used by the world-atlas country shapes. */
function ws_iso_numeric( $alpha2 ) {
	$map = array(
		'at' => '040', 'be' => '056', 'bg' => '100', 'hr' => '191', 'cy' => '196', 'cz' => '203', 'dk' => '208',
		'ee' => '233', 'fi' => '246', 'fr' => '250', 'de' => '276', 'gi' => '292', 'gr' => '300', 'hu' => '348',
		'is' => '352', 'ie' => '372', 'it' => '380', 'lv' => '428', 'lt' => '440', 'lu' => '442', 'mt' => '470',
		'nl' => '528', 'no' => '578', 'pl' => '616', 'pt' => '620', 'ro' => '642', 'ru' => '643', 'rs' => '688',
		'sk' => '703', 'si' => '705', 'es' => '724', 'se' => '752', 'ch' => '756', 'gb' => '826', 'ua' => '804',
		'md' => '498', 'me' => '499', 'mk' => '807', 'al' => '008', 'ba' => '070', 'tr' => '792',
	);
	return $map[ strtolower( (string) $alpha2 ) ] ?? '';
}

add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	return 'topojson-client' === $handle ? str_replace( ' src=', ' integrity="' . WS_TOPOJSON_SRI . '" crossorigin="anonymous" src=', $tag ) : $tag;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Map data
 * ---------------------------------------------------------------------- */

/** Published chapters in a country (the term count also includes its events and news). */
function ws_chapter_count( $term ) {
	return count( ws_get_chapters( $term->slug ) );
}

/** Countries that have chapters: name, flag, shape id, chapter count, national logo, marker position. */
function ws_country_map_data() {
	$out = array();
	foreach ( get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => true, 'orderby' => 'name' ) ) as $term ) {
		$lat = array();
		$lng = array();
		foreach ( ws_get_chapters( $term->slug ) as $c ) {
			$pos = ws_chapter_position( $c->ID );
			if ( $pos ) {
				$lat[] = $pos[0];
				$lng[] = $pos[1];
			}
		}
		$logo   = (int) get_term_meta( $term->term_id, 'national_logo', true );
		$code   = ws_country_code( $term );
		$count  = ws_chapter_count( $term );
		if ( ! $count ) {
			continue;
		}
		$out[]  = array(
			'name'  => $term->name,
			'url'   => get_term_link( $term ),
			'count' => $count,
			'flag'  => $code,
			'shape' => ws_iso_numeric( $code ),
			'logo'  => $logo && wp_attachment_is_image( $logo ) ? wp_get_attachment_image_url( $logo, 'ws-chapter-card' ) : ( ws_default_emblem_url() ?: null ),
			'lat'   => $lat ? array_sum( $lat ) / count( $lat ) : null,
			'lng'   => $lng ? array_sum( $lng ) / count( $lng ) : null,
		);
	}
	return $out;
}

/** Map element. mode=countries (Europe) or chapters (one country / one chapter). */
function ws_map_element( $mode, $points, $highlight, $height = 560, $focus = null ) {
	wp_enqueue_style( 'leaflet' );
	wp_enqueue_script( 'ws-chapter-map' );
	$labels = array(
		'pending'  => __( 'Official logo pending', 'ws-chapters' ),
		'profile'  => __( 'Explore chapter', 'ws-chapters' ),
		'country'  => __( 'View country', 'ws-chapters' ),
		/* translators: used as "4 chapters" */
		'chapters' => __( 'chapters', 'ws-chapters' ),
		'chapter'  => __( 'chapter', 'ws-chapters' ),
	);
	return sprintf(
		'<div class="ws-chapter-map ws-chapter-map--%1$s" style="--ws-map-height:%2$dpx" data-mode="%1$s" data-points="%3$s" data-highlight="%4$s" data-focus="%5$s" data-labels="%6$s" role="region" aria-label="%7$s"></div>',
		esc_attr( $mode ),
		max( 240, (int) $height ),
		esc_attr( wp_json_encode( $points ) ),
		esc_attr( wp_json_encode( array_values( array_filter( $highlight ) ) ) ),
		esc_attr( wp_json_encode( $focus ) ),
		esc_attr( wp_json_encode( $labels ) ),
		esc_attr__( 'Map of Widows Sons in Europe', 'ws-chapters' )
	);
}

/** Shape IDs of every country that has chapters. */
function ws_member_shapes() {
	return array_column( ws_country_map_data(), 'shape' );
}

/* -------------------------------------------------------------------------
 * Small pieces
 * ---------------------------------------------------------------------- */

function ws_icon( $name ) {
	$paths = array(
		'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'globe'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18"/>',
		'facebook'  => '<path d="M14 8h3V4h-3a4 4 0 0 0-4 4v3H7v4h3v6h4v-6h3l1-4h-4V8z"/>',
		'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r=".5"/>',
		'pin'       => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
		'arrow'     => '<path d="M5 12h14M13 6l6 6-6 6"/>',
	);
	return '<svg class="ws-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
}

/** Contact list from a getter: email, website, Facebook, Instagram. */
function ws_contact_list( $get ) {
	$items = array();
	foreach ( ws_contact_fields() as $key => $field ) {
		$value = $get( $key );
		if ( ! $value ) {
			continue;
		}
		$href  = 'email' === $key ? 'mailto:' . antispambot( $value ) : $value;
		$text  = 'email' === $key ? antispambot( $value ) : ( in_array( $key, array( 'facebook', 'instagram' ), true ) ? $field[0] : preg_replace( '#^https?://(www\.)?#', '', untrailingslashit( $value ) ) );
		$items[] = sprintf( '<li><a href="%s"%s>%s<span>%s</span></a></li>', esc_url( $href, array( 'http', 'https', 'mailto' ) ), 'email' === $key ? '' : ' target="_blank" rel="noopener"', ws_icon( 'email' === $key ? 'mail' : ( 'website' === $key ? 'globe' : $key ) ), esc_html( $text ) );
	}
	return $items
		? '<ul class="ws-contact-list">' . implode( '', $items ) . '</ul>'
		: '<p class="ws-muted">' . esc_html__( 'Contact details coming soon.', 'ws-chapters' ) . '</p>';
}

function ws_gallery_grid( $ids ) {
	if ( ! $ids ) {
		return '<p class="ws-muted">' . esc_html__( 'Photos coming soon.', 'ws-chapters' ) . '</p>';
	}
	$out = '<div class="ws-gallery-grid">';
	foreach ( $ids as $id ) {
		$out .= sprintf( '<a href="%s">%s</a>', esc_url( wp_get_attachment_image_url( $id, 'large' ) ), wp_get_attachment_image( $id, 'medium_large', false, array( 'loading' => 'lazy' ) ) );
	}
	return $out . '</div>';
}

/** News cards for a country and/or chapter. */
function ws_news_cards( $country = 0, $chapter = 0, $limit = 3 ) {
	$args = array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $limit, 'no_found_rows' => true );
	if ( $chapter ) {
		$args['meta_query'] = array( array( 'key' => 'related_chapter', 'value' => (int) $chapter ) );
	} elseif ( $country ) {
		$args['tax_query'] = array( array( 'taxonomy' => 'ws_country', 'terms' => (int) $country ) );
	}
	$posts = get_posts( $args );
	if ( ! $posts ) {
		return '<p class="ws-muted">' . esc_html__( 'No news yet.', 'ws-chapters' ) . '</p>';
	}
	$out = '<div class="ws-news-cards">';
	foreach ( $posts as $p ) {
		$out .= sprintf(
			'<article class="ws-news-card"><time datetime="%1$s">%2$s</time><h3><a href="%3$s">%4$s</a></h3><p>%5$s</p></article>',
			esc_attr( get_the_date( 'c', $p ) ),
			esc_html( get_the_date( '', $p ) ),
			esc_url( get_permalink( $p ) ),
			esc_html( get_the_title( $p ) ),
			esc_html( wp_trim_words( get_the_excerpt( $p ), 24 ) )
		);
	}
	return $out . '</div>';
}

/** Events for a country (term ID) or a chapter (post ID): upcoming first, then past. */
function ws_event_cards_for( $country = 0, $chapter = 0 ) {
	$match = function ( $e ) use ( $country, $chapter ) {
		$scope = ws_item_scope( $e );
		return $chapter ? $scope['chapter'] === (int) $chapter : in_array( (int) $country, $scope['countries'], true );
	};
	$cards    = function ( $events ) {
		return '<div class="ws-event-list">' . implode( '', array_map( 'ws_event_card', wp_list_pluck( $events, 'ID' ) ) ) . '</div>';
	};
	$upcoming = array_filter( ws_get_events(), $match );
	$past     = array_filter( ws_get_events( true ), $match );
	$out      = $upcoming ? $cards( $upcoming ) : '<p class="ws-muted">' . esc_html__( 'No upcoming events announced yet.', 'ws-chapters' ) . '</p>';
	if ( $past ) {
		$out .= '<h3 class="ws-subhead">' . esc_html__( 'Past events', 'ws-chapters' ) . '</h3>' . $cards( $past );
	}
	return $out;
}

/** Tabs: a nav plus sections. Without JavaScript every section simply shows in order. */
function ws_tabs( $id, $tabs ) {
	$nav  = '<nav class="ws-tabs__nav" role="tablist" aria-label="' . esc_attr__( 'Page sections', 'ws-chapters' ) . '">';
	$body = '';
	$i    = 0;
	foreach ( $tabs as $slug => $tab ) {
		$tid  = $id . '-' . $slug;
		$nav .= sprintf( '<a href="#%1$s" role="tab" id="%1$s-tab" aria-controls="%1$s" aria-selected="%2$s">%3$s</a>', esc_attr( $tid ), 0 === $i ? 'true' : 'false', esc_html( $tab[0] ) );
		$body .= sprintf( '<section class="ws-tabs__panel" id="%1$s" role="tabpanel" aria-labelledby="%1$s-tab"><h2 class="ws-tabs__heading">%2$s</h2>%3$s</section>', esc_attr( $tid ), esc_html( $tab[0] ), $tab[1] );
		$i++;
	}
	wp_enqueue_script( 'ws-tabs', plugin_dir_url( __DIR__ ) . 'assets/ws-tabs.js', array(), filemtime( plugin_dir_path( __DIR__ ) . 'assets/ws-tabs.js' ), true );
	return '<div class="ws-tabs" data-ws-tabs>' . $nav . '</nav>' . $body . '</div>';
}

/** Full-width page hero with a photo (or dark fallback), a round emblem and a title. */
function ws_page_hero( $photo_id, $emblem_html, $title, $sub_html ) {
	$bg = $photo_id ? wp_get_attachment_image_url( $photo_id, 'full' ) : '';
	return sprintf(
		'<header class="ws-page-hero%1$s"%2$s><div class="ws-page-hero__inner">%3$s<div class="ws-page-hero__text"><h1 class="ws-page-hero__title">%4$s</h1>%5$s</div></div></header>',
		$bg ? '' : ' ws-page-hero--plain',
		$bg ? ' style="--ws-hero-image:url(\'' . esc_url( $bg ) . '\')"' : '',
		$emblem_html ? '<div class="ws-page-hero__emblem">' . $emblem_html . '</div>' : '',
		esc_html( $title ),
		$sub_html
	);
}

/** "Widows Sons Cyprus" unless the name already says Widows Sons. */
function ws_display_name( $name ) {
	return stripos( $name, 'widows sons' ) === 0 ? $name : 'Widows Sons ' . $name;
}

/* -------------------------------------------------------------------------
 * Sections
 * ---------------------------------------------------------------------- */

/** Home / Chapters: country list + gold map. */
function ws_europe_map_panel() {
	$countries = ws_country_map_data();
	$list      = '';
	foreach ( $countries as $c ) {
		$list .= sprintf(
			'<li><a href="%s">%s<span class="ws-country-list__name">%s</span><span class="ws-country-list__count">%s</span></a></li>',
			esc_url( $c['url'] ),
			$c['flag'] ? '<span class="ws-flag fi fi-' . esc_attr( $c['flag'] ) . '" aria-hidden="true"></span>' : '',
			esc_html( $c['name'] ),
			/* translators: %d: number of chapters */
			esc_html( sprintf( _n( '%d chapter', '%d chapters', $c['count'], 'ws-chapters' ), $c['count'] ) )
		);
	}
	return '<div class="ws-europe-map"><div class="ws-europe-map__side"><h2 class="ws-europe-map__title">' . esc_html__( 'Our Brotherhood Across Europe', 'ws-chapters' ) . '</h2>'
		. '<p>' . esc_html__( 'Explore participating countries and their chapters. Select a country to find chapter information, news and events.', 'ws-chapters' ) . '</p>'
		. '<ul class="ws-country-list">' . $list . '</ul></div>'
		. '<div class="ws-europe-map__map">' . ws_map_element( 'countries', $countries, array_column( $countries, 'shape' ), 620 ) . '</div></div>';
}

/** Chapter cards with photo, as on the country page. */
function ws_chapter_rows( $chapters ) {
	if ( ! $chapters ) {
		return '<p class="ws-muted">' . esc_html__( 'No chapters listed yet.', 'ws-chapters' ) . '</p>';
	}
	$out = '<div class="ws-chapter-rows">';
	foreach ( $chapters as $c ) {
		$photo = get_post_thumbnail_id( $c );
		$out  .= sprintf(
			'<article class="ws-chapter-row"><div class="ws-chapter-row__logo">%1$s</div><div class="ws-chapter-row__body"><h3><a href="%2$s">%3$s</a></h3><p class="ws-muted">%4$s</p><a class="ws-link-arrow" href="%2$s">%5$s %6$s</a></div>%7$s</article>',
			ws_chapter_logo( $c->ID ),
			esc_url( get_permalink( $c ) ),
			esc_html( ws_display_name( get_the_title( $c ) ) ),
			esc_html( get_post_meta( $c->ID, 'city', true ) ),
			esc_html__( 'Explore chapter', 'ws-chapters' ),
			ws_icon( 'arrow' ),
			$photo ? '<div class="ws-chapter-row__photo">' . wp_get_attachment_image( $photo, 'medium', false, array( 'loading' => 'lazy' ) ) . '</div>' : ''
		);
	}
	return $out . '</div>';
}

function ws_country_page() {
	$term = is_tax( 'ws_country' ) ? get_queried_object() : null;
	if ( ! $term ) {
		return '';
	}
	$get      = function ( $k ) use ( $term ) {
		return get_term_meta( $term->term_id, $k, true );
	};
	$chapters = ws_get_chapters( $term->slug );
	$gallery  = ws_gallery_ids( $get( 'gallery' ) );
	$about    = term_description( $term ) ?: '<p>' . esc_html( sprintf(
		/* translators: %s: country name */
		__( '%s brings together Masonic motorcycle brothers. We ride for brotherhood, support our communities, and take part in national and international events.', 'ws-chapters' ),
		ws_display_name( $term->name )
	) ) . '</p>';

	$hero = ws_page_hero(
		(int) $get( 'hero_image' ),
		'<div class="ws-emblem"><span class="ws-emblem__inner">' . ws_country_logo( $term->term_id ) . '</span></div>',
		ws_display_name( $term->name ),
		'<p class="ws-page-hero__tagline">' . esc_html__( 'Brotherhood · Motorcycling · Service', 'ws-chapters' ) . '</p>'
	);

	/* translators: 1: country name, 2: number of chapters */
	$chapters_title = sprintf( __( 'Chapters in %1$s (%2$d)', 'ws-chapters' ), $term->name, count( $chapters ) );
	$overview       = '<div class="ws-overview"><div class="ws-overview__main"><h3>' . esc_html( sprintf( __( 'About %s', 'ws-chapters' ), ws_display_name( $term->name ) ) ) . '</h3><div class="ws-prose">' . $about . '</div></div>'
		. ( $gallery ? '<div class="ws-overview__photo">' . wp_get_attachment_image( $gallery[0], 'medium_large' ) . '</div>' : '' )
		. '<aside class="ws-overview__contact"><h3>' . esc_html__( 'Official contacts and links', 'ws-chapters' ) . '</h3>' . ws_contact_list( $get ) . '</aside></div>'
		. '<h3 class="ws-subhead">' . esc_html( $chapters_title ) . '</h3>' . ws_chapter_rows( $chapters );

	$map = $chapters ? ws_map_element( 'chapters', ws_chapter_map_data( $term->slug ), array( ws_iso_numeric( ws_country_code( $term ) ) ), 440 ) : '';

	return $hero . '<div class="ws-page-body">' . ws_tabs( 'country', array(
		'overview' => array( __( 'Overview', 'ws-chapters' ), $overview ),
		'chapters' => array( __( 'Chapters', 'ws-chapters' ), $map . ws_chapter_rows( $chapters ) ),
		'events'   => array( __( 'Events', 'ws-chapters' ), ws_event_cards_for( $term->term_id ) ),
		'news'     => array( __( 'News', 'ws-chapters' ), ws_news_cards( $term->term_id, 0, 6 ) ),
		'gallery'  => array( __( 'Gallery', 'ws-chapters' ), ws_gallery_grid( $gallery ) ),
		'contact'  => array( __( 'Contact', 'ws-chapters' ), ws_contact_list( $get ) ),
	) ) . '</div>';
}

function ws_chapter_profile() {
	$id = get_the_ID();
	if ( ! $id || 'ws_chapter' !== get_post_type( $id ) ) {
		return '';
	}
	$get     = function ( $k ) use ( $id ) {
		return get_post_meta( $id, $k, true );
	};
	$country = ws_chapter_country( $id );
	$city    = $get( 'city' );
	$where   = implode( ', ', array_filter( array( $city, $country ? $country->name : '' ) ) );
	$gallery = ws_gallery_ids( $get( 'gallery' ) );
	$pos     = ws_chapter_position( $id );
	$has_map = (bool) $pos;
	list( $lat, $lng ) = $pos ?: array( 0, 0 );

	$hero = ws_page_hero(
		(int) get_post_thumbnail_id( $id ),
		'',
		ws_display_name( get_the_title( $id ) ),
		$where ? '<p class="ws-page-hero__place">' . ws_icon( 'pin' ) . ' ' . esc_html( $where ) . '</p>' : ''
	);

	$identity = '<div class="ws-identity">'
		. '<figure class="ws-identity__item"><figcaption><strong>' . esc_html__( 'Official Chapter Patch', 'ws-chapters' ) . '</strong><span>' . esc_html__( '(Insignia worn by members)', 'ws-chapters' ) . '</span></figcaption>' . ws_chapter_back_patch( $id ) . '<p>' . esc_html__( 'The chapter’s official patch, displayed as supplied by the chapter.', 'ws-chapters' ) . '</p></figure>'
		. '<figure class="ws-identity__item"><figcaption><strong>' . esc_html__( 'Official Chapter Logo', 'ws-chapters' ) . '</strong><span>' . esc_html__( '(Chapter visual identity)', 'ws-chapters' ) . '</span></figcaption>' . ws_chapter_logo( $id ) . '<p>' . esc_html__( 'The chapter’s official logo, separate from its back patch and displayed as supplied.', 'ws-chapters' ) . '</p></figure>'
		. '<aside class="ws-identity__contact"><h3>' . esc_html__( 'Official Contact Information', 'ws-chapters' ) . '</h3>' . ws_contact_list( $get )
		. ( $has_map ? '<a class="ws-btn-gold" href="#chapter-map">' . esc_html__( 'Show on map', 'ws-chapters' ) . ' ' . ws_icon( 'arrow' ) . '</a>' : '' )
		. ( ws_join_url( $id ) ? '<a class="ws-btn-outline" href="' . esc_url( ws_join_url( $id ) ) . '">' . esc_html__( 'Apply to join this chapter', 'ws-chapters' ) . '</a>' : '' )
		. ( $country ? '<p class="ws-identity__country">' . ws_flag( $country ) . ' <a href="' . esc_url( get_term_link( $country ) ) . '">' . esc_html( ws_display_name( $country->name ) ) . '</a></p>' : '' )
		. '</aside></div>';

	$content = apply_filters( 'the_content', get_post_field( 'post_content', $id ) );
	$about   = '<div class="ws-overview"><div class="ws-overview__main"><h3>' . esc_html( sprintf( __( 'About %s', 'ws-chapters' ), ws_display_name( get_the_title( $id ) ) ) ) . '</h3><div class="ws-prose">' . $content . '</div></div>'
		. ( $gallery ? '<div class="ws-overview__photo">' . wp_get_attachment_image( $gallery[0], 'medium_large' ) . '</div>' : '' ) . '</div>'
		. ( $has_map ? '<div id="chapter-map" class="ws-subhead-map">' . ws_map_element( 'chapters', array( ws_chapter_point( $id ) ), array( ws_iso_numeric( ws_country_code( $country ) ) ), 360, array( (float) $lat, (float) $lng ) ) . '</div>' : '' );

	return $hero . '<div class="ws-page-body">' . $identity . ws_tabs( 'chapter', array(
		'about'   => array( __( 'About', 'ws-chapters' ), $about ),
		'events'  => array( __( 'Events', 'ws-chapters' ), ws_event_cards_for( 0, $id ) ),
		'news'    => array( __( 'News & Activities', 'ws-chapters' ), ws_news_cards( 0, $id, 6 ) ),
		'gallery' => array( __( 'Gallery', 'ws-chapters' ), ws_gallery_grid( $gallery ) ),
		'contact' => array( __( 'Contact', 'ws-chapters' ), ws_contact_list( $get ) ),
	) ) . '</div>';
}

add_action( 'init', function () {
	add_shortcode( 'ws_europe_map', 'ws_europe_map_panel' );
	add_shortcode( 'ws_country_page', 'ws_country_page' );
	add_shortcode( 'ws_chapter_profile', 'ws_chapter_profile' );
	add_shortcode( 'ws_news', function ( $atts ) {
		$atts = shortcode_atts( array( 'country' => '', 'chapter' => 0, 'limit' => 3 ), (array) $atts );
		$term = $atts['country'] ? get_term_by( 'slug', sanitize_title( $atts['country'] ), 'ws_country' ) : null;
		return ws_news_cards( $term ? $term->term_id : 0, (int) $atts['chapter'], (int) $atts['limit'] );
	} );
} );
