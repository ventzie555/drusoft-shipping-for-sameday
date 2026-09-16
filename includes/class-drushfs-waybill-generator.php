<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'Drushfs_Waybill_Generator' ) ) {

	class Drushfs_Waybill_Generator {

		/**
		 * The single instance of the class.
		 */
		protected static $_instance = null;

		/**
		 * Main Drushfs_Waybill_Generator Instance.
		 */
		public static function instance(): ?Drushfs_Waybill_Generator {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		/**
		 * Constructor.
		 */
		public function __construct() {
			// This hook will trigger waybill generation
			add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );
		}

		/**
		 * Hook that fires when an order's status changes.
		 *
		 * @param int      $order_id    Order ID.
		 * @param string   $status_from Old status.
		 * @param string   $status_to   New status.
		 * @param WC_Order $order       Order object.
		 */
		public function on_order_status_changed( int $order_id, string $status_from, string $status_to, WC_Order $order ): void {
			// Get the shipping method instance settings
			$shipping_methods = $order->get_shipping_methods();
			$shipping_method  = reset( $shipping_methods ); // Get the first shipping method

			if ( ! $shipping_method || 'drushfs_sameday' !== $shipping_method->get_method_id() ) {
				return;
			}

			$instance_id = $shipping_method->get_instance_id();
			$settings    = get_option( 'woocommerce_drushfs_sameday_' . $instance_id . '_settings' );

			// Trigger on 'processing' or 'on-hold' if auto-generation is enabled
			$should_generate = ( 'yes' === ( $settings['generate_waybill'] ?? 'no' ) );
			$is_target_status = in_array( $status_to, [ 'processing', 'on-hold' ], true );

			if ( $should_generate && $is_target_status ) {
				$this->generate_waybill( $order_id );
			}
		}

		/**
		 * Generate the waybill for a given order.
		 *
		 * @param int $order_id The ID of the order.
		 * @return string|WP_Error The waybill ID on success, or a WP_Error on failure.
		 */
		public function generate_waybill( int $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return new WP_Error( 'invalid_order', __( 'Invalid order ID.', 'drusoft-shipping-for-sameday' ) );
			}

			// Prevent re-generation if a waybill already exists
			if ( $order->get_meta( '_drushfs_waybill_id' ) ) {
				return $order->get_meta( '_drushfs_waybill_id' );
			}

			// Retrieve the payload saved during checkout
			$payload = $order->get_meta( '_drushfs_order_data' );
			if ( empty( $payload ) ) {
				return new WP_Error( 'no_payload', __( 'No Speedy shipping data found for this order.', 'drusoft-shipping-for-sameday' ) );
			}

			// Get credentials from the specific shipping instance
			$shipping_methods = $order->get_shipping_methods();
			$shipping_method  = reset( $shipping_methods );
			$instance_id      = $shipping_method->get_instance_id();
			$settings         = get_option( 'woocommerce_drushfs_sameday_' . $instance_id . '_settings' );

			$username = $settings['speedy_username'] ?? '';
			$password = $settings['speedy_password'] ?? '';

			if ( ! $username || ! $password ) {
				return new WP_Error( 'no_credentials', __( 'Speedy credentials are not configured for this shipping method.', 'drusoft-shipping-for-sameday' ) );
			}

			// --- Finalize the Payload ---
			$payload['userName'] = $username;
			$payload['password'] = $password;

			// Remove internal tracking keys (not part of the API)
			unset( $payload['_selected_service_id'] );

			// Split shipments: secondary parcels embedded at quote time.
			$split_parcels = $payload['_split_parcels'] ?? [];
			unset( $payload['_split_parcels'] );

			// Apply the order's pickup profile (may have been changed by the
			// admin after checkout) — rebuild the sender block from it.
			$pickup_profile = (string) $order->get_meta( '_drushfs_pickup_profile' );
			if ( '' !== $pickup_profile && class_exists( 'Drushfs_Shipping_Method' ) ) {
				$method = new Drushfs_Shipping_Method( $instance_id );
				$sender = $method->pickup_sender_block( $pickup_profile );
				if ( ! empty( $sender ) ) {
					$payload['sender'] = $sender;
				}
			}

			// COD must be what the courier actually collects: the ORDER TOTAL.
			// The saved payload was built during calculate_shipping(), where the
			// cart's grand total does not exist yet, so it carries the items
			// subtotal EX VAT — on order 15961 that shipped a 172.81 € COD as
			// 141.66 € (VAT and shipping missing), quietly undercollecting more
			// than the order's entire margin. At waybill time the truth is one
			// call away, so impose it here instead of trusting the quote-time
			// approximation. includeShippingPrice=true tells Speedy to add the
			// courier price itself, so in that mode our shipping charge must
			// stay out of the amount.
			// A COD order whose payload carries no COD service at all is the
			// worse variant of the same staleness: the quote was built while a
			// CARD method was selected (the checkout default) and the customer
			// switched to наложен платеж at the end. The rebuild-on-payment-
			// change trigger keys off the payer flipping RECIPIENT<->SENDER, so
			// any configuration in which both methods resolve to the same payer
			// suppresses it — order 16334 (171.99 €) got a waybill with no COD
			// whatsoever, which the courier would have delivered for free.
			// The order object is the truth: it says COD, the waybill says COD.
			if ( 'cod' === $order->get_payment_method()
				&& ! isset( $payload['service']['additionalServices']['cod'] ) ) {
				$payload['service']['additionalServices']['cod'] = [
					'processingType'        => ( 'YES' === ( $settings['moneytransfer'] ?? 'NO' ) )
						? 'POSTAL_MONEY_TRANSFER' : 'CASH',
					'ignoreIfNotApplicable' => true,
				];
			}

			if ( isset( $payload['service']['additionalServices']['cod'] ) ) {
				$cod_total = (float) $order->get_total();
				if ( ! empty( $payload['service']['additionalServices']['cod']['includeShippingPrice'] ) ) {
					$cod_total -= (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
				}
				$payload['service']['additionalServices']['cod']['amount'] = round( $cod_total, 2 );
			}

			// Who pays the courier — settled here for the same reason as the COD
			// amount above: the quote-time guess cannot see what the cart ended
			// up charging for delivery.
			//
			// Billing the recipient is only honest when the order charged them
			// nothing for shipping. Any shipping line means the customer has
			// already paid us for delivery, and handing Speedy's fee to them as
			// well makes them pay for it twice — once at checkout, once at the
			// counter, with no warning on either side.
			//
			// That is not hypothetical. Order 15961 totalled 172.81 € including
			// 2.82 € of shipping; Speedy then billed the customer a further
			// 4.48 € on collection and mailed him a demand for 177.29 €. He
			// queried it. Two earlier customers had already paid the surcharge
			// and said nothing.
			if ( (float) $order->get_shipping_total() > 0
				&& 'RECIPIENT' === ( $payload['payment']['courierServicePayer'] ?? '' ) ) {
				$payload['payment']['courierServicePayer'] = 'SENDER';
			}

			// With the merchant paying the courier, COD must be the plain order
			// total and nothing else. includeShippingPrice asks Speedy to add
			// ITS OWN courier price to the collected amount — meaningful only
			// when the recipient pays the courier. Sent together with SENDER,
			// Speedy treats the service as not applicable, and because the
			// payload carries ignoreIfNotApplicable=true it DROPS COD silently
			// rather than erroring: waybill 63707943299 (order 16334, 171.99 €)
			// went out with no naложен платеж at all — the courier would have
			// handed over the parcel without collecting a стотинка. Caught
			// before pickup; this keeps the combination impossible.
			if ( 'SENDER' === ( $payload['payment']['courierServicePayer'] ?? '' )
				&& isset( $payload['service']['additionalServices']['cod'] ) ) {
				unset( $payload['service']['additionalServices']['cod']['includeShippingPrice'] );
				$payload['service']['additionalServices']['cod']['amount'] =
					round( (float) $order->get_total(), 2 );
			}

			// Convert calculate payload format to shipment format:
			// The calculate endpoint uses service.serviceIds (array),
			// but the shipment endpoint requires service.serviceId (single int).
			if ( isset( $payload['service']['serviceIds'] ) && is_array( $payload['service']['serviceIds'] ) ) {
				$payload['service']['serviceId'] = $payload['service']['serviceIds'][0];
				unset( $payload['service']['serviceIds'] );
			}

			// Inspection before payment (OBPD). Injected at waybill time — like the
			// COD amount above — so the merchant's current setting applies even to
			// orders checked out before the option existed. Only meaningful on COD
			// shipments, and impossible at automats (no staff to open with).
			$obpd = (string) ( $settings['obpd_option'] ?? 'NONE' );
			if ( in_array( $obpd, [ 'OPEN', 'TEST' ], true )
				&& isset( $payload['service']['additionalServices']['cod'] )
				&& 'automat' !== $order->get_meta( '_drushfs_delivery_type' ) ) {
				$payload['service']['additionalServices']['obpd'] = [
					'option'                  => $obpd,
					'returnShipmentServiceId' => (int) ( $payload['service']['serviceId'] ?? 505 ),
					'returnShipmentPayer'     => 'SENDER',
				];
			}

			// Add package type to content (only needed for shipment, not calculate)
			if ( ! isset( $payload['content']['package'] ) ) {
				$payload['content']['package'] = $settings['opakovka'] ?? 'BOX';
			}

			// Automats cannot accept pallets — fall back to BOX.
			if ( 'PALLET' === $payload['content']['package'] && isset( $payload['recipient']['pickupOfficeId'] ) ) {
				$delivery_type = $order->get_meta( '_drushfs_delivery_type' );
				if ( 'automat' === $delivery_type ) {
					$payload['content']['package'] = 'BOX';
				}
			}

			// Add contents description (required by /v1/shipment)
			if ( empty( $payload['content']['contents'] ) ) {
				$items = [];
				foreach ( $order->get_items() as $item ) {
					$items[] = $item->get_name() . ' x' . $item->get_quantity();
				}
				$description = implode( ', ', $items );
				if ( empty( $description ) ) {
					$description = __( 'Order #', 'drusoft-shipping-for-sameday' ) . $order->get_order_number();
				}
				// Speedy limits this field — truncate to 100 chars
				$payload['content']['contents'] = mb_substr( $description, 0, 100 );
			}

			// Add recipient details from the order
			$payload['recipient']['clientName']       = $order->get_formatted_shipping_full_name();
			$payload['recipient']['phone1']['number'] = $order->get_billing_phone();
			$payload['recipient']['email']            = $order->get_billing_email();

			// Add order reference
			$payload['ref1'] = __( 'Order #', 'drusoft-shipping-for-sameday' ) . $order->get_order_number();

			// If delivery is to address, use addressNote
			if ( isset( $payload['recipient']['addressLocation'] ) ) {
				$full_address = $order->get_shipping_address_1();
				if ( $order->get_shipping_address_2() ) {
					$full_address .= ', ' . $order->get_shipping_address_2();
				}
				$payload['recipient']['addressLocation']['addressNote'] = $full_address;
			}

			// --- Make the API Call ---

			$response = wp_remote_post( 'https://api.speedy.bg/v1/shipment/', [
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 20,
			] );

			if ( is_wp_error( $response ) ) {
				$order->add_order_note( __( 'Speedy Waybill Error: ', 'drusoft-shipping-for-sameday' ) . $response->get_error_message() );
				return $response;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			// --- Handle API Response ---
			if ( isset( $body['error'] ) ) {
				$error_message = $body['error']['message'] ?? __( 'Unknown API error', 'drusoft-shipping-for-sameday' );
				$order->add_order_note( __( 'Speedy Waybill Error: ', 'drusoft-shipping-for-sameday' ) . $error_message );
				return new WP_Error( 'api_error', $error_message );
			}

			if ( isset( $body['id'] ) ) {
				$waybill_id  = $body['id'];
				$waybill_ids = [ $waybill_id ];

				// Save the waybill ID and the full response to the order
				$order->update_meta_data( '_drushfs_waybill_id', $waybill_id );
				$order->update_meta_data( '_drushfs_waybill_response', $body );
				$order->add_order_note( __( 'Speedy Waybill Created: ', 'drusoft-shipping-for-sameday' ) . $waybill_id );

				// Secondary parcels (split shipments): same recipient and
				// service, own sender/contents/COD — one waybill each.
				$parcel_no = 1;
				foreach ( $split_parcels as $parcel ) {
					$parcel_no++;
					$parcel['userName'] = $username;
					$parcel['password'] = $password;
					// Secondary parcels carry their own quote-time payload, so the
					// courier-payer correction applied to the primary above has to
					// be repeated here — otherwise a split order would hand the
					// customer a counter charge on every parcel but the first.
					if ( (float) $order->get_shipping_total() > 0
						&& 'RECIPIENT' === ( $parcel['payment']['courierServicePayer'] ?? '' ) ) {
						$parcel['payment']['courierServicePayer'] = 'SENDER';
					}
					// Same COD sanitisation as the primary: includeShippingPrice
					// together with SENDER makes Speedy silently drop the whole
					// COD service (ignoreIfNotApplicable) — see order 16334.
					if ( 'SENDER' === ( $parcel['payment']['courierServicePayer'] ?? '' )
						&& isset( $parcel['service']['additionalServices']['cod'] ) ) {
						unset( $parcel['service']['additionalServices']['cod']['includeShippingPrice'] );
					}
					if ( isset( $parcel['service']['serviceIds'] ) && is_array( $parcel['service']['serviceIds'] ) ) {
						$parcel['service']['serviceId'] = $parcel['service']['serviceIds'][0];
						unset( $parcel['service']['serviceIds'] );
					}
					if ( ! isset( $parcel['content']['package'] ) ) {
						$parcel['content']['package'] = $settings['opakovka'] ?? 'BOX';
					}
					if ( 'PALLET' === $parcel['content']['package'] && isset( $parcel['recipient']['pickupOfficeId'] )
						&& 'automat' === $order->get_meta( '_drushfs_delivery_type' ) ) {
						$parcel['content']['package'] = 'BOX';
					}
					// Same inspection-before-payment rule as the primary parcel:
					// a customer must get identical treatment on every box of one order.
					if ( in_array( $obpd, [ 'OPEN', 'TEST' ], true )
						&& isset( $parcel['service']['additionalServices']['cod'] )
						&& 'automat' !== $order->get_meta( '_drushfs_delivery_type' ) ) {
						$parcel['service']['additionalServices']['obpd'] = [
							'option'                  => $obpd,
							'returnShipmentServiceId' => (int) ( $parcel['service']['serviceId'] ?? 505 ),
							'returnShipmentPayer'     => 'SENDER',
						];
					}
					$parcel['recipient']['clientName']       = $order->get_formatted_shipping_full_name();
					$parcel['recipient']['phone1']['number'] = $order->get_billing_phone();
					$parcel['recipient']['email']            = $order->get_billing_email();
					/* translators: 1: order number, 2: parcel number */
					$parcel['ref1'] = sprintf( __( 'Order #%1$s / parcel %2$d', 'drusoft-shipping-for-sameday' ), $order->get_order_number(), $parcel_no );
					if ( isset( $parcel['recipient']['addressLocation'] ) ) {
						$full_address = $order->get_shipping_address_1();
						if ( $order->get_shipping_address_2() ) {
							$full_address .= ', ' . $order->get_shipping_address_2();
						}
						$parcel['recipient']['addressLocation']['addressNote'] = $full_address;
					}

					$p_response = wp_remote_post( 'https://api.speedy.bg/v1/shipment/', [
						'headers' => [
							'Content-Type' => 'application/json',
							'Accept'       => 'application/json',
						],
						'body'    => wp_json_encode( $parcel ),
						'timeout' => 20,
					] );
					$p_body = is_wp_error( $p_response ) ? null : json_decode( wp_remote_retrieve_body( $p_response ), true );
					if ( is_wp_error( $p_response ) || ! empty( $p_body['error'] ) || empty( $p_body['id'] ) ) {
						$p_msg = is_wp_error( $p_response )
							? $p_response->get_error_message()
							: ( $p_body['error']['message'] ?? __( 'Unknown API error', 'drusoft-shipping-for-sameday' ) );
						/* translators: 1: parcel number, 2: error message */
						$order->add_order_note( sprintf( __( 'Speedy Waybill Error (parcel %1$d): %2$s — create it manually.', 'drusoft-shipping-for-sameday' ), $parcel_no, $p_msg ) );
						continue;
					}
					$waybill_ids[] = $p_body['id'];
					/* translators: 1: parcel number, 2: waybill id */
					$order->add_order_note( sprintf( __( 'Speedy Waybill Created (parcel %1$d): %2$s', 'drusoft-shipping-for-sameday' ), $parcel_no, $p_body['id'] ) );
				}
				if ( count( $waybill_ids ) > 1 ) {
					$order->update_meta_data( '_drushfs_waybill_ids', $waybill_ids );
				}
				$order->save();

				return $waybill_id;
			}

			return new WP_Error( 'unexpected_response', __( 'Unexpected response from Speedy API.', 'drusoft-shipping-for-sameday' ) );
		}
	}
}

// Initialize the generator
Drushfs_Waybill_Generator::instance();
