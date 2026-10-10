<?php
/**
 * Step 3 of the BSA school configuration (owner answer of 2026-10-10):
 * the Sciences and Commercials streams continue into Form 4, so learners are promoted
 * from a Form 3 stream into the matching Form 4 stream.
 *
 * Uses Weblizar's own section handler. Safe to re-run.
 * Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p   = $wpdb->prefix;
$log = array();

if ( ! class_exists( 'WLSM_Staff_Class' ) ) {
	require_once WLSM_PLUGIN_DIR_PATH . 'admin/admin.php';
}

$class_id = 4; // Form 4.
$streams  = array( 'Sciences', 'Commercials' );

// Call a Weblizar AJAX handler in-process; returns 'OK …' or 'FAIL …'.
$call = function ( $handler, array $post ) {
	wp_set_current_user( 1 );
	$_POST    = $post;
	$_REQUEST = $post;
	$level    = ob_get_level();
	// Two layers: save_section closes one buffer more than it opens.
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
	$yes      = strpos( $out, '"success":true' );
	$no       = strpos( $out, '"success":false' );
	return ( false !== $yes && ( false === $no || $yes < $no ) ? 'OK ' : 'FAIL ' ) . substr( wp_strip_all_tags( $out ), 0, 120 );
};

$class_school = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_class_school WHERE school_id = 1 AND class_id = %d", $class_id ) );
$labels       = $wpdb->get_col( $wpdb->prepare( "SELECT label FROM {$p}wlsm_sections WHERE class_school_id = %d", $class_school ) );

// Rename the default section "A" to the first stream, then add the rest.
if ( ! in_array( $streams[0], $labels, true ) && in_array( 'A', $labels, true ) ) {
	$section_a = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_sections WHERE class_school_id = %d AND label = 'A'", $class_school ) );
	$log[]     = "rename Form 4 section A → {$streams[0]}: " . $call(
		array( 'WLSM_Staff_Class', 'save_section' ),
		array( 'section_id' => $section_a, "edit-section-$section_a" => wp_create_nonce( "edit-section-$section_a" ), 'class_id' => $class_id, 'label' => $streams[0], 'is_default' => 1 )
	);
	$labels[] = $streams[0];
}
foreach ( $streams as $stream ) {
	if ( in_array( $stream, $labels, true ) ) {
		continue;
	}
	$log[] = "add Form 4 section $stream: " . $call(
		array( 'WLSM_Staff_Class', 'save_section' ),
		array( 'add-section' => wp_create_nonce( 'add-section' ), 'class_id' => $class_id, 'label' => $stream )
	);
}
wp_set_current_user( 0 );

$log[] = 'sections: ' . implode( ', ', $wpdb->get_col( "SELECT CONCAT(c.label, ' ', se.label) FROM {$p}wlsm_sections se JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id ORDER BY c.ID, se.ID" ) );

return $log;
