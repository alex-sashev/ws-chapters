<?php
/**
 * Front-end display: chapter cards, directory, European map, and shortcodes so any theme
 * (block or classic) can place them.
 *
 * [ws_back_patch]            Official back patch of the current chapter (profile pages).
 * [ws_chapter_logo]          Official chapter logo of the current chapter.
 * [ws_country_logo]          National logo: current country archive, else the chapter's country.
 * [ws_chapter_meta]          "City · Country" line for the current chapter, with flag.
 * [ws_country_flag]          Flag of the Grand Chapter being viewed (or the chapter's).
 * [ws_chapter_directory]     Chapter cards grouped by country (country="slug" to filter).
 * [ws_chapter_map]           Interactive map of Europe with each chapter's logo (height, country).
 * [ws_chapters_url]          URL of the all-chapters page (for links in templates).
 * [ws_grand_chapters]        All countries (national Grand Chapters): flag, national logo, number of chapters.
 * [ws_stats]                 Key numbers: countries, chapters, founding year.
 */

defined( 'ABSPATH' ) || exit;

const WS_LEAFLET_VERSION = '1.9.4';
const WS_FLAG_ICONS_URL  = 'https://cdn.jsdelivr.net/npm/flag-icons@7.5.0/css/flag-icons.min.css';
const WS_FLAG_ICONS_SRI  = 'sha384-8olrmTcwXL3OYMeB+2ok8jx9/U/FZxeLnoRWdu1Pyi+lakrkqoI7hfysfQF5Q1Xx';

/** Flag stylesheet (SVG flags, which unlike emoji flags also show on Windows). Used on the site and the Grand Chapter admin screens. */
function ws_register_flag_style() {
	wp_register_style( 'flag-icons', WS_FLAG_ICONS_URL, array(), null );
}
add_action( 'admin_enqueue_scripts', function () {
	ws_register_flag_style();
	$screen = get_current_screen();
	if ( $screen && 'ws_country' === $screen->taxonomy ) {
		wp_enqueue_style( 'flag-icons' );
	}
} );

add_action( 'wp_enqueue_scripts', function () {
	$url = plugin_dir_url( __DIR__ );
	$dir = plugin_dir_path( __DIR__ );
	ws_register_flag_style();
	wp_enqueue_style( 'ws-chapters', $url . 'assets/ws-chapters.css', array( 'flag-icons' ), filemtime( $dir . 'assets/ws-chapters.css' ) );

	$cdn = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/' . WS_LEAFLET_VERSION . '/';
	wp_register_style( 'leaflet', $cdn . 'leaflet.min.css', array(), null );
	wp_register_script( 'leaflet', $cdn . 'leaflet.min.js', array(), null, true );
	wp_register_script( 'topojson-client', WS_TOPOJSON_URL, array(), null, true );
	wp_register_script( 'ws-chapter-map', $url . 'assets/ws-map.js', array( 'leaflet', 'topojson-client' ), filemtime( $dir . 'assets/ws-map.js' ), true );
	wp_localize_script( 'ws-chapter-map', 'wsMapConfig', array( 'atlas' => WS_WORLD_ATLAS_URL, 'atlasIntegrity' => WS_WORLD_ATLAS_SRI, 'pin' => $url . 'assets/ws-pin.png' ) );
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
	$sri = array( 'leaflet' => $ws_leaflet_sri['leaflet-css'], 'flag-icons' => WS_FLAG_ICONS_SRI );
	return isset( $sri[ $handle ] ) ? str_replace( ' href=', ' integrity="' . $sri[ $handle ] . '" crossorigin="anonymous" href=', $tag ) : $tag;
}, 10, 2 );

/* -------------------------------------------------------------------------
 * Building blocks
 * ---------------------------------------------------------------------- */

/** The chapter's country term (first one), or null. */
function ws_chapter_country( $chapter_id ) {
	$terms = get_the_terms( $chapter_id, 'ws_country' );
	return $terms && ! is_wp_error( $terms ) ? $terms[0] : null;
}

/** Flag code for a Grand Chapter term, or ''. */
function ws_country_code( $term ) {
	return $term ? (string) get_term_meta( $term->term_id, 'country_code', true ) : '';
}

/** Flag of a Grand Chapter, or '' if it has no country code. Decorative next to the name, labelled when alone. */
function ws_flag( $term, $labelled = false ) {
	$code = ws_country_code( $term );
	if ( ! $code ) {
		return '';
	}
	$a11y = $labelled
		/* translators: %s: Grand Chapter / country name */
		? sprintf( ' role="img" aria-label="%s"', esc_attr( sprintf( __( 'Flag of %s', 'ws-chapters' ), $term->name ) ) )
		: ' aria-hidden="true"';
	return sprintf( '<span class="ws-flag fi fi-%s"%s></span>', esc_attr( $code ), $a11y );
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
		$label   = ws_flag( $country ) . ' ' . esc_html( $country->name );
		$parts[] = $link_country
			? sprintf( '<a href="%s">%s</a>', esc_url( get_term_link( $country ) ), $label )
			: $label;
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
		$title  = $term ? sprintf( '<a href="%s">%s %s</a>', esc_url( get_term_link( $term ) ), ws_flag( $term ), esc_html( $term->name ) ) : esc_html__( 'Other', 'ws-chapters' );
		$out   .= '<section class="ws-chapter-group"><h2 class="ws-chapter-group__title">' . $title . '</h2>';
		$out   .= '<div class="ws-chapter-grid">' . implode( '', array_map( 'ws_chapter_card', $group['ids'] ) ) . '</div></section>';
	}
	return $out;
}

