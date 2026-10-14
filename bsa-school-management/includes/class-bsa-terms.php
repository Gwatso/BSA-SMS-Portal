<?php
/**
 * School terms: start and end dates of Terms 1–3 for each Weblizar session (year).
 *
 * Weblizar has sessions (BSA: calendar years) but no terms. Term dates drive the class
 * register (weeks, possible attendances), report-card attendance and "Next term begins".
 * Stored in the option `bsa_terms`: [ session_id => [ 1 => [start, end], 2 => …, 3 => … ] ].
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Term dates and the School Terms screen.
 */
class BSA_Terms {

	const OPTION = 'bsa_terms';
	const PAGE   = 'bsa-school-terms';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_save_terms', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * All saved terms.
	 *
	 * @return array<int,array<int,array{start:string,end:string}>>
	 */
	public static function all() {
		$terms = get_option( self::OPTION, array() );
		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Terms of one session, keyed 1–3 (only terms with both dates).
	 *
	 * @param int $session_id Session ID.
	 * @return array<int,array{start:string,end:string}>
	 */
	public static function for_session( $session_id ) {
		$all = self::all();
		return isset( $all[ (int) $session_id ] ) ? $all[ (int) $session_id ] : array();
	}

	/**
	 * One term's dates, or null when not set.
	 *
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return array{start:string,end:string}|null
	 */
	public static function get( $session_id, $term ) {
		$terms = self::for_session( $session_id );
		return isset( $terms[ (int) $term ] ) ? $terms[ (int) $term ] : null;
	}

	/**
	 * The term containing a date, or the latest term already started (holidays between
	 * terms belong to the term just ended).
	 *
	 * @param string $date Y-m-d (default today).
	 * @return array{session:int,term:int,start:string,end:string}|null
	 */
	public static function current( $date = '' ) {
		$date = $date ? $date : current_time( 'Y-m-d' );
		$best = null;
		foreach ( self::all() as $session_id => $terms ) {
			foreach ( $terms as $term => $dates ) {
				if ( $dates['start'] <= $date && ( ! $best || $dates['start'] > $best['start'] ) ) {
					$best = array( 'session' => (int) $session_id, 'term' => (int) $term, 'start' => $dates['start'], 'end' => $dates['end'] );
				}
			}
		}
		return $best;
	}

	/**
	 * Start date of the term after the given one (next session's Term 1 after Term 3).
	 *
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return string Y-m-d or '' when not set.
	 */
	public static function next_start( $session_id, $term ) {
		$here = self::get( $session_id, $term );
		if ( ! $here ) {
			return '';
		}
		$next = '';
		foreach ( self::all() as $terms ) {
			foreach ( $terms as $dates ) {
				if ( $dates['start'] > $here['end'] && ( '' === $next || $dates['start'] < $next ) ) {
					$next = $dates['start'];
				}
			}
		}
		return $next;
	}

	/**
	 * Fridays ending the weeks of a term (the paper register's "week ending").
	 *
	 * @param string $start Y-m-d.
	 * @param string $end   Y-m-d.
	 * @return string[] Y-m-d Fridays.
	 */
	public static function week_endings( $start, $end ) {
		$out    = array();
		$friday = new DateTimeImmutable( $start );
		$dow    = (int) $friday->format( 'N' );
		$friday = $dow <= 5 ? $friday->modify( '+' . ( 5 - $dow ) . ' days' ) : $friday->modify( '+' . ( 12 - $dow ) . ' days' );
		$stop   = new DateTimeImmutable( $end );
		while ( $friday->modify( '-4 days' ) <= $stop ) {
			$out[] = $friday->format( 'Y-m-d' );
			$friday = $friday->modify( '+7 days' );
		}
		return $out;
	}

	/**
	 * Validates submitted dates. Returns [terms, errors].
	 *
	 * @param array  $input [term => [start, end]] (Y-m-d strings).
	 * @param string $first Earliest allowed date (session start), '' for none.
	 * @param string $last  Latest allowed date (session end), '' for none.
	 * @return array{0: array<int,array{start:string,end:string}>, 1: string[]}
	 */
	public static function validate( array $input, $first = '', $last = '' ) {
		$terms  = array();
		$errors = array();
		$valid  = function ( $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			$d     = DateTime::createFromFormat( '!Y-m-d', $value );
			return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';
		};
		for ( $t = 1; $t <= 3; $t++ ) {
			$start = $valid( $input[ $t ]['start'] ?? '' );
			$end   = $valid( $input[ $t ]['end'] ?? '' );
			$given = '' !== trim( (string) ( $input[ $t ]['start'] ?? '' ) ) || '' !== trim( (string) ( $input[ $t ]['end'] ?? '' ) );
			if ( ! $given ) {
				continue;
			}
			if ( ! $start || ! $end ) {
				/* translators: %d: term number. */
				$errors[] = sprintf( __( 'Term %d: enter both a start and an end date.', 'bsa-school-management' ), $t );
				continue;
			}
			if ( $start > $end ) {
				/* translators: %d: term number. */
				$errors[] = sprintf( __( 'Term %d ends before it starts.', 'bsa-school-management' ), $t );
				continue;
			}
			if ( ( $first && $start < $first ) || ( $last && $end > $last ) ) {
				/* translators: 1: term number, 2: first date, 3: last date. */
				$errors[] = sprintf( __( 'Term %1$d must fall within the school year (%2$s to %3$s).', 'bsa-school-management' ), $t, $first, $last );
				continue;
			}
			$terms[ $t ] = array( 'start' => $start, 'end' => $end );
		}
		$previous = null;
		foreach ( $terms as $t => $dates ) {
			if ( $previous && $dates['start'] <= $terms[ $previous ]['end'] ) {
				/* translators: 1: term number, 2: previous term number. */
				$errors[] = sprintf( __( 'Term %1$d must start after Term %2$d ends.', 'bsa-school-management' ), $t, $previous );
			}
			$previous = $t;
		}
		return array( $terms, $errors );
	}

	/**
	 * Saves one session's terms (already validated).
	 *
	 * @param int   $session_id Session ID.
	 * @param array $terms      Terms.
	 */
	public static function save( $session_id, array $terms ) {
		$all                       = self::all();
		$all[ (int) $session_id ] = $terms;
		ksort( $all );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Menu for school administrators.
	 */
	public static function register_menu() {
		if ( ! BSA_School::is_school_admin( get_current_user_id() ) ) {
			return;
		}
		add_menu_page( __( 'School Terms', 'bsa-school-management' ), __( 'School Terms', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-calendar-alt', 29 );
	}

	/**
	 * School Terms screen: Terms 1–3 for each session.
	 */
	public static function render_page() {
		if ( ! BSA_School::is_school_admin( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You do not have permission to set term dates.', 'bsa-school-management' ), 403 );
		}
		$errors = get_transient( 'bsa_terms_errors_' . get_current_user_id() );
		delete_transient( 'bsa_terms_errors_' . get_current_user_id() );
		echo '<div class="wrap"><h1>' . esc_html__( 'School Terms', 'bsa-school-management' ) . '</h1>';
		echo '<p>' . esc_html__( 'Term dates drive the class register, attendance on report cards and "Next term begins on". Weekends and days without a register are not counted as school days.', 'bsa-school-management' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
		if ( isset( $_GET['bsa_notice'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Term dates saved.', 'bsa-school-management' ) . '</p></div>';
		}
		if ( is_array( $errors ) && $errors ) {
			echo '<div class="notice notice-error"><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $errors ) ) . '</li></ul></div>';
		}
		foreach ( BSA_School::sessions() as $session_id => $label ) {
			$terms = self::for_session( $session_id );
			echo '<h2>' . esc_html( $label ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_save_terms"><input type="hidden" name="session" value="' . (int) $session_id . '">';
			wp_nonce_field( 'bsa_save_terms_' . $session_id );
			echo '<table class="widefat striped" style="max-width:560px"><thead><tr><th>' . esc_html__( 'Term', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Starts', 'bsa-school-management' ) . '</th><th>' . esc_html__( 'Ends', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
			for ( $t = 1; $t <= 3; $t++ ) {
				/* translators: %d: term number. */
				echo '<tr><th scope="row">' . esc_html( sprintf( __( 'Term %d', 'bsa-school-management' ), $t ) ) . '</th>';
				foreach ( array( 'start', 'end' ) as $key ) {
					$id = 'bsa-term-' . $session_id . '-' . $t . '-' . $key;
					/* translators: 1: term number, 2: "start" or "end". */
					echo '<td><label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( 'Term %1$d %2$s', 'bsa-school-management' ), $t, $key ) ) . '</label><input type="date" id="' . esc_attr( $id ) . '" name="terms[' . $t . '][' . $key . ']" value="' . esc_attr( $terms[ $t ][ $key ] ?? '' ) . '"></td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
			submit_button( __( 'Save term dates', 'bsa-school-management' ), 'primary', 'submit', true, array( 'id' => 'bsa-save-terms-' . $session_id ) );
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Saves the form (admin-post).
	 */
	public static function handle_save() {
		$session_id = isset( $_POST['session'] ) ? absint( $_POST['session'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next.
		check_admin_referer( 'bsa_save_terms_' . $session_id );
		if ( ! BSA_School::is_school_admin( get_current_user_id() ) || ! isset( BSA_School::sessions()[ $session_id ] ) ) {
			wp_die( esc_html__( 'You do not have permission to set term dates.', 'bsa-school-management' ), 403 );
		}
		$input                 = isset( $_POST['terms'] ) && is_array( $_POST['terms'] ) ? wp_unslash( $_POST['terms'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in validate().
		global $wpdb;
		$year                  = $wpdb->get_row( $wpdb->prepare( "SELECT start_date, end_date FROM {$wpdb->prefix}wlsm_sessions WHERE ID = %d", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		list( $terms, $errors ) = self::validate( $input, $year ? substr( $year->start_date, 0, 10 ) : '', $year ? substr( $year->end_date, 0, 10 ) : '' );
		$url                   = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
		if ( $errors ) {
			set_transient( 'bsa_terms_errors_' . get_current_user_id(), $errors, 120 );
			wp_safe_redirect( $url );
			exit;
		}
		self::save( $session_id, $terms );
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->info( 'School term dates updated for session #{session}', array( 'session' => $session_id ) );
		}
		wp_safe_redirect( add_query_arg( 'bsa_notice', 'saved', $url ) );
		exit;
	}
}
