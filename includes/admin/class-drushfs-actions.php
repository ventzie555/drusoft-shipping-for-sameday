<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Drushfs_Actions {

	public static function init(): void {
		// AJAX Actions
		add_action( 'wp_ajax_drushfs_cancel_shipment', [ __CLASS__, 'cancel_shipment' ] );
		add_action( 'wp_ajax_drushfs_request_courier', [ __CLASS__, 'request_courier' ] );
		add_action( 'wp_ajax_drushfs_generate_waybill', [ __CLASS__, 'generate_waybill_ajax' ] );
		
		// Admin Post Action for PDF Printing (File Stream)
		add_action( 'admin_post_drushfs_print_waybill', [ __CLASS__, 'print_waybill' ] );
	}

	/**
	 * AJAX handler for manual waybill generation.
	 */
	public static function generate_waybill_ajax(): void {
		check_ajax_referer( 'drushfs_actions', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'drusoft-shipping-for-sameday' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'drusoft-shipping-for-sameday' ) );
		}

		$result = Drushfs_Waybill_Generator::instance()->generate_waybill( $order_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( __( 'Waybill generated successfully.', 'drusoft-shipping-for-sameday' ) );
	}

	/**
	 * Cancel a shipment via AJAX.
	 */
	public static function cancel_shipment(): void {
		check_ajax_referer( 'drushfs_actions', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'drusoft-shipping-for-sameday' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'drusoft-shipping-for-sameday' ) );
		}

		$order = wc_get_order( $order_id );
		$waybill_id = $order->get_meta( '_drushfs_waybill_id' );

		if ( ! $waybill_id ) {
			wp_send_json_error( __( 'No waybill found for this order.', 'drusoft-shipping-for-sameday' ) );
		}

		// Get credentials
		$credentials = self::get_credentials_for_order( $order );
		if ( ! $credentials ) {
			wp_send_json_error( __( 'API credentials not found.', 'drusoft-shipping-for-sameday' ) );
		}

		$payload = [
			'userName'   => $credentials['username'],
			'password'   => $credentials['password'],
			'shipmentId' => $waybill_id,
			'comment'    => __( 'Cancelled by admin', 'drusoft-shipping-for-sameday' ),
		];

		$response = wp_remote_post( 'https://api.speedy.bg/v1/shipment/cancel', [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$http_code = wp_remote_retrieve_response_code( $response );

		error_log( 'Drusoft Shipping for Speedy: Cancel API response (HTTP ' . $http_code . ') – ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( $http_code >= 400 || isset( $body['error'] ) ) {
			wp_send_json_error( $body['error']['message'] ?? __( 'API Error', 'drusoft-shipping-for-sameday' ) );
		}

		// Success: Clear meta
		$order->delete_meta_data( '_drushfs_waybill_id' );
		$order->delete_meta_data( '_drushfs_waybill_response' );
		$order->delete_meta_data( '_drushfs_courier_requested' );
		/* translators: %s: Speedy waybill ID */
		$order->add_order_note( sprintf( __( 'Speedy shipment %s cancelled.', 'drusoft-shipping-for-sameday' ), $waybill_id ) );
		$order->save();

		wp_send_json_success( __( 'Shipment cancelled successfully.', 'drusoft-shipping-for-sameday' ) );
	}

	/**
	 * Request a courier (Pickup) via AJAX.
	 */
	public static function request_courier(): void {
		check_ajax_referer( 'drushfs_actions', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'drusoft-shipping-for-sameday' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_send_json_error( __( 'Invalid order ID.', 'drusoft-shipping-for-sameday' ) );
		}

		$order = wc_get_order( $order_id );
		$waybill_id = $order->get_meta( '_drushfs_waybill_id' );

		if ( ! $waybill_id ) {
			wp_send_json_error( __( 'No waybill found for this order.', 'drusoft-shipping-for-sameday' ) );
		}

		$credentials = self::get_credentials_for_order( $order );
		if ( ! $credentials ) {
			wp_send_json_error( __( 'API credentials not found.', 'drusoft-shipping-for-sameday' ) );
		}

		// Calculate pickup time (Next day if after 16:00, otherwise today)
		// This logic mimics the old plugin
		$timezone = new DateTimeZone( 'Europe/Sofia' );
		$now = new DateTime( 'now', $timezone );
		
		if ( (int) $now->format( 'H' ) >= 16 ) {
			$now->modify( '+1 day' );
		}
		
		// Set pickup time from settings, fallback to 17:30
		$settings = self::get_settings_for_order( $order );
		$visit_end_time = ! empty( $settings['sender_time'] ) ? $settings['sender_time'] : '17:30';

		$payload = [
			'userName'               => $credentials['username'],
			'password'               => $credentials['password'],
			'explicitShipmentIdList' => [ $waybill_id ],
			'visitEndTime'           => $visit_end_time,
			'autoAdjustPickupDate'   => true,
		];

		$response = wp_remote_post( 'https://api.speedy.bg/v1/pickup', [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$http_code = wp_remote_retrieve_response_code( $response );

		error_log( 'Drusoft Shipping for Speedy: Pickup API response (HTTP ' . $http_code . ') – ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// The API returns error.context = 'consignments.consignment_is_ordered'
		// when the shipment was already requested for pickup (e.g. auto-requested
		// during waybill creation). Treat this as success, not an error.
		$error_context = $body['error']['context'] ?? '';
		$is_already_ordered = ( 'consignments.consignment_is_ordered' === $error_context );

		if ( $http_code >= 400 || ( isset( $body['error'] ) && ! $is_already_ordered ) ) {
			wp_send_json_error( $body['error']['message'] ?? __( 'API Error', 'drusoft-shipping-for-sameday' ) );
		}

		$order->update_meta_data( '_drushfs_courier_requested', 'yes' );
		$order->add_order_note( __( 'Courier requested successfully.', 'drusoft-shipping-for-sameday' ) );
		$order->save();
		wp_send_json_success( __( 'Courier requested successfully.', 'drusoft-shipping-for-sameday' ) );
	}

	/**
	 * Print Waybill (PDF Stream).
	 * Handles GET request from admin-post.php.
	 */
	public static function print_waybill(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'drusoft-shipping-for-sameday' ) );
		}

		check_admin_referer( 'drushfs_print_waybill' );

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		if ( ! $order_id ) {
			wp_die( esc_html__( 'Invalid order ID.', 'drusoft-shipping-for-sameday' ) );
		}

		$order = wc_get_order( $order_id );
		$waybill_id = $order->get_meta( '_drushfs_waybill_id' );

		if ( ! $waybill_id ) {
			wp_die( esc_html__( 'No waybill found.', 'drusoft-shipping-for-sameday' ) );
		}

		$credentials = self::get_credentials_for_order( $order );
		if ( ! $credentials ) {
			wp_die( esc_html__( 'API credentials not found.', 'drusoft-shipping-for-sameday' ) );
		}

		// Determine paper size from settings: label printer = A6, regular = A4
		$settings   = self::get_settings_for_order( $order );
		$paper_size = ( ! empty( $settings['printer'] ) && 'YES' === $settings['printer'] ) ? 'A6' : 'A4';

		// Determine if additional copy is needed
		$additional_copy = ( ! empty( $settings['additionalcopy'] ) && 'YES' === $settings['additionalcopy'] );

		$payload = [
			'userName'  => $credentials['username'],
			'password'  => $credentials['password'],
			'paperSize' => $paper_size,
			'parcels'   => [
				[ 'parcel' => [ 'id' => $waybill_id ] ]
			],
		];

		if ( $additional_copy ) {
			$payload['additionalBarcode'] = true;
		}

		$response = wp_remote_post( 'https://api.speedy.bg/v1/print', [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
			'stream'  => false, // We need the body to output it
		] );

		if ( is_wp_error( $response ) ) {
			wp_die( esc_html( $response->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		// Check if the response is JSON (error) instead of PDF.
		// Check both content-type header and body content, as Speedy
		// may not always set the correct content-type.
		$is_json = ( strpos( $content_type, 'application/json' ) !== false )
		           || ( isset( $body[0] ) && $body[0] === '{' );

		if ( $is_json ) {
			$json = json_decode( $body, true );

			// Top-level error
			if ( ! empty( $json['error']['message'] ) ) {
				wp_die( esc_html( $json['error']['message'] ), esc_html__( 'Speedy Print Error', 'drusoft-shipping-for-sameday' ) );
			}

			// Parcel-level error (e.g. shipment cancelled/expired)
			if ( ! empty( $json['parcels'][0]['error']['message'] ) ) {
				wp_die( esc_html( $json['parcels'][0]['error']['message'] ), esc_html__( 'Speedy Print Error', 'drusoft-shipping-for-sameday' ) );
			}

			wp_die( esc_html__( 'Unknown error from Speedy print API.', 'drusoft-shipping-for-sameday' ) );
		}

		// Output PDF
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="speedy-waybill-' . sanitize_file_name( $waybill_id ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $body ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF data cannot be escaped.
		echo $body;
		exit;
	}

	/**
	 * Helper: Get credentials for a specific order.
	 * Tries to find the shipping method instance used for the order.
	 */
	private static function get_credentials_for_order( $order ): ?array {
		$settings = self::get_settings_for_order( $order );
		if ( $settings && ! empty( $settings['speedy_username'] ) && ! empty( $settings['speedy_password'] ) ) {
			return [
				'username' => $settings['speedy_username'],
				'password' => $settings['speedy_password'],
			];
		}

		// Fallback: Use the first available credentials found in DB
		return drushfs_get_first_credentials();
	}

	/**
	 * Helper: Get the full instance settings for a specific order.
	 */
	private static function get_settings_for_order( $order ): ?array {
		foreach ( $order->get_shipping_methods() as $shipping_method ) {
			if ( 'drushfs_sameday' === $shipping_method->get_method_id() ) {
				$instance_id = $shipping_method->get_instance_id();
				$settings = get_option( 'woocommerce_drushfs_sameday_' . $instance_id . '_settings' );
				if ( is_array( $settings ) ) {
					return $settings;
				}
			}
		}
		return null;
	}
}

Drushfs_Actions::init();
