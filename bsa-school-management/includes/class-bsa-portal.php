<?php
/**
 * Portal behaviour for learners and parents: where they land after signing in, and
 * keeping them in the front-end account area rather than wp-admin.
 *
 * The account page ID is stored in the `bsa_sm_account_page_id` option (set by
 * scripts/06-portal-pages.php).
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sign-in routing and wp-admin separation by user type.
 */
class BSA_Portal {

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ) );
		add_action( 'admin_init', array( __CLASS__, 'keep_families_out_of_admin' ), 1 );
	}

	/**
	 * URL of the front-end account page (falls back to the home page).
	 *
	 * @return string
	 */
	public static function account_url() {
		$page_id = (int) get_option( 'bsa_sm_account_page_id' );
		$url     = $page_id ? get_permalink( $page_id ) : '';
		return $url ? $url : home_url( '/' );
	}

	/**
	 * Whether a user is a learner or parent without any staff role.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_family_user( $user_id ) {
		if ( ! $user_id || BSA_Access_Guard::is_staff( $user_id ) || user_can( $user_id, 'edit_posts' ) ) {
			return false;
		}
		return (bool) BSA_Access_Guard::owned_student_ids( $user_id );
	}

	/**
	 * Staff go to the intranet (the owner to wp-admin); learners and parents go to their
	 * account page.
	 *
	 * @param string           $redirect_to Requested redirect.
	 * @param string           $requested   Requested redirect (raw).
	 * @param WP_User|WP_Error $user        User.
	 * @return string
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $redirect_to;
		}
		if ( self::is_family_user( $user->ID ) ) {
			return self::account_url();
		}
		if ( BSA_Access_Guard::is_staff( $user->ID ) && ( '' === $requested || admin_url() === $redirect_to ) ) {
			return BSA_Staff_Area::applies( $user->ID ) && class_exists( 'BSA_Intranet' ) ? BSA_Intranet::url() : admin_url();
		}
		return $redirect_to;
	}

	/**
	 * No WordPress toolbar for learners and parents.
	 *
	 * @param bool $show Default.
	 * @return bool
	 */
	public static function show_admin_bar( $show ) {
		return self::is_family_user( get_current_user_id() ) ? false : $show;
	}

	/**
	 * Learners and parents use the account page; wp-admin is for staff.
	 * AJAX and admin-post requests are left alone (the account page uses them).
	 */
	public static function keep_families_out_of_admin() {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( in_array( $script, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
			return;
		}
		if ( self::is_family_user( get_current_user_id() ) ) {
			wp_safe_redirect( self::account_url() );
			exit;
		}
	}
}
