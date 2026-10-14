<?php
/**
 * Shared look-ups for BSA screens: staff rights, classes and streams, learners, names.
 *
 * Reads Weblizar's tables directly (reviewed against Weblizar 10.3). Weblizar caches the
 * current user's rights per request, so rights for other users are read here instead.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * School look-ups.
 */
class BSA_School {

	const SCHOOL_ID = 1;

	/**
	 * Per-request cache of staff rows by user.
	 *
	 * @var array<int,object|null>
	 */
	private static $staff = array();

	/**
	 * The user's Weblizar staff record in the school (role, permissions, assigned section).
	 *
	 * @param int $user_id User ID.
	 * @return object|null {role, permissions[], section_id, name}
	 */
	public static function staff( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return null;
		}
		if ( ! array_key_exists( $user_id, self::$staff ) ) {
			global $wpdb;
			$p   = $wpdb->prefix;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT s.role, s.permissions, a.section_id, a.name FROM {$p}wlsm_staff s LEFT JOIN {$p}wlsm_admins a ON a.staff_id = s.ID WHERE s.user_id = %d AND s.school_id = %d LIMIT 1", $user_id, self::SCHOOL_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row ) {
				$permissions      = maybe_unserialize( $row->permissions );
				$row->permissions = is_array( $permissions ) ? $permissions : array();
				$row->section_id  = (int) $row->section_id;
			}
			self::$staff[ $user_id ] = $row;
		}
		return self::$staff[ $user_id ];
	}

	/**
	 * Clears the per-request cache (tests).
	 */
	public static function flush() {
		self::$staff = array();
	}

	/**
	 * WordPress administrator or Weblizar "admin" staff (head / school administrator).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_school_admin( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$staff = self::staff( $user_id );
		$admin = class_exists( 'WLSM_M_Role' ) ? WLSM_M_Role::get_admin_key() : 'admin';
		return $staff && $admin === $staff->role;
	}

	/**
	 * Whether the user holds a Weblizar permission (school administrators hold all).
	 *
	 * @param int    $user_id    User ID.
	 * @param string $permission Weblizar permission key.
	 * @return bool
	 */
	public static function can( $user_id, $permission ) {
		if ( self::is_school_admin( $user_id ) ) {
			return true;
		}
		$staff = self::staff( $user_id );
		return $staff && in_array( $permission, $staff->permissions, true );
	}

	/**
	 * Sections (class + stream) the user may work with for a permission: all for school
	 * administrators; for staff limited to their assigned class, only that section.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $permission Weblizar permission key.
	 * @return int[] Section IDs.
	 */
	public static function allowed_sections( $user_id, $permission ) {
		if ( ! self::can( $user_id, $permission ) ) {
			return array();
		}
		$all = array_map( 'intval', wp_list_pluck( self::sections(), 'ID' ) );
		if ( self::is_school_admin( $user_id ) ) {
			return $all;
		}
		$staff = self::staff( $user_id );
		if ( in_array( 'assigned_class', $staff->permissions, true ) ) {
			return $staff->section_id && in_array( $staff->section_id, $all, true ) ? array( $staff->section_id ) : array();
		}
		return $all;
	}

	/**
	 * All sections of the school with their class, ordered Form 1 A … Form 4 Sciences.
	 *
	 * @return object[] {ID, label, class_id, class_label, title}
	 */
	public static function sections() {
		static $rows = null;
		if ( null === $rows ) {
			global $wpdb;
			$p    = $wpdb->prefix;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT se.ID, se.label, c.ID AS class_id, c.label AS class_label FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id WHERE cs.school_id = %d ORDER BY c.ID, se.label", self::SCHOOL_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				$row->title = $row->class_label . ' ' . $row->label;
			}
		}
		return $rows;
	}

	/**
	 * One section by ID.
	 *
	 * @param int $section_id Section ID.
	 * @return object|null
	 */
	public static function section( $section_id ) {
		foreach ( self::sections() as $row ) {
			if ( (int) $row->ID === (int) $section_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Active learners in a section for a session, in register order (roll number, then
	 * surname, then first names).
	 *
	 * @param int $section_id Section ID.
	 * @param int $session_id Session ID.
	 * @return object[] Student record rows with surname/first_names added.
	 */
	public static function learners( $section_id, $session_id ) {
		global $wpdb;
		$p    = $wpdb->prefix;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT sr.ID, sr.name, sr.gender, sr.dob, sr.religion, sr.roll_number, sr.admission_number, sr.student_type, sr.father_name, sr.mother_name, sr.father_phone, sr.mother_phone, sr.address, sr.phone FROM {$p}wlsm_student_records sr WHERE sr.section_id = %d AND sr.session_id = %d AND sr.is_active = 1", $section_id, $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			list( $row->surname, $row->first_names ) = self::split_name( $row->name );
		}
		usort(
			$rows,
			function ( $a, $b ) {
				$ra = (int) $a->roll_number;
				$rb = (int) $b->roll_number;
				if ( $ra && $rb && $ra !== $rb ) {
					return $ra <=> $rb;
				}
				return array( strtolower( $a->surname ), strtolower( $a->first_names ) ) <=> array( strtolower( $b->surname ), strtolower( $b->first_names ) );
			}
		);
		return $rows;
	}

	/**
	 * Splits Weblizar's single name field: the last word is the surname.
	 *
	 * @param string $name Full name.
	 * @return string[] [surname, first names]
	 */
	public static function split_name( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		if ( count( $parts ) < 2 ) {
			return array( (string) $parts[0], '' );
		}
		$surname = array_pop( $parts );
		return array( $surname, implode( ' ', $parts ) );
	}

	/**
	 * Day scholar or boarder, from Weblizar's student type (renamed for BSA).
	 *
	 * @param string $student_type Value stored on the student record.
	 * @return string 'boarder' or 'day'
	 */
	public static function residence( $student_type ) {
		$label = (string) $student_type;
		if ( ctype_digit( $label ) ) {
			global $wpdb;
			$label = (string) $wpdb->get_var( $wpdb->prepare( "SELECT label FROM {$wpdb->prefix}wlsm_student_type WHERE ID = %d", (int) $label ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return false !== stripos( $label, 'board' ) ? 'boarder' : 'day';
	}

	/**
	 * Current Weblizar session ID.
	 *
	 * @return int
	 */
	public static function current_session() {
		return (int) get_option( 'wlsm_current_session' );
	}

	/**
	 * Sessions of the school (ID => label), oldest first.
	 *
	 * @return array<int,string>
	 */
	public static function sessions() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( "SELECT ID, label FROM {$wpdb->prefix}wlsm_sessions ORDER BY start_date, ID" ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (int) $row->ID ] = $row->label;
		}
		return $out;
	}
}
