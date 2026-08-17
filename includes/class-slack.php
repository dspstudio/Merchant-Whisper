<?php
/**
 * Optional HTTPS webhook (test ping + scheduled digest). Slack Incoming Webhooks are the documented example.
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * HTTPS webhook helper (Slack-compatible JSON).
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
	 * Validate an HTTPS webhook URL (public hosts only).
	 *
	 * @param mixed $raw Raw URL.
	 * @return string Empty or sanitized URL.
	 */
	public static function sanitize_webhook( $raw ) {
		$url = esc_url_raw( trim( (string) $raw ), array( 'https' ) );
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return '';
		}
		if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * POST JSON to a webhook (Slack Incoming Webhooks and other HTTPS receivers).
	 *
	 * @param string               $url     Webhook URL.
	 * @param array<string, mixed> $payload JSON body.
	 * @return int|WP_Error HTTP status on success.
	 */
	public static function post( $url, $payload ) {
		$url = self::sanitize_webhook( $url );
		if ( '' === $url ) {
			return new WP_Error( 'mw_st_slack_url', __( 'Enter a valid HTTPS webhook URL.', 'mw-sales-toast' ) );
		}

		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
				'user-agent'  => 'Merchant Whisper/' . MW_SALES_TOAST_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			$transport = $response->get_error_message();
			return new WP_Error(
				'mw_st_slack_transport',
				sprintf(
					/* translators: %s: transport error from WordPress HTTP API */
					__( 'No HTTP response (%s).', 'mw-sales-toast' ),
					$transport
				),
				array( 'status' => 0 )
			);
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$reason = trim( (string) wp_remote_retrieve_response_message( $response ) );
		$label  = $code > 0
			? ( '' !== $reason ? sprintf( 'HTTP %d %s', $code, $reason ) : sprintf( 'HTTP %d', $code ) )
			: __( 'no HTTP status', 'mw-sales-toast' );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'mw_st_slack_http',
				sprintf(
					/* translators: %s: HTTP status, e.g. HTTP 404 Not Found */
					__( 'The webhook did not accept the message (%s). Check the URL and try again.', 'mw-sales-toast' ),
					$label
				),
				array( 'status' => $code )
			);
		}

		return $code;
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
		if ( class_exists( 'MW_Sales_Toast_Settings' ) ) {
			return MW_Sales_Toast_Settings::tab_url( 'statistics' );
		}
		return add_query_arg(
			array(
				'page' => 'mw-sales-toast',
				'tab'  => 'statistics',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a Slack-compatible payload (plain text + blocks) plus extra JSON fields.
	 *
	 * @param string               $fallback Plain text fallback.
	 * @param string               $mrkdwn   Section mrkdwn.
	 * @param array<string, mixed> $extra    Extra keys for generic receivers.
	 * @return array<string, mixed>
	 */
	public static function payload( $fallback, $mrkdwn, $extra = array() ) {
		unset( $extra['text'], $extra['blocks'] );
		return array_merge(
			array(
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
				'plugin' => 'merchant-whisper',
			),
			$extra
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
			__( "*%1\$s* is connected for `%2\$s`.\nScheduled digests will use this webhook when enabled.", 'mw-sales-toast' ),
			MW_SALES_TOAST_NAME,
			$host
		);
		$fallback = sprintf(
			/* translators: 1: plugin name, 2: site host */
			__( '%1$s is connected for %2$s.', 'mw-sales-toast' ),
			MW_SALES_TOAST_NAME,
			$host
		);
		return self::payload(
			$fallback,
			$mrkdwn,
			array(
				'event' => 'test',
			)
		);
	}

	/**
	 * Digest cadence → stats window in days.
	 *
	 * @return array<string, int>
	 */
	public static function digest_intervals() {
		return array(
			'daily'   => 1,
			'3days'   => 3,
			'7days'   => 7,
			'2weeks'  => 14,
			'monthly' => 30,
		);
	}

	/**
	 * Normalize a saved digest mode (legacy weekly → 7 days).
	 *
	 * @param mixed $mode Raw mode.
	 * @return string
	 */
	public static function normalize_digest_mode( $mode ) {
		$mode = is_string( $mode ) ? $mode : 'off';
		if ( 'weekly' === $mode ) {
			$mode = '7days';
		}
		$intervals = self::digest_intervals();
		return isset( $intervals[ $mode ] ) ? $mode : 'off';
	}

	/**
	 * Stats window for a digest mode.
	 *
	 * @param mixed $mode Mode.
	 * @return int 0 if off.
	 */
	public static function digest_days( $mode ) {
		$mode      = self::normalize_digest_mode( $mode );
		$intervals = self::digest_intervals();
		return isset( $intervals[ $mode ] ) ? (int) $intervals[ $mode ] : 0;
	}

	/**
	 * Weekly digest mrkdwn + fallback from analytics.
	 *
	 * @param int $days Window length.
	 * @return array{0:string,1:string}|WP_Error Fallback text, mrkdwn.
	 */
	public static function digest_text( $days = 7 ) {
		if ( ! class_exists( 'MW_Sales_Toast_Analytics' ) || ! MW_Sales_Toast_Analytics::is_enabled() ) {
			return new WP_Error( 'mw_st_slack_analytics', __( 'Statistics collection is off. Turn it on under Statistics → Collection.', 'mw-sales-toast' ) );
		}

		$days    = max( 1, min( 90, (int) $days ) );
		$summary = MW_Sales_Toast_Analytics::summarize( $days );
		$host    = self::site_host();
		$stats   = self::stats_url();
		$period  = sprintf(
			/* translators: %d: number of days */
			_n( 'last %d day', 'last %d days', $days, 'mw-sales-toast' ),
			$days
		);

		$lines = array(
			sprintf(
				/* translators: 1: plugin name, 2: period label, 3: site host */
				__( '*%1$s* — %2$s on `%3$s`', 'mw-sales-toast' ),
				MW_SALES_TOAST_NAME,
				$period,
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
			/* translators: 1: plugin name, 2: period label, 3: site host, 4: impressions, 5: clicks, 6: CTR, 7: carts, 8: orders, 9: revenue */
			__( '%1$s — %2$s on %3$s: %4$s impressions, %5$s clicks (%6$s%% CTR), %7$s carts, %8$s orders, %9$s revenue.', 'mw-sales-toast' ),
			MW_SALES_TOAST_NAME,
			$period,
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
	 * AJAX: send a test webhook payload.
	 */
	public static function ajax_test() {
		$cap = class_exists( 'MW_Sales_Toast_Settings' )
			? MW_Sales_Toast_Settings::capability()
			: ( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' );
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to test the webhook.', 'mw-sales-toast' ) ), 403 );
		}

		check_ajax_referer( 'mw_st_slack_test', 'nonce' );

		$user_id  = get_current_user_id();
		$lock     = 'mw_st_slack_test_lock_' . $user_id;
		$cooldown = 15;
		$until    = (int) get_transient( $lock );
		if ( $until > time() ) {
			$wait = max( 1, $until - time() );
			wp_send_json_error(
				array(
					'message'    => sprintf(
						/* translators: %d: seconds to wait */
						_n( 'Please wait %d second before sending another test.', 'Please wait %d seconds before sending another test.', $wait, 'mw-sales-toast' ),
						$wait
					),
					'retryAfter' => $wait,
				)
			);
		}

		$url = isset( $_POST['webhook'] ) ? self::sanitize_webhook( wp_unslash( $_POST['webhook'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $url && class_exists( 'MW_Sales_Toast_Settings' ) ) {
			$s   = MW_Sales_Toast_Settings::get();
			$url = self::sanitize_webhook( $s['slack_webhook'] ?? '' );
		}
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid HTTPS webhook URL.', 'mw-sales-toast' ) ) );
		}

		$result = self::post( $url, self::test_payload() );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'status'  => is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0,
				)
			);
		}

		$code = (int) $result;
		set_transient( $lock, time() + $cooldown, $cooldown );
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Test message sent (HTTP %d). Check your webhook receiver (e.g. Slack).', 'mw-sales-toast' ),
					$code
				),
				'status'  => $code,
			)
		);
	}

	/**
	 * Whether a digest cadence is active.
	 *
	 * @param array|null $settings Settings snapshot.
	 * @return bool
	 */
	public static function digest_enabled( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : ( class_exists( 'MW_Sales_Toast_Settings' ) ? MW_Sales_Toast_Settings::get() : array() );
		$url      = self::sanitize_webhook( $settings['slack_webhook'] ?? '' );
		$days     = self::digest_days( $settings['slack_digest'] ?? 'off' );
		return '' !== $url && $days > 0;
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
	 * Daily cron: send when the selected interval has elapsed (UTC).
	 */
	public static function run_digest_cron() {
		if ( ! self::digest_enabled() ) {
			return;
		}

		$settings = class_exists( 'MW_Sales_Toast_Settings' ) ? MW_Sales_Toast_Settings::get() : array();
		$days     = self::digest_days( $settings['slack_digest'] ?? 'off' );
		if ( $days < 1 ) {
			return;
		}

		$today = gmdate( 'Y-m-d' );
		$last  = (string) get_option( self::LAST_SENT_OPTION, '' );
		if ( $last === $today ) {
			return;
		}
		if ( '' !== $last ) {
			$last_ts = strtotime( $last . ' UTC' );
			$now_ts  = strtotime( $today . ' UTC' );
			if ( false === $last_ts || false === $now_ts ) {
				return;
			}
			$elapsed = (int) floor( ( $now_ts - $last_ts ) / DAY_IN_SECONDS );
			if ( $elapsed < $days ) {
				return;
			}
		}

		$url = self::sanitize_webhook( $settings['slack_webhook'] ?? '' );
		if ( '' === $url ) {
			return;
		}

		$text = self::digest_text( $days );
		if ( is_wp_error( $text ) ) {
			return;
		}

		$summary = class_exists( 'MW_Sales_Toast_Analytics' )
			? MW_Sales_Toast_Analytics::summarize( $days )
			: array();
		$extra   = array(
			'event'        => 'digest',
			'days'         => $days,
			'impressions'  => (int) ( $summary['impressions'] ?? 0 ),
			'clicks'       => (int) ( $summary['clicks'] ?? 0 ),
			'ctr'          => $summary['ctr'] ?? 0,
			'carts'        => (int) ( $summary['atc'] ?? 0 ),
			'orders'       => (int) ( $summary['purchases'] ?? 0 ),
			'revenueLabel' => (string) ( $summary['revenueLabel'] ?? '0' ),
		);

		$result = self::post( $url, self::payload( $text[0], $text[1], $extra ) );
		if ( is_wp_error( $result ) ) {
			return;
		}

		update_option( self::LAST_SENT_OPTION, $today, false );
	}
}
