<?php
/**
 * Clean addresses (plugin 0.15.0): /staff for the staff area and /sign-in for signing in.
 * Run through Novamira `execute-php` (send everything after the opening tag) after
 * scripts/12-clean-urls.php has switched them on.
 *
 * Address helpers are checked without the network; then, over HTTP: visitors, the
 * school administrator, a teacher and the sample parent, old addresses redirected, and
 * a real sign-in and sign-out through /sign-in as the placeholder bursar account with a
 * throwaway password (its original password is restored at the end). Sign-in sessions
 * made for the run are destroyed. Results are also kept for an hour in the
 * `bsa_test_clean_urls_http` transient.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$home = home_url();

// Helpers.
$check( 'switched on', BSA_Clean_Urls::enabled() );
$cases = array(
	$home . '/wp-admin/admin.php?page=bsa-dashboard'               => $home . '/staff/dashboard',
	$home . '/wp-admin/admin.php?page=bsa-results&tab=risk&term=1' => $home . '/staff/results?tab=risk&term=1',
	$home . '/wp-admin/admin.php?tab=x&page=bsa-marks'              => $home . '/staff/marks?tab=x',
	$home . '/wp-admin/admin.php?page=sm-staff-student'             => $home . '/staff/admin.php?page=sm-staff-student',
	$home . '/wp-admin/admin-ajax.php'                              => $home . '/staff/admin-ajax.php',
	$home . '/wp-admin/'                                            => $home . '/staff/',
	$home . '/wp-admin'                                             => $home . '/staff',
	$home . '/intranet'                                             => $home . '/intranet',
	'https://example.com/wp-admin/admin.php?page=bsa-dashboard'     => 'https://example.com/wp-admin/admin.php?page=bsa-dashboard',
);
$bad = array();
foreach ( $cases as $in => $want ) {
	if ( BSA_Clean_Urls::pretty( $in ) !== $want ) {
		$bad[] = $in . ' → ' . BSA_Clean_Urls::pretty( $in );
	}
}
$check( 'addresses: /wp-admin → /staff, BSA screens shortest, other sites untouched', ! $bad, implode( ' | ', $bad ) );
$check( 'links: admin_url(), sign-in, sign-out and lost password use the new addresses', $home . '/staff/dashboard' === admin_url( 'admin.php?page=bsa-dashboard' ) && 0 === strpos( wp_login_url(), $home . '/sign-in' ) && false !== strpos( wp_logout_url(), '/sign-in?action=logout' ) && false !== strpos( wp_lostpassword_url(), '/sign-in?action=lostpassword' ) );
$sample = "# BEGIN cPanel\nx\n# END cPanel\n\n# BEGIN WordPress\ny\n# END WordPress\n";
$with   = BSA_Clean_Urls::with_block( $sample );
$check( '.htaccess: block before WordPress\'s rules, added once, removed exactly', strpos( $with, '# BEGIN BSA clean addresses' ) < strpos( $with, '# BEGIN WordPress' ) && 1 === substr_count( BSA_Clean_Urls::with_block( $with ), '# BEGIN BSA clean addresses' ) && $sample === BSA_Clean_Urls::without_block( $with ) && false !== strpos( $with, 'RewriteRule ^staff/([a-z0-9-]+)/?$ /wp-admin/admin.php?page=bsa-$1' ) );
$live = (string) file_get_contents( ABSPATH . '.htaccess' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$check( '.htaccess on the site: block present once, before WordPress\'s rules', 1 === substr_count( $live, '# BEGIN BSA clean addresses' ) && strpos( $live, '# BEGIN BSA clean addresses' ) < strpos( $live, '# BEGIN WordPress' ) );

// HTTP.
$users  = array();
$tokens = array();
foreach ( array( 'head' => 'Masango', 'teacher' => 'hlabati.w', 'parent' => 'bsa001.parent' ) as $role => $login ) {
	$u               = get_user_by( 'login', $login );
	$users[ $role ]  = (int) $u->ID;
	$exp             = time() + 600;
	$tokens[ $role ] = array( WP_Session_Tokens::get_instance( $u->ID )->create( $exp ), $exp );
}
$req = function ( $role, $url, array $args = array() ) use ( $users, $tokens ) {
	$cookies = $args['cookies'] ?? array();
	if ( $role ) {
		list( $tok, $exp ) = $tokens[ $role ];
		$cookies           = array(
			new WP_Http_Cookie( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $users[ $role ], $exp, 'secure_auth', $tok ) ) ),
			new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $users[ $role ], $exp, 'logged_in', $tok ) ) ),
		);
	}
	$r = wp_remote_request( $url, array_merge( array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => false ), $args, array( 'cookies' => $cookies ) ) );
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'location' => (string) wp_remote_retrieve_header( $r, 'location' ), 'body' => (string) wp_remote_retrieve_body( $r ), 'set' => (array) wp_remote_retrieve_header( $r, 'set-cookie' ), 'cookies' => wp_remote_retrieve_cookies( $r ) );
};
$php_error = function ( $html ) {
	return (bool) preg_match( '/<b>(Warning|Notice|Fatal error|Deprecated)<\/b>|critical error/', $html );
};
$bursar = get_user_by( 'login', 'bursar' );
$hash   = $bursar->user_pass;
try {
	$r = $req( '', $home . '/sign-in' );
	$check( 'visitor: /sign-in shows the sign-in form posting to /sign-in', 200 === $r['code'] && false !== strpos( $r['body'], 'name="log"' ) && false !== strpos( $r['body'], 'action="' . esc_url( $home . '/sign-in' ) ) && ! $php_error( $r['body'] ), (string) $r['code'] );
	$r = $req( '', $home . '/wp-login.php?action=lostpassword' );
	$check( 'visitor: the old sign-in address redirects to /sign-in, keeping what was asked', 301 === $r['code'] && $home . '/sign-in?action=lostpassword' === $r['location'], $r['code'] . ' ' . $r['location'] );
	$r = $req( '', $home . '/staff/dashboard' );
	$check( 'visitor: /staff/dashboard sends to /sign-in and back', 302 === $r['code'] && 0 === strpos( $r['location'], $home . '/sign-in?redirect_to=' . rawurlencode( $home . '/staff/dashboard' ) ), $r['location'] );

	$r = $req( 'head', $home . '/staff/dashboard' );
	$check( 'school administrator: /staff/dashboard opens, no PHP errors', 200 === $r['code'] && false !== strpos( $r['body'], 'School Dashboard' ) && ! $php_error( $r['body'] ), (string) $r['code'] );
	$check( 'school administrator: no wp-admin or wp-login.php in the screen\'s links', ! preg_match( '#<a\s[^>]*href=["\'](https?://[^"\']+)?/wp-(admin|login\.php)#', $r['body'] ), (string) ( preg_match( '#<a\s[^>]*href=["\'][^"\']*/wp-(admin|login\.php)[^"\']*#', $r['body'], $m ) ? $m[0] : '' ) );
	$check( 'school administrator: menu links shown as short addresses', false !== strpos( $r['body'], "querySelectorAll('a[href*=\"page=bsa-\"]')" ) );
	$r = $req( 'head', $home . '/staff/results?tab=risk' );
	$check( 'school administrator: /staff/results?tab=risk opens the at-risk tab', 200 === $r['code'] && false !== strpos( $r['body'], 'aria-current="page">Learners at risk' ), (string) $r['code'] );
	$r = $req( 'head', $home . '/staff/admin.php?page=sm-staff-student' );
	$check( 'school administrator: Weblizar screens open under /staff', 200 === $r['code'] && ! $php_error( $r['body'] ), (string) $r['code'] );
	$r = $req( 'head', $home . '/wp-admin/admin.php?page=bsa-results&tab=risk' );
	$copied = (bool) preg_grep( '#^' . preg_quote( SECURE_AUTH_COOKIE, '#' ) . '=.*path=/staff#i', $r['set'] );
	$check( 'old address: /wp-admin/…page=bsa-results redirects to /staff/results, and the sign-in carries over to /staff', 301 === $r['code'] && $home . '/staff/results?tab=risk' === $r['location'] && $copied, $r['code'] . ' ' . $r['location'] );
	$r = $req( 'head', $home . '/staff/admin.php?page=bsa-marks' );
	$check( 'long address: /staff/admin.php?page=bsa-marks redirects to /staff/marks', 301 === $r['code'] && $home . '/staff/marks' === $r['location'], $r['code'] . ' ' . $r['location'] );
	$r = $req( 'head', $home . '/staff/admin-post.php', array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_intranet_ack', 'notice' => 1, '_wpnonce' => 'forged' ) ) );
	$check( 'forms: /staff/admin-post.php reaches WordPress (a forged request is refused)', 403 === $r['code'], (string) $r['code'] );
	$r = $req( 'head', $home . '/staff/admin-ajax.php', array( 'method' => 'POST', 'body' => array( 'action' => 'heartbeat', 'interval' => 60, '_nonce' => 'x' ) ) );
	$check( 'background requests: /staff/admin-ajax.php answers', in_array( $r['code'], array( 200, 400, 403 ), true ) && ! $php_error( $r['body'] ), (string) $r['code'] );

	$r = $req( 'teacher', $home . '/staff/home' );
	$check( 'teacher: /staff/home opens his home screen', 200 === $r['code'] && false !== strpos( $r['body'], 'Class teacher, Form 1 A' ), (string) $r['code'] );
	$r = $req( 'parent', $home . '/staff/dashboard' );
	$check( 'parent: /staff sends them to My Account', 302 === $r['code'] && BSA_Portal::account_url() === $r['location'], $r['code'] . ' ' . $r['location'] );
	$r = $req( '', $home . '/intranet' );
	$check( 'intranet: visitors still go to sign in (now /sign-in)', 302 === $r['code'] && 0 === strpos( $r['location'], $home . '/sign-in' ), $r['location'] );

	// A real sign-in and sign-out through /sign-in (placeholder bursar, throwaway password).
	$pass = wp_generate_password( 20, false );
	wp_set_password( $pass, $bursar->ID );
	$r = $req( '', $home . '/sign-in', array( 'method' => 'POST', 'cookies' => array( new WP_Http_Cookie( array( 'name' => 'wordpress_test_cookie', 'value' => 'WP Cookie check' ) ) ), 'body' => array( 'log' => 'bursar', 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1' ) ) );
	$staff_cookie = (bool) preg_grep( '#^' . preg_quote( SECURE_AUTH_COOKIE, '#' ) . '=.*path=/staff#i', $r['set'] );
	$check( 'sign-in form: the bursar lands on /staff/home, signed in for /staff', 302 === $r['code'] && $home . '/staff/home' === $r['location'] && $staff_cookie, $r['code'] . ' ' . $r['location'] );
	$jar = array_values(
		array_filter(
			$r['cookies'],
			static function ( $c ) {
				return '' !== trim( (string) $c->value );
			}
		)
	);
	$p   = $req( '', $home . '/staff/home', array( 'cookies' => $jar ) );
	$check( 'sign-in form: with the cookies received, /staff/home opens', 200 === $p['code'] && false !== strpos( $p['body'], 'Bursar' ), (string) $p['code'] );
	preg_match( '#/sign-in\?action=logout(&amp;|&)_wpnonce=([a-f0-9]+)#', $p['body'], $m );
	$check( 'sign-out link uses /sign-in', ! empty( $m[2] ) );
	$o       = $req( '', $home . '/sign-in?action=logout&_wpnonce=' . ( $m[2] ?? '' ), array( 'cookies' => $jar ) );
	$cleared = (bool) preg_grep( '#^' . preg_quote( SECURE_AUTH_COOKIE, '#' ) . '=.*path=/staff#i', $o['set'] );
	$check( 'sign-out: done, and the /staff sign-in cleared', 302 === $o['code'] && $cleared, $o['code'] . ' ' . $o['location'] );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->update( $wpdb->users, array( 'user_pass' => $hash ), array( 'ID' => $bursar->ID ) );
	clean_user_cache( $bursar->ID );
	WP_Session_Tokens::get_instance( $bursar->ID )->destroy_all();
	foreach ( $tokens as $role => $t ) {
		WP_Session_Tokens::get_instance( $users[ $role ] )->destroy( $t[0] );
	}
}
$check( 'clean-up: the bursar\'s original password restored', get_userdata( $bursar->ID )->user_pass === $hash );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_clean_urls_http', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
