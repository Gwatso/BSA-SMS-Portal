<?php
/**
 * Over-HTTP checks of the branded staff area (plugin 0.9.0), after going live. Run through
 * Novamira `execute-php` (send everything after the opening tag). Read-only: sign-in
 * sessions are created for the run and destroyed afterwards. Results are also kept for an
 * hour in the `bsa_test_staff_area_http` transient.
 *
 * @package BSA_School_Management
 */

$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$users  = array();
$tokens = array();
foreach ( array( 'owner' => 'bsa', 'head' => 'Masango', 'teacher' => 'hlabati.w', 'bursar' => 'bursar' ) as $role => $login ) {
	$u               = get_user_by( 'login', $login );
	$users[ $role ]  = (int) $u->ID;
	$exp             = time() + 600;
	$tok             = WP_Session_Tokens::get_instance( $u->ID )->create( $exp );
	$tokens[ $role ] = array( $tok, $exp );
}
$as = function ( $role, $url ) use ( $users, $tokens ) {
	$id  = $users[ $role ];
	list( $tok, $exp ) = $tokens[ $role ];
	$r   = wp_remote_get(
		$url,
		array(
			'timeout'     => 30,
			'redirection' => 0,
			'sslverify'   => false,
			'cookies'     => array(
				new WP_Http_Cookie( array( 'name' => SECURE_AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $id, $exp, 'secure_auth', $tok ) ) ),
				new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $id, $exp, 'logged_in', $tok ) ) ),
			),
		)
	);
	return array( 'code' => (int) wp_remote_retrieve_response_code( $r ), 'location' => (string) wp_remote_retrieve_header( $r, 'location' ), 'body' => (string) wp_remote_retrieve_body( $r ) );
};
// Visible text: no scripts, styles or HTML comments.
$visible = function ( $html ) {
	$html = preg_replace( '/<(script|style|noscript)\b[^>]*>.*?<\/\1>|<!--.*?-->/is', ' ', $html );
	return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES );
};
$wp_words = function ( $html ) use ( $visible ) {
	$text = $visible( $html );
	$out  = array();
	$pos  = 0;
	while ( false !== ( $pos = stripos( $text, 'wordpress', $pos ) ) && count( $out ) < 3 ) {
		$out[] = trim( preg_replace( '/\s+/', ' ', substr( $text, max( 0, $pos - 60 ), 120 ) ) );
		$pos  += 9;
	}
	if ( preg_match( '/<title>([^<]*WordPress[^<]*)<\/title>/i', $html, $m ) ) {
		$out[] = 'title: ' . $m[1];
	}
	return $out;
};
$php_error = function ( $html ) {
	return (bool) preg_match( '/<b>(Warning|Notice|Fatal error|Deprecated)<\/b>|critical error/', $html );
};

