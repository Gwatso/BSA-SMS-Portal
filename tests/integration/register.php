<?php
/**
 * Integration test for BSA_School, BSA_Terms and BSA_Register (term dates and the class
 * register), run on the live site through Novamira `execute-php` (send everything after
 * the opening tag).
 *
 * Three synthetic learners in Form 1 A (day boy, day girl, boarder girl), term dates for
 * 2026 and one week of register marks are written inside a transaction and rolled back.
 * Expected totals are worked out by hand in the comments.
 *
 * Before activation, set $bsa_stage to the staged plugin folder to test that copy.
 * Results are also kept for an hour in the `bsa_test_register` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
foreach ( array( 'BSA_School' => 'class-bsa-school.php', 'BSA_Terms' => 'class-bsa-terms.php', 'BSA_Register' => 'class-bsa-register.php' ) as $class => $file ) {
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

// The checks table is created by BSA_Register::maybe_migrate() on admin_init; DDL commits,
// so create it before the transaction (it is the real table the plugin will use).
BSA_Register::maybe_migrate();

// Term dates and register checks the owner may already have entered must be left as they
// were.
$terms_before  = get_option( BSA_Terms::OPTION, false );
$checks_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_Register::checks_table() );

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
	$check( 'fixtures: owner, school administrator, teacher and bursar logins', $owner && $head && $teacher && $bursar );

	// Rights.
	BSA_School::flush();
	$all = array_map( 'intval', wp_list_pluck( BSA_School::sections(), 'ID' ) );
	$check( 'rights: owner and school administrator are school admins', BSA_School::is_school_admin( $owner ) && BSA_School::is_school_admin( $head ) );
	$check( 'rights: teacher and bursar are not', ! BSA_School::is_school_admin( $teacher ) && ! BSA_School::is_school_admin( $bursar ) );
	$check( 'rights: school administrator sees every class register', BSA_School::allowed_sections( $head, 'manage_attendance' ) === $all );
	$check( 'rights: teacher sees only Form 1 A', array( 4 ) === BSA_School::allowed_sections( $teacher, 'manage_attendance' ), wp_json_encode( BSA_School::allowed_sections( $teacher, 'manage_attendance' ) ) );
	$check( 'rights: bursar sees no class register', array() === BSA_School::allowed_sections( $bursar, 'manage_attendance' ) );
	$check( 'names: last word is the surname', array( 'Moyo', 'Tendai Blessing' ) === BSA_School::split_name( ' Tendai  Blessing Moyo ' ) );

	// Term dates.
	list( , $e ) = BSA_Terms::validate( array( 1 => array( 'start' => '2026-01-13', 'end' => '' ) ) );
	$check( 'terms: missing end refused', 1 === count( $e ) );
	list( , $e ) = BSA_Terms::validate( array( 1 => array( 'start' => '2026-04-01', 'end' => '2026-01-13' ) ) );
	$check( 'terms: end before start refused', 1 === count( $e ) );
	list( , $e ) = BSA_Terms::validate( array( 1 => array( 'start' => '2026-01-13', 'end' => '2026-04-30' ), 2 => array( 'start' => '2026-04-20', 'end' => '2026-08-06' ) ) );
	$check( 'terms: overlapping terms refused', 1 === count( $e ) );
	list( , $e ) = BSA_Terms::validate( array( 1 => array( 'start' => '2025-12-01', 'end' => '2026-04-02' ) ), '2026-01-01', '2026-12-31' );
	$check( 'terms: dates outside the school year refused', 1 === count( $e ) );
	list( , $e ) = BSA_Terms::validate( array( 1 => array( 'start' => '2026-02-30', 'end' => '2026-04-02' ) ) );
	$check( 'terms: impossible date refused', 1 === count( $e ) );
	$input             = array( 1 => array( 'start' => '2026-01-13', 'end' => '2026-04-02' ), 2 => array( 'start' => '2026-05-05', 'end' => '2026-08-06' ), 3 => array( 'start' => '2026-09-08', 'end' => '2026-12-03' ) );
	list( $terms, $e ) = BSA_Terms::validate( $input, '2026-01-01', '2026-12-31' );
	$check( 'terms: valid 2026 dates accepted', ! $e && 3 === count( $terms ) );
	BSA_Terms::save( 1, $terms );
	BSA_Terms::save( 2, array( 1 => array( 'start' => '2027-01-12', 'end' => '2027-04-01' ) ) );
	$check( 'terms: read back', array( 'start' => '2026-05-05', 'end' => '2026-08-06' ) === BSA_Terms::get( 1, 2 ) );
	$cur = BSA_Terms::current( '2026-06-10' );
	$check( 'terms: 10 Jun 2026 is in Term 2', $cur && 1 === $cur['session'] && 2 === $cur['term'] );
	$cur = BSA_Terms::current( '2026-04-20' );
	$check( 'terms: holiday after Term 1 belongs to Term 1', $cur && 1 === $cur['term'] );
	$check( 'terms: next term after Term 1 starts 5 May 2026', '2026-05-05' === BSA_Terms::next_start( 1, 1 ) );
	$check( 'terms: next term after Term 3 is 2027 Term 1', '2027-01-12' === BSA_Terms::next_start( 1, 3 ) );
	$weeks = BSA_Terms::week_endings( '2026-01-13', '2026-04-02' );
	$check( 'terms: Term 1 has 12 weeks ending Fri 16 Jan … Fri 3 Apr', 12 === count( $weeks ) && '2026-01-16' === $weeks[0] && '2026-04-03' === end( $weeks ), count( $weeks ) . ' ' . $weeks[0] . '…' . end( $weeks ) );
	$check( 'terms: a term starting on Saturday begins with the next Friday', array( '2026-01-16' ) === BSA_Terms::week_endings( '2026-01-10', '2026-01-12' ) );

	// Three synthetic learners in Form 1 A (section 4).
	$wpdb->insert( "{$p}wlsm_student_type", array( 'label' => 'Boarder (test)', 'created_at' => current_time( 'mysql' ) ) );
	$boarder_type = (int) $wpdb->insert_id;
	$mk           = function ( $name, $gender, $type ) use ( $wpdb, $p ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'gender' => $gender, 'enrollment_number' => 'TEST-REG', 'admission_number' => 'TEST-REG', 'session_id' => 1, 'section_id' => 4, 'is_active' => 1, 'student_type' => $type ) );
		return (int) $wpdb->insert_id;
	};
	$day_boy      = $mk( 'Tatenda Test Alpha', 'male', '' );
	$day_girl     = $mk( 'Rufaro Test Bravo', 'female', '' );
	$boarder_girl = $mk( 'Nyasha Test Charlie', 'female', (string) $boarder_type );
	$check( 'fixture: 3 learners', $day_boy && $day_girl && $boarder_girl );
	$check( 'residence: student type containing "Board" is a boarder', 'boarder' === BSA_School::residence( (string) $boarder_type ) && 'day' === BSA_School::residence( '' ) );

	// Week ending Fri 23 Jan 2026. Mon p,p,a; Tue p,l,p; Wed h,h,h; Thu no register; Fri p,a,p.
	$marks = array(
		'2026-01-19' => array( 'p', 'p', 'a' ),
		'2026-01-20' => array( 'p', 'l', 'p' ),
		'2026-01-21' => array( 'h', 'h', 'h' ),
		'2026-01-23' => array( 'p', 'a', 'p' ),
	);
	foreach ( $marks as $date => $statuses ) {
		foreach ( array( $day_boy, $day_girl, $boarder_girl ) as $i => $sid ) {
			$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => $date, 'status' => $statuses[ $i ], 'student_record_id' => $sid, 'added_by' => $teacher, 'created_at' => current_time( 'mysql' ) ) );
		}
	}
	// A subject lesson register on Thursday must not count as the daily register.
	$subject = (int) $wpdb->get_var( "SELECT s.ID FROM {$p}wlsm_subjects s JOIN {$p}wlsm_sections se ON se.class_school_id = s.class_school_id WHERE se.ID = 4 LIMIT 1" );
	$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => '2026-01-22', 'status' => 'p', 'student_record_id' => $day_boy, 'subject_id' => $subject, 'added_by' => $teacher, 'created_at' => current_time( 'mysql' ) ) );

	$week = BSA_Register::week( 4, 1, '2026-01-23' );
	$mine = array_values( array_filter( $week['rows'], function ( $r ) use ( $day_boy, $day_girl, $boarder_girl ) { return in_array( (int) $r->ID, array( $day_boy, $day_girl, $boarder_girl ), true ); } ) );
	$by   = array();
	foreach ( $mine as $r ) {
		$by[ (int) $r->ID ] = $r;
	}
	$check( 'week: days are Mon 19 … Fri 23 Jan', array( '2026-01-19', '2026-01-20', '2026-01-21', '2026-01-22', '2026-01-23' ) === $week['days'] );
	$check( 'week: day boy p,p,h,–,p → total 3', array( 'p', 'p', 'h', '', 'p' ) === $by[ $day_boy ]->days && 3 === $by[ $day_boy ]->total, wp_json_encode( $by[ $day_boy ]->days ) );
	$check( 'week: day girl p,l,h,–,a → total 2', 2 === $by[ $day_girl ]->total );
	$check( 'week: boarder girl a,p,h,–,p → total 2', 2 === $by[ $boarder_girl ]->total );
	$only = function ( $key ) use ( $week ) {
		return $week[ $key ];
	};
	// The week totals include any real learners in Form 1 A, so compare the synthetic part only.
	$real = array_filter( $week['rows'], function ( $r ) use ( $mine ) { return ! in_array( $r, $mine, true ); } );
	$check( 'week: no real learners in Form 1 A distort the footer', ! $real, count( $real ) . ' real learners' );
	if ( ! $real ) {
		$check( 'week: number absent per day 1,0,0,0,1', array( 1, 0, 0, 0, 1 ) === $only( 'absent' ), wp_json_encode( $only( 'absent' ) ) );
		$check( 'week: actual attendance per day 2,3,0,0,2', array( 2, 3, 0, 0, 2 ) === $only( 'actual' ), wp_json_encode( $only( 'actual' ) ) );
		$check( 'week: possible attendances per day 3,3,0,0,3', array( 3, 3, 0, 0, 3 ) === $only( 'possible' ), wp_json_encode( $only( 'possible' ) ) );
		$check( 'week: weekly total of actual attendances 7', 7 === $week['weekly_total'] );
	}
	$ids = array( $day_boy, $day_girl, $boarder_girl );
	$check( 'school days: Mon, Tue, Fri (holiday and Thursday excluded)', array( '2026-01-19', '2026-01-20', '2026-01-23' ) === BSA_Register::school_days( $ids, '2026-01-13', '2026-04-02' ) );
	$check( 'report: day girl attended 2 of 3 in Term 1', array( 'attended' => 2, 'possible' => 3 ) === BSA_Register::for_report( $day_girl, 1, 1 ) );
	$check( 'report: no term dates → no attendance line', null === BSA_Register::for_report( $day_girl, 2, 3 ) );

	if ( ! $real ) {
		$sum = BSA_Register::summary( 4, 1 );
		$t1  = $sum['terms'][1];
		$check( 'summary: Term 1 day boy 3, day girl 2, boarder girl 2, boarder boy 0', 3 === $t1['day_boy'] && 2 === $t1['day_girl'] && 2 === $t1['boarder_girl'] && 0 === $t1['boarder_boy'], wp_json_encode( $t1 ) );
		$check( 'summary: 3 school days, total 7, average 2.3', 3 === $sum['school_days'] && 7 === $sum['total'] && 2.3 === $sum['average'], $sum['school_days'] . '/' . $sum['total'] . '/' . $sum['average'] );
		$check( 'summary: Term 2 and 3 empty but set', 0 === $sum['terms'][2]['school_days'] && array() === $sum['missing_terms'] );
	}

	// Weekly check by the head.
	$r = BSA_Register::record_check( 4, 1, '2026-01-23', $teacher );
	$check( 'check: teacher cannot record a check', is_wp_error( $r ) );
	$r = BSA_Register::record_check( 4, 1, '2026-01-22', $head );
	$check( 'check: a Thursday is refused', is_wp_error( $r ) );
	$future = gmdate( 'Y-m-d', strtotime( 'friday +3 weeks', strtotime( current_time( 'Y-m-d' ) ) ) );
	$r      = BSA_Register::record_check( 4, 1, $future, $head );
	$check( 'check: a week not yet started is refused', is_wp_error( $r ), $future );
	$r = BSA_Register::record_check( 4, 1, '2026-01-23', $head );
	$check( 'check: school administrator records the check', true === $r );
	BSA_Register::record_check( 4, 1, '2026-01-23', $owner );
	$rows = BSA_Register::checks( 4, '2026-01-13', '2026-04-02' );
	$check( 'check: one check per week, latest checker kept', 1 === count( $rows ) && (int) $rows['2026-01-23']->checked_by === $owner );

	// Screen state and pages as the teacher.
	BSA_School::flush();
	$s = BSA_Register::state( array( 'section' => 6, 'session' => 1, 'term' => 1, 'week' => '2026-01-23' ), $teacher );
	$check( 'screen: teacher asking for another class gets Form 1 A', 4 === $s['section'] );
	$s = BSA_Register::state( array( 'section' => 4, 'session' => 1, 'term' => 9, 'week' => 'x' ), $teacher );
	$check( 'screen: bad term and week fall back to valid ones', in_array( $s['term'], array( 1, 2, 3 ), true ) && in_array( $s['week'], $s['weeks'], true ) );
	wp_set_current_user( $teacher );
	foreach ( array( 'register', 'details', 'checks', 'summary' ) as $tab ) {
		$_GET = array( 'page' => BSA_Register::PAGE, 'tab' => $tab, 'section' => 4, 'session' => 1, 'term' => 1, 'week' => '2026-01-23' );
		ob_start();
		BSA_Register::render_page();
		$html = ob_get_clean();
		$want = array( 'register' => 'Weekly total of actual attendances', 'details' => 'TEST', 'checks' => 'Week ending', 'summary' => 'Average daily attendance' )[ $tab ];
		$check( "page: teacher sees the $tab tab", false !== stripos( $html, $want ) && false !== strpos( $html, 'Form 1 A' ) );
		if ( 'register' === $tab ) {
			$check( 'page: teacher gets no "record check" button', false === strpos( $html, 'bsa_register_check' ) );
		}
	}
	$_GET = array();
	wp_set_current_user( $head );
	$_GET = array( 'page' => BSA_Register::PAGE, 'tab' => 'checks', 'section' => 4, 'session' => 1, 'term' => 1 );
	ob_start();
	BSA_Register::render_page();
	$html = ob_get_clean();
	$_GET = array();
	$check( 'page: school administrator gets "record check" buttons for past weeks', false !== strpos( $html, 'bsa_register_check' ) );
	wp_set_current_user( $owner );
	ob_start();
	BSA_Terms::render_page();
	$html = ob_get_clean();
	$check( 'page: School Terms shows 2026 and 2027 with saved dates', false !== strpos( $html, '2026-05-05' ) && false !== strpos( $html, '2027' ) );
} catch ( Throwable $e ) {
	$check( 'no unexpected error', false, get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	$_GET = array();
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number = 'TEST-REG'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_type WHERE label = 'Boarder (test)'" )
	+ (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_Register::checks_table() ) - $checks_before
	+ ( get_option( BSA_Terms::OPTION, false ) === $terms_before ? 0 : 1 );
$check( 'rollback: no synthetic learners, types or checks left; term dates and checks as before', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_register', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
