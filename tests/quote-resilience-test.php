<?php
// Can one bad input or one bad Sameday reply still ship an order for free?
// The Econt shape (trisestri.bg order 816, 30.09.2026): city София + typed
// postcode 2230 → the courier refused the quote, nothing was logged, nothing
// re-asked, shipping 0.00. Real cart, real session, real shipping method; only
// pre_http_request is intercepted, so nothing reaches Sameday.
// Usage: wp eval-file wp-content/plugins/drusoft-shipping-for-sameday/tests/quote-resilience-test.php
$INSTANCE = null;
foreach ( wp_load_alloptions() as $k => $v ) {
	if ( preg_match( '/^woocommerce_drushfs_sameday_(\d+)_settings$/', $k, $m ) ) { $INSTANCE = (int) $m[1]; break; }
}
if ( ! $INSTANCE ) { echo "no drushfs_sameday instance configured on this site\n"; return; }
if ( ! WC()->session ) { WC()->initialize_session(); }
global $wpdb;
$pid = wc_get_product_id_by_sku( 'COD-TEST-2999' );
if ( ! $pid ) { echo "product COD-TEST-2999 missing\n"; return; }
$sofia = $wpdb->get_row( "SELECT id, name, county, postal_code FROM {$wpdb->prefix}drushfs_cities WHERE name = 'София' LIMIT 1", ARRAY_A );
if ( ! $sofia ) { echo "drushfs_cities has no София — run the location sync first\n"; return; }
$no_lockers = $wpdb->get_row( "SELECT c.id, c.name FROM {$wpdb->prefix}drushfs_cities c LEFT JOIN {$wpdb->prefix}drushfs_lockers l ON l.city_id = c.id WHERE l.ooh_id IS NULL AND c.postal_code <> '' LIMIT 1", ARRAY_A );

$all   = true;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$all ) {
	$all &= $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  [$detail]" : '' );
};
$opt      = 'woocommerce_drushfs_sameday_' . $INSTANCE . '_settings';
$settings = get_option( $opt );
$saved    = $settings;
$clear_like = function ( array $patterns ) use ( $wpdb ) {
	// Through the API, not raw SQL: a transient read once stays in the request's object cache.
	$where = implode( ' OR ', array_map( fn( $p ) => "option_name LIKE '\\_transient\\_$p'", $patterns ) );
	foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE $where" ) as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
	}
	wp_cache_flush();
};
$clear_q           = fn() => $clear_like( [ 'drushfs\\_q\\_%', 'drushfs\\_token\\_%', 'drushfs\\_api\\_down\\_%', 'drushfs\\_no\\_pickup\\_%' ] );
$clear_quotes_only = fn() => $clear_like( [ 'drushfs\\_q\\_%' ] ); // keeps the "Sameday is down" pause
$quote_ttl         = function () use ( $wpdb ) { // seconds left on the newest cached quote
	$t = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_drushfs\\_q\\_%' ORDER BY option_id DESC LIMIT 1" );
	return $t ? (int) $t - time() : -1;
};
$clear_q();

// ── Sameday stand-in ──────────────────────────────────────────────────────
$captured = [];
$reply    = null; // array => JSON body (200); ['__code'=>400, ...] => refusal; WP_Error => transport failure
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured, &$reply ) {
	if ( false === strpos( $url, 'sameday' ) && false === strpos( $url, 'zitec' ) ) { return $pre; }
	if ( str_contains( $url, '/api/authenticate' ) ) {
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'token' => 'TESTTOKEN', 'expire_at' => gmdate( 'c', time() + 3600 ) ] ), 'headers' => [] ];
	}
	if ( str_contains( $url, '/api/awb/estimate-cost' ) || preg_match( '#/api/awb$#', $url ) ) {
		parse_str( is_string( $args['body'] ?? '' ) ? $args['body'] : '', $parsed );
		$captured[] = [ 'url' => $url, 'body' => $parsed, 'timeout' => $args['timeout'] ?? null ];
		if ( $reply instanceof WP_Error ) { return $reply; }
		$code = (int) ( $reply['__code'] ?? 200 );
		$body = $reply; unset( $body['__code'] );
		return [ 'response' => [ 'code' => $code ], 'body' => wp_json_encode( $body ), 'headers' => [] ];
	}
	// Anything else (services, pickup points…) answers empty so the code
	// falls back to what it already stored.
	return [ 'response' => [ 'code' => 200 ], 'body' => '[]', 'headers' => [] ];
}, 10, 3 );
$ok_reply = [ 'amount' => 5.42, 'currency' => 'EUR', 'time' => 1 ];
$logged   = [];
add_filter( 'woocommerce_logger_log_message', function ( $message, $level, $context ) use ( &$logged ) {
	if ( 'drusoft-shipping-for-sameday' === ( $context['source'] ?? '' ) ) { $logged[] = $level . ': ' . $message; }
	return $message;
}, 10, 3 );

