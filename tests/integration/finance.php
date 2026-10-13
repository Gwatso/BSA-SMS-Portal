<?php
/**
 * Integration test for BSA_Finance (fees and payments), run on the live site through
 * Novamira `execute-php` (send everything after the opening tag).
 *
 * A synthetic learner with three term invoices that share one number (as Weblizar's
 * admission screen creates them) is added inside a transaction; payments go through
 * Weblizar's real payment handler as the bursar; everything is rolled back.
 *
 * Weblizar's payment handlers issue their own COMMIT/ROLLBACK; the test turns those
 * into no-ops while a handler runs so the outer transaction stays open.
 *
 * Before activation, set $bsa_stage to the staged plugin folder to test that copy.
 * Results are also kept for an hour in the `bsa_test_finance` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
if ( ! class_exists( 'BSA_Finance' ) && $bsa_stage ) {
	require_once $bsa_stage . '/includes/class-bsa-finance.php';
}
if ( ! class_exists( 'WLSM_Staff_Accountant' ) ) {
	require_once WLSM_PLUGIN_DIR_PATH . 'admin/admin.php';
}

$p       = $wpdb->prefix;
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};

// Handlers end in wp_send_json_*() -> wp_die(); capture the JSON instead of exiting.
$last_json   = null;
$die_handler = function () {
	return function ( $message ) {
		throw new RuntimeException( 'wp_die' );
	};
};
$die_filters = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler' );
foreach ( $die_filters as $die_filter ) {
	add_filter( $die_filter, $die_handler );
}
add_filter( 'wp_doing_ajax', '__return_true' );
// Weblizar's payment handlers run COMMIT/ROLLBACK themselves, which would end this
// test's transaction and keep the synthetic data. Neutralise them while a handler runs.
$tx_guard = function ( $query ) {
	return preg_match( '/^\s*(START\s+TRANSACTION|BEGIN|COMMIT|ROLLBACK)\b\s*;?\s*$/i', $query ) ? 'SELECT 1' : $query;
};
$run = function ( $user_id, $callback, array $post ) use ( &$last_json, $tx_guard ) {
	add_filter( 'query', $tx_guard, 0 );
	wp_cache_delete( 'wlsm_user_info' );
	wp_set_current_user( $user_id );
	$_POST     = $post;
	$_REQUEST  = $post;
	$last_json = null;
	$level     = ob_get_level();
	ob_start();
	ob_start();
	$outcome = 'returned';
	try {
		call_user_func( $callback );
	} catch ( RuntimeException $e ) {
		$outcome = 'died';
	}
	$out = '';
	while ( ob_get_level() > $level ) {
		$out = ob_get_clean() . $out;
	}
	remove_filter( 'query', $tx_guard, 0 );
	$_POST     = array();
	$_REQUEST  = array();
	$last_json = json_decode( trim( $out ), true );
	return $outcome;
};

$original = get_current_user_id();
$notices  = array();
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( &$notices ) {
		$notices[] = basename( $errfile ) . ':' . $errline . ' ' . $errstr;
		return true;
	}
);
add_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );

$wpdb->query( 'START TRANSACTION' );
try {
	$admin   = (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$p}capabilities' AND meta_value LIKE '%administrator%' ORDER BY user_id LIMIT 1" );
	$bursar  = (int) $wpdb->get_var( "SELECT s.user_id FROM {$p}wlsm_staff s JOIN {$p}wlsm_admins a ON a.staff_id = s.ID JOIN {$p}wlsm_roles r ON r.ID = a.role_id WHERE r.name = 'Bursar' AND s.user_id IS NOT NULL LIMIT 1" );
	$teacher = (int) $wpdb->get_var( "SELECT s.user_id FROM {$p}wlsm_staff s JOIN {$p}wlsm_admins a ON a.staff_id = s.ID JOIN {$p}wlsm_roles r ON r.ID = a.role_id WHERE r.name = 'Teacher' AND s.user_id IS NOT NULL LIMIT 1" );
	$learner = (int) $wpdb->get_var( "SELECT user_id FROM {$p}wlsm_student_records WHERE user_id IS NOT NULL LIMIT 1" );
	$check( 'fixtures: administrator, bursar, teacher and a learner login exist', $admin && $bursar && $teacher && $learner );

	// Permissions for the report screen.
	$check( 'reports: administrator', BSA_Finance::user_can_view( $admin ) );
	$check( 'reports: bursar', BSA_Finance::user_can_view( $bursar ) );
	$check( 'reports: not teacher', ! BSA_Finance::user_can_view( $teacher ) );
	$check( 'reports: not learner', ! BSA_Finance::user_can_view( $learner ) );
	$check( 'reports: not signed-out visitor', ! BSA_Finance::user_can_view( 0 ) );

	// Payment rules.
	$check( 'rule: cash accepted', array() === BSA_Finance::validate_payment( 'cash', '' ) );
	$check( 'rule: amount within the balance accepted', array() === BSA_Finance::validate_payment( 'cash', '', 250.0, 250.0 ) );
	$check( 'rule: amount over the balance refused', isset( BSA_Finance::validate_payment( 'cash', '', 250.01, 250.0 )['payment_amount'] ) );
	$check( 'rule: bank transfer with reference accepted', array() === BSA_Finance::validate_payment( 'bank-transfer', 'FT2610130001' ) );
	$check( 'rule: bank transfer without reference refused', isset( BSA_Finance::validate_payment( 'bank-transfer', '' )['transaction_id'] ) );
	foreach ( array( 'card', 'cheque', 'upi-transfer', 'demand-draft', '' ) as $method ) {
		$check( "rule: method '$method' refused", isset( BSA_Finance::validate_payment( $method, 'X' )['payment_method'] ) );
	}

	// Synthetic learner in Form 1 A with three term invoices sharing one number.
	$section = (int) $wpdb->get_var( "SELECT se.ID FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id WHERE c.label = 'Form 1' AND cs.school_id = 1 LIMIT 1" );
	$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => 'Synthetic Finance Learner', 'enrollment_number' => 'TEST-FIN', 'admission_number' => 'TEST-FIN', 'session_id' => (int) get_option( 'wlsm_current_session' ), 'section_id' => $section, 'is_active' => 1 ) );
	$student = (int) $wpdb->insert_id;
	ob_start();
	$shared = WLSM_M_Invoice::get_invoice_number( 1 );
	$ids    = array();
	foreach ( array( 'Term 1 Fees', 'Term 2 Fees', 'Term 3 Fees' ) as $label ) {
		$wpdb->insert( "{$p}wlsm_invoices", array( 'invoice_number' => $shared, 'label' => $label, 'amount' => 250, 'discount' => 0, 'date_issued' => current_time( 'Y-m-d' ), 'due_date' => current_time( 'Y-m-d' ), 'partial_payment' => 0, 'status' => WLSM_M_Invoice::get_unpaid_key(), 'student_record_id' => $student, 'added_by' => $admin ) );
		$ids[] = (int) $wpdb->insert_id;
	}
	$check( 'fixture: learner and 3 invoices with one shared number', $student && 3 === count( array_filter( $ids ) ), $shared );

	$changed = BSA_Finance::enforce_invoice_rules();
	$numbers = $wpdb->get_col( "SELECT invoice_number FROM {$p}wlsm_invoices WHERE ID IN (" . implode( ',', $ids ) . ') ORDER BY ID' );
	$check( 'invoices: oldest keeps its number', $shared === $numbers[0] );
	$check( 'invoices: the other two renumbered, all unique', 3 === count( array_unique( $numbers ) ), implode( ',', $numbers ) );
	$dups = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT invoice_number FROM {$p}wlsm_invoices GROUP BY invoice_number HAVING COUNT(*) > 1) d" );
	$check( 'invoices: no duplicate numbers left in the school', 0 === $dups );
	$check( 'invoices: part-payments allowed on unpaid invoices', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_invoices WHERE partial_payment = 0 AND status <> %s", WLSM_M_Invoice::get_paid_key() ) ) );
	$check( 'invoices: second run changes nothing', array() === BSA_Finance::enforce_invoice_rules()['renumbered'] );

	// Payments through Weblizar's handler, with the BSA check first (as wp_ajax would).
	$pay = function ( $invoice, $amount, $method, $ref ) use ( $run, $bursar, &$last_json ) {
		wp_cache_delete( 'wlsm_user_info' );
		wp_set_current_user( $bursar );
		$post = array(
			'invoice_id'                         => $invoice,
			'collect-invoice-payment-' . $invoice => wp_create_nonce( 'collect-invoice-payment-' . $invoice ),
			'payment_amount'                     => $amount,
			'payment_method'                     => $method,
			'transaction_id'                     => $ref,
			'payment_note'                       => '',
			'payment_date'                       => current_time( WLSM_Config::date_format() ),
		);
		$outcome = $run( $bursar, array( 'BSA_Finance', 'check_payment_request' ), $post );
		if ( 'died' === $outcome ) {
			return array( 'refused by BSA', $last_json );
		}
		// Weblizar's handler closes output buffers unevenly, so count rows instead of reading its reply.
		$count = function () use ( $invoice ) {
			global $wpdb;
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wlsm_payments WHERE invoice_id = %d", $invoice ) );
		};
		$before = $count();
		$run( $bursar, array( 'WLSM_Staff_Accountant', 'collect_invoice_payment' ), $post );
		return array( $count() > $before ? 'recorded' : 'refused by Weblizar', $last_json );
	};

	list( $r, $json ) = $pay( $ids[0], 250, 'card', '' );
	$check( 'payment: card refused before Weblizar', 'refused by BSA' === $r && isset( $json['data']['payment_method'] ) );
	list( $r, $json ) = $pay( $ids[0], 100, 'bank-transfer', '' );
	$check( 'payment: bank transfer without reference refused', 'refused by BSA' === $r && isset( $json['data']['transaction_id'] ) );
	list( $r, $json ) = $pay( $ids[0], 100, 'cash', '' );
	$check( 'payment: USD 100 cash part-payment recorded', 'recorded' === $r, wp_json_encode( $json ) );
	list( $r, $json ) = $pay( $ids[0], 150, 'bank-transfer', 'FT-TEST-1' );
	$check( 'payment: USD 150 bank transfer with reference recorded', 'recorded' === $r, wp_json_encode( $json ) );
	$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}wlsm_invoices WHERE ID = %d", $ids[0] ) );
	$check( 'invoice: Term 1 now paid', WLSM_M_Invoice::get_paid_key() === $status, $status );
	list( $r, $json ) = $pay( $ids[0], 1, 'cash', '' );
	$check( 'payment: USD 1 on a paid invoice refused', 'refused by BSA' === $r && isset( $json['data']['payment_amount'] ) );
	list( $r, $json ) = $pay( $ids[1], 251, 'cash', '' );
	$check( 'payment: more than the balance refused', 'refused by BSA' === $r && isset( $json['data']['payment_amount'] ) );
	list( $r, $json ) = $pay( $ids[1], 0, 'cash', '' );
	$check( 'payment: zero refused', 'refused by BSA' === $r && isset( $json['data']['payment_amount'] ) );
	list( $r, $json ) = $pay( $ids[1], -20, 'cash', '' );
	$check( 'payment: negative refused', 'refused by BSA' === $r && isset( $json['data']['payment_amount'] ) );
	list( $r, $json ) = $pay( 999999999, 10, 'cash', '' );
	$check( 'payment: unknown invoice refused', 'refused by BSA' === $r && isset( $json['data']['payment_amount'] ) );
	list( $r, $json ) = $pay( $ids[1], 50, 'cash', '' );
	$check( 'payment: USD 50 monthly part-payment on Term 2 recorded', 'recorded' === $r, wp_json_encode( $json ) );

	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT receipt_number, amount, payment_method, transaction_id, added_by, created_at FROM {$p}wlsm_payments WHERE student_record_id = %d ORDER BY ID", $student ) );
	$check( 'payments: 3 rows, recorded by the bursar', 3 === count( $rows ) && ! array_filter( $rows, function ( $row ) use ( $bursar ) { return (int) $row->added_by !== $bursar; } ) );
	$check( 'payments: unique receipt numbers', 3 === count( array_unique( wp_list_pluck( $rows, 'receipt_number' ) ) ) );
	$check( 'payments: bank reference stored', 'FT-TEST-1' === $rows[1]->transaction_id );

	// Balances and cash-up.
	$mine = array_values( array_filter( BSA_Finance::balances(), function ( $row ) use ( $student ) { return (int) $row->ID === $student; } ) );
	$check( 'balances: invoiced 750, paid 300, balance 450', $mine && 750.0 === (float) $mine[0]->invoiced && 300.0 === (float) $mine[0]->paid && 450.0 === (float) $mine[0]->balance, $mine ? "{$mine[0]->invoiced}/{$mine[0]->paid}/{$mine[0]->balance}" : 'missing' );
	$form1 = (int) $wpdb->get_var( "SELECT ID FROM {$p}wlsm_classes WHERE label = 'Form 1'" );
	$form2 = (int) $wpdb->get_var( "SELECT ID FROM {$p}wlsm_classes WHERE label = 'Form 2'" );
	$check( 'balances: class filter includes Form 1 learner', (bool) array_filter( BSA_Finance::balances( $form1 ), function ( $row ) use ( $student ) { return (int) $row->ID === $student; } ) );
	$check( 'balances: class filter excludes from Form 2', ! array_filter( BSA_Finance::balances( $form2 ), function ( $row ) use ( $student ) { return (int) $row->ID === $student; } ) );
	$today = current_time( 'Y-m-d' );
	$cash  = array_filter( BSA_Finance::payments( $today, $today ), function ( $row ) use ( $student ) { return 'TEST-FIN' === $row->admission_number; } );
	$check( 'cash-up: today lists the 3 payments, total USD 300', 3 === count( $cash ) && 300.0 === array_sum( array_map( 'floatval', wp_list_pluck( $cash, 'amount' ) ) ), count( $cash ) . ' rows; dates ' . implode( ',', array_unique( wp_list_pluck( $rows, 'created_at' ) ) ) );
	$check( 'cash-up: method filter', 2 === count( array_filter( BSA_Finance::payments( $today, $today, 'cash' ), function ( $row ) { return 'TEST-FIN' === $row->admission_number; } ) ) );
	$check( 'cash-up: recorded-by name present', (bool) array_filter( $cash, function ( $row ) { return '' !== (string) $row->recorded_by; } ) );
	$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
	$check( 'cash-up: yesterday excludes today', ! array_filter( BSA_Finance::payments( $yesterday, $yesterday ), function ( $row ) { return 'TEST-FIN' === $row->admission_number; } ) );

	// Filters and CSV.
	$f = BSA_Finance::filters( array( 'tab' => 'payments', 'from' => '2026-13-40', 'to' => $today, 'method' => 'card', 'class' => '-3' ) );
	$check( 'filters: bad date, unknown method and negative class sanitised', $today === $f['from'] && '' === $f['method'] && 0 === $f['class'] && 'payments' === $f['tab'] );
	$csv = BSA_Finance::csv( array( 'A', 'B' ), array( array( '=SUM(A1)', '+1' ), array( 'Plain', '-2' ) ) );
	$check( 'csv: formula-like cells neutralised', false !== strpos( $csv, "'=SUM(A1)" ) && false !== strpos( $csv, "'+1" ) && false !== strpos( $csv, "'-2" ) && false === strpos( $csv, ',=SUM' ) );

	// Audit trail: run the end-of-request step and read Simple History.
	if ( function_exists( 'SimpleLogger' ) ) {
		$before = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$p}simple_history" );
		BSA_Finance::after_request();
		$logged = $wpdb->get_col( $wpdb->prepare( "SELECT message FROM {$p}simple_history WHERE id > %d", $before ) );
		$check( 'audit: 3 payments written to Simple History', 3 === count( preg_grep( '/^Payment recorded/', $logged ) ), count( $logged ) . ' entries' );
	}

	// Deleting a payment (as an administrator) is logged.
	$pid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_payments WHERE student_record_id = %d ORDER BY ID DESC LIMIT 1", $student ) );
	$wpdb->delete( "{$p}wlsm_payments", array( 'ID' => $pid ) );
	if ( function_exists( 'SimpleLogger' ) ) {
		$before = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$p}simple_history" );
		BSA_Finance::after_request();
		$check( 'audit: payment deletion logged', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}simple_history WHERE id > %d AND message LIKE 'Payment deleted%%'", $before ) ) );
	}
} catch ( Throwable $e ) {
	$check( 'no unexpected error', false, get_class( $e ) . ': ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	remove_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );
	foreach ( $die_filters as $die_filter ) {
		remove_filter( $die_filter, $die_handler );
	}
	remove_filter( 'wp_doing_ajax', '__return_true' );
	wp_cache_delete( 'wlsm_user_info' );
	wp_set_current_user( $original );
	wp_cache_flush();
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number = 'TEST-FIN'" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_payments WHERE transaction_id LIKE 'FT-TEST%'" );
$check( 'rollback: no synthetic learner or payments left', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_finance', $results, HOUR_IN_SECONDS );
return $results;
