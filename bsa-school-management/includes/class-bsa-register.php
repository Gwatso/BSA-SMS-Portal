<?php
/**
 * Class Register: the BSA paper register on screen, built on Weblizar's daily attendance.
 *
 * - Register: one week (Monday–Friday, week ending Friday) per class and stream, with
 *   each pupil's weekly total and, per day, Number absent, Actual attendance and Possible
 *   attendances, plus the weekly total of actual attendances.
 * - Pupil details: the register's left-hand pages.
 * - Checks: the head/deputy records the weekly check of the register.
 * - Summary: attendances per term and year split Day/Boarder × Boy/Girl, number of school
 *   days and average daily attendance.
 *
 * Counting: Present and Late are attendances; Absent is not; Holiday and days without a
 * register are not possible attendances. Daily register rows only (no subject).
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class register screens and attendance arithmetic.
 */
class BSA_Register {

	const PAGE       = 'bsa-class-register';
	const DB_VERSION = '1';
	const PERMISSION = 'manage_attendance';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_register_check', array( __CLASS__, 'handle_check' ) );
	}

	/**
	 * Table of weekly register checks.
	 *
	 * @return string
	 */
	public static function checks_table() {
		global $wpdb;
		return $wpdb->prefix . 'bsa_register_checks';
	}

	/**
	 * Creates or updates the checks table.
	 */
	public static function maybe_migrate() {
		if ( get_option( 'bsa_sm_register_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::checks_table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				section_id bigint(20) unsigned NOT NULL,
				session_id bigint(20) unsigned NOT NULL,
				week_ending date NOT NULL,
				checked_by bigint(20) unsigned NOT NULL,
				checked_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY section_week (section_id, week_ending)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'bsa_sm_register_db_version', self::DB_VERSION, false );
	}

	/**
	 * Daily register marks for learners between two dates.
	 *
	 * @param int[]  $student_ids Student record IDs.
	 * @param string $from        Y-m-d.
	 * @param string $to          Y-m-d.
	 * @return array<int,array<string,string>> [student_id][Y-m-d] => p|a|l|h
	 */
	public static function marks( array $student_ids, $from, $to ) {
		$student_ids = array_filter( array_map( 'intval', $student_ids ) );
		if ( ! $student_ids ) {
			return array();
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT student_record_id, attendance_date, status FROM {$wpdb->prefix}wlsm_attendance
				WHERE student_record_id IN (" . implode( ',', $student_ids ) . ') AND attendance_date BETWEEN %s AND %s AND (subject_id IS NULL OR subject_id = 0)',
				$from,
				$to
			)
		);
		// phpcs:enable
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->student_record_id ][ substr( $row->attendance_date, 0, 10 ) ] = strtolower( (string) $row->status );
		}
		return $out;
	}

	/**
	 * Attended / possible / absent for each learner over a period.
	 *
	 * @param int[]  $student_ids Student record IDs.
	 * @param string $from        Y-m-d.
	 * @param string $to          Y-m-d.
	 * @return array<int,array{attended:int,possible:int,absent:int}>
	 */
	public static function totals( array $student_ids, $from, $to ) {
		$marks = self::marks( $student_ids, $from, $to );
		$out   = array();
		foreach ( $student_ids as $id ) {
			$counts = array( 'attended' => 0, 'possible' => 0, 'absent' => 0 );
			foreach ( $marks[ (int) $id ] ?? array() as $status ) {
				if ( in_array( $status, array( 'p', 'l' ), true ) ) {
					++$counts['attended'];
					++$counts['possible'];
				} elseif ( 'a' === $status ) {
					++$counts['absent'];
					++$counts['possible'];
				}
			}
			$out[ (int) $id ] = $counts;
		}
		return $out;
	}

	/**
	 * Days on which the register was taken (any learner marked present, late or absent).
	 *
	 * @param int[]  $student_ids Student record IDs.
	 * @param string $from        Y-m-d.
	 * @param string $to          Y-m-d.
	 * @return string[] Y-m-d, sorted.
	 */
	public static function school_days( array $student_ids, $from, $to ) {
		$days = array();
		foreach ( self::marks( $student_ids, $from, $to ) as $by_date ) {
			foreach ( $by_date as $date => $status ) {
				if ( in_array( $status, array( 'p', 'l', 'a' ), true ) ) {
					$days[ $date ] = true;
				}
			}
		}
		$days = array_keys( $days );
		sort( $days );
		return $days;
	}

	/**
	 * One register week (week ending Friday) for a section.
	 *
	 * @param int    $section_id  Section ID.
	 * @param int    $session_id  Session ID.
	 * @param string $week_ending Y-m-d (a Friday).
	 * @return array{days: string[], rows: object[], absent: int[], actual: int[], possible: int[], weekly_total: int}
	 */
	public static function week( $section_id, $session_id, $week_ending ) {
		$friday = new DateTimeImmutable( $week_ending );
		$days   = array();
		for ( $i = 4; $i >= 0; $i-- ) {
			$days[] = $friday->modify( "-{$i} days" )->format( 'Y-m-d' );
		}
		$learners = BSA_School::learners( $section_id, $session_id );
		$marks    = self::marks( wp_list_pluck( $learners, 'ID' ), $days[0], $days[4] );
		$absent   = array_fill( 0, 5, 0 );
		$actual   = array_fill( 0, 5, 0 );
		$possible = array_fill( 0, 5, 0 );
		foreach ( $learners as $n => $learner ) {
			$learner->class_no = $n + 1;
			$learner->days     = array();
			$learner->total    = 0;
			foreach ( $days as $i => $date ) {
				$status            = $marks[ (int) $learner->ID ][ $date ] ?? '';
				$learner->days[ $i ] = $status;
				if ( in_array( $status, array( 'p', 'l' ), true ) ) {
					++$learner->total;
					++$actual[ $i ];
					++$possible[ $i ];
				} elseif ( 'a' === $status ) {
					++$absent[ $i ];
					++$possible[ $i ];
				}
			}
		}
		return array(
			'days'         => $days,
			'rows'         => $learners,
			'absent'       => $absent,
			'actual'       => $actual,
			'possible'     => $possible,
			'weekly_total' => array_sum( $actual ),
		);
	}

	/**
	 * Attendance summary for a section: Terms 1–3 and the year, Day/Boarder × Boy/Girl.
	 *
	 * @param int $section_id Section ID.
	 * @param int $session_id Session ID.
	 * @return array{terms: array<int|string,array>, school_days: int, total: int, average: float|null, missing_terms: int[]}
	 */
	public static function summary( $section_id, $session_id ) {
		$learners = BSA_School::learners( $section_id, $session_id );
		$ids      = wp_list_pluck( $learners, 'ID' );
		$groups   = array();
		foreach ( $learners as $learner ) {
			$sex                         = 'female' === strtolower( (string) $learner->gender ) ? 'girl' : 'boy';
			$groups[ (int) $learner->ID ] = BSA_School::residence( $learner->student_type ) . '_' . $sex;
		}
		$blank   = array( 'day_boy' => 0, 'day_girl' => 0, 'boarder_boy' => 0, 'boarder_girl' => 0 );
		$out     = array( 'terms' => array(), 'school_days' => 0, 'total' => 0, 'average' => null, 'missing_terms' => array() );
		$year    = $blank;
		$alldays = array();
		for ( $t = 1; $t <= 3; $t++ ) {
			$dates = BSA_Terms::get( $session_id, $t );
			if ( ! $dates ) {
				$out['missing_terms'][] = $t;
				$out['terms'][ $t ]     = null;
				continue;
			}
			$row = $blank;
			foreach ( self::totals( $ids, $dates['start'], $dates['end'] ) as $id => $counts ) {
				$row[ $groups[ $id ] ]  += $counts['attended'];
				$year[ $groups[ $id ] ] += $counts['attended'];
			}
			$days               = self::school_days( $ids, $dates['start'], $dates['end'] );
			$alldays            = array_merge( $alldays, $days );
			$row['school_days'] = count( $days );
			$out['terms'][ $t ] = $row;
		}
		$out['terms']['year'] = $year;
		$out['school_days']   = count( array_unique( $alldays ) );
		$out['total']         = array_sum( $year );
		$out['average']       = $out['school_days'] ? round( $out['total'] / $out['school_days'], 1 ) : null;
		return $out;
	}

	/**
	 * Attendance for a report card: days attended out of possible in a term.
	 *
	 * @param int $student_id Student record ID.
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return array{attended:int,possible:int}|null Null when term dates are not set.
	 */
	public static function for_report( $student_id, $session_id, $term ) {
		$dates = BSA_Terms::get( $session_id, $term );
		if ( ! $dates ) {
			return null;
		}
		$t = self::totals( array( $student_id ), $dates['start'], $dates['end'] )[ (int) $student_id ];
		return array( 'attended' => $t['attended'], 'possible' => $t['possible'] );
	}

	/**
	 * Weekly checks recorded for a section in a date range.
	 *
	 * @param int    $section_id Section ID.
	 * @param string $from       Y-m-d.
	 * @param string $to         Y-m-d.
	 * @return array<string,object> week_ending => {checked_by, checked_at, name}
	 */
	public static function checks( $section_id, $from, $to ) {
		global $wpdb;
		$table = self::checks_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.week_ending, c.checked_by, c.checked_at, u.display_name AS name FROM {$table} c LEFT JOIN {$wpdb->users} u ON u.ID = c.checked_by WHERE c.section_id = %d AND c.week_ending BETWEEN %s AND %s", $section_id, $from, $to ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row->week_ending ] = $row;
		}
		return $out;
	}

	/**
	 * Records the head's check of a register week (school administrators only).
	 *
	 * @param int    $section_id  Section ID.
	 * @param int    $session_id  Session ID.
	 * @param string $week_ending Y-m-d Friday.
	 * @param int    $user_id     Checking user.
	 * @return true|WP_Error
	 */
	public static function record_check( $section_id, $session_id, $week_ending, $user_id ) {
		if ( ! BSA_School::is_school_admin( $user_id ) ) {
			return new WP_Error( 'bsa_register', __( 'Only the head, deputy or teacher in charge can check the register.', 'bsa-school-management' ) );
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $week_ending );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $week_ending || '5' !== $d->format( 'N' ) ) {
			return new WP_Error( 'bsa_register', __( 'Choose a week ending on a Friday.', 'bsa-school-management' ) );
		}
		if ( $d->modify( '-4 days' )->format( 'Y-m-d' ) > current_time( 'Y-m-d' ) ) {
			return new WP_Error( 'bsa_register', __( 'Choose a week that has started.', 'bsa-school-management' ) );
		}
		if ( ! BSA_School::section( $section_id ) ) {
			return new WP_Error( 'bsa_register', __( 'Class not found.', 'bsa-school-management' ) );
		}
		global $wpdb;
		$wpdb->replace( self::checks_table(), array( 'section_id' => (int) $section_id, 'session_id' => (int) $session_id, 'week_ending' => $week_ending, 'checked_by' => (int) $user_id, 'checked_at' => current_time( 'mysql' ) ) );
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->info( 'Class register checked: section #{section}, week ending {week}', array( 'section' => (int) $section_id, 'week' => $week_ending ) );
		}
		return true;
	}

	/**
	 * Menu for staff who take attendance and school administrators.
	 */
	public static function register_menu() {
		if ( ! BSA_School::allowed_sections( get_current_user_id(), self::PERMISSION ) ) {
			return;
		}
		$hook = add_menu_page( __( 'Class Register', 'bsa-school-management' ), __( 'Class Register', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-clipboard', 30 );
		add_action( 'admin_head-' . $hook, array( __CLASS__, 'styles' ) );
	}

	/**
	 * Screen and print styles.
	 */
	public static function styles() {
		?>
		<style>
		.bsa-register table { border-collapse: collapse; background: #fff; }
		.bsa-register th, .bsa-register td { border: 1px solid #c3c4c7; padding: 4px 6px; font-size: 13px; }
		.bsa-register td.mark { text-align: center; min-width: 26px; font-weight: 600; }
		.bsa-register td.mark.a { color: #a71b32; }
		.bsa-register td.mark.h { color: #6b7280; }
		.bsa-register tfoot th { text-align: left; background: #f6f7f9; }
		.bsa-register .name { text-transform: uppercase; }
		.bsa-register .bsa-legend { margin: 8px 0 16px; color: #50575e; }
		.bsa-register .bsa-print-title { display: none; }
		@media print {
			#adminmenumain, #wpadminbar, #wpfooter, .nav-tab-wrapper, .bsa-filters, .bsa-noprint, .notice { display: none !important; }
			#wpcontent { margin-left: 0 !important; }
			.bsa-register .bsa-print-title { display: block; }
			@page { size: A4 landscape; margin: 12mm; }
		}
		</style>
		<?php
	}

	/**
	 * Validated screen state from the request for a user.
	 *
	 * @param array $source  $_GET (unslashed).
	 * @param int   $user_id User ID.
	 * @return array{tab:string,section:int,session:int,term:int,week:string,sections:int[],term_dates:array|null,weeks:string[]}
	 */
	public static function state( array $source, $user_id ) {
		$sections = BSA_School::allowed_sections( $user_id, self::PERMISSION );
		$section  = absint( $source['section'] ?? 0 );
		$section  = in_array( $section, $sections, true ) ? $section : (int) ( $sections[0] ?? 0 );
		$sessions = BSA_School::sessions();
		$session  = absint( $source['session'] ?? 0 );
		$current  = BSA_Terms::current();
		if ( ! isset( $sessions[ $session ] ) ) {
			$session = $current ? $current['session'] : BSA_School::current_session();
		}
		$term = absint( $source['term'] ?? 0 );
		if ( $term < 1 || $term > 3 ) {
			$term = ( $current && $current['session'] === $session ) ? $current['term'] : 1;
		}
		$dates = BSA_Terms::get( $session, $term );
		$weeks = $dates ? BSA_Terms::week_endings( $dates['start'], $dates['end'] ) : array();
		$week  = (string) ( $source['week'] ?? '' );
		if ( ! in_array( $week, $weeks, true ) ) {
			$today = current_time( 'Y-m-d' );
			$week  = '';
			foreach ( $weeks as $friday ) {
				if ( gmdate( 'Y-m-d', strtotime( $friday . ' -4 days' ) ) <= $today ) {
					$week = $friday;
				}
			}
			$week = $week ? $week : (string) ( $weeks[0] ?? '' );
		}
		$tab = sanitize_key( $source['tab'] ?? 'register' );
		return array(
			'tab'        => in_array( $tab, array( 'register', 'details', 'checks', 'summary' ), true ) ? $tab : 'register',
			'section'    => $section,
			'session'    => $session,
			'term'       => $term,
			'week'       => $week,
			'sections'   => $sections,
			'term_dates' => $dates,
			'weeks'      => $weeks,
		);
	}

	/**
	 * Class Register screen.
	 */
	public static function render_page() {
		$user_id = get_current_user_id();
		if ( ! BSA_School::allowed_sections( $user_id, self::PERMISSION ) ) {
			wp_die( esc_html__( 'You do not have permission to view class registers.', 'bsa-school-management' ), 403 );
		}
		$s       = self::state( wp_unslash( $_GET ), $user_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		$section = BSA_School::section( $s['section'] );
		$base    = array( 'page' => self::PAGE, 'section' => $s['section'], 'session' => $s['session'], 'term' => $s['term'] );

		echo '<div class="wrap bsa-register"><h1>' . esc_html__( 'Class Register', 'bsa-school-management' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		$notice = isset( $_GET['bsa_notice'] ) ? sanitize_key( wp_unslash( $_GET['bsa_notice'] ) ) : '';
		if ( 'checked' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Register check recorded.', 'bsa-school-management' ) . '</p></div>';
		} elseif ( 'check_failed' === $notice ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The register check could not be recorded.', 'bsa-school-management' ) . '</p></div>';
		}

		echo '<form method="get" class="bsa-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="' . esc_attr( $s['tab'] ) . '">';
		echo '<label for="bsa-section">' . esc_html__( 'Class', 'bsa-school-management' ) . '</label> <select id="bsa-section" name="section">';
		foreach ( $s['sections'] as $id ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $s['section'], $id, false ), esc_html( BSA_School::section( $id )->title ) );
		}
		echo '</select> <label for="bsa-session">' . esc_html__( 'Year', 'bsa-school-management' ) . '</label> <select id="bsa-session" name="session">';
		foreach ( BSA_School::sessions() as $id => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $s['session'], $id, false ), esc_html( $label ) );
		}
		echo '</select> <label for="bsa-term">' . esc_html__( 'Term', 'bsa-school-management' ) . '</label> <select id="bsa-term" name="term">';
		for ( $t = 1; $t <= 3; $t++ ) {
			printf( '<option value="%d"%s>%d</option>', (int) $t, selected( $s['term'], $t, false ), (int) $t );
		}
		echo '</select> ';
		if ( 'register' === $s['tab'] && $s['weeks'] ) {
			echo '<label for="bsa-week">' . esc_html__( 'Week ending', 'bsa-school-management' ) . '</label> <select id="bsa-week" name="week">';
			foreach ( $s['weeks'] as $n => $friday ) {
				/* translators: 1: week number, 2: date. */
				printf( '<option value="%s"%s>%s</option>', esc_attr( $friday ), selected( $s['week'], $friday, false ), esc_html( sprintf( __( 'Week %1$d: Fri %2$s', 'bsa-school-management' ), $n + 1, mysql2date( 'j M Y', $friday ) ) ) );
			}
			echo '</select> ';
		}
		submit_button( __( 'Show', 'bsa-school-management' ), 'secondary', '', false );
		echo ' <button type="button" class="button bsa-noprint" onclick="window.print()">' . esc_html__( 'Print', 'bsa-school-management' ) . '</button></form>';

		echo '<nav class="nav-tab-wrapper">';
		foreach ( array( 'register' => __( 'Register', 'bsa-school-management' ), 'details' => __( 'Pupil details', 'bsa-school-management' ), 'checks' => __( 'Register checks', 'bsa-school-management' ), 'summary' => __( 'Attendance summary', 'bsa-school-management' ) ) as $tab => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( add_query_arg( array_merge( $base, array( 'tab' => $tab ) ), admin_url( 'admin.php' ) ) ), $tab === $s['tab'] ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';

		if ( ! $section ) {
			echo '<p>' . esc_html__( 'No class is assigned to you. Ask the school office to set your class in School Management → Staff.', 'bsa-school-management' ) . '</p></div>';
			return;
		}
		$year = BSA_School::sessions()[ $s['session'] ] ?? '';
		/* translators: 1: class, 2: term, 3: year. */
		echo '<h2 class="bsa-print-title">' . esc_html( sprintf( __( 'Bindura Science Academy: %1$s, Term %2$d %3$s', 'bsa-school-management' ), $section->title, $s['term'], $year ) ) . '</h2>';

		if ( 'summary' !== $s['tab'] && 'details' !== $s['tab'] && ! $s['term_dates'] ) {
			$url = add_query_arg( 'page', BSA_Terms::PAGE, admin_url( 'admin.php' ) );
			echo '<p>' . esc_html__( 'Term dates are not set for this term yet.', 'bsa-school-management' ) . ' ';
			if ( BSA_School::is_school_admin( $user_id ) ) {
				echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Set term dates', 'bsa-school-management' ) . '</a>';
			} else {
				esc_html_e( 'Ask the school office to set them.', 'bsa-school-management' );
			}
			echo '</p></div>';
			return;
		}

		switch ( $s['tab'] ) {
			case 'details':
				self::render_details( $s );
				break;
			case 'checks':
				self::render_checks( $s, $user_id );
				break;
			case 'summary':
				self::render_summary( $s );
				break;
			default:
				self::render_week( $s, $user_id );
		}
		echo '</div>';
	}

	/**
	 * Register tab: one week.
	 *
	 * @param array $s       State.
	 * @param int   $user_id Current user.
	 */
	private static function render_week( array $s, $user_id ) {
		$week   = self::week( $s['section'], $s['session'], $s['week'] );
		$labels = array( 'p' => array( '✓', __( 'Present', 'bsa-school-management' ) ), 'l' => array( 'L', __( 'Late', 'bsa-school-management' ) ), 'a' => array( 'A', __( 'Absent', 'bsa-school-management' ) ), 'h' => array( 'H', __( 'Holiday', 'bsa-school-management' ) ) );
		echo '<p class="bsa-legend">' . esc_html__( 'Key: ✓ present, L late, A absent, H holiday, blank = register not taken. Attendance is taken in School Management → Attendance.', 'bsa-school-management' ) . '</p>';
		if ( ! $week['rows'] ) {
			echo '<p>' . esc_html__( 'No pupils in this class for the selected year.', 'bsa-school-management' ) . '</p>';
			return;
		}
		echo '<table><thead><tr><th scope="col">' . esc_html__( 'Class No.', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Surname', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'First name', 'bsa-school-management' ) . '</th>';
		foreach ( array( __( 'M', 'bsa-school-management' ), __( 'Tu', 'bsa-school-management' ), __( 'W', 'bsa-school-management' ), __( 'Th', 'bsa-school-management' ), __( 'Fr', 'bsa-school-management' ) ) as $i => $day ) {
			echo '<th scope="col" title="' . esc_attr( mysql2date( 'l j M', $week['days'][ $i ] ) ) . '">' . esc_html( $day ) . '</th>';
		}
		echo '<th scope="col">' . esc_html__( 'Total', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $week['rows'] as $row ) {
			echo '<tr><td>' . (int) $row->class_no . '</td><td class="name">' . esc_html( $row->surname ) . '</td><td class="name">' . esc_html( $row->first_names ) . '</td>';
			foreach ( $row->days as $status ) {
				if ( isset( $labels[ $status ] ) ) {
					echo '<td class="mark ' . esc_attr( $status ) . '"><abbr title="' . esc_attr( $labels[ $status ][1] ) . '">' . esc_html( $labels[ $status ][0] ) . '</abbr></td>';
				} else {
					echo '<td class="mark"></td>';
				}
			}
			echo '<td class="mark">' . (int) $row->total . '</td></tr>';
		}
		echo '</tbody><tfoot>';
		foreach ( array( 'absent' => __( 'Number absent', 'bsa-school-management' ), 'actual' => __( 'Actual attendance', 'bsa-school-management' ), 'possible' => __( 'Possible attendances', 'bsa-school-management' ) ) as $key => $label ) {
			echo '<tr><th colspan="3" scope="row">' . esc_html( $label ) . '</th>';
			foreach ( $week[ $key ] as $value ) {
				echo '<td class="mark">' . (int) $value . '</td>';
			}
			echo '<td class="mark">' . (int) array_sum( $week[ $key ] ) . '</td></tr>';
		}
		echo '<tr><th colspan="3" scope="row">' . esc_html__( 'Weekly total of actual attendances', 'bsa-school-management' ) . '</th><td colspan="6" class="mark">' . (int) $week['weekly_total'] . '</td></tr>';
		echo '</tfoot></table>';

		$check = self::checks( $s['section'], $s['week'], $s['week'] )[ $s['week'] ] ?? null;
		echo '<p>';
		if ( $check ) {
			/* translators: 1: name, 2: date. */
			echo esc_html( sprintf( __( 'Checked by %1$s on %2$s.', 'bsa-school-management' ), $check->name, mysql2date( 'j M Y', $check->checked_at ) ) );
		} elseif ( BSA_School::is_school_admin( $user_id ) ) {
			self::check_button( $s );
		} else {
			esc_html_e( 'Not yet checked by the head.', 'bsa-school-management' );
		}
		echo '</p>';
	}

	/**
	 * "Record check" button for one week.
	 *
	 * @param array $s State (section, session, term, week).
	 */
	private static function check_button( array $s ) {
		echo '<form method="post" class="bsa-noprint" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_register_check">';
		foreach ( array( 'section', 'session', 'term', 'week' ) as $key ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $s[ $key ] ) . '">';
		}
		wp_nonce_field( 'bsa_register_check_' . $s['section'] . '_' . $s['week'] );
		/* translators: %s: date. */
		submit_button( sprintf( __( 'Record check of week ending %s', 'bsa-school-management' ), mysql2date( 'j M Y', $s['week'] ) ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Pupil details tab (the register's left-hand pages).
	 *
	 * @param array $s State.
	 */
	private static function render_details( array $s ) {
		$learners = BSA_School::learners( $s['section'], $s['session'] );
		if ( ! $learners ) {
			echo '<p>' . esc_html__( 'No pupils in this class for the selected year.', 'bsa-school-management' ) . '</p>';
			return;
		}
		global $wpdb;
		$certs = array();
		if ( class_exists( 'BSA_Admission_Records' ) ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $learners, 'ID' ) ) );
			foreach ( $wpdb->get_results( 'SELECT student_record_id, birth_certificate_no FROM ' . BSA_Admission_Records::table() . " WHERE student_record_id IN ({$ids})" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$certs[ (int) $row->student_record_id ] = $row->birth_certificate_no;
			}
		}
		$boys  = count( array_filter( $learners, function ( $l ) { return 'female' !== strtolower( (string) $l->gender ); } ) );
		$girls = count( $learners ) - $boys;
		/* translators: 1: boys, 2: girls. */
		echo '<p>' . esc_html( sprintf( __( 'Boys %1$d, Girls %2$d', 'bsa-school-management' ), $boys, $girls ) ) . '</p>';
		echo '<table><thead><tr>';
		foreach ( array( __( 'Class No.', 'bsa-school-management' ), __( 'Surname', 'bsa-school-management' ), __( 'First name', 'bsa-school-management' ), __( 'Date of birth', 'bsa-school-management' ), __( 'Birth cert. No.', 'bsa-school-management' ), __( 'Religion', 'bsa-school-management' ), __( 'Boarder / Day scholar', 'bsa-school-management' ), __( 'Games house', 'bsa-school-management' ), __( 'Parent / guardian: name, contact phone and residential address', 'bsa-school-management' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $learners as $n => $l ) {
			$parent = implode( ' / ', array_filter( array( $l->father_name, $l->mother_name ) ) );
			$phone  = implode( ' / ', array_filter( array( $l->father_phone, $l->mother_phone ) ) );
			$parts  = array_filter( array( $parent, $phone, $l->address ) );
			echo '<tr><td>' . (int) ( $n + 1 ) . '</td><td class="name">' . esc_html( $l->surname ) . '</td><td class="name">' . esc_html( $l->first_names ) . '</td>';
			echo '<td>' . esc_html( $l->dob ? mysql2date( 'j M Y', $l->dob ) : '' ) . '</td><td>' . esc_html( $certs[ (int) $l->ID ] ?? '' ) . '</td><td>' . esc_html( $l->religion ) . '</td>';
			echo '<td>' . esc_html( 'boarder' === BSA_School::residence( $l->student_type ) ? __( 'Boarder', 'bsa-school-management' ) : __( 'Day scholar', 'bsa-school-management' ) ) . '</td><td></td>';
			echo '<td>' . esc_html( implode( ', ', $parts ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Register checks tab: every week of the term.
	 *
	 * @param array $s       State.
	 * @param int   $user_id Current user.
	 */
	private static function render_checks( array $s, $user_id ) {
		$checks = self::checks( $s['section'], $s['term_dates']['start'], gmdate( 'Y-m-d', strtotime( $s['term_dates']['end'] . ' +6 days' ) ) );
		$today  = current_time( 'Y-m-d' );
		echo '<table><thead><tr><th scope="col">' . esc_html__( 'Week', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Week ending', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Checked by', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Date', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $s['weeks'] as $n => $friday ) {
			$check = $checks[ $friday ] ?? null;
			echo '<tr><td>' . (int) ( $n + 1 ) . '</td><td>' . esc_html( mysql2date( 'j M Y', $friday ) ) . '</td><td>';
			if ( $check ) {
				echo esc_html( $check->name ) . '</td><td>' . esc_html( mysql2date( 'j M Y', $check->checked_at ) );
			} elseif ( BSA_School::is_school_admin( $user_id ) && gmdate( 'Y-m-d', strtotime( $friday . ' -4 days' ) ) <= $today ) {
				self::check_button( array_merge( $s, array( 'week' => $friday ) ) );
				echo '</td><td>';
			} else {
				echo '</td><td>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Attendance summary tab.
	 *
	 * @param array $s State.
	 */
	private static function render_summary( array $s ) {
		$sum = self::summary( $s['section'], $s['session'] );
		if ( $sum['missing_terms'] ) {
			/* translators: %s: list of term numbers. */
			echo '<p>' . esc_html( sprintf( __( 'Term dates not set for Term %s; those terms are left blank.', 'bsa-school-management' ), implode( ', ', $sum['missing_terms'] ) ) ) . '</p>';
		}
		echo '<table><thead><tr><th rowspan="2"></th><th colspan="2" scope="colgroup">' . esc_html__( 'Day', 'bsa-school-management' ) . '</th><th colspan="2" scope="colgroup">' . esc_html__( 'Boarder', 'bsa-school-management' ) . '</th><th rowspan="2" scope="col">' . esc_html__( 'School days', 'bsa-school-management' ) . '</th></tr><tr>';
		foreach ( array( __( 'Boy', 'bsa-school-management' ), __( 'Girl', 'bsa-school-management' ), __( 'Boy', 'bsa-school-management' ), __( 'Girl', 'bsa-school-management' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$names = array( 1 => __( 'First term', 'bsa-school-management' ), 2 => __( 'Second term', 'bsa-school-management' ), 3 => __( 'Third term', 'bsa-school-management' ), 'year' => __( 'Total for year', 'bsa-school-management' ) );
		foreach ( $names as $key => $label ) {
			$row = $sum['terms'][ $key ];
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( array( 'day_boy', 'day_girl', 'boarder_boy', 'boarder_girl' ) as $col ) {
				echo '<td class="mark">' . ( $row ? (int) $row[ $col ] : '' ) . '</td>';
			}
			$days = 'year' === $key ? $sum['school_days'] : ( $row ? $row['school_days'] : '' );
			echo '<td class="mark">' . esc_html( (string) $days ) . '</td></tr>';
		}
		echo '</tbody></table>';
		/* translators: 1: total attendances, 2: school days, 3: average. */
		echo '<p>' . esc_html( sprintf( __( 'Total attendances %1$d over %2$d school days. Average daily attendance for the year: %3$s.', 'bsa-school-management' ), $sum['total'], $sum['school_days'], null === $sum['average'] ? '–' : number_format( $sum['average'], 1 ) ) ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Average daily attendance = total number of attendances ÷ number of school days.', 'bsa-school-management' ) . '</p>';
	}

	/**
	 * Records a weekly check (admin-post).
	 */
	public static function handle_check() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified next.
		$section = isset( $_POST['section'] ) ? absint( $_POST['section'] ) : 0;
		$session = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
		$term    = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$week    = isset( $_POST['week'] ) ? sanitize_text_field( wp_unslash( $_POST['week'] ) ) : '';
		// phpcs:enable
		check_admin_referer( 'bsa_register_check_' . $section . '_' . $week );
		$result = self::record_check( $section, $session, $week, get_current_user_id() );
		if ( is_wp_error( $result ) && 'bsa_register' === $result->get_error_code() && ! BSA_School::is_school_admin( get_current_user_id() ) ) {
			wp_die( esc_html( $result->get_error_message() ), 403 );
		}
		$args = array( 'page' => self::PAGE, 'section' => $section, 'session' => $session, 'term' => $term, 'week' => $week, 'bsa_notice' => is_wp_error( $result ) ? 'check_failed' : 'checked' );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