WC()->cart->empty_cart();
WC()->cart->add_to_cart( $pid, 1 );
WC()->session->set( 'chosen_shipping_methods', [ 'drushfs_sameday:' . $INSTANCE ] );
WC()->session->set( 'chosen_payment_method', 'cod' );
WC()->session->set( 'drushfs_city_id', (int) $sofia['id'] );
WC()->session->set( 'drushfs_delivery_type', 'address' );
WC()->session->set( 'drushfs_office_id', 0 );

$method  = new Drushfs_Shipping_Method( $INSTANCE );
$package = function ( string $type = 'address', string $postcode = '2230', int $city_id = 0 ) use ( $sofia, $INSTANCE ) {
	$p = WC()->cart->get_shipping_packages()[0];
	$p['destination'] = array_merge( $p['destination'], [
		'country' => 'BG', 'state' => 'BG-22', 'city' => (string) ( $city_id ?: $sofia['id'] ),
		'postcode' => $postcode, // what the customer typed — Костинброд's code under София
		'address_1' => '', 'address_2' => '', 'first_name' => 'Test', 'last_name' => 'Quote',
	] );
	$p['sameday_city_id']        = $city_id ?: (int) $sofia['id'];
	$p['sameday_delivery_type']  = $type;
	$p['sameday_office_id']      = 0;
	$p['sameday_payment_method'] = 'cod';
	return $p;
};
$rate = function ( array $p ) use ( &$method ) { // by reference: cases below replace $method after changing the settings
	$method->rates = [];
	$method->calculate_shipping( $p );
	$r = reset( $method->rates );
	return $r ? [ 'cost' => (float) $r->get_cost(), 'meta' => $r->get_meta_data() ] : null;
};

// 1. The typed postcode never reaches Sameday; the nomenclature's does, with Sameday's own city and county spelling.
$reply = $ok_reply; $captured = []; $clear_q();
$r  = $rate( $package( 'address', '2230' ) );
$rc = $captured[0]['body']['awbRecipient'] ?? [];
$check( 'typed 2230 → estimate carries the city\'s ' . $sofia['postal_code'], ( $rc['postalCode'] ?? '' ) === $sofia['postal_code'], 'postalCode=' . ( $rc['postalCode'] ?? '?' ) );
$check( 'estimate carries cityString/countyString from the nomenclature', ( $rc['cityString'] ?? '' ) === $sofia['name'] && ( $rc['countyString'] ?? '' ) === $sofia['county'], ( $rc['cityString'] ?? '?' ) . '/' . ( $rc['countyString'] ?? '?' ) );
$check( 'quote 5.42 from the API', $r && 5.42 === $r['cost'] && 'api' === ( $r['meta']['priced_by'] ?? '' ), 'cost=' . var_export( $r['cost'] ?? null, true ) . ' by=' . ( $r['meta']['priced_by'] ?? '?' ) );
$check( 'COD amount sent = basket total', 29.99 === (float) ( $captured[0]['body']['cashOnDelivery'] ?? 0 ), 'cod=' . ( $captured[0]['body']['cashOnDelivery'] ?? '?' ) );
$check( 'a quote waits 15 s for Sameday, not 45', 15 === (int) ( $captured[0]['timeout'] ?? 0 ), 'timeout=' . var_export( $captured[0]['timeout'] ?? null, true ) );
$check( 'an API price is kept ~15 minutes', abs( $quote_ttl() - 900 ) <= 5, 'ttl=' . $quote_ttl() );

