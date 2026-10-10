<?php
/**
 * Step 6: portal pages and navigation.
 *
 * - "Home" page (template: Portal home) set as the static front page.
 * - "My Account" page with Weblizar's [school_management_account] (sign-in form when
 *   signed out; learner or parent dashboard when signed in). Its ID is stored in
 *   `bsa_sm_account_page_id` for the BSA plugin's sign-in routing.
 * - Header menu: Home, My Account, School website (assigned to Blocksy's header and
 *   mobile menu locations).
 * - Weblizar dashboard items not used by BSA switched off: Live Classes, Books Issued.
 *
 * Safe to re-run. Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$log = array();

if ( ! class_exists( 'WLSM_Staff_General' ) ) {
	require_once WLSM_PLUGIN_DIR_PATH . 'admin/admin.php';
}

// Create or reuse a page by slug.
$page = function ( $slug, $title, $content, $template = '' ) use ( &$log ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	if ( $existing ) {
		$id = (int) $existing->ID;
		$log[] = "page '$title' exists (#$id)";
	} else {
		$id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $title,
				'post_name'      => $slug,
				'post_content'   => $content,
				'post_author'    => 1,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			$log[] = "FAIL page '$title': " . $id->get_error_message();
			return 0;
		}
		$log[] = "page '$title' created (#$id)";
	}
	if ( $template ) {
		update_post_meta( $id, '_wp_page_template', $template );
	}
	return (int) $id;
};

$home_id    = $page( 'home', 'Home', '', 'templates/page-portal-home.php' );
$account_id = $page( 'account', 'My Account', '<!-- wp:shortcode -->[school_management_account]<!-- /wp:shortcode -->' );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_id );
update_option( 'bsa_sm_account_page_id', $account_id, false );
$log[] = "front page = #$home_id; account page = #$account_id";

// Header menu.
$menu_name = 'Portal menu';
$menu      = wp_get_nav_menu_object( $menu_name );
$menu_id   = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( $menu_name );
if ( ! $menu ) {
	wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => 'Home', 'menu-item-object' => 'page', 'menu-item-object-id' => $home_id, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => 'My Account', 'menu-item-object' => 'page', 'menu-item-object-id' => $account_id, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
	wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => 'School website', 'menu-item-url' => 'https://bindurascienceacademy.co.zw/', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
	$log[] = "menu created (#$menu_id)";
}
$registered = get_registered_nav_menus();
$locations  = get_theme_mod( 'nav_menu_locations', array() );
foreach ( array( 'menu_1', 'menu_mobile' ) as $location ) {
	if ( isset( $registered[ $location ] ) ) {
		$locations[ $location ] = $menu_id;
	}
}
set_theme_mod( 'nav_menu_locations', $locations );
$log[] = 'menu locations: ' . implode( ', ', array_keys( array_filter( $locations ) ) ) . ' (registered: ' . implode( ', ', array_keys( $registered ) ) . ')';

// Weblizar dashboard items: keep everything except Live Classes and Books Issued.
$current = WLSM_M_Setting::get_settings_dashboard( 1 );
$off     = array( 'live_classes', 'books_issues' );
$post    = array( 'save-school-dashboard-settings' => wp_create_nonce( 'save-school-dashboard-settings' ) );
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
foreach ( $map as $field => $key ) {
	if ( ! empty( $current[ $key ] ) && ! in_array( $field, $off, true ) ) {
		$post[ $field ] = 1;
	}
}
wp_set_current_user( 1 );
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
wp_set_current_user( 0 );
$after = WLSM_M_Setting::get_settings_dashboard( 1 );
$log[] = 'dashboard: live_classes=' . (int) $after['school_live_classes'] . ' books_issues=' . (int) $after['school_books_issues'] . ' invoice=' . (int) $after['school_invoice'] . ' attendance=' . (int) $after['school_attendance'] . ' parent_fee_invoice=' . (int) $after['parent_fee_invoice'];

flush_rewrite_rules( false );
$log[] = 'home ' . wp_remote_retrieve_response_code( wp_remote_get( home_url( '/' ), array( 'timeout' => 20 ) ) ) . ', account ' . wp_remote_retrieve_response_code( wp_remote_get( get_permalink( $account_id ), array( 'timeout' => 20 ) ) );

return $log;
