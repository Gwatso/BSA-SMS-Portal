<?php
/**
 * Results dashboards: results by class and subject, learners at risk, progression from
 * term to term, one learner's record across the year, and a progress view for families.
 *
 * Figures come from the BSA marks (exam marks; the same arithmetic as Report Cards:
 * subject pass at 50 % rounded half up, result Pass with five subjects passed) and the
 * Class Register (present and late count as attended).
 *
 * Who sees what (owner decision 2026-10-15):
 * - school administrators: every class; class teachers: their own class; subject teachers:
 *   their subjects in each class (no learner list);
 * - "at risk" flags and class positions are for staff only; families see their own
 *   published results, trend and attendance.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Results, at-risk and progression screens.
 */
class BSA_Results {

	const PAGE   = 'bsa-results';
	const OPTION = 'bsa_sm_risk_rules';

	/**
	 * Fewest register days in a term before attendance can flag a learner.
	 */
	const MIN_DAYS = 10;

	/**
	 * Fewest exam marks before a low average can flag a learner.
	 */
	const MIN_MARKS = 3;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_save_risk_rules', array( __CLASS__, 'handle_rules' ) );
	}

	/**
	 * Default at-risk rules (owner decision; adjustable on the screen).
	 *
	 * @return array{average:int, passes:int, drop:int, attendance:int}
	 */
	public static function defaults() {
		return array( 'average' => 50, 'passes' => 5, 'drop' => 10, 'attendance' => 85 );
	}

	/**
	 * Checks rules typed on the screen.
	 *
	 * @param array $input average, passes, drop, attendance.
	 * @return array{0: array, 1: string[]} [rules, errors]
	 */
	public static function validate_rules( array $input ) {
		$limits = array( 'average' => array( 1, 100 ), 'passes' => array( 1, 13 ), 'drop' => array( 1, 100 ), 'attendance' => array( 1, 100 ) );
		$rules  = array();
		$errors = array();
		foreach ( $limits as $key => $range ) {
			$value = trim( (string) ( $input[ $key ] ?? '' ) );
			if ( ! preg_match( '/^\d{1,3}$/', $value ) || (int) $value < $range[0] || (int) $value > $range[1] ) {
				/* translators: 1: lowest, 2: highest. */
				$errors[ $key ] = sprintf( __( 'Enter a whole number from %1$d to %2$d.', 'bsa-school-management' ), $range[0], $range[1] );
				continue;
			}
			$rules[ $key ] = (int) $value;
		}
		return array( $rules, $errors );
	}

	/**
	 * Rules in force.
	 *
	 * @return array{average:int, passes:int, drop:int, attendance:int}
	 */
	public static function rules() {
		$stored = get_option( self::OPTION, array() );
		list( $rules ) = self::validate_rules( array_merge( self::defaults(), is_array( $stored ) ? $stored : array() ) );
		return array_merge( self::defaults(), $rules );
	}

	/**
	 * Classes whose learners the user may see (school administrators all; class teachers
	 * their own class).
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function sections_for( $user_id ) {
		return BSA_Reports::allowed_sections( $user_id );
	}

	/**
	 * Whether the user may open the Results screen.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can_view( $user_id ) {
		return $user_id && ( self::sections_for( $user_id ) || BSA_Marks::assigned_subjects( $user_id ) );
	}

	/**
	 * Menu entry.
	 */
	public static function register_menu() {
		if ( ! self::user_can_view( get_current_user_id() ) ) {
			return;
		}
		add_menu_page( __( 'Results', 'bsa-school-management' ), __( 'Results', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-chart-line', 33 );
	}

	/**
	 * The term before a term: the previous term of the year, or Term 3 of the previous
	 * school year.
	 *
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return array{0:int,1:int}|null [session, term]
	 */
	public static function previous_term( $session_id, $term ) {
		if ( $term > 1 ) {
			return array( (int) $session_id, $term - 1 );
		}
		$ids = array_keys( BSA_School::sessions() );
		$pos = array_search( (int) $session_id, $ids, true );
		return ( false !== $pos && $pos > 0 ) ? array( (int) $ids[ $pos - 1 ], 3 ) : null;
	}

	/**
	 * The same learner's record in another school year (matched on admission number).
	 *
	 * @param int $student_id Student record ID.
	 * @param int $session_id Other session ID.
	 * @return object|null {ID, section_id}
	 */
	public static function record_in( $student_id, $session_id ) {
		global $wpdb;
		$p   = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, section_id, session_id, admission_number FROM {$p}wlsm_student_records WHERE ID = %d", $student_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return null;
		}
		if ( (int) $row->session_id === (int) $session_id ) {
			return $row;
		}
		if ( '' === trim( (string) $row->admission_number ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT ID, section_id, session_id, admission_number FROM {$p}wlsm_student_records WHERE session_id = %d AND admission_number = %s ORDER BY is_active DESC, ID DESC LIMIT 1", $session_id, $row->admission_number ) );
	}

	/**
	 * Attendance for learners over a term, up to today.
	 *
	 * @param int[] $ids        Student record IDs.
	 * @param int   $session_id Session ID.
	 * @param int   $term       1–3.
	 * @return array<int,array{attended:int,possible:int,absent:int}>
	 */
	public static function attendance( array $ids, $session_id, $term ) {
		$dates = BSA_Terms::get( $session_id, $term );
		$today = current_time( 'Y-m-d' );
		if ( ! $ids || ! $dates || $dates['start'] > $today ) {
			return array();
		}
		return BSA_Register::totals( $ids, $dates['start'], min( $dates['end'], $today ) );
	}

	/**
	 * Every learner of a class with results, the change on the previous term, attendance
	 * and at-risk reasons.
	 *
	 * @param int        $section_id Section ID.
	 * @param int        $session_id Session ID.
	 * @param int        $term       1–3.
	 * @param array|null $rules      At-risk rules (default: in force).
	 * @return array<int,array> student ID => row.
	 */
	public static function learner_rows( $section_id, $session_id, $term, $rules = null ) {
		$rules    = $rules ? $rules : self::rules();
		$learners = BSA_School::learners( $section_id, $session_id );
		$results  = BSA_Reports::class_results( $section_id, $session_id, $term );
		$att      = self::attendance( array_map( 'intval', wp_list_pluck( $learners, 'ID' ) ), $session_id, $term );
		$prev     = self::previous_term( $session_id, $term );
		$out      = array();
		foreach ( $learners as $l ) {
			$id   = (int) $l->ID;
			$r    = $results[ $id ] ?? array( 'recorded' => 0, 'passed' => 0, 'average' => null, 'result' => '', 'position' => null );
			$prev_avg = null;
			if ( $prev ) {
				$rec = self::record_in( $id, $prev[0] );
				if ( $rec ) {
					$prev_avg = BSA_Reports::class_results( (int) $rec->section_id, $prev[0], $prev[1] )[ (int) $rec->ID ]['average'] ?? null;
				}
			}
			$a   = $att[ $id ] ?? array( 'attended' => 0, 'possible' => 0, 'absent' => 0 );
			$row = array(
				'id'         => $id,
				'name'       => $l->name,
				'admission'  => $l->admission_number,
				'subjects'   => count( BSA_Reports::learner_subjects( $id, $section_id ) ),
				'recorded'   => $r['recorded'],
				'passed'     => $r['passed'],
				'average'    => $r['average'],
				'result'     => $r['result'],
				'position'   => $r['position'],
				'previous'   => $prev_avg,
				'change'     => ( null !== $prev_avg && null !== $r['average'] ) ? $r['average'] - $prev_avg : null,
				'attended'   => $a['attended'],
				'possible'   => $a['possible'],
				'rate'       => $a['possible'] ? $a['attended'] / $a['possible'] : null,
			);
			$row['reasons'] = self::risk_reasons( $row, $rules );
			$out[ $id ]     = $row;
		}
		return $out;
	}

	/**
	 * Why a learner is at risk (empty: not at risk).
	 *
	 * @param array $row   From learner_rows().
	 * @param array $rules At-risk rules.
	 * @return array<int,array{code:string, text:string}>
	 */
	public static function risk_reasons( array $row, array $rules ) {
		$out = array();
		if ( null !== $row['average'] && $row['recorded'] >= self::MIN_MARKS && BSA_Grades::round_mark( $row['average'] ) < $rules['average'] ) {
			/* translators: %s: average mark. */
			$out[] = array( 'code' => 'average', 'text' => sprintf( __( 'Average %s', 'bsa-school-management' ), self::pct( $row['average'] ) ) );
		}
		// Fewer passes than required, even if every subject still to be marked is passed.
		if ( $row['recorded'] && $row['passed'] + max( 0, $row['subjects'] - $row['recorded'] ) < $rules['passes'] ) {
			/* translators: 1: subjects passed, 2: subjects marked. */
			$out[] = array( 'code' => 'passes', 'text' => sprintf( __( '%1$d of %2$d subjects passed', 'bsa-school-management' ), $row['passed'], $row['recorded'] ) );
		}
		if ( null !== $row['change'] && -$row['change'] >= $rules['drop'] - 0.0001 ) {
			/* translators: 1: marks lost, 2: previous average, 3: average. */
			$out[] = array( 'code' => 'drop', 'text' => sprintf( __( 'Average down %1$s marks (%2$s → %3$s)', 'bsa-school-management' ), self::num( -$row['change'] ), self::pct( $row['previous'] ), self::pct( $row['average'] ) ) );
		}
		if ( $row['possible'] >= self::MIN_DAYS && 100 * $row['rate'] < $rules['attendance'] ) {
			/* translators: 1: percentage, 2: days attended, 3: register days. */
			$out[] = array( 'code' => 'attendance', 'text' => sprintf( __( 'Attendance %1$s (%2$d of %3$d days)', 'bsa-school-management' ), self::pct( 100 * $row['rate'] ), $row['attended'], $row['possible'] ) );
		}
		return $out;
	}

	/**
	 * Class figures for a term.
	 *
	 * @param array $rows From learner_rows().
	 * @return array{learners:int, with_marks:int, complete:int, average:float|null, passing:int, pass_rate:float|null, at_risk:int}
	 */
	public static function summarise( array $rows ) {
		$avgs     = array();
		$complete = 0;
		$passing  = 0;
		$risk     = 0;
		foreach ( $rows as $r ) {
			if ( null !== $r['average'] ) {
				$avgs[] = $r['average'];
			}
			if ( $r['recorded'] && $r['recorded'] >= $r['subjects'] ) {
				++$complete;
				if ( 'Pass' === $r['result'] ) {
					++$passing;
				}
			}
			if ( $r['reasons'] ) {
				++$risk;
			}
		}
		return array(
			'learners'   => count( $rows ),
			'with_marks' => count( $avgs ),
			'complete'   => $complete,
			'average'    => $avgs ? array_sum( $avgs ) / count( $avgs ) : null,
			'passing'    => $passing,
			'pass_rate'  => $complete ? $passing / $complete : null,
			'at_risk'    => $risk,
		);
	}

	/**
	 * Results per subject of a class: entries, average, passes and grades.
	 *
	 * @param int        $section_id Section ID.
	 * @param int        $session_id Session ID.
	 * @param int        $term       1–3.
	 * @param int[]|null $only       Subject IDs to include (null: all).
	 * @return array<int,array{label:string, entries:int, average:float|null, passes:int, grades:array<string,int>}>
	 */
	public static function subjects( $section_id, $session_id, $term, $only = null ) {
		$section = BSA_School::section( $section_id );
		if ( ! $section ) {
			return array();
		}
		$keys  = BSA_Grades::keys()[ BSA_Grades::scheme( $section->class_label ) ];
		$blank = array_fill_keys( array_values( $keys ), 0 );
		$out   = array();
		foreach ( BSA_Marks::section_subjects( $section_id ) as $s ) {
			if ( null === $only || in_array( (int) $s->ID, $only, true ) ) {
				$out[ (int) $s->ID ] = array( 'label' => $s->label, 'entries' => 0, 'average' => null, 'passes' => 0, 'grades' => $blank, 'sum' => 0.0 );
			}
		}
		$ids   = array_map( 'intval', wp_list_pluck( BSA_School::learners( $section_id, $session_id ), 'ID' ) );
		$marks = BSA_Marks::for_learners( $ids, $session_id, $term );
		foreach ( $ids as $id ) {
			foreach ( BSA_Reports::learner_subjects( $id, $section_id ) as $s ) {
				$sid = (int) $s->ID;
				$m   = $marks[ $id ][ $sid ] ?? null;
				if ( ! isset( $out[ $sid ] ) || ! $m || null === $m->exam_mark ) {
					continue;
				}
				++$out[ $sid ]['entries'];
				$out[ $sid ]['sum'] += (float) $m->exam_mark;
				if ( BSA_Grades::is_pass( $m->exam_mark ) ) {
					++$out[ $sid ]['passes'];
				}
				++$out[ $sid ]['grades'][ BSA_Grades::grade( $m->exam_mark, $section->class_label ) ];
			}
		}
		foreach ( $out as $sid => $row ) {
			$out[ $sid ]['average'] = $row['entries'] ? $row['sum'] / $row['entries'] : null;
			unset( $out[ $sid ]['sum'] );
		}
		return $out;
	}

	/**
	 * A class's figures for each term of a school year.
	 *
	 * @param int $section_id Section ID.
	 * @param int $session_id Session ID.
	 * @return array<int,array> term => summarise() figures.
	 */
	public static function progression( $section_id, $session_id ) {
		$out = array();
		for ( $t = 1; $t <= 3; $t++ ) {
			$out[ $t ] = self::summarise( self::learner_rows( $section_id, $session_id, $t ) );
		}
		return $out;
	}

	/**
	 * Learners at risk in a term across the given classes.
	 *
	 * @param int[] $sections   Section IDs.
	 * @param int   $session_id Session ID.
	 * @param int   $term       1–3.
	 * @return array<int,array> Rows with 'section' added, most reasons first.
	 */
	public static function at_risk( array $sections, $session_id, $term ) {
		$out = array();
		foreach ( $sections as $section ) {
			foreach ( self::learner_rows( $section, $session_id, $term ) as $row ) {
				if ( $row['reasons'] ) {
					$out[] = array_merge( $row, array( 'section' => (int) $section ) );
				}
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( count( $b['reasons'] ), $a['average'] ?? 101 ) <=> array( count( $a['reasons'] ), $b['average'] ?? 101 );
			}
		);
		return $out;
	}

	/**
	 * One learner's marks for each term of a school year.
	 *
	 * @param int $student_id Student record ID.
	 * @param int $section_id Section ID.
	 * @param int $session_id Session ID.
	 * @return array{subjects: array<int,array{label:string, marks:array<int,float|null>}>, terms: array<int,array>}
	 */
	public static function learner_year( $student_id, $section_id, $session_id ) {
		$subjects = array();
		foreach ( BSA_Reports::learner_subjects( $student_id, $section_id ) as $s ) {
			$subjects[ (int) $s->ID ] = array( 'label' => $s->label, 'marks' => array( 1 => null, 2 => null, 3 => null ) );
		}
		$terms = array();
		for ( $t = 1; $t <= 3; $t++ ) {
			$marks = BSA_Marks::for_learners( array( $student_id ), $session_id, $t )[ (int) $student_id ] ?? array();
			foreach ( $subjects as $sid => $row ) {
				$m = $marks[ $sid ] ?? null;
				if ( $m && null !== $m->exam_mark ) {
					$subjects[ $sid ]['marks'][ $t ] = (float) $m->exam_mark;
				}
			}
			$terms[ $t ] = self::learner_rows( $section_id, $session_id, $t )[ (int) $student_id ] ?? null;
		}
		return array( 'subjects' => $subjects, 'terms' => $terms );
	}

	/**
	 * A number with up to one decimal, e.g. "15" or "4.5".
	 *
	 * @param float $value Value.
	 * @return string
	 */
	public static function num( $value ) {
		$v = round( (float) $value, 1 );
		return floor( $v ) === $v ? number_format_i18n( $v ) : number_format_i18n( $v, 1 );
	}

	/**
	 * A percentage with up to one decimal, e.g. "63.5%".
	 *
	 * @param float|null $value Value 0–100.
	 * @return string '–' for none.
	 */
	public static function pct( $value ) {
		if ( null === $value ) {
			return '–';
		}
		return self::num( $value ) . '%';
	}

	/**
	 * A change in marks with its sign, e.g. "+4.5" or "−12".
	 *
	 * @param float|null $change Change.
	 * @return string '–' for none.
	 */
	public static function signed( $change ) {
		if ( null === $change ) {
			return '–';
		}
		$v = round( (float) $change, 1 );
		return ( $v > 0 ? '+' : ( $v < 0 ? '−' : '' ) ) . self::num( abs( $v ) );
	}

	/**
	 * Small line chart of values across terms (one series, values written at each point).
	 *
	 * @param array<string,float|null> $values Label => value (0–100), in order.
	 * @param string                   $title  Accessible name.
	 * @return string SVG, or '' with fewer than two values.
	 */
	public static function sparkline( array $values, $title ) {
		$points = array_filter(
			$values,
			static function ( $v ) {
				return null !== $v;
			}
		);
		if ( count( $points ) < 2 ) {
			return '';
		}
		$w      = 44 * count( $values ) + 16;
		$h      = 64;
		$top    = 18;
		$bottom = 46;
		$min    = max( 0, floor( min( $points ) / 10 ) * 10 - 10 );
		$max    = min( 100, ceil( max( $points ) / 10 ) * 10 + 10 );
		$y      = static function ( $v ) use ( $min, $max, $top, $bottom ) {
			return $bottom - ( $v - $min ) / max( 1, $max - $min ) * ( $bottom - $top );
		};
		$i      = 0;
		$line   = array();
		$marks  = '';
		$labels = array();
		foreach ( $values as $label => $v ) {
			$x = 8 + 22 + 44 * $i;
			$marks .= '<text x="' . $x . '" y="60" text-anchor="middle" class="bsa-spark-axis">' . esc_html( $label ) . '</text>';
			if ( null !== $v ) {
				$py      = round( $y( $v ), 1 );
				$line[]  = $x . ',' . $py;
				$marks  .= '<circle cx="' . $x . '" cy="' . $py . '" r="4" class="bsa-spark-dot"><title>' . esc_html( $label . ': ' . self::pct( $v ) ) . '</title></circle>';
				$marks  .= '<text x="' . $x . '" y="' . ( $py - 8 ) . '" text-anchor="middle" class="bsa-spark-value">' . esc_html( (string) BSA_Grades::round_mark( $v ) ) . '</text>';
				$labels[] = $label . ' ' . self::pct( $v );
			}
			++$i;
		}
		return '<svg class="bsa-spark" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr( $title . ': ' . implode( ', ', $labels ) ) . '"><polyline points="' . esc_attr( implode( ' ', $line ) ) . '" class="bsa-spark-line"/>' . $marks . '</svg>';
	}

	/**
	 * Screen and family view styles.
	 *
	 * @return string CSS.
	 */
	public static function chart_css() {
		return '.bsa-spark{display:block;overflow:visible}.bsa-spark-line{fill:none;stroke:#376DBE;stroke-width:2;stroke-linejoin:round;stroke-linecap:round}.bsa-spark-dot{fill:#376DBE;stroke:#fff;stroke-width:2}.bsa-spark-value{font-size:11px;font-weight:600;fill:#1d2327}.bsa-spark-axis{font-size:10px;fill:#50575e}';
	}

	/**
	 * Validated view parameters.
	 *
	 * @param array $source  $_GET (unslashed).
	 * @param int   $user_id User ID.
	 * @return array
	 */
	public static function state( array $source, $user_id ) {
		$sections = self::sections_for( $user_id );
		$current  = BSA_Terms::current();
		$sessions = BSA_School::sessions();
		$session  = absint( $source['session'] ?? 0 );
		$session  = isset( $sessions[ $session ] ) ? $session : ( $current ? $current['session'] : BSA_School::current_session() );
		$term     = absint( $source['term'] ?? 0 );
		$term     = ( $term >= 1 && $term <= 3 ) ? $term : ( ( $current && $current['session'] === $session ) ? $current['term'] : 1 );
		$with     = array_values(
			array_filter(
				$sections,
				static function ( $s ) use ( $session ) {
					return (bool) BSA_School::learners( $s, $session );
				}
			)
		);
		$section  = absint( $source['section'] ?? 0 );
		$section  = in_array( $section, $sections, true ) ? $section : (int) ( $with[0] ?? ( $sections[0] ?? 0 ) );
		$tab      = sanitize_key( $source['tab'] ?? 'overview' );
		$tabs     = $sections ? array( 'overview', 'risk', 'progress', 'learner' ) : array( 'overview' );
		return array(
			'sections' => $sections,
			'with'     => $with,
			'section'  => $section,
			'session'  => $session,
			'term'     => $term,
			'tab'      => in_array( $tab, $tabs, true ) ? $tab : 'overview',
			'student'  => absint( $source['student'] ?? 0 ),
			'admin'    => BSA_School::is_school_admin( $user_id ),
			'subjects' => BSA_Marks::assigned_subjects( $user_id ),
		);
	}

	/**
	 * Screen URL with view parameters.
	 *
	 * @param array $s    State.
	 * @param array $args Overrides.
	 * @return string
	 */
	private static function url( array $s, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'session' => $s['session'], 'term' => $s['term'], 'section' => $s['section'] ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Reasons as icon + label chips (never colour alone).
	 *
	 * @param array $reasons From risk_reasons().
	 * @return string
	 */
	private static function reason_list( array $reasons ) {
		$out = '<ul class="bsa-reasons">';
		foreach ( $reasons as $r ) {
			$out .= '<li class="bsa-reason bsa-reason-' . esc_attr( $r['code'] ) . '"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html( $r['text'] ) . '</li>';
		}
		return $out . '</ul>';
	}

	/**
	 * The Results screen.
	 */
	public static function render_page() {
		$user_id = get_current_user_id();
		if ( ! self::user_can_view( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to view results.', 'bsa-school-management' ), 403 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$s      = self::state( wp_unslash( $_GET ), $user_id );
		$notice = sanitize_key( wp_unslash( $_GET['bsa_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap bsa-dash bsa-results"><h1>' . esc_html__( 'Results', 'bsa-school-management' ) . '</h1>';
		BSA_Dashboard::styles();
		echo '<style>' . self::chart_css() . '.bsa-results .nav-tab-wrapper{margin:0 0 16px}.bsa-results .bsa-filters{margin:0 0 16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}.bsa-reasons{margin:0;list-style:none}.bsa-reasons li{margin:0 0 2px;white-space:normal}.bsa-reasons .dashicons{color:#b26200;font-size:16px;width:16px;height:16px;vertical-align:text-bottom}.bsa-grades{white-space:nowrap;font-variant-numeric:tabular-nums}.bsa-grades span{display:inline-block;margin-right:8px}.bsa-up{color:#0a6b0a}.bsa-down{color:#a83200}.bsa-rules input[type=number]{width:5em}.bsa-results .bsa-tiles{grid-template-columns:repeat(4,minmax(0,1fr))}@media (max-width:1100px){.bsa-results .bsa-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:600px){.bsa-results .bsa-tiles{grid-template-columns:minmax(0,1fr)}}.bsa-rules p{margin:6px 0}.bsa-results .bsa-note{color:#50575e}.bsa-results tr.bsa-total th,.bsa-results tr.bsa-total td{background:#F6F7F9}</style>';
		if ( 'rules' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'At-risk rules saved.', 'bsa-school-management' ) . '</p></div>';
		} elseif ( 'rules_failed' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The rules were not saved: each must be a whole number in range.', 'bsa-school-management' ) . '</p></div>';
		}

		$tabs = array(
			'overview' => __( 'Overview', 'bsa-school-management' ),
			'risk'     => __( 'Learners at risk', 'bsa-school-management' ),
			'progress' => __( 'Progression', 'bsa-school-management' ),
		);
		if ( $s['sections'] ) {
			echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Results views', 'bsa-school-management' ) . '">';
			foreach ( $tabs as $key => $label ) {
				$active = $key === $s['tab'] || ( 'learner' === $s['tab'] && 'risk' === $key );
				echo '<a class="nav-tab' . ( $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $s, array( 'tab' => $key ) ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			echo '</nav>';
		}

		// Filters.
		echo '<form method="get" class="bsa-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="' . esc_attr( $s['tab'] ) . '">';
		if ( 'learner' === $s['tab'] ) {
			echo '<input type="hidden" name="student" value="' . esc_attr( (string) $s['student'] ) . '">';
		}
		if ( $s['sections'] && in_array( $s['tab'], array( 'overview', 'progress' ), true ) ) {
			echo '<label for="bsa-section">' . esc_html__( 'Class', 'bsa-school-management' ) . '</label> <select id="bsa-section" name="section">';
			foreach ( $s['sections'] as $id ) {
				printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $s['section'], $id, false ), esc_html( BSA_School::section( $id )->title ) );
			}
			echo '</select>';
		}
		echo '<label for="bsa-session">' . esc_html__( 'Year', 'bsa-school-management' ) . '</label> <select id="bsa-session" name="session">';
		foreach ( BSA_School::sessions() as $id => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $s['session'], $id, false ), esc_html( $label ) );
		}
		echo '</select>';
		if ( 'progress' !== $s['tab'] ) {
			echo '<label for="bsa-term">' . esc_html__( 'Term', 'bsa-school-management' ) . '</label> <select id="bsa-term" name="term">';
			for ( $t = 1; $t <= 3; $t++ ) {
				printf( '<option value="%d"%s>%d</option>', (int) $t, selected( $s['term'], $t, false ), (int) $t );
			}
			echo '</select>';
		}
		submit_button( __( 'Show', 'bsa-school-management' ), 'secondary', '', false );
		echo '</form>';

		if ( ! $s['sections'] ) {
			self::render_subject_teacher( $s );
		} elseif ( 'risk' === $s['tab'] ) {
			self::render_risk( $s );
		} elseif ( 'progress' === $s['tab'] ) {
			self::render_progress( $s );
		} elseif ( 'learner' === $s['tab'] ) {
			self::render_learner( $s );
		} else {
			self::render_overview( $s );
		}
		echo '<p class="bsa-note">' . esc_html__( 'Exam marks only. A subject is passed at 50% or more; the result is Pass with five or more subjects passed. Pass rates count learners with all their exam marks entered.', 'bsa-school-management' ) . '</p></div>';
	}

	/**
	 * Overview: figures, classes and the selected class's subjects.
	 *
	 * @param array $s State.
	 */
	private static function render_overview( array $s ) {
		$classes = array();
		$all     = array();
		foreach ( $s['sections'] as $section ) {
			$rows = self::learner_rows( $section, $s['session'], $s['term'] );
			if ( ! $rows ) {
				continue;
			}
			$classes[ $section ] = self::summarise( $rows );
			$all                 = array_merge( $all, array_values( $rows ) );
		}
		$total = self::summarise( $all );
		echo '<div class="bsa-tiles">';
		$tiles = array(
			array( __( 'Learners with exam marks', 'bsa-school-management' ), number_format_i18n( $total['with_marks'] ), sprintf( /* translators: %d: learners. */ __( 'of %d learners', 'bsa-school-management' ), $total['learners'] ) ),
			array( __( 'Average exam mark', 'bsa-school-management' ), self::pct( $total['average'] ), __( 'mean of learners\' averages', 'bsa-school-management' ) ),
			array( __( 'Pass rate', 'bsa-school-management' ), null === $total['pass_rate'] ? '–' : self::pct( 100 * $total['pass_rate'] ), sprintf( /* translators: 1: passing, 2: complete. */ __( '%1$d of %2$d with all marks in', 'bsa-school-management' ), $total['passing'], $total['complete'] ) ),
			array( __( 'Learners at risk', 'bsa-school-management' ), number_format_i18n( $total['at_risk'] ), __( 'see the reasons', 'bsa-school-management' ) ),
		);
		foreach ( $tiles as $i => $t ) {
			$note = 3 === $i ? '<a href="' . esc_url( self::url( $s, array( 'tab' => 'risk' ) ) ) . '">' . esc_html( $t[2] ) . '</a>' : esc_html( $t[2] );
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html( $t[0] ) . '</div><div class="bsa-tile-value">' . esc_html( $t[1] ) . '</div><div class="bsa-tile-note">' . $note . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		echo '</div>';
		if ( ! $classes ) {
			echo '<section class="bsa-card"><p>' . esc_html__( 'No learners in these classes for the selected year.', 'bsa-school-management' ) . '</p></section>';
			return;
		}

		/* translators: %d: term. */
		echo '<div class="bsa-grid"><section class="bsa-card"><h2>' . esc_html( sprintf( __( 'Classes, Term %d', 'bsa-school-management' ), $s['term'] ) ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'The bar is the pass rate.', 'bsa-school-management' ) . '</p>';
		echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Average', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Pass rate', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'At risk', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $classes as $section => $c ) {
			$text = null === $c['pass_rate'] ? __( 'no complete results yet', 'bsa-school-management' ) : sprintf( /* translators: 1: passing, 2: complete, 3: percent. */ __( '%1$d of %2$d (%3$s)', 'bsa-school-management' ), $c['passing'], $c['complete'], self::pct( 100 * $c['pass_rate'] ) );
			echo '<tr><th scope="row"><a href="' . esc_url( self::url( $s, array( 'section' => $section ) ) ) . '">' . esc_html( BSA_School::section( $section )->title ) . '</a></th><td class="num">' . esc_html( self::pct( $c['average'] ) ) . '</td><td class="bsa-meter-cell">' . BSA_Dashboard::meter( $c['passing'], $c['complete'], $text ) . '</td><td class="num"><a href="' . esc_url( self::url( $s, array( 'tab' => 'risk' ) ) ) . '">' . esc_html( (string) $c['at_risk'] ) . '</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- meter() escapes.
		}
		echo '</tbody></table></div></section>';

		$sec      = BSA_School::section( $s['section'] );
		$subjects = $sec ? self::subjects( $s['section'], $s['session'], $s['term'] ) : array();
		/* translators: 1: class, 2: term. */
		echo '<section class="bsa-card"><h2>' . esc_html( sprintf( __( 'Subjects, %1$s, Term %2$d', 'bsa-school-management' ), $sec ? $sec->title : '', $s['term'] ) ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'The bar is the share of entries passed. Grades use the class\'s grade key.', 'bsa-school-management' ) . '</p>';
		self::subject_table( $subjects );
		echo '</section></div>';
	}

	/**
	 * Subject results table.
	 *
	 * @param array  $subjects From subjects().
	 * @param string $class    Optional class column value per row (subject teacher view).
	 */
	private static function subject_table( array $subjects, $class = '' ) {
		$with = array_filter(
			$subjects,
			static function ( $r ) {
				return $r['entries'] > 0;
			}
		);
		if ( ! $with ) {
			echo '<p>' . esc_html__( 'No exam marks entered yet for this term.', 'bsa-school-management' ) . '</p>';
			return;
		}
		echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr>' . ( $class ? '<th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th>' : '' ) . '<th scope="col">' . esc_html__( 'Subject', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Average', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Passed', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Grades', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $with as $r ) {
			/* translators: 1: passes, 2: entries. */
			$text   = sprintf( __( '%1$d of %2$d', 'bsa-school-management' ), $r['passes'], $r['entries'] );
			$grades = '';
			foreach ( $r['grades'] as $g => $n ) {
				$grades .= '<span>' . esc_html( $g . ': ' . $n ) . '</span>';
			}
			echo '<tr>' . ( $class ? '<td>' . esc_html( $r['class'] ?? '' ) . '</td>' : '' ) . '<th scope="row">' . esc_html( $r['label'] ) . '</th><td class="num">' . esc_html( self::pct( $r['average'] ) ) . '</td><td class="bsa-meter-cell">' . BSA_Dashboard::meter( $r['passes'], $r['entries'], $text ) . '</td><td class="bsa-grades">' . $grades . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped parts.
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Subject teachers: their subjects in each class.
	 *
	 * @param array $s State.
	 */
	private static function render_subject_teacher( array $s ) {
		$rows = array();
		foreach ( BSA_School::sections() as $section ) {
			if ( ! BSA_School::learners( $section->ID, $s['session'] ) ) {
				continue;
			}
			foreach ( self::subjects( (int) $section->ID, $s['session'], $s['term'], $s['subjects'] ) as $r ) {
				$rows[] = array_merge( $r, array( 'class' => $section->title ) );
			}
		}
		/* translators: %d: term. */
		echo '<section class="bsa-card"><h2>' . esc_html( sprintf( __( 'My subjects, Term %d', 'bsa-school-management' ), $s['term'] ) ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'Results of the subjects you teach, in each class.', 'bsa-school-management' ) . '</p>';
		self::subject_table( $rows, 'class' );
		echo '</section>';
	}

	/**
	 * Learners at risk with reasons, and the rules.
	 *
	 * @param array $s State.
	 */
	private static function render_risk( array $s ) {
		$rules = self::rules();
		$rows  = self::at_risk( $s['sections'], $s['session'], $s['term'] );
		/* translators: 1: number, 2: term. */
		echo '<section class="bsa-card"><h2>' . esc_html( sprintf( _n( '%1$d learner at risk, Term %2$d', '%1$d learners at risk, Term %2$d', count( $rows ), 'bsa-school-management' ), count( $rows ), $s['term'] ) ) . '</h2>';
		/* translators: 1: average, 2: passes, 3: drop, 4: attendance. */
		echo '<p class="bsa-card-sub">' . esc_html( sprintf( __( 'Flagged when any of these apply: average below %1$d%%; fewer than %2$d subjects passed (counting subjects still to be marked as passes); average down %3$d marks or more on the previous term; attendance below %4$d%%. Only staff see this list.', 'bsa-school-management' ), $rules['average'], $rules['passes'], $rules['drop'], $rules['attendance'] ) ) . '</p>';
		if ( ! $rows ) {
			echo '<p class="bsa-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> ' . esc_html__( 'No learners at risk for this term.', 'bsa-school-management' ) . '</p>';
		} else {
			echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Average', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Change', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Attendance', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Why', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><th scope="row"><a href="' . esc_url( self::url( $s, array( 'tab' => 'learner', 'student' => $r['id'], 'section' => $r['section'] ) ) ) . '">' . esc_html( $r['name'] ) . '</a></th><td>' . esc_html( BSA_School::section( $r['section'] )->title ) . '</td><td class="num">' . esc_html( self::pct( $r['average'] ) ) . '</td><td class="num">' . esc_html( self::signed( $r['change'] ) ) . '</td><td class="num">' . esc_html( null === $r['rate'] ? '–' : self::pct( 100 * $r['rate'] ) ) . '</td><td>' . self::reason_list( $r['reasons'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- reason_list() escapes.
			}
			echo '</tbody></table></div>';
		}
		/* translators: 1: register days, 2: exam marks. */
		echo '<p class="bsa-note">' . esc_html( sprintf( __( 'Attendance counts once at least %1$d register days are recorded this term; a low average once at least %2$d exam marks are in.', 'bsa-school-management' ), self::MIN_DAYS, self::MIN_MARKS ) ) . '</p></section>';

		if ( $s['admin'] ) {
			$fields = array(
				'average'    => __( 'Average below (%)', 'bsa-school-management' ),
				'passes'     => __( 'Fewer subjects passed than', 'bsa-school-management' ),
				'drop'       => __( 'Average down by at least (marks)', 'bsa-school-management' ),
				'attendance' => __( 'Attendance below (%)', 'bsa-school-management' ),
			);
			echo '<section class="bsa-card bsa-rules"><h2>' . esc_html__( 'At-risk rules', 'bsa-school-management' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_save_risk_rules">';
			wp_nonce_field( 'bsa_save_risk_rules' );
			echo '<input type="hidden" name="back" value="' . esc_attr( self::url( $s, array( 'tab' => 'risk' ) ) ) . '">';
			foreach ( $fields as $key => $label ) {
				echo '<p><label for="bsa-rule-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label> <input type="number" min="1" max="' . ( 'passes' === $key ? 13 : 100 ) . '" step="1" id="bsa-rule-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $rules[ $key ] ) . '"></p>';
			}
			submit_button( __( 'Save rules', 'bsa-school-management' ), 'secondary', 'submit', false );
			echo '</form></section>';
		}
	}

	/**
	 * Progression: each class term by term, and the biggest changes in the selected class.
	 *
	 * @param array $s State.
	 */
	private static function render_progress( array $s ) {
		/* translators: %s: year. */
		echo '<div class="bsa-grid"><section class="bsa-card"><h2>' . esc_html( sprintf( __( 'Class averages by term, %s', 'bsa-school-management' ), BSA_School::sessions()[ $s['session'] ] ?? '' ) ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'Average exam mark per term; the line shows the trend.', 'bsa-school-management' ) . '</p>';
		echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Term 1', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Term 2', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Term 3', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Trend', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		$any = false;
		foreach ( $s['with'] as $section ) {
			$p     = self::progression( $section, $s['session'] );
			$title = BSA_School::section( $section )->title;
			$vals  = array( 'T1' => $p[1]['average'], 'T2' => $p[2]['average'], 'T3' => $p[3]['average'] );
			$any   = true;
			/* translators: %s: class. */
			echo '<tr><th scope="row"><a href="' . esc_url( self::url( $s, array( 'tab' => 'progress', 'section' => $section ) ) ) . '">' . esc_html( $title ) . '</a></th><td class="num">' . esc_html( self::pct( $p[1]['average'] ) ) . '</td><td class="num">' . esc_html( self::pct( $p[2]['average'] ) ) . '</td><td class="num">' . esc_html( self::pct( $p[3]['average'] ) ) . '</td><td>' . ( self::sparkline( $vals, sprintf( __( '%s average by term', 'bsa-school-management' ), $title ) ) ?: '<span class="bsa-note">' . esc_html__( 'needs two terms', 'bsa-school-management' ) . '</span>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sparkline() escapes.
		}
		if ( ! $any ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No learners in these classes for the selected year.', 'bsa-school-management' ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';

		// Biggest changes in the selected class: latest term with results against the one before.
		$sec    = BSA_School::section( $s['section'] );
		$latest = 0;
		for ( $t = 3; $t >= 1; $t-- ) {
			$rows = self::learner_rows( $s['section'], $s['session'], $t );
			if ( array_filter( wp_list_pluck( $rows, 'average' ), 'is_numeric' ) ) {
				$latest = $t;
				break;
			}
		}
		/* translators: %s: class. */
		echo '<section class="bsa-card"><h2>' . esc_html( sprintf( __( 'Biggest changes, %s', 'bsa-school-management' ), $sec ? $sec->title : '' ) ) . '</h2>';
		$changes = $latest ? array_filter(
			self::learner_rows( $s['section'], $s['session'], $latest ),
			static function ( $r ) {
				return null !== $r['change'];
			}
		) : array();
		if ( ! $changes ) {
			echo '<p>' . esc_html__( 'Changes appear once a learner has results in two terms in a row.', 'bsa-school-management' ) . '</p></section></div>';
			return;
		}
		/* translators: %d: term. */
		echo '<p class="bsa-card-sub">' . esc_html( sprintf( __( 'Term %d average against the previous term.', 'bsa-school-management' ), $latest ) ) . '</p>';
		usort(
			$changes,
			static function ( $a, $b ) {
				return $b['change'] <=> $a['change'];
			}
		);
		$up   = array_slice( array_filter( $changes, static function ( $r ) { return $r['change'] > 0; } ), 0, 5 ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment
		$down = array_slice( array_reverse( array_filter( $changes, static function ( $r ) { return $r['change'] < 0; } ) ), 0, 5 ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment
		foreach ( array( array( __( 'Most improved', 'bsa-school-management' ), $up, 'bsa-up', 'arrow-up-alt' ), array( __( 'Biggest drops', 'bsa-school-management' ), $down, 'bsa-down', 'arrow-down-alt' ) ) as $block ) {
			echo '<h3>' . esc_html( $block[0] ) . '</h3>';
			if ( ! $block[1] ) {
				echo '<p>' . esc_html__( 'None.', 'bsa-school-management' ) . '</p>';
				continue;
			}
			echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Before', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Now', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Change', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
			foreach ( $block[1] as $r ) {
				echo '<tr><th scope="row"><a href="' . esc_url( self::url( $s, array( 'tab' => 'learner', 'student' => $r['id'], 'term' => $latest ) ) ) . '">' . esc_html( $r['name'] ) . '</a></th><td class="num">' . esc_html( self::pct( $r['previous'] ) ) . '</td><td class="num">' . esc_html( self::pct( $r['average'] ) ) . '</td><td class="num ' . esc_attr( $block[2] ) . '"><span class="dashicons dashicons-' . esc_attr( $block[3] ) . '" aria-hidden="true"></span> ' . esc_html( self::signed( $r['change'] ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</section></div>';
	}

	/**
	 * One learner: marks per subject and term, results, attendance and reasons.
	 *
	 * @param array $s State.
	 */
	private static function render_learner( array $s ) {
		global $wpdb;
		$rec = $wpdb->get_row( $wpdb->prepare( "SELECT ID, name, admission_number, section_id, session_id FROM {$wpdb->prefix}wlsm_student_records WHERE ID = %d", $s['student'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rec || ! in_array( (int) $rec->section_id, $s['sections'], true ) ) {
			echo '<section class="bsa-card"><p>' . esc_html__( 'This learner is not in your classes.', 'bsa-school-management' ) . '</p></section>';
			return;
		}
		$section = (int) $rec->section_id;
		$session = (int) $rec->session_id;
		$sec     = BSA_School::section( $section );
		$year    = self::learner_year( (int) $rec->ID, $section, $session );
		/* translators: 1: name, 2: class, 3: year. */
		echo '<section class="bsa-card"><h2>' . esc_html( sprintf( __( '%1$s, %2$s, %3$s', 'bsa-school-management' ), $rec->name, $sec->title, BSA_School::sessions()[ $session ] ?? '' ) ) . '</h2><p class="bsa-card-sub">' . esc_html( __( 'Admission number', 'bsa-school-management' ) . ': ' . $rec->admission_number ) . '</p>';
		$reasons = $year['terms'][ $s['term'] ]['reasons'] ?? array();
		if ( $reasons && $session === $s['session'] ) {
			/* translators: %d: term. */
			echo '<h3>' . esc_html( sprintf( __( 'At risk in Term %d', 'bsa-school-management' ), $s['term'] ) ) . '</h3>' . self::reason_list( $reasons ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- reason_list() escapes.
		}
		echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Subject', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Term 1', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Term 2', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Term 3', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Trend', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		$cell = static function ( $mark ) use ( $sec ) {
			return null === $mark ? '–' : BSA_Marks::show( $mark ) . ' (' . BSA_Grades::grade( $mark, $sec->class_label ) . ')';
		};
		foreach ( $year['subjects'] as $row ) {
			$m = $row['marks'];
			echo '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td class="num">' . esc_html( $cell( $m[1] ) ) . '</td><td class="num">' . esc_html( $cell( $m[2] ) ) . '</td><td class="num">' . esc_html( $cell( $m[3] ) ) . '</td><td>' . self::sparkline( array( 'T1' => $m[1], 'T2' => $m[2], 'T3' => $m[3] ), $row['label'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sparkline() escapes.
		}
		$sum = static function ( $t ) use ( $year ) {
			return $year['terms'][ $t ] ?? null;
		};
		$lines = array(
			__( 'Average', 'bsa-school-management' )         => static function ( $r ) {
				return $r ? self::pct( $r['average'] ) : '–';
			},
			__( 'Subjects passed', 'bsa-school-management' ) => static function ( $r ) {
				/* translators: 1: passed, 2: marked. */
				return $r && $r['recorded'] ? sprintf( __( '%1$d of %2$d', 'bsa-school-management' ), $r['passed'], $r['recorded'] ) : '–';
			},
			__( 'Position', 'bsa-school-management' )        => static function ( $r ) {
				return $r && $r['position'] ? (string) $r['position'] : '–';
			},
			__( 'Attendance', 'bsa-school-management' )      => static function ( $r ) {
				return $r && null !== $r['rate'] ? self::pct( 100 * $r['rate'] ) : '–';
			},
		);
		foreach ( $lines as $label => $fn ) {
			echo '<tr class="bsa-total"><th scope="row">' . esc_html( $label ) . '</th><td class="num">' . esc_html( $fn( $sum( 1 ) ) ) . '</td><td class="num">' . esc_html( $fn( $sum( 2 ) ) ) . '</td><td class="num">' . esc_html( $fn( $sum( 3 ) ) ) . '</td><td></td></tr>';
		}
		echo '</tbody></table></div><p><a href="' . esc_url( admin_url( 'admin.php?page=' . BSA_Reports::PAGE . '&section=' . $section . '&session=' . $session . '&term=' . $s['term'] ) ) . '">' . esc_html__( 'Report Cards', 'bsa-school-management' ) . ' →</a></p></section>';
	}

	/**
	 * Saves the at-risk rules (school administrators).
	 */
	public static function handle_rules() {
		check_admin_referer( 'bsa_save_risk_rules' );
		if ( ! BSA_School::is_school_admin( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You cannot change the at-risk rules.', 'bsa-school-management' ), 403 );
		}
		list( $rules, $errors ) = self::validate_rules( wp_unslash( $_POST ) );
		if ( ! $errors ) {
			update_option( self::OPTION, $rules, false );
		}
		$back = wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['back'] ?? '' ) ), admin_url( 'admin.php?page=' . self::PAGE . '&tab=risk' ) );
		wp_safe_redirect( add_query_arg( 'bsa_notice', $errors ? 'rules_failed' : 'rules', $back ) );
		exit;
	}

	/**
	 * Families: progress from published report cards (no position, no at-risk flag).
	 *
	 * @param int[] $student_ids The learner's records (one per school year).
	 * @return string HTML.
	 */
	public static function family_progress( array $student_ids ) {
		$rows = array_reverse( BSA_Family::published( $student_ids ) );
		if ( ! $rows ) {
			return '';
		}
		$terms  = array();
		$latest = null;
		foreach ( $rows as $r ) {
			$res = BSA_Reports::class_results( (int) $r->section_id, (int) $r->session_id, (int) $r->term )[ (int) $r->student_record_id ] ?? null;
			$att = BSA_Register::for_report( (int) $r->student_record_id, (int) $r->session_id, (int) $r->term );
			/* translators: 1: term, 2: year. */
			$terms[] = array( 'label' => sprintf( __( 'Term %1$d, %2$s', 'bsa-school-management' ), $r->term, $r->year ), 'short' => 'T' . $r->term, 'res' => $res, 'att' => $att, 'row' => $r );
			$latest  = $r;
		}
		$out = '<div class="wlsm-st-main-title bsa-family-progress-title"><span>' . esc_html__( 'Progress', 'bsa-school-management' ) . '</span></div>';
		$out .= '<style>' . self::chart_css() . '</style>';
		$spark = array();
		foreach ( array_slice( $terms, -6 ) as $t ) {
			$spark[ $t['short'] . ' ' . substr( (string) $t['row']->year, -2 ) ] = $t['res']['average'] ?? null;
		}
		$chart = self::sparkline( $spark, __( 'Average exam mark by term', 'bsa-school-management' ) );
		if ( $chart ) {
			$out .= '<div class="bsa-family-chart">' . $chart . '</div>';
		}
		$out .= '<div class="table-responsive w-100 wlsm-w-100"><table class="table table-bordered wlsm-w-100 bsa-family-table"><thead><tr class="bg-primary text-white"><th>' . esc_html__( 'Term', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Average', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Change', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Subjects passed', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Attendance', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		$prev = null;
		foreach ( $terms as $t ) {
			$avg    = $t['res']['average'] ?? null;
			$change = ( null !== $avg && null !== $prev ) ? $avg - $prev : null;
			/* translators: 1: passed, 2: marked. */
			$passed = ( $t['res'] && $t['res']['recorded'] ) ? sprintf( __( '%1$d of %2$d', 'bsa-school-management' ), $t['res']['passed'], $t['res']['recorded'] ) : '–';
			$att    = ( $t['att'] && $t['att']['possible'] ) ? sprintf( /* translators: 1: percent, 2: attended, 3: days. */ __( '%1$s (%2$d of %3$d days)', 'bsa-school-management' ), self::pct( 100 * $t['att']['attended'] / $t['att']['possible'] ), $t['att']['attended'], $t['att']['possible'] ) : '–';
			$out   .= '<tr><td>' . esc_html( $t['label'] ) . '</td><td>' . esc_html( self::pct( $avg ) ) . '</td><td>' . esc_html( self::signed( $change ) ) . '</td><td>' . esc_html( $passed ) . '</td><td>' . esc_html( $att ) . '</td></tr>';
			$prev   = null !== $avg ? $avg : $prev;
		}
		$out .= '</tbody></table></div>';

		// Subject marks across the published terms of the latest year.
		$year_terms = array_values(
			array_filter(
				$terms,
				static function ( $t ) use ( $latest ) {
					return (int) $t['row']->session_id === (int) $latest->session_id;
				}
			)
		);
		$section    = BSA_School::section( (int) $latest->section_id );
		$subjects   = BSA_Reports::learner_subjects( (int) $latest->student_record_id, (int) $latest->section_id );
		if ( $subjects && $section ) {
			$marks = array();
			foreach ( $year_terms as $t ) {
				$marks[ (int) $t['row']->term ] = BSA_Marks::for_learners( array( (int) $latest->student_record_id ), (int) $latest->session_id, (int) $t['row']->term )[ (int) $latest->student_record_id ] ?? array();
			}
			/* translators: %s: year. */
			$out .= '<p class="bsa-family-note"><strong>' . esc_html( sprintf( __( 'Exam marks by subject, %s', 'bsa-school-management' ), $latest->year ) ) . '</strong></p><div class="table-responsive w-100 wlsm-w-100"><table class="table table-bordered wlsm-w-100 bsa-family-table"><thead><tr class="bg-primary text-white"><th>' . esc_html__( 'Subject', 'bsa-school-management' ) . '</th>';
			foreach ( $year_terms as $t ) {
				/* translators: %d: term. */
				$out .= '<th>' . esc_html( sprintf( __( 'Term %d', 'bsa-school-management' ), $t['row']->term ) ) . '</th>';
			}
			$out .= '</tr></thead><tbody>';
			foreach ( $subjects as $sub ) {
				$out .= '<tr><td>' . esc_html( $sub->label ) . '</td>';
				foreach ( $year_terms as $t ) {
					$m    = $marks[ (int) $t['row']->term ][ (int) $sub->ID ] ?? null;
					$out .= '<td>' . esc_html( $m && null !== $m->exam_mark ? BSA_Marks::show( $m->exam_mark ) . ' (' . BSA_Grades::grade( $m->exam_mark, $section->class_label ) . ')' : '–' ) . '</td>';
				}
				$out .= '</tr>';
			}
			$out .= '</tbody></table></div>';
		}
		return $out . '<p class="bsa-family-note">' . esc_html__( 'From published report cards only. Average is the mean of exam marks; change is against the previous published term.', 'bsa-school-management' ) . '</p>';
	}
}
