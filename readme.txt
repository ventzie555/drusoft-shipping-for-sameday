=== Drusoft Shipping for Speedy ===
Contributors: ventzie
Tags: woocommerce, shipping, speedy, bulgaria, delivery
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.1.3
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A clean, conflict-free Speedy integration for WooCommerce stores in Bulgaria.

== Description ==

**Drusoft Shipping for Speedy** is a high-performance, conflict-free WooCommerce integration for Speedy delivery services in Bulgaria. Designed for speed, reliability, and ease of use, it provides a seamless shipping experience for both merchants and customers.

= Important Compatibility Note =

This plugin is currently **not compatible** with the WooCommerce Block Cart and Block Checkout pages. Please ensure your store uses the classic shortcode-based Cart (`[woocommerce_cart]`) and Checkout (`[woocommerce_checkout]`) pages.

= For Your Customers =

* **Dynamic Checkout Experience** — Real-time city and office selection directly on the checkout page.
* **Multiple Delivery Types** — Choose between delivery to Address, Speedy Office, or Speedy Automat (APS).
* **Smart Street Search** — Built-in autocomplete for Bulgarian street names with intelligent prefix handling (e.g., stripping "ул.", "бул.").
* **Live Service Selection** — Customers can choose between available services (Economy, Express, etc.) with real-time price updates.
* **Region Mapping** — Automated city filtering based on the selected Bulgarian province.

= For Merchants =

* **HPOS Compatible** — Fully supports WooCommerce High-Performance Order Storage.
* **Automated Data Sync** — Uses Action Scheduler to keep Bulgarian cities and Speedy offices up-to-date in the background.
* **Credential Validation** — Validates API credentials in real-time before saving.
* **Custom Pricing** — Support for custom pricing CSV files for specialized shipping rates.
* **Advanced Order Management** — Dedicated metabox in the order edit screen, integrated waybill generation, and bulk actions for managing multiple Speedy orders.
* **Clean Codebase** — Built with modern PHP standards and conflict-free architecture.

= Also Ship via Econt? =

