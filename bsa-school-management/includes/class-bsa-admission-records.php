<?php
/**
 * Admission documents BSA collects that Weblizar has no fields for:
 * birth certificate (number + scan) and Grade 7 results (school, year, units, the six
 * subject grades of the new-curriculum slip + slip scan).
 *
 * Data lives in {prefix}bsa_admission_records, keyed by Weblizar student_record ID.
 * Scans are stored under uploads/bsa-private/ (web access denied) with random names
 * and are only served through an authenticated, permission-checked download.
 *
 * Access: WordPress administrators, or Weblizar staff whose role grants
 * manage_admissions or manage_students in the learner's school.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admission records: schema, admin screen, private file store, downloads.
 */
class BSA_Admission_Records {

	const DB_VERSION  = '2';
	const PAGE        = 'bsa-admission-documents';
	const MAX_BYTES   = 5242880; // 5 MB.
	const FILE_FIELDS = array(
		'birth_certificate_file' => 'Birth certificate',
		'grade7_results_file'    => 'Grade 7 results slip',
	);
	const MIME_TYPES  = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_save_admission_record', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bsa_admission_file', array( __CLASS__, 'handle_download' ) );
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bsa_admission_records';
	}

	/**
	 * Creates or updates the table when the stored schema version is older.
	 * Additive only: dbDelta never drops columns or data.
	 */
	public static function maybe_migrate() {
		if ( get_option( 'bsa_sm_admission_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				student_record_id bigint(20) unsigned NOT NULL,
				birth_certificate_no varchar(40) NOT NULL DEFAULT '',
				birth_certificate_file varchar(255) NOT NULL DEFAULT '',
				grade7_school varchar(150) NOT NULL DEFAULT '',
				grade7_year smallint(5) unsigned DEFAULT NULL,
				grade7_units tinyint(3) unsigned DEFAULT NULL,
				grade7_english tinyint(3) unsigned DEFAULT NULL,
				grade7_maths tinyint(3) unsigned DEFAULT NULL,
				grade7_science tinyint(3) unsigned DEFAULT NULL,
				grade7_language tinyint(3) unsigned DEFAULT NULL,
				grade7_social tinyint(3) unsigned DEFAULT NULL,
				grade7_pe_arts tinyint(3) unsigned DEFAULT NULL,
				grade7_results_file varchar(255) NOT NULL DEFAULT '',
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY student_record_id (student_record_id)
			) {$charset};"
		);
		self::ensure_store();
		update_option( 'bsa_sm_admission_db_version', self::DB_VERSION, false );
	}

	/**
	 * Absolute path of the private store (created with deny rules on first use).
	 *
	 * @return string
	 */
	public static function store_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'bsa-private';
	}

	/**
	 * Creates the private store and its web-deny files.
	 *
	 * @return bool
	 */
	public static function ensure_store() {
		$dir = self::store_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$deny = "# BSA private documents: never served directly.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', $deny ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return true;
	}

	/**
	 * Whether the user may view and edit admission documents for a learner's school.
	 *
	 * @param int $user_id   User ID.
	 * @param int $school_id School ID (0 = any school the user administers).
	 * @return bool
	 */
	public static function user_can_manage( $user_id, $school_id = 0 ) {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		global $wpdb;
		$staff = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, permissions, school_id FROM {$wpdb->prefix}wlsm_staff WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);
		$admin_key = class_exists( 'WLSM_M_Role' ) ? WLSM_M_Role::get_admin_key() : 'admin';
		foreach ( $staff as $row ) {
			if ( $school_id && (int) $row->school_id !== (int) $school_id ) {
				continue;
			}
			if ( $admin_key === $row->role ) {
				return true;
			}
			$permissions = maybe_unserialize( $row->permissions );
			if ( is_array( $permissions ) && array_intersect( array( 'manage_admissions', 'manage_students' ), $permissions ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A learner (Weblizar student record) with class context, or null.
	 *
	 * @param int $student_record_id Student record ID.
	 * @return object|null
	 */
	public static function get_learner( $student_record_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT sr.ID, sr.name, sr.admission_number, sr.session_id, cs.school_id, c.label AS class_label, se.label AS section_label
				FROM {$p}wlsm_student_records sr
				JOIN {$p}wlsm_sections se ON se.ID = sr.section_id
				JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id
				JOIN {$p}wlsm_classes c ON c.ID = cs.class_id
				WHERE sr.ID = %d",
				$student_record_id
			)
		);
		// phpcs:enable
	}

	/**
	 * The admission record for a learner (empty defaults when none saved yet).
	 *
	 * @param int $student_record_id Student record ID.
	 * @return array<string,mixed>
	 */
	public static function get_record( $student_record_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE student_record_id = %d', $student_record_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : array(
			'birth_certificate_no'   => '',
			'birth_certificate_file' => '',
			'grade7_school'          => '',
			'grade7_year'            => null,
			'grade7_units'           => null,
			'grade7_english'         => null,
			'grade7_maths'           => null,
			'grade7_science'         => null,
			'grade7_language'        => null,
			'grade7_social'          => null,
			'grade7_pe_arts'         => null,
			'grade7_results_file'    => '',
		);
	}

	/**
	 * Validates text fields from a request. Returns [data, errors].
	 *
	 * @param array $input Raw (unslashed) input.
	 * @return array{0: array<string,mixed>, 1: string[]}
	 */
	public static function validate_fields( array $input ) {
		$errors = array();
		$data   = array(
			'birth_certificate_no' => strtoupper( trim( sanitize_text_field( $input['birth_certificate_no'] ?? '' ) ) ),
			'grade7_school'        => trim( sanitize_text_field( $input['grade7_school'] ?? '' ) ),
			'grade7_year'          => null,
			'grade7_units'         => null,
		);

		if ( strlen( $data['birth_certificate_no'] ) > 40 ) {
			$errors[] = __( 'Birth certificate number must be 40 characters or fewer.', 'bsa-school-management' );
		} elseif ( '' !== $data['birth_certificate_no'] && ! preg_match( '/^[A-Z0-9\/\- ]+$/', $data['birth_certificate_no'] ) ) {
			$errors[] = __( 'Birth certificate number may contain only letters, numbers, spaces, "/" and "-".', 'bsa-school-management' );
		}
		if ( strlen( $data['grade7_school'] ) > 150 ) {
			$errors[] = __( 'Primary school name must be 150 characters or fewer.', 'bsa-school-management' );
		}

		$year = trim( (string) ( $input['grade7_year'] ?? '' ) );
		if ( '' !== $year ) {
			$max = (int) wp_date( 'Y' );
			if ( ! ctype_digit( $year ) || (int) $year < 2000 || (int) $year > $max ) {
				/* translators: %d: current year. */
				$errors[] = sprintf( __( 'Grade 7 year must be between 2000 and %d.', 'bsa-school-management' ), $max );
			} else {
				$data['grade7_year'] = (int) $year;
			}
		}

		$units = trim( (string) ( $input['grade7_units'] ?? '' ) );
		if ( '' !== $units ) {
			if ( ! ctype_digit( $units ) || (int) $units < 1 || (int) $units > 99 ) {
				$errors[] = __( 'Grade 7 units must be a whole number as shown on the results slip.', 'bsa-school-management' );
			} else {
				$data['grade7_units'] = (int) $units;
			}
		}

		// Subject grades (1–9) on the new-curriculum slip; only fields sent are changed.
		$given = array();
		foreach ( BSA_Entry::SUBJECTS as $field => $info ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$g = trim( (string) $input[ $field ] );
			if ( '' === $g ) {
				$data[ $field ] = null;
			} elseif ( ! ctype_digit( $g ) || (int) $g < 1 || (int) $g > 9 ) {
				/* translators: %s: subject. */
				$errors[] = sprintf( __( 'Grade 7 %s grade must be a whole number from 1 to 9, as on the slip.', 'bsa-school-management' ), $info[0] );
			} else {
				$data[ $field ] = (int) $g;
				$given[]        = (int) $g;
			}
		}
		if ( count( $given ) === count( BSA_Entry::SUBJECTS ) ) {
			if ( null === $data['grade7_units'] && '' === $units ) {
				$data['grade7_units'] = array_sum( $given );
			} elseif ( null !== $data['grade7_units'] && $data['grade7_units'] !== array_sum( $given ) ) {
				/* translators: 1: units typed, 2: sum of the subject grades. */
				$errors[] = sprintf( __( 'Grade 7 units (%1$d) do not match the six subject grades, which add up to %2$d.', 'bsa-school-management' ), $data['grade7_units'], array_sum( $given ) );
				$data['grade7_units'] = null;
			}
		}

		return array( $data, $errors );
	}

	/**
	 * Moves a file into the private store under a random name.
	 *
	 * @param string $source            Source path (an uploaded tmp file in production).
	 * @param string $original_name     Client file name (used for the extension only).
	 * @param int    $student_record_id Student record ID.
	 * @param bool   $is_upload         True for HTTP uploads (enforces is_uploaded_file).
	 * @return string|WP_Error Path relative to the store.
	 */
	public static function store_file( $source, $original_name, $student_record_id, $is_upload = true ) {
		if ( $is_upload && ! is_uploaded_file( $source ) ) {
			return new WP_Error( 'bsa_upload', __( 'The file upload failed. Please try again.', 'bsa-school-management' ) );
		}
		$size = (int) @filesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return new WP_Error( 'bsa_upload', __( 'Files must be smaller than 5 MB.', 'bsa-school-management' ) );
		}
		$check = wp_check_filetype_and_ext( $source, $original_name, self::MIME_TYPES );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
			return new WP_Error( 'bsa_upload', __( 'Only PDF, JPG and PNG files are accepted.', 'bsa-school-management' ) );
		}
		if ( ! self::ensure_store() ) {
			return new WP_Error( 'bsa_upload', __( 'The document store is not writable.', 'bsa-school-management' ) );
		}
		$relative = 'admission/' . absint( $student_record_id ) . '/' . wp_generate_password( 24, false ) . '.' . $check['ext'];
		$target   = self::store_dir() . '/' . $relative;
		wp_mkdir_p( dirname( $target ) );
		$moved = $is_upload ? move_uploaded_file( $source, $target ) : rename( $source, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! $moved ) {
			return new WP_Error( 'bsa_upload', __( 'The file could not be saved.', 'bsa-school-management' ) );
		}
		chmod( $target, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		return $relative;
	}

	/**
	 * Inserts or updates a learner's record.
	 *
	 * @param int   $student_record_id Student record ID.
	 * @param array $data              Column => value.
	 * @param int   $user_id           Acting user.
	 * @return bool
	 */
	public static function save_record( $student_record_id, array $data, $user_id ) {
		global $wpdb;
		$now                     = current_time( 'mysql' );
		$data['updated_by']      = (int) $user_id;
		$data['updated_at']      = $now;
		$exists                  = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE student_record_id = %d', $student_record_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $exists ) {
			return false !== $wpdb->update( self::table(), $data, array( 'student_record_id' => $student_record_id ) );
		}
		$data['student_record_id'] = (int) $student_record_id;
		$data['created_at']        = $now;
		return false !== $wpdb->insert( self::table(), $data );
	}

	/**
	 * Deletes a stored file (path relative to the store).
	 *
	 * @param string $relative Relative path.
	 */
	public static function delete_file( $relative ) {
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return;
		}
		$path = self::store_dir() . '/' . $relative;
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Adds the admin menu for permitted users only.
	 */
	public static function register_menu() {
		if ( ! self::user_can_manage( get_current_user_id() ) ) {
			return;
		}
		add_menu_page(
			__( 'Admission Documents', 'bsa-school-management' ),
			__( 'Admission Documents', 'bsa-school-management' ),
			'read',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-id-alt',
			27
		);
	}

	/**
	 * Admin screen: learner list, or the edit form for one learner.
	 */
	public static function render_page() {
		$user_id = get_current_user_id();
		if ( ! self::user_can_manage( $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to view admission documents.', 'bsa-school-management' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$student_id = isset( $_GET['student'] ) ? absint( $_GET['student'] ) : 0;
		$notice     = isset( $_GET['bsa_notice'] ) ? sanitize_key( wp_unslash( $_GET['bsa_notice'] ) ) : '';
		$errors     = get_transient( 'bsa_admission_errors_' . $user_id );
		// phpcs:enable
		delete_transient( 'bsa_admission_errors_' . $user_id );

		echo '<div class="wrap bsa-admission"><style>.bsa-admission .bsa-g7{display:flex;flex-wrap:wrap;gap:4px 20px}.bsa-admission .bsa-g7 p{margin:4px 0}</style>';
		echo '<h1>' . esc_html__( 'Admission Documents', 'bsa-school-management' ) . '</h1>';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Admission documents saved.', 'bsa-school-management' ) . '</p></div>';
		}
		if ( is_array( $errors ) && $errors ) {
			echo '<div class="notice notice-error"><ul>';
			foreach ( $errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( $student_id ) {
			self::render_form( $student_id, $user_id );
		} else {
			self::render_list( $user_id );
		}
		echo '</div>';
	}

	/**
	 * Learner list for the current session with document status.
	 *
	 * @param int $user_id Current user.
	 */
	private static function render_list( $user_id ) {
		global $wpdb;
		$p       = $wpdb->prefix;
		$session = (int) get_option( 'wlsm_current_session' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		$where  = $wpdb->prepare( 'sr.session_id = %d', $session );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (sr.name LIKE %s OR sr.admission_number LIKE %s)', $like, $like );
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT sr.ID, sr.name, sr.admission_number, cs.school_id, c.label AS class_label, se.label AS section_label,
				ar.birth_certificate_no, ar.birth_certificate_file, ar.grade7_results_file
			FROM {$p}wlsm_student_records sr
			JOIN {$p}wlsm_sections se ON se.ID = sr.section_id
			JOIN {$p}wlsm_class_school cs ON cs.ID = se.class_school_id
			JOIN {$p}wlsm_classes c ON c.ID = cs.class_id
			LEFT JOIN " . self::table() . " ar ON ar.student_record_id = sr.ID
			WHERE {$where}
			ORDER BY c.ID, se.label, sr.name
			LIMIT 500"
		);
		// phpcs:enable

		echo '<form method="get" class="bsa-search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label class="screen-reader-text" for="bsa-search">' . esc_html__( 'Search learners', 'bsa-school-management' ) . '</label>';
		echo '<input type="search" id="bsa-search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name or admission number', 'bsa-school-management' ) . '"> ';
		submit_button( __( 'Search', 'bsa-school-management' ), 'secondary', '', false );
		echo '</form>';

		$rows = array_filter(
			$rows,
			function ( $row ) use ( $user_id ) {
				return self::user_can_manage( $user_id, (int) $row->school_id );
			}
		);
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No learners found for the current session. Admit learners in School Management first, then record their documents here.', 'bsa-school-management' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Learner', 'bsa-school-management' ), __( 'Admission no.', 'bsa-school-management' ), __( 'Class', 'bsa-school-management' ), __( 'Birth certificate', 'bsa-school-management' ), __( 'Grade 7 results', 'bsa-school-management' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$birth = $row->birth_certificate_file ? __( 'Scan on file', 'bsa-school-management' ) : ( $row->birth_certificate_no ? __( 'Number only', 'bsa-school-management' ) : __( 'Missing', 'bsa-school-management' ) );
			$g7    = $row->grade7_results_file ? __( 'Slip on file', 'bsa-school-management' ) : __( 'Missing', 'bsa-school-management' );
			$url   = add_query_arg( array( 'page' => self::PAGE, 'student' => (int) $row->ID ), admin_url( 'admin.php' ) );
			echo '<tr><td>' . esc_html( $row->name ) . '</td><td>' . esc_html( $row->admission_number ) . '</td><td>' . esc_html( $row->class_label . ' ' . $row->section_label ) . '</td>';
			echo '<td>' . esc_html( $birth ) . '</td><td>' . esc_html( $g7 ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Edit', 'bsa-school-management' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Edit form for one learner.
	 *
	 * @param int $student_id Student record ID.
	 * @param int $user_id    Current user.
	 */
	private static function render_form( $student_id, $user_id ) {
		$learner = self::get_learner( $student_id );
		if ( ! $learner || ! self::user_can_manage( $user_id, (int) $learner->school_id ) ) {
			echo '<p>' . esc_html__( 'Learner not found.', 'bsa-school-management' ) . '</p>';
			return;
		}
		$record = self::get_record( $student_id );
		$back   = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );

		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All learners', 'bsa-school-management' ) . '</a></p>';
		echo '<h2>' . esc_html( $learner->name ) . ' <span class="description">(' . esc_html( $learner->admission_number . ', ' . $learner->class_label . ' ' . $learner->section_label ) . ')</span></h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="bsa_save_admission_record"><input type="hidden" name="student" value="' . (int) $student_id . '">';
		wp_nonce_field( 'bsa_save_admission_record_' . $student_id );
		echo '<table class="form-table" role="presentation"><tbody>';

		self::text_row( 'birth_certificate_no', __( 'Birth certificate number', 'bsa-school-management' ), (string) $record['birth_certificate_no'], 40 );
		self::file_row( 'birth_certificate_file', $student_id, (string) $record['birth_certificate_file'] );
		self::text_row( 'grade7_school', __( 'Primary school (Grade 7)', 'bsa-school-management' ), (string) $record['grade7_school'], 150 );
		self::text_row( 'grade7_year', __( 'Year Grade 7 was written', 'bsa-school-management' ), (string) $record['grade7_year'], 4, 'number' );
		self::text_row( 'grade7_units', __( 'Grade 7 total units (as on the results slip)', 'bsa-school-management' ), (string) $record['grade7_units'], 2, 'number' );
		echo '<tr><th scope="row">' . esc_html__( 'Grade 7 subject grades', 'bsa-school-management' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Grade 7 subject grades', 'bsa-school-management' ) . '</legend><p class="description">' . esc_html__( 'As on the slip: 1 (best, 85–100%) to 9. The total units are worked out when all six are filled in.', 'bsa-school-management' ) . '</p><div class="bsa-g7">';
		foreach ( BSA_Entry::SUBJECTS as $field => $info ) {
			printf( '<p><label for="%1$s">%2$s</label><br><input type="number" min="1" max="9" step="1" id="%1$s" name="%1$s" value="%3$s" class="small-text"></p>', esc_attr( $field ), esc_html( $info[0] ), esc_attr( (string) $record[ $field ] ) );
		}
		echo '</div></fieldset></td></tr>';
		self::file_row( 'grade7_results_file', $student_id, (string) $record['grade7_results_file'] );

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Scans: PDF, JPG or PNG, up to 5 MB. Files are stored privately and can only be opened by authorised staff.', 'bsa-school-management' ) . '</p>';
		submit_button( __( 'Save documents', 'bsa-school-management' ) );
		echo '</form>';
	}

	/**
	 * One text/number row.
	 *
	 * @param string $name  Field name.
	 * @param string $label Label.
	 * @param string $value Current value.
	 * @param int    $max   Max length.
	 * @param string $type  Input type.
	 */
	private static function text_row( $name, $label, $value, $max, $type = 'text' ) {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" maxlength="%5$d" class="regular-text"></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $value ),
			(int) $max
		);
	}

	/**
	 * One file row: current file (download / remove) and a replacement upload.
	 *
	 * @param string $field      File field.
	 * @param int    $student_id Student record ID.
	 * @param string $current    Current relative path.
	 */
	private static function file_row( $field, $student_id, $current ) {
		$label = self::FILE_FIELDS[ $field ];
		echo '<tr><th scope="row"><label for="' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( $current ) {
			echo '<p><a class="button button-small" href="' . esc_url( self::download_url( $student_id, $field ) ) . '">' . esc_html__( 'Download current file', 'bsa-school-management' ) . '</a> ';
			echo '<label><input type="checkbox" name="remove_' . esc_attr( $field ) . '" value="1"> ' . esc_html__( 'Remove', 'bsa-school-management' ) . '</label></p>';
		}
		echo '<input type="file" id="' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">';
		echo '</td></tr>';
	}

	/**
	 * Authenticated download link.
	 *
	 * @param int    $student_id Student record ID.
	 * @param string $field      File field.
	 * @return string
	 */
	public static function download_url( $student_id, $field ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'bsa_admission_file',
					'student' => (int) $student_id,
					'field'   => $field,
				),
				admin_url( 'admin-post.php' )
			),
			'bsa_admission_file_' . (int) $student_id . '_' . $field
		);
	}

	/**
	 * Saves the form (admin-post).
	 */
	public static function handle_save() {
		$user_id    = get_current_user_id();
		$student_id = isset( $_POST['student'] ) ? absint( $_POST['student'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( 'bsa_save_admission_record_' . $student_id );

		$learner = self::get_learner( $student_id );
		if ( ! $learner || ! self::user_can_manage( $user_id, (int) $learner->school_id ) ) {
			wp_die( esc_html__( 'You do not have permission to edit these admission documents.', 'bsa-school-management' ), 403 );
		}

		list( $data, $errors ) = self::validate_fields( wp_unslash( $_POST ) );
		$record                = self::get_record( $student_id );
		$replaced              = array();

		foreach ( array_keys( self::FILE_FIELDS ) as $field ) {
			if ( ! empty( $_FILES[ $field ]['name'] ) ) {
				$file = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in store_file().
				if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
					$errors[] = self::FILE_FIELDS[ $field ] . ': ' . __( 'the upload failed.', 'bsa-school-management' );
					continue;
				}
				$stored = self::store_file( $file['tmp_name'], sanitize_file_name( $file['name'] ), $student_id, true );
				if ( is_wp_error( $stored ) ) {
					$errors[] = self::FILE_FIELDS[ $field ] . ': ' . $stored->get_error_message();
					continue;
				}
				$data[ $field ] = $stored;
				if ( $record[ $field ] ) {
					$replaced[] = $record[ $field ];
				}
			} elseif ( ! empty( $_POST[ 'remove_' . $field ] ) && $record[ $field ] ) {
				$data[ $field ] = '';
				$replaced[]     = $record[ $field ];
			}
		}

		$redirect = add_query_arg( array( 'page' => self::PAGE, 'student' => $student_id ), admin_url( 'admin.php' ) );
		if ( $errors ) {
			// Discard files stored in this request; keep the existing record untouched.
			foreach ( array_keys( self::FILE_FIELDS ) as $field ) {
				if ( isset( $data[ $field ] ) && $data[ $field ] !== $record[ $field ] && '' !== $data[ $field ] ) {
					self::delete_file( $data[ $field ] );
				}
			}
			set_transient( 'bsa_admission_errors_' . $user_id, $errors, 120 );
			wp_safe_redirect( $redirect );
			exit;
		}

		self::save_record( $student_id, $data, $user_id );
		foreach ( $replaced as $old ) {
			self::delete_file( $old );
		}
		self::log( 'Admission documents updated for student record #{student}', $student_id );
		wp_safe_redirect( add_query_arg( 'bsa_notice', 'saved', $redirect ) );
		exit;
	}

	/**
	 * Streams a stored file to a permitted user (admin-post).
	 */
	public static function handle_download() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		$student_id = isset( $_GET['student'] ) ? absint( $_GET['student'] ) : 0;
		$field      = isset( $_GET['field'] ) ? sanitize_key( wp_unslash( $_GET['field'] ) ) : '';
		// phpcs:enable
		if ( ! isset( self::FILE_FIELDS[ $field ] ) ) {
			wp_die( esc_html__( 'Unknown document.', 'bsa-school-management' ), 404 );
		}
		check_admin_referer( 'bsa_admission_file_' . $student_id . '_' . $field );

		$learner = self::get_learner( $student_id );
		if ( ! $learner || ! self::user_can_manage( get_current_user_id(), (int) $learner->school_id ) ) {
			wp_die( esc_html__( 'You do not have permission to open this document.', 'bsa-school-management' ), 403 );
		}
		$record   = self::get_record( $student_id );
		$relative = (string) $record[ $field ];
		$path     = self::store_dir() . '/' . $relative;
		if ( '' === $relative || false !== strpos( $relative, '..' ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Document not found.', 'bsa-school-management' ), 404 );
		}
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$name = sanitize_file_name( $learner->admission_number . '-' . str_replace( '_file', '', $field ) . '.' . $ext );

		self::log( 'Admission document downloaded for student record #{student}', $student_id );
		nocache_headers();
		header( 'Content-Type: ' . ( self::MIME_TYPES[ $ext ] ?? 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Activity log entry without personal data.
	 *
	 * @param string $message    Message with {student} placeholder.
	 * @param int    $student_id Student record ID.
	 */
	private static function log( $message, $student_id ) {
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->info( $message, array( 'student' => (int) $student_id ) );
		}
	}
}
