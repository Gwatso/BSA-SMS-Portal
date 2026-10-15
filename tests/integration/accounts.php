<?php
/**
 * Integration test for BSA_Accounts (Family Accounts check), run on the live site through
 * Novamira `execute-php` (send everything after the opening tag).
 *
 * Synthetic learners and logins covering each problem are written inside a transaction
 * and rolled back. Before activation, set $bsa_stage to the staged plugin folder to test
 * that copy. Results are also kept for an hour in the `bsa_test_accounts` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
if ( ! class_exists( 'BSA_Accounts' ) && $bsa_stage ) {
	require_once $bsa_stage . '/includes/class-bsa-accounts.php';
}
require_once ABSPATH . 'wp-admin/includes/template.php';

$p       = $wpdb->prefix;
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$original = get_current_user_id();
$notices  = array();
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( &$notices ) {
		$notices[] = basename( $errfile ) . ':' . $errline . ' ' . $errstr;
		return true;
	}
);
$die = function () {
	return function ( $m ) {
		throw new RuntimeException( 'wp_die: ' . ( is_string( $m ) ? wp_strip_all_tags( $m ) : '' ) );
	};
};
// Novamira runs inside a REST request, where wp_die() uses the JSON handler.
$die_hooks = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler' );
foreach ( $die_hooks as $hook ) {
	add_filter( $hook, $die, 999 );
}

// Helpers (no database).
$check( 'email: missing, invalid, placeholder and real addresses told apart', '' !== BSA_Accounts::email_problem( '' ) && '' !== BSA_Accounts::email_problem( 'nope' ) && '' !== BSA_Accounts::email_problem( 'a@example.invalid' ) && '' !== BSA_Accounts::email_problem( 'a@example.com' ) && '' === BSA_Accounts::email_problem( 'parent@gmail.com' ) && '' === BSA_Accounts::email_problem( 'bsa002@bindurascienceacademy.co.zw' ) );
$check( 'phone: +263 77 333 4444, 0773334444 and 773 334 444 match; short numbers ignored', BSA_Accounts::phone_key( '+263 77 333 4444' ) === BSA_Accounts::phone_key( '0773334444' ) && BSA_Accounts::phone_key( '773 334 444' ) === BSA_Accounts::phone_key( '0773334444' ) && '' === BSA_Accounts::phone_key( '1234' ) );

$wpdb->query( 'START TRANSACTION' );
try {
	$user = function ( $login ) {
		$u = get_user_by( 'login', $login );
		return $u ? (int) $u->ID : 0;
	};
	$owner   = $user( 'bsa' );
	$head    = $user( 'Masango' );
	$teacher = $user( 'hlabati.w' );
	$bursar  = $user( 'bursar' );
	$mk_user = function ( $login, $email ) use ( $wpdb ) {
		$wpdb->insert( $wpdb->users, array( 'user_login' => $login, 'user_pass' => 'x', 'user_nicename' => $login, 'user_email' => $email, 'user_registered' => current_time( 'mysql', true ), 'display_name' => $login ) );
		return (int) $wpdb->insert_id;
	};
	$p1 = $mk_user( 'bsa_test_acc_p1', 'family.one@gmail.com' );
	$p2 = $mk_user( 'bsa_test_acc_p2', 'p2@example.invalid' );
	$p3 = $mk_user( 'bsa_test_acc_p3', 'family.three@gmail.com' );
	$l1 = $mk_user( 'bsa_test_acc_l1', 'testacc1@bindurascienceacademy.co.zw' );
	$l2 = $mk_user( 'bsa_test_acc_l2', 'testacc2@bindurascienceacademy.co.zw' );
	$l3 = $mk_user( 'bsa_test_acc_l3', 'not-an-email' );
	$sh = $mk_user( 'bsa_test_acc_shared', 'shared@gmail.com' );
	$rec = function ( $name, $user, $parent, $phone = '' ) use ( $wpdb, $p ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'enrollment_number' => 'TEST-ACC', 'admission_number' => 'TEST-ACC', 'session_id' => 1, 'section_id' => 4, 'user_id' => $user ? $user : null, 'parent_user_id' => $parent ? $parent : null, 'father_phone' => $phone, 'is_active' => 1 ) );
		return (int) $wpdb->insert_id;
	};
	$ok_a   = $rec( 'Acc Ok A', $l1, $p1, '+263 71 900 0001' );
	$ok_b   = $rec( 'Acc Ok B', 0, $p1, '0719000001' );
	$split1 = $rec( 'Acc Split One', 0, $p2, '0719000002' );
	$split2 = $rec( 'Acc Split Two', 0, $p3, '+263719000002' );
	$shared = $rec( 'Acc Shared', $sh, $sh );
	$cross1 = $rec( 'Acc Cross Child', $l2, $p3 );
	$cross2 = $rec( 'Acc Cross Sibling', 0, $l2 );
	$none   = $rec( 'Acc No Parent', 0, 0 );
	$gone   = $rec( 'Acc Gone Parent', 0, 999999999 );
	$admin  = $rec( 'Acc Admin Parent', 0, $owner );
	$staff  = $rec( 'Acc Staff Parent', 0, $teacher );
	$bademl = $rec( 'Acc Bad Email', $l3, $p1 );
	BSA_School::flush();

	$rows   = BSA_Accounts::learners( 1 );
	$issues = BSA_Accounts::issues( $rows );
	$codes  = function ( $id ) use ( $issues ) {
		return wp_list_pluck( $issues[ $id ] ?? array(), 'code' );
	};
	$check( 'listed: all 12 synthetic learners in 2026', 12 === count( array_intersect( array( $ok_a, $ok_b, $split1, $split2, $shared, $cross1, $cross2, $none, $gone, $admin, $staff, $bademl ), array_map( 'intval', wp_list_pluck( $rows, 'ID' ) ) ) ) );
	$check( 'ok: two children on one parent login with the same phone have no problems', array() === $codes( $ok_a ) && array() === $codes( $ok_b ), wp_json_encode( array( $codes( $ok_a ), $codes( $ok_b ) ) ) );
	$check( 'siblings: same phone, different parent logins flagged on both', in_array( 'siblings_split', $codes( $split1 ), true ) && in_array( 'siblings_split', $codes( $split2 ), true ) );
	$check( 'email: placeholder parent email flagged (p2)', in_array( 'parent_email', $codes( $split1 ), true ) && ! in_array( 'parent_email', $codes( $split2 ), true ) );
	$check( 'shared: parent and learner on one login is an error', in_array( 'shared_login', $codes( $shared ), true ) && 'error' === $issues[ $shared ][ array_search( 'shared_login', $codes( $shared ), true ) ]['severity'] );
	$check( 'crossed: a child\'s login used as a sibling\'s parent login flagged on both', in_array( 'learner_is_parent_login', $codes( $cross1 ), true ) && in_array( 'parent_is_learner_login', $codes( $cross2 ), true ) );
	$check( 'missing: no parent login is a warning; a deleted parent login an error', in_array( 'no_parent_login', $codes( $none ), true ) && in_array( 'parent_login_missing', $codes( $gone ), true ) );
	$check( 'admin as parent is an error; teacher as parent only a note', in_array( 'parent_is_admin', $codes( $admin ), true ) && in_array( 'parent_is_staff', $codes( $staff ), true ) && 'info' === $issues[ $staff ][0]['severity'] );
	$check( 'email: invalid learner login email flagged', in_array( 'learner_email', $codes( $bademl ), true ) );
	$check( 'learner without own login is not a problem', ! in_array( 'no_learner_login', $codes( $ok_b ), true ) && array() === $codes( $ok_b ) );

	$mine    = array_values(
		array_filter(
			$rows,
			static function ( $r ) {
				return 'TEST-ACC' === $r->admission_number;
			}
		)
	);
	$summary = BSA_Accounts::summary( $mine, $issues );
	// With an existing parent login: all but "no parent" and "deleted parent" (10). Families:
	// p1, p2, p3, shared, l2, owner, teacher (7). Own login: A, shared, cross child, bad email (4).
	// To fix (errors): shared, both crossed, deleted parent, admin parent (5). To check: both
	// split siblings, no parent, bad learner email (4).
	$check( 'summary: 12 learners, 10 with a parent login, 7 families, 4 with own login, 5 to fix, 4 to check', array( 12, 10, 7, 4, 5, 4 ) === array( $summary['learners'], $summary['with_parent'], $summary['families'], $summary['with_login'], $summary['errors'], $summary['warnings'] ), wp_json_encode( $summary ) );

	// Rights and screen.
	$check( 'rights: owner and school administrator see the screen; teacher and bursar do not', BSA_Accounts::user_can_view( $owner ) && BSA_Accounts::user_can_view( $head ) && ! BSA_Accounts::user_can_view( $teacher ) && ! BSA_Accounts::user_can_view( $bursar ) );
	wp_set_current_user( $head );
	$_GET = array( 'page' => BSA_Accounts::PAGE );
	ob_start();
	BSA_Accounts::render_page();
	$html = ob_get_clean();
	$check( 'screen: problems listed with edit links and the office guide; clean learners hidden by default', false !== strpos( $html, 'Acc Shared' ) && false !== strpos( $html, 'page=sm-staff-students&#038;action=save&#038;id=' . $shared ) && false !== strpos( $html, 'How to set up family accounts' ) && false === strpos( $html, 'Acc Ok A' ) );
	$_GET = array( 'page' => BSA_Accounts::PAGE, 'all' => '1' );
	ob_start();
	BSA_Accounts::render_page();
	$html = ob_get_clean();
	$check( 'screen: "show every learner" includes the clean ones', false !== strpos( $html, 'Acc Ok A' ) && false !== strpos( $html, '3 children linked' ) );
	wp_set_current_user( $bursar );
	$denied = false;
	try {
		ob_start();
		BSA_Accounts::render_page();
	} catch ( RuntimeException $e ) {
		$denied = 0 === strpos( $e->getMessage(), 'wp_die' );
	} finally {
		ob_end_clean();
	}
	$check( 'screen: bursar refused', $denied );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	$_GET = array();
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	foreach ( $die_hooks as $hook ) {
		remove_filter( $hook, $die, 999 );
	}
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number = 'TEST-ACC'" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'bsa\\_test\\_%'" );
$check( 'rollback: no synthetic learners or users left', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_accounts', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
