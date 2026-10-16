<?php
/**
 * Year-end rollover (wp-admin): moves the school to the next year in one checked step.
 *
 * - For each class of the year ending, every learner gets an action: move up to the next
 *   form (default), repeat the form, or leave; Form 4 learners complete (default) or
 *   repeat. Learners moving into Form 3 choose Sciences or Commercials (or later);
 *   Form 3 learners keep their stream into Form 4.
 * - Each learner who stays gets a record in the new year as Weblizar's own promotion
 *   does it: same admission and roll number, a new enrollment number, the learner and
 *   parent logins moved to the new record, and a promotion link from the old record.
 *   Unlike Weblizar, the old record stays active so registers, marks, report cards and
 *   results of the year just ended remain visible; parents keep their past report cards
 *   through the promotion link. The admission documents record moves with the learner.
 * - No invoices are created (fees are invoiced by the bursar). Nothing is saved before
 *   the preview is confirmed; all or nothing; each rollover can be undone while nothing
 *   has been recorded for the new records.
 * - "Make <year> the current year" switches Weblizar's current session (January).
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Year-end rollover.
 */
class BSA_Rollover {

	const PAGE    = 'bsa-rollover';
	const BATCHES = 'bsa_sm_rollover_batches';

	/**
	 * Student record columns copied to the new year (as Weblizar's promotion).
	 */
	const COPY = array( 'admission_number', 'name', 'gender', 'dob', 'phone', 'email', 'address', 'city', 'state', 'country', 'religion', 'caste', 'blood_group', 'father_name', 'father_phone', 'father_occupation', 'mother_name', 'mother_phone', 'mother_occupation', 'id_number', 'id_proof', 'parent_id_proof', 'note', 'admission_date', 'roll_number', 'photo_id', 'route_vehicle_id', 'student_type', 'medium', 'activities', 'room_id', 'user_id', 'parent_user_id', 'added_by' );

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_rollover_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_bsa_rollover_undo', array( __CLASS__, 'handle_undo' ) );
		add_action( 'admin_post_bsa_rollover_switch', array( __CLASS__, 'handle_switch' ) );
	}

	/**
	 * Owner and school administrators.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can( $user_id ) {
		return BSA_School::is_school_admin( $user_id );
	}

	/**
	 * Menu entry.
	 */
	public static function register_menu() {
		if ( self::user_can( get_current_user_id() ) ) {
			add_menu_page( __( 'Year-end rollover', 'bsa-school-management' ), __( 'Year-end rollover', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-controls-forward', 35 );
		}
	}

	/**
	 * The year after a session (next by start date), or 0.
	 *
	 * @param int $session_id Session ID.
	 * @return int
	 */
	public static function next_session( $session_id ) {
		$ids = array_keys( BSA_School::sessions() );
		$pos = array_search( (int) $session_id, $ids, true );
		return false !== $pos && isset( $ids[ $pos + 1 ] ) ? (int) $ids[ $pos + 1 ] : 0;
	}

	/**
	 * The class a learner moves up to: the next form's class with the same name, or its
	 * only class. 0 after the last form (Form 4).
	 *
	 * @param int $section_id Section ID.
	 * @return int
	 */
	public static function next_section( $section_id ) {
		$sections = BSA_School::sections();
		$here     = BSA_School::section( $section_id );
		if ( ! $here ) {
			return 0;
		}
		$classes = array_values( array_unique( array_map( 'intval', wp_list_pluck( $sections, 'class_id' ) ) ) );
		sort( $classes );
		$pos  = array_search( (int) $here->class_id, $classes, true );
		$next = false !== $pos && isset( $classes[ $pos + 1 ] ) ? $classes[ $pos + 1 ] : 0;
		if ( ! $next ) {
			return 0;
		}
		$options = array_values(
			array_filter(
				$sections,
				static function ( $s ) use ( $next ) {
					return (int) $s->class_id === $next;
				}
			)
		);
		foreach ( $options as $s ) {
			if ( $s->label === $here->label ) {
				return (int) $s->ID;
			}
		}
		return 1 === count( $options ) ? (int) $options[0]->ID : 0;
	}

	/**
	 * Every learner of the year with their default action and what would happen.
	 *
	 * @param int $from Session ending.
	 * @param int $to   Next session.
	 * @return array<int,array{section:object, learners:array}> section ID => rows.
	 */
	public static function plan( $from, $to ) {
		global $wpdb;
		$p        = $wpdb->prefix;
		$already  = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT UPPER(TRIM(admission_number)) AS adm, ID FROM {$p}wlsm_student_records WHERE session_id = %d AND admission_number <> ''", $to ) ) as $r ) {
			$already[ $r->adm ] = (int) $r->ID;
		}
		$out = array();
		foreach ( BSA_School::sections() as $sec ) {
			$learners = BSA_School::learners( (int) $sec->ID, $from );
			if ( ! $learners ) {
				continue;
			}
			$next    = self::next_section( (int) $sec->ID );
			$map     = BSA_Streams::subject_map( wp_list_pluck( $learners, 'ID' ) );
			$streams = BSA_Streams::applies( (int) $sec->ID );
			$rows    = array();
			foreach ( $learners as $l ) {
				$adm    = strtoupper( trim( (string) $l->admission_number ) );
				$stream = $streams ? BSA_Streams::stream_of( (int) $sec->ID, $map[ (int) $l->ID ] ) : '';
				$rows[ (int) $l->ID ] = array(
					'learner' => $l,
					'stream'  => $stream,
					'default' => $next ? 'up' : 'complete',
					'status'  => '' === $adm ? 'no_number' : ( isset( $already[ $adm ] ) ? 'already' : 'ready' ),
				);
			}
			$out[ (int) $sec->ID ] = array( 'section' => $sec, 'next' => $next, 'learners' => $rows );
		}
		return $out;
	}

	/**
	 * Checks the chosen actions and builds the list of records to write.
	 *
	 * @param int   $from    Session ending.
	 * @param int   $to      Next session.
	 * @param array $actions Student ID => up|repeat|leave|complete.
	 * @param array $streams Student ID => stream name, '' (later) or 'keep'.
	 * @return array{moves:array, leaving:array, errors:string[]}
	 */
	public static function prepare( $from, $to, array $actions, array $streams ) {
		$moves   = array();
		$leaving = array();
		$errors  = array();
		if ( ! $to || self::next_session( $from ) !== (int) $to ) {
			return array( 'moves' => array(), 'leaving' => array(), 'errors' => array( __( 'Choose a year and the year after it.', 'bsa-school-management' ) ) );
		}
		foreach ( self::plan( $from, $to ) as $section_id => $class ) {
			foreach ( $class['learners'] as $id => $row ) {
				$action = sanitize_key( (string) ( $actions[ $id ] ?? $row['default'] ) );
				if ( ! in_array( $action, $class['next'] ? array( 'up', 'repeat', 'leave' ) : array( 'complete', 'repeat' ), true ) ) {
					$action = $row['default'];
				}
				if ( 'already' === $row['status'] ) {
					continue;
				}
				if ( 'leave' === $action || 'complete' === $action ) {
					$leaving[ $id ] = array( 'name' => $row['learner']->name, 'section' => $section_id, 'action' => $action );
					continue;
				}
				if ( 'no_number' === $row['status'] ) {
					/* translators: %s: learner. */
					$errors[] = sprintf( __( '%s has no admission number: give one in School Management first.', 'bsa-school-management' ), $row['learner']->name );
					continue;
				}
				$target = 'up' === $action ? (int) $class['next'] : (int) $section_id;
				if ( ! $target ) {
					/* translators: %s: learner. */
					$errors[] = sprintf( __( '%s: no class to move up to.', 'bsa-school-management' ), $row['learner']->name );
					continue;
				}
				$stream = '';
				if ( BSA_Streams::applies( $target ) ) {
					$stream = sanitize_text_field( (string) ( $streams[ $id ] ?? ( 'mixed' === $row['stream'] ? 'keep' : $row['stream'] ) ) );
					if ( 'keep' !== $stream && '' !== $stream && ! isset( BSA_Streams::streams()[ $stream ] ) ) {
						$stream = '';
					}
				}
				$moves[ $id ] = array( 'name' => $row['learner']->name, 'from_section' => (int) $section_id, 'to_section' => $target, 'action' => $action, 'stream' => $stream, 'current' => $row['stream'] );
			}
		}
		return array( 'moves' => $moves, 'leaving' => $leaving, 'errors' => $errors );
	}

	/**
	 * Writes the new year's records, as one batch.
	 *
	 * @param int   $from    Session ending.
	 * @param int   $to      Next session.
	 * @param array $actions As for prepare().
	 * @param array $streams As for prepare().
	 * @param int   $user_id Acting user.
	 * @return array{batch:string, moved:int, leaving:int}|WP_Error
	 */
	public static function run( $from, $to, array $actions, array $streams, $user_id ) {
		global $wpdb;
		if ( ! self::user_can( $user_id ) ) {
			return new WP_Error( 'bsa_rollover', __( 'You cannot do the year-end rollover.', 'bsa-school-management' ) );
		}
		$prep = self::prepare( $from, $to, $actions, $streams );
		if ( $prep['errors'] ) {
			return new WP_Error( 'bsa_rollover', implode( ' ', $prep['errors'] ) );
		}
		if ( ! $prep['moves'] ) {
			return new WP_Error( 'bsa_rollover', __( 'Nobody to move to the next year.', 'bsa-school-management' ) );
		}
		$p   = $wpdb->prefix;
		$now = current_time( 'mysql' );
		$enr = 1 + (int) $wpdb->get_var( "SELECT MAX(CAST(enrollment_number AS UNSIGNED)) FROM {$p}wlsm_student_records" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// All or nothing; filterable so a test can use its own (rolled-back) transaction.
		$tx = (bool) apply_filters( 'bsa_sm_rollover_transaction', true );
		if ( $tx ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		$batch = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false );
		$links = array();
		foreach ( $prep['moves'] as $old_id => $m ) {
			$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}wlsm_student_records WHERE ID = %d", $old_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$new = array_intersect_key( (array) $old, array_flip( self::COPY ) );
			$new = array_merge(
				$new,
				array(
					'enrollment_number' => sprintf( '%06d', $enr++ ),
					'section_id'        => $m['to_section'],
					'session_id'        => (int) $to,
					'is_active'         => 1,
					'created_at'        => $now,
				)
			);
			// A login belongs to one record only (unique in Weblizar): free it first, as
			// Weblizar's promotion does, then give it to the new record.
			$ok = $old && false !== $wpdb->update( "{$p}wlsm_student_records", array( 'user_id' => null, 'parent_user_id' => null, 'updated_at' => $now ), array( 'ID' => (int) $old_id ) )
				&& false !== $wpdb->insert( "{$p}wlsm_student_records", $new );
			if ( $ok ) {
				$new_id = (int) $wpdb->insert_id;
				$ok     = false !== $wpdb->insert( "{$p}wlsm_promotions", array( 'from_student_record' => (int) $old_id, 'to_student_record' => $new_id, 'note' => 'BSA rollover ' . $batch, 'created_at' => $now ) );
			}
			if ( ! $ok ) {
				if ( $tx ) {
					$wpdb->query( 'ROLLBACK' );
				}
				/* translators: %s: learner. */
				return new WP_Error( 'bsa_rollover', sprintf( __( '%s could not be moved; nothing was changed.', 'bsa-school-management' ), $m['name'] ) );
			}
			$wpdb->update( BSA_Admission_Records::table(), array( 'student_record_id' => $new_id ), array( 'student_record_id' => (int) $old_id ) );
			$from_streams = BSA_Streams::applies( $m['from_section'] );
			if ( BSA_Streams::applies( $m['to_section'] ) && 'keep' !== $m['stream'] && ! ( $from_streams && $m['stream'] === $m['current'] ) ) {
				BSA_Streams::set( $new_id, $m['to_section'], $m['stream'] );
			} elseif ( ! BSA_Streams::applies( $m['to_section'] ) || $from_streams ) {
				BSA_Streams::copy_subjects( (int) $old_id, $new_id, $m['to_section'] );
			}
			$links[ $new_id ] = (int) $old_id;
		}
		if ( $tx ) {
			$wpdb->query( 'COMMIT' );
		}
		$batches           = get_option( self::BATCHES, array() );
		$batches           = is_array( $batches ) ? $batches : array();
		$batches[ $batch ] = array( 'user' => (int) $user_id, 'time' => $now, 'from' => (int) $from, 'to' => (int) $to, 'links' => $links, 'leaving' => wp_list_pluck( $prep['leaving'], 'name' ), 'undone' => '' );
		update_option( self::BATCHES, array_slice( $batches, -20, null, true ), false );
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->info( 'Year-end rollover {batch}: {moved} learner(s) moved to session #{to}, {leaving} leaving', array( 'batch' => $batch, 'moved' => count( $links ), 'to' => (int) $to, 'leaving' => count( $prep['leaving'] ) ) );
		}
		BSA_School::flush();
		return array( 'batch' => $batch, 'moved' => count( $links ), 'leaving' => count( $prep['leaving'] ) );
	}

	/**
	 * Undoes a rollover: removes its new records that nothing has been recorded against,
	 * giving the logins and admission documents back to the old records.
	 *
	 * @param string $batch   Batch ID.
	 * @param int    $user_id Acting user.
	 * @return array{removed:int, kept:string[]}|WP_Error
	 */
	public static function undo( $batch, $user_id ) {
		global $wpdb;
		$batches = get_option( self::BATCHES, array() );
		if ( ! self::user_can( $user_id ) || ! isset( $batches[ $batch ] ) || $batches[ $batch ]['undone'] ) {
			return new WP_Error( 'bsa_rollover', __( 'This rollover cannot be undone.', 'bsa-school-management' ) );
		}
		$p       = $wpdb->prefix;
		$removed = 0;
		$kept    = array();
		foreach ( $batches[ $batch ]['links'] as $new_id => $old_id ) {
			$new_id = (int) $new_id;
			$row    = $wpdb->get_row( $wpdb->prepare( "SELECT ID, name, user_id, parent_user_id FROM {$p}wlsm_student_records WHERE ID = %d", $new_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				continue;
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$used = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wlsm_invoices WHERE student_record_id = %d LIMIT 1", $new_id ) )
				|| $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wlsm_payments WHERE student_record_id = %d LIMIT 1", $new_id ) )
				|| $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wlsm_attendance WHERE student_record_id = %d LIMIT 1", $new_id ) )
				|| $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . BSA_Marks::table() . ' WHERE student_record_id = %d LIMIT 1', $new_id ) )
				|| $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wlsm_promotions WHERE from_student_record = %d LIMIT 1", $new_id ) );
			// phpcs:enable
			if ( $used ) {
				$kept[] = $row->name;
				continue;
			}
			$wpdb->update( BSA_Admission_Records::table(), array( 'student_record_id' => (int) $old_id ), array( 'student_record_id' => $new_id ) );
			$wpdb->delete( "{$p}wlsm_promotions", array( 'to_student_record' => $new_id ) );
			$wpdb->delete( "{$p}wlsm_students_subjects", array( 'student_id' => $new_id ) );
			$wpdb->delete( "{$p}wlsm_student_records", array( 'ID' => $new_id ) );
			// The logins go back once the new record (which held them) is gone.
			$wpdb->update( "{$p}wlsm_student_records", array( 'user_id' => $row->user_id, 'parent_user_id' => $row->parent_user_id ), array( 'ID' => (int) $old_id ) );
			++$removed;
		}
		$batches[ $batch ]['undone'] = current_time( 'mysql' );
		update_option( self::BATCHES, $batches, false );
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->warning( 'Year-end rollover {batch} undone: {count} record(s) removed', array( 'batch' => $batch, 'count' => $removed ) );
		}
		BSA_School::flush();
		return array( 'removed' => $removed, 'kept' => $kept );
	}

	/**
	 * Makes a year the current one in School Management.
	 *
	 * @param int $session_id Session ID.
	 * @param int $user_id    Acting user.
	 * @return bool
	 */
	public static function make_current( $session_id, $user_id ) {
		if ( ! self::user_can( $user_id ) || ! isset( BSA_School::sessions()[ $session_id ] ) ) {
			return false;
		}
		update_option( 'wlsm_current_session', (int) $session_id );
		// Staff who picked a year in School Management's year switcher follow the new one.
		delete_metadata( 'user', 0, 'wlsm_current_session', '', true );
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->info( 'Current school year set to {year}', array( 'year' => BSA_School::sessions()[ $session_id ] ) );
		}
		return true;
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
	 * Runs the rollover (admin-post).
	 */
	public static function handle_run() {
		check_admin_referer( 'bsa_rollover_run' );
		$user_id = get_current_user_id();
		if ( ! self::user_can( $user_id ) ) {
			wp_die( esc_html__( 'You cannot do the year-end rollover.', 'bsa-school-management' ), 403 );
		}
		$from    = absint( $_POST['from'] ?? 0 );
		$to      = absint( $_POST['to'] ?? 0 );
		$actions = isset( $_POST['act'] ) && is_array( $_POST['act'] ) ? wp_unslash( $_POST['act'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in prepare().
		$streams = isset( $_POST['stream'] ) && is_array( $_POST['stream'] ) ? wp_unslash( $_POST['stream'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in prepare().
		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect( self::url( array( 'from' => $from, 'error' => rawurlencode( __( 'Tick the box to confirm the rollover.', 'bsa-school-management' ) ) ) ) );
			exit;
		}
		$result = self::run( $from, $to, $actions, $streams, $user_id );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( array( 'from' => $from, 'error' => rawurlencode( $result->get_error_message() ) ) ) );
			exit;
		}
		wp_safe_redirect( self::url( array( 'from' => $from, 'done' => $result['batch'], 'moved' => $result['moved'], 'leaving' => $result['leaving'] ) ) );
		exit;
	}

	/**
	 * Undo button (admin-post).
	 */
	public static function handle_undo() {
		$batch = sanitize_text_field( wp_unslash( $_POST['batch'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'bsa_rollover_undo_' . $batch );
		$result = self::undo( $batch, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( array( 'error' => rawurlencode( $result->get_error_message() ) ) ) );
			exit;
		}
		wp_safe_redirect( self::url( array( 'undone' => $result['removed'], 'kept' => count( $result['kept'] ) ) ) );
		exit;
	}

	/**
	 * "Make the year current" button (admin-post).
	 */
	public static function handle_switch() {
		check_admin_referer( 'bsa_rollover_switch' );
		$session = absint( $_POST['session'] ?? 0 );
		if ( ! self::make_current( $session, get_current_user_id() ) ) {
			wp_die( esc_html__( 'You cannot change the current year.', 'bsa-school-management' ), 403 );
		}
		wp_safe_redirect( self::url( array( 'switched' => $session ) ) );
		exit;
	}

	/**
	 * The Year-end rollover screen.
	 */
	public static function render_page() {
		$user_id = get_current_user_id();
		if ( ! self::user_can( $user_id ) ) {
			wp_die( esc_html__( 'You cannot do the year-end rollover.', 'bsa-school-management' ), 403 );
		}
		$sessions = BSA_School::sessions();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view parameters.
		$from  = absint( $_GET['from'] ?? 0 );
		$from  = isset( $sessions[ $from ] ) ? $from : BSA_School::current_session();
		$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
		$to    = self::next_session( $from );
		echo '<div class="wrap bsa-dash bsa-rollover"><h1>' . esc_html__( 'Year-end rollover', 'bsa-school-management' ) . '</h1>';
		BSA_Dashboard::styles();
		echo '<style>.bsa-rollover select{max-width:100%}.bsa-rollover .bsa-confirm{margin:12px 0}.bsa-rollover h3{margin:20px 0 6px}.bsa-rollover .bsa-tiles{grid-template-columns:repeat(4,minmax(0,1fr))}@media (max-width:1100px){.bsa-rollover .bsa-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:600px){.bsa-rollover .bsa-tiles{grid-template-columns:minmax(0,1fr)}.bsa-rollover .bsa-table thead{display:none}.bsa-rollover .bsa-table tr{display:block;padding:8px 0;border-bottom:1px solid #dcdcde}.bsa-rollover .bsa-table th,.bsa-rollover .bsa-table td{display:block;border:0;padding:2px 0}.bsa-rollover .bsa-table td.bsa-st:before{content:attr(data-label);display:block;font-size:12px;color:#50575e;margin-top:4px}}</style>';
		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( isset( $_GET['done'] ) ) {
			/* translators: 1: learners moved, 2: year, 3: learners leaving. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%1$d learners moved to %2$s; %3$d leaving or completing Form 4. You can undo this below.', 'bsa-school-management' ), absint( $_GET['moved'] ?? 0 ), $sessions[ $to ] ?? '', absint( $_GET['leaving'] ?? 0 ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['undone'] ) ) {
			/* translators: 1: records removed, 2: kept. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'Rollover undone: %1$d new-year records removed; %2$d kept because something has already been recorded for them.', 'bsa-school-management' ), absint( $_GET['undone'] ), absint( $_GET['kept'] ?? 0 ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['switched'] ) ) {
			/* translators: %s: year. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%s is now the current year in School Management.', 'bsa-school-management' ), $sessions[ absint( $_GET['switched'] ) ] ?? '' ) ) . '</p></div>';
		}
		// phpcs:enable
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><label for="bsa-ro-from">' . esc_html__( 'Year ending', 'bsa-school-management' ) . '</label> <select id="bsa-ro-from" name="from">';
		foreach ( $sessions as $id => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $from, $id, false ), esc_html( $label ) );
		}
		echo '</select> ';
		submit_button( __( 'Show', 'bsa-school-management' ), 'secondary', '', false );
		echo '</form>';
		if ( ! $to ) {
			echo '<p>' . esc_html__( 'There is no school year after this one. Add it in School Management → Sessions first.', 'bsa-school-management' ) . '</p>';
			self::render_history();
			echo '</div>';
			return;
		}
		$plan  = self::plan( $from, $to );
		$count = array( 'up' => 0, 'complete' => 0, 'already' => 0, 'all' => 0 );
		foreach ( $plan as $class ) {
			foreach ( $class['learners'] as $row ) {
				++$count['all'];
				if ( 'already' === $row['status'] ) {
					++$count['already'];
				} else {
					++$count[ $row['default'] ];
				}
			}
		}
		echo '<div class="bsa-tiles">';
		/* translators: %s: year. */
		echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html( sprintf( __( 'Learners in %s', 'bsa-school-management' ), $sessions[ $from ] ) ) . '</div><div class="bsa-tile-value">' . (int) $count['all'] . '</div></div>';
		/* translators: %s: year. */
		echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html( sprintf( __( 'Moving to %s', 'bsa-school-management' ), $sessions[ $to ] ) ) . '</div><div class="bsa-tile-value">' . (int) $count['up'] . '</div><div class="bsa-tile-note">' . esc_html__( 'by default; change below', 'bsa-school-management' ) . '</div></div>';
		echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'Completing Form 4', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . (int) $count['complete'] . '</div></div>';
		/* translators: %s: year. */
		echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html( sprintf( __( 'Already in %s', 'bsa-school-management' ), $sessions[ $to ] ) ) . '</div><div class="bsa-tile-value">' . (int) $count['already'] . '</div></div></div>';
		echo '<section class="bsa-card"><h2>' . esc_html( sprintf( /* translators: 1: year, 2: next year. */ __( 'From %1$s to %2$s', 'bsa-school-management' ), $sessions[ $from ], $sessions[ $to ] ) ) . '</h2>';
		echo '<p class="bsa-card-sub">' . esc_html__( 'Check each class: who moves up, who repeats and who leaves. Learners moving into Form 3 choose Sciences or Commercials (or later, on the Streams screen); Form 3 learners keep theirs. Logins and admission documents move to the new year; the year ending stays as it is, with its registers, marks and report cards. No invoices are created and no messages are sent.', 'bsa-school-management' ) . '</p>';
		if ( ! $count['all'] ) {
			echo '<p>' . esc_html__( 'No learners in this year.', 'bsa-school-management' ) . '</p></section>';
			self::render_switch( $to );
			self::render_history();
			echo '</div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_rollover_run"><input type="hidden" name="from" value="' . (int) $from . '"><input type="hidden" name="to" value="' . (int) $to . '">';
		wp_nonce_field( 'bsa_rollover_run' );
		$streams = array_keys( BSA_Streams::streams() );
		foreach ( $plan as $section_id => $class ) {
			$sec  = $class['section'];
			$next = $class['next'] ? BSA_School::section( $class['next'] ) : null;
			/* translators: 1: class, 2: next class. */
			echo '<h3>' . esc_html( $next ? sprintf( __( '%1$s → %2$s', 'bsa-school-management' ), $sec->title, $next->title ) : sprintf( /* translators: %s: class. */ __( '%s → completes', 'bsa-school-management' ), $sec->title ) ) . '</h3>';
			$stream_col = ( $next && BSA_Streams::applies( (int) $next->ID ) ) || BSA_Streams::applies( (int) $section_id );
			echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Next year', 'bsa-school-management' ) . '</th>' . ( $stream_col ? '<th scope="col">' . esc_html__( 'Stream next year', 'bsa-school-management' ) . '</th>' : '' ) . '</tr></thead><tbody>';
			foreach ( $class['learners'] as $id => $row ) {
				$l = $row['learner'];
				echo '<tr><th scope="row">' . esc_html( $l->name ) . ( $l->admission_number ? '<br><span class="bsa-card-sub">' . esc_html( $l->admission_number ) . '</span>' : '' ) . '</th><td>';
				if ( 'already' === $row['status'] ) {
					/* translators: %s: year. */
					echo esc_html( sprintf( __( 'Already in %s', 'bsa-school-management' ), $sessions[ $to ] ) ) . '</td>' . ( $stream_col ? '<td></td>' : '' ) . '</tr>';
					continue;
				}
				$aid     = 'bsa-act-' . (int) $id;
				$options = $next ? array( 'up' => sprintf( /* translators: %s: class. */ __( 'Moves up to %s', 'bsa-school-management' ), $next->title ), 'repeat' => sprintf( /* translators: %s: class. */ __( 'Repeats %s', 'bsa-school-management' ), $sec->title ), 'leave' => __( 'Leaves the school', 'bsa-school-management' ) ) : array( 'complete' => __( 'Completes Form 4 (leaves)', 'bsa-school-management' ), 'repeat' => sprintf( /* translators: %s: class. */ __( 'Repeats %s', 'bsa-school-management' ), $sec->title ) );
				/* translators: %s: learner. */
				echo '<label class="screen-reader-text" for="' . esc_attr( $aid ) . '">' . esc_html( sprintf( __( 'Next year for %s', 'bsa-school-management' ), $l->name ) ) . '</label><select id="' . esc_attr( $aid ) . '" name="act[' . (int) $id . ']">';
				foreach ( $options as $value => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $row['default'], $value, false ), esc_html( $label ) );
				}
				echo '</select>' . ( 'no_number' === $row['status'] ? '<br><span class="bsa-card-sub">' . esc_html__( 'No admission number: give one before moving up.', 'bsa-school-management' ) . '</span>' : '' ) . '</td>';
				if ( $stream_col ) {
					$sid = 'bsa-stream-' . (int) $id;
					$cur = 'mixed' === $row['stream'] ? 'keep' : $row['stream'];
					/* translators: %s: learner. */
					echo '<td class="bsa-st" data-label="' . esc_attr__( 'Stream next year', 'bsa-school-management' ) . '"><label class="screen-reader-text" for="' . esc_attr( $sid ) . '">' . esc_html( sprintf( __( 'Stream for %s', 'bsa-school-management' ), $l->name ) ) . '</label><select id="' . esc_attr( $sid ) . '" name="stream[' . (int) $id . ']">';
					if ( 'keep' === $cur ) {
						echo '<option value="keep" selected="selected">' . esc_html__( 'Own subject choice (keep)', 'bsa-school-management' ) . '</option>';
					}
					foreach ( $streams as $name ) {
						printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $name ), selected( $cur, $name, false ) );
					}
					echo '<option value=""' . selected( $cur, '', false ) . '>' . esc_html__( 'Later (every subject until set)', 'bsa-school-management' ) . '</option></select></td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}
		/* translators: %s: year. */
		echo '<p class="bsa-confirm"><label><input type="checkbox" name="confirm" value="1"> ' . esc_html( sprintf( __( 'I have checked every class. Create the %s records now.', 'bsa-school-management' ), $sessions[ $to ] ) ) . '</label></p>';
		/* translators: %s: year. */
		submit_button( sprintf( __( 'Move to %s', 'bsa-school-management' ), $sessions[ $to ] ), 'primary', 'submit', false );
		echo '</form></section>';
		self::render_switch( $to );
		self::render_history();
		echo '</div>';
	}

	/**
	 * "Make the next year current" card.
	 *
	 * @param int $to Next session.
	 */
	private static function render_switch( $to ) {
		$sessions = BSA_School::sessions();
		if ( BSA_School::current_session() === (int) $to ) {
			return;
		}
		/* translators: %s: year. */
		echo '<section class="bsa-card"><h2>' . esc_html( sprintf( __( 'Start %s', 'bsa-school-management' ), $sessions[ $to ] ) ) . '</h2><p class="bsa-card-sub">' . esc_html( sprintf( /* translators: 1: current year, 2: next year. */ __( 'School Management currently opens on %1$s. In January, after the rollover, make %2$s the current year for everyone (fees, admissions and Weblizar screens then use it). BSA screens follow the term dates.', 'bsa-school-management' ), $sessions[ BSA_School::current_session() ] ?? '', $sessions[ $to ] ) ) . '</p>';
		/* translators: %s: year. */
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( sprintf( __( 'Make %s the current year?', 'bsa-school-management' ), $sessions[ $to ] ) ) ) . ');"><input type="hidden" name="action" value="bsa_rollover_switch"><input type="hidden" name="session" value="' . (int) $to . '">';
		wp_nonce_field( 'bsa_rollover_switch' );
		/* translators: %s: year. */
		submit_button( sprintf( __( 'Make %s the current year', 'bsa-school-management' ), $sessions[ $to ] ), 'secondary', 'submit', false );
		echo '</form></section>';
	}

	/**
	 * Past rollovers with an undo button.
	 */
	private static function render_history() {
		$batches = array_reverse( (array) get_option( self::BATCHES, array() ), true );
		if ( ! $batches ) {
			return;
		}
		$sessions = BSA_School::sessions();
		echo '<section class="bsa-card"><h2>' . esc_html__( 'Past rollovers', 'bsa-school-management' ) . '</h2><div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'When', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Years', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Moved', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Leaving', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'By', 'bsa-school-management' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( $batches as $id => $b ) {
			$by   = get_userdata( (int) $b['user'] );
			$cell = $b['undone'] ? esc_html( sprintf( /* translators: %s: date. */ __( 'Undone %s', 'bsa-school-management' ), mysql2date( 'j M Y H:i', $b['undone'] ) ) ) : '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Remove the new-year records of this rollover? Records with fees, marks or registers already recorded are kept.', 'bsa-school-management' ) ) ) . ');"><input type="hidden" name="action" value="bsa_rollover_undo"><input type="hidden" name="batch" value="' . esc_attr( $id ) . '">' . wp_nonce_field( 'bsa_rollover_undo_' . $id, '_wpnonce', true, false ) . '<button type="submit" class="button">' . esc_html__( 'Undo', 'bsa-school-management' ) . '</button></form>';
			echo '<tr><td>' . esc_html( mysql2date( 'j M Y H:i', $b['time'] ) ) . '</td><td>' . esc_html( ( $sessions[ $b['from'] ] ?? '' ) . ' → ' . ( $sessions[ $b['to'] ] ?? '' ) ) . '</td><td class="num">' . (int) count( $b['links'] ) . '</td><td class="num">' . (int) count( $b['leaving'] ) . '</td><td>' . esc_html( $by ? $by->display_name : '' ) . '</td><td>' . $cell . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped parts.
		}
		echo '</tbody></table></div></section>';
	}
}
