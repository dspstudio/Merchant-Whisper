<?php
/**
 * Multilingual facade: Polylang, WPML, TranslatePress.
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detect one language plugin and expose a small API.
 */
class MW_Sales_Toast_Language {

	/**
	 * Active provider slug.
	 *
	 * @var string|null
	 */
	private static $provider = null;

	/**
	 * Which multilingual plugin is active (first wins).
	 *
	 * @return string polylang|wpml|translatepress|''
	 */
	public static function provider() {
		if ( null !== self::$provider ) {
			return self::$provider;
		}

		if ( function_exists( 'pll_current_language' ) || function_exists( 'pll_languages_list' ) ) {
			self::$provider = 'polylang';
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_current_language' ) ) {
			self::$provider = 'wpml';
		} elseif ( class_exists( 'TRP_Translate_Press', false ) || function_exists( 'trp_get_languages' ) ) {
			self::$provider = 'translatepress';
		} else {
			self::$provider = '';
		}

		return self::$provider;
	}

	/**
	 * Human-readable plugin name for admin UI.
	 *
	 * @return string Empty when none.
	 */
	public static function provider_label() {
		$labels = array(
			'polylang'       => 'Polylang',
			'wpml'           => 'WPML',
			'translatepress' => 'TranslatePress',
		);
		$slug = self::provider();
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : '';
	}

	/**
	 * String Translation group name (Polylang / WPML filter).
	 *
	 * @return string
	 */
	public static function string_group() {
		return defined( 'MW_SALES_TOAST_NAME' ) ? MW_SALES_TOAST_NAME : 'Merchant Whisper';
	}

	/**
	 * Hook string registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_strings' ), 20 );
	}

	/**
	 * Register toast copy so it appears under a filterable group.
	 */
	public static function register_strings() {
		if ( ! class_exists( 'MW_Sales_Toast_Settings' ) ) {
			return;
		}

		$provider = self::provider();
		if ( 'polylang' !== $provider && 'wpml' !== $provider ) {
			return;
		}

		$settings = MW_Sales_Toast_Settings::get();
		$group    = self::string_group();
		$multi    = array( 'demo_people', 'demo_whens' );
		$names    = array(
			'message_template' => 'Purchase template',
			'viewing_template' => 'Viewing template',
			'review_template'  => 'Review template',
			'fallback_name'    => 'Fallback name',
			'cta_message'      => 'CTA message',
			'cta_button'       => 'CTA button',
			'demo_people'      => 'Demo people',
			'demo_whens'       => 'Demo times',
		);

		foreach ( MW_Sales_Toast_Settings::string_i18n_keys() as $key ) {
			$string = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
			if ( '' === trim( $string ) ) {
				continue;
			}
			$name = isset( $names[ $key ] ) ? $names[ $key ] : $key;

			if ( 'polylang' === $provider && function_exists( 'pll_register_string' ) ) {
				pll_register_string( $name, $string, $group, in_array( $key, $multi, true ) );
			}

			if ( 'wpml' === $provider ) {
				do_action( 'wpml_register_single_string', $group, $key, $string );
			}
		}
	}

	/**
	 * Translate a registered source string for a language.
	 *
	 * @param string $string Source (default-language) text.
	 * @param string $lang   Target slug.
	 * @param string $key    Setting key (WPML name).
	 * @return string Original if no translation.
	 */
	public static function translate_string( $string, $lang, $key = '' ) {
		$string = (string) $string;
		if ( '' === $string ) {
			return $string;
		}

		$provider = self::provider();
		$lang     = sanitize_key( (string) $lang );

		if ( 'polylang' === $provider && function_exists( 'pll_translate_string' ) ) {
			$translated = pll_translate_string( $string, $lang ? $lang : null );
			return is_string( $translated ) && '' !== $translated ? $translated : $string;
		}

		if ( 'wpml' === $provider && $key ) {
			$translated = apply_filters( 'wpml_translate_single_string', $string, self::string_group(), $key, $lang ? $lang : null );
			return is_string( $translated ) && '' !== $translated ? $translated : $string;
		}

		return $string;
	}

