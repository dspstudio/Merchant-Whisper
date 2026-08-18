=== Merchant Whisper ===
Contributors: dspstudio
Tags: woocommerce, ecommerce, notifications, social-proof, popup
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.0.0
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
* Hide names and use a fallback such as "Someone"
* First-party statistics (counts and product IDs in a table on your site). Off is available under Statistics → Collection
* No third-party tracking pixels for toast delivery
* Multisite-ready: each subsite has its own settings, cache, statistics, and toasts (no cross-site data sharing)

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

= Does it work on WordPress Multisite? =

Yes. Activate per subsite or network-activate the plugin. Each site keeps its own settings, order cache, statistics table, and webhook URL. One store cannot read or change another site's toast data. Configure each subsite under that site's Merchant Whisper menu (WooCommerce must be active on that subsite for real orders).

== Changelog ==

= 1.0.0 =
* Social proof toasts: recent purchases, viewing now, product reviews, and CTA/coupon
* Cached real orders via WP-Cron and order hooks (HPOS-safe); REST delivery
* Privacy: checkout consent (classic + block), hide names, first-name-and-city only
* Six triggers: page load, scroll, exit intent, add to cart, inactivity, click selector
* Multilingual toast copy for Polylang, WPML, and TranslatePress
* Statistics dashboard with impressions, clicks, CTR, attributed carts/orders/revenue, and CSV export
* Design controls: colors, radius, width, shadow, image-fit, custom CSS, theme JSON import/export
* Targeting: URL include/exclude, product/category filters, product page match, role hide
* Demo mode with configurable people, times, and catalog products
* Settings import/export, optional HTTPS webhook digest
* Multisite-ready: per-site settings, cache, statistics, and crons
* Full uninstall cleanup across all subsites

== Upgrade Notice ==

= 1.0.0 =
First public release on WordPress.org. All features included — no premium tier.
