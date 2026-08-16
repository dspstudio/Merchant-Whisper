<?php
/**
 * Optional Slack Incoming Webhook (test ping + weekly digest).
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slack webhook helper.
 */
class MW_Sales_Toast_Slack {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'mw_st_slack_digest';

	/**
	 * Option key for last digest send day (Y-m-d UTC).
	 */
	const LAST_SENT_OPTION = 'mw_st_slack_last';

	/**
	 * Register AJAX + cron.
	 */
	public static function init() {
		add_action( 'wp_ajax_mw_st_slack_test', array( __CLASS__, 'ajax_test' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_digest_cron' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_cron' ) );
	}

	/**
	 * Validate a Slack Incoming Webhook URL.
	 *
	 * @param mixed $raw Raw URL.
	 * @return string Empty or sanitized URL.
	 */
	public static function sanitize_webhook( $raw ) {
		$url = esc_url_raw( trim( (string) $raw ) );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$host = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( 'hooks.slack.com' !== $host ) {
			return '';
		}
		if ( 0 !== strpos( $path, '/services/' ) ) {
			return '';
		}
		if ( ! empty( $parts['scheme'] ) && 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * POST JSON to a Slack webhook.
	 *
	 * @param string               $url     Webhook URL.
	 * @param array<string, mixed> $payload Slack payload.
	 * @return true|WP_Error
	 */
	public static function post( $url, $payload ) {
		$url = self::sanitize_webhook( $url );
		if ( '' === $url ) {
			return new WP_Error( 'mw_st_slack_url', __( 'Enter a valid Slack Incoming Webhook URL.', 'mw-sales-toast' ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( $code < 200 || $code >= 300 || ( '' !== $body && 'ok' !== $body ) ) {
			return new WP_Error(
				'mw_st_slack_http',
				__( 'Slack rejected the message. Check the webhook URL and try again.', 'mw-sales-toast' )
			);
		}

		return true;
	}

	/**
	 * Host label for messages (no path).
	 *
	 * @return string
	 */
	public static function site_host() {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $host : home_url( '/' );
	}

	/**
	 * Admin statistics URL.
	 *
	 * @return string
	 */
	public static function stats_url() {
		return add_query_arg(
			array(
				'page' => 'mw-sales-toast',
				'tab'  => 'statistics',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a Slack payload from text + mrkdwn section.
	 *
	 * @param string $fallback Plain text fallback.
	 * @param string $mrkdwn   Section mrkdwn.
	 * @return array<string, mixed>
	 */
	public static function payload( $fallback, $mrkdwn ) {
		return array(
			'text'   => $fallback,
			'blocks' => array(
				array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => $mrkdwn,
					),
				),
			),
		);
	}

	/**
	 * Test message payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_payload() {
		$host = self::site_host();
		$mrkdwn = sprintf(
			/* translators: 1: plugin name, 2: site host */
			__( "*%1\$s* is connected for `%2\$s`.\nWeekly digests will use this channel when enabled.", 'mw-sales-toast' ),
			MW_SALES_TOAST_NAME,
			$host
		);
		$fallback = sprintf(
			/* translators: 1: plugin name, 2: site host */
			__( '%1$s is connected for %2$s.', 'mw-sales-toast' ),
			MW_SALES_TOAST_NAME,
			$host
		);
		return self::payload( $fallback, $mrkdwn );
	}

	/**
	 * Weekly digest mrkdwn + fallback from analytics.
	 *
	 * @return array{0:string,1:string}|WP_Error Fallback text, mrkdwn.
	 */
	public static function digest_text() {
		if ( ! class_exists( 'MW_Sales_Toast_Analytics' ) || ! MW_Sales_Toast_Analytics::is_enabled() ) {
			return new WP_Error( 'mw_st_slack_analytics', __( 'Statistics collection is off. Turn it on under Statistics → Collection.', 'mw-sales-toast' ) );
		}

		$summary = MW_Sales_Toast_Analytics::summarize( 7 );
		$host    = self::site_host();
		$stats   = self::stats_url();

		$lines = array(
			sprintf(
				/* translators: 1: plugin name, 2: site host */
				__( '*%1$s* — last 7 days on `%2$s`', 'mw-sales-toast' ),
				MW_SALES_TOAST_NAME,
				$host
			),
			'',
			sprintf(
				/* translators: %s: number */
				__( '• Impressions: *%s*', 'mw-sales-toast' ),
				number_format_i18n( (int) ( $summary['impressions'] ?? 0 ) )
			),
			sprintf(
				/* translators: %s: number */
				__( '• Clicks: *%s*', 'mw-sales-toast' ),
				number_format_i18n( (int) ( $summary['clicks'] ?? 0 ) )
			),
			sprintf(
				/* translators: %s: percentage */
				__( '• CTR: *%s%%*', 'mw-sales-toast' ),
				(string) ( $summary['ctr'] ?? 0 )
			),
			sprintf(
				/* translators: %s: number */
				__( '• Attributed carts: *%s*', 'mw-sales-toast' ),
				number_format_i18n( (int) ( $summary['atc'] ?? 0 ) )
			),
			sprintf(
				/* translators: %s: number */
				__( '• Attributed orders: *%s*', 'mw-sales-toast' ),
				number_format_i18n( (int) ( $summary['purchases'] ?? 0 ) )
			),
			sprintf(
				/* translators: %s: money label */
				__( '• Attributed revenue: *%s*', 'mw-sales-toast' ),
				(string) ( $summary['revenueLabel'] ?? '0' )
			),
			'',
			sprintf(
				'<%s|%s>',
				esc_url( $stats ),
				__( 'Open Statistics', 'mw-sales-toast' )
			),
		);

		$mrkdwn   = implode( "\n", $lines );
		$fallback = sprintf(
			/* translators: 1: plugin name, 2: site host, 3: impressions, 4: clicks, 5: CTR, 6: carts, 7: orders, 8: revenue */
			__( '%1$s — last 7 days on %2$s: %3$s impressions, %4$s clicks (%5$s%% CTR), %6$s carts, %7$s orders, %8$s revenue.', 'mw-sales-toast' ),
			MW_SALES_TOAST_NAME,
			$host,
			number_format_i18n( (int) ( $summary['impressions'] ?? 0 ) ),
			number_format_i18n( (int) ( $summary['clicks'] ?? 0 ) ),
			(string) ( $summary['ctr'] ?? 0 ),
			number_format_i18n( (int) ( $summary['atc'] ?? 0 ) ),
			number_format_i18n( (int) ( $summary['purchases'] ?? 0 ) ),
			(string) ( $summary['revenueLabel'] ?? '0' )
		);

		return array( $fallback, $mrkdwn );
	}

	/**
	 * AJAX: send a test Slack message.
	 */
	public static function ajax_test() {
		$cap = class_exists( 'MW_Sales_Toast_Settings' )
			? MW_Sales_Toast_Settings::capability()
			: ( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' );
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to test Slack.', 'mw-sales-toast' ) ), 403 );
		}

		check_ajax_referer( 'mw_st_slack_test', 'nonce' );

		$user_id = get_current_user_id();
		$lock    = 'mw_st_slack_test_lock_' . $user_id;
		if ( get_transient( $lock ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait a minute before sending another test.', 'mw-sales-toast' ) ), 429 );
		}

		$url = isset( $_POST['webhook'] ) ? self::sanitize_webhook( wp_unslash( $_POST['webhook'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $url && class_exists( 'MW_Sales_Toast_Settings' ) ) {
			$s   = MW_Sales_Toast_Settings::get();
			$url = self::sanitize_webhook( $s['slack_webhook'] ?? '' );
		}
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid Slack Incoming Webhook URL.', 'mw-sales-toast' ) ), 400 );
		}

		$result = self::post( $url, self::test_payload() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		set_transient( $lock, 1, MINUTE_IN_SECONDS );
		wp_send_json_success(
			array(
				'message' => __( 'Test message sent. Check your Slack channel.', 'mw-sales-toast' ),
			)
		);
	}

	/**
	 * Whether weekly digest should run.
	 *
	 * @param array|null $settings Settings snapshot.
	 * @return bool
	 */
	public static function digest_enabled( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : ( class_exists( 'MW_Sales_Toast_Settings' ) ? MW_Sales_Toast_Settings::get() : array() );
		$url      = self::sanitize_webhook( $settings['slack_webhook'] ?? '' );
		$mode     = isset( $settings['slack_digest'] ) ? (string) $settings['slack_digest'] : 'off';
		return '' !== $url && 'weekly' === $mode;
	}

	/**
	 * Schedule or clear the daily digest cron.
	 *
	 * @param array|null $settings Optional settings snapshot.
	 */
	public static function ensure_cron( $settings = null ) {
		$enabled = self::digest_enabled( $settings );
		$next    = wp_next_scheduled( self::CRON_HOOK );

		if ( $enabled ) {
			if ( ! $next ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			}
			return;
		}

		self::clear_cron();
	}

	/**
	 * Clear digest cron events.
	 */
	public static function clear_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Daily cron: send digest on Mondays UTC (once per day).
	 */
	public static function run_digest_cron() {
		if ( ! self::digest_enabled() ) {
			return;
		}

		// Monday = 1 in gmdate( 'N' ).
		if ( '1' !== gmdate( 'N' ) ) {
			return;
		}

		$today = gmdate( 'Y-m-d' );
		$last  = (string) get_option( self::LAST_SENT_OPTION, '' );
		if ( $last === $today ) {
			return;
		}

		$settings = class_exists( 'MW_Sales_Toast_Settings' ) ? MW_Sales_Toast_Settings::get() : array();
		$url      = self::sanitize_webhook( $settings['slack_webhook'] ?? '' );
		if ( '' === $url ) {
			return;
		}

		$text = self::digest_text();
		if ( is_wp_error( $text ) ) {
			return;
		}

		$result = self::post( $url, self::payload( $text[0], $text[1] ) );
		if ( is_wp_error( $result ) ) {
			return;
		}

		update_option( self::LAST_SENT_OPTION, $today, false );
	}
}
