# Drusoft Shipping for Sameday — WooCommerce Plugin

[![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759b.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0+-96588a.svg)](https://woocommerce.com/)
[![HPOS Compatible](https://img.shields.io/badge/HPOS-Compatible-green.svg)](https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage)
[![PHP](https://img.shields.io/badge/PHP-8.0+-777bb4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A conflict-free **WooCommerce shipping plugin** for the [Sameday](https://sameday.bg/) courier in **Bulgaria**: live prices, delivery to an address, an easybox locker or a SAMEDAY point, and waybills managed from the order screen — through the Sameday REST API.

> **Compatibility:** requires the classic shortcode-based Cart and Checkout pages (`[woocommerce_cart]` / `[woocommerce_checkout]`). WooCommerce Block Checkout is not yet supported.

Sibling plugins with the same checkout experience: [Drusoft Shipping for Speedy](https://wordpress.org/plugins/drusoft-shipping-for-speedy/) and [Drusoft Shipping for Econt](https://wordpress.org/plugins/drusoft-shipping-for-econt/). All three run side by side on one checkout.

---

## Features

### For customers
- **Three delivery options** — address (24H), easybox locker, SAMEDAY point
- **Live prices** from Sameday for the real parcel, city and payment method
- **Region → city list** (searchable in Latin or Cyrillic) that fills the postcode — the same flow as the Speedy and Econt siblings
- **Only what the city has** — easybox and SAMEDAY point appear only where one exists
- **Map picker** (Leaflet, bundled locally) of the whole country, opened on the chosen city; a pick elsewhere switches region, city and delivery type
- **Full lockers hidden** — locations over capacity are not offered

### For merchants
- **HPOS compatible**
- **Daily location sync** via Action Scheduler — checkout never waits on the API
- **Fallback price table** when Sameday is unreachable
- **Waybills** — create (manually or automatically), print PDF labels, cancel
- **Cash on delivery** always taken from the order total at waybill time
- **Declared value** — off, above a threshold, or always
- **Demo / production** environment switch
- **Bulgarian (bg_BG) translation** included

---

## Installation

1. Copy the plugin to `wp-content/plugins/drusoft-shipping-for-sameday/` and activate it.
2. **WooCommerce → Settings → Shipping → Shipping Zones** → add the **Sameday** method to a zone.
3. Choose the environment, enter the Sameday API username and password, save. Credentials are verified immediately and the first sync runs.
4. Pick your pickup point and the delivery options to offer, save again.

---

## Sameday API

| Purpose | Endpoint |
|---|---|
| Token | `POST /api/authenticate` |
| Cities | `GET /api/geolocation/city` |
| easybox / SAMEDAY points | `GET /api/client/ooh-locations` (the id to send back is `oohId`) |
| Services | `GET /api/client/services` |
| Pickup points | `GET /api/client/pickup-points` |
| Price | `POST /api/awb/estimate-cost` |
| Create waybill | `POST /api/awb` (answers **201**) |
| Label PDF | `GET /api/awb/download/{awbNumber}` |
| Cancel | `DELETE /api/awb/{awbNumber}` (answers **204**, empty body) |
| Find by order | `GET /api/client/awb/{reference}` |
| Status changes | `GET /api/client/status-sync` (window under 7200 s) |

Hosts: `api.sameday.bg` (production), `sameday-api-bg.demo.zitec.com` (demo). Bodies are form-urlencoded with PHP-style nesting (`awbRecipient[name]`, `parcels[0][weight]`).

Things the official documentation does not tell you are recorded at the top of `includes/class-drushfs-api.php`.

---

## Data stored locally

| Table | Contents |
|---|---|
| `wp_drushfs_cities` | Bulgarian cities with county and postcode |
| `wp_drushfs_lockers` | easybox lockers and SAMEDAY points, keyed by `oohId` |

Order meta: `_drushfs_delivery_type`, `_drushfs_office_id` (the pickup location), `_drushfs_waybill_id`, `_drushfs_waybill_response`.

---

## License

GPL v2 or later. © 2026 DRUSOFT LTD.