/** One chapter as a map marker, or null without coordinates. Without its own logo it shows the Widows Sons emblem. */
function ws_chapter_point( $chapter_id ) {
	$pos = ws_chapter_position( $chapter_id );
	if ( ! $pos ) {
		return null;
	}
	list( $lat, $lng ) = $pos;
	$logo_id = (int) get_post_meta( $chapter_id, 'chapter_logo', true );
	$term    = ws_chapter_country( $chapter_id );
	return array(
		'name'    => ws_display_name( get_the_title( $chapter_id ) ),
		'url'     => get_permalink( $chapter_id ),
		'city'    => (string) get_post_meta( $chapter_id, 'city', true ),
		'country' => $term ? $term->name : '',
		'flag'    => ws_country_code( $term ),
		'lat'     => (float) $lat,
		'lng'     => (float) $lng,
		'logo'    => $logo_id && wp_attachment_is_image( $logo_id ) ? wp_get_attachment_image_url( $logo_id, 'ws-chapter-card' ) : ( ws_default_emblem_url() ?: null ),
	);
}

/** Map markers: every chapter whose position is known (own coordinates, city, or country capital). */
function ws_chapter_map_data( $country = '' ) {
	return array_values( array_filter( array_map( 'ws_chapter_point', wp_list_pluck( ws_get_chapters( $country ), 'ID' ) ) ) );
}

/** Chapter map, with the chapters' countries highlighted in gold. */
function ws_chapter_map( $country = '', $height = 560 ) {
	$points = ws_chapter_map_data( $country );
	if ( $country ) {
		$term   = get_term_by( 'slug', $country, 'ws_country' );
		$shapes = array( ws_iso_numeric( ws_country_code( $term ) ) );
	} else {
		$shapes = ws_member_shapes();
	}
	return ws_map_element( 'chapters', $points, $shapes, $height );
}

/** Country cards: flag, name, national logo (or placeholder) and number of chapters. */
function ws_grand_chapter_directory() {
	$terms = get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false, 'orderby' => 'name' ) );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	$out = '<div class="ws-chapter-grid ws-gc-grid">';
	foreach ( $terms as $term ) {
		$count = ws_chapter_count( $term );
		$out  .= sprintf(
			'<article class="ws-chapter-card ws-gc-card"><a class="ws-chapter-card__link" href="%1$s"><div class="ws-chapter-card__logo">%2$s</div><h3 class="ws-chapter-card__name">%3$s %4$s</h3></a><p class="ws-chapter-meta">%5$s</p></article>',
			esc_url( get_term_link( $term ) ),
			ws_country_logo( $term->term_id ),
			ws_flag( $term ),
			esc_html( $term->name ),
			/* translators: %d: number of chapters */
			esc_html( sprintf( _n( '%d chapter', '%d chapters', $count, 'ws-chapters' ), $count ) )
		);
	}
	return $out . '</div>';
}

/** Key numbers strip. */
function ws_stats() {
	$items = array(
		array( count( ws_country_map_data() ), __( 'Countries', 'ws-chapters' ) ),
		array( (int) wp_count_posts( 'ws_chapter' )->publish, __( 'Chapters', 'ws-chapters' ) ),
		array( '1998', __( 'Widows Sons founded, Illinois, USA', 'ws-chapters' ) ),
	);
	$out = '<dl class="ws-stats">';
	foreach ( $items as $item ) {
		$out .= '<div><dt>' . esc_html( $item[1] ) . '</dt><dd>' . esc_html( $item[0] ) . '</dd></div>';
	}
	return $out . '</dl>';
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

	// Flag of the Grand Chapter being viewed, or of the current chapter's Grand Chapter.
	add_shortcode( 'ws_country_flag', function ( $atts ) {
		$atts = (array) $atts;
		$term = is_tax( 'ws_country' ) ? get_queried_object() : null;
		if ( ! $term && ws_shortcode_chapter_id( $atts ) ) {
			$term = ws_chapter_country( ws_shortcode_chapter_id( $atts ) );
		}
		return ws_flag( $term, true );
	} );

	add_shortcode( 'ws_chapter_directory', function ( $atts ) {
		return ws_chapter_directory( ws_resolve_country( (array) $atts ) );
	} );

	add_shortcode( 'ws_grand_chapters', 'ws_grand_chapter_directory' );
	add_shortcode( 'ws_stats', 'ws_stats' );

	add_shortcode( 'ws_chapters_url', function () {
		return esc_url( get_post_type_archive_link( 'ws_chapter' ) );
	} );

	add_shortcode( 'ws_chapter_map', function ( $atts ) {
		$atts = shortcode_atts( array( 'country' => '', 'height' => 560 ), (array) $atts );
		return ws_chapter_map( ws_resolve_country( $atts ), $atts['height'] );
	} );
} );
