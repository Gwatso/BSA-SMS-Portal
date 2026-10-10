<?php
/**
 * Compatibility checks against the Weblizar School Management plugin.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Warns administrators when Weblizar is missing or at an unreviewed version.
 */
class BSA_Compat {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * Returns a problem description, or an empty string when compatible.
	 *
	 * @return string
	 */
	public static function problem() {
		if ( ! defined( 'WLSM_VERSION' ) ) {
			return __( 'The School Management plugin is not active. BSA access protections for school data are idle.', 'bsa-school-management' );
		}
		$installed = implode( '.', array_slice( explode( '.', WLSM_VERSION ), 0, 2 ) );
		if ( BSA_SM_TESTED_WLSM !== $installed ) {
			/* translators: 1: installed version, 2: reviewed version. */
			return sprintf( __( 'The School Management plugin is at version %1$s, but the BSA extension was reviewed against %2$s. Have the BSA access protections re-reviewed before relying on them.', 'bsa-school-management' ), WLSM_VERSION, BSA_SM_TESTED_WLSM );
		}
		return '';
	}

	/**
	 * Shows the compatibility notice to administrators.
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$problem = self::problem();
		if ( '' === $problem ) {
			return;
		}
		printf( '<div class="notice notice-warning"><p><strong>BSA:</strong> %s</p></div>', esc_html( $problem ) );
	}
}
