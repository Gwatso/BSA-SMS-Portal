<?php
/**
 * School Dashboard (wp-admin) for the owner and the school administrator: one screen
 * with fees collected against what is owed, today's register per class, marks entry for
 * the current term, and a list of things that need attention.
 *
 * Read-only. Figures come from Weblizar's tables and the BSA modules:
 * - Fees: the year's invoices and payments per learner (same arithmetic as Fee Reports),
 *   payments received this term and in the last 7 days.
 * - Attendance: the daily register (no subject) for today, or for the last day the
 *   register was taken when nobody has marked today yet.
 * - Marks: exam marks entered against the subjects each learner takes, for the current
 *   term (School Terms), and report cards published.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * School Dashboard screen and figures.
 */
class BSA_Dashboard {

	const PAGE = 'bsa-dashboard';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Who may see the dashboard: the owner and Weblizar "admin" staff.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can_view( $user_id ) {
		return BSA_School::is_school_admin( $user_id );
	}

	/**
	 * Menu entry at the top of wp-admin for school administrators.
	 */
	public static function register_menu() {
		if ( ! self::user_can_view( get_current_user_id() ) ) {
			return;
		}
		add_menu_page( __( 'School Dashboard', 'bsa-school-management' ), __( 'School Dashboard', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-chart-bar', 2 );
	}

	/**
	 * Active learners per section for a session.
	 *
	 * @param int $session_id Session ID.
	 * @return array<int,int[]> section ID => student record IDs.
	 */
	public static function learners_by_section( $session_id ) {
		global $wpdb;
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, section_id FROM {$wpdb->prefix}wlsm_student_records WHERE session_id = %d AND is_active = 1", $session_id ) ) as $r ) {
			$out[ (int) $r->section_id ][] = (int) $r->ID;
		}
		return $out;
	}

	/**
	 * Fees for the current session per class and stream.
	 *
	 * @return array{rows: array<int,array>, invoiced: float, paid: float, balance: float, learners_owing: int}
	 */
	public static function fees() {
		$rows  = array();
		$total = array( 'invoiced' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'learners_owing' => 0 );
		foreach ( BSA_Finance::balances() as $b ) {
			$key = $b->class_label . ' ' . $b->section_label;
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array( 'label' => $key, 'learners' => 0, 'invoiced' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'owing' => 0 );
			}
			$rows[ $key ]['learners']++;
			$rows[ $key ]['invoiced'] += (float) $b->invoiced;
			$rows[ $key ]['paid']     += (float) $b->paid;
			$rows[ $key ]['balance']  += (float) $b->balance;
			if ( (float) $b->balance > 0.004 ) {
				$rows[ $key ]['owing']++;
				$total['learners_owing']++;
			}
			$total['invoiced'] += (float) $b->invoiced;
			$total['paid']     += (float) $b->paid;
			$total['balance']  += (float) $b->balance;
		}
		return array_merge( array( 'rows' => array_values( $rows ) ), $total );
	}

	/**
	 * Payments received between two dates.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array{amount: float, count: int}
	 */
	public static function received( $from, $to ) {
		$payments = BSA_Finance::payments( $from, $to );
		return array( 'amount' => array_sum( array_map( 'floatval', wp_list_pluck( $payments, 'amount' ) ) ), 'count' => count( $payments ) );
	}

