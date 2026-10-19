<?php
/**
 * Every country in the world (ISO 3166-1 alpha-2 code => English name), for "+ Create new chapter":
 * a Super Admin can open a chapter in a country the site does not have yet, and the country is added then.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

function ws_world_countries() {
	return array(
		'af' => 'Afghanistan', 'al' => 'Albania', 'dz' => 'Algeria', 'ad' => 'Andorra', 'ao' => 'Angola',
		'ag' => 'Antigua and Barbuda', 'ar' => 'Argentina', 'am' => 'Armenia', 'au' => 'Australia', 'at' => 'Austria',
		'az' => 'Azerbaijan', 'bs' => 'Bahamas', 'bh' => 'Bahrain', 'bd' => 'Bangladesh', 'bb' => 'Barbados',
		'by' => 'Belarus', 'be' => 'Belgium', 'bz' => 'Belize', 'bj' => 'Benin', 'bt' => 'Bhutan',
		'bo' => 'Bolivia', 'ba' => 'Bosnia and Herzegovina', 'bw' => 'Botswana', 'br' => 'Brazil', 'bn' => 'Brunei',
		'bg' => 'Bulgaria', 'bf' => 'Burkina Faso', 'bi' => 'Burundi', 'cv' => 'Cabo Verde', 'kh' => 'Cambodia',
		'cm' => 'Cameroon', 'ca' => 'Canada', 'cf' => 'Central African Republic', 'td' => 'Chad', 'cl' => 'Chile',
		'cn' => 'China', 'co' => 'Colombia', 'km' => 'Comoros', 'cg' => 'Congo', 'cd' => 'Congo (Democratic Republic)',
		'cr' => 'Costa Rica', 'ci' => "Côte d'Ivoire", 'hr' => 'Croatia', 'cu' => 'Cuba', 'cy' => 'Cyprus',
		'cz' => 'Czechia', 'dk' => 'Denmark', 'dj' => 'Djibouti', 'dm' => 'Dominica', 'do' => 'Dominican Republic',
		'ec' => 'Ecuador', 'eg' => 'Egypt', 'sv' => 'El Salvador', 'gq' => 'Equatorial Guinea', 'er' => 'Eritrea',
		'ee' => 'Estonia', 'sz' => 'Eswatini', 'et' => 'Ethiopia', 'fj' => 'Fiji', 'fi' => 'Finland',
		'fr' => 'France', 'ga' => 'Gabon', 'gm' => 'Gambia', 'ge' => 'Georgia', 'de' => 'Germany',
		'gh' => 'Ghana', 'gi' => 'Gibraltar', 'gr' => 'Greece', 'gd' => 'Grenada', 'gt' => 'Guatemala',
		'gn' => 'Guinea', 'gw' => 'Guinea-Bissau', 'gy' => 'Guyana', 'ht' => 'Haiti', 'hn' => 'Honduras',
		'hk' => 'Hong Kong', 'hu' => 'Hungary', 'is' => 'Iceland', 'in' => 'India', 'id' => 'Indonesia',
		'ir' => 'Iran', 'iq' => 'Iraq', 'ie' => 'Ireland', 'il' => 'Israel', 'it' => 'Italy',
		'jm' => 'Jamaica', 'jp' => 'Japan', 'jo' => 'Jordan', 'kz' => 'Kazakhstan', 'ke' => 'Kenya',
		'ki' => 'Kiribati', 'xk' => 'Kosovo', 'kw' => 'Kuwait', 'kg' => 'Kyrgyzstan', 'la' => 'Laos',
		'lv' => 'Latvia', 'lb' => 'Lebanon', 'ls' => 'Lesotho', 'lr' => 'Liberia', 'ly' => 'Libya',
		'li' => 'Liechtenstein', 'lt' => 'Lithuania', 'lu' => 'Luxembourg', 'mg' => 'Madagascar', 'mw' => 'Malawi',
		'my' => 'Malaysia', 'mv' => 'Maldives', 'ml' => 'Mali', 'mt' => 'Malta', 'mh' => 'Marshall Islands',
		'mr' => 'Mauritania', 'mu' => 'Mauritius', 'mx' => 'Mexico', 'fm' => 'Micronesia', 'md' => 'Moldova',
		'mc' => 'Monaco', 'mn' => 'Mongolia', 'me' => 'Montenegro', 'ma' => 'Morocco', 'mz' => 'Mozambique',
		'mm' => 'Myanmar', 'na' => 'Namibia', 'nr' => 'Nauru', 'np' => 'Nepal', 'nl' => 'Netherlands',
		'nz' => 'New Zealand', 'ni' => 'Nicaragua', 'ne' => 'Niger', 'ng' => 'Nigeria', 'kp' => 'North Korea',
		'mk' => 'North Macedonia', 'no' => 'Norway', 'om' => 'Oman', 'pk' => 'Pakistan', 'pw' => 'Palau',
		'ps' => 'Palestine', 'pa' => 'Panama', 'pg' => 'Papua New Guinea', 'py' => 'Paraguay', 'pe' => 'Peru',
		'ph' => 'Philippines', 'pl' => 'Poland', 'pt' => 'Portugal', 'pr' => 'Puerto Rico', 'qa' => 'Qatar',
		'ro' => 'Romania', 'ru' => 'Russia', 'rw' => 'Rwanda', 'kn' => 'Saint Kitts and Nevis', 'lc' => 'Saint Lucia',
		'vc' => 'Saint Vincent and the Grenadines', 'ws' => 'Samoa', 'sm' => 'San Marino', 'st' => 'São Tomé and Príncipe', 'sa' => 'Saudi Arabia',
		'sn' => 'Senegal', 'rs' => 'Serbia', 'sc' => 'Seychelles', 'sl' => 'Sierra Leone', 'sg' => 'Singapore',
		'sk' => 'Slovakia', 'si' => 'Slovenia', 'sb' => 'Solomon Islands', 'so' => 'Somalia', 'za' => 'South Africa',
		'kr' => 'South Korea', 'ss' => 'South Sudan', 'es' => 'Spain', 'lk' => 'Sri Lanka', 'sd' => 'Sudan',
		'sr' => 'Suriname', 'se' => 'Sweden', 'ch' => 'Switzerland', 'sy' => 'Syria', 'tw' => 'Taiwan',
		'tj' => 'Tajikistan', 'tz' => 'Tanzania', 'th' => 'Thailand', 'tl' => 'Timor-Leste', 'tg' => 'Togo',
		'to' => 'Tonga', 'tt' => 'Trinidad and Tobago', 'tn' => 'Tunisia', 'tr' => 'Türkiye', 'tm' => 'Turkmenistan',
		'tv' => 'Tuvalu', 'ug' => 'Uganda', 'ua' => 'Ukraine', 'ae' => 'United Arab Emirates', 'gb' => 'United Kingdom',
		'us' => 'United States', 'uy' => 'Uruguay', 'uz' => 'Uzbekistan', 'vu' => 'Vanuatu', 'va' => 'Vatican City',
		've' => 'Venezuela', 'vn' => 'Vietnam', 'ye' => 'Yemen', 'zm' => 'Zambia', 'zw' => 'Zimbabwe',
	);
}

/**
 * The country for a new chapter: an existing country (term ID), or a world country by code ("new:us"), which
 * Super Admins may add. Returns the term ID, or 0 when not allowed or not found.
 */
function ws_resolve_new_chapter_country( $value ) {
	$value = (string) $value;
	if ( 0 !== strpos( $value, 'new:' ) ) {
		return absint( $value );
	}
	$code  = strtolower( substr( $value, 4 ) );
	$world = ws_world_countries();
	if ( ! isset( $world[ $code ] ) || ! ( current_user_can( 'manage_options' ) || current_user_can( WS_ASSET_CAP ) ) ) {
		return 0;
	}
	// Already on the site (same flag code)? Use it.
	$have = get_terms( array( 'taxonomy' => 'ws_country', 'hide_empty' => false, 'fields' => 'ids', 'meta_key' => 'country_code', 'meta_value' => $code ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	if ( $have && ! is_wp_error( $have ) ) {
		return (int) $have[0];
	}
	$term = wp_insert_term( $world[ $code ], 'ws_country' );
	if ( is_wp_error( $term ) ) {
		return isset( $term->error_data['term_exists'] ) ? (int) $term->error_data['term_exists'] : 0;
	}
	update_term_meta( (int) $term['term_id'], 'country_code', $code );
	return (int) $term['term_id'];
}
