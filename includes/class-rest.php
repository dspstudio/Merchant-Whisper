<?php
/**
 * REST API for notifications.
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end notifications endpoint (nonce-gated).
 */
class MW_Sales_Toast_REST {

	/**
	 * Nonce action for storefront fetches.
	 */
	const NONCE_ACTION = 'mw_st_notifications';

	/**
	 * Register routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Whether the REST notifications route should be available.
	 *
	 * @param array|null $settings Optional settings snapshot.
	 * @return bool
	 */
	public static function is_enabled( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : MW_Sales_Toast_Settings::get();
		return 'inline' !== ( $settings['event_delivery'] ?? 'rest' );
	}

	/**
	 * Route registration.
	 */
	public static function register_routes() {
		register_rest_route(
			'mw-st/v1',
			'/presence',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'post_presence' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
			)
		);

		if ( ! self::is_enabled() ) {
			return;
		}

		register_rest_route(
			'mw-st/v1',
			'/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_notifications' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'args'                => array(
					'_mwst_nonce' => array(
						'description' => __( 'Storefront nonce from the page load (do not use X-WP-Nonce).', 'mw-sales-toast' ),
						'type'        => 'string',
						'required'    => false,
					),
					'product'     => array(
						'description' => __( 'Current product ID (viewing toasts).', 'mw-sales-toast' ),
						'type'        => 'integer',
						'required'    => false,
					),
				),
			)
		);
	}

	/**
	 * Require a valid storefront nonce (custom header or query).
	 *
	 * Avoid X-WP-Nonce — WordPress REST treats that as the core wp_rest
	 * cookie nonce and returns rest_cookie_invalid_nonce for other actions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function permission_check( $request ) {
		$nonce = $request->get_header( 'X-MW-ST-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			$nonce = $request->get_param( '_mwst_nonce' );
		}
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return new WP_Error(
				'mw_st_rest_nonce_missing',
				__( 'Missing notifications nonce.', 'mw-sales-toast' ),
				array( 'status' => 403 )
			);
		}

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return new WP_Error(
				'mw_st_rest_nonce_invalid',
				__( 'Invalid or expired notifications nonce.', 'mw-sales-toast' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Create a nonce for the front-end script.
	 *
	 * @return string
	 */
	public static function create_nonce() {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	/**
	 * GET notifications.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_notifications( $request ) {
		$settings = MW_Sales_Toast_Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return rest_ensure_response( array() );
		}

		$limit  = max( 1, min( 40, (int) $settings['max_events'] ) );
		if ( class_exists( 'MW_Sales_Toast_Types' ) && MW_Sales_Toast_Types::any_enabled( $settings ) ) {
			$limit = min( 40, $limit + 8 );
		}
		$events = MW_Sales_Toast_Cache::get_events();
		// Pull a wider pool before catalog/PDP filters so includes still fill the limit.
		$events = array_slice( $events, 0, max( $limit * 5, 50 ) );
		if ( class_exists( 'MW_Sales_Toast_Frontend' ) ) {
			$events = MW_Sales_Toast_Frontend::filter_events_for_display( $events, $settings );
		}

		$product_id = absint( $request->get_param( 'product' ) );
		if ( $product_id && class_exists( 'MW_Sales_Toast_Types' ) ) {
			$events = MW_Sales_Toast_Types::inject_current_viewing( $events, $settings, $product_id );
		}

		return rest_ensure_response( array_values( array_slice( $events, 0, $limit ) ) );
	}

	/**
	 * POST product-page presence for live “viewing now” counts (no IPs).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function post_presence( $request ) {
		$settings = MW_Sales_Toast_Settings::get();
		if ( empty( $settings['enabled'] ) || empty( $settings['type_viewing'] ) || 'live' !== ( $settings['viewing_mode'] ?? '' ) ) {
			return rest_ensure_response( array( 'count' => 0 ) );
		}
		if ( ! class_exists( 'MW_Sales_Toast_Types' ) ) {
			return rest_ensure_response( array( 'count' => 0 ) );
		}

		$body       = $request->get_json_params();
		$product_id = isset( $body['productId'] ) ? absint( $body['productId'] ) : 0;
		$visitor    = isset( $body['visitor'] ) ? (string) $body['visitor'] : '';
		$count      = MW_Sales_Toast_Types::ping( $product_id, $visitor, $settings );

		return rest_ensure_response( array( 'count' => $count ) );
	}
}
