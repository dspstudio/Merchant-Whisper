<?php
/**
 * Sales cache rebuild and order hooks.
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transient cache of notification events.
 */
class MW_Sales_Toast_Cache {

	/**
	 * Cron schedule key registered via cron_schedules.
	 */
	const CRON_SCHEDULE = 'mw_st_cache';

	/**
	 * Temporary cron interval (seconds) while saving settings.
	 *
	 * @var int|null
	 */
	private static $cron_interval_override = null;

	/**
	 * Register cron and order hooks.
	 */
	public static function init() {
		add_action( MW_SALES_TOAST_CRON, array( __CLASS__, 'rebuild' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_cron' ) );

		add_action( 'woocommerce_new_order', array( __CLASS__, 'invalidate' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'invalidate' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'invalidate' ), 20 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'invalidate' ), 20 );
	}

	/**
	 * Clamp minutes setting (1–120).
	 *
	 * @param mixed $minutes Raw minutes.
	 * @return int
	 */
	public static function clamp_minutes( $minutes ) {
		return max( 1, min( 120, (int) $minutes ) );
	}

	/**
	 * Cache TTL in seconds from settings.
	 *
	 * @param array|null $settings Optional settings snapshot.
	 * @return int
	 */
	public static function cache_ttl_seconds( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : MW_Sales_Toast_Settings::get();
		$minutes  = self::clamp_minutes( $settings['cache_minutes'] ?? 60 );
		return $minutes * MINUTE_IN_SECONDS;
	}

	/**
	 * Cron interval in seconds from settings.
	 *
	 * @param array|null $settings Optional settings snapshot.
	 * @return int
	 */
	public static function cron_interval_seconds( $settings = null ) {
		if ( null !== self::$cron_interval_override ) {
			return (int) self::$cron_interval_override;
		}
		$settings = is_array( $settings ) ? $settings : MW_Sales_Toast_Settings::get();
		$minutes  = self::clamp_minutes( $settings['cron_minutes'] ?? 60 );
		return $minutes * MINUTE_IN_SECONDS;
	}

	/**
	 * Clear all scheduled instances of the cache cron.
	 */
	public static function clear_cron() {
		$timestamp = wp_next_scheduled( MW_SALES_TOAST_CRON );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, MW_SALES_TOAST_CRON );
			$timestamp = wp_next_scheduled( MW_SALES_TOAST_CRON );
		}
	}

	/**
	 * Schedule cache cron if missing, or migrate off legacy schedule names.
	 */
	public static function ensure_cron() {
		$event = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( MW_SALES_TOAST_CRON ) : false;

		if ( $event && isset( $event->schedule ) && self::CRON_SCHEDULE !== $event->schedule ) {
			self::clear_cron();
			$event = false;
		}

		if ( ! $event && ! wp_next_scheduled( MW_SALES_TOAST_CRON ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, MW_SALES_TOAST_CRON );
		}
	}

	/**
	 * Force-reschedule cron after interval settings change.
	 *
	 * @param array|null $settings Optional settings snapshot (used mid-sanitize before option save).
	 */
	public static function reschedule_cron( $settings = null ) {
		if ( is_array( $settings ) ) {
			self::$cron_interval_override = self::cron_interval_seconds( $settings );
		}
		self::clear_cron();
		wp_schedule_event( time() + 60, self::CRON_SCHEDULE, MW_SALES_TOAST_CRON );
		self::$cron_interval_override = null;
	}

	/**
	 * Rebuild once (debounced across stacked order hooks).
	 *
	 * @param mixed $_unused Order id unused.
	 */
	public static function invalidate( $_unused = null ) {
		if ( get_transient( 'mw_st_rebuild_lock' ) ) {
			return;
		}

		set_transient( 'mw_st_rebuild_lock', 1, 15 );
		delete_transient( MW_SALES_TOAST_TRANSIENT );
		self::rebuild();
	}

	/**
	 * Get events (from cache or rebuild).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_events() {
		$cached = get_transient( MW_SALES_TOAST_TRANSIENT );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		return self::rebuild();
	}

	/**
	 * Rebuild and store cache.
	 *
	 * @param array|null $settings Optional settings snapshot (e.g. mid-sanitize).
	 * @return array<int, array<string, mixed>>
	 */
	public static function rebuild( $settings = null ) {
		$settings = is_array( $settings ) ? $settings : MW_Sales_Toast_Settings::get();
		$need_sales = ! empty( $settings['type_sale'] )
			|| ! class_exists( 'MW_Sales_Toast_Types' )
			|| ! MW_Sales_Toast_Types::any_enabled( $settings );
		$events     = $need_sales ? self::build_events( $settings ) : array();
		if ( class_exists( 'MW_Sales_Toast_Types' ) ) {
			$events = MW_Sales_Toast_Types::mix( $events, $settings );
		}
		set_transient( MW_SALES_TOAST_TRANSIENT, $events, self::cache_ttl_seconds( $settings ) );
		return $events;
	}

	/**
	 * Build event list from settings.
	 *
	 * @param array $settings Settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function build_events( $settings ) {
		$limit  = max( 1, min( 30, (int) $settings['max_events'] ) );
		$source = $settings['source'];

		if ( 'demo' === $source ) {
			return self::demo_events( $settings, $limit );
		}

		$real = self::real_events( $settings );

		if ( 'real_orders' === $source ) {
			return array_slice( $real, 0, $limit );
		}

		// real_then_demo
		if ( count( $real ) >= min( 3, $limit ) ) {
			return array_slice( $real, 0, $limit );
		}

		$need = max( 0, $limit - count( $real ) );
		return array_merge( array_slice( $real, 0, $limit ), self::demo_events( $settings, $need ) );
	}

	/**
	 * Real order events.
	 *
	 * @param array $settings Settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function real_events( $settings ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$limit     = max( 5, min( 100, (int) $settings['max_cached_orders'] ) );
		$lookback  = max( 1, (int) $settings['lookback_days'] );
		$show_img  = ! empty( $settings['show_image'] );
		$hide_name = ! empty( $settings['hide_names'] );
		$fallback  = $settings['fallback_name'];
		$require   = ! empty( $settings['require_consent'] );

		// ISO datetime is more reliable across CPT + HPOS order stores than a raw timestamp.
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $lookback * DAY_IN_SECONDS ) );
		$orders = wc_get_orders(
			array(
				'limit'        => $limit,
				'status'       => array( 'completed', 'processing' ),
				'orderby'      => 'date',
				'order'        => 'DESC',
				'date_created' => '>' . $since,
				'return'       => 'objects',
			)
		);

		$events = array();

		foreach ( $orders as $order ) {
			if ( $require && ! self::order_allows_public( $order ) ) {
				continue;
			}

			$items = $order->get_items();
			if ( empty( $items ) ) {
				continue;
			}

			$item    = reset( $items );
			$product = $item->get_product();
			$title   = $product ? $product->get_name() : $item->get_name();
			if ( '' === trim( (string) $title ) ) {
				continue;
			}

			$first = $hide_name ? $fallback : trim( (string) $order->get_billing_first_name() );
			if ( '' === $first ) {
				$first = $fallback;
			}

			$city = trim( (string) $order->get_billing_city() );
			if ( '' === $city ) {
				$city = __( 'nearby', 'mw-sales-toast' );
			}

			$image = '';
			$url   = '';
			$pid   = 0;
			if ( $product ) {
				$pid = (int) $product->get_id();
				if ( $product->is_type( 'variation' ) ) {
					$pid = (int) $product->get_parent_id();
				}
				$url = get_permalink( $pid > 0 ? $pid : $product->get_id() );
				if ( $show_img ) {
					$image_id = $product->get_image_id();
					$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
				}
			}

			$created = $order->get_date_created();
			$ts      = $created ? $created->getTimestamp() : 0;
			$stock   = self::stock_fields( $product, $settings );
			$events[] = array(
				'id'         => 'o' . $order->get_id(),
				'type'       => 'sale',
				'productId'  => $pid > 0 ? $pid : null,
				'name'       => $first,
				'city'       => $city,
				'title'      => $title,
				'url'        => $url ? $url : '',
				'image'      => $image ? $image : '',
				// Timestamp so the front end can format with the visitor's current Time label setting.
				'whenTs'     => $ts > 0 ? $ts : null,
				'when'       => '',
				'stock'      => $stock['stock'],
				'stockLabel' => $stock['stockLabel'],
			);
		}

		return $events;
	}

	/**
	 * Stock tokens for a real-order product (empty when off / unmanaged / above threshold).
	 *
	 * @param WC_Product|null|false $product  Product.
	 * @param array                 $settings Settings.
	 * @return array{stock:string,stockLabel:string}
	 */
	public static function stock_fields( $product, $settings ) {
		$empty = array(
			'stock'      => '',
			'stockLabel' => '',
		);

		$mode = isset( $settings['stock_display'] ) ? (string) $settings['stock_display'] : 'off';
		if ( ! in_array( $mode, array( 'exact_low', 'soft' ), true ) ) {
			return $empty;
		}
		if ( ! $product || ! is_object( $product ) || ! method_exists( $product, 'managing_stock' ) ) {
			return $empty;
		}
		if ( ! $product->managing_stock() ) {
			return $empty;
		}

		$qty = $product->get_stock_quantity();
		if ( null === $qty || '' === $qty ) {
			return $empty;
		}

		$qty       = (int) $qty;
		$threshold = max( 1, min( 50, (int) ( $settings['stock_threshold'] ?? 5 ) ) );
		if ( $qty < 1 || $qty > $threshold ) {
			return $empty;
		}

		$stock = (string) $qty;

		if ( 'soft' === $mode ) {
			if ( 1 === $qty ) {
				$label = __( 'last one left', 'mw-sales-toast' );
			} elseif ( $qty <= 3 ) {
				$label = __( 'only a few left', 'mw-sales-toast' );
			} else {
				$label = __( 'low stock', 'mw-sales-toast' );
			}
		} else {
			$label = sprintf(
				/* translators: %d: remaining stock quantity */
				_n( 'only %d left', 'only %d left', $qty, 'mw-sales-toast' ),
				$qty
			);
		}

		/**
		 * Filter stock toast fields for a product.
		 *
		 * @param array      $fields   stock + stockLabel.
		 * @param WC_Product $product  Product.
		 * @param array      $settings Settings.
		 * @param int        $qty      Quantity.
		 */
		return (array) apply_filters(
			'mw_sales_toast_stock_fields',
			array(
				'stock'      => $stock,
				'stockLabel' => $label,
			),
			$product,
			$settings,
			$qty
		);
	}

	/**
	 * Format a purchase time for the toast meta line (complete phrase).
	 *
	 * @param int    $timestamp Unix timestamp.
	 * @param string $style     natural|exact.
	 * @return string
	 */
	public static function format_when( $timestamp, $style = 'natural' ) {
		$timestamp = (int) $timestamp;
		if ( $timestamp <= 0 ) {
			return '';
		}

		$style = 'exact' === $style ? 'exact' : 'natural';
		$diff  = max( 0, time() - $timestamp );

		if ( 'exact' === $style ) {
			$label = sprintf(
				/* translators: %s: relative time, e.g. "2 minutes" */
				__( '%s ago', 'mw-sales-toast' ),
				human_time_diff( $timestamp, time() )
			);
		} elseif ( $diff < 2 * MINUTE_IN_SECONDS ) {
			$label = __( 'just now', 'mw-sales-toast' );
		} elseif ( $diff < HOUR_IN_SECONDS ) {
			$label = __( 'a few minutes ago', 'mw-sales-toast' );
		} elseif ( $diff < 6 * HOUR_IN_SECONDS ) {
			$label = __( 'a couple of hours ago', 'mw-sales-toast' );
		} elseif ( $diff < DAY_IN_SECONDS ) {
			$label = __( 'earlier today', 'mw-sales-toast' );
		} elseif ( $diff < 2 * DAY_IN_SECONDS ) {
			$label = __( 'yesterday', 'mw-sales-toast' );
		} elseif ( $diff < WEEK_IN_SECONDS ) {
			$label = __( 'a few days ago', 'mw-sales-toast' );
		} else {
			$label = __( 'recently', 'mw-sales-toast' );
		}

		/**
		 * Filter the time label shown under a toast.
		 *
		 * @param string $label     Display phrase.
		 * @param int    $timestamp Order created timestamp.
		 * @param string $style     natural|exact.
		 * @param int    $diff      Seconds since purchase.
		 */
		return (string) apply_filters( 'mw_sales_toast_when_label', $label, $timestamp, $style, $diff );
	}

	/**
	 * Whether an order may appear in public toasts when consent is required.
	 *
	 * Explicit opt-out ("no") is always hidden. Missing meta means the consent
	 * checkbox was never shown (legacy / admin-created orders) and is allowed.
	 * Checkout paths always write "yes" or "no" when the field is active.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function order_allows_public( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$consent = (string) $order->get_meta( MW_SALES_TOAST_CONSENT_META );
		if ( '' === $consent ) {
			return true;
		}

		return 'yes' === $consent;
	}

	/**
	 * Parse demo people lines.
	 *
	 * @param string $raw Textarea.
	 * @return array<int, array{name:string,city:string}>
	 */
	public static function parse_people( $raw ) {
		$people = array();
		$lines  = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( ',', $line, 2 ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$people[] = array(
				'name' => $parts[0],
				'city' => isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : __( 'nearby', 'mw-sales-toast' ),
			);
		}

		return $people;
	}

	/**
	 * Parse demo when lines.
	 *
	 * @param string $raw   Textarea.
	 * @param string $style natural|exact (polishes legacy "22 minutes" fragments).
	 * @return string[]
	 */
	public static function parse_whens( $raw, $style = 'natural' ) {
		$whens = array();
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$whens[] = self::polish_when_phrase( $line, $style );
		}

		return $whens ? $whens : array( __( 'a few minutes ago', 'mw-sales-toast' ) );
	}

	/**
	 * Whether a demo time line looks like a legacy numeric fragment.
	 *
	 * @param string $line Raw line.
	 * @return bool
	 */
	public static function is_legacy_when_fragment( $line ) {
		return (bool) preg_match( '/^\d+\s+(minutes?|hours?|days?|weeks?)$/i', trim( (string) $line ) );
	}

	/**
	 * Turn legacy fragments ("22 minutes") into full display phrases.
	 * Soft phrases ("just now") are left unchanged.
	 *
	 * @param string $line  Raw phrase.
	 * @param string $style natural|exact.
	 * @return string
	 */
	public static function polish_when_phrase( $line, $style = 'natural' ) {
		$line  = trim( (string) $line );
		$style = 'exact' === $style ? 'exact' : 'natural';

		if ( '' === $line || ! self::is_legacy_when_fragment( $line ) ) {
			return $line;
		}

		if ( ! preg_match( '/^(\d+)\s+(minutes?|hours?|days?|weeks?)$/i', $line, $m ) ) {
			return $line;
		}

		$n    = max( 1, (int) $m[1] );
		$unit = strtolower( $m[2] );

		if ( 'exact' === $style ) {
			return sprintf(
				/* translators: %s: relative time, e.g. "22 minutes" */
				__( '%s ago', 'mw-sales-toast' ),
				$line
			);
		}

		// Map fragment → natural bucket using an approximate duration.
		if ( 0 === strpos( $unit, 'week' ) ) {
			$seconds = $n * WEEK_IN_SECONDS;
		} elseif ( 0 === strpos( $unit, 'day' ) ) {
			$seconds = $n * DAY_IN_SECONDS;
		} elseif ( 0 === strpos( $unit, 'hour' ) ) {
			$seconds = $n * HOUR_IN_SECONDS;
		} else {
			$seconds = $n * MINUTE_IN_SECONDS;
		}

		// Reuse timestamp helper with a synthetic "now - seconds".
		return self::format_when( time() - $seconds, 'natural' );
	}

	/**
	 * Upgrade saved demo times that still use numeric fragments.
	 *
	 * @param string $raw Raw textarea.
	 * @return string
	 */
	public static function migrate_demo_whens( $raw ) {
		$raw   = (string) $raw;
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		$frag  = 0;
		$total = 0;

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$total++;
			if ( self::is_legacy_when_fragment( $line ) ) {
				$frag++;
			}
		}

		if ( $total > 0 && $frag === $total ) {
			$defaults = MW_Sales_Toast_Settings::defaults();
			return (string) $defaults['demo_whens'];
		}

		return $raw;
	}

	/**
	 * Random catalog products for demo.
	 *
	 * @param int $limit Limit.
	 * @return array<int, array{title:string,url:string,image:string}>
	 */
	public static function products( $limit = 8 ) {
		if ( ! post_type_exists( 'product' ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, (int) $limit ),
				'orderby'                => 'rand',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();

		foreach ( $query->posts as $post ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
			if ( $product && ! $product->is_visible() ) {
				continue;
			}

			$title = $product ? $product->get_name() : get_the_title( $post );
			$url   = get_permalink( $post->ID );
			$image = '';

			if ( $product ) {
				$image_id = $product->get_image_id();
				$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
			} elseif ( has_post_thumbnail( $post ) ) {
				$image = get_the_post_thumbnail_url( $post, 'thumbnail' );
			}

			$items[] = array(
				'id'    => (int) $post->ID,
				'title' => $title,
				'url'   => $url ? $url : '',
				'image' => $image ? $image : '',
			);
		}

		return $items;
	}

	/**
	 * Demo events.
	 *
	 * @param array $settings Settings.
	 * @param int   $need     Count.
	 * @return array<int, array<string, mixed>>
	 */
	public static function demo_events( $settings, $need ) {
		$need = max( 0, (int) $need );
		if ( $need < 1 ) {
			return array();
		}

		$style    = isset( $settings['when_style'] ) ? (string) $settings['when_style'] : 'natural';
		$people   = self::parse_people( $settings['demo_people'] );
		$whens    = self::parse_whens( $settings['demo_whens'], $style );
		$products = self::products( max( 8, (int) $settings['max_events'] ) );
		$show_img = ! empty( $settings['show_image'] );
		$events   = array();

		if ( empty( $people ) ) {
			return $events;
		}

		// Fallback product label if catalog empty.
		if ( empty( $products ) ) {
			$products = array(
				array(
					'id'    => 0,
					'title' => __( 'a product', 'mw-sales-toast' ),
					'url'   => '',
					'image' => '',
				),
			);
		}

		for ( $i = 0; $i < $need; $i++ ) {
			$person  = $people[ array_rand( $people ) ];
			$product = $products[ array_rand( $products ) ];
			$name    = ! empty( $settings['hide_names'] ) ? $settings['fallback_name'] : $person['name'];
			$pid     = isset( $product['id'] ) ? (int) $product['id'] : 0;

			$events[] = array(
				'id'          => 'd' . $i . '-' . wp_generate_password( 4, false ),
				'type'        => 'sale',
				'productId'   => $pid > 0 ? $pid : null,
				'name'        => $name,
				'city'        => $person['city'],
				'title'       => $product['title'],
				'url'         => $product['url'],
				'image'       => $show_img ? $product['image'] : '',
				'when'        => $whens[ array_rand( $whens ) ],
				'whenLiteral' => true,
				'demo'        => true,
				// Demo never exposes stock.
				'stock'       => '',
				'stockLabel'  => '',
			);
		}

		return $events;
	}
}
