<?php
/**
 * Grade 7 entry level against results now (owner, 2026-10-16: some learners arrive with
 * strong Grade 7 results and then struggle from Form 1 Term 1; the school wants to see
 * it early).
 *
 * - Grade 7 grades come from the ZIMSEC results slip (new curriculum, from 2023): six
 *   subjects, each graded 1 (best) to 9. The slip's own key gives the percentage behind
 *   each grade: 1 = 85–100, 2 = 77–84, 3 = 70–76, 4 = 60–69, 5 = 50–59, 6 = 40–49,
 *   7 = 30–39, 8 = 20–29, 9 = 0–19.
 * - Term exam marks are put on the same 1–9 scale with that key, so "entered at 1, now 4"
 *   compares like with like. Each Grade 7 subject is matched with the secondary subjects
 *   that follow from it; Physical Education & Arts has no exam subject to match.
 * - The gap is the average, over matched subjects, of (grade now − grade at entry):
 *   positive means below the entry level. With only the total units on file, the entry
 *   level is units ÷ 6 against the overall average.
 * - Staff only (owner): shown on the Results screen and as an at-risk sign.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grade 7 entry level.
 */
class BSA_Entry {

	/**
	 * Lowest percentage of each grade (ZIMSEC Grade 7 key).
	 */
	const BANDS = array( 1 => 85, 2 => 77, 3 => 70, 4 => 60, 5 => 50, 6 => 40, 7 => 30, 8 => 20, 9 => 0 );

	/**
	 * Grade 7 subjects on the slip: field => [label, secondary subjects it leads to].
	 */
	const SUBJECTS = array(
		'grade7_english'  => array( 'English', array( 'english language' ) ),
		'grade7_maths'    => array( 'Mathematics', array( 'mathematics' ) ),
		'grade7_science'  => array( 'Agric, Science & Tech', array( 'combined science', 'physics', 'chemistry', 'biology' ) ),
		'grade7_language' => array( 'Shona', array( 'shona language' ) ),
		'grade7_social'   => array( 'Social Science', array( 'history', 'geography', 'heritage studies' ) ),
		'grade7_pe_arts'  => array( 'Physical Edu & Arts', array() ),
	);

	/**
	 * Subjects on the slip; a units total above this needs per-subject grades to compare.
	 */
	const SLIP_SUBJECTS = 6;

	/**
	 * A percentage on the Grade 7 scale (1–9).
	 *
	 * @param float|null $percent Mark.
	 * @return int|null
	 */
	public static function grade( $percent ) {
		if ( null === $percent ) {
			return null;
		}
		$mark = BSA_Grades::round_mark( (float) $percent );
		foreach ( self::BANDS as $grade => $low ) {
			if ( $mark >= $low ) {
				return $grade;
			}
		}
		return 9;
	}

	/**
	 * Percentage range of a grade, for the key ("85–100").
	 *
	 * @param int $grade 1–9.
	 * @return string
	 */
	public static function range( $grade ) {
		$low  = self::BANDS[ $grade ] ?? 0;
		$high = 1 === (int) $grade ? 100 : self::BANDS[ $grade - 1 ] - 1;
		return $low . '–' . $high;
	}

	/**
	 * Grade 7 results of learners, found through their admission number in any year (the
	 * admission record moves with the learner at the year-end rollover).
	 *
	 * @param int[] $student_ids Student record IDs.
	 * @return array<int,array{grades:array<string,int|null>, units:int|null, level:float|null, school:string, year:int|null}>
	 */
	public static function entries( array $student_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $student_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$p     = $wpdb->prefix;
		$cols  = implode( ', ', array_map( static function ( $f ) { return 'ar.' . $f; }, array_keys( self::SUBJECTS ) ) ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment
		$table = BSA_Admission_Records::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- integer list, fixed column names.
		$rows = $wpdb->get_results( "SELECT sr.ID AS sid, ar.grade7_units, ar.grade7_school, ar.grade7_year, {$cols} FROM {$p}wlsm_student_records sr JOIN {$p}wlsm_student_records s2 ON ( s2.ID = sr.ID OR ( TRIM(sr.admission_number) <> '' AND s2.admission_number = sr.admission_number ) ) JOIN {$table} ar ON ar.student_record_id = s2.ID WHERE sr.ID IN (" . implode( ',', $ids ) . ') ORDER BY ar.updated_at DESC' );
		$out  = array();
		foreach ( $rows as $r ) {
			$sid = (int) $r->sid;
			if ( isset( $out[ $sid ] ) ) {
				continue;
			}
			$grades = array();
			foreach ( array_keys( self::SUBJECTS ) as $f ) {
				$g            = $r->$f;
				$grades[ $f ] = null === $g || '' === $g ? null : (int) $g;
			}
			$given = array_filter(
				$grades,
				static function ( $g ) {
					return null !== $g;
				}
			);
			$units = null === $r->grade7_units ? ( count( $given ) === count( self::SUBJECTS ) ? array_sum( $given ) : null ) : (int) $r->grade7_units;
			if ( ! $given && null === $units ) {
				continue;
			}
			$level       = $given ? array_sum( $given ) / count( $given ) : ( $units >= self::SLIP_SUBJECTS ? $units / self::SLIP_SUBJECTS : null );
			$out[ $sid ] = array(
				'grades' => $grades,
				'units'  => $units,
				'level'  => $level,
				'school' => (string) $r->grade7_school,
				'year'   => null === $r->grade7_year ? null : (int) $r->grade7_year,
			);
		}
		return $out;
	}

	/**
	 * Entry level against a term's exam marks for every learner of a class.
	 *
	 * @param int $section_id Section ID.
	 * @param int $session_id Session ID.
	 * @param int $term       1–3.
	 * @return array<int,array> student ID => comparison (see compare()).
	 */
	public static function for_class( $section_id, $session_id, $term ) {
		$learners = BSA_School::learners( $section_id, $session_id );
		$ids      = array_map( 'intval', wp_list_pluck( $learners, 'ID' ) );
		$entries  = self::entries( $ids );
		$marks    = BSA_Marks::for_learners( $ids, $session_id, $term );
		$out      = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = self::compare( $entries[ $id ] ?? null, $marks[ $id ] ?? array(), BSA_Reports::learner_subjects( $id, $section_id ) );
		}
		return $out;
	}

