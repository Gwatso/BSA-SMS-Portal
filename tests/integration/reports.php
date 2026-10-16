<?php
/**
 * Integration test for BSA_Grades, BSA_Marks and BSA_Reports (marks and report cards),
 * run on the live site through Novamira `execute-php` (send everything after the opening
 * tag).
 *
 * Four synthetic Form 3 A learners (and one in Form 4 A), a subject assignment for the teacher, term
 * dates, marks, comments and publication are written inside a transaction and rolled
 * back. Expected results are worked out by hand in the comments.
 *
 * Before activation, set $bsa_stage to the staged plugin folder to test that copy.
 * Results are also kept for an hour in the `bsa_test_reports` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
foreach ( array( 'BSA_School' => 'class-bsa-school.php', 'BSA_Terms' => 'class-bsa-terms.php', 'BSA_Register' => 'class-bsa-register.php', 'BSA_Grades' => 'class-bsa-grades.php', 'BSA_Marks' => 'class-bsa-marks.php', 'BSA_Reports' => 'class-bsa-reports.php' ) as $class => $file ) {
	if ( ! class_exists( $class ) && $bsa_stage ) {
		require_once $bsa_stage . '/includes/' . $file;
	}
}
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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

// Grades (no database).
$g = function ( $mark, $form ) {
	return BSA_Grades::grade( $mark, $form );
};
$check( 'ZJC: 75→1, 74→2, 74.5→1, 60→4, 50→6, 40→8, 39→9, 0→9, 100→1', array( '1', '2', '1', '4', '6', '8', '9', '9', '1' ) === array( $g( 75, 'Form 1' ), $g( 74, 'Form 1' ), $g( 74.5, 'Form 2' ), $g( 60, 'Form 1' ), $g( 50, 'Form 1' ), $g( 40, 'Form 2' ), $g( 39, 'Form 1' ), $g( 0, 'Form 1' ), $g( 100, 'Form 2' ) ) );
$check( 'O Level: 70 A, 69.5 A, 69.4 B, 60 B, 50 C, 49 D, 44 E, 39 U', array( 'A', 'A', 'B', 'B', 'C', 'D', 'E', 'U' ) === array( $g( 70, 'Form 3' ), $g( 69.5, 'Form 4' ), $g( 69.4, 'Form 3' ), $g( 60, 'Form 3' ), $g( 50, 'Form 4' ), $g( 49, 'Form 3' ), $g( 44, 'Form 3' ), $g( 39, 'Form 4' ) ) );
$check( 'no mark → no grade', '' === $g( null, 'Form 3' ) && '' === $g( '', 'Form 1' ) );
$check( 'pass: 50 and 49.5 pass, 49.4 and none fail', BSA_Grades::is_pass( 50 ) && BSA_Grades::is_pass( 49.5 ) && ! BSA_Grades::is_pass( 49.4 ) && ! BSA_Grades::is_pass( null ) );
$check( 'result: 5 passes Pass, 4 Fail', 'Pass' === BSA_Grades::result( 5 ) && 'Fail' === BSA_Grades::result( 4 ) );
$ok = array();
foreach ( array( '' => true, '65' => true, '65.5' => true, '100' => true, '0' => true, '100.5' => false, '-1' => false, '65.55' => false, 'abc' => false, '101' => false, '6 5' => false ) as $in => $valid ) {
	$ok[] = BSA_Grades::parse_mark( (string) $in )[1] === $valid;
}
$check( 'mark input: 0–100 with one decimal accepted, others refused', ! in_array( false, $ok, true ) );
$check( 'positions: 90, 80, 80, 70 → 1, 2, 2, 4', array( 'a' => 1, 'b' => 2, 'c' => 2, 'd' => 4 ) === BSA_Grades::positions( array( 'b' => 80, 'a' => 90, 'd' => 70, 'c' => 80 ) ) );
$check( 'initials: Willard Hlabati → WH', 'WH' === BSA_Grades::initials( 'Willard Hlabati' ) );
$check( 'display: 65.0 → 65, 60.5 → 60.5, 0 → 0', '65' === BSA_Marks::show( '65.0' ) && '60.5' === BSA_Marks::show( '60.5' ) && '0' === BSA_Marks::show( '0.0' ) && '' === BSA_Marks::show( null ) );

// DDL commits: create the plugin's real tables before the transaction.
BSA_Marks::maybe_migrate();
BSA_Register::maybe_migrate();

// Real data the test must leave as it found it (the owner may already have entered terms
// or subject assignments).
$terms_before    = get_option( BSA_Terms::OPTION, false );
$assigned_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_admin_subject" );

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
	BSA_School::flush();
	BSA_Reports::flush();

	$sci  = (int) $wpdb->get_var( "SELECT se.ID FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id WHERE c.label = 'Form 3' AND se.label = 'A'" );
	$com  = (int) $wpdb->get_var( "SELECT se.ID FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id WHERE c.label = 'Form 4' AND se.label = 'A'" );
	$subs = array();
	foreach ( BSA_Marks::section_subjects( $sci ) as $row ) {
		$subs[ $row->label ] = (int) $row->ID;
	}
	$check( 'fixture: Form 3 A and Form 4 A, 13 subjects, English first', $sci && $com && 13 === count( $subs ) && 'English Language' === array_key_first( $subs ), implode( ', ', array_keys( $subs ) ) );

	// Rights before any assignment.
	$check( 'rights: owner and school administrator enter every subject', 13 === count( BSA_Marks::allowed_subjects( $owner, $sci ) ) && 13 === count( BSA_Marks::allowed_subjects( $head, $sci ) ) );
	$check( 'rights: class teacher enters every Form 1 A subject', count( BSA_Marks::allowed_subjects( $teacher, 4 ) ) === count( BSA_Marks::section_subjects( 4 ) ) && count( BSA_Marks::section_subjects( 4 ) ) > 0 );
	$check( 'rights: teacher has no Form 3 subjects yet', array() === BSA_Marks::allowed_subjects( $teacher, $sci ) );
	$check( 'rights: bursar enters no marks', array() === BSA_Marks::allowed_sections( $bursar ) );
	// Assign Physics in Form 3 to the teacher (Weblizar staff → subjects).
	$admin_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT a.ID FROM {$p}wlsm_admins a JOIN {$p}wlsm_staff s ON s.ID = a.staff_id WHERE s.user_id = %d", $teacher ) );
	$wpdb->insert( "{$p}wlsm_admin_subject", array( 'admin_id' => $admin_row, 'subject_id' => $subs['Physics'], 'created_at' => current_time( 'mysql' ) ) );
	$check( 'rights: subject teacher gets Form 3 Physics only (not Form 4)', array( $subs['Physics'] ) === BSA_Marks::allowed_subjects( $teacher, $sci ) && array() === BSA_Marks::allowed_subjects( $teacher, $com ) );
	$check( 'rights: report cards for owner (all) and class teacher (Form 1 A) only', count( BSA_Reports::allowed_sections( $owner ) ) === count( BSA_School::sections() ) && array( 4 ) === BSA_Reports::allowed_sections( $teacher ) && array() === BSA_Reports::allowed_sections( $bursar ) );

	// Term dates and four learners in Form 3 A (and one in Form 4 A).
	BSA_Terms::save( 1, array( 1 => array( 'start' => '2026-01-13', 'end' => '2026-04-02' ), 2 => array( 'start' => '2026-05-05', 'end' => '2026-08-06' ) ) );
	$mk = function ( $name, $section ) use ( $wpdb, $p ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'gender' => 'female', 'enrollment_number' => 'TEST-RPT', 'admission_number' => 'TEST-RPT', 'session_id' => 1, 'section_id' => $section, 'is_active' => 1 ) );
		return (int) $wpdb->insert_id;
	};
	$a = $mk( 'Anesu Test Alpha', $sci );
	$b = $mk( 'Batsirai Test Bravo', $sci );
	$c = $mk( 'Chipo Test Charlie', $sci );
	$d = $mk( 'Dudzai Test Delta', $sci );
	$e = $mk( 'Eve Test Echo', $com );
	// A takes only 6 subjects (Weblizar per-learner subjects); others take all 13.
	$six = array( 'English Language', 'Mathematics', 'Physics', 'Chemistry', 'Biology', 'Geography' );
	foreach ( $six as $label ) {
		$wpdb->insert( "{$p}wlsm_students_subjects", array( 'student_id' => $a, 'subject_id' => $subs[ $label ] ) );
	}
	$check( 'subjects: A takes 6 (her own), B takes all 13', 6 === count( BSA_Reports::learner_subjects( $a, $sci ) ) && 13 === count( BSA_Reports::learner_subjects( $b, $sci ) ) );

	// Exam marks over the six subjects (term marks and projects on English only).
	// A: 80,75,70,65,60,55 → 6 passes, average 67.5
	// B: 60,55,52,50,49.5,40 → 5 passes (49.5 rounds to 50), average 51.08…
	// C: 60,55,52,49,40,47.5 → 3 passes, average 50.58…
	// D: 49.5,40,52,50,60,55 → 5 passes, same marks as B → average 51.08… (ties with B)
	$exam = array(
		$a => array( 80, 75, 70, 65, 60, 55 ),
		$b => array( 60, 55, 52, 50, 49.5, 40 ),
		$c => array( 60, 55, 52, 49, 40, 47.5 ),
		$d => array( 49.5, 40, 52, 50, 60, 55 ),
	);
	foreach ( $six as $i => $label ) {
		$rows = array();
		foreach ( $exam as $sid => $marks ) {
			$rows[ $sid ] = array( 'term' => 'English Language' === $label ? '68' : '', 'exam' => (string) $marks[ $i ], 'project' => 'English Language' === $label ? '72.5' : '', 'remark' => 'English Language' === $label ? 'Good effort' : '' );
		}
		$saver = 'Physics' === $label ? $teacher : $head;
		$r     = BSA_Marks::save( $saver, $sci, 1, 1, $subs[ $label ], $rows );
		$check( "marks: $label saved for 4 learners", 4 === $r['saved'] && ! $r['errors'], wp_json_encode( $r['errors'] ) );
	}
	$r = BSA_Marks::save( $head, $sci, 1, 1, $subs['History'], array( $b => array( 'exam' => '101' ), $c => array( 'exam' => '55' ) ) );
	$check( 'marks: a bad mark refuses the whole save', 0 === $r['saved'] && 1 === count( $r['errors'] ) && ! isset( BSA_Marks::for_learners( array( $c ), 1, 1 )[ $c ][ $subs['History'] ] ) );
	$r = BSA_Marks::save( $teacher, $sci, 1, 1, $subs['Chemistry'], array( $b => array( 'exam' => '70' ) ) );
	$check( 'marks: subject teacher cannot enter Chemistry', 0 === $r['saved'] && $r['errors'] );
	$r = BSA_Marks::save( $head, $sci, 1, 1, $subs['History'], array( $e => array( 'exam' => '70' ) ) );
	$check( 'marks: a learner from another class is refused', 0 === $r['saved'] && $r['errors'] );
	$r = BSA_Marks::save( $head, $sci, 1, 1, $subs['Mathematics'], array( $a => array( 'exam' => '75' ) ) );
	$check( 'marks: re-saving unchanged marks changes nothing', 0 === $r['saved'] && ! $r['errors'] );

	$res = BSA_Reports::class_results( $sci, 1, 1 );
	$check( 'results: A 6 recorded, 6 passed, Pass', 6 === $res[ $a ]['recorded'] && 6 === $res[ $a ]['passed'] && 'Pass' === $res[ $a ]['result'] );
	$check( 'results: B 5 passed (49.5 counts), Pass', 5 === $res[ $b ]['passed'] && 'Pass' === $res[ $b ]['result'] );
	$check( 'results: C 3 passed, Fail', 3 === $res[ $c ]['passed'] && 'Fail' === $res[ $c ]['result'] );
	$check( 'positions: A 1st, B and D tied 2nd, C 4th', 1 === $res[ $a ]['position'] && 2 === $res[ $b ]['position'] && 2 === $res[ $d ]['position'] && 4 === $res[ $c ]['position'], wp_json_encode( wp_list_pluck( $res, 'position' ) ) );

	// Attendance for the card: 2 of 3 days.
	foreach ( array( '2026-01-19' => 'p', '2026-01-20' => 'a', '2026-01-21' => 'l' ) as $date => $status ) {
		$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => $date, 'status' => $status, 'student_record_id' => $a, 'added_by' => $owner, 'created_at' => current_time( 'mysql' ) ) );
	}

	$card = BSA_Reports::card( $a, 1, 1 );
	$eng  = $card['subjects'][0];
	$phy  = null;
	foreach ( $card['subjects'] as $s ) {
		$phy = 'Physics' === $s['label'] ? $s : $phy;
	}
	$check( 'card: form, term, year', 'Form 3 A' === $card['form'] && 1 === $card['term'] && '2026' === $card['year'] );
	$check( 'card: no. in form counts the whole form', $card['in_form'] >= 4, (string) $card['in_form'] );
	$check( 'card: position 1, 6 recorded, 6 passed, Pass', 1 === $card['position'] && 6 === $card['recorded'] && 6 === $card['passed'] && 'Pass' === $card['result'] );
	$check( 'card: attendance 2 out of 3', array( 'attended' => 2, 'possible' => 3 ) === $card['attendance'] );
	$check( 'card: English first: term 68 B, exam 80 A, project 72.5, remark, head\'s initials', 'English Language' === $eng['label'] && '68' === $eng['term_mark'] && 'B' === $eng['term_grade'] && '80' === $eng['exam_mark'] && 'A' === $eng['exam_grade'] && '72.5' === $eng['project'] && 'Good effort' === $eng['remark'] && BSA_Grades::initials( get_userdata( $head )->display_name ) === $eng['initials'], wp_json_encode( $eng ) );
	$check( 'card: Physics initialled by the subject teacher', $phy && 'WH' === $phy['initials'], $phy ? $phy['initials'] : 'missing' );
	$check( 'card: next term begins 5 May 2026, next term fee USD 250', '2026-05-05' === $card['next_term'] && 250.0 === $card['next_fee'], $card['next_term'] . ' ' . var_export( $card['next_fee'], true ) );
	$bcard = BSA_Reports::card( $b, 1, 1 );
	$check( 'card: B lists all 13 subjects, 6 with marks', 13 === count( $bcard['subjects'] ) && 6 === count( array_filter( wp_list_pluck( $bcard['subjects'], 'exam_mark' ), 'strlen' ) ) );

	// Comments.
	$r = BSA_Reports::save_comments( $teacher, $a, 1, 1, array( 'form_teacher_comment' => 'x' ) );
	$check( 'comments: Form 1 A teacher cannot comment on Form 3', is_wp_error( $r ) );
	$r = BSA_Reports::save_comments( $head, $a, 1, 1, array( 'form_teacher_comment' => 'A hard-working learner.', 'conduct' => 'Excellent', 'principal_comment' => 'Well done.' ) );
	$cm = BSA_Reports::comments( $a, 1, 1 );
	$check( 'comments: head saves form teacher, conduct and principal comments', true === $r && 'Excellent' === $cm->conduct && 'Well done.' === $cm->principal_comment && (int) $cm->principal_id === $head );

	// Publication.
	$r = BSA_Reports::publish( $teacher, $sci, 1, 1 );
	$check( 'publish: teacher cannot publish', is_wp_error( $r ) );
	$r = BSA_Reports::publish( $head, $sci, 1, 1 );
	$check( 'publish: head publishes the 4 learners of Form 3 A', 4 === $r && BSA_Marks::is_published( $a, 1, 1 ) && BSA_Marks::is_published( $c, 1, 1 ) && ! BSA_Marks::is_published( $e, 1, 1 ) );
	$r = BSA_Marks::save( $teacher, $sci, 1, 1, $subs['Physics'], array( $a => array( 'exam' => '71' ) ) );
	$check( 'publish: subject teacher can no longer change a published mark', 0 === $r['saved'] && $r['errors'] );
	$r = BSA_Marks::save( $head, $sci, 1, 1, $subs['Physics'], array( $a => array( 'exam' => '71' ) ) );
	$check( 'publish: head can still correct it', 1 === $r['saved'] );
	$r = BSA_Reports::save_comments( $owner, $a, 1, 1, array( 'conduct' => 'Very good' ) );
	$check( 'publish: owner can still edit comments', true === $r );
	$r = BSA_Reports::publish( $head, $sci, 1, 1, false );
	$check( 'withdraw: head withdraws; no longer published', 4 === $r && ! BSA_Marks::is_published( $a, 1, 1 ) );
	BSA_Reports::publish( $head, $sci, 1, 1 );

	// Family ownership: a parent of A sees A's card only.
	$wpdb->insert( $wpdb->users, array( 'user_login' => 'bsa_test_rpt_parent', 'user_pass' => 'x', 'user_nicename' => 'bsa_test_rpt_parent', 'user_email' => 'rpt@example.invalid', 'user_registered' => current_time( 'mysql', true ), 'display_name' => 'Test Parent' ) );
	$parent = (int) $wpdb->insert_id;
	$wpdb->update( "{$p}wlsm_student_records", array( 'parent_user_id' => $parent ), array( 'ID' => $a ) );
	$owned = BSA_Access_Guard::owned_student_ids( $parent );
	$check( 'family: parent owns A only', array( $a ) === array_map( 'intval', $owned ) );

	// Rendering.
	$html = BSA_Reports::render_card( BSA_Reports::card( $a, 1, 1 ), 'https://example.invalid/crest.png', false );
	$check( 'print: card shows the book fields', false !== strpos( $html, 'Anesu Test Alpha' ) && false !== strpos( $html, 'Ministry of Primary and Secondary Education' ) && false !== strpos( $html, 'No. of Subjects Passed:' ) && false !== strpos( $html, 'Next Term Fees:' ) && false !== strpos( $html, 'USD 250.00' ) && false !== strpos( $html, 'Without Alteration/Erasure' ) );
	$check( 'print: 15 rows in the learning areas table', 15 === substr_count( $html, '<td class="area">' ) );
	$keys = BSA_Reports::render_keys( '' );
	$check( 'print: keys page lists 75–100 1 and 70–100 A', false !== strpos( $keys, '75–100' ) && false !== strpos( $keys, '70–100' ) && false !== strpos( $keys, "Let&#039;s help our children" ) );

	// Screens.
	wp_set_current_user( $teacher );
	$_GET = array( 'page' => BSA_Marks::PAGE, 'section' => $sci, 'session' => 1, 'term' => 1, 'subject' => $subs['Chemistry'] );
	ob_start();
	BSA_Marks::render_page();
	$html = ob_get_clean();
	$check( 'screen: teacher asking for Chemistry gets Physics instead', false !== strpos( $html, 'Physics, Form 3 A' ) && false === strpos( $html, 'Chemistry, Form 3' ) );
	$check( 'screen: published marks are read-only for the teacher', false !== strpos( $html, 'readonly' ) );
	wp_set_current_user( $head );
	$_GET = array( 'page' => BSA_Reports::PAGE, 'section' => $sci, 'session' => 1, 'term' => 1, 'student' => $a );
	ob_start();
	BSA_Reports::render_page();
	$html = ob_get_clean();
	$_GET = array();
	$check( 'screen: head sees the class list, publish button and comments form', false !== strpos( $html, 'Batsirai Test Bravo' ) && false !== strpos( $html, 'bsa_publish_reports' ) && false !== strpos( $html, 'principal_comment' ) );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	$_GET = array();
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	BSA_Reports::flush();
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number = 'TEST-RPT'" )
	+ (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_Marks::table() )
	+ (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_Marks::reports_table() )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_admin_subject" ) - $assigned_before
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'bsa\\_test\\_%'" )
	+ ( get_option( BSA_Terms::OPTION, false ) === $terms_before ? 0 : 1 );
$check( 'rollback: no synthetic learners, marks, reports, assignments or users left; term dates as before', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_reports', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
