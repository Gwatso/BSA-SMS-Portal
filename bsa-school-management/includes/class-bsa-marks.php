<?php
/**
 * Marks: term mark, exam mark, project mark and remark per learner, subject and term,
 * entered by teachers (docs/academics-spec.md §3).
 *
 * Who may enter marks for a class and subject:
 * - school administrators (owner, Weblizar "admin" staff): everything;
 * - the class (form) teacher: every subject of their own class and stream;
 * - a subject teacher: the subjects Weblizar assigns to them (School Management → Staff →
 *   Subjects), in every stream of that form.
 * Once a learner's report for the term is published, only school administrators can
 * change that learner's marks (each change is logged).
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marks storage, rights and the Marks entry screen.
 */
class BSA_Marks {

	const PAGE       = 'bsa-marks';
	const DB_VERSION = '1';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_save_marks', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Marks table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bsa_marks';
	}

	/**
	 * Report comments / publication table (shared with BSA_Reports).
	 *
	 * @return string
	 */
	public static function reports_table() {
		global $wpdb;
		return $wpdb->prefix . 'bsa_reports';
	}

	/**
	 * Creates or updates the marks and reports tables.
	 */
	public static function maybe_migrate() {
		if ( get_option( 'bsa_sm_marks_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$marks   = self::table();
		$reports = self::reports_table();
		dbDelta(
			"CREATE TABLE {$marks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				student_record_id bigint(20) unsigned NOT NULL,
				session_id bigint(20) unsigned NOT NULL,
				term tinyint(3) unsigned NOT NULL,
				subject_id bigint(20) unsigned NOT NULL,
				term_mark decimal(4,1) DEFAULT NULL,
				exam_mark decimal(4,1) DEFAULT NULL,
				project_mark decimal(4,1) DEFAULT NULL,
				remark varchar(200) NOT NULL DEFAULT '',
				entered_by bigint(20) unsigned NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY learner_subject_term (student_record_id, session_id, term, subject_id),
				KEY subject_term (subject_id, session_id, term)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$reports} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				student_record_id bigint(20) unsigned NOT NULL,
				session_id bigint(20) unsigned NOT NULL,
				term tinyint(3) unsigned NOT NULL,
				form_teacher_comment text NOT NULL,
				conduct varchar(100) NOT NULL DEFAULT '',
				form_teacher_id bigint(20) unsigned DEFAULT NULL,
				principal_comment text NOT NULL,
				principal_id bigint(20) unsigned DEFAULT NULL,
				published_at datetime DEFAULT NULL,
				published_by bigint(20) unsigned DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY learner_term (student_record_id, session_id, term)
			) {$charset};"
		);
		update_option( 'bsa_sm_marks_db_version', self::DB_VERSION, false );
	}

	/**
	 * Weblizar subject IDs assigned to a staff member.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function assigned_subjects( $user_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ads.subject_id FROM {$p}wlsm_admin_subject ads JOIN {$p}wlsm_admins a ON a.ID = ads.admin_id JOIN {$p}wlsm_staff s ON s.ID = a.staff_id WHERE s.user_id = %d AND s.school_id = %d", $user_id, BSA_School::SCHOOL_ID ) ) );
	}

	/**
	 * Subjects of a section's form, ordered as in the BSA report book.
	 *
	 * @param int $section_id Section ID.
	 * @return object[] {ID, label}
	 */
	public static function section_subjects( $section_id ) {
		global $wpdb;
		$p    = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.ID, s.label FROM {$p}wlsm_subjects s JOIN {$p}wlsm_sections se ON se.class_school_id = s.class_school_id WHERE se.ID = %d", $section_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return self::book_order( $rows );
	}

	/**
	 * Sorts subject rows in the order printed in the report book; others follow.
	 *
	 * @param object[] $rows Rows with a label.
	 * @return object[]
	 */
	public static function book_order( array $rows ) {
		$order = array( 'english language', 'shona language', 'history', 'heritage studies', 'geography', 'combined science', 'biology', 'physics', 'chemistry', 'mathematics', 'business enterprise skills', 'principles of accounting', 'computer science' );
		usort(
			$rows,
			function ( $a, $b ) use ( $order ) {
				$ia = array_search( strtolower( trim( $a->label ) ), $order, true );
				$ib = array_search( strtolower( trim( $b->label ) ), $order, true );
				$ia = false === $ia ? 99 : $ia;
				$ib = false === $ib ? 99 : $ib;
				return array( $ia, $a->label ) <=> array( $ib, $b->label );
			}
		);
		return $rows;
	}

	/**
	 * Subjects (IDs) the user may enter marks for in a section.
	 *
	 * @param int $user_id    User ID.
	 * @param int $section_id Section ID.
	 * @return int[]
	 */
	public static function allowed_subjects( $user_id, $section_id ) {
		if ( ! $user_id || ! BSA_School::section( $section_id ) ) {
			return array();
		}
		$subjects = array_map( 'intval', wp_list_pluck( self::section_subjects( $section_id ), 'ID' ) );
		if ( BSA_School::is_school_admin( $user_id ) ) {
			return $subjects;
		}
		$staff = BSA_School::staff( $user_id );
		if ( ! $staff ) {
			return array();
		}
		if ( in_array( 'assigned_class', $staff->permissions, true ) && (int) $staff->section_id === (int) $section_id ) {
			return $subjects;
		}
		return array_values( array_intersect( $subjects, self::assigned_subjects( $user_id ) ) );
	}

	/**
	 * Sections where the user may enter at least one subject's marks.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function allowed_sections( $user_id ) {
		$out = array();
		foreach ( BSA_School::sections() as $section ) {
			if ( self::allowed_subjects( $user_id, (int) $section->ID ) ) {
				$out[] = (int) $section->ID;
			}
		}
		return $out;
	}

	/**
	 * Learners of a class who take a subject: those with no subjects recorded take every
	 * subject; the others only their own (Sciences or Commercials in Forms 3–4).
	 *
	 * @param int $section_id Section ID.
	 * @param int $session_id Session ID.
	 * @param int $subject_id Subject ID.
	 * @return object[]
	 */
	public static function subject_learners( $section_id, $session_id, $subject_id ) {
		$learners = BSA_School::learners( $section_id, $session_id );
		$taken    = BSA_Streams::subject_map( wp_list_pluck( $learners, 'ID' ) );
		return array_values(
			array_filter(
				$learners,
				static function ( $l ) use ( $taken, $subject_id ) {
					$mine = $taken[ (int) $l->ID ] ?? array();
					return ! $mine || in_array( (int) $subject_id, $mine, true );
				}
			)
		);
	}

	/**
	 * Marks for a set of learners in a term: [student][subject] => row.
	 *
	 * @param int[] $student_ids Student record IDs.
	 * @param int   $session_id  Session ID.
	 * @param int   $term        1–3.
	 * @return array<int,array<int,object>>
	 */
	public static function for_learners( array $student_ids, $session_id, $term ) {
		$student_ids = array_filter( array_map( 'intval', $student_ids ) );
		if ( ! $student_ids ) {
			return array();
		}
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.*, u.display_name AS entered_by_name FROM {$table} m LEFT JOIN {$wpdb->users} u ON u.ID = m.entered_by WHERE m.session_id = %d AND m.term = %d AND m.student_record_id IN (" . implode( ',', $student_ids ) . ')', $session_id, $term ) );
		// phpcs:enable
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->student_record_id ][ (int) $row->subject_id ] = $row;
		}
		return $out;
	}

	/**
	 * Whether a learner's report for the term is published (locks marks for teachers).
	 *
	 * @param int $student_id Student record ID.
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return bool
	 */
	public static function is_published( $student_id, $session_id, $term ) {
		global $wpdb;
		$table = self::reports_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT published_at FROM {$table} WHERE student_record_id = %d AND session_id = %d AND term = %d AND published_at IS NOT NULL", $student_id, $session_id, $term ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Validates and saves one subject's marks for a class. Rows: student_id =>
	 * [term, exam, project, remark] (strings as typed).
	 *
	 * @param int   $user_id    Acting user.
	 * @param int   $section_id Section ID.
	 * @param int   $session_id Session ID.
	 * @param int   $term       1–3.
	 * @param int   $subject_id Subject ID.
	 * @param array $rows       Input rows.
	 * @return array{saved:int, errors:string[]}
	 */
	public static function save( $user_id, $section_id, $session_id, $term, $subject_id, array $rows ) {
		$errors = array();
		if ( ! in_array( (int) $subject_id, self::allowed_subjects( $user_id, $section_id ), true ) || $term < 1 || $term > 3 || ! isset( BSA_School::sessions()[ $session_id ] ) ) {
			return array( 'saved' => 0, 'errors' => array( __( 'You cannot enter marks for this class and subject.', 'bsa-school-management' ) ) );
		}
		$learners = array();
		foreach ( self::subject_learners( $section_id, $session_id, $subject_id ) as $l ) {
			$learners[ (int) $l->ID ] = $l;
		}
		$admin    = BSA_School::is_school_admin( $user_id );
		$existing = self::for_learners( array_keys( $learners ), $session_id, $term );
		$clean    = array();
		foreach ( $rows as $student_id => $input ) {
			$student_id = (int) $student_id;
			if ( ! isset( $learners[ $student_id ] ) || ! is_array( $input ) ) {
				$errors[] = __( 'A learner in the form is not in this class or does not take this subject.', 'bsa-school-management' );
				continue;
			}
			$name   = $learners[ $student_id ]->name;
			$values = array();
			foreach ( array( 'term' => 'term_mark', 'exam' => 'exam_mark', 'project' => 'project_mark' ) as $key => $column ) {
				list( $mark, $ok ) = BSA_Grades::parse_mark( $input[ $key ] ?? '' );
				if ( ! $ok ) {
					/* translators: 1: learner, 2: mark kind. */
					$errors[] = sprintf( __( '%1$s: the %2$s mark must be a number from 0 to 100 (one decimal at most).', 'bsa-school-management' ), $name, $key );
					continue 2;
				}
				$values[ $column ] = $mark;
			}
			$values['remark'] = mb_substr( trim( sanitize_text_field( (string) ( $input['remark'] ?? '' ) ) ), 0, 200 );
			$old              = $existing[ $student_id ][ (int) $subject_id ] ?? null;
			$same             = $old && self::num( $old->term_mark ) === $values['term_mark'] && self::num( $old->exam_mark ) === $values['exam_mark'] && self::num( $old->project_mark ) === $values['project_mark'] && (string) $old->remark === $values['remark'];
			$empty            = null === $values['term_mark'] && null === $values['exam_mark'] && null === $values['project_mark'] && '' === $values['remark'];
			if ( $same || ( ! $old && $empty ) ) {
				continue;
			}
			if ( ! $admin && self::is_published( $student_id, $session_id, $term ) ) {
				/* translators: %s: learner. */
				$errors[] = sprintf( __( '%s: the report is published; ask the head to change these marks.', 'bsa-school-management' ), $name );
				continue;
			}
			$clean[ $student_id ] = array( $values, $old );
		}
		if ( $errors ) {
			return array( 'saved' => 0, 'errors' => $errors );
		}
		global $wpdb;
		$saved = 0;
		foreach ( $clean as $student_id => list( $values, $old ) ) {
			$row = array_merge( $values, array( 'student_record_id' => $student_id, 'session_id' => (int) $session_id, 'term' => (int) $term, 'subject_id' => (int) $subject_id, 'entered_by' => (int) $user_id, 'updated_at' => current_time( 'mysql' ) ) );
			if ( $old ) {
				$wpdb->update( self::table(), $row, array( 'id' => (int) $old->id ) );
			} else {
				$wpdb->insert( self::table(), $row );
			}
			++$saved;
			if ( $old && self::is_published( $student_id, $session_id, $term ) && function_exists( 'SimpleLogger' ) ) {
				SimpleLogger()->warning( 'Published marks changed: student record #{student}, subject #{subject}, term {term}', array( 'student' => $student_id, 'subject' => (int) $subject_id, 'term' => (int) $term ) );
			}
		}
		if ( $saved && class_exists( 'BSA_Reports' ) ) {
			BSA_Reports::flush();
		}
		if ( $saved && function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->info( 'Marks saved: {count} learner(s), subject #{subject}, section #{section}, term {term}', array( 'count' => $saved, 'subject' => (int) $subject_id, 'section' => (int) $section_id, 'term' => (int) $term ) );
		}
		return array( 'saved' => $saved, 'errors' => array() );
	}

	/**
	 * A mark for display: '65', '60.5', '0'; '' for none (typed text is shown as typed).
	 *
	 * @param mixed $value Stored decimal, typed string or null.
	 * @return string
	 */
	public static function show( $value ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( ! is_numeric( $value ) ) {
			return (string) $value;
		}
		$f = (float) $value;
		return floor( $f ) === $f ? (string) (int) $f : number_format( $f, 1, '.', '' );
	}

	/**
	 * Database decimal to float|null.
	 *
	 * @param mixed $value Value.
	 * @return float|null
	 */
	private static function num( $value ) {
		return null === $value || '' === $value ? null : (float) $value;
	}

	/**
	 * Menu for anyone who may enter marks.
	 */
	public static function register_menu() {
		if ( ! self::allowed_sections( get_current_user_id() ) ) {
			return;
		}
		add_menu_page( __( 'Marks', 'bsa-school-management' ), __( 'Marks', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-welcome-write-blog', 31 );
	}

	/**
	 * Screen state from the request.
	 *
	 * @param array $source  $_GET (unslashed).
	 * @param int   $user_id User ID.
	 * @return array{section:int,session:int,term:int,subject:int,sections:int[],subjects:int[]}
	 */
	public static function state( array $source, $user_id ) {
		$sections = self::allowed_sections( $user_id );
		$section  = absint( $source['section'] ?? 0 );
		$section  = in_array( $section, $sections, true ) ? $section : (int) ( $sections[0] ?? 0 );
		$subjects = $section ? self::allowed_subjects( $user_id, $section ) : array();
		$subject  = absint( $source['subject'] ?? 0 );
		$subject  = in_array( $subject, $subjects, true ) ? $subject : (int) ( $subjects[0] ?? 0 );
		$current  = BSA_Terms::current();
		$session  = absint( $source['session'] ?? 0 );
		if ( ! isset( BSA_School::sessions()[ $session ] ) ) {
			$session = $current ? $current['session'] : BSA_School::current_session();
		}
		$term = absint( $source['term'] ?? 0 );
		if ( $term < 1 || $term > 3 ) {
			$term = ( $current && $current['session'] === $session ) ? $current['term'] : 1;
		}
		return compact( 'section', 'session', 'term', 'subject', 'sections', 'subjects' );
	}

	/**
	 * Marks entry screen.
	 */
	public static function render_page() {
		$user_id = get_current_user_id();
		if ( ! self::allowed_sections( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to enter marks.', 'bsa-school-management' ), 403 );
		}
		$s       = self::state( wp_unslash( $_GET ), $user_id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		$section = BSA_School::section( $s['section'] );
		$names   = array();
		foreach ( self::section_subjects( $s['section'] ) as $row ) {
			$names[ (int) $row->ID ] = $row->label;
		}
		echo '<div class="wrap bsa-marks"><h1>' . esc_html__( 'Marks', 'bsa-school-management' ) . '</h1>';
		$errors = get_transient( 'bsa_marks_errors_' . $user_id );
		delete_transient( 'bsa_marks_errors_' . $user_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$saved = isset( $_GET['bsa_saved'] ) ? absint( $_GET['bsa_saved'] ) : null;
		if ( null !== $saved ) {
			/* translators: %d: number of learners. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( 'Marks saved for %d learner.', 'Marks saved for %d learners.', $saved, 'bsa-school-management' ), $saved ) ) . '</p></div>';
		}
		if ( is_array( $errors ) && $errors ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Nothing was saved. Correct these and save again:', 'bsa-school-management' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $errors ) ) . '</li></ul></div>';
		}

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label for="bsa-section">' . esc_html__( 'Class', 'bsa-school-management' ) . '</label> <select id="bsa-section" name="section">';
		foreach ( $s['sections'] as $id ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $s['section'], $id, false ), esc_html( BSA_School::section( $id )->title ) );
		}
		echo '</select> <label for="bsa-subject">' . esc_html__( 'Subject', 'bsa-school-management' ) . '</label> <select id="bsa-subject" name="subject">';
		foreach ( $s['subjects'] as $id ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $s['subject'], $id, false ), esc_html( $names[ $id ] ?? '' ) );
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
		submit_button( __( 'Show', 'bsa-school-management' ), 'secondary', '', false );
		echo '</form>';

		$all      = $s['subject'] ? count( BSA_School::learners( $s['section'], $s['session'] ) ) : 0;
		$learners = $s['subject'] ? self::subject_learners( $s['section'], $s['session'], $s['subject'] ) : array();
		if ( ! $learners || ! $s['subject'] ) {
			echo '<p>' . esc_html( $all ? __( 'No learner in this class takes this subject.', 'bsa-school-management' ) : __( 'No learners in this class for the selected year.', 'bsa-school-management' ) ) . '</p></div>';
			return;
		}
		$marks = self::for_learners( wp_list_pluck( $learners, 'ID' ), $s['session'], $s['term'] );
		$admin = BSA_School::is_school_admin( $user_id );
		// After a refused save, show what the teacher typed rather than the stored marks.
		$draft = get_transient( 'bsa_marks_draft_' . $user_id );
		delete_transient( 'bsa_marks_draft_' . $user_id );
		$draft = ( is_array( $draft ) && $draft['key'] === implode( '-', array( $s['section'], $s['session'], $s['term'], $s['subject'] ) ) ) ? $draft['rows'] : array();
		/* translators: 1: subject, 2: class, 3: term, 4: year. */
		echo '<h2>' . esc_html( sprintf( __( '%1$s, %2$s, Term %3$d %4$s', 'bsa-school-management' ), $names[ $s['subject'] ] ?? '', $section->title, $s['term'], BSA_School::sessions()[ $s['session'] ] ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Marks are percentages (0–100). Grades follow the BSA report book. The exam mark decides passes and position; leave a mark empty if the learner did not sit it.', 'bsa-school-management' ) . '</p>';
		if ( count( $learners ) < $all ) {
			/* translators: 1: learners taking the subject, 2: learners in the class. */
			echo '<p class="description">' . esc_html( sprintf( __( 'Only the learners taking this subject are listed: %1$d of %2$d in the class (streams).', 'bsa-school-management' ), count( $learners ), $all ) ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_save_marks">';
		foreach ( array( 'section', 'session', 'term', 'subject' ) as $key ) {
			echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . (int) $s[ $key ] . '">';
		}
		wp_nonce_field( 'bsa_save_marks_' . $s['section'] . '_' . $s['subject'] );
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'No.', 'bsa-school-management' ), __( 'Learner', 'bsa-school-management' ), __( 'Term mark', 'bsa-school-management' ), __( 'Grade', 'bsa-school-management' ), __( 'Exam mark', 'bsa-school-management' ), __( 'Grade', 'bsa-school-management' ), __( 'Projects', 'bsa-school-management' ), __( "Teacher's remark", 'bsa-school-management' ), __( 'Init', 'bsa-school-management' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $learners as $n => $l ) {
			$m      = $marks[ (int) $l->ID ][ $s['subject'] ] ?? null;
			$typed  = $draft[ (int) $l->ID ] ?? null;
			$locked = ! $admin && self::is_published( (int) $l->ID, $s['session'], $s['term'] );
			$attr   = $locked ? ' readonly' : '';
			$field  = function ( $key, $value, $label ) use ( $l, $attr ) {
				$id = 'bsa-' . $key . '-' . (int) $l->ID;
				return '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input type="text" inputmode="decimal" size="4" id="' . esc_attr( $id ) . '" name="marks[' . (int) $l->ID . '][' . $key . ']" value="' . esc_attr( BSA_Marks::show( $value ) ) . '"' . $attr . '>';
			};
			echo '<tr><td>' . (int) ( $n + 1 ) . '</td><td>' . esc_html( $l->name ) . ( $locked ? ' <span class="description">(' . esc_html__( 'published', 'bsa-school-management' ) . ')</span>' : '' ) . '</td>';
			/* translators: %s: learner. */
			echo '<td>' . $field( 'term', $typed ? $typed['term'] : ( $m ? $m->term_mark : null ), sprintf( __( 'Term mark for %s', 'bsa-school-management' ), $l->name ) ) . '</td><td>' . esc_html( $m ? BSA_Grades::grade( $m->term_mark, $section->class_label ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $field.
			/* translators: %s: learner. */
			echo '<td>' . $field( 'exam', $typed ? $typed['exam'] : ( $m ? $m->exam_mark : null ), sprintf( __( 'Exam mark for %s', 'bsa-school-management' ), $l->name ) ) . '</td><td>' . esc_html( $m ? BSA_Grades::grade( $m->exam_mark, $section->class_label ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $field.
			/* translators: %s: learner. */
			echo '<td>' . $field( 'project', $typed ? $typed['project'] : ( $m ? $m->project_mark : null ), sprintf( __( 'Project mark for %s', 'bsa-school-management' ), $l->name ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $field.
			$rid = 'bsa-remark-' . (int) $l->ID;
			/* translators: %s: learner. */
			echo '<td><label class="screen-reader-text" for="' . esc_attr( $rid ) . '">' . esc_html( sprintf( __( 'Remark for %s', 'bsa-school-management' ), $l->name ) ) . '</label><input type="text" maxlength="200" class="regular-text" id="' . esc_attr( $rid ) . '" name="marks[' . (int) $l->ID . '][remark]" value="' . esc_attr( $typed ? $typed['remark'] : ( $m ? $m->remark : '' ) ) . '"' . $attr . '></td>';
			echo '<td>' . esc_html( $m ? BSA_Grades::initials( $m->entered_by_name ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save marks', 'bsa-school-management' ) );
		echo '</form></div>';
	}

	/**
	 * Saves the marks form (admin-post).
	 */
	public static function handle_save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified next.
		$section = isset( $_POST['section'] ) ? absint( $_POST['section'] ) : 0;
		$session = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0;
		$term    = isset( $_POST['term'] ) ? absint( $_POST['term'] ) : 0;
		$subject = isset( $_POST['subject'] ) ? absint( $_POST['subject'] ) : 0;
		// phpcs:enable
		check_admin_referer( 'bsa_save_marks_' . $section . '_' . $subject );
		$user_id = get_current_user_id();
		if ( ! in_array( $subject, self::allowed_subjects( $user_id, $section ), true ) ) {
			wp_die( esc_html__( 'You cannot enter marks for this class and subject.', 'bsa-school-management' ), 403 );
		}
		$rows   = isset( $_POST['marks'] ) && is_array( $_POST['marks'] ) ? wp_unslash( $_POST['marks'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in save().
		$result = self::save( $user_id, $section, $session, $term, $subject, $rows );
		$args   = array( 'page' => self::PAGE, 'section' => $section, 'session' => $session, 'term' => $term, 'subject' => $subject );
		if ( $result['errors'] ) {
			set_transient( 'bsa_marks_errors_' . $user_id, $result['errors'], 300 );
			$draft = array();
			foreach ( $rows as $student_id => $input ) {
				if ( is_array( $input ) ) {
					foreach ( array( 'term', 'exam', 'project', 'remark' ) as $key ) {
						$draft[ (int) $student_id ][ $key ] = mb_substr( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ), 0, 200 );
					}
				}
			}
			set_transient( 'bsa_marks_draft_' . $user_id, array( 'key' => implode( '-', array( $section, $session, $term, $subject ) ), 'rows' => $draft ), 300 );
		} else {
			$args['bsa_saved'] = $result['saved'];
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
