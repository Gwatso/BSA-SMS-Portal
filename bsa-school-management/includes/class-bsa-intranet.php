<?php
/**
 * BSA Intranet (/intranet): the staff hub. News, announcements, notices (with
 * acknowledgement and read receipts), achievements, events, a resources library linking
 * to OneDrive / Google Drive, a sliding "top stories this week" hero, tasks drawn from
 * the school data, and daily attendance statistics that can be shared to WhatsApp.
 *
 * - Staff only (anyone with a Weblizar staff record, and the owner). Learners and
 *   parents are sent to My Account; visitors to sign in.
 * - Only the owner and school administrators post (owner decision 2026-10-15).
 * - Content types are not public: no front-end URLs, no REST, no search or sitemap;
 *   everything is shown through /intranet after the access check.
 * - WhatsApp: no automatic posting into an existing group exists through WhatsApp's
 *   official channels, so sharing is one tap by a person (image on phones, or text).
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Staff intranet.
 */
class BSA_Intranet {

	const MENU     = 'bsa-intranet';
	const REWRITE  = '2';
	const PER_PAGE = 12;

	/**
	 * Content types: key => [plural, singular, view].
	 */
	const TYPES = array(
		'bsa_news'         => array( 'News', 'News article', 'news' ),
		'bsa_announcement' => array( 'Announcements', 'Announcement', 'news' ),
		'bsa_notice'       => array( 'Notices', 'Notice', 'notices' ),
		'bsa_achievement'  => array( 'Achievements', 'Achievement', 'achievements' ),
		'bsa_event'        => array( 'Events', 'Event', 'calendar' ),
		'bsa_resource'     => array( 'Resources', 'Resource', 'resources' ),
	);

	const RESOURCE_CATEGORIES = array( 'Academic', 'Administration', 'Policies', 'Teaching Resources', 'Templates', 'Registers', 'Assessments', 'Departments', 'Circulars', 'Forms', 'Meeting Documents', 'Past Papers' );
	const EVENT_TYPES         = array( 'event' => 'Event', 'meeting' => 'Meeting', 'exam' => 'Exam', 'deadline' => 'Deadline', 'holiday' => 'Holiday' );
	const URGENCY             = array( 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent' );
	const ACHIEVEMENT_TYPES   = array( 'Academic', 'Sport', 'Culture', 'Competition', 'Staff' );

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 5 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_caps' ), 10, 4 );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 6 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'admin_post_bsa_intranet_ack', array( __CLASS__, 'handle_ack' ) );
	}

	/**
	 * Intranet URL.
	 *
	 * @param string $path Sub-path, e.g. 'news' or 'item/12'.
	 * @param array  $args Query arguments.
	 * @return string
	 */
	public static function url( $path = '', array $args = array() ) {
		$url = home_url( '/intranet/' . ( '' === $path ? '' : trailingslashit( $path ) ) );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/**
	 * Whether a user may open the intranet: the owner or anyone with a staff record.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_staff( $user_id ) {
		return (bool) $user_id && BSA_Access_Guard::is_staff( $user_id );
	}

	/**
	 * Whether a user may post: the owner and school administrators.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function can_edit( $user_id ) {
		return (bool) $user_id && BSA_School::is_school_admin( $user_id );
	}

	/**
	 * Primitive capabilities of the intranet content types.
	 *
	 * @return string[]
	 */
	public static function edit_caps() {
		$caps = array();
		foreach ( array( 'edit', 'edit_others', 'publish', 'read_private', 'delete', 'delete_private', 'delete_published', 'delete_others', 'edit_private', 'edit_published' ) as $c ) {
			$caps[] = $c . '_bsa_intranet_items';
		}
		return $caps;
	}

	/**
	 * Grants the intranet capabilities: read to staff, editing to the owner and school
	 * administrators. Reads the user's stored capabilities and staff record directly
	 * (calling user_can() here would recurse).
	 *
	 * @param array   $allcaps All capabilities of the user.
	 * @param array   $caps    Requested primitive capabilities.
	 * @param array   $args    Arguments.
	 * @param WP_User $user    User.
	 * @return array
	 */
	public static function grant_caps( $allcaps, $caps, $args, $user ) {
		$id = $user instanceof WP_User ? (int) $user->ID : 0;
		if ( ! $id ) {
			return $allcaps;
		}
		$owner = ! empty( $allcaps['manage_options'] );
		$staff = BSA_School::staff( $id );
		if ( ! $owner && ! $staff ) {
			return $allcaps;
		}
		$allcaps['read_bsa_intranet'] = true;
		$admin_key                    = class_exists( 'WLSM_M_Role' ) ? WLSM_M_Role::get_admin_key() : 'admin';
		if ( $owner || ( $staff && $admin_key === $staff->role ) ) {
			foreach ( self::edit_caps() as $cap ) {
				$allcaps[ $cap ] = true;
			}
			$allcaps['upload_files'] = true;
		}
		return $allcaps;
	}

	/**
	 * Content types and the /intranet routes.
	 */
	public static function register() {
		foreach ( self::TYPES as $type => $t ) {
			register_post_type(
				$type,
				array(
					'labels'              => array(
						'name'               => $t[0],
						'singular_name'      => $t[1],
						'add_new'            => __( 'Add new', 'bsa-school-management' ),
						/* translators: %s: content type. */
						'add_new_item'       => sprintf( __( 'Add %s', 'bsa-school-management' ), strtolower( $t[1] ) ),
						/* translators: %s: content type. */
						'edit_item'          => sprintf( __( 'Edit %s', 'bsa-school-management' ), strtolower( $t[1] ) ),
						'all_items'          => $t[0],
						'search_items'       => __( 'Search', 'bsa-school-management' ),
						'not_found'          => __( 'Nothing yet.', 'bsa-school-management' ),
						'not_found_in_trash' => __( 'Nothing in the bin.', 'bsa-school-management' ),
					),
					'public'              => false,
					'publicly_queryable'  => false,
					'exclude_from_search' => true,
					'show_ui'             => true,
					'show_in_menu'        => self::MENU,
					'show_in_nav_menus'   => false,
					'show_in_admin_bar'   => false,
					'show_in_rest'        => false,
					'has_archive'         => false,
					'rewrite'             => false,
					'query_var'           => false,
					'supports'            => 'bsa_resource' === $type ? array( 'title', 'excerpt', 'author' ) : array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ),
					'capability_type'     => array( 'bsa_intranet_item', 'bsa_intranet_items' ),
					'capabilities'        => array( 'read' => 'read_bsa_intranet' ),
					'map_meta_cap'        => true,
				)
			);
		}
		add_rewrite_rule( '^intranet/?$', 'index.php?bsa_intranet=home', 'top' );
		add_rewrite_rule( '^intranet/(news|notices|achievements|resources|calendar|search)/?$', 'index.php?bsa_intranet=$matches[1]', 'top' );
		add_rewrite_rule( '^intranet/item/([0-9]+)/?$', 'index.php?bsa_intranet=item&bsa_item=$matches[1]', 'top' );
		if ( get_option( 'bsa_sm_intranet_rewrite' ) !== self::REWRITE ) {
			flush_rewrite_rules( false );
			update_option( 'bsa_sm_intranet_rewrite', self::REWRITE, false );
		}
	}

