<?php
/**
 * Integration test for BSA_Family (learner and parent account area), run on the live site
 * through Novamira `execute-php` (send everything after the opening tag).
 *
 * Synthetic learners, a parent, an invoice with a part-payment and report cards are written
 * inside a transaction and rolled back. The account HTML is a cut-down copy of Weblizar's
 * own markup (captured from the live portal on 2026-10-14). End-to-end checks over HTTP are
 * in family-http.php.
 *
 * Before activation, set $bsa_stage to the staged plugin folder to test that copy.
 * Results are also kept for an hour in the `bsa_test_family` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
foreach ( array( 'BSA_School' => 'class-bsa-school.php', 'BSA_Terms' => 'class-bsa-terms.php', 'BSA_Register' => 'class-bsa-register.php', 'BSA_Grades' => 'class-bsa-grades.php', 'BSA_Marks' => 'class-bsa-marks.php', 'BSA_Reports' => 'class-bsa-reports.php', 'BSA_Family' => 'class-bsa-family.php' ) as $class => $file ) {
	if ( ! class_exists( $class ) && $bsa_stage ) {
		require_once $bsa_stage . '/includes/' . $file;
		if ( 'BSA_Family' === $class ) {
			add_filter( 'query', array( 'BSA_Family', 'no_fee_lockout' ) );
		}
	}
}

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
$account = BSA_Portal::account_url();

$wpdb->query( 'START TRANSACTION' );
try {
	$mk_user = function ( $login, $name ) use ( $wpdb ) {
		$wpdb->insert( $wpdb->users, array( 'user_login' => $login, 'user_pass' => 'x', 'user_nicename' => $login, 'user_email' => $login . '@example.invalid', 'user_registered' => current_time( 'mysql', true ), 'display_name' => $name ) );
		return (int) $wpdb->insert_id;
	};
	$learner = $mk_user( 'bsa_test_fam_learner', 'Tariro Test' );
	$parent  = $mk_user( 'bsa_test_fam_parent', 'Test Parent' );
	$other_p = $mk_user( 'bsa_test_fam_other', 'Other Parent' );
	$sci     = (int) $wpdb->get_var( "SELECT se.ID FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id WHERE c.label = 'Form 3' AND se.label = 'A'" );
	$rec     = function ( $name, $section, $user, $par ) use ( $wpdb, $p ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'gender' => 'female', 'enrollment_number' => 'TEST-FAM', 'admission_number' => 'TEST-FAM', 'session_id' => 1, 'section_id' => $section, 'user_id' => $user ? $user : null, 'parent_user_id' => $par, 'is_active' => 1 ) );
		return (int) $wpdb->insert_id;
	};
	$a = $rec( 'Tariro Test', $sci, $learner, $parent );
	$b = $rec( 'Kuda Test', 4, 0, $parent );
	$c = $rec( 'Other Test', $sci, 0, $other_p );
	$check( 'fixtures: parent owns A and B, learner owns A', array( $a, $b ) === BSA_Access_Guard::owned_student_ids( $parent ) && array( $a ) === BSA_Access_Guard::owned_student_ids( $learner ) );

	// Fee lock-out: Weblizar's query counts the payment once per form ("Term 3 Fees" exists for 4 forms).
	$wpdb->insert( "{$p}wlsm_invoices", array( 'invoice_number' => 'TEST-FAM-1', 'label' => 'Term 3 Fees', 'amount' => 250, 'date_issued' => '2026-09-08', 'due_date' => '2026-09-30', 'partial_payment' => 1, 'status' => WLSM_M_Invoice::get_partially_paid_key(), 'student_record_id' => $a ) );
	$inv = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wlsm_payments", array( 'receipt_number' => 'TEST-FAM-R1', 'amount' => 100, 'payment_method' => 'cash', 'invoice_label' => 'Term 3 Fees', 'invoice_payable' => 250, 'invoice_id' => $inv, 'student_record_id' => $a, 'school_id' => 1 ) );
	remove_filter( 'query', array( 'BSA_Family', 'no_fee_lockout' ) );
	$vendor = WLSM_M_Staff_Accountant::get_student_pending_invoices_paid( $a, 1 );
	add_filter( 'query', array( 'BSA_Family', 'no_fee_lockout' ) );
	$check( 'lock-out: without BSA, Weblizar locks the dashboard and shows Paid 400 for a 100 payment', 1 === count( $vendor ) && 400.0 === (float) $vendor[0]->paid, $vendor ? $vendor[0]->paid : 'none' );
	$check( 'lock-out: with BSA, no lock-out invoices', array() === WLSM_M_Staff_Accountant::get_student_pending_invoices_paid( $a, 1 ) );
	$check( 'lock-out: Fee Invoices list still shows the invoice', 1 === count( WLSM_M_Staff_Accountant::get_student_pending_invoices( $a ) ) );
	add_filter( 'bsa_sm_fee_lockout', '__return_true' );
	$check( 'lock-out: filter bsa_sm_fee_lockout restores Weblizar behaviour', 1 === count( WLSM_M_Staff_Accountant::get_student_pending_invoices_paid( $a, 1 ) ) );
	remove_filter( 'bsa_sm_fee_lockout', '__return_true' );
	$other_sql = "SELECT * FROM {$p}wlsm_invoices WHERE 1";
	$check( 'lock-out: other queries untouched', BSA_Family::no_fee_lockout( $other_sql ) === $other_sql );

	// Published report cards: A Term 3 published, A Term 2 not, C (another family) published.
	$subs = BSA_Marks::section_subjects( $sci );
	foreach ( array( $a => array( 80, 70, 65, 60, 55, 52, 40 ), $c => array( 90, 85, 80, 75, 70, 65, 60 ) ) as $sid => $marks ) {
		foreach ( $marks as $i => $mark ) {
			$wpdb->insert( BSA_Marks::table(), array( 'student_record_id' => $sid, 'session_id' => 1, 'term' => 3, 'subject_id' => (int) $subs[ $i ]->ID, 'exam_mark' => $mark, 'entered_by' => 1 ) );
		}
		$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $sid, 'session_id' => 1, 'term' => 3, 'published_at' => current_time( 'mysql' ), 'published_by' => 1 ) );
	}
	$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $a, 'session_id' => 1, 'term' => 2 ) );
	BSA_Reports::flush();
	$pub = BSA_Family::published( array( $a ) );
	$check( 'published: only A Term 3 (Term 2 unpublished)', 1 === count( $pub ) && 3 === (int) $pub[0]->term && $a === (int) $pub[0]->student_record_id );
	$check( 'published: nothing for no learners', array() === BSA_Family::published( array() ) );
	$table = BSA_Family::reports_table( array( $a ), true );
	$check( 'reports table: A passed 6 of 7, Pass, 2nd behind C, link to her card', false !== strpos( $table, '6 of 7' ) && false !== strpos( $table, '>Pass<' ) && false !== strpos( $table, '<td>2</td>' ) && false !== strpos( $table, 'bsa_report=' . $a . '&#038;session=1&#038;term=3' ), wp_strip_all_tags( $table ) );
	$check( 'reports table: empty message for B', false !== strpos( BSA_Family::reports_table( array( $b ), true ), 'No report cards have been published yet' ) );
	$fees = BSA_Family::fee_structure( $a );
	$check( 'fee structure: three terms and the year total for Form 3', 3 === substr_count( $fees, 'Fees</td>' ) && false !== strpos( $fees, '750.00' ) && false !== strpos( $fees, 'Total for 2026' ) );

	// Learner account HTML (cut-down Weblizar markup).
	$learner_html = '<div class="wlsm-logged-in-info"><span class="wlsm-logged-in-text">Bsa_test_fam_learner </span><a class="wlsm-logout-link" href="#">Logout</a></div><div class="wlsm-container wlsm-container-student"><ul class="wlsm-navigation-links"><li><a class="wlsm-navigation-link active" href="' . $account . '">Dashboard</a></li><li><a class="wlsm-navigation-link" href="' . $account . '?action=attendance">Attendance</a></li><li><a class="wlsm-navigation-link" href="' . $account . '?action=leave-request">Leave Request</a></li></ul><div class="wlsm-content-area wlsm-student-dashboard">Dashboard body<img decoding="async" class="wlsm-st-notice-new" src="new.gif"><table class="table"><thead><tr><th><input type="checkbox" name="select_all" id="wlsm-select-all"></th><th>Invoice Number</td><th>Due</th></tr></thead><tbody><tr><td><input type="checkbox" name="invoice_ids[]" class="wlsm-select-single" value="1"></td><!-- <td>x</td> --><td>TEST-1</td><td>$150.00<br><a href="#" class="wlsm-view-student-pending-invoice" data-invoice="1">Pay Now</a></td></tr></tbody></table><a href="#" class="wlsm-view-student-pending-invoice-bulk">Pay Selected</a></div>' . "\n</div>\n";
	wp_set_current_user( $learner );
	$_GET = array();
	$out  = BSA_Family::account_output( $learner_html, 'school_management_account' );
	$check( 'learner: greeting shows her name and an Account settings link', false !== strpos( $out, '<span class="wlsm-logged-in-text">Tariro Test</span>' ) && false !== strpos( $out, 'class="bsa-settings-link"' ) );
	$check( 'learner: Report Cards after Attendance, before Leave Request', preg_match( '#action=attendance">Attendance</a></li>\s*<li><a class="wlsm-navigation-link" href="[^"]*action=report-cards">Report Cards</a></li><li><a[^>]*leave-request#', $out ) );
	$check( 'learner: no Pay Now, Pay Selected or checkboxes while no payment method is enabled', ! BSA_Family::online_payment_enabled() && false === strpos( $out, 'Pay Now' ) && false === strpos( $out, 'Pay Selected' ) && false === strpos( $out, 'type="checkbox"' ) );
	$check( 'learner: cells labelled with their column despite </td> headers and commented cells', false !== strpos( $out, '<td data-label="Invoice Number">TEST-1</td>' ) && false !== strpos( $out, '<td data-label="Due">$150.00' ) && false !== strpos( $out, 'class="bsa-stack table"' ) );
	$check( 'learner: dashboard body kept on the dashboard', false !== strpos( $out, 'Dashboard body' ) );
	$check( 'learner: Weblizar\'s "new" notice badge gets alt text', false !== strpos( $out, '<img alt="New" decoding="async" class="wlsm-st-notice-new"' ) );
	$_GET = array( 'action' => 'report-cards' );
	$out  = BSA_Family::account_output( $learner_html, 'school_management_account' );
	$check( 'learner report page: replaces the content, marks Report Cards active, Dashboard not', false === strpos( $out, 'Dashboard body' ) && false !== strpos( $out, 'bsa-family-reports' ) && false !== strpos( $out, 'active" href="' . esc_url( add_query_arg( 'action', 'report-cards', $account ) ) . '"' ) && false === strpos( $out, 'wlsm-navigation-link active" href="' . $account . '"' ) );
	$check( 'learner report page: her card only (C\'s card not listed)', false !== strpos( $out, 'bsa_report=' . $a . '&#038;' ) && false === strpos( $out, 'bsa_report=' . $c . '&#038;' ) );
	$check( 'learner report page: container still closed', '</div>' === substr( rtrim( $out ), -6 ) && substr_count( $out, '<div' ) === substr_count( $out, '</div>' ), substr_count( $out, '<div' ) . '/' . substr_count( $out, '</div>' ) );

	// Parent account HTML.
	$card        = function ( $sid ) use ( $account ) {
		return '<div class="wlsm-parent-student-section"><div class="wlsm-parent-student-detail">Child ' . $sid . '</div><ul class="wlsm-parent-student-links"><li><a href="' . esc_url( add_query_arg( array( 'action' => 'fee-invoices', 'student_id' => $sid ), $account ) ) . '">Fee Invoices</a></li></ul></div>';
	};
	$parent_html = '<div class="wlsm-logged-in-info"><span class="wlsm-logged-in-text">Bsa_test_fam_parent </span><a class="wlsm-logout-link" href="#">Logout</a></div><div class="wlsm-container wlsm-container-parent"><hr><div class="wlsm-parent-students">' . $card( $a ) . $card( $b ) . "</div>\n</div>\n";
	wp_set_current_user( $parent );
	$_GET = array();
	$out  = BSA_Family::account_output( $parent_html, 'school_management_account' );
	$check( 'parent: Report Cards link in each child\'s card', false !== strpos( $out, esc_url( add_query_arg( array( 'action' => 'report-cards', 'student_id' => $a ), $account ) ) ) && false !== strpos( $out, esc_url( add_query_arg( array( 'action' => 'report-cards', 'student_id' => $b ), $account ) ) ) );
	$_GET = array( 'action' => 'report-cards', 'student_id' => (string) $a );
	$out  = BSA_Family::account_output( $parent_html, 'school_management_account' );
	$check( 'parent report page: back link, child name, her card', false !== strpos( $out, '&#8592;&nbsp;Back' ) && false !== strpos( $out, 'Tariro Test' ) && false !== strpos( $out, 'bsa_report=' . $a . '&#038;' ) && false === strpos( $out, 'wlsm-parent-students' ) );
	$_GET = array( 'action' => 'fee-structure', 'student_id' => (string) $b );
	$out  = BSA_Family::account_output( $parent_html, 'school_management_account' );
	$check( 'parent fee structure page: Kuda\'s Form 1 fees', false !== strpos( $out, 'Kuda Test' ) && false !== strpos( $out, 'Form 1 A' ) && false !== strpos( $out, '750.00' ) );
	$_GET = array( 'action' => 'report-cards', 'student_id' => (string) $c );
	$out  = BSA_Family::account_output( $parent_html, 'school_management_account' );
	$check( 'parent: another family\'s child gets no BSA page and no card link', false === strpos( $out, 'bsa-family-page' ) && false === strpos( $out, 'bsa_report=' . $c ) && false === strpos( $out, 'Other Test' ) );
	wp_set_current_user( $other_p );
	$_GET = array( 'action' => 'report-cards', 'student_id' => (string) $a );
	$out  = BSA_Family::account_output( $parent_html, 'school_management_account' );
	$check( 'parent: the other parent cannot open A\'s report page', false === strpos( $out, 'bsa_report=' . $a ) && false === strpos( $out, 'bsa-family-page' ) );
	wp_set_current_user( $original );
	$_GET = array();
	$check( 'staff: output unchanged for a user without learners', $parent_html === BSA_Family::account_output( $parent_html, 'school_management_account' ) );
	$check( 'other shortcodes untouched', $parent_html === BSA_Family::account_output( $parent_html, 'gallery' ) );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	$_GET = array();
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_Reports::flush();
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number = 'TEST-FAM'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_invoices WHERE invoice_number LIKE 'TEST-FAM%'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_payments WHERE receipt_number LIKE 'TEST-FAM%'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'bsa\\_test\\_%'" );
$check( 'rollback: no synthetic learners, invoices, payments or users left', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_family', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
