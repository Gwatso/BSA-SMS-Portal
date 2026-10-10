<?php
/**
 * Step 4: activate the BSA Portal child theme and the school's brand.
 *
 * - Adds the official crest (from bindurascienceacademy.co.zw) to the Media Library,
 *   unmodified, and uses it as the site logo.
 * - Creates a 512×512 site icon: the crest centred on a transparent square (WordPress
 *   needs a square icon; the crest itself is not altered).
 * - Copies Blocksy's existing settings to the child theme, then switches to it.
 * - Sets Blocksy's global palette to the brand colours (docs/DESIGN.md) and the tagline
 *   to the school motto, then rebuilds Blocksy's generated CSS.
 *
 * Safe to re-run. Run through Novamira `execute-php` (send everything after the opening tag).
 *
 * @package BSA_School_Management
 */

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$log      = array();
$crest    = 'https://bindurascienceacademy.co.zw/__l5e/assets-v1/7fd33cf8-d3ea-4aeb-9dc9-54bf52680d60/bsa-logo-premium.png';
$palette  = array(
	'color1' => array( 'color' => '#1B3674' ), // Navy: buttons, links (accent).
	'color2' => array( 'color' => '#132653' ), // Navy hover.
	'color3' => array( 'color' => '#343A48' ), // Body text.
	'color4' => array( 'color' => '#1D212B' ), // Headings.
	'color5' => array( 'color' => '#E5E7EB' ), // Borders.
	'color6' => array( 'color' => '#F6F7F9' ), // Surface.
	'color7' => array( 'color' => '#FAFBFC' ), // Surface alt.
	'color8' => array( 'color' => '#FFFFFF' ), // Ground.
);
$find_asset = function ( $key ) {
	$ids = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'meta_key'    => '_bsa_asset', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'  => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'      => 'ids',
			'numberposts' => 1,
		)
	);
	return $ids ? (int) $ids[0] : 0;
};

// 1. Crest in the Media Library (unmodified original).
$crest_id = $find_asset( 'crest' );
if ( ! $crest_id ) {
	$crest_id = media_sideload_image( $crest, 0, 'Bindura Science Academy crest', 'id' );
	if ( is_wp_error( $crest_id ) ) {
		return array( 'FAIL crest: ' . $crest_id->get_error_message() );
	}
	update_post_meta( $crest_id, '_bsa_asset', 'crest' );
	update_post_meta( $crest_id, '_wp_attachment_image_alt', 'Bindura Science Academy crest' );
	$log[] = "crest added (attachment $crest_id)";
} else {
	$log[] = "crest exists (attachment $crest_id)";
}

// 2. Square site icon generated from the crest.
$icon_id = $find_asset( 'site-icon' );
if ( ! $icon_id ) {
	$src    = imagecreatefrompng( get_attached_file( $crest_id ) );
	$size   = 512;
	$height = 480;
	$width  = (int) round( imagesx( $src ) * $height / imagesy( $src ) );
	$canvas = imagecreatetruecolor( $size, $size );
	imagealphablending( $canvas, false );
	imagesavealpha( $canvas, true );
	imagefill( $canvas, 0, 0, imagecolorallocatealpha( $canvas, 0, 0, 0, 127 ) );
	imagecopyresampled( $canvas, $src, (int) ( ( $size - $width ) / 2 ), (int) ( ( $size - $height ) / 2 ), 0, 0, $width, $height, imagesx( $src ), imagesy( $src ) );
	$tmp = wp_tempnam( 'bsa-site-icon.png' );
	imagepng( $canvas, $tmp, 9 );
	$icon_id = media_handle_sideload( array( 'name' => 'bsa-site-icon.png', 'tmp_name' => $tmp ), 0, 'Bindura Science Academy site icon' );
	if ( is_wp_error( $icon_id ) ) {
		return array_merge( $log, array( 'FAIL site icon: ' . $icon_id->get_error_message() ) );
	}
	update_post_meta( $icon_id, '_bsa_asset', 'site-icon' );
	update_post_meta( $icon_id, '_wp_attachment_image_alt', 'Bindura Science Academy crest' );
	$log[] = "site icon added (attachment $icon_id)";
}
update_option( 'site_icon', $icon_id );

// 3. Child theme with Blocksy's existing settings carried over.
if ( 'bsa-portal' !== get_stylesheet() ) {
	if ( false === get_option( 'theme_mods_bsa-portal' ) ) {
		update_option( 'theme_mods_bsa-portal', get_option( 'theme_mods_blocksy', array() ) );
	}
	switch_theme( 'bsa-portal' );
	$log[] = 'switched to child theme bsa-portal';
}

// 4. Brand palette, logo and motto.
set_theme_mod( 'colorPalette', $palette );
set_theme_mod( 'custom_logo', $crest_id );
if ( '' === (string) get_option( 'blogdescription' ) ) {
	update_option( 'blogdescription', 'Passion for Excellence' );
}
do_action( 'blocksy:dynamic-css:refresh-caches' );

// 5. Verify what visitors receive.
$home  = wp_remote_get( home_url( '/' ), array( 'timeout' => 20 ) );
$login = wp_remote_get( wp_login_url(), array( 'timeout' => 20 ) );
$hb    = wp_remote_retrieve_body( $home );
$lb    = wp_remote_retrieve_body( $login );
$css   = (string) @file_get_contents( WP_CONTENT_DIR . '/uploads/blocksy/css/global.css' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
$log[] = 'stylesheet: ' . get_stylesheet() . ', template: ' . get_template();
$log[] = 'home ' . wp_remote_retrieve_response_code( $home ) . ( false !== stripos( $hb, 'critical error' ) ? ' CRITICAL' : '' ) . ', brand.css ' . ( false !== strpos( $hb, 'bsa-portal/assets/css/brand.css' ) ? 'yes' : 'no' ) . ', fonts.css ' . ( false !== strpos( $hb, 'bsa-portal/assets/fonts/fonts.css' ) ? 'yes' : 'no' ) . ', google fonts ' . ( false !== strpos( $hb, 'fonts.googleapis.com' ) ? 'YES' : 'no' );
$log[] = 'login ' . wp_remote_retrieve_response_code( $login ) . ', login.css ' . ( false !== strpos( $lb, 'bsa-portal/assets/css/login.css' ) ? 'yes' : 'no' ) . ', crest ' . ( false !== strpos( $lb, '.login h1 a { background-image' ) ? 'yes' : 'no' );
$log[] = 'global.css navy ' . ( false !== stripos( $css, '#1B3674' ) ? 'yes' : 'no' );

return $log;
