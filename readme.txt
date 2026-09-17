=== Drusoft Shipping for Sameday ===
Contributors: ventzie
Tags: woocommerce, shipping, sameday, easybox, bulgaria
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 0.4.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A clean, conflict-free Sameday integration for WooCommerce stores in Bulgaria — live prices, easybox and address delivery, waybills and labels.

== Description ==

**Drusoft Shipping for Sameday** connects a WooCommerce store in Bulgaria to the Sameday courier. Customers see live Sameday prices at checkout and choose delivery to their door, to an easybox locker or to a SAMEDAY point; the merchant creates, prints and cancels waybills from the order screen.

= Important Compatibility Note =

This plugin is currently **not compatible** with the WooCommerce Block Cart and Block Checkout pages. Please ensure your store uses the classic shortcode-based Cart (`[woocommerce_cart]`) and Checkout (`[woocommerce_checkout]`) pages.

= For Your Customers =

* **Three delivery options** — to an address (24H), to an easybox locker, or to a SAMEDAY point.
* **Live prices** — each option is priced by Sameday for the actual parcel, city and payment method.
* **City list** — the customer picks a region, then a city from a searchable list (Latin or Cyrillic); the postcode fills itself.
* **Only what the city has** — easybox and SAMEDAY point are offered only in cities that have one, with a searchable list of that city's locations.
* **Map picker** — a map of every easybox and SAMEDAY point in the country, opened on the customer's city, with filters and search. Picking a location elsewhere sets the region, city and delivery type by itself.
* **Full lockers hidden** — locations Sameday reports as over capacity are not offered.

= For Merchants =

* **HPOS Compatible** — Fully supports WooCommerce High-Performance Order Storage.
* **Automated Data Sync** — Uses Action Scheduler to refresh Bulgarian cities and Sameday pickup locations daily, so checkout never waits on the API.
* **Fallback prices** — if Sameday cannot be reached, checkout still shows a price from a table you configure.
* **Waybill Management** — Create, print (PDF) and cancel waybills from the order edit screen, or create them automatically when an order becomes Processing or On hold.
* **Cash on delivery collected correctly** — the waybill always carries the order total, never a stale checkout estimate.
* **Declared value** — off, above a threshold, or always.
* **Demo and production** — switch between Sameday's demo and live environments in the settings.
* **Bulgarian (bg_BG) Translation Included.**

= Also Ship via Speedy or Econt? =

