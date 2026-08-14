<?php
/**
 * Plugin Name: MW Proof
 * Description: Social proof for WooCommerce — recent purchases, viewing counts, reviews, and promo notices. Cached real orders, privacy controls, optional demo fill.
 * Version: 2.2.0
 * Author: MWV3
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * Text Domain: mw-sales-toast
 */

defined( 'ABSPATH' ) || exit;

define( 'MW_SALES_TOAST_VERSION', '2.2.0' );
define( 'MW_SALES_TOAST_NAME', 'MW Proof' );
define( 'MW_SALES_TOAST_FILE', __FILE__ );
define( 'MW_SALES_TOAST_URL', plugin_dir_url( __FILE__ ) );
define( 'MW_SALES_TOAST_PATH', plugin_dir_path( __FILE__ ) );
define( 'MW_SALES_TOAST_OPTION', 'mw_sales_toast_settings' );
define( 'MW_SALES_TOAST_TRANSIENT', 'mw_st_sales_cache' );
define( 'MW_SALES_TOAST_CRON', 'mw_st_update_sales_cache' );
define( 'MW_SALES_TOAST_CONSENT_META', '_mw_st_allow_public' );
/** Bump when cached event shape / eligibility rules change. */
define( 'MW_SALES_TOAST_CACHE_SCHEMA', 11 );

require_once MW_SALES_TOAST_PATH . 'includes/class-settings.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-transfer.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-cache.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-types.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-rest.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-analytics.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-privacy.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-frontend.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-support.php';

/**
 * Add custom cron interval from settings.
 *
 * @param array $schedules Schedules.
 * @return array
 */
function mw_sales_toast_cron_schedules( $schedules ) {
	$seconds = class_exists( 'MW_Sales_Toast_Cache' )
		? MW_Sales_Toast_Cache::cron_interval_seconds()
		: 5 * MINUTE_IN_SECONDS;
	$minutes = max( 1, (int) round( $seconds / MINUTE_IN_SECONDS ) );

	$schedules[ MW_Sales_Toast_Cache::CRON_SCHEDULE ] = array(
		'interval' => $seconds,
		'display'  => sprintf(
			/* translators: %d: minutes */
			__( 'Every %d minutes (MW Proof)', 'mw-sales-toast' ),
			$minutes
		),
	);

	return $schedules;
}
add_filter( 'cron_schedules', 'mw_sales_toast_cron_schedules' );

/**
 * Activate: schedule cache rebuild.
 */
function mw_sales_toast_activate() {
	MW_Sales_Toast_Cache::ensure_cron();
	MW_Sales_Toast_Cache::rebuild();
}
register_activation_hook( __FILE__, 'mw_sales_toast_activate' );

/**
 * Deactivate: clear cron.
 */
function mw_sales_toast_deactivate() {
	MW_Sales_Toast_Cache::clear_cron();
}
register_deactivation_hook( __FILE__, 'mw_sales_toast_deactivate' );

/**
 * Drop stale sales cache after eligibility / schema changes.
 */
function mw_sales_toast_maybe_flush_cache_schema() {
	$stored = (int) get_option( 'mw_st_cache_schema', 0 );
	if ( $stored === (int) MW_SALES_TOAST_CACHE_SCHEMA ) {
		return;
	}
	delete_transient( MW_SALES_TOAST_TRANSIENT );
	update_option( 'mw_st_cache_schema', (int) MW_SALES_TOAST_CACHE_SCHEMA, false );
}

/**
 * Boot plugin modules.
 */
function mw_sales_toast_init() {
	mw_sales_toast_maybe_flush_cache_schema();
	MW_Sales_Toast_Settings::init();
	MW_Sales_Toast_Transfer::init();
	MW_Sales_Toast_Cache::init();
	MW_Sales_Toast_Types::init();
	MW_Sales_Toast_REST::init();
	MW_Sales_Toast_Analytics::init();
	MW_Sales_Toast_Privacy::init();
	MW_Sales_Toast_Frontend::init();
	MW_Sales_Toast_Support::init();
}
add_action( 'plugins_loaded', 'mw_sales_toast_init' );
