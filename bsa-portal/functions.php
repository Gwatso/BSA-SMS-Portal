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

define( 'BSA_PORTAL_VERSION', '0.3.0' );

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

	if ( is_page_template( 'templates/page-portal-home.php' ) ) {
		wp_enqueue_style( 'bsa-portal-home', bsa_portal_asset( 'assets/css/home.css' ), array( 'bsa-portal-brand' ), BSA_PORTAL_VERSION );
	}
	if ( bsa_portal_is_account_page() ) {
		wp_enqueue_style( 'bsa-portal-account', bsa_portal_asset( 'assets/css/account.css' ), array( 'bsa-portal-brand' ), BSA_PORTAL_VERSION );
	}
}
add_action( 'wp_enqueue_scripts', 'bsa_portal_enqueue', 20 );

/**
 * Whether the current page shows the Weblizar account area.
 *
 * @return bool
 */
function bsa_portal_is_account_page() {
	$post = get_queried_object();
	return is_singular() && $post instanceof WP_Post && has_shortcode( $post->post_content, 'school_management_account' );
}

/**
 * Body class used to scope the account styles.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function bsa_portal_body_class( $classes ) {
	if ( bsa_portal_is_account_page() ) {
		$classes[] = 'bsa-account-page';
		$classes[] = is_user_logged_in() ? 'bsa-account-signed-in' : 'bsa-account-signed-out';
	}
	return $classes;
}
add_filter( 'body_class', 'bsa_portal_body_class' );

/**
 * Below the account sign-in form: how to get an account (signed-out visitors only).
 *
 * @param string $content Page content.
 * @return string
 */
function bsa_portal_account_help( $content ) {
	if ( is_user_logged_in() || ! bsa_portal_is_account_page() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	return $content . '<p class="bsa-account-help">' . sprintf(
		/* translators: 1: school email link, 2: school phone link. */
		esc_html__( 'Accounts are created by the school office. If you cannot sign in, contact %1$s or %2$s.', 'bsa-portal' ),
		'<a href="mailto:info@bindurascienceacademy.co.zw">info@bindurascienceacademy.co.zw</a>',
		'<a href="tel:+263772609503">+263 77 260 9503</a>'
	) . '</p>';
}
add_filter( 'the_content', 'bsa_portal_account_help', 20 );

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
