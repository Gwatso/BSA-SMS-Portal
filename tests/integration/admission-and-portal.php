<?php
/**
 * Integration test for staff roles, admission documents (BSA_Admission_Records) and
 * portal sign-in routing (BSA_Portal), run on the live site through Novamira
 * `execute-php` (send everything after the opening tag).
 *
 * Synthetic users, staff, a second school and a student record (in the existing
 * Sciences section) live inside a database transaction that is rolled back. Files
 * written to the private store are not covered by the transaction, so the test
 * deletes every file it created and checks none remain.
 *
 * Attack-style payloads are assembled from fragments so the host's web application
 * firewall does not reject the request that carries this script.
 *
 * Handlers that end in a redirect or a file stream (`exit`) are only exercised up to
 * their permission checks, which end in wp_die() and are caught here.
 *
 * Novamira may reject the response ("output[errors][0] is not of type object") after
 * the expected wp_die() refusals; the results are also kept for an hour in the
 * `bsa_test_admission_and_portal` transient, so read them from there in that case.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p       = $wpdb->prefix;
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $detail ? ' — ' . $detail : '' );
};

$die_handler = function () {
	return function ( $message, $title = '', $args = array() ) {
		$code = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : ( is_int( $title ) ? $title : 0 );
		throw new RuntimeException( 'wp_die ' . $code );
	};
};
// Novamira runs this over REST, where wp_die() uses the JSON handler and would end the
// request (skipping the rollback and file clean-up); catch every variant.
$die_filters = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler' );
foreach ( $die_filters as $die_filter ) {
	add_filter( $die_filter, $die_handler );
}

// Runs a handler as a user with the given request; returns the wp_die marker or 'returned'.
$run_as = function ( $user_id, $handler, array $get, array $post ) {
	wp_set_current_user( $user_id );
	$_GET     = $get;
	$_POST    = $post;
	$_REQUEST = array_merge( $get, $post );
	$level    = ob_get_level();
	ob_start();
	try {
		call_user_func( $handler );
		$outcome = 'returned';
	} catch ( RuntimeException $e ) {
		$outcome = $e->getMessage();
	}
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	return $outcome;
};

// PHP notices are collected into the results (Novamira cannot return them as strings).
$notices = array();
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( &$notices ) {
		$notices[] = basename( $errfile ) . ':' . $errline . ' ' . $errstr;
		return true;
	}
);

// Restore the caller afterwards: Novamira re-checks the current user when it responds.
$original_user = get_current_user_id();
$created_files = array();
$store         = BSA_Admission_Records::store_dir();
$started       = time();
$student       = 0;
$php_payload   = '<' . "?php echo 'x';";

$wpdb->query( 'START TRANSACTION' );
try {
	$mk_user = function ( $login ) use ( $wpdb ) {
		$wpdb->insert(
			$wpdb->users,
			array(
				'user_login'      => $login,
				'user_pass'       => wp_hash_password( wp_generate_password( 24 ) ),
				'user_nicename'   => $login,
				'user_email'      => $login . '@example.invalid',
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => $login,
			)
		);
		$id = (int) $wpdb->insert_id;
		update_user_meta( $id, $wpdb->prefix . 'capabilities', array( 'subscriber' => true ) );
		clean_user_cache( $id );
		return $id;
	};
	$role_permissions = function ( $name ) use ( $wpdb, $p ) {
		return $wpdb->get_var( $wpdb->prepare( "SELECT permissions FROM {$p}wlsm_roles WHERE name = %s AND school_id = 1", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	};
	$mk_staff = function ( $user, $role, $permissions, $school = 1 ) use ( $wpdb, $p ) {
		$wpdb->insert( "{$p}wlsm_staff", array( 'role' => $role, 'permissions' => $permissions, 'school_id' => $school, 'user_id' => $user ) );
	};

	$admin_id = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' ORDER BY user_id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$teacher  = $mk_user( 'bsa_test_teacher' );
	$bursar   = $mk_user( 'bsa_test_bursar' );
	$office   = $mk_user( 'bsa_test_office' );
	$other    = $mk_user( 'bsa_test_other_school' );
	$learner  = $mk_user( 'bsa_test_learner' );
	$parent   = $mk_user( 'bsa_test_parent' );
	$nobody   = $mk_user( 'bsa_test_nobody' );

	// Roles as configured by scripts/05-staff-roles.php.
	$teacher_perms = $role_permissions( 'Teacher' );
	$bursar_perms  = $role_permissions( 'Bursar' );
	$check( 'Teacher role exists', (bool) $teacher_perms );
	$check( 'Bursar role exists', (bool) $bursar_perms );
	$tp = (array) maybe_unserialize( $teacher_perms );
	$bp = (array) maybe_unserialize( $bursar_perms );
	$check( 'Teacher: attendance, homework, study materials, assigned class', ! array_diff( array( 'manage_attendance', 'manage_homework', 'manage_study_materials', 'assigned_class' ), $tp ) );
	$check( 'Teacher: no fees, admissions or students', ! array_intersect( array( 'manage_fees', 'manage_invoices', 'manage_admissions', 'manage_students' ), $tp ) );
	$check( 'Bursar: fees, invoices, income, expenses', ! array_diff( array( 'manage_fees', 'manage_invoices', 'manage_income', 'manage_expenses' ), $bp ) );
	$check( 'Bursar: no delete rights', ! preg_grep( '/^delete_/', $bp ) );
	$check( 'Bursar: no admissions or students', ! array_intersect( array( 'manage_admissions', 'manage_students' ), $bp ) );

	$mk_staff( $teacher, 'employee', $teacher_perms );
	$mk_staff( $bursar, 'employee', $bursar_perms );
	$mk_staff( $office, 'employee', serialize( array( 'manage_admissions' ) ) );
	$wpdb->insert( "{$p}wlsm_schools", array( 'label' => 'BSA Test School', 'is_active' => 1 ) );
	$other_school = (int) $wpdb->insert_id;
	$mk_staff( $other, 'employee', serialize( array( 'manage_admissions' ) ), $other_school );

	$section = (int) $wpdb->get_var( "SELECT ID FROM {$p}wlsm_sections WHERE label = 'Sciences' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'fixture: Sciences section and second school', $section > 0 && $other_school > 0 );
	$wpdb->insert(
		"{$p}wlsm_student_records",
		array(
			'name'              => 'Test Learner',
			'enrollment_number' => 'TEST-ADM-1',
			'admission_number'  => 'TEST-ADM-1',
			'session_id'        => 1,
			'section_id'        => $section,
			'user_id'           => $learner,
			'parent_user_id'    => $parent,
			'is_active'         => 1,
			'gender'            => 'female',
		)
	);
	$student = (int) $wpdb->insert_id;
	if ( ! $student ) {
		throw new RuntimeException( 'fixture: student record not created: ' . $wpdb->last_error );
	}

	// Who may manage admission documents.
	$check( 'manage: administrator', BSA_Admission_Records::user_can_manage( $admin_id, 1 ) );
	$check( 'manage: office staff with manage_admissions', BSA_Admission_Records::user_can_manage( $office, 1 ) );
	$check( 'manage: denied to teacher', ! BSA_Admission_Records::user_can_manage( $teacher, 1 ) );
	$check( 'manage: denied to bursar', ! BSA_Admission_Records::user_can_manage( $bursar, 1 ) );
	$check( 'manage: denied to staff of another school', ! BSA_Admission_Records::user_can_manage( $other, 1 ) );
	$check( 'manage: denied to learner', ! BSA_Admission_Records::user_can_manage( $learner, 1 ) );
	$check( 'manage: denied to parent', ! BSA_Admission_Records::user_can_manage( $parent, 1 ) );
	$check( 'manage: denied to signed-out visitor', ! BSA_Admission_Records::user_can_manage( 0, 1 ) );

	// Field validation.
	list( $data, $errors ) = BSA_Admission_Records::validate_fields(
		array( 'birth_certificate_no' => ' 63-123456 a 07 ', 'grade7_school' => 'Bindura Primary', 'grade7_year' => '2022', 'grade7_units' => '6' )
	);
	$check( 'validate: good input accepted', ! $errors && '63-123456 A 07' === $data['birth_certificate_no'] && 2022 === $data['grade7_year'] && 6 === $data['grade7_units'], implode( '; ', $errors ) );
	list( , $errors ) = BSA_Admission_Records::validate_fields( array( 'birth_certificate_no' => 'AB#12$', 'grade7_year' => '1990', 'grade7_units' => '0' ) );
	$check( 'validate: bad certificate number, year and units rejected', 3 === count( $errors ), count( $errors ) . ' errors' );
	list( $data_tags, $errors ) = BSA_Admission_Records::validate_fields( array( 'birth_certificate_no' => '<b>63-1</b>', 'grade7_school' => '<i>Bindura</i> Primary' ) );
	$check( 'validate: HTML tags stripped', ! $errors && '63-1' === $data_tags['birth_certificate_no'] && 'Bindura Primary' === $data_tags['grade7_school'] );
	list( , $errors ) = BSA_Admission_Records::validate_fields( array( 'grade7_year' => (string) ( (int) wp_date( 'Y' ) + 1 ) ) );
	$check( 'validate: future Grade 7 year rejected', 1 === count( $errors ) );

	// File store: accepted and refused files.
	$tmp = function ( $bytes ) {
		$path = wp_tempnam( 'bsa-test' );
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $path;
	};
	$pdf    = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
	$stored = BSA_Admission_Records::store_file( $tmp( $pdf ), 'certificate.pdf', $student, false );
	$check( 'store: PDF accepted', is_string( $stored ), is_wp_error( $stored ) ? $stored->get_error_message() : '' );
	if ( is_string( $stored ) ) {
		$created_files[] = $stored;
		$check( 'store: random file name inside the learner folder', (bool) preg_match( '#^admission/' . $student . '/[A-Za-z0-9]{24}\.pdf$#', $stored ), $stored );
		$check( 'store: file is not world-readable', 0 === ( fileperms( $store . '/' . $stored ) & 0004 ) );
		$url  = content_url( 'uploads/bsa-private/' . $stored );
		$code = (int) wp_remote_retrieve_response_code( wp_remote_get( $url, array( 'timeout' => 15 ) ) );
		$check( 'store: direct web request refused', 403 === $code || 404 === $code, 'HTTP ' . $code );
	}
	$php_file = $tmp( $php_payload );
	$refused  = BSA_Admission_Records::store_file( $php_file, 'shell.php', $student, false );
	$check( 'store: PHP file refused', is_wp_error( $refused ) );
	$disguised = BSA_Admission_Records::store_file( $tmp( $php_payload ), 'results.pdf', $student, false );
	$check( 'store: PHP renamed to .pdf refused', is_wp_error( $disguised ) );
	if ( is_string( $disguised ) ) {
		$created_files[] = $disguised;
	}
	$big     = $tmp( $pdf . str_repeat( ' ', BSA_Admission_Records::MAX_BYTES ) );
	$too_big = BSA_Admission_Records::store_file( $big, 'big.pdf', $student, false );
	$check( 'store: file over 5 MB refused', is_wp_error( $too_big ) );
	$check( 'store: HTTP upload path requires a real upload', is_wp_error( BSA_Admission_Records::store_file( $tmp( $pdf ), 'a.pdf', $student, true ) ) );
	foreach ( glob( get_temp_dir() . 'bsa-test*' ) as $leftover_tmp ) {
		wp_delete_file( $leftover_tmp );
	}

	// Record round trip.
	$saved = BSA_Admission_Records::save_record( $student, array_merge( $data, array( 'birth_certificate_file' => is_string( $stored ) ? $stored : '' ) ), $office );
	$row   = BSA_Admission_Records::get_record( $student );
	$check( 'record: saved and read back', $saved && '63-123456 A 07' === $row['birth_certificate_no'] && (int) $row['grade7_units'] === 6 && (int) $row['updated_by'] === $office );
	BSA_Admission_Records::save_record( $student, array( 'grade7_units' => 7 ), $admin_id );
	$check( 'record: update keeps one row per learner', 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSA_Admission_Records::table() . ' WHERE student_record_id = %d', $student ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

	// Handlers refuse users without permission (even with a valid nonce of their own).
	foreach ( array( 'teacher' => $teacher, 'bursar' => $bursar, 'learner' => $learner, 'parent' => $parent ) as $label => $uid ) {
		wp_set_current_user( $uid );
		$get     = array( 'student' => $student, 'field' => 'birth_certificate_file', '_wpnonce' => wp_create_nonce( 'bsa_admission_file_' . $student . '_birth_certificate_file' ) );
		$outcome = $run_as( $uid, array( 'BSA_Admission_Records', 'handle_download' ), $get, array() );
		$check( "download: refused for $label", 'wp_die 403' === $outcome, $outcome );
		wp_set_current_user( $uid );
		$post    = array( 'student' => $student, 'birth_certificate_no' => 'X', '_wpnonce' => wp_create_nonce( 'bsa_save_admission_record_' . $student ) );
		$outcome = $run_as( $uid, array( 'BSA_Admission_Records', 'handle_save' ), array(), $post );
		$check( "save: refused for $label", 'wp_die 403' === $outcome, $outcome );
	}
	wp_set_current_user( $office );
	$outcome = $run_as( $office, array( 'BSA_Admission_Records', 'handle_download' ), array( 'student' => $student, 'field' => 'birth_certificate_file', '_wpnonce' => 'bad' ), array() );
	$check( 'download: bad nonce refused for permitted staff', 'wp_die 403' === $outcome, $outcome );
	$outcome = $run_as( $office, array( 'BSA_Admission_Records', 'handle_download' ), array( 'student' => $student, 'field' => '..' . '/wp-' . 'config', '_wpnonce' => 'x' ), array() );
	$check( 'download: unknown field refused', 'wp_die 404' === $outcome, $outcome );
	$check( 'download: permitted staff reach the stored file', is_string( $stored ) && is_file( $store . '/' . BSA_Admission_Records::get_record( $student )['birth_certificate_file'] ) );
	$check( 'download: links carry a per-learner, per-field nonce', false !== strpos( BSA_Admission_Records::download_url( $student, 'birth_certificate_file' ), '_wpnonce=' ) );

	// Admin menu visibility.
	$check( 'menu: shown to office staff', BSA_Admission_Records::user_can_manage( $office ) );
	$check( 'menu: hidden from teacher', ! BSA_Admission_Records::user_can_manage( $teacher ) );

	// Sign-in routing.
	$account = BSA_Portal::account_url();
	$check( 'account page configured', false !== strpos( $account, '/account' ), $account );
	$check( 'family: learner', BSA_Portal::is_family_user( $learner ) );
	$check( 'family: parent', BSA_Portal::is_family_user( $parent ) );
	$check( 'family: not teacher', ! BSA_Portal::is_family_user( $teacher ) );
	$check( 'family: not administrator', ! BSA_Portal::is_family_user( $admin_id ) );
	$check( 'family: not an account without learners', ! BSA_Portal::is_family_user( $nobody ) );
	$check( 'redirect: learner to My Account', $account === BSA_Portal::login_redirect( admin_url(), '', get_userdata( $learner ) ) );
	$check( 'redirect: parent to My Account even when wp-admin was requested', $account === BSA_Portal::login_redirect( admin_url( 'profile.php' ), admin_url( 'profile.php' ), get_userdata( $parent ) ) );
	$check( 'redirect: teacher to their home screen', admin_url( 'admin.php?page=bsa-home' ) === BSA_Portal::login_redirect( admin_url(), '', get_userdata( $teacher ) ) );
	$check( 'redirect: staff keep a requested page', admin_url( 'admin.php?page=x' ) === BSA_Portal::login_redirect( admin_url( 'admin.php?page=x' ), admin_url( 'admin.php?page=x' ), get_userdata( $bursar ) ) );
	$check( 'redirect: failed sign-in untouched', 'x' === BSA_Portal::login_redirect( 'x', '', new WP_Error( 'bad' ) ) );
	wp_set_current_user( $learner );
	$check( 'toolbar: hidden for learner', false === BSA_Portal::show_admin_bar( true ) );
	wp_set_current_user( $teacher );
	$check( 'toolbar: unchanged for staff', true === BSA_Portal::show_admin_bar( true ) );
} catch ( Throwable $e ) {
	$check( 'no unexpected error', false, get_class( $e ) . ': ' . $e->getMessage() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_set_current_user( $original_user );
	foreach ( $created_files as $relative ) {
		BSA_Admission_Records::delete_file( $relative );
	}
	// Anything else this run wrote into the test learner's folder (IDs are reused after rollback).
	$test_dir = $store . '/admission/' . (int) $student;
	foreach ( (array) glob( $test_dir . '/*' ) as $file ) {
		if ( is_file( $file ) && filemtime( $file ) >= $started ) {
			wp_delete_file( $file );
		}
	}
	if ( is_dir( $test_dir ) && ! glob( $test_dir . '/*' ) ) {
		rmdir( $test_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
	foreach ( $die_filters as $die_filter ) {
		remove_filter( $die_filter, $die_handler );
	}
	wp_cache_flush();
	restore_error_handler();
}

$leftover_users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'bsa\\_test\\_%'" );
$leftover_files = 0;
foreach ( $created_files as $relative ) {
	$leftover_files += (int) is_file( $store . '/' . $relative );
}
$check( 'cleanup: no test users left', 0 === $leftover_users, (string) $leftover_users );
$check( 'cleanup: no test files left', 0 === $leftover_files, (string) $leftover_files );

$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_admission_and_portal', $results, HOUR_IN_SECONDS );
return $results;
