<?php
/**
 * Integration test for Grade 7 entry level against results now (plugin 0.14.0): the six
 * subject grades on the admission record, the comparison (BSA_Entry), the at-risk sign
 * and the screens. Run on the live site through Novamira `execute-php` (send everything
 * after the opening tag).
 *
 * The admission table upgrade (new columns) runs first, outside the transaction. Then
 * five synthetic Form 1 learners ("Testentry") in the last school year, their Grade 7
 * grades and Term 1 exam marks are written inside a transaction and rolled back.
 * Expected values are worked out by hand in the comments. Results are also kept for an
 * hour in the `bsa_test_entry` transient. Set $bsa_capture = true to keep the rendered
 * screens in the `bsa_capture_entry` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/template.php';

$p           = $wpdb->prefix;
$bsa_capture = ! empty( $bsa_capture );
$captured    = array();
$results     = array();
$check       = function ( $name, $ok, $detail = '' ) use ( &$results ) {
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
$render = function ( $callback, $get ) {
	$_GET = $get;
	ob_start();
	try {
		call_user_func( $callback );
	} finally {
		$html = ob_get_clean();
		$_GET = array();
	}
	return $html;
};
$user = function ( $login ) {
	$u = get_user_by( 'login', $login );
	return $u ? (int) $u->ID : 0;
};
$head    = $user( 'Masango' );
$teacher = $user( 'hlabati.w' );

// Table upgrade (DDL: outside the transaction).
BSA_Admission_Records::maybe_migrate();
BSA_Marks::maybe_migrate();
$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . BSA_Admission_Records::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$check( 'table: six Grade 7 grade columns added; schema version 2', ! array_diff( array_keys( BSA_Entry::SUBJECTS ), $cols ) && '2' === get_option( 'bsa_sm_admission_db_version' ), implode( ', ', $cols ) );

// Values (no database).
list( $d, $e ) = BSA_Admission_Records::validate_fields( array( 'grade7_english' => '1', 'grade7_maths' => '1', 'grade7_science' => '2', 'grade7_language' => '2', 'grade7_social' => '1', 'grade7_pe_arts' => '3' ) );
$check( 'grades: six grades kept, units worked out (10)', ! $e && 10 === $d['grade7_units'] && 3 === $d['grade7_pe_arts'] && 1 === $d['grade7_english'], wp_json_encode( array( $d, $e ) ) );
list( $d, $e ) = BSA_Admission_Records::validate_fields( array( 'grade7_units' => '12', 'grade7_english' => '1', 'grade7_maths' => '1', 'grade7_science' => '2', 'grade7_language' => '2', 'grade7_social' => '1', 'grade7_pe_arts' => '3' ) );
$check( 'grades: units that do not add up are refused', 1 === count( $e ) && false !== strpos( $e[0], 'add up to 10' ) && null === $d['grade7_units'], wp_json_encode( $e ) );
list( $d, $e ) = BSA_Admission_Records::validate_fields( array( 'grade7_maths' => '10', 'grade7_english' => 'A', 'grade7_social' => '', 'grade7_units' => '9' ) );
$check( 'grades: 10 or a letter refused; empty clears; units kept when not all six are given', 2 === count( $e ) && array_key_exists( 'grade7_social', $d ) && null === $d['grade7_social'] && 9 === $d['grade7_units'] && ! array_key_exists( 'grade7_pe_arts', $d ), wp_json_encode( array( $d, $e ) ) );
list( $d ) = BSA_Admission_Records::validate_fields( array( 'grade7_units' => '6' ) );
$check( 'grades: a form without grade fields changes no grades', ! array_key_exists( 'grade7_maths', $d ) && 6 === $d['grade7_units'] );

$wpdb->query( 'START TRANSACTION' );
try {
	BSA_School::flush();
	BSA_Reports::flush();
	$f1 = 0;
	foreach ( BSA_School::sections() as $s ) {
		if ( 'Form 1 A' === $s->title ) {
			$f1 = (int) $s->ID;
		}
	}
	$sessions = array_keys( BSA_School::sessions() );
	$year     = end( $sessions );
	$before   = $sessions[ count( $sessions ) - 2 ];
	$check( 'fixture: no real learners in Form 1 A of the last year', $f1 && ! BSA_School::learners( $f1, $year ) );
	$mk = function ( $name, $adm, $session ) use ( $wpdb, $p, $f1 ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'gender' => 'female', 'enrollment_number' => 'T-' . $adm, 'admission_number' => $adm, 'roll_number' => $adm, 'section_id' => $f1, 'session_id' => $session, 'is_active' => 1 ) );
		return (int) $wpdb->insert_id;
	};
	$taf = $mk( 'Tafara Testentry', 'TEST-ENT-T', $year );
	$rum = $mk( 'Rumbi Testentry', 'TEST-ENT-R', $year );
	$nya = $mk( 'Nyasha Testentry', 'TEST-ENT-N', $year );
	$kud = $mk( 'Kuda Testentry', 'TEST-ENT-K', $year );
	$zoe = $mk( 'Zoe Testentry', 'TEST-ENT-Z', $year );
	$rum_old = $mk( 'Rumbi Testentry', 'TEST-ENT-R', $before ); // Her record in the year before holds the Grade 7 results.
	$six = function ( $en, $ma, $sc, $la, $so, $pe ) {
		return array( 'grade7_english' => $en, 'grade7_maths' => $ma, 'grade7_science' => $sc, 'grade7_language' => $la, 'grade7_social' => $so, 'grade7_pe_arts' => $pe );
	};
	BSA_Admission_Records::save_record( $taf, array_merge( $six( 1, 1, 2, 2, 1, 3 ), array( 'grade7_units' => 10, 'grade7_school' => 'Test Primary', 'grade7_year' => 2025 ) ), $head );
	BSA_Admission_Records::save_record( $rum_old, array_merge( $six( 3, 3, 3, 3, 3, 3 ), array( 'grade7_units' => 18 ) ), $head );
	BSA_Admission_Records::save_record( $nya, $six( 4, 4, 4, 4, 4, null ), $head );
	BSA_Admission_Records::save_record( $kud, array( 'grade7_units' => 24 ), $head );
	$rec = BSA_Admission_Records::get_record( $taf );
	$check( 'record: grades saved and read back', 1 === (int) $rec['grade7_maths'] && 3 === (int) $rec['grade7_pe_arts'] && 10 === (int) $rec['grade7_units'] );

	// Term 1 exam marks.
	$subs = array();
	foreach ( BSA_Marks::section_subjects( $f1 ) as $s ) {
		$subs[ $s->label ] = (int) $s->ID;
	}
	$put = function ( $student, array $marks ) use ( $wpdb, $subs, $year, $head ) {
		foreach ( $marks as $label => $exam ) {
			$wpdb->insert( BSA_Marks::table(), array( 'student_record_id' => $student, 'session_id' => $year, 'term' => 1, 'subject_id' => $subs[ $label ], 'term_mark' => null, 'exam_mark' => $exam, 'project_mark' => null, 'remark' => '', 'entered_by' => $head, 'updated_at' => current_time( 'mysql' ) ) );
		}
	};
	// Tafara: English 62 (4), Maths 45 (6), sciences 50/40/60 → 50 (5), Shona 80 (2),
	// History 70 + Geography 66 → 68 (4). Gaps 3, 5, 3, 0, 3 → 2.8 grades lower;
	// level at entry (1+1+2+2+1)/5 = 1.4, now (4+6+5+2+4)/5 = 4.2.
	$put( $taf, array( 'English Language' => 62, 'Mathematics' => 45, 'Physics' => 50, 'Chemistry' => 40, 'Biology' => 60, 'Shona Language' => 80, 'History' => 70, 'Geography' => 66 ) );
	// Rumbi: 70 everywhere (3) against 3: same level.
	$put( $rum, array( 'English Language' => 70, 'Mathematics' => 70, 'Physics' => 70, 'Shona Language' => 70, 'History' => 70 ) );
	// Nyasha: 80 (2) against 4: 2 grades higher.
	$put( $nya, array( 'English Language' => 80, 'Mathematics' => 80, 'Biology' => 80, 'Shona Language' => 80, 'Geography' => 80 ) );
	// Kuda: units only (24 → level 4); average 45 (6): 2 grades lower.
	$put( $kud, array( 'English Language' => 45, 'Mathematics' => 45, 'History' => 45 ) );
	$put( $zoe, array( 'English Language' => 60, 'Mathematics' => 60, 'History' => 60 ) );

	$ent = BSA_Entry::entries( array( $taf, $rum, $nya, $kud, $zoe ) );
	$check( 'entries: found for 4 learners (Rumbi through her other-year record); Zoe has none', isset( $ent[ $taf ], $ent[ $rum ], $ent[ $nya ], $ent[ $kud ] ) && ! isset( $ent[ $zoe ] ) && 18 === $ent[ $rum ]['units'] && 4.0 === (float) $ent[ $kud ]['level'] && 'Test Primary' === $ent[ $taf ]['school'] && null === $ent[ $nya ]['units'] && 4.0 === (float) $ent[ $nya ]['level'], wp_json_encode( array_keys( $ent ) ) );
	$cmp = BSA_Entry::for_class( $f1, $year, 1 );
	$check( 'comparison: Tafara 2.8 lower (1.4 → 4.2), Rumbi same, Nyasha 2 higher, Kuda 2 lower (units only), Zoe none', abs( $cmp[ $taf ]['gap'] - 2.8 ) < 1e-9 && abs( $cmp[ $taf ]['level'] - 1.4 ) < 1e-9 && abs( $cmp[ $taf ]['now'] - 4.2 ) < 1e-9 && 0.0 === (float) $cmp[ $rum ]['gap'] && -2.0 === (float) $cmp[ $nya ]['gap'] && 2.0 === (float) $cmp[ $kud ]['gap'] && null === $cmp[ $zoe ]['gap'], wp_json_encode( wp_list_pluck( $cmp, 'gap' ) ) );
	$check( 'comparison: subject pairs (Mathematics 1 → 6; sciences averaged → 5; PE & Arts not compared)', 6 === $cmp[ $taf ]['pairs']['grade7_maths']['now'] && 5 === $cmp[ $taf ]['pairs']['grade7_science']['now'] && 50.0 === (float) $cmp[ $taf ]['pairs']['grade7_science']['percent'] && null === $cmp[ $taf ]['pairs']['grade7_pe_arts']['gap'] );

	$rows = BSA_Results::learner_rows( $f1, $year, 1 );
	$codes = function ( $id ) use ( &$rows ) {
		return wp_list_pluck( $rows[ $id ]['reasons'], 'code' );
	};
	$tafr = array_values( array_filter( $rows[ $taf ]['reasons'], static function ( $r ) { return 'entry' === $r['code']; } ) ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment
	$check( 'at risk: Tafara and Kuda flagged "below Grade 7 level"; Rumbi, Nyasha and Zoe not', in_array( 'entry', $codes( $taf ), true ) && in_array( 'entry', $codes( $kud ), true ) && ! in_array( 'entry', $codes( $rum ), true ) && ! in_array( 'entry', $codes( $nya ), true ) && ! in_array( 'entry', $codes( $zoe ), true ) );
	$check( 'at risk: the reason in words', $tafr && 'Below Grade 7 level: 2.8 grades lower (entered at 1.4, now 4.2)' === $tafr[0]['text'], $tafr ? $tafr[0]['text'] : '' );
	$strict = BSA_Results::learner_rows( $f1, $year, 1, array_merge( BSA_Results::rules(), array( 'entry' => 3 ) ) );
	$check( 'rule: with 3 grades, Tafara (2.8) is no longer flagged for it', ! in_array( 'entry', wp_list_pluck( $strict[ $taf ]['reasons'], 'code' ), true ) );
	$risk = wp_list_pluck( BSA_Results::at_risk( array( $f1 ), $year, 1 ), 'id' );
	$check( 'at-risk list includes Tafara and Kuda', in_array( $taf, array_map( 'intval', $risk ), true ) && in_array( $kud, array_map( 'intval', $risk ), true ) );

	// Screens.
	wp_set_current_user( $head );
	$base = array( 'page' => BSA_Results::PAGE, 'session' => $year, 'term' => 1, 'section' => $f1 );
	$html = $render( array( 'BSA_Results', 'render_page' ), array_merge( $base, array( 'tab' => 'entry' ) ) );
	$check( 'Grade 7 entry tab: tiles 4 of 5 with results, 2 below, 1 above', false !== strpos( $html, 'aria-current="page">Grade 7 entry' ) && 1 === preg_match( '/With Grade 7 results<\/div><div class="bsa-tile-value">4<\/div><div class="bsa-tile-note">of 5 learners/', $html ) && 1 === preg_match( '/Below Grade 7 level<\/div><div class="bsa-tile-value">2</', $html ) && 1 === preg_match( '/Above Grade 7 level<\/div><div class="bsa-tile-value">1</', $html ), '' );
	$check( 'Grade 7 entry tab: furthest below first; subjects entry → now; status in words with an icon; learners without results named; grade key', strpos( $html, 'Tafara Testentry' ) < strpos( $html, 'Kuda Testentry' ) && strpos( $html, 'Kuda Testentry' ) < strpos( $html, 'Rumbi Testentry' ) && strpos( $html, 'Rumbi Testentry' ) < strpos( $html, 'Nyasha Testentry' ) && false !== strpos( $html, '<li>Mathematics 1 → 6</li>' ) && false !== strpos( $html, 'dashicons-arrow-down-alt" aria-hidden="true"></span> Below Grade 7 level' ) && false !== strpos( $html, 'learner has no Grade 7 results on file: Zoe Testentry' ) && false !== strpos( $html, '<li>Total units only: compared on the overall average</li>' ) && false === strpos( $html, 'English – → 6' ) && false !== strpos( $html, '1: 85–100%' ) && false !== strpos( $html, '9: 0–19%' ) );
	if ( $bsa_capture ) {
		$captured['entry'] = $html;
	}
	$html = $render( array( 'BSA_Results', 'render_page' ), array_merge( $base, array( 'tab' => 'learner', 'student' => $taf ) ) );
	$check( 'learner page: Grade 7 entry with school, year, units, each subject and the change', false !== strpos( $html, '<h3>Grade 7 entry</h3>' ) && false !== strpos( $html, 'Test Primary · 2025 · 10 units' ) && false !== strpos( $html, 'Agric, Science &amp; Tech' ) && false !== strpos( $html, '5 (50%)' ) && false !== strpos( $html, '1.4 → 4.2' ) && false !== strpos( $html, '2.8 grades lower' ), '' );
	if ( $bsa_capture ) {
		$captured['learner'] = $html;
	}
	$html = $render( array( 'BSA_Results', 'render_page' ), array_merge( $base, array( 'tab' => 'risk' ) ) );
	$check( 'at-risk tab: the sign explained and its rule adjustable', false !== strpos( $html, 'grades below the Grade 7 level' ) && false !== strpos( $html, 'name="entry"' ) && false !== strpos( $html, 'Below Grade 7 level: 2.8 grades lower' ) );
	$html = $render( array( 'BSA_Admission_Records', 'render_page' ), array( 'page' => BSA_Admission_Records::PAGE, 'student' => $taf ) );
	$check( 'Admission Documents: the six grade fields with the saved grades', 6 === preg_match_all( '/name="grade7_(english|maths|science|language|social|pe_arts)"/', $html ) && false !== strpos( $html, 'name="grade7_pe_arts" value="3"' ) );

	// The Form 1 A class teacher sees the tab for his class; families never see it.
	wp_set_current_user( $teacher );
	$html = $render( array( 'BSA_Results', 'render_page' ), array_merge( $base, array( 'tab' => 'entry' ) ) );
	$check( 'class teacher: sees the Grade 7 entry tab for his class', false !== strpos( $html, 'Tafara Testentry' ) && false !== strpos( $html, 'Grade 7 entry and Term 1' ) );
	$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $taf, 'session_id' => $year, 'term' => 1, 'form_teacher_comment' => '', 'principal_comment' => '', 'published_at' => current_time( 'mysql' ), 'published_by' => $head, 'updated_at' => current_time( 'mysql' ) ) );
	$fam = BSA_Results::family_progress( array( $taf ) );
	$check( 'families: progress shown without the Grade 7 comparison', '' !== $fam && false === stripos( $fam, 'Grade 7' ) && false === stripos( $fam, 'grades lower' ) );
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

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE name LIKE '%Testentry%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( 'rollback: no synthetic data left', 0 === $left, (string) $left );
if ( $bsa_capture ) {
	set_transient( 'bsa_capture_entry', $captured, HOUR_IN_SECONDS );
}
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_entry', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