// 2. Sameday refuses (validation envelope, HTTP 400) → not free: the fallback table prices it, and the refusal is logged.
$reply = [ '__code' => 400, 'code' => 400, 'message' => 'Validation Failed', 'errors' => [ 'children' => [ 'awbRecipient' => [ 'children' => [ 'postalCode' => [ 'errors' => [ 'Invalid postal code' ] ] ] ] ] ] ];
$logged = []; $clear_q();
$r = $rate( $package( 'address', '2230' ) );
$check( 'refusal → priced by the fallback table, not 0.00', $r && $r['cost'] > 0 && 'fallback' === ( $r['meta']['priced_by'] ?? '' ), 'cost=' . var_export( $r['cost'] ?? null, true ) . ' by=' . ( $r['meta']['priced_by'] ?? '?' ) );
$check( 'refusal logged with Sameday\'s words', (bool) preg_grep( '/postal code|Validation/i', $logged ), implode( ' | ', array_map( fn( $l ) => mb_substr( $l, 0, 70 ), $logged ) ) );
$check( 'a table price is kept ≤ 60 s', $quote_ttl() >= 0 && $quote_ttl() <= 60, 'ttl=' . $quote_ttl() );
$fallback_address = $r['cost'] ?? 0;
// A refusal is an answer: the next quote asks Sameday again (no pause).
$reply = $ok_reply; $captured = []; $clear_quotes_only();
$r = $rate( $package( 'address', '2230' ) );
$check( 'a refusal does not pause quoting — the next quote asks Sameday', 1 === count( $captured ) && 5.42 === ( $r['cost'] ?? 0 ), 'calls=' . count( $captured ) . ' cost=' . var_export( $r['cost'] ?? null, true ) );

// 3. Transport failure (timeout) → fallback price, logged — and quoting pauses for 2 minutes so the checkout is not held 15 s per keystroke.
$reply = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received' );
$logged = []; $clear_q();
$r = $rate( $package( 'address', '2230' ) );
$check( 'timeout → fallback price, not 0.00', $r && $r['cost'] > 0 && 'fallback' === ( $r['meta']['priced_by'] ?? '' ), 'cost=' . var_export( $r['cost'] ?? null, true ) );
$check( 'timeout logged with the cURL message', (bool) preg_grep( '/cURL error 28/', $logged ) );
$check( 'the 2-minute pause is logged', (bool) preg_grep( '/not answering.*2 minutes/', $logged ), implode( ' | ', array_map( fn( $l ) => mb_substr( $l, 0, 70 ), $logged ) ) );
$reply = $ok_reply; $captured = []; $clear_quotes_only();
$r = $rate( $package( 'address', '2230' ) );
$check( 'while paused: no call to Sameday, table price', 0 === count( $captured ) && 'fallback' === ( $r['meta']['priced_by'] ?? '' ), 'calls=' . count( $captured ) . ' by=' . ( $r['meta']['priced_by'] ?? '?' ) );
$pause_left = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_drushfs\\_api\\_down\\_%' LIMIT 1" ) - time();
$check( 'the pause lasts ~2 minutes', abs( $pause_left - 120 ) <= 5, 'left=' . $pause_left );
$clear_like( [ 'drushfs\\_api\\_down\\_%' ] ); $clear_quotes_only(); $captured = [];
$r = $rate( $package( 'address', '2230' ) );
$check( 'after the pause: Sameday asked again, live price back', 1 === count( $captured ) && 5.42 === ( $r['cost'] ?? 0 ), 'calls=' . count( $captured ) . ' cost=' . var_export( $r['cost'] ?? null, true ) );

// 4. A quote in another currency (what demo does) → fallback, not a lei amount shown as euros.
$reply = [ 'amount' => 23.60, 'currency' => 'RON', 'time' => 1 ]; $clear_q();
$r = $rate( $package( 'address', '2230' ) );
$check( 'RON quote → fallback, 23.60 never shown', $r && 'fallback' === ( $r['meta']['priced_by'] ?? '' ) && 23.60 !== $r['cost'], 'cost=' . var_export( $r['cost'] ?? null, true ) );

