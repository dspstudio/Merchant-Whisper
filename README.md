# MW Sales Toast

**Version 2.0.0** — Recent-purchase social-proof toasts for WooCommerce: cached real orders, privacy controls, and optional demo fill.

Requires WordPress 5.8+, PHP 7.2+. WooCommerce is required for real orders; demo mode can run without it.

## Features

### Caching & performance
- Real orders loaded with `wc_get_orders()` (HPOS-safe): statuses `processing` + `completed`
- Results stored in transient `mw_st_sales_cache` (≈5 minute TTL)
- WP-Cron every five minutes (`mw_st_update_sales_cache`)
- Debounced rebuild on `woocommerce_new_order`, `woocommerce_order_status_processing`, `woocommerce_order_status_completed`, and payment complete
- **No `wc_get_orders` on normal page views** — only cron, order hooks, or REST cache miss

### REST delivery
- Public endpoint: `GET /wp-json/mw-st/v1/notifications`
- Front end localizes endpoint + display settings only (not the full event list)
- JS fetches on load, cycles toasts, and lightly re-fetches every few minutes while the tab stays open

### Data sources
| Mode | Behavior |
|------|----------|
| Real orders only | Recent consented/eligible orders |
| Demo / simulated only | Configured demo people + catalog products |
| Real then demo (default) | Prefer real orders; fill with demo when few/no sales |

### Privacy / GDPR
- **Require checkout consent** — only show real orders when the customer opted in
- Checkout checkbox (unchecked by default), classic + block checkout
- Order meta: `_mw_st_allow_public` (`yes` / `no`)
- **Hide names** — always use the fallback name (default “Someone”)
- Only first name and city are shown — never email or full address
- No external network calls
- New installs default consent **on**; upgraded 1.x installs keep it **off** until settings are saved

### Message & session controls
- Message template with `{name}`, `{city}`, `{product}`
- Fallback name
- Max toasts per session (`sessionStorage`)
- Mute after dismiss via `localStorage` (hours; `0` = dismiss current only)
- Optional disable below 768px viewport width

### Display & UX
- Corner positions (bottom/top × left/right)
- Timing: first delay, interval, visible duration
- Product thumbnail; image and title link to the product
- Hide on cart & checkout; entire site or shop/products only
- Respect `prefers-reduced-motion`
- Hover pauses hide/loop timers
- Dismiss control; `role="status"` / polite live region
- Safe escaping; product link is the only intentional HTML in the line
- Empty REST response → no toast loop

### Demo mode
- Demo people (`Name, City` per line) and relative times
- Products pulled from the published catalog when available
- Labeled clearly in admin; useful for low-traffic stores

## Architecture

```text
WooCommerce order ──► status / new_order hooks ──┐
WP-Cron (5 min) ───────────────────────────────►├─► rebuild ──► transient mw_st_sales_cache
                                                 │
Visitor JS ──► GET mw-st/v1/notifications ──────┘──► toast UI
```

## Settings

**WooCommerce → Sales Toast** (falls back to **Settings → Sales Toast** if WooCommerce is inactive). Capability: `manage_woocommerce` (or `manage_options` without WC).

| Setting | Purpose |
|---------|---------|
| Enable | Show toasts on the front end |
| Position | Corner placement |
| Data source | real / demo / real_then_demo |
| Show on | Entire site or shop/products |
| Hide on cart & checkout | Skip cart/checkout pages |
| Disable on mobile | Hide below 768px |
| Product image | Show thumbnail |
| Reduced motion | Skip when visitor prefers reduced motion |
| Message template | `{name}` `{city}` `{product}` |
| Fallback name | Default “Someone” |
| Hide names | Always use fallback name |
| Require checkout consent | Privacy gate for real orders |
| Mute after dismiss (hours) | localStorage mute TTL |
| Max toasts per session | Cap FOMO fatigue |
| Timing | Delay / interval / visible for (seconds) |
| Max events shown | Cap returned/cycled events |
| Max cached orders | Cap rebuild query size |
| Order lookback (days) | Query window (default 30) |
| Demo people / times | Simulated social proof |

## How checkout consent works

1. With **Require checkout consent** enabled, checkout shows an opt-in checkbox.
2. On place order, the choice is saved as `_mw_st_allow_public`.
3. When the sales cache rebuilds, orders without `yes` are skipped if consent is required.
4. With consent off, recent processing/completed orders can appear (subject to other settings).

## Installation

1. Place this folder in `wp-content/plugins/mw-sales-toast`.
2. Activate **MW Sales Toast** (re-activate after upgrading to 2.0 so cron is scheduled).
3. Configure **WooCommerce → Sales Toast**.
4. Confirm toasts appear after the configured delay when the REST endpoint returns events.

## File structure

```text
mw-sales-toast/
├── mw-sales-toast.php          Bootstrap, constants, activation/deactivation
├── uninstall.php               Deletes option + transients
├── README.md
├── assets/
│   ├── toast.js                REST fetch, mute, session cap, hover-pause
│   └── toast.css
└── includes/
    ├── class-settings.php      Options + admin UI
    ├── class-cache.php         Rebuild + cron/order hooks
    ├── class-rest.php          GET mw-st/v1/notifications
    ├── class-privacy.php       Checkout consent meta
    └── class-frontend.php      Enqueue + page gates
```

## Out of scope (later / Pro)

Review notifications, view-based fake sales, geolocation targeting, analytics dashboards, and WP.org submission packaging.

## Keywords / discovery

Terms people may use in **WordPress Admin → Plugins → Add New** or Google:

### WordPress plugin search
- woocommerce social proof
- recent sales
- sales notification
- purchase notification
- sales popup
- fomo
- live sales
- recent purchases
- order notification
- conversion popup
- social proof popup
- woocommerce notification
- sales toast
- customer activity
- who bought

### Google
- woocommerce recent sales notification
- woocommerce social proof plugin
- woocommerce purchase popup
- show recent purchases woocommerce
- someone just bought woocommerce
- woocommerce fomo notification
- woocommerce live sales feed
- recent sales popup wordpress
- woocommerce sales notification plugin
- fake sales notification woocommerce
- demo sales popup woocommerce
- gdpr social proof woocommerce
- privacy friendly sales notification
- woocommerce conversion social proof
- proof / fomo alternative woocommerce

### Primary targets
1. woocommerce social proof
2. recent sales notification
3. purchase notification
4. sales popup
5. fomo

## Changelog

### 2.0.0
- Modular structure; cached orders; cron + order hooks; REST delivery
- Privacy consent, hide names, message template, session mute/cap, mobile gate
- Expanded settings; version bump from 1.x MVP

### 1.1.0
- Admin settings; hover-pause; product image/title links

### 1.0.0
- Initial toast UI and real/demo mix
