<?php
/**
 * Where a chapter's pin goes on the map.
 *
 * 1. Latitude/longitude typed in by hand, if any.
 * 2. Otherwise the chapter's city, looked up automatically when the chapter is saved
 *    (OpenStreetMap Nominatim; only the city and country name are sent).
 * 3. Otherwise the capital of the chapter's country.
 */

defined( 'ABSPATH' ) || exit;

/** Capital city coordinates by ISO country code. */
function ws_capital_coords( $code ) {
	$capitals = array(
		'at' => array( 48.2082, 16.3738 ), 'be' => array( 50.8503, 4.3517 ), 'bg' => array( 42.6977, 23.3219 ),
		'hr' => array( 45.8150, 15.9819 ), 'cy' => array( 35.1856, 33.3823 ), 'cz' => array( 50.0755, 14.4378 ),
		'dk' => array( 55.6761, 12.5683 ), 'ee' => array( 59.4370, 24.7536 ), 'fi' => array( 60.1699, 24.9384 ),
		'fr' => array( 48.8566, 2.3522 ), 'de' => array( 52.5200, 13.4050 ), 'gi' => array( 36.1408, -5.3536 ),
		'gr' => array( 37.9838, 23.7275 ), 'hu' => array( 47.4979, 19.0402 ), 'is' => array( 64.1466, -21.9426 ),
		'ie' => array( 53.3498, -6.2603 ), 'it' => array( 41.9028, 12.4964 ), 'lv' => array( 56.9496, 24.1052 ),
		'lt' => array( 54.6872, 25.2797 ), 'lu' => array( 49.6116, 6.1319 ), 'mt' => array( 35.8989, 14.5146 ),
		'nl' => array( 52.3676, 4.9041 ), 'no' => array( 59.9139, 10.7522 ), 'pl' => array( 52.2297, 21.0122 ),
		'pt' => array( 38.7223, -9.1393 ), 'ro' => array( 44.4268, 26.1025 ), 'rs' => array( 44.7866, 20.4489 ),
		'ru' => array( 55.7558, 37.6173 ), 'sk' => array( 48.1486, 17.1077 ), 'si' => array( 46.0569, 14.5058 ),
		'es' => array( 40.4168, -3.7038 ), 'se' => array( 59.3293, 18.0686 ), 'ch' => array( 46.9480, 7.4474 ),
		'gb' => array( 51.5072, -0.1276 ), 'gb-sct' => array( 55.9533, -3.1883 ), 'gb-eng' => array( 51.5072, -0.1276 ), 'gb-wls' => array( 51.4816, -3.1791 ), 'gb-nir' => array( 54.5973, -5.9301 ), 'ua' => array( 50.4501, 30.5234 ), 'md' => array( 47.0105, 28.8638 ),
		'me' => array( 42.4304, 19.2594 ), 'mk' => array( 41.9981, 21.4254 ), 'al' => array( 41.3275, 19.8187 ),
		'ba' => array( 43.8563, 18.4131 ), 'tr' => array( 39.9334, 32.8597 ),
	);
	return $capitals[ strtolower( (string) $code ) ] ?? null;
}

/**
 * Pin position for a chapter: [lat, lng, source] where source is 'manual', 'city' or 'capital',
 * or null if nothing is known.
 */
function ws_chapter_position( $chapter_id ) {
	$lat = get_post_meta( $chapter_id, 'latitude', true );
	$lng = get_post_meta( $chapter_id, 'longitude', true );
	if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
		// A city with no position yet (e.g. a chapter made on the site): look it up once and keep the result.
		$city = trim( (string) get_post_meta( $chapter_id, 'city', true ) );
		if ( '' !== $city && $city !== (string) get_post_meta( $chapter_id, 'geocode_tried', true ) && ws_locate_chapter( $chapter_id ) ) {
			$lat = get_post_meta( $chapter_id, 'latitude', true );
			$lng = get_post_meta( $chapter_id, 'longitude', true );
		}
	}
	if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
		return array( (float) $lat, (float) $lng, get_post_meta( $chapter_id, 'coords_source', true ) ?: 'manual' );
	}
	$capital = ws_capital_coords( ws_country_code( ws_chapter_country( $chapter_id ) ) );
	return $capital ? array( $capital[0], $capital[1], 'capital' ) : null;
}

