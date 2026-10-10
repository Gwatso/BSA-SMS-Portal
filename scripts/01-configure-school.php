<?php
/**
 * Configures the BSA school in Weblizar through Weblizar's own admin AJAX handlers
 * (the same code the wp-admin forms call), acting as administrator user 1.
 * Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * Safe to re-run: existing classes, subjects and fees are detected and skipped.
 * Data sources: owner decisions 2026-10-10 and the public website (docs/decisions-log.md).
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p   = $wpdb->prefix;
$log = array();

// Weblizar loads its admin handlers only when is_admin(); load them as admin-ajax.php would.
if ( ! class_exists( 'WLSM_School' ) ) {
	require_once WLSM_PLUGIN_DIR_PATH . 'admin/admin.php';
}

$config = array(
	'school'   => array(
		'label'   => 'Bindura Science Academy',
		'phone'   => '+263 77 260 9503',
		'email'   => 'info@bindurascienceacademy.co.zw',
		'address' => '3159 Aerodrome Medium, Bindura, Mashonaland Central, Zimbabwe',
	),
	// Weblizar's default classes 1-4 become Form 1-4; 5-12 are removed.
	'forms'    => array( 1 => 'Form 1', 2 => 'Form 2', 3 => 'Form 3', 4 => 'Form 4' ),
	'remove'   => array( 5, 6, 7, 8, 9, 10, 11, 12 ),
	// Zimbabwe school year, January to December (Q1b default).
	'sessions' => array(
		1 => array( '2026', '2026-01-01', '2026-12-31' ),
		2 => array( '2027', '2027-01-01', '2027-12-31' ),
	),
	// Published on bindurascienceacademy.co.zw (O' Level list), applied to every form (Q2b default).
	'subjects' => array(
		'Mathematics',
		'English Language',
		'Combined Science',
		'History',
		'Geography',
		'Principles of Accounting',
		'Chemistry',
		'Physics',
		'Biology',
		'Shona Language',
		'Computer Science',
		'Business Enterprise Skills',
		'Heritage Studies',
	),
	// USD 250 per term. Weblizar has no "term" period, so one one-time fee per term
	// keeps the yearly total correct (USD 750).
	'fees'     => array( 'Term 1 Fees', 'Term 2 Fees', 'Term 3 Fees' ),
	'fee'      => 250,
);

// Call a Weblizar AJAX handler in-process and return its decoded JSON response.
$call = function ( $handler, array $post ) {
	wp_set_current_user( 1 );
	$_POST    = $post;
	$_REQUEST = $post;
	$level    = ob_get_level();
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
		unset( $e ); // Normal end of a Weblizar handler.
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
	$json     = json_decode( $out, true );
	return is_array( $json ) ? $json : array( 'success' => false, 'data' => substr( wp_strip_all_tags( $out ), 0, 200 ) );
};
$note = function ( $step, $res ) use ( &$log ) {
	$data  = $res['data'] ?? '';
	$log[] = ( ! empty( $res['success'] ) ? 'OK   ' : 'FAIL ' ) . $step . ': ' . ( is_array( $data ) ? wp_json_encode( $data['message'] ?? $data ) : $data );
};

// 1. School identity (keeps numbering settings as they are).
$school = $wpdb->get_row( "SELECT * FROM {$p}wlsm_schools WHERE ID = 1" );
$note(
	'school',
	$call(
		array( 'WLSM_School', 'save' ),
		array(
			'school_id'          => 1,
			'edit-school-1'      => wp_create_nonce( 'edit-school-1' ),
			'label'              => $config['school']['label'],
			'phone'              => $config['school']['phone'],
			'email'              => $config['school']['email'],
			'address'            => $config['school']['address'],
			'description'        => (string) $school->description,
			'school_category'    => (int) $school->category_id,
			'is_active'          => 1,
			'enrollment_prefix'  => $school->enrollment_prefix,
			'enrollment_base'    => $school->enrollment_base,
			'enrollment_padding' => $school->enrollment_padding,
			'admission_prefix'   => $school->admission_prefix,
			'admission_base'     => $school->admission_base,
			'admission_padding'  => $school->admission_padding,
		)
	)
);

// 2. Forms 1-4 and removal of unused default classes.
foreach ( $config['forms'] as $id => $label ) {
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT label FROM {$p}wlsm_classes WHERE ID = %d", $id ) ) === $label ) {
		$log[] = "SKIP class $id already '$label'";
		continue;
	}
	$note( "class $id → $label", $call( array( 'WLSM_Class', 'save' ), array( 'class_id' => $id, "edit-class-$id" => wp_create_nonce( "edit-class-$id" ), 'label' => $label ) ) );
}
foreach ( $config['remove'] as $id ) {
	if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_classes WHERE ID = %d", $id ) ) ) {
		continue;
	}
	$note( "delete class $id", $call( array( 'WLSM_Class', 'delete' ), array( 'class_id' => $id, "delete-class-$id" => wp_create_nonce( "delete-class-$id" ) ) ) );
}

// 3. Assign Forms 1-4 to the school (creates default sections).
$note( 'assign forms to school', $call( array( 'WLSM_School', 'assign_classes' ), array( 'school_id' => 1, 'assign-classes-1' => wp_create_nonce( 'assign-classes-1' ), 'classes' => array_keys( $config['forms'] ) ) ) );

// 4. Sessions.
foreach ( $config['sessions'] as $id => list( $label, $start, $end ) ) {
	$note(
		"session $id → $label",
		$call(
			array( 'WLSM_Session', 'save' ),
			array(
				'session_id'         => $id,
				"edit-session-$id"   => wp_create_nonce( "edit-session-$id" ),
				'label'              => $label,
				'start_date'         => mysql2date( WLSM_Config::date_format(), $start ),
				'end_date'           => mysql2date( WLSM_Config::date_format(), $end ),
			)
		)
	);
}

// 5. Subjects for every form. The handler takes class_id as an array of class IDs;
// a scalar is silently ignored (reports success, saves nothing).
foreach ( $config['subjects'] as $subject ) {
	$missing = array();
	foreach ( array_keys( $config['forms'] ) as $class_id ) {
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$p}wlsm_subjects s JOIN {$p}wlsm_class_school cs ON cs.ID = s.class_school_id WHERE cs.class_id = %d AND cs.school_id = 1 AND s.label = %s",
				$class_id,
				$subject
			)
		);
		if ( ! $exists ) {
			$missing[] = $class_id;
		}
	}
	if ( ! $missing ) {
		continue;
	}
	$res = $call( array( 'WLSM_Staff_Class', 'save_subject' ), array( 'add-subject' => wp_create_nonce( 'add-subject' ), 'label' => $subject, 'code' => '', 'type' => 'theory', 'class_id' => $missing ) );
	if ( empty( $res['success'] ) ) {
		$note( "subject '$subject'", $res );
	}
}

// 6. Term fees for every form.
foreach ( $config['fees'] as $label ) {
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_fees WHERE label = %s AND school_id = 1", $label ) ) ) {
		$log[] = "SKIP fee '$label' exists";
		continue;
	}
	$note(
		"fee '$label'",
		$call(
			array( 'WLSM_Staff_Accountant', 'save_fee' ),
			array(
				'add-fee'             => wp_create_nonce( 'add-fee' ),
				'label'               => $label,
				'amount'              => $config['fee'],
				'period'              => 'one-time',
				'class_id'            => array_keys( $config['forms'] ),
				'active_on_admission' => 1,
				'active_on_dashboard' => 1,
			)
		)
	);
}

wp_set_current_user( 0 );

// Summary read back from the database.
$log[] = 'school: ' . $wpdb->get_var( "SELECT CONCAT(label,' | ',phone,' | ',email) FROM {$p}wlsm_schools WHERE ID = 1" );
$log[] = 'classes: ' . implode( ', ', $wpdb->get_col( "SELECT label FROM {$p}wlsm_classes ORDER BY ID" ) );
// Keep the summary small: a large Novamira response drops the MCP connection.
$log[] = 'sections: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_sections" );
$log[] = 'sessions: ' . implode( ', ', $wpdb->get_col( "SELECT CONCAT(label, ' ', start_date, '..', end_date) FROM {$p}wlsm_sessions ORDER BY ID" ) );
$log[] = 'subjects per form: ' . implode( ', ', $wpdb->get_col( "SELECT CONCAT(c.label, '=', COUNT(s.ID)) FROM {$p}wlsm_class_school cs JOIN {$p}wlsm_classes c ON c.ID = cs.class_id LEFT JOIN {$p}wlsm_subjects s ON s.class_school_id = cs.ID GROUP BY c.ID" ) );
$log[] = 'fee rows: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_fees" );

return $log;
