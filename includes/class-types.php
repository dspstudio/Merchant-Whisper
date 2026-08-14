<?php
/**
 * Extra toast types: viewing, reviews, CTA/coupon.
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build and mix non-purchase toast events.
 */
class MW_Sales_Toast_Types {

	const PRESENCE_TRANSIENT = 'mw_st_presence';

	/**
	 * Hook review cache invalidation.
	 */
	public static function init() {
		add_action( 'comment_post', array( __CLASS__, 'maybe_invalidate_review' ), 20, 3 );
		add_action( 'wp_set_comment_status', array( __CLASS__, 'maybe_invalidate_review' ), 20, 1 );
		add_action( 'deleted_comment', array( __CLASS__, 'maybe_invalidate_review' ), 20, 1 );
	}

	/**
	 * Whether any extra type is enabled.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function any_enabled( $settings ) {
		return ! empty( $settings['type_viewing'] )
			|| ! empty( $settings['type_review'] )
			|| ! empty( $settings['type_cta'] );
	}

	/**
	 * Mix extra types into the sales event list.
	 *
	 * @param array $sales    Sale events.
	 * @param array $settings Settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function mix( $sales, $settings ) {
		$chunks = array();

		if ( ! empty( $settings['type_sale'] ) ) {
			$chunks[] = array_values( (array) $sales );
		} elseif ( ! self::any_enabled( $settings ) ) {
			$chunks[] = array_values( (array) $sales );
		}

		if ( ! empty( $settings['type_viewing'] ) ) {
			$chunks[] = self::viewing_events( $settings );
		}
		if ( ! empty( $settings['type_review'] ) ) {
			$chunks[] = self::review_events( $settings );
		}
		if ( ! empty( $settings['type_cta'] ) ) {
			$cta = self::cta_event( $settings );
			if ( $cta ) {
				$chunks[] = array( $cta );
			}
		}

		$chunks = array_values(
			array_filter(
				$chunks,
				static function ( $c ) {
					return ! empty( $c );
				}
			)
		);

		if ( empty( $chunks ) ) {
			return array();
		}
		if ( 1 === count( $chunks ) ) {
			return $chunks[0];
		}

		$out = array();
		$max = 0;
		foreach ( $chunks as $chunk ) {
			$max = max( $max, count( $chunk ) );
		}
		for ( $i = 0; $i < $max; $i++ ) {
			foreach ( $chunks as $chunk ) {
				if ( isset( $chunk[ $i ] ) ) {
					$out[] = $chunk[ $i ];
				}
			}
		}

		$cap = max( 1, min( 40, (int) ( $settings['max_events'] ?? 8 ) + 8 ) );
		return array_slice( $out, 0, $cap );
	}

	/**
	 * Inject a live/simulated viewing event for the current product.
	 *
	 * @param array $events      Events.
	 * @param array $settings    Settings.
	 * @param int   $product_id  Current product ID.
	 * @return array
	 */
	public static function inject_current_viewing( $events, $settings, $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 || empty( $settings['type_viewing'] ) ) {
			return $events;
		}

		$chosen = self::viewing_product_ids( $settings );
		$is_live = 'live' === ( $settings['viewing_mode'] ?? 'simulated' );
		if ( ! $is_live && ! empty( $chosen ) && ! in_array( $product_id, $chosen, true ) ) {
			return $events;
		}
		if (
			class_exists( 'MW_Sales_Toast_Frontend' )
			&& ! MW_Sales_Toast_Frontend::product_passes_catalog( $product_id, $settings )
		) {
			return $events;
		}

		$viewing = self::viewing_event_for_product( $product_id, $settings );
		if ( ! $viewing ) {
			return $events;
		}

		$out   = array();
		$found = false;
		foreach ( (array) $events as $event ) {
			$type = isset( $event['type'] ) ? (string) $event['type'] : 'sale';
			$eid  = isset( $event['productId'] ) ? (int) $event['productId'] : 0;
			if ( 'viewing' === $type && $eid === $product_id ) {
				$out[]  = $viewing;
				$found  = true;
				continue;
			}
			$out[] = $event;
		}
		if ( ! $found ) {
			array_unshift( $out, $viewing );
		}

