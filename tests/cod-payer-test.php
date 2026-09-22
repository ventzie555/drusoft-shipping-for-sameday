<?php
/**
 * Can the si-brand.eu COD double-charge happen under Sameday?
 * Real generator, real WC orders, HTTP intercepted — nothing reaches Sameday.
 * Usage: wp eval-file tests/sameday-cod-payer-test.php
 */
$INSTANCE = null;
foreach ( wp_load_alloptions() as $k => $v ) {
	if ( preg_match( '/^woocommerce_drushfs_sameday_(\d+)_settings$/', $k, $m ) ) { $INSTANCE = (int) $m[1]; break; }
}
if ( ! $INSTANCE ) { echo "no Sameday instance configured\n"; return; }

$captured = [];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured ) {
	if ( false === strpos( $url, 'sameday.ro' ) && false === strpos( $url, 'sameday.bg' ) ) { return $pre; }
	if ( str_contains( $url, '/api/authenticate' ) ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'token' => 'TESTTOKEN', 'expire_at' => gmdate( 'c', time() + 3600 ) ] ) ];
	}
	if ( str_contains( $url, '/api/awb' ) ) {
		$body = $args['body'] ?? '';
		parse_str( is_string( $body ) ? $body : '', $parsed );
		$captured[] = $parsed;
		return [ 'response' => [ 'code' => 201 ], 'body' => wp_json_encode( [
			'awbNumber' => '9999000111', 'awbCost' => 4.00,
			'parcels' => [ [ 'position' => 1, 'awbNumber' => '9999000111' ] ] ] ) ];
	}
	return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
}, 10, 3 );

$pid = wc_get_product_id_by_sku( 'COD-TEST-2999' );
if ( ! $pid ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'COD test item' ); $p->set_sku( 'COD-TEST-2999' ); $p->set_regular_price( '29.99' ); $p->set_weight( '0.5' );
	$pid = $p->save();
}

function mk( int $pid, int $instance, string $payment, float $shipping, int $qty = 1 ): WC_Order {
	$o = wc_create_order();
	$o->add_product( wc_get_product( $pid ), $qty );
	$i = new WC_Order_Item_Shipping();
	$i->set_method_title( 'Доставка със Sameday' ); $i->set_method_id( 'drushfs_sameday' );
	$i->set_instance_id( $instance ); $i->set_total( (string) $shipping );
	$o->add_item( $i );
	$o->set_payment_method( $payment );
	$o->set_address( [ 'first_name' => 'Тест', 'last_name' => 'Клиент', 'phone' => '0888123456', 'email' => 'test@example.com', 'city' => 'София', 'postcode' => '1000', 'country' => 'BG', 'address_1' => 'ул. Тестова 1' ], 'billing' );
	$o->set_address( [ 'first_name' => 'Тест', 'last_name' => 'Клиент', 'city' => 'София', 'postcode' => '1000', 'country' => 'BG', 'address_1' => 'ул. Тестова 1' ], 'shipping' );
	$o->update_meta_data( '_drushfs_delivery_type', 'office' );
	$o->update_meta_data( '_drushfs_locker_id', '57' );
	$o->update_meta_data( '_drushfs_city_id', '6' );
	$o->calculate_totals( false );
	$o->set_status( 'processing' );
	$o->save();
	return $o;
}

function run( string $name, array $cfg, array &$captured, int $pid, int $instance, array $expect ): bool {
	$captured = [];
	$o   = mk( $pid, $instance, $cfg['payment'] ?? 'cod', $cfg['shipping'], $cfg['qty'] ?? 1 );
	$res = Drushfs_Waybill_Generator::instance()->generate_waybill( $o->get_id() );
	$sent = $captured[0] ?? [];
	$payer = $sent['awbPayment'] ?? '(none)';
	$cod   = array_key_exists( 'cashOnDelivery', $sent ) ? (float) $sent['cashOnDelivery'] : null;
	$total = (float) $o->get_total();
	// awbPayment can only be 1 = the contract holder pays, so the recipient is
	// never billed the courier fee: the customer pays exactly the COD.
	$customer_pays = null === $cod ? 0.0 : $cod;

	$ok = true; $why = [];
	if ( is_wp_error( $res ) ) { $ok = false; $why[] = 'generator error: ' . $res->get_error_message(); }
	if ( (string) $payer !== '1' ) { $ok = false; $why[] = "awbPayment=$payer, must always be 1"; }
	if ( null === $expect['cod'] ) {
		if ( null !== $cod && $cod > 0.005 ) { $ok = false; $why[] = "unexpected COD $cod"; }
	} elseif ( null === $cod || abs( $cod - $expect['cod'] ) > 0.005 ) {
		$ok = false; $why[] = 'COD ' . var_export( $cod, true ) . ", expected {$expect['cod']}";
	}
	if ( isset( $expect['customer_pays'] ) && abs( $customer_pays - $expect['customer_pays'] ) > 0.005 ) {
		$ok = false; $why[] = sprintf( 'customer pays %.2f, expected %.2f', $customer_pays, $expect['customer_pays'] );
	}
	printf( "%s  %-50s total %6.2f  awbPayment %-4s COD %-7s → customer pays %.2f\n",
		$ok ? 'PASS' : 'FAIL', $name, $total, (string) $payer, null === $cod ? 'none' : number_format( $cod, 2 ), $customer_pays );
	foreach ( $why as $w ) { echo "        ! $w\n"; }
	$o->delete( true );
	return $ok;
}

$all = true;
$all &= run( 'si-brand shape: COD with shipping charged', [ 'shipping' => 5.11 ], $captured, $pid, $INSTANCE,
	[ 'cod' => 35.10, 'customer_pays' => 35.10 ] );
$all &= run( 'COD, free shipping', [ 'shipping' => 0 ], $captured, $pid, $INSTANCE,
	[ 'cod' => 29.99, 'customer_pays' => 29.99 ] );
$all &= run( 'card payment: no COD at all', [ 'payment' => 'revolut_cc', 'shipping' => 5.11 ], $captured, $pid, $INSTANCE,
	[ 'cod' => null, 'customer_pays' => 0.0 ] );
$all &= run( '3 units + shipping', [ 'qty' => 3, 'shipping' => 5.11 ], $captured, $pid, $INSTANCE,
	[ 'cod' => 95.08, 'customer_pays' => 95.08 ] );
echo $all ? "\nALL PASS — COD always equals the order total; awbPayment is always 1\n" : "\nSOME FAILED\n";