	/**
	 * Whether a multilingual provider with 2+ languages is available.
	 *
	 * @return bool
	 */
	public static function is_multilingual() {
		return count( self::languages() ) >= 2;
	}

	/**
	 * Configured languages.
	 *
	 * @return array<int, array{slug:string,name:string}>
	 */
	public static function languages() {
		$provider = self::provider();
		$list     = array();

		if ( 'polylang' === $provider && function_exists( 'pll_languages_list' ) ) {
			$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
			$names = function_exists( 'pll_languages_list' )
				? pll_languages_list( array( 'fields' => 'name' ) )
				: array();
			if ( is_array( $slugs ) ) {
				foreach ( array_values( $slugs ) as $i => $slug ) {
					$slug = sanitize_key( (string) $slug );
					if ( '' === $slug ) {
						continue;
					}
					$name = isset( $names[ $i ] ) ? (string) $names[ $i ] : $slug;
					$list[] = array(
						'slug' => $slug,
						'name' => $name,
					);
				}
			}
		} elseif ( 'wpml' === $provider ) {
			$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $langs ) ) {
				foreach ( $langs as $code => $row ) {
					$slug = sanitize_key( is_array( $row ) && isset( $row['code'] ) ? (string) $row['code'] : (string) $code );
					if ( '' === $slug ) {
						continue;
					}
					$name = is_array( $row ) && ! empty( $row['native_name'] )
						? (string) $row['native_name']
						: ( is_array( $row ) && ! empty( $row['translated_name'] ) ? (string) $row['translated_name'] : $slug );
					$list[] = array(
						'slug' => $slug,
						'name' => $name,
					);
				}
			}
		} elseif ( 'translatepress' === $provider ) {
			$settings = get_option( 'trp_settings', array() );
			if ( is_array( $settings ) && ! empty( $settings['publish-languages'] ) && is_array( $settings['publish-languages'] ) ) {
				$all_names = isset( $settings['language-names'] ) && is_array( $settings['language-names'] )
					? $settings['language-names']
					: array();
				foreach ( $settings['publish-languages'] as $code ) {
					$slug = self::normalize_trp_slug( (string) $code );
					if ( '' === $slug ) {
						continue;
					}
					$name = isset( $all_names[ $code ] ) ? (string) $all_names[ $code ] : $slug;
					$list[] = array(
						'slug' => $slug,
						'name' => $name,
					);
				}
			}
		}

		return $list;
	}

	/**
	 * WordPress locale for a shop language slug (for loading plugin translations).
	 *
	 * @param string $slug Language slug (e.g. en, ro, de).
	 * @return string Locale like en_US, or empty.
	 */
	public static function locale_for_lang( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return '';
		}

		$provider = self::provider();

		if ( 'polylang' === $provider && function_exists( 'pll_languages_list' ) ) {
			$slugs   = pll_languages_list( array( 'fields' => 'slug' ) );
			$locales = pll_languages_list( array( 'fields' => 'locale' ) );
			if ( is_array( $slugs ) && is_array( $locales ) ) {
				foreach ( array_values( $slugs ) as $i => $row_slug ) {
					if ( sanitize_key( (string) $row_slug ) === $slug && ! empty( $locales[ $i ] ) ) {
						return str_replace( '-', '_', (string) $locales[ $i ] );
					}
				}
			}
		} elseif ( 'wpml' === $provider ) {
			$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $langs ) ) {
				foreach ( $langs as $code => $row ) {
					$row_slug = sanitize_key( is_array( $row ) && isset( $row['code'] ) ? (string) $row['code'] : (string) $code );
					if ( $row_slug !== $slug || ! is_array( $row ) ) {
						continue;
					}
					if ( ! empty( $row['default_locale'] ) ) {
						return str_replace( '-', '_', (string) $row['default_locale'] );
					}
					if ( ! empty( $row['locale'] ) ) {
						return str_replace( '-', '_', (string) $row['locale'] );
					}
				}
			}
		} elseif ( 'translatepress' === $provider ) {
			$settings = get_option( 'trp_settings', array() );
			if ( is_array( $settings ) && ! empty( $settings['publish-languages'] ) && is_array( $settings['publish-languages'] ) ) {
				foreach ( $settings['publish-languages'] as $code ) {
					if ( self::normalize_trp_slug( (string) $code ) === $slug ) {
						return str_replace( '-', '_', (string) $code );
					}
				}
			}
		}

		$short = strtolower( substr( $slug, 0, 2 ) );
		$map   = array(
			'en' => 'en_US',
			'ro' => 'ro_RO',
			'de' => 'de_DE',
		);
		if ( isset( $map[ $short ] ) ) {
			return $map[ $short ];
		}
		if ( strlen( $slug ) > 2 ) {
			return str_replace( '-', '_', $slug );
		}
		return '';
	}

	/**
	 * Default / site language slug.
	 *
	 * @return string
	 */
	public static function default_lang() {
		$provider = self::provider();

		if ( 'polylang' === $provider && function_exists( 'pll_default_language' ) ) {
			$slug = pll_default_language( 'slug' );
			return is_string( $slug ) ? sanitize_key( $slug ) : '';
		}

		if ( 'wpml' === $provider ) {
			$slug = apply_filters( 'wpml_default_language', null );
			return is_string( $slug ) ? sanitize_key( $slug ) : '';
		}

		if ( 'translatepress' === $provider ) {
			$settings = get_option( 'trp_settings', array() );
			if ( is_array( $settings ) && ! empty( $settings['default-language'] ) ) {
				return self::normalize_trp_slug( (string) $settings['default-language'] );
			}
		}

		$langs = self::languages();
		if ( ! empty( $langs[0]['slug'] ) ) {
			return (string) $langs[0]['slug'];
		}

		return '';
	}

	/**
	 * Current request language slug (front / REST / admin preview).
	 *
	 * @return string Empty when unknown or not multilingual.
	 */
	public static function current_lang() {
		$provider = self::provider();
		if ( '' === $provider ) {
			return '';
		}

		if ( 'polylang' === $provider && function_exists( 'pll_current_language' ) ) {
			$slug = pll_current_language( 'slug' );
			return is_string( $slug ) ? sanitize_key( $slug ) : '';
		}

		if ( 'wpml' === $provider ) {
			$slug = apply_filters( 'wpml_current_language', null );
			return is_string( $slug ) ? sanitize_key( $slug ) : '';
		}

		if ( 'translatepress' === $provider ) {
			global $TRP_LANGUAGE;
			if ( is_string( $TRP_LANGUAGE ) && '' !== $TRP_LANGUAGE ) {
				return self::normalize_trp_slug( $TRP_LANGUAGE );
			}
			if ( ! empty( $_GET['trp-edit-translation'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return '';
			}
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			foreach ( self::languages() as $lang ) {
				$slug = $lang['slug'];
				if ( '' !== $slug && preg_match( '#/(?:' . preg_quote( $slug, '#' ) . ')(/|$)#', $uri ) ) {
					return $slug;
				}
			}
			return self::default_lang();
		}

		return '';
	}

	/**
	 * Map a post ID to another language (Polylang / WPML). TranslatePress: no-op.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Target language slug (empty = current).
	 * @return int
	 */
	public static function translate_post( $post_id, $lang = '' ) {
		$post_id = absint( $post_id );
		if ( $post_id < 1 ) {
			return 0;
		}

		$lang = is_string( $lang ) && '' !== $lang ? sanitize_key( $lang ) : self::current_lang();
		$provider = self::provider();

		if ( 'polylang' === $provider && function_exists( 'pll_get_post' ) ) {
			$mapped = $lang ? pll_get_post( $post_id, $lang ) : pll_get_post( $post_id );
			$mapped = absint( $mapped );
			return $mapped > 0 ? $mapped : $post_id;
		}

		if ( 'wpml' === $provider ) {
			$type = get_post_type( $post_id );
			if ( ! $type ) {
				$type = 'product';
			}
			$mapped = apply_filters( 'wpml_object_id', $post_id, $type, true, $lang ? $lang : null );
			$mapped = absint( $mapped );
			return $mapped > 0 ? $mapped : $post_id;
		}

		return $post_id;
	}

	/**
	 * Map a term ID to another language (Polylang / WPML). TranslatePress: no-op.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $lang    Target language slug (empty = current).
	 * @param string $taxonomy Taxonomy (default product_cat).
	 * @return int
	 */
	public static function translate_term( $term_id, $lang = '', $taxonomy = 'product_cat' ) {
		$term_id = absint( $term_id );
		if ( $term_id < 1 ) {
			return 0;
		}

		$lang     = is_string( $lang ) && '' !== $lang ? sanitize_key( $lang ) : self::current_lang();
		$provider = self::provider();
		$taxonomy = $taxonomy ? (string) $taxonomy : 'product_cat';

		if ( 'polylang' === $provider && function_exists( 'pll_get_term' ) ) {
			$mapped = $lang ? pll_get_term( $term_id, $lang ) : pll_get_term( $term_id );
			$mapped = absint( $mapped );
			return $mapped > 0 ? $mapped : $term_id;
		}

		if ( 'wpml' === $provider ) {
			$mapped = apply_filters( 'wpml_object_id', $term_id, $taxonomy, true, $lang ? $lang : null );
			$mapped = absint( $mapped );
			return $mapped > 0 ? $mapped : $term_id;
		}

		return $term_id;
	}

	/**
	 * Expand post IDs to include siblings in all languages (for catalog filters).
	 *
	 * @param array<int, int> $ids Post IDs.
	 * @return array<int, int>
	 */
	public static function expand_post_ids( $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		if ( empty( $ids ) || ! self::is_multilingual() ) {
			return $ids;
		}
		if ( in_array( self::provider(), array( 'translatepress', '' ), true ) ) {
			return $ids;
		}

		$out = $ids;
		foreach ( $ids as $id ) {
			foreach ( self::languages() as $lang ) {
				$mapped = self::translate_post( $id, $lang['slug'] );
				if ( $mapped > 0 ) {
					$out[] = $mapped;
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $out ) ) ) );
	}

	/**
	 * Expand term IDs to include siblings in all languages.
	 *
	 * @param array<int, int> $ids Term IDs.
	 * @param string          $taxonomy Taxonomy.
	 * @return array<int, int>
	 */
	public static function expand_term_ids( $ids, $taxonomy = 'product_cat' ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		if ( empty( $ids ) || ! self::is_multilingual() ) {
			return $ids;
		}
		if ( in_array( self::provider(), array( 'translatepress', '' ), true ) ) {
			return $ids;
		}

		$out = $ids;
		foreach ( $ids as $id ) {
			foreach ( self::languages() as $lang ) {
				$mapped = self::translate_term( $id, $lang['slug'], $taxonomy );
				if ( $mapped > 0 ) {
					$out[] = $mapped;
				}
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $out ) ) ) );
	}

	/**
	 * IDs that match the current product page across languages.
	 *
	 * @param int $product_id Current product ID.
	 * @return array<int, int>
	 */
	public static function product_match_ids( $product_id ) {
		$product_id = absint( $product_id );
		if ( $product_id < 1 ) {
			return array();
		}
		return self::expand_post_ids( array( $product_id ) );
	}

	/**
	 * Normalize TranslatePress locale codes to short slugs (fr_FR → fr when possible).
	 *
	 * @param string $code Locale or slug.
	 * @return string
	 */
	private static function normalize_trp_slug( $code ) {
		$code = str_replace( '_', '-', strtolower( trim( (string) $code ) ) );
		if ( preg_match( '/^([a-z]{2})(?:-|$)/', $code, $m ) ) {
			return sanitize_key( $m[1] );
		}
		return sanitize_key( $code );
	}
}