	/**
	 * Query variables of the routes.
	 *
	 * @param string[] $vars Public query variables.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'bsa_intranet';
		$vars[] = 'bsa_item';
		return $vars;
	}

	/**
	 * "Intranet" in wp-admin: the content menu for those who post, a link for other staff.
	 */
	public static function register_menu() {
		$user_id = get_current_user_id();
		if ( self::can_edit( $user_id ) ) {
			add_menu_page( __( 'Intranet', 'bsa-school-management' ), __( 'Intranet', 'bsa-school-management' ), 'edit_bsa_intranet_items', self::MENU, array( __CLASS__, 'render_admin' ), 'dashicons-groups', 3 );
			add_submenu_page( self::MENU, __( 'Overview', 'bsa-school-management' ), __( 'Overview', 'bsa-school-management' ), 'edit_bsa_intranet_items', self::MENU, array( __CLASS__, 'render_admin' ), 0 );
			// "Add new" screens must be registered under this menu, or WordPress matches
			// them to the Posts menu, which school administrators cannot use. Hidden in
			// the menu; the overview and list screens link to them.
			foreach ( self::TYPES as $type => $t ) {
				/* translators: %s: content type. */
				add_submenu_page( self::MENU, sprintf( __( 'Add %s', 'bsa-school-management' ), strtolower( $t[1] ) ), sprintf( __( 'Add %s', 'bsa-school-management' ), strtolower( $t[1] ) ), 'edit_bsa_intranet_items', 'post-new.php?post_type=' . $type );
			}
			add_action(
				'admin_head',
				static function () {
					echo '<style>#adminmenu a[href^="post-new.php?post_type=bsa_"]{display:none}</style>';
				}
			);
		} elseif ( self::is_staff( $user_id ) ) {
			add_menu_page( __( 'Intranet', 'bsa-school-management' ), __( 'Intranet', 'bsa-school-management' ), 'read', self::url(), '', 'dashicons-groups', 3 );
		}
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * Content
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Kind label of a post.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function kind( $post ) {
		return self::TYPES[ $post->post_type ][1] ?? '';
	}

	/**
	 * Short summary: the excerpt, or the start of the text.
	 *
	 * @param WP_Post $post  Post.
	 * @param int     $words Words.
	 * @return string Plain text.
	 */
	public static function summary( $post, $words = 28 ) {
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
		return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $text ) ), $words, '…' );
	}

	/**
	 * Whether a notice is past its "show until" date.
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $today Y-m-d.
	 * @return bool
	 */
	public static function expired( $post, $today = '' ) {
		$until = (string) get_post_meta( $post->ID, '_bsa_until', true );
		return '' !== $until && $until < ( $today ? $today : current_time( 'Y-m-d' ) );
	}

	/**
	 * Published posts of some types.
	 *
	 * @param string[] $types Post types.
	 * @param array    $args  Extra WP_Query arguments.
	 * @return WP_Post[]
	 */
	public static function posts( array $types, array $args = array() ) {
		$q = new WP_Query(
			array_merge(
				array(
					'post_type'           => $types,
					'post_status'         => 'publish',
					'posts_per_page'      => 50,
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
					'suppress_filters'    => false,
				),
				$args
			)
		);
		return $q->posts;
	}

	/**
	 * Active notices (not expired), most urgent first, newest first within urgency.
	 *
	 * @return WP_Post[]
	 */
	public static function notices() {
		$rank = array( 'urgent' => 0, 'high' => 1, 'normal' => 2 );
		$out  = array_values(
			array_filter(
				self::posts( array( 'bsa_notice' ), array( 'posts_per_page' => 100 ) ),
				static function ( $p ) {
					return ! self::expired( $p );
				}
			)
		);
		usort(
			$out,
			static function ( $a, $b ) use ( $rank ) {
				$ua = $rank[ get_post_meta( $a->ID, '_bsa_urgency', true ) ] ?? 2;
				$ub = $rank[ get_post_meta( $b->ID, '_bsa_urgency', true ) ] ?? 2;
				return array( $ua, strtotime( $b->post_date ) ) <=> array( $ub, strtotime( $a->post_date ) );
			}
		);
		return $out;
	}

	/**
	 * Events from a date onwards (and term dates), soonest first.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<int,array{date:string, end:string, time:string, title:string, type:string, location:string, url:string, id:int}>
	 */
	public static function events( $from, $to ) {
		$out = array();
		foreach ( self::posts( array( 'bsa_event' ), array( 'posts_per_page' => 200 ) ) as $p ) {
			$date = (string) get_post_meta( $p->ID, '_bsa_date', true );
			$end  = (string) get_post_meta( $p->ID, '_bsa_end', true );
			$end  = $end ? $end : $date;
			if ( ! $date || $end < $from || $date > $to ) {
				continue;
			}
			$out[] = array( 'date' => $date, 'end' => $end, 'time' => (string) get_post_meta( $p->ID, '_bsa_time', true ), 'title' => get_the_title( $p ), 'type' => (string) get_post_meta( $p->ID, '_bsa_type', true ), 'location' => (string) get_post_meta( $p->ID, '_bsa_location', true ), 'url' => self::url( 'item/' . $p->ID ), 'id' => (int) $p->ID );
		}
		$sessions = BSA_School::sessions();
		foreach ( BSA_Terms::all() as $session => $terms ) {
			foreach ( $terms as $term => $d ) {
				foreach ( array( 'start' => __( 'Term %1$d begins (%2$s)', 'bsa-school-management' ), 'end' => __( 'Term %1$d ends (%2$s)', 'bsa-school-management' ) ) as $key => $label ) {
					if ( ! empty( $d[ $key ] ) && $d[ $key ] >= $from && $d[ $key ] <= $to ) {
						$out[] = array( 'date' => $d[ $key ], 'end' => $d[ $key ], 'time' => '', 'title' => sprintf( $label, $term, $sessions[ $session ] ?? '' ), 'type' => 'term', 'location' => '', 'url' => '', 'id' => 0 );
					}
				}
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['date'], $a['time'] ) <=> array( $b['date'], $b['time'] );
			}
		);
		return $out;
	}

	/**
	 * Top stories for the hero slider: featured posts (last 30 days), urgent notices,
	 * events in the next 7 days, and everything published in the last 7 days.
	 *
	 * @param int $limit Most slides.
	 * @return array<int,array{id:int, kind:string, title:string, summary:string, url:string, image:string, when:string}>
	 */
	public static function slides( $limit = 8 ) {
		$now      = current_time( 'mysql' );
		$today    = current_time( 'Y-m-d' );
		$types    = array( 'bsa_news', 'bsa_announcement', 'bsa_notice', 'bsa_achievement' );
		$featured = self::posts( $types, array( 'meta_key' => '_bsa_pin', 'meta_value' => '1', 'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' -30 days' ) ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$recent   = self::posts( $types, array( 'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' -7 days' ) ) ) ) ) );
		$urgent   = array();
		$others   = array();
		foreach ( $recent as $p ) {
			if ( 'bsa_notice' === $p->post_type && 'urgent' === get_post_meta( $p->ID, '_bsa_urgency', true ) ) {
				$urgent[] = $p;
			} else {
				$others[] = $p;
			}
		}
		$seen  = array();
		$out   = array();
		$add   = static function ( $p ) use ( &$seen, &$out, $today ) {
			if ( isset( $seen[ $p->ID ] ) || ( 'bsa_notice' === $p->post_type && self::expired( $p, $today ) ) ) {
				return;
			}
			$seen[ $p->ID ] = true;
			$image          = has_post_thumbnail( $p ) ? (string) get_the_post_thumbnail_url( $p, 'large' ) : '';
			$out[]          = array( 'id' => (int) $p->ID, 'kind' => self::kind( $p ), 'title' => get_the_title( $p ), 'summary' => self::summary( $p ), 'url' => self::url( 'item/' . $p->ID ), 'image' => $image, 'when' => mysql2date( 'j M Y', $p->post_date ) );
		};
		foreach ( array_merge( $featured, $urgent ) as $p ) {
			$add( $p );
		}
		foreach ( self::events( $today, gmdate( 'Y-m-d', strtotime( $today . ' +7 days' ) ) ) as $e ) {
			if ( $e['id'] && ! isset( $seen[ $e['id'] ] ) ) {
				$p              = get_post( $e['id'] );
				$seen[ $p->ID ] = true;
				$out[]          = array( 'id' => (int) $p->ID, 'kind' => self::EVENT_TYPES[ $e['type'] ] ?? __( 'Event', 'bsa-school-management' ), 'title' => $e['title'], 'summary' => self::summary( $p ), 'url' => $e['url'], 'image' => has_post_thumbnail( $p ) ? (string) get_the_post_thumbnail_url( $p, 'large' ) : '', 'when' => mysql2date( 'l j M', $e['date'] ) . ( $e['time'] ? ', ' . $e['time'] : '' ) . ( $e['location'] ? ' · ' . $e['location'] : '' ) );
			}
		}
		foreach ( $others as $p ) {
			$add( $p );
		}
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Document provider of a link (OneDrive, SharePoint, Google Drive/Docs/Sheets/…).
	 *
	 * @param string $url Link.
	 * @return array{id:string, label:string}
	 */
	public static function provider( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( preg_match( '/(^|\.)(onedrive\.live\.com|1drv\.ms)$/', $host ) || preg_match( '/-my\.sharepoint\.com$/', $host ) ) {
			return array( 'id' => 'onedrive', 'label' => 'OneDrive' );
		}
		if ( preg_match( '/(^|\.)sharepoint\.com$/', $host ) ) {
			return array( 'id' => 'sharepoint', 'label' => 'SharePoint' );
		}
		if ( 'docs.google.com' === $host ) {
			$map = array( '/document/' => 'Google Docs', '/spreadsheets/' => 'Google Sheets', '/presentation/' => 'Google Slides', '/forms/' => 'Google Forms' );
			foreach ( $map as $part => $label ) {
				if ( 0 === strpos( $path, $part ) ) {
					return array( 'id' => sanitize_title( $label ), 'label' => $label );
				}
			}
			return array( 'id' => 'google-drive', 'label' => 'Google Drive' );
		}
		if ( preg_match( '/(^|\.)drive\.google\.com$/', $host ) || 'forms.gle' === $host ) {
			return array( 'id' => 'forms.gle' === $host ? 'google-forms' : 'google-drive', 'label' => 'forms.gle' === $host ? 'Google Forms' : 'Google Drive' );
		}
		return array( 'id' => 'link', 'label' => __( 'Link', 'bsa-school-management' ) );
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * Acknowledgements
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Staff accounts that are expected to read notices.
	 *
	 * @return array<int,string> user ID => name.
	 */
	public static function staff_users() {
		global $wpdb;
		$out = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT u.ID, u.display_name FROM {$wpdb->prefix}wlsm_staff s JOIN {$wpdb->users} u ON u.ID = s.user_id WHERE s.school_id = %d ORDER BY u.display_name", BSA_School::SCHOOL_ID ) ) as $r ) {
			$out[ (int) $r->ID ] = $r->display_name;
		}
		return $out;
	}

	/**
	 * Who has acknowledged a notice, and when.
	 *
	 * @param int $post_id Notice.
	 * @return array<int,string> user ID => date-time.
	 */
	public static function acknowledgements( $post_id ) {
		$out = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( preg_match( '/^_bsa_ack_(\d+)$/', $key, $m ) ) {
				$out[ (int) $m[1] ] = (string) $values[0];
			}
		}
		return $out;
	}

	/**
	 * Whether a notice asks staff to acknowledge it.
	 *
	 * @param int $post_id Notice.
	 * @return bool
	 */
	public static function needs_ack( $post_id ) {
		return 'bsa_notice' === get_post_type( $post_id ) && '1' === get_post_meta( $post_id, '_bsa_ack', true );
	}

	/**
	 * Records an acknowledgement.
	 *
	 * @param int $post_id Notice.
	 * @param int $user_id Staff member.
	 * @return bool
	 */
	public static function acknowledge( $post_id, $user_id ) {
		if ( ! self::is_staff( $user_id ) || ! self::needs_ack( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return false;
		}
		if ( ! get_post_meta( $post_id, '_bsa_ack_' . $user_id, true ) ) {
			update_post_meta( $post_id, '_bsa_ack_' . $user_id, current_time( 'mysql' ) );
		}
		return true;
	}

	/**
	 * Active notices the user still has to acknowledge.
	 *
	 * @param int $user_id User ID.
	 * @return WP_Post[]
	 */
	public static function pending_acks( $user_id ) {
		return array_values(
			array_filter(
				self::notices(),
				static function ( $p ) use ( $user_id ) {
					return self::needs_ack( $p->ID ) && ! get_post_meta( $p->ID, '_bsa_ack_' . $user_id, true );
				}
			)
		);
	}

	/**
	 * Acknowledge button (admin-post).
	 */
	public static function handle_ack() {
		$post_id = absint( $_POST['notice'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'bsa_intranet_ack_' . $post_id );
		$ok = self::acknowledge( $post_id, get_current_user_id() );
		if ( ! $ok ) {
			wp_die( esc_html__( 'This notice cannot be acknowledged.', 'bsa-school-management' ), 403 );
		}
		wp_safe_redirect( self::url( 'item/' . $post_id, array( 'done' => 'ack' ) ) );
		exit;
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * Attendance and tasks
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Daily register figures for the latest register day (today when taken).
	 *
	 * @return array{date:string, today:bool, classes:array, attended:int, possible:int, absent:int, late:int, unmarked:string[], trend:array<string,float|null>}
	 */
	public static function attendance() {
		$today   = current_time( 'Y-m-d' );
		$term    = BSA_Terms::current( $today );
		$session = $term ? (int) $term['session'] : BSA_School::current_session();
		$day     = $today;
		$att     = BSA_Dashboard::attendance( $today, $session );
		if ( ! array_sum( wp_list_pluck( $att, 'marked' ) ) ) {
			$last = BSA_Dashboard::last_register_day( $today );
			if ( $last ) {
				$day = $last;
				$att = BSA_Dashboard::attendance( $last, $session );
			}
		}
		$out = array( 'date' => $day, 'today' => $day === $today, 'classes' => array(), 'attended' => 0, 'possible' => 0, 'absent' => 0, 'late' => 0, 'unmarked' => array(), 'trend' => array() );
		foreach ( $att as $section => $c ) {
			$sec = BSA_School::section( $section );
			if ( ! $sec || ! $c['learners'] ) {
				continue;
			}
			$attended = $c['present'] + $c['late'];
			$possible = $attended + $c['absent'];
			$out['classes'][] = array( 'title' => $sec->title, 'learners' => $c['learners'], 'marked' => $c['marked'], 'attended' => $attended, 'possible' => $possible, 'absent' => $c['absent'], 'late' => $c['late'] );
			if ( ! $c['marked'] ) {
				$out['unmarked'][] = $sec->title;
			}
			$out['attended'] += $attended;
			$out['possible'] += $possible;
			$out['absent']   += $c['absent'];
			$out['late']     += $c['late'];
		}
		$out['trend'] = self::attendance_trend( $session, $day );
		return $out;
	}

	/**
	 * Whole-school attendance rate on the last register days (oldest first).
	 *
	 * @param int    $session_id Session ID.
	 * @param string $to         Y-m-d (last day).
	 * @param int    $days       Register days.
	 * @return array<string,float> "Mon 12" => percentage.
	 */
	public static function attendance_trend( $session_id, $to, $days = 10 ) {
		global $wpdb;
		$p    = $wpdb->prefix;
		$from = gmdate( 'Y-m-d', strtotime( $to . ' -45 days' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT a.attendance_date AS d, SUM(a.status IN ('p','l')) AS att, SUM(a.status IN ('p','l','a')) AS pos FROM {$p}wlsm_attendance a JOIN {$p}wlsm_student_records sr ON sr.ID = a.student_record_id WHERE a.subject_id IS NULL AND sr.session_id = %d AND sr.is_active = 1 AND a.attendance_date BETWEEN %s AND %s GROUP BY a.attendance_date HAVING pos > 0 ORDER BY a.attendance_date DESC LIMIT %d", $session_id, $from, $to, $days ) );
		$out  = array();
		foreach ( array_reverse( $rows ) as $r ) {
			$out[ mysql2date( 'D j', $r->d ) ] = 100 * $r->att / $r->pos;
		}
		return $out;
	}

	/**
	 * Attendance summary for sharing (WhatsApp formatting: *bold*).
	 *
	 * @param array $a From attendance().
	 * @return string
	 */
	public static function attendance_text( array $a ) {
		$pct   = static function ( $x, $y ) {
			return $y ? round( 100 * $x / $y ) . '%' : '–';
		};
		/* translators: %s: date. */
		$lines = array( '*' . sprintf( __( 'BSA attendance, %s', 'bsa-school-management' ), mysql2date( 'l j F Y', $a['date'] ) ) . '*' );
		/* translators: 1: percentage, 2: attended, 3: possible. */
		$lines[] = sprintf( __( 'Whole school: %1$s (%2$d of %3$d)', 'bsa-school-management' ), $pct( $a['attended'], $a['possible'] ), $a['attended'], $a['possible'] );
		foreach ( $a['classes'] as $c ) {
			/* translators: 1: class, 2: percentage, 3: attended, 4: possible. */
			$lines[] = $c['marked'] ? sprintf( __( '%1$s: %2$s (%3$d/%4$d)', 'bsa-school-management' ), $c['title'], $pct( $c['attended'], $c['possible'] ), $c['attended'], $c['possible'] ) : sprintf( /* translators: %s: class. */ __( '%s: not marked', 'bsa-school-management' ), $c['title'] );
		}
		/* translators: 1: absent, 2: late. */
		$lines[] = sprintf( __( 'Absent: %1$d · Late: %2$d', 'bsa-school-management' ), $a['absent'], $a['late'] );
		$lines[] = __( 'Present and late count as attended. BSA Intranet', 'bsa-school-management' );
		return implode( "\n", $lines );
	}

	/**
	 * "For you today": unread notices and work from the school data.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array{text:string, url:string, level:string}>
	 */
	public static function tasks( $user_id ) {
		$out = array();
		foreach ( self::pending_acks( $user_id ) as $p ) {
			/* translators: %s: notice title. */
			$out[] = array( 'text' => sprintf( __( 'Read and acknowledge: %s', 'bsa-school-management' ), get_the_title( $p ) ), 'url' => self::url( 'item/' . $p->ID ), 'level' => 'warning' );
		}
		if ( BSA_School::is_school_admin( $user_id ) ) {
			foreach ( BSA_Dashboard::attention( BSA_Dashboard::state() ) as $item ) {
				$out[] = array( 'text' => $item['text'], 'url' => $item['link'], 'level' => $item['level'] );
			}
			return $out;
		}
		$home = BSA_Staff_Area::home_state( $user_id );
		if ( $home['class'] ) {
			$c = $home['class'];
			if ( $home['school'] && $c['learners'] && ( ! $c['register'] || ! $c['register']['marked'] ) ) {
				/* translators: %s: class. */
				$out[] = array( 'text' => sprintf( __( 'Mark today\'s register for %s.', 'bsa-school-management' ), $c['title'] ), 'url' => admin_url( 'admin.php?page=' . BSA_Register::PAGE . '&section=' . $c['section'] ), 'level' => 'warning' );
			}
			$missing = array_sum( wp_list_pluck( $c['progress'], 'expected' ) ) - array_sum( wp_list_pluck( $c['progress'], 'entered' ) );
			if ( $home['term'] && $missing > 0 ) {
				/* translators: 1: number, 2: class. */
				$out[] = array( 'text' => sprintf( _n( '%1$d exam mark still to enter for %2$s.', '%1$d exam marks still to enter for %2$s.', $missing, 'bsa-school-management' ), $missing, $c['title'] ), 'url' => admin_url( 'admin.php?page=' . BSA_Marks::PAGE . '&section=' . $c['section'] ), 'level' => 'info' );
			}
			if ( ! empty( $c['at_risk'] ) ) {
				/* translators: %d: learners. */
				$out[] = array( 'text' => sprintf( _n( '%d learner in your class is at risk.', '%d learners in your class are at risk.', $c['at_risk'], 'bsa-school-management' ), $c['at_risk'] ), 'url' => admin_url( 'admin.php?page=' . BSA_Results::PAGE . '&tab=risk' ), 'level' => 'warning' );
			}
		}
		foreach ( $home['subjects'] as $s ) {
			if ( $s['expected'] > $s['entered'] ) {
				/* translators: 1: number, 2: subject, 3: class. */
				$out[] = array( 'text' => sprintf( __( '%1$d %2$s marks still to enter for %3$s.', 'bsa-school-management' ), $s['expected'] - $s['entered'], $s['label'], $s['class'] ), 'url' => admin_url( 'admin.php?page=' . BSA_Marks::PAGE . '&section=' . $s['section'] . '&subject=' . $s['subject'] ), 'level' => 'info' );
			}
		}
		if ( $home['finance'] && $home['finance']['fees']['learners_owing'] ) {
			/* translators: %d: learners. */
			$out[] = array( 'text' => sprintf( _n( '%d learner owes fees.', '%d learners owe fees.', $home['finance']['fees']['learners_owing'], 'bsa-school-management' ), $home['finance']['fees']['learners_owing'] ), 'url' => admin_url( 'admin.php?page=' . BSA_Finance::PAGE ), 'level' => 'info' );
		}
		return $out;
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * Admin: meta boxes and overview
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Meta boxes per content type.
	 */
	public static function meta_boxes() {
		foreach ( array_keys( self::TYPES ) as $type ) {
			add_meta_box( 'bsa_intranet_meta', __( 'Intranet details', 'bsa-school-management' ), array( __CLASS__, 'render_meta_box' ), $type, 'side', 'high' );
		}
		add_meta_box( 'bsa_intranet_receipts', __( 'Read receipts', 'bsa-school-management' ), array( __CLASS__, 'render_receipts_box' ), 'bsa_notice', 'normal', 'default' );
	}

	/**
	 * Fields of the meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'bsa_intranet_meta', 'bsa_intranet_meta_nonce' );
		$get    = static function ( $key ) use ( $post ) {
			return (string) get_post_meta( $post->ID, $key, true );
		};
		$select = static function ( $name, array $options, $value ) {
			$out = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" class="widefat">';
			foreach ( $options as $key => $label ) {
				$key  = is_int( $key ) ? $label : $key;
				$out .= '<option value="' . esc_attr( $key ) . '"' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
			}
			return $out . '</select>';
		};
		$field  = static function ( $label, $name, $html ) {
			echo '<p><label for="' . esc_attr( $name ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>' . $html . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		};
		switch ( $post->post_type ) {
			case 'bsa_notice':
				$field( __( 'Urgency', 'bsa-school-management' ), 'bsa_urgency', $select( 'bsa_urgency', self::URGENCY, $get( '_bsa_urgency' ) ? $get( '_bsa_urgency' ) : 'normal' ) );
				$field( __( 'Show until (optional)', 'bsa-school-management' ), 'bsa_until', '<input type="date" class="widefat" name="bsa_until" id="bsa_until" value="' . esc_attr( $get( '_bsa_until' ) ) . '">' );
				echo '<p><label><input type="checkbox" name="bsa_ack" value="1"' . checked( $get( '_bsa_ack' ), '1', false ) . '> ' . esc_html__( 'Staff must acknowledge this notice', 'bsa-school-management' ) . '</label></p>';
				break;
			case 'bsa_event':
				$field( __( 'Date', 'bsa-school-management' ), 'bsa_date', '<input type="date" class="widefat" name="bsa_date" id="bsa_date" required value="' . esc_attr( $get( '_bsa_date' ) ) . '">' );
				$field( __( 'Ends (optional, several days)', 'bsa-school-management' ), 'bsa_end', '<input type="date" class="widefat" name="bsa_end" id="bsa_end" value="' . esc_attr( $get( '_bsa_end' ) ) . '">' );
				$field( __( 'Time (optional)', 'bsa-school-management' ), 'bsa_time', '<input type="time" class="widefat" name="bsa_time" id="bsa_time" value="' . esc_attr( $get( '_bsa_time' ) ) . '">' );
				$field( __( 'Place (optional)', 'bsa-school-management' ), 'bsa_location', '<input type="text" class="widefat" name="bsa_location" id="bsa_location" value="' . esc_attr( $get( '_bsa_location' ) ) . '">' );
				$field( __( 'Type', 'bsa-school-management' ), 'bsa_type', $select( 'bsa_type', self::EVENT_TYPES, $get( '_bsa_type' ) ? $get( '_bsa_type' ) : 'event' ) );
				break;
			case 'bsa_resource':
				$field( __( 'Link (OneDrive, Google Drive, SharePoint…)', 'bsa-school-management' ), 'bsa_url', '<input type="url" class="widefat" name="bsa_url" id="bsa_url" required placeholder="https://" value="' . esc_attr( $get( '_bsa_url' ) ) . '">' );
				$field( __( 'Category', 'bsa-school-management' ), 'bsa_category', $select( 'bsa_category', self::RESOURCE_CATEGORIES, $get( '_bsa_category' ) ) );
				$field( __( 'Department (optional)', 'bsa-school-management' ), 'bsa_department', '<input type="text" class="widefat" name="bsa_department" id="bsa_department" value="' . esc_attr( $get( '_bsa_department' ) ) . '">' );
				echo '<p class="description">' . esc_html__( 'Share the file in OneDrive or Google Drive with the staff who need it, then paste its link here. The intranet only links to it.', 'bsa-school-management' ) . '</p>';
				break;
			case 'bsa_achievement':
				$field( __( 'Category', 'bsa-school-management' ), 'bsa_category', $select( 'bsa_category', self::ACHIEVEMENT_TYPES, $get( '_bsa_category' ) ) );
				break;
		}
		if ( in_array( $post->post_type, array( 'bsa_news', 'bsa_announcement', 'bsa_notice', 'bsa_achievement' ), true ) ) {
			echo '<p><label><input type="checkbox" name="bsa_pin" value="1"' . checked( $get( '_bsa_pin' ), '1', false ) . '> ' . esc_html__( 'Feature in the top stories slider (for 30 days)', 'bsa-school-management' ) . '</label></p><p class="description">' . esc_html__( 'Everything published in the last 7 days is shown there anyway.', 'bsa-school-management' ) . '</p>';
		}
	}

	/**
	 * Saves the meta box fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save_meta( $post_id, $post ) {
		if ( ! isset( self::TYPES[ $post->post_type ] ) || ! isset( $_POST['bsa_intranet_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bsa_intranet_meta_nonce'] ), 'bsa_intranet_meta' ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = wp_unslash( $_POST );
		self::save_fields( $post_id, $post->post_type, is_array( $in ) ? $in : array() );
	}

	/**
	 * Validates and stores the fields of a content type (also used by tests).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Post type.
	 * @param array  $in      Unslashed input.
	 */
	public static function save_fields( $post_id, $type, array $in ) {
		$date = static function ( $v ) {
			$v = trim( (string) $v );
			return preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $v : '';
		};
		$set  = static function ( $key, $value ) use ( $post_id ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		};
		if ( in_array( $type, array( 'bsa_news', 'bsa_announcement', 'bsa_notice', 'bsa_achievement' ), true ) ) {
			$set( '_bsa_pin', empty( $in['bsa_pin'] ) ? '' : '1' );
		}
		switch ( $type ) {
			case 'bsa_notice':
				$u = sanitize_key( $in['bsa_urgency'] ?? 'normal' );
				$set( '_bsa_urgency', isset( self::URGENCY[ $u ] ) ? $u : 'normal' );
				$set( '_bsa_until', $date( $in['bsa_until'] ?? '' ) );
				$set( '_bsa_ack', empty( $in['bsa_ack'] ) ? '' : '1' );
				break;
			case 'bsa_event':
				$start = $date( $in['bsa_date'] ?? '' );
				$end   = $date( $in['bsa_end'] ?? '' );
				$set( '_bsa_date', $start );
				$set( '_bsa_end', ( $end && $start && $end > $start ) ? $end : '' );
				$time = trim( (string) ( $in['bsa_time'] ?? '' ) );
				$set( '_bsa_time', preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '' );
				$set( '_bsa_location', sanitize_text_field( $in['bsa_location'] ?? '' ) );
				$t = sanitize_key( $in['bsa_type'] ?? 'event' );
				$set( '_bsa_type', isset( self::EVENT_TYPES[ $t ] ) ? $t : 'event' );
				break;
			case 'bsa_resource':
				$url = esc_url_raw( trim( (string) ( $in['bsa_url'] ?? '' ) ), array( 'https' ) );
				$set( '_bsa_url', $url );
				$cat = sanitize_text_field( $in['bsa_category'] ?? '' );
				$set( '_bsa_category', in_array( $cat, self::RESOURCE_CATEGORIES, true ) ? $cat : 'Administration' );
				$set( '_bsa_department', sanitize_text_field( $in['bsa_department'] ?? '' ) );
				break;
			case 'bsa_achievement':
				$cat = sanitize_text_field( $in['bsa_category'] ?? '' );
				$set( '_bsa_category', in_array( $cat, self::ACHIEVEMENT_TYPES, true ) ? $cat : 'Academic' );
				break;
		}
	}

	/**
	 * Read receipts in the notice editor.
	 *
	 * @param WP_Post $post Notice.
	 */
	public static function render_receipts_box( $post ) {
		echo self::receipts_html( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
	}

	/**
	 * Who has and has not acknowledged a notice.
	 *
	 * @param int $post_id Notice.
	 * @return string HTML.
	 */
	public static function receipts_html( $post_id ) {
		if ( ! self::needs_ack( $post_id ) ) {
			return '<p>' . esc_html__( 'Tick "Staff must acknowledge this notice" to collect read receipts.', 'bsa-school-management' ) . '</p>';
		}
		$staff = self::staff_users();
		$acks  = self::acknowledgements( $post_id );
		$done  = array_intersect_key( $acks, $staff );
		/* translators: 1: acknowledged, 2: staff. */
		$out  = '<p><strong>' . esc_html( sprintf( __( 'Acknowledged by %1$d of %2$d staff', 'bsa-school-management' ), count( $done ), count( $staff ) ) ) . '</strong></p><ul class="bsi-receipts">';
		foreach ( $staff as $id => $name ) {
			$out .= '<li>' . ( isset( $acks[ $id ] ) ? '<span aria-hidden="true">✓</span> ' . esc_html( $name ) . ' <span class="bsi-muted">' . esc_html( mysql2date( 'j M, H:i', $acks[ $id ] ) ) . '</span>' : '<span aria-hidden="true">○</span> ' . esc_html( $name ) . ' <span class="bsi-muted">' . esc_html__( 'not yet', 'bsa-school-management' ) . '</span>' ) . '</li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Admin overview: what is published and notice receipts.
	 */
	public static function render_admin() {
		if ( ! self::can_edit( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You cannot manage the intranet.', 'bsa-school-management' ), 403 );
		}
		echo '<div class="wrap bsa-dash"><h1>' . esc_html__( 'Intranet', 'bsa-school-management' ) . '</h1>';
		BSA_Dashboard::styles();
		echo '<p class="bsa-dash-sub">' . esc_html__( 'Staff see these on /intranet. Only you and the school administrator can post.', 'bsa-school-management' ) . ' <a class="button button-primary" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open the intranet', 'bsa-school-management' ) . '</a></p>';
		echo '<div class="bsa-tiles">';
		foreach ( self::TYPES as $type => $t ) {
			$n = (int) ( wp_count_posts( $type )->publish ?? 0 );
			echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html( $t[0] ) . '</div><div class="bsa-tile-value">' . esc_html( number_format_i18n( $n ) ) . '</div><div class="bsa-tile-note"><a href="' . esc_url( admin_url( 'post-new.php?post_type=' . $type ) ) . '">' . esc_html__( 'Add new', 'bsa-school-management' ) . '</a> · <a href="' . esc_url( admin_url( 'edit.php?post_type=' . $type ) ) . '">' . esc_html__( 'All', 'bsa-school-management' ) . '</a></div></div>';
		}
		echo '</div><section class="bsa-card"><h2>' . esc_html__( 'Notices to acknowledge', 'bsa-school-management' ) . '</h2>';
		$any = false;
		foreach ( self::notices() as $p ) {
			if ( self::needs_ack( $p->ID ) ) {
				$any = true;
				echo '<h3><a href="' . esc_url( get_edit_post_link( $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3>' . self::receipts_html( $p->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			}
		}
		if ( ! $any ) {
			echo '<p>' . esc_html__( 'No active notices ask for acknowledgement.', 'bsa-school-management' ) . '</p>';
		}
		echo '</section></div>';
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * Front end
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Serves /intranet: sign-in for visitors, My Account for families, the page for staff.
	 */
	public static function route() {
		$view = (string) get_query_var( 'bsa_intranet' );
		if ( '' === $view ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			auth_redirect();
		}
		if ( ! self::is_staff( $user_id ) ) {
			wp_safe_redirect( BSA_Portal::account_url() );
			exit;
		}
		global $wp_query;
		$wp_query->is_404  = false;
		$wp_query->is_home = false;
		status_header( 200 );
		nocache_headers();
		$title = self::view_title( $view );
		add_filter(
			'pre_get_document_title',
			static function () use ( $title ) {
				return $title . ' ‹ ' . __( 'BSA Intranet', 'bsa-school-management' );
			}
		);
		add_filter(
			'body_class',
			static function ( $classes ) {
				$classes[] = 'bsa-intranet-page';
				return $classes;
			}
		);
		wp_enqueue_style( 'bsa-intranet', plugins_url( 'assets/intranet.css', BSA_SM_FILE ), array(), BSA_SM_VERSION );
		wp_enqueue_script( 'bsa-intranet', plugins_url( 'assets/intranet.js', BSA_SM_FILE ), array(), BSA_SM_VERSION, true );
		get_header();
		echo self::render( $view, $user_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes.
		get_footer();
		exit;
	}

	/**
	 * Title of a view.
	 *
	 * @param string $view View.
	 * @return string
	 */
	public static function view_title( $view ) {
		$titles = array(
			'home'         => __( 'Home', 'bsa-school-management' ),
			'news'         => __( 'News and announcements', 'bsa-school-management' ),
			'notices'      => __( 'Notices', 'bsa-school-management' ),
			'achievements' => __( 'Achievements', 'bsa-school-management' ),
			'resources'    => __( 'Resources', 'bsa-school-management' ),
			'calendar'     => __( 'Calendar', 'bsa-school-management' ),
			'search'       => __( 'Search', 'bsa-school-management' ),
		);
		if ( 'item' === $view ) {
			$p = get_post( absint( get_query_var( 'bsa_item' ) ) );
			return $p && isset( self::TYPES[ $p->post_type ] ) ? get_the_title( $p ) : __( 'Not found', 'bsa-school-management' );
		}
		return $titles[ $view ] ?? $titles['home'];
	}

	/**
	 * The page body for a view.
	 *
	 * @param string $view    View.
	 * @param int    $user_id Viewer.
	 * @param array  $get     Query parameters (default $_GET).
	 * @return string HTML.
	 */
	public static function render( $view, $user_id, $get = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$get   = is_array( $get ) ? $get : wp_unslash( $_GET );
		$nav   = array(
			'home'         => array( '', __( 'Home', 'bsa-school-management' ) ),
			'news'         => array( 'news', __( 'News', 'bsa-school-management' ) ),
			'notices'      => array( 'notices', __( 'Notices', 'bsa-school-management' ) ),
			'achievements' => array( 'achievements', __( 'Achievements', 'bsa-school-management' ) ),
			'resources'    => array( 'resources', __( 'Resources', 'bsa-school-management' ) ),
			'calendar'     => array( 'calendar', __( 'Calendar', 'bsa-school-management' ) ),
		);
		$user  = get_userdata( $user_id );
		$out   = '<div class="bsi"><div class="bsi-bar"><div class="bsi-wrap bsi-bar-inner"><a class="bsi-brand" href="' . esc_url( self::url() ) . '">' . esc_html__( 'BSA Intranet', 'bsa-school-management' ) . '</a><nav class="bsi-nav" aria-label="' . esc_attr__( 'Intranet', 'bsa-school-management' ) . '"><ul>';
		foreach ( $nav as $key => $n ) {
			$out .= '<li><a href="' . esc_url( self::url( $n[0] ) ) . '"' . ( $key === $view ? ' aria-current="page"' : '' ) . '>' . esc_html( $n[1] ) . '</a></li>';
		}
		$out .= '</ul></nav><form class="bsi-search" role="search" method="get" action="' . esc_url( self::url( 'search' ) ) . '"><label class="screen-reader-text" for="bsi-q">' . esc_html__( 'Search the intranet', 'bsa-school-management' ) . '</label><input id="bsi-q" type="search" name="q" placeholder="' . esc_attr__( 'Search', 'bsa-school-management' ) . '" value="' . esc_attr( 'search' === $view ? (string) ( $get['q'] ?? '' ) : '' ) . '"></form>';
		$out .= '<a class="bsi-work" href="' . esc_url( BSA_Staff_Area::home_url( $user_id ) ) . '">' . esc_html__( 'My work', 'bsa-school-management' ) . ' →</a>';
		if ( self::can_edit( $user_id ) ) {
			$out .= '<a class="bsi-work" href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU ) ) . '">' . esc_html__( 'Post', 'bsa-school-management' ) . '</a>';
		}
		$out .= '</div></div><div class="bsi-wrap bsi-body">';
		switch ( $view ) {
			case 'news':
				$out .= self::render_list( array( 'bsa_news', 'bsa_announcement' ), $get, true );
				break;
			case 'notices':
				$out .= self::render_notices( $user_id );
				break;
			case 'achievements':
				$out .= self::render_list( array( 'bsa_achievement' ), $get, false );
				break;
			case 'resources':
				$out .= self::render_resources( $get );
				break;
			case 'calendar':
				$out .= self::render_calendar();
				break;
			case 'search':
				$out .= self::render_search( (string) ( $get['q'] ?? '' ) );
				break;
			case 'item':
				$out .= self::render_item( absint( get_query_var( 'bsa_item' ) ? get_query_var( 'bsa_item' ) : ( $get['item'] ?? 0 ) ), $user_id, $get );
				break;
			default:
				$out .= self::render_home( $user );
		}
		return $out . '</div></div>';
	}

	/**
	 * The sliding top stories hero.
	 *
	 * @param array   $slides From slides().
	 * @param WP_User $user   Viewer.
	 * @return string
	 */
	public static function render_hero( array $slides, $user ) {
		$hour = (int) current_time( 'G' );
		/* translators: %s: name. */
		$greet = sprintf( $hour < 12 ? __( 'Good morning, %s', 'bsa-school-management' ) : ( $hour < 17 ? __( 'Good afternoon, %s', 'bsa-school-management' ) : __( 'Good evening, %s', 'bsa-school-management' ) ), $user ? strtok( $user->display_name, ' ' ) : '' );
		$head  = '<div class="bsi-greet"><p class="bsi-eyebrow">' . esc_html( mysql2date( 'l j F Y', current_time( 'mysql' ) ) ) . '</p><h1>' . esc_html( $greet ) . '</h1></div>';
		if ( ! $slides ) {
			return $head . '<section class="bsi-hero bsi-hero-empty"><div class="bsi-slide-body"><span class="bsi-kind">' . esc_html__( 'This week', 'bsa-school-management' ) . '</span><h2>' . esc_html__( 'No new stories this week.', 'bsa-school-management' ) . '</h2><p>' . esc_html__( 'News, announcements, notices and events posted by the school appear here.', 'bsa-school-management' ) . '</p></div></section>';
		}
		$n   = count( $slides );
		$out = $head . '<section class="bsi-hero" aria-roledescription="' . esc_attr__( 'carousel', 'bsa-school-management' ) . '" aria-label="' . esc_attr__( 'Top stories this week', 'bsa-school-management' ) . '" data-bsi-carousel><div class="bsi-track" aria-live="off">';
		foreach ( $slides as $i => $s ) {
			/* translators: 1: slide, 2: slides. */
			$out .= '<article class="bsi-slide' . ( $s['image'] ? ' has-image' : '' ) . '" role="group" aria-roledescription="' . esc_attr__( 'slide', 'bsa-school-management' ) . '" aria-label="' . esc_attr( sprintf( __( '%1$d of %2$d', 'bsa-school-management' ), $i + 1, $n ) ) . '">';
			if ( $s['image'] ) {
				$out .= '<img class="bsi-slide-img" src="' . esc_url( $s['image'] ) . '" alt="" loading="' . ( $i ? 'lazy' : 'eager' ) . '">';
			}
			$out .= '<div class="bsi-slide-body"><span class="bsi-kind">' . esc_html( $s['kind'] ) . '</span><h2><a href="' . esc_url( $s['url'] ) . '">' . esc_html( $s['title'] ) . '</a></h2>' . ( '' !== $s['summary'] ? '<p>' . esc_html( $s['summary'] ) . '</p>' : '' ) . '<p class="bsi-when">' . esc_html( $s['when'] ) . '</p></div></article>';
		}
		$out .= '</div>';
		if ( $n > 1 ) {
			$out .= '<div class="bsi-hero-controls"><button type="button" class="bsi-prev" aria-label="' . esc_attr__( 'Previous story', 'bsa-school-management' ) . '">‹</button><div class="bsi-dots">';
			for ( $i = 0; $i < $n; $i++ ) {
				/* translators: %d: story number. */
				$out .= '<button type="button" class="bsi-dot" aria-label="' . esc_attr( sprintf( __( 'Show story %d', 'bsa-school-management' ), $i + 1 ) ) . '"></button>';
			}
			$out .= '</div><button type="button" class="bsi-next" aria-label="' . esc_attr__( 'Next story', 'bsa-school-management' ) . '">›</button><button type="button" class="bsi-pause" aria-label="' . esc_attr__( 'Pause the slides', 'bsa-school-management' ) . '" data-play="' . esc_attr__( 'Play the slides', 'bsa-school-management' ) . '" data-pause="' . esc_attr__( 'Pause the slides', 'bsa-school-management' ) . '">❚❚</button></div>';
		}
		return $out . '</section>';
	}

	/**
	 * Home: hero, today's tasks, attendance, notices, news, events, resources.
	 *
	 * @param WP_User|false $user Viewer.
	 * @return string
	 */
	public static function render_home( $user ) {
		$uid = $user ? (int) $user->ID : 0;
		$out = self::render_hero( self::slides(), $user );
		$out .= '<div class="bsi-grid"><div class="bsi-main">';

		// For you today.
		$tasks = self::tasks( $uid );
		$out  .= '<section class="bsi-card bsi-o1"><h2>' . esc_html__( 'For you today', 'bsa-school-management' ) . '</h2>';
		if ( ! $tasks ) {
			$out .= '<p class="bsi-ok"><span aria-hidden="true">✓</span> ' . esc_html__( 'Nothing needs your attention.', 'bsa-school-management' ) . '</p>';
		} else {
			$out .= '<ul class="bsi-tasks">';
			foreach ( $tasks as $t ) {
				$label = 'warning' === $t['level'] ? __( 'Action', 'bsa-school-management' ) : __( 'Note', 'bsa-school-management' );
				$out  .= '<li class="bsi-task bsi-' . esc_attr( $t['level'] ) . '"><span class="bsi-badge">' . esc_html( $label ) . '</span> ' . ( $t['url'] ? '<a href="' . esc_url( $t['url'] ) . '">' . esc_html( $t['text'] ) . '</a>' : esc_html( $t['text'] ) ) . '</li>';
			}
			$out .= '</ul>';
		}
		$out .= '</section>';

		// Notices.
		$notices = array_slice( self::notices(), 0, 4 );
		if ( $notices ) {
			$out .= '<section class="bsi-card bsi-o3"><div class="bsi-card-head"><h2>' . esc_html__( 'Notices', 'bsa-school-management' ) . '</h2><a href="' . esc_url( self::url( 'notices' ) ) . '">' . esc_html__( 'All notices', 'bsa-school-management' ) . ' →</a></div>' . self::notice_list( $notices, $uid ) . '</section>';
		}

		// News and announcements.
		$news = self::posts( array( 'bsa_news', 'bsa_announcement' ), array( 'posts_per_page' => 4 ) );
		$out .= '<section class="bsi-card bsi-o5"><div class="bsi-card-head"><h2>' . esc_html__( 'Latest news and announcements', 'bsa-school-management' ) . '</h2><a href="' . esc_url( self::url( 'news' ) ) . '">' . esc_html__( 'All news', 'bsa-school-management' ) . ' →</a></div>';
		$out .= $news ? self::cards( $news ) : '<p class="bsi-muted">' . esc_html__( 'Nothing posted yet.', 'bsa-school-management' ) . '</p>';
		$out .= '</section>';

		// Achievements.
		$ach = self::posts( array( 'bsa_achievement' ), array( 'posts_per_page' => 3 ) );
		if ( $ach ) {
			$out .= '<section class="bsi-card bsi-o6"><div class="bsi-card-head"><h2>' . esc_html__( 'Achievements', 'bsa-school-management' ) . '</h2><a href="' . esc_url( self::url( 'achievements' ) ) . '">' . esc_html__( 'All achievements', 'bsa-school-management' ) . ' →</a></div>' . self::cards( $ach ) . '</section>';
		}
		$out .= '</div><aside class="bsi-side">';

		// Attendance.
		$out .= self::render_attendance( self::attendance(), self::can_edit( $uid ) );

		// Upcoming.
		$today  = current_time( 'Y-m-d' );
		$events = array_slice( self::events( $today, gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) ) ), 0, 6 );
		$out   .= '<section class="bsi-card bsi-o4"><div class="bsi-card-head"><h2>' . esc_html__( 'Coming up', 'bsa-school-management' ) . '</h2><a href="' . esc_url( self::url( 'calendar' ) ) . '">' . esc_html__( 'Calendar', 'bsa-school-management' ) . ' →</a></div>' . ( $events ? self::event_list( $events ) : '<p class="bsi-muted">' . esc_html__( 'Nothing in the next 30 days.', 'bsa-school-management' ) . '</p>' ) . '</section>';

		// Resources.
		$res  = self::posts( array( 'bsa_resource' ), array( 'posts_per_page' => 5, 'orderby' => 'modified' ) );
		$out .= '<section class="bsi-card bsi-o7"><div class="bsi-card-head"><h2>' . esc_html__( 'Recently updated resources', 'bsa-school-management' ) . '</h2><a href="' . esc_url( self::url( 'resources' ) ) . '">' . esc_html__( 'Library', 'bsa-school-management' ) . ' →</a></div>' . ( $res ? self::resource_list( $res, true ) : '<p class="bsi-muted">' . esc_html__( 'No documents linked yet.', 'bsa-school-management' ) . '</p>' ) . '</section>';
		return $out . '</aside></div>';
	}

	/**
	 * Attendance card: whole-school figure, classes as bars, trend, share buttons.
	 *
	 * @param array $a     From attendance().
	 * @param bool  $share Show the share buttons.
	 * @return string
	 */
	public static function render_attendance( array $a, $share ) {
		$title = $a['today'] ? __( 'Attendance today', 'bsa-school-management' ) : sprintf( /* translators: %s: date. */ __( 'Attendance on %s', 'bsa-school-management' ), mysql2date( 'l j M', $a['date'] ) );
		$out   = '<section class="bsi-card bsi-attendance bsi-o2"><h2>' . esc_html( $title ) . '</h2>';
		if ( ! $a['classes'] ) {
			return $out . '<p class="bsi-muted">' . esc_html__( 'No register has been taken yet.', 'bsa-school-management' ) . '</p></section>';
		}
		$rate = $a['possible'] ? round( 100 * $a['attended'] / $a['possible'] ) : null;
		/* translators: 1: attended, 2: possible, 3: absent, 4: late. */
		$out .= '<p class="bsi-big">' . esc_html( null === $rate ? '–' : $rate . '%' ) . '</p><p class="bsi-muted">' . esc_html( sprintf( __( '%1$d of %2$d attended · %3$d absent · %4$d late', 'bsa-school-management' ), $a['attended'], $a['possible'], $a['absent'], $a['late'] ) ) . '</p><ul class="bsi-bars">';
		foreach ( $a['classes'] as $c ) {
			$pct  = $c['possible'] ? round( 100 * $c['attended'] / $c['possible'] ) : 0;
			$text = $c['marked'] ? sprintf( /* translators: 1: percent, 2: attended, 3: possible. */ __( '%1$d%% (%2$d of %3$d)', 'bsa-school-management' ), $pct, $c['attended'], $c['possible'] ) : __( 'Not marked', 'bsa-school-management' );
			$out .= '<li><span class="bsi-bar-label">' . esc_html( $c['title'] ) . '</span><span class="bsi-bar-track" aria-hidden="true"><span class="bsi-bar-fill" style="width:' . esc_attr( (string) ( $c['marked'] ? $pct : 0 ) ) . '%"></span></span><span class="bsi-bar-value' . ( $c['marked'] ? '' : ' bsi-warn' ) . '">' . ( $c['marked'] ? '' : '<span aria-hidden="true">⚠ </span>' ) . esc_html( $text ) . '</span></li>';
		}
		$out  .= '</ul>';
		$spark = class_exists( 'BSA_Results' ) ? BSA_Results::sparkline( $a['trend'], __( 'Whole-school attendance by day', 'bsa-school-management' ) ) : '';
		if ( $spark ) {
			$out .= '<p class="bsi-muted bsi-small">' . esc_html__( 'Last register days (whole school, %)', 'bsa-school-management' ) . '</p><div class="bsi-spark">' . $spark . '</div>';
		}
		if ( $share ) {
			$text = self::attendance_text( $a );
			$out .= '<script type="application/json" class="bsi-att-data">' . wp_json_encode( array( 'title' => wp_strip_all_tags( str_replace( '*', '', strtok( $text, "\n" ) ) ), 'rate' => $rate, 'attended' => $a['attended'], 'possible' => $a['possible'], 'absent' => $a['absent'], 'late' => $a['late'], 'classes' => $a['classes'], 'text' => $text ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . '</script>';
			$out .= '<div class="bsi-share"><button type="button" class="bsi-btn bsi-share-image" hidden>' . esc_html__( 'Share chart to WhatsApp', 'bsa-school-management' ) . '</button><a class="bsi-btn bsi-btn-outline" href="' . esc_url( 'https://wa.me/?text=' . rawurlencode( $text ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Send as WhatsApp text', 'bsa-school-management' ) . '</a></div><p class="bsi-muted bsi-small">' . esc_html__( 'Opens WhatsApp: choose the BSA Staff group and send. On a phone the chart is shared as a picture.', 'bsa-school-management' ) . '</p>';
		}
		return $out . '</section>';
	}

	/**
	 * Cards for news-like posts.
	 *
	 * @param WP_Post[] $posts Posts.
	 * @return string
	 */
	public static function cards( array $posts ) {
		$out = '<div class="bsi-cards">';
		foreach ( $posts as $p ) {
			$img  = has_post_thumbnail( $p ) ? get_the_post_thumbnail( $p, 'medium_large', array( 'class' => 'bsi-card-img', 'alt' => '', 'loading' => 'lazy' ) ) : '';
			$cat  = (string) get_post_meta( $p->ID, '_bsa_category', true );
			$out .= '<article class="bsi-item">' . $img . '<div class="bsi-item-body"><p class="bsi-meta"><span class="bsi-chip">' . esc_html( self::kind( $p ) ) . '</span>' . ( $cat ? ' <span class="bsi-chip bsi-chip-soft">' . esc_html( $cat ) . '</span>' : '' ) . ' <time datetime="' . esc_attr( mysql2date( 'c', $p->post_date ) ) . '">' . esc_html( mysql2date( 'j M Y', $p->post_date ) ) . '</time></p><h3><a href="' . esc_url( self::url( 'item/' . $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3><p>' . esc_html( self::summary( $p, 22 ) ) . '</p></div></article>';
		}
		return $out . '</div>';
	}

	/**
	 * Notice list with urgency and acknowledgement state.
	 *
	 * @param WP_Post[] $notices Notices.
	 * @param int       $user_id Viewer.
	 * @return string
	 */
	public static function notice_list( array $notices, $user_id ) {
		$out = '<ul class="bsi-notices">';
		foreach ( $notices as $p ) {
			$u    = (string) get_post_meta( $p->ID, '_bsa_urgency', true );
			$u    = isset( self::URGENCY[ $u ] ) ? $u : 'normal';
			$ack  = self::needs_ack( $p->ID ) ? ( get_post_meta( $p->ID, '_bsa_ack_' . $user_id, true ) ? __( 'Acknowledged', 'bsa-school-management' ) : __( 'Please acknowledge', 'bsa-school-management' ) ) : '';
			$out .= '<li class="bsi-notice bsi-urgency-' . esc_attr( $u ) . '"><span class="bsi-badge">' . ( 'normal' === $u ? '' : '<span aria-hidden="true">⚠ </span>' ) . esc_html( self::URGENCY[ $u ] ) . '</span> <a href="' . esc_url( self::url( 'item/' . $p->ID ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a> <span class="bsi-muted">' . esc_html( mysql2date( 'j M', $p->post_date ) ) . ( $ack ? ' · ' . $ack : '' ) . '</span></li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Event agenda.
	 *
	 * @param array $events From events().
	 * @return string
	 */
	public static function event_list( array $events ) {
		$out = '<ul class="bsi-events">';
		foreach ( $events as $e ) {
			$type  = 'term' === $e['type'] ? __( 'Term', 'bsa-school-management' ) : ( self::EVENT_TYPES[ $e['type'] ] ?? __( 'Event', 'bsa-school-management' ) );
			$when  = mysql2date( 'D j M', $e['date'] ) . ( $e['end'] !== $e['date'] ? ' – ' . mysql2date( 'D j M', $e['end'] ) : '' ) . ( $e['time'] ? ', ' . $e['time'] : '' );
			$title = $e['url'] ? '<a href="' . esc_url( $e['url'] ) . '">' . esc_html( $e['title'] ) . '</a>' : esc_html( $e['title'] );
			$out  .= '<li><span class="bsi-date"><span class="bsi-day">' . esc_html( mysql2date( 'j', $e['date'] ) ) . '</span><span class="bsi-mon">' . esc_html( mysql2date( 'M', $e['date'] ) ) . '</span></span><span class="bsi-ev"><strong>' . $title . '</strong><span class="bsi-muted">' . esc_html( $type . ' · ' . $when . ( $e['location'] ? ' · ' . $e['location'] : '' ) ) . '</span></span></li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Resource list (opens the linked document).
	 *
	 * @param WP_Post[] $posts   Resources.
	 * @param bool      $compact Short form.
	 * @return string
	 */
	public static function resource_list( array $posts, $compact = false ) {
		$out = '<ul class="bsi-resources' . ( $compact ? ' bsi-compact' : '' ) . '">';
		foreach ( $posts as $p ) {
			$url  = (string) get_post_meta( $p->ID, '_bsa_url', true );
			$prov = self::provider( $url );
			$cat  = (string) get_post_meta( $p->ID, '_bsa_category', true );
			$dep  = (string) get_post_meta( $p->ID, '_bsa_department', true );
			$link = $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $p ) ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'bsa-school-management' ) . '</span></a>' : esc_html( get_the_title( $p ) );
			$out .= '<li><span class="bsi-prov bsi-prov-' . esc_attr( $prov['id'] ) . '">' . esc_html( $prov['label'] ) . '</span><span class="bsi-res"><strong>' . $link . '</strong>';
			if ( ! $compact && '' !== trim( $p->post_excerpt ) ) {
				$out .= '<span>' . esc_html( $p->post_excerpt ) . '</span>';
			}
			/* translators: %s: date. */
			$out .= '<span class="bsi-muted">' . esc_html( implode( ' · ', array_filter( array( $cat, $dep, sprintf( __( 'updated %s', 'bsa-school-management' ), mysql2date( 'j M Y', $p->post_modified ) ) ) ) ) ) . '</span></span></li>';
		}
		return $out . '</ul>';
	}

	/**
	 * News-like list with paging (and a news / announcements filter).
	 *
	 * @param string[] $types  Post types.
	 * @param array    $get    Query parameters.
	 * @param bool     $filter Show the kind filter.
	 * @return string
	 */
	public static function render_list( array $types, array $get, $filter ) {
		$kind  = sanitize_key( $get['kind'] ?? '' );
		$types = ( $filter && in_array( 'bsa_' . $kind, $types, true ) ) ? array( 'bsa_' . $kind ) : $types;
		$page  = max( 1, absint( $get['pg'] ?? 1 ) );
		$q     = new WP_Query( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => self::PER_PAGE, 'paged' => $page, 'ignore_sticky_posts' => true ) );
		$title = $filter ? __( 'News and announcements', 'bsa-school-management' ) : __( 'Achievements', 'bsa-school-management' );
		$out   = '<div class="bsi-page-head"><h1>' . esc_html( $title ) . '</h1>';
		if ( $filter ) {
			$out .= '<div class="bsi-filters">';
			foreach ( array( '' => __( 'All', 'bsa-school-management' ), 'news' => __( 'News', 'bsa-school-management' ), 'announcement' => __( 'Announcements', 'bsa-school-management' ) ) as $k => $label ) {
				$out .= '<a class="bsi-filter" href="' . esc_url( self::url( 'news', $k ? array( 'kind' => $k ) : array() ) ) . '"' . ( $k === $kind ? ' aria-current="true"' : '' ) . '>' . esc_html( $label ) . '</a>';
			}
			$out .= '</div>';
		}
		$out .= '</div>' . ( $q->posts ? self::cards( $q->posts ) : '<p class="bsi-muted">' . esc_html__( 'Nothing posted yet.', 'bsa-school-management' ) . '</p>' );
		if ( $q->max_num_pages > 1 ) {
			$base = $filter ? 'news' : 'achievements';
			$out .= '<nav class="bsi-pager" aria-label="' . esc_attr__( 'Pages', 'bsa-school-management' ) . '">';
			if ( $page > 1 ) {
				$out .= '<a href="' . esc_url( self::url( $base, array_filter( array( 'kind' => $kind, 'pg' => $page - 1 ) ) ) ) . '">← ' . esc_html__( 'Newer', 'bsa-school-management' ) . '</a>';
			}
			if ( $page < $q->max_num_pages ) {
				$out .= '<a href="' . esc_url( self::url( $base, array_filter( array( 'kind' => $kind, 'pg' => $page + 1 ) ) ) ) . '">' . esc_html__( 'Older', 'bsa-school-management' ) . ' →</a>';
			}
			$out .= '</nav>';
		}
		return $out;
	}

	/**
	 * Notices page.
	 *
	 * @param int $user_id Viewer.
	 * @return string
	 */
	public static function render_notices( $user_id ) {
		$n = self::notices();
		return '<div class="bsi-page-head"><h1>' . esc_html__( 'Notices', 'bsa-school-management' ) . '</h1></div><section class="bsi-card">' . ( $n ? self::notice_list( $n, $user_id ) : '<p class="bsi-muted">' . esc_html__( 'No current notices.', 'bsa-school-management' ) . '</p>' ) . '</section>';
	}

	/**
	 * Resources library with category filter and search.
	 *
	 * @param array $get Query parameters.
	 * @return string
	 */
	public static function render_resources( array $get ) {
		$cat  = sanitize_text_field( (string) ( $get['category'] ?? '' ) );
		$cat  = in_array( $cat, self::RESOURCE_CATEGORIES, true ) ? $cat : '';
		$q    = sanitize_text_field( (string) ( $get['rq'] ?? '' ) );
		$args = array( 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' );
		if ( $cat ) {
			$args['meta_query'] = array( array( 'key' => '_bsa_category', 'value' => $cat ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( '' !== $q ) {
			$args['s'] = $q;
		}
		$res = self::posts( array( 'bsa_resource' ), $args );
		$out = '<div class="bsi-page-head"><h1>' . esc_html__( 'Resources', 'bsa-school-management' ) . '</h1><p class="bsi-muted">' . esc_html__( 'Templates, policies, registers and other documents. They open in OneDrive or Google Drive.', 'bsa-school-management' ) . '</p></div>';
		$out .= '<form class="bsi-res-filter" method="get" action="' . esc_url( self::url( 'resources' ) ) . '"><label for="bsi-cat">' . esc_html__( 'Category', 'bsa-school-management' ) . '</label> <select id="bsi-cat" name="category"><option value="">' . esc_html__( 'All', 'bsa-school-management' ) . '</option>';
		foreach ( self::RESOURCE_CATEGORIES as $c ) {
			$out .= '<option value="' . esc_attr( $c ) . '"' . selected( $cat, $c, false ) . '>' . esc_html( $c ) . '</option>';
		}
		$out .= '</select> <label for="bsi-rq">' . esc_html__( 'Find', 'bsa-school-management' ) . '</label> <input id="bsi-rq" type="search" name="rq" value="' . esc_attr( $q ) . '"> <button type="submit" class="bsi-btn">' . esc_html__( 'Show', 'bsa-school-management' ) . '</button></form>';
		return $out . '<section class="bsi-card">' . ( $res ? self::resource_list( $res ) : '<p class="bsi-muted">' . esc_html__( 'No documents match.', 'bsa-school-management' ) . '</p>' ) . '</section>';
	}

	/**
	 * Calendar: agenda from a week ago to three months ahead, by month.
	 *
	 * @return string
	 */
	public static function render_calendar() {
		$today  = current_time( 'Y-m-d' );
		$events = self::events( gmdate( 'Y-m-d', strtotime( $today . ' -7 days' ) ), gmdate( 'Y-m-d', strtotime( $today . ' +90 days' ) ) );
		$out    = '<div class="bsi-page-head"><h1>' . esc_html__( 'Calendar', 'bsa-school-management' ) . '</h1><p class="bsi-muted">' . esc_html__( 'School events and term dates, from last week to three months ahead.', 'bsa-school-management' ) . '</p></div>';
		if ( ! $events ) {
			return $out . '<section class="bsi-card"><p class="bsi-muted">' . esc_html__( 'Nothing on the calendar yet.', 'bsa-school-management' ) . '</p></section>';
		}
		$by = array();
		foreach ( $events as $e ) {
			$by[ mysql2date( 'F Y', $e['date'] ) ][] = $e;
		}
		foreach ( $by as $month => $list ) {
			$out .= '<section class="bsi-card"><h2>' . esc_html( $month ) . '</h2>' . self::event_list( $list ) . '</section>';
		}
		return $out;
	}

	/**
	 * Search across the intranet.
	 *
	 * @param string $q Query.
	 * @return string
	 */
	public static function render_search( $q ) {
		$q   = sanitize_text_field( $q );
		$out = '<div class="bsi-page-head"><h1>' . esc_html__( 'Search', 'bsa-school-management' ) . '</h1></div>';
		if ( '' === $q ) {
			return $out . '<p class="bsi-muted">' . esc_html__( 'Type a word in the search box.', 'bsa-school-management' ) . '</p>';
		}
		$hits = self::posts( array_keys( self::TYPES ), array( 's' => $q, 'posts_per_page' => 30 ) );
		/* translators: 1: results, 2: query. */
		$out .= '<p>' . esc_html( sprintf( _n( '%1$d result for "%2$s"', '%1$d results for "%2$s"', count( $hits ), 'bsa-school-management' ), count( $hits ), $q ) ) . '</p><section class="bsi-card"><ul class="bsi-hits">';
		foreach ( $hits as $p ) {
			$url  = 'bsa_resource' === $p->post_type ? (string) get_post_meta( $p->ID, '_bsa_url', true ) : self::url( 'item/' . $p->ID );
			$out .= '<li><span class="bsi-chip">' . esc_html( self::kind( $p ) ) . '</span> <a href="' . esc_url( $url ) . '"' . ( 'bsa_resource' === $p->post_type ? ' target="_blank" rel="noopener"' : '' ) . '>' . esc_html( get_the_title( $p ) ) . '</a><span class="bsi-muted"> ' . esc_html( self::summary( $p, 18 ) ) . '</span></li>';
		}
		return $out . '</ul></section>';
	}

	/**
	 * One item.
	 *
	 * @param int   $id      Post ID.
	 * @param int   $user_id Viewer.
	 * @param array $get     Query parameters.
	 * @return string
	 */
	public static function render_item( $id, $user_id, array $get ) {
		$p = get_post( $id );
		if ( ! $p || ! isset( self::TYPES[ $p->post_type ] ) || ( 'publish' !== $p->post_status && ! self::can_edit( $user_id ) ) ) {
			status_header( 404 );
			return '<div class="bsi-page-head"><h1>' . esc_html__( 'Not found', 'bsa-school-management' ) . '</h1><p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to the intranet', 'bsa-school-management' ) . '</a></p></div>';
		}
		$back = self::TYPES[ $p->post_type ][2];
		$out  = '<p class="bsi-crumb"><a href="' . esc_url( self::url( $back ) ) . '">← ' . esc_html( self::view_title( $back ) ) . '</a></p><article class="bsi-card bsi-article">';
		if ( 'ack' === ( $get['done'] ?? '' ) ) {
			$out .= '<p class="bsi-ok" role="status"><span aria-hidden="true">✓</span> ' . esc_html__( 'Thank you, your acknowledgement is recorded.', 'bsa-school-management' ) . '</p>';
		}
		$meta = array( self::kind( $p ), mysql2date( 'j F Y', $p->post_date ) );
		if ( 'bsa_notice' === $p->post_type ) {
			$u      = (string) get_post_meta( $p->ID, '_bsa_urgency', true );
			$meta[] = self::URGENCY[ $u ] ?? self::URGENCY['normal'];
			$until  = (string) get_post_meta( $p->ID, '_bsa_until', true );
			if ( $until ) {
				/* translators: %s: date. */
				$meta[] = sprintf( self::expired( $p ) ? __( 'expired %s', 'bsa-school-management' ) : __( 'until %s', 'bsa-school-management' ), mysql2date( 'j M Y', $until ) );
			}
		}
		if ( 'bsa_event' === $p->post_type ) {
			$d   = (string) get_post_meta( $p->ID, '_bsa_date', true );
			$end = (string) get_post_meta( $p->ID, '_bsa_end', true );
			$meta = array( self::EVENT_TYPES[ get_post_meta( $p->ID, '_bsa_type', true ) ] ?? __( 'Event', 'bsa-school-management' ), $d ? mysql2date( 'l j F Y', $d ) . ( $end ? ' – ' . mysql2date( 'l j F Y', $end ) : '' ) : '', (string) get_post_meta( $p->ID, '_bsa_time', true ), (string) get_post_meta( $p->ID, '_bsa_location', true ) );
		}
		$out .= '<p class="bsi-meta">' . esc_html( implode( ' · ', array_filter( $meta ) ) ) . '</p><h1>' . esc_html( get_the_title( $p ) ) . '</h1>';
		if ( has_post_thumbnail( $p ) ) {
			$out .= get_the_post_thumbnail( $p, 'large', array( 'class' => 'bsi-article-img', 'alt' => '' ) );
		}
		if ( 'bsa_resource' === $p->post_type ) {
			$url  = (string) get_post_meta( $p->ID, '_bsa_url', true );
			$out .= '<p>' . esc_html( $p->post_excerpt ) . '</p>' . ( $url ? '<p><a class="bsi-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( sprintf( /* translators: %s: provider. */ __( 'Open in %s', 'bsa-school-management' ), self::provider( $url )['label'] ) ) . '</a></p>' : '' );
		} else {
			$out .= '<div class="bsi-content">' . wp_kses_post( wpautop( do_shortcode( $p->post_content ) ) ) . '</div>';
		}
		$author = get_userdata( (int) $p->post_author );
		if ( $author ) {
			/* translators: %s: name. */
			$out .= '<p class="bsi-muted bsi-small">' . esc_html( sprintf( __( 'Posted by %s', 'bsa-school-management' ), $author->display_name ) ) . '</p>';
		}
		if ( self::needs_ack( $p->ID ) ) {
			$done = get_post_meta( $p->ID, '_bsa_ack_' . $user_id, true );
			if ( $done ) {
				/* translators: %s: date and time. */
				$out .= '<p class="bsi-ok"><span aria-hidden="true">✓</span> ' . esc_html( sprintf( __( 'You acknowledged this on %s.', 'bsa-school-management' ), mysql2date( 'j M Y, H:i', $done ) ) ) . '</p>';
			} elseif ( 'publish' === $p->post_status ) {
				$out .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_intranet_ack"><input type="hidden" name="notice" value="' . esc_attr( (string) $p->ID ) . '">' . wp_nonce_field( 'bsa_intranet_ack_' . $p->ID, '_wpnonce', true, false ) . '<button type="submit" class="bsi-btn">' . esc_html__( 'I have read this notice', 'bsa-school-management' ) . '</button></form>';
			}
			if ( self::can_edit( $user_id ) ) {
				$out .= '<div class="bsi-receipts-box"><h2>' . esc_html__( 'Read receipts', 'bsa-school-management' ) . '</h2>' . self::receipts_html( $p->ID ) . '</div>';
			}
		}
		if ( self::can_edit( $user_id ) ) {
			$out .= '<p><a href="' . esc_url( admin_url( 'post.php?action=edit&post=' . $p->ID ) ) . '">' . esc_html__( 'Edit', 'bsa-school-management' ) . '</a></p>';
		}
		return $out . '</article>';
	}
}
