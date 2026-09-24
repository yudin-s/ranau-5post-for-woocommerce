=== Ranau 5Post for WooCommerce ===
Contributors: yudin-s
Tags: woocommerce, 5post, pickup, shipping, delivery
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.32
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronize 5Post pickup points and let WooCommerce customers choose a compatible point on a map.

== Description ==

Ranau 5Post for WooCommerce is a standalone pickup shipping method for stores that fulfil orders outside WordPress.

Features:

* 5Post pickup-point directory synchronization.
* Parcel-size and weight filtering.
* Yandex Maps point selection, address search, and optional browser geolocation.
* Contract-based tariff settings, markup, handling days, and free-shipping policy.
* Checkout Blocks, classic checkout, and HPOS support.
* Provider-local state machine with server-owned pickup point and quote commits.
* No fulfilment order, label, tracking, or dispatch creation.
* No Ranau account, license key, tracking, or remote Ranau service.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate this plugin.
3. Open WooCommerce > Ranau 5Post and enter the credentials supplied for your 5Post contract plus the Yandex Maps keys.
4. Synchronize pickup points.
5. Add Ranau 5Post to the required shipping zones and configure its tariff rules.
6. Test parcel limits, the point map, totals, checkout validation, and every payment method before launch.

== Frequently Asked Questions ==

= Does this plugin create fulfilment orders in 5Post? =

No. It synchronizes points, calculates the checkout rate, and stores the confirmed point on the WooCommerce order. Fulfilment remains external.

= What data is stored locally? =

The plugin keeps a local pickup-point directory in a dedicated WordPress table so checkout map searches do not call the carrier for every viewport change.

== External services ==

The plugin connects to the 5Post API during manual or scheduled pickup-point synchronization. It sends the store's API key to request a short-lived JWT and then requests paginated pickup-point reference data from `api-omni.x5.ru` or the configured pre-production service. It does not send customer or order data to 5Post and does not create a fulfillment order.

The checkout map loads Yandex Maps JavaScript API. Address search uses Yandex Geocoder and Geosuggest. Those browser requests can include the configured API keys, the shopper's search text, map viewport, IP address, and device information. Browser geolocation is requested only after the shopper explicitly chooses that action.

5Post privacy policy: https://fivepost.ru/static/docs/privacyPolicy.html
5Post user agreement: https://fivepost.ru/static/docs/userLicenseAgreement.html
Yandex Maps API terms: https://yandex.ru/legal/maps_api/ru/
Yandex privacy policy: https://yandex.ru/legal/confidential/

== Privacy ==

The plugin contacts 5Post during directory synchronization and Yandex Maps services for map display, geocoding, and address suggestions when configured. Browser geolocation is requested only after the customer chooses that action. No checkout data is sent to Ranau and no analytics or tracking are added.

== Support ==

Community issues: https://github.com/yudin-s/ranau-5post-for-woocommerce/issues

Optional paid support and custom WooCommerce development are available at https://ranau.uk/ and are not required to use the plugin.

== Changelog ==

= 0.1.32 =

* First independent open-source release candidate.
