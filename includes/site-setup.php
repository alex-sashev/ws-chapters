<?php
/**
 * Install of a new live site: chapters, pages, menu, the 2026 events, useful links, the home page photo,
 * Bulgaria's photos and insignia. No demo content. The member pages are added by member-management.php.
 *
 * Runs once, on the first page load after the plugin is activated, on a site that has no chapters yet.
 * The Playground demo builds its own content and sets the option ws_site_setup_done first, so it is skipped there.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	if ( get_option( 'ws_site_setup_done' ) || ! post_type_exists( 'ws_chapter' ) ) {
		return;
	}
	if ( ! add_option( 'ws_site_setup_done', 'running', '', false ) ) {
		return; // Another request got there first.
	}
	if ( get_posts( array( 'post_type' => 'ws_chapter', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) ) ) {
		update_option( 'ws_site_setup_done', 'skipped: chapters already exist', false );
		return;
	}
	// Act as the first administrator while building (so page and menu markup is kept as written), then switch back.
	$visitor = get_current_user_id();
	$admins  = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $admins ? (int) $admins[0] : 0 );
	ws_site_setup();
	wp_set_current_user( $visitor );
	update_option( 'ws_site_setup_done', gmdate( 'c' ), false );
}, 30 );

function ws_site_setup() {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	update_option( 'blogname', 'Widows Sons Europe' );
	global $wp_rewrite;
	if ( '' === (string) get_option( 'permalink_structure' ) ) {
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}
	$img = array();
	$gc = function ($name) { $t = term_exists($name, 'ws_country'); return $t ? (int) $t['term_id'] : 0; };
	// One chapter per Grand Chapter, named by country; chapters can rename themselves.
	// No coordinates: the map puts each pin on the country's capital until the chapter enters its city. [name, grand chapter, city, lat, lng, logo, back patch]
	$chapters = array(
	  array('Austria', 'Austria', '', null, null, -1, -1),
	  array('Belgium', 'Belgium', '', null, null, -1, -1),
	  array('Bulgaria', 'Bulgaria', '', null, null, -1, -1),
	  array('Croatia', 'Croatia', '', null, null, -1, -1),
	  array('Cyprus', 'Cyprus', '', null, null, -1, -1),
	  array('Estonia', 'Estonia', '', null, null, -1, -1),
	  array('Finland', 'Finland', '', null, null, -1, -1),
	  array('France', 'France', '', null, null, -1, -1),
	  array('Germany', 'Germany', '', null, null, -1, -1),
	  array('Gibraltar', 'Gibraltar', '', null, null, -1, -1),
	  array('Greece', 'Greece', '', null, null, -1, -1),
	  array('Hungary', 'Hungary', '', null, null, -1, -1),
	  array('Iceland', 'Iceland', '', null, null, -1, -1),
	  array('Ireland', 'Ireland', '', null, null, -1, -1),
	  array('Italy', 'Italy', '', null, null, -1, -1),
	  array('Italy Military Traveler', 'Italy Military Traveler', 'Naples', 40.8518, 14.2681, -1, -1),
	  array('Latvia', 'Latvia', '', null, null, -1, -1),
	  array('Luxembourg', 'Luxembourg', '', null, null, -1, -1),
	  array('Malta', 'Malta', '', null, null, -1, -1),
	  array('Netherlands', 'Netherlands', '', null, null, -1, -1),
	  array('Portugal', 'Portugal', '', null, null, -1, -1),
	  array('Romania', 'Romania', '', null, null, -1, -1),
	  array('Spain', 'Spain', '', null, null, -1, -1),
	  array('Sweden', 'Sweden', '', null, null, -1, -1),
	  array('Switzerland', 'Switzerland', '', null, null, -1, -1),
	  array('United Kingdom', 'United Kingdom', '', null, null, -1, -1),
	);
	foreach ($chapters as $c) {
	  $id = wp_insert_post(array('post_type'=>'ws_chapter','post_title'=>$c[0],'post_status'=>'publish',
	    'post_content'=>'<!-- wp:paragraph --><p>This chapter profile is being prepared. Its history, riding activities and charitable initiatives will appear when confirmed by its representatives.</p><!-- /wp:paragraph -->'));
	  wp_set_object_terms($id, array($gc($c[1])), 'ws_country');
	  if ($c[2]) update_post_meta($id, 'city', $c[2]);
	  if ($c[3] !== null) { update_post_meta($id, 'latitude', $c[3]); update_post_meta($id, 'longitude', $c[4]); }
	  if ($c[2] && $c[3] !== null) update_post_meta($id, 'coords_source', 'city'); // re-geocodes if the city is edited later
	  if ($c[5] >= 0) update_post_meta($id, 'chapter_logo', $img[$c[5]]);
	  if ($c[6] >= 0) update_post_meta($id, 'back_patch', $img[$c[6]]);
	}

	// Remove WordPress's default sample content.
	foreach (array(get_page_by_path('hello-world', OBJECT, 'post'), get_page_by_path('sample-page')) as $p) { if ($p) wp_delete_post($p->ID, true); }

	$page = function ($title, $slug, $content) {
	  return wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>$title,'post_name'=>$slug,'post_content'=>$content));
	};
	$home  = $page('Home', 'home', '');
	$news  = $page('News', 'news', '');
	$about = $page('About', 'about', ws_site_setup_pattern_text('page-about'));
	$char  = $page('Charity & Community', 'charity', '<!-- wp:pattern {"slug":"ws-europe/page-charity"} /-->');
	$join  = $page('Join Widows Sons', 'join', '<!-- wp:pattern {"slug":"ws-europe/page-join"} /-->');
	$subev = $page('Submit an event', 'submit-event', '<!-- wp:pattern {"slug":"ws-europe/page-submit-event"} /-->');
	$memb  = $page('Members', 'members', '<!-- wp:html -->[ws_members_area]<!-- /wp:html -->');
	$ulnk  = $page('Useful links', 'useful-links', '<!-- wp:pattern {"slug":"ws-europe/page-useful-links"} /-->');
	// Useful links (more are added by admins under Useful links).
	foreach (array(
	  array('Widows Sons Masonic Riders Association, Inc.', 'https://wsmrainc.org', 'Official website of the Widows Sons Masonic Riders Association, Inc. (WSMRA Inc.), the international association of Widows Sons chapters worldwide.', -1),
	) as $l) {
	  $lid = wp_insert_post(array('post_type'=>'ws_link','post_status'=>'publish','post_title'=>$l[0],'menu_order'=>$l[3]));
	  update_post_meta($lid, '_ws_url', $l[1]); update_post_meta($lid, '_ws_desc', $l[2]);
	}
	$cont  = $page('Get in Touch', 'contact', '<!-- wp:pattern {"slug":"ws-europe/page-contact"} /-->');
	// Privacy policy: WordPress's draft page, filled with the theme's privacy pattern.
	$priv  = (int) get_option('wp_page_for_privacy_policy');
	$privc = '<!-- wp:pattern {"slug":"ws-europe/page-privacy"} /-->';
	if ($priv) wp_update_post(array('ID'=>$priv, 'post_status'=>'publish', 'post_title'=>'Privacy Policy', 'post_name'=>'privacy-policy', 'post_content'=>$privc));
	else { $priv = $page('Privacy Policy', 'privacy-policy', $privc); update_option('wp_page_for_privacy_policy', $priv); }
	update_option('show_on_front', 'page');
	update_option('page_on_front', $home);
	update_option('page_for_posts', $news);
	update_option('blogdescription', 'Masonic Riders Association · Brotherhood Beyond Borders');

	// Main menu (Appearance → Editor → Navigation to change it).
	$link = function ($label, $url, $id = 0, $class = '') {
	  $a = array('label'=>$label, 'url'=>$url, 'kind'=>$id ? 'post-type' : 'custom', 'isTopLevelLink'=>true);
	  if ($class) $a['className'] = $class;
	  if ($id) { $a['type'] = 'page'; $a['id'] = $id; }
	  return '<!-- wp:navigation-link ' . wp_json_encode($a) . ' /-->';
	};
	wp_insert_post(array('post_type'=>'wp_navigation','post_status'=>'publish','post_title'=>'Main menu','post_content'=>implode("\n", array(
	  $link('Home', home_url('/')),
	  $link('About', get_permalink($about), $about),
	  $link('Chapters', get_post_type_archive_link('ws_chapter')),
	  $link('Events', get_post_type_archive_link('ws_event')),
	  $link('News', get_permalink($news), $news),
	  $link('Charity', get_permalink($char), $char),
	  $link('Contact', get_permalink($cont), $cont),
	  $link('Members', get_permalink($memb), $memb, 'ws-members-only'),
	))));

	// Events (real 2026 events from the Grand Chapters).
	$ch = function ($title) { $p = get_page_by_path(sanitize_title($title), OBJECT, 'ws_chapter'); return $p ? $p->ID : 0; };
	$events = json_decode(<<<'JSON'
	[
	{
	"title": "WS Bulgaria National Rally",
	"start": "2026-08-02",
	"end": "2026-08-09",
	"time": "",
	"country": "Bulgaria",
	"city": "Sofia",
	"venue": "Round trip",
	"organiser": "Widows Sons Grand Chapter Bulgaria",
	"host": "Bulgaria",
	"desc": "Details to be confirmed later. The plan is to start right after the European/World Widows Sons meeting in Romania, from 2 to 9 August, and ride a round trip finishing in Sofia, Bulgaria.",
	"contact": "Alex Sashev, +359889323265"
	},
	{
	"title": "Austrian Masonic Biker Meeting",
	"start": "2026-05-22",
	"end": "2026-05-25",
	"time": "",
	"country": "Austria",
	"city": "Schladming",
	"venue": "https://www.erlebniswelt.at",
	"organiser": "Tu Felix Austria",
	"host": "",
	"desc": "Meeting of Masonic bikers from around the world, with three rides in the Austrian Alps.",
	"contact": "tu.felix.austria.ooe@gmail.com"
	},
	{
	"title": "First Birthday of Grand Chapter Italy MRA",
	"start": "2026-05-01",
	"end": "2026-05-04",
	"time": "15:00",
	"country": "Italy",
	"city": "Zoppola (Pordenone)",
	"venue": "Albergo Hotel Tuan, Via Nazionale 3, 33080 Zoppola (PN), tel. +39 0434 97087",
	"organiser": "Grand Chapter Italia",
	"host": "Italy",
	"desc": "We will toast to the first birthday of the Italian Widows Sons Grand Chapter, riding along Friuli's hills and lands, eating and drinking local food and wines, dancing, and sharing our happiness and confidence in the future of the Widows Sons Masonic Riders Association. The motto all along the motorcycle rally will be: WSFEFEWS!",
	"contact": "ws.grandchapter.italia@gmail.com or curzio89@yahoo.it"
	},
	{
	"title": "WS International Gathering – Romania",
	"start": "2026-07-30",
	"end": "2026-08-02",
	"time": "10:00",
	"country": "Romania",
	"city": "Bucharest",
	"venue": "Bran & Bucharest",
	"organiser": "Widows Sons Grand Chapter Romania",
	"host": "Romania",
	"desc": "Dear Brothers,\n\nThe Romanian WS Grand Chapter is pleased to announce the WS Grand Gathering in Romania from 30 July to 2 August 2026. The programme is split into two parts.\n\n1. Brothers arriving by motorcycle (from the West and South):\n• Thursday 30 July (morning): you will be met by the Romanian WS and escorted to Bran Castle (known worldwide as Dracula's Castle).\n• Thursday night: overnight stay in the Bran area.\n• Friday 31 July: all brothers regroup in Bucharest for a WS Party hosted at our Clubhouse.\n\n2. Brothers attending only the WS Grand Gathering:\n• Friday night, 31 July: arrival in Bucharest and participation in the WS Party.\n• Saturday 1 August: Grand Gathering at the Parliament Building (also known as Casa Poporului).\n\nWe look forward to welcoming you to Romania for an unforgettable gathering of brotherhood, history and tradition.\n\nWith respect,\nRomanian WS Grand Chapter",
	"contact": "widowssonsromania@gmail.com; Emil Dobrovolschi, Secretary, +40 745 344 971"
	},
	{
	"title": "10th Anniversary of WS Germany",
	"start": "2026-06-11",
	"end": "2026-06-14",
	"time": "",
	"country": "Germany",
	"city": "Osterode",
	"venue": "Hotel Sauerbrey, Friedrich-Ebert-Str. 12, 37520 Osterode (Harz Mountains)",
	"organiser": "Widows Sons Germany",
	"host": "Germany",
	"desc": "Dear Brethren,\n\nWe, the Widows Sons Germany, are honoured to invite you to the 10th Anniversary of our Grand Chapter, from 11 to 14 June 2026, in the beautiful Harz Mountains. Meet on the level and part upon the square.\n\nProgramme highlights:\n• Welcome Party\n• Initiation of brethren into Widows Sons Germany (Widows Sons only)\n• Branding Ceremony (for branded members only; branding of brethren from other chapters is possible with prior permission from their chapter)\n• Masonic Ritual with a German Lodge (for Freemasons only; a Letter of Good Standing is required)\n• Two motorcycle rides through the scenic Harz Mountains\n• Festive Board in celebration of the 10th anniversary\n\nParticipation fee:\n• €475 – single person (single room)\n• €820 – two persons (double room, €410 per person)\n\nThe fee includes accommodation for 3 nights with breakfast, 2 dinners, 1 Festive Board and 3 drinks at the Welcome Party. All other food and beverages are at your own expense.\n\nRegistration form: https://widows-sons-germany.de/10thanniversary.html",
	"contact": "info@germany-widows-sons.de"
	},
	{
	"title": "Widows Sons Latvia Meeting during Kurland 2026",
	"start": "2026-07-16",
	"end": "2026-07-19",
	"time": "18:00",
	"country": "Latvia",
	"city": "Riga, Ventspils",
	"venue": "Aldaru str. 8, Riga and Vasarnicu str. 56, Ventspils",
	"organiser": "Widows Sons Latvia",
	"host": "Latvia",
	"desc": "Kurland is one of the most popular biker meetings in Latvia, attracting motorcyclists from all the Baltic countries. Widows Sons Latvia, as an associated member of the MCA, regularly takes part and enjoys benefits similar to MCA members, including a reduced participation fee and a reserved place for the Widows Sons camp in the camping area.\n\nOvernight accommodation in Ventspils is organised by Widows Sons Latvia not far from the main camping site. Before the meeting, on 16 July, new members will be initiated with 1st degree works in the A8 temple (Aldaru str. 8, Riga). On the morning of 17 July a group ride goes from Riga to Ventspils. Looking forward to meeting you in Riga and Ventspils!\n\nInvitation: https://drive.google.com/file/d/1ljtuTUQtMgPOJiplh1W6wfVbbIJkYX8e/view\nDetailed programme: https://drive.google.com/file/d/1ZKIgKw4Aevqkd1BgVFyvZtqay6ft-x1K/view",
	"contact": "WSLA Secretary: Maris, +37126179193, widowssonslatvia@gmail.com; President WSLA: Bernhard, +371 29185512, president.wslv@gmail.com"
	},
	{
	"title": "WS Belgian Weekend",
	"start": "2026-09-11",
	"end": "2026-09-13",
	"time": "",
	"country": "Belgium",
	"city": "Champlon",
	"venue": "Champlon (Tenneville)",
	"organiser": "Phoenix Widows Sons Belgian Grand Chapter",
	"host": "Belgium",
	"desc": "National gathering of the Belgian Phoenix Widows Sons. Brothers and sisters from other countries are warmly welcome. Further details will follow.",
	"contact": "secratariat.phoenixws@gmail.com"
	},
	{
	"title": "WS Cyprus Patch-up",
	"start": "2026-05-29",
	"end": "2026-05-31",
	"time": "20:00",
	"country": "Cyprus",
	"city": "Larnaca",
	"venue": "Military Base, Larnaca",
	"organiser": "Widows Sons Grand Chapter Cyprus",
	"host": "Cyprus",
	"desc": "Patching of the founding members of WS Cyprus, combined with a gathering of other bikers, MCs and brethren from Cypriot lodges. The main event takes place on 30 May 2026.",
	"contact": "WS Secretary, +35795152636"
	},
	{
	"title": "Denmark – International Rally Masonic Biker",
	"start": "2026-09-04",
	"end": "2026-09-06",
	"time": "",
	"country": "Denmark",
	"city": "South Jutland",
	"venue": "Scout camp in South Jutland",
	"organiser": "Masonic Biker Denmark",
	"host": "",
	"desc": "For more information and the registration form, email info@masonic-biker.dk.\n\nProgramme:\n• Friday evening: DIY grill\n• Saturday: ride out, then dinner in the great hall and action in the hut\n\nFree beer, soda and wine! For brethren only: no regalia or apron at the lodge meeting.\n\nAll inclusive:\n• Own tent: €175\n• Bed in camp: €225",
	"contact": "info@masonic-biker.dk"
	}
	]
JSON
	, true);
	// Denmark has no chapter yet; add it as a country so the event can be filed there (no map marker without chapters).
	if (!term_exists('Denmark', 'ws_country')) { $dk = wp_insert_term('Denmark', 'ws_country'); if (!is_wp_error($dk)) update_term_meta($dk['term_id'], 'country_code', 'dk'); }
	foreach ($events as $e) {
	  $content = '';
	  foreach (preg_split('/\n{2,}/', $e['desc']) as $para) {
	    $content .= '<!-- wp:paragraph --><p>' . nl2br(make_clickable(esc_html($para))) . '</p><!-- /wp:paragraph -->';
	  }
	  $id = wp_insert_post(array('post_type'=>'ws_event','post_status'=>'publish','post_title'=>$e['title'],'post_content'=>$content,
	    'post_excerpt'=>wp_trim_words($e['desc'], 30)));
	  foreach (array('start_date'=>'start','end_date'=>'end','start_time'=>'time','venue'=>'venue','city'=>'city','organiser'=>'organiser','contact'=>'contact') as $meta => $k) {
	    if ($e[$k] !== '') update_post_meta($id, $meta, $e[$k]);
	  }
	  if ($e['host']) update_post_meta($id, 'host_chapter', $ch($e['host']));
	  $t = term_exists($e['country'], 'ws_country');
	  if ($t) wp_set_object_terms($id, array((int) $t['term_id']), 'ws_country');
	}
	$bg = term_exists('Bulgaria', 'ws_country');
	// Bulgaria country text (from the Grand Chapter Bulgaria site).
	wp_update_term((int) $bg['term_id'], 'ws_country', array('description' => 'Widows Sons Bulgaria is the first Bulgarian Masonic motorcycle organisation, part of the United Grand Lodge of Bulgaria. It brings together brothers from different lodges who share common values and a passion for motorcycling, combining Masonic tradition with motorcycle culture to build a close community and serve society through charitable initiatives.'));
	update_post_meta($ch('Bulgaria'), 'city', 'Sofia');

	ws_site_setup_photos();
	ws_site_setup_bulgaria_insignia();
	flush_rewrite_rules( false );
}

/**
 * A theme pattern's block markup, so the page holds the text itself and can be edited like any page
 * (the About page). Falls back to the pattern reference when the theme is not there.
 */
