<?php
/**
 * Branded staff area: wp-admin without WordPress for everyone except the owner.
 *
 * For staff (school administrator, teachers, bursar) and any other non-owner account:
 * - no WordPress logo menu, command palette, "Howdy", footer credit, version, help tabs,
 *   update notices, application passwords or WordPress Dashboard;
 * - Weblizar's "SM …" menus renamed, unused modules hidden from the menu, BSA order;
 * - BSA navy menu and toolbar with the crest;
 * - a home screen per role: School Dashboard for school administrators; "My class",
 *   "My subjects" and fees for teachers and the bursar.
 * The owner (WordPress administrator) keeps the standard WordPress screens.
 *
 * Hiding a menu is presentation only: access is still decided by Weblizar and the BSA
 * checks on each screen.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Staff area branding and role home screens.
 */
class BSA_Staff_Area {

	const HOME = 'bsa-home';

	/**
	 * Weblizar top-level menus, renamed (slug => label).
	 */
	const MENU_LABELS = array(
		'sm-staff-student'       => 'Learners',
		'sm-staff-academic'      => 'Academics',
		'sm-staff-accounting'    => 'Accounts',
		'sm-staff-examination'   => 'Exams',
		'sm-staff-dashboard'     => 'School',
		'sm-staff-administrator' => 'Staff',
		'sm-staff-library'       => 'Library',
		'sm-staff-transport'     => 'Transport',
		'sm-staff-hostel'        => 'Hostel',
		'profile.php'            => 'My profile',
	);

