# Drusoft Shipping for Speedy — WooCommerce Plugin

[![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759b.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0+-96588a.svg)](https://woocommerce.com/)
[![HPOS Compatible](https://img.shields.io/badge/HPOS-Compatible-green.svg)](https://github.com/woocommerce/woocommerce/wiki/High-Performance-Order-Storage)
[![PHP](https://img.shields.io/badge/PHP-7.4+-777bb4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A high-performance, conflict-free **WooCommerce shipping plugin** for [Speedy](https://www.speedy.bg/) courier services in **Bulgaria**. Provides real-time shipping rates, waybill generation, office/automat selection, and full order management — all through the official Speedy REST API.

> **⚠️ Compatibility Note:** This plugin requires the classic shortcode-based Cart and Checkout pages (`[woocommerce_cart]` / `[woocommerce_checkout]`). WooCommerce Block Checkout is not yet supported.

---

## ✨ Features

### For Customers
- 🏙️ **Dynamic city & office selection** — Real-time search on checkout
- 📦 **Multiple delivery types** — Address, Speedy Office, or Speedy Automat (APS)
- 🔍 **Smart street autocomplete** — Bulgarian street names with prefix handling (ул., бул.)
- 💰 **Live service & price selection** — Economy, Express, etc. with real-time rates
- 🗺️ **Region-based filtering** — Cities filtered by Bulgarian province

### For Merchants
- ⚡ **HPOS compatible** — High-Performance Order Storage support
- 🔄 **Background data sync** — Cities & offices updated via Action Scheduler
- 🧾 **Waybill management** — Generate, print (PDF), cancel waybills from the order screen
- 🚚 **Courier pickup requests** — Request pickup directly from WP admin
- 📊 **Speedy Orders page** — Dedicated admin page for all Speedy shipments
- 💳 **Flexible pricing** — Calculator, fixed price, free shipping, CSV custom prices, or calculator + surcharge
- 🎁 **Free shipping thresholds** — Per delivery type (address/office/automat)
- ✅ **Credential validation** — Real-time API key verification on save
- 🇧🇬 **Bulgarian translation included**

---

## 🤝 Sibling Plugin — Also Ship via Econt?

This plugin has a sibling for the **Econt** courier: **[Drusoft Shipping for Econt](https://wordpress.org/plugins/drusoft-shipping-for-econt/)**.

Both plugins share the same checkout UI, settings layout, and admin order-management screens — once you've learned one, the other feels familiar. Install both if your store supports delivery via either courier.

---

## 📦 Installation

1. Download the [latest release](https://github.com/ventzie555/drusoft-shipping-for-sameday/releases) or clone this repo
2. Upload the `drusoft-shipping-for-sameday` folder to `/wp-content/plugins/`
3. Ensure **WooCommerce** is installed and active.
4. Activate the plugin in **Plugins → Installed Plugins**
5. Go to **WooCommerce → Settings → Shipping → Shipping Zones**
6. Add/edit a zone (e.g. "Bulgaria") → **Add shipping method** → select **Drusoft Shipping for Speedy**
7. Enter your **Speedy API credentials** and click **Save Changes**
8. Background sync of cities and offices starts automatically

### Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- Speedy API credentials (contact [Speedy Bulgaria](https://www.speedy.bg/))

---

## ⚙️ Configuration

### API Connection
| Setting | Description |
|---------|-------------|
| Username & Password | Your Speedy REST API credentials |
| Module Status | Enable/disable the shipping method |
| Method Title | Display name at checkout |

### Sender Information
- Sender profile (Speedy client object)
- Contact details for waybills (name, email, phone)
- Ship-from-office option
- Working day end time for pickup scheduling

### Shipment Settings
- Active services (Economy, Express, etc.)
- Default packaging type and weight
- Declared value, fragile, Saturday delivery options

### Pricing Methods

| Method | Description |
|--------|-------------|
| **Speedy Calculator** | Real-time API rates based on weight, destination, service |
| **Fixed Price** | Flat rate per delivery type (address/office/automat) |
| **Free Shipping** | Always free, or above a configurable threshold |
| **Custom CSV** | Upload a CSV for complex weight/total-based rules |
| **Calculator + Surcharge** | API price + fixed fee |

### Payment & Fiscal
- Cash on Delivery (COD) and Postal Money Transfer
- Fiscal receipt handling for Speedy deliveries

---

## 🛠️ Technical Details

### Database
Creates two custom tables for cached location data:
- `{prefix}_drushfs_cities` — ~5,300 Bulgarian cities/villages
- `{prefix}_drushfs_offices` — ~1,200 Speedy offices and automats

### Background Sync
The `drushfs_sync_locations_event` action runs via WooCommerce Action Scheduler. Monitor it at **WooCommerce → Status → Scheduled Actions**.

### API Integration
All communication with `https://api.speedy.bg/v1/` — endpoints used:
- `/location/site` — City search
- `/location/office` — Office lookup
- `/location/street` — Street autocomplete
- `/calculate` — Shipping rate calculation
- `/shipment` — Waybill creation
- `/print` — PDF label generation
- `/pickup` — Courier pickup requests
- `/client/contract` — Credential validation

### Security
- All AJAX handlers are nonce-protected (separate public/admin/actions scopes)
- All database queries use `$wpdb->prepare()`
- File uploads use `wp_handle_upload()`
- API calls use `wp_remote_post()` (no raw cURL)

---

## 📄 License

This plugin is licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).

Developed and maintained by **[DRUSOFT LTD](https://drusoft.dev/)**.

---

## 🤝 Contributing

Contributions are welcome! Please open an issue or submit a pull request.

For support or custom feature requests, contact [DRUSOFT LTD](https://drusoft.dev/).

---

---

# Drusoft Shipping for Speedy — WooCommerce плъгин 🇧🇬

Високопроизводителна и безконфликтна интеграция на **Спиди** куриерски услуги за **WooCommerce** магазини в България. Осигурява изчисляване на цени в реално време, генериране на товарителници, избор на офис/автомат и пълно управление на поръчки — през официалния Speedy REST API.

> **⚠️ Забележка:** Плъгинът изисква класическите страници за Количка и Плащане (`[woocommerce_cart]` / `[woocommerce_checkout]`). WooCommerce Block Checkout все още не се поддържа.

## ✨ Основни функции

### За клиенти
- 🏙️ **Динамичен избор на град и офис** в реално време при поръчка
- 📦 **Няколко вида доставка** — до Адрес, Офис на Спиди или Автомат (APS)
- 🔍 **Интелигентно търсене на улици** с автоматично довършване
- 💰 **Избор на услуга с актуализация на цената** в реално време
- 🗺️ **Филтриране по област** — градовете се зареждат по избраната област

### За търговци
- ⚡ **HPOS съвместим** — поддръжка на High-Performance Order Storage
- 🔄 **Фоново синхронизиране** на градове и офиси чрез Action Scheduler
- 🧾 **Управление на товарителници** — генериране, печат (PDF), отмяна
- 🚚 **Заявка за куриер** директно от админ панела
- 📊 **Страница Поръчки Спиди** — специализиран изглед за всички пратки
- 💳 **Гъвкаво ценообразуване** — калкулатор, фиксирана цена, безплатна доставка, CSV, калкулатор + надбавка
- 🎁 **Прагове за безплатна доставка** по тип доставка
- ✅ **Валидация на API данни** в реално време
- 🇧🇬 **Включен български превод**

## 🤝 Сестрински плъгин — изпращате и с Иконт?

Този плъгин има сестрински плъгин за куриер **Иконт**: **[Drusoft Shipping for Econt](https://wordpress.org/plugins/drusoft-shipping-for-econt/)**.

Двата плъгина споделят един и същ потребителски интерфейс при поръчка, разположение на настройките и админ изгледи за управление на поръчки — щом сте свикнали с единия, другият ще ви е познат. Инсталирайте и двата, ако магазинът ви поддържа доставка чрез който и да е от двата куриера.

## 📦 Инсталация

1. Изтеглете [последната версия](https://github.com/ventzie555/drusoft-shipping-for-sameday/releases) или клонирайте репото
2. Качете папката `drusoft-shipping-for-sameday` в `/wp-content/plugins/`
3. Активирайте плъгина от **Разширения → Инсталирани разширения**
4. Отидете в **WooCommerce → Настройки → Доставка → Зони за доставка**
5. Добавете/редактирайте зона (напр. „България") → **Добави метод** → изберете **Drusoft Shipping for Speedy**
6. Въведете вашите **Спиди API данни** и натиснете **Запази промените**
7. Синхронизирането на градове и офиси започва автоматично

### Изисквания
- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- Спиди API данни (свържете се със [Спиди](https://www.speedy.bg/))

---

## 📄 Лиценз

Лицензиран под [GNU General Public License v2 или по-късна версия](https://www.gnu.org/licenses/gpl-2.0.html).

Разработен и поддържан от **[ДРУСОФТ ЕООД](https://drusoft.dev/)**.

*За поддръжка или заявки за персонализирани функции, свържете се с [ДРУСОФТ ЕООД](https://drusoft.dev/).*
