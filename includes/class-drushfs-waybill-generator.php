<?php
/**
 * Creates Sameday waybills for orders.
 *
 * The payload is built from the ORDER, not from the quote taken at checkout.
 * That is deliberate and was learned the expensive way on the Speedy side: a
 * quote-time payload carries the cart subtotal before VAT and before shipping,
 * so order 15961 shipped a 172.81 € order with a 141.66 € cash-on-delivery —
 * quietly undercollecting more than the order's whole margin. At waybill time
 * the order total is one call away, so it is imposed here.
 *
 * Sameday cannot repeat the other Speedy trap — billing the recipient for the
 * courier while the customer had already paid for delivery at checkout —
 * because awbPayment accepts only 1, "the client with the contract pays".
 *
 * @package Drusoft_Shipping_For_Sameday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Drushfs_Waybill_Generator' ) ) {

	/**
	 * Order to waybill.
	 */
	class Drushfs_Waybill_Generator {

		/** Singleton. */
		protected static $instance = null;

		/**
		 * Instance.
		 *
		 * @return Drushfs_Waybill_Generator|null
		 */
		public static function instance(): ?Drushfs_Waybill_Generator {
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
		}

		/**
		 * Generate automatically when the shop asked for it.
		 *
		 * @param int      $order_id    Order id.
		 * @param string   $status_from Previous status.
		 * @param string   $status_to   New status.
		 * @param WC_Order $order       Order.
		 */
		public function on_order_status_changed( int $order_id, string $status_from, string $status_to, WC_Order $order ): void {
			unset( $status_from );

			if ( ! self::shipping_method_of( $order ) ) {
				return;
			}

			$settings = self::settings_for_order( $order );

			if ( 'yes' !== ( $settings['generate_waybill'] ?? 'no' ) ) {
				return;
			}

			if ( ! in_array( $status_to, array( 'processing', 'on-hold' ), true ) ) {
				return;
			}

			$this->generate_waybill( $order_id );
		}

		/**
		 * Create the waybill.
		 *
		 * @param int $order_id Order id.
		 * @return string|WP_Error Waybill number, or an error.
		 */
		public function generate_waybill( int $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return new WP_Error( 'invalid_order', __( 'Invalid order ID.', 'drusoft-shipping-for-sameday' ) );
			}

			$existing = (string) $order->get_meta( '_drushfs_waybill_id' );
			if ( '' !== $existing ) {
				return $existing;
			}

			$creds = self::credentials_for_order( $order );
			if ( ! $creds ) {
				return new WP_Error( 'no_credentials', __( 'Sameday credentials are not configured.', 'drusoft-shipping-for-sameday' ) );
			}

			$payload = $this->build_payload( $order, $creds );
			if ( is_wp_error( $payload ) ) {
				$order->add_order_note( __( 'Sameday waybill error: ', 'drusoft-shipping-for-sameday' ) . $payload->get_error_message() );
				return $payload;
			}

			$response = Drushfs_Api::create_awb( $creds, $payload );

			if ( is_wp_error( $response ) ) {
				$order->add_order_note( __( 'Sameday waybill error: ', 'drusoft-shipping-for-sameday' ) . $response->get_error_message() );
				return $response;
			}

			$awb = (string) ( $response['awbNumber'] ?? '' );
			if ( '' === $awb ) {
				return new WP_Error( 'unexpected_response', __( 'Sameday did not return a waybill number.', 'drusoft-shipping-for-sameday' ) );
			}

			$order->update_meta_data( '_drushfs_waybill_id', $awb );
			$order->update_meta_data( '_drushfs_waybill_response', $response );
			$order->add_order_note(
				sprintf(
					/* translators: 1: waybill number, 2: cost charged by Sameday */
					__( 'Sameday waybill created: %1$s (%2$s).', 'drusoft-shipping-for-sameday' ),
					$awb,
					// The demo environment bills in Romanian lei with test
					// tariffs and names no currency; never dress that as euro.
					( 'live' === ( $creds['sameday_env'] ?? 'demo' ) )
						? wc_price( (float) ( $response['awbCost'] ?? 0 ) )
						: number_format_i18n( (float) ( $response['awbCost'] ?? 0 ), 2 ) . ' — demo'
				)
			);
			$order->save();

			return $awb;
		}

		/**
		 * Build the shipment payload from the order.
		 *
		 * @param WC_Order $order Order.
		 * @return array|WP_Error
		 */
		private function build_payload( WC_Order $order, array $creds ) {
			$settings = self::settings_for_order( $order );

			$pickup = (int) ( $settings['pickup_point'] ?? 0 );
			if ( ! $pickup ) {
				return new WP_Error( 'no_pickup_point', __( 'No Sameday pickup point is configured.', 'drusoft-shipping-for-sameday' ) );
			}

			$type = $this->delivery_type_of( $order );

			$service = Drushfs_Api::service_for_type( $creds, $type );

			$city   = $order->get_shipping_city() ?: $order->get_billing_city();
			$county = $this->county_for_city( $city, $order->get_shipping_state() ?: $order->get_billing_state() );

			// An order placed through the city list carries the exact city.
			$city_id = (int) ( $order->get_meta( '_drushfs_shipping_city_id' ) ?: $order->get_meta( '_drushfs_billing_city_id' ) );
			$row     = $city_id ? drushfs_city_row( $city_id ) : null;
			if ( $row ) {
				$city   = (string) $row['name'];
				$county = (string) $row['county'];
			}

			$address = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
			if ( '' === $address ) {
				$address = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
			}

			// Sameday refuses a locker shipment whose recipient has no e-mail,
			// and buries the reason deep in its validation tree.
			$email = $order->get_billing_email();
			if ( '' === $email ) {
				$email = get_option( 'admin_email' );
			}

			$weight = $this->order_weight( $order, (float) ( $settings['default_weight'] ?? 1 ) );

			$payload = array(
				'pickupPoint'             => $pickup,
				'packageType'             => 0,
				'packageNumber'           => 1,
				'packageWeight'           => $weight,
				'service'                 => $service,
				'awbPayment'              => 1,
				'cashOnDelivery'          => $this->cod_amount( $order ),
				'insuredValue'            => $this->declared_value( $order, $settings ),
				'thirdPartyPickup'        => 0,
				'clientInternalReference' => (string) $order->get_order_number(),
				'awbRecipient'            => array(
					'name'         => $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name(),
					'phoneNumber'  => $order->get_billing_phone(),
					'email'        => $email,
					'personType'   => 0,
					'cityString'   => $city,
					'countyString' => $county,
					'address'      => $address ?: '-',
					'postalCode'   => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
				),
				'parcels'                 => array(
					array( 'weight' => $weight ),
				),
			);

			if ( in_array( $type, array( 'easybox', 'pudo' ), true ) ) {
				$ooh = (int) $order->get_meta( '_drushfs_office_id' );
				if ( ! $ooh ) {
					return new WP_Error(
						'no_locker',
						__( 'This order is set for locker delivery but carries no locker choice.', 'drusoft-shipping-for-sameday' )
					);
				}
				$payload['oohLastMile'] = $ooh;
			}

			$contents = array();
			foreach ( $order->get_items() as $item ) {
				$contents[] = $item->get_name() . ' x' . $item->get_quantity();
			}
			if ( $contents ) {
				$payload['packageDetails'] = mb_substr( implode( ', ', $contents ), 0, 100 );
			}

			return $payload;
		}

		/**
		 * Cash on delivery: the order total, or zero when the customer is not
		 * paying cash on delivery.
		 *
		 * @param WC_Order $order Order.
		 * @return float
		 */
		private function cod_amount( WC_Order $order ): float {
			if ( 'cod' !== $order->get_payment_method() ) {
				return 0.0;
			}

			return round( (float) $order->get_total(), 2 );
		}

		/**
		 * Declared value per the shop's insurance setting.
		 *
		 * @param WC_Order $order    Order.
		 * @param array    $settings Method settings.
		 * @return float
		 */
		private function declared_value( WC_Order $order, array $settings ): float {
			$mode = (string) ( $settings['insure'] ?? 'no' );

			if ( 'no' === $mode ) {
				return 0.0;
			}

			$total = (float) $order->get_total();

			if ( 'threshold' === $mode && $total < (float) ( $settings['insure_from'] ?? 300 ) ) {
				return 0.0;
			}

			return round( $total, 2 );
		}

		/**
		 * Total weight of the order.
		 *
		 * @param WC_Order $order   Order.
		 * @param float    $default Default per item when a product has no weight.
		 * @return float
		 */
		private function order_weight( WC_Order $order, float $default ): float {
			$weight = 0.0;

			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();
				$each    = $product ? (float) $product->get_weight() : 0.0;
				$weight += ( $each > 0 ? $each : $default ) * (int) $item->get_quantity();
			}

			return $weight > 0 ? $weight : max( 0.1, $default );
		}

		/**
		 * Which delivery type the customer chose.
		 *
		 * Prefers the meta written at checkout, and falls back to the rate id,
		 * which ends in the type (drushfs_sameday:3:easybox).
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		private function delivery_type_of( WC_Order $order ): string {
			$type = (string) $order->get_meta( '_drushfs_delivery_type' );

			if ( in_array( $type, array( 'address', 'easybox', 'pudo' ), true ) ) {
				return $type;
			}

			$method = self::shipping_method_of( $order );
			if ( $method ) {
				$parts = explode( ':', (string) $method->get_method_id() . ':' . (string) $method->get_instance_id() );
				$rate  = (string) $method->get_meta( 'delivery_type' );
				if ( in_array( $rate, array( 'address', 'easybox', 'pudo' ), true ) ) {
					return $rate;
				}
				unset( $parts );
			}

			return 'address';
		}

		/**
		 * County for a city name.
		 *
		 * @param string $city  City.
		 * @param string $state Fallback state code.
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

		/* -----------------------------------------------------------------
		 * Shared helpers, also used by the admin actions
		 * -------------------------------------------------------------- */

		/**
		 * The Sameday shipping line on an order, if any.
		 *
		 * @param WC_Order $order Order.
		 * @return WC_Order_Item_Shipping|null
		 */
		public static function shipping_method_of( WC_Order $order ) {
			foreach ( $order->get_shipping_methods() as $method ) {
				if ( 'drushfs_sameday' === $method->get_method_id() ) {
					return $method;
				}
			}

			return null;
		}

		/**
		 * Settings of the instance that shipped this order, falling back to the
		 * method's global settings.
		 *
		 * @param WC_Order $order Order.
		 * @return array
		 */
		public static function settings_for_order( WC_Order $order ): array {
			$method = self::shipping_method_of( $order );

			if ( $method ) {
				$instance = get_option( 'woocommerce_drushfs_sameday_' . $method->get_instance_id() . '_settings' );
				if ( is_array( $instance ) && $instance ) {
					return $instance;
				}
			}

			$global = get_option( 'woocommerce_drushfs_sameday_settings' );

			return is_array( $global ) ? $global : array();
		}

		/**
		 * Credentials to use for this order.
		 *
		 * @param WC_Order $order Order.
		 * @return array|null
		 */
		public static function credentials_for_order( WC_Order $order ): ?array {
			$settings = self::settings_for_order( $order );

			if ( ! empty( $settings['sameday_username'] ) && ! empty( $settings['sameday_password'] ) ) {
				return array(
					'sameday_username' => $settings['sameday_username'],
					'sameday_password' => $settings['sameday_password'],
					'sameday_env'      => $settings['sameday_env'] ?? 'demo',
				);
			}

			$creds = Drushfs_Syncer::credentials();

			return $creds ?: null;
		}
	}
}

Drushfs_Waybill_Generator::instance();