	/**
	 * Menu order for staff; anything else follows, "My profile" last.
	 */
	const MENU_ORDER = array(
		'bsa-home',
		'bsa-dashboard',
		'bsa-class-register',
		'bsa-marks',
		'bsa-report-cards',
		'bsa-fee-reports',
		'sm-staff-student',
		'sm-staff-academic',
		'sm-staff-accounting',
		'sm-staff-examination',
		'bsa-admission-documents',
		'bsa-family-accounts',
		'bsa-school-terms',
		'sm-staff-dashboard',
		'sm-staff-administrator',
	);

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_home' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'tidy_menu' ), 9999 );
		add_filter( 'custom_menu_order', array( __CLASS__, 'custom_menu_order' ) );
		add_filter( 'menu_order', array( __CLASS__, 'menu_order' ) );
		add_action( 'load-index.php', array( __CLASS__, 'redirect_dashboard' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'brand_node' ), 5 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'tidy_admin_bar' ), 9999 );
		add_filter( 'admin_title', array( __CLASS__, 'admin_title' ), 20, 2 );
		add_filter( 'login_title', array( __CLASS__, 'login_title' ), 20, 2 );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ), 99 );
		add_filter( 'update_footer', array( __CLASS__, 'footer_version' ), 99 );
		add_action( 'admin_init', array( __CLASS__, 'no_update_notices' ) );
		add_action( 'admin_head', array( __CLASS__, 'no_help_tabs' ) );
		add_action( 'admin_head', array( __CLASS__, 'print_styles' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_toolbar_styles' ) );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'application_passwords' ), 10, 2 );
		add_filter( 'get_user_option_admin_color', array( __CLASS__, 'admin_color' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'no_colour_picker' ) );
		add_filter( 'user_contactmethods', array( __CLASS__, 'contact_methods' ), 99, 2 );
	}

	/**
	 * Whether a user gets the branded staff area: any signed-in account except the owner
	 * (WordPress administrator).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function applies( $user_id ) {
		return $user_id && ! user_can( $user_id, 'manage_options' );
	}

	/**
	 * Where a user starts: School Dashboard for school administrators, the home screen
	 * for other staff, the standard wp-admin for the owner.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function home_url( $user_id ) {
		if ( ! self::applies( $user_id ) ) {
			return admin_url();
		}
		if ( BSA_School::is_school_admin( $user_id ) ) {
			return admin_url( 'admin.php?page=' . BSA_Dashboard::PAGE );
		}
		return admin_url( 'admin.php?page=' . self::HOME );
	}

	/**
	 * "Home" at the top of the menu for staff who are not school administrators (they
	 * start on the School Dashboard).
	 */
	public static function register_home() {
		$user_id = get_current_user_id();
		if ( ! self::applies( $user_id ) || BSA_School::is_school_admin( $user_id ) ) {
			return;
		}
		add_menu_page( __( 'Home', 'bsa-school-management' ), __( 'Home', 'bsa-school-management' ), 'read', self::HOME, array( __CLASS__, 'render_home' ), 'dashicons-admin-home', 1 );
	}

	/**
	 * Menu entries hidden from staff (modules the school does not use). Filterable.
	 *
	 * @return string[] Menu or submenu slugs.
	 */
	public static function hidden_menus() {
		return (array) apply_filters( 'bsa_sm_hidden_staff_menus', array( 'sm-staff-library', 'sm-staff-transport', 'sm-staff-assigned-live-classes', 'sm-staff-live-classes' ) );
	}

	/**
	 * Removes the WordPress Dashboard and separators, hides unused modules and renames
	 * Weblizar's menus.
	 */
	public static function tidy_menu() {
		if ( ! self::applies( get_current_user_id() ) ) {
			return;
		}
		global $menu, $submenu;
		$hidden = self::hidden_menus();
		foreach ( (array) $menu as $key => $item ) {
			$slug = $item[2] ?? '';
			if ( 'index.php' === $slug || in_array( $slug, $hidden, true ) || false !== strpos( $item[4] ?? '', 'wp-menu-separator' ) ) {
				unset( $menu[ $key ], $submenu[ $slug ] );
				continue;
			}
			if ( isset( self::MENU_LABELS[ $slug ] ) ) {
				$menu[ $key ][0] = self::MENU_LABELS[ $slug ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			} elseif ( 0 === strpos( (string) $item[0], 'SM ' ) ) {
				$menu[ $key ][0] = substr( $item[0], 3 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		}
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( $items as $key => $item ) {
				if ( in_array( $item[2] ?? '', $hidden, true ) ) {
					unset( $submenu[ $parent ][ $key ] );
				}
			}
		}
	}

	/**
	 * Use our menu order for staff.
	 *
	 * @param bool $custom Default.
	 * @return bool
	 */
	public static function custom_menu_order( $custom ) {
		return self::applies( get_current_user_id() ) ? true : $custom;
	}

	/**
	 * BSA screens first, then Weblizar's groups, "My profile" last.
	 *
	 * @param string[] $order Menu slugs.
	 * @return string[]
	 */
	public static function menu_order( $order ) {
		if ( ! self::applies( get_current_user_id() ) || ! is_array( $order ) ) {
			return $order;
		}
		$rank = array_flip( self::MENU_ORDER );
		$pos  = array_flip( array_values( $order ) );
		$out  = array_values( $order );
		usort(
			$out,
			static function ( $a, $b ) use ( $rank, $pos ) {
				$ra = 'profile.php' === $a ? 1000 : ( $rank[ $a ] ?? 500 );
				$rb = 'profile.php' === $b ? 1000 : ( $rank[ $b ] ?? 500 );
				return array( $ra, $pos[ $a ] ) <=> array( $rb, $pos[ $b ] );
			}
		);
		return $out;
	}

	/**
	 * The WordPress Dashboard sends staff to their home screen.
	 */
	public static function redirect_dashboard() {
		$user_id = get_current_user_id();
		if ( self::applies( $user_id ) ) {
			wp_safe_redirect( self::home_url( $user_id ) );
			exit;
		}
	}

	/**
	 * Crest and "BSA Portal" at the start of the toolbar, linking to the home screen.
	 *
	 * @param WP_Admin_Bar $bar Toolbar.
	 */
	public static function brand_node( $bar ) {
		$user_id = get_current_user_id();
		if ( ! self::applies( $user_id ) ) {
			return;
		}
		$icon = get_site_icon_url( 64 );
		$bar->add_node(
			array(
				'id'    => 'bsa-brand',
				'title' => ( $icon ? '<img src="' . esc_url( $icon ) . '" alt="" width="24" height="24">' : '' ) . '<span class="bsa-brand-label">' . esc_html__( 'BSA Portal', 'bsa-school-management' ) . '</span>',
				'href'  => self::home_url( $user_id ),
				'meta'  => array( 'title' => __( 'Bindura Science Academy: home', 'bsa-school-management' ) ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'bsa-brand',
				'id'     => 'bsa-brand-site',
				'title'  => esc_html__( 'Portal home page', 'bsa-school-management' ),
				'href'   => home_url( '/' ),
			)
		);
	}

	/**
	 * Removes WordPress items from the toolbar and the "Howdy" greeting.
	 *
	 * @param WP_Admin_Bar $bar Toolbar.
	 */
	public static function tidy_admin_bar( $bar ) {
		if ( ! self::applies( get_current_user_id() ) ) {
			return;
		}
		foreach ( array( 'wp-logo', 'site-name', 'command-palette', 'comments', 'new-content', 'updates', 'search', 'customize' ) as $id ) {
			$bar->remove_node( $id );
		}
		$account = $bar->get_node( 'my-account' );
		if ( $account ) {
			$howdy = strstr( __( 'Howdy, %s' ), '%s', true ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress's own string.
			if ( $howdy ) {
				$account->title = str_replace( $howdy, '', $account->title );
				if ( isset( $account->meta['menu_title'] ) ) {
					$account->meta['menu_title'] = str_replace( wp_strip_all_tags( $howdy ), '', $account->meta['menu_title'] );
				}
				$bar->add_node( (array) $account );
			}
		}
	}

	/**
	 * Browser tab: "<screen> ‹ <site name>", without "— WordPress".
	 *
	 * @param string $admin_title Full title.
	 * @param string $title       Screen title.
	 * @return string
	 */
	public static function admin_title( $admin_title, $title ) {
		if ( ! self::applies( get_current_user_id() ) ) {
			return $admin_title;
		}
		return sprintf( '%1$s &lsaquo; %2$s', $title, get_bloginfo( 'name', 'display' ) );
	}

	/**
	 * Sign-in page tab without "— WordPress" (for everyone).
	 *
	 * @param string $login_title Full title.
	 * @param string $title       Page title.
	 * @return string
	 */
	public static function login_title( $login_title, $title ) {
		return sprintf( '%1$s &lsaquo; %2$s', $title, get_bloginfo( 'name', 'display' ) );
	}

	/**
	 * Footer: the school instead of "Thank you for creating with WordPress".
	 *
	 * @param string $text Default.
	 * @return string
	 */
	public static function footer_text( $text ) {
		if ( ! self::applies( get_current_user_id() ) ) {
			return $text;
		}
		return '<span class="bsa-footer">' . esc_html__( 'Bindura Science Academy · Passion for Excellence', 'bsa-school-management' ) . '</span>';
	}

	/**
	 * No WordPress version in the footer.
	 *
	 * @param string $text Default.
	 * @return string
	 */
	public static function footer_version( $text ) {
		return self::applies( get_current_user_id() ) ? '' : $text;
	}

	/**
	 * No WordPress update or maintenance notices for staff.
	 */
	public static function no_update_notices() {
		if ( self::applies( get_current_user_id() ) ) {
			remove_action( 'admin_notices', 'update_nag', 3 );
			remove_action( 'admin_notices', 'maintenance_nag', 10 );
		}
	}

	/**
	 * No WordPress help tabs for staff.
	 */
	public static function no_help_tabs() {
		$screen = get_current_screen();
		if ( $screen && self::applies( get_current_user_id() ) ) {
			$screen->remove_help_tabs();
			$screen->set_help_sidebar( '' );
		}
	}

	/**
	 * No application passwords for anyone but the owner (fewer ways in).
	 *
	 * @param bool    $available Default.
	 * @param WP_User $user      User.
	 * @return bool
	 */
	public static function application_passwords( $available, $user ) {
		return ( $user instanceof WP_User && self::applies( $user->ID ) ) ? false : $available;
	}

	/**
	 * Staff always get the default scheme (the BSA colours are layered on it).
	 *
	 * @param mixed  $value   Stored value.
	 * @param string $option  Option name.
	 * @param object $user    User.
	 * @return mixed
	 */
	public static function admin_color( $value, $option = '', $user = null ) {
		$id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : get_current_user_id();
		return self::applies( $id ) ? 'fresh' : $value;
	}

	/**
	 * No social-media fields (one is labelled "WordPress") on staff profiles.
	 *
	 * @param array        $methods Contact methods.
	 * @param WP_User|null $user    User being edited.
	 * @return array
	 */
	public static function contact_methods( $methods, $user = null ) {
		$id = $user instanceof WP_User ? $user->ID : get_current_user_id();
		return self::applies( $id ) ? array() : $methods;
	}

	/**
	 * No colour-scheme picker on the profile page for staff.
	 */
	public static function no_colour_picker() {
		if ( self::applies( get_current_user_id() ) ) {
			remove_action( 'admin_color_scheme_picker', 'admin_color_scheme_picker' );
		}
	}

	/**
	 * Brand fonts from the BSA Portal theme, when it is active.
	 *
	 * @return string URL or ''.
	 */
	private static function fonts_url() {
		return file_exists( get_stylesheet_directory() . '/assets/fonts/fonts.css' ) ? get_stylesheet_directory_uri() . '/assets/fonts/fonts.css' : '';
	}

	/**
	 * Toolbar colours (shared by wp-admin and the site).
	 *
	 * @return string CSS.
	 */
	private static function toolbar_css() {
		return '
#wpadminbar{background:#132653;font-family:"Plus Jakarta Sans",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
#wpadminbar .ab-item,#wpadminbar a.ab-item,#wpadminbar>#wp-toolbar span.ab-label,#wpadminbar>#wp-toolbar span.noticon{color:#fff}
#wpadminbar .ab-icon:before,#wpadminbar .ab-item:before,#wpadminbar .ab-item:after{color:#DCE6F5}
#wpadminbar .menupop .ab-sub-wrapper,#wpadminbar .shortlink-input{background:#1B3674}
#wpadminbar .quicklinks .menupop ul li a,#wpadminbar .quicklinks .menupop ul li a strong,#wpadminbar #wp-admin-bar-user-info .display-name,#wpadminbar #wp-admin-bar-user-info .username{color:#DCE6F5}
#wpadminbar .quicklinks .menupop ul li a:hover,#wpadminbar .quicklinks .menupop ul li a:focus,#wpadminbar #wp-admin-bar-user-info a:hover .display-name{color:#fff}
#wpadminbar:not(.mobile) .ab-top-menu>li:hover>.ab-item,#wpadminbar .ab-top-menu>li.hover>.ab-item,#wpadminbar.nojq .quicklinks .ab-top-menu>li>.ab-item:focus,#wpadminbar:not(.mobile) .ab-top-menu>li>.ab-item:focus{background:#1B3674;color:#fff}
#wpadminbar #wp-admin-bar-bsa-brand>.ab-item{display:flex;align-items:center;gap:8px;font-weight:600}
#wpadminbar #wp-admin-bar-bsa-brand img{width:24px;height:24px;border-radius:50%;background:#fff}
@media screen and (max-width:782px){#wpadminbar li#wp-admin-bar-bsa-brand{display:block}#wpadminbar #wp-admin-bar-bsa-brand>.ab-item{height:46px;padding:0 10px}#wpadminbar #wp-admin-bar-bsa-brand img{width:30px;height:30px}#wpadminbar .bsa-brand-label{display:none}}
';
	}

	/**
	 * Staff area styles: BSA navy menu, toolbar, fonts, a tidier profile page.
	 */
	public static function print_styles() {
		if ( ! self::applies( get_current_user_id() ) ) {
			return;
		}
		$fonts = self::fonts_url();
		if ( $fonts ) {
			echo '<link rel="stylesheet" id="bsa-staff-fonts" href="' . esc_url( $fonts ) . '">' . "\n";
		}
		echo '<style id="bsa-staff-area">' . self::toolbar_css() . '
#adminmenuback,#adminmenuwrap,#adminmenu{background:#1B3674}
#adminmenu,#collapse-button,.bsa-dash h1,.bsa-dash h2{font-family:"Plus Jakarta Sans",-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
#adminmenu a{color:#fff}
#adminmenu div.wp-menu-image:before{color:#DCE6F5}
#adminmenu li.menu-top:hover,#adminmenu li.opensub>a.menu-top,#adminmenu li>a.menu-top:focus{background:#132653;color:#fff}
#adminmenu li.menu-top:hover div.wp-menu-image:before,#adminmenu li.opensub>a.menu-top div.wp-menu-image:before,#adminmenu li>a.menu-top:focus div.wp-menu-image:before{color:#fff}
#adminmenu .wp-submenu,#adminmenu .wp-has-current-submenu .wp-submenu,#adminmenu .wp-has-current-submenu.opensub .wp-submenu,#adminmenu a.wp-has-current-submenu:focus+.wp-submenu,.folded #adminmenu .wp-has-current-submenu .wp-submenu{background:#132653}
#adminmenu .wp-submenu a,#adminmenu .wp-has-current-submenu .wp-submenu a{color:#DCE6F5}
#adminmenu .wp-submenu a:hover,#adminmenu .wp-submenu a:focus,#adminmenu .wp-has-current-submenu .wp-submenu a:hover{color:#fff}
#adminmenu .wp-submenu li.current a,#adminmenu .wp-submenu li.current a:hover,#adminmenu .wp-submenu li.current a:focus{color:#fff;font-weight:600}
#adminmenu .wp-submenu-head{color:#fff}
#adminmenu li.current a.menu-top,#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,#adminmenu li.wp-has-current-submenu .wp-submenu .wp-submenu-head,.folded #adminmenu li.current.menu-top{background:#376DBE;color:#fff}
#adminmenu li.wp-has-current-submenu div.wp-menu-image:before,#adminmenu .current div.wp-menu-image:before,#adminmenu a.current:hover div.wp-menu-image:before{color:#fff}
#adminmenu li a:focus div.wp-menu-image:before{color:#fff}
#collapse-button{color:#DCE6F5}
#collapse-button:hover,#collapse-button:focus{color:#fff}
.bsa-footer{color:#50575e}
body.profile-php .user-rich-editing-wrap,body.profile-php .user-syntax-highlighting-wrap,body.profile-php .user-comment-shortcuts-wrap,body.profile-php .user-admin-bar-front-wrap,body.profile-php .user-language-wrap,body.profile-php .user-url-wrap,body.profile-php .user-description-wrap,body.profile-php .user-profile-picture,body.profile-php .user-admin-color-wrap{display:none}
</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS.
	}

	/**
	 * Toolbar styles on the site for staff (the toolbar shows there too).
	 */
	public static function print_toolbar_styles() {
		if ( is_admin_bar_showing() && self::applies( get_current_user_id() ) ) {
			echo '<style id="bsa-staff-toolbar">' . self::toolbar_css() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS.
		}
	}

	/**
	 * Exam marks entered per subject for one class in a term.
	 *
	 * @param int        $section_id Section ID.
	 * @param int        $session_id Session ID.
	 * @param int        $term       1–3.
	 * @param int[]|null $only       Subject IDs to include (null = all of the form).
	 * @return array<int,array{label:string, expected:int, entered:int}> subject ID => counts.
	 */
	public static function subject_progress( $section_id, $session_id, $term, $only = null ) {
		$out = array();
		foreach ( BSA_Marks::section_subjects( $section_id ) as $s ) {
			if ( null === $only || in_array( (int) $s->ID, $only, true ) ) {
				$out[ (int) $s->ID ] = array( 'label' => $s->label, 'expected' => 0, 'entered' => 0 );
			}
		}
		if ( ! $out ) {
			return $out;
		}
		$ids   = array_map( 'intval', wp_list_pluck( BSA_School::learners( $section_id, $session_id ), 'ID' ) );
		$marks = BSA_Marks::for_learners( $ids, $session_id, $term );
		foreach ( $ids as $id ) {
			foreach ( BSA_Reports::learner_subjects( $id, $section_id ) as $s ) {
				$sid = (int) $s->ID;
				if ( isset( $out[ $sid ] ) ) {
					++$out[ $sid ]['expected'];
					$m = $marks[ $id ][ $sid ] ?? null;
					if ( $m && null !== $m->exam_mark ) {
						++$out[ $sid ]['entered'];
					}
				}
			}
		}
		return $out;
	}

	/**
	 * What the home screen shows for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function home_state( $user_id ) {
		$staff   = BSA_School::staff( $user_id );
		$today   = current_time( 'Y-m-d' );
		$term    = BSA_Terms::current( $today );
		$session = $term ? (int) $term['session'] : BSA_School::current_session();
		$state   = array(
			'today'    => $today,
			'term'     => $term,
			'in_term'  => $term && $today <= $term['end'],
			'school'   => $term && $today <= $term['end'] && (int) gmdate( 'N', strtotime( $today ) ) <= 5,
			'session'  => $session,
			'name'     => $staff && $staff->name ? $staff->name : wp_get_current_user()->display_name,
			'class'    => null,
			'subjects' => array(),
			'finance'  => null,
		);
		if ( $staff && in_array( 'assigned_class', $staff->permissions, true ) && BSA_School::section( $staff->section_id ) ) {
			$section    = BSA_School::section( $staff->section_id );
			$att        = BSA_Dashboard::attendance( $today, $session );
			$progress   = $term ? self::subject_progress( $section->ID, $session, (int) $term['term'] ) : array();
			$learners   = BSA_School::learners( $section->ID, $session );
			$published  = 0;
			foreach ( $learners as $l ) {
				if ( $term && BSA_Marks::is_published( (int) $l->ID, $session, (int) $term['term'] ) ) {
					++$published;
				}
			}
			$state['class'] = array(
				'section'   => (int) $section->ID,
				'title'     => $section->title,
				'learners'  => count( $learners ),
				'register'  => $att[ (int) $section->ID ] ?? null,
				'progress'  => $progress,
				'published' => $published,
			);
		}
		$assigned = BSA_Marks::assigned_subjects( $user_id );
		if ( $assigned && $term ) {
			foreach ( BSA_School::sections() as $section ) {
				if ( ! BSA_School::learners( $section->ID, $session ) ) {
					continue;
				}
				foreach ( self::subject_progress( $section->ID, $session, (int) $term['term'], $assigned ) as $sid => $p ) {
					$state['subjects'][] = array_merge( $p, array( 'section' => (int) $section->ID, 'class' => $section->title, 'subject' => $sid ) );
				}
			}
		}
		if ( ! BSA_School::is_school_admin( $user_id ) && ( BSA_School::can( $user_id, 'manage_invoices' ) || BSA_School::can( $user_id, 'manage_fees' ) ) ) {
			$owing = array_values(
				array_filter(
					BSA_Finance::balances(),
					static function ( $b ) {
						return (float) $b->balance > 0.004;
					}
				)
			);
			usort(
				$owing,
				static function ( $a, $b ) {
					return (float) $b->balance <=> (float) $a->balance;
				}
			);
			$state['finance'] = array(
				'fees'   => BSA_Dashboard::fees(),
				'week'   => BSA_Dashboard::received( gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) ), $today ),
				'term'   => $term ? BSA_Dashboard::received( $term['start'], $today ) : array( 'amount' => 0.0, 'count' => 0 ),
				'recent' => array_slice( BSA_Finance::payments( gmdate( 'Y-m-d', strtotime( $today . ' -30 days' ) ), $today ), 0, 8 ),
				'owing'  => array_slice( $owing, 0, 8 ),
			);
		}
		return $state;
	}

	/**
	 * Home screen for staff who are not school administrators.
	 */
	public static function render_home() {
		$user_id = get_current_user_id();
		if ( ! self::applies( $user_id ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
		$s    = self::home_state( $user_id );
		$pct  = static function ( $part, $whole ) {
			return $whole > 0 ? (int) round( 100 * $part / $whole ) : 0;
		};
		$hour = (int) current_time( 'G' );
		/* translators: %s: first name. */
		$greeting = $hour < 12 ? __( 'Good morning, %s', 'bsa-school-management' ) : ( $hour < 17 ? __( 'Good afternoon, %s', 'bsa-school-management' ) : __( 'Good evening, %s', 'bsa-school-management' ) );
		list( $surname, $firsts ) = BSA_School::split_name( $s['name'] );
		$first                    = strtok( '' !== $firsts ? $firsts : $surname, ' ' );

		echo '<div class="wrap bsa-dash bsa-home">';
		BSA_Dashboard::styles();
		echo '<style>.bsa-home .bsa-roles{margin:0 0 16px;display:flex;flex-wrap:wrap;gap:8px}.bsa-home .bsa-role{background:#DCE6F5;color:#132653;border-radius:999px;padding:4px 12px;font-weight:600;font-size:12px}.bsa-home .bsa-actions{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 0}.bsa-home .bsa-actions .button{min-height:36px;line-height:34px}.bsa-home .bsa-tiles{grid-template-columns:repeat(3,minmax(0,1fr))}@media (max-width:782px){.bsa-home .bsa-tiles{grid-template-columns:minmax(0,1fr)}}</style>';
		echo '<h1>' . esc_html( sprintf( $greeting, $first ) ) . '</h1>';
		$term_text = $s['term'] ? sprintf(
			/* translators: 1: term, 2: start, 3: end. */
			__( 'Term %1$d: %2$s to %3$s', 'bsa-school-management' ),
			$s['term']['term'],
			mysql2date( 'j M Y', $s['term']['start'] ),
			mysql2date( 'j M Y', $s['term']['end'] )
		) . ( $s['in_term'] ? '' : ' ' . __( '(holiday)', 'bsa-school-management' ) ) : __( 'No term dates set for today', 'bsa-school-management' );
		echo '<p class="bsa-dash-sub">' . esc_html( mysql2date( 'l j F Y', $s['today'] ) . ' · ' . $term_text ) . '</p>';

		$roles = array();
		if ( $s['class'] ) {
			/* translators: %s: class. */
			$roles[] = sprintf( __( 'Class teacher, %s', 'bsa-school-management' ), $s['class']['title'] );
		}
		if ( $s['subjects'] ) {
			$labels = array_unique( wp_list_pluck( $s['subjects'], 'label' ) );
			/* translators: %s: subjects. */
			$roles[] = sprintf( __( 'Subject teacher: %s', 'bsa-school-management' ), implode( ', ', $labels ) );
		}
		if ( $s['finance'] ) {
			$roles[] = __( 'Fees', 'bsa-school-management' );
		}
		if ( $roles ) {
			echo '<p class="bsa-roles">';
			foreach ( $roles as $r ) {
				echo '<span class="bsa-role">' . esc_html( $r ) . '</span>';
			}
			echo '</p>';
		}

		// My class.
		if ( $s['class'] ) {
			$c        = $s['class'];
			$reg      = $c['register'];
			$exp      = array_sum( wp_list_pluck( $c['progress'], 'expected' ) );
			$ent      = array_sum( wp_list_pluck( $c['progress'], 'entered' ) );
			$reg_url  = admin_url( 'admin.php?page=' . BSA_Register::PAGE . '&section=' . $c['section'] );
			$mark_url = admin_url( 'admin.php?page=' . BSA_Marks::PAGE . '&section=' . $c['section'] );
			if ( ! $s['school'] ) {
				$reg_value = '–';
				$reg_note  = __( 'no school today', 'bsa-school-management' );
			} elseif ( ! $reg || ! $reg['marked'] ) {
				$reg_value = __( 'Not marked', 'bsa-school-management' );
				$reg_note  = __( 'mark today\'s register', 'bsa-school-management' );
			} else {
				$poss      = $reg['present'] + $reg['late'] + $reg['absent'];
				$reg_value = $poss ? $pct( $reg['present'] + $reg['late'], $poss ) . '%' : '–';
				/* translators: 1: absent, 2: late. */
				$reg_note = sprintf( __( '%1$d absent · %2$d late', 'bsa-school-management' ), $reg['absent'], $reg['late'] );
			}
			/* translators: %s: class. */
			echo '<section class="bsa-card"><h2>' . esc_html( sprintf( __( 'My class: %s', 'bsa-school-management' ), $c['title'] ) ) . '</h2>';
			echo '<div class="bsa-tiles">';
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'Learners', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . esc_html( number_format_i18n( $c['learners'] ) ) . '</div><div class="bsa-tile-note">' . esc_html__( 'active this year', 'bsa-school-management' ) . '</div></div>';
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'Register today', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . esc_html( $reg_value ) . '</div><div class="bsa-tile-note">' . esc_html( $reg_note ) . '</div></div>';
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'Exam marks entered this term', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . esc_html( $s['term'] ? $pct( $ent, $exp ) . '%' : '–' ) . '</div><div class="bsa-tile-note">' . esc_html( $s['term'] ? sprintf( /* translators: 1: entered, 2: expected, 3: published, 4: learners. */ __( '%1$d of %2$d · %3$d of %4$d report cards published', 'bsa-school-management' ), $ent, $exp, $c['published'], $c['learners'] ) : __( 'no current term', 'bsa-school-management' ) ) . '</div></div>';
			echo '</div>';
			if ( $c['progress'] ) {
				echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Subject', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Exam marks entered', 'bsa-school-management' ) . '</th><th scope="col" class="num"><span class="screen-reader-text">' . esc_html__( 'Link', 'bsa-school-management' ) . '</span></th></tr></thead><tbody>';
				foreach ( $c['progress'] as $sid => $p ) {
					/* translators: 1: entered, 2: expected. */
					$text = $p['expected'] ? sprintf( __( '%1$d of %2$d', 'bsa-school-management' ), $p['entered'], $p['expected'] ) : __( 'nobody takes it', 'bsa-school-management' );
					echo '<tr><th scope="row">' . esc_html( $p['label'] ) . '</th><td class="bsa-meter-cell">' . BSA_Dashboard::meter( $p['entered'], $p['expected'], $text ) . '</td><td class="num"><a href="' . esc_url( $mark_url . '&subject=' . $sid ) . '">' . esc_html__( 'Enter marks', 'bsa-school-management' ) . '</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- meter() escapes.
				}
				echo '</tbody></table></div>';
			}
			echo '<p class="bsa-actions"><a class="button button-primary" href="' . esc_url( $reg_url ) . '">' . esc_html__( 'Class Register', 'bsa-school-management' ) . '</a><a class="button" href="' . esc_url( $mark_url ) . '">' . esc_html__( 'Marks', 'bsa-school-management' ) . '</a><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . BSA_Reports::PAGE ) ) . '">' . esc_html__( 'Report Cards', 'bsa-school-management' ) . '</a></p></section>';
		}

		// My subjects.
		if ( $s['subjects'] ) {
			/* translators: %d: term. */
			echo '<section class="bsa-card"><h2>' . esc_html( sprintf( __( 'My subjects, Term %d', 'bsa-school-management' ), $s['term']['term'] ) ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'Exam marks entered in each class you teach.', 'bsa-school-management' ) . '</p>';
			echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Subject', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Exam marks entered', 'bsa-school-management' ) . '</th><th scope="col" class="num"><span class="screen-reader-text">' . esc_html__( 'Link', 'bsa-school-management' ) . '</span></th></tr></thead><tbody>';
			foreach ( $s['subjects'] as $p ) {
				/* translators: 1: entered, 2: expected. */
				$text = $p['expected'] ? sprintf( __( '%1$d of %2$d', 'bsa-school-management' ), $p['entered'], $p['expected'] ) : __( 'nobody takes it', 'bsa-school-management' );
				echo '<tr><th scope="row">' . esc_html( $p['class'] ) . '</th><td>' . esc_html( $p['label'] ) . '</td><td class="bsa-meter-cell">' . BSA_Dashboard::meter( $p['entered'], $p['expected'], $text ) . '</td><td class="num"><a href="' . esc_url( admin_url( 'admin.php?page=' . BSA_Marks::PAGE . '&section=' . $p['section'] . '&subject=' . $p['subject'] ) ) . '">' . esc_html__( 'Enter marks', 'bsa-school-management' ) . '</a></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- meter() escapes.
			}
			echo '</tbody></table></div></section>';
		}

		// Fees.
		if ( $s['finance'] ) {
			$f    = $s['finance'];
			$fees = $f['fees'];
			echo '<section class="bsa-card"><h2>' . esc_html__( 'Fees', 'bsa-school-management' ) . '</h2>';
			echo '<div class="bsa-tiles">';
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'Collected this year', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . esc_html( BSA_Dashboard::money( $fees['paid'] ) ) . '</div><div class="bsa-tile-note">' . esc_html( sprintf( /* translators: 1: percent, 2: invoiced. */ __( '%1$d%% of %2$s invoiced', 'bsa-school-management' ), $pct( $fees['paid'], $fees['invoiced'] ), BSA_Dashboard::money( $fees['invoiced'] ) ) ) . '</div></div>';
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'Outstanding', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . esc_html( BSA_Dashboard::money( $fees['balance'] ) ) . '</div><div class="bsa-tile-note">' . esc_html( sprintf( /* translators: %d: learners. */ _n( '%d learner owing', '%d learners owing', $fees['learners_owing'], 'bsa-school-management' ), $fees['learners_owing'] ) ) . '</div></div>';
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'Received in the last 7 days', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . esc_html( BSA_Dashboard::money( $f['week']['amount'] ) ) . '</div><div class="bsa-tile-note">' . esc_html( sprintf( /* translators: 1: payments, 2: amount this term. */ _n( '%1$d payment · this term %2$s', '%1$d payments · this term %2$s', $f['week']['count'], 'bsa-school-management' ), $f['week']['count'], BSA_Dashboard::money( $f['term']['amount'] ) ) ) . '</div></div>';
			echo '</div>';
			echo '<div class="bsa-grid">';
			echo '<div><h3>' . esc_html__( 'By class', 'bsa-school-management' ) . '</h3>';
			BSA_Dashboard::fees_table( $fees );
			echo '</div><div><h3>' . esc_html__( 'Largest balances', 'bsa-school-management' ) . '</h3>';
			if ( ! $f['owing'] ) {
				echo '<p>' . esc_html__( 'Nobody owes fees.', 'bsa-school-management' ) . '</p>';
			} else {
				echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Balance', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
				foreach ( $f['owing'] as $b ) {
					echo '<tr><th scope="row">' . esc_html( $b->name ) . '</th><td>' . esc_html( $b->class_label . ' ' . $b->section_label ) . '</td><td class="num">' . esc_html( BSA_Dashboard::money( $b->balance ) ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			}
			echo '</div></div>';
			echo '<h3>' . esc_html__( 'Recent payments (30 days)', 'bsa-school-management' ) . '</h3>';
			if ( ! $f['recent'] ) {
				echo '<p>' . esc_html__( 'No payments in the last 30 days.', 'bsa-school-management' ) . '</p>';
			} else {
				echo '<div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Date', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Method', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Amount', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
				foreach ( $f['recent'] as $pay ) {
					$method = class_exists( 'WLSM_M_Invoice' ) ? WLSM_M_Invoice::get_payment_method_text( $pay->payment_method ) : $pay->payment_method;
					echo '<tr><td>' . esc_html( mysql2date( 'j M Y', $pay->created_at ) ) . '</td><th scope="row">' . esc_html( (string) $pay->name ) . '</th><td>' . esc_html( (string) $method ) . '</td><td class="num">' . esc_html( BSA_Dashboard::money( $pay->amount ) ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			}
			echo '<p class="bsa-actions"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=sm-staff-invoices' ) ) . '">' . esc_html__( 'Fee invoices', 'bsa-school-management' ) . '</a><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . BSA_Finance::PAGE ) ) . '">' . esc_html__( 'Fee Reports', 'bsa-school-management' ) . '</a></p></section>';
		}

		if ( ! $s['class'] && ! $s['subjects'] && ! $s['finance'] ) {
			echo '<section class="bsa-card"><p>' . esc_html__( 'Welcome to the BSA staff area. Your screens are in the menu on the left.', 'bsa-school-management' ) . '</p></section>';
		}
		echo '</div>';
	}
}
