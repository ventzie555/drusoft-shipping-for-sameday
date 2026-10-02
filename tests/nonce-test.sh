#!/bin/bash
# Run from the host against ~/drusoft/wpfresh (http://localhost:8088). OFFICE=<a Plovdiv ooh_id> tests/nonce-test.sh
# Sameday 1.0.3: unverified POSTs must not touch the session; WooCommerce's own verified requests must.
B=http://localhost:8088; J=$(mktemp); PASS=1
wpq(){ (cd ~/drusoft/wpfresh && docker compose exec -T php sh -c "cd /var/www/html/wordpress && $1"); }
sess(){ # print drushfs_city_id / delivery_type / office_id from this cookie's WC session
  local cust=$(grep -o 'wp_woocommerce_session_[^[:space:]]*[[:space:]][^[:space:]]*' $J | awk '{print $2}' | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read()).split("|")[0])')
  wpq "wp eval '\$v=maybe_unserialize(\$GLOBALS[\"wpdb\"]->get_var(\$GLOBALS[\"wpdb\"]->prepare(\"select session_value from {\$GLOBALS[\"wpdb\"]->prefix}woocommerce_sessions where session_key=%s\",\"$cust\")));foreach([\"drushfs_city_id\",\"drushfs_delivery_type\",\"drushfs_office_id\"] as \$k){echo \$k,\"=\",maybe_unserialize(\$v[\$k]??\"\"),\" \";}'"; }
check(){ if [ "$2" = "$3" ]; then echo "PASS  $1"; else echo "FAIL  $1 (want '$3' got '$2')"; PASS=0; fi; }
city(){ sess | grep -o 'drushfs_city_id=[0-9]*' | cut -d= -f2; }
typ(){ sess | grep -o 'drushfs_delivery_type=[a-z]*' | cut -d= -f2; }

curl -s -c $J -b $J "$B/?add-to-cart=25" -o /dev/null
curl -s -c $J -b $J "$B/cart/" -o /dev/null
echo "start: $(sess)"
start=$(city)

# 1. Forged POST to the cart page, no nonce (the CSRF wp.org described)
curl -s -c $J -b $J -X POST "$B/cart/" --data "sameday_city_id=110448&sameday_delivery_type=easybox&calc_shipping_city=110448" -o /dev/null
check "forged cart POST, no nonce → city unchanged" "$(city)" "$start"
# 2. Forged POST with a garbage nonce
curl -s -c $J -b $J -X POST "$B/cart/" --data "sameday_city_id=110448&woocommerce-cart-nonce=abc123&update_cart=1" -o /dev/null
check "forged cart POST, bad nonce → city unchanged" "$(city)" "$start"
# 3. Forged checkout-refresh: post_data without security
curl -s -c $J -b $J -X POST "$B/?wc-ajax=update_order_review" --data-urlencode "post_data=billing_city=110448&sameday_delivery_type=easybox" -o /dev/null
check "update_order_review without security → city unchanged" "$(city)" "$start"

# 4. The real cart update form (what WC's cart.js submits), with its nonce
CN=$(curl -s -c $J -b $J "$B/cart/" | grep -o 'name="woocommerce-cart-nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')
KEY=$(curl -s -c $J -b $J "$B/cart/" | grep -o 'name="cart\[[a-f0-9]*\]\[qty\]"' | head -1 | sed 's/name="//;s/"//')
curl -s -c $J -b $J -X POST "$B/cart/" --data "$KEY=1&sameday_city_id=110448&sameday_delivery_type=easybox&woocommerce-cart-nonce=$CN&update_cart=Update" -o /dev/null
check "cart update form with its nonce → city 110448" "$(city)" "110448"
check "cart update form with its nonce → type easybox" "$(typ)" "easybox"

# 5. The real checkout refresh with update-order-review nonce
CO=$(curl -s -c $J -b $J "$B/checkout/")
UN=$(echo "$CO" | grep -o '"update_order_review_nonce":"[^"]*"' | cut -d'"' -f4)
curl -s -c $J -b $J -X POST "$B/?wc-ajax=update_order_review" --data "security=$UN" --data-urlencode "post_data=billing_city=111961&sameday_delivery_type=address&billing_state=BG-03" -o $J.uor
check "checkout refresh with security → city 111961" "$(city)" "111961"
grep -q '"result":"success"\|fragments' $J.uor && echo "PASS  refresh returned fragments" || { echo "FAIL  refresh response"; PASS=0; }

# 6. Place the order (the real checkout form nonce) → order meta saved by drushfs_save_order_meta
PN=$(echo "$CO" | grep -o 'name="woocommerce-process-checkout-nonce" value="[^"]*"' | sed 's/.*value="//;s/"//')
SM=$(echo "$CO" | grep -o 'value="drushfs_sameday:[0-9]*"' | head -1 | cut -d'"' -f2); SM=${SM:-drushfs_sameday:2}
R=$(curl -s -c $J -b $J -X POST "$B/?wc-ajax=checkout" --data "billing_first_name=Test&billing_last_name=Nonce&billing_country=BG&billing_state=BG-16&billing_city=110448&billing_postcode=4000&billing_address_1=x&billing_phone=0888000000&billing_email=t@example.com&shipping_method[0]=$SM&payment_method=cod&sameday_delivery_type=easybox&sameday_office_id=$OFFICE&woocommerce-process-checkout-nonce=$PN&_wp_http_referer=/checkout/")
echo "checkout: $(echo $R | head -c 300)"
OID=$(echo "$R" | grep -o 'order-received\\\/[0-9]*' | grep -o '[0-9]*$')
if [ -n "$OID" ]; then
  M=$(wpq "wp eval '\$o=wc_get_order($OID); echo \$o->get_meta(\"_drushfs_delivery_type\"),\"|\",\$o->get_meta(\"_drushfs_office_id\");'")
  check "order $OID meta saved via verified checkout" "$M" "easybox|$OFFICE"
else echo "FAIL  no order"; PASS=0; fi

# 7. Checkout with a bad process nonce → WC refuses, no order
R2=$(curl -s -c $J -b $J -X POST "$B/?wc-ajax=checkout" --data "billing_first_name=X&payment_method=cod&woocommerce-process-checkout-nonce=bad")
echo "$R2" | grep -q '"result":"failure"' && echo "PASS  bad checkout nonce refused" || { echo "FAIL  bad nonce: $R2"; PASS=0; }
[ $PASS = 1 ] && echo "ALL PASS" || echo "SOME FAILED"
