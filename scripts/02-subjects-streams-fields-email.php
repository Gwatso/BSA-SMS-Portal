<?php
/**
 * Step 2 of the BSA school configuration (owner answers of 2026-10-10):
 * - Forms 1–2 take the 10 ZJC subjects published on the school website; Combined
 *   Science, Business Enterprise Skills and Heritage Studies start at Form 3.
 * - Form 3 has two streams, Sciences and Commercials (Weblizar sections).
 * - Admission forms: religion kept; caste and blood group not collected; transport off
 *   (module out of scope).
 * - Portal email sent from info@bindurascienceacademy.co.zw through FluentSMTP
 *   (PHP mail on the same server, which the domain's SPF record authorises).
 *
 * Uses Weblizar's own admin handlers and FluentSMTP's settings model. Safe to re-run.
 * Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p   = $wpdb->prefix;
$log = array();

if ( ! class_exists( 'WLSM_Staff_General' ) ) {
	require_once WLSM_PLUGIN_DIR_PATH . 'admin/admin.php';
}

$not_before_form3 = array( 'Combined Science', 'Business Enterprise Skills', 'Heritage Studies' );
$form3_streams    = array( 'Sciences', 'Commercials' );
$sender           = array(
	'email' => 'info@bindurascienceacademy.co.zw',
	'name'  => 'Bindura Science Academy',
);

// Call a Weblizar AJAX handler in-process; returns 'OK …' or 'FAIL …'.
$call = function ( $handler, array $post ) {
	wp_set_current_user( 1 );
	$_POST    = $post;
	$_REQUEST = $post;
	$level    = ob_get_level();
	// Two layers: some Weblizar handlers (save_section, registration settings) close
	// one buffer more than they open; the spare layer keeps their output captured.
	ob_start();
	ob_start();
	$die = function () {
		return function () {
			throw new RuntimeException( 'wp_die' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $die );
	add_filter( 'wp_die_handler', $die );
	add_filter( 'wp_doing_ajax', '__return_true' );
	try {
		call_user_func( $handler );
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$out = '';
	while ( ob_get_level() > $level ) {
		$out = ob_get_clean() . $out;
	}
	remove_filter( 'wp_die_ajax_handler', $die );
	remove_filter( 'wp_die_handler', $die );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	$_POST    = array();
	$_REQUEST = array();
	// Some handlers print a success JSON and then a second one from wp_die; the first wins.
	$yes = strpos( $out, '"success":true' );
	$no  = strpos( $out, '"success":false' );
	$ok  = false !== $yes && ( false === $no || $yes < $no );
	return ( $ok ? 'OK ' : 'FAIL ' ) . substr( wp_strip_all_tags( $out ), 0, 120 );
};

// 1. Forms 1–2: remove subjects that start at Form 3.
foreach ( array( 1, 2 ) as $class_id ) {
	foreach ( $not_before_form3 as $label ) {
		$subject_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT s.ID FROM {$p}wlsm_subjects s JOIN {$p}wlsm_class_school cs ON cs.ID = s.class_school_id WHERE cs.school_id = 1 AND cs.class_id = %d AND s.label = %s",
				$class_id,
				$label
			)
		);
		if ( ! $subject_id ) {
			continue;
		}
		$log[] = "remove '$label' from class $class_id: " . $call(
			array( 'WLSM_Staff_Class', 'delete_subject' ),
			array( 'subject_id' => $subject_id, "delete-subject-$subject_id" => wp_create_nonce( "delete-subject-$subject_id" ) )
		);
	}
}

// 2. Form 3 streams: rename the default section "A" to the first stream, add the rest.
$form3_cs = (int) $wpdb->get_var( "SELECT ID FROM {$p}wlsm_class_school WHERE school_id = 1 AND class_id = 3" );
$sections = $wpdb->get_results( $wpdb->prepare( "SELECT ID, label FROM {$p}wlsm_sections WHERE class_school_id = %d ORDER BY ID", $form3_cs ) );
$labels   = wp_list_pluck( $sections, 'label' );
if ( ! in_array( $form3_streams[0], $labels, true ) && in_array( 'A', $labels, true ) ) {
	$section_a = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_sections WHERE class_school_id = %d AND label = 'A'", $form3_cs ) );
	$log[]     = "rename Form 3 section A → {$form3_streams[0]}: " . $call(
		array( 'WLSM_Staff_Class', 'save_section' ),
		array( 'section_id' => $section_a, "edit-section-$section_a" => wp_create_nonce( "edit-section-$section_a" ), 'class_id' => 3, 'label' => $form3_streams[0], 'is_default' => 1 )
	);
	$labels[] = $form3_streams[0];
}
foreach ( $form3_streams as $stream ) {
	if ( in_array( $stream, $labels, true ) ) {
		continue;
	}
	$log[] = "add Form 3 section $stream: " . $call(
		array( 'WLSM_Staff_Class', 'save_section' ),
		array( 'add-section' => wp_create_nonce( 'add-section' ), 'class_id' => 3, 'label' => $stream )
	);
}

// 3. Admission form fields. Checkbox semantics: a posted key means "on".
$current = WLSM_M_Setting::get_settings_registration( 1 );
$off     = array( 'caste', 'blood_group', 'transport' );
$post    = array(
	'save-school-registration-settings' => wp_create_nonce( 'save-school-registration-settings' ),
	'registration_form_title'           => $current['form_title'],
	'redirect_url'                      => $current['redirect_url'],
	'registration_success_message'      => $current['success_message'],
	'registration_admin_phone'          => $current['admin_phone'],
	'registration_admin_email'          => $current['admin_email'],
);
$flags   = array( 'login_user', 'create_invoice', 'auto_admission_number', 'auto_roll_number', 'dob', 'religion', 'caste', 'blood_group', 'phone', 'city', 'state', 'country', 'transport', 'parent_detail', 'parent_occupation', 'parent_login', 'id_number', 'survey', 'fees', 'medium' );
foreach ( $flags as $flag ) {
	if ( ! empty( $current[ $flag ] ) && ! in_array( $flag, $off, true ) ) {
		$post[ 'registration_' . $flag ] = 1;
	}
}
if ( ! empty( $current['student_aprove'] ) ) {
	$post['student_aprove'] = 1;
}
$log[] = 'admission fields (caste, blood group, transport off): ' . $call( array( 'WLSM_Staff_General', 'save_school_registration_settings' ), $post );

// 4a. Weblizar sends through wp_mail (handled by FluentSMTP).
$log[] = 'Weblizar email carrier → wp_mail: ' . $call(
	array( 'WLSM_Staff_General', 'save_school_email_carrier_settings' ),
	array(
		'save-school-email-carrier-settings' => wp_create_nonce( 'save-school-email-carrier-settings' ),
		'email_carrier'                      => 'wp_mail',
		'wp_mail_from_name'                  => $sender['name'],
		'wp_mail_from_email'                 => $sender['email'],
	)
);

// 4b. FluentSMTP connection (PHP mail on this server; no stored mailbox password).
$fluent = get_option( 'fluentmail-settings', array() );
$exists = false;
foreach ( (array) ( $fluent['connections'] ?? array() ) as $connection ) {
	if ( ( $connection['provider_settings']['sender_email'] ?? '' ) === $sender['email'] ) {
		$exists = true;
	}
}
if ( $exists ) {
	$log[] = 'FluentSMTP connection exists';
} else {
	( new \FluentMail\App\Models\Settings() )->store(
		array(
			'connection_key' => '',
			'valid_senders'  => array(),
			'connection'     => array(
				'provider'         => 'default',
				'sender_name'      => $sender['name'],
				'sender_email'     => $sender['email'],
				'force_from_name'  => 'yes',
				'force_from_email' => 'yes',
				'return_path'      => 'yes',
				'key_store'        => 'db',
			),
		)
	);
	$log[] = 'FluentSMTP connection created';
}
wp_set_current_user( 0 );

// Read back (kept small).
$log[] = 'subjects per form: ' . implode( ', ', $wpdb->get_col( "SELECT CONCAT(c.label, '=', COUNT(s.ID)) FROM {$p}wlsm_class_school cs JOIN {$p}wlsm_classes c ON c.ID = cs.class_id LEFT JOIN {$p}wlsm_subjects s ON s.class_school_id = cs.ID GROUP BY c.ID" ) );
$log[] = 'sections: ' . implode( ', ', $wpdb->get_col( "SELECT CONCAT(c.label, ' ', se.label) FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id ORDER BY c.ID, se.ID" ) );
$reg   = WLSM_M_Setting::get_settings_registration( 1 );
$log[] = 'fields: religion=' . (int) $reg['religion'] . ' caste=' . (int) $reg['caste'] . ' blood_group=' . (int) $reg['blood_group'] . ' transport=' . (int) $reg['transport'];
$fl    = get_option( 'fluentmail-settings', array() );
$log[] = 'fluent default connection: ' . ( $fl['connections'][ $fl['misc']['default_connection'] ?? '' ]['provider_settings']['sender_email'] ?? 'none' );

return $log;
