<?php
/**
 * Learner and parent account area (Weblizar's [school_management_account]): BSA pages and
 * fixes on top of Weblizar's own, through the shortcode's output (no vendor files edited).
 *
 * - "Report Cards" in the learner menu and in each child's links on the parent dashboard:
 *   published BSA report cards with subjects passed, result and position.
 * - Parent "Fee Structure": Weblizar links to it but has no parent page for it (the link
 *   showed the dashboard again); BSA shows the child's term fees.
 * - The learner dashboard is no longer replaced by Weblizar's "pending fee invoices" screen
 *   (fee types marked "Dashboard Disable?", which all BSA term fees are so that admission
 *   creates one invoice per term). That screen hid the menu from every learner with an
 *   unpaid or part-paid invoice and counted each payment once per form. Invoices remain
 *   on the Fee Invoices page. Filter `bsa_sm_fee_lockout` (true) restores it.
 * - "Pay Now" / "Pay Selected" are removed while no online or bank-transfer payment method
 *   is enabled in Weblizar (they opened an empty payment box).
 * - The greeting shows the user's name instead of the capitalised login, and an "Account
 *   settings" link (parents had no link to Weblizar's settings page).
 * - Table cells carry their column heading (data-label) and the table the class
 *   bsa-stack, so the theme can show each row as a card on phones instead of a table cut
 *   off at the screen edge.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Account-area pages and fixes for learners and parents.
 */
class BSA_Family {

