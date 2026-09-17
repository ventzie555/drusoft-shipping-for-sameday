<?php
/**
 * Daily refresh of Sameday locations into local tables.
 *
 * Checkout must never wait on Sameday: the locker picker and the city lookup
 * both read these tables. The same pattern (Action Scheduler, daily) runs in the
 * Speedy and Econt plugins.
 *
 * @package Drusoft_Shipping_For_Sameday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pulls cities, lockers and services.
 */
class Drushfs_Syncer {

	/**
	 * Entry point for the scheduled job.
	 */
	public static function sync(): void {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- thousands of rows.
		}

		$creds = self::credentials();
		if ( empty( $creds['sameday_username'] ) || empty( $creds['sameday_password'] ) ) {
			self::log( 'error', __( 'Sameday sync skipped: no credentials configured.', 'drusoft-shipping-for-sameday' ) );
			return;
		}

		$cities   = self::update_cities( $creds );
		$lockers  = self::update_lockers( $creds );
		$services = self::update_services( $creds );

		// Services are a small convenience list; the two tables are what
		// checkout depends on, so only they decide whether the run counts.
		if ( $cities && $lockers ) {
			update_option( 'drushfs_last_sync', time(), false );
		}

		// The map's point list is cached for an hour; fresh data should show at once.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_drushfs\\_all\\_points\\_%' OR option_name LIKE '\\_transient\\_timeout\\_drushfs\\_all\\_points\\_%'" );