$start = array(
	'head'    => admin_url( 'admin.php?page=bsa-dashboard' ),
	'teacher' => admin_url( 'admin.php?page=bsa-home' ),
	'bursar'  => admin_url( 'admin.php?page=bsa-home' ),
);
$pages = array(
	'head'    => array( 'admin.php?page=bsa-dashboard', 'admin.php?page=sm-staff-students', 'admin.php?page=bsa-marks', 'profile.php' ),
	'teacher' => array( 'admin.php?page=bsa-home', 'admin.php?page=bsa-class-register', 'admin.php?page=sm-staff-attendance', 'profile.php' ),
	'bursar'  => array( 'admin.php?page=bsa-home', 'admin.php?page=sm-staff-invoices', 'admin.php?page=bsa-fee-reports', 'profile.php' ),
);
foreach ( $start as $role => $home ) {
	$r = $as( $role, admin_url() );
	$check( "$role: the WordPress Dashboard sends them to their home screen", 302 === $r['code'] && $home === $r['location'], $r['code'] . ' ' . $r['location'] );
	foreach ( $pages[ $role ] as $path ) {
		$r     = $as( $role, admin_url( $path ) );
		$found = $wp_words( $r['body'] );
		$check( "$role: $path opens with no WordPress anywhere on screen", 200 === $r['code'] && ! $found && ! $php_error( $r['body'] ), $r['code'] . ' ' . implode( ' | ', $found ) );
		if ( 0 === strpos( $path, 'admin.php?page=bsa-' ) && 'admin.php?page=bsa-marks' !== $path ) {
			$body = $r['body'];
			$check(
				"$role: $path has the BSA toolbar, menu and footer",
				false !== strpos( $body, 'wp-admin-bar-bsa-brand' ) && false === strpos( $body, 'wp-admin-bar-wp-logo' ) && false === strpos( $body, 'Howdy' ) && false !== strpos( $body, 'Passion for Excellence' ) && false !== strpos( $body, 'id="bsa-staff-area"' ) && ! preg_match( '/<div class="wp-menu-name">SM /', $body ) && false === strpos( $body, 'menu-dashboard' )
			);
		}
	}
}
$r = $as( 'teacher', admin_url( 'admin.php?page=bsa-home' ) );
$check( 'teacher: home shows my class and the greeting', false !== strpos( $r['body'], 'My class: Form 1 A' ) && 1 === preg_match( '/<h1>Good (morning|afternoon|evening),/', $r['body'] ) );
$check( 'teacher: menu has Home, Class Register, Marks, Report Cards, Academics, My profile; not Library', false !== strpos( $r['body'], '>Home<' ) && false !== strpos( $r['body'], '>Academics<' ) && false !== strpos( $r['body'], '>My profile<' ) && false === strpos( $r['body'], '>Library<' ) );
$r = $as( 'bursar', admin_url( 'admin.php?page=bsa-home' ) );
$check( 'bursar: home shows fees', false !== strpos( $r['body'], 'Collected this year' ) && false !== strpos( $r['body'], 'Largest balances' ) );
$check( 'bursar: menu has Accounts and Fee Reports, no Learners', false !== strpos( $r['body'], '>Accounts<' ) && false !== strpos( $r['body'], '>Fee Reports<' ) && false === strpos( $r['body'], '>Learners<' ) );
$r = $as( 'head', admin_url( 'admin.php?page=bsa-dashboard' ) );
$check( 'school administrator: no separate Home entry; Learners, Accounts, Staff menus', false === strpos( $r['body'], 'page=bsa-home' ) && false !== strpos( $r['body'], '>Learners<' ) && false !== strpos( $r['body'], '>Staff<' ) );
$r = $as( 'head', admin_url( 'profile.php' ) );
$check( 'profile: no application passwords or colour scheme for staff', false === strpos( $r['body'], 'application-passwords-section' ) && false === strpos( $r['body'], 'color-picker' ) );

$r = $as( 'owner', admin_url() );
$check( 'owner: standard WordPress Dashboard and toolbar kept', 200 === $r['code'] && false !== strpos( $r['body'], 'wp-admin-bar-wp-logo' ) && false === strpos( $r['body'], 'id="bsa-staff-area"' ) );
$r = $as( 'teacher', home_url( '/' ) );
$check( 'site: staff toolbar on the home page has the crest, not the WordPress logo', 200 === $r['code'] && false !== strpos( $r['body'], 'wp-admin-bar-bsa-brand' ) && false === strpos( $r['body'], 'wp-admin-bar-wp-logo' ) && false !== strpos( $r['body'], 'id="bsa-staff-toolbar"' ) );
$l = wp_remote_get( wp_login_url(), array( 'sslverify' => false ) );
$check( 'sign-in page: no WordPress in the title or text', ! $wp_words( wp_remote_retrieve_body( $l ) ), implode( ' | ', $wp_words( wp_remote_retrieve_body( $l ) ) ) );

foreach ( $tokens as $role => $t ) {
	WP_Session_Tokens::get_instance( $users[ $role ] )->destroy( $t[0] );
}

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_staff_area_http', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
