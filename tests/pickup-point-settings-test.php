<?php
// Saving the method without a pickup point (1.0.4): chosen automatically when the
// account has exactly one; refused with a clear error when there are several and
// none was picked; the very first save (list not yet known) goes through but says so.
// Only pre_http_request is intercepted; every sync call gets a WP_Error so the
// location tables are never touched.
// Usage: [DRUSHFS_INSTANCE=3] wp eval-file wp-content/plugins/drusoft-shipping-for-sameday/tests/pickup-point-settings-test.php
$INSTANCE = (int) ( getenv( 'DRUSHFS_INSTANCE' ) ?: 0 );
if ( ! $INSTANCE ) {
	foreach ( wp_load_alloptions() as $k => $v ) {
		if ( preg_match( '/^woocommerce_drushfs_sameday_(\d+)_settings$/', $k, $m ) ) { $INSTANCE = (int) $m[1]; break; }
	}
}
if ( ! $INSTANCE ) { echo "no drushfs_sameday instance configured on this site\n"; return; }
global $wpdb;
$opt   = 'woocommerce_drushfs_sameday_' . $INSTANCE . '_settings';
$saved = get_option( $opt );
if ( empty( $saved['sameday_username'] ) ) { echo "instance $INSTANCE has no credentials\n"; return; }

$all   = true;
$check = function ( string $label, bool $ok, string $detail = '' ) use ( &$all ) {
	$all &= $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  [$detail]" : '' );
};
$counts = fn() => $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}drushfs_cities" ) . '/' . $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}drushfs_lockers" );
$before = $counts();

// ── Sameday stand-in ──────────────────────────────────────────────────────
$points  = [];   // what /api/client/pickup-points lists; null → the call fails
$auth_ok = true;
$P1 = [ 'id' => 701, 'alias' => 'Склад Хасково', 'city' => [ 'name' => 'Хасково' ], 'address' => 'бул. България 19' ];
$P2 = [ 'id' => 702, 'alias' => 'Склад София', 'city' => [ 'name' => 'София' ], 'address' => 'бул. Цариградско шосе 1' ];
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$points, &$auth_ok ) {
	if ( false === strpos( $url, 'sameday' ) && false === strpos( $url, 'zitec' ) ) { return $pre; }
	if ( str_contains( $url, '/api/authenticate' ) ) {
		return $auth_ok
			? [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'token' => 'TESTTOKEN', 'expire_at' => gmdate( 'c', time() + 3600 ) ] ), 'headers' => [] ]
			: [ 'response' => [ 'code' => 401 ], 'body' => '{"error":{"code":401,"message":"Invalid credentials."}}', 'headers' => [] ];
	}
	if ( str_contains( $url, '/api/client/pickup-points' ) ) {
		if ( null === $points ) { return new WP_Error( 'http_request_failed', 'cURL error 28: timed out (stand-in)' ); }
		return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'total' => count( $points ), 'data' => $points ] ), 'headers' => [] ];
	}
	// Everything the sync would fetch fails, so Drushfs_Syncer::sync() aborts before touching a table.
	return new WP_Error( 'http_request_failed', 'stand-in: no sync during the settings test' );
}, 10, 3 );

$fields   = ( new Drushfs_Shipping_Method( $INSTANCE ) )->get_instance_form_fields();
$post_for = function ( array $settings, string $pickup_posted ) use ( $fields ) {
	$post = [];
	foreach ( $fields as $key => $f ) {
		if ( 'title' === ( $f['type'] ?? '' ) ) { continue; }
		$value = (string) ( $settings[ $key ] ?? ( $f['default'] ?? '' ) );
		if ( 'checkbox' === ( $f['type'] ?? '' ) ) { if ( 'yes' === $value ) { $post[ 'woocommerce_drushfs_sameday_' . $key ] = '1'; } continue; }
		$post[ 'woocommerce_drushfs_sameday_' . $key ] = $value;
	}
	$post['woocommerce_drushfs_sameday_pickup_point'] = $pickup_posted;
	return $post;
};
$save = function ( array $settings, string $pickup_posted, array $post_override = [] ) use ( $post_for, $INSTANCE, $opt, $wpdb ) {
	update_option( $opt, $settings );
	foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_drushfs\\_pickup\\_points\\_%' OR option_name LIKE '\\_transient\\_drushfs\\_token\\_%'" ) as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
	}
	wp_cache_flush();
	$_REQUEST['instance_id'] = $INSTANCE;
	$_POST = array_merge( $post_for( $settings, $pickup_posted ), $post_override );
	$m = new Drushfs_Shipping_Method( $INSTANCE );
	$m->process_admin_options();
	wp_cache_flush();
	return [ 'errors' => $m->get_errors(), 'option' => get_option( $opt ) ];
};
$errs = fn( array $r ) => implode( ' | ', array_map( fn( $e ) => mb_substr( wp_strip_all_tags( $e ), 0, 60 ), $r['errors'] ) );

