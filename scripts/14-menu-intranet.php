<?php
/**
 * Step 14: "Intranet" in the portal's site menu (owner, 2026-10-19): Home, My Account
 * (My Dashboard for staff), Intranet, Help, School website. Signed-in learners and
 * parents do not see it (plugin 0.16.4, BSA_Portal::staff_menu); visitors do, so staff
 * can tap it and sign in. Safe to run again. Run through Novamira `execute-php` (send
 * everything after the opening tag).
 *
 * @package BSA_School_Management
 */

$menu = wp_get_nav_menu_object( 'Portal menu' );
if ( ! $menu ) {
	return array( 'The "Portal menu" was not found; nothing changed.' );
}
$url   = home_url( '/intranet' );
$items = wp_get_nav_menu_items( $menu->term_id );
$have  = false;
foreach ( $items as $item ) {
	if ( untrailingslashit( $item->url ) === untrailingslashit( $url ) ) {
		$have = true;
	}
}
$log = array();
if ( ! $have ) {
	$id    = wp_update_nav_menu_item( $menu->term_id, 0, array( 'menu-item-title' => 'Intranet', 'menu-item-url' => $url, 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
	$log[] = is_wp_error( $id ) ? 'Could not add the Intranet link: ' . $id->get_error_message() : 'Intranet link added (menu item ' . $id . ').';
} else {
	$log[] = 'Intranet link already in the menu.';
}
$order = array( 'Home' => 1, 'My Account' => 2, 'Intranet' => 3, 'Help' => 4, 'School website' => 5 );
foreach ( wp_get_nav_menu_items( $menu->term_id ) as $item ) {
	if ( isset( $order[ $item->title ] ) && (int) $item->menu_order !== $order[ $item->title ] ) {
		wp_update_post( array( 'ID' => $item->ID, 'menu_order' => $order[ $item->title ] ) );
	}
}
wp_cache_flush();
$log[] = 'Menu: ' . implode( ', ', wp_list_pluck( wp_get_nav_menu_items( $menu->term_id ), 'title' ) );
return $log;
