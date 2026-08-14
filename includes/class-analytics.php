<?php
/**
 * Analytics — aggregate toast events (no PII).
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily counters + soft attribution.
 */
class MW_Sales_Toast_Analytics {

	const OPTION          = 'mw_st_analytics';
	const COOKIE          = 'mw_st_attr';
	const ATTR_WINDOW_SEC = 1800; // 30 minutes.
	const RETENTION_DAYS  = 90;

	/**
	 * Allowed beacon / total keys.
	 *
	 * @var array<int, string>
	 */
	const EVENTS = array(
		'impression',
		'dismiss',
		'auto_hide',
		'click',
		'muted',
		'skipped_session_cap',
		'skipped_mute',
		'skipped_reduced_motion',
		'skipped_mobile',
		'atc',
		'purchase',
	);

	/**
	 * Boot hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 20, 6 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'on_thankyou' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 20, 1 );
	}

	/**
	 * Whether analytics is active.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return true;
	}

	/**
	 * REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			'mw-st/v1',
			'/analytics',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_beacon' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
			)
		);
	}

	/**
	 * Storefront nonce (same action as notifications).
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
				'mw_st_analytics_nonce_missing',
				__( 'Missing analytics nonce.', 'mw-sales-toast' ),
				array( 'status' => 403 )
			);
		}
		if ( ! wp_verify_nonce( $nonce, MW_Sales_Toast_REST::NONCE_ACTION ) ) {
			return new WP_Error(
				'mw_st_analytics_nonce_invalid',
				__( 'Invalid or expired analytics nonce.', 'mw-sales-toast' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * POST beacon — { event, productId?, source?, reason? }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_beacon( $request ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error( 'mw_st_analytics_off', __( 'Analytics unavailable.', 'mw-sales-toast' ), array( 'status' => 403 ) );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$event = isset( $params['event'] ) ? sanitize_key( (string) $params['event'] ) : '';
		if ( 'skipped' === $event && ! empty( $params['reason'] ) ) {
			$reason = sanitize_key( (string) $params['reason'] );
			$map    = array(
				'session_cap'     => 'skipped_session_cap',
				'mute'            => 'skipped_mute',
				'reduced_motion'  => 'skipped_reduced_motion',
				'mobile'          => 'skipped_mobile',
			);
			$event = isset( $map[ $reason ] ) ? $map[ $reason ] : '';
		}

		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return new WP_Error( 'mw_st_analytics_bad_event', __( 'Unknown event.', 'mw-sales-toast' ), array( 'status' => 400 ) );
		}

		// Soft attribution hooks write atc/purchase — ignore from beacon.
		if ( in_array( $event, array( 'atc', 'purchase' ), true ) ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => true ) );
		}

		$product_id = isset( $params['productId'] ) ? absint( $params['productId'] ) : 0;
		self::track( $event, $product_id );

		if ( 'click' === $event && $product_id > 0 ) {
			self::set_attr_cookie( $product_id );
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Increment a daily counter.
	 *
	 * @param string $event      Event key.
	 * @param int    $product_id Optional product ID.
	 */
	public static function track( $event, $product_id = 0 ) {
		if ( ! self::is_enabled() || ! in_array( $event, self::EVENTS, true ) ) {
			return;
		}

		$day  = gmdate( 'Y-m-d' );
		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( ! isset( $data[ $day ] ) || ! is_array( $data[ $day ] ) ) {
			$data[ $day ] = array(
				'totals'   => array(),
				'products' => array(),
			);
		}
		if ( ! isset( $data[ $day ]['totals'] ) || ! is_array( $data[ $day ]['totals'] ) ) {
			$data[ $day ]['totals'] = array();
		}
		if ( ! isset( $data[ $day ]['products'] ) || ! is_array( $data[ $day ]['products'] ) ) {
			$data[ $day ]['products'] = array();
		}

		$data[ $day ]['totals'][ $event ] = (int) ( $data[ $day ]['totals'][ $event ] ?? 0 ) + 1;

		$product_id = absint( $product_id );
		if ( $product_id > 0 ) {
			$key = (string) $product_id;
			if ( ! isset( $data[ $day ]['products'][ $key ] ) || ! is_array( $data[ $day ]['products'][ $key ] ) ) {
				$data[ $day ]['products'][ $key ] = array();
			}
			$data[ $day ]['products'][ $key ][ $event ] = (int) ( $data[ $day ]['products'][ $key ][ $event ] ?? 0 ) + 1;
		}

		$data = self::prune_data( $data );
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Drop days older than retention.
	 *
	 * @param array $data Raw option.
	 * @return array
	 */
	private static function prune_data( $data ) {
		$cutoff = gmdate( 'Y-m-d', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		foreach ( array_keys( $data ) as $day ) {
			if ( ! is_string( $day ) || $day < $cutoff ) {
				unset( $data[ $day ] );
			}
		}
		return $data;
	}

	/**
	 * Set soft-attribution cookie after a toast click.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function set_attr_cookie( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 ) {
			return;
		}
		$value = $product_id . '.' . time();
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => time() + self::ATTR_WINDOW_SEC,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( self::COOKIE, $value, time() + self::ATTR_WINDOW_SEC, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * Read attribution cookie → product ID if still in window.
	 *
	 * @return int
	 */
	public static function get_attr_product_id() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return 0;
		}
		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		if ( ! preg_match( '/^(\d+)\.(\d+)$/', $raw, $m ) ) {
			return 0;
		}
		$product_id = (int) $m[1];
		$ts         = (int) $m[2];
		if ( $product_id < 1 || $ts < 1 || ( time() - $ts ) > self::ATTR_WINDOW_SEC ) {
			return 0;
		}
		return $product_id;
	}

