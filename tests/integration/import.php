<?php
/**
 * Integration test for BSA_Import (learner and parent import), run on the live site
 * through Novamira `execute-php` (send everything after the opening tag).
 *
 * Reading (.xlsx and .docx built in a temporary folder, CSV, pasted tables), column
 * matching and value parsing need no database. The all-or-nothing check runs the
 * import's own transaction with a forced failure (nothing is kept). Everything else is
 * written inside a transaction and rolled back. Before activation, set $bsa_stage to the
 * staged plugin folder to test that copy. Results are also kept for an hour in the
 * `bsa_test_import` transient. Set $bsa_capture = true to keep the rendered screens
 * (synthetic data) in the `bsa_capture_import` transient for a visual check.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
if ( ! class_exists( 'BSA_Import' ) && $bsa_stage ) {
	require_once $bsa_stage . '/includes/class-bsa-import.php';
}
require_once ABSPATH . 'wp-admin/includes/template.php';

$p           = $wpdb->prefix;
$bsa_capture = ! empty( $bsa_capture );
$captured    = array();
$results     = array();
$check       = function ( $name, $ok, $detail = '' ) use ( &$results ) {
	$results[] = ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== (string) $detail ? ' - ' . $detail : '' );
};
$original = get_current_user_id();
$notices  = array();
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) use ( &$notices ) {
		$notices[] = basename( $errfile ) . ':' . $errline . ' ' . $errstr;
		return true;
	}
);
$die = function () {
	return function ( $m ) {
		throw new RuntimeException( 'wp_die: ' . ( is_string( $m ) ? wp_strip_all_tags( $m ) : '' ) );
	};
};
$die_hooks = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler' );
foreach ( $die_hooks as $hook ) {
	add_filter( $hook, $die, 999 );
}
$render = function ( $get ) {
	$_GET = $get;
	ob_start();
	try {
		BSA_Import::render_page();
	} finally {
		$html = ob_get_clean();
		$_GET = array();
	}
	return $html;
};
$user = function ( $login ) {
	$u = get_user_by( 'login', $login );
	return $u ? (int) $u->ID : 0;
};
$owner   = $user( 'bsa' );
$head    = $user( 'Masango' );
$teacher = $user( 'hlabati.w' );
$bursar  = $user( 'bursar' );
$parent  = $user( 'bsa001.parent' );
$sessions = array_keys( BSA_School::sessions() );
$s1       = $sessions[0];
$s2       = $sessions[1];

// ---------------------------------------------------------------------------------
// Reading (no database).
// ---------------------------------------------------------------------------------
$check( 'columns: A = 0, Z = 25, AA = 26, AB = 27', array( 0, 25, 26, 27 ) === array_map( array( 'BSA_Import', 'column_index' ), array( 'A', 'Z', 'AA', 'AB' ) ) );

$rows = BSA_Import::read_text( "\xEF\xBB\xBFName,Class,Address\r\nTendai Moyo,Form 1,\"12 Main Rd, Bindura\"\r\n" );
$check( 'CSV: byte-order mark removed, quoted comma kept', array( array( 'Name', 'Class', 'Address' ), array( 'Tendai Moyo', 'Form 1', '12 Main Rd, Bindura' ) ) === $rows, wp_json_encode( $rows ) );
$rows = BSA_Import::read_text( "Name;Class\nRudo;Form 2\n" );
$check( 'CSV with semicolons (Excel in some languages)', array( array( 'Name', 'Class' ), array( 'Rudo', 'Form 2' ) ) === $rows, wp_json_encode( $rows ) );
$rows = BSA_Import::read_text( "Name\tClass\tSex\n Chipo \tForm 3 Sci\tF\n" );
$check( 'pasted from Word or Excel: tabs, cells trimmed', array( array( 'Name', 'Class', 'Sex' ), array( 'Chipo', 'Form 3 Sci', 'F' ) ) === $rows, wp_json_encode( $rows ) );
$rows = BSA_Import::read_text( "Name,Class\nRen\xE9,Form 1\n" );
$check( 'old Windows text (not UTF-8) converted', 'René' === ( $rows[1][0] ?? '' ), wp_json_encode( $rows ) );

$parts = BSA_Import::split_headers( array( array( 'FORM 2 A CLASS LIST 2025' ), array(), array( 'No.', 'Name', 'Sex' ), array( '1', 'A B', 'M' ), array( '', '', '' ), array( '2', 'C D', 'F' ) ) );
$check( 'headings: a title above the table is skipped; blank rows dropped; line numbers as in the file', array( 'No.', 'Name', 'Sex' ) === $parts['headers'] && array( 4, 6 ) === array_keys( $parts['data'] ) && 'C D' === $parts['data'][6][1], wp_json_encode( $parts ) );

$map = BSA_Import::guess_mapping( array( 'No.', 'NAME', 'SURNAME', 'SEX', 'D.O.B', 'CLASS', "Father's Name", 'Contact Number', 'Grade 7 Units', 'Date of admission (dd/mm/yyyy)' ) );
$check( 'column matching from typical school headings', array( 'name' => 1, 'surname' => 2, 'class' => 5, 'gender' => 3, 'dob' => 4, 'father_name' => 6, 'guardian_phone' => 7, 'admission_date' => 9, 'grade7_units' => 8 ) == $map, wp_json_encode( $map ) ); // phpcs:ignore Universal.Operators.StrictComparisons -- order does not matter.

// Values.
$classes = array(
	'Form 3 Sciences'    => 6,
	'F3 Sci'             => 6,
	'form 4 commercials' => 9,
	'Form 1'             => 4,
	'Form One'           => 4,
	'FORM II A'          => 5,
	'2A'                 => 5,
	'F4 Science'         => 7,
);
$bad = array();
foreach ( $classes as $text => $want ) {
	$got = BSA_Import::parse_class( $text );
	if ( $want !== $got ) {
		$bad[] = $text . ' → ' . ( is_wp_error( $got ) ? $got->get_error_message() : $got );
	}
}
$check( 'classes: "Form 3 Sciences", "F3 Sci", "Form One", "FORM II A", "2A" …', ! $bad, implode( '; ', $bad ) );
$check( 'classes: form and stream in two columns', 7 === BSA_Import::parse_class( 'Form 4', 'Sciences' ) && 8 === BSA_Import::parse_class( '3', 'Commercials' ) );
$e1 = BSA_Import::parse_class( 'Form 3' );
$e2 = BSA_Import::parse_class( 'Form 5' );
$e3 = BSA_Import::parse_class( 'Form 1 Sciences' );
$check( 'classes: stream needed, unknown form, missing stream are errors', is_wp_error( $e1 ) && false !== strpos( $e1->get_error_message(), 'say which stream' ) && is_wp_error( $e2 ) && is_wp_error( $e3 ) && false !== strpos( $e3->get_error_message(), 'no Sciences stream' ), ( is_wp_error( $e1 ) ? $e1->get_error_message() : '' ) );

$dates = array(
	'24/05/2012'   => '2012-05-24',
	'2012-05-24'   => '2012-05-24',
	'24.5.12'      => '2012-05-24',
	'24-05-2012'   => '2012-05-24',
	'24 May 2012'  => '2012-05-24',
	'24th May, 2012' => '2012-05-24',
	'May 24, 2012' => '2012-05-24',
	'41053'        => '2012-05-24',
	'31/02/2012'   => '',
	'5/13/2012'    => '',
	'soon'         => '',
	''             => '',
);
$bad = array();
foreach ( $dates as $text => $want ) {
	if ( BSA_Import::parse_date( $text ) !== $want ) {
		$bad[] = $text . ' → ' . BSA_Import::parse_date( $text );
	}
}
$check( 'dates: day before month, words, Excel date numbers; impossible dates refused', ! $bad, implode( '; ', $bad ) );
$check( 'sex: M, F, Boy, Girl, Female; others empty', array( 'male', 'female', 'male', 'female', 'female', '' ) === array_map( array( 'BSA_Import', 'parse_gender' ), array( 'M', 'f', 'Boy', 'GIRL', 'Female', 'X' ) ) );
$phones = array(
	'+263 77 123 4567' => array( '0771234567', true ),
	'771234567'        => array( '0771234567', true ),
	'0712-345-678'     => array( '0712345678', true ),
	'263712345678'     => array( '0712345678', true ),
	'12345'            => array( '12345', false ),
	''                 => array( '', true ),
);
$bad = array();
foreach ( $phones as $text => $want ) {
	if ( BSA_Import::parse_phone( $text ) !== $want ) {
		$bad[] = $text . ' → ' . wp_json_encode( BSA_Import::parse_phone( $text ) );
	}
}
$check( 'phones: written as 07XXXXXXXX; others kept and flagged', ! $bad, implode( '; ', $bad ) );
$types = $wpdb->get_results( "SELECT ID, label FROM {$p}wlsm_student_type" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$board = '';
$day   = '';
foreach ( $types as $t ) {
	if ( false !== stripos( $t->label, 'board' ) ) {
		$board = (string) $t->ID;
	} elseif ( false !== stripos( $t->label, 'day' ) ) {
		$day = (string) $t->ID;
	}
}
$res = array_map(
	function ( $v ) {
		return BSA_Import::parse_residence( $v );
	},
	array( 'Boarder', 'B', 'boarding', 'Day', 'Day Scholar', 'D', '', 'weekly' )
);
$check( 'boarder or day: words and letters; unknown flagged', array( array( $board, true ), array( $board, true ), array( $board, true ), array( $day, true ), array( $day, true ), array( $day, true ), array( '', true ), array( '', false ) ) === $res && '' !== $board && '' !== $day, wp_json_encode( $res ) );
$check( 'names: CAPITALS turned into Title Case, spaces tidied, mixed case kept', 'Tapiwa Moyo' === BSA_Import::tidy_name( 'TAPIWA   MOYO' ) && "O'Brien-Smith" === BSA_Import::tidy_name( "O'BRIEN-SMITH" ) && 'Chipo mukanya' === BSA_Import::tidy_name( ' Chipo mukanya ' ) );

// .xlsx and .docx built here.
$dir = trailingslashit( get_temp_dir() ) . 'bsa-import-test-' . wp_generate_password( 8, false );
wp_mkdir_p( $dir );
try {
	$xlsx = $dir . '/list.xlsx';
	$zip  = new ZipArchive();
	$zip->open( $xlsx, ZipArchive::CREATE );
	$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Form 2" sheetId="1" r:id="rId7"/></sheets></workbook>' );
	$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/classlist.xml"/></Relationships>' );
	$zip->addFromString( 'xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Name</t></si><si><t>Class</t></si><si><t>DOB</t></si><si><r><t>Tendai </t></r><r><rPr><b/></rPr><t>Moyo</t></r></si><si><t>Form 2 A</t></si></sst>' );
	$zip->addFromString( 'xl/worksheets/classlist.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="D1" t="s"><v>2</v></c></row><row r="2"><c r="A2" t="s"><v>3</v></c><c r="B2" t="s"><v>4</v></c><c r="D2"><v>41053</v></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>Rudo Banda</t></is></c><c r="B3" t="s"><v>4</v></c></row></sheetData></worksheet>' );
	$zip->close();
	$rows = BSA_Import::read_xlsx( $xlsx );
	$check( 'Excel: sheet found through the workbook, shared, rich and inline text, empty column C, date number', array( array( 'Name', 'Class', '', 'DOB' ), array( 'Tendai Moyo', 'Form 2 A', '', '41053' ), array( 'Rudo Banda', 'Form 2 A' ) ) === $rows, wp_json_encode( $rows ) );
	file_put_contents( $dir . '/fake.xlsx', 'not a zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$check( 'Excel: a file that is not .xlsx is refused', is_wp_error( BSA_Import::read_xlsx( $dir . '/fake.xlsx' ) ) && is_wp_error( BSA_Import::read_docx( $dir . '/fake.xlsx' ) ) );

	$w    = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
	$cell = function ( $text, $span = 1 ) {
		$pr    = $span > 1 ? '<w:tcPr><w:gridSpan w:val="' . $span . '"/></w:tcPr>' : '';
		$paras = '';
		foreach ( (array) $text as $t ) {
			$paras .= '<w:p><w:r><w:t xml:space="preserve">' . esc_html( $t ) . '</w:t></w:r></w:p>';
		}
		return '<w:tc>' . $pr . $paras . '</w:tc>';
	};
	$row  = function ( array $cells ) {
		return '<w:tr>' . implode( '', $cells ) . '</w:tr>';
	};
	$head = $row( array( $cell( 'No.' ), $cell( 'Name' ), $cell( 'Sex' ), $cell( 'Parent phone' ) ) );
	$body = '<w:p><w:r><w:t>FORM 2 A CLASS LIST</w:t></w:r></w:p>'
		. '<w:tbl>' . $head . $row( array( $cell( '1' ), $cell( array( 'CHIPO', 'MUSIYIWA' ) ), $cell( 'F' ), $cell( '0771 234 567' ) ) ) . $row( array( $cell( '2' ), $cell( 'Absent all term', 3 ) ) ) . '</w:tbl>'
		. '<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
		. '<w:tbl>' . $head . $row( array( $cell( '3' ), $cell( 'Kuda Ncube' ), $cell( 'M' ), $cell( '' ) ) ) . '</w:tbl>'
		. '<w:tbl>' . $row( array( $cell( 'Signed' ), $cell( 'Class teacher' ) ) ) . '</w:tbl>';
	$docx = $dir . '/list.docx';
	$zip  = new ZipArchive();
	$zip->open( $docx, ZipArchive::CREATE );
	$zip->addFromString( 'word/document.xml', '<?xml version="1.0"?><w:document ' . $w . '><w:body>' . $body . '</w:body></w:document>' );
	$zip->close();
	$rows = BSA_Import::read_docx( $docx );
	$check( 'Word: tables joined over pages, repeated heading dropped, merged cells padded, other tables ignored', array( array( 'No.', 'Name', 'Sex', 'Parent phone' ), array( '1', 'CHIPO MUSIYIWA', 'F', '0771 234 567' ), array( '2', 'Absent all term', '', '' ), array( '3', 'Kuda Ncube', 'M', '' ) ) === $rows, wp_json_encode( $rows ) );
	$zip = new ZipArchive();
	$zip->open( $dir . '/empty.docx', ZipArchive::CREATE );
	$zip->addFromString( 'word/document.xml', '<?xml version="1.0"?><w:document ' . $w . '><w:body><w:p><w:r><w:t>No table</w:t></w:r></w:p></w:body></w:document>' );
	$zip->close();
	$e = BSA_Import::read_docx( $dir . '/empty.docx' );
	$check( 'Word: a document without a table is refused', is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'No table' ) );
} finally {
	foreach ( (array) glob( $dir . '/*' ) as $f ) {
		wp_delete_file( $f );
	}
	rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

// Rights.
BSA_School::flush();
$check( 'rights: owner and school administrator may import; teacher, bursar and parent may not', BSA_Import::user_can_import( $owner ) && BSA_Import::user_can_import( $head ) && ! BSA_Import::user_can_import( $teacher ) && ! BSA_Import::user_can_import( $bursar ) && ! BSA_Import::user_can_import( $parent ) && ! BSA_Import::user_can_import( 0 ) );

// ---------------------------------------------------------------------------------
// All or nothing: the import's own transaction, with the second learner failing.
// ---------------------------------------------------------------------------------
$batches_before = get_option( BSA_Import::BATCHES, false );
$adm_rows       = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_Admission_Records::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$fixture_check  = BSA_Import::check(
	array(
		2 => array( 'TEST-IMP-RB1', 'Rollback Testimport', 'Form 1', 'F', '8' ),
		3 => array( 'TEST-IMP-RB2', 'Rollback Testimporttwo', 'Form 1', 'M', '9' ),
	),
	array( 'admission_number' => 0, 'name' => 1, 'class' => 2, 'gender' => 3, 'grade7_units' => 4 ),
	$s2
);
$inserts = 0;
$break   = function ( $q ) use ( &$inserts, $p ) {
	if ( 0 === strpos( $q, "INSERT INTO `{$p}wlsm_student_records`" ) && 2 === ++$inserts ) {
		return 'INSERT INTO `' . $p . 'wlsm_student_records_missing` (x) VALUES (1)';
	}
	return $q;
};
add_filter( 'query', $break );
$suppress = $wpdb->suppress_errors( true );
$r        = BSA_Import::import( $fixture_check, $owner, 'rollback.csv' );
$wpdb->suppress_errors( $suppress );
remove_filter( 'query', $break );
$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number LIKE 'TEST-IMP-RB%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$check( 'all or nothing: when line 3 fails, line 2 and its admission record are not kept and no import is listed', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Line 3' ) && 0 === $left && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BSA_Admission_Records::table() ) === $adm_rows && get_option( BSA_Import::BATCHES, false ) === $batches_before, ( is_wp_error( $r ) ? $r->get_error_message() : 'no error' ) . " left $left" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// ---------------------------------------------------------------------------------
// Checking, importing, screens and undo, inside a rolled-back transaction.
// ---------------------------------------------------------------------------------
add_filter( 'bsa_sm_import_transaction', '__return_false' );
$wpdb->query( 'START TRANSACTION' );
try {
	BSA_School::flush();
	foreach ( array( 4, 5, 6, 9 ) as $sec ) {
		$check( "fixture: no real learners in section $sec of the second year", ! BSA_School::learners( $sec, $s2 ) );
	}
	// Two learners of the first year, for linking.
	foreach ( array( array( 'TEST-IMP-OLD1', 'Farai Testimport', '2010-02-01' ), array( 'TEST-IMP-OLD2', 'Nyasha Testimport', '2010-03-01' ) ) as $old ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'admission_number' => $old[0], 'roll_number' => $old[0], 'enrollment_number' => 'T' . $old[0], 'name' => $old[1], 'dob' => $old[2], 'section_id' => 6, 'session_id' => $s1, 'is_active' => 1 ) );
	}
	$csv  = "Admission No,Name,Surname,Sex,DOB,Class,Boarder/Day,Father's name,Father's phone,Mother's name,Mother phone,Guardian,Guardian phone,Grade 7 units,Primary school,Birth certificate no\n";
	$csv .= "TEST-IMP-001,TENDAI,TESTIMPORT,M,14/03/2011,Form 3 Sciences,Boarder,Tafadzwa Testimport,+263 77 123 4567,Rumbi Testimport,0712 345 678,,,8,Chipadze Primary,12-345678A12\n";
	$csv .= ",Rudo,Testimport,F,24/05/2013,Form One,Day,,,,,,,,,\n";
	$csv .= "TEST-IMP-002,Noclass Testimport,,F,01/01/2012,,Day,,,,,Aunt Testimport,0773 000 111,,,\n";
	$csv .= "TEST-IMP-001,Duplicate Testimport,,M,02/02/2011,Form 3 Sciences,Day,,0771000222,,,,,,,\n";
	$csv .= "TEST-IMP-OLD1,Farai,Testimport,M,01/02/2010,Form 4 Commercials,Day,,0771000333,,,,,,,\n";
	$csv .= ",Nyasha,Testimport,F,01/03/2010,Form 4 Commercials,Boarder,,,,,Gogo Testimport,0784 555 666,,,\n";
	$csv .= "TEST-IMP-003,KUDA,,X,31/02/2012,F2,weekly,,12345,,,,,40,,\n";
	$csv .= "TEST-IMP-004,Tino Testimport,,M,01/01/2011,Form 3,Day,,0771000444,,,,,,,\n";
	$csv .= ",Rudo,Testimport,F,24/05/2013,Form 1,Day,,0771000555,,,,,,,\n";
	$parts   = BSA_Import::split_headers( BSA_Import::read_text( $csv ) );
	$mapping = BSA_Import::guess_mapping( $parts['headers'] );
	$check( 'test list: all 16 columns matched', 16 === count( $mapping ), wp_json_encode( $mapping ) );
	$rows = BSA_Import::check( $parts['data'], $mapping, $s2 );
	$by   = array();
	foreach ( $rows as $r ) {
		$by[ $r['line'] ] = $r;
	}
	$has = function ( $line, $kind, $text ) use ( &$by ) {
		foreach ( $by[ $line ][ $kind ] ?? array() as $m ) {
			if ( false !== strpos( $m, $text ) ) {
				return true;
			}
		}
		return false;
	};
	$r2 = $by[2]['record'];
	$check( 'line 2: names joined and tidied, class, boarder, phones written as 07…, ready', 'Tendai Testimport' === $r2['name'] && 6 === $r2['section_id'] && $board === $r2['student_type'] && 'male' === $r2['gender'] && '2011-03-14' === $r2['dob'] && '0771234567' === $r2['father_phone'] && '0712345678' === $r2['mother_phone'] && 'TEST-IMP-001' === $r2['admission_number'] && ! $by[2]['errors'] && ! $by[2]['warnings'], wp_json_encode( $by[2] ) );
	$check( 'line 2: Grade 7 units, primary school and birth certificate kept for the admission record', array( 'birth_certificate_no' => '12-345678A12', 'grade7_school' => 'Chipadze Primary', 'grade7_units' => 8 ) === $by[2]['extra'], wp_json_encode( $by[2]['extra'] ) );
	$check( 'line 3: "Form One"; a new admission number will be given; no parent phone flagged', 4 === $by[3]['record']['section_id'] && $has( 3, 'notes', 'new admission number' ) && $has( 3, 'warnings', 'No parent or guardian phone' ) && ! $by[3]['errors'] );
	$check( 'line 4: no class is an error; guardian kept in the note and as the contact phone', $has( 4, 'errors', 'No class' ) && 'Guardian: Aunt Testimport 0773000111' === $by[4]['record']['note'] && '0773000111' === $by[4]['record']['father_phone'] );
	$check( 'line 5: an admission number used twice in the list is an error', $has( 5, 'errors', 'Same admission number as line 2' ) );
	$check( 'line 6: linked to the same learner in the first year', $has( 6, 'notes', 'Linked to the same learner' ) && ! $by[6]['errors'] && 9 === $by[6]['record']['section_id'] );
	$check( 'line 7: no number, same name and birth date as in the first year → keeps that admission number', 'TEST-IMP-OLD2' === $by[7]['record']['admission_number'] && $has( 7, 'notes', 'TEST-IMP-OLD2 taken from' ) && ! $by[7]['errors'], wp_json_encode( $by[7]['notes'] ) );
	$check(
		'line 8: one name, sex, impossible date, phone, boarder or day and Grade 7 units are flagged, not refused',
		! $by[8]['errors'] && $has( 8, 'warnings', 'Only one name' ) && $has( 8, 'warnings', 'Sex "X"' ) && $has( 8, 'warnings', 'Date of birth "31/02/2012"' ) && $has( 8, 'warnings', 'not a Zimbabwe mobile' ) && $has( 8, 'warnings', 'Boarder or day "weekly"' ) && $has( 8, 'warnings', 'between 4 and 36' ) && 'Kuda' === $by[8]['record']['name'] && 5 === $by[8]['record']['section_id'] && null === $by[8]['record']['dob'],
		wp_json_encode( $by[8]['warnings'] )
	);
	$check( 'line 9: "Form 3" without a stream is an error', $has( 9, 'errors', 'say which stream' ) );
	$check( 'line 10: the same learner twice in the list is an error', $has( 10, 'errors', 'Same learner as line 3' ) );

	// One-class list without a class column.
	$one = BSA_Import::check( array( 2 => array( 'Solo Testimport' ) ), array( 'name' => 0 ), $s2, 5 );
	$bad = BSA_Import::check( array( 2 => array( 'Solo Testimport' ) ), array( 'name' => 0 ), $s2, 999999 );
	$check( 'class for every row: used when the list has no class; an unknown class is ignored', 5 === $one[0]['record']['section_id'] && ! $one[0]['errors'] && 0 === $bad[0]['record']['section_id'] && in_array( 'No class.', $bad[0]['errors'], true ) );

	// Screens.
	wp_set_current_user( $head );
	set_transient( 'bsa_import_' . $head, array( 'headers' => $parts['headers'], 'data' => $parts['data'], 'session' => $s2, 'source' => 'test-list.csv', 'mapping' => $mapping ), HOUR_IN_SECONDS );
	$html = $render( array( 'page' => 'bsa-import' ) );
	$check( 'screen: start with year, file (.xlsx, .docx, .csv), paste box and template link', false !== strpos( $html, 'name="session"' ) && false !== strpos( $html, 'accept=".xlsx,.docx,.csv,.txt"' ) && false !== strpos( $html, 'name="paste"' ) && false !== strpos( $html, 'action=bsa_import_template' ) && false !== strpos( $html, 'aria-current="step"' ) );
	if ( $bsa_capture ) {
		$captured['start'] = $html;
	}
	$html = $render( array( 'page' => 'bsa-import', 'step' => 'map' ) );
	$check( 'screen: column matching shows "9 rows read", choices made from the headings and examples', false !== strpos( $html, '9 rows read from test-list.csv' ) && false !== strpos( $html, 'name="map[dob]"' ) && false !== strpos( $html, '<option value="4" selected=\'selected\'>DOB</option>' ) && false !== strpos( $html, 'TENDAI · Rudo · Noclass Testimport' ) && false !== strpos( $html, 'name="default_class"' ), '' );
	if ( $bsa_capture ) {
		$captured['map'] = $html;
	}
	$html    = $render( array( 'page' => 'bsa-import', 'step' => 'map', 'map' => array_map( 'strval', $mapping ) + array( 'religion' => '-1', 'bogus' => '3' ), 'default_class' => '0' ) );
	$pending = get_transient( 'bsa_import_' . $head );
	$check( 'screen: confirming the columns keeps them and moves on to checking', false !== strpos( $html, 'location.replace' ) && ! empty( $pending['mapping_confirmed'] ) && $mapping == $pending['mapping'] && 0 === $pending['default_section'] ); // phpcs:ignore Universal.Operators.StrictComparisons
	$html = $render( array( 'page' => 'bsa-import', 'step' => 'check' ) );
	$check( 'screen: check shows 5 ready, 2 to look at, 4 errors, every row and "Import 5 learners"', 1 === preg_match( '/Ready to import<\/div><div class="bsa-tile-value">5</', $html ) && 1 === preg_match( '/With something to check<\/div><div class="bsa-tile-value">2</', $html ) && 1 === preg_match( '/With errors<\/div><div class="bsa-tile-value">4</', $html ) && 9 === substr_count( $html, '<th scope="row">' ) && false !== strpos( $html, 'Import 5 learners' ) && 4 === substr_count( $html, 'bsa-row-error' ), '' );
	if ( $bsa_capture ) {
		$captured['check'] = $html;
	}
	$xss = BSA_Import::check( array( 2 => array( '<script>alert(1)</script> Testimport', 'Form 1' ) ), array( 'name' => 0, 'class' => 1 ), $s2 );
	$check( 'names: markup removed', false === strpos( $xss[0]['record']['name'], '<' ) );

	// Import.
	$enr_before = (int) $wpdb->get_var( "SELECT MAX(CAST(enrollment_number AS UNSIGNED)) FROM {$p}wlsm_student_records" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$bsa_before = (int) $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING(admission_number, 4) AS UNSIGNED)) FROM {$p}wlsm_student_records WHERE admission_number REGEXP '^BSA[0-9]+$'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'import: refused for a teacher', is_wp_error( BSA_Import::import( $rows, $teacher, 'x' ) ) );
	$done = BSA_Import::import( $rows, $head, 'test-list.csv' );
	$check( 'import: 5 learners saved, 4 rows skipped', is_array( $done ) && 5 === $done['imported'] && 4 === $done['skipped'], wp_json_encode( $done ) );
	$batch   = is_array( $done ) ? $done['batch'] : '';
	$batches = get_option( BSA_Import::BATCHES );
	$ids     = array_map( 'intval', $batches[ $batch ]['ids'] ?? array( 0 ) );
	$saved   = $wpdb->get_results( "SELECT * FROM {$p}wlsm_student_records WHERE ID IN (" . implode( ',', $ids ) . ') ORDER BY ID' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$byname = array();
	foreach ( $saved as $s ) {
		$byname[ $s->name ] = $s;
	}
	$t = $byname['Tendai Testimport'] ?? null;
	$check( 'import: learner record as Weblizar keeps it (enrollment number, roll = admission number, active, country, type, parents)', $t && sprintf( '%06d', $enr_before + 1 ) === $t->enrollment_number && 'TEST-IMP-001' === $t->roll_number && '1' === (string) $t->is_active && 'Zimbabwe' === $t->country && $board === $t->student_type && 'Tafadzwa Testimport' === $t->father_name && '0771234567' === $t->father_phone && '2011-03-14' === $t->dob && (int) $head === (int) $t->added_by && 'a:0:{}' === $t->activities && ! $t->user_id && ! $t->parent_user_id, wp_json_encode( $t ) );
	$rudo = $byname['Rudo Testimport'] ?? null;
	$check( 'import: new admission number BSA### after the highest one', $rudo && sprintf( 'BSA%03d', $bsa_before + 1 ) === $rudo->admission_number && $rudo->admission_number === $rudo->roll_number, $rudo ? $rudo->admission_number : 'missing' );
	$check( 'import: five learners in the order of the list, enrollment numbers without gaps', array( 'Tendai Testimport', 'Rudo Testimport', 'Farai Testimport', 'Nyasha Testimport', 'Kuda' ) === wp_list_pluck( $saved, 'name' ) && array_map( function ( $i ) use ( $enr_before ) { return sprintf( '%06d', $enr_before + $i ); }, range( 1, 5 ) ) === wp_list_pluck( $saved, 'enrollment_number' ), wp_json_encode( wp_list_pluck( $saved, 'enrollment_number' ) ) );
	$check( 'import: learner without a number in the list keeps the first-year number; no logins', 'TEST-IMP-OLD2' === ( $byname['Nyasha Testimport']->admission_number ?? '' ) && ! array_filter( wp_list_pluck( $saved, 'user_id' ) ) && ! array_filter( wp_list_pluck( $saved, 'parent_user_id' ) ) );
	$record = $t ? BSA_Admission_Records::get_record( (int) $t->ID ) : array();
	$check( 'import: Grade 7 units, primary school and birth certificate in the admission record', 8 === (int) ( $record['grade7_units'] ?? 0 ) && 'Chipadze Primary' === ( $record['grade7_school'] ?? '' ) && '12-345678A12' === ( $record['birth_certificate_no'] ?? '' ) && (int) $head === (int) ( $record['updated_by'] ?? 0 ), wp_json_encode( $record ) );
	$check( 'import: learners appear in their class for the year', in_array( (int) $t->ID, array_map( 'intval', wp_list_pluck( BSA_School::learners( 6, $s2 ), 'ID' ) ), true ) );
	$check( 'import: listed with source, year, user and learners', isset( $batches[ $batch ] ) && 'test-list.csv' === $batches[ $batch ]['source'] && $s2 === $batches[ $batch ]['session'] && $head === $batches[ $batch ]['user'] && 5 === count( $batches[ $batch ]['ids'] ) && '' === $batches[ $batch ]['undone'] );
	$again = BSA_Import::check( $parts['data'], $mapping, $s2 );
	$check( 'the same list again: admission numbers already used this year are errors', in_array( 'Admission number already used this year (Tendai Testimport).', $again[0]['errors'], true ), wp_json_encode( $again[0]['errors'] ) );
	$html = $render( array( 'page' => 'bsa-import', 'done' => $batch, 'n' => '5', 'skipped' => '4' ) );
	$check( 'screen: success message and the import listed with an Undo button', false !== strpos( $html, '5 learners imported; 4 rows with errors were skipped' ) && false !== strpos( $html, 'value="' . $batch . '"' ) && false !== strpos( $html, '>Undo</button>' ) );
	if ( $bsa_capture ) {
		$captured['done'] = $html;
	}

	// Undo keeps learners with records against them.
	$farai = $byname['Farai Testimport'] ?? null;
	$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => current_time( 'Y-m-d' ), 'status' => 'p', 'student_record_id' => (int) $farai->ID, 'added_by' => $head ) );
	$check( 'undo: refused for the bursar', is_wp_error( BSA_Import::undo( $batch, $bursar ) ) );
	$undo = BSA_Import::undo( $batch, $head );
	$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE ID IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$check( 'undo: 4 removed; the learner with a register entry is kept', is_array( $undo ) && 4 === $undo['removed'] && array( 'Farai Testimport' ) === $undo['kept'] && 1 === $left, wp_json_encode( $undo ) . " left $left" );
	$check( 'undo: admission record removed with the learner', 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSA_Admission_Records::table() . ' WHERE student_record_id = %d', (int) $t->ID ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$check( 'undo: cannot be done twice', is_wp_error( BSA_Import::undo( $batch, $head ) ) && '' !== get_option( BSA_Import::BATCHES )[ $batch ]['undone'] );
	$html = $render( array( 'page' => 'bsa-import' ) );
	$check( 'screen: the import is shown as undone', false !== strpos( $html, 'Undone ' ) );

	// Who sees the screen.
	wp_set_current_user( $teacher );
	$denied = false;
	try {
		$render( array( 'page' => 'bsa-import' ) );
	} catch ( RuntimeException $ex ) {
		$denied = false !== strpos( $ex->getMessage(), 'You cannot import data' );
	}
	$check( 'screen: refused for a teacher', $denied );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	remove_filter( 'bsa_sm_import_transaction', '__return_false' );
	delete_transient( 'bsa_import_' . $head );
	$_GET = array();
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	foreach ( $die_hooks as $hook ) {
		remove_filter( $hook, $die, 999 );
	}
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE name LIKE '%Testimport%' OR admission_number LIKE 'TEST-IMP%'" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	+ ( get_option( BSA_Import::BATCHES, false ) === $batches_before ? 0 : 1 );
$check( 'rollback: no synthetic data left; import list as before', 0 === $left, (string) $left );
if ( $bsa_capture ) {
	set_transient( 'bsa_capture_import', $captured, HOUR_IN_SECONDS );
}
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_import', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
