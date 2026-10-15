<?php
/**
 * Family Accounts check (wp-admin): finds learner and parent logins that will not work
 * as intended, so the office can fix them before accounts are handed out.
 *
 * Checks, per active learner in a school year:
 * - no parent login (deleting a login unlinks it, so deleted logins show here too);
 * - parent and learner using the same login, or one login used as a learner and as a
 *   parent (a parent signing in with a child's account);
 * - possible siblings (same parent phone number) linked to different parent logins;
 * - login emails that are missing, invalid or not real (password reset cannot reach them);
 * - a parent login that is a staff or administrator account.
 * A learner without their own login is allowed (optional for younger learners) and only
 * counted.
 *
 * Open to administrators and to staff with Manage Admissions or Manage Students (the
 * people who create the accounts). Read-only: fixes are made in School Management.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Family Accounts screen and checks.
 */
class BSA_Accounts {

	const PAGE = 'bsa-family-accounts';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Who may see the screen: those who may manage admissions or learner records.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can_view( $user_id ) {
		return BSA_Admission_Records::user_can_manage( $user_id, BSA_School::SCHOOL_ID );
	}

	/**
	 * Menu for permitted users only.
	 */
	public static function register_menu() {
		if ( ! self::user_can_view( get_current_user_id() ) ) {
			return;
		}
		add_menu_page( __( 'Family Accounts', 'bsa-school-management' ), __( 'Family Accounts', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-groups', 27 );
	}

	/**
	 * Active learners of a school year with their logins.
	 *
	 * @param int $session_id Session ID.
	 * @return object[]
	 */
	public static function learners( $session_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT sr.ID, sr.name, sr.admission_number, sr.user_id, sr.parent_user_id, sr.father_name, sr.mother_name, sr.father_phone, sr.mother_phone, sr.phone, c.label AS class_label, se.label AS section_label, lu.ID AS l_id, lu.user_login AS l_login, lu.user_email AS l_email, pu.ID AS p_id, pu.user_login AS p_login, pu.user_email AS p_email, pu.display_name AS p_name FROM {$p}wlsm_student_records sr JOIN {$p}wlsm_sections se ON se.ID = sr.section_id JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id JOIN {$p}wlsm_classes c ON c.ID = cs.class_id LEFT JOIN {$wpdb->users} lu ON lu.ID = sr.user_id LEFT JOIN {$wpdb->users} pu ON pu.ID = sr.parent_user_id WHERE sr.session_id = %d AND sr.is_active = 1 AND cs.school_id = %d ORDER BY c.ID, se.label, sr.name", $session_id, BSA_School::SCHOOL_ID ) );
	}

	/**
	 * Why an email address cannot receive a password-reset link ('' when it can).
	 *
	 * @param string $email Address.
	 * @return string
	 */
	public static function email_problem( $email ) {
		$email = trim( (string) $email );
		if ( '' === $email ) {
			return __( 'no email address', 'bsa-school-management' );
		}
		if ( ! is_email( $email ) ) {
			return __( 'not a valid email address', 'bsa-school-management' );
		}
		$domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );
		if ( preg_match( '/(^|\.)(example\.(com|org|net)|invalid|test|localhost|local|example)$/', $domain ) ) {
			return __( 'a placeholder address (not a real mailbox)', 'bsa-school-management' );
		}
		return '';
	}

	/**
	 * Phone number reduced to its last nine digits (Zimbabwe mobile without 0 / +263), or ''.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	public static function phone_key( $phone ) {
		$digits = preg_replace( '/\D/', '', (string) $phone );
		return strlen( $digits ) >= 9 ? substr( $digits, -9 ) : '';
	}

	/**
	 * Problems per learner.
	 *
	 * @param object[] $rows Learners from learners().
	 * @return array<int, array{severity:string, code:string, text:string}[]> learner ID => problems.
	 */
	public static function issues( array $rows ) {
		$out       = array();
		$as_learn  = array();
		$as_parent = array();
		$by_phone  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->ID ] = array();
			if ( $r->user_id ) {
				$as_learn[ (int) $r->user_id ][] = $r;
			}
			if ( $r->parent_user_id ) {
				$as_parent[ (int) $r->parent_user_id ][] = $r;
			}
			foreach ( array_unique( array_filter( array( self::phone_key( $r->father_phone ), self::phone_key( $r->mother_phone ) ) ) ) as $key ) {
				$by_phone[ $key ][] = $r;
			}
		}
		$add = function ( $id, $severity, $code, $text ) use ( &$out ) {
			$out[ (int) $id ][] = array( 'severity' => $severity, 'code' => $code, 'text' => $text );
		};
		$admin_key = class_exists( 'WLSM_M_Role' ) ? WLSM_M_Role::get_admin_key() : 'admin';
		foreach ( $rows as $r ) {
			$id = (int) $r->ID;
			// Parent login (a deleted login is unlinked by the database, so it shows here too).
			if ( ! $r->parent_user_id ) {
				$add( $id, 'warning', 'no_parent_login', __( 'No parent login: the parent cannot see fees, attendance or report cards.', 'bsa-school-management' ) );
			}
			// Shared and crossed logins.
			if ( $r->user_id && (int) $r->user_id === (int) $r->parent_user_id ) {
				$add( $id, 'error', 'shared_login', __( 'Parent and learner use the same login. Give the parent their own login.', 'bsa-school-management' ) );
			} else {
				if ( $r->parent_user_id && isset( $as_learn[ (int) $r->parent_user_id ] ) ) {
					/* translators: %s: learner names. */
					$add( $id, 'error', 'parent_is_learner_login', sprintf( __( 'The parent login is also the learner login of %s: the parent is signing in with a child\'s account.', 'bsa-school-management' ), implode( ', ', wp_list_pluck( $as_learn[ (int) $r->parent_user_id ], 'name' ) ) ) );
				}
				if ( $r->user_id && isset( $as_parent[ (int) $r->user_id ] ) ) {
					/* translators: %s: learner names. */
					$add( $id, 'error', 'learner_is_parent_login', sprintf( __( 'This learner\'s login is also used as the parent login of %s.', 'bsa-school-management' ), implode( ', ', wp_list_pluck( $as_parent[ (int) $r->user_id ], 'name' ) ) ) );
				}
			}
			if ( $r->user_id && isset( $as_learn[ (int) $r->user_id ] ) && count( $as_learn[ (int) $r->user_id ] ) > 1 ) {
				$others = array_filter(
					$as_learn[ (int) $r->user_id ],
					static function ( $o ) use ( $id ) {
						return (int) $o->ID !== $id;
					}
				);
				/* translators: %s: learner names. */
				$add( $id, 'error', 'learner_login_shared', sprintf( __( 'This learner login is also the learner login of %s.', 'bsa-school-management' ), implode( ', ', wp_list_pluck( $others, 'name' ) ) ) );
			}
			// Emails.
			if ( $r->p_id ) {
				$problem = self::email_problem( $r->p_email );
				if ( $problem ) {
					/* translators: %s: what is wrong with the address. */
					$add( $id, 'warning', 'parent_email', sprintf( __( 'Parent login email is %s: "Lost your password?" cannot reach the parent.', 'bsa-school-management' ), $problem ) );
				}
				if ( user_can( (int) $r->p_id, 'manage_options' ) ) {
					$add( $id, 'error', 'parent_is_admin', __( 'The parent login is a site administrator account. Give the parent an ordinary login.', 'bsa-school-management' ) );
				} elseif ( BSA_School::staff( (int) $r->p_id ) ) {
					$staff = BSA_School::staff( (int) $r->p_id );
					$add( $id, $admin_key === $staff->role ? 'warning' : 'info', 'parent_is_staff', __( 'The parent login is also a staff login: it opens wp-admin; the family pages are under My Account.', 'bsa-school-management' ) );
				}
			}
			if ( $r->l_id ) {
				$problem = self::email_problem( $r->l_email );
				if ( $problem ) {
					/* translators: %s: what is wrong with the address. */
					$add( $id, 'warning', 'learner_email', sprintf( __( 'Learner login email is %s.', 'bsa-school-management' ), $problem ) );
				}
			}
		}
		// Possible siblings with different parent logins.
		foreach ( $by_phone as $group ) {
			$parents = array_unique(
				array_map(
					static function ( $r ) {
						return (int) $r->parent_user_id;
					},
					$group
				)
			);
			if ( count( $group ) < 2 || count( $parents ) < 2 ) {
				continue;
			}
			foreach ( $group as $r ) {
				$others = array_filter(
					$group,
					static function ( $o ) use ( $r ) {
						return (int) $o->ID !== (int) $r->ID && (int) $o->parent_user_id !== (int) $r->parent_user_id;
					}
				);
				if ( $others ) {
					/* translators: %s: learner names. */
					$add( (int) $r->ID, 'warning', 'siblings_split', sprintf( __( 'Possible sibling of %s (same parent phone) but linked to a different parent login. Link all the children to one parent login.', 'bsa-school-management' ), implode( ', ', array_unique( wp_list_pluck( $others, 'name' ) ) ) ) );
				}
			}
		}
		// One message per code per learner.
		foreach ( $out as $id => $list ) {
			$seen        = array();
			$out[ $id ] = array_values(
				array_filter(
					$list,
					static function ( $i ) use ( &$seen ) {
						if ( isset( $seen[ $i['code'] ] ) ) {
							return false;
						}
						$seen[ $i['code'] ] = true;
						return true;
					}
				)
			);
		}
		return $out;
	}

	/**
	 * Totals for the summary line.
	 *
	 * @param object[] $rows   Learners.
	 * @param array    $issues From issues().
	 * @return array<string,int>
	 */
	public static function summary( array $rows, array $issues ) {
		$s = array( 'learners' => count( $rows ), 'with_parent' => 0, 'families' => 0, 'with_login' => 0, 'errors' => 0, 'warnings' => 0, 'ok' => 0 );
		$families = array();
		foreach ( $rows as $r ) {
			if ( $r->p_id ) {
				++$s['with_parent'];
				$families[ (int) $r->p_id ] = true;
			}
			if ( $r->l_id ) {
				++$s['with_login'];
			}
			$sev = wp_list_pluck( $issues[ (int) $r->ID ] ?? array(), 'severity' );
			if ( in_array( 'error', $sev, true ) ) {
				++$s['errors'];
			} elseif ( in_array( 'warning', $sev, true ) ) {
				++$s['warnings'];
			} else {
				++$s['ok'];
			}
		}
		$s['families'] = count( $families );
		return $s;
	}

	/**
	 * The screen.
	 */
	public static function render_page() {
		$user_id = get_current_user_id();
		if ( ! self::user_can_view( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to view family accounts.', 'bsa-school-management' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$sessions = BSA_School::sessions();
		$session  = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : BSA_School::current_session();
		$session  = isset( $sessions[ $session ] ) ? $session : BSA_School::current_session();
		$show_all = ! empty( $_GET['all'] );
		// phpcs:enable
		$rows    = self::learners( $session );
		$issues  = self::issues( $rows );
		$summary = self::summary( $rows, $issues );
		$counts  = array();
		foreach ( $rows as $r ) {
			if ( $r->p_id ) {
				$counts[ (int) $r->p_id ] = ( $counts[ (int) $r->p_id ] ?? 0 ) + 1;
			}
		}

		echo '<div class="wrap bsa-accounts"><h1>' . esc_html__( 'Family Accounts', 'bsa-school-management' ) . '</h1>';
		echo '<style>.bsa-accounts .bsa-sum{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0}.bsa-accounts .bsa-sum div{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;min-width:120px}.bsa-accounts .bsa-sum b{display:block;font-size:20px}.bsa-accounts .bsa-issue{display:block;margin:2px 0;padding-left:10px;border-left:3px solid #dba617}.bsa-accounts .bsa-issue.error{border-color:#d63638}.bsa-accounts .bsa-issue.info{border-color:#72aee6}.bsa-accounts td small{color:#646970}.bsa-accounts details{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 14px;margin:16px 0;max-width:900px}.bsa-accounts details summary{font-weight:600;cursor:pointer}</style>';

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><label>' . esc_html__( 'School year', 'bsa-school-management' ) . ' <select name="session">';
		foreach ( $sessions as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $id, $session, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> <label><input type="checkbox" name="all" value="1"' . checked( $show_all, true, false ) . '> ' . esc_html__( 'Show every learner (not only those with problems)', 'bsa-school-management' ) . '</label> ';
		submit_button( __( 'Show', 'bsa-school-management' ), 'secondary', '', false );
		echo '</form>';

		$tiles = array(
			'learners'    => __( 'Active learners', 'bsa-school-management' ),
			'with_parent' => __( 'With a parent login', 'bsa-school-management' ),
			'families'    => __( 'Parent logins (families)', 'bsa-school-management' ),
			'with_login'  => __( 'With their own login', 'bsa-school-management' ),
			'errors'      => __( 'To fix', 'bsa-school-management' ),
			'warnings'    => __( 'To check', 'bsa-school-management' ),
		);
		echo '<div class="bsa-sum">';
		foreach ( $tiles as $key => $label ) {
			echo '<div><b>' . esc_html( (string) $summary[ $key ] ) . '</b>' . esc_html( $label ) . '</div>';
		}
		echo '</div>';

		self::render_guide();

		$list = array_filter(
			$rows,
			static function ( $r ) use ( $issues, $show_all ) {
				return $show_all || array_filter(
					$issues[ (int) $r->ID ],
					static function ( $i ) {
						return 'info' !== $i['severity'];
					}
				);
			}
		);
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No active learners in this school year.', 'bsa-school-management' ) . '</p></div>';
			return;
		}
		if ( ! $list ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'No problems found: every learner has a working parent login.', 'bsa-school-management' ) . '</p></div></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Learner login', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Parent login', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Problems', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Fix', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $list as $r ) {
			$edit = admin_url( 'admin.php?page=sm-staff-students&action=save&id=' . (int) $r->ID );
			echo '<tr><td><strong>' . esc_html( $r->name ) . '</strong><br><small>' . esc_html( $r->admission_number . ' · ' . $r->class_label . ' ' . $r->section_label ) . '</small></td>';
			echo '<td>' . ( $r->l_id ? esc_html( $r->l_login ) . '<br><small>' . esc_html( $r->l_email ) . '</small>' : '<small>' . esc_html__( 'none (optional)', 'bsa-school-management' ) . '</small>' ) . '</td>';
			$children = $r->p_id ? (int) $counts[ (int) $r->p_id ] : 0;
			/* translators: %d: number of children. */
			echo '<td>' . ( $r->p_id ? esc_html( $r->p_login ) . '<br><small>' . esc_html( $r->p_email ) . '<br>' . esc_html( sprintf( _n( '%d child linked', '%d children linked', $children, 'bsa-school-management' ), $children ) ) . '</small>' : '<small>' . esc_html__( 'none', 'bsa-school-management' ) . '</small>' ) . '</td><td>';
			if ( ! $issues[ (int) $r->ID ] ) {
				echo '<small>' . esc_html__( 'No problems', 'bsa-school-management' ) . '</small>';
			}
			foreach ( $issues[ (int) $r->ID ] as $i ) {
				echo '<span class="bsa-issue ' . esc_attr( $i['severity'] ) . '">' . esc_html( $i['text'] ) . '</span>';
			}
			echo '</td><td><a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit learner', 'bsa-school-management' ) . '</a>';
			foreach ( array( (int) $r->l_id => __( 'Learner login', 'bsa-school-management' ), (int) $r->p_id => __( 'Parent login', 'bsa-school-management' ) ) as $uid => $label ) {
				if ( $uid && current_user_can( 'edit_user', $uid ) ) {
					echo '<br><a href="' . esc_url( get_edit_user_link( $uid ) ) . '">' . esc_html( $label ) . '</a>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Short guide for the office (collapsed).
	 */
	public static function render_guide() {
		$steps = array(
			__( 'Every family gets its own parent login, separate from the learner\'s. Never give a parent the child\'s username and password.', 'bsa-school-management' ),
			__( 'Admission form, Parent Login: choose "New User" for the first child of a family, with the parent\'s own email address. For a brother or sister, choose "Existing User" and enter the same parent username, so one parent login shows all the children.', 'bsa-school-management' ),
			__( 'Student Login is optional. For a learner who will use the portal, choose "New User" with a school address (for example bsa002@bindurascienceacademy.co.zw), so the parent\'s email stays free for the parent login. Younger learners without a phone can have no login.', 'bsa-school-management' ),
			__( 'Use real email addresses: "Lost your password?" sends the link there. A parent without email can be given a password by the office (Users → Edit), but cannot reset it alone.', 'bsa-school-management' ),
			__( 'Hand the username to the parent and point them to the Help page on the portal. Then check this screen again.', 'bsa-school-management' ),
		);
		echo '<details><summary>' . esc_html__( 'How to set up family accounts', 'bsa-school-management' ) . '</summary><ol>';
		foreach ( $steps as $step ) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}
		echo '</ol></details>';
	}
}
