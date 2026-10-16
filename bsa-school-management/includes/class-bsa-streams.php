<?php
/**
 * Streams (wp-admin): Sciences and Commercials as subject choices inside one Form 3 and
 * one Form 4 class (owner, 2026-10-16: one class, one class teacher, one register; the
 * learners split only for their stream subjects).
 *
 * - A stream is a set of subjects taken on top of the subjects everyone in the form takes.
 *   The lists are a setting (defaults from the owner): Sciences = Physics, Chemistry,
 *   Biology; Commercials = Heritage Studies, Combined Science, Business Enterprise
 *   Skills. Streaming applies from Form 3.
 * - A learner's stream is recorded as their Weblizar per-learner subjects (everyone's
 *   subjects plus the stream's), which report cards, marks sheets and results already
 *   follow. The stream shown is read back from those subjects; a learner with no
 *   subjects recorded takes every subject of the form ("not set").
 * - Position stays within the class, so the whole Form 3 is ranked together.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Streams.
 */
class BSA_Streams {

	const PAGE   = 'bsa-streams';
	const OPTION = 'bsa_sm_streams';

	/**
	 * Forms where learners choose a stream.
	 */
	const FORMS = array( 'Form 3', 'Form 4' );

	/**
	 * Default stream subjects (owner, 2026-10-16). Every other subject is for everyone.
	 */
	const DEFAULTS = array(
		'Sciences'    => array( 'Physics', 'Chemistry', 'Biology' ),
		'Commercials' => array( 'Heritage Studies', 'Combined Science', 'Business Enterprise Skills' ),
	);

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_save_streams', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bsa_save_stream_subjects', array( __CLASS__, 'handle_subjects' ) );
	}

	/**
	 * Stream name => subject labels.
	 *
	 * @return array<string,string[]>
	 */
	public static function streams() {
		$saved = get_option( self::OPTION, null );
		if ( ! is_array( $saved ) ) {
			return self::DEFAULTS;
		}
		$out = array();
		foreach ( array_keys( self::DEFAULTS ) as $name ) {
			$out[ $name ] = isset( $saved[ $name ] ) && is_array( $saved[ $name ] ) ? array_values( array_map( 'strval', $saved[ $name ] ) ) : array();
		}
		return $out;
	}

	/**
	 * The stream a subject belongs to, or '' for everyone.
	 *
	 * @param string $label Subject label.
	 * @return string
	 */
	public static function subject_stream( $label ) {
		$key = strtolower( trim( (string) $label ) );
		foreach ( self::streams() as $name => $labels ) {
			foreach ( $labels as $l ) {
				if ( strtolower( trim( $l ) ) === $key ) {
					return $name;
				}
			}
		}
		return '';
	}

	/**
	 * Whether learners of this section choose a stream.
	 *
	 * @param int $section_id Section ID.
	 * @return bool
	 */
	public static function applies( $section_id ) {
		$section = BSA_School::section( $section_id );
		return $section && in_array( $section->class_label, self::FORMS, true );
	}

	/**
	 * A stream name as written (case-insensitive; "Sci", "Science", "Com", "Commerce").
	 *
	 * @param string $text Text.
	 * @return string Stream name or ''.
	 */
	public static function parse( $text ) {
		$t = strtolower( (string) $text );
		if ( preg_match( '/\bsci/', $t ) ) {
			return 'Sciences';
		}
		if ( preg_match( '/\bcom/', $t ) ) {
			return 'Commercials';
		}
		return '';
	}

	/**
	 * Subject IDs a learner of the section takes in a stream: everyone's subjects plus
	 * the stream's. Empty for '' (no stream: every subject).
	 *
	 * @param int    $section_id Section ID.
	 * @param string $stream     Stream name.
	 * @return int[]
	 */
	public static function subjects_for( $section_id, $stream ) {
		if ( '' === $stream || ! isset( self::streams()[ $stream ] ) ) {
			return array();
		}
		$out = array();
		foreach ( BSA_Marks::section_subjects( $section_id ) as $s ) {
			$belongs = self::subject_stream( $s->label );
			if ( '' === $belongs || $stream === $belongs ) {
				$out[] = (int) $s->ID;
			}
		}
		return $out;
	}

	/**
	 * Recorded subjects of several learners: student ID => subject IDs (none = all).
	 *
	 * @param int[] $student_ids Student record IDs.
	 * @return array<int,int[]>
	 */
	public static function subject_map( array $student_ids ) {
		global $wpdb;
		$student_ids = array_filter( array_map( 'intval', $student_ids ) );
		$out         = array_fill_keys( $student_ids, array() );
		if ( ! $student_ids ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- integer list.
		foreach ( $wpdb->get_results( "SELECT student_id, subject_id FROM {$wpdb->prefix}wlsm_students_subjects WHERE student_id IN (" . implode( ',', $student_ids ) . ')' ) as $r ) {
			$out[ (int) $r->student_id ][] = (int) $r->subject_id;
		}
		return $out;
	}

	/**
	 * A learner's stream, read from their subjects.
	 *
	 * @param int        $section_id Section ID.
	 * @param int[]      $taken      Subject IDs recorded for the learner.
	 * @return string Stream name; '' when not set (no subjects recorded); 'mixed' when the
	 *                subjects do not match one stream.
	 */
	public static function stream_of( $section_id, array $taken ) {
		if ( ! $taken ) {
			return '';
		}
		$count = array_fill_keys( array_keys( self::streams() ), 0 );
		foreach ( BSA_Marks::section_subjects( $section_id ) as $s ) {
			$belongs = self::subject_stream( $s->label );
			if ( '' !== $belongs && in_array( (int) $s->ID, $taken, true ) ) {
				++$count[ $belongs ];
			}
		}
		$chosen = array_keys( array_filter( $count ) );
		return 1 === count( $chosen ) ? $chosen[0] : 'mixed';
	}

	/**
	 * Words for a stream value.
	 *
	 * @param string $stream Stream, '' or 'mixed'.
	 * @return string
	 */
	public static function label( $stream ) {
		if ( '' === $stream ) {
			return __( 'Not set', 'bsa-school-management' );
		}
		if ( 'mixed' === $stream ) {
			return __( 'Own subject choice', 'bsa-school-management' );
		}
		return $stream;
	}

	/**
	 * Records a learner's stream as their subjects ('' removes them: every subject).
	 *
	 * @param int    $student_id Student record ID.
	 * @param int    $section_id Section ID.
	 * @param string $stream     Stream name or ''.
	 * @return bool
	 */
	public static function set( $student_id, $section_id, $stream ) {
		global $wpdb;
		if ( '' !== $stream && ! isset( self::streams()[ $stream ] ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'wlsm_students_subjects';
		$wpdb->delete( $table, array( 'student_id' => (int) $student_id ) );
		foreach ( self::subjects_for( $section_id, $stream ) as $subject_id ) {
			$wpdb->insert( $table, array( 'student_id' => (int) $student_id, 'subject_id' => $subject_id ) );
		}
		return true;
	}

	/**
	 * Copies a learner's subjects to another class (next year's form), matching subject
	 * names. Nothing recorded stays nothing (every subject).
	 *
	 * @param int $from_student From student record.
	 * @param int $to_student   To student record.
	 * @param int $to_section   To section.
	 */
	public static function copy_subjects( $from_student, $to_student, $to_section ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT LOWER(TRIM(s.label)) FROM {$p}wlsm_students_subjects ss JOIN {$p}wlsm_subjects s ON s.ID = ss.subject_id WHERE ss.student_id = %d", $from_student ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $names ) {
			return;
		}
		foreach ( BSA_Marks::section_subjects( $to_section ) as $s ) {
			if ( in_array( strtolower( trim( $s->label ) ), $names, true ) ) {
				$wpdb->insert( "{$p}wlsm_students_subjects", array( 'student_id' => (int) $to_student, 'subject_id' => (int) $s->ID ) );
			}
		}
	}

	/**
	 * Learners in stream forms without a stream, by class (for the dashboard).
	 *
	 * @param int $session_id Session ID.
	 * @return array<string,int> Class title => count.
	 */
	public static function unset_counts( $session_id ) {
		$out = array();
		foreach ( BSA_School::sections() as $sec ) {
			if ( ! in_array( $sec->class_label, self::FORMS, true ) ) {
				continue;
			}
			$learners = BSA_School::learners( (int) $sec->ID, $session_id );
			$n        = count(
				array_filter(
					self::subject_map( wp_list_pluck( $learners, 'ID' ) ),
					static function ( $subjects ) {
						return ! $subjects;
					}
				)
			);
			if ( $n ) {
				$out[ $sec->title ] = $n;
			}
		}
		return $out;
	}

	/**
	 * Sections whose streams the user may set: school administrators all stream forms;
	 * a class teacher their own class.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function allowed_sections( $user_id ) {
		$out = array();
		foreach ( BSA_School::sections() as $sec ) {
			if ( ! in_array( $sec->class_label, self::FORMS, true ) ) {
				continue;
			}
			if ( BSA_School::is_school_admin( $user_id ) ) {
				$out[] = (int) $sec->ID;
				continue;
			}
			$staff = BSA_School::staff( $user_id );
			if ( $staff && in_array( 'assigned_class', $staff->permissions, true ) && (int) $staff->section_id === (int) $sec->ID ) {
				$out[] = (int) $sec->ID;
			}
		}
		return $out;
	}

	/**
	 * Menu entry.
	 */
	public static function register_menu() {
		if ( self::allowed_sections( get_current_user_id() ) ) {
			add_menu_page( __( 'Streams', 'bsa-school-management' ), __( 'Streams', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-randomize', 32 );
		}
	}

	/**
	 * Saves several learners' streams. Rows: student ID => stream ('' or a name; 'keep'
	 * leaves an own subject choice alone).
	 *
	 * @param int   $user_id    Acting user.
	 * @param int   $section_id Section ID.
	 * @param int   $session_id Session ID.
	 * @param array $rows       Input.
	 * @return int|WP_Error Learners changed.
	 */
	public static function save( $user_id, $section_id, $session_id, array $rows ) {
		if ( ! in_array( (int) $section_id, self::allowed_sections( $user_id ), true ) ) {
			return new WP_Error( 'bsa_streams', __( 'You cannot set streams for this class.', 'bsa-school-management' ) );
		}
		$learners = wp_list_pluck( BSA_School::learners( $section_id, $session_id ), 'name', 'ID' );
		$map      = self::subject_map( array_keys( $learners ) );
		$changed  = 0;
		foreach ( $rows as $student_id => $stream ) {
			$student_id = (int) $student_id;
			$stream     = sanitize_text_field( (string) $stream );
			if ( ! isset( $learners[ $student_id ] ) || 'keep' === $stream ) {
				continue;
			}
			if ( '' !== $stream && ! isset( self::streams()[ $stream ] ) ) {
				continue;
			}
			if ( self::stream_of( $section_id, $map[ $student_id ] ?? array() ) === $stream ) {
				continue;
			}
			self::set( $student_id, $section_id, $stream );
			++$changed;
		}
		if ( $changed ) {
			if ( class_exists( 'BSA_Reports' ) ) {
				BSA_Reports::flush();
			}
			if ( function_exists( 'SimpleLogger' ) ) {
				SimpleLogger()->info( 'Streams set for {count} learner(s), section #{section}', array( 'count' => $changed, 'section' => (int) $section_id ) );
			}
		}
		return $changed;
	}

	/**
	 * Screen URL.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Saves the streams form (admin-post).
	 */
	public static function handle_save() {
		$section = absint( $_POST['section'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		$session = absint( $_POST['session'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'bsa_save_streams_' . $section );
		$rows   = isset( $_POST['stream'] ) && is_array( $_POST['stream'] ) ? wp_unslash( $_POST['stream'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in save().
		$result = self::save( get_current_user_id(), $section, $session, $rows );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 403 );
		}
		wp_safe_redirect( self::url( array( 'section' => $section, 'session' => $session, 'saved' => $result ) ) );
		exit;
	}

	/**
	 * Saves which subjects belong to each stream (school administrators).
	 */
	public static function handle_subjects() {
		check_admin_referer( 'bsa_save_stream_subjects' );
		if ( ! BSA_School::is_school_admin( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You cannot change the stream subjects.', 'bsa-school-management' ), 403 );
		}
		$input = isset( $_POST['belongs'] ) && is_array( $_POST['belongs'] ) ? wp_unslash( $_POST['belongs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against the stream names.
		$out   = array_fill_keys( array_keys( self::DEFAULTS ), array() );
		foreach ( self::form_subjects() as $label ) {
			$to = sanitize_text_field( (string) ( $input[ md5( $label ) ] ?? '' ) );
			if ( isset( $out[ $to ] ) ) {
				$out[ $to ][] = $label;
			}
		}
		update_option( self::OPTION, $out, false );
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->info( 'Stream subjects changed', array( 'streams' => wp_json_encode( $out ) ) );
		}
		wp_safe_redirect( self::url( array( 'subjects_saved' => 1 ) ) );
		exit;
	}

	/**
	 * Subject names used in the stream forms, in report book order.
	 *
	 * @return string[]
	 */
	private static function form_subjects() {
		$rows = array();
		foreach ( BSA_School::sections() as $sec ) {
			if ( in_array( $sec->class_label, self::FORMS, true ) ) {
				foreach ( BSA_Marks::section_subjects( (int) $sec->ID ) as $s ) {
					$rows[ strtolower( trim( $s->label ) ) ] = $s;
				}
			}
		}
		return wp_list_pluck( BSA_Marks::book_order( array_values( $rows ) ), 'label' );
	}

	/**
	 * The Streams screen: each learner's stream for a class and year; the stream subjects.
	 */
	public static function render_page() {
		$user_id  = get_current_user_id();
		$sections = self::allowed_sections( $user_id );
		if ( ! $sections ) {
			wp_die( esc_html__( 'You cannot set streams.', 'bsa-school-management' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view parameters.
		$section = absint( $_GET['section'] ?? 0 );
		$section = in_array( $section, $sections, true ) ? $section : $sections[0];
		$current = BSA_Terms::current();
		$session = absint( $_GET['session'] ?? 0 );
		$session = isset( BSA_School::sessions()[ $session ] ) ? $session : ( $current ? $current['session'] : BSA_School::current_session() );
		$saved   = isset( $_GET['saved'] ) ? absint( $_GET['saved'] ) : null;
		$subj_ok = isset( $_GET['subjects_saved'] );
		// phpcs:enable
		$sec = BSA_School::section( $section );
		echo '<div class="wrap bsa-dash bsa-streams"><h1>' . esc_html__( 'Streams', 'bsa-school-management' ) . '</h1>';
		BSA_Dashboard::styles();
		echo '<style>.bsa-streams .bsa-chips{margin:4px 0 0;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:6px}.bsa-streams .bsa-chips li{padding:2px 10px;border:1px solid #dcdcde;border-radius:999px;background:#fff}.bsa-streams select{max-width:100%}</style>';
		if ( null !== $saved ) {
			/* translators: %d: learners. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( 'Stream changed for %d learner.', 'Streams changed for %d learners.', $saved, 'bsa-school-management' ), $saved ) ) . '</p></div>';
		}
		if ( $subj_ok ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Stream subjects saved. Learners already set keep their subjects until their stream is set again.', 'bsa-school-management' ) . '</p></div>';
		}
		echo '<p class="bsa-card-sub">' . esc_html__( 'Forms 3 and 4 are one class each (one class teacher, one register). Sciences and Commercials are the subjects a learner takes on top of the subjects for everyone. Marks sheets list only the learners taking each subject; report cards show each learner\'s own subjects; the position is within the whole class.', 'bsa-school-management' ) . '</p>';

		// Stream subjects.
		$streams = self::streams();
		echo '<div class="bsa-grid"><section class="bsa-card"><h2>' . esc_html__( 'Subjects', 'bsa-school-management' ) . '</h2>';
		$everyone = array_values(
			array_filter(
				self::form_subjects(),
				function ( $l ) {
					return '' === self::subject_stream( $l );
				}
			)
		);
		foreach ( array_merge( array( __( 'Everyone', 'bsa-school-management' ) => $everyone ), $streams ) as $name => $labels ) {
			echo '<h3>' . esc_html( $name ) . '</h3><ul class="bsa-chips">';
			foreach ( $labels as $l ) {
				echo '<li>' . esc_html( $l ) . '</li>';
			}
			echo $labels ? '' : '<li>' . esc_html__( 'none', 'bsa-school-management' ) . '</li>';
			echo '</ul>';
		}
		if ( BSA_School::is_school_admin( $user_id ) ) {
			echo '<details><summary>' . esc_html__( 'Change the stream subjects', 'bsa-school-management' ) . '</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_save_stream_subjects">';
			wp_nonce_field( 'bsa_save_stream_subjects' );
			echo '<table class="bsa-table"><tbody>';
			foreach ( self::form_subjects() as $label ) {
				$id = 'bsa-belongs-' . md5( $label );
				echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $id ) . '" name="belongs[' . esc_attr( md5( $label ) ) . ']"><option value="">' . esc_html__( 'Everyone', 'bsa-school-management' ) . '</option>';
				foreach ( array_keys( $streams ) as $name ) {
					printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $name ), selected( self::subject_stream( $label ), $name, false ) );
				}
				echo '</select></td></tr>';
			}
			echo '</tbody></table>';
			submit_button( __( 'Save the stream subjects', 'bsa-school-management' ), 'secondary', 'submit', false );
			echo '</form></details>';
		}
		echo '</section>';

		// Learners.
		$learners = BSA_School::learners( $section, $session );
		$map      = self::subject_map( wp_list_pluck( $learners, 'ID' ) );
		$counts   = array();
		foreach ( $learners as $l ) {
			$st            = self::stream_of( $section, $map[ (int) $l->ID ] );
			$counts[ $st ] = ( $counts[ $st ] ?? 0 ) + 1;
		}
		echo '<section class="bsa-card"><h2>' . esc_html( ( $sec ? $sec->title : '' ) . ', ' . ( BSA_School::sessions()[ $session ] ?? '' ) ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><label for="bsa-st-section">' . esc_html__( 'Class', 'bsa-school-management' ) . '</label> <select id="bsa-st-section" name="section">';
		foreach ( $sections as $id ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $section, $id, false ), esc_html( BSA_School::section( $id )->title ) );
		}
		echo '</select> <label for="bsa-st-session">' . esc_html__( 'Year', 'bsa-school-management' ) . '</label> <select id="bsa-st-session" name="session">';
		foreach ( BSA_School::sessions() as $id => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $session, $id, false ), esc_html( $label ) );
		}
		echo '</select> ';
		submit_button( __( 'Show', 'bsa-school-management' ), 'secondary', '', false );
		echo '</form>';
		if ( ! $learners ) {
			echo '<p>' . esc_html__( 'No learners in this class for the selected year.', 'bsa-school-management' ) . '</p></section></div></div>';
			return;
		}
		$parts = array();
		foreach ( array_merge( array_keys( $streams ), array( 'mixed', '' ) ) as $st ) {
			if ( ! empty( $counts[ $st ] ) ) {
				$parts[] = self::label( $st ) . ': ' . $counts[ $st ];
			}
		}
		echo '<p class="bsa-card-sub">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_save_streams"><input type="hidden" name="section" value="' . (int) $section . '"><input type="hidden" name="session" value="' . (int) $session . '">';
		wp_nonce_field( 'bsa_save_streams_' . $section );
		echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'No.', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Stream', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $learners as $n => $l ) {
			$st = self::stream_of( $section, $map[ (int) $l->ID ] );
			$id = 'bsa-stream-' . (int) $l->ID;
			echo '<tr><td>' . (int) ( $n + 1 ) . '</td><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $l->name ) . '</label></th><td><select id="' . esc_attr( $id ) . '" name="stream[' . (int) $l->ID . ']">';
			if ( 'mixed' === $st ) {
				echo '<option value="keep" selected="selected">' . esc_html__( 'Own subject choice (keep)', 'bsa-school-management' ) . '</option>';
			}
			foreach ( array_keys( $streams ) as $name ) {
				printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $name ), selected( $st, $name, false ) );
			}
			echo '<option value=""' . selected( $st, '', false ) . '>' . esc_html__( 'Not set (every subject)', 'bsa-school-management' ) . '</option></select></td></tr>';
		}
		echo '</tbody></table></div>';
		submit_button( __( 'Save streams', 'bsa-school-management' ) );
		echo '</form></section></div></div>';
	}
}
