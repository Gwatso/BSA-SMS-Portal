<?php
/**
 * Step 10: a 2025 school year, so last year's class lists (and, later, marks) can be
 * imported as history.
 *
 * Adds the Weblizar session "2025" (1 January to 31 December 2025) when no session of
 * that name exists. The current session (2026) is not changed. Term dates for 2025 are
 * set later in School Terms, once the owner supplies them. Re-runnable. Run through
 * Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$table = $wpdb->prefix . 'wlsm_sessions';
$log   = array();

$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$table} WHERE label = %s", '2025' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( $id ) {
	$log[] = "2025 already exists (ID $id)";
} else {
	$wpdb->insert(
		$table,
		array(
			'label'      => '2025',
			'start_date' => '2025-01-01',
			'end_date'   => '2025-12-31',
			'created_at' => current_time( 'mysql' ),
		)
	);
	$id    = (int) $wpdb->insert_id;
	$log[] = $id ? "2025 added (ID $id)" : 'Could not add 2025: ' . $wpdb->last_error;
}

$log[] = 'Current session unchanged: ' . get_option( 'wlsm_current_session' );
$log[] = 'Sessions: ' . wp_json_encode( BSA_School::sessions() );
return $log;
