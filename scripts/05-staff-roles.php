<?php
/**
 * Step 5: Weblizar staff roles for teachers and the bursar (least privilege).
 *
 * Teacher: own assigned class only; attendance, leave requests, study materials,
 *   homework, lessons, timetable (view). No marks/exams yet: Weblizar's single
 *   "manage_exams" permission also creates exams and publishes results, so it waits for
 *   the approved grading rules and report template.
 * Bursar: fee types, invoices (create and edit), income, expenses, finance statistics.
 *   No delete_invoices / delete_payments, so completed financial records cannot be
 *   deleted by the bursar.
 *
 * Uses Weblizar's own role handler. Safe to re-run (updates the role if it exists).
 * Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p   = $wpdb->prefix;
$log = array();

if ( ! class_exists( 'WLSM_Staff_General' ) ) {
	require_once WLSM_PLUGIN_DIR_PATH . 'admin/admin.php';
}

$roles = array(
	'Teacher' => array( 'view_timetable', 'manage_attendance', 'manage_student_leaves', 'manage_study_materials', 'manage_homework', 'manage_lessons', 'assigned_class' ),
	'Bursar'  => array( 'manage_fees', 'manage_invoices', 'edit_invoices', 'manage_income', 'manage_expenses', 'stats_payments', 'stats_amount_fees_structure', 'stats_income', 'stats_expense' ),
);

$known = array_keys( WLSM_M_Role::get_permissions() );
foreach ( $roles as $permissions ) {
	$unknown = array_diff( $permissions, $known );
	if ( $unknown ) {
		return array( 'FAIL unknown permission(s): ' . implode( ', ', $unknown ) );
	}
}

// Call a Weblizar AJAX handler in-process; returns 'OK …' or 'FAIL …'.
$call = function ( $handler, array $post ) {
	wp_set_current_user( 1 );
	$_POST    = $post;
	$_REQUEST = $post;
	$level    = ob_get_level();
	ob_start();
	ob_start();
	$die = function () {
		return function () {
			throw new RuntimeException( 'wp_die' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $die );
	add_filter( 'wp_die_handler', $die );
	add_filter( 'wp_doing_ajax', '__return_true' );
	try {
		call_user_func( $handler );
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$out = '';
	while ( ob_get_level() > $level ) {
		$out = ob_get_clean() . $out;
	}
	remove_filter( 'wp_die_ajax_handler', $die );
	remove_filter( 'wp_die_handler', $die );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	$_POST    = array();
	$_REQUEST = array();
	$yes      = strpos( $out, '"success":true' );
	$no       = strpos( $out, '"success":false' );
	return ( false !== $yes && ( false === $no || $yes < $no ) ? 'OK ' : 'FAIL ' ) . substr( wp_strip_all_tags( $out ), 0, 120 );
};

foreach ( $roles as $name => $permissions ) {
	$role_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_roles WHERE school_id = 1 AND name = %s", $name ) );
	$post    = array( 'name' => $name, 'permission' => $permissions );
	if ( $role_id ) {
		$post['role_id']                = $role_id;
		$post[ "edit-role-$role_id" ]  = wp_create_nonce( "edit-role-$role_id" );
	} else {
		$post['add-role'] = wp_create_nonce( 'add-role' );
	}
	$log[] = ( $role_id ? 'update' : 'add' ) . " role $name: " . $call( array( 'WLSM_Staff_General', 'save_role' ), $post );
}
wp_set_current_user( 0 );

foreach ( $wpdb->get_results( "SELECT name, permissions FROM {$p}wlsm_roles WHERE school_id = 1 ORDER BY ID" ) as $row ) {
	$saved = maybe_unserialize( $row->permissions );
	$log[] = $row->name . ': ' . implode( ', ', is_array( $saved ) ? $saved : array( (string) $row->permissions ) );
}

return $log;
