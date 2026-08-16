<?php
/**
 * Uninstall cleanup.
 *
 * @package MW_Sales_Toast
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mw_sales_toast_settings' );
delete_option( 'mw_st_analytics' );
delete_option( 'mw_st_stats_schema' );
delete_option( 'mw_st_cache_schema' );
delete_option( 'mw_st_slack_last' );
delete_transient( 'mw_st_sales_cache' );
delete_transient( 'mw_st_rebuild_lock' );
delete_transient( 'mw_st_presence' );

$slack_cron = wp_next_scheduled( 'mw_st_slack_digest' );
while ( $slack_cron ) {
	wp_unschedule_event( $slack_cron, 'mw_st_slack_digest' );
	$slack_cron = wp_next_scheduled( 'mw_st_slack_digest' );
}

global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) ) {
	$table = $wpdb->prefix . 'mw_st_stats';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
