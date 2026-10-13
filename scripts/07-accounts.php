<?php
/**
 * Step 7: tidy the first accounts created by the owner (2026-10-13).
 *
 * 1. Robert Masango: remove the WordPress administrator role (owner decision: he keeps
 *    full School Management control through his Weblizar "admin" staff role), then link
 *    his Weblizar staff record to his login. Weblizar refuses to link WordPress
 *    administrators ("multi-school administrator"), which is why the link was missing.
 * 2. Teacher (Weblizar staff record without a login): create a login from the email on
 *    the staff record, link it, and send WordPress's password-setup email.
 * 3. Bursar: create the Weblizar staff record (role Bursar) for the existing `bursar`
 *    login. Name "Bursar" until the person is known.
 * 4. Learner BSA001: the learner's own login was also set as the parent login. Create a
 *    separate parent login and fill in parent names. The owner asked for fictional
 *    parents for now, so names are marked "(sample)" and the email is non-deliverable.
 *    Also corrects the religion spelling "Christain".
 * 5. Remove the two scheduled tasks and options left behind by All-In-One Security.
 *
 * Linking mirrors WLSM_Staff_General::save_staff(): wlsm_staff.user_id plus the user
 * meta `wlsm_school_id`. Identities are matched by email, never guessed.
 * Re-runnable: each step checks the current state first. Snapshot before running:
 * /home/thwxghpf/bsa-backups/2026-10-13-pre-accounts/portal-db.sql.
 * Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p         = $wpdb->prefix;
$school_id = 1;
$log       = array();
$original  = get_current_user_id();

$staff_for = function ( $email ) use ( $wpdb, $p ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT a.ID AS admin_id, a.name, a.email, a.role_id, s.ID AS staff_id, s.role, s.user_id FROM {$p}wlsm_admins a JOIN {$p}wlsm_staff s ON s.ID = a.staff_id WHERE a.email = %s AND s.school_id = 1", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
$link = function ( $staff_id, $user_id ) use ( $wpdb, $p, $school_id ) {
	$other = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_staff WHERE user_id = %d AND school_id = %d AND ID <> %d", $user_id, $school_id, $staff_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $other ) {
		return "FAIL user #$user_id already has staff record #$other";
	}
	if ( user_can( $user_id, WLSM_ADMIN_CAPABILITY ) ) {
		return "FAIL user #$user_id is a WordPress administrator";
	}
	$wpdb->update( "{$p}wlsm_staff", array( 'user_id' => $user_id, 'updated_at' => current_time( 'Y-m-d H:i:s' ) ), array( 'ID' => $staff_id ) );
	if ( ! get_user_meta( $user_id, 'wlsm_school_id', true ) ) {
		update_user_meta( $user_id, 'wlsm_school_id', $school_id );
	}
	return "linked staff #$staff_id to user #$user_id";
};

// 1. Robert Masango.
$masango = get_user_by( 'login', 'Masango' );
$record  = $masango ? $staff_for( $masango->user_email ) : null;
if ( ! $masango || ! $record ) {
	$log[] = 'FAIL Masango: login or matching staff record (by email) not found';
} else {
	$other_admins = array_diff( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ), array( $masango->ID ) );
	if ( in_array( 'administrator', (array) $masango->roles, true ) ) {
		if ( ! $other_admins ) {
			$log[] = 'FAIL Masango: would leave no administrator';
		} else {
			$masango->set_role( 'subscriber' );
			$log[] = 'Masango: WordPress administrator role removed (now subscriber)';
		}
	}
	$masango = get_user_by( 'id', $masango->ID );
	$log[]   = (int) $record->user_id === (int) $masango->ID ? 'Masango: already linked' : 'Masango: ' . $link( $record->staff_id, $masango->ID );
}

// 2. Teacher without a login (Weblizar role "Teacher").
$teacher_role = (int) $wpdb->get_var( "SELECT ID FROM {$p}wlsm_roles WHERE name = 'Teacher' AND school_id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$teachers     = $wpdb->get_results( $wpdb->prepare( "SELECT a.email, a.name, s.ID AS staff_id FROM {$p}wlsm_admins a JOIN {$p}wlsm_staff s ON s.ID = a.staff_id WHERE a.role_id = %d AND s.user_id IS NULL AND s.school_id = 1", $teacher_role ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$reset_sent   = array();
foreach ( $teachers as $t ) {
	if ( ! is_email( $t->email ) ) {
		$log[] = "FAIL teacher staff #{$t->staff_id}: no valid email on the staff record";
		continue;
	}
	$user = get_user_by( 'email', $t->email );
	if ( ! $user ) {
		$login = sanitize_user( strtolower( strstr( $t->email, '@', true ) ), true );
		$id    = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email'   => $t->email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $t->name,
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $id ) ) {
			$log[] = "FAIL teacher staff #{$t->staff_id}: " . $id->get_error_message();
			continue;
		}
		$user  = get_user_by( 'id', $id );
		$log[] = "teacher staff #{$t->staff_id}: login '{$user->user_login}' created (#{$user->ID})";
	}
	$log[]        = "teacher staff #{$t->staff_id}: " . $link( $t->staff_id, $user->ID );
	$reset_sent[] = $user->user_login;
}

// 3. Bursar staff record for the `bursar` login.
$bursar = get_user_by( 'login', 'bursar' );
if ( $bursar ) {
	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_staff WHERE user_id = %d AND school_id = 1", $bursar->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $existing ) {
		$log[] = "bursar: already has staff record #$existing";
	} else {
		$role = $wpdb->get_row( "SELECT ID, permissions FROM {$p}wlsm_roles WHERE name = 'Bursar' AND school_id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$now  = current_time( 'Y-m-d H:i:s' );
		$wpdb->insert( "{$p}wlsm_staff", array( 'role' => WLSM_M_Role::get_employee_key(), 'permissions' => $role->permissions, 'school_id' => $school_id, 'user_id' => $bursar->ID, 'created_at' => $now ) );
		$staff_id = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wlsm_admins", array( 'name' => 'Bursar', 'email' => $bursar->user_email, 'role_id' => (int) $role->ID, 'staff_id' => $staff_id, 'is_active' => 1, 'joining_date' => current_time( 'Y-m-d' ), 'created_at' => $now ) );
		if ( ! get_user_meta( $bursar->ID, 'wlsm_school_id', true ) ) {
			update_user_meta( $bursar->ID, 'wlsm_school_id', $school_id );
		}
		$log[] = "bursar: staff record #$staff_id created with role Bursar";
	}
}

// 4. Learner BSA001: separate (sample) parent login, parent names, religion spelling.
$learner = $wpdb->get_row( "SELECT ID, user_id, parent_user_id, religion FROM {$p}wlsm_student_records WHERE admission_number = 'BSA001'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( $learner ) {
	$changes = array();
	if ( 'Christain' === $learner->religion ) {
		$changes['religion'] = 'Christian';
	}
	if ( ! $learner->parent_user_id || (int) $learner->parent_user_id === (int) $learner->user_id ) {
		$parent = get_user_by( 'login', 'bsa001.parent' );
		if ( ! $parent ) {
			$pid = wp_insert_user(
				array(
					'user_login'   => 'bsa001.parent',
					'user_email'   => 'bsa001.parent@example.invalid',
					'user_pass'    => wp_generate_password( 32, true, true ),
					'display_name' => 'Parent of BSA001 (sample)',
					'role'         => 'subscriber',
				)
			);
			$parent = is_wp_error( $pid ) ? null : get_user_by( 'id', $pid );
			$log[]  = $parent ? "BSA001: sample parent login 'bsa001.parent' created (#{$parent->ID})" : 'FAIL BSA001 parent: ' . $pid->get_error_message();
		}
		if ( $parent ) {
			$changes += array(
				'parent_user_id' => $parent->ID,
				'father_name'    => 'Tendai Zanamwe (sample)',
				'mother_name'    => 'Rudo Zanamwe (sample)',
			);
		}
	}
	if ( $changes ) {
		$wpdb->update( "{$p}wlsm_student_records", $changes, array( 'ID' => $learner->ID ) );
		$log[] = 'BSA001: updated ' . implode( ', ', array_keys( $changes ) );
	}
}

// 5. All-In-One Security leftovers.
foreach ( array( 'aiowps_clean_old_events', 'aiowps_weekly_cron_event' ) as $hook ) {
	$n = wp_clear_scheduled_hook( $hook );
	if ( $n ) {
		$log[] = "removed scheduled task $hook";
	}
}
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'aiowps%' OR option_name LIKE 'aios\\_%'" ) as $option ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_option( $option );
	$log[] = "removed option $option";
}

// Password-setup emails (owner-approved) for new staff logins.
foreach ( $reset_sent as $login ) {
	$sent  = retrieve_password( $login );
	$log[] = "password-setup email for '$login': " . ( true === $sent ? 'sent' : 'FAIL ' . ( is_wp_error( $sent ) ? $sent->get_error_message() : 'unknown' ) );
}

// Verify what each account can do in School Management (Weblizar's own permission check).
// Weblizar caches the first user's details for the whole request (`wlsm_user_info`),
// so clear it before every check or all checks report the first user's rights.
$can = function ( $user_id, $permission ) {
	wp_cache_delete( 'wlsm_user_info' );
	wp_set_current_user( $user_id );
	$ok = (bool) WLSM_M_Role::can( $permission );
	return $ok;
};
$checks = array(
	'Masango'  => array( 'manage_students' => true, 'manage_invoices' => true, 'manage_settings' => true, 'delete_invoices' => true ),
	'bursar'   => array( 'manage_invoices' => true, 'manage_fees' => true, 'edit_invoices' => true, 'delete_invoices' => false, 'delete_payments' => false, 'manage_students' => false, 'manage_admissions' => false, 'manage_attendance' => false ),
	'teacher'  => array( 'manage_attendance' => true, 'manage_homework' => true, 'assigned_class' => true, 'manage_fees' => false, 'manage_invoices' => false, 'manage_students' => false, 'manage_exams' => false ),
);
$ids = array(
	'Masango' => $masango ? $masango->ID : 0,
	'bursar'  => $bursar ? $bursar->ID : 0,
	'teacher' => $teachers ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$p}wlsm_staff WHERE ID = %d", $teachers[0]->staff_id ) ) : (int) $wpdb->get_var( $wpdb->prepare( "SELECT s.user_id FROM {$p}wlsm_staff s JOIN {$p}wlsm_admins a ON a.staff_id = s.ID WHERE a.role_id = %d LIMIT 1", $teacher_role ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
foreach ( $checks as $who => $perms ) {
	foreach ( $perms as $perm => $expected ) {
		$got   = $ids[ $who ] ? $can( $ids[ $who ], $perm ) : null;
		$log[] = ( $got === $expected ? 'PASS ' : 'FAIL ' ) . "$who $perm = " . var_export( $got, true );
	}
}
wp_cache_delete( 'wlsm_user_info' );
wp_set_current_user( $original );
foreach ( $ids as $who => $id ) {
	$log[] = "$who: WordPress roles " . implode( '|', (array) get_userdata( $id )->roles ) . ', staff ' . ( BSA_Access_Guard::is_staff( $id ) ? 'yes' : 'no' ) . ', family ' . ( BSA_Portal::is_family_user( $id ) ? 'yes' : 'no' );
}
$parent = get_user_by( 'login', 'bsa001.parent' );
if ( $parent ) {
	$log[] = 'bsa001.parent: family ' . ( BSA_Portal::is_family_user( $parent->ID ) ? 'yes' : 'no' ) . ', owns records ' . implode( ',', BSA_Access_Guard::owned_student_ids( $parent->ID ) );
}
$log[] = 'administrators: ' . implode( ', ', get_users( array( 'role' => 'administrator', 'fields' => 'user_login' ) ) );

return $log;