// 5. A 200 without a price in it → fallback, and it is logged (it used to be silent).
$reply = [ 'time' => 96 ]; $logged = []; $clear_q();
$r = $rate( $package( 'address', '2230' ) );
$check( 'reply without amount → fallback price', $r && 'fallback' === ( $r['meta']['priced_by'] ?? '' ) && $r['cost'] > 0, 'cost=' . var_export( $r['cost'] ?? null, true ) );
$check( 'reply without amount is logged', (bool) preg_grep( '/without a price/', $logged ), implode( ' | ', array_map( fn( $l ) => mb_substr( $l, 0, 70 ), $logged ) ) );

// 5b. No pickup point on the instance → fallback, and the log says so once an hour (it used to be silent).
$settings['pickup_point'] = ''; update_option( $opt, $settings );
$method = new Drushfs_Shipping_Method( $INSTANCE );
$reply = $ok_reply; $captured = []; $logged = []; $clear_q();
$r = $rate( $package( 'address', '2230' ) );
$check( 'no pickup point → no call to Sameday, table price', 0 === count( $captured ) && $r && 'fallback' === ( $r['meta']['priced_by'] ?? '' ) && $r['cost'] > 0, 'calls=' . count( $captured ) . ' cost=' . var_export( $r['cost'] ?? null, true ) );
$check( 'no pickup point is logged', (bool) preg_grep( '/no pickup point/', $logged ), implode( ' | ', array_map( fn( $l ) => mb_substr( $l, 0, 90 ), $logged ) ) );
$logged = []; $clear_quotes_only();
$rate( $package( 'address', '2230' ) );
$check( 'no pickup point: logged once an hour, not per quote', ! preg_grep( '/no pickup point/', $logged ) );
update_option( $opt, $saved ); $method = new Drushfs_Shipping_Method( $INSTANCE ); $clear_q();

// 6. Fallback fields emptied by the shop owner → an API failure must not price the delivery at 0.00.
$settings['fallback_address'] = ''; $settings['fallback_easybox'] = ''; $settings['fallback_pudo'] = ''; $settings['fallback_per_kg'] = '';
update_option( $opt, $settings );
$method = new Drushfs_Shipping_Method( $INSTANCE );
$reply  = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ); $clear_q();
$r = $rate( $package( 'address', '2230' ) );
$check( 'empty fallback table + API failure → no free delivery (defaults apply)', $r && ( $r['cost'] ?? 0 ) > 0 && 'fallback' === ( $r['meta']['priced_by'] ?? '' ), 'rate=' . ( $r ? 'cost ' . var_export( $r['cost'], true ) . ' by ' . ( $r['meta']['priced_by'] ?? '?' ) : 'none' ) );
$settings['fallback_address'] = '0'; update_option( $opt, $settings ); $method = new Drushfs_Shipping_Method( $INSTANCE ); $clear_q();
$reply = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
$r = $rate( $package( 'address', '2230' ) );
$check( 'a deliberate 0 in the table stays 0 (shop\'s choice)', $r && 0.0 === (float) ( $r['cost'] ?? -1 ), 'cost=' . var_export( $r['cost'] ?? null, true ) );
update_option( $opt, $saved );
$method = new Drushfs_Shipping_Method( $INSTANCE );
$clear_q(); // the timeout above paused quoting; the cases below need Sameday "up"

// 7. A city without lockers: easybox/pudo asked for → priced as what can be delivered there, never a locker quote for nothing.
if ( $no_lockers ) {
	$reply = $ok_reply; $captured = []; $clear_q();
	WC()->session->set( 'drushfs_city_id', (int) $no_lockers['id'] );
	$r = $rate( $package( 'easybox', '', (int) $no_lockers['id'] ) );
	$check( 'easybox in ' . $no_lockers['name'] . ' (no lockers) → address quote instead', $r && 'address' === ( $r['meta']['delivery_type'] ?? '' ) && 5.42 === $r['cost'], 'type=' . ( $r['meta']['delivery_type'] ?? '?' ) . ' cost=' . var_export( $r['cost'] ?? null, true ) );
	WC()->session->set( 'drushfs_city_id', (int) $sofia['id'] );
}

