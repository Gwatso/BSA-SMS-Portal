<?php
/**
 * Phase 9 security and health sweep, run on the live site through Novamira `execute-php`
 * (send everything after the opening tag). Read-only.
 *
 * 1. Anonymous HTTP: pages, security headers, XML-RPC, REST users, author enumeration,
 *    private uploads, direct plugin files, Weblizar AJAX blocks, report-card links,
 *    admin-post handlers.
 * 2. Each BSA screen and wp-admin, requested over HTTP as each role (owner, school
 *    administrator, teacher, bursar, sample parent, learner). The sign-in cookies are made
 *    for this run and their sessions destroyed at the end.
 * 3. Configuration: debug output, file editor, administrators, two-factor, login lockout,
 *    versions and pending updates, PHP error logs, backups.
 * Results are kept for an hour in the `bsa_test_security` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$results = array();
$info    = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$get     = function ( $url, array $args = array() ) {
	$r = wp_remote_request( $url, array_merge( array( 'timeout' => 30, 'redirection' => 0 ), $args ) );
	if ( is_wp_error( $r ) ) {
		return array( 'code' => 0, 'body' => $r->get_error_message(), 'headers' => array(), 'location' => '' );
	}
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $r ),
		'body'     => (string) wp_remote_retrieve_body( $r ),
		'headers'  => wp_remote_retrieve_headers( $r ),
		'location' => (string) wp_remote_retrieve_header( $r, 'location' ),
	);
};
$home = home_url( '/' );

// 1. Anonymous HTTP.
foreach ( array( '/', '/account/', '/help/', '/wp-login.php' ) as $path ) {
	$r = $get( home_url( $path ) );
	$check( "anon: $path 200 without a PHP error", 200 === $r['code'] && ! preg_match( '/critical error|Fatal error|Warning:|Notice:|Deprecated:/', $r['body'] ), (string) $r['code'] );
}
$r = $get( $home );
foreach ( array( 'strict-transport-security', 'x-frame-options', 'x-content-type-options', 'referrer-policy' ) as $h ) {
	$check( "headers: $h on the home page", ! empty( $r['headers'][ $h ] ) );
}
$check( 'headers: no X-Pingback', empty( $r['headers']['x-pingback'] ) );
$x = $get( site_url( '/xmlrpc.php' ), array( 'method' => 'POST', 'headers' => array( 'Content-Type' => 'text/xml' ), 'body' => '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>' ) );
$check( 'xml-rpc: no wp.* methods offered', false === strpos( $x['body'], 'wp.getUsersBlogs' ) && false === strpos( $x['body'], 'wp.getPosts' ), (string) $x['code'] );
$check( 'rest: users list hidden from visitors', 404 === $get( rest_url( 'wp/v2/users' ) )['code'] || 401 === $get( rest_url( 'wp/v2/users' ) )['code'] );
$a = $get( add_query_arg( 'author', 1, $home ) );
$check( 'enumeration: ?author=1 does not reveal a login name', false === strpos( $a['location'], '/author/' ) && false === stripos( $a['body'], 'author-' ), $a['code'] . ' ' . $a['location'] );
$an = $get( add_query_arg( 'author_name', 'bsa', $home ) );
$check( 'enumeration: ?author_name= answers 404', 404 === $an['code'], (string) $an['code'] );
$sm = $get( home_url( '/wp-sitemap.xml' ) );
$check( 'enumeration: no users sitemap', false === strpos( $sm['body'], 'sitemap-users' ) && 200 !== $get( home_url( '/wp-sitemap-users-1.xml' ) )['code'], (string) $sm['code'] );
$oe = $get( add_query_arg( 'url', rawurlencode( home_url( '/help/' ) ), rest_url( 'oembed/1.0/embed' ) ) );
$check( 'enumeration: oEmbed data carries no author name', false === strpos( $oe['body'], 'author_name' ), (string) $oe['code'] );
$u = $get( rest_url( 'wp/v2/users/1' ) );
$check( 'enumeration: REST user 1 hidden', in_array( $u['code'], array( 401, 403, 404 ), true ), (string) $u['code'] );
$up = wp_upload_dir();
$check( 'private uploads: folder refused over the web', 403 === $get( trailingslashit( $up['baseurl'] ) . 'bsa-private/' )['code'] );
$d = $get( content_url( '/plugins/bsa-school-management/includes/class-bsa-accounts.php' ) );
$check( 'plugin files: direct request prints nothing', '' === trim( $d['body'] ) || $d['code'] >= 400, $d['code'] . ' ' . strlen( $d['body'] ) . 'b' );
$l = $get( content_url( '/plugins/bsa-school-management/includes/' ) );
$check( 'plugin files: no directory listing', false === stripos( $l['body'], 'Index of' ), (string) $l['code'] );
$check( 'debug.log not published', 200 !== $get( content_url( '/debug.log' ) )['code'] );
foreach ( array( 'wlsm-p-get-students-with-pending-invoices', 'wlsm-p-get-student-pending-invoice', 'wlsm-p-get-exam-result', 'wlsm-p-get-certificate', 'wlsm-p-submit-staff-registration' ) as $action ) {
	$aj = $get( admin_url( 'admin-ajax.php' ), array( 'method' => 'POST', 'body' => array( 'action' => $action ) ) );
	$check( "ajax: $action refused to visitors", 403 === $aj['code'], (string) $aj['code'] );
}
$rc = $get( add_query_arg( array( 'bsa_report' => 1, 'session' => 1, 'term' => 1 ), $home ) );
$check( 'report card link: visitors sent to sign-in', 302 === $rc['code'] && false !== strpos( $rc['location'], 'wp-login.php' ), $rc['code'] . ' ' . $rc['location'] );
foreach ( array( 'bsa_print_reports', 'bsa_save_marks', 'bsa_publish_reports', 'bsa_save_terms', 'bsa_fee_report_csv', 'bsa_admission_file', 'bsa_register_check' ) as $action ) {
	$ap = $get( add_query_arg( 'action', $action, admin_url( 'admin-post.php' ) ) );
	$check( "admin-post: $action does nothing for visitors", 200 !== $ap['code'] || '' === trim( $ap['body'] ), $ap['code'] . ' ' . strlen( $ap['body'] ) . 'b' );
}
$wa = $get( admin_url() );
$check( 'wp-admin: visitors sent to sign-in', 302 === $wa['code'] && false !== strpos( $wa['location'], 'wp-login.php' ) );

// 2. Screens per role over HTTP.
$users  = array();
foreach ( array( 'owner' => 'bsa', 'school administrator' => 'Masango', 'teacher' => 'hlabati.w', 'bursar' => 'bursar', 'parent' => 'bsa001.parent', 'learner' => 'bsa001' ) as $role => $login ) {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		$users[ $role ] = (int) $user->ID;
	}
}
$check( 'roles: all six test logins exist', 6 === count( $users ), implode( ', ', array_keys( $users ) ) );
$tokens = array();
$as     = function ( $role, $url ) use ( $users, &$tokens, $get ) {
	$uid = $users[ $role ];
	if ( ! isset( $tokens[ $role ] ) ) {
		$exp              = time() + 600;
		$token            = WP_Session_Tokens::get_instance( $uid )->create( $exp );
		$tokens[ $role ]  = array( $token, wp_generate_auth_cookie( $uid, $exp, 'logged_in', $token ), wp_generate_auth_cookie( $uid, $exp, is_ssl() ? 'secure_auth' : 'auth', $token ) );
	}
	return $get( $url, array( 'cookies' => array( LOGGED_IN_COOKIE => $tokens[ $role ][1], ( is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE ) => $tokens[ $role ][2] ) ) );
};
// Expected: Y = screen opens, N = refused or redirected.
$matrix = array(
	'bsa-admission-documents' => array( 'Y', 'Y', 'N', 'N', 'N', 'N' ),
	'bsa-family-accounts'     => array( 'Y', 'Y', 'N', 'N', 'N', 'N' ),
	'bsa-fee-reports'         => array( 'Y', 'Y', 'N', 'Y', 'N', 'N' ),
	'bsa-school-terms'        => array( 'Y', 'Y', 'N', 'N', 'N', 'N' ),
	'bsa-class-register'      => array( 'Y', 'Y', 'Y', 'N', 'N', 'N' ),
	'bsa-marks'               => array( 'Y', 'Y', 'Y', 'N', 'N', 'N' ),
	'bsa-report-cards'        => array( 'Y', 'Y', 'Y', 'N', 'N', 'N' ),
);
$roles  = array_keys( $users );
$matrix_ok = true;
$cells     = array();
foreach ( $matrix as $page => $expect ) {
	foreach ( $roles as $i => $role ) {
		$r      = $as( $role, admin_url( 'admin.php?page=' . $page ) );
		$opened = 200 === $r['code'] && false !== strpos( $r['body'], 'id="wpbody"' ) && ! preg_match( '/Sorry, you are not allowed|do not have permission/i', $r['body'] );
		$got    = $opened ? 'Y' : 'N';
		$cells[] = $page . ':' . $role . '=' . $got . ( $got !== $expect[ $i ] ? '(expected ' . $expect[ $i ] . ', HTTP ' . $r['code'] . ')' : '' );
		if ( $got !== $expect[ $i ] ) {
			$matrix_ok = false;
		}
		if ( $opened && preg_match( '/<b>(Warning|Notice|Fatal error|Deprecated)<\/b>/', $r['body'], $m ) ) {
			$matrix_ok = false;
			$cells[]   = "$page:$role PHP " . $m[1];
		}
	}
}
$check( 'screens: each BSA screen opens for exactly the expected roles, without PHP errors', $matrix_ok, implode( ' ', array_filter( $cells, static function ( $c ) { return false !== strpos( $c, 'expected' ) || false !== strpos( $c, 'PHP' ); } ) ) );
$info['screen matrix'] = implode( ' ', $cells );
foreach ( array( 'parent', 'learner' ) as $role ) {
	$r = $as( $role, admin_url() );
	$check( "wp-admin: $role sent to My Account", 302 === $r['code'] && false !== strpos( $r['location'], '/account' ), $r['code'] . ' ' . $r['location'] );
	$r = $as( $role, home_url( '/account/' ) );
	$check( "account page: $role sees their own page without PHP errors", 200 === $r['code'] && false !== strpos( $r['body'], 'wlsm-container' ) && ! preg_match( '/<b>(Warning|Notice|Fatal error)<\/b>|critical error/', $r['body'] ) );
}
$r = $as( 'parent', add_query_arg( array( 'bsa_report' => 999999, 'session' => 1, 'term' => 1 ), $home ) );
$check( 'report card link: another learner\'s card refused to a parent', 404 === $r['code'], (string) $r['code'] );
foreach ( $tokens as $role => $t ) {
	WP_Session_Tokens::get_instance( $users[ $role ] )->destroy( $t[0] );
}

// 3. Configuration and health.
$check( 'config: debug output off on the site', ! ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY && defined( 'WP_DEBUG' ) && WP_DEBUG ) && ! ini_get( 'display_errors' ), 'WP_DEBUG=' . var_export( defined( 'WP_DEBUG' ) && WP_DEBUG, true ) . ' display_errors=' . ini_get( 'display_errors' ) );
$check( 'config: theme and plugin file editor disabled', defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT && ! current_user_can( 'edit_plugins' ) );
$admins = get_users( array( 'role' => 'administrator', 'fields' => array( 'ID', 'user_login' ) ) );
$check( 'accounts: one WordPress administrator (the owner)', 1 === count( $admins ) && 'bsa' === $admins[0]->user_login, implode( ', ', wp_list_pluck( $admins, 'user_login' ) ) );
$check( 'accounts: two-factor on for the administrator', class_exists( 'Two_Factor_Core' ) && Two_Factor_Core::is_user_using_two_factor( (int) $admins[0]->ID ) );
$check( 'login: lockout plugin active', is_plugin_active( 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php' ) );
global $wp_version;
require_once ABSPATH . 'wp-admin/includes/update.php';
wp_version_check();
wp_update_plugins();
wp_update_themes();
$core    = get_core_updates();
$plugins = get_plugin_updates();
$themes  = get_theme_updates();
$info['versions'] = 'WordPress ' . $wp_version . ', PHP ' . PHP_VERSION . ', Weblizar ' . ( defined( 'WLSM_VERSION' ) ? WLSM_VERSION : '?' ) . ', BSA plugin ' . BSA_SM_VERSION . ', theme ' . wp_get_theme()->get( 'Version' );
$info['updates']  = 'core: ' . ( ( $core && 'upgrade' === $core[0]->response ) ? $core[0]->current : 'none' ) . '; plugins: ' . ( $plugins ? implode( ', ', array_map( static function ( $p ) { return $p->Name . ' ' . $p->Version . '→' . $p->update->new_version; }, $plugins ) ) : 'none' ) . '; themes: ' . ( $themes ? implode( ', ', array_keys( $themes ) ) : 'none' );
// PHP error logs: lines from the last 7 days mentioning BSA code or fatal errors.
$logs  = array_filter( array_merge( glob( ABSPATH . 'error_log' ) ?: array(), glob( ABSPATH . 'wp-admin/error_log' ) ?: array(), glob( WP_CONTENT_DIR . '/debug.log' ) ?: array(), glob( dirname( ABSPATH ) . '/logs/*error*' ) ?: array() ) );
$lines = array();
foreach ( $logs as $log ) {
	$tail = array_slice( file( $log, FILE_IGNORE_NEW_LINES ) ?: array(), -400 );
	foreach ( $tail as $line ) {
		if ( preg_match( '/\[(\d{2}-\w{3}-\d{4})/', $line, $m ) && strtotime( $m[1] ) < time() - 7 * DAY_IN_SECONDS ) {
			continue;
		}
		if ( preg_match( '/bsa-school-management|bsa-portal|PHP Fatal/i', $line ) ) {
			$lines[] = basename( dirname( $log ) ) . '/' . basename( $log ) . ': ' . substr( $line, 0, 220 );
		}
	}
}
$info['error logs'] = $logs ? implode( ', ', array_map( 'basename', $logs ) ) : 'none found';
$check( 'error logs: no BSA or fatal errors in the last 7 days', ! $lines, implode( ' | ', array_slice( array_unique( $lines ), -5 ) ) );
$last = get_option( 'updraft_last_backup' );
$when = is_array( $last ) && ! empty( $last['backup_time'] ) ? (int) $last['backup_time'] : 0;
$check( 'backups: UpdraftPlus completed a backup in the last 8 days', $when > time() - 8 * DAY_IN_SECONDS, $when ? gmdate( 'Y-m-d H:i', $when ) . ' UTC' : 'none recorded' );
$info['backup'] = $when ? gmdate( 'Y-m-d H:i', $when ) . ' UTC, errors: ' . ( is_array( $last ) && ! empty( $last['errors'] ) ? wp_json_encode( $last['errors'] ) : 'none' ) . ', next database backup: ' . ( wp_next_scheduled( 'updraft_backup_database' ) ? gmdate( 'Y-m-d H:i', wp_next_scheduled( 'updraft_backup_database' ) ) . ' UTC' : 'not scheduled' ) . ', next files backup: ' . ( wp_next_scheduled( 'updraft_backup' ) ? gmdate( 'Y-m-d H:i', wp_next_scheduled( 'updraft_backup' ) ) . ' UTC' : 'not scheduled' ) : 'none';
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'bsa_' ) . '%' ) );
$info['bsa tables'] = implode( ', ', $tables );
$check( 'backups: BSA tables use the site prefix (included in UpdraftPlus database backups)', 4 <= count( $tables ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_security', array( 'results' => $results, 'info' => $info ), HOUR_IN_SECONDS );
return array( 'summary' => array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) ), 'info' => $info );
