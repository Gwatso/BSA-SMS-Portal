<?php
/**
 * End-to-end test of the learner and parent account pages over HTTP, run on the live
 * site through Novamira `execute-php` (send everything after the opening tag) after
 * deployment.
 *
 * The pages are fetched by loopback requests signed in as synthetic users, so the
 * fixtures cannot sit in a transaction (the requests use their own database connection):
 * a synthetic learner (Form 3 A, own login), a sibling (Form 1 A) and their parent,
 * an invoice with a part-payment, attendance, a notice and a published report card are
 * written, the pages fetched, and every row deleted by ID in `finally` (checked at the
 * end). The fee audit hook is detached meanwhile so no test entries reach Simple History.
 * Results are kept for an hour in the `bsa_test_family_http` transient and the pages in
 * `bsa_capture_family`.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p = $wpdb->prefix;
remove_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );
$made  = array( 'users' => array(), 'records' => array(), 'invoices' => array(), 'payments' => array(), 'notices' => array() );
$pages = array();
$log   = array();
$extra = array(
	'learner-report-cards'        => array( 'learner', array( 'action' => 'report-cards' ) ),
	'parent-report-cards'         => array( 'parent', array( 'action' => 'report-cards', 'student_id' => '{a}' ) ),
	'parent-report-cards-sibling' => array( 'parent', array( 'action' => 'report-cards', 'student_id' => '{b}' ) ),
	'parent-fee-structure-sibling' => array( 'parent', array( 'action' => 'fee-structure', 'student_id' => '{b}' ) ),
	'parent-report-cards-foreign' => array( 'parent', array( 'action' => 'report-cards', 'student_id' => '1' ) ),
);
try {
	$mk_user = function ( $login, $name ) use ( $wpdb, &$made ) {
		$wpdb->insert( $wpdb->users, array( 'user_login' => $login, 'user_pass' => wp_hash_password( wp_generate_password( 32 ) ), 'user_nicename' => $login, 'user_email' => $login . '@example.invalid', 'user_registered' => current_time( 'mysql', true ), 'display_name' => $name ) );
		$made['users'][] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	};
	$la  = $mk_user( 'bsa_test_fam_learner', 'Tariro Test' );
	$par = $mk_user( 'bsa_test_fam_parent', 'Test Parent' );
	$sci = (int) $wpdb->get_var( "SELECT se.ID FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id WHERE c.label = 'Form 3' AND se.label = 'A'" );
	$rec = function ( $name, $section, $user, $no ) use ( $wpdb, $p, $par, &$made ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'gender' => 'female', 'enrollment_number' => $no, 'admission_number' => $no, 'session_id' => 1, 'section_id' => $section, 'user_id' => $user ? $user : null, 'parent_user_id' => $par, 'father_name' => 'Test Parent', 'father_phone' => '+263 77 000 0000', 'is_active' => 1, 'admission_date' => '2026-01-13', 'created_at' => current_time( 'mysql' ) ) );
		$made['records'][] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	};
	$a = $rec( 'Tariro Test', $sci, $la, 'TEST-FAM-A' );
	$b = $rec( 'Kuda Test', 4, 0, 'TEST-FAM-B' );
	$unpaid  = WLSM_M_Invoice::get_unpaid_key();
	$partial = method_exists( 'WLSM_M_Invoice', 'get_partially_paid_key' ) ? WLSM_M_Invoice::get_partially_paid_key() : $unpaid;
	$inv = function ( $sid, $no, $status ) use ( $wpdb, $p, &$made ) {
		$wpdb->insert( "{$p}wlsm_invoices", array( 'invoice_number' => $no, 'label' => 'Term 3 Fees', 'amount' => 250, 'discount' => 0, 'date_issued' => '2026-09-08', 'due_date' => '2026-09-30', 'partial_payment' => 1, 'status' => $status, 'student_record_id' => $sid, 'created_at' => current_time( 'mysql' ) ) );
		$made['invoices'][] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	};
	$ia = $inv( $a, 'TEST-FAM-1', $partial );
	$inv( $b, 'TEST-FAM-2', $unpaid );
	$wpdb->insert( "{$p}wlsm_payments", array( 'receipt_number' => 'TEST-FAM-R1', 'amount' => 100, 'payment_method' => 'cash', 'invoice_label' => 'Term 3 Fees', 'invoice_payable' => 250, 'invoice_id' => $ia, 'student_record_id' => $a, 'school_id' => 1, 'created_at' => '2026-09-10 10:00:00', 'added_by' => 1 ) );
	$made['payments'][] = (int) $wpdb->insert_id;
	foreach ( array( '2026-09-08' => 'p', '2026-09-09' => 'p', '2026-09-10' => 'a', '2026-09-11' => 'l' ) as $d => $s ) {
		$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => $d, 'status' => $s, 'student_record_id' => $a, 'added_by' => 1, 'created_at' => current_time( 'mysql' ) ) );
	}
	$wpdb->insert( "{$p}wlsm_notices", array( 'title' => 'Sample notice: Term 3 sports day', 'description' => 'Sample notice text.', 'link_to' => 'none', 'is_active' => 1, 'school_id' => 1, 'added_by' => 1, 'created_at' => current_time( 'mysql' ) ) );
	$made['notices'][] = (int) $wpdb->insert_id;
	$k = 0;
	foreach ( BSA_Marks::section_subjects( $sci ) as $s ) {
		if ( ++$k > 7 ) {
			break;
		}
		$wpdb->insert( BSA_Marks::table(), array( 'student_record_id' => $a, 'session_id' => 1, 'term' => 3, 'subject_id' => (int) $s->ID, 'term_mark' => 60 + $k, 'exam_mark' => 48 + 4 * $k, 'remark' => 'Sample remark', 'entered_by' => 1, 'updated_at' => current_time( 'mysql' ) ) );
	}
	$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $a, 'session_id' => 1, 'term' => 3, 'form_teacher_comment' => 'Sample comment.', 'conduct' => 'Good', 'published_at' => current_time( 'mysql' ), 'published_by' => 1 ) );

	$account = BSA_Portal::account_url();
	$fetch   = function ( $user, array $args ) use ( $account ) {
		$exp    = time() + 600;
		$cookie = wp_generate_auth_cookie( $user, $exp, 'logged_in' );
		$r      = wp_remote_get( add_query_arg( $args, $account ), array( 'timeout' => 30, 'redirection' => 0, 'cookies' => array( LOGGED_IN_COOKIE => $cookie ) ) );
		if ( is_wp_error( $r ) ) {
			return 'ERROR ' . $r->get_error_message();
		}
		return '<!-- HTTP ' . wp_remote_retrieve_response_code( $r ) . ' -->' . wp_remote_retrieve_body( $r );
	};
	$learner_actions = array( '', 'fee-invoices', 'fee-structure', 'payment-history', 'study-materials', 'homework', 'noticeboard', 'events', 'class-time-table', 'exams-time-table', 'exam-admit-card', 'exam-results', 'certificates', 'attendance', 'leave-request', 'settings' );
	foreach ( $learner_actions as $act ) {
		$pages[ 'learner-' . ( $act ? $act : 'dashboard' ) ] = $fetch( $la, $act ? array( 'action' => $act ) : array() );
	}
	$pages['parent-dashboard'] = $fetch( $par, array() );
	foreach ( array( 'fee-invoices', 'fee-structure', 'payment-history', 'noticeboard', 'class-time-table', 'exam-results', 'attendance', 'settings' ) as $act ) {
		$pages[ 'parent-' . $act ] = $fetch( $par, array( 'action' => $act, 'student_id' => $a ) );
	}
	foreach ( $extra as $name => $spec ) {
		$pages[ $name ] = $fetch( 'parent' === $spec[0] ? $par : $la, str_replace( array( '{a}', '{b}' ), array( $a, $b ), $spec[1] ) );
	}
	$pages['parent-foreign-child'] = $fetch( $par, array( 'action' => 'fee-invoices', 'student_id' => 1 ) );
	$log['ids'] = compact( 'a', 'b', 'la', 'par' );
} catch ( Throwable $e ) {
	$log['error'] = $e->getMessage() . ' @' . $e->getLine();
} finally {
	$ids = function ( $list ) {
		return implode( ',', array_map( 'intval', $list ? $list : array( 0 ) ) );
	};
	$r = $ids( $made['records'] );
	$wpdb->query( "DELETE FROM {$p}wlsm_payments WHERE ID IN (" . $ids( $made['payments'] ) . ") OR student_record_id IN ($r)" );
	$wpdb->query( "DELETE FROM {$p}wlsm_invoices WHERE ID IN (" . $ids( $made['invoices'] ) . ") OR student_record_id IN ($r)" );
	$wpdb->query( "DELETE FROM {$p}wlsm_attendance WHERE student_record_id IN ($r)" );
	$wpdb->query( 'DELETE FROM ' . BSA_Marks::table() . " WHERE student_record_id IN ($r)" );
	$wpdb->query( 'DELETE FROM ' . BSA_Marks::reports_table() . " WHERE student_record_id IN ($r)" );
	$wpdb->query( "DELETE FROM {$p}wlsm_student_records WHERE ID IN ($r)" );
	$wpdb->query( "DELETE FROM {$p}wlsm_notices WHERE ID IN (" . $ids( $made['notices'] ) . ')' );
	$u = $ids( $made['users'] );
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE user_id IN ($u)" );
	$wpdb->query( "DELETE FROM {$wpdb->users} WHERE ID IN ($u)" );
	wp_cache_flush();
	add_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );
}
$store = array();
foreach ( $pages as $n => $h ) {
	$store[ $n ] = base64_encode( gzcompress( $h, 9 ) );
}
set_transient( 'bsa_capture_family', $store, HOUR_IN_SECONDS );
$log['left'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number LIKE 'TEST-FAM%'" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'bsa\\_test\\_fam%'" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_notices WHERE title LIKE 'Sample notice%'" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_invoices WHERE invoice_number LIKE 'TEST-FAM%'" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_payments WHERE receipt_number LIKE 'TEST-FAM%'" );
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$pg  = function ( $n ) use ( $pages ) {
	return isset( $pages[ $n ] ) ? $pages[ $n ] : '';
};
$nav = function ( $h ) {
	return preg_match( '#<ul class="wlsm-navigation-links">(.*?)</ul>#s', $h, $m ) ? wp_strip_all_tags( $m[1] ) : '';
};
$ok200 = true;
foreach ( $pages as $n => $h ) {
	$ok200 = $ok200 && 0 === strpos( $h, '<!-- HTTP 200' ) && false === stripos( $h, 'critical error' );
}
$check( 'all ' . count( $pages ) . ' pages answer 200 without a critical error', $ok200 );
$d = $pg( 'learner-dashboard' );
$check( 'learner dashboard: menu and details shown (no fee lock-out) despite a part-paid invoice', false !== strpos( $d, 'wlsm-navigation-links' ) && false !== strpos( $d, 'wlsm-student-dashboard' ) && false === strpos( $d, 'wlsm-student-pending-invoices-table' ) );
$check( 'learner menu: Report Cards after Attendance; no Exam Time Table, Admit Card, Exam Results, Certificates', (bool) preg_match( '/Attendance\s+Report Cards/', $nav( $d ) ) && ! preg_match( '/Exam Time Table|Admit Card|Exam Results|Certificates/', $nav( $d ) ), trim( preg_replace( '/\s+/', ' ', $nav( $d ) ) ) );
$check( 'learner greeting: her name and Account settings', false !== strpos( $d, '<span class="wlsm-logged-in-text">Tariro Test</span>' ) && false !== strpos( $d, 'bsa-settings-link' ) );
$fi = $pg( 'learner-fee-invoices' );
$check( 'learner fee invoices: balance 150 after the 100 payment, labelled cells, no Pay Now', false !== strpos( $fi, 'data-label="Due"' ) && false !== strpos( $fi, '150.00' ) && false === strpos( $fi, 'Pay Now' ) && false !== strpos( $fi, 'bsa-stack' ) );
$rc = $pg( 'learner-report-cards' );
$check( 'learner report cards: her Term 3 card, 7 subjects recorded, link to the card', false !== strpos( $rc, 'Term 3, 2026' ) && (bool) preg_match( '/\d of 7/', $rc ) && false !== strpos( $rc, 'bsa_report=' . $a . '&#038;session=1&#038;term=3' ) );
$check( 'learner report cards: old appended list gone', false === strpos( $rc, '<section class="bsa-family-reports">' ) );
$pd = $pg( 'parent-dashboard' );
$check( 'parent dashboard: both children, Report Cards for each, no Exam Results', 2 === substr_count( $pd, 'wlsm-parent-student-section' ) && false !== strpos( $pd, 'action=report-cards&#038;student_id=' . $a ) && false !== strpos( $pd, 'action=report-cards&#038;student_id=' . $b ) && false === strpos( $pd, '>Exam Results<' ) );
$check( 'parent report cards: Tariro\'s card', false !== strpos( $pg( 'parent-report-cards' ), 'bsa_report=' . $a . '&#038;' ) );
$check( 'parent report cards for Kuda: none published yet', false !== strpos( $pg( 'parent-report-cards-sibling' ), 'No report cards have been published yet' ) );
$check( 'parent fee structure (Weblizar had none): Kuda\'s Form 1 term fees', false !== strpos( $pg( 'parent-fee-structure-sibling' ), 'Form 1 A' ) && false !== strpos( $pg( 'parent-fee-structure-sibling' ), 'Total for 2026' ) );
$check( 'parent: another family\'s learner shows nothing of theirs', false === strpos( $pg( 'parent-report-cards-foreign' ), 'bsa-family-page' ) && false === strpos( $pg( 'parent-foreign-child' ), 'wlsm-student-pending-invoices-table' ) );
$help = wp_remote_get( home_url( '/help/' ), array( 'timeout' => 20 ) );
$check( 'Help page 200 with the guide, linked in the menu', 200 === wp_remote_retrieve_response_code( $help ) && false !== strpos( wp_remote_retrieve_body( $help ), 'Fees and paying' ) && false !== strpos( wp_remote_retrieve_body( $help ), '/help/"' ) );
$check( 'cleanup: no synthetic learners, users, invoices, payments or notices left', 0 === $log['left'], (string) $log['left'] );
$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_family_http', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ), isset( $log['error'] ) ? array( 'ERROR ' . $log['error'] ) : array() );