// 8. Weight 0 on the product → a positive weight goes to Sameday (default weight), never 0.
$prod = wc_get_product( $pid ); $saved_w = $prod->get_weight(); $prod->set_weight( '' ); $prod->save();
WC()->cart->empty_cart(); WC()->cart->add_to_cart( $pid, 1 );
$reply = $ok_reply; $captured = []; $clear_q();
$rate( $package( 'address', '2230' ) );
$w = $captured[0]['body']['packageWeight'] ?? null;
$check( 'weight 0 → default weight sent (> 0)', null !== $w && (float) $w > 0, 'packageWeight=' . var_export( $w, true ) );
$prod->set_weight( $saved_w ); $prod->save();
WC()->cart->empty_cart(); WC()->cart->add_to_cart( $pid, 1 );

// 9. Order placed with the city id in billing_city and the typed 2230: the order gets the real name and postcode, the id goes to meta.
WC()->session->set( 'chosen_shipping_methods', [ 'drushfs_sameday:' . $INSTANCE ] );
$o = wc_create_order(); $o->add_product( wc_get_product( $pid ), 1 );
$o->set_billing_city( (string) $sofia['id'] ); $o->set_billing_postcode( '2230' ); $o->set_billing_state( 'BG-22' ); $o->set_billing_address_1( 'ул. Тестова 1' );
$o->set_billing_first_name( 'Test' ); $o->set_billing_last_name( 'Quote' ); $o->set_billing_phone( '0888000000' ); $o->set_billing_email( 'quote@example.com' );
$o->set_shipping_city( (string) $sofia['id'] ); $o->set_shipping_postcode( '2230' ); $o->set_shipping_state( 'BG-22' );
$i = new WC_Order_Item_Shipping(); $i->set_method_id( 'drushfs_sameday' ); $i->set_instance_id( $INSTANCE ); $i->set_total( 5.42 ); $o->add_item( $i );
$o->set_payment_method( 'cod' );
$o->calculate_totals( false );
drushfs_order_city_from_id( $o );
$o->update_meta_data( '_drushfs_delivery_type', 'address' );
$o->save(); $o = wc_get_order( $o->get_id() );
$check( 'order: postcode normalised to ' . $sofia['postal_code'], $sofia['postal_code'] === $o->get_billing_postcode() && $sofia['postal_code'] === $o->get_shipping_postcode(), 'billing=' . $o->get_billing_postcode() . ' shipping=' . $o->get_shipping_postcode() );
$check( 'order: city name + id in meta', $sofia['name'] === $o->get_billing_city() && (int) $o->get_meta( '_drushfs_billing_city_id' ) === (int) $sofia['id'], 'city=' . $o->get_billing_city() . ' meta=' . $o->get_meta( '_drushfs_billing_city_id' ) );

// 10. The waybill for that order carries the nomenclature postcode and the full order total as COD.
$reply = [ '__code' => 201, 'awbNumber' => '9999000222', 'awbCost' => 4.00, 'parcels' => [ [ 'position' => 1, 'awbNumber' => '9999000222' ] ] ];
$captured = [];
$awb = Drushfs_Waybill_Generator::instance()->generate_waybill( $o->get_id() );
$ab  = $captured[0]['body'] ?? [];
$check( 'waybill created (intercepted)', '9999000222' === $awb, is_wp_error( $awb ) ? $awb->get_error_message() : (string) $awb );
$check( 'waybill postalCode = ' . $sofia['postal_code'], ( $ab['awbRecipient']['postalCode'] ?? '' ) === $sofia['postal_code'], 'postalCode=' . ( $ab['awbRecipient']['postalCode'] ?? '?' ) );
$check( 'waybill COD = order total ' . $o->get_total(), (float) $o->get_total() === (float) ( $ab['cashOnDelivery'] ?? -1 ), 'cod=' . ( $ab['cashOnDelivery'] ?? '?' ) );
$o->delete( true );

WC()->cart->empty_cart();
$clear_q(); // never leave the stand-in token behind for the real API
echo $all ? "ALL PASS\n" : "SOME FAILED\n";
