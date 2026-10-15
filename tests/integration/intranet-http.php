<?php
/**
 * Over-HTTP checks of the intranet (plugin 0.11.0), after going live. Run through
 * Novamira `execute-php` (send everything after the opening tag). Sign-in sessions are
 * created for the run and destroyed afterwards. One notice titled "(test)" is published
 * for a few seconds to test acknowledgement through the real form, then deleted.
 * Results are also kept for an hour in the `bsa_test_intranet_http` transient.
 *
 * @package BSA_School_Management
 */

$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$users  = array();
$tokens = array();
foreach ( array( 'owner' => 'bsa', 'head' => 'Masango', 'teacher' => 'hlabati.w', 'bursar' => 'bursar', 'parent' => 'bsa001.parent', 'learner' => 'bsa001' ) as $role => $login ) {
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
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'location' => (string) wp_remote_retrieve_header( $r, 'location' ), 'body' => (string) wp_remote_retrieve_body( $r ), 'cache' => (string) wp_remote_retrieve_header( $r, 'cache-control' ) );
};
$php_error = function ( $html ) {
	return (bool) preg_match( '/<b>(Warning|Notice|Fatal error|Deprecated)<\/b>|critical error/', $html );
};
$visible_wp = function ( $html ) {
	$text = html_entity_decode( wp_strip_all_tags( preg_replace( '/<(script|style|noscript)\b[^>]*>.*?<\/\1>|<!--.*?-->/is', ' ', $html ) ), ENT_QUOTES );
	return false !== stripos( $text, 'wordpress' );
};

$r = $req( '', home_url( '/intranet' ) );
$check( 'visitor: sent to sign-in, coming back to /intranet', 302 === $r['code'] && false !== strpos( $r['location'], 'wp-login.php' ) && false !== strpos( $r['location'], 'redirect_to=' . rawurlencode( home_url( '/intranet' ) ) . '&' ), $r['code'] . ' ' . $r['location'] );
$r = $req( 'teacher', home_url( '/intranet/' ) );
$check( 'old address with a slash: 301 to /intranet', 301 === $r['code'] && home_url( '/intranet' ) === $r['location'], $r['code'] . ' ' . $r['location'] );
$r = $req( 'teacher', home_url( '/intranet/resources/?category=Templates' ) );
$check( 'old sub-address with a slash keeps its query: 301 to /intranet/resources?category=Templates', 301 === $r['code'] && home_url( '/intranet/resources?category=Templates' ) === $r['location'], $r['code'] . ' ' . $r['location'] );
foreach ( array( 'parent', 'learner' ) as $role ) {
	$r = $req( $role, home_url( '/intranet' ) );
	$check( "$role: sent to My Account", 302 === $r['code'] && false !== strpos( $r['location'], '/account' ), $r['code'] . ' ' . $r['location'] );
}
foreach ( array( 'teacher', 'bursar', 'head', 'owner' ) as $role ) {
	$r = $req( $role, home_url( '/intranet' ) );
	$check( "$role: intranet opens with the theme, styles and script, no PHP errors, not cached", 200 === $r['code'] && false !== strpos( $r['body'], 'class="bsi"' ) && false !== strpos( $r['body'], 'assets/intranet.css' ) && false !== strpos( $r['body'], 'assets/intranet.js' ) && false !== strpos( $r['body'], 'BSA Intranet' ) && ! $php_error( $r['body'] ) && false !== stripos( $r['cache'], 'no-cache' ), $r['code'] . ' ' . $r['cache'] );
	if ( 'owner' !== $role ) {
		$check( "$role: no \"WordPress\" visible", ! $visible_wp( $r['body'] ) );
	}
}
foreach ( array( 'news', 'notices', 'achievements', 'resources', 'calendar' ) as $view ) {
	$r = $req( 'teacher', home_url( '/intranet/' . $view ) );
	$check( "teacher: /intranet/$view opens", 200 === $r['code'] && ! $php_error( $r['body'] ) && false !== strpos( $r['body'], 'aria-current="page"' ), (string) $r['code'] );
}
$r = $req( 'teacher', home_url( '/intranet/search?q=term' ) );
$check( 'teacher: search opens', 200 === $r['code'] && false !== strpos( $r['body'], 'Search' ) );
$r = $req( 'teacher', home_url( '/intranet/item/999999999' ) );
$check( 'teacher: unknown item is a 404 page inside the intranet', 404 === $r['code'] && false !== strpos( $r['body'], 'Not found' ), (string) $r['code'] );

