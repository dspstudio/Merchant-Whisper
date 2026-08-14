<?php
/**
 * Uninstall cleanup.
 *
 * @package MW_Sales_Toast
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mw_sales_toast_settings' );
delete_option( 'mw_st_analytics' );
delete_option( 'mw_st_cache_schema' );
delete_transient( 'mw_st_sales_cache' );
delete_transient( 'mw_st_rebuild_lock' );
delete_transient( 'mw_st_presence' );
