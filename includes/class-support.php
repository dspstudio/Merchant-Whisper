<?php
/**
 * Admin support contact form (proxies to Webformatic).
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Support request handler.
 */
class MW_Sales_Toast_Support {

	/**
	 * Hook AJAX.
	 */
	public static function init() {
		add_action( 'wp_ajax_mw_st_support_request', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Webformatic submit endpoint.
	 *
	 * @return string
	 */
	public static function endpoint() {
		$default = 'https://webformatic.com/form/4b0487e7-5c67-49ce-bcea-123e1270199c/submit';
		return (string) apply_filters( 'mw_sales_toast_support_endpoint', $default );
	}

	/**
	 * Diagnostic blob for tickets.
	 *
	 * @return string
	 */
	public static function system_info() {
		global $wp_version;

		$is_pro = class_exists( 'MW_Sales_Toast_Settings' ) && MW_Sales_Toast_Settings::is_pro();
		$theme  = wp_get_theme();
		$lines  = array(
			'Plugin: MW Sales Toast ' . MW_SALES_TOAST_VERSION,
			'Plan: ' . ( $is_pro ? 'Pro' : 'Free' ),
			'Site: ' . home_url( '/' ),
			'WP: ' . ( isset( $wp_version ) ? $wp_version : '' ),
			'PHP: ' . PHP_VERSION,
			'WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'n/a' ),
			'Theme: ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'Locale: ' . get_locale(),
			'Multisite: ' . ( is_multisite() ? 'yes' : 'no' ),
		);

		return implode( "\n", $lines );
	}

	/**
	 * Validate + forward support form to Webformatic.
	 */
	public static function handle() {
		$cap = class_exists( 'MW_Sales_Toast_Settings' )
			? MW_Sales_Toast_Settings::capability()
			: ( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' );
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to send this request.', 'mw-sales-toast' ) ), 403 );
		}

		check_ajax_referer( 'mw_st_support', 'nonce' );

		$user_id = get_current_user_id();
		$lock    = 'mw_st_support_lock_' . $user_id;
		if ( get_transient( $lock ) ) {
			wp_send_json_error( array( 'message' => __( 'Please wait a few minutes before sending another message.', 'mw-sales-toast' ) ), 429 );
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$include = ! empty( $_POST['include_system'] );

		if ( '' === $name || '' === $email || ! is_email( $email ) || '' === $subject || '' === $message ) {
			wp_send_json_error( array( 'message' => __( 'Please fill in name, email, subject, and message.', 'mw-sales-toast' ) ), 400 );
		}
		if ( strlen( $message ) > 5000 ) {
			wp_send_json_error( array( 'message' => __( 'Message is too long.', 'mw-sales-toast' ) ), 400 );
		}

		$is_pro = class_exists( 'MW_Sales_Toast_Settings' ) && MW_Sales_Toast_Settings::is_pro();

		$payload = array(
			'name'    => $name,
			'email'   => $email,
			'subject' => $subject,
			'message' => $message,
			'site'    => home_url( '/' ),
			'plugin'  => 'MW Sales Toast ' . MW_SALES_TOAST_VERSION,
			'plan'    => $is_pro ? 'pro' : 'free',
			'is_pro'  => $is_pro ? 1 : 0,
			'source'  => 'wordpress-admin',
		);

		if ( $include ) {
			$payload['system_info'] = self::system_info();
		}

		/**
		 * Filter payload sent to Webformatic.
		 *
		 * @param array $payload Payload.
		 */
		$payload = apply_filters( 'mw_sales_toast_support_payload', $payload );

		$response = wp_remote_post(
			self::endpoint(),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not reach the support form service. Please try again later.', 'mw-sales-toast' ) ), 502 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$ok = ( $code >= 200 && $code < 300 )
			|| ! empty( $body['success'] )
			|| ( isset( $body['status'] ) && 'success' === $body['status'] );

		if ( ! $ok ) {
			$msg = isset( $body['message'] ) ? (string) $body['message'] : '';
			if ( '' === $msg && isset( $body['error'] ) ) {
				$msg = (string) $body['error'];
			}
			if ( '' === $msg ) {
				$msg = __( 'Something went wrong. Please try again.', 'mw-sales-toast' );
			}
			wp_send_json_error( array( 'message' => $msg ), $code ? $code : 500 );
		}

		set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

		$success_msg = isset( $body['message'] ) && $body['message']
			? (string) $body['message']
			: __( 'Message sent. We’ll get back to you as soon as we can.', 'mw-sales-toast' );

		wp_send_json_success( array( 'message' => $success_msg ) );
	}
}
