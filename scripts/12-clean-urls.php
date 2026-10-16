<?php
/**
 * Step 12: clean addresses (owner, 2026-10-16): staff screens at /staff and signing in at
 * /sign-in, so "wp-admin" and "wp-login.php" no longer show in the address bar.
 *
 * Set $bsa_mode before running: 'on' (default), 'off' (links and redirects stop; the
 * rules stay, harmless) or 'remove' (off, and the .htaccess block removed).
 *
 * 'on' keeps a copy of .htaccess in $bsa_backup_dir, adds the rule block, then checks
 * over HTTP that /sign-in shows the sign-in form, that /staff sends visitors to sign in
 * and that the school administrator's /staff/dashboard opens. Only then are clean
 * addresses switched on; if a check fails the block is taken out again and nothing is
 * switched on. Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

$bsa_mode       = isset( $bsa_mode ) ? $bsa_mode : 'on';
$bsa_backup_dir = isset( $bsa_backup_dir ) ? $bsa_backup_dir : '';
$log            = array();

if ( 'off' === $bsa_mode || 'remove' === $bsa_mode ) {
	update_option( BSA_Clean_Urls::OPTION, '0', true );
	$log[] = 'Clean addresses switched off (links back to /wp-admin and /wp-login.php).';
	if ( 'remove' === $bsa_mode ) {
		$log[] = BSA_Clean_Urls::remove_rules() ? '.htaccess block removed.' : 'Could not write .htaccess.';
	}
	return $log;
}

if ( ! $bsa_backup_dir || ! wp_mkdir_p( $bsa_backup_dir ) ) {
	return array( 'Set $bsa_backup_dir to a folder for the .htaccess copy.' );
}
if ( ! BSA_Clean_Urls::install_rules( trailingslashit( $bsa_backup_dir ) . 'htaccess.bak' ) ) {
	return array( 'Could not copy or write .htaccess; nothing changed.' );
}
$log[] = 'Rule block added to .htaccess (copy in ' . $bsa_backup_dir . ').';

$get = function ( $url, array $cookies = array() ) {
	$r = wp_remote_get( $url, array( 'timeout' => 30, 'redirection' => 0, 'sslverify' => false, 'cookies' => $cookies ) );
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'location' => (string) wp_remote_retrieve_header( $r, 'location' ), 'body' => (string) wp_remote_retrieve_body( $r ) );
};
$run_checks = function () use ( $get ) {
	$checks = array();
	$r      = $get( home_url( '/sign-in' ) );
	$checks['/sign-in shows the sign-in form'] = 200 === $r['code'] && false !== strpos( $r['body'], 'name="log"' );
	$r      = $get( home_url( '/staff/' ) );
	$checks['/staff sends visitors to sign in'] = 302 === $r['code'] && false !== strpos( $r['location'], 'redirect_to=' );
	$admin = get_user_by( 'login', 'Masango' );
	if ( $admin ) {
		$exp     = time() + 300;
		$token   = WP_Session_Tokens::get_instance( $admin->ID )->create( $exp );
		$cookies = array(
			new WP_Http_Cookie( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $admin->ID, $exp, 'secure_auth', $token ) ) ),
			new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $admin->ID, $exp, 'logged_in', $token ) ) ),
		);
		$r = $get( home_url( '/staff/dashboard' ), $cookies );
		WP_Session_Tokens::get_instance( $admin->ID )->destroy( $token );
		$checks['/staff/dashboard opens for the school administrator'] = 200 === $r['code'] && false !== strpos( $r['body'], 'School Dashboard' );
	}
	return $checks;
};
// The web server can take a few seconds to notice a changed .htaccess: try up to 4 times.
for ( $try = 1; $try <= 4; $try++ ) {
	sleep( 1 === $try ? 2 : 4 );
	$checks = $run_checks();
	if ( ! in_array( false, $checks, true ) ) {
		break;
	}
}
$log[] = 'Checked after ' . min( $try, 4 ) . ' attempt(s).';
foreach ( $checks as $name => $ok ) {
	$log[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name;
}
if ( in_array( false, $checks, true ) ) {
	BSA_Clean_Urls::remove_rules();
	$log[] = 'A check failed: rule block removed again; clean addresses stay off.';
	return $log;
}
update_option( BSA_Clean_Urls::OPTION, '1', true );
$log[] = 'Clean addresses switched on.';
return $log;