	/**
	 * One learner's comparison.
	 *
	 * @param array|null $entry    From entries().
	 * @param array      $marks    subject ID => marks row (exam_mark).
	 * @param object[]   $subjects The learner's subjects {ID, label}.
	 * @return array{entry:array|null, level:float|null, now:float|null, now_average:float|null, gap:float|null, pairs:array}
	 */
	public static function compare( $entry, array $marks, array $subjects ) {
		$by_label = array();
		$all      = array();
		foreach ( $subjects as $s ) {
			$m = $marks[ (int) $s->ID ] ?? null;
			if ( $m && null !== $m->exam_mark ) {
				$by_label[ strtolower( trim( $s->label ) ) ] = (float) $m->exam_mark;
				$all[]                                     = (float) $m->exam_mark;
			}
		}
		$now_average = $all ? array_sum( $all ) / count( $all ) : null;
		$pairs       = array();
		if ( $entry ) {
			foreach ( self::SUBJECTS as $field => $info ) {
				$then = $entry['grades'][ $field ];
				$vals = array_values( array_intersect_key( $by_label, array_flip( $info[1] ) ) );
				$pct  = $vals ? array_sum( $vals ) / count( $vals ) : null;
				if ( null === $then && null === $pct ) {
					continue;
				}
				$now             = self::grade( $pct );
				$pairs[ $field ] = array(
					'label'   => $info[0],
					'then'    => $then,
					'now'     => $now,
					'percent' => $pct,
					'gap'     => null !== $then && null !== $now ? $now - $then : null,
				);
			}
		}
		$gaps = array_values( array_filter( wp_list_pluck( $pairs, 'gap' ), 'is_int' ) );
		if ( $gaps ) {
			$gap   = array_sum( $gaps ) / count( $gaps );
			$used  = array_filter(
				$pairs,
				static function ( $p ) {
					return null !== $p['gap'];
				}
			);
			$level = array_sum( wp_list_pluck( $used, 'then' ) ) / count( $used );
			$now   = array_sum( wp_list_pluck( $used, 'now' ) ) / count( $used );
		} else {
			// Units only: the overall level against the overall average.
			$level = $entry ? $entry['level'] : null;
			$now   = null === $now_average ? null : (float) self::grade( $now_average );
			$gap   = null !== $level && null !== $now && $entry && ! array_filter( $entry['grades'], 'is_int' ) ? $now - $level : null;
		}
		return array(
			'entry'       => $entry,
			'level'       => $level,
			'now'         => $now,
			'now_average' => $now_average,
			'gap'         => $gap,
			'pairs'       => $pairs,
		);
	}

	/**
	 * Words for a gap: "2.5 grades lower", "1 grade higher", "same level".
	 *
	 * @param float|null $gap Gap (positive: lower than at entry).
	 * @return string
	 */
	public static function gap_text( $gap ) {
		if ( null === $gap ) {
			return '–';
		}
		$n = BSA_Results::num( abs( $gap ) );
		if ( abs( $gap ) < 0.05 ) {
			return __( 'same level', 'bsa-school-management' );
		}
		/* translators: %s: number of grades. */
		return $gap > 0 ? sprintf( _n( '%s grade lower', '%s grades lower', abs( $gap ) > 1.05 ? 2 : 1, 'bsa-school-management' ), $n ) : sprintf( _n( '%s grade higher', '%s grades higher', abs( $gap ) > 1.05 ? 2 : 1, 'bsa-school-management' ), $n );
	}
}
