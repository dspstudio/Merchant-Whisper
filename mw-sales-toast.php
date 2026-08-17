<?php
/**
 * Plugin Name: Merchant Whisper
 * Description: Social proof for WooCommerce — recent purchases, viewing counts, reviews, and promo notices. Cached real orders, privacy controls, optional demo fill.
 * Version: 0.9.1
 * Author: MWV3
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mw-sales-toast
 */

defined( 'ABSPATH' ) || exit;

define( 'MW_SALES_TOAST_VERSION', '0.9.1' );
define( 'MW_SALES_TOAST_NAME', 'Merchant Whisper' );
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
require_once MW_SALES_TOAST_PATH . 'includes/class-language.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-transfer.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-cache.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-types.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-rest.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-analytics.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-privacy.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-frontend.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-support.php';
require_once MW_SALES_TOAST_PATH . 'includes/class-slack.php';

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
			__( 'Every %d minutes (Merchant Whisper)', 'mw-sales-toast' ),
			$minutes
		),
	);

	return $schedules;
}
add_filter( 'cron_schedules', 'mw_sales_toast_cron_schedules' );

/**
 * Per-site setup: cron, cache, analytics table.
 */
function mw_sales_toast_activate_site() {
	MW_Sales_Toast_Cache::ensure_cron();
	MW_Sales_Toast_Cache::rebuild();
	if ( class_exists( 'MW_Sales_Toast_Analytics' ) ) {
		MW_Sales_Toast_Analytics::maybe_install();
	}
	if ( class_exists( 'MW_Sales_Toast_Slack' ) ) {
		MW_Sales_Toast_Slack::ensure_cron();
	}
}

/**
 * Activate on one site or every site in a network.
 *
 * @param bool $network_wide Network activation flag.
 */
function mw_sales_toast_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			mw_sales_toast_activate_site();
			restore_current_blog();
		}
		return;
	}

	mw_sales_toast_activate_site();
}
register_activation_hook( __FILE__, 'mw_sales_toast_activate' );

/**
 * Bootstrap a new subsite when the plugin is network-active.
 *
 * @param int $blog_id New site ID.
 */
function mw_sales_toast_on_new_blog( $blog_id ) {
	if ( ! is_multisite() ) {
		return;
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active_for_network( plugin_basename( MW_SALES_TOAST_FILE ) ) ) {
		return;
	}

	switch_to_blog( (int) $blog_id );
	mw_sales_toast_activate_site();
	restore_current_blog();
}
add_action( 'wpmu_new_blog', 'mw_sales_toast_on_new_blog', 10, 1 );

/**
 * Per-site deactivation: clear scheduled crons.
 */
function mw_sales_toast_deactivate_site() {
	MW_Sales_Toast_Cache::clear_cron();
	if ( class_exists( 'MW_Sales_Toast_Slack' ) ) {
		MW_Sales_Toast_Slack::clear_cron();
	}
}

/**
 * Deactivate on one site or every site in a network.
 *
 * @param bool $network_wide Network deactivation flag.
 */
function mw_sales_toast_deactivate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			mw_sales_toast_deactivate_site();
			restore_current_blog();
		}
		return;
	}

	mw_sales_toast_deactivate_site();
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
	load_plugin_textdomain( 'mw-sales-toast' );
	mw_sales_toast_maybe_flush_cache_schema();
	MW_Sales_Toast_Settings::init();
	if ( class_exists( 'MW_Sales_Toast_Language' ) ) {
		MW_Sales_Toast_Language::init();
	}
	MW_Sales_Toast_Transfer::init();
	MW_Sales_Toast_Cache::init();
	MW_Sales_Toast_Types::init();
	MW_Sales_Toast_REST::init();
	MW_Sales_Toast_Analytics::init();
	MW_Sales_Toast_Privacy::init();
	MW_Sales_Toast_Frontend::init();
	MW_Sales_Toast_Support::init();
	MW_Sales_Toast_Slack::init();
}
add_action( 'plugins_loaded', 'mw_sales_toast_init' );
