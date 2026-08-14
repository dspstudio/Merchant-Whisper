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
	const SCHEMA_OPTION   = 'mw_st_stats_schema';
	const SCHEMA_VERSION  = 1;
	const COOKIE          = 'mw_st_attr';
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

	const TOAST_TYPES    = array( 'sale', 'viewing', 'review', 'cta' );
	const TYPED_EVENTS   = array( 'impression', 'click', 'dismiss', 'auto_hide', 'muted', 'atc', 'purchase' );
	const SOURCES        = array( 'real', 'demo' );
	const PAGE_TYPES     = array( 'product', 'shop', 'taxonomy', 'home', 'other' );
	const TRIGGERS       = array( 'page_load', 'scroll', 'exit_intent', 'add_to_cart', 'inactivity', 'click' );
	const CLICK_TARGETS  = array( 'product', 'coupon' );
	const ATTR_MINUTES   = array( 15, 30, 60, 120 );

	/**
	 * Boot hooks.
	 */
	public static function init() {
		self::maybe_install();
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 20, 6 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'on_thankyou' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 20, 1 );
		add_action( 'wp_ajax_mw_st_analytics_reset', array( __CLASS__, 'ajax_reset' ) );
		add_action( 'wp_ajax_mw_st_analytics_set', array( __CLASS__, 'ajax_set_enabled' ) );
		add_action( 'wp_ajax_mw_st_analytics_set_attr', array( __CLASS__, 'ajax_set_attr' ) );
	}

	/**
	 * Stats table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mw_st_stats';
	}

	/**
	 * Create table and migrate the legacy option blob once.
	 */
	public static function maybe_install() {
		$stored = (int) get_option( self::SCHEMA_OPTION, 0 );
		if ( $stored >= self::SCHEMA_VERSION ) {
			return;
		}
		self::create_table();
		self::migrate_option_blob();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * dbDelta table.
	 */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			day date NOT NULL,
			dim varchar(20) NOT NULL,
			dim_key varchar(64) NOT NULL,
			event varchar(40) NOT NULL,
			n bigint(20) NOT NULL DEFAULT 0,
			PRIMARY KEY  (day, dim, dim_key, event),
			KEY day_dim (day, dim)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Copy legacy option aggregates into the table.
	 */
	private static function migrate_option_blob() {
		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return;
		}
		foreach ( $data as $day => $row ) {
			if ( ! is_string( $day ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) || ! is_array( $row ) ) {
				continue;
			}
			foreach ( (array) ( $row['totals'] ?? array() ) as $event => $count ) {
				$event = sanitize_key( (string) $event );
				$count = (int) $count;
				if ( $count && in_array( $event, self::EVENTS, true ) ) {
					self::bump( $day, 'total', '', $event, $count );
				}
			}
			foreach ( (array) ( $row['products'] ?? array() ) as $pid => $counts ) {
				$pid = absint( $pid );
				if ( $pid < 1 || ! is_array( $counts ) ) {
					continue;
				}
				foreach ( $counts as $event => $count ) {
					$event = sanitize_key( (string) $event );
					$count = (int) $count;
					if ( $count && in_array( $event, self::EVENTS, true ) ) {
						self::bump( $day, 'product', (string) $pid, $event, $count );
					}
				}
			}
			foreach ( (array) ( $row['types'] ?? array() ) as $type_id => $counts ) {
				$type_id = self::sanitize_toast_type( (string) $type_id );
				if ( '' === $type_id || ! is_array( $counts ) ) {
					continue;
				}
				foreach ( $counts as $event => $count ) {
					$event = sanitize_key( (string) $event );
					$count = (int) $count;
					if ( $count && in_array( $event, self::TYPED_EVENTS, true ) ) {
						self::bump( $day, 'type', $type_id, $event, $count );
					}
				}
			}
		}
		delete_option( self::OPTION );
	}

	/**
	 * Atomic increment.
	 *
	 * @param string $day     Y-m-d.
	 * @param string $dim     Dimension.
	 * @param string $dim_key Key.
	 * @param string $event   Event.
	 * @param int    $by      Delta.
	 */
	private static function bump( $day, $dim, $dim_key, $event, $by = 1 ) {
		global $wpdb;
		$by = (int) $by;
		if ( 0 === $by ) {
			return;
		}
		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (`day`,`dim`,`dim_key`,`event`,`n`) VALUES (%s,%s,%s,%s,%d)
				ON DUPLICATE KEY UPDATE `n` = `n` + VALUES(`n`)",
				$day,
				$dim,
				$dim_key,
				$event,
				$by
			)
		);
	}

	/**
	 * Drop days older than retention.
	 */
	private static function prune_table() {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE `day` < %s", $cutoff ) );
	}

	/**
	 * Whether analytics is active.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( ! class_exists( 'MW_Sales_Toast_Settings' ) ) {
			return true;
		}
		$s = MW_Sales_Toast_Settings::get();
		return ! empty( $s['analytics_enabled'] );
	}

	/**
	 * Attribution window in seconds.
	 *
	 * @return int
	 */
	public static function attr_window_sec() {
		return self::attr_window_minutes() * MINUTE_IN_SECONDS;
	}

	/**
	 * Attribution window in minutes.
	 *
	 * @return int
	 */
	public static function attr_window_minutes() {
		$min = 30;
		if ( class_exists( 'MW_Sales_Toast_Settings' ) ) {
			$s   = MW_Sales_Toast_Settings::get();
			$min = (int) ( $s['analytics_attr_minutes'] ?? 30 );
		}
		return in_array( $min, self::ATTR_MINUTES, true ) ? $min : 30;
	}

	/**
	 * Persist attribution minutes without a full settings save.
	 *
	 * @param int $minutes Window.
	 */
	public static function set_attr_minutes( $minutes ) {
		$minutes = (int) $minutes;
		if ( ! in_array( $minutes, self::ATTR_MINUTES, true ) ) {
			$minutes = 30;
		}
		$saved = get_option( MW_SALES_TOAST_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$saved['analytics_attr_minutes'] = $minutes;
		update_option( MW_SALES_TOAST_OPTION, $saved );
	}

	/**
	 * Capability for admin analytics actions.
	 *
	 * @return string
	 */
	private static function capability() {
		return class_exists( 'MW_Sales_Toast_Settings' )
			? MW_Sales_Toast_Settings::capability()
			: ( class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options' );
	}

	/**
	 * Persist the collection toggle without a full settings save.
	 *
	 * @param bool $on Enabled.
	 */
	public static function set_enabled( $on ) {
		$saved = get_option( MW_SALES_TOAST_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$saved['analytics_enabled'] = $on ? 1 : 0;
		update_option( MW_SALES_TOAST_OPTION, $saved );
	}

	/**
	 * Drop stored aggregates.
	 */
	public static function reset() {
		global $wpdb;
		self::maybe_install();
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::OPTION );
	}

	/**
	 * AJAX: wipe statistics.
	 */
	public static function ajax_reset() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to reset statistics.', 'mw-sales-toast' ) ), 403 );
		}
		check_ajax_referer( 'mw_st_analytics', 'nonce' );
		self::reset();
		wp_send_json_success(
			array(
				'message' => __( 'Statistics cleared.', 'mw-sales-toast' ),
			)
		);
	}

	/**
	 * AJAX: enable or disable collection.
	 */
	public static function ajax_set_enabled() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this setting.', 'mw-sales-toast' ) ), 403 );
		}
		check_ajax_referer( 'mw_st_analytics', 'nonce' );
		$raw = isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '0';
		$on  = '1' === $raw;
		self::set_enabled( $on );
		wp_send_json_success(
			array(
				'enabled' => $on,
				'message' => $on
					? __( 'Toast statistics collection is on.', 'mw-sales-toast' )
					: __( 'Toast statistics collection is off.', 'mw-sales-toast' ),
			)
		);
	}

	/**
	 * AJAX: attribution window.
	 */
	public static function ajax_set_attr() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to change this setting.', 'mw-sales-toast' ) ), 403 );
		}
		check_ajax_referer( 'mw_st_analytics', 'nonce' );
		$minutes = isset( $_POST['minutes'] ) ? absint( wp_unslash( $_POST['minutes'] ) ) : 30;
		self::set_attr_minutes( $minutes );
		$minutes = self::attr_window_minutes();
		wp_send_json_success(
			array(
				'minutes' => $minutes,
				'message' => sprintf(
					/* translators: %d: minutes */
					__( 'Attribution window set to %d minutes.', 'mw-sales-toast' ),
					$minutes
				),
			)
		);
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
		if ( ! class_exists( 'MW_Sales_Toast_REST' ) || ! MW_Sales_Toast_REST::verify_nonce( $nonce ) ) {
			return new WP_Error(
				'mw_st_analytics_nonce_invalid',
				__( 'Invalid or expired analytics nonce.', 'mw-sales-toast' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * POST beacon.
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
				'session_cap'    => 'skipped_session_cap',
				'mute'           => 'skipped_mute',
				'reduced_motion' => 'skipped_reduced_motion',
				'mobile'         => 'skipped_mobile',
			);
			$event = isset( $map[ $reason ] ) ? $map[ $reason ] : '';
		}

		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return new WP_Error( 'mw_st_analytics_bad_event', __( 'Unknown event.', 'mw-sales-toast' ), array( 'status' => 400 ) );
		}

		if ( in_array( $event, array( 'atc', 'purchase' ), true ) ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => true ) );
		}

		$product_id = isset( $params['productId'] ) ? absint( $params['productId'] ) : 0;
		$type       = isset( $params['type'] ) ? (string) $params['type'] : '';
		$ctx        = array(
			'source'      => isset( $params['source'] ) ? (string) $params['source'] : '',
			'page'        => isset( $params['pageType'] ) ? (string) $params['pageType'] : '',
			'trigger'     => isset( $params['trigger'] ) ? (string) $params['trigger'] : '',
			'clickTarget' => isset( $params['clickTarget'] ) ? (string) $params['clickTarget'] : '',
			'dwellMs'     => isset( $params['dwellMs'] ) ? absint( $params['dwellMs'] ) : 0,
		);
		self::track( $event, $product_id, $type, $ctx );

		$click_target = self::sanitize_click_target( $ctx['clickTarget'] );
		if ( 'click' === $event && $product_id > 0 && 'coupon' !== $click_target ) {
			self::set_attr_cookie( $product_id, $type );
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Increment daily counters.
	 *
	 * @param string               $event      Event key.
	 * @param int                  $product_id Optional product ID.
	 * @param string               $type       Optional toast type.
	 * @param array<string, mixed> $ctx        Extra dimensions.
	 */
	public static function track( $event, $product_id = 0, $type = '', $ctx = array() ) {
		if ( ! self::is_enabled() || ! in_array( $event, self::EVENTS, true ) ) {
			return;
		}

		self::maybe_install();
		$day = gmdate( 'Y-m-d' );
		$ctx = is_array( $ctx ) ? $ctx : array();

		self::bump( $day, 'total', '', $event, 1 );

		$product_id = absint( $product_id );
		if ( $product_id > 0 ) {
			self::bump( $day, 'product', (string) $product_id, $event, 1 );
		}

		if ( in_array( $event, self::TYPED_EVENTS, true ) ) {
			$type_key = self::sanitize_toast_type( $type );
			if ( '' !== $type_key ) {
				self::bump( $day, 'type', $type_key, $event, 1 );
			}
			$source = self::sanitize_source( isset( $ctx['source'] ) ? $ctx['source'] : '' );
			if ( '' !== $source ) {
				self::bump( $day, 'source', $source, $event, 1 );
			}
		}

		$page = self::sanitize_page( isset( $ctx['page'] ) ? $ctx['page'] : '' );
		if ( '' !== $page ) {
			self::bump( $day, 'page', $page, $event, 1 );
		}

		$trigger = self::sanitize_trigger( isset( $ctx['trigger'] ) ? $ctx['trigger'] : '' );
		if ( '' !== $trigger && in_array( $event, array( 'impression', 'click', 'dismiss', 'auto_hide' ), true ) ) {
			self::bump( $day, 'trigger', $trigger, $event, 1 );
		}

		if ( 'click' === $event ) {
			$target = self::sanitize_click_target( isset( $ctx['clickTarget'] ) ? $ctx['clickTarget'] : '' );
			if ( '' !== $target ) {
				self::bump( $day, 'click', $target, 'click', 1 );
			}
		}

		if ( in_array( $event, array( 'dismiss', 'auto_hide' ), true ) ) {
			$dwell = isset( $ctx['dwellMs'] ) ? absint( $ctx['dwellMs'] ) : 0;
			if ( $dwell > 0 ) {
				self::bump( $day, 'dwell', $event, 'sum_ms', $dwell );
				self::bump( $day, 'dwell', $event, 'count', 1 );
			}
		}

		if ( wp_rand( 1, 40 ) === 1 ) {
			self::prune_table();
		}
	}

	/**
	 * @param string $raw Raw.
	 * @return string
	 */
	public static function sanitize_toast_type( $raw ) {
		$key = sanitize_key( (string) $raw );
		return in_array( $key, self::TOAST_TYPES, true ) ? $key : '';
	}

	/**
	 * @param string $raw Raw.
	 * @return string
	 */
	public static function sanitize_source( $raw ) {
		$key = sanitize_key( (string) $raw );
		return in_array( $key, self::SOURCES, true ) ? $key : '';
	}

	/**
	 * @param string $raw Raw.
	 * @return string
	 */
	public static function sanitize_page( $raw ) {
		$key = sanitize_key( (string) $raw );
		return in_array( $key, self::PAGE_TYPES, true ) ? $key : '';
	}

	/**
	 * @param string $raw Raw.
	 * @return string
	 */
	public static function sanitize_trigger( $raw ) {
		$key = sanitize_key( (string) $raw );
		return in_array( $key, self::TRIGGERS, true ) ? $key : '';
	}

	/**
	 * @param string $raw Raw.
	 * @return string
	 */
	public static function sanitize_click_target( $raw ) {
		$key = sanitize_key( (string) $raw );
		return in_array( $key, self::CLICK_TARGETS, true ) ? $key : '';
	}

	/**
	 * Set soft-attribution cookie after a toast click.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $type       Toast type.
	 */
	public static function set_attr_cookie( $product_id, $type = '' ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 ) {
			return;
		}
		$ttl   = self::attr_window_sec();
		$value = $product_id . '.' . time();
		$type  = self::sanitize_toast_type( $type );
		if ( '' !== $type ) {
			$value .= '.' . $type;
		}
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				self::COOKIE,
				$value,
				array(
					'expires'  => time() + $ttl,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( self::COOKIE, $value, time() + $ttl, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * Read attribution cookie.
	 *
	 * @return array{product_id:int,type:string}
	 */
	public static function get_attr() {
		$empty = array(
			'product_id' => 0,
			'type'       => '',
		);
		if ( empty( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return $empty;
		}
		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		if ( ! preg_match( '/^(\d+)\.(\d+)(?:\.([a-z0-9_]+))?$/', $raw, $m ) ) {
			return $empty;
		}
		$product_id = (int) $m[1];
		$ts         = (int) $m[2];
		if ( $product_id < 1 || $ts < 1 || ( time() - $ts ) > self::attr_window_sec() ) {
			return $empty;
		}
		return array(
			'product_id' => $product_id,
			'type'       => isset( $m[3] ) ? self::sanitize_toast_type( $m[3] ) : '',
		);
	}

	/**
	 * Read attribution cookie → product ID if still in window.
	 *
	 * @return int
	 */
	public static function get_attr_product_id() {
		$attr = self::get_attr();
		return (int) $attr['product_id'];
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
		$attr    = self::get_attr();
		$attr_id = (int) $attr['product_id'];
		if ( $attr_id < 1 ) {
			return;
		}
		$pid    = absint( $variation_id ? $variation_id : $product_id );
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
		$flag = 'mw_st_atc_' . $attr_id;
		if ( ! empty( $_COOKIE[ $flag ] ) ) {
			return;
		}
		self::track( 'atc', $attr_id, $attr['type'] );
		$ttl = self::attr_window_sec();
		if ( PHP_VERSION_ID >= 70300 ) {
			setcookie(
				$flag,
				'1',
				array(
					'expires'  => time() + $ttl,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		} else {
			setcookie( $flag, '1', time() + $ttl, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
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

		$attr    = self::get_attr();
		$attr_id = (int) $attr['product_id'];
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
		$type = isset( $attr['type'] ) ? $attr['type'] : '';
		self::track( 'purchase', $attr_id, $type );

		$cents = (int) round( (float) $order->get_total() * 100 );
		if ( $cents > 0 ) {
			$day = gmdate( 'Y-m-d' );
			self::maybe_install();
			self::bump( $day, 'total', '', 'revenue', $cents );
			self::bump( $day, 'product', (string) $attr_id, 'revenue', $cents );
			$type_key = self::sanitize_toast_type( $type );
			if ( '' !== $type_key ) {
				self::bump( $day, 'type', $type_key, 'revenue', $cents );
			}
		}

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
		$days    = max( 1, min( 90, (int) $days ) );
		$current = self::sum_range( $days, 0 );
		$prior   = self::sum_range( $days, $days );

		$impressions = (int) ( $current['totals']['impression'] ?? 0 );
		$clicks      = (int) ( $current['totals']['click'] ?? 0 );
		$dismiss     = (int) ( $current['totals']['dismiss'] ?? 0 );
		$auto_hide   = (int) ( $current['totals']['auto_hide'] ?? 0 );
		$muted       = (int) ( $current['totals']['muted'] ?? 0 );
		$atc         = (int) ( $current['totals']['atc'] ?? 0 );
		$purchase    = (int) ( $current['totals']['purchase'] ?? 0 );
		$revenue     = (int) ( $current['totals']['revenue'] ?? 0 );
		$skip_mute   = (int) ( $current['totals']['skipped_mute'] ?? 0 );
		$skip_cap    = (int) ( $current['totals']['skipped_session_cap'] ?? 0 );
		$skip_motion = (int) ( $current['totals']['skipped_reduced_motion'] ?? 0 );
		$skip_mobile = (int) ( $current['totals']['skipped_mobile'] ?? 0 );
		$ctr         = $impressions > 0 ? round( ( $clicks / $impressions ) * 100, 1 ) : 0.0;
		$conv        = $clicks > 0 ? round( ( $purchase / $clicks ) * 100, 1 ) : 0.0;

		$p_imp  = (int) ( $prior['totals']['impression'] ?? 0 );
		$p_clk  = (int) ( $prior['totals']['click'] ?? 0 );
		$p_atc  = (int) ( $prior['totals']['atc'] ?? 0 );
		$p_pur  = (int) ( $prior['totals']['purchase'] ?? 0 );
		$p_rev  = (int) ( $prior['totals']['revenue'] ?? 0 );
		$p_ctr  = $p_imp > 0 ? round( ( $p_clk / $p_imp ) * 100, 1 ) : 0.0;
		$p_conv = $p_clk > 0 ? round( ( $p_pur / $p_clk ) * 100, 1 ) : 0.0;

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
				'revenue'     => (int) ( $counts['revenue'] ?? 0 ),
			);
		}
		usort(
			$products,
			static function ( $a, $b ) {
				return $b['impressions'] - $a['impressions'];
			}
		);
		$products = array_slice( $products, 0, 100 );

		$defs  = class_exists( 'MW_Sales_Toast_Settings' ) ? MW_Sales_Toast_Settings::type_defs() : array();
		$types = self::metric_rows( $current['types'], self::TOAST_TYPES, $defs );

		$source_labels = array(
			'real' => __( 'Real orders', 'mw-sales-toast' ),
			'demo' => __( 'Demo', 'mw-sales-toast' ),
		);
		$page_labels   = array(
			'product'  => __( 'Product', 'mw-sales-toast' ),
			'shop'     => __( 'Shop', 'mw-sales-toast' ),
			'taxonomy' => __( 'Category / tag', 'mw-sales-toast' ),
			'home'     => __( 'Home', 'mw-sales-toast' ),
			'other'    => __( 'Other', 'mw-sales-toast' ),
		);
		$trig_labels   = array();
		if ( class_exists( 'MW_Sales_Toast_Settings' ) ) {
			foreach ( MW_Sales_Toast_Settings::trigger_defs() as $tid => $tdef ) {
				$trig_labels[ $tid ] = $tdef['label'];
			}
		}
		$click_labels  = array(
			'product' => __( 'Product link', 'mw-sales-toast' ),
			'coupon'  => __( 'Coupon copy', 'mw-sales-toast' ),
		);
		$click_targets = self::metric_rows( $current['clicks'], self::CLICK_TARGETS, $click_labels );
		$click_total   = 0;
		foreach ( $click_targets as $ct_row ) {
			$click_total += (int) $ct_row['clicks'];
		}
		foreach ( $click_targets as $i => $ct_row ) {
			$clk                        = (int) $ct_row['clicks'];
			$click_targets[ $i ]['ctr'] = $click_total > 0 ? round( ( $clk / $click_total ) * 100, 1 ) : 0.0;
		}

		$dwell_dismiss = self::dwell_avg( $current['dwell']['dismiss'] ?? array() );
		$dwell_hide    = self::dwell_avg( $current['dwell']['auto_hide'] ?? array() );

		$has_data = $impressions > 0
			|| $clicks > 0
			|| $atc > 0
			|| $purchase > 0
			|| $dismiss > 0
			|| $auto_hide > 0
			|| $muted > 0
			|| $skip_mute > 0
			|| $skip_cap > 0
			|| $skip_motion > 0
			|| $skip_mobile > 0
			|| $revenue > 0;

		return array(
			'days'                   => $days,
			'impressions'            => $impressions,
			'clicks'                 => $clicks,
			'ctr'                    => $ctr,
			'convRate'               => $conv,
			'dismissed'              => $dismiss,
			'autoHide'               => $auto_hide,
			'muted'                  => $muted,
			'atc'                    => $atc,
			'purchases'              => $purchase,
			'revenue'                => $revenue,
			'revenueLabel'           => self::format_money( $revenue ),
			'skippedMute'            => $skip_mute,
			'skippedSessionCap'      => $skip_cap,
			'skippedReducedMotion'   => $skip_motion,
			'skippedMobile'          => $skip_mobile,
			'dwellDismiss'           => $dwell_dismiss,
			'dwellAutoHide'          => $dwell_hide,
			'delta'                  => array(
				'impressions' => self::delta_pct( $impressions, $p_imp ),
				'clicks'      => self::delta_pct( $clicks, $p_clk ),
				'ctr'         => self::delta_pp( $ctr, $p_ctr ),
				'atc'         => self::delta_pct( $atc, $p_atc ),
				'purchases'   => self::delta_pct( $purchase, $p_pur ),
				'convRate'    => self::delta_pp( $conv, $p_conv ),
				'revenue'     => self::delta_pct( $revenue, $p_rev ),
			),
			'types'                  => $types,
			'sources'                => self::metric_rows( $current['sources'], self::SOURCES, $source_labels ),
			'pages'                  => self::metric_rows( $current['pages'], self::PAGE_TYPES, $page_labels ),
			'triggers'               => self::metric_rows( $current['triggers'], self::TRIGGERS, $trig_labels ),
			'clickTargets'           => $click_targets,
			'products'               => $products,
			'series'                 => self::series_range( $days ),
			'attrWindow'             => self::attr_window_minutes(),
			'hasData'                => $has_data,
		);
	}

	/**
	 * Impression/click rows for a dimension.
	 *
	 * @param array  $bucket  Counts by id.
	 * @param array  $order   Ids.
	 * @param array  $labels  Labels keyed by id (or type_defs).
	 * @return array<int, array<string, mixed>>
	 */
	private static function metric_rows( $bucket, $order, $labels ) {
		$rows = array();
		foreach ( $order as $id ) {
			$counts = isset( $bucket[ $id ] ) && is_array( $bucket[ $id ] ) ? $bucket[ $id ] : array();
			$imp    = (int) ( $counts['impression'] ?? 0 );
			$clk    = (int) ( $counts['click'] ?? 0 );
			$label  = $id;
			if ( isset( $labels[ $id ] ) ) {
				$label = is_array( $labels[ $id ] ) ? (string) ( $labels[ $id ]['label'] ?? $id ) : (string) $labels[ $id ];
			}
			$rows[] = array(
				'id'           => $id,
				'label'        => $label,
				'impressions'  => $imp,
				'clicks'       => $clk,
				'ctr'          => $imp > 0 ? round( ( $clk / $imp ) * 100, 1 ) : 0.0,
				'carts'        => (int) ( $counts['atc'] ?? 0 ),
				'orders'       => (int) ( $counts['purchase'] ?? 0 ),
				'revenue'      => (int) ( $counts['revenue'] ?? 0 ),
				'revenueLabel' => self::format_money( (int) ( $counts['revenue'] ?? 0 ) ),
			);
		}
		return $rows;
	}

	/**
	 * Average dwell label.
	 *
	 * @param array $row sum_ms / count.
	 * @return string
	 */
	private static function dwell_avg( $row ) {
		$sum   = (int) ( $row['sum_ms'] ?? 0 );
		$count = (int) ( $row['count'] ?? 0 );
		if ( $count < 1 || $sum < 1 ) {
			return '—';
		}
		$ms = (int) round( $sum / $count );
		if ( $ms < 1000 ) {
			return sprintf(
				/* translators: %d: milliseconds */
				__( '%d ms', 'mw-sales-toast' ),
				$ms
			);
		}
		return sprintf(
			/* translators: %s: seconds */
			__( '%s s', 'mw-sales-toast' ),
			(string) round( $ms / 1000, 1 )
		);
	}

	/**
	 * Format cents as store money.
	 *
	 * @param int $cents Cents.
	 * @return string
	 */
	private static function format_money( $cents ) {
		$cents = (int) $cents;
		$major = $cents / 100;
		if ( function_exists( 'wc_price' ) ) {
			$html = (string) wc_price( $major );
			$text = wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$text = str_replace( "\xC2\xA0", ' ', $text );
			$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
			return $text;
		}
		return (string) number_format_i18n( $major, 2 );
	}

	/**
	 * Summaries for 7 / 30 / 90 for the admin UI.
	 *
	 * @return array<string, array>
	 */
	public static function dashboard_payload() {
		self::maybe_install();
		return array(
			'7'  => self::summarize( 7 ),
			'30' => self::summarize( 30 ),
			'90' => self::summarize( 90 ),
		);
	}

	/**
	 * Daily points for the selected window (UTC dates).
	 *
	 * @param int $days Length.
	 * @return array<int, array<string, mixed>>
	 */
	private static function series_range( $days ) {
		global $wpdb;
		self::maybe_install();
		$table = self::table();
		$end   = gmdate( 'Y-m-d' );
		$start = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `day`, `event`, SUM(`n`) AS n FROM {$table}
				WHERE `dim` = 'total' AND `dim_key` = '' AND `day` >= %s AND `day` <= %s
				AND `event` IN ('impression','click','auto_hide','dismiss','atc','purchase','revenue')
				GROUP BY `day`, `event`",
				$start,
				$end
			),
			ARRAY_A
		);
		$by_day = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$day = (string) ( $row['day'] ?? '' );
				$ev  = sanitize_key( (string) ( $row['event'] ?? '' ) );
				if ( '' === $day || '' === $ev ) {
					continue;
				}
				if ( ! isset( $by_day[ $day ] ) ) {
					$by_day[ $day ] = array();
				}
				$by_day[ $day ][ $ev ] = (int) ( $row['n'] ?? 0 );
			}
		}

		$series = array();
		$t_end  = time();
		$t_start = $t_end - ( ( $days - 1 ) * DAY_IN_SECONDS );
		for ( $t = $t_start; $t <= $t_end; $t += DAY_IN_SECONDS ) {
			$day = gmdate( 'Y-m-d', $t );
			$c   = isset( $by_day[ $day ] ) ? $by_day[ $day ] : array();
			$series[] = array(
				'day'         => $day,
				'impressions' => (int) ( $c['impression'] ?? 0 ),
				'clicks'      => (int) ( $c['click'] ?? 0 ),
				'autoHide'    => (int) ( $c['auto_hide'] ?? 0 ),
				'dismissed'   => (int) ( $c['dismiss'] ?? 0 ),
				'atc'         => (int) ( $c['atc'] ?? 0 ),
				'purchases'   => (int) ( $c['purchase'] ?? 0 ),
				'revenue'     => (int) ( $c['revenue'] ?? 0 ),
			);
		}
		return $series;
	}

	/**
	 * Sum dimensions for a sliding window.
	 *
	 * @param int $days   Length.
	 * @param int $offset Days back before window starts.
	 * @return array<string, mixed>
	 */
	private static function sum_range( $days, $offset ) {
		global $wpdb;
		self::maybe_install();
		$table = self::table();
		$end   = gmdate( 'Y-m-d', time() - ( $offset * DAY_IN_SECONDS ) );
		$start = gmdate( 'Y-m-d', time() - ( ( $offset + $days - 1 ) * DAY_IN_SECONDS ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT `dim`, `dim_key`, `event`, SUM(`n`) AS n FROM {$table}
				WHERE `day` >= %s AND `day` <= %s
				GROUP BY `dim`, `dim_key`, `event`",
				$start,
				$end
			),
			ARRAY_A
		);

		$out = array(
			'totals'   => array(),
			'products' => array(),
			'types'    => array(),
			'sources'  => array(),
			'pages'    => array(),
			'triggers' => array(),
			'clicks'   => array(),
			'dwell'    => array(),
		);
		if ( ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $row ) {
			$dim  = sanitize_key( (string) ( $row['dim'] ?? '' ) );
			$key  = (string) ( $row['dim_key'] ?? '' );
			$ev   = sanitize_key( (string) ( $row['event'] ?? '' ) );
			$n    = (int) ( $row['n'] ?? 0 );
			if ( '' === $dim || '' === $ev ) {
				continue;
			}
			if ( 'total' === $dim ) {
				$out['totals'][ $ev ] = (int) ( $out['totals'][ $ev ] ?? 0 ) + $n;
			} elseif ( 'product' === $dim ) {
				if ( ! isset( $out['products'][ $key ] ) ) {
					$out['products'][ $key ] = array();
				}
				$out['products'][ $key ][ $ev ] = (int) ( $out['products'][ $key ][ $ev ] ?? 0 ) + $n;
			} elseif ( 'type' === $dim ) {
				$type = self::sanitize_toast_type( $key );
				if ( '' === $type ) {
					continue;
				}
				if ( ! isset( $out['types'][ $type ] ) ) {
					$out['types'][ $type ] = array();
				}
				$out['types'][ $type ][ $ev ] = (int) ( $out['types'][ $type ][ $ev ] ?? 0 ) + $n;
			} elseif ( 'source' === $dim ) {
				$src = self::sanitize_source( $key );
				if ( '' === $src ) {
					continue;
				}
				if ( ! isset( $out['sources'][ $src ] ) ) {
					$out['sources'][ $src ] = array();
				}
				$out['sources'][ $src ][ $ev ] = (int) ( $out['sources'][ $src ][ $ev ] ?? 0 ) + $n;
			} elseif ( 'page' === $dim ) {
				$page = self::sanitize_page( $key );
				if ( '' === $page ) {
					continue;
				}
				if ( ! isset( $out['pages'][ $page ] ) ) {
					$out['pages'][ $page ] = array();
				}
				$out['pages'][ $page ][ $ev ] = (int) ( $out['pages'][ $page ][ $ev ] ?? 0 ) + $n;
			} elseif ( 'trigger' === $dim ) {
				$trig = self::sanitize_trigger( $key );
				if ( '' === $trig ) {
					continue;
				}
				if ( ! isset( $out['triggers'][ $trig ] ) ) {
					$out['triggers'][ $trig ] = array();
				}
				$out['triggers'][ $trig ][ $ev ] = (int) ( $out['triggers'][ $trig ][ $ev ] ?? 0 ) + $n;
			} elseif ( 'click' === $dim ) {
				$tgt = self::sanitize_click_target( $key );
				if ( '' === $tgt ) {
					continue;
				}
				if ( ! isset( $out['clicks'][ $tgt ] ) ) {
					$out['clicks'][ $tgt ] = array();
				}
				$out['clicks'][ $tgt ][ $ev ] = (int) ( $out['clicks'][ $tgt ][ $ev ] ?? 0 ) + $n;
			} elseif ( 'dwell' === $dim ) {
				if ( ! isset( $out['dwell'][ $key ] ) ) {
					$out['dwell'][ $key ] = array();
				}
				$out['dwell'][ $key ][ $ev ] = (int) ( $out['dwell'][ $key ][ $ev ] ?? 0 ) + $n;
			}
		}

		return $out;
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
		$pct  = round( ( ( $current - $prior ) / $prior ) * 100, 1 );
		$dir  = 0.0 === $pct ? 'flat' : ( $pct > 0 ? 'up' : 'down' );
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
		$pp   = round( $current - $prior, 1 );
		$dir  = 0.0 === $pp ? 'flat' : ( $pp > 0 ? 'up' : 'down' );
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
