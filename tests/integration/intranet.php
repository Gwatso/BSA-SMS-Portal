<?php
/**
 * Integration test for BSA_Intranet, run on the live site through Novamira `execute-php`
 * (send everything after the opening tag).
 *
 * Synthetic posts, acknowledgements, learners and register entries are written inside a
 * transaction and rolled back. Before activation, set $bsa_stage to the staged plugin
 * folder to test that copy. Results are also kept for an hour in the `bsa_test_intranet`
 * transient. Over-HTTP checks after going live: `intranet-http.php`.
 *
 * @package BSA_School_Management
 */

global $wpdb;
$bsa_stage = isset( $bsa_stage ) ? $bsa_stage : '';
if ( ! class_exists( 'BSA_Intranet' ) && $bsa_stage ) {
	require_once $bsa_stage . '/includes/class-bsa-intranet.php';
	BSA_Intranet::register();
	add_filter( 'user_has_cap', array( 'BSA_Intranet', 'grant_caps' ), 10, 4 );
}

$p       = $wpdb->prefix;
$results = array();
$check   = function ( $name, $ok, $detail = '' ) use ( &$results ) {
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
$user = function ( $login ) {
	$u = get_user_by( 'login', $login );
	return $u ? (int) $u->ID : 0;
};
$owner   = $user( 'bsa' );
$head    = $user( 'Masango' );
$teacher = $user( 'hlabati.w' );
$bursar  = $user( 'bursar' );
$parent  = $user( 'bsa001.parent' );
$learner = $user( 'bsa001' );
BSA_School::flush();
$terms_before = get_option( BSA_Terms::OPTION, false );

// Helpers.
$prov = array(
	'https://onedrive.live.com/edit.aspx?resid=1' => 'OneDrive',
	'https://1drv.ms/w/s!abc'                     => 'OneDrive',
	'https://bsa-my.sharepoint.com/personal/x/a'  => 'OneDrive',
	'https://bsa.sharepoint.com/sites/staff/a'    => 'SharePoint',
	'https://docs.google.com/document/d/1/edit'   => 'Google Docs',
	'https://docs.google.com/spreadsheets/d/1'    => 'Google Sheets',
	'https://docs.google.com/forms/d/1/viewform'  => 'Google Forms',
	'https://drive.google.com/file/d/1/view'      => 'Google Drive',
	'https://example.org/file.pdf'                => 'Link',
);
$bad = array();
foreach ( $prov as $url => $label ) {
	if ( BSA_Intranet::provider( $url )['label'] !== $label ) {
		$bad[] = $url . ' → ' . BSA_Intranet::provider( $url )['label'];
	}
}
$check( 'provider: OneDrive, SharePoint, Google Docs/Sheets/Forms/Drive and plain links told apart', ! $bad, implode( ' | ', $bad ) );
$check( 'url: /intranet/ and sub-pages', home_url( '/intranet/' ) === BSA_Intranet::url() && home_url( '/intranet/item/5/' ) === BSA_Intranet::url( 'item/5' ) );

// Content types and rights.
$types_ok = true;
foreach ( array_keys( BSA_Intranet::TYPES ) as $type ) {
	$o = get_post_type_object( $type );
	if ( ! $o || $o->public || $o->publicly_queryable || $o->show_in_rest || ! $o->exclude_from_search ) {
		$types_ok = false;
	}
}
$check( 'types: six content types, not public, not in REST or site search', $types_ok );
$check( 'rights: owner and school administrator post; teacher, bursar, families cannot', user_can( $owner, 'edit_bsa_intranet_items' ) && user_can( $owner, 'publish_bsa_intranet_items' ) && user_can( $head, 'edit_bsa_intranet_items' ) && user_can( $head, 'publish_bsa_intranet_items' ) && user_can( $head, 'upload_files' ) && ! user_can( $teacher, 'edit_bsa_intranet_items' ) && ! user_can( $bursar, 'publish_bsa_intranet_items' ) && ! user_can( $parent, 'edit_bsa_intranet_items' ) && ! user_can( $learner, 'edit_bsa_intranet_items' ) );
$check( 'rights: staff read; families and visitors do not', user_can( $teacher, 'read_bsa_intranet' ) && user_can( $bursar, 'read_bsa_intranet' ) && ! user_can( $parent, 'read_bsa_intranet' ) && ! user_can( $learner, 'read_bsa_intranet' ) && BSA_Intranet::is_staff( $teacher ) && ! BSA_Intranet::is_staff( $learner ) && ! BSA_Intranet::is_staff( 0 ) && BSA_Intranet::can_edit( $head ) && ! BSA_Intranet::can_edit( $teacher ) );

$wpdb->query( 'START TRANSACTION' );
remove_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );
try {
	wp_set_current_user( $owner );
	$now   = current_time( 'mysql' );
	$today = current_time( 'Y-m-d' );
	$mk    = function ( $type, $title, $ago_days, array $fields = array(), $status = 'publish', $content = 'Synthetic test text.' ) use ( $now, $head ) {
		$id = wp_insert_post(
			array(
				'post_type'    => $type,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $status,
				'post_author'  => $head,
				'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( $now . ' -' . $ago_days . ' days' ) ),
			)
		);
		BSA_Intranet::save_fields( $id, $type, $fields );
		return (int) $id;
	};
	$news     = $mk( 'bsa_news', 'Intranet Test News Today', 0 );
	$old_ann  = $mk( 'bsa_announcement', 'Intranet Test Old Announcement', 10 );
	$urgent   = $mk( 'bsa_notice', 'Intranet Test Urgent Notice', 1, array( 'bsa_urgency' => 'urgent', 'bsa_ack' => '1' ) );
	$normal   = $mk( 'bsa_notice', 'Intranet Test Normal Notice', 2, array( 'bsa_urgency' => 'normal' ) );
	$expired  = $mk( 'bsa_notice', 'Intranet Test Expired Notice', 1, array( 'bsa_urgency' => 'urgent', 'bsa_until' => gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) ) ) );
	$pinned   = $mk( 'bsa_achievement', 'Intranet Test Pinned Achievement', 20, array( 'bsa_pin' => '1', 'bsa_category' => 'Sport' ) );
	$old_ach  = $mk( 'bsa_achievement', 'Intranet Test Old Achievement', 40, array( 'bsa_pin' => '1' ) );
	$soon     = $mk( 'bsa_event', 'Intranet Test Event Soon', 1, array( 'bsa_date' => gmdate( 'Y-m-d', strtotime( $today . ' +3 days' ) ), 'bsa_time' => '14:00', 'bsa_location' => 'Main Hall', 'bsa_type' => 'meeting' ) );
	$later    = $mk( 'bsa_event', 'Intranet Test Event Later', 1, array( 'bsa_date' => gmdate( 'Y-m-d', strtotime( $today . ' +20 days' ) ), 'bsa_end' => gmdate( 'Y-m-d', strtotime( $today . ' +22 days' ) ), 'bsa_type' => 'exam' ) );
	$res      = $mk( 'bsa_resource', 'Intranet Test Lesson Plan Template', 1, array( 'bsa_url' => 'https://onedrive.live.com/edit.aspx?resid=test', 'bsa_category' => 'Templates', 'bsa_department' => 'Academic' ) );
	$res_http = $mk( 'bsa_resource', 'Intranet Test Bad Link', 1, array( 'bsa_url' => 'http://insecure.example/x', 'bsa_category' => 'Nonsense' ) );
	$draft    = $mk( 'bsa_news', 'Intranet Test Draft', 0, array(), 'draft' );
	$bad_ev   = $mk( 'bsa_event', 'Intranet Test Bad Event', 1, array( 'bsa_date' => '2026-02-30', 'bsa_time' => '25:99', 'bsa_type' => 'party' ) );

	$check( 'fields: event date, time, place and type stored; a three-day exam keeps its end date', gmdate( 'Y-m-d', strtotime( $today . ' +3 days' ) ) === get_post_meta( $soon, '_bsa_date', true ) && '14:00' === get_post_meta( $soon, '_bsa_time', true ) && 'meeting' === get_post_meta( $soon, '_bsa_type', true ) && gmdate( 'Y-m-d', strtotime( $today . ' +22 days' ) ) === get_post_meta( $later, '_bsa_end', true ) );
	$check( 'fields: impossible date, bad time and unknown type refused', '' === get_post_meta( $bad_ev, '_bsa_date', true ) && '' === get_post_meta( $bad_ev, '_bsa_time', true ) && 'event' === get_post_meta( $bad_ev, '_bsa_type', true ) );
	$check( 'fields: only https links kept; unknown category falls back', '' === get_post_meta( $res_http, '_bsa_url', true ) && 'Administration' === get_post_meta( $res_http, '_bsa_category', true ) && 'Templates' === get_post_meta( $res, '_bsa_category', true ) );

	// Slider.
	$slides = BSA_Intranet::slides( 20 );
	$ids    = wp_list_pluck( $slides, 'id' );
	$check( 'slider: this week\'s news, urgent notice, event in 3 days and the featured achievement', in_array( $news, $ids, true ) && in_array( $urgent, $ids, true ) && in_array( $soon, $ids, true ) && in_array( $pinned, $ids, true ), wp_json_encode( wp_list_pluck( $slides, 'title' ) ) );
	$check( 'slider: not the 10-day-old announcement, the 40-day-old achievement, the expired notice, the event in 20 days or the draft', ! array_intersect( array( $old_ann, $old_ach, $expired, $later, $draft ), $ids ) );
	$pos = array_flip( $ids );
	$check( 'slider: featured and urgent first, then events, then the rest', $pos[ $pinned ] < $pos[ $soon ] && $pos[ $urgent ] < $pos[ $soon ] && $pos[ $soon ] < $pos[ $news ] );
	$check( 'slider: an event slide says when and where', false !== strpos( $slides[ $pos[ $soon ] ]['when'], '14:00' ) && false !== strpos( $slides[ $pos[ $soon ] ]['when'], 'Main Hall' ) && 'Meeting' === $slides[ $pos[ $soon ] ]['kind'] );

	// Notices and events.
	$nids = wp_list_pluck( BSA_Intranet::notices(), 'ID' );
	$check( 'notices: urgent before normal, expired left out', in_array( $urgent, $nids, true ) && in_array( $normal, $nids, true ) && ! in_array( $expired, $nids, true ) && array_search( $urgent, $nids, true ) < array_search( $normal, $nids, true ) );
	BSA_Terms::save( 1, array_replace( BSA_Terms::all()[1] ?? array(), array( 3 => array( 'start' => '2026-09-08', 'end' => gmdate( 'Y-m-d', strtotime( $today . ' +10 days' ) ) ) ) ) );
	$ev     = BSA_Intranet::events( $today, gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) ) );
	$titles = wp_list_pluck( $ev, 'title' );
	$dates  = wp_list_pluck( $ev, 'date' );
	$sorted = $dates;
	sort( $sorted );
	$check( 'events: both events and the end of term, in date order', in_array( 'Intranet Test Event Soon', $titles, true ) && in_array( 'Intranet Test Event Later', $titles, true ) && (bool) preg_grep( '/^Term 3 ends/', $titles ) && $dates === $sorted );
	$check( 'events: a multi-day exam shows while it is on', in_array( 'Intranet Test Event Later', wp_list_pluck( BSA_Intranet::events( gmdate( 'Y-m-d', strtotime( $today . ' +21 days' ) ), gmdate( 'Y-m-d', strtotime( $today . ' +21 days' ) ) ), 'title' ), true ) );

	// Acknowledgements.
	$pending_before = wp_list_pluck( BSA_Intranet::pending_acks( $teacher ), 'ID' );
	$tasks_before   = wp_list_pluck( BSA_Intranet::tasks( $teacher ), 'text' );
	$check( 'acknowledge: the urgent notice is waiting for the teacher and listed in "For you today"', in_array( $urgent, $pending_before, true ) && in_array( 'Read and acknowledge: Intranet Test Urgent Notice', $tasks_before, true ) );
	$check( 'acknowledge: teacher can; a learner cannot; a notice without acknowledgement cannot', BSA_Intranet::acknowledge( $urgent, $teacher ) && ! BSA_Intranet::acknowledge( $urgent, $learner ) && ! BSA_Intranet::acknowledge( $normal, $teacher ) );
	$acks = BSA_Intranet::acknowledgements( $urgent );
	$check( 'acknowledge: recorded once with the time; no longer pending for the teacher, still for the bursar', isset( $acks[ $teacher ] ) && 1 === count( $acks ) && ! in_array( $urgent, wp_list_pluck( BSA_Intranet::pending_acks( $teacher ), 'ID' ), true ) && in_array( $urgent, wp_list_pluck( BSA_Intranet::pending_acks( $bursar ), 'ID' ), true ) );
	$staff   = BSA_Intranet::staff_users();
	$receipt = BSA_Intranet::receipts_html( $urgent );
	$check( 'receipts: "Acknowledged by 1 of N staff" with who has and has not', false !== strpos( $receipt, 'Acknowledged by 1 of ' . count( $staff ) . ' staff' ) && false !== strpos( $receipt, 'Willard Hlabati' ) && false !== strpos( $receipt, 'not yet' ) );

	// Attendance: synthetic learners marked today in Form 3 Sciences.
	$mk_l = function ( $name ) use ( $wpdb, $p ) {
		$wpdb->insert( "{$p}wlsm_student_records", array( 'name' => $name, 'gender' => 'female', 'enrollment_number' => 'TEST-INTRA', 'admission_number' => 'TEST-INTRA', 'session_id' => 1, 'section_id' => 6, 'is_active' => 1 ) );
		return (int) $wpdb->insert_id;
	};
	foreach ( array( 'p', 'p', 'l', 'a' ) as $i => $status ) {
		$sid = $mk_l( 'Intra Test ' . $i );
		$wpdb->insert( "{$p}wlsm_attendance", array( 'attendance_date' => $today, 'status' => $status, 'student_record_id' => $sid, 'added_by' => $owner, 'created_at' => current_time( 'mysql' ) ) );
	}
	$att = BSA_Intranet::attendance();
	$f3s = array_values( array_filter( $att['classes'], static function ( $c ) { return 'Form 3 Sciences' === $c['title']; } ) );
	$check( 'attendance: today, Form 3 Sciences 3 of 4 attended (late counts), 1 absent, 1 late', $att['today'] && $f3s && 3 === $f3s[0]['attended'] && 4 === $f3s[0]['possible'] && 1 === $f3s[0]['absent'] && 1 === $f3s[0]['late'], wp_json_encode( $f3s ) );
	$text = BSA_Intranet::attendance_text( $att );
	$check( 'attendance: WhatsApp text with title in bold, each class and unmarked classes', 0 === strpos( $text, '*BSA attendance, ' ) && false !== strpos( $text, 'Form 3 Sciences: 75% (3/4)' ) && ( ! $att['unmarked'] || false !== strpos( $text, $att['unmarked'][0] . ': not marked' ) ) );
	$check( 'attendance: trend includes today', $att['trend'] && null !== end( $att['trend'] ) );

	// Screens.
	$render = function ( $uid, $view, array $get = array(), $item = 0 ) {
		wp_set_current_user( $uid );
		return BSA_Intranet::render( $view, $uid, $item ? array_merge( $get, array( 'item' => $item ) ) : $get );
	};
	$visible = static function ( $html ) {
		return html_entity_decode( wp_strip_all_tags( preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html ) ), ENT_QUOTES );
	};
	$home_t = $render( $teacher, 'home' );
	$check( 'home (teacher): greeting, sliding hero with controls, tasks, notices, attendance chart, events, resources', 1 === preg_match( '/<h1>Good (morning|afternoon|evening), \w+<\/h1>/', $home_t ) && false !== strpos( $home_t, 'data-bsi-carousel' ) && false !== strpos( $home_t, 'Pause the slides' ) && false !== strpos( $home_t, 'For you today' ) && false !== strpos( $home_t, 'Attendance today' ) && false !== strpos( $home_t, 'Coming up' ) && false !== strpos( $home_t, 'Intranet Test Lesson Plan Template' ) );
	$check( 'home (teacher): no share buttons, no "WordPress"', false === strpos( $home_t, 'wa.me' ) && false === stripos( $visible( $home_t ), 'wordpress' ) );
	$home_h = $render( $head, 'home' );
	$check( 'home (school administrator): share chart and WhatsApp text, Post link', false !== strpos( $home_h, 'https://wa.me/?text=' ) && false !== strpos( $home_h, 'bsi-att-data' ) && false !== strpos( $home_h, 'page=bsa-intranet' ) );
	$item_t = $render( $bursar, 'item', array(), $urgent );
	$check( 'notice page (bursar): acknowledge button with a nonce, no receipts', false !== strpos( $item_t, 'bsa_intranet_ack' ) && false !== strpos( $item_t, '_wpnonce' ) && false === strpos( $item_t, 'Read receipts' ) );
	$item_h = $render( $head, 'item', array(), $urgent );
	$check( 'notice page (school administrator): read receipts and edit link', false !== strpos( $item_h, 'Read receipts' ) && false !== strpos( $item_h, 'Acknowledged by 1 of' ) && false !== strpos( $item_h, 'post.php?action=edit' ) );
	$check( 'drafts: hidden from the teacher, shown to the school administrator', false !== strpos( $render( $teacher, 'item', array(), $draft ), 'Not found' ) && false !== strpos( $render( $head, 'item', array(), $draft ), 'Intranet Test Draft' ) );
	$check( 'not an intranet post: not found', false !== strpos( $render( $teacher, 'item', array(), (int) get_option( 'page_on_front' ) ), 'Not found' ) );
	$news_html = $render( $teacher, 'news', array( 'kind' => 'announcement' ) );
	$check( 'news page: announcements filter', false !== strpos( $news_html, 'Intranet Test Old Announcement' ) && false === strpos( $news_html, 'Intranet Test News Today' ) );
	$res_html = $render( $teacher, 'resources', array( 'category' => 'Templates' ) );
	$check( 'resources: category filter, OneDrive badge, opens in a new tab', false !== strpos( $res_html, 'Intranet Test Lesson Plan Template' ) && false === strpos( $res_html, 'Intranet Test Bad Link' ) && false !== strpos( $res_html, 'bsi-prov-onedrive' ) && false !== strpos( $res_html, 'target="_blank" rel="noopener"' ) );
	$cal = $render( $teacher, 'calendar' );
	$check( 'calendar: events by month with term end', false !== strpos( $cal, 'Intranet Test Event Soon' ) && false !== strpos( $cal, 'Term 3 ends' ) );
	$s = $render( $teacher, 'search', array( 'q' => 'Lesson Plan' ) );
	$check( 'search: finds the resource', false !== strpos( $s, 'Intranet Test Lesson Plan Template' ) && false !== strpos( $s, '1 result for' ) );
	$check( 'tasks (school administrator): School Dashboard items included', (bool) BSA_Intranet::tasks( $head ) );
} catch ( Throwable $ex ) {
	$check( 'no unexpected error', false, get_class( $ex ) . ': ' . $ex->getMessage() . ' @' . basename( $ex->getFile() ) . ':' . $ex->getLine() );
} finally {
	$wpdb->query( 'ROLLBACK' );
	add_filter( 'query', array( 'BSA_Finance', 'watch_query' ) );
	wp_set_current_user( $original );
	wp_cache_flush();
	BSA_School::flush();
	restore_error_handler();
}

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE 'Intranet Test%'" ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wlsm_student_records WHERE admission_number = 'TEST-INTRA'" );
$left += get_option( BSA_Terms::OPTION, false ) === $terms_before ? 0 : 1;
$check( 'rollback: no synthetic posts or learners left; term dates as before', 0 === $left, (string) $left );
$check( 'no PHP notices or warnings', ! $notices, implode( ' | ', array_slice( array_unique( $notices ), 0, 5 ) ) );

$failed = count( preg_grep( '/^FAIL/', $results ) );
array_unshift( $results, sprintf( '%d/%d passed', count( $results ) - $failed, count( $results ) ) );
set_transient( 'bsa_test_intranet', $results, HOUR_IN_SECONDS );
return array_merge( array( $results[0] ), array_values( preg_grep( '/^FAIL/', $results ) ) );
