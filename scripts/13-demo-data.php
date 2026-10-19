<?php
/**
 * Step 13: demo data for presenting the system to the directors (owner, 2026-10-16),
 * before real data is entered. Mock learners with Zimbabwean names (about 30 per class),
 * their parents, demo teachers and a demo bursar, Grade 7 slips, streams, marks for
 * 2025 and 2026, report cards, the daily register, fees and payments, and intranet
 * posts, so that every screen has something to show (documents: the school's real
 * OneDrive folder, not demo links). School news from the public
 * website (bindurascienceacademy.co.zw) is added to the intranet as well.
 *
 * Nothing real is changed: the real learner, real staff and their logins are only
 * referred to (as the class teacher of Form 1 A, as the head who signs report cards).
 * Everything created is listed in the option `bsa_sm_demo`, and 'remove' deletes
 * exactly that list. No email is sent while this runs.
 *
 * Set before running (Novamira `execute-php`, everything after the opening tag):
 *   $bsa_mode  = 'add' | 'status' | 'extend' | 'remove' | 'redo' (phase 'academics' only:
 *                marks, report cards and Grade 7 grades again)
 *   $bsa_phase = 'people' | 'academics' | 'attendance' | 'fees' | 'intranet' | 'whatsapp' (mode 'add';
 *                run them in this order, one call each)
 *   $bsa_keep_news = true (mode 'remove': keep the public-website news; default true)
 *
 * - 'extend' adds register days for the demo learners from the last day recorded up to
 *   today (run it on the morning of the presentation).
 * - Term dates the demo needs (2025, 2026 Terms 1–2, 2027 Term 1) are added only where
 *   none are set, and 'remove' takes out only those it added and that are unchanged.
 * - Demo phone numbers start with 070 (not a Zimbabwean mobile prefix); emails are at
 *   demo.bindurascienceacademy.co.zw.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p             = $wpdb->prefix;
$bsa_mode      = isset( $bsa_mode ) ? $bsa_mode : 'status';
$bsa_phase     = isset( $bsa_phase ) ? $bsa_phase : '';
$bsa_keep_news = isset( $bsa_keep_news ) ? (bool) $bsa_keep_news : true;
$opt           = 'bsa_sm_demo';
$reg           = get_option( $opt, array() );
$reg           = is_array( $reg ) ? $reg : array();
$log           = array();
$today         = current_time( 'Y-m-d' );
$now           = current_time( 'mysql' );
$domain        = 'demo.bindurascienceacademy.co.zw';

add_filter( 'pre_wp_mail', '__return_false', 999 );
if ( function_exists( 'set_time_limit' ) ) {
	@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
if ( class_exists( 'BSA_Finance' ) ) {
	$prio = has_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );
	if ( false !== $prio ) {
		remove_filter( 'query', array( 'BSA_Finance', 'watch_query' ), $prio );
	}
}

$save = function () use ( &$reg, $opt ) {
	update_option( $opt, $reg, false );
};
$ids_sql = function ( array $ids ) {
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	return $ids ? implode( ',', $ids ) : '0';
};
// Multi-row INSERT; values are strings, ints or null. Returns the first insert id.
$bulk = function ( $table, array $cols, array $rows ) use ( $wpdb ) {
	$first = 0;
	foreach ( array_chunk( $rows, 400 ) as $chunk ) {
		$vals = array();
		foreach ( $chunk as $r ) {
			$cells = array();
			foreach ( $r as $v ) {
				$cells[] = null === $v ? 'NULL' : ( is_int( $v ) ? (string) $v : "'" . esc_sql( (string) $v ) . "'" );
			}
			$vals[] = '(' . implode( ',', $cells ) . ')';
		}
		$wpdb->query( "INSERT INTO {$table} (" . implode( ',', $cols ) . ') VALUES ' . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $first ) {
			$first = (int) $wpdb->insert_id;
		}
	}
	return $first;
};
// Deterministic random numbers (same data on every run).
$rand = function ( $min, $max ) {
	return mt_rand( $min, $max );
};
$chance = function ( $p ) {
	return mt_rand() / mt_getrandmax() < $p;
};
$normal = function ( $mean, $sd ) {
	$u = max( 1e-9, mt_rand() / mt_getrandmax() );
	$v = mt_rand() / mt_getrandmax();
	return $mean + $sd * sqrt( -2 * log( $u ) ) * cos( 2 * M_PI * $v );
};
$pick = function ( array $a ) {
	return $a[ mt_rand( 0, count( $a ) - 1 ) ];
};
$clamp = function ( $x, $lo, $hi ) {
	return max( $lo, min( $hi, $x ) );
};
$weekdays = function ( $from, $to ) {
	$out = array();
	for ( $d = strtotime( $from ); $d <= strtotime( $to ); $d += DAY_IN_SECONDS ) {
		if ( (int) gmdate( 'N', $d ) <= 5 ) {
			$out[] = gmdate( 'Y-m-d', $d );
		}
	}
	return $out;
};

// School structure (live ids are looked up, never assumed).
$sessions = array();
foreach ( $wpdb->get_results( "SELECT ID, label FROM {$p}wlsm_sessions" ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sessions[ $s->label ] = (int) $s->ID;
}
$sections = array();
$class_of = array();
foreach ( $wpdb->get_results( "SELECT se.ID, c.label AS form, c.ID AS class_id, cs.ID AS cs FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id WHERE cs.school_id = 1 AND se.label = 'A'" ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sections[ $s->form ] = (int) $s->ID;
	$class_of[ (int) $s->ID ] = (int) $s->class_id;
}
$subjects = array();
foreach ( $sections as $form => $sec ) {
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT s.ID, s.label FROM {$p}wlsm_subjects s JOIN {$p}wlsm_sections se ON se.class_school_id = s.class_school_id WHERE se.ID = %d", $sec ) ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$subjects[ $sec ][ $s->label ] = (int) $s->ID;
	}
}
$head    = get_user_by( 'login', 'Masango' );
$head_id = $head ? (int) $head->ID : 1;
$f1_ct   = get_user_by( 'login', 'hlabati.w' );

// ---------------------------------------------------------------------------------
if ( 'status' === $bsa_mode ) {
	$rec = $ids_sql( $reg['records'] ?? array() );
	return array(
		'created'     => $reg['created'] ?? '(no demo data)',
		'phases'      => $reg['phases'] ?? array(),
		'learners'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE ID IN ($rec) AND session_id = " . (int) ( $sessions['2026'] ?? 0 ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'records'     => count( $reg['records'] ?? array() ),
		'users'       => count( $reg['users'] ?? array() ),
		'marks'       => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_Marks::table() . " WHERE student_record_id IN ($rec)" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		'reports'     => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_Marks::reports_table() . " WHERE student_record_id IN ($rec)" ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		'attendance'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_attendance WHERE student_record_id IN ($rec)" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'invoices'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_invoices WHERE student_record_id IN ($rec)" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'payments'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_payments WHERE student_record_id IN ($rec)" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		'posts'       => count( $reg['posts'] ?? array() ),
		'news_posts'  => count( $reg['news'] ?? array() ),
		'logins'      => $reg['logins'] ?? array(),
		'last_marked' => $reg['last_marked'] ?? '',
	);
}

// ---------------------------------------------------------------------------------
if ( 'remove' === $bsa_mode ) {
	if ( ! $reg ) {
		return array( 'No demo data recorded; nothing removed.' );
	}
	$adm = $reg['adm'] ?? array();
	$rec = $reg['records'] ?? array();
	if ( $adm ) {
		// Also records made from demo learners later (for example by a year-end rollover).
		$in  = implode( ',', array_map( function ( $a ) use ( $wpdb ) { return $wpdb->prepare( '%s', $a ); }, $adm ) );
		$rec = array_unique( array_merge( $rec, array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$p}wlsm_student_records WHERE admission_number IN ($in)" ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$r = $ids_sql( $rec );
	$n = array();
	if ( class_exists( 'BSA_WhatsApp' ) && get_option( 'bsa_sm_whatsapp_db_version' ) ) {
		$n['whatsapp'] = (int) $wpdb->query( 'DELETE FROM ' . BSA_WhatsApp::log_table() . " WHERE student_record_id IN ($r)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $adm ) {
			$n['whatsapp'] += (int) $wpdb->query( 'DELETE FROM ' . BSA_WhatsApp::contacts_table() . " WHERE admission_number IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
	$n['marks']    = (int) $wpdb->query( 'DELETE FROM ' . BSA_Marks::table() . " WHERE student_record_id IN ($r)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$n['reports']  = (int) $wpdb->query( 'DELETE FROM ' . BSA_Marks::reports_table() . " WHERE student_record_id IN ($r)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$n['grade7']   = (int) $wpdb->query( 'DELETE FROM ' . BSA_Admission_Records::table() . " WHERE student_record_id IN ($r)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$n['subjects'] = (int) $wpdb->query( "DELETE FROM {$p}wlsm_students_subjects WHERE student_id IN ($r)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// Invoices, payments, attendance and promotions go with the records (foreign keys).
	$n['records']  = (int) $wpdb->query( "DELETE FROM {$p}wlsm_student_records WHERE ID IN ($r)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$n['checks']   = (int) $wpdb->query( 'DELETE FROM ' . BSA_Register::checks_table() . ' WHERE id IN (' . $ids_sql( $reg['checks'] ?? array() ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$n['notices']  = (int) $wpdb->query( "DELETE FROM {$p}wlsm_notices WHERE ID IN (" . $ids_sql( $reg['notices'] ?? array() ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	// Demo staff: the admin rows and subject links go with the staff rows.
	$n['staff']    = (int) $wpdb->query( "DELETE FROM {$p}wlsm_staff WHERE ID IN (" . $ids_sql( $reg['staff'] ?? array() ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$posts = $reg['posts'] ?? array();
	if ( ! $bsa_keep_news ) {
		$posts = array_merge( $posts, $reg['news'] ?? array() );
	}
	$n['posts'] = 0;
	foreach ( $posts as $id ) {
		$n['posts'] += wp_delete_post( (int) $id, true ) ? 1 : 0;
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$n['users'] = 0;
	foreach ( $reg['users'] ?? array() as $id ) {
		// Demo users own no posts; nothing is reassigned or lost.
		$n['users'] += wp_delete_user( (int) $id ) ? 1 : 0;
	}
	// Term dates: only those the demo added and that nobody has changed since.
	$terms = BSA_Terms::all();
	$n['terms'] = 0;
	foreach ( $reg['terms_added'] ?? array() as $sid => $list ) {
		foreach ( $list as $t => $dates ) {
			if ( isset( $terms[ $sid ][ $t ] ) && $terms[ $sid ][ $t ] == $dates ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				unset( $terms[ $sid ][ $t ] );
				++$n['terms'];
			}
		}
		if ( isset( $terms[ $sid ] ) && ! $terms[ $sid ] ) {
			unset( $terms[ $sid ] );
		}
	}
	update_option( BSA_Terms::OPTION, $terms, false );
	$keep = $bsa_keep_news && ! empty( $reg['news'] ) ? array( 'news' => $reg['news'], 'created' => $reg['created'] ) : array();
	if ( $keep ) {
		update_option( $opt, $keep, false );
	} else {
		delete_option( $opt );
	}
	BSA_School::flush();
	BSA_Reports::flush();
	return array( 'removed' => $n, 'news kept' => $keep ? count( $keep['news'] ) : 0 );
}

// ---------------------------------------------------------------------------------
// Term dates (placeholders where the school has not set them yet).
$demo_terms = array(
	'2025' => array(
		1 => array( 'start' => '2025-01-14', 'end' => '2025-04-10' ),
		2 => array( 'start' => '2025-05-06', 'end' => '2025-08-07' ),
		3 => array( 'start' => '2025-09-09', 'end' => '2025-12-04' ),
	),
	'2026' => array(
		1 => array( 'start' => '2026-01-13', 'end' => '2026-04-02' ),
		2 => array( 'start' => '2026-05-05', 'end' => '2026-08-06' ),
		3 => array( 'start' => '2026-09-08', 'end' => '2026-12-01' ),
	),
	'2027' => array(
		1 => array( 'start' => '2027-01-12', 'end' => '2027-04-01' ),
	),
);
$term_dates = function ( $year, $t ) use ( $sessions ) {
	$x = BSA_Terms::get( $sessions[ $year ], $t );
	return $x && ! empty( $x['start'] ) ? $x : null;
};
$school_days = function ( $year, $t ) use ( $term_dates, $weekdays, $today ) {
	$d = $term_dates( $year, $t );
	if ( ! $d ) {
		return array();
	}
	return $weekdays( $d['start'], min( $d['end'], $today ) );
};
$holidays = array( '2025-12-22', '2026-05-25', '2026-08-10', '2026-08-11' );

// ---------------------------------------------------------------------------------
if ( 'extend' === $bsa_mode ) {
	if ( empty( $reg['phases']['attendance'] ) ) {
		return array( 'The demo register has not been added yet.' );
	}
	$from = gmdate( 'Y-m-d', strtotime( $reg['last_marked'] . ' +1 day' ) );
	$cur  = BSA_Terms::current( $today );
	$days = array();
	if ( $cur && $from <= $today ) {
		$days = $weekdays( max( $from, $cur['start'] ), min( $cur['end'], $today ) );
	}
	$rows = array();
	$recs = $cur ? $wpdb->get_results( "SELECT ID, section_id FROM {$p}wlsm_student_records WHERE ID IN (" . $ids_sql( $reg['records'] ) . ') AND session_id = ' . (int) $cur['session'] ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	mt_srand( crc32( $today ) );
	foreach ( $days as $d ) {
		foreach ( $recs as $r ) {
			$abs    = in_array( (int) $r->ID, $reg['absentees'] ?? array(), true ) ? 0.2 : 0.035;
			$x      = mt_rand() / mt_getrandmax();
			$rows[] = array( $d, $x < $abs ? 'a' : ( $x < $abs + 0.03 ? 'l' : 'p' ), (int) $r->ID, null, (int) ( $reg['class_teachers'][ (int) $r->section_id ] ?? $head_id ), $d . ' 07:45:00' );
		}
	}
	if ( $rows ) {
		$bulk( "{$p}wlsm_attendance", array( 'attendance_date', 'status', 'student_record_id', 'subject_id', 'added_by', 'created_at' ), $rows );
		$reg['last_marked'] = end( $days );
		$save();
	}
	return array( 'days added' => $days, 'rows' => count( $rows ) );
}

if ( 'redo' === $bsa_mode && 'academics' === $bsa_phase && ! empty( $reg['phases']['academics'] ) ) {
	// Marks, report cards and Grade 7 grades again (people, register and fees stay).
	$r = $ids_sql( $reg['records'] );
	$wpdb->query( 'DELETE FROM ' . BSA_Marks::table() . " WHERE student_record_id IN ($r)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( 'DELETE FROM ' . BSA_Marks::reports_table() . " WHERE student_record_id IN ($r)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( 'UPDATE ' . BSA_Admission_Records::table() . " SET grade7_school = '', grade7_year = NULL, grade7_units = NULL, grade7_english = NULL, grade7_maths = NULL, grade7_science = NULL, grade7_language = NULL, grade7_social = NULL, grade7_pe_arts = NULL WHERE student_record_id IN ($r)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	unset( $reg['phases']['academics'] );
	$save();
	$bsa_mode = 'add';
}
if ( 'add' !== $bsa_mode ) {
	return array( 'Unknown mode.' );
}
if ( ! empty( $reg['phases'][ $bsa_phase ] ) ) {
	return array( "Phase '$bsa_phase' was already run on " . $reg['phases'][ $bsa_phase ] . '.' );
}
foreach ( array( 'Form 1', 'Form 2', 'Form 3', 'Form 4' ) as $f ) {
	if ( empty( $sections[ $f ] ) ) {
		return array( "Section for $f A not found; nothing added." );
	}
}
foreach ( array( '2025', '2026', '2027' ) as $y ) {
	if ( empty( $sessions[ $y ] ) ) {
		return array( "School year $y not found; nothing added." );
	}
}
$need = array( 'people' => array(), 'academics' => array( 'people' ), 'attendance' => array( 'academics' ), 'fees' => array( 'people' ), 'intranet' => array( 'people' ), 'whatsapp' => array( 'attendance' ) );
if ( ! isset( $need[ $bsa_phase ] ) ) {
	return array( 'Set $bsa_phase to people, academics, attendance, fees, intranet or whatsapp.' );
}
foreach ( $need[ $bsa_phase ] as $before ) {
	if ( empty( $reg['phases'][ $before ] ) ) {
		return array( "Run phase '$before' first." );
	}
}

// ---------------------------------------------------------------------------------
if ( 'people' === $bsa_phase ) {
	mt_srand( 20261016 );
	$reg = array_merge(
		array( 'created' => $now, 'phases' => array(), 'users' => array(), 'staff' => array(), 'records' => array(), 'adm' => array(), 'learners' => array(), 'posts' => array(), 'news' => array(), 'notices' => array(), 'checks' => array(), 'terms_added' => array(), 'logins' => array() ),
		$reg
	);
	$save();
	$used_logins = array();
	$new_user    = function ( $login, $display, $first, $last, $pass = '' ) use ( &$reg, $save, $domain, &$used_logins ) {
		$base = sanitize_user( strtolower( $login ), true );
		$try  = $base;
		$i    = 2;
		while ( username_exists( $try ) || isset( $used_logins[ $try ] ) ) {
			$try = $base . $i++;
		}
		$used_logins[ $try ] = true;
		$id = wp_insert_user(
			array(
				'user_login'   => $try,
				'user_email'   => $try . '@' . $domain,
				'user_pass'    => $pass ? $pass : wp_generate_password( 24, true, true ),
				'display_name' => $display,
				'first_name'   => $first,
				'last_name'    => $last,
				'role'         => 'subscriber',
			)
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $try . ': ' . $id->get_error_message() );
		}
		$reg['users'][] = (int) $id;
		$save();
		return array( (int) $id, $try );
	};

	// Demo staff: ten teachers (three of them class teachers) and a bursar.
	$teacher_role = $wpdb->get_row( "SELECT ID, permissions FROM {$p}wlsm_roles WHERE name = 'Teacher' AND school_id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$bursar_role  = $wpdb->get_row( "SELECT ID, permissions FROM {$p}wlsm_roles WHERE name = 'Bursar' AND school_id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $teacher_role || ! $bursar_role ) {
		return array( 'Teacher or Bursar role not found; nothing added.' );
	}
	$staff_list = array(
		// name, gender, title, subjects, class teacher of, designation.
		array( 'Tsitsi Chikore', 'female', 'Mrs', array( 'English Language' ), 'Form 2', 'Teacher, English' ),
		array( 'Tendai Marufu', 'male', 'Mr', array( 'Physics' ), 'Form 3', 'Teacher, Physics' ),
		array( 'Nomsa Ndlovu', 'female', 'Mrs', array( 'Biology' ), 'Form 4', 'Teacher, Biology' ),
		array( 'Farai Mlambo', 'male', 'Mr', array( 'Mathematics' ), '', 'Head of Mathematics' ),
		array( 'Rutendo Chinembiri', 'female', 'Ms', array( 'Chemistry' ), '', 'Head of Sciences' ),
		array( 'Blessing Gumbo', 'male', 'Mr', array( 'History', 'Heritage Studies' ), '', 'Teacher, Humanities' ),
		array( 'Chipo Mazarura', 'female', 'Mrs', array( 'Shona Language' ), '', 'Teacher, Shona' ),
		array( 'Tafadzwa Zhou', 'male', 'Mr', array( 'Computer Science' ), '', 'Teacher, Computer Science' ),
		array( 'Precious Machingura', 'female', 'Mrs', array( 'Principles of Accounting', 'Business Enterprise Skills' ), '', 'Teacher, Commercials' ),
		array( 'Kudakwashe Nyathi', 'male', 'Mr', array( 'Geography', 'Combined Science' ), '', 'Teacher, Geography' ),
	);
	$teacher_of = array();
	$ct_user    = array( $sections['Form 1'] => $f1_ct ? (int) $f1_ct->ID : $head_id );
	$demo_pass  = array();
	foreach ( $staff_list as $i => $st ) {
		list( $first, $last ) = explode( ' ', $st[0], 2 );
		$pass                 = 1 === $i ? wp_generate_password( 14, false ) : '';
		list( $uid, $login )  = $new_user( strtolower( $first[0] . '.' . $last ), $st[0], $first, $last, $pass ); // No title: report cards use the initials.
		update_user_meta( $uid, 'wlsm_school_id', 1 );
		$wpdb->insert( "{$p}wlsm_staff", array( 'role' => WLSM_M_Role::get_employee_key(), 'permissions' => $teacher_role->permissions, 'school_id' => 1, 'user_id' => $uid, 'created_at' => $now ) );
		$staff_id         = (int) $wpdb->insert_id;
		$reg['staff'][]   = $staff_id;
		$section          = $st[4] ? $sections[ $st[4] ] : null;
		$wpdb->insert( "{$p}wlsm_admins", array( 'name' => $st[0], 'gender' => $st[1], 'email' => $login . '@' . $domain, 'phone' => '070' . $rand( 1000000, 9999999 ), 'designation' => $st[5], 'role_id' => (int) $teacher_role->ID, 'staff_id' => $staff_id, 'section_id' => $section, 'is_active' => 1, 'joining_date' => $pick( array( '2022-01-10', '2023-01-09', '2024-01-08', '2025-01-13' ) ), 'created_at' => $now ) );
		$admin_id = (int) $wpdb->insert_id;
		if ( $section ) {
			$ct_user[ $section ] = $uid;
		}
		foreach ( $st[3] as $label ) {
			foreach ( $sections as $sec ) {
				if ( isset( $subjects[ $sec ][ $label ] ) ) {
					$wpdb->insert( "{$p}wlsm_admin_subject", array( 'admin_id' => $admin_id, 'subject_id' => $subjects[ $sec ][ $label ], 'created_at' => $now ) );
				}
			}
			$teacher_of[ $label ] = $uid;
		}
		if ( $pass ) {
			$reg['logins']['teacher'] = $login;
			$demo_pass['teacher']     = array( $login, $pass, $st[2] . ' ' . $st[0] . ', class teacher of Form 3 A and Physics teacher' );
		}
	}
	$pass                 = wp_generate_password( 14, false );
	list( $uid, $login )  = $new_user( 'r.kambarami', 'Ruth Kambarami', 'Ruth', 'Kambarami', $pass );
	update_user_meta( $uid, 'wlsm_school_id', 1 );
	$wpdb->insert( "{$p}wlsm_staff", array( 'role' => WLSM_M_Role::get_employee_key(), 'permissions' => $bursar_role->permissions, 'school_id' => 1, 'user_id' => $uid, 'created_at' => $now ) );
	$staff_id       = (int) $wpdb->insert_id;
	$reg['staff'][] = $staff_id;
	$wpdb->insert( "{$p}wlsm_admins", array( 'name' => 'Ruth Kambarami', 'gender' => 'female', 'email' => $login . '@' . $domain, 'designation' => 'Bursar', 'role_id' => (int) $bursar_role->ID, 'staff_id' => $staff_id, 'is_active' => 1, 'joining_date' => '2023-01-09', 'created_at' => $now ) );
	$reg['bursar']           = $uid;
	$reg['logins']['bursar'] = $login;
	$demo_pass['bursar']     = array( $login, $pass, 'Mrs Ruth Kambarami, bursar' );
	$reg['teacher_of']       = $teacher_of;
	$reg['class_teachers']   = $ct_user;
	$save();

	// Learners.
	$girls = array( 'Tariro', 'Rutendo', 'Chiedza', 'Nyasha', 'Tsitsi', 'Rumbidzai', 'Ruvimbo', 'Vimbai', 'Shamiso', 'Chipo', 'Fadzai', 'Kudzai', 'Anesu', 'Ropafadzo', 'Munashe', 'Tadiwanashe', 'Ruramai', 'Mufaro', 'Danai', 'Makanaka', 'Chenai', 'Yeukai', 'Rudo', 'Rufaro', 'Tanyaradzwa', 'Nokutenda', 'Ruvarashe', 'Nyarai', 'Mazvita', 'Tinevimbo', 'Sibongile', 'Nokuthula', 'Thandeka', 'Zanele', 'Nomvula', 'Kimberly', 'Natasha', 'Michelle', 'Precious', 'Faith', 'Grace', 'Ashley', 'Tanaka', 'Panashe', 'Rejoice', 'Primrose' );
	$boys  = array( 'Tatenda', 'Tinashe', 'Takudzwa', 'Kudakwashe', 'Tapiwa', 'Tawanda', 'Simbarashe', 'Tanatswa', 'Tafara', 'Tinotenda', 'Munyaradzi', 'Kundai', 'Tonderai', 'Ngonidzashe', 'Tawananyasha', 'Anotida', 'Takunda', 'Panashe', 'Nigel', 'Brandon', 'Brian', 'Ryan', 'Prince', 'Gift', 'Blessing', 'Collins', 'Shingai', 'Farai', 'Tendai', 'Tafadzwa', 'Mthulisi', 'Sipho', 'Bongani', 'Lwazi', 'Thabani', 'Sibusiso', 'Nkosana', 'Rangarirai', 'Tichaona', 'Kudzaishe', 'Tanaka', 'Ashton', 'Donald', 'Kuziva', 'Mitchell', 'Ruvimbo' );
	$boys  = array_values( array_diff( $boys, array( 'Ruvimbo' ) ) );
	$surn  = array( 'Moyo', 'Ncube', 'Sibanda', 'Dube', 'Ndlovu', 'Mpofu', 'Nyathi', 'Mlambo', 'Gumbo', 'Shumba', 'Zhou', 'Hove', 'Chikwanha', 'Chigumba', 'Marufu', 'Chirwa', 'Mhlanga', 'Tshuma', 'Banda', 'Phiri', 'Mushonga', 'Chinyama', 'Mavhunga', 'Mudzingwa', 'Chakanetsa', 'Matanga', 'Nyamande', 'Musarurwa', 'Chitando', 'Madziva', 'Mukwena', 'Chiweshe', 'Makamure', 'Chidzikwe', 'Nyakudya', 'Mukarakate', 'Dzvairo', 'Chipunza', 'Mapuranga', 'Nyoni', 'Mabhena', 'Masuku', 'Khumalo', 'Mugari', 'Mazhindu', 'Murambiwa', 'Chingwaru', 'Gonese', 'Manyika', 'Mhizha', 'Kaseke', 'Mutendi', 'Mashingaidze', 'Rusike', 'Chabuka', 'Mandaza', 'Chirimuuta', 'Zvinavashe', 'Mupfumi', 'Nhamo', 'Gwenzi', 'Muchenje', 'Chiwanza', 'Sithole', 'Mutizwa', 'Chinyoka', 'Mabika', 'Chikanda', 'Mutero', 'Kanengoni', 'Mazango', 'Ruzvidzo', 'Chakawa', 'Nyandoro', 'Mavengere', 'Chibanda', 'Bvuma', 'Machakaire', 'Mutonhori', 'Chakaipa', 'Marimo', 'Dhliwayo', 'Zinyemba', 'Mafukidze', 'Chakabuda', 'Chiriseri', 'Mudimu', 'Nyika', 'Mangena', 'Mutyambizi', 'Nzou', 'Mukonoweshuro', 'Chitsike', 'Gororo', 'Mashiri', 'Muchengeti', 'Tagwirei', 'Mbiriri', 'Munemo', 'Chaurura', 'Kufa', 'Chimuka', 'Mugweni', 'Rukweza', 'Mhaka', 'Chitiyo', 'Pfende', 'Murehwa', 'Hungwe', 'Jera', 'Tavengwa', 'Chamunorwa', 'Matsika', 'Mukuzunga', 'Chigwida' );
	$surn  = array_values( array_diff( $surn, array( 'Tagwirei', 'Zvinavashe', 'Marufu', 'Chikore', 'Ndlovu', 'Mlambo', 'Gumbo', 'Zhou', 'Nyathi' ) ) ); // No public figures; no demo staff surnames.
	$dads  = array( 'Tendai', 'Farai', 'Kudakwashe', 'Tapiwa', 'Simbarashe', 'Godfrey', 'Clever', 'Innocent', 'Lovemore', 'Edmore', 'Tichaona', 'Givemore', 'Taurai', 'Joseph', 'Peter', 'Stanley', 'Wellington', 'Never', 'Owen', 'Albert', 'Sibusiso', 'Themba', 'Fungai', 'Hardlife', 'Learnmore', 'Tawanda', 'Munyaradzi', 'Gilbert', 'Patrick', 'Takesure' );
	$moms  = array( 'Memory', 'Precious', 'Spiwe', 'Rudo', 'Chipo', 'Sarah', 'Ruth', 'Agnes', 'Loveness', 'Shuvai', 'Tsitsi', 'Grace', 'Fungai', 'Nyasha', 'Sithembile', 'Nomusa', 'Charity', 'Pretty', 'Esther', 'Beauty', 'Tariro', 'Rumbidzai', 'Chiedza', 'Patience', 'Netsai', 'Mercy', 'Florence', 'Sekai', 'Vongai', 'Rutendo' );
	$occ   = array( 'Teacher', 'Nurse', 'Farmer', 'Miner', 'Civil servant', 'Accountant', 'Business owner', 'Police officer', 'Driver', 'Engineer', 'Vendor', 'Bank officer', 'Mechanic', 'Pastor', 'Lecturer', 'Builder', 'Self-employed' );
	$town  = array( 'Chiwaridzo', 'Chipadze', 'Aerodrome', 'Shashi View', 'Garikai', 'Bindura Town', 'Chiwaridzo', 'Chipadze' );
	$away  = array( 'Mt Darwin', 'Shamva', 'Concession', 'Glendale', 'Mvurwi', 'Harare', 'Guruve', 'Marondera' );
	$faith = array( 'Christian (Apostolic)', 'Christian (Catholic)', 'Christian (Methodist)', 'Christian (Anglican)', 'Christian (Pentecostal)', 'Christian (SDA)', 'Christian (ZAOGA)', 'Christian' );
	$types = array();
	foreach ( $wpdb->get_results( "SELECT ID, label FROM {$p}wlsm_student_type" ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$types[ false !== stripos( $t->label, 'board' ) ? 'boarder' : 'day' ] = (string) $t->ID;
	}
	$phone = function () use ( $rand ) {
		return '070' . $rand( 1000000, 9999999 );
	};
	$bc = function () use ( $rand, $pick ) {
		$d = $pick( array( '08', '63', '42', '22', '29' ) );
		return $d . '-' . $rand( 1000000, 2999999 ) . ' ' . $pick( array( 'A', 'B', 'D', 'F', 'K', 'M', 'P', 'R', 'T', 'X' ) ) . ' ' . $d;
	};

	// Plan: 30 per class (29 in Form 2, which has the real learner). Six families have
	// two children at BSA. Admission numbers follow the year of entry.
	$plan  = array( 'Form 4' => 30, 'Form 3' => 30, 'Form 2' => 29, 'Form 1' => 30 );
	$entry = array( 'Form 4' => 2023, 'Form 3' => 2024, 'Form 2' => 2025, 'Form 1' => 2026 );
	$pairs = array( array( 'Form 1', 'Form 3' ), array( 'Form 2', 'Form 4' ), array( 'Form 1', 'Form 4' ), array( 'Form 1', 'Form 2' ), array( 'Form 3', 'Form 4' ), array( 'Form 2', 'Form 3' ) );
	$fam   = array();
	foreach ( $pairs as $i => $pr ) {
		$fam[ $pr[0] ][] = $i;
		$fam[ $pr[1] ][] = $i;
	}
	$families = array();
	$names    = array();
	$no       = 101;
	$learners = array();
	foreach ( $plan as $form => $count ) {
		for ( $k = 0; $k < $count; $k++ ) {
			$gender = $k % 2 ? 'male' : 'female';
			$fid    = isset( $fam[ $form ][ $k ] ) && $k < count( $fam[ $form ] ) ? $fam[ $form ][ $k ] : null;
			do {
				$first  = $pick( 'female' === $gender ? $girls : $boys );
				$second = $chance( 0.2 ) ? $pick( 'female' === $gender ? $girls : $boys ) : $first;
				if ( $second !== $first ) {
					$first .= ' ' . $second;
				}
				$last = null !== $fid && isset( $families[ $fid ] ) ? $families[ $fid ]['surname'] : $pick( $surn );
				$full = $first . ' ' . $last;
			} while ( isset( $names[ $full ] ) );
			$names[ $full ] = true;
			if ( null === $fid || ! isset( $families[ $fid ] ) ) {
				$boarder = $chance( 0.22 );
				$f       = array(
					'surname' => $last,
					'father'  => $chance( 0.85 ) ? $pick( $dads ) . ' ' . $last : '',
					'mother'  => $pick( $moms ) . ' ' . $last,
					'fphone'  => $phone(),
					'mphone'  => $phone(),
					'focc'    => $pick( $occ ),
					'mocc'    => $pick( $occ ),
					'address' => $boarder ? $rand( 10, 999 ) . ' ' . $pick( $away ) : $rand( 100, 4999 ) . ' ' . $pick( $town ) . ', Bindura',
					'boarder' => $boarder,
					'faith'   => $pick( $faith ),
				);
				if ( null !== $fid ) {
					$families[ $fid ] = $f;
				}
			} else {
				$f = $families[ $fid ];
			}
			$by = $entry[ $form ] - 13 + $rand( 0, 1 ) - ( $chance( 0.1 ) ? 1 : 0 );
			$learners[] = array(
				'form'    => $form,
				'name'    => $full,
				'first'   => explode( ' ', $first )[0],
				'gender'  => $gender,
				'adm'     => sprintf( 'BSA%03d', $no++ ),
				'dob'     => sprintf( '%04d-%02d-%02d', $by, $rand( 1, 12 ), $rand( 1, 28 ) ),
				'fam'     => $fid,
				'f'       => $f,
				'entry'   => $entry[ $form ],
				'new'     => false,
			);
		}
	}
	// Three learners joined BSA in 2026 from other schools (no 2025 record).
	foreach ( array( 'Form 2' => 3, 'Form 3' => 7, 'Form 4' => 11 ) as $form => $nth ) {
		$seen = 0;
		foreach ( $learners as &$l ) {
			if ( $form === $l['form'] && null === $l['fam'] && ++$seen === $nth ) {
				$l['new']   = true;
				$l['entry'] = 2026;
			}
		}
		unset( $l );
	}

	// Ability and storylines (used by the later phases).
	$ix = array();
	foreach ( $learners as $i => $l ) {
		$ix[ $l['form'] ][] = $i;
	}
	$story = array();
	foreach ( array( 'Form 1' => array( 2, 5, 9, 14, 19, 24 ), 'Form 2' => array( 4, 17 ) ) as $form => $list ) {
		foreach ( $list as $k ) {
			$story[ $ix[ $form ][ $k ] ] = 'g7drop';
		}
	}
	foreach ( array( 'Form 1' => 11, 'Form 2' => 8, 'Form 3' => 12, 'Form 4' => 6 ) as $form => $k ) {
		$story[ $ix[ $form ][ $k ] ] = 'drop';
	}
	foreach ( array( 'Form 1' => 16, 'Form 2' => 21, 'Form 3' => 3, 'Form 4' => 20 ) as $form => $k ) {
		$story[ $ix[ $form ][ $k ] ] = 'improve';
	}
	$absent = array( $ix['Form 1'][27], $ix['Form 2'][13], $ix['Form 3'][22], $ix['Form 3'][26], $ix['Form 4'][15] );
	foreach ( $learners as $i => &$l ) {
		$l['story']  = $story[ $i ] ?? '';
		$l['absent'] = in_array( $i, $absent, true );
		$l['seed']   = $rand( 1, 1000000 );
	}
	unset( $l );

	// Streams: Form 3 Sciences or Commercials (two not yet chosen); the current Form 4
	// was not streamed and mostly takes the sciences, a few their own mix.
	$k3 = 0;
	$k4 = 0;
	foreach ( $learners as &$l ) {
		if ( 'Form 3' === $l['form'] ) {
			$l['stream'] = 28 <= $k3 ? '' : ( 0 === $k3 % 5 || 3 === $k3 % 5 ? 'Commercials' : 'Sciences' );
			++$k3;
		} elseif ( 'Form 4' === $l['form'] ) {
			$l['stream'] = 0 === $k4 % 8 ? 'own' : 'Sciences';
			++$k4;
		} else {
			$l['stream'] = '';
		}
	}
	unset( $l );

	$types_day = $types['day'] ?? '';
	$types_brd = $types['boarder'] ?? $types_day;
	$parents   = array();
	$demo_fam  = 0; // The family of pair 0 (Form 1 + Form 3) gets the demo parent login.
	foreach ( $learners as $i => &$l ) {
		$f   = $l['f'];
		$key = null !== $l['fam'] ? 'fam' . $l['fam'] : 'l' . $i;
		if ( ! isset( $parents[ $key ] ) ) {
			$who        = $f['father'] && $chance( 0.4 ) ? $f['father'] : $f['mother'];
			list( $fn ) = explode( ' ', $who );
			$is_demo    = 'fam' . $demo_fam === $key;
			$pass       = $is_demo ? wp_generate_password( 14, false ) : '';
			list( $pid, $plogin ) = $new_user( strtolower( $fn . '.' . $f['surname'] ), $who, $fn, $f['surname'], $pass );
			$parents[ $key ] = $pid;
			if ( $is_demo ) {
				$reg['logins']['parent'] = $plogin;
				$demo_pass['parent']     = array( $plogin, $pass, $who . ', parent of two learners (Form 1 and Form 3)' );
			}
		}
		$l['parent'] = $parents[ $key ];
	}
	unset( $l );

	$sess   = array( 2025 => $sessions['2025'], 2026 => $sessions['2026'] );
	$prev   = array( 'Form 2' => 'Form 1', 'Form 3' => 'Form 2', 'Form 4' => 'Form 3' );
	$ex_sub = function ( $section, $stream ) use ( $subjects ) {
		if ( 'own' === $stream ) {
			$want = array( 'English Language', 'Shona Language', 'History', 'Geography', 'Mathematics', 'Principles of Accounting', 'Computer Science', 'Biology', 'Combined Science', 'Business Enterprise Skills' );
			return array_values( array_intersect_key( $subjects[ $section ], array_flip( $want ) ) );
		}
		return BSA_Streams::subjects_for( $section, $stream );
	};
	foreach ( $learners as $i => &$l ) {
		$f    = $l['f'];
		$base = array(
			'name'              => $l['name'],
			'gender'            => $l['gender'],
			'dob'               => $l['dob'],
			'admission_number'  => $l['adm'],
			'enrollment_number' => $l['adm'],
			'roll_number'       => $l['adm'],
			'student_type'      => $f['boarder'] ? $types_brd : $types_day,
			'religion'          => $f['faith'],
			'address'           => $f['address'],
			'city'              => $f['boarder'] ? '' : 'Bindura',
			'state'             => 'Mashonaland Central',
			'country'           => 'Zimbabwe',
			'father_name'       => $f['father'],
			'father_phone'      => $f['father'] ? $f['fphone'] : '',
			'father_occupation' => $f['father'] ? $f['focc'] : '',
			'mother_name'       => $f['mother'],
			'mother_phone'      => $f['mphone'],
			'mother_occupation' => $f['mocc'],
			'admission_date'    => 2026 === $l['entry'] ? '2026-01-13' : $l['entry'] . '-01-' . ( 2023 === $l['entry'] ? '10' : ( 2024 === $l['entry'] ? '09' : '14' ) ),
			'medium'            => '',
			'activities'        => serialize( array() ),
			'is_active'         => 1,
			'parent_user_id'    => $l['parent'],
			'created_at'        => $now,
			'added_by'          => $head_id,
		);
		$recs = array();
		if ( ! $l['new'] && 'Form 1' !== $l['form'] ) {
			$old                = $base;
			$old['section_id']  = $sections[ $prev[ $l['form'] ] ];
			$old['session_id']  = $sess[2025];
			$old['is_active']   = 1;
			$old['parent_user_id'] = null;
			$wpdb->insert( "{$p}wlsm_student_records", $old );
			$recs[2025]         = (int) $wpdb->insert_id;
			$reg['records'][]   = $recs[2025];
			if ( 'Form 4' === $l['form'] && $l['stream'] ) {
				foreach ( $ex_sub( $sections['Form 3'], $l['stream'] ) as $sid ) {
					$wpdb->insert( "{$p}wlsm_students_subjects", array( 'student_id' => $recs[2025], 'subject_id' => $sid ) );
				}
			}
		}
		$new               = $base;
		$new['section_id'] = $sections[ $l['form'] ];
		$new['session_id'] = $sess[2026];
		$wpdb->insert( "{$p}wlsm_student_records", $new );
		$recs[2026]        = (int) $wpdb->insert_id;
		$reg['records'][]  = $recs[2026];
		$reg['adm'][]      = $l['adm'];
		if ( isset( $recs[2025] ) ) {
			$wpdb->insert( "{$p}wlsm_promotions", array( 'from_student_record' => $recs[2025], 'to_student_record' => $recs[2026], 'note' => 'Promoted', 'created_at' => '2025-12-12 10:00:00' ) );
		}
		if ( $l['stream'] ) {
			foreach ( $ex_sub( $sections[ $l['form'] ], $l['stream'] ) as $sid ) {
				$wpdb->insert( "{$p}wlsm_students_subjects", array( 'student_id' => $recs[2026], 'subject_id' => $sid ) );
			}
		}
		$l['recs'] = $recs;
		$save();
	}
	unset( $l );

	// The demo learner login: the Form 3 child of the demo family.
	foreach ( $learners as &$l ) {
		if ( 0 === $l['fam'] && 'Form 3' === $l['form'] ) {
			$pass                 = wp_generate_password( 14, false );
			list( $first )        = explode( ' ', $l['name'] );
			list( $uid, $ulogin ) = $new_user( strtolower( $l['adm'] ), $l['name'], $first, $l['f']['surname'], $pass );
			$wpdb->update( "{$p}wlsm_student_records", array( 'user_id' => $uid ), array( 'ID' => $l['recs'][2026] ) );
			$reg['logins']['learner'] = $ulogin;
			$demo_pass['learner']     = array( $ulogin, $pass, $l['name'] . ', Form 3 A (' . ( $l['stream'] ? $l['stream'] : 'no stream' ) . ')' );
		}
	}
	unset( $l );

	// Birth certificate numbers (Grade 7 slips are added with the marks).
	foreach ( $learners as $i => $l ) {
		BSA_Admission_Records::save_record( $l['recs'][2026], array( 'birth_certificate_no' => 0 === ( $i + 1 ) % 9 ? '' : $bc() ), $head_id );
	}

	foreach ( $learners as &$l ) {
		unset( $l['f'] );
	}
	unset( $l );
	$reg['learners']         = $learners;
	$reg['absentees']        = array();
	foreach ( $learners as $l ) {
		if ( $l['absent'] ) {
			$reg['absentees'][] = $l['recs'][2026];
		}
	}
	$reg['phases']['people'] = $now;
	$save();
	BSA_School::flush();
	return array( 'learners' => count( $learners ), 'records' => count( $reg['records'] ), 'users' => count( $reg['users'] ), 'staff' => count( $reg['staff'] ), 'demo logins (passwords shown once)' => $demo_pass );
}

// ---------------------------------------------------------------------------------
if ( 'academics' === $bsa_phase ) {
	// Term dates where none are set.
	foreach ( $demo_terms as $year => $list ) {
		$sid = $sessions[ $year ];
		$cur = BSA_Terms::all()[ $sid ] ?? array();
		$add = array();
		foreach ( $list as $t => $dates ) {
			if ( empty( $cur[ $t ]['start'] ) ) {
				$cur[ $t ] = $dates;
				$add[ $t ] = $dates;
			}
		}
		if ( $add ) {
			ksort( $cur );
			BSA_Terms::save( $sid, $cur );
			$reg['terms_added'][ $sid ] = $add;
		}
	}
	$save();

	// Ability and direction of travel per learner (from the learner's seed, so a redo
	// gives the same people new marks only if these numbers change).
	foreach ( $reg['learners'] as &$l ) {
		mt_srand( $l['seed'] + 1 );
		$l['ability'] = round( $clamp( 'g7drop' === $l['story'] ? $normal( 53, 2.5 ) : $normal( 67, 9.5 ), 40, 94 ), 1 );
		$l['trend']   = 'improve' === $l['story'] ? 4 : ( 'g7drop' === $l['story'] ? -2 : round( $normal( 0, 1 ), 2 ) );
	}
	unset( $l );

	// Grade 7 slips: most with all six grades, a few with total units only, a few missing.
	$prim = array( 'Bindura Primary School', 'Chipadze Primary School', 'Chiwaridzo Primary School', 'Shashi Primary School', 'Kitsiyatota Primary School', 'Hermitage Primary School', 'Mt Darwin Primary School', 'Shamva Primary School' );
	$g7   = function ( $pct ) {
		foreach ( array( 1 => 85, 2 => 77, 3 => 70, 4 => 60, 5 => 50, 6 => 40, 7 => 30, 8 => 20 ) as $g => $min ) {
			if ( $pct >= $min ) {
				return $g;
			}
		}
		return 9;
	};
	foreach ( $reg['learners'] as $i => $l ) {
		if ( 0 === ( $i + 1 ) % 17 ) {
			continue; // No slip on file yet.
		}
		mt_srand( $l['seed'] + 2 );
		$lvl = 'g7drop' === $l['story'] ? 88 : $l['ability'] + 4;
		$gr  = array();
		foreach ( array( 'english', 'maths', 'science', 'language', 'social', 'pe_arts' ) as $k ) {
			$gr[ $k ] = $g7( $clamp( $normal( $lvl, 'g7drop' === $l['story'] ? 3 : 5 ), 5, 99 ) );
		}
		$rec = array( 'grade7_school' => $pick( $prim ), 'grade7_year' => 2026 - (int) substr( $l['form'], -1 ), 'grade7_units' => array_sum( $gr ) );
		if ( 0 !== ( $i + 1 ) % 11 ) { // Every eleventh: an older slip copy, total units only.
			foreach ( $gr as $k => $g ) {
				$rec[ 'grade7_' . $k ] = $g;
			}
		}
		BSA_Admission_Records::save_record( $l['recs'][2026], $rec, $head_id );
	}

	$diff  = array( 'Mathematics' => -5, 'Physics' => -4, 'Chemistry' => -4, 'Principles of Accounting' => -2, 'Biology' => -1, 'English Language' => 2, 'Shona Language' => 7, 'Computer Science' => 3, 'History' => 0, 'Geography' => 0, 'Combined Science' => 1, 'Business Enterprise Skills' => 4, 'Heritage Studies' => 4 );
	$prev  = array( 'Form 2' => 'Form 1', 'Form 3' => 'Form 2', 'Form 4' => 'Form 3' );
	$remark = function ( $m ) {
		if ( $m >= 80 ) {
			return 'Excellent work';
		} if ( $m >= 70 ) {
			return 'Very good';
		} if ( $m >= 60 ) {
			return 'Good effort';
		} if ( $m >= 50 ) {
			return 'Fair; keep working';
		} if ( $m >= 40 ) {
			return 'Needs more practice';
		}
		return 'Work harder; see me';
	};
	$fmt   = function ( $x ) {
		return number_format( (float) $x, 1, '.', '' );
	};
	$tch   = $reg['teacher_of'];
	$ct    = $reg['class_teachers'];
	$rows  = array();
	$rep   = array();
	$learner_subjects = function ( $rec, $section ) use ( $wpdb, $p, $subjects ) {
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT subject_id FROM {$p}wlsm_students_subjects WHERE student_id = %d", $rec ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all = $subjects[ $section ];
		return $ids ? array_intersect( $all, $ids ) : $all;
	};
	$comment = function ( $first, $avg, $delta ) use ( $pick ) {
		if ( $avg >= 75 ) {
			$c = $pick( array( "An outstanding term, $first. Keep up the excellent work.", "$first has worked with real dedication this term. Excellent results.", "A superb performance. $first leads by example in class." ) );
		} elseif ( $avg >= 62 ) {
			$c = $pick( array( "A very good term, $first. Aim even higher next term.", "$first works well and participates actively. Very good.", "Pleasing results; $first is capable of reaching the top." ) );
		} elseif ( $avg >= 50 ) {
			$c = $pick( array( "A fair term. $first should revise more regularly.", "$first can do better with more consistent effort.", "Satisfactory, but $first must improve in the weaker subjects." ) );
		} else {
			$c = $pick( array( "$first is struggling in several subjects and needs extra support. Please see the class teacher.", "A difficult term. $first must attend the extra lessons and revise daily.", "$first needs to work much harder; we will follow up closely next term." ) );
		}
		if ( $delta <= -8 ) {
			$c .= ' Results dropped this term; we will discuss this with the parents.';
		} elseif ( $delta >= 6 ) {
			$c .= ' A clear improvement on last term.';
		}
		return $c;
	};
	$head_c = function ( $avg ) use ( $pick ) {
		if ( $avg >= 75 ) {
			return $pick( array( 'Excellent. Well done.', 'Outstanding results. Keep it up.' ) );
		} if ( $avg >= 62 ) {
			return $pick( array( 'Very good. Keep working hard.', 'Pleasing progress.' ) );
		} if ( $avg >= 50 ) {
			return $pick( array( 'Fair. More effort needed.', 'Capable of more.' ) );
		}
		return $pick( array( 'Must improve. Parents to see the class teacher.', 'Serious effort needed next term.' ) );
	};
	$conduct = array( 'Excellent', 'Very good', 'Very good', 'Good', 'Good', 'Good', 'Satisfactory' );
	foreach ( $reg['learners'] as $l ) {
		mt_srand( $l['seed'] );
		$offs = array();
		foreach ( $diff as $label => $d ) {
			$offs[ $label ] = $normal( 0, 7 ) + $d;
		}
		$periods = array();
		if ( isset( $l['recs'][2025] ) ) {
			foreach ( array( 1, 2, 3 ) as $t ) {
				$periods[] = array( 2025, $t, $l['recs'][2025], $sections[ $prev[ $l['form'] ] ], $t - 1 );
			}
		}
		foreach ( array( 1, 2, 3 ) as $t ) {
			$periods[] = array( 2026, $t, $l['recs'][2026], $sections[ $l['form'] ], $t + 2 );
		}
		$last = null;
		foreach ( $periods as $per ) {
			list( $year, $t, $rec, $section, $step ) = $per;
			$sid    = $sessions[ (string) $year ];
			$labels = array_flip( $subjects[ $section ] );
			$sum    = 0;
			$n      = 0;
			foreach ( $learner_subjects( $rec, $section ) as $label_id ) {
				$label = $labels[ $label_id ];
				$m     = $l['ability'] + $offs[ $label ] + $l['trend'] * ( $step - 3 ) + $normal( 0, 4 );
				if ( 'drop' === $l['story'] && 2026 === $year && 3 === $t ) {
					$m -= 15;
				}
				if ( 'g7drop' === $l['story'] && 'Form 2' === $l['form'] && 2025 === $year ) {
					$m += 14 - 5 * $t; // Strong start in Form 1, then a slide.
				}
				$m     = round( $clamp( $m, 8, 98 ) );
				$tm    = round( $clamp( $m + $normal( 4, 6 ), 10, 99 ) );
				$pm    = in_array( $label, array( 'Computer Science', 'Physics', 'Chemistry', 'Biology' ), true ) && in_array( $l['form'], array( 'Form 3', 'Form 4' ), true ) && 2026 === $year ? $fmt( $clamp( $m + $normal( 6, 7 ), 10, 99 ) ) : null;
				$exam  = $fmt( $m );
				// Term 3 2026: Form 2 History and Geography exams not marked yet.
				if ( 2026 === $year && 3 === $t && 'Form 2' === $l['form'] && in_array( $label, array( 'History', 'Geography' ), true ) ) {
					$exam = null;
				}
				$rows[] = array( $rec, $sid, $t, $label_id, $fmt( $tm ), $exam, $pm, null === $exam ? '' : $remark( $m ), (int) ( $tch[ $label ] ?? $head_id ), ( $year . '-' . ( 1 === $t ? '03-27' : ( 2 === $t ? '07-31' : ( 2025 === $year ? '11-28' : '10-14' ) ) ) . ' 16:' . sprintf( '%02d', mt_rand( 0, 59 ) ) . ':00' ) );
				if ( null !== $exam ) {
					$sum += $m;
					++$n;
				}
			}
			$avg   = $n ? $sum / $n : 0;
			$delta = null === $last ? 0 : $avg - $last;
			$last  = $avg;
			$terms = BSA_Terms::get( $sid, $t );
			$published = ! ( 2026 === $year && 3 === $t );
			$ct_id = (int) ( $ct[ $section ] ?? $head_id );
			if ( $published ) {
				$pub   = gmdate( 'Y-m-d', strtotime( $terms['end'] . ' +2 days' ) ) . ' 11:00:00';
				$rep[] = array( $rec, $sid, $t, $comment( $l['first'], $avg, $delta ), $pick( $conduct ), $ct_id, $head_c( $avg ), $head_id, $pub, $head_id, $pub );
			} elseif ( 'Form 1' === $l['form'] && mt_rand( 0, 2 ) ) {
				// Term 3 2026 report cards being prepared: some Form 1 comments drafted.
				$rep[] = array( $rec, $sid, $t, $comment( $l['first'], $avg, $delta ), $pick( $conduct ), $ct_id, '', null, null, null, $now );
			}
		}
	}
	$bulk( BSA_Marks::table(), array( 'student_record_id', 'session_id', 'term', 'subject_id', 'term_mark', 'exam_mark', 'project_mark', 'remark', 'entered_by', 'updated_at' ), $rows );
	$bulk( BSA_Marks::reports_table(), array( 'student_record_id', 'session_id', 'term', 'form_teacher_comment', 'conduct', 'form_teacher_id', 'principal_comment', 'principal_id', 'published_at', 'published_by', 'updated_at' ), $rep );
	$reg['phases']['academics'] = $now;
	$save();
	BSA_Reports::flush();
	return array( 'terms added' => $reg['terms_added'], 'marks' => count( $rows ), 'report cards' => count( $rep ) );
}

// ---------------------------------------------------------------------------------
if ( 'attendance' === $bsa_phase ) {
	$prev   = array( 'Form 2' => 'Form 1', 'Form 3' => 'Form 2', 'Form 4' => 'Form 3' );
	$ct     = $reg['class_teachers'];
	$rows   = array();
	$last   = '';
	$periods = array( array( '2025', 3 ), array( '2026', 1 ), array( '2026', 2 ), array( '2026', 3 ) );
	foreach ( $reg['learners'] as $l ) {
		mt_srand( $l['seed'] + 7 );
		$base = $clamp( $normal( 0.03, 0.015 ), 0.005, 0.07 ) + ( $l['ability'] < 45 ? 0.02 : 0 );
		foreach ( $periods as $per ) {
			list( $year, $t ) = $per;
			$rec              = '2025' === $year ? ( $l['recs'][2025] ?? 0 ) : $l['recs'][2026];
			if ( ! $rec ) {
				continue;
			}
			$section = '2025' === $year ? $sections[ $prev[ $l['form'] ] ] : $sections[ $l['form'] ];
			$abs     = $l['absent'] && '2026' === $year && 3 === $t ? 0.22 : $base;
			foreach ( $school_days( $year, $t ) as $d ) {
				// Today: Form 4 A's register is not marked yet (shows on the dashboard).
				if ( $d === $today && 'Form 4' === $l['form'] ) {
					continue;
				}
				$x      = mt_rand() / mt_getrandmax();
				$status = in_array( $d, $holidays, true ) ? 'h' : ( $x < $abs ? 'a' : ( $x < $abs + 0.03 ? 'l' : 'p' ) );
				$rows[] = array( $d, $status, $rec, null, (int) ( $ct[ $section ] ?? $head_id ), $d . ' 07:' . sprintf( '%02d', 40 + mt_rand( 0, 19 ) ) . ':00' );
				$last   = max( $last, $d );
			}
		}
	}
	$bulk( "{$p}wlsm_attendance", array( 'attendance_date', 'status', 'student_record_id', 'subject_id', 'added_by', 'created_at' ), $rows );
	// The head's weekly register checks (all past weeks of 2026; this week still open).
	$checks = 0;
	foreach ( array( 1, 2, 3 ) as $t ) {
		foreach ( $school_days( '2026', $t ) as $d ) {
			if ( '5' !== gmdate( 'N', strtotime( $d ) ) || $d >= $today ) {
				continue;
			}
			foreach ( $sections as $sec ) {
				$ok = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . BSA_Register::checks_table() . ' (section_id, session_id, week_ending, checked_by, checked_at) VALUES (%d, %d, %s, %d, %s)', $sec, $sessions['2026'], $d, $head_id, $d . ' 15:' . sprintf( '%02d', mt_rand( 0, 59 ) ) . ':00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( $ok ) {
					$reg['checks'][] = (int) $wpdb->insert_id;
					++$checks;
				}
			}
		}
	}
	$reg['last_marked']          = $last;
	$reg['phases']['attendance'] = $now;
	$save();
	return array( 'register entries' => count( $rows ), 'weekly checks' => $checks, 'last day' => $last );
}

// ---------------------------------------------------------------------------------
if ( 'fees' === $bsa_phase ) {
	mt_srand( 4242 );
	$paid_k    = WLSM_M_Invoice::get_paid_key();
	$part_k    = WLSM_M_Invoice::get_partially_paid_key();
	$unpaid_k  = WLSM_M_Invoice::get_unpaid_key();
	$bursar    = (int) $reg['bursar'];
	$fees      = array();
	foreach ( $wpdb->get_results( "SELECT class_id, label, amount FROM {$p}wlsm_fees WHERE school_id = 1" ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fees[ (int) $f->class_id ][ $f->label ] = (float) $f->amount;
	}
	$issue     = array( 1 => array( '2026-01-06', '2026-01-13', '2026-03-20' ), 2 => array( '2026-04-28', '2026-05-05', '2026-07-24' ), 3 => array( '2026-09-01', '2026-09-08', $today ) );
	$share     = array( 1 => array( 0.93, 0.05 ), 2 => array( 0.86, 0.09 ), 3 => array( 0.46, 0.30 ) );
	$receipt   = 1;
	$invoices  = 0;
	$payments  = 0;
	$k         = 0;
	$recent    = 0;
	foreach ( $reg['learners'] as $l ) {
		$rec        = $l['recs'][2026];
		$class_id   = $class_of[ $sections[ $l['form'] ] ];
		$scholar    = 0 === $k % 37; // Merit scholarship: half fees.
		++$k;
		foreach ( array( 1, 2, 3 ) as $t ) {
			$label  = "Term $t Fees";
			$amount = $fees[ $class_id ][ $label ] ?? 250.0;
			$disc   = $scholar ? round( $amount / 2, 2 ) : 0.0;
			$due    = $amount - $disc;
			$x      = mt_rand() / mt_getrandmax();
			$paid   = $x < $share[ $t ][0] ? $due : ( $x < $share[ $t ][0] + $share[ $t ][1] ? round( $due * mt_rand( 3, 8 ) / 10 / 5 ) * 5 : 0 );
			$status = $paid >= $due - 0.004 ? $paid_k : ( $paid > 0 ? $part_k : $unpaid_k );
			$wpdb->insert( "{$p}wlsm_invoices", array( 'invoice_number' => sprintf( 'BSA-26T%d-%s', $t, substr( $l['adm'], 3 ) ), 'label' => $label, 'description' => $scholar ? 'Merit scholarship: 50%' : '', 'amount' => $amount, 'discount' => $disc, 'invoice_amount_total' => $amount, 'date_issued' => $issue[ $t ][0], 'due_date' => $issue[ $t ][1], 'partial_payment' => 1, 'status' => $status, 'student_record_id' => $rec, 'created_at' => $issue[ $t ][0] . ' 09:00:00', 'added_by' => $bursar ) );
			$inv = (int) $wpdb->insert_id;
			++$invoices;
			// One or two payments between the issue date and the end of the payment window.
			$parts = $paid > 0 ? ( $paid >= $due && $chance( 0.3 ) ? array( round( $paid / 2 / 5 ) * 5, $paid - round( $paid / 2 / 5 ) * 5 ) : array( $paid ) ) : array();
			$from  = strtotime( $issue[ $t ][0] );
			$to    = strtotime( $issue[ $t ][2] );
			foreach ( $parts as $j => $amt ) {
				$when = $from + mt_rand( 0, max( 0, $to - $from ) );
				if ( 3 === $t && $recent < 14 && $chance( 0.12 ) ) {
					$when = strtotime( $today ) - mt_rand( 0, 6 ) * DAY_IN_SECONDS; // Some this week.
					++$recent;
				}
				if ( (int) gmdate( 'N', $when ) > 5 ) {
					$when -= ( (int) gmdate( 'N', $when ) - 5 ) * DAY_IN_SECONDS;
				}
				$day    = gmdate( 'Y-m-d', $when );
				$time   = $day === $today ? sprintf( '%02d:%02d:00', mt_rand( 8, 11 ), mt_rand( 0, 59 ) ) : sprintf( '%02d:%02d:00', mt_rand( 8, 15 ), mt_rand( 0, 59 ) );
				$bank   = $chance( 0.55 );
				$wpdb->insert( "{$p}wlsm_payments", array( 'receipt_number' => sprintf( 'BSA-R26-%05d', $receipt++ ), 'amount' => $amt, 'payment_method' => $bank ? 'bank-transfer' : 'cash', 'transaction_id' => $bank ? 'FT26' . mt_rand( 10000000, 99999999 ) : '', 'note' => '', 'invoice_label' => $label, 'invoice_payable' => $due, 'invoice_id' => $inv, 'student_record_id' => $rec, 'school_id' => 1, 'created_at' => $day . ' ' . $time, 'added_by' => $bursar ) );
				++$payments;
			}
		}
	}
	$reg['phases']['fees'] = $now;
	$save();
	return array( 'invoices' => $invoices, 'payments' => $payments, 'this week' => $recent );
}

// ---------------------------------------------------------------------------------
if ( 'intranet' === $bsa_phase ) {
	$post = function ( $type, $title, $content, $date, array $fields, $group = 'posts', $excerpt = '' ) use ( &$reg, $save, $head_id ) {
		$id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_status'  => 'publish',
				'post_author'  => $head_id,
				'post_date'    => $date,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( $title . ': ' . $id->get_error_message() );
		}
		if ( $fields ) {
			BSA_Intranet::save_fields( $id, $type, $fields );
		}
		$reg[ $group ][] = (int) $id;
		$save();
		return (int) $id;
	};
	$d = function ( $offset_days, $time = '09:30:00' ) use ( $today ) {
		return gmdate( 'Y-m-d', strtotime( $today . ' ' . $offset_days . ' days' ) ) . ' ' . $time;
	};

	// From the public website (bindurascienceacademy.co.zw), kept when the demo is removed
	// (and not added twice).
	$site     = 'From the BSA website.';
	$had_news = ! empty( $reg['news'] );
	if ( $had_news ) {
		$post = function ( $type, $title, $content, $date, array $fields, $group = 'posts', $excerpt = '' ) use ( $post ) {
			return 'news' === $group ? 0 : $post( $type, $title, $content, $date, $fields, $group, $excerpt );
		};
	}
	$post( 'bsa_announcement', 'Admissions open for 2027: Form 1 and Lower Sixth', "<p>Applications are now open for learners interested in joining the Bindura Science Academy family in 2027. Places are limited, so parents and guardians are encouraged to apply early.</p>\n<p><strong>Form 1, 2027.</strong> Applicants may use their End of Term 2 2026 results to support an early application and secure consideration for a place. Once the official Grade 7 results are released, the Grade 7 results slip should be submitted. Subject to the school's admissions process and availability of places.</p>\n<p><strong>Lower Sixth, January 2027.</strong> Admission will take place after the release of the ZIMSEC Ordinary Level results, subject to the required O' Level results and the school's admissions requirements.</p>\n<p>Applications are completed on the BSA school campus: collect the application form, complete it and submit it at the campus. Please direct parents' questions to the admissions office.</p>\n<p><em>$site</em></p>", $d( '-4' ), array( 'bsa_pin' => '1' ), 'news' );
	$post( 'bsa_achievement', 'Moses Mhonhe: Gold at the NASH National Championships', "<p>Gold in Shot Put at the Bindura District and the Provincial Athletics Competitions held in Mt Darwin, followed by Gold at the NASH National Championships. Moses represents Zimbabwe at the COSSASA Games in Namibia (August 2026).</p>\n<p>He received his first-place certificate at the Provincial Athletics Competitions in Mt Darwin: a defining moment in a remarkable season for BSA sports.</p>\n<p><em>$site</em></p>", '2026-07-20 10:00:00', array( 'bsa_category' => 'Sport' ), 'news' );
	$post( 'bsa_achievement', 'Runyararo Matemure represents BSA in Addis Ababa', "<p>Runyararo Matemure, Honourable Junior Town Clerk of the Bindura Municipality, represented Bindura Science Academy at an African youth programme in Addis Ababa, Ethiopia, in April 2026.</p>\n<p><em>$site</em></p>", '2026-04-28 10:00:00', array( 'bsa_category' => 'Culture' ), 'news' );
	$post( 'bsa_achievement', 'Bronze at the district Debate and Public Speaking Competitions', "<p>BSA learners took part in the Debate and Public Speaking Competitions held at Chipindura Technical High School in May 2026 at district level, proudly securing a bronze medal.</p>\n<p><em>$site</em></p>", '2026-05-29 10:00:00', array( 'bsa_category' => 'Competition' ), 'news' );
	$post( 'bsa_achievement', 'Benhilda Nkoloma shines in the 100m heat', "<p>Benhilda Nkoloma proudly represented Bindura Science Academy at the Bindura District Athletics Competitions, finishing 1st in the 100m heat.</p>\n<p><em>$site</em></p>", '2026-06-12 10:00:00', array( 'bsa_category' => 'Sport' ), 'news' );
	$post( 'bsa_achievement', '100% O Level pass rate for our 2025 candidates', "<p>Our 2025 ZIMSEC O' Level candidates achieved a 100% pass rate (5 or more subjects), a strong start to our examination record.</p>\n<p><em>$site</em></p>", '2026-02-20 10:00:00', array( 'bsa_category' => 'Academic' ), 'news' );
	$post( 'bsa_achievement', 'Three learners elected Junior Town Clerk members', "<p>Three learners from Bindura Science Academy were elected as Honourable Junior Town Clerk members of the Bindura Municipality: a proud moment for BSA's civic voice.</p>\n<p><em>$site</em></p>", '2026-08-05 10:00:00', array( 'bsa_category' => 'Culture' ), 'news' );
	$post( 'bsa_news', 'BSA basketball at the Mashonaland Central Provincial Tournament', "<p>Our basketball teams (boys and girls) took to the court at the Mashonaland Central Basketball Provincial Tournament, competing against schools from across the province and showing the discipline, teamwork and passion that define BSA sport.</p>\n<p><em>$site</em></p>", '2026-06-22 10:00:00', array(), 'news' );
	$post( 'bsa_news', 'Africa Day celebrated in traditional attire', "<p>Learners came dressed in vibrant traditional attire to celebrate Africa Day, honouring the richness of Zimbabwean and African culture through music, dance and shared storytelling across the school.</p>\n<p><em>$site</em></p>", '2026-05-26 10:00:00', array(), 'news' );
	$post( 'bsa_news', 'Visit KuMusha educational trip on 31 July', "<p>BSA's Visit KuMusha educational trip, a cultural and educational learners' trip, is scheduled for 31 July. Learners are encouraged to pay in early and secure their place.</p>\n<p><em>$site</em></p>", '2026-07-06 10:00:00', array(), 'news' );
	$post( 'bsa_news', 'Vacation School: 12 to 28 August 2026', "<p>Focused holiday tuition across ZIMSEC and Cambridge subjects for Form 1 to A Level learners, on campus in Bindura from 12 to 28 August 2026. Places can be reserved on WhatsApp or in person at the campus.</p>\n<p>The programme reinforces challenging topics, gives focused exam practice and feedback, and structured revision aligned with ZIMSEC and Cambridge International expectations.</p>\n<p><em>$site</em></p>", '2026-08-03 10:00:00', array(), 'news' );
	$post( 'bsa_news', 'Coding & Robotics extramural', "<p>Hands-on sessions in programming, electronics and robotics, where learners design, build and program their first robots: nurturing our young scientists and engineers.</p>\n<p><em>$site</em></p>", $d( '-9' ), array(), 'news' );

	// Demo staff notices, events and resources (removed with the demo).
	$staff = array_map( 'intval', $wpdb->get_col( "SELECT user_id FROM {$p}wlsm_staff WHERE ID IN (" . $ids_sql( $reg['staff'] ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$n1 = $post( 'bsa_notice', 'Term 3 examination timetable', "<p>The Term 3 examination timetable for Forms 1 to 3 is in the BSA OneDrive folder (Intranet → Resources). Invigilation duties are on the staff-room board. Please check your slots and tell the deputy head of any clash by Wednesday.</p><p><em>Sample notice for the demonstration.</em></p>", $d( '-2', '07:15:00' ), array( 'bsa_urgency' => 'urgent', 'bsa_ack' => '1', 'bsa_until' => '2026-11-30' ) );
	foreach ( array_slice( $staff, 0, 7 ) as $uid ) {
		BSA_Intranet::acknowledge( $n1, $uid );
	}
	$post( 'bsa_notice', 'Registers by 08:00 every morning', '<p>Class teachers: please mark the daily register by 08:00 so the office can follow up absences the same morning.</p><p><em>Sample notice for the demonstration.</em></p>', $d( '-6', '07:20:00' ), array( 'bsa_urgency' => 'high', 'bsa_until' => '2026-12-01' ) );
	$post( 'bsa_notice', 'Term 3 marks: deadline for exam marks', '<p>All Term 3 exam marks to be entered on the portal by Friday 20 November, so report cards can be checked and published before closing.</p><p><em>Sample notice for the demonstration.</em></p>', $d( '-1', '12:30:00' ), array( 'bsa_urgency' => 'normal', 'bsa_ack' => '1', 'bsa_until' => '2026-11-20' ) );
	$post( 'bsa_event', 'Staff meeting', '<p>Agenda: Term 3 examinations, report cards, 2027 admissions.</p><p><em>Sample event.</em></p>', $d( '-3' ), array( 'bsa_date' => gmdate( 'Y-m-d', strtotime( $today . ' +5 days' ) ), 'bsa_time' => '14:00', 'bsa_location' => 'Staff room', 'bsa_type' => 'meeting' ) );
	$post( 'bsa_event', 'Parents\' consultation: Form 1 and Form 4', '<p>Parents of Form 1 and Form 4 learners meet with subject teachers to review academic progress and discuss support strategies for the term ahead.</p><p><em>Event from the BSA website; the date is a sample.</em></p>', $d( '-5' ), array( 'bsa_date' => gmdate( 'Y-m-d', strtotime( $today . ' +8 days' ) ), 'bsa_time' => '09:00', 'bsa_location' => 'School hall', 'bsa_type' => 'meeting' ) );
	$post( 'bsa_event', 'Forms 1 to 3: end-of-term examinations', '<p>End-of-term examinations for Forms 1 to 3.</p><p><em>Sample event.</em></p>', $d( '-7' ), array( 'bsa_date' => '2026-11-09', 'bsa_end' => '2026-11-20', 'bsa_type' => 'exam' ) );
	$post( 'bsa_event', 'Term 3 exam marks due', '<p>Last day to enter Term 3 exam marks on the portal.</p><p><em>Sample event.</em></p>', $d( '-1' ), array( 'bsa_date' => '2026-11-20', 'bsa_type' => 'deadline' ) );
	$post( 'bsa_event', 'Form 4 Class of 2026 celebration evening', '<p>A celebration evening for our Form 4 O\' Level Class of 2026 as they complete their secondary journey at BSA.</p><p><em>Event from the BSA website; the date is a sample.</em></p>', $d( '-10' ), array( 'bsa_date' => '2026-11-27', 'bsa_time' => '17:30', 'bsa_location' => 'School hall', 'bsa_type' => 'event' ) );
	// Resources: none. Every school document lives in the school's OneDrive folder, linked
	// on the intranet as real content (owner, 2026-10-19).
	// Notice board on the parents' and learners' account page.
	foreach ( array( array( 'Admissions open for 2027', 'Applications for Form 1 and Lower Sixth 2027 are open. Collect the application form at the school campus. Places are limited.' ), array( 'Term 3 closes on 1 December', 'Term 3 closes on Tuesday 1 December 2026. Report cards will be available on this portal. (Sample notice.)' ) ) as $nt ) {
		$wpdb->insert( "{$p}wlsm_notices", array( 'title' => $nt[0], 'description' => $nt[1], 'link_to' => 'none', 'is_active' => 1, 'school_id' => 1, 'added_by' => $head_id, 'created_at' => $now ) );
		$reg['notices'][] = (int) $wpdb->insert_id;
	}
	$reg['phases']['intranet'] = $now;
	$save();
	return array( 'news from the website' => count( $reg['news'] ), 'demo posts' => count( $reg['posts'] ), 'notices for families' => count( $reg['notices'] ) );
}

// ---------------------------------------------------------------------------------
if ( 'whatsapp' === $bsa_phase ) {
	if ( ! class_exists( 'BSA_WhatsApp' ) ) {
		return array( 'The WhatsApp module (plugin 0.16.0) is not installed.' );
	}
	BSA_WhatsApp::maybe_migrate();
	mt_srand( 777 );
	$session = $sessions['2026'];
	$rows    = array();
	$ready   = array();
	$k       = 0;
	foreach ( $sections as $form => $sec ) {
		foreach ( BSA_School::learners( $sec, $session ) as $l ) {
			if ( ! in_array( (int) $l->ID, array_map( 'intval', $reg['records'] ), true ) ) {
				continue; // Real learners are left alone.
			}
			++$k;
			$r       = BSA_WhatsApp::recipient( $l, null );
			$consent = in_array( $k, array( 17, 58, 96 ), true ) ? 'no' : ( 0 === $k % 13 ? '' : 'yes' );
			$at      = '' === $consent ? null : sprintf( '2026-%02d-%02d 10:%02d:00', mt_rand( 1, 2 ), mt_rand( 13, 28 ), mt_rand( 0, 59 ) );
			$rows[]  = array( BSA_WhatsApp::key( $l->admission_number ), $r['number'], $r['name'], $consent, $at, $head_id, $now );
			if ( 'yes' === $consent && $r['number'] ) {
				$ready[ (int) $l->ID ] = array( $l, $r, $sec );
			}
		}
	}
	$bulk( BSA_WhatsApp::contacts_table(), array( 'admission_number', 'number', 'name', 'consent', 'consent_at', 'updated_by', 'updated_at' ), $rows );
	// History: Term 2 report cards (Forms 1 and 2) and last week's absences.
	$log = array();
	$ct  = $reg['class_teachers'];
	foreach ( $ready as $id => $x ) {
		list( $l, $r, $sec ) = $x;
		if ( in_array( $sec, array( $sections['Form 1'], $sections['Form 2'] ), true ) ) {
			$msg   = BSA_WhatsApp::message( 'report', $l, $r, array( 'term' => 2, 'session' => $session ), $session . '-2' );
			$log[] = array( 'report', $session . '-2', $id, $l->admission_number, $r['number'], $msg, $head_id, '2026-08-08 ' . sprintf( '%02d:%02d:00', mt_rand( 9, 11 ), mt_rand( 0, 59 ) ) );
		}
	}
	$days = array_slice( array_values( array_filter( $school_days( '2026', 3 ), function ( $d ) use ( $today ) { return $d < $today; } ) ), -5 );
	if ( $ready && $days ) {
		$in = implode( ',', array_keys( $ready ) );
		foreach ( $wpdb->get_results( "SELECT student_record_id, attendance_date FROM {$p}wlsm_attendance WHERE status = 'a' AND subject_id IS NULL AND attendance_date IN ('" . implode( "','", array_map( 'esc_sql', $days ) ) . "') AND student_record_id IN ($in)" ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			list( $l, $r, $sec ) = $ready[ (int) $a->student_record_id ];
			$msg   = BSA_WhatsApp::message( 'absence', $l, $r, array( 'date' => $a->attendance_date ), $a->attendance_date );
			$log[] = array( 'absence', $a->attendance_date, (int) $a->student_record_id, $l->admission_number, $r['number'], $msg, (int) ( $ct[ $sec ] ?? $head_id ), $a->attendance_date . ' 08:' . sprintf( '%02d', mt_rand( 15, 55 ) ) . ':00' );
		}
	}
	if ( $log ) {
		$bulk( BSA_WhatsApp::log_table(), array( 'type', 'context', 'student_record_id', 'admission_number', 'number', 'message', 'sent_by', 'sent_at' ), $log );
	}
	$reg['phases']['whatsapp'] = $now;
	$save();
	$c = array_count_values( array_column( $rows, 3 ) );
	return array( 'contacts' => count( $rows ), 'consent yes' => $c['yes'] ?? 0, 'consent no' => $c['no'] ?? 0, 'not asked' => $c[''] ?? 0, 'messages in the log' => count( $log ) );
}

return array( 'Nothing done.' );
