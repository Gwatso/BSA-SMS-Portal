<?php
/**
 * BSA Portal child theme.
 *
 * Brand source: docs/DESIGN.md (captured from bindurascienceacademy.co.zw and the
 * official crest). Colours are set in Blocksy's own palette (Customizer); this file adds
 * the self-hosted typefaces, component styles and the branded login screen.
 *
 * @package BSA_Portal
 */

defined( 'ABSPATH' ) || exit;

define( 'BSA_PORTAL_VERSION', '0.1.2' );

/**
 * URL of a file inside this child theme.
 *
 * @param string $path Path relative to the theme root.
 * @return string
 */
function bsa_portal_asset( $path ) {
	return get_stylesheet_directory_uri() . '/' . ltrim( $path, '/' );
}

/**
 * Front end: fonts and brand styles, after Blocksy's own stylesheet.
 */
function bsa_portal_enqueue() {
	wp_enqueue_style( 'bsa-portal-fonts', bsa_portal_asset( 'assets/fonts/fonts.css' ), array(), BSA_PORTAL_VERSION );
	$deps = wp_style_is( 'ct-main-styles', 'registered' ) ? array( 'ct-main-styles', 'bsa-portal-fonts' ) : array( 'bsa-portal-fonts' );
	wp_enqueue_style( 'bsa-portal-brand', bsa_portal_asset( 'assets/css/brand.css' ), $deps, BSA_PORTAL_VERSION );
}
add_action( 'wp_enqueue_scripts', 'bsa_portal_enqueue', 20 );

/**
 * Preload the two faces used above the fold.
 */
function bsa_portal_preload_fonts() {
	foreach ( array( 'plus-jakarta-sans-latin.woff2', 'playfair-display-latin.woff2' ) as $file ) {
		printf(
			'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
			esc_url( bsa_portal_asset( 'assets/fonts/' . $file ) )
		);
	}
}
add_action( 'wp_head', 'bsa_portal_preload_fonts', 1 );
add_action( 'login_head', 'bsa_portal_preload_fonts', 1 );

/**
 * Login screen: brand styles and the school crest from the Media Library.
 */
function bsa_portal_login_enqueue() {
	wp_enqueue_style( 'bsa-portal-fonts', bsa_portal_asset( 'assets/fonts/fonts.css' ), array(), BSA_PORTAL_VERSION );
	wp_enqueue_style( 'bsa-portal-login', bsa_portal_asset( 'assets/css/login.css' ), array( 'login', 'bsa-portal-fonts' ), BSA_PORTAL_VERSION );

	$logo_id  = (int) get_theme_mod( 'custom_logo' );
	$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
	if ( $logo_url ) {
		wp_add_inline_style( 'bsa-portal-login', sprintf( '.login h1 a { background-image: url("%s"); }', esc_url( $logo_url ) ) );
	}
}
add_action( 'login_enqueue_scripts', 'bsa_portal_login_enqueue' );

/**
 * Replace Blocksy's default footer credit ("WordPress Theme by {theme_author}", which
 * would credit the school as theme author). Only the untouched default is changed;
 * text entered in the Customizer is left alone.
 *
 * @param string $translation Translated text.
 * @param string $text        Original text.
 * @param string $domain      Text domain.
 * @return string
 */
function bsa_portal_footer_credit( $translation, $text, $domain ) {
	if ( 'blocksy' === $domain && 'Copyright &copy; {current_year} - WordPress Theme by {theme_author}' === $text ) {
		return 'Copyright &copy; {current_year} Bindura Science Academy';
	}
	return $translation;
}
add_filter( 'gettext', 'bsa_portal_footer_credit', 10, 3 );

add_filter(
	'login_headerurl',
	static function () {
		return home_url( '/' );
	}
);

add_filter(
	'login_headertext',
	static function () {
		return __( 'Bindura Science Academy', 'bsa-portal' );
	}
);

/**
 * One line of context above the sign-in form (sign-in screen only).
 *
 * @param string $message Existing login message.
 * @return string
 */
function bsa_portal_login_message( $message ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';
	if ( 'login' !== $action || '' !== $message ) {
		return $message;
	}
	return '<p class="bsa-login-intro">' . esc_html__( 'School portal. Passion for Excellence.', 'bsa-portal' ) . '</p>';
}
add_filter( 'login_message', 'bsa_portal_login_message' );
