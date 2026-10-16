<?php
/**
 * Integration test for BSA_Dashboard (School Dashboard), run on the live site through
 * Novamira `execute-php` (send everything after the opening tag).
 *
 * Synthetic learners, invoices, payments, register entries, marks and a published report
 * card are written inside a transaction and rolled back. Real data may already exist, so
 * figures are checked as the change the synthetic data makes. Before activation, set
 * $bsa_stage to the staged plugin folder to test that copy. Results are also kept for an
 * hour in the `bsa_test_dashboard` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
if ( ! class_exists( 'BSA_Dashboard' ) && $bsa_stage ) {
	require_once $bsa_stage . '/includes/class-bsa-dashboard.php';
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$p       = $wpdb->prefix;
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
// A link to a screen, as admin.php?page=<slug> or (clean addresses on) /staff/<screen>.
$links_to = function ( $html, $page ) {
	return false !== strpos( $html, 'page=' . $page ) || ( 0 === strpos( $page, 'bsa-' ) && false !== strpos( $html, '/staff/' . substr( $page, 4 ) ) );
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
$check( 'money: USD with two decimals', 'USD 1,234.50' === BSA_Dashboard::money( 1234.5 ) && 'USD 0.00' === BSA_Dashboard::money( 0 ) );
$m = BSA_Dashboard::meter( 3, 4, 'Three of <b>four</b>' );
$check( 'meter: share as width, text written out and escaped, labelled for screen readers', false !== strpos( $m, 'width:75.0%' ) && false !== strpos( $m, 'role="img"' ) && false !== strpos( $m, 'aria-label="Three of &lt;b&gt;four&lt;/b&gt;"' ) && false !== strpos( $m, '>Three of &lt;b&gt;four&lt;/b&gt;</span>' ) );
$check( 'meter: over 100% capped, nothing of nothing is 0%', false !== strpos( BSA_Dashboard::meter( 5, 4, 'x' ), 'width:100.0%' ) && false !== strpos( BSA_Dashboard::meter( 0, 0, 'x' ), 'width:0.0%' ) );

BSA_Marks::maybe_migrate();
BSA_Register::maybe_migrate();
$terms_before    = get_option( BSA_Terms::OPTION, false );
$assigned_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_admin_subject" );
// The payment audit would log the synthetic invoices and payments; it is not under test.
remove_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );

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
	$parent  = $user( 'bsa001.parent' );
	$learner = $user( 'bsa001' );
	BSA_School::flush();
	BSA_Reports::flush();

	$x       = 6; // Form 3 A.
	$y       = 7; // Form 4 A.
	$titles  = wp_list_pluck( BSA_School::sections(), 'title', 'ID' );
	$subs    = BSA_Marks::section_subjects( $x );
	$n       = count( $subs );
	$check( 'fixture: Form 3 A and Form 4 A with their subjects', 'Form 3 A' === ( $titles[ $x ] ?? '' ) && 'Form 4 A' === ( $titles[ $y ] ?? '' ) && $n > 2, $n . ' subjects' );
	$session = (int) get_option( 'wlsm_current_session' );
	$day     = '2026-11-02'; // A Monday with no real register yet.
	$term    = 3;

	// Before the synthetic data.
	$fees0  = BSA_Dashboard::fees();
	$row    = function ( $fees, $label ) {
		foreach ( $fees['rows'] as $r ) {
			if ( $label === $r['label'] ) {
				return $r;
			}
		}
		return array( 'learners' => 0, 'invoiced' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'owing' => 0 );
	};
	$x_fee0   = $row( $fees0, 'Form 3 A' );
	$att0     = BSA_Dashboard::attendance( $day, $session );
	$marks0   = BSA_Dashboard::marks( $session, $term );
	$marks2_0 = BSA_Dashboard::marks( $session, 2 );
	$week0    = BSA_Dashboard::received( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -6 days' ) ), current_time( 'Y-m-d' ) );
	$unasg0   = BSA_Dashboard::unassigned_subjects();
	$zero_att = array( 'learners' => 0, 'marked' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'holiday' => 0 );
	$zero_mk  = array( 'learners' => 0, 'expected' => 0, 'entered' => 0, 'published' => 0 );

	// Three learners in Form 3 A, one in Form 4 A.
	$mk = function ( $name, $section ) use ( $wpdb, $p, $session ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'gender' => 'female', 'enrollment_number' => 'TEST-DASH', 'admission_number' => 'TEST-DASH', 'session_id' => $session, 'section_id' => $section, 'is_active' => 1 ) );
		return (int) $wpdb->insert_id;
	};
	$l1 = $mk( 'Dash Test One', $x );
	$l2 = $mk( 'Dash Test Two', $x );
	$l3 = $mk( 'Dash Test Three', $x );
	$l4 = $mk( 'Dash Test Four', $y );
	$gone = $mk( 'Dash Test Left', $x );
	$wpdb->update( "{$p}wlsm_student_records", array( 'is_active' => 0 ), array( 'ID' => $gone ) );

	// Fees: 300 each (L2 has 50 off); L1 pays in full, L2 pays 100, L3 nothing.
	$inv = function ( $student, $amount, $discount ) use ( $wpdb, $p, $owner ) {
		$wpdb->insert( "{$p}wlsm_invoices", array( 'invoice_number' => 'TEST-DASH-' . $student, 'label' => 'Term 3 fees (test)', 'amount' => $amount, 'discount' => $discount, 'date_issued' => current_time( 'Y-m-d' ), 'due_date' => current_time( 'Y-m-d' ), 'status' => 'unpaid', 'student_record_id' => $student, 'added_by' => $owner ) );
		return (int) $wpdb->insert_id;
	};
	$pay = function ( $invoice, $student, $amount, $when ) use ( $wpdb, $p, $bursar ) {
		$wpdb->insert( "{$p}wlsm_payments", array( 'receipt_number' => 'TEST-DASH', 'amount' => $amount, 'payment_method' => 'cash', 'transaction_id' => 'FT-TEST-DASH', 'invoice_id' => $invoice, 'student_record_id' => $student, 'school_id' => 1, 'created_at' => $when, 'added_by' => $bursar ) );
	};
	$today = current_time( 'Y-m-d' );
	$pay( $inv( $l1, 300, 0 ), $l1, 300, $today . ' 09:00:00' );
	$pay( $inv( $l2, 300, 50 ), $l2, 100, gmdate( 'Y-m-d', strtotime( $today . ' -10 days' ) ) . ' 09:00:00' );
	$inv( $l3, 300, 0 );
	$inv( $gone, 300, 0 ); // A learner who has left is not counted.

	$fees1  = BSA_Dashboard::fees();
	$x_fee1 = $row( $fees1, 'Form 3 A' );
	$delta  = array();
	foreach ( array( 'learners', 'invoiced', 'paid', 'balance', 'owing' ) as $k ) {
		$delta[ $k ] = round( $x_fee1[ $k ] - $x_fee0[ $k ], 2 );
	}
	$check( 'fees: Form 3 A gains 3 learners, 850 invoiced, 400 paid, 450 outstanding, 2 owing', array( 'learners' => 3, 'invoiced' => 850.0, 'paid' => 400.0, 'balance' => 450.0, 'owing' => 2 ) == $delta, wp_json_encode( $delta ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$check( 'fees: school totals move by the same amounts', 850.0 === round( $fees1['invoiced'] - $fees0['invoiced'], 2 ) && 400.0 === round( $fees1['paid'] - $fees0['paid'], 2 ) && 450.0 === round( $fees1['balance'] - $fees0['balance'], 2 ) && 2 === $fees1['learners_owing'] - $fees0['learners_owing'] );
	$week1 = BSA_Dashboard::received( gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ), $today );
	$check( 'fees: last 7 days counts today\'s 300, not the payment 10 days ago', 1 === $week1['count'] - $week0['count'] && 300.0 === round( $week1['amount'] - $week0['amount'], 2 ) );

	// Register on the Monday: L1 present, L2 late, L3 absent; a subject register for L3
	// and L4 is not the daily register; L4's class is not marked.
	$mark = function ( $student, $status, $date, $subject = null ) use ( $wpdb, $p, $owner ) {
		$row = array( 'attendance_date' => $date, 'status' => $status, 'student_record_id' => $student, 'added_by' => $owner, 'created_at' => current_time( 'mysql' ) );
		if ( $subject ) {
			$row['subject_id'] = $subject;
		}
		$wpdb->insert( "{$p}wlsm_attendance", $row );
	};
	$mark( $l1, 'p', $day );
	$mark( $l2, 'l', $day );
	$mark( $l3, 'a', $day );
	$mark( $l3, 'p', $day, (int) $subs[0]->ID );
	$mark( $l4, 'p', $day, (int) $subs[0]->ID );
	$mark( $l1, 'p', '2026-11-04', (int) $subs[0]->ID );
	$att1 = BSA_Dashboard::attendance( $day, $session );
	$xa   = array();
	foreach ( $zero_att as $k => $v ) {
		$xa[ $k ] = $att1[ $x ][ $k ] - ( $att0[ $x ][ $k ] ?? 0 );
	}
	$check( 'register: Form 3 A 3 marked (1 present, 1 late, 1 absent); subject registers ignored', array( 'learners' => 3, 'marked' => 3, 'present' => 1, 'late' => 1, 'absent' => 1, 'holiday' => 0 ) === $xa, wp_json_encode( $xa ) );
	$check( 'fixture: no real register on the Monday', 0 === ( $att0[ $x ]['marked'] ?? 0 ) + ( $att0[ $y ]['marked'] ?? 0 ) );
	$check( 'register: present and late count as attended (2 of 3)', abs( (float) $att1[ $x ]['rate'] - 2 / 3 ) < 0.0001, (string) $att1[ $x ]['rate'] );
	$check( 'register: Form 4 A not marked, no rate', 1 === $att1[ $y ]['learners'] - ( $att0[ $y ]['learners'] ?? 0 ) && 0 === $att1[ $y ]['marked'] && null === $att1[ $y ]['rate'] );
	$check( 'register: last day taken is the Monday (subject registers do not count)', $day === BSA_Dashboard::last_register_day( '2026-11-05' ) && $day !== BSA_Dashboard::last_register_day( '2026-11-01' ), BSA_Dashboard::last_register_day( '2026-11-05' ) );

	// Marks for Term 3: L1 takes 2 subjects (both exam marks in); L2 and L3 take all;
	// L2 has one exam mark and one term mark only. L1's report card is published.
	$wpdb->insert( "{$p}wlsm_students_subjects", array( 'student_id' => $l1, 'subject_id' => (int) $subs[0]->ID ) );
	$wpdb->insert( "{$p}wlsm_students_subjects", array( 'student_id' => $l1, 'subject_id' => (int) $subs[1]->ID ) );
	$put = function ( $student, $subject, $exam, $term_mark = null ) use ( $wpdb, $session, $term, $owner ) {
		$wpdb->insert( BSA_Marks::table(), array( 'student_record_id' => $student, 'session_id' => $session, 'term' => $term, 'subject_id' => $subject, 'term_mark' => $term_mark, 'exam_mark' => $exam, 'project_mark' => null, 'remark' => '', 'entered_by' => $owner, 'updated_at' => current_time( 'mysql' ) ) );
	};
	$put( $l1, (int) $subs[0]->ID, 70 );
	$put( $l1, (int) $subs[1]->ID, 0 ); // A mark of 0 is still entered.
	$put( $l2, (int) $subs[0]->ID, 55 );
	$put( $l2, (int) $subs[1]->ID, null, 60 );
	$put( $l1, (int) $subs[2]->ID, 80 ); // Not one of L1's subjects: not counted.
	$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $l1, 'session_id' => $session, 'term' => $term, 'form_teacher_comment' => '', 'conduct' => '', 'principal_comment' => '', 'published_at' => current_time( 'mysql' ), 'published_by' => $head, 'updated_at' => current_time( 'mysql' ) ) );
	$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $l2, 'session_id' => $session, 'term' => $term, 'form_teacher_comment' => 'Draft', 'conduct' => '', 'principal_comment' => '', 'updated_at' => current_time( 'mysql' ) ) );
	$marks1 = BSA_Dashboard::marks( $session, $term );
	$xm     = array();
	foreach ( $zero_mk as $k => $v ) {
		$xm[ $k ] = $marks1[ $x ][ $k ] - ( $marks0[ $x ][ $k ] ?? 0 );
	}
	$check( 'marks: Form 3 A expects 2 + 2 × ' . $n . ' exam marks, 3 entered, 1 report card published', array( 'learners' => 3, 'expected' => 2 + 2 * $n, 'entered' => 3, 'published' => 1 ) === $xm, wp_json_encode( $xm ) );
	$check( 'marks: Term 3 marks not counted in Term 2', ( $marks2_0[ $x ]['entered'] ?? 0 ) === BSA_Dashboard::marks( $session, 2 )[ $x ]['entered'] );

	// Subject teachers.
	$form3 = $unasg0['Form 3'] ?? array();
	$admin = (int) $wpdb->get_var( $wpdb->prepare( "SELECT a.ID FROM {$p}wlsm_admins a JOIN {$p}wlsm_staff s ON s.ID = a.staff_id WHERE s.user_id = %d", $teacher ) );
	$wpdb->insert( "{$p}wlsm_admin_subject", array( 'admin_id' => $admin, 'subject_id' => (int) $subs[0]->ID, 'created_at' => current_time( 'mysql' ) ) );
	$unasg1 = BSA_Dashboard::unassigned_subjects();
	$check( 'subjects: assigning ' . $subs[0]->label . ' removes it from Form 3\'s unassigned list', in_array( $subs[0]->label, $form3, true ) ? ( count( $unasg1['Form 3'] ?? array() ) === count( $form3 ) - 1 && ! in_array( $subs[0]->label, $unasg1['Form 3'] ?? array(), true ) ) : ! in_array( $subs[0]->label, $unasg1['Form 3'] ?? array(), true ), wp_json_encode( $unasg1['Form 3'] ?? array() ) );
	$check( 'subjects: each form listed once', count( $unasg1 ) === count( array_unique( array_keys( $unasg1 ) ) ) && ! isset( $unasg1['Form 3 A'] ) );

	// Needs attention, from hand-made states.
	BSA_Terms::save( $session, array( 1 => array( 'start' => '2026-01-13', 'end' => '2026-04-02' ), 2 => array( 'start' => '2026-05-05', 'end' => '2026-08-06' ), 3 => array( 'start' => '2026-09-08', 'end' => '2026-12-01' ) ) );
	$clean = array(
		'term'          => array( 'session' => $session, 'term' => 1, 'start' => '2026-01-13', 'end' => '2099-04-02' ),
		'is_school_day' => true,
		'attendance'    => array( $x => array( 'learners' => 3, 'marked' => 3 ), $y => array( 'learners' => 0, 'marked' => 0 ) ),
		'sections'      => $titles,
		'marks'         => array( $x => array( 'learners' => 3, 'published' => 0 ) ),
		'unassigned'    => array(),
		'accounts'      => array( 'errors' => 0, 'warnings' => 0 ),
	);
	$texts = function ( $state ) {
		return wp_list_pluck( BSA_Dashboard::attention( $state ), 'text' );
	};
	$check( 'attention: nothing when terms are set, registers marked and accounts clean', array() === BSA_Dashboard::attention( $clean ), wp_json_encode( $texts( $clean ) ) );
	$a = BSA_Dashboard::attention( array_merge( $clean, array( 'streams_unset' => array( 'Form 3 A' => 2 ) ) ) );
	$check( 'attention: learners without a stream, with a Streams link', 1 === count( $a ) && false !== strpos( $a[0]['text'], 'Form 3 A (2)' ) && $links_to( $a[0]['link'], 'bsa-streams' ), wp_json_encode( $a ) );
	$s = array_merge( $clean, array( 'term' => null ) );
	$a = BSA_Dashboard::attention( $s );
	$check( 'attention: no term covering today is an action with a School Terms link', 1 === count( $a ) && 'warning' === $a[0]['level'] && $links_to( $a[0]['link'], BSA_Terms::PAGE ) );
	$s = array_merge( $clean, array( 'term' => array( 'session' => 999, 'term' => 3, 'start' => '2026-09-08', 'end' => '2099-12-01' ) ) );
	$a = BSA_Dashboard::attention( $s );
	$check( 'attention: next term start missing is an action', 1 === count( $a ) && false !== strpos( $a[0]['text'], 'after Term 3 is not set' ) );
	$s = $clean;
	$s['attendance'][ $y ] = array( 'learners' => 1, 'marked' => 0 );
	$a = BSA_Dashboard::attention( $s );
	$check( 'attention: unmarked register named by class, with a Class Register link', 1 === count( $a ) && 'Register not marked today: Form 4 A.' === $a[0]['text'] && $links_to( $a[0]['link'], BSA_Register::PAGE ) );
	$s['is_school_day'] = false;
	$check( 'attention: no register reminder on a weekend or holiday', array() === BSA_Dashboard::attention( $s ) );
	$s = array_merge( $clean, array( 'term' => array( 'session' => $session, 'term' => 1, 'start' => '2026-01-13', 'end' => '2026-04-02' ) ) );
	$a = BSA_Dashboard::attention( $s );
	$check( 'attention: after the term ends, unpublished report cards are a note', 1 === count( $a ) && 'info' === $a[0]['level'] && '3 report cards for Term 1 are not published yet.' === $a[0]['text'], wp_json_encode( $texts( $s ) ) );
	$s['marks'][ $x ]['published'] = 3;
	$check( 'attention: no note once all are published', array() === BSA_Dashboard::attention( $s ) );
	$s = array_merge( $clean, array( 'unassigned' => array( 'Form 1' => array( 'History', 'Physics' ), 'Form 3' => array( 'Biology' ) ) ) );
	$a = BSA_Dashboard::attention( $s );
	$check( 'attention: subjects without a teacher counted per form, with a Subjects link', 1 === count( $a ) && 0 === strpos( $a[0]['text'], '3 subjects have no subject teacher assigned: Form 1 (2), Form 3 (1).' ) && false !== strpos( $a[0]['link'], 'page=sm-staff-subjects' ) );
	$s = array_merge( $clean, array( 'accounts' => array( 'errors' => 1, 'warnings' => 2 ) ) );
	$a = BSA_Dashboard::attention( $s );
	$check( 'attention: family account errors are an action', 1 === count( $a ) && 'warning' === $a[0]['level'] && 'Family accounts: 1 to fix, 2 to check.' === $a[0]['text'] && $links_to( $a[0]['link'], BSA_Accounts::PAGE ) );
	$s['accounts']['errors'] = 0;
	$a = BSA_Dashboard::attention( $s );
	$check( 'attention: family account warnings alone are a note', 1 === count( $a ) && 'info' === $a[0]['level'] );

	// The real state for today, and the screen.
	$state = BSA_Dashboard::state();
	$check( 'state: today\'s figures assembled', $today === $state['today'] && $session === $state['session'] && isset( $state['fees']['rows'], $state['attendance'][ $x ], $state['accounts']['families'] ) && $state['term'] && 3 === $state['term']['term'] );

	$check( 'rights: owner and school administrator only', BSA_Dashboard::user_can_view( $owner ) && BSA_Dashboard::user_can_view( $head ) && ! BSA_Dashboard::user_can_view( $teacher ) && ! BSA_Dashboard::user_can_view( $bursar ) && ! BSA_Dashboard::user_can_view( $parent ) && ! BSA_Dashboard::user_can_view( $learner ) && ! BSA_Dashboard::user_can_view( 0 ) );
	global $admin_page_hooks;
	$hooks_before = $admin_page_hooks;
	wp_set_current_user( $bursar );
	BSA_Dashboard::register_menu();
	$bursar_menu = isset( $admin_page_hooks[ BSA_Dashboard::PAGE ] );
	wp_set_current_user( $head );
	BSA_Dashboard::register_menu();
	$check( 'menu: shown to the school administrator, not the bursar', isset( $admin_page_hooks[ BSA_Dashboard::PAGE ] ) && ! $bursar_menu );
	$admin_page_hooks = $hooks_before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	$start = microtime( true );
	ob_start();
	BSA_Dashboard::render_page();
	$html = ob_get_clean();
	$secs = microtime( true ) - $start;
	$check( 'screen: title, needs attention, six tiles and three cards', false !== strpos( $html, '<h1>School Dashboard</h1>' ) && false !== strpos( $html, 'Needs attention' ) && 6 === substr_count( $html, 'class="bsa-tile"' ) && false !== strpos( $html, 'Fees by class this year' ) && false !== strpos( $html, 'Marks entry, Term 3' ) );
	$fee_text  = BSA_Dashboard::money( $x_fee1['paid'] ) . ' of ' . number_format_i18n( $x_fee1['invoiced'], 2 );
	$mark_text = $marks1[ $x ]['entered'] . ' of ' . $marks1[ $x ]['expected'] . ' (';
	$check( 'screen: synthetic class shown with its fees and marks written out', false !== strpos( $html, '<th scope="row">Form 3 A</th>' ) && false !== strpos( $html, '>' . $fee_text . ' (' ) && false !== strpos( $html, '>' . $mark_text ), $fee_text . ' / ' . $mark_text );
	$check( 'screen: links to Fee Reports, Class Register and Report Cards', $links_to( $html, BSA_Finance::PAGE ) && $links_to( $html, BSA_Register::PAGE ) && $links_to( $html, BSA_Reports::PAGE ) );
	$check( 'screen: status never colour alone (icons carry a word)', false === strpos( $html, 'Not marked' ) || false !== strpos( $html, 'dashicons-warning" aria-hidden="true"></span> Not marked' ) );
	$check( 'screen: renders in under 3 seconds', $secs < 3, round( $secs, 2 ) . ' s' );
	wp_set_current_user( $bursar );
	$denied = false;
	try {
		ob_start();
		BSA_Dashboard::render_page();
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
	add_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	BSA_Reports::flush();
	foreach ( $die_hooks as $hook ) {
		remove_filter( $hook, $die, 999 );
	}
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number = 'TEST-DASH'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_payments WHERE transaction_id = 'FT-TEST-DASH'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_invoices WHERE invoice_number LIKE 'TEST-DASH-%'" )
	+ ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_admin_subject" ) === $assigned_before ? 0 : 1 )
	+ ( get_option( BSA_Terms::OPTION, false ) === $terms_before ? 0 : 1 );
$check( 'rollback: no synthetic data left; terms and subject teachers as before', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_dashboard', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
