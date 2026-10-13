<?php
/**
 * Fees and payments: BSA rules on top of Weblizar's invoices and payments.
 *
 * - Payments are recorded as cash or bank transfer only; a bank transfer needs the
 *   bank reference (owner decision: bank or cash at school). The amount must be above
 *   zero and no more than the invoice balance (Weblizar does not check amounts).
 * - Every unpaid invoice accepts part-payments (some parents pay monthly against a
 *   termly invoice).
 * - Invoice numbers are unique: Weblizar's admission screen gives every fee invoice it
 *   creates for a new learner the same number; duplicates are renumbered at the end of
 *   the request, before anyone sees them.
 * - Payments recorded or deleted, and invoices deleted, are written to Simple History.
 * - "Fee Reports" screen for the bursar and administrators: balances by class and
 *   payments received (cash-up), with CSV export.
 *
 * Weblizar has no hooks around these actions, so the plugin watches the queries that
 * touch its invoice and payment tables and acts in `shutdown`.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fee rules, audit trail and reports.
 */
class BSA_Finance {

	const PAGE            = 'bsa-fee-reports';
	const ALLOWED_METHODS = array( 'cash', 'bank-transfer' );

	/**
	 * Whether this request wrote to the invoices table.
	 *
	 * @var bool
	 */
	private static $invoices_written = false;

	/**
	 * Highest payment ID before this request's first payment insert (null = none seen).
	 *
	 * @var int|null
	 */
	private static $payments_before = null;

	/**
	 * Payment rows about to be deleted, keyed by ID.
	 *
	 * @var array<int,object>
	 */
	private static $payment_deletes = array();

	/**
	 * Invoice rows about to be deleted, keyed by ID.
	 *
	 * @var array<int,object>
	 */
	private static $invoice_deletes = array();

