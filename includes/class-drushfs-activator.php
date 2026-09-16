<?php
/**
 * Fired during plugin activation.
 *
 * Two local tables, both refreshed daily from Sameday:
 *  - cities, because every shipment must carry a county (област) string and the
 *    customer only ever types a city;
 *  - lockers, so checkout never waits on Sameday's API to draw the picker.
 *
 * @package Drusoft_Shipping_For_Sameday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and drops the plugin's tables.
 */
class Drushfs_Activator {

	/**
	 * Create the location tables.
	 */
	public static function activate(): void {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_cities = $wpdb->prefix . 'drushfs_cities';
		$sql_cities   = "CREATE TABLE $table_cities (
			id int(11) UNSIGNED NOT NULL,
			name varchar(255) NULL,
			latin_name varchar(255) NULL,
			county_id int(11) UNSIGNED NULL,
			county varchar(255) NULL,
			postal_code varchar(16) NULL,
			village varchar(255) NULL,
			PRIMARY KEY  (id),
			KEY name_index (name),
			KEY county_index (county_id)
		) $charset_collate;";

		dbDelta( $sql_cities );

		// The key is oohId — the value Sameday expects back as oohLastMile.
		// Their records also carry an unrelated "id"; using it silently books
		// parcels to the wrong locker.
		$table_lockers = $wpdb->prefix . 'drushfs_lockers';
		$sql_lockers   = "CREATE TABLE $table_lockers (
			ooh_id int(11) UNSIGNED NOT NULL,
			name varchar(512) NULL,
			city varchar(255) NULL,
			city_id int(11) UNSIGNED NULL,
			county varchar(255) NULL,
			county_id int(11) UNSIGNED NULL,
			address varchar(512) NULL,
			postal_code varchar(16) NULL,
			latitude varchar(32) NULL,
			longitude varchar(32) NULL,
			ooh_type tinyint(4) NULL,
			supported_payment tinyint(4) NULL,
			client_visible tinyint(4) NULL,
			occupancy_level tinyint(4) NULL,
			capacity_exceeded tinyint(4) NULL,
			schedule text NULL,
			PRIMARY KEY  (ooh_id),
			KEY city_index (city_id),
			KEY name_index (name(191))
		) $charset_collate;";

		dbDelta( $sql_lockers );
	}

	/**
	 * Drop the tables and forget the schedule.
	 */
	public static function deactivate(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drushfs_cities" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drushfs_lockers" );
	}
}
