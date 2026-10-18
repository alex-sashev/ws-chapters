<?php
/**
 * Data retention, as stated in the privacy policy (section "Data Retention"). Runs once a day.
 *
 * - Membership applications: deleted 12 months after they were received.
 * - Event submissions never published (pending, draft or in the Trash): deleted 6 months after submission.
 * - Published events: the submitter's name and email are removed 6 months after submission
 *   (the event itself stays; the contact details for riders are part of the event).
 */

defined( 'ABSPATH' ) || exit;

const WS_KEEP_APPLICATIONS = '12 months';
const WS_KEEP_SUBMISSIONS  = '6 months';

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'ws_retention_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ws_retention_cleanup' );
	}
} );

register_deactivation_hook( dirname( __DIR__ ) . '/ws-chapters.php', function () {
	wp_clear_scheduled_hook( 'ws_retention_cleanup' );
} );

/** IDs of posts of a type, in the given statuses, received before a cut-off ("… ago"). */
function ws_posts_older_than( $type, $statuses, $age, $extra = array() ) {
	return get_posts( array_merge( array(
		'post_type'        => $type,
		'post_status'      => $statuses,
		'date_query'       => array( array( 'column' => 'post_date_gmt', 'before' => $age . ' ago' ) ),
		'posts_per_page'   => 200,
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => true,
	), $extra ) );
}

/** Run the clean-up now; returns the number of applications and submissions removed or anonymised. */
function ws_retention_cleanup() {
	$done = array( 'applications' => 0, 'submissions' => 0, 'anonymised' => 0 );

	foreach ( ws_posts_older_than( 'ws_application', 'any', WS_KEEP_APPLICATIONS ) as $id ) {
		$done['applications'] += wp_delete_post( $id, true ) ? 1 : 0;
	}

	$submitted = array( 'meta_key' => '_ws_submitted_email' );
	foreach ( ws_posts_older_than( 'ws_event', array( 'pending', 'draft', 'trash' ), WS_KEEP_SUBMISSIONS, $submitted ) as $id ) {
		$done['submissions'] += wp_delete_post( $id, true ) ? 1 : 0;
	}
	foreach ( ws_posts_older_than( 'ws_event', array( 'publish', 'future', 'private' ), WS_KEEP_SUBMISSIONS, $submitted ) as $id ) {
		delete_post_meta( $id, '_ws_submitted_by' );
		delete_post_meta( $id, '_ws_submitted_email' );
		$done['anonymised']++;
	}
	return $done;
}
add_action( 'ws_retention_cleanup', 'ws_retention_cleanup' );
