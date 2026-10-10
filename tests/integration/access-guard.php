<?php
/**
 * Integration test for BSA_Access_Guard, run on the live site through Novamira
 * `execute-php` (send everything after the opening tag).
 *
 * Creates synthetic users, student records and invoices inside a database
 * transaction, exercises the guard and the real Weblizar handlers, then rolls
 * everything back. No data persists (auto-increment counters advance).
 *
 * REST checks call the guard filter and the Weblizar handler methods directly
 * rather than dispatching, because the Novamira request is OAuth-scoped to its own
 * routes and that boundary must stay in place.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p       = $wpdb->prefix;
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $detail ? ' — ' . $detail : '' );
};

// AJAX handlers end with wp_die(); turn that into an exception we can inspect.
$die_handler = function () {
	return function () {
		throw new RuntimeException( 'wp_die' );
	};
};
add_filter( 'wp_die_handler', $die_handler );
add_filter( 'wp_die_ajax_handler', $die_handler );
add_filter( 'wp_doing_ajax', '__return_true' );

// Weblizar handlers open and close output buffers unevenly; restore the level afterwards.
$ob_level   = ob_get_level();
$restore_ob = function ( $level ) {
	while ( ob_get_level() > $level ) {
		ob_end_clean();
	}
	while ( ob_get_level() < $level ) {
		ob_start();
	}
};

// Restore the caller afterwards: Novamira re-checks the current user when it responds.
$original_user = get_current_user_id();

$wpdb->query( 'START TRANSACTION' );
try {
	$mk_user = function ( $login ) use ( $wpdb ) {
		$wpdb->insert(
			$wpdb->users,
			array(
				'user_login'      => $login,
				'user_pass'       => wp_hash_password( wp_generate_password( 24 ) ),
				'user_nicename'   => $login,
				'user_email'      => $login . '@example.invalid',
				'user_registered' => current_time( 'mysql', true ),
				'display_name'    => $login,
			)
		);
		return (int) $wpdb->insert_id;
	};
	$learner_a = $mk_user( 'bsa_test_learner_a' );
	$learner_b = $mk_user( 'bsa_test_learner_b' );
	$parent_a  = $mk_user( 'bsa_test_parent_a' );

	// Form 1 is linked to the school since Phase 3; reuse that link (it is unique per class and school).
	$class_school = (int) $wpdb->get_var( "SELECT ID FROM {$p}wlsm_class_school WHERE class_id = 1 AND school_id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $class_school ) {
		$wpdb->insert( "{$p}wlsm_class_school", array( 'class_id' => 1, 'school_id' => 1 ) );
		$class_school = (int) $wpdb->insert_id;
	}
	$wpdb->insert( "{$p}wlsm_sections", array( 'label' => 'TEST', 'class_school_id' => $class_school ) );
	$section = (int) $wpdb->insert_id;

	$mk_record = function ( $name, $user, $parent ) use ( $wpdb, $p, $section ) {
		$wpdb->insert(
			"{$p}wlsm_student_records",
			array(
				'name'              => $name,
				'enrollment_number' => 'TEST-' . $user,
				'admission_number'  => 'TEST-' . $user,
				'session_id'        => 1,
				'section_id'        => $section,
				'user_id'           => $user,
				'parent_user_id'    => $parent ? $parent : null, // FK to users: no parent must be NULL, not 0.
				'is_active'         => 1,
			)
		);
		return (int) $wpdb->insert_id;
	};
	$record_a = $mk_record( 'Synthetic Learner A', $learner_a, $parent_a );
	$record_b = $mk_record( 'Synthetic Learner B', $learner_b, 0 );

	$mk_invoice = function ( $record ) use ( $wpdb, $p ) {
		$wpdb->insert(
			"{$p}wlsm_invoices",
			array(
				'invoice_number'    => 'TEST-' . $record,
				'label'             => 'Synthetic term fees',
				'amount'            => 250,
				'date_issued'       => current_time( 'Y-m-d' ),
				'due_date'          => current_time( 'Y-m-d' ),
				'partial_payment'   => 1,
				'status'            => WLSM_M_Invoice::get_unpaid_key(),
				'student_record_id' => $record,
			)
		);
		return (int) $wpdb->insert_id;
	};
	$invoice_a = $mk_invoice( $record_a );
	$invoice_b = $mk_invoice( $record_b );

	$check( 'fixtures created', $learner_a && $learner_b && $parent_a && $record_a && $record_b && $invoice_a && $invoice_b, $wpdb->last_error );

	// Unit-level checks.
	$check( 'administrator counts as staff', BSA_Access_Guard::is_staff( 1 ) );
	$check( 'parent is not staff', ! BSA_Access_Guard::is_staff( $parent_a ) );
	$check( 'parent owns own child invoice', BSA_Access_Guard::owns_all( $parent_a, array(), array( $invoice_a ) ) );
	$check( 'parent does not own other learner invoice', ! BSA_Access_Guard::owns_all( $parent_a, array(), array( $invoice_b ) ) );
	$check( 'mixed list fails closed', ! BSA_Access_Guard::owns_all( $parent_a, array(), array( $invoice_a, $invoice_b ) ) );
	$check( 'learner does not own other student record', ! BSA_Access_Guard::owns_all( $learner_a, array( $record_b ), array() ) );
	$check( 'empty request fails closed', ! BSA_Access_Guard::owns_all( $learner_a, array(), array() ) );
	$check( 'comma list parsed', array( 3, 5 ) === BSA_Access_Guard::ids_from_request( array( 'x' => '3, 5,0' ), array( 'x' ) ) );

	// REST: guard filter first; when it allows, the real Weblizar handler runs.
	$rest = function ( $user, $route, $url_params, $handler ) use ( $restore_ob ) {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_url_params( $url_params );
		$guard = BSA_Access_Guard::guard_rest( null, array(), $request );
		if ( is_wp_error( $guard ) ) {
			return array( $guard->get_error_data()['status'] ?? 0, $guard->get_error_code() );
		}
		$level    = ob_get_level();
		$response = rest_ensure_response( call_user_func( $handler, $request ) );
		$restore_ob( $level );
		$data = $response->get_data();
		return array( $response->get_status(), is_array( $data ) ? ( $data['success'] ?? null ) : null );
	};
	$student_handler = array( 'WLSM_Api', 'fee_invoice' );
	$parent_handler  = array( 'WLSM_Api', 'parent_fee_invoice' );

	list( $status, $code ) = $rest( $learner_a, "/sm/student/fee-invoices/{$invoice_a}", array( 'invoice_id' => $invoice_a ), $student_handler );
	$check( 'learner reads own invoice (REST)', 200 === $status && true === $code, "$status/" . var_export( $code, true ) );
	list( $status, $code ) = $rest( $learner_a, "/sm/student/fee-invoices/{$invoice_b}", array( 'invoice_id' => $invoice_b ), $student_handler );
	$check( 'learner blocked from other invoice (REST)', 404 === $status && 'bsa_sm_not_found' === $code, "$status/$code" );
	list( $status, $code ) = $rest( $parent_a, "/sm/parent/students/{$record_a}/fee-invoices/{$invoice_a}", array( 'student_id' => $record_a, 'invoice_id' => $invoice_a ), $parent_handler );
	$check( 'parent reads own child invoice (REST)', 200 === $status && true === $code, "$status/" . var_export( $code, true ) );
	list( $status, $code ) = $rest( $parent_a, "/sm/parent/students/{$record_a}/fee-invoices/{$invoice_b}", array( 'student_id' => $record_a, 'invoice_id' => $invoice_b ), $parent_handler );
	$check( 'parent blocked from other invoice via own child route (REST)', 404 === $status && 'bsa_sm_not_found' === $code, "$status/$code" );

	// Weblizar's own permission callback still rejects anonymous callers.
	wp_set_current_user( 0 );
	$routes = rest_get_server()->get_routes();
	$perm   = $routes['/sm/student/fee-invoices/(?P<invoice_id>\d+)'][0]['permission_callback'] ?? null;
	$check( 'anonymous rejected by route permission (REST)', is_callable( $perm ) && false === call_user_func( $perm, new WP_REST_Request( 'GET' ) ) );

	// AJAX: guard callback as a logged-in user.
	$ajax = function ( $user, $action, $post ) use ( $restore_ob ) {
		wp_set_current_user( $user );
		$_POST    = $post;
		$_REQUEST = array_merge( $post, array( 'action' => $action ) );
		$level    = ob_get_level();
		ob_start();
		try {
			BSA_Access_Guard::require_ownership_ajax();
			$outcome = 'allowed';
		} catch ( RuntimeException $e ) {
			$outcome = 'denied';
		}
		$restore_ob( $level );
		return $outcome;
	};
	$check( 'parent AJAX own invoice allowed', 'allowed' === $ajax( $parent_a, 'wlsm-p-get-student-pending-invoice', array( 'invoice_id' => $invoice_a ) ) );
	$check( 'parent AJAX other invoice denied', 'denied' === $ajax( $parent_a, 'wlsm-p-get-student-pending-invoice', array( 'invoice_id' => $invoice_b ) ) );
	$check( 'parent AJAX bulk with foreign invoice denied', 'denied' === $ajax( $parent_a, 'wlsm-p-get-student-pending-invoice-bulk', array( 'invoice_ids' => array( $invoice_a, $invoice_b ) ) ) );
	$check( 'learner AJAX other student invoices denied', 'denied' === $ajax( $learner_a, 'wlsm-p-get-student-pending-invoices', array( 'student_id' => $record_b ) ) );
	$check( 'admin AJAX allowed', 'allowed' === $ajax( 1, 'wlsm-p-get-student-pending-invoice', array( 'invoice_id' => $invoice_b ) ) );
} catch ( Throwable $e ) {
	$check( 'test run', false, get_class( $e ) . ': ' . $e->getMessage() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	$restore_ob( $ob_level );
	$_POST    = array();
	$_REQUEST = array();
	wp_set_current_user( $original_user );
	wp_cache_flush();
	remove_filter( 'wp_die_handler', $die_handler );
	remove_filter( 'wp_die_ajax_handler', $die_handler );
	remove_filter( 'wp_doing_ajax', '__return_true' );
}

$leftover = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'bsa\\_test\\_%'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE enrollment_number LIKE 'TEST-%'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_invoices WHERE invoice_number LIKE 'TEST-%'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_sections WHERE label = 'TEST'" );
$check( 'rollback left no synthetic data', 0 === $leftover, "leftover=$leftover" );

return $results;
