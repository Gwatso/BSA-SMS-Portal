<?php
/**
 * WhatsApp messages to parents (owner, 2026-10-17): "tap to send".
 *
 * The portal writes each family's own message (report card ready, absent today, fee
 * balance) and lines them up; one tap opens WhatsApp on that number with the message
 * filled in, sent from the school's WhatsApp on the device in use (the office phone or
 * WhatsApp on the office computer). Nothing is sent by the server and nothing costs
 * money. Every tap is logged: who sent what, to whom, when.
 *
 * - Only parents whose consent is recorded as "yes" can be messaged (Zimbabwe's Cyber and
 *   Data Protection Act). Contacts and consent are kept per admission number, so they
 *   carry over from year to year; the number suggested comes from the learner record.
 * - Fee reminders are off until the school administrators switch them on (fee data
 *   needs their approval). Report card messages carry no marks, only a link to the portal.
 * - Who may send: school administrators everything; the bursar fee reminders; a class
 *   teacher report card and absence messages for their own class.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp messages.
 */
class BSA_WhatsApp {

	const PAGE       = 'bsa-whatsapp';
	const OPTION     = 'bsa_sm_whatsapp';
	const DB_VERSION = '1';

	/**
	 * The school's WhatsApp number, from the public website (also the office phone).
	 */
	const NUMBER = '+263 77 260 9503';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'wp_ajax_bsa_wa_sent', array( __CLASS__, 'ajax_sent' ) );
		add_action( 'admin_post_bsa_wa_contacts', array( __CLASS__, 'handle_contacts' ) );
		add_action( 'admin_post_bsa_wa_settings', array( __CLASS__, 'handle_settings' ) );
	}

	/**
	 * Contacts and consent, per admission number.
	 *
	 * @return string
	 */
	public static function contacts_table() {
		global $wpdb;
		return $wpdb->prefix . 'bsa_wa_contacts';
	}

	/**
	 * Messages opened in WhatsApp (sent).
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'bsa_wa_log';
	}

	/**
	 * Creates or updates the tables.
	 */
	public static function maybe_migrate() {
		if ( get_option( 'bsa_sm_whatsapp_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset  = $wpdb->get_charset_collate();
		$contacts = self::contacts_table();
		$log      = self::log_table();
		dbDelta(
			"CREATE TABLE {$contacts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				admission_number varchar(60) NOT NULL,
				number varchar(20) NOT NULL DEFAULT '',
				name varchar(100) NOT NULL DEFAULT '',
				consent varchar(3) NOT NULL DEFAULT '',
				consent_at datetime DEFAULT NULL,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY admission_number (admission_number)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(20) NOT NULL,
				context varchar(40) NOT NULL,
				student_record_id bigint(20) unsigned NOT NULL,
				admission_number varchar(60) NOT NULL DEFAULT '',
				number varchar(20) NOT NULL,
				message text NOT NULL,
				sent_by bigint(20) unsigned NOT NULL,
				sent_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY type_context (type, context),
				KEY student (student_record_id)
			) {$charset};"
		);
		update_option( 'bsa_sm_whatsapp_db_version', self::DB_VERSION, false );
	}

	/**
	 * Message types.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'report'  => __( 'Report card ready', 'bsa-school-management' ),
			'absence' => __( 'Absent today', 'bsa-school-management' ),
			'fees'    => __( 'Fee reminder', 'bsa-school-management' ),
		);
	}

	/**
	 * Standard wording. Placeholders: {parent} {learner} {first} {class} {term} {year}
	 * {date} {balance} {link} {school_phone}.
	 *
	 * @return array<string,string>
	 */
	public static function default_templates() {
		return array(
			'report'  => "Dear {parent},\n{first}'s report card for Term {term} {year} is ready. You can open it on the BSA portal: {link}\n\nBindura Science Academy",
			'absence' => "Dear {parent},\n{first} ({class}) was marked absent from school today, {date}. If you were not aware, please call or WhatsApp the school on {school_phone}.\n\nBindura Science Academy",
			'fees'    => "Dear {parent},\nThis is a reminder that the school fees balance for {first} ({class}) is USD {balance}. You can pay at the school's finance office or by bank transfer. If you have already paid, please send us the receipt. Thank you.\n\nBindura Science Academy",
		);
	}

	/**
	 * Settings: number (display form), fees ('1' on), templates.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$tpl   = self::default_templates();
		foreach ( (array) ( $saved['templates'] ?? array() ) as $type => $text ) {
			if ( isset( $tpl[ $type ] ) && '' !== trim( (string) $text ) ) {
				$tpl[ $type ] = (string) $text;
			}
		}
		$number = self::normalise( (string) ( $saved['number'] ?? '' ) );
		return array(
			'number'    => $number ? $number : self::normalise( self::NUMBER ),
			'fees'      => '1' === (string) ( $saved['fees'] ?? '' ),
			'templates' => $tpl,
		);
	}

	/**
	 * A phone number in WhatsApp's form (country code and number, digits only), or ''.
	 * Zimbabwe: 07XXXXXXXX, 7XXXXXXXX, +263 7X XXX XXXX → 2637XXXXXXXX. Other countries
	 * need the + or 00 and the country code.
	 *
	 * @param string $raw As typed or stored.
	 * @return string
	 */
	public static function normalise( $raw ) {
		$raw = trim( (string) $raw );
		$d   = preg_replace( '/\D+/', '', $raw );
		if ( '' === $d ) {
			return '';
		}
		if ( 0 === strpos( $raw, '+' ) ) {
			$d = ltrim( $d, '0' );
		} elseif ( 0 === strpos( $d, '00' ) ) {
			$d = substr( $d, 2 );
		} elseif ( 10 === strlen( $d ) && '0' === $d[0] ) {
			$d = '263' . substr( $d, 1 );
		} elseif ( 9 === strlen( $d ) && '7' === $d[0] ) {
			$d = '263' . $d;
		}
		if ( 0 === strpos( $d, '263' ) ) {
			return preg_match( '/^2637\d{8}$/', $d ) ? $d : '';
		}
		return preg_match( '/^[1-9]\d{9,14}$/', $d ) ? $d : '';
	}

	/**
	 * Number for people: +263 77 260 9503.
	 *
	 * @param string $digits From normalise().
	 * @return string
	 */
	public static function display( $digits ) {
		$digits = (string) $digits;
		if ( preg_match( '/^263(\d{2})(\d{3})(\d{4})$/', $digits, $m ) ) {
			return '+263 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
		}
		return '' === $digits ? '' : '+' . $digits;
	}

	// ------------------------------------------------------------------ Permissions.

	/**
	 * Sections the user may send this type of message for.
	 *
	 * @param int    $user_id User.
	 * @param string $type    Message type.
	 * @return int[]
	 */
	public static function sections_for( $user_id, $type ) {
		$all = array_map( 'intval', wp_list_pluck( BSA_School::sections(), 'ID' ) );
		if ( ! $user_id || ! isset( self::types()[ $type ] ) ) {
			return array();
		}
		if ( BSA_School::is_school_admin( $user_id ) ) {
			return $all;
		}
		$staff = BSA_School::staff( $user_id );
		if ( ! $staff ) {
			return array();
		}
		if ( 'fees' === $type ) {
			return array_intersect( array( 'manage_invoices', 'manage_fees' ), $staff->permissions ) ? $all : array();
		}
		if ( in_array( 'assigned_class', $staff->permissions, true ) && in_array( $staff->section_id, $all, true ) ) {
			return array( $staff->section_id );
		}
		return array();
	}

	/**
	 * Types the user may send.
	 *
	 * @param int $user_id User.
	 * @return string[]
	 */
	public static function user_types( $user_id ) {
		return array_values(
			array_filter(
				array_keys( self::types() ),
				function ( $type ) use ( $user_id ) {
					return (bool) self::sections_for( $user_id, $type );
				}
			)
		);
	}

	/**
	 * Sections whose contacts the user may keep: school administrators all; a class
	 * teacher their class.
	 *
	 * @param int $user_id User.
	 * @return int[]
	 */
	public static function contact_sections( $user_id ) {
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
	 * Whether the user sees the screen.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function user_can_view( $user_id ) {
		return self::user_types( $user_id ) || self::contact_sections( $user_id );
	}

	/**
	 * Menu.
	 */
	public static function register_menu() {
		if ( self::user_can_view( get_current_user_id() ) ) {
			add_menu_page( __( 'WhatsApp messages', 'bsa-school-management' ), __( 'WhatsApp', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-format-chat', 29 );
		}
	}

	// ------------------------------------------------------------------ Data.

	/**
	 * Contacts by admission number (upper case, trimmed).
	 *
	 * @param string[] $admission_numbers Admission numbers.
	 * @return array<string,object>
	 */
	public static function contacts( array $admission_numbers ) {
		global $wpdb;
		$keys = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'key' ), $admission_numbers ) ) ) );
		if ( ! $keys ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, u.display_name AS updated_by_name FROM ' . self::contacts_table() . " c LEFT JOIN {$wpdb->users} u ON u.ID = c.updated_by WHERE c.admission_number IN ($in)", $keys ) ) as $row ) {
			$out[ self::key( $row->admission_number ) ] = $row;
		}
		return $out;
	}

	/**
	 * Admission number as a key.
	 *
	 * @param string $admission_number Admission number.
	 * @return string
	 */
	public static function key( $admission_number ) {
		return strtoupper( trim( (string) $admission_number ) );
	}

	/**
	 * Who receives messages about a learner: the saved contact, else the mother's then the
	 * father's number on the learner record.
	 *
	 * @param object      $learner Learner row (BSA_School::learners()).
	 * @param object|null $contact Saved contact.
	 * @return array{number:string,name:string,consent:string,saved:bool}
	 */
	public static function recipient( $learner, $contact ) {
		if ( $contact && '' !== $contact->number ) {
			return array( 'number' => (string) $contact->number, 'name' => (string) $contact->name, 'consent' => (string) $contact->consent, 'saved' => true );
		}
		$consent = $contact ? (string) $contact->consent : '';
		foreach ( array( array( 'mother_phone', 'mother_name' ), array( 'father_phone', 'father_name' ) ) as $pair ) {
			$n = self::normalise( (string) ( $learner->{$pair[0]} ?? '' ) );
			if ( $n ) {
				return array( 'number' => $n, 'name' => trim( (string) ( $learner->{$pair[1]} ?? '' ) ), 'consent' => $consent, 'saved' => false );
			}
		}
		return array( 'number' => '', 'name' => $contact ? (string) $contact->name : '', 'consent' => $consent, 'saved' => (bool) $contact );
	}

	/**
	 * Report-card terms that have published report cards, newest first.
	 *
	 * @return array<string,string> "session-term" => "Term 2, 2026"
	 */
	public static function report_terms() {
		global $wpdb;
		$p    = $wpdb->prefix;
		$rows = $wpdb->get_results( 'SELECT DISTINCT r.session_id, r.term, s.label, s.start_date FROM ' . BSA_Marks::reports_table() . " r JOIN {$p}wlsm_sessions s ON s.ID = r.session_id WHERE r.published_at IS NOT NULL ORDER BY s.start_date DESC, r.term DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			/* translators: 1: term number, 2: year. */
			$out[ (int) $r->session_id . '-' . (int) $r->term ] = sprintf( __( 'Term %1$d, %2$s', 'bsa-school-management' ), (int) $r->term, $r->label );
		}
		return $out;
	}

	/**
	 * The school year messages are about (term in progress, else Weblizar's current year).
	 *
	 * @return int
	 */
	public static function session() {
		$t = BSA_Terms::current( current_time( 'Y-m-d' ) );
		return $t ? (int) $t['session'] : BSA_School::current_session();
	}

	/**
	 * Learners a message applies to in a section, with what the message needs.
	 *
	 * @param string $type       Message type.
	 * @param int    $section_id Section.
	 * @param string $context    report: "session-term"; absence: Y-m-d; fees: Y-m-d.
	 * @return array<int,array{learner:object,extra:array}>
	 */
	public static function candidates( $type, $section_id, $context ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( 'report' === $type ) {
			list( $session, $term ) = array_map( 'intval', explode( '-', $context . '-0' ) );
		} else {
			$session = self::session();
		}
		$learners = BSA_School::learners( $section_id, $session );
		if ( ! $learners ) {
			return array();
		}
		$ids = array_map( 'intval', wp_list_pluck( $learners, 'ID' ) );
		$in  = implode( ',', $ids );
		$hit = array();
		if ( 'report' === $type ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT student_record_id FROM ' . BSA_Marks::reports_table() . " WHERE session_id = %d AND term = %d AND published_at IS NOT NULL AND student_record_id IN ($in)", $session, $term ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
				$hit[ (int) $id ] = array( 'term' => $term, 'session' => $session );
			}
		} elseif ( 'absence' === $type ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT student_record_id FROM {$p}wlsm_attendance WHERE attendance_date = %s AND status = 'a' AND (subject_id IS NULL OR subject_id = 0) AND student_record_id IN ($in)", $context ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hit[ (int) $id ] = array( 'date' => $context );
			}
		} elseif ( 'fees' === $type ) {
			$section = BSA_School::section( $section_id );
			foreach ( BSA_Finance::balances( $section ? (int) $section->class_id : 0 ) as $b ) {
				if ( in_array( (int) $b->ID, $ids, true ) && (float) $b->balance > 0.004 ) {
					$hit[ (int) $b->ID ] = array( 'balance' => (float) $b->balance );
				}
			}
		}
		$out = array();
		foreach ( $learners as $l ) {
			if ( isset( $hit[ (int) $l->ID ] ) ) {
				$out[ (int) $l->ID ] = array( 'learner' => $l, 'extra' => $hit[ (int) $l->ID ] );
			}
		}
		return $out;
	}

	/**
	 * The message for one learner.
	 *
	 * @param string $type      Message type.
	 * @param object $learner   Learner row.
	 * @param array  $recipient From recipient().
	 * @param array  $extra     From candidates().
	 * @param string $context   Context.
	 * @return string
	 */
	public static function message( $type, $learner, array $recipient, array $extra, $context ) {
		global $wpdb;
		$s       = self::settings();
		$section = BSA_School::section( (int) $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->prefix}wlsm_student_records WHERE ID = %d", $learner->ID ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$names   = trim( (string) ( $learner->first_names ?? '' ) );
		$first   = '' !== $names ? strtok( $names, ' ' ) : (string) $learner->name;
		$year    = '';
		if ( 'report' === $type ) {
			$year = (string) $wpdb->get_var( $wpdb->prepare( "SELECT label FROM {$wpdb->prefix}wlsm_sessions WHERE ID = %d", (int) ( $extra['session'] ?? 0 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$link = 'report' === $type && class_exists( 'BSA_Family' ) ? add_query_arg( array( 'action' => BSA_Family::REPORTS, 'student_id' => (int) $learner->ID ), BSA_Portal::account_url() ) : BSA_Portal::account_url();
		$date = ! empty( $extra['date'] ) ? date_i18n( 'l j F Y', strtotime( $extra['date'] ) ) : '';
		return strtr(
			$s['templates'][ $type ],
			array(
				'{parent}'       => '' !== $recipient['name'] ? $recipient['name'] : __( 'Parent/Guardian', 'bsa-school-management' ),
				'{learner}'      => (string) $learner->name,
				'{first}'        => $first,
				'{class}'        => $section ? $section->title : '',
				'{term}'         => (string) ( $extra['term'] ?? '' ),
				'{year}'         => $year,
				'{date}'         => $date,
				'{balance}'      => number_format( (float) ( $extra['balance'] ?? 0 ), 2 ),
				'{link}'         => $link,
				'{school_phone}' => self::display( $s['number'] ),
			)
		);
	}

	/**
	 * Messages already sent for a type and context: student ID => {sent_at, by}.
	 *
	 * @param string $type    Type.
	 * @param string $context Context.
	 * @param int[]  $ids     Student record IDs.
	 * @return array<int,object>
	 */
	public static function sent( $type, $context, array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT l.student_record_id, l.sent_at, u.display_name AS by_name FROM ' . self::log_table() . " l LEFT JOIN {$wpdb->users} u ON u.ID = l.sent_by WHERE l.type = %s AND l.context = %s AND l.student_record_id IN (" . implode( ',', $ids ) . ') ORDER BY l.sent_at', $type, $context ) ) as $r ) {
			$out[ (int) $r->student_record_id ] = $r;
		}
		return $out;
	}

	/**
	 * Rows for the send list.
	 *
	 * @param string $type     Type.
	 * @param int[]  $sections Section IDs.
	 * @param string $context  Context.
	 * @return array[] {id, learner, class, recipient, status, message, sent}
	 */
	public static function rows( $type, array $sections, $context ) {
		$cands = array();
		$class = array();
		foreach ( $sections as $sec ) {
			$section = BSA_School::section( $sec );
			foreach ( self::candidates( $type, $sec, $context ) as $id => $c ) {
				$cands[ $id ] = $c;
				$class[ $id ] = $section ? $section->title : '';
			}
		}
		$contacts = self::contacts( wp_list_pluck( wp_list_pluck( $cands, 'learner' ), 'admission_number' ) );
		$sent     = self::sent( $type, $context, array_keys( $cands ) );
		$rows     = array();
		foreach ( $cands as $id => $c ) {
			$l   = $c['learner'];
			$rcp = self::recipient( $l, $contacts[ self::key( $l->admission_number ) ] ?? null );
			if ( '' === $rcp['number'] ) {
				$status = 'no_number';
			} elseif ( 'no' === $rcp['consent'] ) {
				$status = 'refused';
			} elseif ( 'yes' !== $rcp['consent'] ) {
				$status = 'consent';
			} else {
				$status = isset( $sent[ $id ] ) ? 'sent' : 'ready';
			}
			$rows[] = array(
				'id'        => $id,
				'learner'   => $l,
				'class'     => $class[ $id ],
				'recipient' => $rcp,
				'status'    => $status,
				'message'   => self::message( $type, $l, $rcp, $c['extra'], $context ),
				'sent'      => $sent[ $id ] ?? null,
				'extra'     => $c['extra'],
			);
		}
		return $rows;
	}

	/**
	 * Learners absent on a day whose parents can be messaged and have not been yet (for
	 * the School Dashboard).
	 *
	 * @param string $date Y-m-d.
	 * @return int
	 */
	public static function unsent_absences( $date ) {
		if ( get_option( 'bsa_sm_whatsapp_db_version' ) !== self::DB_VERSION ) {
			return 0;
		}
		$n = 0;
		foreach ( self::rows( 'absence', array_map( 'intval', wp_list_pluck( BSA_School::sections(), 'ID' ) ), $date ) as $r ) {
			$n += 'ready' === $r['status'] ? 1 : 0;
		}
		return $n;
	}

	/**
	 * The wa.me link that opens WhatsApp with the message.
	 *
	 * @param string $number  Digits.
	 * @param string $message Text.
	 * @return string
	 */
	public static function link( $number, $message ) {
		return 'https://wa.me/' . rawurlencode( $number ) . '?text=' . rawurlencode( $message );
	}

	/**
	 * Records a message as sent (the sender opened it in WhatsApp).
	 *
	 * @param int    $user_id    Sender.
	 * @param string $type       Type.
	 * @param string $context    Context.
	 * @param int    $student_id Student record.
	 * @return array|WP_Error {when, by}
	 */
	public static function record( $user_id, $type, $context, $student_id ) {
		global $wpdb;
		if ( 'fees' === $type && ! self::settings()['fees'] ) {
			return new WP_Error( 'bsa_wa', __( 'Fee reminders are switched off.', 'bsa-school-management' ) );
		}
		$section = (int) $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->prefix}wlsm_student_records WHERE ID = %d", $student_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $section || ! in_array( $section, self::sections_for( $user_id, $type ), true ) ) {
			return new WP_Error( 'bsa_wa', __( 'You cannot send this message.', 'bsa-school-management' ) );
		}
		$row = null;
		foreach ( self::rows( $type, array( $section ), $context ) as $r ) {
			if ( (int) $r['id'] === (int) $student_id ) {
				$row = $r;
			}
		}
		if ( ! $row || ! in_array( $row['status'], array( 'ready', 'sent' ), true ) ) {
			return new WP_Error( 'bsa_wa', __( 'This parent cannot be messaged (no number or no consent).', 'bsa-school-management' ) );
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			self::log_table(),
			array(
				'type'              => $type,
				'context'           => substr( (string) $context, 0, 40 ),
				'student_record_id' => (int) $student_id,
				'admission_number'  => (string) $row['learner']->admission_number,
				'number'            => $row['recipient']['number'],
				'message'           => $row['message'],
				'sent_by'           => (int) $user_id,
				'sent_at'           => $now,
			)
		);
		$user = get_userdata( $user_id );
		return array( 'when' => $now, 'by' => $user ? $user->display_name : '' );
	}

	/**
	 * AJAX: a message was opened in WhatsApp.
	 */
	public static function ajax_sent() {
		check_ajax_referer( 'bsa_wa_sent' );
		$type    = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
		$context = sanitize_text_field( wp_unslash( $_POST['context'] ?? '' ) );
		$student = absint( $_POST['student'] ?? 0 );
		$r       = self::record( get_current_user_id(), $type, $context, $student );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ), 403 );
		}
		/* translators: 1: time, 2: name. */
		wp_send_json_success( array( 'label' => sprintf( __( 'Sent %1$s by %2$s', 'bsa-school-management' ), mysql2date( 'j M H:i', $r['when'] ), $r['by'] ) ) );
	}

	/**
	 * Saves contacts and consent for a section.
	 *
	 * @param int   $user_id    User.
	 * @param int   $section_id Section.
	 * @param array $rows       admission number => {number, name, consent}.
	 * @param bool  $all_yes    Set consent "yes" wherever a number is given.
	 * @return array|WP_Error {saved, invalid[]}
	 */
	public static function save_contacts( $user_id, $section_id, array $rows, $all_yes = false ) {
		global $wpdb;
		if ( ! in_array( (int) $section_id, self::contact_sections( $user_id ), true ) ) {
			return new WP_Error( 'bsa_wa', __( 'You cannot keep contacts for this class.', 'bsa-school-management' ) );
		}
		$learners = array();
		foreach ( BSA_School::learners( $section_id, self::session() ) as $l ) {
			$learners[ self::key( $l->admission_number ) ] = $l;
		}
		$contacts = self::contacts( array_keys( $learners ) );
		$now      = current_time( 'mysql' );
		$saved    = 0;
		$invalid  = array();
		foreach ( $rows as $adm => $in ) {
			$adm = self::key( $adm );
			if ( '' === $adm || ! isset( $learners[ $adm ] ) || ! is_array( $in ) ) {
				continue;
			}
			$raw     = sanitize_text_field( (string) ( $in['number'] ?? '' ) );
			$number  = self::normalise( $raw );
			$name    = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 100 );
			$consent = in_array( $in['consent'] ?? '', array( 'yes', 'no' ), true ) ? $in['consent'] : '';
			if ( '' !== $raw && '' === $number ) {
				$invalid[] = $learners[ $adm ]->name;
				continue;
			}
			if ( $all_yes && $number && 'no' !== $consent ) {
				$consent = 'yes';
			}
			$old       = $contacts[ $adm ] ?? null;
			$suggested = self::recipient( $learners[ $adm ], null );
			if ( ! $old && '' === $consent && ( '' === $number || $number === $suggested['number'] ) && ( '' === $name || $name === $suggested['name'] ) ) {
				continue; // Nothing new to keep.
			}
			if ( $old && $old->number === $number && $old->name === $name && $old->consent === $consent ) {
				continue;
			}
			$data = array(
				'admission_number' => $adm,
				'number'           => $number,
				'name'             => $name,
				'consent'          => $consent,
				'consent_at'       => $old && $old->consent === $consent ? $old->consent_at : ( '' === $consent ? null : $now ),
				'updated_by'       => (int) $user_id,
				'updated_at'       => $now,
			);
			if ( $old ) {
				$wpdb->update( self::contacts_table(), $data, array( 'id' => (int) $old->id ) );
			} else {
				$wpdb->insert( self::contacts_table(), $data );
			}
			++$saved;
		}
		return array( 'saved' => $saved, 'invalid' => $invalid );
	}

	/**
	 * Form handler: contacts.
	 */
	public static function handle_contacts() {
		check_admin_referer( 'bsa_wa_contacts' );
		$section = absint( $_POST['section'] ?? 0 );
		$rows    = isset( $_POST['contact'] ) && is_array( $_POST['contact'] ) ? wp_unslash( $_POST['contact'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field in save_contacts().
		$r       = self::save_contacts( get_current_user_id(), $section, $rows, ! empty( $_POST['all_yes'] ) );
		if ( is_wp_error( $r ) ) {
			wp_die( esc_html( $r->get_error_message() ), 403 );
		}
		$args = array( 'tab' => 'contacts', 'section' => $section, 'bsa_notice' => 'contacts', 'saved' => $r['saved'] );
		if ( $r['invalid'] ) {
			$args['invalid'] = count( $r['invalid'] );
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/**
	 * Form handler: settings (school administrators).
	 */
	public static function handle_settings() {
		check_admin_referer( 'bsa_wa_settings' );
		if ( ! BSA_School::is_school_admin( get_current_user_id() ) ) {
			wp_die( esc_html__( 'Only school administrators can change WhatsApp settings.', 'bsa-school-management' ), 403 );
		}
		$number = self::normalise( sanitize_text_field( wp_unslash( $_POST['number'] ?? '' ) ) );
		if ( ! $number ) {
			wp_safe_redirect( self::url( array( 'tab' => 'settings', 'bsa_notice' => 'number' ) ) );
			exit;
		}
		$tpl = array();
		foreach ( array_keys( self::types() ) as $type ) {
			$text = sanitize_textarea_field( wp_unslash( $_POST['template'][ $type ] ?? '' ) );
			if ( '' !== trim( $text ) && trim( $text ) !== trim( self::default_templates()[ $type ] ) ) {
				$tpl[ $type ] = mb_substr( $text, 0, 1000 );
			}
		}
		update_option( self::OPTION, array( 'number' => $number, 'fees' => empty( $_POST['fees'] ) ? '0' : '1', 'templates' => $tpl ), false );
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'bsa_notice' => 'settings' ) ) );
		exit;
	}

	/**
	 * Screen address.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	// ------------------------------------------------------------------ Screen.

	/**
	 * The screen.
	 */
	public static function render_page() {
		$user_id = get_current_user_id();
		if ( ! self::user_can_view( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to send WhatsApp messages.', 'bsa-school-management' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$get    = wp_unslash( $_GET );
		$types  = self::user_types( $user_id );
		$admin  = BSA_School::is_school_admin( $user_id );
		$tabs   = array();
		if ( $types ) {
			$tabs['send'] = __( 'Send', 'bsa-school-management' );
		}
		if ( self::contact_sections( $user_id ) ) {
			$tabs['contacts'] = __( 'Contacts and consent', 'bsa-school-management' );
		}
		$tabs['log'] = __( 'Sent messages', 'bsa-school-management' );
		if ( $admin ) {
			$tabs['settings'] = __( 'Settings', 'bsa-school-management' );
		}
		$tab = sanitize_key( $get['tab'] ?? '' );
		$tab = isset( $tabs[ $tab ] ) ? $tab : array_key_first( $tabs );
		// phpcs:enable

		echo '<div class="wrap bsa-dash bsa-wa"><h1>' . esc_html__( 'WhatsApp messages', 'bsa-school-management' ) . '</h1>';
		BSA_Dashboard::styles();
		echo '<style>.bsa-wa .nav-tab-wrapper{margin:0 0 16px}.bsa-wa .bsa-filters{margin:0 0 16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}.bsa-wa .bsa-msg{white-space:pre-wrap;margin:0;color:#1d2327;font-size:13px;max-width:56ch}.bsa-wa details summary{cursor:pointer;color:#2271b1}.bsa-wa .bsa-wa-status{display:block;color:#50575e;font-size:12px;margin-top:4px}.bsa-wa tr.bsa-wa-done td,.bsa-wa tr.bsa-wa-done th{background:#F2F8F2}.bsa-wa .bsa-wa-send{white-space:nowrap}.bsa-wa .bsa-wa-send .dashicons{vertical-align:text-bottom;margin-right:4px}.bsa-wa .bsa-pill{display:inline-block;padding:1px 8px;border-radius:999px;border:1px solid #dcdcde;background:#fff;font-size:12px;white-space:nowrap}.bsa-wa .bsa-pill.warn{border-color:#dba617;background:#FCF9E8}.bsa-wa .bsa-pill.bad{border-color:#d63638;background:#FCF0F1}.bsa-wa .bsa-pill.ok{border-color:#68de7c;background:#EDFAEF}.bsa-wa input.bsa-num{width:11em}.bsa-wa input.bsa-name{width:14em}.bsa-wa textarea{width:100%;max-width:640px}.bsa-wa .bsa-tiles{grid-template-columns:repeat(4,minmax(0,1fr))}@media (max-width:1100px){.bsa-wa .bsa-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:600px){.bsa-wa .bsa-tiles{grid-template-columns:minmax(0,1fr)}.bsa-wa .bsa-msg{max-width:none}}</style>';
		self::notices();
		echo '<p class="bsa-dash-sub">' . esc_html(
			sprintf(
				/* translators: %s: WhatsApp number. */
				__( 'Messages open in WhatsApp ready to send. Send them from the school\'s WhatsApp (%s) on the office phone or WhatsApp on this computer, so replies come back to the school.', 'bsa-school-management' ),
				self::display( self::settings()['number'] )
			)
		) . '</p>';
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'WhatsApp views', 'bsa-school-management' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$active = $key === $tab;
			echo '<a class="nav-tab' . ( $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'send' === $tab ) {
			self::render_send( $user_id, $types, $get );
		} elseif ( 'contacts' === $tab ) {
			self::render_contacts( $user_id, $get );
		} elseif ( 'settings' === $tab ) {
			self::render_settings();
		} else {
			self::render_log( $user_id, $get );
		}
		echo '</div>';
	}

	/**
	 * Notices after a save.
	 */
	private static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$n = sanitize_key( wp_unslash( $_GET['bsa_notice'] ?? '' ) );
		if ( 'contacts' === $n ) {
			/* translators: %d: number of learners. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( 'Contact saved for %d learner.', 'Contacts saved for %d learners.', absint( $_GET['saved'] ?? 0 ), 'bsa-school-management' ), absint( $_GET['saved'] ?? 0 ) ) ) . '</p></div>';
			if ( ! empty( $_GET['invalid'] ) ) {
				/* translators: %d: number of learners. */
				echo '<div class="notice notice-error"><p>' . esc_html( sprintf( _n( '%d number was not saved: it is not a mobile number. Use 07… or +country code.', '%d numbers were not saved: they are not mobile numbers. Use 07… or +country code.', absint( $_GET['invalid'] ), 'bsa-school-management' ), absint( $_GET['invalid'] ) ) ) . '</p></div>';
			}
		} elseif ( 'settings' === $n ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'WhatsApp settings saved.', 'bsa-school-management' ) . '</p></div>';
		} elseif ( 'number' === $n ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Settings not saved: the school WhatsApp number is not a mobile number.', 'bsa-school-management' ) . '</p></div>';
		}
		// phpcs:enable
	}

	/**
	 * Class filter for the sections a user may use.
	 *
	 * @param int[] $allowed  Section IDs.
	 * @param int   $selected Selected (0 = all).
	 * @param bool  $all      Offer "All classes".
	 */
	private static function section_select( array $allowed, $selected, $all = true ) {
		echo '<label class="screen-reader-text" for="bsa-wa-section">' . esc_html__( 'Class', 'bsa-school-management' ) . '</label><select id="bsa-wa-section" name="section">';
		if ( $all && count( $allowed ) > 1 ) {
			echo '<option value="0">' . esc_html__( 'All classes', 'bsa-school-management' ) . '</option>';
		}
		foreach ( BSA_School::sections() as $s ) {
			if ( in_array( (int) $s->ID, $allowed, true ) ) {
				echo '<option value="' . (int) $s->ID . '"' . selected( (int) $s->ID, (int) $selected, false ) . '>' . esc_html( $s->title ) . '</option>';
			}
		}
		echo '</select>';
	}

	/**
	 * Send tab.
	 *
	 * @param int      $user_id User.
	 * @param string[] $types   Types the user may send.
	 * @param array    $get     Query.
	 */
	private static function render_send( $user_id, array $types, array $get ) {
		$all_types = self::types();
		$type      = sanitize_key( $get['type'] ?? '' );
		$type      = in_array( $type, $types, true ) ? $type : $types[0];
		$allowed   = self::sections_for( $user_id, $type );
		$section   = absint( $get['section'] ?? 0 );
		$sections  = $section && in_array( $section, $allowed, true ) ? array( $section ) : $allowed;
		$settings  = self::settings();
		$today     = current_time( 'Y-m-d' );
		$terms     = self::report_terms();
		if ( 'report' === $type ) {
			$context = sanitize_text_field( $get['term'] ?? '' );
			$context = isset( $terms[ $context ] ) ? $context : (string) array_key_first( $terms );
		} elseif ( 'absence' === $type ) {
			$context = sanitize_text_field( $get['date'] ?? '' );
			$context = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $context ) && $context <= $today ? $context : $today;
		} else {
			$context = $today;
		}

		echo '<form method="get" class="bsa-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="send">';
		echo '<label class="screen-reader-text" for="bsa-wa-type">' . esc_html__( 'Message', 'bsa-school-management' ) . '</label><select id="bsa-wa-type" name="type" onchange="this.form.submit()">';
		foreach ( $types as $t ) {
			echo '<option value="' . esc_attr( $t ) . '"' . selected( $t, $type, false ) . '>' . esc_html( $all_types[ $t ] ) . '</option>';
		}
		echo '</select>';
		self::section_select( $allowed, $section );
		if ( 'report' === $type && $terms ) {
			echo '<label class="screen-reader-text" for="bsa-wa-term">' . esc_html__( 'Term', 'bsa-school-management' ) . '</label><select id="bsa-wa-term" name="term">';
			foreach ( $terms as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $context, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'absence' === $type ) {
			echo '<label for="bsa-wa-date">' . esc_html__( 'Day', 'bsa-school-management' ) . '</label> <input type="date" id="bsa-wa-date" name="date" value="' . esc_attr( $context ) . '" max="' . esc_attr( $today ) . '">';
		}
		echo '<button class="button">' . esc_html__( 'Show', 'bsa-school-management' ) . '</button></form>';

		if ( 'report' === $type && ! $terms ) {
			echo '<div class="bsa-card"><p>' . esc_html__( 'No report cards have been published yet. Publish them under Report Cards, then tell the parents here.', 'bsa-school-management' ) . '</p></div>';
			return;
		}
		$preview = 'fees' === $type && ! $settings['fees'];
		if ( $preview ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Fee reminders are switched off until the school administrators approve fee messages. The list below is a preview only; a school administrator can switch them on under Settings.', 'bsa-school-management' ) . '</p></div>';
		}
		$rows   = self::rows( $type, $sections, $context );
		$counts = array_count_values( wp_list_pluck( $rows, 'status' ) );
		$tiles  = array(
			array( __( 'Ready to send', 'bsa-school-management' ), $counts['ready'] ?? 0 ),
			array( __( 'Already sent', 'bsa-school-management' ), $counts['sent'] ?? 0 ),
			array( __( 'Consent not recorded', 'bsa-school-management' ), ( $counts['consent'] ?? 0 ) + ( $counts['refused'] ?? 0 ) ),
			array( __( 'No WhatsApp number', 'bsa-school-management' ), $counts['no_number'] ?? 0 ),
		);
		echo '<div class="bsa-tiles">';
		foreach ( $tiles as $t ) {
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html( $t[0] ) . '</div><div class="bsa-tile-value">' . (int) $t[1] . '</div></div>';
		}
		echo '</div>';

		$what = array(
			'report'  => __( 'Learners whose report card for this term is published.', 'bsa-school-management' ),
			'absence' => __( 'Learners marked absent in the daily register on this day.', 'bsa-school-management' ),
			'fees'    => __( 'Learners with a fees balance this year.', 'bsa-school-management' ),
		);
		echo '<section class="bsa-card"><h2>' . esc_html( $all_types[ $type ] ) . '</h2><p class="bsa-card-sub">' . esc_html( $what[ $type ] ) . ' ' . esc_html__( 'Tap "Open in WhatsApp", press send in WhatsApp, then come back for the next family. Each tap is recorded.', 'bsa-school-management' ) . '</p>';
		if ( ! $rows ) {
			$empty = array(
				'report'  => __( 'No published report cards for this term in these classes.', 'bsa-school-management' ),
				'absence' => __( 'Nobody was marked absent on this day.', 'bsa-school-management' ),
				'fees'    => __( 'Nobody owes fees in these classes.', 'bsa-school-management' ),
			);
			echo '<p>' . esc_html( $empty[ $type ] ) . '</p></section>';
			return;
		}
		$contact_secs = self::contact_sections( $user_id );
		$order        = array( 'ready' => 0, 'sent' => 1, 'consent' => 2, 'refused' => 3, 'no_number' => 4 );
		usort(
			$rows,
			function ( $a, $b ) use ( $order ) {
				return array( $order[ $a['status'] ], $a['class'] ) <=> array( $order[ $b['status'] ], $b['class'] );
			}
		);
		echo '<div class="bsa-scroll"><table class="bsa-table widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Parent', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Message', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Send', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$rcp  = $r['recipient'];
			$done = 'sent' === $r['status'];
			echo '<tr' . ( $done ? ' class="bsa-wa-done"' : '' ) . '><th scope="row">' . esc_html( $r['learner']->name ) . '<br><span class="bsa-note">' . esc_html( $r['class'] . ( 'fees' === $type ? ' · USD ' . number_format( (float) $r['extra']['balance'], 2 ) : '' ) ) . '</span></th>';
			echo '<td>' . esc_html( '' !== $rcp['name'] ? $rcp['name'] : '—' ) . '<br><span class="bsa-note">' . esc_html( '' !== $rcp['number'] ? self::display( $rcp['number'] ) : __( 'No number', 'bsa-school-management' ) ) . '</span></td>';
			echo '<td><details><summary>' . esc_html__( 'Show message', 'bsa-school-management' ) . '</summary><p class="bsa-msg">' . esc_html( $r['message'] ) . '</p></details></td><td>';
			if ( in_array( $r['status'], array( 'ready', 'sent' ), true ) && ! $preview ) {
				echo '<a class="button' . ( $done ? '' : ' button-primary' ) . ' bsa-wa-send" target="_blank" rel="noopener" href="' . esc_url( self::link( $rcp['number'], $r['message'] ) ) . '" data-type="' . esc_attr( $type ) . '" data-context="' . esc_attr( $context ) . '" data-student="' . (int) $r['id'] . '"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span>' . esc_html( $done ? __( 'Send again', 'bsa-school-management' ) : __( 'Open in WhatsApp', 'bsa-school-management' ) ) . '</a>';
				/* translators: 1: time, 2: name. */
				echo '<span class="bsa-wa-status">' . esc_html( $done ? sprintf( __( 'Sent %1$s by %2$s', 'bsa-school-management' ), mysql2date( 'j M H:i', $r['sent']->sent_at ), (string) $r['sent']->by_name ) : '' ) . '</span>';
			} elseif ( $preview ) {
				echo '<span class="bsa-pill">' . esc_html__( 'Preview only', 'bsa-school-management' ) . '</span>';
			} else {
				$label = array(
					'consent'   => array( 'warn', __( 'Consent not recorded', 'bsa-school-management' ) ),
					'refused'   => array( 'bad', __( 'Parent said no', 'bsa-school-management' ) ),
					'no_number' => array( 'bad', __( 'No WhatsApp number', 'bsa-school-management' ) ),
				)[ $r['status'] ];
				echo '<span class="bsa-pill ' . esc_attr( $label[0] ) . '">' . esc_html( $label[1] ) . '</span>';
				$sec = self::section_of( $r['id'] );
				if ( in_array( $sec, $contact_secs, true ) ) {
					echo '<span class="bsa-wa-status"><a href="' . esc_url( self::url( array( 'tab' => 'contacts', 'section' => $sec ) ) ) . '">' . esc_html__( 'Update contact', 'bsa-school-management' ) . '</a></span>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';
		$again = __( 'Send again', 'bsa-school-management' );
		echo '<script>(function(){var ajax=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',nonce=' . wp_json_encode( wp_create_nonce( 'bsa_wa_sent' ) ) . ',again=' . wp_json_encode( $again ) . ';document.addEventListener("click",function(e){var a=e.target.closest?e.target.closest("a.bsa-wa-send"):null;if(!a){return;}var f=new FormData();f.append("action","bsa_wa_sent");f.append("_ajax_nonce",nonce);f.append("type",a.getAttribute("data-type"));f.append("context",a.getAttribute("data-context"));f.append("student",a.getAttribute("data-student"));fetch(ajax,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){if(!j||!j.success){return;}var row=a.closest("tr");row.className+=" bsa-wa-done";var s=row.querySelector(".bsa-wa-status");if(s){s.textContent=j.data.label;}a.classList.remove("button-primary");a.lastChild.textContent=again;var next=row.parentNode.querySelector("tr:not(.bsa-wa-done) a.bsa-wa-send");if(next){next.focus();}});});})();</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded values.
	}

	/**
	 * Section of a student record.
	 *
	 * @param int $student_id Student record.
	 * @return int
	 */
	private static function section_of( $student_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT section_id FROM {$wpdb->prefix}wlsm_student_records WHERE ID = %d", $student_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Contacts tab.
	 *
	 * @param int   $user_id User.
	 * @param array $get     Query.
	 */
	private static function render_contacts( $user_id, array $get ) {
		$allowed = self::contact_sections( $user_id );
		$section = absint( $get['section'] ?? 0 );
		$section = in_array( $section, $allowed, true ) ? $section : $allowed[0];
		echo '<form method="get" class="bsa-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="contacts">';
		self::section_select( $allowed, $section, false );
		echo '<button class="button">' . esc_html__( 'Show', 'bsa-school-management' ) . '</button></form>';
		$learners = BSA_School::learners( $section, self::session() );
		echo '<section class="bsa-card"><h2>' . esc_html__( 'Contacts and consent', 'bsa-school-management' ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'One WhatsApp number per learner\'s family. The number on the learner record is suggested; change it if the parent uses another one for WhatsApp. Messages go only to parents whose consent is "Yes" (for example on the signed consent slip, or a "yes" reply on WhatsApp). Set "No" if a parent asks to stop.', 'bsa-school-management' ) . '</p>';
		if ( ! $learners ) {
			echo '<p>' . esc_html__( 'No learners in this class for the current year.', 'bsa-school-management' ) . '</p></section>';
			return;
		}
		$contacts = self::contacts( wp_list_pluck( $learners, 'admission_number' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_wa_contacts"><input type="hidden" name="section" value="' . (int) $section . '">';
		wp_nonce_field( 'bsa_wa_contacts' );
		echo '<div class="bsa-scroll"><table class="bsa-table widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'On the learner record', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'WhatsApp number', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Parent\'s name', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Consent', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		$no_adm = 0;
		foreach ( $learners as $l ) {
			$adm = self::key( $l->admission_number );
			if ( '' === $adm ) {
				++$no_adm;
				continue;
			}
			$c    = $contacts[ $adm ] ?? null;
			$rcp  = self::recipient( $l, $c );
			$f    = 'contact[' . $adm . ']';
			$id   = 'bsa-wa-' . sanitize_html_class( $adm );
			$rec  = array();
			foreach ( array( array( 'mother_name', 'mother_phone' ), array( 'father_name', 'father_phone' ) ) as $pair ) {
				if ( '' !== trim( (string) $l->{$pair[1]} ) ) {
					$rec[] = trim( $l->{$pair[0]} . ' ' . $l->{$pair[1]} );
				}
			}
			echo '<tr><th scope="row">' . esc_html( $l->name ) . '<br><span class="bsa-note">' . esc_html( $adm ) . '</span></th><td>' . ( $rec ? implode( '<br>', array_map( 'esc_html', $rec ) ) : '—' ) . '</td>';
			echo '<td><label class="screen-reader-text" for="' . esc_attr( $id ) . '-n">' . esc_html__( 'WhatsApp number', 'bsa-school-management' ) . '</label><input class="bsa-num" type="tel" id="' . esc_attr( $id ) . '-n" name="' . esc_attr( $f ) . '[number]" value="' . esc_attr( self::display( $rcp['number'] ) ) . '" placeholder="07…"></td>';
			echo '<td><label class="screen-reader-text" for="' . esc_attr( $id ) . '-m">' . esc_html__( 'Parent\'s name', 'bsa-school-management' ) . '</label><input class="bsa-name" type="text" id="' . esc_attr( $id ) . '-m" name="' . esc_attr( $f ) . '[name]" value="' . esc_attr( $rcp['name'] ) . '"></td>';
			echo '<td><label class="screen-reader-text" for="' . esc_attr( $id ) . '-c">' . esc_html__( 'Consent', 'bsa-school-management' ) . '</label><select id="' . esc_attr( $id ) . '-c" name="' . esc_attr( $f ) . '[consent]"><option value="">' . esc_html__( 'Not asked', 'bsa-school-management' ) . '</option><option value="yes"' . selected( $rcp['consent'], 'yes', false ) . '>' . esc_html__( 'Yes', 'bsa-school-management' ) . '</option><option value="no"' . selected( $rcp['consent'], 'no', false ) . '>' . esc_html__( 'No', 'bsa-school-management' ) . '</option></select>';
			if ( $c && $c->consent_at ) {
				/* translators: 1: date, 2: name. */
				echo '<span class="bsa-wa-status">' . esc_html( sprintf( __( 'Recorded %1$s by %2$s', 'bsa-school-management' ), mysql2date( 'j M Y', $c->consent_at ), (string) $c->updated_by_name ) ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( $no_adm ) {
			/* translators: %d: learners. */
			echo '<p class="bsa-note">' . esc_html( sprintf( _n( '%d learner has no admission number and is not listed.', '%d learners have no admission number and are not listed.', $no_adm, 'bsa-school-management' ), $no_adm ) ) . '</p>';
		}
		echo '<p><label><input type="checkbox" name="all_yes" value="1"> ' . esc_html__( 'Consent slips are back for this class: set "Yes" wherever a number is given (except where it says "No").', 'bsa-school-management' ) . '</label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save contacts', 'bsa-school-management' ) . '</button></p></form></section>';
	}

	/**
	 * Sent messages tab: everything for school administrators, own messages for others.
	 *
	 * @param int   $user_id User.
	 * @param array $get     Query.
	 */
	private static function render_log( $user_id, array $get ) {
		global $wpdb;
		$p     = $wpdb->prefix;
		$types = self::types();
		$type  = sanitize_key( $get['type'] ?? '' );
		$where = '1=1';
		if ( isset( $types[ $type ] ) ) {
			$where .= $wpdb->prepare( ' AND l.type = %s', $type );
		}
		if ( ! BSA_School::is_school_admin( $user_id ) ) {
			$where .= $wpdb->prepare( ' AND l.sent_by = %d', $user_id );
		}
		echo '<form method="get" class="bsa-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="log"><label class="screen-reader-text" for="bsa-wa-ltype">' . esc_html__( 'Message', 'bsa-school-management' ) . '</label><select id="bsa-wa-ltype" name="type"><option value="">' . esc_html__( 'All messages', 'bsa-school-management' ) . '</option>';
		foreach ( $types as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $k, $type, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><button class="button">' . esc_html__( 'Show', 'bsa-school-management' ) . '</button></form>';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT l.*, u.display_name AS by_name, sr.name AS learner FROM ' . self::log_table() . " l LEFT JOIN {$wpdb->users} u ON u.ID = l.sent_by LEFT JOIN {$p}wlsm_student_records sr ON sr.ID = l.student_record_id WHERE {$where} ORDER BY l.sent_at DESC, l.id DESC LIMIT 300" );
		echo '<section class="bsa-card"><h2>' . esc_html__( 'Sent messages', 'bsa-school-management' ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'Recorded when a message was opened in WhatsApp for sending. The latest 300.', 'bsa-school-management' ) . '</p>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No messages sent yet.', 'bsa-school-management' ) . '</p></section>';
			return;
		}
		echo '<div class="bsa-scroll"><table class="bsa-table widefat striped"><thead><tr><th scope="col">' . esc_html__( 'When', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Message', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'To', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Sent by', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( mysql2date( 'j M Y H:i', $r->sent_at ) ) . '</td><td>' . esc_html( $types[ $r->type ] ?? $r->type ) . '<details><summary>' . esc_html__( 'Text', 'bsa-school-management' ) . '</summary><p class="bsa-msg">' . esc_html( $r->message ) . '</p></details></td><td>' . esc_html( (string) $r->learner ) . '</td><td>' . esc_html( self::display( $r->number ) ) . '</td><td>' . esc_html( (string) $r->by_name ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * Settings tab (school administrators).
	 */
	private static function render_settings() {
		$s = self::settings();
		echo '<section class="bsa-card"><h2>' . esc_html__( 'Settings', 'bsa-school-management' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_wa_settings">';
		wp_nonce_field( 'bsa_wa_settings' );
		echo '<p><label for="bsa-wa-number"><strong>' . esc_html__( 'School WhatsApp number', 'bsa-school-management' ) . '</strong></label><br><input type="tel" id="bsa-wa-number" name="number" value="' . esc_attr( self::display( $s['number'] ) ) . '" class="regular-text"><br><span class="bsa-note">' . esc_html__( 'Written in the messages for parents to call or reply to. Send from the WhatsApp on this number.', 'bsa-school-management' ) . '</span></p>';
		echo '<p><label><input type="checkbox" name="fees" value="1"' . checked( $s['fees'], true, false ) . '> <strong>' . esc_html__( 'Fee reminders on', 'bsa-school-management' ) . '</strong></label><br><span class="bsa-note">' . esc_html__( 'Leave off until the school administrators approve sending fee balances to parents.', 'bsa-school-management' ) . '</span></p>';
		echo '<h3>' . esc_html__( 'Wording', 'bsa-school-management' ) . '</h3><p class="bsa-note">' . esc_html__( 'Filled in for each family: {parent} {learner} {first} {class} {term} {year} {date} {balance} {link} {school_phone}. Empty a box to go back to the standard wording.', 'bsa-school-management' ) . '</p>';
		foreach ( self::types() as $type => $label ) {
			echo '<p><label for="bsa-wa-t-' . esc_attr( $type ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><textarea id="bsa-wa-t-' . esc_attr( $type ) . '" name="template[' . esc_attr( $type ) . ']" rows="6">' . esc_textarea( $s['templates'][ $type ] ) . '</textarea></p>';
		}
		echo '<p><button class="button button-primary">' . esc_html__( 'Save settings', 'bsa-school-management' ) . '</button></p></form></section>';
	}
}