	/**
	 * Woo add-to-cart soft attribution.
	 *
	 * @param string $cart_item_key Cart key.
	 * @param int    $product_id    Product ID.
	 * @param int    $quantity      Qty.
	 * @param int    $variation_id  Variation.
	 * @param array  $variation     Variation data.
	 * @param array  $cart_item_data Extra.
	 */
	public static function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		$attr_id = self::get_attr_product_id();
		if ( $attr_id < 1 ) {
			return;
		}
		$pid = absint( $variation_id ? $variation_id : $product_id );
		$parent = $pid;
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $pid );
			if ( $product && $product->get_parent_id() ) {
				$parent = (int) $product->get_parent_id();
			}
		}
		if ( $attr_id !== $pid && $attr_id !== $parent ) {
			return;
		}
		// One ATC credit per attribution window.
		$flag = 'mw_st_atc_' . $attr_id;
		if ( ! empty( $_COOKIE[ $flag ] ) ) {
			return;
		}
		self::track( 'atc', $attr_id );
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				$flag,
				'1',
				array(
					'expires'  => time() + self::ATTR_WINDOW_SEC,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( $flag, '1', time() + self::ATTR_WINDOW_SEC, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		$_COOKIE[ $flag ] = '1';
	}

	/**
	 * Thank-you page attribution.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_thankyou( $order_id ) {
		self::attribute_order( $order_id );
	}

	/**
	 * Payment complete attribution (covers some gateways).
	 *
	 * @param int $order_id Order ID.
	 */
	public static function on_payment_complete( $order_id ) {
		self::attribute_order( $order_id );
	}

	/**
	 * Soft-attribute a purchase once per order.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function attribute_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( '_mw_st_attr_tracked' ) ) {
			return;
		}

		$attr_id = self::get_attr_product_id();
		if ( $attr_id < 1 ) {
			return;
		}

		$matched = false;
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			$vid = (int) $item->get_variation_id();
			if ( $attr_id === $pid || $attr_id === $vid ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			return;
		}

		$order->update_meta_data( '_mw_st_attr_tracked', '1' );
		$order->save();
		self::track( 'purchase', $attr_id );
		self::clear_attr_cookie();
	}

	/**
	 * Expire attribution cookie.
	 */
	private static function clear_attr_cookie() {
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::COOKIE,
				'',
				array(
					'expires'  => time() - HOUR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Summarize last N days (+ prior period deltas).
	 *
	 * @param int $days Window length.
	 * @return array<string, mixed>
	 */
	public static function summarize( $days = 7 ) {
		$days = max( 1, min( 90, (int) $days ) );
		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$current = self::sum_range( $data, $days, 0 );
		$prior   = self::sum_range( $data, $days, $days );

		$impressions = (int) ( $current['totals']['impression'] ?? 0 );
		$clicks      = (int) ( $current['totals']['click'] ?? 0 );
		$dismiss     = (int) ( $current['totals']['dismiss'] ?? 0 );
		$muted       = (int) ( $current['totals']['muted'] ?? 0 );
		$atc         = (int) ( $current['totals']['atc'] ?? 0 );
		$purchase    = (int) ( $current['totals']['purchase'] ?? 0 );
		$ctr         = $impressions > 0 ? round( ( $clicks / $impressions ) * 100, 1 ) : 0.0;

		$p_imp = (int) ( $prior['totals']['impression'] ?? 0 );
		$p_clk = (int) ( $prior['totals']['click'] ?? 0 );
		$p_atc = (int) ( $prior['totals']['atc'] ?? 0 );
		$p_ctr = $p_imp > 0 ? round( ( $p_clk / $p_imp ) * 100, 1 ) : 0.0;

		$products = array();
		foreach ( $current['products'] as $pid => $counts ) {
			$pid = absint( $pid );
			if ( $pid < 1 ) {
				continue;
			}
			$p_impressions = (int) ( $counts['impression'] ?? 0 );
			$p_clicks      = (int) ( $counts['click'] ?? 0 );
			$products[]    = array(
				'id'          => $pid,
				'name'        => self::product_name( $pid ),
				'editUrl'     => self::product_edit_url( $pid ),
				'thumb'       => self::product_thumb( $pid ),
				'impressions' => $p_impressions,
				'clicks'      => $p_clicks,
				'ctr'         => $p_impressions > 0 ? round( ( $p_clicks / $p_impressions ) * 100, 1 ) : 0.0,
				'carts'       => (int) ( $counts['atc'] ?? 0 ),
				'orders'      => (int) ( $counts['purchase'] ?? 0 ),
			);
		}
		usort(
			$products,
			static function ( $a, $b ) {
				return $b['impressions'] - $a['impressions'];
			}
		);
		$products = array_slice( $products, 0, 20 );

		return array(
			'days'         => $days,
			'impressions'  => $impressions,
			'clicks'       => $clicks,
			'ctr'          => $ctr,
			'dismissed'    => $dismiss,
			'muted'        => $muted,
			'atc'          => $atc,
			'purchases'    => $purchase,
			'delta'        => array(
				'impressions' => self::delta_pct( $impressions, $p_imp ),
				'clicks'      => self::delta_pct( $clicks, $p_clk ),
				'ctr'         => self::delta_pp( $ctr, $p_ctr ),
				'atc'         => self::delta_pct( $atc, $p_atc ),
			),
			'products'     => $products,
			'attrWindow'   => self::ATTR_WINDOW_SEC / 60,
			'hasData'      => $impressions > 0 || $clicks > 0 || $atc > 0 || $purchase > 0,
		);
	}

	/**
	 * Summaries for 7 / 30 / 90 for the admin UI.
	 *
	 * @return array<string, array>
	 */
	public static function dashboard_payload() {
		return array(
			'7'  => self::summarize( 7 ),
			'30' => self::summarize( 30 ),
			'90' => self::summarize( 90 ),
		);
	}

	/**
	 * Sum totals/products for a sliding window.
	 *
	 * @param array $data   Option data.
	 * @param int   $days   Length.
	 * @param int   $offset Days back before window starts.
	 * @return array{totals:array,products:array}
	 */
	private static function sum_range( $data, $days, $offset ) {
		$totals   = array();
		$products = array();
		$end      = time() - ( $offset * DAY_IN_SECONDS );
		$start    = $end - ( ( $days - 1 ) * DAY_IN_SECONDS );

		for ( $t = $start; $t <= $end; $t += DAY_IN_SECONDS ) {
			$day = gmdate( 'Y-m-d', $t );
			if ( empty( $data[ $day ] ) || ! is_array( $data[ $day ] ) ) {
				continue;
			}
			foreach ( (array) ( $data[ $day ]['totals'] ?? array() ) as $key => $count ) {
				$totals[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + (int) $count;
			}
			foreach ( (array) ( $data[ $day ]['products'] ?? array() ) as $pid => $counts ) {
				if ( ! is_array( $counts ) ) {
					continue;
				}
				if ( ! isset( $products[ $pid ] ) ) {
					$products[ $pid ] = array();
				}
				foreach ( $counts as $key => $count ) {
					$products[ $pid ][ $key ] = (int) ( $products[ $pid ][ $key ] ?? 0 ) + (int) $count;
				}
			}
		}

		return array(
			'totals'   => $totals,
			'products' => $products,
		);
	}

	/**
	 * Percent change label data.
	 *
	 * @param int $current Current.
	 * @param int $prior   Prior.
	 * @return array{value:float,dir:string,label:string}
	 */
	private static function delta_pct( $current, $prior ) {
		if ( $prior <= 0 ) {
			return array(
				'value' => 0.0,
				'dir'   => 'flat',
				'label' => __( 'vs prior', 'mw-sales-toast' ),
			);
		}
		$pct = round( ( ( $current - $prior ) / $prior ) * 100, 1 );
		$dir = 0.0 === $pct ? 'flat' : ( $pct > 0 ? 'up' : 'flat' );
		$sign = $pct > 0 ? '+' : '';
		return array(
			'value' => $pct,
			'dir'   => $dir,
			'label' => sprintf(
				/* translators: %s: signed percent */
				__( '%s%% vs prior', 'mw-sales-toast' ),
				$sign . $pct
			),
		);
	}

	/**
	 * Percentage-point change for CTR.
	 *
	 * @param float $current Current CTR.
	 * @param float $prior   Prior CTR.
	 * @return array{value:float,dir:string,label:string}
	 */
	private static function delta_pp( $current, $prior ) {
		$pp = round( $current - $prior, 1 );
		$dir = 0.0 === $pp ? 'flat' : ( $pp > 0 ? 'up' : 'flat' );
		$sign = $pp > 0 ? '+' : '';
		return array(
			'value' => $pp,
			'dir'   => $dir,
			'label' => sprintf(
				/* translators: %s: signed percentage points */
				__( '%s%% vs prior', 'mw-sales-toast' ),
				$sign . $pp
			),
		);
	}

	/**
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private static function product_name( $product_id ) {
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				return $product->get_name();
			}
		}
		$title = get_the_title( $product_id );
		return $title ? $title : ( '#' . $product_id );
	}

	/**
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private static function product_edit_url( $product_id ) {
		$url = get_edit_post_link( $product_id, 'raw' );
		return $url ? $url : '';
	}

	/**
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private static function product_thumb( $product_id ) {
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$image_id = $product->get_image_id();
				if ( $image_id ) {
					$url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
					return $url ? $url : '';
				}
			}
		}
		$url = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
		return $url ? $url : '';
	}
}