function ws_site_setup_pattern_text( $slug ) {
	$file = get_theme_file_path( 'patterns/' . $slug . '.php' );
	$src  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$end  = strpos( $src, '?>' );
	$text = trim( false === $end ? '' : substr( $src, $end + 2 ) );
	return '' !== $text ? $text : '<!-- wp:pattern {"slug":"ws-europe/' . $slug . '"} /-->';
}

/** Bulgaria's insignia: the WS back patch is the chapter patch, the Motorcycle Riders (MR) logo is the chapter logo. */
function ws_site_setup_bulgaria_insignia() {
	$ch = get_page_by_path( 'bulgaria', OBJECT, 'ws_chapter' );
	if ( ! $ch ) {
		return;
	}
	$dir = dirname( __DIR__ ) . '/playground/media/';
	$up  = wp_upload_dir( null, false );
	$sub = trailingslashit( $up['basedir'] ) . 'countries/bulgaria';
	$set = function ( $file, $title ) use ( $dir, $sub, $ch ) {
		if ( ! is_file( $dir . $file ) || ! wp_mkdir_p( $sub ) ) {
			return 0;
		}
		$dest = $sub . '/' . wp_unique_filename( $sub, $file );
		if ( ! copy( $dir . $file, $dest ) ) {
			return 0;
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $title, 'post_status' => 'inherit' ), $dest, $ch->ID );
		if ( ! $id || is_wp_error( $id ) ) {
			return 0;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $title );
		return (int) $id;
	};
	$patch = $set( 'bulgaria-ws-patch.png', 'Bulgarian Grand Chapter: back patch' );
	$logo  = $set( 'bulgaria-mr-logo.png', 'Bulgarian Grand Chapter: MR logo' );
	if ( $patch ) {
		update_post_meta( $ch->ID, 'back_patch', $patch );
	}
	if ( $logo ) {
		update_post_meta( $ch->ID, 'chapter_logo', $logo );
	}
}

