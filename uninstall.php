<?php
/**
 * Uninstall cleanup.
 *
 * @package MW_Sales_Toast
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Wipe plugin data for the current site.
 */
function mw_sales_toast_uninstall_site() {
	global $wpdb;

	delete_option( 'mw_sales_toast_settings' );
	delete_option( 'mw_st_analytics' );
	delete_option( 'mw_st_stats_schema' );
	delete_option( 'mw_st_cache_schema' );
	delete_option( 'mw_st_slack_last' );
	delete_option( 'mw_st_trp_strings_hash' );

	delete_transient( 'mw_st_sales_cache' );
	delete_transient( 'mw_st_rebuild_lock' );
	delete_transient( 'mw_st_presence' );

	wp_clear_scheduled_hook( 'mw_st_slack_digest' );
	wp_clear_scheduled_hook( 'mw_st_update_sales_cache' );

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return;
	}

	$like_locks = array(
		$wpdb->esc_like( '_transient_mw_st_slack_test_lock_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mw_st_slack_test_lock_' ) . '%',
		$wpdb->esc_like( '_transient_mw_st_support_lock_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_mw_st_support_lock_' ) . '%',
	);
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$like_locks[0],
			$like_locks[1],
			$like_locks[2],
			$like_locks[3]
		)
	);

	$meta_keys = array( '_mw_st_allow_public', '_mw_st_attr_tracked' );
	foreach ( $meta_keys as $meta_key ) {
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
	}

	$orders_meta = $wpdb->prefix . 'wc_orders_meta';
	$found       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_meta ) ) );
	if ( $found === $orders_meta ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$orders_meta} WHERE meta_key IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$meta_keys[0],
				$meta_keys[1]
			)
		);
	}

	$table = $wpdb->prefix . 'mw_st_stats';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		mw_sales_toast_uninstall_site();
		restore_current_blog();
	}
} else {
	mw_sales_toast_uninstall_site();
}