	/**
	 * Daily register per section for one date.
	 *
	 * @param string $date       Y-m-d.
	 * @param int    $session_id Session ID.
	 * @return array<int,array{learners:int, marked:int, present:int, late:int, absent:int, holiday:int, rate:float|null}> section ID => counts.
	 */
	public static function attendance( $date, $session_id ) {
		global $wpdb;
		$p   = $wpdb->prefix;
		$out = array();
		foreach ( self::learners_by_section( $session_id ) as $section => $ids ) {
			$out[ $section ] = array( 'learners' => count( $ids ), 'marked' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'holiday' => 0, 'rate' => null );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT sr.section_id, a.status, COUNT(DISTINCT a.student_record_id) AS n FROM {$p}wlsm_attendance a JOIN {$p}wlsm_student_records sr ON sr.ID = a.student_record_id WHERE a.attendance_date = %s AND a.subject_id IS NULL AND sr.session_id = %d AND sr.is_active = 1 GROUP BY sr.section_id, a.status", $date, $session_id ) );
		$map  = array( 'p' => 'present', 'l' => 'late', 'a' => 'absent', 'h' => 'holiday' );
		foreach ( $rows as $r ) {
			$section = (int) $r->section_id;
			if ( isset( $out[ $section ], $map[ $r->status ] ) ) {
				$out[ $section ][ $map[ $r->status ] ] += (int) $r->n;
				$out[ $section ]['marked']             += (int) $r->n;
			}
		}
		foreach ( $out as $section => $c ) {
			$possible = $c['present'] + $c['late'] + $c['absent'];
			// Present and Late count as attended (as on the BSA register).
			$out[ $section ]['rate'] = $possible ? ( $c['present'] + $c['late'] ) / $possible : null;
		}
		return $out;
	}

	/**
	 * The last date on or before $date on which any daily register was taken ('' if none
	 * in the last 30 days).
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public static function last_register_day( $date ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(attendance_date) FROM {$wpdb->prefix}wlsm_attendance WHERE subject_id IS NULL AND attendance_date <= %s AND attendance_date > %s", $date, gmdate( 'Y-m-d', strtotime( $date . ' -30 days' ) ) ) );
	}

	/**
	 * Marks entry for a term per section: exam marks entered against the subjects each
	 * learner takes, and report cards published.
	 *
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return array<int,array{learners:int, expected:int, entered:int, published:int}>
	 */
	public static function marks( $session_id, $term ) {
		$out = array();
		foreach ( self::learners_by_section( $session_id ) as $section => $ids ) {
			$marks    = BSA_Marks::for_learners( $ids, $session_id, $term );
			$expected = 0;
			$entered  = 0;
			$pub      = 0;
			foreach ( $ids as $id ) {
				foreach ( BSA_Reports::learner_subjects( $id, $section ) as $s ) {
					++$expected;
					$m = $marks[ $id ][ (int) $s->ID ] ?? null;
					if ( $m && null !== $m->exam_mark ) {
						++$entered;
					}
				}
				if ( BSA_Marks::is_published( $id, $session_id, $term ) ) {
					++$pub;
				}
			}
			$out[ $section ] = array( 'learners' => count( $ids ), 'expected' => $expected, 'entered' => $entered, 'published' => $pub );
		}
		return $out;
	}

	/**
	 * Subjects of each form with no subject teacher assigned in Weblizar.
	 *
	 * @return array<string,string[]> form label => subject labels.
	 */
	public static function unassigned_subjects() {
		global $wpdb;
		$assigned = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT subject_id FROM {$wpdb->prefix}wlsm_admin_subject" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out      = array();
		$seen     = array();
		foreach ( BSA_School::sections() as $section ) {
			if ( isset( $seen[ $section->class_id ] ) ) {
				continue;
			}
			$seen[ $section->class_id ] = true;
			foreach ( BSA_Marks::section_subjects( (int) $section->ID ) as $s ) {
				if ( ! in_array( (int) $s->ID, $assigned, true ) ) {
					$out[ $section->class_label ][] = $s->label;
				}
			}
		}
		return $out;
	}

	/**
	 * Things that need attention, most important first.
	 *
	 * @param array $state From state().
	 * @return array<int,array{level:string, text:string, link:string}>
	 */
	public static function attention( array $state ) {
		$items = array();
		$add   = function ( $level, $text, $link = '' ) use ( &$items ) {
			$items[] = array( 'level' => $level, 'text' => $text, 'link' => $link );
		};
		$terms_url = admin_url( 'admin.php?page=' . BSA_Terms::PAGE );
		if ( ! $state['term'] ) {
			$add( 'warning', __( 'No school term covers today: enter the term dates.', 'bsa-school-management' ), $terms_url );
		} elseif ( '' === BSA_Terms::next_start( $state['term']['session'], $state['term']['term'] ) ) {
			/* translators: %d: term number. */
			$add( 'warning', sprintf( __( 'The start of the term after Term %d is not set: report cards will show no "Next term begins on" date.', 'bsa-school-management' ), $state['term']['term'] ), $terms_url );
		}
		if ( $state['is_school_day'] ) {
			$missing = array();
			foreach ( $state['attendance'] as $section => $c ) {
				if ( $c['learners'] && ! $c['marked'] ) {
					$missing[] = $state['sections'][ $section ] ?? (string) $section;
				}
			}
			if ( $missing ) {
				/* translators: %s: class names. */
				$add( 'warning', sprintf( __( 'Register not marked today: %s.', 'bsa-school-management' ), implode( ', ', $missing ) ), admin_url( 'admin.php?page=' . BSA_Register::PAGE ) );
			}
		}
		if ( ! empty( $state['wa_absent'] ) ) {
			/* translators: %d: number of learners. */
			$add( 'info', sprintf( _n( '%d learner is absent today and the parent has not been told on WhatsApp yet.', '%d learners are absent today and their parents have not been told on WhatsApp yet.', $state['wa_absent'], 'bsa-school-management' ), $state['wa_absent'] ), admin_url( 'admin.php?page=' . BSA_WhatsApp::PAGE . '&tab=send&type=absence' ) );
		}
		if ( $state['term'] && current_time( 'Y-m-d' ) > $state['term']['end'] ) {
			$unpublished = 0;
			foreach ( $state['marks'] as $m ) {
				$unpublished += max( 0, $m['learners'] - $m['published'] );
			}
			if ( $unpublished ) {
				/* translators: 1: number of learners, 2: term number. */
				$add( 'info', sprintf( _n( '%1$d report card for Term %2$d is not published yet.', '%1$d report cards for Term %2$d are not published yet.', $unpublished, 'bsa-school-management' ), $unpublished, $state['term']['term'] ), admin_url( 'admin.php?page=' . BSA_Reports::PAGE ) );
			}
		}
		$unassigned = 0;
		foreach ( $state['unassigned'] as $subjects ) {
			$unassigned += count( $subjects );
		}
		if ( $unassigned ) {
			$parts = array();
			foreach ( $state['unassigned'] as $form => $subjects ) {
				$parts[] = $form . ' (' . count( $subjects ) . ')';
			}
			/* translators: 1: number of subjects, 2: forms with counts. */
			$add( 'info', sprintf( __( '%1$d subjects have no subject teacher assigned: %2$s. Until they are assigned, only class teachers and administrators can enter those marks.', 'bsa-school-management' ), $unassigned, implode( ', ', $parts ) ), admin_url( 'admin.php?page=sm-staff-subjects' ) );
		}
		if ( ! empty( $state['streams_unset'] ) ) {
			$parts = array();
			foreach ( $state['streams_unset'] as $class => $n ) {
				$parts[] = $class . ' (' . $n . ')';
			}
			/* translators: %s: classes with counts. */
			$add( 'info', sprintf( __( 'Learners without a stream (Sciences or Commercials): %s. Until it is set they appear on every subject\'s marks sheet.', 'bsa-school-management' ), implode( ', ', $parts ) ), admin_url( 'admin.php?page=bsa-streams' ) );
		}
		if ( ! empty( $state['at_risk'] ) && $state['term'] ) {
			/* translators: 1: learners, 2: term. */
			$add( 'warning', sprintf( _n( '%1$d learner is at risk in Term %2$d.', '%1$d learners are at risk in Term %2$d.', $state['at_risk'], 'bsa-school-management' ), $state['at_risk'], $state['term']['term'] ), admin_url( 'admin.php?page=' . BSA_Results::PAGE . '&tab=risk&session=' . $state['term']['session'] . '&term=' . $state['term']['term'] ) );
		}
		if ( $state['accounts']['errors'] || $state['accounts']['warnings'] ) {
			/* translators: 1: learners to fix, 2: learners to check. */
			$add( $state['accounts']['errors'] ? 'warning' : 'info', sprintf( __( 'Family accounts: %1$d to fix, %2$d to check.', 'bsa-school-management' ), $state['accounts']['errors'], $state['accounts']['warnings'] ), admin_url( 'admin.php?page=' . BSA_Accounts::PAGE ) );
		}
		return $items;
	}

	/**
	 * Everything the screen shows.
	 *
	 * @return array
	 */
	public static function state() {
		$today   = current_time( 'Y-m-d' );
		$term    = BSA_Terms::current( $today );
		$session = $term ? (int) $term['session'] : BSA_School::current_session();
		$in_term = $term && $today <= $term['end'];
		$weekday = (int) gmdate( 'N', strtotime( $today ) ) <= 5;
		$day     = $today;
		$att     = self::attendance( $today, $session );
		$marked  = array_sum( wp_list_pluck( $att, 'marked' ) );
		if ( ! $marked ) {
			$last = self::last_register_day( $today );
			if ( $last && $last !== $today ) {
				$day = $last;
				$att = self::attendance( $last, $session );
			}
		}
		$sections = array();
		foreach ( BSA_School::sections() as $s ) {
			$sections[ (int) $s->ID ] = $s->title;
		}
		$family   = BSA_Accounts::learners( $session );
		$accounts = BSA_Accounts::summary( $family, BSA_Accounts::issues( $family ) );
		return array(
			'today'         => $today,
			'term'          => $term,
			'in_term'       => $in_term,
			'session'       => $session,
			'is_school_day' => $in_term && $weekday,
			'register_day'  => $day,
			'attendance'    => $att,
			'sections'      => $sections,
			'fees'          => self::fees(),
			'received_term' => $term ? self::received( $term['start'], $today ) : array( 'amount' => 0.0, 'count' => 0 ),
			'received_week' => self::received( gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ), $today ),
			'marks'         => $term ? self::marks( $session, (int) $term['term'] ) : array(),
			'unassigned'    => self::unassigned_subjects(),
			'streams_unset' => class_exists( 'BSA_Streams' ) ? BSA_Streams::unset_counts( $session ) : array(),
			'wa_absent'     => class_exists( 'BSA_WhatsApp' ) && $in_term && $weekday ? BSA_WhatsApp::unsent_absences( $today ) : 0,
			'accounts'      => $accounts,
			'at_risk'       => $term ? count( BSA_Results::at_risk( array_map( 'intval', wp_list_pluck( BSA_School::sections(), 'ID' ) ), $session, (int) $term['term'] ) ) : 0,
		);
	}