/** Home page photo, and Bulgaria's 2025 National Tour photos, from the plugin's media folder. */
function ws_site_setup_photos() {
	$titles = array(
		'tour-01-group-service-station.jpg' => 'National Tour 2025: the group at the start',
		'tour-02-church-blessing.jpg'       => 'National Tour 2025: blessing at the monastery',
		'tour-03-evening-dinner.jpg'        => 'National Tour 2025: evening gathering',
		'tour-04-three-men-bar.jpg'         => 'National Tour 2025: with friends on the road',
		'ws-hero-emblem.jpg'                => 'Widows Sons emblem pin on a leather vest',
	);
	$ids = array();
	$up  = wp_upload_dir();
	foreach ( $titles as $file => $title ) {
		$src = dirname( __DIR__ ) . '/playground/media/' . $file;
		if ( ! is_file( $src ) || ! wp_mkdir_p( $up['path'] ) ) {
			continue;
		}
		$dest = $up['path'] . '/' . wp_unique_filename( $up['path'], $file );
		if ( ! copy( $src, $dest ) ) {
			continue;
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $title, 'post_status' => 'inherit' ), $dest );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $dest ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $title );
		$ids[ $file ] = $id;
	}
	if ( ! empty( $ids['ws-hero-emblem.jpg'] ) ) {
		set_post_thumbnail( (int) get_option( 'page_on_front' ), $ids['ws-hero-emblem.jpg'] );
	}
	$tour = array_values( array_diff_key( $ids, array( 'ws-hero-emblem.jpg' => 1 ) ) );
	$bg   = term_exists( 'Bulgaria', 'ws_country' );
	if ( ! $tour || ! $bg ) {
		return;
	}
	update_term_meta( (int) $bg['term_id'], 'hero_image', $ids['tour-02-church-blessing.jpg'] ?? $tour[0] );
	update_term_meta( (int) $bg['term_id'], 'gallery', implode( ',', $tour ) );
}

/*
 * Switch the site address to https:// the first time the site is reached over HTTPS
 * (i.e. once the host's SSL certificate works), so links, images and logins all use HTTPS.
 */
add_action( 'init', function () {
	if ( ! is_ssl() || 0 !== strpos( (string) get_option( 'home' ), 'http://' ) ) {
		return;
	}
	foreach ( array( 'home', 'siteurl' ) as $opt ) {
		update_option( $opt, set_url_scheme( (string) get_option( $opt ), 'https' ) );
	}
}, 1 );
