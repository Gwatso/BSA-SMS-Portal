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
		// A staff screen they may not open stops before admin_init with "not allowed".
		add_action( 'admin_page_access_denied', array( __CLASS__, 'keep_families_out_of_admin' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'staff_account_to_dashboard' ), 1 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'staff_menu' ) );
	}

	/**
	 * Whether a signed-in user is staff (not a learner or parent).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function is_staff_user( $user_id ) {
		return $user_id && ! self::is_family_user( $user_id ) && ( BSA_Access_Guard::is_staff( $user_id ) || user_can( $user_id, 'manage_options' ) );
	}

	/**
	 * The account page is for learners and parents: staff who open it go to their own
	 * dashboard instead of "There is no student or parent record".
	 */
	public static function staff_account_to_dashboard() {
		$page_id = (int) get_option( 'bsa_sm_account_page_id' );
		if ( ! $page_id || ! is_page( $page_id ) || ! self::is_staff_user( get_current_user_id() ) ) {
			return;
		}
		wp_safe_redirect( BSA_Staff_Area::home_url( get_current_user_id() ) );
		exit;
	}

	/**
	 * Site menu by who is signed in: for staff "My Account" becomes "My Dashboard", to their
	 * dashboard; for signed-in learners and parents the Intranet link is left out.
	 *
	 * @param object[] $items Menu items.
	 * @return object[]
	 */
	public static function staff_menu( $items ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $items;
		}
		if ( ! self::is_staff_user( $user_id ) ) {
			// The intranet is for staff: signed-in learners and parents do not see its link
			// (visitors do, so staff can tap it and sign in).
			$intranet = untrailingslashit( home_url( '/intranet' ) );
			return array_values(
				array_filter(
					$items,
					function ( $item ) use ( $intranet ) {
						return untrailingslashit( (string) $item->url ) !== $intranet;
					}
				)
			);
		}
		$account = untrailingslashit( self::account_url() );
		foreach ( $items as $item ) {
			if ( untrailingslashit( (string) $item->url ) === $account ) {
				$item->title = __( 'My Dashboard', 'bsa-school-management' );
				$item->url   = BSA_Staff_Area::home_url( $user_id );
			}
		}
		return $items;
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
	 * Staff go to their own dashboard (School Dashboard for school administrators, the
	 * home screen for teachers and the bursar; the owner to wp-admin); learners and
	 * parents go to their account page. Owner request 2026-10-16 (was the intranet).
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
			return BSA_Staff_Area::home_url( $user->ID );
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
