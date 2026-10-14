<?php
/**
 * Step 8: Weblizar "student type" becomes the register's Boarder / Day scholar.
 *
 * Renames the default types Regular → Day Scholar and Private → Boarder, and removes
 * "Other" when no learner uses it. Only labels change; existing learners keep their type
 * ID. Learners without a type count as day scholars (BSA_School::residence()).
 * Re-runnable. Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$table = $wpdb->prefix . 'wlsm_student_type';
$log   = array();

foreach ( array( 'Regular' => 'Day Scholar', 'Private' => 'Boarder' ) as $old => $new ) {
	$n     = $wpdb->update( $table, array( 'label' => $new, 'updated_at' => current_time( 'mysql' ) ), array( 'label' => $old ) );
	$log[] = "$old → $new: " . (int) $n . ' row(s)';
}

$other = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$table} WHERE label = %s", 'Other' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( $other ) {
	$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wlsm_student_records WHERE student_type = %s", (string) $other ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $used ) {
		$log[] = "Other kept: used by $used learner(s)";
	} else {
		$wpdb->delete( $table, array( 'ID' => $other ) );
		$log[] = 'Other removed (unused)';
	}
}

$log[] = 'types now: ' . implode( ', ', $wpdb->get_col( "SELECT label FROM {$table} ORDER BY ID" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
return $log;
