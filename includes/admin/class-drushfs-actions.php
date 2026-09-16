<?php
/**
 * Order screen actions: create a waybill, cancel it, print the label.
 *
 * There is no "request a courier" action, unlike the Speedy plugin this was
 * forked from: Sameday's client API has no courier-request endpoint. Collection
 * is arranged by the pickup point on the waybill, or the parcel is dropped into
 * an easybox.
 *
 * @package Drusoft_Shipping_For_Sameday
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin actions.
 */
class Drushfs_Actions {

	/**
	 * Register the handlers.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_drushfs_generate_waybill', array( __CLASS__, 'generate_waybill_ajax' ) );
		add_action( 'wp_ajax_drushfs_cancel_shipment', array( __CLASS__, 'cancel_shipment' ) );
		add_action( 'admin_post_drushfs_print_waybill', array( __CLASS__, 'print_waybill' ) );
	}

	/**
	 * Create a waybill for an order.
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

		wp_send_json_success(
			sprintf(
				/* translators: %s: waybill number */
				__( 'Waybill %s created.', 'drusoft-shipping-for-sameday' ),
				$result
			)
		);
	}

	/**
	 * Cancel the waybill of an order.
	 *
	 * Sameday answers 204 with an empty body, so success is the absence of an
	 * error rather than anything in the response.
	 */
	public static function cancel_shipment(): void {
		check_ajax_referer( 'drushfs_actions', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'drusoft-shipping-for-sameday' ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_send_json_error( __( 'Invalid order ID.', 'drusoft-shipping-for-sameday' ) );
		}

		$awb = (string) $order->get_meta( '_drushfs_waybill_id' );
		if ( '' === $awb ) {
			wp_send_json_error( __( 'No waybill found for this order.', 'drusoft-shipping-for-sameday' ) );
		}

		$creds = Drushfs_Waybill_Generator::credentials_for_order( $order );
		if ( ! $creds ) {
			wp_send_json_error( __( 'Sameday credentials are not configured.', 'drusoft-shipping-for-sameday' ) );
		}

		$response = Drushfs_Api::cancel_awb( $creds, $awb );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$order->delete_meta_data( '_drushfs_waybill_id' );
		$order->delete_meta_data( '_drushfs_waybill_response' );
		$order->add_order_note(
			sprintf(
				/* translators: %s: waybill number */
				__( 'Sameday waybill %s cancelled.', 'drusoft-shipping-for-sameday' ),
				$awb
			)
		);
		$order->save();

		wp_send_json_success( __( 'Shipment cancelled.', 'drusoft-shipping-for-sameday' ) );
	}

	/**
	 * Stream the label PDF.
	 *
	 * The label lives at /api/awb/download/{awb}. The four-segment form the PDF
	 * documentation gives (/download/{awb}/A4/PDF/inline) answers 400, so paper
	 * size is not ours to choose here.
	 */
	public static function print_waybill(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'drusoft-shipping-for-sameday' ) );
		}

		check_admin_referer( 'drushfs_print_waybill' );

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			wp_die( esc_html__( 'Invalid order ID.', 'drusoft-shipping-for-sameday' ) );
		}

		$awb = (string) $order->get_meta( '_drushfs_waybill_id' );
		if ( '' === $awb ) {
			wp_die( esc_html__( 'No waybill found.', 'drusoft-shipping-for-sameday' ) );
		}

		$creds = Drushfs_Waybill_Generator::credentials_for_order( $order );
		if ( ! $creds ) {
			wp_die( esc_html__( 'Sameday credentials are not configured.', 'drusoft-shipping-for-sameday' ) );
		}

		$pdf = Drushfs_Api::label( $creds, $awb );

		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ), esc_html__( 'Sameday label error', 'drusoft-shipping-for-sameday' ) );
		}

		if ( ! is_string( $pdf ) || 0 !== strpos( $pdf, '%PDF' ) ) {
			wp_die( esc_html__( 'Sameday did not return a PDF label.', 'drusoft-shipping-for-sameday' ) );
		}

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="sameday-' . sanitize_file_name( $awb ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF.
		echo $pdf;
		exit;
	}
}

Drushfs_Actions::init();
