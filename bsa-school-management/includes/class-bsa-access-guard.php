<?php
/**
 * Server-side access checks for Weblizar invoice, result and registration endpoints.
 *
 * Weblizar 10.3.9 exposes several AJAX and REST handlers that return a learner's
 * invoices, results or certificates by ID, relying only on nonces (which its public
 * pages hand out) or on the requester being logged in. See docs/audit-report.md §7.
 * This guard runs before those handlers (priority 0) and fails closed.
 *
 * Depends on Weblizar internals: tables wlsm_student_records (ID, user_id,
 * parent_user_id), wlsm_invoices (ID, student_record_id) and wlsm_staff (user_id).
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blocks unauthenticated access to learner data and enforces record ownership.
 */
class BSA_Access_Guard {

	/**
	 * AJAX actions that expose learner data or create accounts. Blocked for visitors
	 * who are not logged in, and allowed only for staff when logged in.
	 *
	 * @var string[]
	 */
	const STAFF_ONLY_ACTIONS = array(
		'wlsm-p-get-students-with-pending-invoices', // Search learners by class/name/admission number.
		'wlsm-p-get-pending-invoices-history',       // Invoice history search by learner name.
		'wlsm-p-get-exam-result',                    // Result lookup by roll number.
		'wlsm-p-get-exam-admit-card',                // Admit card lookup by roll number.
		'wlsm-p-get-certificate',                    // Certificate lookup by enrolment number.
		'wlsm-p-submit-registration',                // Public learner registration (online admissions not in use).
		'wlsm-p-submit-staff-registration',          // Public staff self-registration.
		'wlsm-p-pay-with-razorpay',                  // Online gateways are not used by BSA.
		'wlsm-p-pay-with-stripe',
		'wlsm-p-pay-with-paypal',
		'wlsm-p-pay-with-sslcommerz',
		'wlsm-p-pay-with-pesapal',
		'wlsm-p-pay-with-paystack',
	);

	/**
	 * AJAX actions a logged-in learner or parent may use for their own records only.
	 * Maps action => request fields holding student record IDs or invoice IDs.
	 *
	 * @var array<string,array<string,string[]>>
	 */
	const OWNED_ACTIONS = array(
		'wlsm-p-get-student-pending-invoices'     => array( 'students' => array( 'student_id' ) ),
		'wlsm-p-get-student-pending-invoice'      => array( 'invoices' => array( 'invoice_id' ) ),
		'wlsm-p-get-student-pending-invoice-bulk' => array( 'invoices' => array( 'invoice_ids' ) ),
		'wlsm-p-pay-invoice-amount'               => array( 'invoices' => array( 'invoice_id', 'invoice_ids' ) ),
	);