/** Look up a city's coordinates. Returns [lat, lng] or null. */
function ws_geocode_city( $city, $country = '' ) {
	$key    = 'ws_geo_' . md5( strtolower( $city . '|' . $country ) );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached ?: null;
	}
	$url  = add_query_arg( array(
		'format'  => 'json',
		'limit'   => 1,
		'city'    => $city,
		'country' => $country,
	), 'https://nominatim.openstreetmap.org/search' );
	$resp = wp_remote_get( $url, array(
		'timeout' => 6,
		'headers' => array( 'User-Agent' => 'WidowsSonsEurope/1.0 (' . home_url( '/' ) . ')', 'Accept-Language' => 'en' ),
	) );
	$data = is_wp_error( $resp ) ? null : json_decode( wp_remote_retrieve_body( $resp ), true );
	$hit  = is_array( $data ) && isset( $data[0]['lat'], $data[0]['lon'] ) ? array( round( (float) $data[0]['lat'], 6 ), round( (float) $data[0]['lon'], 6 ) ) : null;
	set_transient( $key, $hit ?: array(), $hit ? MONTH_IN_SECONDS : HOUR_IN_SECONDS );
	return $hit;
}

/**
 * Put a chapter's pin on its city: look the city up and store the position (marked as found from the city,
 * so it follows later city changes). Returns true when a position was found. Each city is tried once.
 */
function ws_locate_chapter( $post_id ) {
	$city = trim( (string) get_post_meta( $post_id, 'city', true ) );
	if ( '' === $city ) {
		return false;
	}
	update_post_meta( $post_id, 'geocode_tried', $city );
	$country = ws_chapter_country( $post_id );
	$hit     = ws_geocode_city( $city, $country ? $country->name : '' );
	if ( ! $hit ) {
		return false;
	}
	update_post_meta( $post_id, 'latitude', $hit[0] );
	update_post_meta( $post_id, 'longitude', $hit[1] );
	update_post_meta( $post_id, 'coords_source', 'city' );
	update_post_meta( $post_id, 'geocoded_city', $city );
	return true;
}

/**
 * After the location box is saved: if no coordinates were typed in, look up the city. Coordinates
 * that came from an earlier lookup are refreshed when the city changes; typed-in ones are kept.
 */
add_action( 'save_post_ws_chapter', function ( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! isset( $_POST['ws_chapter_location_nonce'] ) ) {
		return;
	}
	$city    = trim( (string) get_post_meta( $post_id, 'city', true ) );
	$typed   = isset( $_POST['ws_latitude'], $_POST['ws_longitude'] ) && '' !== trim( wp_unslash( $_POST['ws_latitude'] ) ) && '' !== trim( wp_unslash( $_POST['ws_longitude'] ) );
	$auto    = 'city' === get_post_meta( $post_id, 'coords_source', true );
	$was_for = (string) get_post_meta( $post_id, 'geocoded_city', true );

	$city_changed = $city !== $was_for;
	if ( $typed && ( ! $auto || ! $city_changed ) ) {
		// Typed-in coordinates are kept as they are; earlier automatic ones too while the city is unchanged.
		if ( ! $auto ) {
			update_post_meta( $post_id, 'coords_source', 'manual' );
		}
		return;
	}
	if ( '' === $city ) {
		if ( $auto ) {
			delete_post_meta( $post_id, 'latitude' );
			delete_post_meta( $post_id, 'longitude' );
			delete_post_meta( $post_id, 'coords_source' );
			delete_post_meta( $post_id, 'geocoded_city' );
		}
		return;
	}
	ws_locate_chapter( $post_id );
}, 30 );