$no_pickup = $saved; $no_pickup['pickup_point'] = '';

// A. Two pickup points on the account, none chosen → refused, nothing saved.
$points = [ $P1, $P2 ];
$no_pickup['title'] = 'Before';
$r = $save( $no_pickup, '', [ 'woocommerce_drushfs_sameday_title' => 'Saved by A' ] );
$check( 'A: two points, none chosen → error "Choose a pickup point"', (bool) preg_grep( '/Choose a pickup point|Изберете/u', $r['errors'] ), $errs( $r ) );
$check( 'A: nothing saved (title unchanged, pickup still empty)', 'Before' === ( $r['option']['title'] ?? '' ) && '' === (string) ( $r['option']['pickup_point'] ?? '' ), 'title=' . ( $r['option']['title'] ?? '?' ) . ' pickup=' . var_export( $r['option']['pickup_point'] ?? null, true ) );

// B. One pickup point on the account, none chosen → chosen automatically.
$points = [ $P1 ];
$r = $save( $no_pickup, '' );
$check( 'B: one point, none chosen → 701 selected automatically', '701' === (string) ( $r['option']['pickup_point'] ?? '' ), 'pickup=' . var_export( $r['option']['pickup_point'] ?? null, true ) );
$check( 'B: no error shown', empty( $r['errors'] ), $errs( $r ) );

// C. Two points, the admin picks the second → saved as chosen.
$points = [ $P1, $P2 ];
$r = $save( $no_pickup, '702' );
$check( 'C: two points, 702 chosen → saved', '702' === (string) ( $r['option']['pickup_point'] ?? '' ), 'pickup=' . var_export( $r['option']['pickup_point'] ?? null, true ) );
$check( 'C: no error shown', empty( $r['errors'] ), $errs( $r ) );

// D. Sameday rejects the credentials → the modal gets the error (method errors), whatever the pickup point.
$auth_ok = false;
$r = $save( $no_pickup, '702' );
$check( 'D: rejected credentials reach the shipping-zone modal', (bool) preg_grep( '/rejected these credentials|отхвърли/u', $r['errors'] ), $errs( $r ) );
$auth_ok = true;

// E. First save: the pickup-point list is not available yet → the save goes through and the error says what to do next.
$points = null;
$r = $save( $no_pickup, '', [ 'woocommerce_drushfs_sameday_title' => 'Saved by E' ] );
$check( 'E: list unavailable → saved anyway', '' === (string) ( $r['option']['pickup_point'] ?? '' ) && 'Saved by E' === ( $r['option']['title'] ?? '' ), 'title=' . ( $r['option']['title'] ?? '?' ) );
$check( 'E: …and the admin is told to choose one', (bool) preg_grep( '/Choose a pickup point|Изберете/u', $r['errors'] ), $errs( $r ) );

$check( 'location tables untouched by the (aborted) sync', $before === $counts(), $before . ' → ' . $counts() );

update_option( $opt, $saved );
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_drushfs\\_pickup\\_points\\_%' OR option_name LIKE '\\_transient\\_drushfs\\_token\\_%' OR option_name LIKE '\\_transient\\_drushfs\\_no\\_pickup\\_%'" ) as $name ) {
	delete_transient( substr( $name, strlen( '_transient_' ) ) );
}
wp_cache_flush();
unset( $_POST, $_REQUEST['instance_id'] );
echo $all ? "ALL PASS\n" : "SOME FAILED\n";
