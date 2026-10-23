<?php
/**
 * Events page views and "Add to calendar".
 *
 * [ws_events_views]  The Events page: a List / Month / Week switch above the event list. List is the default and
 *                    is the existing upcoming + past list. Month and Week are drawn by FullCalendar, which is only
 *                    fetched when someone picks one of them. The chosen view is kept in the address (?view=month).
 *
 * On each event page, under the details: "Add to calendar" with Google Calendar, Outlook.com, Office 365 and a
 * .ics file (Apple Calendar and any other calendar). Events go in as all-day entries over their dates, so they
 * never shift between time zones; a start time is mentioned in the description.
 *
 * @package ws-chapters
 */

defined( 'ABSPATH' ) || exit;

const WS_FULLCALENDAR_URL = 'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.15/index.global.min.js';
const WS_FULLCALENDAR_SRI = 'sha384-B1OFx8Gy9GjPu8UbUyXbGQpzll9ubAUQ9agInFJ8NnD7nYG1u/CLR+Sqr5yifl4q';

/** First and last day of an event (Y-m-d); the last day is the start day when there is no end date. */
function ws_event_days( $event_id ) {
	$start = (string) get_post_meta( $event_id, 'start_date', true );
	$end   = (string) get_post_meta( $event_id, 'end_date', true );
	return array( $start, ( $end && $end >= $start ) ? $end : $start );
}

/** The day after $date (Y-m-d): calendars count an all-day entry's end as "up to, not including". */
function ws_day_after( $date ) {
	return gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) );
}

/** Short plain-text description for a calendar entry: start time, the first lines of the text, the link. */
function ws_event_calendar_text( $event_id ) {
	$time = (string) get_post_meta( $event_id, 'start_time', true );
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) get_post_field( 'post_content', $event_id ) ) ) );
	$text = wp_html_excerpt( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ), 400, '…' );
	$lines = array_filter( array(
		/* translators: %s: start time */
		$time ? sprintf( __( 'Starts at %s (local time).', 'ws-chapters' ), $time ) : '',
		$text,
		get_permalink( $event_id ),
	) );
	return implode( "\n\n", $lines );
}

/* -------------------------------------------------------------------------
 * Events page: List / Month / Week
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	add_shortcode( 'ws_events_views', function () {
		$items = array();
		foreach ( get_posts( array( 'post_type' => 'ws_event', 'post_status' => 'publish', 'posts_per_page' => -1, 'no_found_rows' => true ) ) as $e ) {
			list( $first, $last ) = ws_event_days( $e->ID );
			if ( ! $first ) {
				continue;
			}
			$host    = (int) get_post_meta( $e->ID, 'host_chapter', true );
			$items[] = array(
				'title' => html_entity_decode( get_the_title( $e ), ENT_QUOTES, 'UTF-8' ),
				'start' => $first,
				'end'   => ws_day_after( $last ),
				'url'   => get_permalink( $e ),
				'place' => (string) ( get_post_meta( $e->ID, 'city', true ) ?: ws_event_place( $e->ID ) ),
				'host'  => $host ? html_entity_decode( ws_chapter_name( $host ), ENT_QUOTES, 'UTF-8' ) : '',
			);
		}
		wp_enqueue_script( 'ws-calendar', plugin_dir_url( __DIR__ ) . 'assets/ws-calendar.js', array(), filemtime( plugin_dir_path( __DIR__ ) . 'assets/ws-calendar.js' ), true );
		wp_localize_script( 'ws-calendar', 'wsCalendar', array(
			'lib'    => WS_FULLCALENDAR_URL,
			'sri'    => WS_FULLCALENDAR_SRI,
			'locale' => substr( get_locale(), 0, 2 ),
			'today'  => __( 'Today', 'ws-chapters' ),
			'none'   => __( 'No events in this period.', 'ws-chapters' ),
			'failed' => __( 'The calendar could not be loaded. Please use the list.', 'ws-chapters' ),
		) );

		$button = function ( $view, $icon, $label ) {
			return '<button type="button" class="ws-views__btn" data-ws-view="' . esc_attr( $view ) . '" aria-pressed="' . ( 'list' === $view ? 'true' : 'false' ) . '">' . $icon . '<span>' . esc_html( $label ) . '</span></button>';
		};
		$svg = function ( $paths ) {
			return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
		};
		// "Submit an event" on the same row as the switch, on the right.
		$submit_page = get_page_by_path( 'submit-event' );
		$submit      = $submit_page ? '<a class="ws-btn-gold ws-views__submit" href="' . esc_url( get_permalink( $submit_page ) ) . '">' . esc_html__( 'Submit an event', 'ws-chapters' ) . '</a>' : '';
		$list = do_shortcode( '[ws_events]' ) . '<div class="ws-section-head" style="margin-top:3rem"><h2>' . esc_html__( 'Past events', 'ws-chapters' ) . '</h2></div>' . do_shortcode( '[ws_events past="1"]' );

		return '<div class="ws-views" data-ws-events="' . esc_attr( wp_json_encode( $items ) ) . '">'
			. '<div class="ws-views__top"><div class="ws-views__bar" role="group" aria-label="' . esc_attr__( 'View', 'ws-chapters' ) . '">'
			. $button( 'list', $svg( '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>' ), __( 'List', 'ws-chapters' ) )
			. $button( 'month', $svg( '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>' ), __( 'Month', 'ws-chapters' ) )
			. $button( 'week', $svg( '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h8"/>' ), __( 'Week', 'ws-chapters' ) )
			. '</div>' . $submit . '</div>'
			. '<div class="ws-views__list">' . $list . '</div>'
			. '<div class="ws-views__cal" hidden></div>'
			. '</div>';
	} );
} );

/* -------------------------------------------------------------------------
 * Event page: Add to calendar
 * ---------------------------------------------------------------------- */

