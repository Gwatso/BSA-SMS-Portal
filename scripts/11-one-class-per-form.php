<?php
/**
 * Step 11: Forms 3 and 4 become one class each (owner, 2026-10-16).
 *
 * BSA runs one Form 3 and one Form 4 class (one class teacher, one register); Sciences
 * and Commercials are subject choices inside the class (BSA_Streams, plugin 0.13.0), not
 * separate classes. For each of Forms 3 and 4: the "Sciences" section is renamed "A" and
 * the "Commercials" section is deleted, only if nothing refers to it (learners, class
 * teachers, registers, homework, timetables...). Weblizar deletes the learners of a
 * deleted section, so a section in use is never deleted: the script stops and reports.
 * Re-runnable. Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p   = $wpdb->prefix;
$log = array();

// Every table with a section_id column, to prove a section is unused before deleting it.
$tables = $wpdb->get_col( $wpdb->prepare( "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'section_id' AND TABLE_NAME LIKE %s", $wpdb->esc_like( $p ) . '%' ) );

foreach ( array( 'Form 3', 'Form 4' ) as $form ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sections = $wpdb->get_results( $wpdb->prepare( "SELECT se.ID, se.label FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id WHERE c.label = %s ORDER BY se.ID", $form ) );
	$by       = array();
	foreach ( $sections as $s ) {
		$by[ $s->label ] = (int) $s->ID;
	}
	if ( isset( $by['Commercials'] ) ) {
		$id   = $by['Commercials'];
		$used = array();
		foreach ( $tables as $t ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$t}` WHERE section_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $n ) {
				$used[] = "$t: $n";
			}
		}
		if ( $used ) {
			$log[] = "$form Commercials (ID $id) is in use, not deleted: " . implode( ', ', $used ) . '. Move its learners to the other section first.';
			continue;
		}
		$wpdb->delete( "{$p}wlsm_sections", array( 'ID' => $id ) );
		$log[] = "$form Commercials (ID $id) deleted (unused)";
	}
	if ( isset( $by['Sciences'] ) ) {
		$wpdb->update( "{$p}wlsm_sections", array( 'label' => 'A', 'updated_at' => current_time( 'mysql' ) ), array( 'ID' => $by['Sciences'] ) );
		$log[] = "$form Sciences (ID {$by['Sciences']}) renamed to $form A";
	}
	if ( ! isset( $by['Commercials'] ) && ! isset( $by['Sciences'] ) ) {
		$log[] = "$form already one class: " . implode( ', ', array_keys( $by ) );
	}
}

if ( class_exists( 'BSA_School' ) ) {
	BSA_School::flush();
	$log[] = 'Classes now: ' . implode( ', ', wp_list_pluck( BSA_School::sections(), 'title' ) );
}
return $log;
