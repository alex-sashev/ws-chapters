<?php
/**
 * Front-end display: chapter cards, directory, European map, and shortcodes so any theme
 * (block or classic) can place them.
 *
 * [ws_back_patch]            Official back patch of the current chapter (profile pages).
 * [ws_chapter_logo]          Official chapter logo of the current chapter.
 * [ws_country_logo]          National logo: current country archive, else the chapter's country.
 * [ws_chapter_meta]          "City · Country" line for the current chapter.
 * [ws_chapter_directory]     Chapter cards grouped by country (country="slug" to filter).
 * [ws_chapter_map]           Interactive map of Europe with each chapter's logo (height, country).
 * [ws_chapters_url]          URL of the all-chapters page (for links in templates).
 */

defined( 'ABSPATH' ) || exit;

const WS_LEAFLET_VERSION = '1.9.4';

add_action( 'wp_enqueue_scripts', function () {
	$url = plugin_dir_url( __DIR__ );
	$dir = plugin_dir_path( __DIR__ );
	wp_enqueue_style( 'ws-chapters', $url . 'assets/ws-chapters.css', array(), filemtime( $dir . 'assets/ws-chapters.css' ) );

	$cdn = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/' . WS_LEAFLET_VERSION . '/';
	wp_register_style( 'leaflet', $cdn . 'leaflet.min.css', array(), null );
	wp_register_script( 'leaflet', $cdn . 'leaflet.min.js', array(), null, true );
	wp_register_script( 'ws-chapter-map', $url . 'assets/ws-map.js', array( 'leaflet' ), filemtime( $dir . 'assets/ws-map.js' ), true );
} );

