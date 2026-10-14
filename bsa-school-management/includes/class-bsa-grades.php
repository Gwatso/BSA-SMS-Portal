<?php
/**
 * BSA grading rules (docs/academics-spec.md §1–2): grade keys printed in the BSA report
 * book, subject pass, overall result and class position.
 *
 * - Forms 1–2 (ZJC): 75–100 → 1, 70–74 → 2 … 40–44 → 8, 0–39 → 9.
 * - Forms 3–4 (O Level): 70–100 A, 60–69 B, 50–59 C, 45–49 D, 40–44 E, 0–39 U.
 * - Marks are percentages; a fraction is rounded half up before grading.
 * - Subject pass: 50 % and above. Result: Pass with 5 or more subjects passed.
 * - Exam marks decide subjects passed, result and position.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure grading functions (no database).
 */
class BSA_Grades {

	const PASS_MARK       = 50;
	const PASSES_REQUIRED = 5;

	/**
	 * Grade keys: [lowest mark => grade], highest band first.
	 *
	 * @return array{zjc: array<int,string>, olevel: array<int,string>}
	 */
	public static function keys() {
		return array(
			'zjc'    => array( 75 => '1', 70 => '2', 65 => '3', 60 => '4', 55 => '5', 50 => '6', 45 => '7', 40 => '8', 0 => '9' ),
			'olevel' => array( 70 => 'A', 60 => 'B', 50 => 'C', 45 => 'D', 40 => 'E', 0 => 'U' ),
		);
	}

	/**
	 * Which key a class uses: Forms 1–2 ZJC, Forms 3–4 O Level.
	 *
	 * @param string $class_label Weblizar class label, e.g. "Form 3".
	 * @return string 'zjc' or 'olevel'
	 */
	public static function scheme( $class_label ) {
		return preg_match( '/\b[34]\b/', (string) $class_label ) ? 'olevel' : 'zjc';
	}

	/**
	 * Whole-percentage mark, rounded half up; null for no mark.
	 *
	 * @param float|string|null $mark Mark.
	 * @return int|null
	 */
	public static function round_mark( $mark ) {
		if ( null === $mark || '' === $mark ) {
			return null;
		}
		return (int) floor( (float) $mark + 0.5 );
	}

	/**
	 * Grade for a mark.
	 *
	 * @param float|string|null $mark        Mark (0–100).
	 * @param string            $class_label Class label.
	 * @return string '' when there is no mark.
	 */
	public static function grade( $mark, $class_label ) {
		$rounded = self::round_mark( $mark );
		if ( null === $rounded ) {
			return '';
		}
		foreach ( self::keys()[ self::scheme( $class_label ) ] as $lowest => $grade ) {
			if ( $rounded >= $lowest ) {
				return $grade;
			}
		}
		return '';
	}

	/**
	 * Whether a mark is a subject pass.
	 *
	 * @param float|string|null $mark Mark.
	 * @return bool
	 */
	public static function is_pass( $mark ) {
		$rounded = self::round_mark( $mark );
		return null !== $rounded && $rounded >= self::PASS_MARK;
	}

	/**
	 * Overall result from the number of subjects passed.
	 *
	 * @param int $passed Subjects passed.
	 * @return string 'Pass' or 'Fail'
	 */
	public static function result( $passed ) {
		return $passed >= self::PASSES_REQUIRED ? 'Pass' : 'Fail';
	}

	/**
	 * Validates a mark typed by a teacher: '' (none) or 0–100 with at most one decimal.
	 *
	 * @param mixed $value Input.
	 * @return array{0: float|null, 1: bool} [mark, valid]
	 */
	public static function parse_mark( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array( null, true );
		}
		if ( ! preg_match( '/^\d{1,3}(\.\d)?$/', $value ) || (float) $value > 100 ) {
			return array( null, false );
		}
		return array( (float) $value, true );
	}

	/**
	 * Competition ranking (1, 2, 2, 4) of averages, highest first.
	 *
	 * @param array<int,float> $averages key => average.
	 * @return array<int,int> key => position
	 */
	public static function positions( array $averages ) {
		arsort( $averages );
		$out      = array();
		$position = 0;
		$seen     = 0;
		$previous = null;
		foreach ( $averages as $key => $average ) {
			++$seen;
			$rounded = round( (float) $average, 4 );
			if ( null === $previous || $rounded < $previous ) {
				$position = $seen;
				$previous = $rounded;
			}
			$out[ $key ] = $position;
		}
		return $out;
	}

	/**
	 * Initials of a teacher's name ("Willard Hlabati" → "WH").
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	public static function initials( $name ) {
		$out = '';
		foreach ( preg_split( '/[\s.]+/', trim( (string) $name ) ) as $part ) {
			if ( '' !== $part ) {
				$out .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
			}
		}
		return $out;
	}
}
