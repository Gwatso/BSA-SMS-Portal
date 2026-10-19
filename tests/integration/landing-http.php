<?php
/**
 * Over-HTTP checks of where people land (plugin 0.14.1, owner request 2026-10-16): after
 * signing in, staff go straight to their own dashboard and the site menu's "My Account"
 * is "My Dashboard" for them; learners and parents go to My Account. Also the menu's
 * Intranet link (plugin 0.16.4): staff and visitors see it, signed-in parents do not. Run through
 * Novamira `execute-php` (send everything after the opening tag). Sign-in sessions are
 * created for the run and destroyed afterwards; the real sign-in form is posted only
 * for the sample parent with a throwaway password that is restored at the end.
 * Results are also kept for an hour in the `bsa_test_landing_http` transient.
 *
 * @package BSA_School_Management
 */

$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$users  = array();
$tokens = array();
foreach ( array( 'head' => 'Masango', 'teacher' => 'hlabati.w', 'bursar' => 'bursar', 'parent' => 'bsa001.parent' ) as $role => $login ) {
	$u               = get_user_by( 'login', $login );
	$users[ $role ]  = (int) $u->ID;
	$exp             = time() + 600;
	$tokens[ $role ] = array( WP_Session_Tokens::get_instance( $u->ID )->create( $exp ), $exp );
}
$req = function ( $role, $url ) use ( $users, $tokens ) {
	list( $tok, $exp ) = $tokens[ $role ];
	$cookies           = array(
		new WP_Http_Cookie( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $users[ $role ], $exp, 'secure_auth', $tok ) ) ),
		new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $users[ $role ], $exp, 'logged_in', $tok ) ) ),
	);
	$r = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => false, 'cookies' => $cookies ) );
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'location' => (string) wp_remote_retrieve_header( $r, 'location' ), 'body' => (string) wp_remote_retrieve_body( $r ) );
};
$account = BSA_Portal::account_url();
try {
	foreach ( array( 'head' => 'bsa-dashboard', 'teacher' => 'bsa-home', 'bursar' => 'bsa-home' ) as $role => $page ) {
		$want = admin_url( 'admin.php?page=' . $page );
		$check( "$role: sent to their dashboard after signing in", $want === BSA_Portal::login_redirect( admin_url(), '', get_userdata( $users[ $role ] ) ) );
		$r = $req( $role, $account );
		$check( "$role: My Account sends them to their dashboard", 302 === $r['code'] && $want === $r['location'], $r['code'] . ' ' . $r['location'] );
		$r = $req( $role, home_url( '/' ) );
		$check( "$role: the site menu says My Dashboard, to their dashboard", 200 === $r['code'] && false !== strpos( $r['body'], 'My Dashboard' ) && false !== strpos( $r['body'], 'href="' . esc_url( $want ) . '"' ), (string) $r['code'] );
	}
	$check( 'parent: sent to My Account after signing in', $account === BSA_Portal::login_redirect( admin_url(), '', get_userdata( $users['parent'] ) ) );
	$r = $req( 'parent', $account );
	$check( 'parent: My Account opens with their child, menu still says My Account', 200 === $r['code'] && false !== strpos( $r['body'], 'Trish' ) && false === strpos( $r['body'], 'My Dashboard' ) && false === strpos( $r['body'], 'There is no student or parent record' ), (string) $r['code'] );

	// The Intranet link in the site menu (plugin 0.16.4): staff and visitors, not families.
	$in_menu = function ( $html ) {
		return (bool) preg_match( '#<li[^>]*menu-item[^>]*>\s*<a[^>]*href="' . preg_quote( esc_url( home_url( '/intranet' ) ), '#' ) . '"#', $html );
	};
	$r = $req( 'teacher', home_url( '/' ) );
	$check( 'menu: Intranet for staff', $in_menu( $r['body'] ) );
	$r = wp_remote_get( home_url( '/' ), array( 'timeout' => 30, 'sslverify' => false ) );
	$check( 'menu: Intranet for visitors (staff tap it and sign in)', $in_menu( (string) wp_remote_retrieve_body( $r ) ) );
	$r = $req( 'parent', $account );
	$check( 'menu: no Intranet for a signed-in parent', 200 === $r['code'] && ! $in_menu( $r['body'] ) && false !== strpos( $r['body'], 'My Account' ) );

	// The real sign-in form, as the sample parent, with a throwaway password.
	$u    = get_userdata( $users['parent'] );
	$hash = $u->user_pass;
	$pass = wp_generate_password( 20, false );
	wp_set_password( $pass, $u->ID );
	try {
		$r = wp_remote_post( wp_login_url(), array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => false, 'cookies' => array( new WP_Http_Cookie( array( 'name' => 'wordpress_test_cookie', 'value' => 'WP Cookie check' ) ) ), 'body' => array( 'log' => $u->user_login, 'pwd' => $pass, 'wp-submit' => 'Log In', 'testcookie' => '1' ) ) );
		$check( 'sign-in form: the sample parent lands on My Account', 302 === (int) wp_remote_retrieve_response_code( $r ) && $account === (string) wp_remote_retrieve_header( $r, 'location' ), wp_remote_retrieve_response_code( $r ) . ' ' . wp_remote_retrieve_header( $r, 'location' ) );
	} finally {
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'user_pass' => $hash ), array( 'ID' => $u->ID ) );
		clean_user_cache( $u->ID );
		WP_Session_Tokens::get_instance( $u->ID )->destroy_all();
	}
	$check( 'sign-in form: the parent\'s original password restored', get_userdata( $users['parent'] )->user_pass === $hash );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() );
} finally {
	foreach ( $tokens as $role => $t ) {
		WP_Session_Tokens::get_instance( $users[ $role ] )->destroy( $t[0] );
	}
}
$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_landing_http', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
