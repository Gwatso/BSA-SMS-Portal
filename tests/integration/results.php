<?php
/**
 * Integration test for BSA_Results (results, at-risk, progression, family progress), run on
 * the live site through Novamira `execute-php` (send everything after the opening tag).
 *
 * Synthetic learners, marks, register entries, term dates, rules and published report
 * cards are written inside a transaction and rolled back. Before activation, set
 * $bsa_stage to the staged plugin folder to test that copy. Results are also kept for an
 * hour in the `bsa_test_results` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
if ( ! class_exists( 'BSA_Results' ) && $bsa_stage ) {
	require_once $bsa_stage . '/includes/class-bsa-results.php';
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
$die_hooks = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler' );
foreach ( $die_hooks as $hook ) {
	add_filter( $hook, $die, 999 );
}

// Helpers (no database).
$check( 'rules: defaults 50 / 5 / 10 / 85', array( 'average' => 50, 'passes' => 5, 'drop' => 10, 'attendance' => 85 ) === BSA_Results::defaults() );
list( $r, $e ) = BSA_Results::validate_rules( array( 'average' => '45', 'passes' => '0', 'drop' => 'ten', 'attendance' => '101' ) );
$check( 'rules: out-of-range and non-numbers refused', array( 'average' => 45 ) === $r && array( 'passes', 'drop', 'attendance' ) === array_keys( $e ) );
$check( 'format: 63.5%, 70%, +4.5, −12, –', '63.5%' === BSA_Results::pct( 63.45 ) && '70%' === BSA_Results::pct( 70 ) && '+4.5' === BSA_Results::signed( 4.5 ) && '−12' === BSA_Results::signed( -12 ) && '–' === BSA_Results::pct( null ) && '–' === BSA_Results::signed( null ) );
$sp = BSA_Results::sparkline( array( 'T1' => 60, 'T2' => null, 'T3' => 72.4 ), 'Maths' );
$check( 'sparkline: two points with values written and an accessible name; nothing for one point', 2 === substr_count( $sp, '<circle' ) && false !== strpos( $sp, 'aria-label="Maths: T1 60%, T3 72.4%"' ) && false !== strpos( $sp, '>72<' ) && '' === BSA_Results::sparkline( array( 'T1' => 60, 'T2' => null ), 'x' ) );
$sessions = array_keys( BSA_School::sessions() );
$check( 'previous term: Term 2 → Term 1; first term of the first year → none; Term 1 2027 → Term 3 2026', array( 1, 1 ) === BSA_Results::previous_term( 1, 2 ) && null === BSA_Results::previous_term( $sessions[0], 1 ) && array( $sessions[0], 3 ) === BSA_Results::previous_term( $sessions[1], 1 ) );

BSA_Marks::maybe_migrate();
BSA_Register::maybe_migrate();
$terms_before = get_option( BSA_Terms::OPTION, false );
$rules_before = get_option( BSA_Results::OPTION, false );

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
	BSA_School::flush();
	BSA_Reports::flush();

	$sci = 6; // Form 3 Sciences.
	$f4s = 7; // Form 4 Sciences.
	$s1  = $sessions[0];
	$s2  = $sessions[1];
	$check( 'fixture: no real learners in Form 3 Sciences 2026 or Form 4 Sciences 2027', ! BSA_School::learners( $sci, $s1 ) && ! BSA_School::learners( $f4s, $s2 ) );
	BSA_Terms::save( $s1, array( 1 => array( 'start' => '2026-01-13', 'end' => '2026-04-02' ), 2 => array( 'start' => '2026-05-05', 'end' => '2026-08-06' ), 3 => array( 'start' => '2026-09-08', 'end' => '2026-12-01' ) ) );
	BSA_Terms::save( $s2, array( 1 => array( 'start' => '2027-01-12', 'end' => '2027-04-01' ) ) );
	delete_option( BSA_Results::OPTION );

	$subs = array();
	foreach ( BSA_Marks::section_subjects( $sci ) as $row ) {
		$subs[ $row->label ] = (int) $row->ID;
	}
	$six = array( 'English Language', 'Mathematics', 'Physics', 'Chemistry', 'Biology', 'Geography' );
	$mk  = function ( $name, $section, $session, $adm ) use ( $wpdb, $p, $subs, $six ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'gender' => 'female', 'enrollment_number' => $adm, 'admission_number' => $adm, 'session_id' => $session, 'section_id' => $section, 'is_active' => 1 ) );
		$id = (int) $wpdb->insert_id;
		foreach ( $six as $label ) {
			$wpdb->insert( "{$p}wlsm_students_subjects", array( 'student_id' => $id, 'subject_id' => $subs[ $label ] ) );
		}
		return $id;
	};
	$put = function ( $student, $session, $term, array $marks ) use ( $wpdb, $subs, $six, $owner ) {
		foreach ( array_values( $marks ) as $i => $mark ) {
			if ( null === $mark ) {
				continue;
			}
			$wpdb->insert( BSA_Marks::table(), array( 'student_record_id' => $student, 'session_id' => $session, 'term' => $term, 'subject_id' => $subs[ $six[ $i ] ], 'exam_mark' => $mark, 'remark' => '', 'entered_by' => $owner, 'updated_at' => current_time( 'mysql' ) ) );
		}
	};
	$a = $mk( 'Res Test Alpha', $sci, $s1, 'TEST-RES-A' );   // Fine.
	$b = $mk( 'Res Test Bravo', $sci, $s1, 'TEST-RES-B' );   // Low average and few passes.
	$c = $mk( 'Res Test Charlie', $sci, $s1, 'TEST-RES-C' ); // Average dropped 15.
	$d = $mk( 'Res Test Delta', $sci, $s1, 'TEST-RES-D' );   // Attendance 8 of 12.
	$e = $mk( 'Res Test Echo', $sci, $s1, 'TEST-RES-E' );    // Two marks in, can still pass 5.
	$f = $mk( 'Res Test Foxtrot', $sci, $s1, 'TEST-RES-F' ); // Three low marks.
	$g = $mk( 'Res Test Golf', $sci, $s1, 'TEST-RES-G' );    // Absent 3 of only 5 days.
	$h = $mk( 'Res Test Hotel', $f4s, $s2, 'TEST-RES-C' );   // Charlie's 2027 record.
	$put( $a, $s1, 2, array( 70, 70, 70, 70, 70, 70 ) );
	$put( $a, $s1, 3, array( 72, 72, 72, 72, 72, 72 ) );
	$put( $b, $s1, 2, array( 47, 47, 47, 47, 47, 47 ) );
	$put( $b, $s1, 3, array( 40, 45, 42, 48, 50, 55 ) );
	$put( $c, $s1, 2, array( 75, 75, 75, 75, 75, 75 ) );
	$put( $c, $s1, 3, array( 60, 60, 60, 60, 60, 60 ) );
	$put( $d, $s1, 3, array( 65, 65, 65, 65, 65, 65 ) );
	$put( $e, $s1, 3, array( 30, 80, null, null, null, null ) );
	$put( $f, $s1, 3, array( 30, 35, 40, null, null, null ) );
	$put( $g, $s1, 3, array( 60, 60, 60, 60, 60, 60 ) );
	$put( $h, $s2, 1, array( 50, 50, 50, 50, 50, 50 ) );

	// Twelve school days in Term 3 (from 8 September); Delta absent on four, Golf on 3 of 5.
	$days = array();
	for ( $t = strtotime( '2026-09-08' ); count( $days ) < 12; $t += DAY_IN_SECONDS ) {
		if ( (int) gmdate( 'N', $t ) <= 5 ) {
			$days[] = gmdate( 'Y-m-d', $t );
		}
	}
	foreach ( $days as $i => $day ) {
		foreach ( array( $a, $b, $c, $d ) as $sid ) {
			$status = ( $sid === $d && $i < 4 ) ? 'a' : 'p';
			$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => $day, 'status' => $status, 'student_record_id' => $sid, 'added_by' => $owner, 'created_at' => current_time( 'mysql' ) ) );
		}
		if ( $i < 5 ) {
			$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => $day, 'status' => $i < 3 ? 'a' : 'p', 'student_record_id' => $g, 'added_by' => $owner, 'created_at' => current_time( 'mysql' ) ) );
		}
	}
	BSA_Reports::flush();

	$rows  = BSA_Results::learner_rows( $sci, $s1, 3 );
	$codes = function ( $id ) use ( $rows ) {
		return wp_list_pluck( $rows[ $id ]['reasons'], 'code' );
	};
	$check( 'rows: seven synthetic learners with averages and changes', 7 === count( $rows ) && 72.0 === round( $rows[ $a ]['average'], 2 ) && 2.0 === round( $rows[ $a ]['change'], 2 ) && 6 === $rows[ $a ]['subjects'], wp_json_encode( array( $rows[ $a ]['average'], $rows[ $a ]['change'] ) ) );
	$check( 'risk: a learner doing fine is not flagged', array() === $codes( $a ) );
	$check( 'risk: low average and too few passes (2 of 6)', array( 'average', 'passes' ) === $codes( $b ), wp_json_encode( $rows[ $b ]['reasons'] ) );
	$check( 'risk: average down 15 on Term 2 (75% → 60%)', array( 'drop' ) === $codes( $c ) && false !== strpos( $rows[ $c ]['reasons'][0]['text'], 'down 15' ), wp_json_encode( $rows[ $c ]['reasons'] ) );
	$check( 'risk: attendance 8 of 12 days (66.7%)', array( 'attendance' ) === $codes( $d ) && false !== strpos( $rows[ $d ]['reasons'][0]['text'], '8 of 12 days' ), wp_json_encode( $rows[ $d ]['reasons'] ) );
	$check( 'risk: two marks in (30, 80), five passes still possible: not flagged', array() === $codes( $e ) );
	$check( 'risk: three low marks: low average and five passes no longer possible', array( 'average', 'passes' ) === $codes( $f ) );
	$check( 'risk: attendance on only 5 register days does not count yet', array() === $codes( $g ) && 5 === $rows[ $g ]['possible'] );
	update_option( BSA_Results::OPTION, array( 'average' => 50, 'passes' => 5, 'drop' => 20, 'attendance' => 60 ) );
	$strict = BSA_Results::learner_rows( $sci, $s1, 3 );
	$check( 'rules: stored rules used (drop 20, attendance 60 clear Charlie and Delta)', array() === $strict[ $c ]['reasons'] && array() === $strict[ $d ]['reasons'] );
	delete_option( BSA_Results::OPTION );

	$sum = BSA_Results::summarise( $rows );
	// Complete: A, B, C, D, G (all six marks); passing A, C, D, G; at risk B, C, D, F.
	$check( 'summary: 7 learners, 7 with marks, 5 complete, 4 passing (80%), 4 at risk', array( 7, 7, 5, 4, 4 ) === array( $sum['learners'], $sum['with_marks'], $sum['complete'], $sum['passing'], $sum['at_risk'] ) && 0.8 === round( $sum['pass_rate'], 2 ), wp_json_encode( $sum ) );

	$subj = BSA_Results::subjects( $sci, $s1, 3 );
	$phys = $subj[ $subs['Physics'] ];
	// Physics (third subject): 72, 42, 60, 65, 40 (F), 60 (G) → 6 entries, 4 passes, average 56.5.
	$check( 'subjects: Physics 6 entries, 4 passed, average 56.5%, grades A 1, B 3, E 2 on the O Level key', 6 === $phys['entries'] && 4 === $phys['passes'] && 56.5 === round( $phys['average'], 2 ) && 1 === $phys['grades']['A'] && 3 === $phys['grades']['B'] && 2 === $phys['grades']['E'] && array( 'A', 'B', 'C', 'D', 'E', 'U' ) === array_keys( $phys['grades'] ), wp_json_encode( $phys ) );
	$check( 'subjects: limited to given subjects; untaken subjects have no entries', array( $subs['Physics'] ) === array_keys( BSA_Results::subjects( $sci, $s1, 3, array( $subs['Physics'] ) ) ) && 0 === $subj[ $subs['History'] ]['entries'] );

	$prog = BSA_Results::progression( $sci, $s1 );
	$check( 'progression: no Term 1 results; Term 2 average 64 (A 70, B 47, C 75); Term 3 average present', null === $prog[1]['average'] && 64.0 === round( $prog[2]['average'], 2 ) && null !== $prog[3]['average'] );

	$risk = BSA_Results::at_risk( array( $sci, 4 ), $s1, 3 );
	$check( 'at risk: four learners, two-reason learners first', 4 === count( $risk ) && 2 === count( $risk[0]['reasons'] ) && 2 === count( $risk[1]['reasons'] ) && 1 === count( $risk[3]['reasons'] ) && $sci === $risk[0]['section'] );

	$rec = BSA_Results::record_in( $h, $s1 );
	$check( 'next year: the 2027 record is matched to the 2026 one by admission number', $rec && (int) $rec->ID === $c );
	$h_rows = BSA_Results::learner_rows( $f4s, $s2, 1 );
	$check( 'next year: Term 1 2027 compared with Term 3 2026 (60% → 50%, down 10: flagged)', -10.0 === round( $h_rows[ $h ]['change'], 2 ) && in_array( 'drop', wp_list_pluck( $h_rows[ $h ]['reasons'], 'code' ), true ) );

	$year = BSA_Results::learner_year( $c, $sci, $s1 );
	$check( 'learner year: Charlie\'s English 75 in Term 2, 60 in Term 3, none in Term 1', null === $year['subjects'][ $subs['English Language'] ]['marks'][1] && 75.0 === $year['subjects'][ $subs['English Language'] ]['marks'][2] && 60.0 === $year['subjects'][ $subs['English Language'] ]['marks'][3] && 6 === count( $year['subjects'] ) );

	// Rights.
	$check( 'rights: owner and school administrator all classes; class teacher own class; bursar and parent nothing', BSA_Results::user_can_view( $owner ) && count( BSA_Results::sections_for( $head ) ) === count( BSA_School::sections() ) && array( 4 ) === BSA_Results::sections_for( $teacher ) && ! BSA_Results::user_can_view( $bursar ) && ! BSA_Results::user_can_view( $parent ) && ! BSA_Results::user_can_view( 0 ) );

	// Screens.
	$render = function ( $uid, array $get ) {
		wp_set_current_user( $uid );
		$_GET = array_merge( array( 'page' => BSA_Results::PAGE ), $get );
		ob_start();
		try {
			BSA_Results::render_page();
			return ob_get_clean();
		} catch ( RuntimeException $ex ) {
			ob_end_clean();
			return 'DIED ' . $ex->getMessage();
		}
	};
	$base = array( 'session' => $s1, 'term' => 3, 'section' => $sci );
	$html = $render( $head, $base );
	$check( 'screen overview: figures, classes and subjects of Form 3 Sciences', false !== strpos( $html, 'Classes, Term 3' ) && false !== strpos( $html, 'Subjects, Form 3 Sciences, Term 3' ) && false !== strpos( $html, '4 of 5 (80%)' ) && false !== strpos( $html, '>Physics</th>' ) && false !== strpos( $html, 'B: 3' ) );
	$html = $render( $head, array_merge( $base, array( 'tab' => 'risk' ) ) );
	$check( 'screen at risk: four learners with reasons, rules form for the school administrator', false !== strpos( $html, '4 learners at risk, Term 3' ) && false !== strpos( $html, 'Res Test Bravo' ) && false !== strpos( $html, '2 of 6 subjects passed' ) && false !== strpos( $html, 'Attendance 66.7% (8 of 12 days)' ) && false !== strpos( $html, 'bsa_save_risk_rules' ) && false === strpos( $html, 'Res Test Alpha' ) );
	$html = $render( $head, array_merge( $base, array( 'tab' => 'progress' ) ) );
	$check( 'screen progression: class averages with a trend line and biggest changes', false !== strpos( $html, 'Class averages by term, ' ) && false !== strpos( $html, '<svg class="bsa-spark"' ) && false !== strpos( $html, 'Most improved' ) && false !== strpos( $html, 'Biggest drops' ) && false !== strpos( $html, 'Res Test Charlie' ) );
	$html = $render( $head, array_merge( $base, array( 'tab' => 'learner', 'student' => $c ) ) );
	$check( 'screen learner: marks with grades by term, reasons, position for staff', false !== strpos( $html, 'Res Test Charlie, Form 3 Sciences' ) && false !== strpos( $html, '75 (A)' ) && false !== strpos( $html, '60 (B)' ) && false !== strpos( $html, 'At risk in Term 3' ) && false !== strpos( $html, '>Position</th>' ) );
	$html = $render( $teacher, array( 'tab' => 'learner', 'student' => $c, 'session' => $s1, 'term' => 3 ) );
	$check( 'screen: a class teacher cannot open a learner of another class', false !== strpos( $html, 'This learner is not in your classes.' ) && false === strpos( $html, '75 (A)' ) );
	$html = $render( $teacher, array( 'tab' => 'risk', 'session' => $s1, 'term' => 3 ) );
	$check( 'screen: class teacher sees only their class (no Form 3 learners), no rules form', false === strpos( $html, 'Res Test' ) && false === strpos( $html, 'bsa_save_risk_rules' ) );
	$html = $render( $bursar, $base );
	$check( 'screen: bursar refused', 0 === strpos( $html, 'DIED wp_die' ) );

	// Families: only published terms, no position, no flag.
	$check( 'family: nothing before publication', '' === BSA_Results::family_progress( array( $c ) ) );
	foreach ( array( 2, 3 ) as $t ) {
		$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $c, 'session_id' => $s1, 'term' => $t, 'form_teacher_comment' => '', 'conduct' => '', 'principal_comment' => '', 'published_at' => current_time( 'mysql' ), 'published_by' => $head, 'updated_at' => current_time( 'mysql' ) ) );
	}
	BSA_Reports::flush();
	$fam = BSA_Results::family_progress( array( $c ) );
	$check( 'family: progress from published terms with change, passes and marks by subject', false !== strpos( $fam, 'Term 2, 2026' ) && false !== strpos( $fam, 'Term 3, 2026' ) && false !== strpos( $fam, '>75%<' ) && false !== strpos( $fam, '>60%<' ) && false !== strpos( $fam, '>−15<' ) && false !== strpos( $fam, '6 of 6' ) && false !== strpos( $fam, '60 (B)' ) && false !== strpos( $fam, '<svg class="bsa-spark"' ) );
	$check( 'family: no position and no at-risk wording', false === stripos( $fam, 'position' ) && false === stripos( $fam, 'risk' ) );
	$check( 'family: report cards page shows the progress section', false !== strpos( BSA_Family::reports_table( array( $c ), true ), 'Progress' ) );

	// Dashboard and teacher home.
	$state = BSA_Dashboard::state();
	$check( 'dashboard: today\'s at-risk count includes the synthetic learners (Term 3)', $state['at_risk'] >= 4, (string) $state['at_risk'] );
	$items = wp_list_pluck(
		BSA_Dashboard::attention(
			array(
				'term'          => array( 'session' => $s1, 'term' => 3, 'start' => '2026-09-08', 'end' => '2099-12-01' ),
				'is_school_day' => false,
				'attendance'    => array(),
				'sections'      => array(),
				'marks'         => array(),
				'unassigned'    => array(),
				'accounts'      => array( 'errors' => 0, 'warnings' => 0 ),
				'at_risk'       => 4,
			)
		),
		'text'
	);
	$check( 'dashboard: "4 learners are at risk in Term 3." needs attention', in_array( '4 learners are at risk in Term 3.', $items, true ), wp_json_encode( $items ) );
	$check( 'teacher home: at-risk count for the class', isset( BSA_Staff_Area::home_state( $teacher )['class']['at_risk'] ) );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	$_GET = array();
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	BSA_Reports::flush();
	foreach ( $die_hooks as $hook ) {
		remove_filter( $hook, $die, 999 );
	}
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number LIKE 'TEST-RES%'" )
	+ ( get_option( BSA_Terms::OPTION, false ) === $terms_before ? 0 : 1 )
	+ ( get_option( BSA_Results::OPTION, false ) === $rules_before ? 0 : 1 );
$check( 'rollback: no synthetic data left; terms and rules as before', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_results', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
