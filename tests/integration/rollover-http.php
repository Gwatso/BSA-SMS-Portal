<?php
/**
 * Over-HTTP checks of the Streams and Year-end rollover screens (plugin 0.13.0), after
 * going live: the real forms as the school administrator, and refusals. Run through
 * Novamira `execute-php` (send everything after the opening tag).
 *
 * Synthetic learners ("Httproll") are created in the earliest school year (2025) and
 * rolled over into the next through the real form, then the rollover is undone through
 * its Undo button; anything left is deleted at the end. The test refuses to run while
 * the earliest year holds real learners (they would be rolled over too). The current
 * year is never switched (only the refusal for a teacher is tried). Sign-in sessions are
 * created for the run and destroyed afterwards. Results are also kept for an hour in the
 * `bsa_test_rollover_http` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$p       = $wpdb->prefix;
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$users  = array();
$tokens = array();
foreach ( array( 'head' => 'Masango', 'teacher' => 'hlabati.w' ) as $role => $login ) {
	$u               = get_user_by( 'login', $login );
	$users[ $role ]  = (int) $u->ID;
	$exp             = time() + 600;
	$tokens[ $role ] = array( WP_Session_Tokens::get_instance( $u->ID )->create( $exp ), $exp );
}
$req = function ( $role, $url, array $args = array() ) use ( $users, $tokens ) {
	$cookies = array();
	if ( $role ) {
		list( $tok, $exp ) = $tokens[ $role ];
		$cookies           = array(
			new WP_Http_Cookie( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $users[ $role ], $exp, 'secure_auth', $tok ) ) ),
			new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $users[ $role ], $exp, 'logged_in', $tok ) ) ),
		);
	}
	$r = wp_remote_request( $url, array_merge( array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => false, 'cookies' => $cookies ), $args ) );
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'location' => (string) wp_remote_retrieve_header( $r, 'location' ), 'body' => (string) wp_remote_retrieve_body( $r ) );
};
$php_error  = function ( $html ) {
	return (bool) preg_match( '/<b>(Warning|Notice|Fatal error|Deprecated)<\/b>|critical error/', $html );
};
$visible_wp = function ( $html ) {
	$text = html_entity_decode( wp_strip_all_tags( preg_replace( '/<(script|style|noscript)\b[^>]*>.*?<\/\1>|<!--.*?-->/is', ' ', $html ) ), ENT_QUOTES );
	return false !== stripos( $text, 'wordpress' );
};
$nonce = function ( $html, $action ) {
	if ( preg_match( '/name="action" value="' . preg_quote( $action, '/' ) . '".*?name="_wpnonce" value="([^"]+)"/s', $html, $m ) ) {
		return $m[1];
	}
	return '';
};
$post     = admin_url( 'admin-post.php' );
$sessions = array_keys( BSA_School::sessions() );
$from     = $sessions[0];
$to       = $sessions[1];
BSA_School::flush();
$sec = array();
foreach ( BSA_School::sections() as $s ) {
	$sec[ $s->title ] = (int) $s->ID;
}
$batches_before = get_option( BSA_Rollover::BATCHES, false );
$current_before = (string) get_option( 'wlsm_current_session' );
$made           = array();
$count_new      = function () use ( $wpdb, $p, $to ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE session_id = %d AND name LIKE %s", $to, '%Httproll' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};

try {
	$real = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE session_id = %d", $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'fixture: no real learners in the earliest year (else this test does not run)', 0 === $real, (string) $real );
	if ( $real ) {
		throw new RuntimeException( 'earliest year has real learners: test skipped' );
	}
	foreach ( array( array( 'Panashe Httproll', 'Form 2 A', 'TEST-HROLL-P' ), array( 'Rufaro Httproll', 'Form 2 A', 'TEST-HROLL-R' ), array( 'Simba Httproll', 'Form 3 A', 'TEST-HROLL-S' ), array( 'Tanaka Httproll', 'Form 4 A', 'TEST-HROLL-T' ) ) as $l ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $l[0], 'gender' => 'male', 'enrollment_number' => 'T-' . $l[2], 'admission_number' => $l[2], 'roll_number' => $l[2], 'section_id' => $sec[ $l[1] ], 'session_id' => $from, 'is_active' => 1 ) );
		$made[ $l[0] ] = (int) $wpdb->insert_id;
	}

	// Streams.
	$page = $req( 'head', admin_url( 'admin.php?page=bsa-streams&section=' . $sec['Form 3 A'] . '&session=' . $from ) );
	$check( 'Streams: opens for the school administrator, no PHP errors, no "WordPress" visible', 200 === $page['code'] && false !== strpos( $page['body'], 'Simba Httproll' ) && ! $php_error( $page['body'] ) && ! $visible_wp( $page['body'] ), (string) $page['code'] );
	$check( 'Streams and Year-end rollover in the school administrator\'s menu', false !== strpos( $page['body'], 'page=bsa-streams' ) && false !== strpos( $page['body'], 'page=bsa-rollover' ) );
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_save_streams', 'section' => $sec['Form 3 A'], 'session' => $from, 'stream' => array( $made['Simba Httproll'] => 'Commercials' ), '_wpnonce' => $nonce( $page['body'], 'bsa_save_streams' ) ) ) );
	$map = BSA_Streams::subject_map( array( $made['Simba Httproll'] ) );
	$check( 'Streams: saved through the form (Simba Commercials)', 302 === $r['code'] && false !== strpos( $r['location'], 'saved=1' ) && 'Commercials' === BSA_Streams::stream_of( $sec['Form 3 A'], $map[ $made['Simba Httproll'] ] ), $r['code'] . ' ' . $r['location'] );
	$r = $req( 'teacher', admin_url( 'admin.php?page=bsa-streams' ) );
	$check( 'Streams: no screen for the Form 1 class teacher', 200 !== $r['code'] || false === strpos( $r['body'], 'bsa_save_streams' ), (string) $r['code'] );
	$r = $req( 'teacher', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_save_streams', 'section' => $sec['Form 3 A'], 'session' => $from, 'stream' => array( $made['Simba Httproll'] => 'Sciences' ), '_wpnonce' => 'forged' ) ) );
	$check( 'Streams: a forged save is refused', 403 === $r['code'] && 'Commercials' === BSA_Streams::stream_of( $sec['Form 3 A'], BSA_Streams::subject_map( array( $made['Simba Httproll'] ) )[ $made['Simba Httproll'] ] ), (string) $r['code'] );

	// Rollover.
	$url  = admin_url( 'admin.php?page=bsa-rollover&from=' . $from );
	$page = $req( 'head', $url );
	$check( 'Rollover: opens with every class and a choice per learner, no PHP errors, no "WordPress" visible', 200 === $page['code'] && false !== strpos( $page['body'], 'Form 2 A → Form 3 A' ) && false !== strpos( $page['body'], 'name="act[' . $made['Panashe Httproll'] . ']"' ) && false !== strpos( $page['body'], 'name="stream[' . $made['Panashe Httproll'] . ']"' ) && ! $php_error( $page['body'] ) && ! $visible_wp( $page['body'] ), (string) $page['code'] );
	$body = array(
		'action'   => 'bsa_rollover_run',
		'_wpnonce' => $nonce( $page['body'], 'bsa_rollover_run' ),
		'from'     => $from,
		'to'       => $to,
		'act'      => array( $made['Panashe Httproll'] => 'up', $made['Rufaro Httproll'] => 'repeat', $made['Simba Httproll'] => 'up', $made['Tanaka Httproll'] => 'complete' ),
		'stream'   => array( $made['Panashe Httproll'] => 'Sciences', $made['Simba Httproll'] => 'Commercials' ),
	);
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => $body ) );
	$check( 'Rollover: without the confirmation tick nothing happens', 302 === $r['code'] && false !== strpos( rawurldecode( $r['location'] ), 'Tick the box' ) && 0 === $count_new(), $r['location'] );
	$r = $req( 'teacher', $post, array( 'method' => 'POST', 'body' => array_merge( $body, array( 'confirm' => '1', '_wpnonce' => 'forged' ) ) ) );
	$check( 'Rollover: refused for a teacher', 403 === $r['code'] && 0 === $count_new(), (string) $r['code'] );
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => array_merge( $body, array( 'confirm' => '1' ) ) ) );
	parse_str( (string) wp_parse_url( $r['location'], PHP_URL_QUERY ), $q );
	$check( 'Rollover: 3 moved, 1 completing Form 4', 302 === $r['code'] && '3' === ( $q['moved'] ?? '' ) && '1' === ( $q['leaving'] ?? '' ) && 3 === $count_new(), $r['code'] . ' ' . $r['location'] );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, name, section_id FROM {$p}wlsm_student_records WHERE session_id = %d AND name LIKE %s ORDER BY name", $to, '%Httproll' ), OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$byn  = array();
	foreach ( $rows as $row ) {
		$byn[ $row->name ] = $row;
	}
	$pmap = BSA_Streams::subject_map( array( (int) ( $byn['Panashe Httproll']->ID ?? 0 ), (int) ( $byn['Simba Httproll']->ID ?? 0 ) ) );
	$check( 'Rollover: Panashe in Form 3 A with Sciences, Rufaro repeats Form 2 A, Simba in Form 4 A with Commercials, Tanaka not carried on', isset( $byn['Panashe Httproll'], $byn['Rufaro Httproll'], $byn['Simba Httproll'] ) && ! isset( $byn['Tanaka Httproll'] ) && $sec['Form 3 A'] === (int) $byn['Panashe Httproll']->section_id && $sec['Form 2 A'] === (int) $byn['Rufaro Httproll']->section_id && $sec['Form 4 A'] === (int) $byn['Simba Httproll']->section_id && 'Sciences' === BSA_Streams::stream_of( $sec['Form 3 A'], array_values( $pmap )[0] ) && 'Commercials' === BSA_Streams::stream_of( $sec['Form 4 A'], array_values( $pmap )[1] ), wp_json_encode( $byn ) );
	$done = $req( 'head', $r['location'] );
	$check( 'Rollover: message and Undo button (no year-switch card: the next year is already current)', 200 === $done['code'] && false !== strpos( $done['body'], '3 learners moved to' ) && false !== strpos( $done['body'], 'value="' . esc_attr( $q['done'] ?? '' ) . '"' ) && false === strpos( $done['body'], 'bsa_rollover_switch' ) );
	$r = $req( 'teacher', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_rollover_switch', 'session' => $to, '_wpnonce' => 'forged' ) ) );
	$check( 'Current year: a teacher cannot switch it', 403 === $r['code'] && get_option( 'wlsm_current_session' ) == $current_before, (string) $r['code'] ); // phpcs:ignore Universal.Operators.StrictComparisons
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_rollover_undo', 'batch' => $q['done'] ?? '', '_wpnonce' => $nonce( $done['body'], 'bsa_rollover_undo' ) ) ) );
	$check( 'Undo: the 3 new-year records removed', 302 === $r['code'] && false !== strpos( $r['location'], 'undone=3' ) && 0 === $count_new(), $r['code'] . ' ' . $r['location'] );
} catch ( Throwable $ex ) {
	if ( false === strpos( $ex->getMessage(), 'test skipped' ) ) {
		$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
	}
} finally {
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$p}wlsm_student_records WHERE name LIKE %s", '%Httproll' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $ids as $id ) {
		$wpdb->delete( "{$p}wlsm_students_subjects", array( 'student_id' => $id ) );
		$wpdb->delete( "{$p}wlsm_student_records", array( 'ID' => $id ) );
	}
	if ( false === $batches_before ) {
		delete_option( BSA_Rollover::BATCHES );
	} else {
		update_option( BSA_Rollover::BATCHES, $batches_before, false );
	}
	foreach ( $tokens as $role => $t ) {
		WP_Session_Tokens::get_instance( $users[ $role ] )->destroy( $t[0] );
	}
}
$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE name LIKE %s", '%Httproll' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( 'clean-up: no test learners; rollover list and current year as before', 0 === $left && get_option( BSA_Rollover::BATCHES, false ) === $batches_before && (string) get_option( 'wlsm_current_session' ) === $current_before );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_rollover_http', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
