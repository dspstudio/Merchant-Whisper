=== Merchant Whisper ===
Contributors: mwv3
Tags: woocommerce, ecommerce, notifications, social-proof, popup
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 0.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Social proof toasts for WooCommerce: recent purchases, viewing counts, reviews, and promos, with checkout consent and cached orders.

== Description ==

Merchant Whisper shows discreet sale and activity toasts on your storefront. Real WooCommerce orders are cached in the background so product pages do not run heavy order queries.

**Toast types**

* Purchases from recent processing and completed orders
* Viewing now (simulated count or live unique visitors on the product page)
* Approved product reviews
* Promo / coupon with an optional copyable code

**Privacy**

* Optional checkout consent (off by default on the checkbox; recommended setting is on)
* First name and city only — never email or full address
* Hide names and use a fallback such as “Someone”
* First-party statistics (counts and product IDs in a table on your site). Off is available under Statistics → Collection
* No third-party tracking pixels for toast delivery

**Optional outbound requests (admin-initiated)**

* Support form on the Support tab: sending a message posts to Webformatic so the author can reply. You choose whether to include system info (WordPress, PHP, WooCommerce, theme).
* HTTPS webhook on the Account tab (off by default): weekly aggregate stats JSON. Slack Incoming Webhooks are the documented example. Local/private URLs are blocked.

WooCommerce is required for real orders. Demo / simulated toasts can run without it.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/mw-sales-toast`, or install the zip via Plugins → Add New.
2. Activate Merchant Whisper.
3. Open WooCommerce → Merchant Whisper (or Settings → Merchant Whisper if WooCommerce is inactive).
4. Choose a data source, keep checkout consent on if you show real orders, then save.

== Frequently Asked Questions ==

= Does this phone home? =

Toast delivery and statistics stay on your WordPress site. The only outbound HTTP is when an administrator sends the Support form (Webformatic) or saves an HTTPS webhook and enables the weekly digest. The Account newsletter checkbox is stored on your site only; it does not subscribe anyone until that feature ships.

= Where is the source for minified JavaScript? =

Chart.js 4.4.8 (MIT) is bundled as `assets/vendor/chart.umd.min.js` for the Statistics chart. Source: https://github.com/chartjs/Chart.js/tree/v4.4.8

= Is this GDPR friendly? =

You can require checkout consent before an order appears in toasts, hide names, and turn off first-party statistics. Document the checkout checkbox and the optional webhook/support flows in your own privacy policy.

= Will this slow down the shop? =

Recent orders are queried on WP-Cron and order hooks, then stored in a transient. Storefront requests load a small REST payload (or inline events), not a live `wc_get_orders()` on every page view.

== Changelog ==

= 0.9.1 =
* Settings tabs are also WordPress admin submenu items under Merchant Whisper
* Coupon copy starts the same attribution window as a product-link click
* By source statistics include carts, orders, and revenue
* Account webhook is a general HTTPS JSON digest (Slack Incoming Webhooks remain the example)

= 2.2.3 =
* Weekly digest webhook accepts any public HTTPS JSON URL; Slack Incoming Webhooks remain the example

= 2.2.2 =
* WordPress.org packaging: GPL license header, readme.txt, Chart.js source attribution
* Newsletter opt-in is a local placeholder — nothing is emailed until the feature ships

= 2.2.1 =
* Multilingual toast copy for Polylang, WPML, and TranslatePress

= 2.2.0 =
* Extra toast types: viewing now, product reviews, and CTA/coupon

= 2.1.0 =
* Triggers: page load, scroll, exit intent, add to cart, inactivity, click selector

= 2.0.0 =
* Cached orders, REST delivery, privacy consent, session mute/cap

= 1.1.0 =
* Admin settings; hover-pause; product image/title links

= 1.0.0 =
* Initial toast UI and real/demo mix

== Upgrade Notice ==

= 0.9.1 =
Public version is now 0.9.1. Coupon copy attributes carts and orders; settings tabs appear under Merchant Whisper in the admin menu.

= 2.2.3 =
The Account webhook field is a general HTTPS JSON hook. Slack Incoming Webhooks still work as before.

= 2.2.2 =
Adds GPL metadata and directory readme. Newsletter preference stays local until mail is implemented.
