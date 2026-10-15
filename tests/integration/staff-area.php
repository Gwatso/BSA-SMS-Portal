<?php
/**
 * Integration test for BSA_Staff_Area (branded staff area and role home screens), run on
 * the live site through Novamira `execute-php` (send everything after the opening tag).
 *
 * Synthetic learners, marks, register entries, a subject assignment, an invoice and a
 * payment are written inside a transaction and rolled back. Before activation, set
 * $bsa_stage to the staged plugin folder to test that copy. Results are also kept for an
 * hour in the `bsa_test_staff_area` transient. Over-HTTP checks after going live:
 * `staff-area-http.php`.
 *
 * @package BSA_School_Management
 */

global $wpdb, $menu, $submenu, $admin_page_hooks;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
if ( ! class_exists( 'BSA_Staff_Area' ) && $bsa_stage ) {
	if ( ! method_exists( 'BSA_Dashboard', 'fees_table' ) ) {
		throw new RuntimeException( 'The live BSA_Dashboard is older than this test: deploy class-bsa-dashboard.php first.' );
	}
	require_once $bsa_stage . '/includes/class-bsa-staff-area.php';
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

$p       = $wpdb->prefix;
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$original = get_current_user_id();
$notices  = array();
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( &$notices ) {
		$notices[] = basename( $errfile ) . ':' . $errline . ' ' . $errstr;
		return true;
	}
);

$user = function ( $login ) {
	$u = get_user_by( 'login', $login );
	return $u ? (int) $u->ID : 0;
};
$owner   = $user( 'bsa' );
$head    = $user( 'Masango' );
$teacher = $user( 'hlabati.w' );
$bursar  = $user( 'bursar' );

// Who gets the staff area, and where they start.
$check( 'applies: everyone but the owner', ! BSA_Staff_Area::applies( $owner ) && BSA_Staff_Area::applies( $head ) && BSA_Staff_Area::applies( $teacher ) && BSA_Staff_Area::applies( $bursar ) && ! BSA_Staff_Area::applies( 0 ) );
$check( 'home: owner wp-admin, school administrator the School Dashboard, teacher and bursar the home screen', admin_url() === BSA_Staff_Area::home_url( $owner ) && admin_url( 'admin.php?page=bsa-dashboard' ) === BSA_Staff_Area::home_url( $head ) && admin_url( 'admin.php?page=bsa-home' ) === BSA_Staff_Area::home_url( $teacher ) && admin_url( 'admin.php?page=bsa-home' ) === BSA_Staff_Area::home_url( $bursar ) );

// Titles, footer, application passwords, colour scheme.
$name = get_bloginfo( 'name', 'display' );
wp_set_current_user( $teacher );
$t_title  = BSA_Staff_Area::admin_title( 'Marks &lsaquo; ' . $name . ' &#8212; WordPress', 'Marks' );
$t_footer = BSA_Staff_Area::footer_text( 'Thank you for creating with <a href="https://wordpress.org/">WordPress</a>.' );
$t_ver    = BSA_Staff_Area::footer_version( 'Version 7.1.3' );
$t_color  = BSA_Staff_Area::admin_color( 'midnight', 'admin_color', get_userdata( $teacher ) );
wp_set_current_user( $owner );
$o_title  = BSA_Staff_Area::admin_title( 'Marks &lsaquo; ' . $name . ' &#8212; WordPress', 'Marks' );
$o_footer = BSA_Staff_Area::footer_text( 'WP footer' );
$o_ver    = BSA_Staff_Area::footer_version( 'Version 7.1.3' );
$check( 'title: no "WordPress" for staff; owner unchanged', 'Marks &lsaquo; ' . $name === $t_title && false !== strpos( $o_title, 'WordPress' ), $t_title );
$check( 'sign-in page title: no "WordPress"', 'Log In &lsaquo; ' . $name === BSA_Staff_Area::login_title( 'Log In &lsaquo; ' . $name . ' &#8212; WordPress', 'Log In' ) );
$check( 'footer: the school instead of WordPress, no version, for staff only', false !== strpos( $t_footer, 'Passion for Excellence' ) && false === stripos( $t_footer, 'wordpress' ) && '' === $t_ver && 'WP footer' === $o_footer && 'Version 7.1.3' === $o_ver );
$check( 'application passwords: off for staff, unchanged for the owner', false === BSA_Staff_Area::application_passwords( true, get_userdata( $teacher ) ) && true === BSA_Staff_Area::application_passwords( true, get_userdata( $owner ) ) );
$check( 'profile: no social-media fields for staff; owner unchanged', array() === BSA_Staff_Area::contact_methods( array( 'wordpress' => 'WordPress' ), get_userdata( $teacher ) ) && array( 'wordpress' => 'WordPress' ) === BSA_Staff_Area::contact_methods( array( 'wordpress' => 'WordPress' ), get_userdata( $owner ) ) );
$check( 'colour scheme: staff on the default (BSA colours on top)', 'fresh' === $t_color && 'midnight' === BSA_Staff_Area::admin_color( 'midnight', 'admin_color', get_userdata( $owner ) ) );

