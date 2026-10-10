<?php
/**
 * Site-wide hardening: XML-RPC, security headers, file editor, user enumeration.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies low-risk WordPress hardening that the hosting does not provide.
 */
class BSA_Hardening {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		// Disable the theme/plugin file editor in wp-admin.
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		// XML-RPC is not used by the portal and amplifies brute-force attempts.
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		add_filter( 'wp_headers', array( __CLASS__, 'filter_wp_headers' ) );

		add_action( 'login_init', array( __CLASS__, 'send_security_headers' ) );
		add_action( 'admin_init', array( __CLASS__, 'send_security_headers' ) );

		// Stop anonymous enumeration of user accounts via the REST API.
		add_filter( 'rest_endpoints', array( __CLASS__, 'restrict_user_endpoints' ) );
	}

	/**
	 * Security headers sent on every response.
	 *
	 * A Content-Security-Policy is deliberately not set yet: the Weblizar plugin
	 * relies on inline scripts, so a CSP needs its own testing pass.
	 *
	 * @return array<string,string>
	 */
	public static function security_headers() {
		return array(
			'Strict-Transport-Security' => 'max-age=31536000',
			'X-Frame-Options'           => 'SAMEORIGIN',
			'X-Content-Type-Options'    => 'nosniff',
			'Referrer-Policy'           => 'strict-origin-when-cross-origin',
			'Permissions-Policy'        => 'camera=(), microphone=(), geolocation=(), payment=()',
		);
	}

	/**
	 * Adds security headers to front-end responses and removes X-Pingback.
	 *
	 * @param array $headers Response headers.
	 * @return array
	 */
	public static function filter_wp_headers( $headers ) {
		unset( $headers['X-Pingback'] );
		return array_merge( $headers, self::security_headers() );
	}

	/**
	 * Sends security headers on login and admin requests, which bypass wp_headers.
	 */
	public static function send_security_headers() {
		if ( headers_sent() ) {
			return;
		}
		foreach ( self::security_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Removes the users endpoints for anyone who cannot list users, and the media
	 * endpoints for anyone who cannot upload (the media list is otherwise public).
	 *
	 * @param array $endpoints Registered REST endpoints.
	 * @return array
	 */
	public static function restrict_user_endpoints( $endpoints ) {
		$hide = array();
		if ( ! current_user_can( 'list_users' ) ) {
			$hide[] = '/wp/v2/users';
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			$hide[] = '/wp/v2/media';
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			foreach ( $hide as $prefix ) {
				if ( 0 === strpos( $route, $prefix ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}
}