	/**
	 * Registers hooks.
	 */
	public static function init() {
		foreach ( self::staff_only_actions() as $action ) {
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, 'deny_ajax' ), 0 );
			add_action( 'wp_ajax_' . $action, array( __CLASS__, 'require_staff_ajax' ), 0 );
		}
		foreach ( array_keys( self::OWNED_ACTIONS ) as $action ) {
			add_action( 'wp_ajax_nopriv_' . $action, array( __CLASS__, 'deny_ajax' ), 0 );
			add_action( 'wp_ajax_' . $action, array( __CLASS__, 'require_ownership_ajax' ), 0 );
		}
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_rest' ), 10, 3 );
	}

	/**
	 * Staff-only actions, filterable so the school can re-enable e.g. online admissions.
	 *
	 * @return string[]
	 */
	public static function staff_only_actions() {
		return (array) apply_filters( 'bsa_sm_staff_only_ajax_actions', self::STAFF_ONLY_ACTIONS );
	}

	/**
	 * Rejects a request for a visitor who is not logged in.
	 */
	public static function deny_ajax() {
		self::log_denial( 'ajax:' . self::current_ajax_action(), 'not logged in' );
		wp_send_json_error( __( 'Please log in to continue.', 'bsa-school-management' ), 403 );
	}

	/**
	 * Lets the request through only for staff.
	 */
	public static function require_staff_ajax() {
		if ( self::is_staff( get_current_user_id() ) ) {
			return;
		}
		self::log_denial( 'ajax:' . self::current_ajax_action(), 'not staff' );
		wp_send_json_error( __( 'You do not have permission to do that.', 'bsa-school-management' ), 403 );
	}

	/**
	 * Lets the request through for staff, or when every referenced record belongs to
	 * the current user (as learner or parent).
	 */
	public static function require_ownership_ajax() {
		$user_id = get_current_user_id();
		if ( self::is_staff( $user_id ) ) {
			return;
		}

		$action = self::current_ajax_action();
		$fields = self::OWNED_ACTIONS[ $action ] ?? array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the Weblizar handler verifies its own nonce after this check.
		$student_ids = self::ids_from_request( $_POST, $fields['students'] ?? array() );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$invoice_ids = self::ids_from_request( $_POST, $fields['invoices'] ?? array() );

		if ( self::owns_all( $user_id, $student_ids, $invoice_ids ) ) {
			return;
		}
		self::log_denial( 'ajax:' . $action, 'record not owned' );
		wp_send_json_error( __( 'Record not found.', 'bsa-school-management' ), 404 );
	}

	/**
	 * Enforces invoice ownership on Weblizar's REST routes.
	 *
	 * @param mixed           $response Response so far (WP_Error or null).
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function guard_rest( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$route   = $request->get_route();
		$user_id = get_current_user_id();
		$allowed = null;

		if ( preg_match( '#^/sm/student/fee-invoices/(\d+)$#', $route, $m ) ) {
			$allowed = self::student_owns_invoice( $user_id, (int) $m[1] );
		} elseif ( preg_match( '#^/sm/parent/students/(\d+)/fee-invoices/(\d+)$#', $route, $m ) ) {
			$allowed = self::parent_owns_invoice( $user_id, (int) $m[1], (int) $m[2] );
		} elseif ( '/sm/student/submit-invoice-payment-request' === $route ) {
			$allowed = self::student_owns_invoice( $user_id, absint( $request->get_param( 'id' ) ) );
		}

		if ( false === $allowed ) {
			self::log_denial( 'rest:' . $route, 'invoice not owned' );
			return new WP_Error( 'bsa_sm_not_found', __( 'Invoice not found.', 'bsa-school-management' ), array( 'status' => 404 ) );
		}
		return $response;
	}

	/**
	 * Whether a user is school staff (WordPress administrator or a Weblizar staff record).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_staff( $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$staff = self::table( 'WLSM_STAFF', 'wlsm_staff' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a constant.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$staff} WHERE user_id = %d LIMIT 1", $user_id ) );
	}

	/**
	 * Student record IDs the user may see, as learner or parent.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function owned_student_ids( $user_id ) {
		global $wpdb;
		if ( ! $user_id ) {
			return array();
		}
		$records = self::table( 'WLSM_STUDENT_RECORDS', 'wlsm_student_records' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$records} WHERE user_id = %d OR parent_user_id = %d", $user_id, $user_id ) );
		// Logins move to the new year's record at the year-end rollover; the same learner's
		// earlier records (linked by the promotion) stay theirs, for past report cards.
		return self::with_earlier_years( array_map( 'intval', $ids ) );
	}

	/**
	 * Student record IDs plus the same learners' earlier records, followed through
	 * Weblizar's promotion links (written only by staff promoting learners).
	 *
	 * @param int[] $ids Student record IDs.
	 * @return int[]
	 */
	public static function with_earlier_years( array $ids ) {
		global $wpdb;
		$all      = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$frontier = $all;
		$table    = self::table( 'WLSM_PROMOTIONS', 'wlsm_promotions' );
		for ( $i = 0; $frontier && $i < 20; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- integer list.
			$prev     = array_map( 'intval', $wpdb->get_col( "SELECT from_student_record FROM {$table} WHERE to_student_record IN (" . implode( ',', $frontier ) . ')' ) );
			$frontier = array_values( array_diff( array_unique( $prev ), $all ) );
			$all      = array_merge( $all, $frontier );
		}
		return $all;
	}

	/**
	 * Student record ID an invoice belongs to, or 0 if the invoice does not exist.
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return int
	 */
	public static function invoice_student_id( $invoice_id ) {
		global $wpdb;
		$invoices = self::table( 'WLSM_INVOICES', 'wlsm_invoices' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT student_record_id FROM {$invoices} WHERE ID = %d", $invoice_id ) );
	}

	/**
	 * Whether every listed student record and invoice belongs to the user. Fails
	 * closed when nothing is listed.
	 *
	 * @param int   $user_id     User ID.
	 * @param int[] $student_ids Student record IDs.
	 * @param int[] $invoice_ids Invoice IDs.
	 * @return bool
	 */
	public static function owns_all( $user_id, array $student_ids, array $invoice_ids ) {
		if ( ! $student_ids && ! $invoice_ids ) {
			return false;
		}
		$owned = self::owned_student_ids( $user_id );
		if ( ! $owned ) {
			return false;
		}
		foreach ( $student_ids as $id ) {
			if ( ! in_array( $id, $owned, true ) ) {
				return false;
			}
		}
		foreach ( $invoice_ids as $id ) {
			if ( ! in_array( self::invoice_student_id( $id ), $owned, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the invoice belongs to the learner logged in as $user_id.
	 *
	 * @param int $user_id    User ID.
	 * @param int $invoice_id Invoice ID.
	 * @return bool
	 */
	public static function student_owns_invoice( $user_id, $invoice_id ) {
		global $wpdb;
		if ( ! $user_id || ! $invoice_id ) {
			return false;
		}
		$records = self::table( 'WLSM_STUDENT_RECORDS', 'wlsm_student_records' );
		$sid     = self::invoice_student_id( $invoice_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $sid && (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$records} WHERE ID = %d AND user_id = %d", $sid, $user_id ) );
	}

	/**
	 * Whether the invoice belongs to $student_id and that learner is the parent's child.
	 *
	 * @param int $user_id    Parent user ID.
	 * @param int $student_id Student record ID from the route.
	 * @param int $invoice_id Invoice ID from the route.
	 * @return bool
	 */
	public static function parent_owns_invoice( $user_id, $student_id, $invoice_id ) {
		global $wpdb;
		if ( ! $user_id || ! $student_id || self::invoice_student_id( $invoice_id ) !== $student_id ) {
			return false;
		}
		$records = self::table( 'WLSM_STUDENT_RECORDS', 'wlsm_student_records' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$records} WHERE ID = %d AND parent_user_id = %d", $student_id, $user_id ) );
	}

	/**
	 * Collects positive integer IDs from request fields (scalar, array or comma list).
	 *
	 * @param array    $source Request data.
	 * @param string[] $fields Field names.
	 * @return int[]
	 */
	public static function ids_from_request( array $source, array $fields ) {
		$ids = array();
		foreach ( $fields as $field ) {
			if ( ! isset( $source[ $field ] ) ) {
				continue;
			}
			$value = wp_unslash( $source[ $field ] );
			$parts = is_array( $value ) ? $value : explode( ',', (string) $value );
			foreach ( $parts as $part ) {
				$id = absint( is_scalar( $part ) ? $part : 0 );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The AJAX action currently being handled.
	 *
	 * @return string
	 */
	private static function current_ajax_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	}

	/**
	 * Resolves a Weblizar table name, preferring the plugin's own constant.
	 *
	 * @param string $constant Weblizar constant name.
	 * @param string $suffix   Table name without prefix.
	 * @return string
	 */
	private static function table( $constant, $suffix ) {
		global $wpdb;
		return defined( $constant ) ? constant( $constant ) : $wpdb->prefix . $suffix;
	}

	/**
	 * Records a blocked request in the activity log (no personal data).
	 *
	 * @param string $context Endpoint identifier.
	 * @param string $reason  Short reason.
	 */
	private static function log_denial( $context, $reason ) {
		do_action( 'bsa_sm_access_denied', $context, $reason, get_current_user_id() );
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->warning(
				'BSA blocked {context}: {reason}',
				array(
					'context' => $context,
					'reason'  => $reason,
				)
			);
		}
	}
}
