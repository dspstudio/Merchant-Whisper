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
		if ( is_string( self::$provider ) && '' !== self::$provider ) {
			return self::$provider;
		}

		$pll  = 0;
		$wpml = 0;
		$trp  = 0;
		if ( function_exists( 'pll_languages_list' ) ) {
			$pll_slugs = pll_languages_list( array( 'fields' => 'slug' ) );
			$pll       = is_array( $pll_slugs ) ? count( $pll_slugs ) : 0;
		}
		if ( self::wpml_is_active() ) {
			$wpml = count( self::wpml_language_rows() );
		}
		if ( self::trp_is_active() ) {
			$trp = count( self::trp_language_rows() );
		}

		if ( $pll >= 2 ) {
			self::$provider = 'polylang';
		} elseif ( $wpml >= 2 ) {
			self::$provider = 'wpml';
		} elseif ( $trp >= 2 ) {
			self::$provider = 'translatepress';
		} elseif ( function_exists( 'pll_current_language' ) || function_exists( 'pll_languages_list' ) ) {
			self::$provider = 'polylang';
		} elseif ( $wpml > 0 || self::wpml_is_active() ) {
			self::$provider = 'wpml';
		} elseif ( $trp > 0 || self::trp_is_active() ) {
			self::$provider = 'translatepress';
		} else {
			self::$provider = '';
		}

		return self::$provider;
	}

	/**
	 * Whether WPML core is available.
	 *
	 * @return bool
	 */
	private static function wpml_is_active() {
		return defined( 'ICL_SITEPRESS_VERSION' )
			|| class_exists( 'SitePress', false )
			|| function_exists( 'icl_get_languages' )
			|| has_filter( 'wpml_active_languages' )
			|| has_filter( 'wpml_current_language' );
	}

	/**
	 * Whether TranslatePress is available.
	 *
	 * @return bool
	 */
	private static function trp_is_active() {
		return class_exists( 'TRP_Translate_Press', false )
			|| class_exists( 'TRP_Settings', false )
			|| function_exists( 'trp_get_languages' );
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
		add_action( 'wp_footer', array( __CLASS__, 'print_trp_editor_strings' ), 5 );
	}

	/**
	 * Register toast copy so it appears under a filterable group.
	 */
	public static function register_strings() {
		if ( ! class_exists( 'MW_Sales_Toast_Settings' ) ) {
			return;
		}

		$provider = self::provider();
		if ( 'polylang' !== $provider && 'wpml' !== $provider && 'translatepress' !== $provider ) {
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

		if ( 'translatepress' === $provider ) {
			self::register_trp_strings( $settings );
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

		if ( 'translatepress' === $provider && function_exists( 'trp_translate' ) ) {
			$locale = self::locale_for_lang( $lang );
			if ( '' === $locale ) {
				return $string;
			}
			$translated = trp_translate( $string, $locale, false );
			$translated = self::strip_trp_wrappers( is_string( $translated ) ? $translated : $string );
			return '' !== $translated ? $translated : $string;
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
			foreach ( self::wpml_language_rows() as $row ) {
				$list[] = $row;
			}
		} elseif ( 'translatepress' === $provider ) {
			foreach ( self::trp_language_rows() as $row ) {
				$list[] = $row;
			}
		}

		return $list;
	}

	/**
	 * Active WPML languages (admin-safe; skip_missing=0).
	 *
	 * @return array<int, array{slug:string,name:string,locale:string}>
	 */
	private static function wpml_language_rows() {
		$raw = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			$raw = apply_filters( 'wpml_active_languages', null, 'skip_missing=0' );
		}
		if ( ( ! is_array( $raw ) || empty( $raw ) ) && function_exists( 'icl_get_languages' ) ) {
			$raw = icl_get_languages( 'skip_missing=0' );
		}
		if ( ( ! is_array( $raw ) || empty( $raw ) ) && isset( $GLOBALS['sitepress'] ) && is_object( $GLOBALS['sitepress'] ) && method_exists( $GLOBALS['sitepress'], 'get_active_languages' ) ) {
			$raw = $GLOBALS['sitepress']->get_active_languages();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$list = array();
		foreach ( $raw as $code => $row ) {
			$slug = '';
			$name = '';
			$loc  = '';
			if ( is_array( $row ) ) {
				$slug = isset( $row['code'] ) ? (string) $row['code'] : (string) $code;
				if ( isset( $row['language_code'] ) && '' === $slug ) {
					$slug = (string) $row['language_code'];
				}
				$name = ! empty( $row['native_name'] )
					? (string) $row['native_name']
					: ( ! empty( $row['translated_name'] ) ? (string) $row['translated_name'] : ( ! empty( $row['display_name'] ) ? (string) $row['display_name'] : $slug ) );
				$loc = ! empty( $row['default_locale'] )
					? (string) $row['default_locale']
					: ( ! empty( $row['locale'] ) ? (string) $row['locale'] : '' );
			} else {
				$slug = is_string( $code ) ? $code : (string) $row;
			}
			$slug = sanitize_key( $slug );
			if ( '' === $slug ) {
				continue;
			}
			$list[] = array(
				'slug'   => $slug,
				'name'   => $name ? $name : $slug,
				'locale' => $loc,
			);
		}

		return $list;
	}

	/**
	 * TranslatePress languages (published + translation list).
	 *
	 * @return array<int, array{slug:string,name:string,locale:string}>
	 */
	private static function trp_language_rows() {
		$settings = get_option( 'trp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$codes = array();
		foreach ( array( 'publish-languages', 'translation-languages' ) as $key ) {
			if ( empty( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
				continue;
			}
			foreach ( $settings[ $key ] as $code ) {
				$codes[] = (string) $code;
			}
		}
		if ( ! empty( $settings['default-language'] ) ) {
			$codes[] = (string) $settings['default-language'];
		}
		$codes = array_values( array_unique( array_filter( $codes ) ) );
		if ( empty( $codes ) ) {
			return array();
		}

		$all_names = isset( $settings['language-names'] ) && is_array( $settings['language-names'] )
			? $settings['language-names']
			: array();

		$list = array();
		$seen = array();
		foreach ( $codes as $code ) {
			$slug = self::normalize_trp_slug( $code );
			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;
			$name          = isset( $all_names[ $code ] ) ? (string) $all_names[ $code ] : $slug;
			$list[]        = array(
				'slug'   => $slug,
				'name'   => $name,
				'locale' => str_replace( '-', '_', (string) $code ),
			);
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
			foreach ( self::wpml_language_rows() as $row ) {
				if ( $row['slug'] !== $slug ) {
					continue;
				}
				if ( ! empty( $row['locale'] ) ) {
					return str_replace( '-', '_', (string) $row['locale'] );
				}
			}
		} elseif ( 'translatepress' === $provider ) {
			foreach ( self::trp_language_rows() as $row ) {
				if ( $row['slug'] === $slug && ! empty( $row['locale'] ) ) {
					return str_replace( '-', '_', (string) $row['locale'] );
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
			$from_uri = self::trp_slug_from_path( $uri );
			if ( '' !== $from_uri ) {
				return $from_uri;
			}
			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
			if ( $ref ) {
				$path = (string) wp_parse_url( $ref, PHP_URL_PATH );
				$from_ref = self::trp_slug_from_path( $path );
				if ( '' !== $from_ref ) {
					return $from_ref;
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
	 * Insert toast source strings into TranslatePress so they can be translated.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private static function register_trp_strings( $settings ) {
		if ( ! class_exists( 'TRP_Translate_Press', false ) || ! method_exists( 'TRP_Translate_Press', 'get_trp_instance' ) ) {
			return;
		}
		$strings = self::source_toast_strings( $settings );
		if ( empty( $strings ) ) {
			return;
		}

		$hash = md5( '2|' . wp_json_encode( $strings ) );
		if ( get_option( 'mw_st_trp_strings_hash' ) === $hash ) {
			return;
		}

		$trp = TRP_Translate_Press::get_trp_instance();
		if ( ! is_object( $trp ) || ! method_exists( $trp, 'get_component' ) ) {
			return;
		}
		$query = $trp->get_component( 'query' );
		if ( ! is_object( $query ) || ! method_exists( $query, 'insert_strings' ) ) {
			return;
		}

		$default = self::default_lang();
		foreach ( self::trp_language_rows() as $row ) {
			if ( $default && $row['slug'] === $default ) {
				continue;
			}
			$locale = ! empty( $row['locale'] ) ? (string) $row['locale'] : '';
			if ( '' === $locale ) {
				continue;
			}
			$query->insert_strings( $strings, $locale );
		}

		update_option( 'mw_st_trp_strings_hash', $hash, false );
	}

	/**
	 * Print toast copy in the TranslatePress visual editor so it appears in the string list.
	 */
	public static function print_trp_editor_strings() {
		if ( 'translatepress' !== self::provider() ) {
			return;
		}
		if ( empty( $_GET['trp-edit-translation'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'MW_Sales_Toast_Settings' ) ) {
			return;
		}

		$strings = self::source_toast_strings( MW_Sales_Toast_Settings::get() );
		if ( empty( $strings ) ) {
			return;
		}

		echo '<div id="mwst-trp-string-source" class="mwst-trp-string-source" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">';
		echo '<p>' . esc_html__( 'Merchant Whisper toast copy', 'mw-sales-toast' ) . '</p>';
		foreach ( $strings as $string ) {
			echo '<p>' . esc_html( $string ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Accept a shop language slug only if the active provider lists it.
	 *
	 * @param string $lang Raw slug.
	 * @return string Sanitized slug or empty.
	 */
	public static function known_lang( $lang ) {
		$lang = sanitize_key( (string) $lang );
		if ( '' === $lang ) {
			return '';
		}
		foreach ( self::languages() as $row ) {
			if ( isset( $row['slug'] ) && $row['slug'] === $lang ) {
				return $lang;
			}
		}
		return '';
	}

	/**
	 * Language slug from a URL path (TranslatePress directory prefixes / url-slugs).
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	private static function trp_slug_from_path( $path ) {
		$path = '/' . trim( str_replace( '\\', '/', (string) $path ), '/' ) . '/';
		if ( '//' === $path ) {
			return '';
		}

		$candidates = array();
		$settings   = get_option( 'trp_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['url-slugs'] ) && is_array( $settings['url-slugs'] ) ) {
			foreach ( $settings['url-slugs'] as $code => $url_slug ) {
				$url_slug = trim( (string) $url_slug, '/' );
				if ( '' === $url_slug ) {
					continue;
				}
				$candidates[ $url_slug ] = self::normalize_trp_slug( (string) $code );
			}
		}
		foreach ( self::trp_language_rows() as $row ) {
			if ( ! empty( $row['slug'] ) ) {
				$candidates[ $row['slug'] ] = $row['slug'];
			}
		}

		foreach ( $candidates as $url_slug => $norm ) {
			if ( '' === $norm ) {
				continue;
			}
			if ( preg_match( '#/' . preg_quote( (string) $url_slug, '#' ) . '/#', $path ) ) {
				return $norm;
			}
		}

		return '';
	}

	/**
	 * Non-empty source toast strings for registration / editor.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<int, string>
	 */
	private static function source_toast_strings( $settings ) {
		$out = array();
		foreach ( MW_Sales_Toast_Settings::string_i18n_keys() as $key ) {
			$string = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
			if ( '' === $string ) {
				continue;
			}
			$out[] = $string;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Remove TranslatePress wrapper markup from a translated string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function strip_trp_wrappers( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/<\/?trp-postprocess[^>]*>/i', '', $text );
		$text = preg_replace( '/<\/?span[^>]*data-trp[^>]*>/i', '', $text );
		return is_string( $text ) ? $text : '';
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
