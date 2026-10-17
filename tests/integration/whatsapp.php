<?php
/**
 * Integration test for WhatsApp messages (plugin 0.16.0): numbers, who may send what,
 * which families a message applies to, consent, the wording, the sent log, contacts and
 * the screens. Run on the live site through Novamira `execute-php` (send everything after
 * the opening tag).
 *
 * The tables are created first, outside the transaction. Four synthetic Form 1 A learners
 * ("Testwa") with an absence today, a published report card and fee balances are written
 * inside a transaction and rolled back; the settings option is restored. Nothing is sent:
 * the screen only builds wa.me links. Results are also kept for an hour in the
 * `bsa_test_whatsapp` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
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
$die_hooks = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler' );
foreach ( $die_hooks as $hook ) {
	add_filter( $hook, $die, 999 );
}
$render = function ( $get ) {
	$_GET = $get;
	ob_start();
	try {
		BSA_WhatsApp::render_page();
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
$bursar  = $user( 'bursar' );
$f1a     = 0;
$f2a     = 0;
foreach ( BSA_School::sections() as $s ) {
	if ( 'Form 1 A' === $s->title ) {
		$f1a = (int) $s->ID;
	} elseif ( 'Form 2 A' === $s->title ) {
		$f2a = (int) $s->ID;
	}
}
$saved_settings = get_option( BSA_WhatsApp::OPTION, null );

BSA_WhatsApp::maybe_migrate();
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p . 'bsa_wa_' ) . '%' ) );
$check( 'tables: contacts and log created; schema version 1', 2 === count( $tables ) && '1' === get_option( 'bsa_sm_whatsapp_db_version' ), implode( ', ', $tables ) );

// Numbers (no database).
$cases = array( '0772 609 503' => '263772609503', '+263 77 260 9503' => '263772609503', '772609503' => '263772609503', '00263772609503' => '263772609503', '+27 82 123 4567' => '27821234567', '0242123456' => '', '+263 242 123456' => '', '07726095' => '' );
$bad   = array();
foreach ( $cases as $in => $want ) {
	if ( BSA_WhatsApp::normalise( $in ) !== $want ) {
		$bad[] = $in . ' → ' . BSA_WhatsApp::normalise( $in );
	}
}
$check( 'numbers: Zimbabwe mobiles in any form → 2637…; foreign with +; landlines and short numbers refused', ! $bad, implode( ' | ', $bad ) );
$check( 'numbers: shown as +263 77 260 9503; school number from the website by default', '+263 77 260 9503' === BSA_WhatsApp::display( '263772609503' ) && '263772609503' === BSA_WhatsApp::settings()['number'] || null !== $saved_settings );
$check( 'link: wa.me with the message encoded', 'https://wa.me/263772609503?text=Hi%20%26%20bye%0Aok' === BSA_WhatsApp::link( '263772609503', "Hi & bye\nok" ) );

// Who may send what.
$check( 'school administrator: every type, every class', 3 === count( BSA_WhatsApp::user_types( $head ) ) && count( BSA_WhatsApp::sections_for( $head, 'fees' ) ) === count( BSA_School::sections() ) );
$check( 'class teacher: report card and absence for his own class only; no fee reminders', array( 'report', 'absence' ) === BSA_WhatsApp::user_types( $teacher ) && array( $f1a ) === BSA_WhatsApp::sections_for( $teacher, 'absence' ) && array( $f1a ) === BSA_WhatsApp::contact_sections( $teacher ), wp_json_encode( BSA_WhatsApp::user_types( $teacher ) ) );
$check( 'bursar: fee reminders only, no contacts', array( 'fees' ) === BSA_WhatsApp::user_types( $bursar ) && ! BSA_WhatsApp::contact_sections( $bursar ), wp_json_encode( BSA_WhatsApp::user_types( $bursar ) ) );
$check( 'nobody else: no access', ! BSA_WhatsApp::user_can_view( 0 ) && ! BSA_WhatsApp::user_can_view( $user( 'bsa001.parent' ) ) );

$today   = current_time( 'Y-m-d' );
$session = BSA_WhatsApp::session();
$before  = BSA_WhatsApp::unsent_absences( $today );
$wpdb->query( 'START TRANSACTION' );
try {
	remove_all_filters( 'query' );
	$ids = array();
	$mk  = function ( $name, $adm, $extra ) use ( $wpdb, $p, $f1a, $session, &$ids ) {
		$wpdb->insert( "{$p}wlsm_student_records", array_merge( array( 'name' => $name, 'gender' => 'female', 'admission_number' => $adm, 'enrollment_number' => $adm, 'roll_number' => $adm, 'section_id' => $f1a, 'session_id' => $session, 'is_active' => 1, 'created_at' => current_time( 'mysql' ) ), $extra ) );
		$ids[ $adm ] = (int) $wpdb->insert_id;
		return $ids[ $adm ];
	};
	$a = $mk( 'Tariro Testwa', 'TESTWA1', array( 'mother_name' => 'Memory Testwa', 'mother_phone' => '0772 111 001', 'father_name' => 'Tendai Testwa', 'father_phone' => '0772 111 002' ) );
	$b = $mk( 'Tinashe Testwa', 'TESTWA2', array( 'father_name' => 'Farai Testwa', 'father_phone' => '+263 71 222 0003' ) );
	$c = $mk( 'Chipo Testwa', 'TESTWA3', array( 'mother_name' => 'Rudo Testwa', 'mother_phone' => '0773 333 004' ) );
	$d = $mk( 'Danai Testwa', 'TESTWA4', array( 'mother_name' => 'Sekai Testwa', 'mother_phone' => '0242 123456' ) );
	foreach ( array( $a, $b, $c, $d ) as $sid ) {
		$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => $today, 'status' => $sid === $c ? 'p' : 'a', 'student_record_id' => $sid, 'subject_id' => null, 'added_by' => $teacher, 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( "{$p}wlsm_invoices", array( 'invoice_number' => 'TEST-WA-' . $sid, 'label' => 'Term 3 Fees', 'amount' => 250, 'discount' => 0, 'date_issued' => $today, 'due_date' => $today, 'status' => 'unpaid', 'student_record_id' => $sid, 'added_by' => $head ) );
	}
	$wpdb->insert( "{$p}wlsm_payments", array( 'receipt_number' => 'TEST-WA', 'amount' => 125, 'payment_method' => 'cash', 'invoice_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_invoices WHERE invoice_number = %s", 'TEST-WA-' . $a ) ), 'student_record_id' => $a, 'school_id' => 1, 'created_at' => current_time( 'mysql' ), 'added_by' => $bursar ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->insert( "{$p}wlsm_payments", array( 'receipt_number' => 'TEST-WA', 'amount' => 250, 'payment_method' => 'cash', 'invoice_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_invoices WHERE invoice_number = %s", 'TEST-WA-' . $c ) ), 'student_record_id' => $c, 'school_id' => 1, 'created_at' => current_time( 'mysql' ), 'added_by' => $bursar ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $a, 'session_id' => $session, 'term' => 2, 'form_teacher_comment' => '', 'conduct' => '', 'principal_comment' => '', 'published_at' => current_time( 'mysql' ), 'published_by' => $head, 'updated_at' => current_time( 'mysql' ) ) );
	$wpdb->insert( BSA_Marks::reports_table(), array( 'student_record_id' => $b, 'session_id' => $session, 'term' => 2, 'form_teacher_comment' => '', 'conduct' => '', 'principal_comment' => '', 'published_at' => null, 'updated_at' => current_time( 'mysql' ) ) );
	BSA_School::flush();
	$mine = function ( array $rows ) use ( $ids ) {
		$out = array();
		foreach ( $rows as $r ) {
			if ( in_array( (int) $r['id'], $ids, true ) ) {
				$out[ $r['learner']->admission_number ] = $r;
			}
		}
		ksort( $out );
		return $out;
	};

	// Who a message applies to.
	$abs = $mine( BSA_WhatsApp::rows( 'absence', array( $f1a ), $today ) );
	$check( 'absent today: the three marked absent, not the one present', array( 'TESTWA1', 'TESTWA2', 'TESTWA4' ) === array_keys( $abs ), implode( ',', array_keys( $abs ) ) );
	$rep = $mine( BSA_WhatsApp::rows( 'report', array( $f1a ), $session . '-2' ) );
	$check( 'report card ready: only the published report card', array( 'TESTWA1' ) === array_keys( $rep ) );
	$fee = $mine( BSA_WhatsApp::rows( 'fees', array( $f1a ), $today ) );
	$check( 'fee reminder: learners with a balance (paid in full left out), with the balance', array( 'TESTWA1', 'TESTWA2', 'TESTWA4' ) === array_keys( $fee ) && 125.0 === (float) $fee['TESTWA1']['extra']['balance'], implode( ',', array_keys( $fee ) ) );

	// Recipient and consent.
	$check( 'recipient: the mother\'s number first, else the father\'s; landline means no number', '263772111001' === $abs['TESTWA1']['recipient']['number'] && 'Memory Testwa' === $abs['TESTWA1']['recipient']['name'] && '263712220003' === $abs['TESTWA2']['recipient']['number'] && '' === $abs['TESTWA4']['recipient']['number'] );
	$check( 'consent: nobody can be messaged before consent is recorded', 'consent' === $abs['TESTWA1']['status'] && 'consent' === $abs['TESTWA2']['status'] && 'no_number' === $abs['TESTWA4']['status'] );
	$r = BSA_WhatsApp::save_contacts( $teacher, $f1a, array( 'TESTWA1' => array( 'number' => '0772 111 001', 'name' => 'Memory Testwa', 'consent' => 'yes' ), 'TESTWA2' => array( 'number' => '', 'name' => '', 'consent' => 'no' ), 'TESTWA4' => array( 'number' => '0242 123', 'name' => 'Sekai Testwa', 'consent' => 'yes' ) ) );
	$check( 'contacts: class teacher saves his class; a landline is refused and named', ! is_wp_error( $r ) && 2 === $r['saved'] && array( 'Danai Testwa' ) === $r['invalid'], wp_json_encode( $r ) );
	$check( 'contacts: another class is refused for the class teacher', is_wp_error( BSA_WhatsApp::save_contacts( $teacher, $f2a, array() ) ) && is_wp_error( BSA_WhatsApp::save_contacts( $bursar, $f1a, array() ) ) );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSA_WhatsApp::contacts_table() . ' WHERE admission_number = %s', 'TESTWA1' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$check( 'contacts: consent kept with the date and who recorded it', $row && 'yes' === $row->consent && $row->consent_at && (int) $row->updated_by === $teacher && '263772111001' === $row->number );
	$abs = $mine( BSA_WhatsApp::rows( 'absence', array( $f1a ), $today ) );
	$check( 'consent: "yes" is ready to send; "no" is never offered', 'ready' === $abs['TESTWA1']['status'] && 'refused' === $abs['TESTWA2']['status'] );
	$check( 'dashboard: absent learners whose parents can be told, not told yet', $before + 1 === BSA_WhatsApp::unsent_absences( $today ), ( $before + 1 ) . ' vs ' . BSA_WhatsApp::unsent_absences( $today ) );

	// Wording.
	$m = $abs['TESTWA1']['message'];
	$check( 'absence message: parent, first name, class, day, school number', 0 === strpos( $m, 'Dear Memory Testwa,' ) && false !== strpos( $m, 'Tariro (Form 1 A) was marked absent from school today, ' . date_i18n( 'l j F Y', strtotime( $today ) ) ) && false !== strpos( $m, '+263 77 260 9503' ), $m );
	BSA_WhatsApp::save_contacts( $head, $f1a, array( 'TESTWA1' => array( 'number' => '0772 111 001', 'name' => 'Memory Testwa', 'consent' => 'yes' ) ) );
	$rep = $mine( BSA_WhatsApp::rows( 'report', array( $f1a ), $session . '-2' ) );
	$m   = $rep['TESTWA1']['message'];
	$check( 'report card message: term and year, a link to that learner\'s report cards, no marks', false !== strpos( $m, "Tariro's report card for Term 2" ) && false !== strpos( $m, 'student_id=' . $a ) && ! preg_match( '/\d+(\.\d)?%/', $m ), $m );
	$fee = $mine( BSA_WhatsApp::rows( 'fees', array( $f1a ), $today ) );
	$check( 'fee message: the balance', false !== strpos( $fee['TESTWA1']['message'], 'USD 125.00' ) );

	// Sending (recording).
	update_option( BSA_WhatsApp::OPTION, array( 'fees' => '0' ), false );
	$check( 'record: refused while fee reminders are off, even for the school administrator', is_wp_error( BSA_WhatsApp::record( $head, 'fees', $today, $a ) ) );
	$check( 'record: refused for a parent without consent', is_wp_error( BSA_WhatsApp::record( $head, 'absence', $today, $b ) ) );
	$check( 'record: refused for the bursar (absence) and the class teacher in another class', is_wp_error( BSA_WhatsApp::record( $bursar, 'absence', $today, $a ) ) );
	$r = BSA_WhatsApp::record( $teacher, 'absence', $today, $a );
	$check( 'record: the class teacher sends an absence message; logged with the text and number', ! is_wp_error( $r ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSA_WhatsApp::log_table() . " WHERE type = 'absence' AND context = %s AND student_record_id = %d AND sent_by = %d AND number = '263772111001' AND message LIKE %s", $today, $a, $teacher, 'Dear Memory%' ) ), is_wp_error( $r ) ? $r->get_error_message() : '' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$abs = $mine( BSA_WhatsApp::rows( 'absence', array( $f1a ), $today ) );
	$check( 'record: shown as sent (and no longer counted on the dashboard)', 'sent' === $abs['TESTWA1']['status'] && $before === BSA_WhatsApp::unsent_absences( $today ) );
	update_option( BSA_WhatsApp::OPTION, array( 'fees' => '1' ), false );
	$check( 'record: fee reminder by the bursar once switched on', ! is_wp_error( BSA_WhatsApp::record( $bursar, 'fees', $today, $a ) ) );

	// Screens.
	wp_set_current_user( $head );
	$html = $render( array( 'tab' => 'send', 'type' => 'absence', 'section' => $f1a, 'date' => $today ) );
	$check( 'screen (school administrator): absence list with wa.me link and tiles', false !== strpos( $html, 'https://wa.me/263772111001?text=Dear%20Memory%20Testwa' ) && false !== strpos( $html, 'Send again' ) && false !== strpos( $html, 'Consent not recorded' ) && false !== strpos( $html, 'Parent said no' ) && false !== strpos( $html, 'No WhatsApp number' ) );
	$check( 'screen (school administrator): Settings tab; contacts form', false !== strpos( $html, 'tab=settings' ) && false !== strpos( $render( array( 'tab' => 'contacts', 'section' => $f1a ) ), 'contact[TESTWA1][consent]' ) );
	update_option( BSA_WhatsApp::OPTION, array( 'fees' => '0' ), false );
	$html = $render( array( 'tab' => 'send', 'type' => 'fees', 'section' => $f1a ) );
	$check( 'screen: fee reminders off → preview only, no links', false !== strpos( $html, 'preview only' ) && false !== strpos( $html, 'Preview only' ) && false === strpos( $html, 'https://wa.me/' ) );
	$html = $render( array( 'tab' => 'log' ) );
	$check( 'screen: sent messages log', false !== strpos( $html, 'Tariro Testwa' ) && false !== strpos( $html, '+263 77 211 1001' ) );
	wp_set_current_user( $teacher );
	$html = $render( array( 'tab' => 'send', 'type' => 'fees' ) );
	$check( 'screen (class teacher): no Settings, no fee type, own class only', false === strpos( $html, 'tab=settings' ) && false === strpos( $html, 'value="fees"' ) && false === strpos( $html, 'Form 2 A' ) );
	wp_set_current_user( $bursar );
	$html = $render( array() );
	$check( 'screen (bursar): fee reminders only, no contacts tab', false !== strpos( $html, 'value="fees"' ) && false === strpos( $html, 'value="absence"' ) && false === strpos( $html, 'tab=contacts' ) );
	wp_set_current_user( $user( 'bsa001.parent' ) );
	try {
		$render( array() );
		$check( 'screen: refused for a parent', false );
	} catch ( RuntimeException $e ) {
		$check( 'screen: refused for a parent', false !== strpos( $e->getMessage(), 'permission' ) );
	}
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	if ( null === $saved_settings ) {
		delete_option( BSA_WhatsApp::OPTION );
	} else {
		update_option( BSA_WhatsApp::OPTION, $saved_settings, false );
	}
	$_GET = array();
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	foreach ( $die_hooks as $hook ) {
		remove_filter( $hook, $die, 999 );
	}
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE name LIKE '%Testwa%'" ) + (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_WhatsApp::contacts_table() . " WHERE admission_number LIKE 'TESTWA%'" ) + (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_WhatsApp::log_table() . " WHERE admission_number LIKE 'TESTWA%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
$check( 'rollback: no synthetic data left', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_whatsapp', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