This plugin has siblings for the other Bulgarian couriers: [Drusoft Shipping for Speedy](https://wordpress.org/plugins/drusoft-shipping-for-speedy/) and [Drusoft Shipping for Econt](https://wordpress.org/plugins/drusoft-shipping-for-econt/). All three share the same checkout experience, map picker and order screens, and are built to run side by side on one checkout.

== Installation ==

1. Upload the `drusoft-shipping-for-sameday` folder to the `/wp-content/plugins/` directory.
2. Ensure **WooCommerce** is installed and active.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Navigate to **WooCommerce > Settings > Shipping > Shipping Zones**.
5. Add or edit a shipping zone (e.g. "Bulgaria").
6. Click **Add shipping method** and select **Sameday**.
7. Choose the environment, enter your **Sameday API username and password**, and click **Save Changes**. The credentials are checked immediately and the first location sync runs.
8. Select your **pickup point** and the delivery options you want to offer, then save again.

== Frequently Asked Questions ==

= What Sameday credentials do I need? =

An API username and password issued by Sameday for your contract. Demo credentials and production credentials are separate — ask Sameday's integration team for both.

= Does this plugin support the WooCommerce Block Checkout? =

Not yet. The plugin currently requires the classic shortcode-based Checkout page (`[woocommerce_checkout]`).

= How are pickup locations kept up to date? =

The plugin uses the WooCommerce Action Scheduler to refresh cities, easybox lockers and SAMEDAY points from the Sameday API once a day. You can monitor the scheduled action (`drushfs_sync_locations_event`) under **WooCommerce > Status > Scheduled Actions**. The settings screen shows when the last refresh ran.

= How is shipping cost calculated? =

Each delivery option is priced live by Sameday's `estimate-cost` endpoint for the parcel weight, destination, cash-on-delivery amount and declared value. Quotes are cached for 15 minutes so a checkout does not call the API on every keystroke. If Sameday cannot be reached, the fallback prices from the settings are used instead.

= Why is the SAMEDAY point option missing at checkout? =

Each pickup option appears only when the customer's city has such a location. A city with no SAMEDAY point offers address delivery and, if it has one, easybox.

= Can I automatically generate waybills? =

Yes. Enable **Create automatically** in the shipping method settings. A waybill is created when an order becomes "Processing" or "On hold"; an order that already has one is never given a second.

= Can I request a courier from WordPress? =

No. Sameday's client API has no courier-request endpoint. Collection follows your pickup point arrangement with Sameday, or you drop parcels into an easybox.

== Screenshots ==

1. Checkout with the three Sameday delivery options and the easybox list.
2. Map picker for easybox lockers and SAMEDAY points.
3. Shipping method settings in the WooCommerce shipping zone modal.
4. Sameday Shipment box on the order edit screen.

== External Services ==

This plugin relies on the **Sameday API**, a third-party service provided by **Деливъри Солюшънс ЕООД** (Delivery Solutions EOOD, operator of the Sameday brand in Bulgaria), to deliver its shipping functionality. The plugin cannot operate without a valid Sameday API account.

The checkout map also loads map tiles from **OpenStreetMap**.

= What the service is =

Sameday is a courier company operating in Bulgaria, Romania and Hungary. Its REST API lets merchants price shipments, create and cancel waybills, download labels, and retrieve cities and pickup locations (easybox lockers and SAMEDAY points).

= What data is sent and when =

Authentication: the API username and password are exchanged once for a token, which is cached and sent as an `X-AUTH-TOKEN` header on later requests.

* **Recipient data** (name, phone, email, city, county, address, postcode, chosen pickup location) — sent when a shipping price is calculated at checkout and when a waybill is created.
* **Shipment details** (pickup point id, weight, package count, service, cash-on-delivery amount, declared value, order number, item descriptions) — sent when a price is calculated and when a waybill is created.
* **Waybill numbers** — sent when a label is downloaded or a waybill is cancelled.
* **No customer data** is sent during the daily location sync, which only downloads cities and pickup locations.

Map tiles are requested from OpenStreetMap by the customer's browser only when the customer opens the map picker; the request reveals the customer's IP address and the map area viewed.

= Service links =

* Sameday: [https://sameday.bg/](https://sameday.bg/)
* Sameday general terms: [https://sameday.bg/obshti-usloviya-otnosno-predostavyaneto/](https://sameday.bg/obshti-usloviya-otnosno-predostavyaneto/)
* Sameday privacy notice: [https://sameday.bg/sabshchenieto-za-poveritelnost-28-03/](https://sameday.bg/sabshchenieto-za-poveritelnost-28-03/)
* OpenStreetMap tile usage policy: [https://operations.osmfoundation.org/policies/tiles/](https://operations.osmfoundation.org/policies/tiles/)
* OpenStreetMap copyright: [https://www.openstreetmap.org/copyright](https://www.openstreetmap.org/copyright)

== Sameday API Endpoints ==

This plugin communicates with one host per environment:

* **`api.sameday.bg`** — production
* **`sameday-api-bg.demo.zitec.com`** — demo

= Authentication =

* **`POST /api/authenticate`** — Exchanges the username and password for a token, cached until shortly before it expires.

= Location Data =

* **`GET /api/geolocation/city`** — Bulgarian cities with their county, stored in `wp_drushfs_cities`.
* **`GET /api/client/ooh-locations`** — easybox lockers and SAMEDAY points, stored in `wp_drushfs_lockers`.
* **`GET /api/client/services`** — Services enabled for the account.
* **`GET /api/client/pickup-points`** — The merchant's pickup points, for the settings screen.

= Pricing =

* **`POST /api/awb/estimate-cost`** — Price for a shipment, used at cart and checkout.

= Shipment Management =

* **`POST /api/awb`** — Creates a waybill.
* **`GET /api/awb/download/{awbNumber}`** — Downloads the label PDF.
* **`DELETE /api/awb/{awbNumber}`** — Cancels a waybill.
* **`GET /api/client/awb/{reference}`** — Looks a shipment up by order number.
* **`GET /api/client/status-sync`** — Shipment status changes.

= Rate Limiting & Caching =

* **Local database tables** — Cities and pickup locations are synced once per day and queried locally.
* **Token caching** — One authentication per token lifetime rather than one per request.
* **Quote caching** — Prices are cached for 15 minutes per shipment shape.
* **Map data** — The pickup list is loaded per city; the map's country-wide list is fetched only when the customer opens the map and is cached for an hour.

== Changelog ==

= 0.4.1 =
* Location sync: follow the page count Sameday reports. A page can come back short without being the last one, and the sync stopped there, dropping most SAMEDAY points on accounts with more than a thousand locations.

= 0.4.0 =
* Map: shows the whole country and opens zoomed on the chosen city, as Drusoft Shipping for Econt does; a location picked in another city switches region, city and delivery type. The map button is available as soon as Sameday is selected.
* Cart: keeps its region search, hidden Update button and locked postcode after a sibling courier plugin resets the shared calculator.
* The order's second address line names the pickup location also when the choice was restored rather than re-made.

= 0.3.2 =
* Cart page: the shipping calculator now offers the same region and city list, and the same delivery-type chooser, as Drusoft Shipping for Speedy and Econt.
* The delivery type chosen on the cart is kept when the checkout opens.
* The cart city list re-applies itself if a sibling courier plugin resets the shared city field.

= 0.2.2 =
* A quote in a currency other than the shop's is ignored and the fallback price used. Sameday's demo environment quotes in Romanian lei.

= 0.2.1 =
* Checkout now follows the same flow as Drusoft Shipping for Speedy and Econt: region, city list with automatic postcode, then only the delivery options the chosen city has, with a pickup list and map limited to that city.
* No price is shown until a city is chosen.
* Service ids are resolved by service code, because they differ between the demo and production environments.
* Quotes no longer fall back to the price table before the customer has typed a street.

= 0.1.0 =
* First version: live pricing for address, easybox and SAMEDAY point delivery; searchable pickup list and map picker; daily location sync; waybill creation (manual or automatic), label printing and cancellation; fallback prices; Bulgarian translation.