// wp-admin and REST.
$r = $req( 'head', admin_url( 'post-new.php?post_type=bsa_notice' ) );
$check( 'school administrator: can open "Add notice"', 200 === $r['code'] && false !== strpos( $r['body'], 'Intranet details' ) && false !== strpos( $r['body'], 'Staff must acknowledge this notice' ), (string) $r['code'] );
$r = $req( 'head', admin_url( 'edit.php?post_type=bsa_resource' ) );
$check( 'school administrator: can open the Resources list', 200 === $r['code'] && false !== strpos( $r['body'], 'post-new.php?post_type=bsa_resource' ), (string) $r['code'] );
$r = $req( 'head', admin_url( 'admin.php?page=bsa-intranet' ) );
$check( 'school administrator: intranet overview with counts and receipts', 200 === $r['code'] && false !== strpos( $r['body'], 'Notices to acknowledge' ) && false !== strpos( $r['body'], 'Open the intranet' ), (string) $r['code'] );
$r = $req( 'teacher', admin_url( 'post-new.php?post_type=bsa_notice' ) );
$check( 'teacher: cannot add notices', 200 !== $r['code'] || false === strpos( $r['body'], 'Intranet details' ), (string) $r['code'] );
$r = $req( 'teacher', admin_url( 'admin.php?page=bsa-home' ) );
$check( 'teacher: "Intranet" in the menu', false !== strpos( $r['body'], "href='" . home_url( '/intranet' ) . "'" ) || false !== strpos( $r['body'], 'href="' . home_url( '/intranet' ) . '"' ) );
$r = $req( '', rest_url( 'wp/v2/bsa_notice' ) );
$check( 'REST: intranet content not exposed', 404 === $r['code'], (string) $r['code'] );

// Acknowledge through the real form.
$notice = wp_insert_post( array( 'post_type' => 'bsa_notice', 'post_title' => 'Intranet acknowledgement check (test)', 'post_content' => 'Automated check; deleted straight away.', 'post_status' => 'publish', 'post_author' => $users['head'] ) );
update_post_meta( $notice, '_bsa_ack', '1' );
update_post_meta( $notice, '_bsa_urgency', 'normal' );
try {
	$page = $req( 'teacher', home_url( '/intranet/item/' . $notice ) );
	preg_match( '/name="_wpnonce" value="([^"]+)"/', $page['body'], $m );
	$check( 'acknowledge: the notice page shows the button', 200 === $page['code'] && ! empty( $m[1] ) );
	$r = $req( 'teacher', admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_intranet_ack', 'notice' => $notice, '_wpnonce' => $m[1] ?? '' ) ) );
	$check( 'acknowledge: recorded and back to the notice with thanks', 302 === $r['code'] && home_url( '/intranet/item/' . $notice . '?done=ack' ) === $r['location'] && '' !== (string) get_post_meta( $notice, '_bsa_ack_' . $users['teacher'], true ), $r['code'] . ' ' . $r['location'] );
	$r = $req( 'bursar', admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'bsa_intranet_ack', 'notice' => $notice, '_wpnonce' => 'forged' ) ) );
	$check( 'acknowledge: a forged request is refused', 403 === $r['code'] && '' === (string) get_post_meta( $notice, '_bsa_ack_' . $users['bursar'], true ), (string) $r['code'] );
	$r = $req( 'head', home_url( '/intranet/item/' . $notice ) );
	$check( 'receipts: the school administrator sees who acknowledged', false !== strpos( $r['body'], 'Read receipts' ) && false !== strpos( $r['body'], 'Acknowledged by 1 of' ) );
	$r = $req( 'head', admin_url( 'post.php?action=edit&post=' . $notice ) );
	$check( 'school administrator: can edit the notice, with read receipts in the editor', 200 === $r['code'] && false !== strpos( $r['body'], 'Intranet details' ) && false !== strpos( $r['body'], 'Acknowledged by 1 of' ), (string) $r['code'] );
	$r = $req( 'teacher', admin_url( 'post.php?action=edit&post=' . $notice ) );
	$check( 'teacher: cannot edit the notice', 200 !== $r['code'] || false === strpos( $r['body'], 'Intranet details' ), (string) $r['code'] );
} finally {
	wp_delete_post( $notice, true );
}
$check( 'clean-up: the test notice is gone', null === get_post( $notice ) );

foreach ( $tokens as $role => $t ) {
	WP_Session_Tokens::get_instance( $users[ $role ] )->destroy( $t[0] );
}
$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_intranet_http', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