	const REPORTS = 'report-cards';
	const FEES    = 'fee-structure';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'account_output' ), 10, 2 );
		add_filter( 'query', array( __CLASS__, 'no_fee_lockout' ) );
	}

	/**
	 * Empties the query behind Weblizar's dashboard fee lock-out (front end only).
	 *
	 * @param string $query SQL.
	 * @return string
	 */
	public static function no_fee_lockout( $query ) {
		if ( false === strpos( $query, 'wif.active_on_dashboard = 1 AND (i.status' ) || is_admin() || apply_filters( 'bsa_sm_fee_lockout', false ) ) {
			return $query;
		}
		return str_replace( 'wif.active_on_dashboard = 1 AND (i.status', 'wif.active_on_dashboard = 1 AND 1 = 0 AND (i.status', $query );
	}

	/**
	 * Whether Weblizar offers any way to pay from the account area.
	 *
	 * @return bool
	 */
	public static function online_payment_enabled() {
		foreach ( array( 'razorpay', 'paytm', 'sslcommerz', 'stripe', 'paypal', 'pesapal', 'paystack', 'authorize', 'bank_transfer', 'upi_transfer' ) as $method ) {
			$get = 'get_settings_' . $method;
			if ( method_exists( 'WLSM_M_Setting', $get ) ) {
				$settings = WLSM_M_Setting::$get( BSA_School::SCHOOL_ID );
				if ( is_array( $settings ) && ! empty( $settings['enable'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Adjusts the account shortcode's HTML for learners and parents.
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @return string
	 */
	public static function account_output( $output, $tag ) {
		$user_id = get_current_user_id();
		if ( 'school_management_account' !== $tag || ! $user_id || ! is_string( $output ) ) {
			return $output;
		}
		$owned = BSA_Access_Guard::owned_student_ids( $user_id );
		if ( ! $owned ) {
			return $output;
		}
		$user = get_userdata( $user_id );
		if ( $user ) {
			$output = preg_replace( '#(<span class="wlsm-logged-in-text">).*?(</span>)#s', '${1}' . esc_html( $user->display_name ) . '${2}', $output, 1 );
		}
		$settings = '<a class="bsa-settings-link" href="' . esc_url( add_query_arg( 'action', 'settings', BSA_Portal::account_url() ) ) . '">' . esc_html__( 'Account settings', 'bsa-school-management' ) . '</a>';
		$output   = preg_replace( '#<a class="wlsm-logout-link"#', $settings . "\n\t\t" . '$0', $output, 1 );
		if ( ! self::online_payment_enabled() ) {
			$output = self::remove_pay_buttons( $output );
		}
		// Weblizar's "new" badge beside recent notices has no text alternative.
		$output = preg_replace( '#<img(?![^>]*\balt=)([^>]*class="wlsm-st-notice-new")#', '<img alt="' . esc_attr__( 'New', 'bsa-school-management' ) . '"$1', $output );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$student = isset( $_GET['student_id'] ) ? absint( $_GET['student_id'] ) : 0;
		// phpcs:enable
		if ( false !== strpos( $output, 'wlsm-container-student' ) ) {
			$output = self::learner( $output, $action, $owned );
		} elseif ( false !== strpos( $output, 'wlsm-container-parent' ) ) {
			$output = self::parent( $output, $action, $student, $owned );
		}
		return self::label_tables( $output );
	}

	/**
	 * Adds each column's heading to its cells as data-label.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function label_tables( $html ) {
		return preg_replace_callback(
			'#<table\b[^>]*>.*?</table>#s',
			static function ( $table ) {
				// Weblizar closes some headers with </td> and leaves cells in HTML comments.
				$t = preg_replace( '#<!--.*?-->#s', '', $table[0] );
				if ( ! preg_match( '#<thead\b[^>]*>(.*?)</thead>#s', $t, $head, PREG_OFFSET_CAPTURE ) || ! preg_match_all( '#<th\b[^>]*>(.*?)</t[hd]>#s', $head[1][0], $th ) ) {
					return $table[0];
				}
				$labels = array();
				foreach ( $th[1] as $cell ) {
					$labels[] = trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $cell ), ENT_QUOTES, 'UTF-8' ) ) );
				}
				$after = $head[0][1] + strlen( $head[0][0] );
				$rest  = preg_replace_callback(
					'#<tr\b[^>]*>.*?</tr>#s',
					static function ( $row ) use ( $labels ) {
						$i = 0;
						return preg_replace_callback(
							'#<t[dh]\b(?![^>]*data-label)#',
							static function ( $cell ) use ( &$i, $labels ) {
								$label = $labels[ $i++ ] ?? '';
								return '' === $label ? $cell[0] : $cell[0] . ' data-label="' . esc_attr( $label ) . '"';
							},
							$row[0]
						);
					},
					substr( $t, $after )
				);
				$open = substr( $t, 0, $after );
				$open = preg_match( '#^<table\b[^>]*\bclass="#', $open ) ? preg_replace( '#^(<table\b[^>]*\bclass=")#', '${1}bsa-stack ', $open, 1 ) : preg_replace( '#^<table\b#', '<table class="bsa-stack"', $open, 1 );
				return $open . $rest;
			},
			$html
		);
	}

	/**
	 * Removes the pay links and the invoice checkboxes that only serve them.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function remove_pay_buttons( $html ) {
		$html = preg_replace( '#(<br>)?<a href="\#" class="wlsm-view-student-pending-invoice(-bulk)?"[^>]*>.*?</a>#s', '', $html );
		$html = preg_replace( '#<th><input type="checkbox" name="select_all"[^>]*></th>#', '', $html );
		return preg_replace( '#<td><input type="checkbox" name="invoice_ids\[\]"[^>]*></td>#', '', $html );
	}

	/**
	 * Learner: "Report Cards" menu item and page.
	 *
	 * @param string $html   Shortcode output.
	 * @param string $action Requested page.
	 * @param int[]  $owned  The learner's own student records (one per school year).
	 * @return string
	 */
	public static function learner( $html, $action, array $owned ) {
		$active = self::REPORTS === $action;
		$link   = '<li><a class="wlsm-navigation-link' . ( $active ? ' active' : '' ) . '" href="' . esc_url( add_query_arg( 'action', self::REPORTS, BSA_Portal::account_url() ) ) . '">' . esc_html__( 'Report Cards', 'bsa-school-management' ) . '</a></li>';
		$nav    = strpos( $html, '<ul class="wlsm-navigation-links">' );
		if ( false === $nav ) {
			return $html;
		}
		$end = strpos( $html, '</ul>', $nav );
		$ul  = substr( $html, $nav, $end - $nav );
		if ( $active ) {
			$ul = str_replace( 'wlsm-navigation-link active"', 'wlsm-navigation-link"', $ul );
		}
		// After "Attendance" when it is shown, otherwise last.
		$after = preg_match( '#<li>\s*<a class="wlsm-navigation-link[^"]*" href="[^"]*action=attendance"[^>]*>.*?</li>#s', $ul, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] + strlen( $m[0][0] ) : strlen( $ul );
		$ul    = substr( $ul, 0, $after ) . "\n" . $link . substr( $ul, $after );
		$html  = substr( $html, 0, $nav ) . $ul . substr( $html, $end );
		if ( ! $active ) {
			return $html;
		}
		$start = strpos( $html, '<div class="wlsm-content-area' );
		$close = strrpos( $html, '</div>' );
		if ( false === $start || false === $close || $close < $start ) {
			return $html;
		}
		$page = '<div class="wlsm-content-area bsa-family-page bsa-family-reports"><div class="wlsm-st-main-title"><span>' . esc_html__( 'Report Cards', 'bsa-school-management' ) . '</span></div>' . self::reports_table( $owned, false ) . '</div>';
		return substr( $html, 0, $start ) . $page . "\n" . substr( $html, $close );
	}

	/**
	 * Parent: "Report Cards" in each child's links; Report Cards and Fee Structure pages.
	 *
	 * @param string $html    Shortcode output.
	 * @param string $action  Requested page.
	 * @param int    $student Requested child (student record ID).
	 * @param int[]  $owned   The parent's children.
	 * @return string
	 */
	public static function parent( $html, $action, $student, array $owned ) {
		$html = preg_replace_callback(
			'#(<ul class="wlsm-parent-student-links">)(.*?)(</ul>)#s',
			static function ( $m ) {
				if ( ! preg_match( '#student_id=(\d+)#', $m[2], $id ) ) {
					return $m[0];
				}
				$url = add_query_arg( array( 'action' => self::REPORTS, 'student_id' => (int) $id[1] ), BSA_Portal::account_url() );
				return $m[1] . $m[2] . '<li><a href="' . esc_url( $url ) . '">' . esc_html__( 'Report Cards', 'bsa-school-management' ) . "</a></li>\n" . $m[3];
			},
			$html
		);
		if ( ! in_array( $action, array( self::REPORTS, self::FEES ), true ) || ! in_array( $student, $owned, true ) ) {
			return $html;
		}
		$open  = '<div class="wlsm-container wlsm-container-parent">';
		$start = strpos( $html, $open );
		$close = strrpos( $html, '</div>' );
		if ( false === $start || false === $close || $close < $start ) {
			return $html;
		}
		$start += strlen( $open );
		if ( self::REPORTS === $action ) {
			$title = __( 'Report Cards', 'bsa-school-management' );
			$body  = self::reports_table( array( $student ), true );
		} else {
			$title = __( 'Fee Structure', 'bsa-school-management' );
			$body  = self::fee_structure( $student );
		}
		$page = '<div class="wlsm-parent-student-entity bsa-family-page bsa-family-' . esc_attr( $action ) . '"><hr><a href="' . esc_url( BSA_Portal::account_url() ) . '" class="wlsm-mb-3 wlsm-font-bold">&#8592;&nbsp;' . esc_html__( 'Back', 'bsa-school-management' ) . '</a>' . self::child_detail( $student ) . '<div class="wlsm-st-main-title"><span>' . esc_html( $title ) . '</span></div>' . $body . '</div>';
		return substr( $html, 0, $start ) . "\n" . $page . "\n" . substr( $html, $close );
	}

	/**
	 * Learner record with class, stream and year.
	 *
	 * @param int $student_id Student record ID.
	 * @return object|null
	 */
	public static function learner_record( $student_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT sr.ID, sr.name, sr.admission_number, sr.section_id, sr.session_id, se.label AS section_label, c.ID AS class_id, c.label AS class_label, s.label AS year FROM {$p}wlsm_student_records sr JOIN {$p}wlsm_sections se ON se.ID = sr.section_id JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id JOIN {$p}wlsm_sessions s ON s.ID = sr.session_id WHERE sr.ID = %d", $student_id ) );
	}

	/**
	 * Child's name, class and admission number above a parent page (Weblizar's layout).
	 *
	 * @param int $student_id Student record ID.
	 * @return string
	 */
	public static function child_detail( $student_id ) {
		$r = self::learner_record( $student_id );
		if ( ! $r ) {
			return '';
		}
		$rows = array(
			__( 'Student Name', 'bsa-school-management' )     => $r->name,
			__( 'Class', 'bsa-school-management' )            => $r->class_label . ' ' . $r->section_label,
			__( 'Session', 'bsa-school-management' )          => $r->year,
			__( 'Admission Number', 'bsa-school-management' ) => $r->admission_number,
		);
		$out = '<div class="wlsm-parent-student-detail">';
		foreach ( $rows as $label => $value ) {
			$out .= '<span class="wlsm-font-bold">' . esc_html( $label ) . ':</span> <span>' . esc_html( $value ) . '</span><br>';
		}
		return $out . '</div>';
	}

	/**
	 * Published report cards of the given learners, newest first.
	 *
	 * @param int[] $student_ids Student record IDs.
	 * @return object[] student_record_id, session_id, term, name, section_id, section_label, class_label, year.
	 */
	public static function published( array $student_ids ) {
		global $wpdb;
		$student_ids = array_filter( array_map( 'intval', $student_ids ) );
		if ( ! $student_ids ) {
			return array();
		}
		$p     = $wpdb->prefix;
		$table = BSA_Marks::reports_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		return $wpdb->get_results( "SELECT r.student_record_id, r.session_id, r.term, sr.name, sr.section_id, se.label AS section_label, c.label AS class_label, s.label AS year FROM {$table} r JOIN {$p}wlsm_student_records sr ON sr.ID = r.student_record_id JOIN {$p}wlsm_sections se ON se.ID = sr.section_id JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id JOIN {$p}wlsm_sessions s ON s.ID = r.session_id WHERE r.published_at IS NOT NULL AND r.student_record_id IN (" . implode( ',', $student_ids ) . ') ORDER BY s.start_date DESC, r.term DESC, sr.name' );
	}

	/**
	 * Table of published report cards with results and a link to each card.
	 *
	 * @param int[] $student_ids Student record IDs.
	 * @param bool  $for_parent  Wording for a parent (names the child).
	 * @return string
	 */
	public static function reports_table( array $student_ids, $for_parent ) {
		$rows = self::published( $student_ids );
		if ( ! $rows ) {
			return '<p class="bsa-family-empty">' . esc_html__( 'No report cards have been published yet. They appear here once the school publishes them at the end of each term.', 'bsa-school-management' ) . '</p>';
		}
		$out = '<div class="table-responsive w-100 wlsm-w-100"><table class="table table-bordered wlsm-w-100 bsa-family-table"><thead><tr class="bg-primary text-white"><th>' . esc_html__( 'Term', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Subjects passed', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Result', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Position', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Report card', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$res = BSA_Reports::class_results( (int) $r->section_id, (int) $r->session_id, (int) $r->term )[ (int) $r->student_record_id ] ?? null;
			$url = add_query_arg( array( 'bsa_report' => (int) $r->student_record_id, 'session' => (int) $r->session_id, 'term' => (int) $r->term ), BSA_Portal::account_url() );
			/* translators: 1: term number, 2: year. */
			$term = sprintf( __( 'Term %1$d, %2$s', 'bsa-school-management' ), $r->term, $r->year );
			$out .= '<tr><td>' . esc_html( $term ) . '</td><td>' . esc_html( $r->class_label . ' ' . $r->section_label ) . '</td>';
			/* translators: 1: subjects passed, 2: subjects with an exam mark. */
			$out .= '<td>' . esc_html( $res && $res['recorded'] ? sprintf( __( '%1$d of %2$d', 'bsa-school-management' ), $res['passed'], $res['recorded'] ) : '–' ) . '</td>';
			$out .= '<td>' . esc_html( $res && $res['result'] ? $res['result'] : '–' ) . '</td><td>' . esc_html( $res && $res['position'] ? (string) $res['position'] : '–' ) . '</td>';
			$out .= '<td><a class="bsa-family-open" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open and print', 'bsa-school-management' ) . '</a></td></tr>';
		}
		$out .= '</tbody></table></div>';
		$note = $for_parent ? __( 'Position is within the class. A subject is passed at 50% or more; the result is Pass with five or more subjects passed.', 'bsa-school-management' ) : __( 'Position is within your class. A subject is passed at 50% or more; the result is Pass with five or more subjects passed.', 'bsa-school-management' );
		$progress = class_exists( 'BSA_Results' ) ? BSA_Results::family_progress( $student_ids ) : '';
		return $out . '<p class="bsa-family-note">' . esc_html( $note ) . '</p>' . $progress;
	}

	/**
	 * A child's term fees (the class's fee types) and how to pay.
	 *
	 * @param int $student_id Student record ID.
	 * @return string
	 */
	public static function fee_structure( $student_id ) {
		global $wpdb;
		$r = self::learner_record( $student_id );
		if ( ! $r ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fees = $wpdb->get_results( $wpdb->prepare( "SELECT label, amount FROM {$wpdb->prefix}wlsm_fees WHERE class_id = %s AND school_id = %d ORDER BY label", (string) $r->class_id, BSA_School::SCHOOL_ID ) );
		if ( ! $fees ) {
			return '<p class="bsa-family-empty">' . esc_html__( 'No fees are set for this class yet.', 'bsa-school-management' ) . '</p>';
		}
		$money = static function ( $amount ) {
			return class_exists( 'WLSM_Config' ) ? WLSM_Config::get_money_text( $amount, BSA_School::SCHOOL_ID ) : number_format( (float) $amount, 2 );
		};
		$total = 0.0;
		$out   = '<div class="table-responsive w-100 wlsm-w-100"><table class="table table-bordered wlsm-w-100 bsa-family-table"><thead><tr class="bg-primary text-white"><th>' . esc_html__( 'Fee', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Amount', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $fees as $f ) {
			$total += (float) $f->amount;
			$out   .= '<tr><td>' . esc_html( $f->label ) . '</td><td>' . esc_html( $money( $f->amount ) ) . '</td></tr>';
		}
		/* translators: %s: year. */
		$out .= '</tbody><tfoot><tr><th>' . esc_html( sprintf( __( 'Total for %s', 'bsa-school-management' ), $r->year ) ) . '</th><th>' . esc_html( $money( $total ) ) . '</th></tr></tfoot></table></div>';
		return $out . '<p class="bsa-family-note">' . esc_html__( 'Fees are invoiced each term. Pay in cash at the school office or by bank transfer (quote the invoice number); you may pay in parts, for example monthly. Payments recorded by the school appear under Payment History.', 'bsa-school-management' ) . '</p>';
	}
}
