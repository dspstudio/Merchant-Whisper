<?php
/**
 * Front-end enqueue.
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assets and page gates.
 */
class MW_Sales_Toast_Frontend {

	/**
	 * Hook enqueue.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether assets should load.
	 *
	 * @param array|null $settings Settings.
	 * @return bool
	 */
	public static function should_load( $settings = null ) {
		$settings = $settings ? $settings : MW_Sales_Toast_Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || is_feed() ) {
			return false;
		}

		// Real-only without WooCommerce: nothing to show unless extra types can run.
		if ( 'real_orders' === $settings['source'] && ! class_exists( 'WooCommerce' ) ) {
			$extras = ! empty( $settings['type_cta'] ) || ! empty( $settings['type_viewing'] ) || ! empty( $settings['type_review'] );
			if ( ! $extras ) {
				return false;
			}
		}

		if ( ! empty( $settings['guests_only'] ) && is_user_logged_in() ) {
			return false;
		}

		$show_on = isset( $settings['show_on'] ) ? (string) $settings['show_on'] : 'all';

		if ( 'home' === $show_on ) {
			if ( ! is_front_page() ) {
				return false;
			}
		} elseif ( 'products' === $show_on ) {
			if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
				return false;
			}
		} elseif ( 'shop' === $show_on ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return false;
			}
			$on_shop = ( function_exists( 'is_shop' ) && is_shop() )
				|| ( function_exists( 'is_product' ) && is_product() )
				|| ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() );
			if ( ! $on_shop ) {
				return false;
			}
		}

		if ( ! empty( $settings['exclude_home'] ) && 'home' !== $show_on && is_front_page() ) {
			return false;
		}

		$wc         = class_exists( 'WooCommerce' );
		$is_thankyou = $wc
			&& function_exists( 'is_wc_endpoint_url' )
			&& is_wc_endpoint_url( 'order-received' );

		if ( ! empty( $settings['hide_cart_checkout'] ) && $wc ) {
			if ( function_exists( 'is_cart' ) && is_cart() ) {
				return false;
			}
			if ( function_exists( 'is_checkout' ) && is_checkout() && ! $is_thankyou ) {
				return false;
			}
		}

		if ( ! empty( $settings['hide_thankyou'] ) && $is_thankyou ) {
			return false;
		}

		if ( ! empty( $settings['hide_account'] ) && $wc && function_exists( 'is_account_page' ) && is_account_page() ) {
			return false;
		}

		if ( ! self::passes_targeting( $settings ) ) {
			return false;
		}

		return (bool) apply_filters( 'mw_sales_toast_should_load', true, $settings );
	}

	/**
	 * URL / catalog / role gates.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private static function passes_targeting( $settings ) {
		if ( ! self::passes_url_rules( $settings ) ) {
			return false;
		}

		if ( ! self::passes_role_rules( $settings ) ) {
			return false;
		}

		if ( ! self::passes_catalog_rules( $settings ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Current request path relative to home.
	 *
	 * @return string
	 */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = $path ? $path : '/';

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = untrailingslashit( $home_path ? $home_path : '' );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
			$path = $path ? $path : '/';
		}

		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}

		return $path;
	}

	/**
	 * Whether a path matches a pattern (* wildcards).
	 *
	 * @param string $path    Request path.
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	private static function path_matches( $path, $pattern ) {
		$pattern = trim( (string) $pattern );
		if ( '' === $pattern ) {
			return false;
		}
		$pattern = '/' . ltrim( $pattern, '/' );
		if ( false === strpos( $pattern, '*' ) ) {
			return $path === $pattern || $path === trailingslashit( $pattern ) || trailingslashit( $path ) === trailingslashit( $pattern );
		}
		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
		return (bool) preg_match( $regex, $path );
	}

	/**
	 * URL include / exclude.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private static function passes_url_rules( $settings ) {
		$path = self::request_path();

		$exclude = preg_split( '/\r\n|\r|\n/', (string) ( $settings['url_exclude'] ?? '' ) ) ?: array();
		foreach ( $exclude as $pattern ) {
			if ( self::path_matches( $path, $pattern ) ) {
				return false;
			}
		}

		$include = preg_split( '/\r\n|\r|\n/', (string) ( $settings['url_include'] ?? '' ) ) ?: array();
		$include = array_values(
			array_filter(
				array_map( 'trim', $include ),
				static function ( $line ) {
					return '' !== $line;
				}
			)
		);
		if ( empty( $include ) ) {
			return true;
		}

		foreach ( $include as $pattern ) {
			if ( self::path_matches( $path, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Hide for selected roles.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private static function passes_role_rules( $settings ) {
		$roles = MW_Sales_Toast_Settings::normalize_id_list( $settings['hide_roles'] ?? array(), true );
		if ( empty( $roles ) || ! is_user_logged_in() ) {
			return true;
		}

		$user = wp_get_current_user();
		if ( ! $user || empty( $user->roles ) ) {
			return true;
		}

		foreach ( (array) $user->roles as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Product / category page excludes (includes filter the toast feed, not pages).
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	private static function passes_catalog_rules( $settings ) {
		$exc_products = MW_Sales_Toast_Settings::normalize_id_list( $settings['exclude_products'] ?? array() );
		$exc_cats     = MW_Sales_Toast_Settings::normalize_id_list( $settings['exclude_categories'] ?? array() );
		$wc           = class_exists( 'WooCommerce' );

		if ( $wc && function_exists( 'is_product' ) && is_product() ) {
			$product_id = (int) get_queried_object_id();
			if ( $product_id && in_array( $product_id, $exc_products, true ) ) {
				return false;
			}
			$exc_cats_expanded = self::expand_category_ids( $exc_cats );
			if ( $product_id && ! empty( $exc_cats_expanded ) && self::product_in_categories( $product_id, $exc_cats_expanded ) ) {
				return false;
			}
			return true;
		}

		if ( $wc && function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() && function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			$tid  = ( $term && isset( $term->term_id ) ) ? (int) $term->term_id : 0;
			$exc_cats_expanded = self::expand_category_ids( $exc_cats );
			if ( $tid && in_array( $tid, $exc_cats_expanded, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Filter toast events by include/exclude product & category lists.
	 *
	 * Include products + categories = union (product is listed OR in an included category).
	 * Exclude products + categories = never show those products in the feed.
	 *
	 * @param array $events   Events.
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function filter_events_by_catalog( $events, $settings ) {
		$inc_products = MW_Sales_Toast_Settings::normalize_id_list( $settings['include_products'] ?? array() );
		$exc_products = MW_Sales_Toast_Settings::normalize_id_list( $settings['exclude_products'] ?? array() );
		$inc_cats     = self::expand_category_ids( $settings['include_categories'] ?? array() );
		$exc_cats     = self::expand_category_ids( $settings['exclude_categories'] ?? array() );

		$has_include = ! empty( $inc_products ) || ! empty( $inc_cats );
		if ( ! $has_include && empty( $exc_products ) && empty( $exc_cats ) ) {
			return $events;
		}

		$out = array();
		foreach ( (array) $events as $event ) {
			$type = isset( $event['type'] ) ? (string) $event['type'] : 'sale';
			if ( 'cta' === $type ) {
				$out[] = $event;
				continue;
			}
			$pid = isset( $event['productId'] ) ? absint( $event['productId'] ) : 0;
			if ( $pid < 1 ) {
				// Keep demo/unknown without product id only when no include list is set.
				if ( ! $has_include ) {
					$out[] = $event;
				}
				continue;
			}

			if ( in_array( $pid, $exc_products, true ) ) {
				continue;
			}
			if ( ! empty( $exc_cats ) && self::product_in_categories( $pid, $exc_cats ) ) {
				continue;
			}

			if ( $has_include ) {
				$in_products = ! empty( $inc_products ) && in_array( $pid, $inc_products, true );
				$in_cats     = ! empty( $inc_cats ) && self::product_in_categories( $pid, $inc_cats );
				if ( ! $in_products && ! $in_cats ) {
					continue;
				}
			}

			$out[] = $event;
		}

		return $out;
	}

	/**
	 * Expand category IDs to include all child terms.
	 *
	 * @param mixed $ids Raw category IDs.
	 * @return array<int, int>
	 */
	private static function expand_category_ids( $ids ) {
		$ids = MW_Sales_Toast_Settings::normalize_id_list( $ids );
		if ( empty( $ids ) || ! taxonomy_exists( 'product_cat' ) ) {
			return $ids;
		}

		$expanded = $ids;
		foreach ( $ids as $tid ) {
			$children = get_term_children( $tid, 'product_cat' );
			if ( is_wp_error( $children ) || empty( $children ) ) {
				continue;
			}
			foreach ( $children as $child ) {
				$expanded[] = absint( $child );
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $expanded ) ) ) );
	}

	/**
	 * Whether a product belongs to any of the given category term IDs.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $cat_ids    Term IDs (already expanded).
	 * @return bool
	 */
	private static function product_in_categories( $product_id, $cat_ids ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 || empty( $cat_ids ) || ! taxonomy_exists( 'product_cat' ) ) {
			return false;
		}
		return has_term( $cat_ids, 'product_cat', $product_id );
	}

	/**
	 * Whether a product is allowed by include/exclude product and category lists.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $settings   Settings.
	 * @return bool
	 */
	public static function product_passes_catalog( $product_id, $settings ) {
		$probe = array(
			array(
				'type'      => 'viewing',
				'productId' => absint( $product_id ),
			),
		);
		return ! empty( self::filter_events_by_catalog( $probe, $settings ) );
	}

	/**
	 * Apply catalog feed filters + optional PDP product match.
	 *
	 * @param array $events   Events.
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function filter_events_for_display( $events, $settings ) {
		$events = self::filter_events_by_catalog( $events, $settings );
		return self::filter_events_for_page( $events, $settings );
	}

	/**
	 * Filter events to the current product when match is on.
	 *
	 * @param array $events   Events.
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function filter_events_for_page( $events, $settings ) {
		if ( empty( $settings['match_product_page'] ) ) {
			return $events;
		}
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return $events;
		}

		$product_id = (int) get_queried_object_id();
		if ( $product_id < 1 ) {
			return $events;
		}

		$filtered = array();
		foreach ( (array) $events as $event ) {
			$type = isset( $event['type'] ) ? (string) $event['type'] : 'sale';
			if ( 'cta' === $type ) {
				$filtered[] = $event;
				continue;
			}
			$eid = isset( $event['productId'] ) ? (int) $event['productId'] : 0;
			if ( $eid === $product_id ) {
				$filtered[] = $event;
			}
		}

		return $filtered;
	}

	/**
	 * Enqueue script/style — settings + either REST fetch or inline events.
	 */
	public static function enqueue() {
		$settings = MW_Sales_Toast_Settings::get();

		if ( ! self::should_load( $settings ) ) {
			return;
		}

		wp_enqueue_style(
			'mw-sales-toast',
			MW_SALES_TOAST_URL . 'assets/toast.css',
			array(),
			MW_SALES_TOAST_VERSION
		);

		$design_css = MW_Sales_Toast_Settings::design_css( $settings );
		if ( '' !== $design_css ) {
			wp_add_inline_style( 'mw-sales-toast', $design_css );
		}

		wp_enqueue_script(
			'mw-sales-toast-pop',
			MW_SALES_TOAST_URL . 'assets/pop-sound.js',
			array(),
			MW_SALES_TOAST_VERSION,
			true
		);

		wp_enqueue_script(
			'mw-sales-toast',
			MW_SALES_TOAST_URL . 'assets/toast.js',
			array( 'mw-sales-toast-pop' ),
			MW_SALES_TOAST_VERSION,
			true
		);

		$delivery   = ( 'inline' === ( $settings['event_delivery'] ?? '' ) ) ? 'inline' : 'rest';
		$limit      = max( 1, min( 30, (int) $settings['max_events'] ) );
		if ( class_exists( 'MW_Sales_Toast_Types' ) && MW_Sales_Toast_Types::any_enabled( $settings ) ) {
			$limit = min( 40, $limit + 8 );
		}
		$breakpoint = max( 320, min( 1200, (int) ( $settings['mobile_breakpoint'] ?? 768 ) ) );

		$current_product_id = 0;
		if ( function_exists( 'is_product' ) && is_product() ) {
			$current_product_id = (int) get_queried_object_id();
		}

		$config = array(
			'delivery'             => $delivery,
			'endpoint'             => '',
			'nonce'                => '',
			'events'               => array(),
			'position'             => $settings['position'],
			'delay'                => max( 1, (int) $settings['delay'] ) * 1000,
			'duration'             => max( 2, (int) $settings['duration'] ) * 1000,
			'gap'                  => max( 1, (int) $settings['gap'] ) * 1000,
			'jitter'               => max( 0, min( 50, (int) ( $settings['jitter'] ?? 0 ) ) ),
			'respectReducedMotion' => ! empty( $settings['respect_reduced_motion'] ),
			'disableMobile'        => ! empty( $settings['disable_mobile'] ),
			'mobileBreakpoint'     => $breakpoint,
			'soundEnabled'         => ! empty( $settings['sound_enabled'] ),
			'imageFit'             => ( 'padded' === ( $settings['style_image_fit'] ?? '' ) ) ? 'padded' : 'full',
			'maxPerSession'        => max( 1, (int) $settings['max_per_session'] ),
			'muteHours'            => max( 0, (int) $settings['mute_hours'] ),
			'messageTemplate'      => $settings['message_template'],
			'viewingTemplate'      => (string) ( $settings['viewing_template'] ?? '' ),
			'reviewTemplate'       => (string) ( $settings['review_template'] ?? '' ),
			'ctaOnce'              => ! empty( $settings['cta_once'] ),
			'viewingMode'          => ( 'live' === ( $settings['viewing_mode'] ?? '' ) ) ? 'live' : 'simulated',
			'presenceEndpoint'     => '',
			'whenStyle'            => ( 'exact' === ( $settings['when_style'] ?? '' ) ) ? 'exact' : 'natural',
			'matchProductPage'     => ! empty( $settings['match_product_page'] ),
			'currentProductId'     => $current_product_id,
			'triggers'             => MW_Sales_Toast_Settings::triggers_config( $settings ),
			'analytics'            => false,
			'refetchMs'            => 0,
			'i18n'                 => array(
				/* translators: %s: relative time, e.g. "2 minutes" */
				'ago'          => __( '%s ago', 'mw-sales-toast' ),
				'justNow'      => __( 'just now', 'mw-sales-toast' ),
				'fewMinutes'   => __( 'a few minutes ago', 'mw-sales-toast' ),
				'coupleHours'  => __( 'a couple of hours ago', 'mw-sales-toast' ),
				'earlierToday' => __( 'earlier today', 'mw-sales-toast' ),
				'yesterday'    => __( 'yesterday', 'mw-sales-toast' ),
				'fewDays'      => __( 'a few days ago', 'mw-sales-toast' ),
				'recently'     => __( 'recently', 'mw-sales-toast' ),
				'minute'       => __( 'minute', 'mw-sales-toast' ),
				'minutes'      => __( 'minutes', 'mw-sales-toast' ),
				'hour'         => __( 'hour', 'mw-sales-toast' ),
				'hours'        => __( 'hours', 'mw-sales-toast' ),
				'day'          => __( 'day', 'mw-sales-toast' ),
				'days'         => __( 'days', 'mw-sales-toast' ),
				'week'         => __( 'week', 'mw-sales-toast' ),
				'weeks'        => __( 'weeks', 'mw-sales-toast' ),
				'month'        => __( 'month', 'mw-sales-toast' ),
				'months'       => __( 'months', 'mw-sales-toast' ),
				'copied'       => __( 'Copied', 'mw-sales-toast' ),
				'copyFailed'   => __( 'Copy failed', 'mw-sales-toast' ),
				'now'          => __( 'now', 'mw-sales-toast' ),
				'person'       => __( 'person', 'mw-sales-toast' ),
				'people'       => __( 'people', 'mw-sales-toast' ),
			),
		);

		if ( 'inline' === $delivery ) {
			$events           = array_slice( MW_Sales_Toast_Cache::get_events(), 0, max( $limit * 3, 30 ) );
			$events           = self::filter_events_for_display( $events, $settings );
			if ( $current_product_id && class_exists( 'MW_Sales_Toast_Types' ) ) {
				$events = MW_Sales_Toast_Types::inject_current_viewing( $events, $settings, $current_product_id );
			}
			$config['events'] = array_values( array_slice( $events, 0, $limit ) );
			if ( class_exists( 'MW_Sales_Toast_Analytics' ) && MW_Sales_Toast_Analytics::is_enabled() ) {
				$config['nonce'] = MW_Sales_Toast_REST::create_nonce();
			}
		} else {
			$config['endpoint']  = esc_url_raw( rest_url( 'mw-st/v1/notifications' ) );
			$config['nonce']     = MW_Sales_Toast_REST::create_nonce();
			$config['refetchMs'] = MW_Sales_Toast_Cache::cache_ttl_seconds( $settings ) * 1000;
		}

		if ( ! empty( $settings['type_viewing'] ) && 'live' === ( $settings['viewing_mode'] ?? '' ) && $current_product_id > 0 ) {
			$config['presenceEndpoint'] = esc_url_raw( rest_url( 'mw-st/v1/presence' ) );
			if ( empty( $config['nonce'] ) ) {
				$config['nonce'] = MW_Sales_Toast_REST::create_nonce();
			}
		}

		if ( class_exists( 'MW_Sales_Toast_Analytics' ) && MW_Sales_Toast_Analytics::is_enabled() ) {
			$config['analytics'] = array(
				'endpoint' => esc_url_raw( rest_url( 'mw-st/v1/analytics' ) ),
				'pageType' => self::page_type(),
			);
			if ( empty( $config['nonce'] ) ) {
				$config['nonce'] = MW_Sales_Toast_REST::create_nonce();
			}
		}

		wp_localize_script( 'mw-sales-toast', 'mwSalesToast', $config );
	}

	/**
	 * Coarse page type for analytics (no path PII).
	 *
	 * @return string
	 */
	private static function page_type() {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'shop';
		}
		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			return 'taxonomy';
		}
		if ( is_front_page() ) {
			return 'home';
		}
		return 'other';
	}
}
