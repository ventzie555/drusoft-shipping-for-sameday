<?php
/**
 * Sameday shipping method.
 *
 * Three delivery types, priced live by Sameday's estimate-cost endpoint with a
 * configurable fallback table for when the API is unreachable:
 *
 *   address  — service 24H to the customer's door
 *   easybox  — service Locker NextDay to an easybox
 *   pudo     — service PUDO to a SAMEDAY point
 *
 * The PUDO option hides itself while no SAMEDAY point exists for the customer's
 * country: on 16.09.2026 Sameday Bulgaria had 147 easybox and zero real PUDO
 * points, so offering it would have been an empty dropdown.
 *
 * What this method deliberately does NOT do: halve the price for small baskets.
 * That is a druoutlet promise and lives in the theme, which halves every rate
 * with a cost above zero regardless of courier.
 *
 * @package Drusoft_Shipping_For_Sameday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Drushfs_Shipping_Method' ) ) {

	/**
	 * WooCommerce shipping method for Sameday.
	 */
	class Drushfs_Shipping_Method extends WC_Shipping_Method {

		/** Service ids as returned by GET /api/client/services for a BG account. */
		const SERVICE_ADDRESS = 7;
		const SERVICE_LOCKER  = 15;
		const SERVICE_PUDO    = 48;

		/** oohType values in the lockers table. */
		const OOH_EASYBOX = 0;
		const OOH_PUDO    = 1;

		/**
		 * Constructor.
		 *
		 * @param int $instance_id Shipping zone instance.
		 */
		public function __construct( $instance_id = 0 ) {
			$this->id                 = 'drushfs_sameday';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Sameday', 'drusoft-shipping-for-sameday' );
			$this->method_description = __( 'Delivery to an address, an easybox or a SAMEDAY point, priced by Sameday.', 'drusoft-shipping-for-sameday' );
			$this->supports           = array(
				'shipping-zones',
				'instance-settings',
				'settings',
			);

			$this->init();
		}

		/**
		 * Load settings and wire the admin hooks.
		 */
		public function init(): void {
			$this->init_form_fields();
			$this->init_settings();

			$this->title   = $this->get_option( 'title', __( 'Sameday delivery', 'drusoft-shipping-for-sameday' ) );
			$this->enabled = $this->get_option( 'enabled', 'yes' );

			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/* -----------------------------------------------------------------
		 * Settings
		 * -------------------------------------------------------------- */

		/**
		 * Settings screen.
		 */
		public function init_form_fields(): void {
			$this->form_fields = array(
				'api_title'        => array(
					'title' => __( 'Sameday API', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				),
				'enabled'          => array(
					'title'   => __( 'Module status', 'drusoft-shipping-for-sameday' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable Sameday at checkout', 'drusoft-shipping-for-sameday' ),
					'default' => 'no',
				),
				'title'            => array(
					'title'       => __( 'Method title', 'drusoft-shipping-for-sameday' ),
					'type'        => 'text',
					'description' => __( 'Shown to the customer at checkout.', 'drusoft-shipping-for-sameday' ),
					'default'     => __( 'Sameday delivery', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
				),
				'sameday_env'      => array(
					'title'       => __( 'Environment', 'drusoft-shipping-for-sameday' ),
					'type'        => 'select',
					'options'     => array(
						'demo' => __( 'Demo (sameday-api-bg.demo.zitec.com)', 'drusoft-shipping-for-sameday' ),
						'live' => __( 'Production (api.sameday.bg)', 'drusoft-shipping-for-sameday' ),
					),
					'default'     => 'demo',
					'description' => __( 'Demo and production have separate credentials. Waybills created on demo are not real shipments.', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
				),
				'sameday_username' => array(
					'title' => __( 'Username', 'drusoft-shipping-for-sameday' ),
					'type'  => 'text',
				),
				'sameday_password' => array(
					'title' => __( 'Password', 'drusoft-shipping-for-sameday' ),
					'type'  => 'password',
				),
				'sync_status'      => array(
					'title'       => __( 'Locations', 'drusoft-shipping-for-sameday' ),
					'type'        => 'title',
					'description' => $this->sync_status_text(),
				),
				'sender_title'     => array(
					'title' => __( 'Sender', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				),
				'pickup_point'     => array(
					'title'       => __( 'Pickup point', 'drusoft-shipping-for-sameday' ),
					'type'        => 'select',
					'options'     => $this->pickup_point_options(),
					'description' => __( 'The warehouse Sameday collects from. Every shipment carries this id.', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
				),
				'delivery_title'   => array(
					'title' => __( 'Delivery options', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				),
				'offer_address'    => array(
					'title'   => __( 'To an address', 'drusoft-shipping-for-sameday' ),
					'type'    => 'checkbox',
					'label'   => __( 'Offer delivery to the customer\'s address (24H)', 'drusoft-shipping-for-sameday' ),
					'default' => 'yes',
				),
				'offer_easybox'    => array(
					'title'   => __( 'To an easybox', 'drusoft-shipping-for-sameday' ),
					'type'    => 'checkbox',
					'label'   => __( 'Offer delivery to an easybox locker (Locker NextDay)', 'drusoft-shipping-for-sameday' ),
					'default' => 'yes',
				),
				'offer_pudo'       => array(
					'title'       => __( 'To a SAMEDAY point', 'drusoft-shipping-for-sameday' ),
					'type'        => 'checkbox',
					'label'       => __( 'Offer delivery to a SAMEDAY point (PUDO)', 'drusoft-shipping-for-sameday' ),
					'default'     => 'yes',
					'description' => __( 'Hidden automatically while Sameday lists no SAMEDAY point in the customer\'s country.', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
				),
				'waybill_title'    => array(
					'title' => __( 'Waybills', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				),
				'generate_waybill' => array(
					'title'       => __( 'Create automatically', 'drusoft-shipping-for-sameday' ),
					'type'        => 'checkbox',
					'label'       => __( 'Create the Sameday waybill when an order becomes Processing or On hold', 'drusoft-shipping-for-sameday' ),
					'default'     => 'no',
					'description' => __( 'Off: create each waybill by hand from the order screen. An order that already has a waybill is never given a second one.', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
				),
				'parcel_title'     => array(
					'title' => __( 'Parcel', 'drusoft-shipping-for-sameday' ),
					'type'  => 'title',
				),
				'default_weight'   => array(
					'title'       => __( 'Default weight (kg)', 'drusoft-shipping-for-sameday' ),
					'type'        => 'number',
					'default'     => '1',
					'css'         => 'width: 100px;',
					'custom_attributes' => array(
						'step' => '0.1',
						'min'  => '0.1',
					),
					'description' => __( 'Used when a product carries no weight.', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
				),
				'insure'           => array(
					'title'       => __( 'Declared value', 'drusoft-shipping-for-sameday' ),
					'type'        => 'select',
					'options'     => array(
						'no'        => __( 'Never declare a value', 'drusoft-shipping-for-sameday' ),
						'threshold' => __( 'Declare above a threshold', 'drusoft-shipping-for-sameday' ),
						'always'    => __( 'Always declare the order value', 'drusoft-shipping-for-sameday' ),
					),
					'default'     => 'no',
					'description' => __( 'Insurance costs 0.5% of the declared value. Without it Sameday\'s liability is capped low.', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
				),
				'insure_from'      => array(
					'title'   => __( 'Declare from (order total)', 'drusoft-shipping-for-sameday' ),
					'type'    => 'number',
					'default' => '300',
					'css'     => 'width: 100px;',
				),
				'fallback_title'   => array(
					'title'       => __( 'Fallback prices', 'drusoft-shipping-for-sameday' ),
					'type'        => 'title',
					'description' => __( 'Used only when Sameday cannot be reached, so checkout still shows a price. Enter what the contract charges, including any surcharges.', 'drusoft-shipping-for-sameday' ),
				),
				'fallback_address' => array(
					'title'   => __( 'To an address', 'drusoft-shipping-for-sameday' ),
					'type'    => 'number',
					'default' => '3.40',
					'css'     => 'width: 100px;',
					'custom_attributes' => array( 'step' => '0.01' ),
				),
				'fallback_easybox' => array(
					'title'   => __( 'To an easybox', 'drusoft-shipping-for-sameday' ),
					'type'    => 'number',
					'default' => '1.60',
					'css'     => 'width: 100px;',
					'custom_attributes' => array( 'step' => '0.01' ),
				),
				'fallback_pudo'    => array(
					'title'   => __( 'To a SAMEDAY point', 'drusoft-shipping-for-sameday' ),
					'type'    => 'number',
					'default' => '1.60',
					'css'     => 'width: 100px;',
					'custom_attributes' => array( 'step' => '0.01' ),
				),
				'fallback_per_kg'  => array(
					'title'       => __( 'Per extra kg', 'drusoft-shipping-for-sameday' ),
					'type'        => 'number',
					'default'     => '0.25',
					'css'         => 'width: 100px;',
					'custom_attributes' => array( 'step' => '0.01' ),
					'description' => __( 'Added for every kilogram above three.', 'drusoft-shipping-for-sameday' ),
					'desc_tip'    => true,
				),
			);

			$this->instance_form_fields = $this->form_fields;
		}

		/**
		 * One line for the settings screen: when the locations were last
		 * refreshed, and whether the daily job is alive.
		 *
		 * Reads the option rather than the WooCommerce log, because a failed
		 * log write is silent and proves nothing.
		 *
		 * @return string
		 */
		private function sync_status_text(): string {
			global $wpdb;

			$last = (int) get_option( 'drushfs_last_sync', 0 );

			if ( ! $last ) {
				return '<strong>' . esc_html__( 'Locations have never been refreshed yet. They arrive once credentials are saved.', 'drusoft-shipping-for-sameday' ) . '</strong>';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$lockers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}drushfs_lockers" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cities = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}drushfs_cities" );

			$text = sprintf(
				/* translators: 1: date and time, 2: age such as "3 hours", 3: locker count, 4: city count */
				__( 'Last refreshed %1$s (%2$s ago): %3$d pickup locations, %4$d cities.', 'drusoft-shipping-for-sameday' ),
				date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ),
				human_time_diff( $last ),
				$lockers,
				$cities
			);

			if ( time() - $last > 3 * DAY_IN_SECONDS ) {
				$text .= ' <strong>' . esc_html__( 'That is more than three days — the daily refresh may not be running.', 'drusoft-shipping-for-sameday' ) . '</strong>';
			}

			return $text;
		}

		/**
		 * Pickup points for the select, fetched from Sameday and cached.
		 *
		 * @return array
		 */
		private function pickup_point_options(): array {
			$creds = $this->credentials();
			if ( empty( $creds['sameday_username'] ) ) {
				return array( '' => __( 'Save your credentials first', 'drusoft-shipping-for-sameday' ) );
			}

			$cache_key = 'drushfs_pickup_points_' . md5( $creds['sameday_username'] . '|' . ( $creds['sameday_env'] ?? '' ) );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) && $cached ) {
				return $cached;
			}

			$response = Drushfs_Api::pickup_points( $creds );
			if ( is_wp_error( $response ) ) {
				return array( '' => $response->get_error_message() );
			}

			$options = array();
			foreach ( (array) ( $response['data'] ?? array() ) as $point ) {
				$id = (int) ( $point['id'] ?? 0 );
				if ( ! $id ) {
					continue;
				}
				$options[ $id ] = sprintf(
					'%s — %s, %s',
					$point['alias'] ?? __( 'Pickup point', 'drusoft-shipping-for-sameday' ),
					$point['city']['name'] ?? '',
					$point['address'] ?? ''
				);
			}

			if ( $options ) {
				set_transient( $cache_key, $options, HOUR_IN_SECONDS );
			}

			return $options ?: array( '' => __( 'Sameday returned no pickup points', 'drusoft-shipping-for-sameday' ) );
		}

		/**
		 * Credentials for this instance, falling back to the global settings.
		 *
		 * @return array
		 */
		public function credentials(): array {
			$user = (string) $this->get_option( 'sameday_username', '' );
			$pass = (string) $this->get_option( 'sameday_password', '' );

			if ( '' !== $user && '' !== $pass ) {
				return array(
					'sameday_username' => $user,
					'sameday_password' => $pass,
					'sameday_env'      => (string) $this->get_option( 'sameday_env', 'demo' ),
				);
			}

			return Drushfs_Syncer::credentials();
		}

		/**
		 * Check the credentials when they are saved, and refresh the locations
		 * immediately so the shop never waits a day for its first locker list.
		 *
		 * @return bool
		 */
		public function process_admin_options(): bool {
			$saved = parent::process_admin_options();

			$creds = $this->credentials();
			if ( empty( $creds['sameday_username'] ) || empty( $creds['sameday_password'] ) ) {
				return $saved;
			}

			$token = Drushfs_Api::token( $creds, true );

			if ( is_wp_error( $token ) ) {
				WC_Admin_Settings::add_error(
					sprintf(
						/* translators: %s: error message from Sameday. */
						__( 'Sameday rejected these credentials: %s', 'drusoft-shipping-for-sameday' ),
						$token->get_error_message()
					)
				);
				return $saved;
			}

			WC_Admin_Settings::add_message( __( 'Sameday credentials accepted.', 'drusoft-shipping-for-sameday' ) );

			delete_transient( 'drushfs_pickup_points_' . md5( $creds['sameday_username'] . '|' . ( $creds['sameday_env'] ?? '' ) ) );

			if ( class_exists( 'Drushfs_Syncer' ) ) {
				Drushfs_Syncer::sync();
			}

			return $saved;
		}

		/* -----------------------------------------------------------------
		 * Rates
		 * -------------------------------------------------------------- */

		/**
		 * Offer ONE rate, priced for the delivery type the customer chose in
		 * the checkout form.
		 *
		 * The first version offered a rate per type, which put three Sameday
		 * entries in the shipping list beside one each for Speedy and Econt, and
		 * left the list and the in-form address/easybox radios able to disagree
		 * (the browser test on 17.09.2026 showed "easybox" ticked in the list and
		 * "address" in the form). The siblings do it this way: one entry, the
		 * type chosen in the form, the price following it.
		 *
		 * @param array $package WooCommerce package.
		 */
		public function calculate_shipping( $package = array() ): void {
			$creds = $this->credentials();
			if ( empty( $creds['sameday_username'] ) ) {
				return;
			}

			$enabled = $this->enabled_types( $package );
			if ( ! $enabled ) {
				return;
			}

			$type = $this->chosen_type( $package, array_keys( $enabled ) );

			// A point picked in the form travels in the package; make it the
			// one the quote is priced for.
			$point = absint( $package['sameday_office_id'] ?? 0 );
			if ( WC()->session ) {
				WC()->session->set( 'drushfs_delivery_type', $type );
				if ( 'address' === $type ) {
					WC()->session->set( 'drushfs_office_id', 0 );
				} elseif ( $point ) {
					WC()->session->set( 'drushfs_office_id', $point );
				}
			}

			$weight = $this->package_weight( $package );
			$cod    = $this->cod_amount( $package );
			$value  = $this->declared_value( $package );

			$cost = $this->quote( $type, $package, $weight, $cod, $value, $creds );
			if ( null === $cost ) {
				return;
			}

			$this->add_rate(
				array(
					'id'        => $this->get_rate_id(),
					'label'     => $this->title,
					'cost'      => $cost['amount'],
					'package'   => $package,
					'meta_data' => array(
						'delivery_type' => $type,
						'priced_by'     => $cost['source'],
					),
				)
			);
		}

		/**
		 * The delivery type to price: the form's choice, then the session, then
		 * the first type this shop offers.
		 *
		 * @param array $package WooCommerce package.
		 * @param array $allowed Enabled type keys.
		 * @return string
		 */
		private function chosen_type( array $package, array $allowed ): string {
			$candidates = array(
				(string) ( $package['sameday_delivery_type'] ?? '' ),
				WC()->session ? (string) WC()->session->get( 'drushfs_delivery_type', '' ) : '',
			);

			foreach ( $candidates as $candidate ) {
				if ( in_array( $candidate, $allowed, true ) ) {
					return $candidate;
				}
			}

			return (string) reset( $allowed );
		}

		/**
		 * Delivery types this shop offers, minus any that cannot work for this
		 * customer — PUDO disappears when Sameday lists no SAMEDAY point in
		 * their country rather than showing an empty picker.
		 *
		 * @param array $package WooCommerce package.
		 * @return array type => label
		 */
		private function enabled_types( array $package ): array {
			$types = array();

			if ( 'yes' === $this->get_option( 'offer_address', 'yes' ) ) {
				$types['address'] = __( 'Sameday — to an address', 'drusoft-shipping-for-sameday' );
			}

			if ( 'yes' === $this->get_option( 'offer_easybox', 'yes' ) && $this->has_points( self::OOH_EASYBOX ) ) {
				$types['easybox'] = __( 'Sameday — to an easybox', 'drusoft-shipping-for-sameday' );
			}

			if ( 'yes' === $this->get_option( 'offer_pudo', 'yes' ) && $this->has_points( self::OOH_PUDO ) ) {
				$types['pudo'] = __( 'Sameday — to a SAMEDAY point', 'drusoft-shipping-for-sameday' );
			}

			unset( $package );

			return $types;
		}

		/**
		 * Whether any pickup location of this kind exists locally.
		 *
		 * @param int $ooh_type OOH_EASYBOX or OOH_PUDO.
		 * @return bool
		 */
		private function has_points( int $ooh_type ): bool {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}drushfs_lockers WHERE ooh_type = %d AND client_visible = 1",
					$ooh_type
				)
			);
		}

		/**
		 * Price one delivery type: ask Sameday, fall back to the table.
		 *
		 * The quote is cached for the exact shipment shape, because WooCommerce
		 * recalculates shipping on every checkout keystroke that touches the
		 * address and we would otherwise call the API dozens of times per order.
		 *
		 * @param string $type    Delivery type.
		 * @param array  $package Package.
		 * @param float  $weight  Weight in kg.
		 * @param float  $cod     Cash on delivery amount.
		 * @param float  $value   Declared value.
		 * @param array  $creds   Credentials.
		 * @return array|null {amount, source} or null when nothing can be quoted.
		 */
		private function quote( string $type, array $package, float $weight, float $cod, float $value, array $creds ): ?array {
			$destination = $package['destination'] ?? array();
			$city        = (string) ( $destination['city'] ?? '' );

			$cache_key = 'drushfs_q_' . md5(
				wp_json_encode(
					array( $type, $city, $destination['postcode'] ?? '', $weight, $cod, $value, $creds['sameday_env'] ?? '' )
				)
			);

			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}

			$quote = null;

			$payload = $this->build_payload( $type, $package, $weight, $cod, $value );
			if ( $payload ) {
				$response = Drushfs_Api::estimate( $creds, $payload );

				if ( ! is_wp_error( $response ) && isset( $response['amount'] ) ) {
					$quote = array(
						'amount' => round( (float) $response['amount'], 2 ),
						'source' => 'api',
					);
				} elseif ( is_wp_error( $response ) && function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->warning(
						'Sameday estimate failed (' . $type . '), using the fallback table: ' . $response->get_error_message(),
						array( 'source' => 'drusoft-shipping-for-sameday' )
					);
				}
			}

			if ( null === $quote ) {
				$quote = array(
					'amount' => $this->fallback_price( $type, $weight ),
					'source' => 'fallback',
				);
			}

			set_transient( $cache_key, $quote, 15 * MINUTE_IN_SECONDS );

			return $quote;
		}

		/**
		 * Price from the configured table when Sameday cannot be reached.
		 *
		 * @param string $type   Delivery type.
		 * @param float  $weight Weight in kg.
		 * @return float
		 */
		private function fallback_price( string $type, float $weight ): float {
			$base = (float) $this->get_option( 'fallback_' . $type, 0 );

			if ( $weight > 3 ) {
				$base += ceil( $weight - 3 ) * (float) $this->get_option( 'fallback_per_kg', 0 );
			}

			return round( $base, 2 );
		}

		/**
		 * Build the shipment payload shared by estimate-cost and AWB creation.
		 *
		 * @param string $type    Delivery type.
		 * @param array  $package Package.
		 * @param float  $weight  Weight.
		 * @param float  $cod     Cash on delivery.
		 * @param float  $value   Declared value.
		 * @return array Empty when the shipment cannot be described yet.
		 */
		private function build_payload( string $type, array $package, float $weight, float $cod, float $value ): array {
			$pickup = (int) $this->get_option( 'pickup_point', 0 );
			if ( ! $pickup ) {
				return array();
			}

			$destination = $package['destination'] ?? array();
			$city        = (string) ( $destination['city'] ?? '' );
			if ( '' === $city ) {
				return array();
			}

			$service = self::SERVICE_ADDRESS;
			if ( 'easybox' === $type ) {
				$service = self::SERVICE_LOCKER;
			} elseif ( 'pudo' === $type ) {
				$service = self::SERVICE_PUDO;
			}

			$payload = array(
				'pickupPoint'      => $pickup,
				'packageType'      => 0,
				'packageNumber'    => 1,
				'packageWeight'    => max( 0.1, $weight ),
				'service'          => $service,
				'awbPayment'       => 1,
				'cashOnDelivery'   => $cod,
				'insuredValue'     => $value,
				'thirdPartyPickup' => 0,
				'awbRecipient'     => array(
					'name'         => trim( (string) ( $destination['first_name'] ?? '' ) . ' ' . (string) ( $destination['last_name'] ?? '' ) ) ?: __( 'Recipient', 'drusoft-shipping-for-sameday' ),
					'phoneNumber'  => '0000000000',
					// Sameday rejects a locker shipment with a blank recipient
					// e-mail, and says so only deep inside its validation tree.
					'email'        => 'noreply@example.com',
					'personType'   => 0,
					'cityString'   => $city,
					'countyString' => $this->county_for_city( $city, (string) ( $destination['state'] ?? '' ) ),
					'address'      => trim( (string) ( $destination['address_1'] ?? '' ) . ' ' . (string) ( $destination['address_2'] ?? '' ) ) ?: '-',
					'postalCode'   => (string) ( $destination['postcode'] ?? '' ),
				),
				'parcels'          => array(
					array( 'weight' => max( 0.1, $weight ) ),
				),
			);

			// A quote for a locker needs a locker: use the customer's choice
			// when they have made one, otherwise any visible point in their
			// city, because the price is the same across a city.
			if ( in_array( $type, array( 'easybox', 'pudo' ), true ) ) {
				$ooh = $this->resolve_point( $type, $city );
				if ( ! $ooh ) {
					return array();
				}
				$payload['oohLastMile'] = $ooh;
			}

			return $payload;
		}

		/**
		 * The county (област) Sameday expects alongside a city name.
		 *
		 * @param string $city  City name.
		 * @param string $state WooCommerce state code, used as a fallback.
		 * @return string
		 */
		private function county_for_city( string $city, string $state = '' ): string {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$county = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT county FROM {$wpdb->prefix}drushfs_cities WHERE name = %s ORDER BY id ASC LIMIT 1",
					$city
				)
			);

			return $county ?: $state;
		}

		/**
		 * The locker or SAMEDAY point to quote for.
		 *
		 * @param string $type Delivery type.
		 * @param string $city City name.
		 * @return int oohId, or 0 when the city has none.
		 */
		private function resolve_point( string $type, string $city ): int {
			$chosen = WC()->session ? absint( WC()->session->get( 'drushfs_office_id', 0 ) ) : 0;
			if ( $chosen ) {
				return $chosen;
			}

			global $wpdb;
			$ooh_type = ( 'pudo' === $type ) ? self::OOH_PUDO : self::OOH_EASYBOX;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ooh_id FROM {$wpdb->prefix}drushfs_lockers
					 WHERE ooh_type = %d AND client_visible = 1 AND capacity_exceeded = 0 AND city = %s
					 ORDER BY occupancy_level ASC LIMIT 1",
					$ooh_type,
					$city
				)
			);
		}

		/**
		 * Total weight of the package, never zero.
		 *
		 * @param array $package Package.
		 * @return float
		 */
		private function package_weight( array $package ): float {
			$weight = 0.0;

			foreach ( (array) ( $package['contents'] ?? array() ) as $item ) {
				$product = $item['data'] ?? null;
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$each    = (float) $product->get_weight();
				$weight += ( $each > 0 ? $each : (float) $this->get_option( 'default_weight', 1 ) ) * (int) ( $item['quantity'] ?? 1 );
			}

			return $weight > 0 ? $weight : (float) $this->get_option( 'default_weight', 1 );
		}

		/**
		 * Cash on delivery for this package, zero unless the customer is paying
		 * cash on delivery.
		 *
		 * @param array $package Package.
		 * @return float
		 */
		private function cod_amount( array $package ): float {
			$chosen = WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';

			if ( 'cod' !== $chosen ) {
				return 0.0;
			}

			$total = (float) ( $package['contents_cost'] ?? 0 );

			return round( $total, 2 );
		}

		/**
		 * Declared value, per the insurance setting.
		 *
		 * @param array $package Package.
		 * @return float
		 */
		private function declared_value( array $package ): float {
			$mode = (string) $this->get_option( 'insure', 'no' );

			if ( 'no' === $mode ) {
				return 0.0;
			}

			$total = (float) ( $package['contents_cost'] ?? 0 );

			if ( 'threshold' === $mode && $total < (float) $this->get_option( 'insure_from', 300 ) ) {
				return 0.0;
			}

			return round( $total, 2 );
		}

		/**
		 * Rate id for a delivery type.
		 *
		 * @param string $type Delivery type.
		 * @return string
		 */
		private function rate_id_for( string $type ): string {
			return $this->id . ':' . $this->instance_id . ':' . $type;
		}

		/* -----------------------------------------------------------------
		 * Compatibility shims
		 *
		 * The admin screens, the waybill generator and the main plugin file
		 * still call these Speedy-era helpers. They keep the plugin loading
		 * while those files are rewritten in turn.
		 * -------------------------------------------------------------- */

		/**
		 * Sender block for the waybill generator.
		 *
		 * @param string $profile_key Unused for Sameday; the pickup point id is
		 *                            the sender.
		 * @return array
		 */
		public function pickup_sender_block( string $profile_key = '' ): array {
			unset( $profile_key );

			return array( 'pickupPoint' => (int) $this->get_option( 'pickup_point', 0 ) );
		}

		/**
		 * Pickup profiles. Sameday addresses its warehouses by id, so there is
		 * one profile: whichever pickup point is configured.
		 *
		 * @return array
		 */
		public function get_pickup_profiles(): array {
			return array(
				'default' => array(
					'key'          => 'default',
					'pickup_point' => (int) $this->get_option( 'pickup_point', 0 ),
				),
			);
		}

		/**
		 * First usable pickup location in a city.
		 *
		 * @param int    $city_id City id.
		 * @param string $type    'easybox' or 'pudo'.
		 * @return int
		 */
		public static function get_first_available_office( int $city_id, string $type = 'easybox' ): int {
			global $wpdb;

			$ooh_type = ( 'pudo' === $type ) ? self::OOH_PUDO : self::OOH_EASYBOX;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ooh_id FROM {$wpdb->prefix}drushfs_lockers
					 WHERE city_id = %d AND ooh_type = %d AND client_visible = 1 AND capacity_exceeded = 0
					 ORDER BY occupancy_level ASC LIMIT 1",
					$city_id,
					$ooh_type
				)
			);
		}

		/**
		 * Pickup locations for the checkout picker.
		 *
		 * @param string|null $term      Optional search term.
		 * @param bool        $only_easybox Restrict to easybox.
		 * @return array
		 */
		public static function get_points( ?string $term = null, bool $only_easybox = false ): array {
			global $wpdb;

			$sql    = "SELECT ooh_id, name, city, address, ooh_type, supported_payment, occupancy_level
			           FROM {$wpdb->prefix}drushfs_lockers
			           WHERE client_visible = 1 AND capacity_exceeded = 0";
			$params = array();

			if ( $only_easybox ) {
				$sql     .= ' AND ooh_type = %d';
				$params[] = self::OOH_EASYBOX;
			}

			if ( $term ) {
				$like     = '%' . $wpdb->esc_like( $term ) . '%';
				$sql     .= ' AND (name LIKE %s OR city LIKE %s OR address LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}

			$sql .= ' ORDER BY city ASC, name ASC LIMIT 200';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

			return (array) $rows;
		}
	}
}