		return $out;
	}

	/**
	 * Products pinned for Viewing now (empty = any).
	 *
	 * @param array $settings Settings.
	 * @return array<int, int>
	 */
	public static function viewing_product_ids( $settings ) {
		if ( class_exists( 'MW_Sales_Toast_Settings' ) ) {
			return MW_Sales_Toast_Settings::normalize_id_list( $settings['viewing_products'] ?? array() );
		}
		$out = array();
		foreach ( (array) ( $settings['viewing_products'] ?? array() ) as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Simulated or live viewing events for catalog products.
	 *
	 * @param array $settings Settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function viewing_events( $settings ) {
		$show_img = ! empty( $settings['show_image'] );
		$out      = array();
		$limit    = 8;

		if ( 'live' === ( $settings['viewing_mode'] ?? 'simulated' ) ) {
			$data = get_transient( self::PRESENCE_TRANSIENT );
			if ( ! is_array( $data ) ) {
				return array();
			}
			foreach ( $data as $pid => $visitors ) {
				if ( count( $out ) >= $limit ) {
					break;
				}
				$event = self::viewing_event_for_product( (int) $pid, $settings );
				if ( $event && (int) $event['count'] > 0 ) {
					if ( ! $show_img ) {
						$event['image'] = '';
					}
					$out[] = $event;
				}
			}
			return $out;
		}

		$chosen = self::viewing_product_ids( $settings );
		if ( ! empty( $chosen ) ) {
			foreach ( $chosen as $pid ) {
				if ( count( $out ) >= $limit ) {
					break;
				}
				$event = self::viewing_event_for_product( (int) $pid, $settings );
				if ( $event ) {
					if ( ! $show_img ) {
						$event['image'] = '';
					}
					$out[] = $event;
				}
			}
			return $out;
		}

		if ( ! class_exists( 'MW_Sales_Toast_Cache' ) ) {
			return array();
		}

		$products = MW_Sales_Toast_Cache::products( 8 );
		if ( empty( $products ) ) {
			return array();
		}

		$used  = array();
		$count = min( 2, count( $products ) );
		foreach ( $products as $product ) {
			if ( count( $out ) >= $count ) {
				break;
			}
			$pid = isset( $product['id'] ) ? (int) $product['id'] : 0;
			if ( $pid < 1 || isset( $used[ $pid ] ) ) {
				continue;
			}
			$used[ $pid ] = true;
			$event        = self::viewing_event_for_product( $pid, $settings, $product );
			if ( $event ) {
				if ( ! $show_img ) {
					$event['image'] = '';
				}
				$out[] = $event;
			}
		}

		return $out;
	}

	/**
	 * One viewing event for a product.
	 *
	 * @param int         $product_id Product ID.
	 * @param array       $settings   Settings.
	 * @param array|null  $product    Optional product row from Cache::products().
	 * @return array<string, mixed>|null
	 */
	public static function viewing_event_for_product( $product_id, $settings, $product = null ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 ) {
			return null;
		}

		if ( ! is_array( $product ) ) {
			$title = get_the_title( $product_id );
			if ( '' === trim( (string) $title ) ) {
				return null;
			}
			$url   = get_permalink( $product_id );
			$image = '';
			if ( ! empty( $settings['show_image'] ) ) {
				$wc_product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
				if ( $wc_product ) {
					$image_id = $wc_product->get_image_id();
					$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
					$title    = $wc_product->get_name();
				} elseif ( has_post_thumbnail( $product_id ) ) {
					$image = get_the_post_thumbnail_url( $product_id, 'thumbnail' );
				}
			}
			$product = array(
				'id'    => $product_id,
				'title' => $title,
				'url'   => $url ? $url : '',
				'image' => $image ? $image : '',
			);
		}

		$count = self::viewing_count( $product_id, $settings );
		if ( $count < 1 ) {
			return null;
		}

		$people = ( 1 === $count )
			? __( 'person', 'mw-sales-toast' )
			: __( 'people', 'mw-sales-toast' );

		return array(
			'id'        => 'v' . $product_id,
			'type'      => 'viewing',
			'productId' => $product_id,
			'name'      => '',
			'city'      => '',
			'title'     => $product['title'],
			'url'       => $product['url'],
			'image'     => ! empty( $settings['show_image'] ) ? ( $product['image'] ?? '' ) : '',
			'count'     => $count,
			'people'    => $people,
			'when'      => __( 'now', 'mw-sales-toast' ),
			'whenLiteral' => true,
			'demo'      => 'live' !== ( $settings['viewing_mode'] ?? 'simulated' ),
			'stock'     => '',
			'stockLabel'=> '',
		);
	}

	/**
	 * Viewer count for a product.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $settings   Settings.
	 * @return int
	 */
	public static function viewing_count( $product_id, $settings ) {
		$min = max( 1, min( 99, (int) ( $settings['viewing_min'] ?? 2 ) ) );
		$max = max( $min, min( 99, (int) ( $settings['viewing_max'] ?? 12 ) ) );

		if ( 'live' === ( $settings['viewing_mode'] ?? 'simulated' ) ) {
			$live = self::live_count( $product_id, $settings );
			if ( $live > 0 ) {
				return min( $max, $live );
			}
			return 0;
		}

		if ( $min === $max ) {
			return $min;
		}

		$hash = abs( crc32( 'mwst-viewing-' . (int) $product_id ) );
		return $min + ( $hash % ( $max - $min + 1 ) );
	}

	/**
	 * Record a product-page presence ping (no IPs).
	 *
	 * @param int    $product_id Product ID.
	 * @param string $visitor    Opaque session token from the browser.
	 * @param array  $settings   Settings.
	 * @return int Current count.
	 */
	public static function ping( $product_id, $visitor, $settings = null ) {
		$settings   = is_array( $settings ) ? $settings : MW_Sales_Toast_Settings::get();
		$product_id = absint( $product_id );
		$visitor    = self::sanitize_visitor( $visitor );
		if ( $product_id < 1 || '' === $visitor ) {
			return 0;
		}

		$window = max( 2, min( 30, (int) ( $settings['viewing_window'] ?? 5 ) ) ) * MINUTE_IN_SECONDS;
		$now    = time();
		$data   = get_transient( self::PRESENCE_TRANSIENT );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( ! isset( $data[ $product_id ] ) || ! is_array( $data[ $product_id ] ) ) {
			$data[ $product_id ] = array();
		}

		foreach ( $data as $pid => $visitors ) {
			if ( ! is_array( $visitors ) ) {
				unset( $data[ $pid ] );
				continue;
			}
			foreach ( $visitors as $vid => $ts ) {
				if ( ( $now - (int) $ts ) > $window ) {
					unset( $data[ $pid ][ $vid ] );
				}
			}
			if ( empty( $data[ $pid ] ) ) {
				unset( $data[ $pid ] );
			}
		}

		if ( ! isset( $data[ $product_id ] ) ) {
			$data[ $product_id ] = array();
		}
		$data[ $product_id ][ $visitor ] = $now;

		if ( count( $data ) > 80 ) {
			$data = array_slice( $data, -80, 80, true );
		}

		set_transient( self::PRESENCE_TRANSIENT, $data, $window + MINUTE_IN_SECONDS );
		return count( $data[ $product_id ] );
	}

	/**
	 * Live unique viewers in the window.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $settings   Settings.
	 * @return int
	 */
	public static function live_count( $product_id, $settings ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 ) {
			return 0;
		}
		$window = max( 2, min( 30, (int) ( $settings['viewing_window'] ?? 5 ) ) ) * MINUTE_IN_SECONDS;
		$now    = time();
		$data   = get_transient( self::PRESENCE_TRANSIENT );
		if ( ! is_array( $data ) || empty( $data[ $product_id ] ) || ! is_array( $data[ $product_id ] ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $data[ $product_id ] as $ts ) {
			if ( ( $now - (int) $ts ) <= $window ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * @param mixed $raw Raw visitor token.
	 * @return string
	 */
	public static function sanitize_visitor( $raw ) {
		$v = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $raw );
		if ( ! is_string( $v ) ) {
			return '';
		}
		$len = strlen( $v );
		if ( $len < 8 || $len > 32 ) {
			return '';
		}
		return $v;
	}

	/**
	 * Approved WooCommerce product reviews.
	 *
	 * @param array $settings Settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function review_events( $settings ) {
		$max        = max( 1, min( 12, (int) ( $settings['review_max'] ?? 4 ) ) );
		$min_rating = max( 1, min( 5, (int) ( $settings['review_min_rating'] ?? 4 ) ) );
		$show_img   = ! empty( $settings['show_image'] );
		$hide_name  = ! empty( $settings['hide_names'] );
		$fallback   = isset( $settings['fallback_name'] ) ? (string) $settings['fallback_name'] : 'Someone';
		$lookback   = max( 7, min( 365, (int) ( $settings['review_lookback'] ?? 90 ) ) );

		$real = array();
		if ( post_type_exists( 'product' ) ) {
			$comments = get_comments(
				array(
					'status'    => 'approve',
					'type'      => 'review',
					'post_type' => 'product',
					'number'    => 40,
					'orderby'   => 'comment_date_gmt',
					'order'     => 'DESC',
					'date_query' => array(
						array(
							'after'     => gmdate( 'Y-m-d H:i:s', time() - ( $lookback * DAY_IN_SECONDS ) ),
							'inclusive' => true,
						),
					),
				)
			);

			foreach ( (array) $comments as $comment ) {
				if ( count( $real ) >= $max ) {
					break;
				}
				$rating = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
				if ( $rating < $min_rating ) {
					continue;
				}

				$pid = (int) $comment->comment_post_ID;
				if ( $pid < 1 ) {
					continue;
				}

				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
				$title   = $product ? $product->get_name() : get_the_title( $pid );
				if ( '' === trim( (string) $title ) ) {
					continue;
				}

				$name = $hide_name ? $fallback : trim( (string) $comment->comment_author );
				if ( '' === $name ) {
					$name = $fallback;
				} else {
					$parts = preg_split( '/\s+/', $name );
					$name  = $parts ? $parts[0] : $name;
				}

				$url   = get_permalink( $pid );
				$image = '';
				if ( $show_img && $product ) {
					$image_id = $product->get_image_id();
					$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
				}

				$excerpt = '';
				if ( ! empty( $settings['review_excerpt'] ) ) {
					$excerpt = wp_strip_all_tags( (string) $comment->comment_content );
					$excerpt = html_entity_decode( $excerpt, ENT_QUOTES, 'UTF-8' );
					$excerpt = wp_trim_words( $excerpt, 14, '…' );
				}

				$created = strtotime( $comment->comment_date_gmt . ' GMT' );

				$real[] = array(
					'id'          => 'r' . $comment->comment_ID,
					'type'        => 'review',
					'productId'   => $pid,
					'name'        => $name,
					'city'        => '',
					'title'       => $title,
					'url'         => $url ? $url : '',
					'image'       => $image ? $image : '',
					'rating'      => $rating,
					'excerpt'     => $excerpt,
					'whenTs'      => $created > 0 ? $created : null,
					'when'        => '',
					'demo'        => false,
					'stock'       => '',
					'stockLabel'  => '',
				);
			}
		}

		$source = isset( $settings['source'] ) ? (string) $settings['source'] : 'real_then_demo';
		if ( 'real_orders' === $source ) {
			return $real;
		}
		if ( 'demo' === $source ) {
			return self::demo_review_events( $settings, $max );
		}

		$need = max( 0, $max - count( $real ) );
		if ( $need < 1 ) {
			return $real;
		}
		return array_merge( $real, self::demo_review_events( $settings, $need ) );
	}

	/**
	 * Demo reviews from people + catalog.
	 *
	 * @param array $settings Settings.
	 * @param int   $need     Count.
	 * @return array<int, array<string, mixed>>
	 */
	public static function demo_review_events( $settings, $need ) {
		$need = max( 0, (int) $need );
		if ( $need < 1 || ! class_exists( 'MW_Sales_Toast_Cache' ) ) {
			return array();
		}

		$people   = MW_Sales_Toast_Cache::parse_people( $settings['demo_people'] ?? '' );
		$products = MW_Sales_Toast_Cache::products( max( 4, $need ) );
		$show_img = ! empty( $settings['show_image'] );
		$hide     = ! empty( $settings['hide_names'] );
		$fallback = isset( $settings['fallback_name'] ) ? (string) $settings['fallback_name'] : 'Someone';
		$min_r    = max( 1, min( 5, (int) ( $settings['review_min_rating'] ?? 4 ) ) );

		if ( empty( $people ) || empty( $products ) ) {
			return array();
		}

		$quotes = array(
			__( 'Exactly what I needed.', 'mw-sales-toast' ),
			__( 'Great quality — will order again.', 'mw-sales-toast' ),
			__( 'Arrived quickly and looks even better in person.', 'mw-sales-toast' ),
			__( 'Happy with this purchase.', 'mw-sales-toast' ),
		);

		$out = array();
		for ( $i = 0; $i < $need; $i++ ) {
			$person  = $people[ $i % count( $people ) ];
			$product = $products[ $i % count( $products ) ];
			$pid     = isset( $product['id'] ) ? (int) $product['id'] : 0;
			$rating  = $min_r >= 5 ? 5 : max( $min_r, 5 - ( $i % 2 ) );
			$name    = $hide ? $fallback : $person['name'];
			$excerpt = '';
			if ( ! empty( $settings['review_excerpt'] ) ) {
				$excerpt = $quotes[ $i % count( $quotes ) ];
			}

			$out[] = array(
				'id'          => 'rd' . $i,
				'type'        => 'review',
				'productId'   => $pid > 0 ? $pid : null,
				'name'        => $name,
				'city'        => '',
				'title'       => $product['title'],
				'url'         => $product['url'],
				'image'       => $show_img ? $product['image'] : '',
				'rating'      => $rating,
				'excerpt'     => $excerpt,
				'when'        => __( 'recently', 'mw-sales-toast' ),
				'whenLiteral' => true,
				'demo'        => true,
				'stock'       => '',
				'stockLabel'  => '',
			);
		}

		return $out;
	}

	/**
	 * Single CTA / coupon toast.
	 *
	 * @param array $settings Settings.
	 * @return array<string, mixed>|null
	 */
	public static function cta_event( $settings ) {
		$message = trim( (string) ( $settings['cta_message'] ?? '' ) );
		$coupon  = strtoupper( trim( (string) ( $settings['cta_coupon'] ?? '' ) ) );
		$label   = trim( (string) ( $settings['cta_button'] ?? '' ) );
		$url     = trim( (string) ( $settings['cta_url'] ?? '' ) );

		if ( '' === $message && '' === $coupon ) {
			return null;
		}
		if ( '' === $message ) {
			$message = __( 'Use this code at checkout', 'mw-sales-toast' );
		}
		if ( '' === $label ) {
			$label = $coupon
				? __( 'Copy code', 'mw-sales-toast' )
				: __( 'Shop now', 'mw-sales-toast' );
		}

		return array(
			'id'          => 'cta',
			'type'        => 'cta',
			'productId'   => null,
			'name'        => '',
			'city'        => '',
			'title'       => $message,
			'url'         => $url,
			'image'       => '',
			'coupon'      => $coupon,
			'ctaLabel'    => $label,
			'ctaUrl'      => $url,
			'when'        => $coupon,
			'whenLiteral' => true,
			'demo'        => false,
			'stock'       => '',
			'stockLabel'  => '',
		);
	}

	/**
	 * Drop review cache when a product review changes.
	 *
	 * @param mixed $comment Comment ID or unused.
	 * @param mixed $_a      Unused.
	 * @param mixed $_b      Unused.
	 */
	public static function maybe_invalidate_review( $comment = null, $_a = null, $_b = null ) {
		$is_review = false;
		if ( is_object( $comment ) && isset( $comment->comment_type ) ) {
			$is_review = ( 'review' === $comment->comment_type );
		} elseif ( is_numeric( $comment ) ) {
			$obj = get_comment( (int) $comment );
			$is_review = $obj && 'review' === $obj->comment_type;
		} else {
			$is_review = true;
		}
		if ( ! $is_review || ! class_exists( 'MW_Sales_Toast_Cache' ) ) {
			return;
		}
		MW_Sales_Toast_Cache::invalidate();
	}
}