		unset( $services );
	}

	/**
	 * Credentials from the shipping method's settings, falling back to any
	 * per-instance settings row that carries them.
	 *
	 * @return array
	 */
	public static function credentials(): array {
		$settings = get_option( 'woocommerce_drushfs_sameday_settings' );

		if ( is_array( $settings ) && ! empty( $settings['sameday_username'] ) && ! empty( $settings['sameday_password'] ) ) {
			return array(
				'sameday_username' => $settings['sameday_username'],
				'sameday_password' => $settings['sameday_password'],
				'sameday_env'      => $settings['sameday_env'] ?? 'demo',
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				'woocommerce_drushfs_sameday_%_settings'
			)
		);

		foreach ( (array) $rows as $row ) {
			$instance = maybe_unserialize( $row );
			if ( is_array( $instance ) && ! empty( $instance['sameday_username'] ) && ! empty( $instance['sameday_password'] ) ) {
				return array(
					'sameday_username' => $instance['sameday_username'],
					'sameday_password' => $instance['sameday_password'],
					'sameday_env'      => $instance['sameday_env'] ?? 'demo',
				);
			}
		}

		return array();
	}

	/**
	 * Bulgarian cities, paged. Needed because every shipment carries a county
	 * string that the customer never types.
	 *
	 * @param array $creds Credentials.
	 * @return bool
	 */
	private static function update_cities( array $creds ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'drushfs_cities';
		$rows  = array();

		for ( $page = 1; $page <= 40; $page++ ) {
			$response = Drushfs_Api::request(
				$creds,
				'GET',
				'/api/geolocation/city',
				array(
					'countryCode'  => 'BG',
					'page'         => $page,
					'countPerPage' => 500,
				)
			);

			if ( is_wp_error( $response ) ) {
				self::log( 'error', 'Sameday cities sync: ' . $response->get_error_message() );
				return false;
			}

			$data = $response['data'] ?? array();
			if ( ! $data ) {
				break;
			}

			foreach ( $data as $city ) {
				$rows[] = array(
					'id'          => (int) ( $city['id'] ?? 0 ),
					'name'        => (string) ( $city['name'] ?? '' ),
					'latin_name'  => (string) ( $city['latinName'] ?? '' ),
					'county_id'   => (int) ( $city['county']['id'] ?? 0 ),
					'county'      => (string) ( $city['county']['name'] ?? '' ),
					'postal_code' => (string) ( $city['postalCode'] ?? '' ),
					'village'     => (string) ( $city['village'] ?? '' ),
				);
			}

			$pages = (int) ( $response['pages'] ?? 0 );
			if ( $pages && $page >= $pages ) {
				break;
			}
		}

		if ( ! $rows ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		foreach ( $rows as $row ) {
			if ( ! $row['id'] ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, $row );
		}

		self::log( 'info', sprintf( 'Sameday cities sync completed: %d rows.', count( $rows ) ) );
		return true;
	}

	/**
	 * Easybox and PUDO points. The stored key is oohId — the value that goes
	 * back to Sameday as oohLastMile.
	 *
	 * @param array $creds Credentials.
	 * @return bool
	 */
	private static function update_lockers( array $creds ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'drushfs_lockers';
		$rows  = array();

		for ( $page = 1; $page <= 20; $page++ ) {
			$response = Drushfs_Api::ooh_locations( $creds, $page, 500 );

			if ( is_wp_error( $response ) ) {
				// Past the last page Sameday answers an error, not an empty list.
				if ( $page > 1 && $rows ) {
					break;
				}
				self::log( 'error', 'Sameday lockers sync: ' . $response->get_error_message() );
				return false;
			}

			$data = $response['data'] ?? array();
			if ( ! $data ) {
				break;
			}

			foreach ( $data as $locker ) {
				$ooh_id = (int) ( $locker['oohId'] ?? 0 );
				if ( ! $ooh_id ) {
					continue;
				}
				$rows[ $ooh_id ] = array(
					'ooh_id'            => $ooh_id,
					'name'              => (string) ( $locker['name'] ?? '' ),
					'city'              => (string) ( $locker['city'] ?? '' ),
					'city_id'           => (int) ( $locker['cityId'] ?? 0 ),
					'county'            => (string) ( $locker['county'] ?? '' ),
					'county_id'         => (int) ( $locker['countyId'] ?? 0 ),
					'address'           => (string) ( $locker['address'] ?? '' ),
					'postal_code'       => (string) ( $locker['postalCode'] ?? '' ),
					'latitude'          => (string) ( $locker['lat'] ?? '' ),
					'longitude'         => (string) ( $locker['lng'] ?? '' ),
					'ooh_type'          => (int) ( $locker['oohType'] ?? 0 ),
					'supported_payment' => (int) ( $locker['supportedPayment'] ?? 0 ),
					'client_visible'    => (int) ( $locker['clientVisible'] ?? 1 ),
					'occupancy_level'   => (int) ( $locker['occupancyLevel'] ?? 0 ),
					'capacity_exceeded' => (int) ( $locker['capacityExceeded'] ?? 0 ),
					'schedule'          => wp_json_encode( $locker['schedule'] ?? array() ),
				);
			}

			// Follow the page count Sameday reports. A short page is NOT the last
			// one: production answers 500, 499, 497, 226 for its 1,722 locations,
			// and stopping at the first short page silently dropped 723 of them
			// (found 17.09.2026 — every easybox arrived, most SAMEDAY points did
			// not). Without a page count, carry on until a page comes back empty.
			$pages = (int) ( $response['pages'] ?? 0 );
			if ( $pages && $page >= $pages ) {
				break;
			}
		}

		if ( ! $rows ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert( $table, $row );
		}

		self::log( 'info', sprintf( 'Sameday lockers sync completed: %d rows.', count( $rows ) ) );
		return true;
	}

	/**
	 * Services enabled for the account, cached in an option for the settings
	 * screen and the rate builder.
	 *
	 * @param array $creds Credentials.
	 * @return bool
	 */
	private static function update_services( array $creds ): bool {
		$response = Drushfs_Api::services( $creds );

		if ( is_wp_error( $response ) ) {
			self::log( 'error', 'Sameday services sync: ' . $response->get_error_message() );
			return false;
		}

		$services = array();
		foreach ( (array) ( $response['data'] ?? array() ) as $service ) {
			$id = (int) ( $service['id'] ?? 0 );
			if ( ! $id ) {
				continue;
			}
			$services[ $id ] = array(
				'id'   => $id,
				'code' => (string) ( $service['serviceCode'] ?? '' ),
				'name' => (string) ( $service['name'] ?? '' ),
			);
		}

		if ( ! $services ) {
			return false;
		}

		update_option( 'drushfs_services', $services, false );
		return true;
	}

	/**
	 * Write to the WooCommerce log, if it is available.
	 *
	 * A failed log write is silent, so the settings screen reads
	 * drushfs_last_sync instead — never the log — to decide whether the daily
	 * refresh is alive.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 */
	private static function log( string $level, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => 'drusoft-shipping-for-sameday' ) );
		}
	}
}
