<?php
/**
 * Integration test for BSA_Streams (Sciences and Commercials as subject choices in one
 * Form 3 / Form 4 class) and BSA_Rollover (year-end rollover), run on the live site
 * through Novamira `execute-php` (send everything after the opening tag), after script 11
 * (one class per form).
 *
 * Synthetic learners ("Testroll") in the earliest school year (2025, no real learners)
 * are rolled over into the next year inside a transaction that is rolled back; the
 * all-or-nothing check runs the rollover's own transaction with a forced failure.
 * Results are also kept for an hour in the `bsa_test_rollover` transient. Set
 * $bsa_capture = true to keep the rendered screens in the `bsa_capture_rollover`
 * transient.
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
$owner   = $user( 'bsa' );
$head    = $user( 'Masango' );
$teacher = $user( 'hlabati.w' );
$bursar  = $user( 'bursar' );
BSA_School::flush();
$sec = array();
foreach ( BSA_School::sections() as $s ) {
	$sec[ $s->title ] = (int) $s->ID;
}
$f1 = $sec['Form 1 A'] ?? 0;
$f2 = $sec['Form 2 A'] ?? 0;
$f3 = $sec['Form 3 A'] ?? 0;
$f4 = $sec['Form 4 A'] ?? 0;
$check( 'classes: one class per form (Form 1 A … Form 4 A)', array( 'Form 1 A', 'Form 2 A', 'Form 3 A', 'Form 4 A' ) === array_keys( $sec ), implode( ', ', array_keys( $sec ) ) );
$sessions = array_keys( BSA_School::sessions() );
$from     = $sessions[0];
$to       = $sessions[1];
$subjects = function ( $section ) {
	$out = array();
	foreach ( BSA_Marks::section_subjects( $section ) as $s ) {
		$out[ $s->label ] = (int) $s->ID;
	}
	return $out;
};
$s3 = $subjects( $f3 );
$s4 = $subjects( $f4 );

// ---------------------------------------------------------------------------------
// Streams (no learners).
// ---------------------------------------------------------------------------------
$check( 'streams: owner\'s subject lists by default', array( 'Sciences' => array( 'Physics', 'Chemistry', 'Biology' ), 'Commercials' => array( 'Heritage Studies', 'Combined Science', 'Business Enterprise Skills' ) ) === BSA_Streams::streams() );
$check( 'streams: subject belongs to a stream or to everyone', 'Sciences' === BSA_Streams::subject_stream( 'physics' ) && 'Commercials' === BSA_Streams::subject_stream( 'Combined Science ' ) && '' === BSA_Streams::subject_stream( 'Mathematics' ) && '' === BSA_Streams::subject_stream( 'Principles of Accounting' ) );
$check( 'streams: words read as streams', 'Sciences' === BSA_Streams::parse( 'F3 Sci' ) && 'Sciences' === BSA_Streams::parse( 'Science' ) && 'Commercials' === BSA_Streams::parse( 'COMMERCIALS' ) && 'Commercials' === BSA_Streams::parse( 'Commerce' ) && '' === BSA_Streams::parse( 'Form 3' ) );
$check( 'streams: from Form 3 only', ! BSA_Streams::applies( $f1 ) && ! BSA_Streams::applies( $f2 ) && BSA_Streams::applies( $f3 ) && BSA_Streams::applies( $f4 ) );
$sci = array_map( 'intval', BSA_Streams::subjects_for( $f3, 'Sciences' ) );
$com = array_map( 'intval', BSA_Streams::subjects_for( $f3, 'Commercials' ) );
$check( 'streams: Form 3 has 13 subjects; Sciences and Commercials learners take 10 each (7 for everyone + 3)', 13 === count( $s3 ) && 10 === count( $sci ) && 10 === count( $com ) && in_array( $s3['Physics'], $sci, true ) && ! in_array( $s3['Combined Science'], $sci, true ) && in_array( $s3['Combined Science'], $com, true ) && in_array( $s3['Principles of Accounting'], $sci, true ) && in_array( $s3['Principles of Accounting'], $com, true ) && array() === BSA_Streams::subjects_for( $f3, '' ), count( $s3 ) . '/' . count( $sci ) . '/' . count( $com ) );
$check( 'streams: no stream read from no subjects; a mix is an own choice', '' === BSA_Streams::stream_of( $f3, array() ) && 'Sciences' === BSA_Streams::stream_of( $f3, $sci ) && 'Commercials' === BSA_Streams::stream_of( $f3, $com ) && 'mixed' === BSA_Streams::stream_of( $f3, array( $s3['Physics'], $s3['Combined Science'] ) ) );
$check( 'streams: who sets them (school administrator all stream classes; Form 1 class teacher none; bursar none)', array( $f3, $f4 ) === BSA_Streams::allowed_sections( $head ) && array() === BSA_Streams::allowed_sections( $teacher ) && array() === BSA_Streams::allowed_sections( $bursar ) );
$check( 'rollover: rights (owner and school administrator only)', BSA_Rollover::user_can( $owner ) && BSA_Rollover::user_can( $head ) && ! BSA_Rollover::user_can( $teacher ) && ! BSA_Rollover::user_can( $bursar ) );
$check( 'rollover: next year and next class (Form 4 → none)', $to === BSA_Rollover::next_session( $from ) && 0 === BSA_Rollover::next_session( end( $sessions ) ) && $f2 === BSA_Rollover::next_section( $f1 ) && $f3 === BSA_Rollover::next_section( $f2 ) && $f4 === BSA_Rollover::next_section( $f3 ) && 0 === BSA_Rollover::next_section( $f4 ) );

BSA_Marks::maybe_migrate();
$batches_before = get_option( BSA_Rollover::BATCHES, false );
$current_before = get_option( 'wlsm_current_session' );
$fake_user      = 0;
$fake_parent    = 0;
$mk             = function ( $name, $section, $session, $adm, array $extra = array() ) use ( $wpdb, $p ) {
	$wpdb->insert( "{$p}wlsm_student_records", array_merge( array( 'name' => $name, 'gender' => 'female', 'enrollment_number' => 'T-' . $adm, 'admission_number' => $adm, 'roll_number' => $adm, 'section_id' => $section, 'session_id' => $session, 'is_active' => 1, 'student_type' => '2', 'country' => 'Zimbabwe' ), $extra ) );
	return (int) $wpdb->insert_id;
};

// ---------------------------------------------------------------------------------
// All or nothing: the rollover's own transaction, the second learner failing.
// ---------------------------------------------------------------------------------
$a1 = $mk( 'Rollback One Testroll', $f1, $from, 'TEST-ROLL-RB1' );
$a2 = $mk( 'Rollback Two Testroll', $f1, $from, 'TEST-ROLL-RB2' );
try {
	$inserts = 0;
	$break   = function ( $q ) use ( &$inserts, $p ) {
		if ( 0 === strpos( $q, "INSERT INTO `{$p}wlsm_student_records`" ) && 2 === ++$inserts ) {
			return 'INSERT INTO `' . $p . 'wlsm_student_records_missing` (x) VALUES (1)';
		}
		return $q;
	};
	add_filter( 'query', $break );
	$suppress = $wpdb->suppress_errors( true );
	$r        = BSA_Rollover::run( $from, $to, array(), array(), $owner );
	$wpdb->suppress_errors( $suppress );
	remove_filter( 'query', $break );
	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE session_id = %d AND admission_number LIKE 'TEST-ROLL-RB%%'", $to ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'all or nothing: when the second learner fails, the first is not moved and nothing is listed', is_wp_error( $r ) && 0 === $left && get_option( BSA_Rollover::BATCHES, false ) === $batches_before && ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_promotions WHERE from_student_record IN (%d, %d)", $a1, $a2 ) ), ( is_wp_error( $r ) ? $r->get_error_message() : 'no error' ) . " left $left" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
} finally {
	$wpdb->delete( "{$p}wlsm_student_records", array( 'ID' => $a1 ) );
	$wpdb->delete( "{$p}wlsm_student_records", array( 'ID' => $a2 ) );
}

// ---------------------------------------------------------------------------------
// Streams, marks sheets, rollover, undo: inside a rolled-back transaction.
// ---------------------------------------------------------------------------------
add_filter( 'bsa_sm_rollover_transaction', '__return_false' );
$wpdb->query( 'START TRANSACTION' );
try {
	BSA_School::flush();
	$check( 'fixture: no real learners in the first year', ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE session_id = %d", $from ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// Two throwaway logins (rolled back with everything else).
	foreach ( array( 'fake_user', 'fake_parent' ) as $who ) {
		$wpdb->insert( $wpdb->users, array( 'user_login' => 'bsa_test_roll_' . $who, 'user_pass' => 'x', 'user_nicename' => 'bsa_test_roll_' . $who, 'user_email' => $who . '@example.invalid', 'user_registered' => current_time( 'mysql', true ), 'display_name' => 'Test ' . $who ) );
		$$who = (int) $wpdb->insert_id;
	}
	$ama  = $mk( 'Ama Testroll', $f1, $from, 'TEST-ROLL-A', array( 'user_id' => $fake_user, 'parent_user_id' => $fake_parent ) );
	$check( 'fixture: synthetic learners and logins created', $fake_user && $fake_parent && $ama, $wpdb->last_error );
	$bon  = $mk( 'Bongi Testroll', $f2, $from, 'TEST-ROLL-B' );
	$chi  = $mk( 'Chido Testroll', $f2, $from, 'TEST-ROLL-C' );
	$dar  = $mk( 'Dara Testroll', $f2, $from, 'TEST-ROLL-D' );
	$eli  = $mk( 'Eli Testroll', $f3, $from, 'TEST-ROLL-E' );
	$fay  = $mk( 'Fay Testroll', $f3, $from, 'TEST-ROLL-F' );
	$kim  = $mk( 'Kim Testroll', $f3, $from, 'TEST-ROLL-K' );
	$gar  = $mk( 'Gari Testroll', $f4, $from, 'TEST-ROLL-G' );
	$han  = $mk( 'Hana Testroll', $f4, $from, 'TEST-ROLL-H' );
	$ivy  = $mk( 'Ivy Testroll', $f1, $from, '' );
	$jo   = $mk( 'Jo Testroll', $f1, $from, 'TEST-ROLL-J' );
	$jo2  = $mk( 'Jo Testroll', $f2, $to, 'TEST-ROLL-J' );
	BSA_Admission_Records::save_record( $ama, array( 'grade7_units' => 9, 'grade7_school' => 'Test Primary' ), $head );

	// Streams.
	$r = BSA_Streams::save( $teacher, $f3, $from, array( $eli => 'Commercials' ) );
	$check( 'streams: refused for the Form 1 class teacher', is_wp_error( $r ) );
	$r = BSA_Streams::save( $head, $f3, $from, array( $eli => 'Commercials', $kim => 'Sciences', $fay => '', 999 => 'Sciences', $ama => 'Sciences' ) );
	$map = BSA_Streams::subject_map( array( $eli, $kim, $fay ) );
	$check( 'streams: saved as subjects (Eli Commercials, Kim Sciences, Fay not set); learners of other classes ignored', 2 === $r && 'Commercials' === BSA_Streams::stream_of( $f3, $map[ $eli ] ) && 'Sciences' === BSA_Streams::stream_of( $f3, $map[ $kim ] ) && array() === $map[ $fay ] && 10 === count( $map[ $eli ] ) && array() === BSA_Streams::subject_map( array( $ama ) )[ $ama ], wp_json_encode( $r ) );
	$check( 'streams: saving the same again changes nothing; "keep" leaves an own choice', 0 === BSA_Streams::save( $head, $f3, $from, array( $eli => 'Commercials', $kim => 'keep' ) ) );
	$phys = wp_list_pluck( BSA_Marks::subject_learners( $f3, $from, $s3['Physics'] ), 'ID' );
	$comb = wp_list_pluck( BSA_Marks::subject_learners( $f3, $from, $s3['Combined Science'] ), 'ID' );
	$math = wp_list_pluck( BSA_Marks::subject_learners( $f3, $from, $s3['Mathematics'] ), 'ID' );
	$check( 'marks sheets: Physics lists Kim and Fay (not set) but not Eli; Combined Science Eli and Fay; Mathematics everyone', array( $fay, $kim ) == array_map( 'intval', array_values( array_intersect( array( $fay, $kim ), $phys ) ) ) && ! in_array( (string) $eli, array_map( 'strval', $phys ), true ) && in_array( (string) $eli, array_map( 'strval', $comb ), true ) && ! in_array( (string) $kim, array_map( 'strval', $comb ), true ) && 3 === count( $math ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$r = BSA_Marks::save( $head, $f3, $from, 3, $s3['Physics'], array( $eli => array( 'exam' => '60' ) ) );
	$check( 'marks: a Commercials learner cannot get a Physics mark', 0 === $r['saved'] && $r['errors'] );
	$r = BSA_Marks::save( $head, $f3, $from, 3, $s3['Physics'], array( $kim => array( 'exam' => '64' ) ) );
	$check( 'marks: a Sciences learner can', 1 === $r['saved'] );
	$check( 'report card subjects follow the stream (Eli 10, Fay all 13)', 10 === count( BSA_Reports::learner_subjects( $eli, $f3 ) ) && 13 === count( BSA_Reports::learner_subjects( $fay, $f3 ) ) );
	$unset = BSA_Streams::unset_counts( $from );
	$check( 'dashboard reminder: 1 learner in Form 3 A and 2 in Form 4 A without a stream', array( 'Form 3 A' => 1, 'Form 4 A' => 2 ) === $unset, wp_json_encode( $unset ) );

	wp_set_current_user( $head );
	$html = $render( array( 'BSA_Streams', 'render_page' ), array( 'page' => 'bsa-streams', 'section' => $f3, 'session' => $from ) );
	$check( 'Streams screen: subjects for everyone and per stream, each learner with a choice, counts', false !== strpos( $html, '<h3>Everyone</h3>' ) && false !== strpos( $html, '<li>Heritage Studies</li>' ) && false !== strpos( $html, 'name="stream[' . $eli . ']"' ) && false !== strpos( $html, 'Sciences: 1 · Commercials: 1 · Not set: 1' ) && false !== strpos( $html, 'Change the stream subjects' ), '' );
	if ( $bsa_capture ) {
		$captured['streams'] = $html;
	}

	// Rollover plan.
	$plan = BSA_Rollover::plan( $from, $to );
	$row  = function ( $id ) use ( $plan ) {
		foreach ( $plan as $c ) {
			if ( isset( $c['learners'][ $id ] ) ) {
				return $c['learners'][ $id ];
			}
		}
		return null;
	};
	$check( 'plan: defaults move up (Form 1–3) or complete (Form 4); already next year; no number', 'up' === $row( $ama )['default'] && 'up' === $row( $eli )['default'] && 'complete' === $row( $gar )['default'] && 'already' === $row( $jo )['status'] && 'no_number' === $row( $ivy )['status'] && 'Commercials' === $row( $eli )['stream'] );
	$acts    = array( $dar => 'repeat', $fay => 'leave', $han => 'repeat', $ivy => 'up' );
	$streams = array( $bon => 'Sciences', $chi => '' );
	$r       = BSA_Rollover::run( $from, $to, $acts, $streams, $head );
	$check( 'rollover: refused while a learner moving up has no admission number; nothing written', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Ivy Testroll has no admission number' ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE session_id = %d AND name LIKE %s AND ID <> %d", $to, '%Testroll', $jo2 ) ), is_wp_error( $r ) ? $r->get_error_message() : '' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'rollover: refused for a teacher and for a year that is not the next one', is_wp_error( BSA_Rollover::run( $from, $to, array(), array(), $teacher ) ) && is_wp_error( BSA_Rollover::run( $from, end( $sessions ), array(), array(), $head ) ) );

	$html = $render( array( 'BSA_Rollover', 'render_page' ), array( 'page' => 'bsa-rollover', 'from' => $from ) );
	$check( 'screen: classes with the next class, a choice per learner, streams into Form 3, "already" and confirmation', false !== strpos( $html, 'Form 2 A → Form 3 A' ) && false !== strpos( $html, 'Form 4 A → completes' ) && false !== strpos( $html, 'name="act[' . $ama . ']"' ) && false !== strpos( $html, 'name="stream[' . $bon . ']"' ) && false === strpos( $html, 'name="stream[' . $ama . ']"' ) && false !== strpos( $html, 'name="confirm"' ) && false === strpos( $html, 'name="act[' . $jo . ']"' ) && false !== strpos( $html, 'No admission number: give one before moving up.' ), '' );
	if ( $bsa_capture ) {
		$captured['rollover'] = $html;
	}

	$acts[ $ivy ] = 'leave';
	$enr_before   = (int) $wpdb->get_var( "SELECT MAX(CAST(enrollment_number AS UNSIGNED)) FROM {$p}wlsm_student_records" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$done         = BSA_Rollover::run( $from, $to, $acts, $streams, $head );
	$check( 'rollover: 7 moved (Ama, Bongi, Chido, Dara, Eli, Kim, Hana); 3 leaving (Fay, Gari, Ivy)', is_array( $done ) && 7 === $done['moved'] && 3 === $done['leaving'], wp_json_encode( $done ) );
	$batch = is_array( $done ) ? $done['batch'] : '';
	$links = get_option( BSA_Rollover::BATCHES )[ $batch ]['links'] ?? array();
	$new   = array_flip( $links );
	$rec   = function ( $id ) use ( $wpdb, $p ) {
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wlsm_student_records WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	};
	$na = $rec( $new[ $ama ] ?? 0 );
	$oa = $rec( $ama );
	$check( 'new year: Ama in Form 2 A next year with the same admission and roll number, a new enrollment number, boarder kept', $na && $f2 === (int) $na->section_id && $to === (int) $na->session_id && 'TEST-ROLL-A' === $na->admission_number && 'TEST-ROLL-A' === $na->roll_number && sprintf( '%06d', $enr_before + 1 ) === $na->enrollment_number && '2' === (string) $na->student_type && '1' === (string) $na->is_active, $na ? wp_json_encode( $na ) : 'missing' );
	$check( 'new year: logins moved to the new record; the old record keeps its year and stays visible', (int) $na->user_id === $fake_user && (int) $na->parent_user_id === $fake_parent && ! $oa->user_id && ! $oa->parent_user_id && '1' === (string) $oa->is_active && in_array( $ama, array_map( 'intval', wp_list_pluck( BSA_School::learners( $f1, $from ), 'ID' ) ), true ) );
	$check( 'new year: promotion link and admission documents moved', (int) $wpdb->get_var( $wpdb->prepare( "SELECT to_student_record FROM {$p}wlsm_promotions WHERE from_student_record = %d", $ama ) ) === (int) $na->ID && 9 === (int) ( BSA_Admission_Records::get_record( (int) $na->ID )['grade7_units'] ?? 0 ) && ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSA_Admission_Records::table() . ' WHERE student_record_id = %d', $ama ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	$nb   = (int) ( $new[ $bon ] ?? 0 );
	$nc   = (int) ( $new[ $chi ] ?? 0 );
	$ne   = (int) ( $new[ $eli ] ?? 0 );
	$nk   = (int) ( $new[ $kim ] ?? 0 );
	$nmap = BSA_Streams::subject_map( array( $nb, $nc, $ne, $nk ) );
	$check( 'into Form 3: Bongi Sciences (10 subjects), Chido later (every subject)', $f3 === (int) $rec( $nb )->section_id && 'Sciences' === BSA_Streams::stream_of( $f3, $nmap[ $nb ] ) && 10 === count( $nmap[ $nb ] ) && array() === $nmap[ $nc ] );
	$check( 'into Form 4: Eli keeps Commercials and Kim Sciences, on Form 4\'s own subjects', $f4 === (int) $rec( $ne )->section_id && 'Commercials' === BSA_Streams::stream_of( $f4, $nmap[ $ne ] ) && in_array( $s4['Combined Science'], $nmap[ $ne ], true ) && ! array_intersect( array_values( $s3 ), $nmap[ $ne ] ) && 'Sciences' === BSA_Streams::stream_of( $f4, $nmap[ $nk ] ) );
	$check( 'repeaters stay in their form; leavers and Form 4 completers get no new record', $f2 === (int) $rec( $new[ $dar ] ?? 0 )->section_id && $f4 === (int) $rec( $new[ $han ] ?? 0 )->section_id && ! isset( $new[ $fay ] ) && ! isset( $new[ $gar ] ) && ! isset( $new[ $ivy ] ) && ! isset( $new[ $jo ] ) );
	$check( 'parents: own the new record and, through the promotion, last year\'s (for past report cards)', array_map( 'intval', BSA_Access_Guard::owned_student_ids( $fake_parent ) ) == array( (int) $na->ID, $ama ) && in_array( $bon, BSA_Access_Guard::with_earlier_years( array( $nb ) ), true ), wp_json_encode( BSA_Access_Guard::owned_student_ids( $fake_parent ) ) ); // phpcs:ignore Universal.Operators.StrictComparisons
	$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $ama, 'session_id' => $from, 'term' => 3, 'form_teacher_comment' => '', 'principal_comment' => '', 'published_at' => current_time( 'mysql' ), 'published_by' => $head, 'updated_at' => current_time( 'mysql' ) ) );
	$cards = BSA_Family::published( BSA_Access_Guard::owned_student_ids( $fake_parent ) );
	$check( 'parents: last year\'s published report card still listed after the rollover', 1 === count( $cards ) && $ama === (int) $cards[0]->student_record_id && 'Form 1' === $cards[0]->class_label, wp_json_encode( $cards ) );
	$again = BSA_Rollover::run( $from, $to, array(), array(), $head );
	$check( 'rollover again: everyone already moved, nothing to do', is_wp_error( $again ) && 'already' === BSA_Rollover::plan( $from, $to )[ $f1 ]['learners'][ $ama ]['status'] );
	$html = $render( array( 'BSA_Rollover', 'render_page' ), array( 'page' => 'bsa-rollover', 'from' => $from, 'done' => $batch, 'moved' => '7', 'leaving' => '3' ) );
	$check( 'screen: success message and the rollover listed with an Undo button', false !== strpos( $html, '7 learners moved to' ) && false !== strpos( $html, 'value="' . $batch . '"' ) && false !== strpos( $html, '>Undo</button>' ) );
	if ( $bsa_capture ) {
		$captured['done'] = $html;
	}

	// Undo keeps records with something recorded.
	$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => current_time( 'Y-m-d' ), 'status' => 'p', 'student_record_id' => (int) $new[ $dar ], 'added_by' => $head ) );
	$check( 'undo: refused for the bursar', is_wp_error( BSA_Rollover::undo( $batch, $bursar ) ) );
	$undo = BSA_Rollover::undo( $batch, $head );
	$check( 'undo: 6 new records removed; Dara (register entry) kept', is_array( $undo ) && 6 === $undo['removed'] && array( 'Dara Testroll' ) === $undo['kept'], wp_json_encode( $undo ) );
	$oa = $rec( $ama );
	$check( 'undo: logins and admission documents back on the old record; promotion links removed', (int) $oa->user_id === $fake_user && (int) $oa->parent_user_id === $fake_parent && 9 === (int) ( BSA_Admission_Records::get_record( $ama )['grade7_units'] ?? 0 ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_promotions WHERE from_student_record = %d", $ama ) ) && null === $rec( (int) $na->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'undo: not twice', is_wp_error( BSA_Rollover::undo( $batch, $head ) ) );

	// Current year.
	$check( 'current year: refused for a teacher', ! BSA_Rollover::make_current( $to, $teacher ) && (string) $current_before === (string) get_option( 'wlsm_current_session' ) );
	update_user_meta( $head, 'wlsm_current_session', $from );
	$check( 'current year: switched by the school administrator, for everyone (personal year choice cleared)', BSA_Rollover::make_current( $to, $head ) && (string) $to === (string) get_option( 'wlsm_current_session' ) && '' === get_user_meta( $head, 'wlsm_current_session', true ) );

	wp_set_current_user( $teacher );
	$denied = array();
	foreach ( array( 'BSA_Rollover', 'BSA_Streams' ) as $class ) {
		try {
			$render( array( $class, 'render_page' ), array() );
		} catch ( RuntimeException $ex ) {
			$denied[] = $class;
		}
	}
	$check( 'screens: refused for a teacher', array( 'BSA_Rollover', 'BSA_Streams' ) === $denied );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	remove_filter( 'bsa_sm_rollover_transaction', '__return_false' );
	$_GET = array();
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	foreach ( $die_hooks as $hook ) {
		remove_filter( $hook, $die, 999 );
	}
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE name LIKE '%Testroll%'" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	+ ( get_option( BSA_Rollover::BATCHES, false ) === $batches_before ? 0 : 1 )
	+ ( (string) get_option( 'wlsm_current_session' ) === (string) $current_before ? 0 : 1 );
$check( 'rollback: no synthetic data left; rollover list and current year as before', 0 === $left, (string) $left );
if ( $bsa_capture ) {
	set_transient( 'bsa_capture_rollover', $captured, HOUR_IN_SECONDS );
}
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_rollover', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
