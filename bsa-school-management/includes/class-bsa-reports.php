<?php
/**
 * Report cards in the layout of the BSA report book (docs/academics-spec.md §3).
 *
 * - Results per learner and term from BSA_Marks, graded with BSA_Grades; position by
 *   average exam mark within class and stream; attendance from BSA_Register; next term
 *   start from BSA_Terms; next term fee from the Weblizar fee type.
 * - Form teacher: comment and character/conduct for their own class. Principal (school
 *   administrator): comment, publish and withdraw.
 * - Printing: one A4 page per learner (plus the grade keys), single or whole class.
 * - Parents and learners: published reports listed on the My Account page and opened
 *   only for their own learners.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report card data, screens and printing.
 */
class BSA_Reports {

	const PAGE = 'bsa-report-cards';

	/**
	 * Per-request cache of class results (printing a class needs them for every card).
	 *
	 * @var array<string,array>
	 */
	private static $results = array();

	/**
	 * Clears the per-request cache (after marks change, and in tests).
	 */
	public static function flush() {
		self::$results = array();
	}

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_save_report_comments', array( __CLASS__, 'handle_comments' ) );
		add_action( 'admin_post_bsa_publish_reports', array( __CLASS__, 'handle_publish' ) );
		add_action( 'admin_post_bsa_print_reports', array( __CLASS__, 'handle_print' ) );
		add_action( 'template_redirect', array( __CLASS__, 'family_view' ) );
	}

	/**
	 * Sections whose report cards the user may work on: school administrators all;
	 * a class teacher their own class.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function allowed_sections( $user_id ) {
		if ( BSA_School::is_school_admin( $user_id ) ) {
			return array_map( 'intval', wp_list_pluck( BSA_School::sections(), 'ID' ) );
		}
		$staff = BSA_School::staff( $user_id );
		if ( $staff && in_array( 'assigned_class', $staff->permissions, true ) && BSA_School::section( $staff->section_id ) ) {
			return array( (int) $staff->section_id );
		}
		return array();
	}

	/**
	 * Subjects a learner takes: Weblizar's per-learner subjects when recorded, else all
	 * subjects of the form. Ordered as in the report book.
	 *
	 * @param int $student_id Student record ID.
	 * @param int $section_id Section ID.
	 * @return object[] {ID, label}
	 */
	public static function learner_subjects( $student_id, $section_id ) {
		global $wpdb;
		$p    = $wpdb->prefix;
		$all  = BSA_Marks::section_subjects( $section_id );
		$mine = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT subject_id FROM {$p}wlsm_students_subjects WHERE student_id = %d", $student_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $mine ) {
			return $all;
		}
		return array_values( array_filter( $all, function ( $s ) use ( $mine ) { return in_array( (int) $s->ID, $mine, true ); } ) );
	}

	/**
	 * Results for every learner in a class and stream: subjects recorded and passed,
	 * average exam mark, result and position.
	 *
	 * @param int $section_id Section ID.
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return array<int,array{recorded:int,passed:int,average:float|null,result:string,position:int|null}>
	 */
	public static function class_results( $section_id, $session_id, $term ) {
		$key = $section_id . '-' . $session_id . '-' . $term;
		if ( isset( self::$results[ $key ] ) ) {
			return self::$results[ $key ];
		}
		$learners = BSA_School::learners( $section_id, $session_id );
		$marks    = BSA_Marks::for_learners( wp_list_pluck( $learners, 'ID' ), $session_id, $term );
		$out      = array();
		$averages = array();
		foreach ( $learners as $l ) {
			$exams = array();
			foreach ( self::learner_subjects( (int) $l->ID, $section_id ) as $subject ) {
				$m = $marks[ (int) $l->ID ][ (int) $subject->ID ] ?? null;
				if ( $m && null !== $m->exam_mark ) {
					$exams[] = (float) $m->exam_mark;
				}
			}
			$passed               = count( array_filter( $exams, array( 'BSA_Grades', 'is_pass' ) ) );
			$average              = $exams ? array_sum( $exams ) / count( $exams ) : null;
			$out[ (int) $l->ID ] = array( 'recorded' => count( $exams ), 'passed' => $passed, 'average' => $average, 'result' => $exams ? BSA_Grades::result( $passed ) : '', 'position' => null );
			if ( null !== $average ) {
				$averages[ (int) $l->ID ] = $average;
			}
		}
		foreach ( BSA_Grades::positions( $averages ) as $id => $position ) {
			$out[ $id ]['position'] = $position;
		}
		self::$results[ $key ] = $out;
		return $out;
	}

	/**
	 * Comments and publication row (empty defaults when none).
	 *
	 * @param int $student_id Student record ID.
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return object
	 */
	public static function comments( $student_id, $session_id, $term ) {
		global $wpdb;
		$table = BSA_Marks::reports_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT r.*, ft.display_name AS form_teacher_name, pr.display_name AS principal_name FROM {$table} r LEFT JOIN {$wpdb->users} ft ON ft.ID = r.form_teacher_id LEFT JOIN {$wpdb->users} pr ON pr.ID = r.principal_id WHERE r.student_record_id = %d AND r.session_id = %d AND r.term = %d", $student_id, $session_id, $term ) );
		return $row ? $row : (object) array( 'form_teacher_comment' => '', 'conduct' => '', 'principal_comment' => '', 'form_teacher_name' => '', 'principal_name' => '', 'published_at' => null );
	}

	/**
	 * Saves comments for one learner. Form teacher fields need the class; the principal's
	 * comment needs a school administrator.
	 *
	 * @param int   $user_id    Acting user.
	 * @param int   $student_id Student record ID.
	 * @param int   $session_id Session ID.
	 * @param int   $term       1–3.
	 * @param array $input      form_teacher_comment, conduct, principal_comment.
	 * @return true|WP_Error
	 */
	public static function save_comments( $user_id, $student_id, $session_id, $term, array $input ) {
		global $wpdb;
		$section = (int) $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->prefix}wlsm_student_records WHERE ID = %d AND session_id = %d", $student_id, $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $section || ! in_array( $section, self::allowed_sections( $user_id ), true ) || $term < 1 || $term > 3 ) {
			return new WP_Error( 'bsa_reports', __( 'You cannot write comments for this learner.', 'bsa-school-management' ) );
		}
		$admin = BSA_School::is_school_admin( $user_id );
		$old   = self::comments( $student_id, $session_id, $term );
		if ( ! $admin && $old->published_at ) {
			return new WP_Error( 'bsa_reports', __( 'The report is published; ask the head to change it.', 'bsa-school-management' ) );
		}
		$data = array(
			'form_teacher_comment' => mb_substr( sanitize_textarea_field( (string) ( $input['form_teacher_comment'] ?? $old->form_teacher_comment ) ), 0, 600 ),
			'conduct'              => mb_substr( sanitize_text_field( (string) ( $input['conduct'] ?? $old->conduct ) ), 0, 100 ),
			'updated_at'           => current_time( 'mysql' ),
		);
		if ( $data['form_teacher_comment'] !== (string) $old->form_teacher_comment || $data['conduct'] !== (string) $old->conduct ) {
			$data['form_teacher_id'] = $user_id;
		}
		if ( $admin && isset( $input['principal_comment'] ) ) {
			$data['principal_comment'] = mb_substr( sanitize_textarea_field( (string) $input['principal_comment'] ), 0, 600 );
			if ( $data['principal_comment'] !== (string) $old->principal_comment ) {
				$data['principal_id'] = $user_id;
			}
		}
		$table = BSA_Marks::reports_table();
		if ( isset( $old->id ) ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $old->id ) );
		} else {
			$wpdb->insert( $table, array_merge( array( 'student_record_id' => $student_id, 'session_id' => $session_id, 'term' => $term, 'principal_comment' => '' ), $data ) );
		}
		return true;
	}

	/**
	 * Publishes (or withdraws) a class's reports for a term. School administrators only.
	 * Publishing records every learner in the class, with or without comments.
	 *
	 * @param int  $user_id    Acting user.
	 * @param int  $section_id Section ID.
	 * @param int  $session_id Session ID.
	 * @param int  $term       1–3.
	 * @param bool $publish    True to publish, false to withdraw.
	 * @return int|WP_Error Learners affected.
	 */
	public static function publish( $user_id, $section_id, $session_id, $term, $publish = true ) {
		if ( ! BSA_School::is_school_admin( $user_id ) || ! BSA_School::section( $section_id ) || $term < 1 || $term > 3 ) {
			return new WP_Error( 'bsa_reports', __( 'Only the head can publish report cards.', 'bsa-school-management' ) );
		}
		global $wpdb;
		$table = BSA_Marks::reports_table();
		$n     = 0;
		foreach ( BSA_School::learners( $section_id, $session_id ) as $l ) {
			$old  = self::comments( (int) $l->ID, $session_id, $term );
			$data = array( 'published_at' => $publish ? current_time( 'mysql' ) : null, 'published_by' => $publish ? $user_id : null, 'updated_at' => current_time( 'mysql' ) );
			if ( isset( $old->id ) ) {
				$wpdb->update( $table, $data, array( 'id' => (int) $old->id ) );
			} elseif ( $publish ) {
				$wpdb->insert( $table, array_merge( array( 'student_record_id' => (int) $l->ID, 'session_id' => $session_id, 'term' => $term, 'form_teacher_comment' => '', 'principal_comment' => '' ), $data ) );
			}
			++$n;
		}
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->info( ( $publish ? 'Report cards published' : 'Report cards withdrawn' ) . ': section #{section}, term {term}, {count} learner(s)', array( 'section' => $section_id, 'term' => $term, 'count' => $n ) );
		}
		return $n;
	}

	/**
	 * Fee of the term after this one for a class (Weblizar fee type "Term N Fees").
	 *
	 * @param int $class_id Weblizar class ID.
	 * @param int $term     Current term.
	 * @return float|null
	 */
	public static function next_term_fee( $class_id, $term ) {
		global $wpdb;
		$label = sprintf( 'Term %d Fees', $term < 3 ? $term + 1 : 1 );
		$fee   = $wpdb->get_var( $wpdb->prepare( "SELECT amount FROM {$wpdb->prefix}wlsm_fees WHERE class_id = %d AND label = %s AND school_id = %d LIMIT 1", $class_id, $label, BSA_School::SCHOOL_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $fee ? null : (float) $fee;
	}

	/**
	 * Everything on one report card.
	 *
	 * @param int $student_id Student record ID.
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return array|null Null when the learner is not in the session.
	 */
	public static function card( $student_id, $session_id, $term ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$l = $wpdb->get_row( $wpdb->prepare( "SELECT sr.ID, sr.name, sr.admission_number, sr.section_id, sr.session_id FROM {$p}wlsm_student_records sr WHERE sr.ID = %d AND sr.session_id = %d", $student_id, $session_id ) );
		if ( ! $l ) {
			return null;
		}
		$section = BSA_School::section( (int) $l->section_id );
		if ( ! $section ) {
			return null;
		}
		$marks    = BSA_Marks::for_learners( array( $student_id ), $session_id, $term )[ $student_id ] ?? array();
		$subjects = array();
		foreach ( self::learner_subjects( $student_id, (int) $l->section_id ) as $s ) {
			$m          = $marks[ (int) $s->ID ] ?? null;
			$subjects[] = array(
				'label'      => $s->label,
				'term_mark'  => $m ? BSA_Marks::show( $m->term_mark ) : '',
				'term_grade' => $m ? BSA_Grades::grade( $m->term_mark, $section->class_label ) : '',
				'exam_mark'  => $m ? BSA_Marks::show( $m->exam_mark ) : '',
				'exam_grade' => $m ? BSA_Grades::grade( $m->exam_mark, $section->class_label ) : '',
				'project'    => $m ? BSA_Marks::show( $m->project_mark ) : '',
				'remark'     => $m ? $m->remark : '',
				'initials'   => $m ? BSA_Grades::initials( $m->entered_by_name ) : '',
			);
		}
		$results = self::class_results( (int) $l->section_id, $session_id, $term )[ $student_id ] ?? array( 'recorded' => 0, 'passed' => 0, 'average' => null, 'result' => '', 'position' => null );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$in_form = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_student_records sr JOIN {$p}wlsm_sections se ON se.ID = sr.section_id JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id WHERE cs.class_id = %d AND sr.session_id = %d AND sr.is_active = 1", $section->class_id, $session_id ) );
		return array(
			'student_id'  => $student_id,
			'name'        => $l->name,
			'admission'   => $l->admission_number,
			'form'        => $section->title,
			'class_label' => $section->class_label,
			'scheme'      => BSA_Grades::scheme( $section->class_label ),
			'term'        => (int) $term,
			'year'        => BSA_School::sessions()[ $session_id ] ?? '',
			'in_form'     => $in_form,
			'position'    => $results['position'],
			'attendance'  => BSA_Register::for_report( $student_id, $session_id, $term ),
			'subjects'    => $subjects,
			'recorded'    => $results['recorded'],
			'passed'      => $results['passed'],
			'result'      => $results['result'],
			'comments'    => self::comments( $student_id, $session_id, $term ),
			'next_term'   => BSA_Terms::next_start( $session_id, $term ),
			'next_fee'    => self::next_term_fee( (int) $section->class_id, $term ),
		);
	}

	/**
	 * Menu for school administrators and class teachers.
	 */
	public static function register_menu() {
		if ( ! self::allowed_sections( get_current_user_id() ) ) {
			return;
		}
		add_menu_page( __( 'Report Cards', 'bsa-school-management' ), __( 'Report Cards', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-media-document', 32 );
	}

	/**
	 * Report Cards screen: class list with results, comments form, publish, print.
	 */
	public static function render_page() {
		$user_id  = get_current_user_id();
		$sections = self::allowed_sections( $user_id );
		if ( ! $sections ) {
			wp_die( esc_html__( 'You do not have permission to work on report cards.', 'bsa-school-management' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$section = absint( $_GET['section'] ?? 0 );
		$section = in_array( $section, $sections, true ) ? $section : $sections[0];
		$current = BSA_Terms::current();
		$session = absint( $_GET['session'] ?? 0 );
		$session = isset( BSA_School::sessions()[ $session ] ) ? $session : ( $current ? $current['session'] : BSA_School::current_session() );
		$term    = absint( $_GET['term'] ?? 0 );
		$term    = ( $term >= 1 && $term <= 3 ) ? $term : ( ( $current && $current['session'] === $session ) ? $current['term'] : 1 );
		$editing = absint( $_GET['student'] ?? 0 );
		$notice  = sanitize_key( wp_unslash( $_GET['bsa_notice'] ?? '' ) );
		// phpcs:enable
		$admin = BSA_School::is_school_admin( $user_id );
		$sec   = BSA_School::section( $section );
		$base  = array( 'page' => self::PAGE, 'section' => $section, 'session' => $session, 'term' => $term );

		echo '<div class="wrap bsa-reports"><h1>' . esc_html__( 'Report Cards', 'bsa-school-management' ) . '</h1>';
		$messages = array( 'saved' => __( 'Comments saved.', 'bsa-school-management' ), 'published' => __( 'Report cards published. Parents and learners can now see them in My Account.', 'bsa-school-management' ), 'withdrawn' => __( 'Report cards withdrawn from parents.', 'bsa-school-management' ), 'failed' => __( 'That could not be done.', 'bsa-school-management' ) );
		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-' . ( 'failed' === $notice ? 'error' : 'success' ) . '"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><label for="bsa-section">' . esc_html__( 'Class', 'bsa-school-management' ) . '</label> <select id="bsa-section" name="section">';
		foreach ( $sections as $id ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $section, $id, false ), esc_html( BSA_School::section( $id )->title ) );
		}
		echo '</select> <label for="bsa-session">' . esc_html__( 'Year', 'bsa-school-management' ) . '</label> <select id="bsa-session" name="session">';
		foreach ( BSA_School::sessions() as $id => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $session, $id, false ), esc_html( $label ) );
		}
		echo '</select> <label for="bsa-term">' . esc_html__( 'Term', 'bsa-school-management' ) . '</label> <select id="bsa-term" name="term">';
		for ( $t = 1; $t <= 3; $t++ ) {
			printf( '<option value="%d"%s>%d</option>', (int) $t, selected( $term, $t, false ), (int) $t );
		}
		echo '</select> ';
		submit_button( __( 'Show', 'bsa-school-management' ), 'secondary', '', false );
		echo '</form>';

		$learners = BSA_School::learners( $section, $session );
		if ( ! $learners ) {
			echo '<p>' . esc_html__( 'No learners in this class for the selected year.', 'bsa-school-management' ) . '</p></div>';
			return;
		}
		$results = self::class_results( $section, $session, $term );
		$print   = function ( $student = 0 ) use ( $section, $session, $term ) {
			$args = array( 'action' => 'bsa_print_reports', 'section' => $section, 'session' => $session, 'term' => $term );
			if ( $student ) {
				$args['student'] = $student;
			}
			return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'bsa_print_reports_' . $section );
		};
		/* translators: 1: class, 2: term, 3: year. */
		echo '<h2>' . esc_html( sprintf( __( '%1$s, Term %2$d %3$s', 'bsa-school-management' ), $sec->title, $term, BSA_School::sessions()[ $session ] ) ) . '</h2>';
		echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( $print() ) . '">' . esc_html__( 'Print all report cards', 'bsa-school-management' ) . '</a> ';
		if ( $admin ) {
			foreach ( array( true => __( 'Publish to parents', 'bsa-school-management' ), false => __( 'Withdraw from parents', 'bsa-school-management' ) ) as $publish => $label ) {
				echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_publish_reports"><input type="hidden" name="publish" value="' . (int) $publish . '">';
				foreach ( array( 'section' => $section, 'session' => $session, 'term' => $term ) as $k => $v ) {
					echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . (int) $v . '">';
				}
				wp_nonce_field( 'bsa_publish_reports_' . $section . '_' . $term );
				submit_button( $label, $publish ? 'primary' : 'secondary', 'submit', false, $publish ? array() : array( 'onclick' => "return confirm('" . esc_js( __( 'Parents will no longer see these report cards. Continue?', 'bsa-school-management' ) ) . "');" ) );
				echo '</form> ';
			}
		}
		echo '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Learner', 'bsa-school-management' ), __( 'Subjects recorded', 'bsa-school-management' ), __( 'Passed', 'bsa-school-management' ), __( 'Average exam', 'bsa-school-management' ), __( 'Position', 'bsa-school-management' ), __( 'Result', 'bsa-school-management' ), __( 'Comments', 'bsa-school-management' ), __( 'Published', 'bsa-school-management' ), '' ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $learners as $l ) {
			$r = $results[ (int) $l->ID ];
			$c = self::comments( (int) $l->ID, $session, $term );
			echo '<tr><td>' . esc_html( $l->name ) . '</td><td>' . (int) $r['recorded'] . '</td><td>' . (int) $r['passed'] . '</td><td>' . esc_html( null === $r['average'] ? '' : number_format( $r['average'], 1 ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['position'] ?? '' ) ) . '</td><td>' . esc_html( $r['result'] ) . '</td>';
			echo '<td>' . esc_html( ( $c->form_teacher_comment ? __( 'Form teacher', 'bsa-school-management' ) : '' ) . ( $c->form_teacher_comment && $c->principal_comment ? ', ' : '' ) . ( $c->principal_comment ? __( 'Principal', 'bsa-school-management' ) : '' ) ) . '</td>';
			echo '<td>' . esc_html( $c->published_at ? mysql2date( 'j M Y', $c->published_at ) : '' ) . '</td>';
			echo '<td><a href="' . esc_url( add_query_arg( array_merge( $base, array( 'student' => (int) $l->ID ) ), admin_url( 'admin.php' ) ) . '#bsa-comments' ) . '">' . esc_html__( 'Comments', 'bsa-school-management' ) . '</a> · <a target="_blank" rel="noopener" href="' . esc_url( $print( (int) $l->ID ) ) . '">' . esc_html__( 'View / print', 'bsa-school-management' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';

		$ids = array_map( 'intval', wp_list_pluck( $learners, 'ID' ) );
		if ( $editing && in_array( $editing, $ids, true ) ) {
			$c      = self::comments( $editing, $session, $term );
			$locked = ! $admin && $c->published_at;
			$name   = '';
			foreach ( $learners as $l ) {
				$name = (int) $l->ID === $editing ? $l->name : $name;
			}
			/* translators: %s: learner. */
			echo '<h2 id="bsa-comments">' . esc_html( sprintf( __( 'Comments for %s', 'bsa-school-management' ), $name ) ) . '</h2>';
			if ( $locked ) {
				echo '<p>' . esc_html__( 'This report is published; ask the head to change it.', 'bsa-school-management' ) . '</p>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_save_report_comments">';
			foreach ( array( 'section' => $section, 'session' => $session, 'term' => $term, 'student' => $editing ) as $k => $v ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . (int) $v . '">';
			}
			wp_nonce_field( 'bsa_save_report_comments_' . $editing . '_' . $term );
			$ro = $locked ? ' readonly' : '';
			echo '<table class="form-table" role="presentation"><tbody>';
			echo '<tr><th scope="row"><label for="bsa-ftc">' . esc_html__( "Form teacher's comments", 'bsa-school-management' ) . '</label></th><td><textarea id="bsa-ftc" name="form_teacher_comment" rows="3" class="large-text" maxlength="600"' . $ro . '>' . esc_textarea( $c->form_teacher_comment ) . '</textarea></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $ro is a constant.
			echo '<tr><th scope="row"><label for="bsa-conduct">' . esc_html__( 'Character / conduct', 'bsa-school-management' ) . '</label></th><td><input type="text" id="bsa-conduct" name="conduct" class="regular-text" maxlength="100" value="' . esc_attr( $c->conduct ) . '"' . $ro . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $ro is a constant.
			if ( $admin ) {
				echo '<tr><th scope="row"><label for="bsa-pc">' . esc_html__( "Principal's comment", 'bsa-school-management' ) . '</label></th><td><textarea id="bsa-pc" name="principal_comment" rows="3" class="large-text" maxlength="600">' . esc_textarea( $c->principal_comment ) . '</textarea></td></tr>';
			}
			echo '</tbody></table>';
			if ( ! $locked ) {
				submit_button( __( 'Save comments', 'bsa-school-management' ) );
			}
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Saves comments (admin-post).
	 */
	public static function handle_comments() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified next.
		$student = isset( $_POST['student'] ) ? absint( $_POST['student'] ) : 0;
		$session = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
		$term    = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$section = isset( $_POST['section'] ) ? absint( $_POST['section'] ) : 0;
		// phpcs:enable
		check_admin_referer( 'bsa_save_report_comments_' . $student . '_' . $term );
		$input = array();
		foreach ( array( 'form_teacher_comment', 'conduct', 'principal_comment' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in save_comments().
			}
		}
		$result = self::save_comments( get_current_user_id(), $student, $session, $term, $input );
		if ( is_wp_error( $result ) && ! self::allowed_sections( get_current_user_id() ) ) {
			wp_die( esc_html( $result->get_error_message() ), 403 );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'section' => $section, 'session' => $session, 'term' => $term, 'student' => $student, 'bsa_notice' => is_wp_error( $result ) ? 'failed' : 'saved' ), admin_url( 'admin.php' ) ) . '#bsa-comments' );
		exit;
	}

	/**
	 * Publishes or withdraws a class's reports (admin-post).
	 */
	public static function handle_publish() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified next.
		$section = isset( $_POST['section'] ) ? absint( $_POST['section'] ) : 0;
		$session = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
		$term    = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$publish = ! empty( $_POST['publish'] );
		// phpcs:enable
		check_admin_referer( 'bsa_publish_reports_' . $section . '_' . $term );
		$result = self::publish( get_current_user_id(), $section, $session, $term, $publish );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 403 );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'section' => $section, 'session' => $session, 'term' => $term, 'bsa_notice' => $publish ? 'published' : 'withdrawn' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Printable report cards for staff (admin-post, opens in a new tab).
	 */
	public static function handle_print() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified next.
		$section = isset( $_GET['section'] ) ? absint( $_GET['section'] ) : 0;
		$session = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0;
		$term    = isset( $_GET['term'] ) ? absint( $_GET['term'] ) : 0;
		$student = isset( $_GET['student'] ) ? absint( $_GET['student'] ) : 0;
		// phpcs:enable
		check_admin_referer( 'bsa_print_reports_' . $section );
		if ( ! in_array( $section, self::allowed_sections( get_current_user_id() ), true ) || $term < 1 || $term > 3 ) {
			wp_die( esc_html__( 'You do not have permission to print these report cards.', 'bsa-school-management' ), 403 );
		}
		$cards = array();
		foreach ( BSA_School::learners( $section, $session ) as $l ) {
			if ( ! $student || (int) $l->ID === $student ) {
				$card = self::card( (int) $l->ID, $session, $term );
				if ( $card ) {
					$cards[] = $card;
				}
			}
		}
		self::output_document( $cards, true );
		exit;
	}

	/**
	 * Opens one published report card for its learner or parent.
	 */
	public static function family_view() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, ownership checked.
		if ( ! isset( $_GET['bsa_report'] ) ) {
			return;
		}
		$student = absint( $_GET['bsa_report'] );
		$session = absint( $_GET['session'] ?? 0 );
		$term    = absint( $_GET['term'] ?? 0 );
		// phpcs:enable
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			auth_redirect();
		}
		$owned   = class_exists( 'BSA_Access_Guard' ) ? BSA_Access_Guard::owned_student_ids( $user_id ) : array();
		$allowed = in_array( $student, array_map( 'intval', $owned ), true ) && BSA_Marks::is_published( $student, $session, $term );
		if ( ! $allowed && ! BSA_School::is_school_admin( $user_id ) ) {
			wp_die( esc_html__( 'This report card is not available.', 'bsa-school-management' ), '', array( 'response' => 404 ) );
		}
		$card = self::card( $student, $session, $term );
		if ( ! $card ) {
			wp_die( esc_html__( 'This report card is not available.', 'bsa-school-management' ), '', array( 'response' => 404 ) );
		}
		self::output_document( array( $card ), false );
		exit;
	}

	/**
	 * Full HTML document with one or more cards (A4 portrait) and the grade keys.
	 *
	 * @param array[] $cards Card data.
	 * @param bool    $staff Staff copy (shows "not published" marks).
	 */
	public static function output_document( array $cards, $staff ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$crest   = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html__( 'Report card', 'bsa-school-management' ) . ' - Bindura Science Academy</title><style>' . self::css() . '</style></head><body>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS.
		echo '<p class="noprint"><button type="button" onclick="window.print()">' . esc_html__( 'Print', 'bsa-school-management' ) . '</button></p>';
		if ( ! $cards ) {
			echo '<p>' . esc_html__( 'No report cards to show.', 'bsa-school-management' ) . '</p>';
		}
		foreach ( $cards as $card ) {
			echo self::render_card( $card, $crest, $staff ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_card().
		}
		echo self::render_keys( $crest ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_keys().
		echo '</body></html>';
	}

	/**
	 * One report card page.
	 *
	 * @param array  $c     Card data.
	 * @param string $crest Crest image URL.
	 * @param bool   $staff Staff copy.
	 * @return string HTML.
	 */
	public static function render_card( array $c, $crest, $staff ) {
		$e   = 'esc_html';
		$cm  = $c['comments'];
		$att = $c['attendance'];
		$h   = '<section class="card">';
		if ( $staff && ! $cm->published_at ) {
			$h .= '<p class="draft noprint">' . $e( __( 'Not yet published to parents.', 'bsa-school-management' ) ) . '</p>';
		}
		$h .= '<header><div class="ministry">' . $e( __( 'Ministry of Primary and Secondary Education', 'bsa-school-management' ) ) . '</div>';
		$h .= ( $crest ? '<img class="crest" src="' . esc_url( $crest ) . '" alt="' . esc_attr__( 'Bindura Science Academy crest', 'bsa-school-management' ) . '">' : '' );
		$h .= '<div class="school">Bindura Science Academy</div><div class="motto">' . $e( __( 'Passion for Excellence', 'bsa-school-management' ) ) . '</div></header>';
		$h .= '<div class="learner">' . $e( $c['name'] ) . ' <span>(' . $e( $c['admission'] ) . ')</span></div>';
		$h .= '<table class="head"><tr><td><b>' . $e( __( 'Form:', 'bsa-school-management' ) ) . '</b> ' . $e( $c['form'] ) . '</td><td><b>' . $e( __( 'Term:', 'bsa-school-management' ) ) . '</b> ' . (int) $c['term'] . '</td><td><b>' . $e( __( 'Year:', 'bsa-school-management' ) ) . '</b> ' . $e( $c['year'] ) . '</td></tr>';
		$h .= '<tr><td><b>' . $e( __( 'No. in Form:', 'bsa-school-management' ) ) . '</b> ' . (int) $c['in_form'] . '</td><td><b>' . $e( __( 'Class position:', 'bsa-school-management' ) ) . '</b> ' . $e( (string) ( $c['position'] ?? '' ) ) . '</td><td><b>' . $e( __( 'Attendance:', 'bsa-school-management' ) ) . '</b> ' . ( $att ? (int) $att['attended'] . ' ' . $e( __( 'out of', 'bsa-school-management' ) ) . ' ' . (int) $att['possible'] : '' ) . '</td></tr></table>';
		$h .= '<table class="marks"><thead><tr><th rowspan="2" class="area">' . $e( __( 'Learning Areas', 'bsa-school-management' ) ) . '</th><th colspan="2">' . $e( __( 'Term', 'bsa-school-management' ) ) . '</th><th colspan="2">' . $e( __( 'Exam', 'bsa-school-management' ) ) . '</th><th rowspan="2">' . $e( __( 'Projects', 'bsa-school-management' ) ) . '</th><th rowspan="2" class="remark">' . $e( __( "Teacher's Remarks", 'bsa-school-management' ) ) . '</th><th rowspan="2">' . $e( __( 'Init', 'bsa-school-management' ) ) . '</th></tr>';
		$h .= '<tr><th>' . $e( __( 'Mark', 'bsa-school-management' ) ) . '</th><th>' . $e( __( 'Grade', 'bsa-school-management' ) ) . '</th><th>' . $e( __( 'Mark', 'bsa-school-management' ) ) . '</th><th>' . $e( __( 'Grade', 'bsa-school-management' ) ) . '</th></tr></thead><tbody>';
		foreach ( $c['subjects'] as $s ) {
			$h .= '<tr><td class="area">' . $e( $s['label'] ) . '</td><td>' . $e( $s['term_mark'] ) . '</td><td>' . $e( $s['term_grade'] ) . '</td><td>' . $e( $s['exam_mark'] ) . '</td><td>' . $e( $s['exam_grade'] ) . '</td><td>' . $e( $s['project'] ) . '</td><td class="remark">' . $e( $s['remark'] ) . '</td><td>' . $e( $s['initials'] ) . '</td></tr>';
		}
		for ( $i = count( $c['subjects'] ); $i < 15; $i++ ) {
			$h .= '<tr class="blank"><td class="area">&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td class="remark"></td><td></td></tr>';
		}
		$h .= '</tbody></table>';
		$h .= '<p class="line"><b>' . $e( __( 'No. of Subjects Recorded:', 'bsa-school-management' ) ) . '</b> ' . (int) $c['recorded'] . ' &nbsp; <b>' . $e( __( 'No. of Subjects Passed:', 'bsa-school-management' ) ) . '</b> ' . (int) $c['passed'] . ' &nbsp; <b>' . $e( __( 'Result:', 'bsa-school-management' ) ) . '</b> ' . $e( $c['result'] ) . '</p>';
		$h .= '<p class="line"><b>' . $e( __( "Form Teacher's Comments:", 'bsa-school-management' ) ) . '</b> ' . $e( $cm->form_teacher_comment ) . '</p>';
		$h .= '<p class="line"><b>' . $e( __( 'Character/Conduct:', 'bsa-school-management' ) ) . '</b> ' . $e( $cm->conduct ) . ' <span class="sign"><b>' . $e( __( 'Sign:', 'bsa-school-management' ) ) . '</b> ' . $e( $cm->form_teacher_name ) . '</span></p>';
		$h .= '<p class="line"><b>' . $e( __( "Principal's Comment:", 'bsa-school-management' ) ) . '</b> ' . $e( $cm->principal_comment ) . ' <span class="sign"><b>' . $e( __( 'Sign:', 'bsa-school-management' ) ) . '</b> ' . $e( $cm->principal_name ) . '</span></p>';
		$h .= '<div class="foot"><div><p class="line"><b>' . $e( __( 'Next Term Begins on:', 'bsa-school-management' ) ) . '</b> ' . $e( $c['next_term'] ? mysql2date( 'j F Y', $c['next_term'] ) : '' ) . ' &nbsp; <b>' . $e( __( 'Next Term Fees:', 'bsa-school-management' ) ) . '</b> ' . $e( null === $c['next_fee'] ? '' : 'USD ' . number_format( $c['next_fee'], 2 ) ) . '</p>';
		$h .= '<p class="line"><b>' . $e( __( 'Parent/Guardian Signature:', 'bsa-school-management' ) ) . '</b> ______________________</p>';
		$h .= '<p class="boxed">' . $e( __( 'This Report is Issued Without Alteration/Erasure', 'bsa-school-management' ) ) . '</p></div><div class="stamp">' . $e( __( 'STAMP', 'bsa-school-management' ) ) . '</div></div>';
		return $h . '</section>';
	}

	/**
	 * Back page: keys to grades (as printed in the book).
	 *
	 * @param string $crest Crest image URL.
	 * @return string HTML.
	 */
	public static function render_keys( $crest ) {
		$keys = BSA_Grades::keys();
		$band = function ( array $key ) {
			$rows  = array();
			$upper = 100;
			foreach ( $key as $lowest => $grade ) {
				$rows[] = array( $lowest . '–' . $upper, $grade );
				$upper  = $lowest - 1;
			}
			return $rows;
		};
		$zjc = $band( $keys['zjc'] );
		$ol  = $band( $keys['olevel'] );
		$h   = '<section class="card keys"><h2>' . esc_html__( 'Keys to Grades', 'bsa-school-management' ) . '</h2><table class="marks"><thead><tr><th colspan="2">' . esc_html__( 'Form 1–2', 'bsa-school-management' ) . '</th><th colspan="2">' . esc_html__( 'Form 3–4', 'bsa-school-management' ) . '</th></tr><tr><th>' . esc_html__( 'Percentage', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Grade', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Percentage', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Grade', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		for ( $i = 0; $i < count( $zjc ); $i++ ) {
			$h .= '<tr><td>' . esc_html( $zjc[ $i ][0] ) . '</td><td>' . esc_html( $zjc[ $i ][1] ) . '</td><td>' . esc_html( $ol[ $i ][0] ?? '' ) . '</td><td>' . esc_html( $ol[ $i ][1] ?? '' ) . '</td></tr>';
		}
		$h .= '</tbody></table>' . ( $crest ? '<img class="crest big" src="' . esc_url( $crest ) . '" alt="">' : '' );
		$h .= '<p class="center">' . esc_html__( 'This report is issued without erasure/alteration', 'bsa-school-management' ) . '</p><p class="banner">' . esc_html__( "Let's help our children at home and encourage them to read", 'bsa-school-management' ) . '</p></section>';
		return $h;
	}

	/**
	 * Print styles (A4 portrait, BSA navy).
	 *
	 * @return string
	 */
	private static function css() {
		return 'body{font-family:"Plus Jakarta Sans",Arial,sans-serif;color:#1D212B;margin:0;background:#F6F7F9}'
			. '.card{background:#fff;width:190mm;min-height:270mm;margin:10mm auto;padding:10mm;box-sizing:border-box;page-break-after:always}'
			. 'header{display:grid;grid-template-columns:1fr auto;align-items:center;border-bottom:2px solid #1B3674;padding-bottom:4mm;margin-bottom:4mm}'
			. '.ministry{grid-column:1;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.crest{grid-column:2;grid-row:1/4;height:24mm}'
			. '.school{grid-column:1;font-family:"Playfair Display",Georgia,serif;font-size:22px;color:#1B3674;font-weight:700}.motto{grid-column:1;font-style:italic;font-size:12px}'
			. '.learner{font-size:16px;font-weight:700;margin:2mm 0}.learner span{font-weight:400;font-size:13px}'
			. 'table{border-collapse:collapse;width:100%}table.head td{padding:1.5mm 0;font-size:13px}'
			. 'table.marks th,table.marks td{border:1px solid #6B7280;padding:1.2mm 1.5mm;font-size:12px;text-align:center}table.marks th{background:#EEF1F7}'
			. 'table.marks .area,table.marks .remark{text-align:left}table.marks .remark{width:38%}.blank td{height:5mm}'
			. '.line{font-size:13px;margin:2.5mm 0;border-bottom:1px dotted #9CA3AF;padding-bottom:1mm}.sign{float:right}'
			. '.foot{display:grid;grid-template-columns:1fr 45mm;gap:5mm;align-items:end}.stamp{border:1px solid #6B7280;height:28mm;display:flex;align-items:center;justify-content:center;font-weight:700;border-radius:3mm}'
			. '.boxed{display:inline-block;border:1px solid #1D212B;padding:1mm 3mm;font-size:12px;font-weight:600}'
			. '.draft{background:#FFF4E5;padding:2mm;font-size:12px}.keys h2{text-align:center;color:#1B3674}.crest.big{display:block;margin:10mm auto;height:60mm}'
			. '.center{text-align:center;font-size:13px}.banner{background:#1B3674;color:#fff;text-align:center;padding:3mm;font-weight:700}'
			. '.noprint{text-align:center}@media print{body{background:#fff}.card{margin:0;width:auto;min-height:auto}.noprint{display:none}@page{size:A4 portrait;margin:10mm}}';
	}
}