/** The "Add to calendar" menu for one event. */
function ws_event_add_to_calendar( $event_id ) {
	list( $first, $last ) = ws_event_days( $event_id );
	if ( ! $first ) {
		return '';
	}
	$title = html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' );
	$place = (string) ( get_post_meta( $event_id, 'city', true ) ?: ws_event_place( $event_id ) );
	$text  = ws_event_calendar_text( $event_id );
	$end   = ws_day_after( $last );

	$google = add_query_arg( array_map( 'rawurlencode', array(
		'action'   => 'TEMPLATE',
		'text'     => $title,
		'dates'    => str_replace( '-', '', $first ) . '/' . str_replace( '-', '', $end ),
		'details'  => $text,
		'location' => $place,
	) ), 'https://calendar.google.com/calendar/render' );
	$outlook = function ( $host ) use ( $title, $first, $end, $text, $place ) {
		return add_query_arg( array_map( 'rawurlencode', array(
			'path'     => '/calendar/action/compose',
			'rru'      => 'addevent',
			'subject'  => $title,
			'startdt'  => $first,
			'enddt'    => $end,
			'allday'   => 'true',
			'body'     => $text,
			'location' => $place,
		) ), 'https://' . $host . '/calendar/0/deeplink/compose' );
	};
	$ics  = add_query_arg( 'ws_ics', '1', get_permalink( $event_id ) );
	$item = function ( $href, $label, $download = false ) {
		return '<li><a href="' . esc_url( $href ) . '"' . ( $download ? ' download' : ' target="_blank" rel="noopener"' ) . '>' . esc_html( $label ) . '</a></li>';
	};
	return '<details class="ws-addcal"><summary class="ws-addcal__btn">'
		. '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M12 14v4M10 16h4"/></svg>'
		. esc_html__( 'Add to calendar', 'ws-chapters' ) . '</summary><ul class="ws-addcal__menu">'
		. $item( $google, __( 'Google Calendar', 'ws-chapters' ) )
		. $item( $outlook( 'outlook.live.com' ), __( 'Outlook.com', 'ws-chapters' ) )
		. $item( $outlook( 'outlook.office.com' ), __( 'Office 365 / Outlook at work', 'ws-chapters' ) )
		. $item( $ics, __( 'Apple Calendar', 'ws-chapters' ), true )
		. $item( $ics, __( 'Other calendar (.ics file)', 'ws-chapters' ), true )
		. '</ul></details>';
}

/** Text for an .ics line: escape the special characters and fold long lines (75 bytes). */
function ws_ics_line( $name, $value ) {
	$value = str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), $value );
	$line  = $name . ':' . $value;
	$out   = '';
	while ( strlen( $line ) > 75 ) {
		$cut  = 75;
		while ( $cut > 0 && ( ord( $line[ $cut ] ) & 0xC0 ) === 0x80 ) { // Do not split a UTF-8 character.
			--$cut;
		}
		$out .= substr( $line, 0, $cut ) . "\r\n ";
		$line = substr( $line, $cut );
	}
	return $out . $line . "\r\n";
}

/* The .ics file for one event: its page address plus ?ws_ics=1. */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['ws_ics'] ) || ! is_singular( 'ws_event' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$id = get_queried_object_id();
	list( $first, $last ) = ws_event_days( $id );
	if ( ! $first || 'publish' !== get_post_status( $id ) ) {
		return;
	}
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$ics  = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Widows Sons Europe//Events//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nBEGIN:VEVENT\r\n"
		. ws_ics_line( 'UID', 'ws-event-' . $id . '@' . $host )
		. ws_ics_line( 'DTSTAMP', gmdate( 'Ymd\THis\Z', (int) get_post_modified_time( 'U', true, $id ) ) )
		. 'DTSTART;VALUE=DATE:' . str_replace( '-', '', $first ) . "\r\n"
		. 'DTEND;VALUE=DATE:' . str_replace( '-', '', ws_day_after( $last ) ) . "\r\n"
		. ws_ics_line( 'SUMMARY', html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) )
		. ws_ics_line( 'LOCATION', (string) ( get_post_meta( $id, 'city', true ) ?: ws_event_place( $id ) ) )
		. ws_ics_line( 'DESCRIPTION', ws_event_calendar_text( $id ) )
		. ws_ics_line( 'URL', get_permalink( $id ) )
		. "END:VEVENT\r\nEND:VCALENDAR\r\n";
	nocache_headers();
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( get_post_field( 'post_name', $id ) ?: 'event' ) . '.ics"' );
	echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput -- calendar file, escaped by ws_ics_line().
	exit;
}, 5 );