	/**
	 * Guards against watching our own queries.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_wlsm-collect-invoice-payment', array( __CLASS__, 'check_payment_request' ), 1 );
		add_filter( 'query', array( __CLASS__, 'watch_query' ) );
		add_action( 'shutdown', array( __CLASS__, 'after_request' ), 1 );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_fee_report_csv', array( __CLASS__, 'handle_csv' ) );
		add_action( 'admin_footer', array( __CLASS__, 'limit_method_options' ) );
	}

	/**
	 * Staff payment form: refuse methods other than cash and bank transfer, and bank
	 * transfers without a reference. Runs before Weblizar's handler.
	 */
	public static function check_payment_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Weblizar verifies the nonce; this only refuses.
		$method     = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		$reference  = isset( $_POST['transaction_id'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['transaction_id'] ) ) ) : '';
		$raw        = isset( $_POST['payment_amount'] ) ? str_replace( ',', '', trim( sanitize_text_field( wp_unslash( $_POST['payment_amount'] ) ) ) ) : '';
		$amount     = preg_match( '/^\d+(\.\d{1,2})?$/', $raw ) ? (float) $raw : 0.0; // Anything but a plain positive amount is refused.
		$invoice_id = isset( $_POST['invoice_id'] ) ? absint( $_POST['invoice_id'] ) : 0;
		// phpcs:enable
		$errors = self::validate_payment( $method, $reference, $amount, self::outstanding( $invoice_id ) );
		if ( $errors ) {
			wp_send_json_error( $errors );
		}
	}

	/**
	 * Payment rules. Returns field => message for each problem. Weblizar itself does not
	 * check the amount (it accepts zero, negative and overpayments), so BSA does.
	 *
	 * @param string     $method      Weblizar payment method key.
	 * @param string     $reference   Transaction / bank reference.
	 * @param float|null $amount      Amount entered (null = not checked).
	 * @param float|null $outstanding Invoice balance (null = invoice not found).
	 * @return array<string,string>
	 */
	public static function validate_payment( $method, $reference, $amount = null, $outstanding = null ) {
		$errors = array();
		if ( ! in_array( $method, self::ALLOWED_METHODS, true ) ) {
			$errors['payment_method'] = __( 'Choose Cash or Bank Transfer. Bindura Science Academy accepts only these.', 'bsa-school-management' );
		} elseif ( 'bank-transfer' === $method && '' === $reference ) {
			$errors['transaction_id'] = __( 'Enter the bank reference for this transfer.', 'bsa-school-management' );
		}
		if ( null !== $amount ) {
			if ( null === $outstanding ) {
				$errors['payment_amount'] = __( 'Invoice not found.', 'bsa-school-management' );
			} elseif ( $amount <= 0 ) {
				$errors['payment_amount'] = __( 'Enter an amount greater than zero.', 'bsa-school-management' );
			} elseif ( round( $amount, 2 ) > round( $outstanding, 2 ) ) {
				/* translators: %s: outstanding amount. */
				$errors['payment_amount'] = sprintf( __( 'The amount is more than the balance on this invoice (USD %s). Record at most the balance.', 'bsa-school-management' ), number_format( $outstanding, 2 ) );
			}
		}
		return $errors;
	}

	/**
	 * Balance on an invoice, as Weblizar computes it (amount - discount - payments).
	 *
	 * @param int $invoice_id Invoice ID.
	 * @return float|null Null when the invoice does not exist.
	 */
	public static function outstanding( $invoice_id ) {
		global $wpdb;
		$p   = $wpdb->prefix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT i.amount - i.discount AS payable, (SELECT COALESCE(SUM(p.amount), 0) FROM {$p}wlsm_payments p WHERE p.invoice_id = i.ID) AS paid FROM {$p}wlsm_invoices i WHERE i.ID = %d", $invoice_id ) );
		return $row ? (float) $row->payable - (float) $row->paid : null;
	}

	/**
	 * Notes writes to Weblizar's invoice and payment tables (the query runs unchanged).
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public static function watch_query( $query ) {
		if ( self::$busy || false === stripos( $query, 'wlsm_' ) ) {
			return $query;
		}
		global $wpdb;
		$invoices = $wpdb->prefix . 'wlsm_invoices';
		$payments = $wpdb->prefix . 'wlsm_payments';
		if ( ! preg_match( '/^\s*(INSERT|UPDATE|DELETE)\b/i', $query, $verb ) ) {
			return $query;
		}
		$verb = strtoupper( $verb[1] );
		self::$busy = true;
		if ( preg_match( '/^\s*(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?' . preg_quote( $invoices, '/' ) . '`?\s/i', $query ) ) {
			self::$invoices_written = true;
			if ( 'DELETE' === $verb ) {
				foreach ( self::ids_in( $query ) as $id ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT i.ID, i.invoice_number, i.label, i.amount, i.discount, i.student_record_id, (SELECT COALESCE(SUM(p.amount),0) FROM {$payments} p WHERE p.invoice_id = i.ID) AS paid FROM {$invoices} i WHERE i.ID = %d", $id ) );
					if ( $row ) {
						self::$invoice_deletes[ $id ] = $row;
					}
				}
			}
		} elseif ( preg_match( '/^\s*(INSERT\s+INTO|DELETE\s+FROM)\s+`?' . preg_quote( $payments, '/' ) . '`?\s/i', $query ) ) {
			if ( 'INSERT' === $verb && null === self::$payments_before ) {
				self::$payments_before = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID),0) FROM {$payments}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} elseif ( 'DELETE' === $verb ) {
				foreach ( self::ids_in( $query ) as $id ) {
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, receipt_number, amount, payment_method, transaction_id, invoice_id, student_record_id FROM {$payments} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( $row ) {
						self::$payment_deletes[ $id ] = $row;
					}
				}
			}
		}
		self::$busy = false;
		return $query;
	}

	/**
	 * IDs targeted by a `... WHERE ID = n` or `ID IN (...)` statement.
	 *
	 * @param string $query SQL.
	 * @return int[]
	 */
	private static function ids_in( $query ) {
		if ( preg_match( '/\bWHERE\s+`?ID`?\s*=\s*\'?(\d+)/i', $query, $m ) ) {
			return array( (int) $m[1] );
		}
		if ( preg_match( '/\bWHERE\s+`?ID`?\s+IN\s*\(([\d,\s\']+)\)/i', $query, $m ) ) {
			return array_filter( array_map( 'absint', explode( ',', str_replace( "'", '', $m[1] ) ) ) );
		}
		return array();
	}

	/**
	 * End of request: apply invoice rules and write the audit trail.
	 */
	public static function after_request() {
		if ( ! self::$invoices_written && null === self::$payments_before && ! self::$payment_deletes ) {
			return;
		}
		self::$busy = true;
		try {
			if ( self::$invoices_written ) {
				self::enforce_invoice_rules();
			}
			self::log_payment_changes();
		} catch ( Throwable $e ) {
			self::log( 'warning', 'BSA fee rules could not run: {error}', array( 'error' => $e->getMessage() ) );
		}
		self::$busy = false;
	}

	/**
	 * Renumbers duplicate invoice numbers (oldest keeps its number) and lets every unpaid
	 * or part-paid invoice accept part-payments. Returns what changed.
	 *
	 * @return array{renumbered: array<int,string>, partial: int}
	 */
	public static function enforce_invoice_rules() {
		global $wpdb;
		$p       = $wpdb->prefix;
		$changed = array( 'renumbered' => array(), 'partial' => 0 );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT i.ID, i.invoice_number, cs.school_id
			FROM {$p}wlsm_invoices i
			JOIN {$p}wlsm_student_records sr ON sr.ID = i.student_record_id
			JOIN {$p}wlsm_sections se ON se.ID = sr.section_id
			JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id
			WHERE (cs.school_id, i.invoice_number) IN (
				SELECT cs2.school_id, i2.invoice_number FROM {$p}wlsm_invoices i2
				JOIN {$p}wlsm_student_records sr2 ON sr2.ID = i2.student_record_id
				JOIN {$p}wlsm_sections se2 ON se2.ID = sr2.section_id
				JOIN {$p}wlsm_class_school cs2 ON cs2.ID = se2.class_school_id
				GROUP BY cs2.school_id, i2.invoice_number HAVING COUNT(*) > 1
			)
			ORDER BY cs.school_id, i.invoice_number, i.ID"
		);
		// phpcs:enable
		$seen = array();
		foreach ( $rows as $row ) {
			$key = $row->school_id . '|' . $row->invoice_number;
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true; // The oldest invoice keeps its number.
				continue;
			}
			// Weblizar's numbering helper closes one output buffer; give it one.
			ob_start();
			$number = WLSM_M_Invoice::get_invoice_number( (int) $row->school_id );
			$wpdb->update( "{$p}wlsm_invoices", array( 'invoice_number' => $number ), array( 'ID' => (int) $row->ID ) );
			$changed['renumbered'][ (int) $row->ID ] = $number;
			self::log( 'notice', 'Invoice #{invoice_id} renumbered from {old} to {new} (duplicate number)', array( 'invoice_id' => (int) $row->ID, 'old' => $row->invoice_number, 'new' => $number ) );
		}

		$paid             = WLSM_M_Invoice::get_paid_key();
		$changed['partial'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$p}wlsm_invoices SET partial_payment = 1 WHERE partial_payment = 0 AND status <> %s", $paid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $changed;
	}

	/**
	 * Simple History entries for payments recorded or deleted and invoices deleted.
	 */
	private static function log_payment_changes() {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( null !== self::$payments_before ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$new = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, p.receipt_number, p.amount, p.payment_method, p.transaction_id, p.invoice_id, p.student_record_id, i.invoice_number FROM {$p}wlsm_payments p LEFT JOIN {$p}wlsm_invoices i ON i.ID = p.invoice_id WHERE p.ID > %d", self::$payments_before ) );
			foreach ( $new as $row ) {
				self::log(
					'info',
					'Payment recorded: receipt {receipt}, USD {amount} by {method} for invoice {invoice} (student record #{student})',
					array(
						'receipt'   => $row->receipt_number,
						'amount'    => number_format( (float) $row->amount, 2, '.', '' ),
						'method'    => $row->payment_method,
						'reference' => $row->transaction_id,
						'invoice'   => $row->invoice_number,
						'student'   => (int) $row->student_record_id,
					)
				);
			}
		}
		foreach ( self::$payment_deletes as $id => $row ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_payments WHERE ID = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::log( 'warning', 'Payment deleted: receipt {receipt}, USD {amount} by {method} (student record #{student})', array( 'receipt' => $row->receipt_number, 'amount' => number_format( (float) $row->amount, 2, '.', '' ), 'method' => $row->payment_method, 'reference' => $row->transaction_id, 'student' => (int) $row->student_record_id ) );
			}
		}
		foreach ( self::$invoice_deletes as $id => $row ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_invoices WHERE ID = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::log( 'warning', 'Invoice deleted: {invoice} "{label}", USD {amount}, USD {paid} paid (student record #{student})', array( 'invoice' => $row->invoice_number, 'label' => $row->label, 'amount' => number_format( (float) $row->amount - (float) $row->discount, 2, '.', '' ), 'paid' => number_format( (float) $row->paid, 2, '.', '' ), 'student' => (int) $row->student_record_id ) );
			}
		}
	}

	/**
	 * Simple History entry (no learner names: record IDs only).
	 *
	 * @param string $level   info|notice|warning.
	 * @param string $message Message with {placeholders}.
	 * @param array  $context Context.
	 */
	private static function log( $level, $message, array $context ) {
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->$level( $message, $context );
		}
	}

	/**
	 * Hides payment methods BSA does not accept in Weblizar's payment forms (the server
	 * refuses them anyway).
	 */
	public static function limit_method_options() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'sm-' ) ) {
			return;
		}
		$allowed = wp_json_encode( self::ALLOWED_METHODS );
		?>
		<script>
		( function () {
			var allowed = <?php echo $allowed; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON of constants. ?>;
			// Weblizar's select uses bootstrap-select, which draws its own list: refresh it.
			function prune( root ) {
				root.querySelectorAll( 'select[name="payment_method"]' ).forEach( function ( select ) {
					var removed = false;
					Array.prototype.slice.call( select.options ).forEach( function ( option ) {
						if ( option.value && allowed.indexOf( option.value ) === -1 ) {
							option.remove();
							removed = true;
						}
					} );
					if ( removed && window.jQuery && jQuery.fn.selectpicker ) {
						jQuery( select ).selectpicker( 'refresh' );
					}
				} );
			}
			prune( document );
			new MutationObserver( function () { prune( document ); } ).observe( document.body, { childList: true, subtree: true } );
		} )();
		</script>
		<?php
	}

	/**
	 * Whether a user may see fee reports: administrators and staff with invoice or
	 * payment-statistics rights in the school.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can_view( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		global $wpdb;
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT role, permissions FROM {$wpdb->prefix}wlsm_staff WHERE user_id = %d AND school_id = 1", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$admin_key = class_exists( 'WLSM_M_Role' ) ? WLSM_M_Role::get_admin_key() : 'admin';
		foreach ( $rows as $row ) {
			if ( $admin_key === $row->role ) {
				return true;
			}
			$permissions = maybe_unserialize( $row->permissions );
			if ( is_array( $permissions ) && array_intersect( array( 'manage_invoices', 'stats_payments' ), $permissions ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Admin menu for permitted users only.
	 */
	public static function register_menu() {
		if ( ! self::user_can_view( get_current_user_id() ) ) {
			return;
		}
		add_menu_page( __( 'Fee Reports', 'bsa-school-management' ), __( 'Fee Reports', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-money-alt', 28 );
	}

	/**
	 * Balances per learner in the current session.
	 *
	 * @param int $class_id Weblizar class ID (0 = all).
	 * @return object[] Rows with invoiced, paid and balance.
	 */
	public static function balances( $class_id = 0 ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$where = $wpdb->prepare( 'sr.session_id = %d AND sr.is_active = 1 AND cs.school_id = 1', (int) get_option( 'wlsm_current_session' ) );
		if ( $class_id ) {
			$where .= $wpdb->prepare( ' AND c.ID = %d', $class_id );
		}
		// Same arithmetic as Weblizar: payable = amount - discount; paid = sum of payments.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			"SELECT sr.ID, sr.name, sr.admission_number, c.ID AS class_id, c.label AS class_label, se.label AS section_label,
				COALESCE(inv.invoiced, 0) AS invoiced, COALESCE(pay.paid, 0) AS paid,
				COALESCE(inv.invoiced, 0) - COALESCE(pay.paid, 0) AS balance, COALESCE(inv.invoices, 0) AS invoices
			FROM {$p}wlsm_student_records sr
			JOIN {$p}wlsm_sections se ON se.ID = sr.section_id
			JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id
			JOIN {$p}wlsm_classes c ON c.ID = cs.class_id
			LEFT JOIN (SELECT student_record_id, SUM(amount - discount) AS invoiced, COUNT(*) AS invoices FROM {$p}wlsm_invoices GROUP BY student_record_id) inv ON inv.student_record_id = sr.ID
			LEFT JOIN (SELECT student_record_id, SUM(amount) AS paid FROM {$p}wlsm_payments GROUP BY student_record_id) pay ON pay.student_record_id = sr.ID
			WHERE {$where}
			ORDER BY c.ID, se.label, sr.name"
		);
		// phpcs:enable
	}

	/**
	 * Payments received between two dates (inclusive), newest first.
	 *
	 * @param string $from   Y-m-d.
	 * @param string $to     Y-m-d.
	 * @param string $method Payment method key ('' = all).
	 * @return object[]
	 */
	public static function payments( $from, $to, $method = '' ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$where = $wpdb->prepare( 'p.school_id = 1 AND p.created_at >= %s AND p.created_at < %s', $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' );
		if ( '' !== $method ) {
			$where .= $wpdb->prepare( ' AND p.payment_method = %s', $method );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			"SELECT p.ID, p.receipt_number, p.created_at, p.amount, p.payment_method, p.transaction_id, p.added_by,
				i.invoice_number, i.label AS invoice_label, sr.name, sr.admission_number, c.label AS class_label, se.label AS section_label,
				u.display_name AS recorded_by
			FROM {$p}wlsm_payments p
			LEFT JOIN {$p}wlsm_invoices i ON i.ID = p.invoice_id
			LEFT JOIN {$p}wlsm_student_records sr ON sr.ID = p.student_record_id
			LEFT JOIN {$p}wlsm_sections se ON se.ID = sr.section_id
			LEFT JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id
			LEFT JOIN {$p}wlsm_classes c ON c.ID = cs.class_id
			LEFT JOIN {$wpdb->users} u ON u.ID = p.added_by
			WHERE {$where}
			ORDER BY p.created_at DESC, p.ID DESC"
		);
		// phpcs:enable
	}

	/**
	 * Validated report filters from the request.
	 *
	 * @param array $source $_GET or $_POST (unslashed).
	 * @return array{tab:string,class:int,from:string,to:string,method:string}
	 */
	public static function filters( array $source ) {
		$today  = current_time( 'Y-m-d' );
		$date   = function ( $value ) use ( $today ) {
			$value = is_string( $value ) ? $value : '';
			$d     = DateTime::createFromFormat( '!Y-m-d', $value );
			return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : $today;
		};
		$from   = $date( $source['from'] ?? '' );
		$to     = $date( $source['to'] ?? '' );
		$method = sanitize_key( $source['method'] ?? '' );
		return array(
			'tab'    => 'payments' === ( $source['tab'] ?? '' ) ? 'payments' : 'balances',
			'class'  => max( 0, (int) ( $source['class'] ?? 0 ) ),
			'from'   => min( $from, $to ),
			'to'     => max( $from, $to ),
			'method' => in_array( $method, self::ALLOWED_METHODS, true ) ? $method : '',
		);
	}

	/**
	 * Fee Reports screen.
	 */
	public static function render_page() {
		if ( ! self::user_can_view( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to view fee reports.', 'bsa-school-management' ), 403 );
		}
		$f    = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$base = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );

		echo '<div class="wrap bsa-fees"><h1>' . esc_html__( 'Fee Reports', 'bsa-school-management' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( array( 'balances' => __( 'Balances', 'bsa-school-management' ), 'payments' => __( 'Payments received', 'bsa-school-management' ) ) as $tab => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( add_query_arg( 'tab', $tab, $base ) ), $tab === $f['tab'] ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';
		if ( 'payments' === $f['tab'] ) {
			self::render_payments( $f );
		} else {
			self::render_balances( $f );
		}
		echo '</div>';
	}

	/**
	 * Money for display.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function money( $amount ) {
		return 'USD ' . number_format( (float) $amount, 2 );
	}

	/**
	 * CSV download link for the current filters.
	 *
	 * @param array $f Filters.
	 * @return string
	 */
	private static function csv_url( array $f ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'bsa_fee_report_csv' ), $f ), admin_url( 'admin-post.php' ) ), 'bsa_fee_report_csv' );
	}

	/**
	 * Balances tab.
	 *
	 * @param array $f Filters.
	 */
	private static function render_balances( array $f ) {
		global $wpdb;
		$classes = $wpdb->get_results( "SELECT c.ID, c.label FROM {$wpdb->prefix}wlsm_classes c JOIN {$wpdb->prefix}wlsm_class_school cs ON cs.class_id = c.ID AND cs.school_id = 1 ORDER BY c.ID" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		echo '<form method="get" class="bsa-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="balances">';
		echo '<label for="bsa-class">' . esc_html__( 'Class', 'bsa-school-management' ) . '</label> <select id="bsa-class" name="class"><option value="0">' . esc_html__( 'All classes', 'bsa-school-management' ) . '</option>';
		foreach ( $classes as $class ) {
			printf( '<option value="%d"%s>%s</option>', (int) $class->ID, selected( $f['class'], (int) $class->ID, false ), esc_html( $class->label ) );
		}
		echo '</select> ';
		submit_button( __( 'Show', 'bsa-school-management' ), 'secondary', '', false );
		echo ' <a class="button" href="' . esc_url( self::csv_url( $f ) ) . '">' . esc_html__( 'Download CSV', 'bsa-school-management' ) . '</a></form>';

		$rows = self::balances( $f['class'] );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No learners in the current session.', 'bsa-school-management' ) . '</p>';
			return;
		}
		$totals = array( 'invoiced' => 0, 'paid' => 0, 'balance' => 0, 'owing' => 0 );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Learner', 'bsa-school-management' ), __( 'Admission no.', 'bsa-school-management' ), __( 'Class', 'bsa-school-management' ), __( 'Invoiced', 'bsa-school-management' ), __( 'Paid', 'bsa-school-management' ), __( 'Balance', 'bsa-school-management' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$totals['invoiced'] += (float) $row->invoiced;
			$totals['paid']     += (float) $row->paid;
			$totals['balance']  += (float) $row->balance;
			$totals['owing']    += (float) $row->balance > 0 ? 1 : 0;
			echo '<tr><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $row->admission_number ) . '</td><td>' . esc_html( $row->class_label . ' ' . $row->section_label ) . '</td>';
			echo '<td>' . esc_html( self::money( $row->invoiced ) ) . '</td><td>' . esc_html( self::money( $row->paid ) ) . '</td><td><strong>' . esc_html( self::money( $row->balance ) ) . '</strong></td></tr>';
		}
		echo '</tbody><tfoot><tr><th colspan="3">';
		/* translators: 1: learners, 2: learners with a balance. */
		echo esc_html( sprintf( __( 'Total: %1$d learners, %2$d with a balance', 'bsa-school-management' ), count( $rows ), $totals['owing'] ) );
		echo '</th><th>' . esc_html( self::money( $totals['invoiced'] ) ) . '</th><th>' . esc_html( self::money( $totals['paid'] ) ) . '</th><th>' . esc_html( self::money( $totals['balance'] ) ) . '</th></tr></tfoot></table>';
	}

	/**
	 * Payments tab (cash-up).
	 *
	 * @param array $f Filters.
	 */
	private static function render_payments( array $f ) {
		echo '<form method="get" class="bsa-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="payments">';
		echo '<label for="bsa-from">' . esc_html__( 'From', 'bsa-school-management' ) . '</label> <input type="date" id="bsa-from" name="from" value="' . esc_attr( $f['from'] ) . '"> ';
		echo '<label for="bsa-to">' . esc_html__( 'To', 'bsa-school-management' ) . '</label> <input type="date" id="bsa-to" name="to" value="' . esc_attr( $f['to'] ) . '"> ';
		echo '<label for="bsa-method">' . esc_html__( 'Method', 'bsa-school-management' ) . '</label> <select id="bsa-method" name="method"><option value="">' . esc_html__( 'All', 'bsa-school-management' ) . '</option>';
		foreach ( self::ALLOWED_METHODS as $key ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $f['method'], $key, false ), esc_html( WLSM_M_Invoice::get_payment_method_text( $key ) ) );
		}
		echo '</select> ';
		submit_button( __( 'Show', 'bsa-school-management' ), 'secondary', '', false );
		echo ' <a class="button" href="' . esc_url( self::csv_url( $f ) ) . '">' . esc_html__( 'Download CSV', 'bsa-school-management' ) . '</a></form>';

		$rows = self::payments( $f['from'], $f['to'], $f['method'] );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No payments recorded in this period.', 'bsa-school-management' ) . '</p>';
			return;
		}
		$by_method = array();
		$by_staff  = array();
		$total     = 0;
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Date', 'bsa-school-management' ), __( 'Receipt', 'bsa-school-management' ), __( 'Learner', 'bsa-school-management' ), __( 'Class', 'bsa-school-management' ), __( 'Invoice', 'bsa-school-management' ), __( 'Method', 'bsa-school-management' ), __( 'Reference', 'bsa-school-management' ), __( 'Amount', 'bsa-school-management' ), __( 'Recorded by', 'bsa-school-management' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$method_text = WLSM_M_Invoice::get_payment_method_text( $row->payment_method );
			$staff       = $row->recorded_by ? $row->recorded_by : __( 'Online / parent', 'bsa-school-management' );
			$total                      += (float) $row->amount;
			$by_method[ $method_text ]   = ( $by_method[ $method_text ] ?? 0 ) + (float) $row->amount;
			$by_staff[ $staff ]          = ( $by_staff[ $staff ] ?? 0 ) + (float) $row->amount;
			echo '<tr><td>' . esc_html( mysql2date( 'j M Y', $row->created_at ) ) . '</td><td>' . esc_html( $row->receipt_number ) . '</td><td>' . esc_html( $row->name . ' (' . $row->admission_number . ')' ) . '</td>';
			echo '<td>' . esc_html( trim( $row->class_label . ' ' . $row->section_label ) ) . '</td><td>' . esc_html( $row->invoice_number . ' ' . $row->invoice_label ) . '</td><td>' . esc_html( $method_text ) . '</td>';
			echo '<td>' . esc_html( $row->transaction_id ) . '</td><td>' . esc_html( self::money( $row->amount ) ) . '</td><td>' . esc_html( $staff ) . '</td></tr>';
		}
		echo '</tbody><tfoot><tr><th colspan="7">';
		/* translators: %d: number of payments. */
		echo esc_html( sprintf( _n( '%d payment', '%d payments', count( $rows ), 'bsa-school-management' ), count( $rows ) ) );
		echo '</th><th>' . esc_html( self::money( $total ) ) . '</th><th></th></tr></tfoot></table>';

		echo '<h2>' . esc_html__( 'Cash-up summary', 'bsa-school-management' ) . '</h2><table class="widefat striped" style="max-width:520px"><tbody>';
		foreach ( $by_method as $label => $amount ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( self::money( $amount ) ) . '</td></tr>';
		}
		foreach ( $by_staff as $label => $amount ) {
			/* translators: %s: staff name. */
			echo '<tr><th scope="row">' . esc_html( sprintf( __( 'Recorded by %s', 'bsa-school-management' ), $label ) ) . '</th><td>' . esc_html( self::money( $amount ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * CSV export (admin-post), same filters and permission as the screen.
	 */
	public static function handle_csv() {
		check_admin_referer( 'bsa_fee_report_csv' );
		if ( ! self::user_can_view( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to view fee reports.', 'bsa-school-management' ), 403 );
		}
		$f = self::filters( wp_unslash( $_GET ) );
		if ( 'payments' === $f['tab'] ) {
			$name = 'bsa-payments-' . $f['from'] . '-to-' . $f['to'] . '.csv';
			$head = array( 'Date', 'Receipt', 'Learner', 'Admission no.', 'Class', 'Invoice', 'Method', 'Reference', 'Amount (USD)', 'Recorded by' );
			$data = array_map(
				function ( $r ) {
					return array( mysql2date( 'Y-m-d', $r->created_at ), $r->receipt_number, $r->name, $r->admission_number, trim( $r->class_label . ' ' . $r->section_label ), trim( $r->invoice_number . ' ' . $r->invoice_label ), WLSM_M_Invoice::get_payment_method_text( $r->payment_method ), $r->transaction_id, number_format( (float) $r->amount, 2, '.', '' ), $r->recorded_by );
				},
				self::payments( $f['from'], $f['to'], $f['method'] )
			);
		} else {
			$name = 'bsa-balances-' . current_time( 'Y-m-d' ) . '.csv';
			$head = array( 'Learner', 'Admission no.', 'Class', 'Invoiced (USD)', 'Paid (USD)', 'Balance (USD)' );
			$data = array_map(
				function ( $r ) {
					return array( $r->name, $r->admission_number, trim( $r->class_label . ' ' . $r->section_label ), number_format( (float) $r->invoiced, 2, '.', '' ), number_format( (float) $r->paid, 2, '.', '' ), number_format( (float) $r->balance, 2, '.', '' ) );
				},
				self::balances( $f['class'] )
			);
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::csv( $head, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells neutralised.
		exit;
	}

	/**
	 * CSV text. Cells that a spreadsheet would treat as formulas are prefixed with '.
	 *
	 * @param string[]   $head Header row.
	 * @param string[][] $rows Rows.
	 * @return string
	 */
	public static function csv( array $head, array $rows ) {
		$out = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		foreach ( array_merge( array( $head ), $rows ) as $row ) {
			$row = array_map(
				function ( $cell ) {
					$cell = (string) $cell;
					return preg_match( '/^[=+\-@\t\r]/', $cell ) ? "'" . $cell : $cell;
				},
				$row
			);
			fputcsv( $out, $row );
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return "\xEF\xBB\xBF" . $csv;
	}
}