This plugin has a sibling for the **Econt** courier: [Drusoft Shipping for Econt](https://wordpress.org/plugins/drusoft-shipping-for-econt/). Both plugins share the same checkout UI, settings layout, and admin order-management screens — once you've learned one, the other feels familiar. Install both if your store supports delivery via either courier.

== Installation ==

1. Upload the `drusoft-shipping-for-sameday` folder to the `/wp-content/plugins/` directory.
2. Ensure **WooCommerce** is installed and active.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Navigate to **WooCommerce > Settings > Shipping > Shipping Zones**.
5. Add or edit a shipping zone (e.g., "Bulgaria").
6. Click **Add shipping method** and select **Drusoft Shipping for Speedy**.
7. Enter your **Speedy API Username** and **Password**, then click **Save Changes**.
8. The plugin will validate your credentials and unlock additional configuration options.
9. Background sync for cities and offices starts automatically via the WooCommerce Action Scheduler.

== Frequently Asked Questions ==

= What Speedy API credentials do I need? =

You need the username and password provided by Speedy for their REST API (v1). Contact Speedy Bulgaria to obtain API access.

= Does this plugin support the WooCommerce Block Checkout? =

Not yet. The plugin currently requires the classic shortcode-based Checkout page (`[woocommerce_checkout]`). Block Checkout support is planned for a future release.

= How are cities and offices kept up to date? =

The plugin uses the WooCommerce Action Scheduler to sync cities and offices from the Speedy API in the background. You can monitor the scheduled action (`drushfs_sync_locations_event`) under **WooCommerce > Status > Scheduled Actions**.

= What pricing methods are available? =

* **Speedy Calculator** — Real-time API calculation based on weight, destination, and service.
* **Fixed Price** — Configurable per delivery type (Address, Office, Automat).
* **Free Shipping** — Always free, or triggered by a minimum order amount per delivery type.
* **Custom Prices (CSV)** — Upload a CSV file for complex pricing rules based on weight and order total.
* **Calculator + Surcharge** — API price plus a fixed additional fee.

= How does the CSV custom pricing work? =

Upload a CSV with columns: `service_id, delivery_type, max_weight, max_order_total, price`. Delivery type mapping: `0` = Address, `1` = Office, `2` = Automat. The plugin matches rows where the order's weight and subtotal are within the specified limits.

= Can I automatically generate waybills? =

Yes. Enable the **Automatic Waybill** option in the shipping method settings. A waybill will be created automatically when an order reaches the "Processing" or "On Hold" status.

== Screenshots ==

1. Checkout page with Speedy delivery type selection (Address, Office, Automat).
2. Shipping method settings in the WooCommerce shipping zone modal.
3. Speedy Shipment metabox on the order edit screen.
4. Speedy Orders admin page with bulk actions.

== External Services ==

This plugin relies on the **Speedy REST API**, a third-party service provided by **Speedy AD** (Speedy Bulgaria), to deliver its shipping functionality. The plugin cannot operate without a valid Speedy API account.

= What the service is =

Speedy AD is a courier and logistics company operating in Bulgaria. Their REST API allows merchants to calculate shipping rates, create shipments (waybills), manage deliveries, and retrieve location data (cities, offices, automats, streets).

= What data is sent and when =

* **API credentials** (username and password) — sent with every API request for authentication.
* **Recipient address data** (city, street, office ID) — sent when calculating shipping rates at cart/checkout and when creating a waybill after order placement.
* **Shipment details** (weight, dimensions, COD amount, service type, sender/recipient info) — sent when generating a waybill.
* **Waybill ID** — sent when cancelling a shipment, requesting a courier pickup, or printing a waybill label.
* **Location queries** (city name, street name) — sent when the customer or admin searches for cities, offices, or streets.

Data is transmitted only when the corresponding action is triggered (e.g., a customer proceeds to checkout, a merchant generates a waybill, or the background sync runs).

= Service links =

* Speedy API base URL: [https://api.speedy.bg/v1/](https://api.speedy.bg/v1/)
* Speedy Terms and Conditions: [https://www.speedy.bg/en/general-conditions](https://www.speedy.bg/en/general-conditions)
* Speedy Privacy Policy: [https://www.speedy.bg/en/privacy-policy](https://www.speedy.bg/en/privacy-policy)
* Speedy API Documentation: [https://api.speedy.bg/](https://api.speedy.bg/)

== Speedy API Endpoints ==

This plugin communicates with the **Speedy REST API v1** (`https://api.speedy.bg/v1/`). All requests are authenticated using the `userName` and `password` fields configured in the shipping method settings. Below is a summary of every endpoint used, along with its purpose and where it is called in the plugin.

= Authentication & Account =

* **`POST /v1/client/contract`** — Validates API credentials and retrieves the list of client contracts (sender accounts). Used during credential validation when the merchant saves settings, and to populate the "Sender (Object)" dropdown in the shipping method configuration.
* **`POST /v1/client/contract/info`** — Retrieves detailed contract information, including special delivery requirements (e.g., mandatory open-on-test, two-way receipt). Used to populate the "Special Requirements" multi-select in the shipping method settings.

= Location Data =

* **`POST /v1/location/site`** — Searches for cities/sites by name within Bulgaria (`countryId: 100`). Used by the admin Select2 city search when configuring the sender city, and for the public-facing city autocomplete on checkout.
* **`POST /v1/location/site/csv/100`** — Downloads the complete list of Bulgarian cities in CSV format. Used by the background syncer (`Drushfs_Syncer`) to populate and update the `wp_drushfs_cities` database table via Action Scheduler.
* **`POST /v1/location/office`** — Retrieves all Speedy offices and automats for Bulgaria. Used in two contexts: (1) the background syncer updates the `wp_drushfs_offices` table, and (2) the admin Select2 office search for configuring the sender office.
* **`POST /v1/location/street`** — Searches for streets within a specific city (`siteId`). Used on the checkout page to provide street autocomplete when the customer selects "Delivery to Address."

= Services & Pricing =

* **`POST /v1/services`** — Retrieves the list of available shipping services (e.g., Standard 505, Express 501) for the authenticated account. Used to populate the "Active Services" multi-select in the shipping method settings.
* **`POST /v1/calculate`** — Calculates the shipping price for a specific service, weight, destination, and delivery type. Used at cart/checkout time when the pricing method is set to "Speedy Calculator" or "Calculator + Surcharge."

= Shipment Management =

* **`POST /v1/shipment/`** — Creates a new shipment (waybill) with the Speedy system. Includes sender/recipient details, service, weight, COD amount, and delivery type. Used by the waybill generator, either automatically on order status change or manually from the order metabox.
* **`POST /v1/shipment/cancel`** — Cancels an existing shipment by its waybill ID. Used from the "Cancel Shipment" button in the Speedy order metabox or the Speedy Orders bulk action.
* **`POST /v1/pickup`** — Requests a courier pickup for one or more shipments. Accepts a visit end time and auto-adjusts the pickup date. Used from the "Request Courier" button in the order metabox.
* **`POST /v1/print`** — Generates a printable waybill label (PDF) for a shipment. Supports A4, A6, and label formats with optional additional barcode copy. Used from the "Print Waybill" button in the order metabox and the Speedy Orders page.

= Rate Limiting & Caching =

The plugin minimizes API calls through several strategies:

* **Local database tables** — Cities and offices are synced periodically via Action Scheduler and queried locally, avoiding per-request API calls for location data.
* **Transient caching** — Service lists, contract data, and client information are cached using WordPress transients to reduce redundant API calls.
* **Session storage** — Cart selections (city, delivery type, office) are stored in the WooCommerce session, so shipping calculations reuse the customer's choices without extra lookups.

== Changelog ==

= 1.1.3 =
* New: when an order is placed but the delivery price could not be calculated (Speedy did not answer), the order is still accepted — you should not lose the sale — and an order note now tells you so, with the chosen office, so you see it in the order history before shipping. Previously such an order arrived with a 0.00 shipping line and nothing explaining why.
* New: the daily refresh of offices and cities now makes sure it is scheduled on every normal page load, not only when the plugin is activated or its settings are saved from the admin screen. Shops set up with wp-cli or with settings written programmatically had no refresh at all and were quietly serving weeks-old office lists.
* New: the settings screen shows when offices and cities were last refreshed from Speedy and when the next refresh is due, and warns if it is more than three days old.

= 1.1.2 =
* Important fix: a cash-on-delivery order could receive a waybill carrying no cash-on-delivery at all, so the courier would have handed the parcel over without collecting the money. It happened when the customer switched to наложен платеж at the last step, after the quote had been built for card payment, and again when the shop pays the courier fee itself. Both cases are now closed and the amount is taken from the order rather than from the stale quote.
* Important fix: when the delivery-price call failed at checkout, the office the customer had picked was not saved on the order. The order still went through — correctly, you should not lose the sale — but it reached you marked "to office" with no office on it, and the waybill could not be created without phoning the customer. This affected every shop using WooCommerce's High-Performance Order Storage, which is the default for new installations.
* Fixed: the chosen office, delivery type and stored order data could be written as several repeated rows on one order instead of a single value that gets replaced.

= 1.1.1 =
* Important fix: on cash-on-delivery orders the courier fee could be charged to the customer at the counter even though the order had already charged them for shipping — so they paid for delivery twice, with no warning at checkout. The fee is now billed to the merchant whenever the order carries a shipping charge. Stores that offer free shipping are unaffected. If you ship COD and charge for delivery, please update.
* New: office and automat picking now uses a map bundled with the plugin instead of the Speedy-hosted locator. The old locator could not be used on a phone — its selection button fell outside the visible area — so mobile customers had no way to choose an office from the map.
* Fixed: the office list sent to the checkout no longer carries map coordinates for every location. In Sofia that was 226 KB on a field most customers never open; the map now loads its data only when opened.

= 1.1.0 =
* Important fix: the cash-on-delivery (COD) amount sent to Speedy now equals the full order total (incl. VAT and shipping). Previously the waybill carried the items subtotal excluding VAT, so the courier collected less than the order total. If you ship COD, please update.

= 1.0.9 =
* Fixed: switching the shipping method to another courier via keyboard (arrow keys on the radio group), assistive technology or a script left this plugin's city dropdown active under the other courier, with Speedy city IDs the other courier could misread. The cleanup that previously ran only on mouse clicks now also runs for those switches.
* Fixed: the script/style version constant had not been bumped since 1.0.7, so browsers could keep serving a cached older checkout script after updating the plugin.

= 1.0.8 =
* Fixed: the delivery city was stored as Speedy's internal site ID (e.g. "56784") in the order's billing/shipping city field, so the WooCommerce order screen, invoices and CSV exports showed a number instead of the city name. The city name and its postcode are now saved on the order, while the site ID is kept in order meta for waybill generation (delivery is unaffected).

= 1.0.7 =
* Improved: customers now see the Speedy parcel-tracking link (waybill number + a link to speedy.bg) on the order-received page, in My Account, on the order-tracking page, and in their order emails — shown once the waybill is generated.
* Improved: orders delivered to a Speedy automat (APT) are now labelled "Speedy automat" instead of "Speedy office" on the order admin screen.

= 1.0.6 =
* Improved: the selected Speedy office (name and address) is now shown directly on the WooCommerce order screen, in the shipping-address column. Previously only the internal office ID was stored, so merchants had to generate a waybill to see which office the customer chose. Resolved at display time, so it also applies to orders placed before this version.

= 1.0.5 =
* Fixed: when both Drusoft Shipping for Speedy and Drusoft Shipping for Econt are active, switching couriers during checkout could leave the newly selected courier's office/map picker uninitialised. Both plugins tagged their city dropdown with the same generic Select2 class; each now marks its own.
* Fixed: each courier keeps its own delivery selection (province, city, office, delivery type, postcode). A value entered under one courier no longer carries over to the other through the shared address fields.
* Fixed: changing the province after the city/office were already chosen now clears them and reloads the cities for the new province (the province-change handler is bound directly on the field so it survives Select2 re-initialisation).
* Improved: switching back to Speedy no longer briefly shows an intermediate price — the order review is greyed while it re-quotes.
* i18n: the "Delivery Method" field label is now translatable.

= 1.0.4 =
* Compatibility: bumped minimum PHP from 7.4 to 8.0 (7.4 has been end-of-life since November 2022). Tested up to WordPress 7.0.
* Fixed: stale Speedy price persisted in the cart total after the customer switched to a different shipping method during checkout. New `drushfs_clear_speedy_when_unselected` hook + `calculate_shipping` short-circuit clear the Speedy session keys and invalidate the package-rate cache when Speedy is no longer selected.
* Fixed: shipping price now re-quotes when the customer toggles between Cash on Delivery and a non-COD payment method on checkout (COD adds a handling fee). The package hash already varied by payment_method server-side; the missing piece was a JS trigger to fire `update_checkout` on `payment_method` change.
* Improved: late `drushfs_get_services` AJAX responses no longer re-inject the Speedy service-tier radios into the order review after the customer switched to a non-Speedy method.

= 1.0.3 =
* Fixed: On cart page, when Speedy is preselected as the default shipping method, the shipping calculator is now opened automatically.
* Improved: Prevented race with WooCommerce core cart script hiding the calculator on initial load.

= 1.0.2 =
* Fixed: Shipping method not initializing when it is the only method in a shipping zone (WooCommerce renders a hidden input instead of radio buttons for single methods).
* Fixed: Shipping method enabled/disabled toggle and custom title now correctly read from instance settings instead of empty global settings.

= 1.0.1 =
* Added a WordPress Playground blueprint for plugin page Live Preview with WooCommerce preinstalled.
* Declared WooCommerce as a required plugin and updated installation instructions accordingly.
* Improved WordPress.org compatibility, including plugin metadata, text domain alignment, and compliance fixes.
* Fixed shipping recalculation when switching payment methods during checkout.

= 1.0.0 =
* Initial release.
* Full Speedy API integration for shipping calculation and waybill generation.
* Support for delivery to Address, Office, and Automat (APS).
* Dynamic city, office, and street selection on checkout.
* Multiple pricing methods: Speedy Calculator, Fixed Price, Free Shipping, Custom CSV, Calculator + Surcharge.
* Free shipping thresholds configurable per delivery type.
* Background sync of Bulgarian cities and Speedy offices via Action Scheduler.
* HPOS (High-Performance Order Storage) compatibility.
* Admin order management: waybill generation, printing, courier requests, and cancellation.
* Nonce-protected AJAX handlers with separate public and admin scopes.
* Bulgarian (bg_BG) translation included.

== Upgrade Notice ==

= 1.1.3 =
Recommended update. Orders placed without a calculated delivery price now carry an order note so you notice before shipping; the daily office/city refresh self-heals if it was never scheduled; the settings screen shows the last refresh time.

= 1.1.2 =
Important update. A cash-on-delivery order could go out with no cash-on-delivery on the waybill, meaning the courier would deliver without collecting payment. Also fixes the customer's chosen office being lost on High-Performance Order Storage shops.

= 1.0.5 =
Recommended update. Fixes courier-switch issues when used alongside Drusoft Shipping for Econt, keeps each courier's checkout selection separate, and reloads cities correctly when the province is changed.

= 1.0.4 =
Recommended update. Fixes stale shipping price after switching shipping methods and missing COD re-quote on payment-method change. Minimum PHP version is now 8.0 (PHP 7.4 has been end-of-life since November 2022).

= 1.0.3 =
Recommended update: fixes cart address form visibility when Speedy is preselected as the default shipping method.

= 1.0.2 =
Important fix: Shipping rates were not displayed when Drusoft Shipping for Speedy was the only shipping method in a zone. Also fixes the enable/disable toggle and custom method title not being respected.

= 1.0.1 =
Recommended update with WordPress.org compatibility improvements, WooCommerce dependency metadata, Playground Live Preview support, and checkout recalculation fixes.

= 1.0.0 =
Initial release.
