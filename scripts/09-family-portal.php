<?php
/**
 * Step 9: learner and parent portal (Phase 8).
 *
 * - Weblizar account items BSA does not use switched off (BSA does not use Weblizar's
 *   exams module; results come from BSA report cards): Exam Time Table, Admit Card, Exam
 *   Results (learner and parent), Certificates. Everything else is kept as it is.
 * - "Help" page (/help/): the guide for parents and learners, printable, linked in the
 *   header menu after My Account.
 *
 * Safe to re-run (the page content is refreshed). Run through Novamira `execute-php`
 * (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$log = array();

if ( ! class_exists( 'WLSM_Staff_General' ) ) {
	require_once WLSM_PLUGIN_DIR_PATH . 'admin/admin.php';
}

// 1. Weblizar account items.
$current = WLSM_M_Setting::get_settings_dashboard( 1 );
$off     = array( 'live_classes', 'books_issues', 'exam_time_table', 'admit_card', 'exam_result', 'certificate', 'parent_exam_results' );
$map     = array(
	'invoice' => 'school_invoice', 'payment_history' => 'school_payment_history', 'study_material' => 'school_study_material',
	'home_work' => 'school_home_work', 'noticeboard' => 'school_noticeboard', 'events' => 'school_events',
	'class_time_table' => 'school_class_time_table', 'live_classes' => 'school_live_classes', 'books_issues' => 'school_books_issues',
	'exam_time_table' => 'school_exam_time_table', 'admit_card' => 'school_admit_card', 'exam_result' => 'school_exam_result',
	'certificate' => 'school_certificate', 'attendance' => 'school_attendance', 'leave_request' => 'school_leave_request',
	'enrollment_number' => 'school_enrollment_number', 'admission_number' => 'school_admission_number',
	'parent_id_card' => 'parent_id_card', 'parent_fee_invoice' => 'parent_fee_invoice', 'parent_payement_history' => 'parent_payement_history',
	'parent_noticeboard' => 'parent_noticeboard', 'parent_class_time_table' => 'parent_class_time_table',
	'parent_exam_results' => 'parent_exam_results', 'parent_attendance' => 'parent_attendance',
);
$post = array( 'save-school-dashboard-settings' => wp_create_nonce( 'save-school-dashboard-settings' ) );
foreach ( $map as $field => $key ) {
	if ( ! empty( $current[ $key ] ) && ! in_array( $field, $off, true ) ) {
		$post[ $field ] = 1;
	}
}
$original = get_current_user_id();
wp_set_current_user( 1 );
$post['save-school-dashboard-settings'] = wp_create_nonce( 'save-school-dashboard-settings' );
$_POST    = $post;
$_REQUEST = $post;
$level    = ob_get_level();
ob_start();
ob_start();
$die = function () {
	return function () {
		throw new RuntimeException( 'wp_die' );
	};
};
add_filter( 'wp_die_ajax_handler', $die );
add_filter( 'wp_doing_ajax', '__return_true' );
try {
	WLSM_Staff_General::save_school_dashboard_settings();
} catch ( RuntimeException $e ) {
	unset( $e );
}
while ( ob_get_level() > $level ) {
	ob_end_clean();
}
remove_filter( 'wp_die_ajax_handler', $die );
remove_filter( 'wp_doing_ajax', '__return_true' );
$_POST    = array();
$_REQUEST = array();
$after = WLSM_M_Setting::get_settings_dashboard( 1 );
$on    = array();
foreach ( $after as $key => $value ) {
	if ( $value ) {
		$on[] = $key;
	}
}
$log[] = 'account items on: ' . implode( ', ', $on );

// 2. Help page.
$account = get_permalink( (int) get_option( 'bsa_sm_account_page_id' ) );
$p       = function ( $text ) {
	return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->\n\n";
};
$h       = function ( $text ) {
	return "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">$text</h2>\n<!-- /wp:heading -->\n\n";
};
$ul      = function ( array $items ) {
	$li = '';
	foreach ( $items as $item ) {
		$li .= "<!-- wp:list-item -->\n<li>$item</li>\n<!-- /wp:list-item -->\n";
	}
	return "<!-- wp:list -->\n<ul class=\"wp-block-list\">$li</ul>\n<!-- /wp:list -->\n\n";
};
$content  = $p( 'This page explains how parents and learners use the Bindura Science Academy portal. You can print it from your browser.' );
$content .= $h( 'Signing in' );
$content .= $ul(
	array(
		'Accounts are created by the school office when a learner is admitted. Parents and learners each have their own sign-in.',
		'Go to <a href="' . esc_url( $account ) . '">My Account</a> and enter the username and password given by the school.',
		'To choose your own password, click <strong>Lost your password?</strong> on the sign-in page and enter the email address the school has for you. A link to set a new password is emailed to you.',
		'You can change your email address and password later under <strong>Account settings</strong> (next to Logout).',
		'Keep your password private, and click <strong>Logout</strong> when you finish on a shared phone or computer.',
	)
);
$content .= $h( 'For parents' );
$content .= $p( 'After signing in you see one card for each of your children at the school. Each card has:' );
$content .= $ul(
	array(
		'<strong>Fee Invoices</strong>: the term invoices, with the amount paid and the balance still due.',
		'<strong>Fee Structure</strong>: the fees for the year, term by term.',
		'<strong>Payment History</strong>: every payment the school has recorded, with a receipt you can print.',
		'<strong>Noticeboard</strong> and <strong>Class Time Table</strong>.',
		'<strong>Attendance Report</strong>: days present, absent and late, month by month.',
		'<strong>Report Cards</strong>: the end-of-term report cards, once the school has published them.',
	)
);
$content .= $h( 'For learners' );
$content .= $p( 'The menu on the left (or under <strong>Account menu</strong> on a phone) has your fee invoices and payments, study materials, homework, the noticeboard and events, your class timetable, your attendance, your report cards and leave requests.' );
$content .= $h( 'Fees and paying' );
$content .= $ul(
	array(
		'The fee is USD 250 per term and is invoiced each term.',
		'Pay in cash at the school office, or by bank transfer quoting the invoice number. Ask the school office for the bank details.',
		'You may pay in parts, for example monthly. Each payment reduces the balance on the term invoice.',
		'Payments appear under <strong>Payment History</strong> once the school has recorded them. Keep your bank slip until then.',
	)
);
$content .= $h( 'Report cards' );
$content .= $ul(
	array(
		'Report cards appear under <strong>Report Cards</strong> when the school publishes them at the end of each term. Open one to view or print it.',
		'A subject is passed with 50% or more. The overall result is <strong>Pass</strong> with five or more subjects passed.',
		'The position is within the class and is based on the average exam mark.',
	)
);
$content .= $h( 'Need help?' );
$content .= $p( 'Contact the school office: <a href="mailto:info@bindurascienceacademy.co.zw">info@bindurascienceacademy.co.zw</a>, <a href="tel:+263772609503">+263 77 260 9503</a>, <a href="tel:+263778070422">+263 77 807 0422</a> or <a href="tel:+263783655488">+263 78 365 5488</a>. 3159 Aerodrome Medium, Bindura.' );

$existing = get_page_by_path( 'help', OBJECT, 'page' );
$page     = array(
	'post_type'      => 'page',
	'post_status'    => 'publish',
	'post_title'     => 'Help for parents and learners',
	'post_name'      => 'help',
	'post_content'   => $content,
	'post_author'    => 1,
	'comment_status' => 'closed',
	'ping_status'    => 'closed',
);
if ( $existing ) {
	$page['ID'] = (int) $existing->ID;
	$help_id    = wp_update_post( $page, true );
} else {
	$help_id = wp_insert_post( $page, true );
}
$log[] = is_wp_error( $help_id ) ? 'FAIL help page: ' . $help_id->get_error_message() : 'help page #' . $help_id . ' ' . get_permalink( $help_id );

// 3. Menu: Home, My Account, Help, School website.
$menu = wp_get_nav_menu_object( 'Portal menu' );
if ( $menu && ! is_wp_error( $help_id ) ) {
	$items = wp_get_nav_menu_items( $menu->term_id );
	$has   = false;
	foreach ( $items as $item ) {
		$has = $has || ( 'page' === $item->object && (int) $item->object_id === (int) $help_id );
	}
	if ( ! $has ) {
		wp_update_nav_menu_item( $menu->term_id, 0, array( 'menu-item-title' => 'Help', 'menu-item-object' => 'page', 'menu-item-object-id' => $help_id, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
	}
	$order = array( 'Home' => 1, 'My Account' => 2, 'Help' => 3, 'School website' => 4 );
	foreach ( wp_get_nav_menu_items( $menu->term_id ) as $item ) {
		if ( isset( $order[ $item->title ] ) && (int) $item->menu_order !== $order[ $item->title ] ) {
			wp_update_post( array( 'ID' => $item->ID, 'menu_order' => $order[ $item->title ] ) );
		}
	}
	$log[] = 'menu: ' . implode( ', ', wp_list_pluck( wp_get_nav_menu_items( $menu->term_id ), 'title' ) );
}

wp_set_current_user( $original );
return $log;
