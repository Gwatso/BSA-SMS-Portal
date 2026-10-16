<?php
/**
 * Over-HTTP checks of the Import data screen (plugin 0.12.0), after going live: the real
 * forms, as the school administrator, end to end (paste → columns → check → import →
 * undo), a Word file upload, the template download, and refusals. Run through Novamira
 * `execute-php` (send everything after the opening tag). Sign-in sessions are created for
 * the run and destroyed afterwards. Synthetic learners ("Httptest") are imported into the
 * last school year (which has no real learners) and removed by the undo; anything left is
 * deleted at the end. Results are also kept for an hour in the `bsa_test_import_http`
 * transient.
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
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'location' => (string) wp_remote_retrieve_header( $r, 'location' ), 'body' => (string) wp_remote_retrieve_body( $r ), 'type' => (string) wp_remote_retrieve_header( $r, 'content-type' ) );
};
$php_error = function ( $html ) {
	return (bool) preg_match( '/<b>(Warning|Notice|Fatal error|Deprecated)<\/b>|critical error/', $html );
};
$visible_wp = function ( $html ) {
	$text = html_entity_decode( wp_strip_all_tags( preg_replace( '/<(script|style|noscript)\b[^>]*>.*?<\/\1>|<!--.*?-->/is', ' ', $html ) ), ENT_QUOTES );
	return false !== stripos( $text, 'wordpress' );
};
$nonce = function ( $html, $action_field ) {
	// The nonce of the form whose hidden "action" field has this value.
	if ( preg_match( '/name="action" value="' . preg_quote( $action_field, '/' ) . '".*?name="_wpnonce" value="([^"]+)"/s', $html, $m ) ) {
		return $m[1];
	}
	return '';
};
$page     = admin_url( 'admin.php?page=bsa-import' );
$post     = admin_url( 'admin-post.php' );
$sessions = array_keys( BSA_School::sessions() );
$year     = end( $sessions );
$before   = get_option( BSA_Import::BATCHES, false );
$count    = function () use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE name LIKE '%Httptest%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};

try {
	$check( 'fixture: no real learners in the last school year; no test learners', ! (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE session_id = %d", $year ) ) && 0 === $count() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Who gets in.
	$r = $req( '', $page );
	$check( 'visitor: sent to sign-in', 302 === $r['code'] && false !== strpos( $r['location'], 'wp-login.php' ), $r['code'] . ' ' . $r['location'] );
	$r = $req( 'teacher', $page );
	$check( 'teacher: no Import data screen', 200 !== $r['code'] || false === strpos( $r['body'], 'bsa_import_upload' ), (string) $r['code'] );
	$r = $req( 'teacher', admin_url( 'admin.php?page=bsa-home' ) );
	$check( 'teacher: no "Import data" in the menu', 200 === $r['code'] && false === strpos( $r['body'], 'page=bsa-import' ) );
	$r = $req( 'teacher', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_import_upload', '_wpnonce' => 'forged', 'session' => $year, 'paste' => "Name\tClass\nX Httptest\tForm 1" ) ) );
	$check( 'teacher: a forged upload is refused', 403 === $r['code'] && false === get_transient( 'bsa_import_' . $users['teacher'] ), (string) $r['code'] );

	$first = $req( 'head', $page );
	$check( 'school administrator: screen opens, no PHP errors, no "WordPress" visible', 200 === $first['code'] && false !== strpos( $first['body'], 'bsa_import_upload' ) && ! $php_error( $first['body'] ) && ! $visible_wp( $first['body'] ), (string) $first['code'] );
	$check( 'school administrator: "Import data" in the menu', false !== strpos( $first['body'], 'page=bsa-import' ) && false !== strpos( $first['body'], 'Import data' ) );

	// Template.
	preg_match( '/href="([^"]*action=bsa_import_template[^"]*)"/', $first['body'], $m );
	$tpl = html_entity_decode( $m[1] ?? '' );
	$r   = $req( 'head', $tpl );
	$check( 'template: a CSV that opens in Excel, with the headings', 200 === $r['code'] && false !== stripos( $r['type'], 'text/csv' ) && 0 === strpos( $r['body'], "\xEF\xBB\xBF\"Admission number\",\"First names\",Surname,Sex," ) && false !== strpos( $r['body'], ',Example,Learner,F,24/05/2012,"Form 1",Day,' ), $r['code'] . ' ' . $r['type'] );
	$r = $req( 'teacher', $tpl );
	$check( 'template: refused for a teacher (nonce is per user)', 403 === $r['code'], (string) $r['code'] );

	// Paste → columns → check → import.
	$paste = "Name\tSurname\tSex\tDate of birth\tClass\tParent phone\n"
		. "Tatenda\tHttptest\tM\t12/04/2013\tForm 1\t0771 111 222\n"
		. "Ruvimbo\tHttptest\tF\t03/09/2012\tForm 2 A\t+263 712 333 444\n"
		. "Nokuthula\tHttptest\tF\t01/01/2011\tForm 5\t0773 555 666\n";
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_import_upload', '_wpnonce' => $nonce( $first['body'], 'bsa_import_upload' ), 'session' => $year, 'paste' => $paste ) ) );
	$check( 'paste: read and on to matching the columns', 302 === $r['code'] && false !== strpos( $r['location'], 'step=map' ), $r['code'] . ' ' . $r['location'] );
	$r = $req( 'head', $page . '&step=map' );
	$check( 'columns: "3 rows read from pasted table", headings matched', 200 === $r['code'] && false !== strpos( $r['body'], '3 rows read from pasted table' ) && false !== strpos( $r['body'], '<option value="5" selected=\'selected\'>Parent phone</option>' ) && ! $php_error( $r['body'] ), (string) $r['code'] );
	$map = array( 'first_names' => 0, 'surname' => 1, 'gender' => 2, 'dob' => 3, 'class' => 4, 'guardian_phone' => 5 );
	$r   = $req( 'head', $page . '&' . http_build_query( array( 'step' => 'map', 'map' => $map, 'default_class' => 0 ) ) );
	$check( 'columns: confirmed', 200 === $r['code'] && false !== strpos( $r['body'], 'location.replace' ) );
	$chk = $req( 'head', $page . '&step=check' );
	$check( 'check: 2 ready, 1 error ("Form 5" not recognised), "Import 2 learners"', 200 === $chk['code'] && false !== strpos( $chk['body'], 'Import 2 learners' ) && false !== strpos( $chk['body'], 'Class &quot;Form 5&quot; not recognised' ) && ! $php_error( $chk['body'] ), (string) $chk['code'] );
	$check( 'check: nothing saved yet', 0 === $count() );
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_import_run', '_wpnonce' => 'forged' ) ) );
	$check( 'import: a forged request is refused', 403 === $r['code'] && 0 === $count(), (string) $r['code'] );
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_import_run', '_wpnonce' => $nonce( $chk['body'], 'bsa_import_run' ) ) ) );
	parse_str( (string) wp_parse_url( $r['location'], PHP_URL_QUERY ), $q );
	$check( 'import: 2 learners saved, 1 skipped, back to the screen', 302 === $r['code'] && '2' === ( $q['n'] ?? '' ) && '1' === ( $q['skipped'] ?? '' ) && 2 === $count(), $r['code'] . ' ' . $r['location'] );
	$batch = $q['done'] ?? '';
	$rows  = $wpdb->get_results( "SELECT name, section_id, session_id, father_phone, dob, admission_number, user_id, parent_user_id FROM {$p}wlsm_student_records WHERE name LIKE '%Httptest%' ORDER BY ID" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'import: names, classes, year, phones, birth dates and BSA numbers as expected; no logins', 2 === count( $rows ) && 'Tatenda Httptest' === $rows[0]->name && 4 === (int) $rows[0]->section_id && 5 === (int) $rows[1]->section_id && $year === (int) $rows[0]->session_id && '0771111222' === $rows[0]->father_phone && '0712333444' === $rows[1]->father_phone && '2012-09-03' === $rows[1]->dob && preg_match( '/^BSA\d{3,}$/', $rows[0]->admission_number ) && ! $rows[0]->user_id && ! $rows[0]->parent_user_id, wp_json_encode( $rows ) );
	$check( 'import: the list is not kept after importing', false === get_transient( 'bsa_import_' . $users['head'] ) );
	$done = $req( 'head', $r['location'] );
	$check( 'done: message and Undo button', 200 === $done['code'] && false !== strpos( $done['body'], '2 learners imported. 1 row with errors was skipped.' ) && false !== strpos( $done['body'], 'value="' . esc_attr( $batch ) . '"' ) );

	// Undo.
	$r = $req( 'teacher', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_import_undo', 'batch' => $batch, '_wpnonce' => 'forged' ) ) );
	$check( 'undo: refused for a teacher', 403 === $r['code'] && 2 === $count(), (string) $r['code'] );
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_import_undo', 'batch' => $batch, '_wpnonce' => $nonce( $done['body'], 'bsa_import_undo' ) ) ) );
	$check( 'undo: both learners removed', 302 === $r['code'] && false !== strpos( $r['location'], 'undone=2' ) && 0 === $count(), $r['code'] . ' ' . $r['location'] );
	$r = $req( 'head', $r['location'] );
	$check( 'undo: message, and the import shown as undone', false !== strpos( $r['body'], 'Import undone: 2 learners removed.' ) && false !== strpos( $r['body'], 'Undone ' ) );

	// A Word class list without a class column, uploaded as a file.
	$w    = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
	$tr   = function ( array $cells ) {
		return '<w:tr>' . implode( '', array_map( function ( $c ) { return '<w:tc><w:p><w:r><w:t>' . esc_html( $c ) . '</w:t></w:r></w:p></w:tc>'; }, $cells ) ) . '</w:tr>';
	};
	$xml  = '<?xml version="1.0"?><w:document ' . $w . '><w:body><w:p><w:r><w:t>FORM 2 A</w:t></w:r></w:p><w:tbl>' . $tr( array( 'No.', 'Name', 'Sex' ) ) . $tr( array( '1', 'Panashe Httptest', 'M' ) ) . $tr( array( '2', 'Vimbai Httptest', 'F' ) ) . '</w:tbl></w:body></w:document>';
	$tmp  = wp_tempnam( 'bsa-docx' );
	$zip  = new ZipArchive();
	$zip->open( $tmp, ZipArchive::OVERWRITE );
	$zip->addFromString( 'word/document.xml', $xml );
	$zip->close();
	$docx = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	wp_delete_file( $tmp );
	$first = $req( 'head', $page );
	$bound = 'bsa' . wp_generate_password( 16, false );
	$body  = '';
	foreach ( array( 'action' => 'bsa_import_upload', '_wpnonce' => $nonce( $first['body'], 'bsa_import_upload' ), 'session' => (string) $year, 'paste' => '' ) as $k => $v ) {
		$body .= "--$bound\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
	}
	$body .= "--$bound\r\nContent-Disposition: form-data; name=\"file\"; filename=\"Form 2A class list.docx\"\r\nContent-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document\r\n\r\n$docx\r\n--$bound--\r\n";
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => $body, 'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $bound ) ) );
	$check( 'Word upload: read and on to matching the columns', 302 === $r['code'] && false !== strpos( $r['location'], 'step=map' ), $r['code'] . ' ' . $r['location'] );
	$r = $req( 'head', $page . '&step=map' );
	$check( 'Word upload: "2 rows read from Form-2A-class-list.docx", names found', false !== strpos( $r['body'], '2 rows read from Form-2A-class-list.docx' ) && false !== strpos( $r['body'], 'Panashe Httptest · Vimbai Httptest' ), '' );
	$req( 'head', $page . '&' . http_build_query( array( 'step' => 'map', 'map' => array( 'name' => 1, 'gender' => 2 ), 'default_class' => 5 ) ) );
	$chk = $req( 'head', $page . '&step=check' );
	$check( 'Word upload: "Class for every row" puts both in Form 2 A; ready to import', false !== strpos( $chk['body'], 'Import 2 learners' ) && 2 === substr_count( $chk['body'], '<td>Form 2 A</td>' ) );
	$r = $req( 'head', $post, array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_import_upload', '_wpnonce' => $nonce( $first['body'], 'bsa_import_upload' ), 'session' => (string) $year, 'paste' => '' ) ) );
	$check( 'nothing chosen: asked to choose a file or paste a table', 302 === $r['code'] && false !== strpos( rawurldecode( $r['location'] ), 'Choose a file or paste a table' ), $r['location'] );
	$check( 'Word upload: nothing saved without pressing Import', 0 === $count() );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	// Remove anything left by a failed run.
	$ids = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$p}wlsm_student_records WHERE name LIKE '%Httptest%'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $ids as $id ) {
		$wpdb->delete( BSA_Admission_Records::table(), array( 'student_record_id' => $id ) );
		$wpdb->delete( "{$p}wlsm_student_records", array( 'ID' => $id ) );
	}
	if ( false === $before ) {
		delete_option( BSA_Import::BATCHES );
	} else {
		update_option( BSA_Import::BATCHES, $before, false );
	}
	foreach ( $users as $uid ) {
		delete_transient( 'bsa_import_' . $uid );
	}
	foreach ( $tokens as $role => $t ) {
		WP_Session_Tokens::get_instance( $users[ $role ] )->destroy( $t[0] );
	}
}
$check( 'clean-up: no test learners, import list as before', 0 === $count() && get_option( BSA_Import::BATCHES, false ) === $before );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_import_http', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