	/**
	 * Money as USD with two decimals.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		return 'USD ' . number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * A labelled meter: value / total with the share written out (never colour alone).
	 *
	 * @param float  $value Filled part.
	 * @param float  $total Whole.
	 * @param string $text  Visible label beside the bar.
	 * @return string
	 */
	public static function meter( $value, $total, $text ) {
		$share = $total > 0 ? max( 0, min( 1, $value / $total ) ) : 0;
		return '<div class="bsa-meter" role="img" aria-label="' . esc_attr( $text ) . '" title="' . esc_attr( $text ) . '"><span class="bsa-meter-fill" style="width:' . esc_attr( number_format( $share * 100, 1, '.', '' ) ) . '%"></span></div><span class="bsa-meter-text">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Fees per class: collected against invoiced (bar and figures), outstanding, owing.
	 *
	 * @param array $fees From fees().
	 */
	public static function fees_table( array $fees ) {
		$pct = static function ( $part, $whole ) {
			return $whole > 0 ? (int) round( 100 * $part / $whole ) : 0;
		};
		if ( ! $fees['rows'] ) {
			echo '<p>' . esc_html__( 'No learners in the current school year.', 'bsa-school-management' ) . '</p>';
		} else {
			echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Collected', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Outstanding', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Owing', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
			foreach ( $fees['rows'] as $r ) {
				/* translators: 1: collected, 2: invoiced, 3: percent. */
				$text = $r['invoiced'] > 0 ? sprintf( __( '%1$s of %2$s (%3$d%%)', 'bsa-school-management' ), self::money( $r['paid'] ), number_format_i18n( $r['invoiced'], 2 ), $pct( $r['paid'], $r['invoiced'] ) ) : __( 'nothing invoiced', 'bsa-school-management' );
				echo '<tr><th scope="row">' . esc_html( $r['label'] ) . '</th><td class="bsa-meter-cell">' . self::meter( $r['paid'], $r['invoiced'], $text ) . '</td><td class="num">' . esc_html( self::money( $r['balance'] ) ) . '</td><td class="num">' . esc_html( $r['owing'] . ' / ' . $r['learners'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- meter() escapes.
			}
			echo '</tbody></table></div>';
		}
	}

	/**
	 * The screen.
	 */
	public static function render_page() {
		if ( ! self::user_can_view( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to view the school dashboard.', 'bsa-school-management' ), 403 );
		}
		$s    = self::state();
		$fees = $s['fees'];
		$pct  = static function ( $part, $whole ) {
			return $whole > 0 ? (int) round( 100 * $part / $whole ) : 0;
		};

		echo '<div class="wrap bsa-dash"><h1>' . esc_html__( 'School Dashboard', 'bsa-school-management' ) . '</h1>';
		self::styles();
		$term_text = $s['term'] ? sprintf(
			/* translators: 1: term number, 2: start date, 3: end date. */
			__( 'Term %1$d: %2$s to %3$s', 'bsa-school-management' ),
			$s['term']['term'],
			mysql2date( 'j M Y', $s['term']['start'] ),
			mysql2date( 'j M Y', $s['term']['end'] )
		) . ( $s['in_term'] ? '' : ' ' . __( '(holiday)', 'bsa-school-management' ) ) : __( 'No term dates set for today', 'bsa-school-management' );
		echo '<p class="bsa-dash-sub">' . esc_html( mysql2date( 'l j F Y', $s['today'] ) . ' · ' . $term_text ) . '</p>';

		// Needs attention.
		$attention = self::attention( $s );
		echo '<section class="bsa-card"><h2>' . esc_html__( 'Needs attention', 'bsa-school-management' ) . '</h2>';
		if ( ! $attention ) {
			echo '<p class="bsa-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'Nothing needs attention.', 'bsa-school-management' ) . '</p>';
		} else {
			echo '<ul class="bsa-attention">';
			foreach ( $attention as $item ) {
				$icon  = 'warning' === $item['level'] ? 'warning' : 'info-outline';
				$label = 'warning' === $item['level'] ? __( 'Action', 'bsa-school-management' ) : __( 'Note', 'bsa-school-management' );
				echo '<li class="' . esc_attr( $item['level'] ) . '"><span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span> <strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $item['text'] ) . ( $item['link'] ? ' <a href="' . esc_url( $item['link'] ) . '">' . esc_html__( 'Open', 'bsa-school-management' ) . '</a>' : '' ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</section>';

		// Stat tiles.
		$learners  = array_sum( array_map( 'count', self::learners_by_section( $s['session'] ) ) );
		$att_total = array( 'present' => 0, 'late' => 0, 'absent' => 0, 'marked' => 0 );
		foreach ( $s['attendance'] as $c ) {
			foreach ( $att_total as $k => $v ) {
				$att_total[ $k ] += $c[ $k ];
			}
		}
		$att_possible = $att_total['present'] + $att_total['late'] + $att_total['absent'];
		$marks_exp    = array_sum( wp_list_pluck( $s['marks'], 'expected' ) );
		$marks_ent    = array_sum( wp_list_pluck( $s['marks'], 'entered' ) );
		$tiles        = array(
			array( __( 'Active learners', 'bsa-school-management' ), number_format_i18n( $learners ), sprintf( /* translators: %d: families. */ _n( '%d parent login', '%d parent logins', $s['accounts']['families'], 'bsa-school-management' ), $s['accounts']['families'] ) ),
			array( __( 'Fees collected this year', 'bsa-school-management' ), self::money( $fees['paid'] ), sprintf( /* translators: 1: percent, 2: amount invoiced. */ __( '%1$d%% of %2$s invoiced', 'bsa-school-management' ), $pct( $fees['paid'], $fees['invoiced'] ), self::money( $fees['invoiced'] ) ) ),
			array( __( 'Outstanding', 'bsa-school-management' ), self::money( $fees['balance'] ), sprintf( /* translators: %d: learners. */ _n( '%d learner owing', '%d learners owing', $fees['learners_owing'], 'bsa-school-management' ), $fees['learners_owing'] ) ),
			array( __( 'Received in the last 7 days', 'bsa-school-management' ), self::money( $s['received_week']['amount'] ), sprintf( /* translators: 1: payments, 2: amount this term. */ _n( '%1$d payment · this term %2$s', '%1$d payments · this term %2$s', $s['received_week']['count'], 'bsa-school-management' ), $s['received_week']['count'], self::money( $s['received_term']['amount'] ) ) ),
			array( $s['register_day'] === $s['today'] ? __( 'Attendance today', 'bsa-school-management' ) : __( 'Attendance, last register day', 'bsa-school-management' ), $att_possible ? $pct( $att_total['present'] + $att_total['late'], $att_possible ) . '%' : '–', $att_possible ? sprintf( /* translators: 1: absent, 2: late. */ __( '%1$d absent · %2$d late', 'bsa-school-management' ), $att_total['absent'], $att_total['late'] ) : __( 'register not taken', 'bsa-school-management' ) ),
			array( __( 'Exam marks entered this term', 'bsa-school-management' ), $s['term'] ? $pct( $marks_ent, $marks_exp ) . '%' : '–', $s['term'] ? sprintf( /* translators: 1: entered, 2: expected. */ __( '%1$s of %2$s', 'bsa-school-management' ), number_format_i18n( $marks_ent ), number_format_i18n( $marks_exp ) ) : __( 'no current term', 'bsa-school-management' ) ),
		);
		echo '<div class="bsa-tiles">';
		foreach ( $tiles as $t ) {
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html( $t[0] ) . '</div><div class="bsa-tile-value">' . esc_html( $t[1] ) . '</div><div class="bsa-tile-note">' . esc_html( $t[2] ) . '</div></div>';
		}
		echo '</div>';

		echo '<div class="bsa-grid">';
		// Fees per class.
		echo '<section class="bsa-card"><h2>' . esc_html__( 'Fees by class this year', 'bsa-school-management' ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'Collected against invoiced; the bar is the share collected.', 'bsa-school-management' ) . '</p>';
		self::fees_table( $fees );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . BSA_Finance::PAGE ) ) . '">' . esc_html__( 'Fee Reports', 'bsa-school-management' ) . ' →</a></p></section>';

		// Attendance per class.
		$day_label = $s['register_day'] === $s['today'] ? __( 'Register today', 'bsa-school-management' ) : sprintf( /* translators: %s: date. */ __( 'Register on %s (last day taken)', 'bsa-school-management' ), mysql2date( 'l j F', $s['register_day'] ) );
		echo '<section class="bsa-card"><h2>' . esc_html( $day_label ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'Present and late count as attended.', 'bsa-school-management' ) . '</p>';
		echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Attended', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Absent', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Late', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $s['attendance'] as $section => $c ) {
			$name = $s['sections'][ $section ] ?? (string) $section;
			if ( ! $c['marked'] ) {
				$cell = '<span class="bsa-status warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html__( 'Not marked', 'bsa-school-management' ) . '</span>';
			} else {
				$attended = $c['present'] + $c['late'];
				$possible = $attended + $c['absent'];
				/* translators: 1: attended, 2: possible, 3: percent. */
				$cell = self::meter( $attended, $possible, $possible ? sprintf( __( '%1$d of %2$d (%3$d%%)', 'bsa-school-management' ), $attended, $possible, $pct( $attended, $possible ) ) : __( 'holiday', 'bsa-school-management' ) );
				if ( $c['marked'] < $c['learners'] ) {
					/* translators: 1: marked, 2: learners. */
					$cell .= ' <span class="bsa-status warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html( sprintf( __( '%1$d of %2$d learners marked', 'bsa-school-management' ), $c['marked'], $c['learners'] ) ) . '</span>';
				}
			}
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td class="bsa-meter-cell">' . $cell . '</td><td class="num">' . esc_html( $c['marked'] ? (string) $c['absent'] : '–' ) . '</td><td class="num">' . esc_html( $c['marked'] ? (string) $c['late'] : '–' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
		if ( ! $s['attendance'] ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No learners in the current school year.', 'bsa-school-management' ) . '</td></tr>';
		}
		echo '</tbody></table></div><p><a href="' . esc_url( admin_url( 'admin.php?page=' . BSA_Register::PAGE ) ) . '">' . esc_html__( 'Class Register', 'bsa-school-management' ) . ' →</a></p></section>';

		// Marks entry.
		echo '<section class="bsa-card"><h2>' . esc_html( $s['term'] ? sprintf( /* translators: %d: term. */ __( 'Marks entry, Term %d', 'bsa-school-management' ), $s['term']['term'] ) : __( 'Marks entry', 'bsa-school-management' ) ) . '</h2>';
		if ( ! $s['term'] ) {
			echo '<p>' . esc_html__( 'Enter the term dates to follow marks entry.', 'bsa-school-management' ) . '</p>';
		} else {
			echo '<p class="bsa-card-sub">' . esc_html__( 'Exam marks entered for the subjects each learner takes.', 'bsa-school-management' ) . '</p><div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Exam marks entered', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Report cards published', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
			foreach ( $s['marks'] as $section => $m ) {
				/* translators: 1: entered, 2: expected, 3: percent. */
				$text = $m['expected'] ? sprintf( __( '%1$d of %2$d (%3$d%%)', 'bsa-school-management' ), $m['entered'], $m['expected'], $pct( $m['entered'], $m['expected'] ) ) : __( 'no subjects', 'bsa-school-management' );
				echo '<tr><th scope="row">' . esc_html( $s['sections'][ $section ] ?? (string) $section ) . '</th><td class="bsa-meter-cell">' . self::meter( $m['entered'], $m['expected'], $text ) . '</td><td class="num">' . esc_html( $m['published'] . ' / ' . $m['learners'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- meter() escapes.
			}
			echo '</tbody></table></div>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . BSA_Reports::PAGE ) ) . '">' . esc_html__( 'Report Cards', 'bsa-school-management' ) . ' →</a></p></section>';
		echo '</div></div>';
	}

	/**
	 * Screen styles (wp-admin; light surface). Meter: brand blue #376DBE on a lighter
	 * step of the same blue (#DCE6F5); values always written beside the bar.
	 */
	public static function styles() {
		echo '<style>
.bsa-dash{max-width:1400px}
.bsa-dash-sub{color:#50575e;margin:4px 0 16px}
.bsa-dash .bsa-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:0 0 16px}
.bsa-dash .bsa-card h2{margin:0 0 4px;font-size:16px}
.bsa-dash .bsa-card-sub{margin:0 0 12px;color:#50575e}
.bsa-tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 16px}
.bsa-tile{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px}
.bsa-tile-label{color:#50575e;font-size:13px}
.bsa-tile-value{font-size:24px;font-weight:600;line-height:1.3;color:#1d2327;margin:4px 0}
.bsa-tile-note{color:#50575e;font-size:12px}
.bsa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px;align-items:start}
.bsa-grid .bsa-card{margin:0;min-width:0}
.bsa-scroll{overflow-x:auto}
.bsa-table{width:100%;border-collapse:collapse}
.bsa-table th,.bsa-table td{text-align:left;padding:8px 6px;border-bottom:1px solid #f0f0f1;vertical-align:middle;font-weight:400}
.bsa-table thead th{color:#50575e;font-size:12px;font-weight:600}
.bsa-table tbody th{font-weight:600;white-space:nowrap}
.bsa-table .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.bsa-meter{display:block;width:100%;max-width:180px;height:8px;border-radius:4px;background:#DCE6F5;overflow:hidden;margin:2px 0 4px}
.bsa-meter-fill{display:block;height:100%;background:#376DBE;border-radius:4px}
.bsa-meter-text{font-variant-numeric:tabular-nums;color:#1d2327;white-space:nowrap}
.bsa-attention{margin:0}
.bsa-attention li{margin:0 0 6px;padding-left:2px}
.bsa-attention .dashicons,.bsa-status .dashicons,.bsa-ok .dashicons{vertical-align:text-bottom}
.bsa-attention li.warning .dashicons,.bsa-status.warning .dashicons{color:#b26200}
.bsa-attention li.info .dashicons{color:#376DBE}
.bsa-ok .dashicons{color:#0a7d0a}
.bsa-status{white-space:nowrap;color:#1d2327}
@media (min-width:1600px){.bsa-tiles{grid-template-columns:repeat(6,minmax(0,1fr))}}
@media (max-width:782px){.bsa-grid{grid-template-columns:minmax(0,1fr)}.bsa-tiles{grid-template-columns:minmax(0,1fr)}}
@media (max-width:600px){.bsa-dash .bsa-card{padding:12px}.bsa-table th,.bsa-table td{padding:8px 4px}.bsa-table tbody th,.bsa-meter-text,.bsa-status{white-space:normal}.bsa-table tbody th{min-width:70px}.bsa-meter{min-width:72px}}
</style>';
	}
}