/** Subresource integrity for the pinned Leaflet files. */
$ws_leaflet_sri = array(
	'leaflet' => 'sha512-puJW3E/qXDqYp9IfhAI54BJEaWIfloJ7JWs7OeD5i6ruC9JZL1gERT1wjtwXFlh7CjE7ZJ+/vcRZRkIYIb6p4g==',
	'leaflet-css' => 'sha512-h9FcoyWjHcOcmEVkxOfTLnmZFWIH0iZhZT1H2TbOq55xssQGEJHEaIm+PgoUaZbRvQTNTluNOEfb1ZRy6D3BOw==',
);
add_filter( 'script_loader_tag', function ( $tag, $handle ) use ( $ws_leaflet_sri ) {
	return 'leaflet' === $handle ? str_replace( ' src=', ' integrity="' . $ws_leaflet_sri['leaflet'] . '" crossorigin="anonymous" src=', $tag ) : $tag;
}, 10, 2 );
add_filter( 'style_loader_tag', function ( $tag, $handle ) use ( $ws_leaflet_sri ) {
	return 'leaflet' === $handle ? str_replace( ' href=', ' integrity="' . $ws_leaflet_sri['leaflet-css'] . '" crossorigin="anonymous" href=', $tag ) : $tag;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Building blocks
 * ---------------------------------------------------------------------- */

/** The chapter's country term (first one), or null. */
function ws_chapter_country( $chapter_id ) {
	$terms = get_the_terms( $chapter_id, 'ws_country' );
	return $terms && ! is_wp_error( $terms ) ? $terms[0] : null;
}

/** "City · Country" for a chapter, country linked to its archive. */
function ws_chapter_meta_line( $chapter_id, $link_country = true ) {
	$parts = array();
	$city  = get_post_meta( $chapter_id, 'city', true );
	if ( $city ) {
		$parts[] = esc_html( $city );
	}
	$country = ws_chapter_country( $chapter_id );
	if ( $country ) {
		$parts[] = $link_country
			? sprintf( '<a href="%s">%s</a>', esc_url( get_term_link( $country ) ), esc_html( $country->name ) )
			: esc_html( $country->name );
	}
	return $parts ? '<p class="ws-chapter-meta">' . implode( ' <span aria-hidden="true">·</span> ', $parts ) . '</p>' : '';
}

/** Directory card: chapter logo (never the back patch), name, location. */
function ws_chapter_card( $chapter_id ) {
	return sprintf(
		'<article class="ws-chapter-card"><a class="ws-chapter-card__link" href="%1$s"><div class="ws-chapter-card__logo">%2$s</div><h3 class="ws-chapter-card__name">%3$s</h3></a>%4$s</article>',
		esc_url( get_permalink( $chapter_id ) ),
		ws_chapter_logo( $chapter_id ),
		esc_html( get_the_title( $chapter_id ) ),
		ws_chapter_meta_line( $chapter_id, false )
	);
}

/** Published chapters, optionally limited to one country slug. */
function ws_get_chapters( $country = '' ) {
	$args = array(
		'post_type'      => 'ws_chapter',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	);
	if ( $country ) {
		$args['tax_query'] = array( array( 'taxonomy' => 'ws_country', 'field' => 'slug', 'terms' => $country ) );
	}
	return get_posts( $args );
}

/** Country slug from a shortcode attribute, falling back to the country archive being viewed. */
function ws_resolve_country( $atts ) {
	if ( ! empty( $atts['country'] ) ) {
		return sanitize_title( $atts['country'] );
	}
	$term = is_tax( 'ws_country' ) ? get_queried_object() : null;
	return $term ? $term->slug : '';
}

/** Chapter cards; grouped under country headings when showing more than one country. */
function ws_chapter_directory( $country = '' ) {
	$chapters = ws_get_chapters( $country );
	if ( ! $chapters ) {
		return '<p class="ws-chapter-empty">' . esc_html__( 'No chapters listed yet.', 'ws-chapters' ) . '</p>';
	}
	if ( $country ) {
		return '<div class="ws-chapter-grid">' . implode( '', array_map( 'ws_chapter_card', wp_list_pluck( $chapters, 'ID' ) ) ) . '</div>';
	}

	$groups = array();
	foreach ( $chapters as $chapter ) {
		$term  = ws_chapter_country( $chapter->ID );
		$key   = $term ? $term->name : '~';
		$groups[ $key ]['term']   = $term;
		$groups[ $key ]['ids'][]  = $chapter->ID;
	}
	ksort( $groups, SORT_LOCALE_STRING );

	$out = '';
	foreach ( $groups as $group ) {
		$term   = $group['term'];
		$title  = $term ? sprintf( '<a href="%s">%s</a>', esc_url( get_term_link( $term ) ), esc_html( $term->name ) ) : esc_html__( 'Other', 'ws-chapters' );
		$out   .= '<section class="ws-chapter-group"><h2 class="ws-chapter-group__title">' . $title . '</h2>';
		$out   .= '<div class="ws-chapter-grid">' . implode( '', array_map( 'ws_chapter_card', $group['ids'] ) ) . '</div></section>';
	}
	return $out;
}

/** Map markers: only chapters with coordinates. Logo is null while pending. */
function ws_chapter_map_data( $country = '' ) {
	$points = array();
	foreach ( ws_get_chapters( $country ) as $chapter ) {
		$lat = get_post_meta( $chapter->ID, 'latitude', true );
		$lng = get_post_meta( $chapter->ID, 'longitude', true );
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			continue;
		}
		$logo_id = (int) get_post_meta( $chapter->ID, 'chapter_logo', true );
		$term    = ws_chapter_country( $chapter->ID );
		$points[] = array(
			'name'    => get_the_title( $chapter->ID ),
			'url'     => get_permalink( $chapter->ID ),
			'city'    => (string) get_post_meta( $chapter->ID, 'city', true ),
			'country' => $term ? $term->name : '',
			'lat'     => (float) $lat,
			'lng'     => (float) $lng,
			'logo'    => $logo_id && wp_attachment_is_image( $logo_id ) ? wp_get_attachment_image_url( $logo_id, 'ws-chapter-card' ) : null,
		);
	}
	return $points;
}

function ws_chapter_map( $country = '', $height = 560 ) {
	wp_enqueue_style( 'leaflet' );
	wp_enqueue_script( 'ws-chapter-map' );
	$points = ws_chapter_map_data( $country );
	$labels = array(
		'pending' => __( 'Official chapter logo pending', 'ws-chapters' ),
		'profile' => __( 'Chapter profile', 'ws-chapters' ),
	);
	return sprintf(
		'<div class="ws-chapter-map" style="--ws-map-height:%1$dpx" data-points="%2$s" data-labels="%3$s" role="region" aria-label="%4$s"><noscript>%5$s</noscript></div>',
		max( 240, (int) $height ),
		esc_attr( wp_json_encode( $points ) ),
		esc_attr( wp_json_encode( $labels ) ),
		esc_attr__( 'Map of Widows Sons chapters in Europe', 'ws-chapters' ),
		esc_html__( 'The map needs JavaScript. All chapters are listed in the directory.', 'ws-chapters' )
	);
}

/* -------------------------------------------------------------------------
 * Shortcodes
 * ---------------------------------------------------------------------- */

/** Chapter ID from an id="" attribute or the current post. */
function ws_shortcode_chapter_id( $atts ) {
	$id = ! empty( $atts['id'] ) ? (int) $atts['id'] : get_the_ID();
	return $id && 'ws_chapter' === get_post_type( $id ) ? $id : 0;
}

add_action( 'init', function () {
	add_shortcode( 'ws_back_patch', function ( $atts ) {
		$id = ws_shortcode_chapter_id( (array) $atts );
		return $id ? '<div class="ws-back-patch">' . ws_chapter_back_patch( $id ) . '</div>' : '';
	} );

	add_shortcode( 'ws_chapter_logo', function ( $atts ) {
		$id = ws_shortcode_chapter_id( (array) $atts );
		return $id ? ws_chapter_logo( $id ) : '';
	} );

	add_shortcode( 'ws_chapter_meta', function ( $atts ) {
		$id = ws_shortcode_chapter_id( (array) $atts );
		return $id ? ws_chapter_meta_line( $id ) : '';
	} );

	add_shortcode( 'ws_country_logo', function ( $atts ) {
		$atts = (array) $atts;
		$term = null;
		if ( ! empty( $atts['country'] ) ) {
			$term = get_term_by( 'slug', sanitize_title( $atts['country'] ), 'ws_country' );
		} elseif ( is_tax( 'ws_country' ) ) {
			$term = get_queried_object();
		} elseif ( ws_shortcode_chapter_id( $atts ) ) {
			$term = ws_chapter_country( ws_shortcode_chapter_id( $atts ) );
		}
		return $term ? '<div class="ws-country-logo">' . ws_country_logo( $term->term_id ) . '</div>' : '';
	} );

	add_shortcode( 'ws_chapter_directory', function ( $atts ) {
		return ws_chapter_directory( ws_resolve_country( (array) $atts ) );
	} );

	add_shortcode( 'ws_chapters_url', function () {
		return esc_url( get_post_type_archive_link( 'ws_chapter' ) );
	} );

	add_shortcode( 'ws_chapter_map', function ( $atts ) {
		$atts = shortcode_atts( array( 'country' => '', 'height' => 560 ), (array) $atts );
		return ws_chapter_map( ws_resolve_country( $atts ), $atts['height'] );
	} );
} );
