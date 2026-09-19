<?php
/**
 * Runs when the plugin is DELETED from the Plugins screen — not when it is
 * deactivated. Deactivating is routinely done to test a conflict, and a merchant
 * who does that must get their settings and their synced locations back when they
 * switch the plugin on again.
 *
 * Order meta is deliberately left alone: a shipped order keeps the waybill number
 * and the pickup location it was sent to, whatever happens to this plugin.
 *
 * @package Drusoft_Shipping_For_Sameday
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove this plugin's tables, options and transients from the current site.
 */
function drushfs_uninstall_site(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drushfs_cities" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drushfs_lockers" );

	delete_option( 'drushfs_last_sync' );
	delete_option( 'woocommerce_drushfs_sameday_settings' );

	// Per-zone instance settings, plus every transient this plugin created.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$names = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options}
		  WHERE option_name LIKE 'woocommerce\\_drushfs\\_sameday\\_%\\_settings'
		     OR option_name LIKE '\\_transient\\_drushfs\\_%'
		     OR option_name LIKE '\\_transient\\_timeout\\_drushfs\\_%'"
	);
	foreach ( (array) $names as $name ) {
		delete_option( $name );
	}
}

/**
 * Run the cleanup on every site of the installation.
 *
 * Wrapped in a function so the loop variable is not a global — uninstall.php is
 * included at the top level, where globals belong to WordPress, not to us.
 */
function drushfs_uninstall_all_sites(): void {
	if ( ! is_multisite() ) {
		drushfs_uninstall_site();
		return;
	}

	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		drushfs_uninstall_site();
		restore_current_blog();
	}
}

drushfs_uninstall_all_sites();
