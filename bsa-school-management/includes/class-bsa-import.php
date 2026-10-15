<?php
/**
 * Import data (wp-admin): learners and parents from an Excel (.xlsx), Word (.docx) or CSV
 * file, or a table pasted from Word or Excel. Columns are matched by their headings (and can be
 * changed), every row is checked and previewed before anything is saved, only rows
 * without errors are imported, and a whole import can be undone.
 *
 * - Owner and school administrators only.
 * - Records are written as Weblizar student records of the chosen school year, without
 *   learner or parent logins (owner decision: parent emails are collected later) and
 *   without Weblizar's automatic admission invoices (fee balances are imported apart).
 * - Grade 7 results and birth certificate numbers go to the Admission Documents record.
 * - The uploaded file is read once and not kept; the parsed rows wait for an hour in a
 *   transient tied to the user, then disappear.
 *
 * @package BSA_School_Management
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data import.
 */
class BSA_Import {

	const PAGE      = 'bsa-import';
	const MAX_BYTES = 2097152;
	const MAX_ROWS  = 2000;
	const BATCHES   = 'bsa_sm_import_batches';

	/**
	 * Fields a learner row can have: key => [label, synonyms of the column heading].
	 */
	const FIELDS = array(
		'name'                 => array( 'Full name', array( 'name', 'full name', 'names', 'learner', 'learner name', 'learners name', 'student', 'student name', 'pupil', 'pupil name', 'name of learner', 'name and surname', 'learner full name' ) ),
		'first_names'          => array( 'First name(s)', array( 'first name', 'first names', 'firstname', 'forename', 'forenames', 'given name', 'given names', 'christian name' ) ),
		'surname'              => array( 'Surname', array( 'surname', 'last name', 'lastname', 'family name' ) ),
		'class'                => array( 'Class (form and stream)', array( 'class', 'form', 'class form', 'form class', 'form and stream', 'form stream', 'class name', 'grade', 'level' ) ),
		'stream'               => array( 'Stream (if in its own column)', array( 'stream', 'section', 'combination', 'subject combination' ) ),
		'gender'               => array( 'Sex', array( 'sex', 'gender', 'm f', 'boy girl' ) ),
		'dob'                  => array( 'Date of birth', array( 'dob', 'date of birth', 'birth date', 'd o b', 'birthday', 'born' ) ),
		'admission_number'     => array( 'Admission number', array( 'admission number', 'admission no', 'adm no', 'adm number', 'admission', 'reg no', 'reg number', 'registration number', 'student number', 'learner number', 'student id', 'learner id' ) ),
		'residence'            => array( 'Boarder or day', array( 'boarder', 'boarding', 'boarder day', 'day boarder', 'boarder or day', 'day or boarder', 'residence', 'boarding status', 'type' ) ),
		'religion'             => array( 'Religion', array( 'religion', 'church', 'denomination' ) ),
		'address'              => array( 'Home address', array( 'address', 'home address', 'residential address', 'physical address' ) ),
		'phone'                => array( 'Learner phone', array( 'learner phone', 'student phone', 'learner cell', 'student cell' ) ),
		'father_name'          => array( 'Father\'s name', array( 'father', 'father name', 'fathers name', 'name of father' ) ),
		'father_phone'         => array( 'Father\'s phone', array( 'father phone', 'fathers phone', 'father cell', 'father contact', 'fathers contact', 'father mobile' ) ),
		'mother_name'          => array( 'Mother\'s name', array( 'mother', 'mother name', 'mothers name', 'name of mother' ) ),
		'mother_phone'         => array( 'Mother\'s phone', array( 'mother phone', 'mothers phone', 'mother cell', 'mother contact', 'mothers contact', 'mother mobile' ) ),
		'guardian_name'        => array( 'Parent or guardian name', array( 'guardian', 'guardian name', 'parent', 'parent name', 'parent guardian', 'parent or guardian', 'name of parent', 'name of guardian', 'next of kin' ) ),
		'guardian_phone'       => array( 'Parent or guardian phone', array( 'guardian phone', 'parent phone', 'parent contact', 'guardian contact', 'contact', 'contact number', 'phone', 'phone number', 'cell', 'cell number', 'mobile', 'mobile number', 'telephone', 'tel' ) ),
		'admission_date'       => array( 'Admission date', array( 'admission date', 'date of admission', 'date admitted', 'date joined', 'enrolment date', 'enrollment date' ) ),
		'grade7_units'         => array( 'Grade 7 units', array( 'grade 7 units', 'grade 7', 'grade 7 results', 'g7 units', 'g7', 'units', 'grade seven units', 'aggregate' ) ),
		'grade7_school'        => array( 'Primary school', array( 'primary school', 'grade 7 school', 'previous school', 'former school', 'school attended' ) ),
		'grade7_year'          => array( 'Grade 7 year', array( 'grade 7 year', 'year of grade 7', 'g7 year' ) ),
		'birth_certificate_no' => array( 'Birth certificate number', array( 'birth certificate', 'birth certificate number', 'birth certificate no', 'birth cert', 'birth cert no', 'birth entry number', 'birth entry no' ) ),
	);

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_bsa_import_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_bsa_import_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_bsa_import_undo', array( __CLASS__, 'handle_undo' ) );
		add_action( 'admin_post_bsa_import_template', array( __CLASS__, 'handle_template' ) );
	}

	/**
	 * Who may import: the owner and school administrators.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function user_can_import( $user_id ) {
		return BSA_School::is_school_admin( $user_id );
	}

	/**
	 * Menu entry.
	 */
	public static function register_menu() {
		if ( self::user_can_import( get_current_user_id() ) ) {
			add_menu_page( __( 'Import data', 'bsa-school-management' ), __( 'Import data', 'bsa-school-management' ), 'read', self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-upload', 34 );
		}
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * Reading files
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Cells of the first worksheet of an .xlsx file.
	 *
	 * @param string $path File.
	 * @return array[]|WP_Error Rows of strings.
	 */
	public static function read_xlsx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'bsa_import', __( 'Excel files cannot be read on this server; save the sheet as CSV instead.', 'bsa-school-management' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'bsa_import', __( 'This is not a readable Excel (.xlsx) file.', 'bsa-school-management' ) );
		}
		$get = static function ( $name ) use ( $zip ) {
			$stat = $zip->statName( $name );
			// Refuse unreasonably large parts (protects against zip bombs).
			if ( ! $stat || $stat['size'] > 25 * 1024 * 1024 ) {
				return false;
			}
			return $zip->getFromName( $name );
		};
		$xml = static function ( $string ) {
			if ( false === $string || '' === $string ) {
				return null;
			}
			$prev = libxml_use_internal_errors( true );
			$doc  = simplexml_load_string( $string, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			return false === $doc ? null : $doc;
		};
		// First sheet: from the workbook and its relationships.
		$sheet_path = 'xl/worksheets/sheet1.xml';
		$wb         = $xml( $get( 'xl/workbook.xml' ) );
		$rels       = $xml( $get( 'xl/_rels/workbook.xml.rels' ) );
		if ( $wb && $rels && isset( $wb->sheets->sheet[0] ) ) {
			$rid = (string) $wb->sheets->sheet[0]->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
			foreach ( $rels->Relationship as $rel ) {
				if ( (string) $rel['Id'] === $rid ) {
					$target     = ltrim( (string) $rel['Target'], '/' );
					$sheet_path = 0 === strpos( $target, 'xl/' ) ? $target : 'xl/' . $target;
				}
			}
		}
		$shared = array();
		$ss     = $xml( $get( 'xl/sharedStrings.xml' ) );
		if ( $ss ) {
			foreach ( $ss->si as $si ) {
				if ( isset( $si->t ) ) {
					$shared[] = (string) $si->t;
				} else {
					$text = '';
					foreach ( $si->r as $run ) {
						$text .= (string) $run->t;
					}
					$shared[] = $text;
				}
			}
		}
		$sheet = $xml( $get( $sheet_path ) );
		$zip->close();
		if ( ! $sheet || ! isset( $sheet->sheetData ) ) {
			return new WP_Error( 'bsa_import', __( 'The first sheet of this Excel file could not be read.', 'bsa-school-management' ) );
		}
		$rows = array();
		foreach ( $sheet->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				$ref = (string) $c['r'];
				$col = preg_match( '/^([A-Z]+)/', $ref, $m ) ? self::column_index( $m[1] ) : count( $cells );
				$t   = (string) $c['t'];
				if ( 's' === $t ) {
					$v = $shared[ (int) $c->v ] ?? '';
				} elseif ( 'inlineStr' === $t ) {
					$v = isset( $c->is->t ) ? (string) $c->is->t : '';
				} elseif ( 'b' === $t ) {
					$v = '1' === (string) $c->v ? 'TRUE' : 'FALSE';
				} else {
					$v = (string) $c->v;
				}
				$cells[ $col ] = trim( $v );
			}
			if ( $cells ) {
				$max  = max( array_keys( $cells ) );
				$line = array();
				for ( $i = 0; $i <= $max; $i++ ) {
					$line[] = $cells[ $i ] ?? '';
				}
				$rows[] = $line;
			} else {
				$rows[] = array();
			}
			if ( count( $rows ) > self::MAX_ROWS + 20 ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Rows of the tables in a Word (.docx) document. A class list split over several
	 * tables (one per page, or with the heading repeated) is joined into one: tables with
	 * the same number of columns as the first are appended, without repeated headings.
	 *
	 * @param string $path File.
	 * @return array[]|WP_Error Rows of strings.
	 */
	public static function read_docx( $path ) {
		if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'bsa_import', __( 'Word files cannot be read on this server; copy the table and paste it instead.', 'bsa-school-management' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'bsa_import', __( 'This is not a readable Word (.docx) file.', 'bsa-school-management' ) );
		}
		$stat = $zip->statName( 'word/document.xml' );
		$xml  = $stat && $stat['size'] <= 25 * 1024 * 1024 ? $zip->getFromName( 'word/document.xml' ) : false;
		$zip->close();
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = false !== $xml && '' !== $xml && $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return new WP_Error( 'bsa_import', __( 'This Word file could not be read.', 'bsa-school-management' ) );
		}
		$x = new DOMXPath( $doc );
		$x->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
		$text = static function ( DOMNode $cell ) use ( $x ) {
			$parts = array();
			foreach ( $x->query( './/w:p', $cell ) as $para ) {
				$s = '';
				foreach ( $x->query( './/w:t|.//w:tab|.//w:br', $para ) as $node ) {
					$s .= 't' === $node->localName ? $node->textContent : ' ';
				}
				$parts[] = trim( $s );
			}
			return trim( preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );
		};
		$rows   = array();
		$width  = null;
		$header = null;
		foreach ( $x->query( '//w:tbl[not(ancestor::w:tbl)]' ) as $table ) {
			$lines = array();
			foreach ( $x->query( './w:tr', $table ) as $tr ) {
				$line = array();
				foreach ( $x->query( './w:tc', $tr ) as $tc ) {
					$line[] = $text( $tc );
					$span   = $x->query( './w:tcPr/w:gridSpan/@w:val', $tc );
					for ( $i = 1; $span->length && $i < (int) $span->item( 0 )->nodeValue; $i++ ) {
						$line[] = '';
					}
				}
				$lines[] = $line;
			}
			$filled = array_values(
				array_filter(
					$lines,
					static function ( $l ) {
						return count( array_filter( $l, 'strlen' ) ) >= 2;
					}
				)
			);
			if ( ! $filled ) {
				continue;
			}
			$cols = max( array_map( 'count', $filled ) );
			if ( null === $width ) {
				$width  = $cols;
				$header = $filled[0];
				$rows   = $lines;
			} elseif ( $cols === $width ) {
				foreach ( $lines as $l ) {
					if ( $l !== $header ) {
						$rows[] = $l;
					}
				}
			}
			if ( count( $rows ) > self::MAX_ROWS + 20 ) {
				break;
			}
		}
		if ( ! $rows ) {
			return new WP_Error( 'bsa_import', __( 'No table was found in this Word file.', 'bsa-school-management' ) );
		}
		return $rows;
	}

	/**
	 * Zero-based index of a column letter (A = 0, AA = 26).
	 *
	 * @param string $letters Letters.
	 * @return int
	 */
	public static function column_index( $letters ) {
		$n = 0;
		foreach ( str_split( strtoupper( $letters ) ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}

	/**
	 * Rows of delimited text: CSV (comma or semicolon) or a table pasted from Word or
	 * Excel (tab separated).
	 *
	 * @param string $text Text.
	 * @return array[]
	 */
	public static function read_text( $text ) {
		$text = (string) $text;
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		$text  = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$first = strtok( $text, "\n" );
		$delim = substr_count( (string) $first, "\t" ) ? "\t" : ( substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ? ';' : ',' );
		$rows  = array();
		$fh    = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		while ( false !== ( $line = fgetcsv( $fh, 0, $delim, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$rows[] = array_map(
				static function ( $v ) {
					return trim( (string) $v );
				},
				null === $line[0] && 1 === count( $line ) ? array() : $line
			);
			if ( count( $rows ) > self::MAX_ROWS + 20 ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/**
	 * Splits rows into the heading row and the data rows (with their line numbers).
	 * The heading row is the first of the first ten with two or more filled cells.
	 *
	 * @param array[] $rows Rows.
	 * @return array{headers: string[], data: array<int,string[]>}
	 */
	public static function split_headers( array $rows ) {
		$head = null;
		foreach ( array_slice( $rows, 0, 10, true ) as $i => $row ) {
			if ( count( array_filter( $row, 'strlen' ) ) >= 2 ) {
				$head = $i;
				break;
			}
		}
		if ( null === $head ) {
			return array( 'headers' => array(), 'data' => array() );
		}
		$headers = array_map( 'strval', $rows[ $head ] );
		$data    = array();
		foreach ( $rows as $i => $row ) {
			if ( $i > $head && array_filter( $row, 'strlen' ) ) {
				$data[ $i + 1 ] = array_pad( $row, count( $headers ), '' );
			}
		}
		return array( 'headers' => $headers, 'data' => $data );
	}

	/**
	 * Lower-case heading with punctuation removed.
	 *
	 * @param string $text Heading.
	 * @return string
	 */
	public static function norm( $text ) {
		$text = strtolower( remove_accents( (string) $text ) );
		$text = str_replace( array( "'", '’' ), '', $text );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
	}

	/**
	 * Guesses which column holds which field from the headings.
	 *
	 * @param string[] $headers Headings.
	 * @return array<string,int> field => column index (fields not found are left out).
	 */
	public static function guess_mapping( array $headers ) {
		$norm = array_map( array( __CLASS__, 'norm' ), $headers );
		$map  = array();
		$used = array();
		// Exact matches first, in field order; then headings that start with a synonym.
		foreach ( array( 'exact', 'prefix' ) as $pass ) {
			foreach ( self::FIELDS as $key => $f ) {
				if ( isset( $map[ $key ] ) ) {
					continue;
				}
				foreach ( $norm as $i => $h ) {
					if ( isset( $used[ $i ] ) || '' === $h ) {
						continue;
					}
					foreach ( $f[1] as $syn ) {
						if ( ( 'exact' === $pass && $h === $syn ) || ( 'prefix' === $pass && strlen( $syn ) >= 4 && 0 === strpos( $h, $syn . ' ' ) ) ) {
							$map[ $key ] = $i;
							$used[ $i ]  = true;
							break 2;
						}
					}
				}
			}
		}
		return $map;
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * Values
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Section for a class written as on school lists: "Form 3 Sciences", "F3 Sci", "3A",
	 * "Form 1", or the form and the stream in two columns.
	 *
	 * @param string $class  Class text.
	 * @param string $stream Stream text (optional).
	 * @return int|WP_Error Section ID.
	 */
	public static function parse_class( $class, $stream = '' ) {
		$text = self::norm( $class . ' ' . $stream );
		// "Form One", "Form II" and similar.
		$text = preg_replace(
			array( '/\b(one|i)\b/', '/\b(two|ii)\b/', '/\b(three|iii)\b/', '/\b(four|iv)\b/' ),
			array( '1', '2', '3', '4' ),
			$text
		);
		if ( ! preg_match( '/(?:^|\D)([1-4])(?:\D|$)/', $text, $m ) ) {
			/* translators: %s: class as written. */
			return new WP_Error( 'class', sprintf( __( 'Class "%s" not recognised: write the form, e.g. "Form 3 Sciences".', 'bsa-school-management' ), trim( $class . ' ' . $stream ) ) );
		}
		$form    = 'Form ' . $m[1];
		$want    = preg_match( '/\bsci/', $text ) ? 'Sciences' : ( preg_match( '/\bcom/', $text ) ? 'Commercials' : '' );
		$options = array();
		foreach ( BSA_School::sections() as $s ) {
			if ( $s->class_label === $form ) {
				$options[ $s->label ] = (int) $s->ID;
			}
		}
		if ( ! $options ) {
			/* translators: %s: form. */
			return new WP_Error( 'class', sprintf( __( '%s does not exist in School Management.', 'bsa-school-management' ), $form ) );
		}
		if ( $want ) {
			if ( isset( $options[ $want ] ) ) {
				return $options[ $want ];
			}
			/* translators: 1: form, 2: stream. */
			return new WP_Error( 'class', sprintf( __( '%1$s has no %2$s stream.', 'bsa-school-management' ), $form, $want ) );
		}
		if ( 1 === count( $options ) ) {
			return reset( $options );
		}
		/* translators: 1: form, 2: streams. */
		return new WP_Error( 'class', sprintf( __( '%1$s has %2$s: say which stream.', 'bsa-school-management' ), $form, implode( __( ' and ', 'bsa-school-management' ), array_keys( $options ) ) ) );
	}

	/**
	 * A date written in any common way: 24/05/2012, 2012-05-24, 24 May 2012, 24.5.12 or an
	 * Excel date number. Days come before months (as written in Zimbabwe).
	 *
	 * @param string $value Text.
	 * @return string Y-m-d, or '' when not a date.
	 */
	public static function parse_date( $value ) {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return '';
		}
		$ok = static function ( $y, $m, $d ) {
			return checkdate( (int) $m, (int) $d, (int) $y ) && (int) $y >= 1950 && (int) $y <= 2100 ? sprintf( '%04d-%02d-%02d', $y, $m, $d ) : '';
		};
		if ( preg_match( '/^\d{4,5}(\.\d+)?$/', $v ) && (float) $v > 18000 && (float) $v < 80000 ) {
			return gmdate( 'Y-m-d', (int) round( ( (float) $v - 25569 ) * 86400 ) );
		}
		if ( preg_match( '/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $v, $m ) ) {
			return $ok( $m[1], $m[2], $m[3] );
		}
		if ( preg_match( '/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})$/', $v, $m ) ) {
			$y = (int) $m[3];
			if ( $y < 100 ) {
				$y += $y > (int) gmdate( 'y' ) ? 1900 : 2000;
			}
			return $ok( $y, $m[2], $m[1] );
		}
		$months = array( 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 );
		if ( preg_match( '/^(\d{1,2})(?:st|nd|rd|th)?[\s\-]+([a-z]{3})[a-z]*\.?,?[\s\-]+(\d{4})$/i', $v, $m ) && isset( $months[ strtolower( $m[2] ) ] ) ) {
			return $ok( $m[3], $months[ strtolower( $m[2] ) ], $m[1] );
		}
		if ( preg_match( '/^([a-z]{3})[a-z]*\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/i', $v, $m ) && isset( $months[ strtolower( $m[1] ) ] ) ) {
			return $ok( $m[3], $months[ strtolower( $m[1] ) ], $m[2] );
		}
		return '';
	}

	/**
	 * Sex as stored by Weblizar.
	 *
	 * @param string $value Text.
	 * @return string 'male', 'female' or ''.
	 */
	public static function parse_gender( $value ) {
		$v = self::norm( $value );
		if ( in_array( $v, array( 'm', 'male', 'boy', 'b' ), true ) ) {
			return 'male';
		}
		if ( in_array( $v, array( 'f', 'female', 'girl', 'g' ), true ) ) {
			return 'female';
		}
		return '';
	}

	/**
	 * A Zimbabwe phone number as 07XXXXXXXX (other numbers kept as written).
	 *
	 * @param string $value Text.
	 * @return array{0:string, 1:bool} [number, recognised]
	 */
	public static function parse_phone( $value ) {
		$raw    = trim( (string) $value );
		$digits = preg_replace( '/\D/', '', $raw );
		if ( '' === $digits ) {
			return array( '', true );
		}
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '263' ) ) {
			$digits = '0' . substr( $digits, 3 );
		} elseif ( 9 === strlen( $digits ) && '7' === $digits[0] ) {
			$digits = '0' . $digits;
		}
		if ( preg_match( '/^07[1-8]\d{7}$/', $digits ) ) {
			return array( $digits, true );
		}
		return array( substr( sanitize_text_field( $raw ), 0, 40 ), false );
	}

	/**
	 * Boarder or day scholar as Weblizar's learner type ID.
	 *
	 * @param string $value Text.
	 * @return array{0:string, 1:bool} [type ID or '', recognised]
	 */
	public static function parse_residence( $value ) {
		$v = self::norm( $value );
		if ( '' === $v ) {
			return array( '', true );
		}
		global $wpdb;
		$types = $wpdb->get_results( "SELECT ID, label FROM {$wpdb->prefix}wlsm_student_type" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$want  = preg_match( '/\bb(oard|oarder|oarding)?\b|^b$/', $v ) ? 'board' : ( preg_match( '/\bd(ay)?\b|day scholar|^d$/', $v ) ? 'day' : '' );
		foreach ( $types as $t ) {
			if ( $want && false !== stripos( $t->label, $want ) ) {
				return array( (string) $t->ID, true );
			}
		}
		return array( '', false );
	}

	/**
	 * A name tidied: spaces collapsed; ALL CAPS turned into Title Case.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function tidy_name( $value ) {
		$v = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $value ) ) );
		if ( $v === strtoupper( $v ) && preg_match( '/[A-Z]{2}/', $v ) ) {
			$v = ucwords( strtolower( $v ), " -'" );
		}
		return $v;
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * Checking and importing
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Checks every row and builds the record to save.
	 *
	 * @param array<int,string[]> $data            Line => cells.
	 * @param array<string,int>   $mapping         Field => column.
	 * @param int                 $session_id      School year.
	 * @param int                 $default_section Class for rows without one (a list of one class).
	 * @return array<int,array{line:int, record:array, extra:array, errors:string[], warnings:string[], notes:string[]}>
	 */
	public static function check( array $data, array $mapping, $session_id, $default_section = 0 ) {
		global $wpdb;
		$p        = $wpdb->prefix;
		$get      = static function ( $row, $key ) use ( $mapping ) {
			return isset( $mapping[ $key ] ) && $mapping[ $key ] >= 0 ? trim( (string) ( $row[ $mapping[ $key ] ] ?? '' ) ) : '';
		};
		$existing = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, name, admission_number FROM {$p}wlsm_student_records WHERE session_id = %d", $session_id ) ) as $r ) {
			$existing['adm'][ strtoupper( trim( (string) $r->admission_number ) ) ] = $r;
			$existing['name'][ self::norm( $r->name ) ]                             = $r;
		}
		$other_years = array();
		$other_names = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT UPPER(TRIM(sr.admission_number)) AS adm, sr.name, sr.dob, se.label AS year FROM {$p}wlsm_student_records sr LEFT JOIN {$p}wlsm_sessions se ON se.ID = sr.session_id WHERE sr.session_id <> %d AND sr.admission_number <> ''", $session_id ) ) as $r ) {
			$other_years[ $r->adm ] = true;
			// Same name in another year: its admission number, once per learner.
			$other_names[ self::norm( $r->name ) ][ $r->adm ] = $r;
		}
		$default_section = isset( wp_list_pluck( BSA_School::sections(), 'ID', 'ID' )[ (int) $default_section ] ) ? (int) $default_section : 0;
		$session    = $wpdb->get_row( $wpdb->prepare( "SELECT start_date FROM {$p}wlsm_sessions WHERE ID = %d", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$year_start = $session ? $session->start_date : current_time( 'Y-m-d' );
		$seen_adm   = array();
		$seen_name  = array();
		$out        = array();
		foreach ( $data as $line => $row ) {
			$errors   = array();
			$warnings = array();
			$notes    = array();
			$name     = self::tidy_name( $get( $row, 'name' ) );
			$first    = self::tidy_name( $get( $row, 'first_names' ) );
			$surname  = self::tidy_name( $get( $row, 'surname' ) );
			if ( '' === $name ) {
				$name = trim( $first . ' ' . $surname );
			} elseif ( '' !== $surname && false === stripos( $name, $surname ) ) {
				$name = trim( $name . ' ' . $surname );
			}
			$name = mb_substr( $name, 0, 120 );
			if ( '' === $name ) {
				$errors[] = __( 'No name.', 'bsa-school-management' );
			} elseif ( false === strpos( $name, ' ' ) ) {
				$warnings[] = __( 'Only one name: add the surname.', 'bsa-school-management' );
			}
			$section = '' === $get( $row, 'class' ) && '' === $get( $row, 'stream' ) && $default_section ? $default_section : self::parse_class( $get( $row, 'class' ), $get( $row, 'stream' ) );
			if ( is_wp_error( $section ) ) {
				$errors[] = '' === $get( $row, 'class' ) ? __( 'No class.', 'bsa-school-management' ) : $section->get_error_message();
				$section  = 0;
			}
			$gender = self::parse_gender( $get( $row, 'gender' ) );
			if ( '' === $gender ) {
				$warnings[] = '' === $get( $row, 'gender' ) ? __( 'Sex missing.', 'bsa-school-management' ) : sprintf( /* translators: %s: value. */ __( 'Sex "%s" not recognised (use M or F).', 'bsa-school-management' ), $get( $row, 'gender' ) );
			}
			$dob = self::parse_date( $get( $row, 'dob' ) );
			if ( '' !== $get( $row, 'dob' ) && '' === $dob ) {
				/* translators: %s: value. */
				$warnings[] = sprintf( __( 'Date of birth "%s" not understood; left empty (write it as 24/05/2012).', 'bsa-school-management' ), $get( $row, 'dob' ) );
			} elseif ( $dob ) {
				$age = (int) floor( ( strtotime( $year_start ) - strtotime( $dob ) ) / ( 365.25 * DAY_IN_SECONDS ) );
				if ( $age < 10 || $age > 21 ) {
					/* translators: %d: age. */
					$warnings[] = sprintf( __( 'Date of birth gives age %d at the start of the year: check it.', 'bsa-school-management' ), $age );
				}
			} else {
				$warnings[] = __( 'Date of birth missing.', 'bsa-school-management' );
			}
			$adm = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( $get( $row, 'admission_number' ) ) ) );
			if ( '' === $adm && '' !== $name && isset( $other_names[ self::norm( $name ) ] ) ) {
				// Without a number: the same learner in another year (same name, and the
				// same date of birth when both are known) keeps their admission number.
				$match = array_filter(
					$other_names[ self::norm( $name ) ],
					static function ( $r ) use ( $dob ) {
						return '' === $dob || empty( $r->dob ) || $r->dob === $dob;
					}
				);
				if ( 1 === count( $match ) ) {
					$r   = reset( $match );
					$adm = (string) $r->adm;
					/* translators: 1: admission number, 2: year. */
					$notes[] = sprintf( __( 'Admission number %1$s taken from the learner with the same name in %2$s.', 'bsa-school-management' ), $adm, $r->year );
				} elseif ( count( $match ) > 1 ) {
					$warnings[] = __( 'Several learners with this name in other years: add the admission number to link the right one.', 'bsa-school-management' );
				}
			}
			if ( strlen( $adm ) > 30 ) {
				$errors[] = __( 'Admission number longer than 30 characters.', 'bsa-school-management' );
			} elseif ( '' !== $adm ) {
				if ( isset( $seen_adm[ $adm ] ) ) {
					/* translators: %d: line. */
					$errors[] = sprintf( __( 'Same admission number as line %d.', 'bsa-school-management' ), $seen_adm[ $adm ] );
				} elseif ( isset( $existing['adm'][ $adm ] ) ) {
					/* translators: %s: name. */
					$errors[] = sprintf( __( 'Admission number already used this year (%s).', 'bsa-school-management' ), $existing['adm'][ $adm ]->name );
				} elseif ( isset( $other_years[ $adm ] ) && '' !== $get( $row, 'admission_number' ) ) {
					$notes[] = __( 'Linked to the same learner in another school year.', 'bsa-school-management' );
				}
				$seen_adm[ $adm ] = $line;
			} else {
				$notes[] = __( 'A new admission number will be given.', 'bsa-school-management' );
			}
			$key = self::norm( $name ) . '|' . $dob;
			if ( '' !== $name && isset( $seen_name[ $key ] ) ) {
				/* translators: %d: line. */
				$errors[] = sprintf( __( 'Same learner as line %d.', 'bsa-school-management' ), $seen_name[ $key ] );
			} elseif ( '' !== $name && isset( $existing['name'][ self::norm( $name ) ] ) && ! isset( $existing['adm'][ $adm ] ) ) {
				$warnings[] = __( 'A learner with this name is already in this school year: check it is not the same person.', 'bsa-school-management' );
			}
			$seen_name[ $key ] = $line;

			$phones = array();
			foreach ( array( 'phone', 'father_phone', 'mother_phone', 'guardian_phone' ) as $field ) {
				list( $num, $ok ) = self::parse_phone( $get( $row, $field ) );
				$phones[ $field ] = $num;
				if ( ! $ok ) {
					/* translators: 1: field, 2: value. */
					$warnings[] = sprintf( __( '%1$s "%2$s" is not a Zimbabwe mobile number; kept as written.', 'bsa-school-management' ), self::FIELDS[ $field ][0], $get( $row, $field ) );
				}
			}
			if ( '' === $phones['father_phone'] && '' === $phones['mother_phone'] && '' === $phones['guardian_phone'] ) {
				$warnings[] = __( 'No parent or guardian phone number.', 'bsa-school-management' );
			}
			list( $type, $type_ok ) = self::parse_residence( $get( $row, 'residence' ) );
			if ( ! $type_ok ) {
				/* translators: %s: value. */
				$warnings[] = sprintf( __( 'Boarder or day "%s" not understood; left empty.', 'bsa-school-management' ), $get( $row, 'residence' ) );
			}
			$adm_date = self::parse_date( $get( $row, 'admission_date' ) );
			if ( '' !== $get( $row, 'admission_date' ) && '' === $adm_date ) {
				$warnings[] = __( 'Admission date not understood; left empty.', 'bsa-school-management' );
			}
			list( $extra, $extra_errors ) = BSA_Admission_Records::validate_fields(
				array(
					'birth_certificate_no' => $get( $row, 'birth_certificate_no' ),
					'grade7_school'        => $get( $row, 'grade7_school' ),
					'grade7_year'          => $get( $row, 'grade7_year' ),
					'grade7_units'         => $get( $row, 'grade7_units' ),
				)
			);
			foreach ( $extra_errors as $e ) {
				$warnings[] = $e . ' ' . __( '(left empty)', 'bsa-school-management' );
			}
			if ( null !== $extra['grade7_units'] && ( $extra['grade7_units'] < 4 || $extra['grade7_units'] > 36 ) ) {
				$warnings[] = __( 'Grade 7 units are usually between 4 and 36: check them.', 'bsa-school-management' );
			}
			$guardian = self::tidy_name( $get( $row, 'guardian_name' ) );
			$note     = '' !== $guardian || '' !== $phones['guardian_phone'] ? trim( sprintf( /* translators: 1: name, 2: phone. */ __( 'Guardian: %1$s %2$s', 'bsa-school-management' ), $guardian, $phones['guardian_phone'] ) ) : '';
			$record   = array(
				'name'             => $name,
				'gender'           => $gender,
				'dob'              => $dob ? $dob : null,
				'admission_number' => $adm,
				'section_id'       => $section,
				'session_id'       => (int) $session_id,
				'student_type'     => $type,
				'religion'         => substr( sanitize_text_field( $get( $row, 'religion' ) ), 0, 40 ),
				'address'          => sanitize_textarea_field( $get( $row, 'address' ) ),
				'phone'            => $phones['phone'],
				'father_name'      => substr( self::tidy_name( $get( $row, 'father_name' ) ), 0, 60 ),
				'father_phone'     => '' !== $phones['father_phone'] ? $phones['father_phone'] : ( '' === $phones['mother_phone'] ? $phones['guardian_phone'] : '' ),
				'mother_name'      => substr( self::tidy_name( $get( $row, 'mother_name' ) ), 0, 60 ),
				'mother_phone'     => $phones['mother_phone'],
				'admission_date'   => $adm_date ? $adm_date : null,
				'note'             => $note,
			);
			$extra    = array_filter(
				$extra,
				static function ( $v ) {
					return null !== $v && '' !== $v;
				}
			);
			$out[]    = array( 'line' => (int) $line, 'record' => $record, 'extra' => $extra, 'errors' => $errors, 'warnings' => $warnings, 'notes' => $notes );
		}
		return $out;
	}

	/**
	 * Next free enrollment number (six digits, as Weblizar numbers them).
	 *
	 * @return int
	 */
	private static function next_enrollment() {
		global $wpdb;
		return 1 + (int) $wpdb->get_var( "SELECT MAX(CAST(enrollment_number AS UNSIGNED)) FROM {$wpdb->prefix}wlsm_student_records" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Next free admission number of the form BSA###.
	 *
	 * @return int
	 */
	private static function next_admission() {
		global $wpdb;
		return 1 + (int) $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING(admission_number, 4) AS UNSIGNED)) FROM {$wpdb->prefix}wlsm_student_records WHERE admission_number REGEXP '^BSA[0-9]+$'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Saves the rows without errors, as one batch.
	 *
	 * @param array  $checked From check().
	 * @param int    $user_id Acting user.
	 * @param string $source  File name or "pasted table".
	 * @return array{batch:string, imported:int, skipped:int}|WP_Error
	 */
	public static function import( array $checked, $user_id, $source ) {
		global $wpdb;
		if ( ! self::user_can_import( $user_id ) ) {
			return new WP_Error( 'bsa_import', __( 'You cannot import data.', 'bsa-school-management' ) );
		}
		$p    = $wpdb->prefix;
		$ok   = array_filter(
			$checked,
			static function ( $r ) {
				return ! $r['errors'];
			}
		);
		$ids  = array();
		$enr  = self::next_enrollment();
		$adm  = self::next_admission();
		$now  = current_time( 'mysql' );
		// All or nothing. Filterable so a test can run inside its own (rolled-back)
		// transaction: in MySQL a second START TRANSACTION would commit the first.
		$tx = (bool) apply_filters( 'bsa_sm_import_transaction', true );
		if ( $tx ) {
			$wpdb->query( 'START TRANSACTION' );
		}
		foreach ( $ok as $r ) {
			$rec = $r['record'];
			if ( '' === $rec['admission_number'] ) {
				$rec['admission_number'] = sprintf( 'BSA%03d', $adm++ );
			}
			$rec = array_merge(
				$rec,
				array(
					'enrollment_number' => sprintf( '%06d', $enr++ ),
					'roll_number'       => $rec['admission_number'],
					'is_active'         => 1,
					'country'           => 'Zimbabwe',
					'medium'            => '',
					'activities'        => serialize( array() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Weblizar's format.
					'room_id'           => 0,
					'created_at'        => $now,
					'added_by'          => (int) $user_id,
				)
			);
			if ( false === $wpdb->insert( "{$p}wlsm_student_records", $rec ) ) {
				if ( $tx ) {
					$wpdb->query( 'ROLLBACK' );
				}
				/* translators: %d: line. */
				return new WP_Error( 'bsa_import', sprintf( __( 'Line %d could not be saved; nothing was imported.', 'bsa-school-management' ), $r['line'] ) );
			}
			$id    = (int) $wpdb->insert_id;
			$ids[] = $id;
			if ( $r['extra'] ) {
				BSA_Admission_Records::save_record( $id, $r['extra'], $user_id );
			}
		}
		if ( $tx ) {
			$wpdb->query( 'COMMIT' );
		}
		$batch            = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false );
		$batches          = get_option( self::BATCHES, array() );
		$batches          = is_array( $batches ) ? $batches : array();
		$batches[ $batch ] = array( 'type' => 'learners', 'user' => (int) $user_id, 'time' => $now, 'source' => sanitize_text_field( (string) $source ), 'session' => (int) ( $ok ? reset( $ok )['record']['session_id'] : 0 ), 'ids' => $ids, 'undone' => '' );
		$batches          = array_slice( $batches, -30, null, true );
		update_option( self::BATCHES, $batches, false );
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->info( 'Imported {count} learner(s) (batch {batch})', array( 'count' => count( $ids ), 'batch' => $batch ) );
		}
		BSA_School::flush();
		return array( 'batch' => $batch, 'imported' => count( $ids ), 'skipped' => count( $checked ) - count( $ids ) );
	}

	/**
	 * Undoes an import: removes its learners that nothing has been recorded against yet.
	 *
	 * @param string $batch   Batch ID.
	 * @param int    $user_id Acting user.
	 * @return array{removed:int, kept:string[]}|WP_Error
	 */
	public static function undo( $batch, $user_id ) {
		global $wpdb;
		$batches = get_option( self::BATCHES, array() );
		if ( ! self::user_can_import( $user_id ) || ! isset( $batches[ $batch ] ) || $batches[ $batch ]['undone'] ) {
			return new WP_Error( 'bsa_import', __( 'This import cannot be undone.', 'bsa-school-management' ) );
		}
		$p       = $wpdb->prefix;
		$removed = 0;
		$kept    = array();
		foreach ( $batches[ $batch ]['ids'] as $id ) {
			$id   = (int) $id;
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT ID, name, user_id, parent_user_id FROM {$p}wlsm_student_records WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				continue;
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$used = $row->user_id || $row->parent_user_id
				|| $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wlsm_invoices WHERE student_record_id = %d LIMIT 1", $id ) )
				|| $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wlsm_payments WHERE student_record_id = %d LIMIT 1", $id ) )
				|| $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$p}wlsm_attendance WHERE student_record_id = %d LIMIT 1", $id ) )
				|| $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . BSA_Marks::table() . ' WHERE student_record_id = %d LIMIT 1', $id ) );
			// phpcs:enable
			if ( $used ) {
				$kept[] = $row->name;
				continue;
			}
			$wpdb->delete( "{$p}wlsm_students_subjects", array( 'student_id' => $id ) );
			$wpdb->delete( BSA_Admission_Records::table(), array( 'student_record_id' => $id ) );
			$wpdb->delete( "{$p}wlsm_student_records", array( 'ID' => $id ) );
			++$removed;
		}
		$batches[ $batch ]['undone'] = current_time( 'mysql' );
		update_option( self::BATCHES, $batches, false );
		if ( function_exists( 'SimpleLogger' ) ) {
			SimpleLogger()->warning( 'Import {batch} undone: {count} learner(s) removed', array( 'batch' => $batch, 'count' => $removed ) );
		}
		BSA_School::flush();
		return array( 'removed' => $removed, 'kept' => $kept );
	}

	/*
	 * ---------------------------------------------------------------------------------
	 * Screens and handlers
	 * ---------------------------------------------------------------------------------
	 */

	/**
	 * Key of the user's pending import.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function key( $user_id ) {
		return 'bsa_import_' . (int) $user_id;
	}

	/**
	 * Screen URL.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Step 1: file or pasted table read; rows kept for an hour.
	 */
	public static function handle_upload() {
		check_admin_referer( 'bsa_import_upload' );
		$user_id = get_current_user_id();
		if ( ! self::user_can_import( $user_id ) ) {
			wp_die( esc_html__( 'You cannot import data.', 'bsa-school-management' ), 403 );
		}
		$session = absint( $_POST['session'] ?? 0 );
		$fail    = static function ( $message ) {
			wp_safe_redirect( self::url( array( 'error' => rawurlencode( $message ) ) ) );
			exit;
		};
		if ( ! isset( BSA_School::sessions()[ $session ] ) ) {
			$fail( __( 'Choose the school year.', 'bsa-school-management' ) );
		}
		$paste = trim( (string) wp_unslash( $_POST['paste'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as data, cells sanitized per field.
		$file  = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		if ( '' !== $paste ) {
			if ( strlen( $paste ) > self::MAX_BYTES ) {
				$fail( __( 'The pasted table is too large; import it in parts.', 'bsa-school-management' ) );
			}
			$rows   = self::read_text( $paste );
			$source = 'pasted table';
		} elseif ( $file && UPLOAD_ERR_OK === (int) $file['error'] && is_uploaded_file( $file['tmp_name'] ) ) {
			if ( (int) $file['size'] > self::MAX_BYTES ) {
				$fail( __( 'Files must be smaller than 2 MB.', 'bsa-school-management' ) );
			}
			$ext    = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
			$source = sanitize_file_name( (string) $file['name'] );
			if ( 'xlsx' === $ext ) {
				$rows = self::read_xlsx( $file['tmp_name'] );
			} elseif ( 'docx' === $ext ) {
				$rows = self::read_docx( $file['tmp_name'] );
			} elseif ( in_array( $ext, array( 'csv', 'txt' ), true ) ) {
				$rows = self::read_text( (string) file_get_contents( $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			} else {
				$fail( __( 'Use an Excel (.xlsx), Word (.docx) or CSV file. For an old .xls or .doc file, copy the table and paste it below instead.', 'bsa-school-management' ) );
			}
			wp_delete_file( $file['tmp_name'] );
		} else {
			$fail( __( 'Choose a file or paste a table.', 'bsa-school-management' ) );
		}
		if ( is_wp_error( $rows ) ) {
			$fail( $rows->get_error_message() );
		}
		$parts = self::split_headers( $rows );
		if ( ! $parts['data'] ) {
			$fail( __( 'No rows found under a heading row.', 'bsa-school-management' ) );
		}
		if ( count( $parts['data'] ) > self::MAX_ROWS ) {
			/* translators: %d: rows. */
			$fail( sprintf( __( 'At most %d rows at a time; split the list.', 'bsa-school-management' ), self::MAX_ROWS ) );
		}
		set_transient( self::key( $user_id ), array( 'headers' => $parts['headers'], 'data' => $parts['data'], 'session' => $session, 'source' => $source, 'mapping' => self::guess_mapping( $parts['headers'] ) ), HOUR_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'step' => 'map' ) ) );
		exit;
	}

	/**
	 * Step 3: import the good rows.
	 */
	public static function handle_run() {
		check_admin_referer( 'bsa_import_run' );
		$user_id = get_current_user_id();
		if ( ! self::user_can_import( $user_id ) ) {
			wp_die( esc_html__( 'You cannot import data.', 'bsa-school-management' ), 403 );
		}
		$pending = get_transient( self::key( $user_id ) );
		if ( ! is_array( $pending ) || empty( $pending['mapping_confirmed'] ) ) {
			wp_safe_redirect( self::url( array( 'error' => rawurlencode( __( 'The import expired; start again.', 'bsa-school-management' ) ) ) ) );
			exit;
		}
		$result = self::import( self::check( $pending['data'], $pending['mapping'], $pending['session'], (int) ( $pending['default_section'] ?? 0 ) ), $user_id, $pending['source'] );
		delete_transient( self::key( $user_id ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( array( 'error' => rawurlencode( $result->get_error_message() ) ) ) );
			exit;
		}
		wp_safe_redirect( self::url( array( 'done' => $result['batch'], 'n' => $result['imported'], 'skipped' => $result['skipped'] ) ) );
		exit;
	}

	/**
	 * Undo button.
	 */
	public static function handle_undo() {
		$batch = sanitize_text_field( wp_unslash( $_POST['batch'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
		check_admin_referer( 'bsa_import_undo_' . $batch );
		$result = self::undo( $batch, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::url( array( 'error' => rawurlencode( $result->get_error_message() ) ) ) );
			exit;
		}
		wp_safe_redirect( self::url( array( 'undone' => $result['removed'], 'kept' => count( $result['kept'] ) ) ) );
		exit;
	}

	/**
	 * Template download (CSV that opens in Excel).
	 */
	public static function handle_template() {
		check_admin_referer( 'bsa_import_template' );
		if ( ! self::user_can_import( get_current_user_id() ) ) {
			wp_die( esc_html__( 'You cannot import data.', 'bsa-school-management' ), 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="bsa-learners-template.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array( 'Admission number', 'First names', 'Surname', 'Sex', 'Date of birth', 'Class', 'Boarder or day', 'Religion', 'Home address', 'Father name', 'Father phone', 'Mother name', 'Mother phone', 'Guardian name', 'Guardian phone', 'Admission date', 'Grade 7 units', 'Primary school', 'Grade 7 year', 'Birth certificate number' ), ',', '"', '' );
		fputcsv( $out, array( '', 'Example', 'Learner', 'F', '24/05/2012', 'Form 3 Sciences', 'Day', 'Christian', '12 Example Road, Bindura', 'Example Father', '0771234567', 'Example Mother', '0712345678', '', '', '13/01/2025', '8', 'Example Primary School', '2024', '' ), ',', '"', '' );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * The Import data screen: upload, match columns, check, import, past imports.
	 */
	public static function render_page() {
		$user_id = get_current_user_id();
		if ( ! self::user_can_import( $user_id ) ) {
			wp_die( esc_html__( 'You cannot import data.', 'bsa-school-management' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view parameters; the import itself is nonce-checked.
		$step    = sanitize_key( $_GET['step'] ?? '' );
		$error   = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
		$pending = get_transient( self::key( $user_id ) );
		echo '<div class="wrap bsa-dash bsa-import"><h1>' . esc_html__( 'Import data', 'bsa-school-management' ) . '</h1>';
		BSA_Dashboard::styles();
		echo '<style>.bsa-import .bsa-steps{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px;padding:0;list-style:none}.bsa-import .bsa-steps li{padding:6px 12px;border-radius:999px;background:#fff;border:1px solid #dcdcde}.bsa-import .bsa-steps li[aria-current]{background:#1B3674;color:#fff;border-color:#1B3674}.bsa-import textarea{width:100%;min-height:140px;font-family:monospace}.bsa-import .bsa-map td,.bsa-import .bsa-map th{padding:6px}.bsa-row-error{background:#FDECEA}.bsa-row-warn{background:#FFF8E5}.bsa-import ul.bsa-msgs{margin:0;padding:0 0 0 1em;list-style:disc}.bsa-import .bsa-msgs li{margin:0}.bsa-import .bsa-label{font-weight:700}</style>';
		if ( $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		if ( isset( $_GET['done'] ) ) {
			$n       = absint( $_GET['n'] ?? 0 );
			$skipped = absint( $_GET['skipped'] ?? 0 );
			/* translators: %d: learners imported. */
			$msg = sprintf( _n( '%d learner imported.', '%d learners imported.', $n, 'bsa-school-management' ), $n );
			if ( $skipped ) {
				/* translators: %d: rows skipped. */
				$msg .= ' ' . sprintf( _n( '%d row with errors was skipped.', '%d rows with errors were skipped.', $skipped, 'bsa-school-management' ), $skipped );
			}
			echo '<div class="notice notice-success"><p>' . esc_html( $msg . ' ' . __( 'You can undo this import below.', 'bsa-school-management' ) ) . '</p></div>';
		}
		if ( isset( $_GET['undone'] ) ) {
			$removed = absint( $_GET['undone'] );
			$kept    = absint( $_GET['kept'] ?? 0 );
			/* translators: %d: learners removed. */
			$msg = sprintf( _n( 'Import undone: %d learner removed.', 'Import undone: %d learners removed.', $removed, 'bsa-school-management' ), $removed );
			if ( $kept ) {
				/* translators: %d: learners kept. */
				$msg .= ' ' . sprintf( __( 'Kept: %d, because fees, marks or registers have already been recorded for them.', 'bsa-school-management' ), $kept );
			}
			echo '<div class="notice notice-success"><p>' . esc_html( $msg ) . '</p></div>';
		}
		// phpcs:enable
		$steps = array( '' => __( '1. Choose the list', 'bsa-school-management' ), 'map' => __( '2. Match the columns', 'bsa-school-management' ), 'check' => __( '3. Check and import', 'bsa-school-management' ) );
		echo '<ol class="bsa-steps">';
		foreach ( $steps as $k => $label ) {
			echo '<li' . ( $k === $step ? ' aria-current="step"' : '' ) . '>' . esc_html( $label ) . '</li>';
		}
		echo '</ol>';

		if ( in_array( $step, array( 'map', 'check' ), true ) && ! is_array( $pending ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The list you started with has expired (after an hour). Start again.', 'bsa-school-management' ) . '</p></div>';
			$step = '';
		}
		if ( 'map' === $step ) {
			self::render_map( $pending );
		} elseif ( 'check' === $step ) {
			self::render_check( $pending, $user_id );
		} else {
			self::render_start();
		}
		self::render_history();
		echo '</div>';
	}

	/**
	 * Step 1 form.
	 */
	private static function render_start() {
		$current = BSA_Terms::current();
		$session = $current ? $current['session'] : BSA_School::current_session();
		echo '<section class="bsa-card"><h2>' . esc_html__( 'Learners and parents', 'bsa-school-management' ) . '</h2>';
		echo '<p class="bsa-card-sub">' . esc_html__( 'One row per learner. Needed: name and class. Useful: admission number, sex, date of birth, boarder or day, parents\' names and phones, Grade 7 units. Nothing is saved until you have checked the rows. No logins are created and no messages are sent.', 'bsa-school-management' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_import_upload">';
		wp_nonce_field( 'bsa_import_upload' );
		echo '<p><label for="bsa-session"><span class="bsa-label">' . esc_html__( 'School year of this list', 'bsa-school-management' ) . '</span></label><br><select id="bsa-session" name="session">';
		foreach ( BSA_School::sessions() as $id => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $session, $id, false ), esc_html( $label ) );
		}
		echo '</select></p>';
		echo '<p><label for="bsa-file"><span class="bsa-label">' . esc_html__( 'Excel (.xlsx), Word (.docx) or CSV file', 'bsa-school-management' ) . '</span></label><br><input type="file" id="bsa-file" name="file" accept=".xlsx,.docx,.csv,.txt"></p>';
		echo '<p><label for="bsa-paste"><span class="bsa-label">' . esc_html__( 'Or paste a table copied from Word or Excel (headings included)', 'bsa-school-management' ) . '</span></label><br><textarea id="bsa-paste" name="paste" placeholder="' . esc_attr__( 'Name&#9;Class&#9;Sex …', 'bsa-school-management' ) . '"></textarea></p>';
		submit_button( __( 'Read the list', 'bsa-school-management' ), 'primary', 'submit', false );
		echo '</form><p class="bsa-card-sub">' . esc_html__( 'Word class lists are read from their tables. Old .xls or .doc files: open them and save as .xlsx or .docx, or copy the table and paste it.', 'bsa-school-management' ) . ' <a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bsa_import_template' ), 'bsa_import_template' ) ) . '">' . esc_html__( 'Download the learner template', 'bsa-school-management' ) . '</a></p></section>';
	}

	/**
	 * Step 2: column matching with the first rows shown.
	 *
	 * @param array $pending Pending import.
	 */
	private static function render_map( array $pending ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$posted = isset( $_GET['map'] ) && is_array( $_GET['map'] ) ? array_map( 'intval', wp_unslash( $_GET['map'] ) ) : null;
		if ( null !== $posted ) {
			$mapping = array();
			foreach ( $posted as $field => $col ) {
				if ( isset( self::FIELDS[ $field ] ) && $col >= 0 && $col < count( $pending['headers'] ) ) {
					$mapping[ $field ] = $col;
				}
			}
			$pending['mapping']           = $mapping;
			$pending['default_section']   = absint( $_GET['default_class'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked against the sections in check().
			$pending['mapping_confirmed'] = true;
			set_transient( self::key( get_current_user_id() ), $pending, HOUR_IN_SECONDS );
			echo '<script>location.replace(' . wp_json_encode( self::url( array( 'step' => 'check' ) ) ) . ');</script>';
			echo '<p><a href="' . esc_url( self::url( array( 'step' => 'check' ) ) ) . '">' . esc_html__( 'Continue', 'bsa-school-management' ) . '</a></p>';
			return;
		}
		$headers = $pending['headers'];
		/* translators: 1: rows, 2: source. */
		echo '<section class="bsa-card"><h2>' . esc_html( sprintf( __( '%1$d rows read from %2$s', 'bsa-school-management' ), count( $pending['data'] ), $pending['source'] ) ) . '</h2><p class="bsa-card-sub">' . esc_html__( 'Check which column holds each item. Columns found from their headings are already chosen.', 'bsa-school-management' ) . '</p>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="step" value="map"><div class="bsa-scroll"><table class="bsa-table bsa-map"><thead><tr><th scope="col">' . esc_html__( 'Item', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Column in your list', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'First rows', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		$sample = array_slice( $pending['data'], 0, 3 );
		foreach ( self::FIELDS as $field => $f ) {
			$chosen = $pending['mapping'][ $field ] ?? -1;
			echo '<tr><th scope="row"><label for="bsa-map-' . esc_attr( $field ) . '">' . esc_html( $f[0] ) . ( in_array( $field, array( 'name', 'class' ), true ) ? ' *' : '' ) . '</label></th><td><select id="bsa-map-' . esc_attr( $field ) . '" name="map[' . esc_attr( $field ) . ']"><option value="-1">' . esc_html__( '— not in this list —', 'bsa-school-management' ) . '</option>';
			foreach ( $headers as $i => $h ) {
				printf( '<option value="%d"%s>%s</option>', (int) $i, selected( $chosen, $i, false ), esc_html( '' !== $h ? $h : sprintf( /* translators: %d: column number. */ __( 'Column %d', 'bsa-school-management' ), $i + 1 ) ) );
			}
			$examples = array();
			if ( $chosen >= 0 ) {
				foreach ( $sample as $row ) {
					$examples[] = $row[ $chosen ] ?? '';
				}
			}
			echo '</select></td><td>' . esc_html( implode( ' · ', array_filter( $examples, 'strlen' ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></div><p class="bsa-card-sub">' . esc_html__( '* Name: either one full-name column, or first names and surname. Class: e.g. "Form 3 Sciences", "F3 Sci", "Form One".', 'bsa-school-management' ) . '</p>';
		$default = (int) ( $pending['default_section'] ?? 0 );
		echo '<p><label for="bsa-default-class"><span class="bsa-label">' . esc_html__( 'Class for every row without one', 'bsa-school-management' ) . '</span></label><br><select id="bsa-default-class" name="default_class"><option value="0">' . esc_html__( '— none: the list has a class column —', 'bsa-school-management' ) . '</option>';
		foreach ( BSA_School::sections() as $sec ) {
			printf( '<option value="%d"%s>%s</option>', (int) $sec->ID, selected( $default, (int) $sec->ID, false ), esc_html( $sec->title ) );
		}
		echo '</select><br><span class="bsa-card-sub">' . esc_html__( 'For a class list of one class, such as a Word list titled "Form 2 A".', 'bsa-school-management' ) . '</span></p>';
		submit_button( __( 'Check the rows', 'bsa-school-management' ), 'primary', '', false );
		echo ' <a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Start again', 'bsa-school-management' ) . '</a></form></section>';
	}

	/**
	 * Step 3: every row with its problems, and the import button.
	 *
	 * @param array $pending Pending import.
	 * @param int   $user_id Viewer.
	 */
	private static function render_check( array $pending, $user_id ) {
		$checked = self::check( $pending['data'], $pending['mapping'], $pending['session'], (int) ( $pending['default_section'] ?? 0 ) );
		$errors  = count(
			array_filter(
				$checked,
				static function ( $r ) {
					return $r['errors'];
				}
			)
		);
		$warns   = count(
			array_filter(
				$checked,
				static function ( $r ) {
					return ! $r['errors'] && $r['warnings'];
				}
			)
		);
		$good    = count( $checked ) - $errors;
		$titles  = wp_list_pluck( BSA_School::sections(), 'title', 'ID' );
		echo '<div class="bsa-tiles">';
		/* translators: %s: year. */
		echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'Ready to import', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . esc_html( (string) $good ) . '</div><div class="bsa-tile-note">' . esc_html( sprintf( __( 'into %s', 'bsa-school-management' ), BSA_School::sessions()[ $pending['session'] ] ?? '' ) ) . '</div></div>';
		echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'With something to check', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . esc_html( (string) $warns ) . '</div><div class="bsa-tile-note">' . esc_html__( 'imported; worth a look', 'bsa-school-management' ) . '</div></div>';
		echo '<div class="bsa-tile"><div class="bsa-tile-label">' . esc_html__( 'With errors', 'bsa-school-management' ) . '</div><div class="bsa-tile-value">' . esc_html( (string) $errors ) . '</div><div class="bsa-tile-note">' . esc_html__( 'skipped: fix in your list and import them again', 'bsa-school-management' ) . '</div></div>';
		echo '</div><section class="bsa-card"><h2>' . esc_html__( 'Every row', 'bsa-school-management' ) . '</h2><div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'Line', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Learner', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Class', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Sex · born', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Parents', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Result', 'bsa-school-management' ) . '</th></tr></thead><tbody>';
		foreach ( $checked as $r ) {
			$rec   = $r['record'];
			$class = $r['errors'] ? 'bsa-row-error' : ( $r['warnings'] ? 'bsa-row-warn' : '' );
			$msgs  = '';
			foreach ( array( 'errors' => __( 'Error', 'bsa-school-management' ), 'warnings' => __( 'Check', 'bsa-school-management' ), 'notes' => __( 'Note', 'bsa-school-management' ) ) as $k => $label ) {
				foreach ( $r[ $k ] as $m ) {
					$msgs .= '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $m ) . '</li>';
				}
			}
			// The guardian's phone stands in as the contact phone: shown once, with the guardian.
			$father  = '' === $rec['father_name'] && '' !== $rec['father_phone'] && false !== strpos( $rec['note'], $rec['father_phone'] ) ? '' : trim( $rec['father_name'] . ' ' . $rec['father_phone'] );
			$parents = implode( '; ', array_filter( array( $father, trim( $rec['mother_name'] . ' ' . $rec['mother_phone'] ), $rec['note'] ) ) );
			echo '<tr class="' . esc_attr( $class ) . '"><td>' . esc_html( (string) $r['line'] ) . '</td><th scope="row">' . esc_html( $rec['name'] ) . ( $rec['admission_number'] ? '<br><span class="bsa-card-sub">' . esc_html( $rec['admission_number'] ) . '</span>' : '' ) . '</th><td>' . esc_html( $titles[ $rec['section_id'] ] ?? '–' ) . '</td><td>' . esc_html( trim( ucfirst( (string) $rec['gender'] ) . ' · ' . ( $rec['dob'] ? mysql2date( 'j M Y', $rec['dob'] ) : '–' ), ' ·' ) ) . '</td><td>' . esc_html( $parents ) . '</td><td>' . ( $msgs ? '<ul class="bsa-msgs">' . $msgs . '</ul>' : '<span class="dashicons dashicons-yes" aria-hidden="true"></span> ' . esc_html__( 'Ready', 'bsa-school-management' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped parts.
		}
		echo '</tbody></table></div>';
		if ( $good ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsa_import_run">';
			wp_nonce_field( 'bsa_import_run' );
			/* translators: %d: rows. */
			submit_button( sprintf( _n( 'Import %d learner', 'Import %d learners', $good, 'bsa-school-management' ), $good ), 'primary', 'submit', false );
			echo ' <a class="button" href="' . esc_url( self::url( array( 'step' => 'map' ) ) ) . '">' . esc_html__( 'Change the columns', 'bsa-school-management' ) . '</a> <a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Start again', 'bsa-school-management' ) . '</a></form>';
		} else {
			echo '<p><a class="button" href="' . esc_url( self::url( array( 'step' => 'map' ) ) ) . '">' . esc_html__( 'Change the columns', 'bsa-school-management' ) . '</a> <a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Start again', 'bsa-school-management' ) . '</a></p>';
		}
		echo '</section>';
	}

	/**
	 * Past imports with an undo button.
	 */
	private static function render_history() {
		$batches = array_reverse( (array) get_option( self::BATCHES, array() ), true );
		if ( ! $batches ) {
			return;
		}
		echo '<section class="bsa-card"><h2>' . esc_html__( 'Past imports', 'bsa-school-management' ) . '</h2><div class="bsa-scroll"><table class="bsa-table"><thead><tr><th scope="col">' . esc_html__( 'When', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'From', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'Year', 'bsa-school-management' ) . '</th><th scope="col" class="num">' . esc_html__( 'Learners', 'bsa-school-management' ) . '</th><th scope="col">' . esc_html__( 'By', 'bsa-school-management' ) . '</th><th scope="col"></th></tr></thead><tbody>';
		foreach ( $batches as $id => $b ) {
			$by   = get_userdata( (int) $b['user'] );
			$cell = $b['undone'] ? esc_html( sprintf( /* translators: %s: date. */ __( 'Undone %s', 'bsa-school-management' ), mysql2date( 'j M Y H:i', $b['undone'] ) ) ) : '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Remove the learners of this import? Learners with fees, marks or registers already recorded are kept.', 'bsa-school-management' ) ) ) . ');"><input type="hidden" name="action" value="bsa_import_undo"><input type="hidden" name="batch" value="' . esc_attr( $id ) . '">' . wp_nonce_field( 'bsa_import_undo_' . $id, '_wpnonce', true, false ) . '<button type="submit" class="button">' . esc_html__( 'Undo', 'bsa-school-management' ) . '</button></form>';
			echo '<tr><td>' . esc_html( mysql2date( 'j M Y H:i', $b['time'] ) ) . '</td><td>' . esc_html( $b['source'] ) . '</td><td>' . esc_html( BSA_School::sessions()[ $b['session'] ] ?? '' ) . '</td><td class="num">' . esc_html( (string) count( $b['ids'] ) ) . '</td><td>' . esc_html( $by ? $by->display_name : '' ) . '</td><td>' . $cell . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped parts.
		}
		echo '</tbody></table></div></section>';
	}
}