// Menu: WordPress Dashboard, separators and unused modules gone; Weblizar renamed; order.
$saved_menu    = $menu;
$saved_submenu = $submenu;
$fake_menu     = function () {
	return array(
		2  => array( 'Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard', 'dashicons-dashboard' ),
		4  => array( '', 'read', 'separator1', '', 'wp-menu-separator' ),
		30 => array( 'SM Academic', 'read', 'sm-staff-academic', '', 'menu-top' ),
		31 => array( 'SM Library', 'read', 'sm-staff-library', '', 'menu-top' ),
		32 => array( 'SM Lessons', 'read', 'abc_wlsm_lecture', '', 'menu-top' ),
		33 => array( 'SM School', 'read', 'sm-staff-dashboard', '', 'menu-top' ),
		70 => array( 'Profile', 'read', 'profile.php', '', 'menu-top' ),
	);
};
$fake_sub = array(
	'index.php'          => array( array( 'Home', 'read', 'index.php' ) ),
	'sm-staff-dashboard' => array( array( 'Dashboard', 'read', 'sm-staff-dashboard' ), array( 'Live Classes', 'read', 'sm-staff-assigned-live-classes' ) ),
);
wp_set_current_user( $teacher );
$menu    = $fake_menu(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$submenu = $fake_sub; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
BSA_Staff_Area::tidy_menu();
$labels = wp_list_pluck( $menu, 0, 2 );
$check( 'menu: no Dashboard, separators or Library; Weblizar renamed; "My profile"', array( 'sm-staff-academic' => 'Academics', 'abc_wlsm_lecture' => 'Lessons', 'sm-staff-dashboard' => 'School', 'profile.php' => 'My profile' ) === $labels && ! isset( $submenu['index.php'] ), wp_json_encode( $labels ) );
$check( 'menu: Live Classes hidden', array( 'sm-staff-dashboard' ) === array_values( wp_list_pluck( $submenu['sm-staff-dashboard'], 2 ) ) );
$order = BSA_Staff_Area::menu_order( array( 'index.php', 'profile.php', 'abc_wlsm_lecture', 'sm-staff-academic', 'bsa-marks', 'bsa-home', 'sm-staff-student' ) );
$check( 'menu order: Home, BSA screens, Weblizar groups, "My profile" last', array( 'bsa-home', 'bsa-marks', 'sm-staff-student', 'sm-staff-academic', 'index.php', 'abc_wlsm_lecture', 'profile.php' ) === $order, wp_json_encode( $order ) );
$check( 'menu order: custom order switched on for staff', true === BSA_Staff_Area::custom_menu_order( false ) );
wp_set_current_user( $owner );
$menu    = $fake_menu(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$submenu = $fake_sub; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
BSA_Staff_Area::tidy_menu();
$check( 'menu: owner\'s menu untouched', $fake_menu() === $menu && false === BSA_Staff_Area::custom_menu_order( false ) && array( 'b', 'a' ) === BSA_Staff_Area::menu_order( array( 'b', 'a' ) ) );
$menu    = $saved_menu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$submenu = $saved_submenu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

// Home menu entry.
$hooks_before = $admin_page_hooks;
wp_set_current_user( $head );
BSA_Staff_Area::register_home();
$head_home = isset( $admin_page_hooks['bsa-home'] );
wp_set_current_user( $teacher );
BSA_Staff_Area::register_home();
$check( 'menu: "Home" for the teacher; the school administrator starts on the School Dashboard instead', isset( $admin_page_hooks['bsa-home'] ) && ! $head_home );
$admin_page_hooks = $hooks_before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

// Toolbar.
$bar_for = function ( $uid ) {
	wp_set_current_user( $uid );
	$bar = new WP_Admin_Bar();
	$bar->add_node( array( 'id' => 'wp-logo', 'title' => 'W', 'href' => 'https://wordpress.org/' ) );
	$bar->add_node( array( 'id' => 'site-name', 'title' => 'Site' ) );
	$bar->add_node( array( 'id' => 'command-palette', 'title' => 'Ctrl+K' ) );
	$howdy = sprintf( __( 'Howdy, %s' ), '<span class="display-name">Test Person</span>' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
	$bar->add_node( array( 'id' => 'my-account', 'title' => $howdy, 'parent' => 'top-secondary', 'meta' => array( 'menu_title' => wp_strip_all_tags( $howdy ) ) ) );
	BSA_Staff_Area::brand_node( $bar );
	BSA_Staff_Area::tidy_admin_bar( $bar );
	return $bar;
};
$bar = $bar_for( $teacher );
$acc = $bar->get_node( 'my-account' );
$brand = $bar->get_node( 'bsa-brand' );
$check( 'toolbar: no WordPress logo, site menu or command palette for staff', ! $bar->get_node( 'wp-logo' ) && ! $bar->get_node( 'site-name' ) && ! $bar->get_node( 'command-palette' ) );
$check( 'toolbar: crest and "BSA Portal" linking to the home screen; no "Howdy", also not in the menu label', $brand && false !== strpos( $brand->title, 'BSA Portal' ) && admin_url( 'admin.php?page=bsa-home' ) === $brand->href && $acc && false === strpos( $acc->title, 'Howdy' ) && false !== strpos( $acc->title, 'Test Person' ) && 'Test Person' === $acc->meta['menu_title'] );
$bar = $bar_for( $owner );
$check( 'toolbar: owner keeps the WordPress toolbar', $bar->get_node( 'wp-logo' ) && ! $bar->get_node( 'bsa-brand' ) && false !== strpos( $bar->get_node( 'my-account' )->title, 'Howdy' ) );

// Home screens with synthetic data.
$wpdb->query( 'START TRANSACTION' );
remove_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );
try {
	BSA_School::flush();
	BSA_Reports::flush();
	$term    = BSA_Terms::current();
	$session = $term ? (int) $term['session'] : BSA_School::current_session();
	$check( 'fixture: a current term (Term 3 2026)', $term && 3 === $term['term'] );
	$staff = BSA_School::staff( $teacher );
	$f1a   = (int) $staff->section_id;
	$sci   = 6; // Form 3 Sciences.
	$check( 'fixture: the teacher is class teacher of Form 1 A', 'Form 1 A' === BSA_School::section( $f1a )->title && in_array( 'assigned_class', $staff->permissions, true ) );
	if ( ! BSA_School::learners( $f1a, $session ) ) {
		wp_set_current_user( $teacher );
		ob_start();
		BSA_Staff_Area::render_home();
		$html = ob_get_clean();
		$check( 'teacher screen: an empty class says so, without a subject list', false !== strpos( $html, 'There are no learners in Form 1 A yet.' ) && false === strpos( $html, 'nobody takes it' ) && false === strpos( $html, 'Not marked' ) );
	}
	$mk = function ( $name, $section ) use ( $wpdb, $p, $session ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'gender' => 'female', 'enrollment_number' => 'TEST-STAFF', 'admission_number' => 'TEST-STAFF', 'session_id' => $session, 'section_id' => $section, 'is_active' => 1 ) );
		return (int) $wpdb->insert_id;
	};
	$a    = $mk( 'Staff Test Alpha', $f1a );
	$b    = $mk( 'Staff Test Bravo', $f1a );
	$c    = $mk( 'Staff Test Charlie', $sci );
	$subs = BSA_Marks::section_subjects( $f1a );
	$phys = 0;
	foreach ( BSA_Marks::section_subjects( $sci ) as $s ) {
		if ( 'Physics' === $s->label ) {
			$phys = (int) $s->ID;
		}
	}
	$put = function ( $student, $subject, $exam ) use ( $wpdb, $session, $term, $owner ) {
		$wpdb->insert( BSA_Marks::table(), array( 'student_record_id' => $student, 'session_id' => $session, 'term' => $term['term'], 'subject_id' => $subject, 'exam_mark' => $exam, 'remark' => '', 'entered_by' => $owner, 'updated_at' => current_time( 'mysql' ) ) );
	};
	$put( $a, (int) $subs[0]->ID, 64 );
	$put( $b, (int) $subs[0]->ID, 58 );
	$put( $a, (int) $subs[1]->ID, 71 );
	$today = current_time( 'Y-m-d' );
	foreach ( array( $a => 'p', $b => 'a' ) as $sid => $status ) {
		$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => $today, 'status' => $status, 'student_record_id' => $sid, 'added_by' => $teacher, 'created_at' => current_time( 'mysql' ) ) );
	}
	$admin_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT a.ID FROM {$p}wlsm_admins a JOIN {$p}wlsm_staff s ON s.ID = a.staff_id WHERE s.user_id = %d", $teacher ) );
	$wpdb->insert( "{$p}wlsm_admin_subject", array( 'admin_id' => $admin_row, 'subject_id' => $phys, 'created_at' => current_time( 'mysql' ) ) );
	$wpdb->insert( "{$p}wlsm_invoices", array( 'invoice_number' => 'TEST-STAFF-1', 'label' => 'Term 3 fees (test)', 'amount' => 250, 'discount' => 0, 'date_issued' => $today, 'due_date' => $today, 'status' => 'unpaid', 'student_record_id' => $c, 'added_by' => $owner ) );
	$inv = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}wlsm_payments", array( 'receipt_number' => 'TEST-STAFF', 'amount' => 40, 'payment_method' => 'cash', 'transaction_id' => 'FT-TEST-STAFF', 'invoice_id' => $inv, 'student_record_id' => $c, 'school_id' => 1, 'created_at' => $today . ' 08:00:00', 'added_by' => $bursar ) );

	$prog = BSA_Staff_Area::subject_progress( $f1a, $session, $term['term'] );
	$first = $prog[ (int) $subs[0]->ID ];
	$second = $prog[ (int) $subs[1]->ID ];
	$check( 'subject progress: Form 1 A ' . $subs[0]->label . ' 2 of 2, ' . $subs[1]->label . ' 1 of 2 (with any real learners added)', 2 <= $first['expected'] && $first['expected'] - $first['entered'] === $second['expected'] - $second['entered'] - 1 && count( $prog ) === count( $subs ), wp_json_encode( array( $first, $second ) ) );
	$only = BSA_Staff_Area::subject_progress( $sci, $session, $term['term'], array( $phys ) );
	$check( 'subject progress: limited to the given subjects', array( $phys ) === array_keys( $only ) && $only[ $phys ]['expected'] >= 1 );

	$ts = BSA_Staff_Area::home_state( $teacher );
	$check( 'teacher home: my class Form 1 A with today\'s register', $ts['class'] && 'Form 1 A' === $ts['class']['title'] && $ts['class']['learners'] >= 2 && $ts['class']['register']['marked'] >= 2 && $ts['class']['register']['absent'] >= 1 );
	$mine = array_values(
		array_filter(
			$ts['subjects'],
			static function ( $r ) use ( $phys, $sci ) {
				return $r['subject'] === $phys && $r['section'] === $sci;
			}
		)
	);
	$empty = array_filter(
		$ts['subjects'],
		static function ( $r ) use ( $session ) {
			return ! BSA_School::learners( $r['section'], $session );
		}
	);
	$check( 'teacher home: Physics in Form 3 Sciences under my subjects; classes without learners skipped', 1 === count( $mine ) && 'Form 3 Sciences' === $mine[0]['class'] && ! $empty, wp_json_encode( $ts['subjects'] ) );
	$check( 'teacher home: no fees section', null === $ts['finance'] );
	$bs = BSA_Staff_Area::home_state( $bursar );
	$recent = wp_list_pluck( $bs['finance']['recent'] ?? array(), 'transaction_id' );
	$owing  = wp_list_pluck( $bs['finance']['owing'] ?? array(), 'ID' );
	$check( 'bursar home: fees with the synthetic payment in recent payments and balance in largest balances', $bs['finance'] && in_array( 'FT-TEST-STAFF', $recent, true ) && in_array( (string) $c, array_map( 'strval', $owing ), true ) && null === $bs['class'] && array() === $bs['subjects'] );
	$check( 'school administrator: no separate home (School Dashboard)', null === BSA_Staff_Area::home_state( $head )['finance'] );

	wp_set_current_user( $teacher );
	ob_start();
	BSA_Staff_Area::render_home();
	$html = ob_get_clean();
	$check( 'teacher screen: greeting, role, my class, my subjects, links', 1 === preg_match( '/<h1>Good (morning|afternoon|evening), \w+<\/h1>/', $html ) && false !== strpos( $html, 'Class teacher, Form 1 A' ) && false !== strpos( $html, 'My class: Form 1 A' ) && false !== strpos( $html, 'My subjects, Term 3' ) && false !== strpos( $html, 'page=bsa-marks&#038;section=' . $sci . '&#038;subject=' . $phys ) && false !== strpos( $html, 'page=bsa-class-register&#038;section=' . $f1a ) );
	$check( 'teacher screen: no fees, no WordPress', false === strpos( $html, 'Largest balances' ) && false === stripos( wp_strip_all_tags( $html ), 'wordpress' ) );
	wp_set_current_user( $bursar );
	ob_start();
	BSA_Staff_Area::render_home();
	$html = ob_get_clean();
	$check( 'bursar screen: fee figures, by class, largest balances, recent payments, links', false !== strpos( $html, 'Collected this year' ) && false !== strpos( $html, 'Largest balances' ) && false !== strpos( $html, 'Staff Test Charlie' ) && false !== strpos( $html, 'Recent payments (30 days)' ) && false !== strpos( $html, 'page=sm-staff-invoices' ) && false !== strpos( $html, 'page=bsa-fee-reports' ) && false === strpos( $html, 'My class' ) );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	add_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	BSA_Reports::flush();
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number = 'TEST-STAFF'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_payments WHERE transaction_id = 'FT-TEST-STAFF'" )
	+ (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_invoices WHERE invoice_number = 'TEST-STAFF-1'" );
$check( 'rollback: no synthetic data left', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_staff_area', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
