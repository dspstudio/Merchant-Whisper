<?php
/**
 * Settings API and admin page.
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin settings.
 */
class MW_Sales_Toast_Settings {

	/**
	 * Hook admin.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_plugins_list' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( MW_SALES_TOAST_FILE ),
			array( __CLASS__, 'plugin_action_links' )
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'                => 1,
			'position'               => 'bottom-left',
			'offset_x'               => 20,
			'offset_y'               => 20,
			'source'                 => 'real_then_demo',
			'timing_preset'          => 'balanced',
			'delay'                  => 6,
			'duration'               => 7,
			'gap'                    => 12,
			'jitter'                 => 20,
			'max_events'             => 8,
			'max_cached_orders'      => 40,
			'cache_minutes'          => 60,
			'cron_minutes'           => 60,
			'lookback_days'          => 30,
			'show_image'             => 1,
			'show_on'                => 'all',
			'exclude_home'           => 0,
			'hide_cart_checkout'     => 1,
			'hide_thankyou'          => 1,
			'hide_account'           => 0,
			'guests_only'            => 0,
			'respect_reduced_motion' => 1,
			'require_consent'        => 1,
			'hide_names'             => 0,
			'fallback_name'          => 'Someone',
			'message_template'       => '{name} from {city} just bought {product}',
			'max_per_session'        => 12,
			'mute_hours'             => 24,
			'disable_mobile'         => 0,
			'mobile_breakpoint'      => 768,
			'sound_enabled'          => 0,
			'newsletter'             => 0,
			'when_style'             => 'natural',
			'event_delivery'         => 'rest',
			'stock_display'          => 'soft',
			'stock_threshold'        => 5,
			// Pro targeting (Where toasts appear).
			'url_include'            => '',
			'url_exclude'            => '',
			'include_products'       => array(),
			'exclude_products'       => array(),
			'include_categories'     => array(),
			'exclude_categories'     => array(),
			'match_product_page'     => 0,
			'hide_roles'             => array(),
			'demo_people'            => "Ana, Bucharest\nMarco, Milan\nSofia, Lisbon\nJonas, Berlin\nLéa, Paris\nNoah, Amsterdam\nElena, Madrid\nOmar, Cairo",
			'demo_whens'             => "just now\na few minutes ago\na couple of hours ago\nearlier today\nyesterday\nrecently",
			'style_bg'               => '#0c1220',
			'style_bg_opacity'       => 92,
			'style_text'             => '#e8eef8',
			'style_body'             => '#d7deea',
			'style_accent'           => '#e8c872',
			'style_meta'             => '#a8b2c4',
			'style_close_hover'      => '#dc2626',
			'style_border'           => '#ffffff',
			'style_border_opacity'   => 10,
			'style_radius'           => 14,
			'style_padding'          => 12,
			'style_max_width'        => 360,
			'style_shadow'           => 'medium',
			'style_image_fit'        => 'full',
			'design_preset'          => 'midnight',
			'use_elementor_theme'    => 0,
			'custom_css'             => '',
		);
	}

	/**
	 * Whether Elementor is available for Site Kit theming.
	 *
	 * @return bool
	 */
	public static function is_elementor_active() {
		return class_exists( '\Elementor\Plugin' ) || did_action( 'elementor/loaded' );
	}

	/**
	 * Sanitize a CSS font-family name from Elementor kit.
	 *
	 * @param mixed $font Raw.
	 * @return string
	 */
	private static function sanitize_font_family( $font ) {
		$font = is_string( $font ) ? $font : '';
		$font = wp_strip_all_tags( $font );
		$font = preg_replace( '/[^a-zA-Z0-9\s\-_]/', '', $font );
		$font = trim( preg_replace( '/\s+/', ' ', $font ) );
		if ( strlen( $font ) > 80 ) {
			$font = substr( $font, 0, 80 );
		}
		return $font;
	}

	/**
	 * Read Elementor active kit page settings (colors + typography).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function elementor_kit_page_settings() {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->kits_manager ) ) {
			$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
			if ( $kit && method_exists( $kit, 'get_settings_for_display' ) ) {
				$colors = $kit->get_settings_for_display( 'system_colors' );
				$typo   = $kit->get_settings_for_display( 'system_typography' );
				if ( is_array( $colors ) || is_array( $typo ) ) {
					return array(
						'system_colors'      => is_array( $colors ) ? $colors : array(),
						'system_typography'  => is_array( $typo ) ? $typo : array(),
					);
				}
			}
		}

		$kit_id = absint( get_option( 'elementor_active_kit' ) );
		if ( ! $kit_id ) {
			return null;
		}

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		return is_array( $settings ) ? $settings : null;
	}

	/**
	 * Map Elementor Site Kit globals → toast design tokens.
	 *
	 * @return array{style_bg:string,style_text:string,style_body:string,style_accent:string,style_meta:string,style_border:string,font:string}|null
	 */
	public static function get_elementor_theme() {
		if ( ! self::is_elementor_active() ) {
			return null;
		}

		$settings = self::elementor_kit_page_settings();
		if ( ! is_array( $settings ) ) {
			return null;
		}

		$by_id = array();
		if ( ! empty( $settings['system_colors'] ) && is_array( $settings['system_colors'] ) ) {
			foreach ( $settings['system_colors'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['_id'] ) ) {
					continue;
				}
				$raw = is_string( $row['color'] ?? null ) ? $row['color'] : '';
				$hex = sanitize_hex_color( $raw );
				if ( ! $hex ) {
					$parsed = self::parse_rgb_color( $raw );
					if ( $parsed ) {
						$hex = sprintf( '#%02x%02x%02x', $parsed['r'], $parsed['g'], $parsed['b'] );
					}
				}
				if ( ! $hex ) {
					continue;
				}
				$by_id[ (string) $row['_id'] ] = $hex;
			}
		}

		$font = '';
		if ( ! empty( $settings['system_typography'] ) && is_array( $settings['system_typography'] ) ) {
			foreach ( $settings['system_typography'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['_id'] ) || 'text' !== (string) $row['_id'] ) {
					continue;
				}
				$font = self::sanitize_font_family( $row['typography_font_family'] ?? '' );
				break;
			}
			if ( '' === $font ) {
				foreach ( $settings['system_typography'] as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$font = self::sanitize_font_family( $row['typography_font_family'] ?? '' );
					if ( '' !== $font ) {
						break;
					}
				}
			}
		}

		$primary   = $by_id['primary'] ?? '';
		$secondary = $by_id['secondary'] ?? '';
		$text      = $by_id['text'] ?? '';
		$accent    = $by_id['accent'] ?? '';

		if ( '' === $primary && '' === $secondary && '' === $text && '' === $accent && '' === $font ) {
			return null;
		}

		$defaults = self::defaults();

		return array(
			'style_bg'     => $secondary ? $secondary : $defaults['style_bg'],
			'style_text'   => $primary ? $primary : ( $text ? $text : $defaults['style_text'] ),
			'style_body'   => $text ? $text : ( $secondary ? $secondary : $defaults['style_body'] ),
			'style_accent' => $accent ? $accent : ( $primary ? $primary : $defaults['style_accent'] ),
			'style_meta'   => $secondary ? $secondary : ( $text ? $text : $defaults['style_meta'] ),
			'style_border' => $text ? $text : ( $primary ? $primary : $defaults['style_border'] ),
			'font'         => $font,
		);
	}

	/**
	 * Whether Pro features are active.
	 *
	 * @return bool
	 */
	public static function is_pro() {
		/**
		 * Whether the Pro license is active.
		 *
		 * @param bool $is_pro Pro active.
		 */
		return (bool) apply_filters( 'mw_sales_toast_is_pro', (bool) MW_SALES_TOAST_IS_PRO );
	}

	/**
	 * Normalize a list of IDs or role slugs from mixed saved shapes.
	 *
	 * @param mixed $value   Raw.
	 * @param bool  $as_slug True for role slugs.
	 * @return array<int, int|string>
	 */
	public static function normalize_id_list( $value, $as_slug = false ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value ) ?: array();
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( $as_slug ) {
				$slug = sanitize_key( (string) $item );
				if ( '' !== $slug ) {
					$out[] = $slug;
				}
				continue;
			}
			$id = absint( $item );
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize numeric ID list from form input.
	 *
	 * @param mixed $value Raw.
	 * @return array<int, int>
	 */
	private static function sanitize_id_list( $value ) {
		return array_map( 'absint', self::normalize_id_list( $value, false ) );
	}

	/**
	 * Sanitize role slug list.
	 *
	 * @param mixed $value Raw.
	 * @return array<int, string>
	 */
	private static function sanitize_role_list( $value ) {
		$roles = wp_roles();
		$valid = $roles ? array_keys( $roles->roles ) : array();
		$out   = array();
		foreach ( self::normalize_id_list( $value, true ) as $slug ) {
			if ( in_array( $slug, $valid, true ) ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize URL path patterns (one per line).
	 *
	 * @param mixed $value Raw textarea.
	 * @return string
	 */
	private static function sanitize_path_list( $value ) {
		$value = sanitize_textarea_field( (string) $value );
		$lines = preg_split( '/\r\n|\r|\n/', $value ) ?: array();
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// Keep path-like patterns only (no scheme/host).
			$line = preg_replace( '#^https?://[^/]+#i', '', $line );
			$line = '/' . ltrim( (string) $line, '/' );
			$out[] = $line;
		}
		return implode( "\n", array_slice( array_unique( $out ), 0, 100 ) );
	}

	/**
	 * Sanitize a hex color for the picker.
	 *
	 * @param mixed  $value   Raw.
	 * @param string $default Fallback hex.
	 * @return string
	 */
	private static function sanitize_hex( $value, $default ) {
		$hex = sanitize_hex_color( is_string( $value ) ? $value : '' );
		if ( $hex ) {
			return $hex;
		}

		// Legacy rgba()/rgb() → hex for the picker.
		$parsed = self::parse_rgb_color( $value );
		if ( $parsed ) {
			return sprintf( '#%02x%02x%02x', $parsed['r'], $parsed['g'], $parsed['b'] );
		}

		$fallback = sanitize_hex_color( $default );
		return $fallback ? $fallback : '#000000';
	}

	/**
	 * Parse rgb/rgba string.
	 *
	 * @param mixed $value Raw color.
	 * @return array{r:int,g:int,b:int,a:float}|null
	 */
	private static function parse_rgb_color( $value ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$/', $value, $m ) ) {
			return null;
		}
		return array(
			'r' => max( 0, min( 255, (int) round( (float) $m[1] ) ) ),
			'g' => max( 0, min( 255, (int) round( (float) $m[2] ) ) ),
			'b' => max( 0, min( 255, (int) round( (float) $m[3] ) ) ),
			'a' => isset( $m[4] ) ? max( 0, min( 1, (float) $m[4] ) ) : 1.0,
		);
	}

	/**
	 * Convert hex + opacity percent to rgba().
	 *
	 * @param string $hex      Hex color.
	 * @param int    $opacity  0–100.
	 * @param string $fallback Fallback rgba/hex.
	 * @return string
	 */
	private static function hex_to_rgba( $hex, $opacity, $fallback ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return $fallback;
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$a = max( 0, min( 100, (int) $opacity ) ) / 100;
		$a = rtrim( rtrim( number_format( $a, 2, '.', '' ), '0' ), '.' );
		if ( '' === $a ) {
			$a = '0';
		}

		return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, $a );
	}

	/**
	 * Resolve a design color: keep legacy rgba, or build from hex + opacity.
	 *
	 * @param mixed  $value    Saved color.
	 * @param int    $opacity  Opacity percent.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function resolve_color( $value, $opacity, $fallback ) {
		$value = trim( (string) $value );
		if ( self::parse_rgb_color( $value ) ) {
			return preg_replace( '/\s+/', '', $value );
		}
		$hex = self::sanitize_hex( $value, $fallback );
		return self::hex_to_rgba( $hex, $opacity, $fallback );
	}

	/**
	 * Sanitize custom CSS (strip HTML, cap length).
	 *
	 * @param mixed $css Raw CSS.
	 * @return string
	 */
	private static function sanitize_css( $css ) {
		$css = is_string( $css ) ? $css : '';
		$css = wp_strip_all_tags( $css );
		$css = preg_replace( '/<\/?style[^>]*>/i', '', $css );
		$css = str_replace( array( "\0", '`' ), '', $css );
		if ( strlen( $css ) > 20000 ) {
			$css = substr( $css, 0, 20000 );
		}
		return trim( $css );
	}

	/**
	 * Example Custom CSS shown when the field is empty (commented so it does not apply).
	 *
	 * @return string
	 */
	private static function custom_css_example() {
		return "/* Example — uncomment to try\n"
			. ".mw-sales-toast {\n"
			. "  box-shadow: 0 10px 30px rgba(0,0,0,.25);\n"
			. "}\n"
			. ".mw-sales-toast__text a {\n"
			. "  color: #fff;\n"
			. "}\n"
			. ".mw-sales-toast--media-full .mw-sales-toast__media {\n"
			. "  width: 88px;\n"
			. "}\n"
			. '*/';
	}

	/**
	 * Box-shadow CSS for a style_shadow setting key.
	 *
	 * @param string $key none|soft|medium|strong.
	 * @return string
	 */
	public static function shadow_css( $key ) {
		$map = array(
			'none'   => 'none',
			'soft'   => '0 1px 0 rgba(255,255,255,0.04) inset, 0 8px 20px rgba(0,0,0,0.18)',
			'medium' => '0 1px 0 rgba(255,255,255,0.05) inset, 0 18px 40px rgba(0,0,0,0.45)',
			'strong' => '0 1px 0 rgba(255,255,255,0.06) inset, 0 28px 56px rgba(0,0,0,0.6)',
		);
		$key = sanitize_key( (string) $key );
		return isset( $map[ $key ] ) ? $map[ $key ] : $map['medium'];
	}

	/**
	 * Inline design CSS from settings (variables + custom CSS).
	 *
	 * @param array|null $settings Settings.
	 * @return string
	 */
	public static function design_css( $settings = null ) {
		$s        = $settings ? $settings : self::get();
		$defaults = self::defaults();

		$bg_opacity     = max( 0, min( 100, (int) ( $s['style_bg_opacity'] ?? $defaults['style_bg_opacity'] ) ) );
		$border_opacity = max( 0, min( 100, (int) ( $s['style_border_opacity'] ?? $defaults['style_border_opacity'] ) ) );

		$bg     = self::resolve_color( $s['style_bg'] ?? '', $bg_opacity, 'rgba(12,18,32,0.92)' );
		$body   = self::sanitize_hex( $s['style_body'] ?? '', $defaults['style_body'] );
		$accent = self::sanitize_hex( $s['style_accent'] ?? '', $defaults['style_accent'] );
		$meta   = self::sanitize_hex( $s['style_meta'] ?? '', $defaults['style_meta'] );
		$close_hover = self::sanitize_hex( $s['style_close_hover'] ?? '', $defaults['style_close_hover'] );
		// Legacy --mw-st-color aliases meta (idle close + time).
		$text   = $meta;
		$border = self::resolve_color( $s['style_border'] ?? '', $border_opacity, 'rgba(255,255,255,0.1)' );
		$radius  = max( 0, min( 40, (int) ( $s['style_radius'] ?? $defaults['style_radius'] ) ) );
		$padding = max( 4, min( 32, (int) ( $s['style_padding'] ?? $defaults['style_padding'] ) ) );
		$max_w   = max( 220, min( 560, (int) ( $s['style_max_width'] ?? $defaults['style_max_width'] ) ) );
		$shadow  = self::shadow_css( $s['style_shadow'] ?? $defaults['style_shadow'] );
		$image_fit = ( 'padded' === ( $s['style_image_fit'] ?? '' ) ) ? 'padded' : 'full';
		$media_r   = ( 'padded' === $image_fit )
			? max( 0, min( 24, (int) round( $radius * 0.7 ) ) )
			: 0;
		$off_x   = max( 0, min( 80, (int) ( $s['offset_x'] ?? $defaults['offset_x'] ) ) );
		$off_y   = max( 0, min( 80, (int) ( $s['offset_y'] ?? $defaults['offset_y'] ) ) );
		$font_css = '';

		$use_elementor = ! empty( $s['use_elementor_theme'] ) && self::is_elementor_active();
		if ( $use_elementor ) {
			$theme = self::get_elementor_theme();
			if ( is_array( $theme ) ) {
				$bg     = self::resolve_color( $theme['style_bg'], $bg_opacity, $bg );
				$body   = self::sanitize_hex( $theme['style_body'], $body );
				$accent = self::sanitize_hex( $theme['style_accent'], $accent );
				$meta   = self::sanitize_hex( $theme['style_meta'], $meta );
				$text   = $meta;
				$border = self::resolve_color( $theme['style_border'], $border_opacity, $border );
				if ( ! empty( $theme['font'] ) ) {
					$font_css = sprintf(
						'--mw-st-font:"%1$s",system-ui,sans-serif;',
						$theme['font']
					);
				}
			}
		}

		$css = sprintf(
			'.mw-sales-toast{%15$s--mw-st-bg:%1$s;--mw-st-color:%2$s;--mw-st-body:%3$s;--mw-st-accent:%4$s;--mw-st-meta:%5$s;--mw-st-close-hover:%6$s;--mw-st-border:%7$s;--mw-st-radius:%8$dpx;--mw-st-media-radius:%9$dpx;--mw-st-padding:%10$dpx;--mw-st-max-width:%11$dpx;--mw-st-offset-x:%12$dpx;--mw-st-offset-y:%13$dpx;--mw-st-shadow:%14$s;}',
			$bg,
			$text,
			$body,
			$accent,
			$meta,
			$close_hover,
			$border,
			$radius,
			$media_r,
			$padding,
			$max_w,
			$off_x,
			$off_y,
			$shadow,
			$font_css
		);

		$custom = self::is_pro() ? self::sanitize_css( $s['custom_css'] ?? '' ) : '';
		if ( '' !== $custom ) {
			$css .= "\n" . $custom;
		}

		/**
		 * Filter generated design CSS.
		 *
		 * @param string $css      CSS.
		 * @param array  $settings Settings.
		 */
		return (string) apply_filters( 'mw_sales_toast_design_css', $css, $s );
	}

	/**
	 * Timing presets (seconds).
	 *
	 * @return array<string, array{delay:int,duration:int,gap:int,label:string,desc:string}>
	 */
	public static function timing_presets() {
		return array(
			'relaxed'  => array(
				'delay'    => 12,
				'duration' => 8,
				'gap'      => 22,
				'label'    => __( 'Relaxed', 'mw-sales-toast' ),
				'desc'     => __( 'Slower first toast, longer quiet time', 'mw-sales-toast' ),
			),
			'balanced' => array(
				'delay'    => 6,
				'duration' => 7,
				'gap'      => 12,
				'label'    => __( 'Balanced', 'mw-sales-toast' ),
				'desc'     => __( 'Good default for most stores', 'mw-sales-toast' ),
			),
			'frequent' => array(
				'delay'    => 3,
				'duration' => 5,
				'gap'      => 6,
				'label'    => __( 'Frequent', 'mw-sales-toast' ),
				'desc'     => __( 'Faster cadence — use sparingly', 'mw-sales-toast' ),
			),
			'custom'   => array(
				'delay'    => 6,
				'duration' => 7,
				'gap'      => 12,
				'label'    => __( 'Custom', 'mw-sales-toast' ),
				'desc'     => __( 'Set delay, visible time, and gap yourself', 'mw-sales-toast' ),
			),
		);
	}

	/**
	 * Design theme presets (colors + radius). Keeps palettes coherent.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function design_presets() {
		return array(
			'midnight' => array(
				'label'                => __( 'Midnight', 'mw-sales-toast' ),
				'desc'                 => __( 'Dark glass with warm gold accent', 'mw-sales-toast' ),
				'style_bg'             => '#0c1220',
				'style_bg_opacity'     => 92,
				'style_text'           => '#e8eef8',
				'style_body'           => '#d7deea',
				'style_accent'         => '#e8c872',
				'style_meta'           => '#a8b2c4',
				'style_close_hover'    => '#dc2626',
				'style_border'         => '#ffffff',
				'style_border_opacity' => 10,
				'style_radius'         => 14,
			),
			'soft'     => array(
				'label'                => __( 'Soft light', 'mw-sales-toast' ),
				'desc'                 => __( 'Clean white card with blue accent', 'mw-sales-toast' ),
				'style_bg'             => '#ffffff',
				'style_bg_opacity'     => 96,
				'style_text'           => '#0f172a',
				'style_body'           => '#334155',
				'style_accent'         => '#2563eb',
				'style_meta'           => '#64748b',
				'style_close_hover'    => '#dc2626',
				'style_border'         => '#0f172a',
				'style_border_opacity' => 8,
				'style_radius'         => 16,
			),
			'slate'    => array(
				'label'                => __( 'Slate', 'mw-sales-toast' ),
				'desc'                 => __( 'Cool charcoal with sky accent', 'mw-sales-toast' ),
				'style_bg'             => '#1e293b',
				'style_bg_opacity'     => 94,
				'style_text'           => '#f1f5f9',
				'style_body'           => '#cbd5e1',
				'style_accent'         => '#38bdf8',
				'style_meta'           => '#94a3b8',
				'style_close_hover'    => '#dc2626',
				'style_border'         => '#ffffff',
				'style_border_opacity' => 12,
				'style_radius'         => 12,
			),
			'ink'      => array(
				'label'                => __( 'Ink', 'mw-sales-toast' ),
				'desc'                 => __( 'Minimal black with crisp edges', 'mw-sales-toast' ),
				'style_bg'             => '#111111',
				'style_bg_opacity'     => 95,
				'style_text'           => '#fafafa',
				'style_body'           => '#d4d4d4',
				'style_accent'         => '#fafafa',
				'style_meta'           => '#a3a3a3',
				'style_close_hover'    => '#dc2626',
				'style_border'         => '#ffffff',
				'style_border_opacity' => 14,
				'style_radius'         => 8,
			),
			'forest'   => array(
				'label'                => __( 'Forest', 'mw-sales-toast' ),
				'desc'                 => __( 'Deep green with mint accent', 'mw-sales-toast' ),
				'style_bg'             => '#14201a',
				'style_bg_opacity'     => 93,
				'style_text'           => '#ecfdf5',
				'style_body'           => '#d1fae5',
				'style_accent'         => '#34d399',
				'style_meta'           => '#6ee7b7',
				'style_close_hover'    => '#dc2626',
				'style_border'         => '#ffffff',
				'style_border_opacity' => 10,
				'style_radius'         => 14,
			),
			'custom'   => array(
				'label' => __( 'Custom', 'mw-sales-toast' ),
				'desc'  => __( 'Tune colors and radius yourself', 'mw-sales-toast' ),
			),
		);
	}

	/**
	 * Apply a named design preset onto a settings array.
	 *
	 * @param array  $settings Settings.
	 * @param string $preset   Preset key.
	 * @return array
	 */
	public static function apply_design_preset( $settings, $preset ) {
		$presets = self::design_presets();
		if ( ! isset( $presets[ $preset ] ) || 'custom' === $preset ) {
			$settings['design_preset'] = isset( $presets[ $preset ] ) ? $preset : 'custom';
			return $settings;
		}

		$theme = $presets[ $preset ];
		foreach ( array(
			'style_bg',
			'style_bg_opacity',
			'style_text',
			'style_body',
			'style_accent',
			'style_meta',
			'style_close_hover',
			'style_border',
			'style_border_opacity',
			'style_radius',
		) as $key ) {
			if ( array_key_exists( $key, $theme ) ) {
				$settings[ $key ] = $theme[ $key ];
			}
		}
		$settings['design_preset'] = $preset;
		return $settings;
	}

	/**
	 * Merged settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$saved = get_option( MW_SALES_TOAST_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$defaults = self::defaults();

		// Pre-2.0 installs: do not suddenly require consent until an admin saves settings.
		if ( $saved && ! array_key_exists( 'require_consent', $saved ) ) {
			$defaults['require_consent'] = 0;
		}

		// Existing installs without a design preset: keep current colors (treat as Custom).
		if ( $saved && ! array_key_exists( 'design_preset', $saved ) ) {
			$defaults['design_preset'] = 'custom';
		}

		// Pre-image-fit installs used inset thumbnails with toast padding.
		if ( $saved && ! array_key_exists( 'style_image_fit', $saved ) ) {
			$defaults['style_image_fit'] = 'padded';
		}

		// Migrate legacy start-to-start interval → quiet gap after hide.
		if ( $saved && ! array_key_exists( 'gap', $saved ) && array_key_exists( 'interval', $saved ) ) {
			$duration = isset( $saved['duration'] ) ? (int) $saved['duration'] : (int) $defaults['duration'];
			$interval = (int) $saved['interval'];
			$defaults['gap'] = max( 3, $interval - $duration );
			$defaults['timing_preset'] = 'custom';
		}

		$merged = array_merge( $defaults, $saved );

		// Old demo times were bare fragments ("22 minutes") that expected an “… ago” suffix.
		if ( isset( $merged['demo_whens'] ) && class_exists( 'MW_Sales_Toast_Cache' ) ) {
			$merged['demo_whens'] = MW_Sales_Toast_Cache::migrate_demo_whens( $merged['demo_whens'] );
		}

		$when_styles = array( 'natural', 'exact' );
		if ( ! in_array( $merged['when_style'] ?? '', $when_styles, true ) ) {
			$merged['when_style'] = $defaults['when_style'];
		}

		$deliveries = array( 'rest', 'inline' );
		if ( ! in_array( $merged['event_delivery'] ?? '', $deliveries, true ) ) {
			$merged['event_delivery'] = $defaults['event_delivery'];
		}

		// Existing installs: keep stock off until an admin opts in.
		if ( $saved && ! array_key_exists( 'stock_display', $saved ) ) {
			$merged['stock_display'] = 'off';
		}
		$stock_modes = array( 'off', 'exact_low', 'soft' );
		if ( ! in_array( $merged['stock_display'] ?? '', $stock_modes, true ) ) {
			$merged['stock_display'] = $defaults['stock_display'];
		}
		$merged['stock_threshold'] = max( 1, min( 50, (int) ( $merged['stock_threshold'] ?? $defaults['stock_threshold'] ) ) );

		foreach ( array( 'include_products', 'exclude_products', 'include_categories', 'exclude_categories', 'hide_roles' ) as $list_key ) {
			$merged[ $list_key ] = self::normalize_id_list( $merged[ $list_key ] ?? array(), 'hide_roles' === $list_key );
		}
		$merged['url_include'] = isset( $merged['url_include'] ) ? (string) $merged['url_include'] : '';
		$merged['url_exclude'] = isset( $merged['url_exclude'] ) ? (string) $merged['url_exclude'] : '';
		$merged['match_product_page'] = empty( $merged['match_product_page'] ) ? 0 : 1;

		$presets = self::timing_presets();
		$preset  = isset( $merged['timing_preset'] ) ? $merged['timing_preset'] : 'balanced';
		if ( isset( $presets[ $preset ] ) && 'custom' !== $preset ) {
			$merged['delay']    = $presets[ $preset ]['delay'];
			$merged['duration'] = $presets[ $preset ]['duration'];
			$merged['gap']      = $presets[ $preset ]['gap'];
		}

		$design_presets = self::design_presets();
		$design_preset  = isset( $merged['design_preset'] ) ? (string) $merged['design_preset'] : 'midnight';
		if ( ! isset( $design_presets[ $design_preset ] ) ) {
			$design_preset = 'midnight';
		}
		if ( 'custom' !== $design_preset ) {
			$merged = self::apply_design_preset( $merged, $design_preset );
		} else {
			$merged['design_preset'] = 'custom';
		}

		// Migrate legacy rgba colors to hex + opacity for the color picker.
		foreach ( array( 'style_bg' => 'style_bg_opacity', 'style_border' => 'style_border_opacity' ) as $color_key => $opacity_key ) {
			$parsed = self::parse_rgb_color( $merged[ $color_key ] ?? '' );
			if ( ! $parsed ) {
				continue;
			}
			$merged[ $color_key ] = sprintf( '#%02x%02x%02x', $parsed['r'], $parsed['g'], $parsed['b'] );
			if ( ! array_key_exists( $opacity_key, $saved ) ) {
				$merged[ $opacity_key ] = (int) round( $parsed['a'] * 100 );
			}
		}
		foreach ( array( 'style_meta', 'style_text', 'style_body', 'style_accent' ) as $color_key ) {
			$parsed = self::parse_rgb_color( $merged[ $color_key ] ?? '' );
			if ( $parsed ) {
				$merged[ $color_key ] = sprintf( '#%02x%02x%02x', $parsed['r'], $parsed['g'], $parsed['b'] );
			}
		}

		// Without WooCommerce, real-order sources cannot run — use demo for admin + front end.
		if ( ! class_exists( 'WooCommerce' ) && 'demo' !== ( $merged['source'] ?? '' ) ) {
			$merged['source'] = 'demo';
		}

		return $merged;
	}

	/**
	 * Register option.
	 */
	public static function register() {
		register_setting(
			'mw_sales_toast',
			MW_SALES_TOAST_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Raw.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = $defaults;

		$checks = array(
			'enabled',
			'show_image',
			'exclude_home',
			'hide_cart_checkout',
			'hide_thankyou',
			'hide_account',
			'guests_only',
			'respect_reduced_motion',
			'require_consent',
			'hide_names',
			'disable_mobile',
			'sound_enabled',
			'newsletter',
			'use_elementor_theme',
		);
		foreach ( $checks as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		// Elementor theme sync only when Elementor is installed.
		if ( ! self::is_elementor_active() ) {
			$out['use_elementor_theme'] = 0;
		}

		$positions = array( 'bottom-left', 'bottom-right', 'top-left', 'top-right' );
		$out['position'] = in_array( $input['position'] ?? '', $positions, true )
			? $input['position']
			: $defaults['position'];
		$out['offset_x'] = max( 0, min( 80, (int) ( $input['offset_x'] ?? $defaults['offset_x'] ) ) );
		$out['offset_y'] = max( 0, min( 80, (int) ( $input['offset_y'] ?? $defaults['offset_y'] ) ) );

		$sources = array( 'real_orders', 'demo', 'real_then_demo' );
		$out['source'] = in_array( $input['source'] ?? '', $sources, true )
			? $input['source']
			: $defaults['source'];
		// Without WooCommerce, only demo events are available.
		if ( ! class_exists( 'WooCommerce' ) ) {
			$out['source'] = 'demo';
		}

		$show_on = array( 'all', 'shop', 'products', 'home' );
		$out['show_on'] = in_array( $input['show_on'] ?? '', $show_on, true )
			? $input['show_on']
			: $defaults['show_on'];

		// Disabled inputs are omitted from POST — keep previously saved values.
		$saved_opts = get_option( MW_SALES_TOAST_OPTION, array() );

		$out['mobile_breakpoint'] = max(
			320,
			min(
				1200,
				(int) (
					array_key_exists( 'mobile_breakpoint', $input )
						? $input['mobile_breakpoint']
						: ( ( is_array( $saved_opts ) && isset( $saved_opts['mobile_breakpoint'] ) )
							? $saved_opts['mobile_breakpoint']
							: $defaults['mobile_breakpoint'] )
				)
			)
		);

		$presets = self::timing_presets();
		$preset  = isset( $input['timing_preset'] ) ? sanitize_key( $input['timing_preset'] ) : $defaults['timing_preset'];
		if ( ! isset( $presets[ $preset ] ) ) {
			$preset = $defaults['timing_preset'];
		}
		$out['timing_preset'] = $preset;

		if ( 'custom' !== $preset ) {
			$out['delay']    = $presets[ $preset ]['delay'];
			$out['duration'] = $presets[ $preset ]['duration'];
			$out['gap']      = $presets[ $preset ]['gap'];
		} else {
			$out['delay']    = max( 1, min( 120, (int) ( $input['delay'] ?? $defaults['delay'] ) ) );
			$out['duration'] = max( 2, min( 60, (int) ( $input['duration'] ?? $defaults['duration'] ) ) );
			$out['gap']      = max( 1, min( 300, (int) ( $input['gap'] ?? $defaults['gap'] ) ) );
		}

		$out['jitter'] = max( 0, min( 50, (int) ( $input['jitter'] ?? $defaults['jitter'] ) ) );

		$when_styles = array( 'natural', 'exact' );
		$out['when_style'] = in_array( $input['when_style'] ?? '', $when_styles, true )
			? $input['when_style']
			: $defaults['when_style'];

		$deliveries = array( 'rest', 'inline' );
		$out['event_delivery'] = in_array( $input['event_delivery'] ?? '', $deliveries, true )
			? $input['event_delivery']
			: $defaults['event_delivery'];

		$stock_modes = array( 'off', 'exact_low', 'soft' );
		$out['stock_display'] = in_array( $input['stock_display'] ?? '', $stock_modes, true )
			? $input['stock_display']
			: $defaults['stock_display'];
		// Disabled threshold input is omitted from POST — keep the previously saved value.
		$prev_threshold = ( is_array( $saved_opts ) && isset( $saved_opts['stock_threshold'] ) )
			? $saved_opts['stock_threshold']
			: $defaults['stock_threshold'];
		$out['stock_threshold'] = max(
			1,
			min(
				50,
				(int) ( array_key_exists( 'stock_threshold', $input ) ? $input['stock_threshold'] : $prev_threshold )
			)
		);

		$out['max_events']        = max( 1, min( 30, (int) ( $input['max_events'] ?? $defaults['max_events'] ) ) );
		$out['max_cached_orders'] = max( 5, min( 100, (int) ( $input['max_cached_orders'] ?? $defaults['max_cached_orders'] ) ) );
		$out['cache_minutes']     = max( 1, min( 120, (int) ( $input['cache_minutes'] ?? $defaults['cache_minutes'] ) ) );
		$out['cron_minutes']      = max( 1, min( 120, (int) ( $input['cron_minutes'] ?? $defaults['cron_minutes'] ) ) );
		$out['lookback_days']     = max( 1, min( 365, (int) ( $input['lookback_days'] ?? $defaults['lookback_days'] ) ) );
		$out['max_per_session']   = max( 1, min( 100, (int) ( $input['max_per_session'] ?? $defaults['max_per_session'] ) ) );
		$out['mute_hours']        = max( 0, min( 720, (int) ( $input['mute_hours'] ?? $defaults['mute_hours'] ) ) );

		$out['fallback_name'] = isset( $input['fallback_name'] )
			? sanitize_text_field( $input['fallback_name'] )
			: $defaults['fallback_name'];
		if ( '' === $out['fallback_name'] ) {
			$out['fallback_name'] = $defaults['fallback_name'];
		}

		$out['message_template'] = isset( $input['message_template'] )
			? sanitize_text_field( $input['message_template'] )
			: $defaults['message_template'];
		if ( '' === $out['message_template'] ) {
			$out['message_template'] = $defaults['message_template'];
		}

		$out['demo_people'] = isset( $input['demo_people'] )
			? sanitize_textarea_field( $input['demo_people'] )
			: $defaults['demo_people'];
		$out['demo_whens'] = isset( $input['demo_whens'] )
			? sanitize_textarea_field( $input['demo_whens'] )
			: $defaults['demo_whens'];
		if ( class_exists( 'MW_Sales_Toast_Cache' ) ) {
			$out['demo_whens'] = MW_Sales_Toast_Cache::migrate_demo_whens( $out['demo_whens'] );
		}

		$out['style_bg']             = self::sanitize_hex( $input['style_bg'] ?? '', $defaults['style_bg'] );
		$out['style_bg_opacity']     = max( 0, min( 100, (int) ( $input['style_bg_opacity'] ?? $defaults['style_bg_opacity'] ) ) );
		$out['style_body']           = self::sanitize_hex( $input['style_body'] ?? '', $defaults['style_body'] );
		$out['style_accent']         = self::sanitize_hex( $input['style_accent'] ?? '', $defaults['style_accent'] );
		$out['style_meta']           = self::sanitize_hex( $input['style_meta'] ?? '', $defaults['style_meta'] );
		$out['style_close_hover']    = self::sanitize_hex( $input['style_close_hover'] ?? '', $defaults['style_close_hover'] );
		$out['style_border']         = self::sanitize_hex( $input['style_border'] ?? '', $defaults['style_border'] );
		$out['style_border_opacity'] = max( 0, min( 100, (int) ( $input['style_border_opacity'] ?? $defaults['style_border_opacity'] ) ) );
		$out['style_radius']         = max( 0, min( 40, (int) ( $input['style_radius'] ?? $defaults['style_radius'] ) ) );
		$out['style_padding']        = max( 4, min( 32, (int) ( $input['style_padding'] ?? $defaults['style_padding'] ) ) );
		$out['style_max_width']      = max( 220, min( 560, (int) ( $input['style_max_width'] ?? $defaults['style_max_width'] ) ) );
		$shadow_opts                 = array( 'none', 'soft', 'medium', 'strong' );
		$out['style_shadow']         = in_array( $input['style_shadow'] ?? '', $shadow_opts, true )
			? $input['style_shadow']
			: $defaults['style_shadow'];
		// Disabled radios are omitted from POST — keep the previously saved fit.
		if ( array_key_exists( 'style_image_fit', $input ) ) {
			$out['style_image_fit'] = ( 'padded' === ( $input['style_image_fit'] ?? '' ) ) ? 'padded' : 'full';
		} else {
			$prev_fit = ( is_array( $saved_opts ) && isset( $saved_opts['style_image_fit'] ) )
				? $saved_opts['style_image_fit']
				: $defaults['style_image_fit'];
			$out['style_image_fit'] = ( 'padded' === $prev_fit ) ? 'padded' : 'full';
		}
		if ( self::is_pro() ) {
			$out['custom_css'] = self::sanitize_css( $input['custom_css'] ?? '' );
		} else {
			$out['custom_css'] = self::sanitize_css(
				( is_array( $saved_opts ) && isset( $saved_opts['custom_css'] ) )
					? $saved_opts['custom_css']
					: ''
			);
		}

		$design_presets = self::design_presets();
		$design_preset  = isset( $input['design_preset'] ) ? sanitize_key( $input['design_preset'] ) : $defaults['design_preset'];
		if ( ! isset( $design_presets[ $design_preset ] ) ) {
			$design_preset = $defaults['design_preset'];
		}
		$out['design_preset'] = $design_preset;
		if ( 'custom' !== $design_preset ) {
			$out = self::apply_design_preset( $out, $design_preset );
		}

		// style_text is no longer editable — alias meta for legacy CSS vars / presets.
		$out['style_text'] = self::sanitize_hex( $out['style_meta'] ?? '', $defaults['style_meta'] );

		// Elementor kit sync is an override — keep preset as Custom in storage.
		if ( ! empty( $out['use_elementor_theme'] ) ) {
			$out['design_preset'] = 'custom';
		}

		// Pro targeting — only update from POST when Pro is active; otherwise keep saved values.
		$pro_keys = array(
			'url_include',
			'url_exclude',
			'include_products',
			'exclude_products',
			'include_categories',
			'exclude_categories',
			'match_product_page',
			'hide_roles',
		);
		if ( self::is_pro() ) {
			$out['url_include'] = self::sanitize_path_list( $input['url_include'] ?? '' );
			$out['url_exclude'] = self::sanitize_path_list( $input['url_exclude'] ?? '' );
			$out['include_products']   = self::sanitize_id_list( $input['include_products'] ?? array() );
			$out['exclude_products']   = self::sanitize_id_list( $input['exclude_products'] ?? array() );
			$out['include_categories'] = self::sanitize_id_list( $input['include_categories'] ?? array() );
			$out['exclude_categories'] = self::sanitize_id_list( $input['exclude_categories'] ?? array() );
			$out['match_product_page'] = empty( $input['match_product_page'] ) ? 0 : 1;
			$out['hide_roles']         = self::sanitize_role_list( $input['hide_roles'] ?? array() );
		} else {
			foreach ( $pro_keys as $key ) {
				if ( is_array( $saved_opts ) && array_key_exists( $key, $saved_opts ) ) {
					$out[ $key ] = $saved_opts[ $key ];
				}
			}
			$out['include_products']   = self::sanitize_id_list( $out['include_products'] ?? array() );
			$out['exclude_products']   = self::sanitize_id_list( $out['exclude_products'] ?? array() );
			$out['include_categories'] = self::sanitize_id_list( $out['include_categories'] ?? array() );
			$out['exclude_categories'] = self::sanitize_id_list( $out['exclude_categories'] ?? array() );
			$out['hide_roles']         = self::sanitize_role_list( $out['hide_roles'] ?? array() );
			$out['url_include']        = self::sanitize_path_list( $out['url_include'] ?? '' );
			$out['url_exclude']        = self::sanitize_path_list( $out['url_exclude'] ?? '' );
			$out['match_product_page'] = empty( $out['match_product_page'] ) ? 0 : 1;
		}

		// Rebuild cache + reschedule cron so admin status and front end reflect the new settings immediately.
		delete_transient( MW_SALES_TOAST_TRANSIENT );
		if ( class_exists( 'MW_Sales_Toast_Cache' ) ) {
			MW_Sales_Toast_Cache::rebuild( $out );
			MW_Sales_Toast_Cache::reschedule_cron( $out );
		}

		return $out;
	}

	/**
	 * Capability for the settings screen.
	 *
	 * @return string
	 */
	public static function capability() {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Settings link on Plugins → Installed Plugins.
	 *
	 * @param array<string, string> $links Action links.
	 * @return array<string, string>
	 */
	public static function plugin_action_links( $links ) {
		if ( ! current_user_can( self::capability() ) ) {
			return $links;
		}

		$settings = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=mw-sales-toast' ) ),
			esc_html__( 'Settings', 'mw-sales-toast' )
		);

		$out = array_merge(
			array( 'settings' => $settings ),
			$links
		);

		// Hide upgrade CTA when Pro is active.
		if ( ! self::is_pro() ) {
			$out['go_pro'] = sprintf(
				'<a class="mwst-go-pro" href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=mw-sales-toast&tab=account' ) ),
				esc_html__( 'Go Pro', 'mw-sales-toast' )
			);
		}

		return $out;
	}

	/**
	 * Top-level admin menu (always). Also listed under WooCommerce when present.
	 */
	public static function menu() {
		$cap = self::capability();

		add_menu_page(
			__( 'Sales Toast', 'mw-sales-toast' ),
			__( 'Sales Toast', 'mw-sales-toast' ),
			$cap,
			'mw-sales-toast',
			array( __CLASS__, 'render' ),
			'dashicons-megaphone',
			58
		);

		// Rename the auto-added first submenu item.
		add_submenu_page(
			'mw-sales-toast',
			__( 'Sales Toast', 'mw-sales-toast' ),
			__( 'Settings', 'mw-sales-toast' ),
			$cap,
			'mw-sales-toast',
			array( __CLASS__, 'render' )
		);

		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'Sales Toast', 'mw-sales-toast' ),
				__( 'Sales Toast', 'mw-sales-toast' ),
				$cap,
				'mw-sales-toast',
				array( __CLASS__, 'render' )
			);
		}
	}

	/**
	 * Style the Go Pro link on Plugins → Installed Plugins.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_plugins_list( $hook ) {
		if ( 'plugins.php' !== $hook || self::is_pro() ) {
			return;
		}

		wp_register_style( 'mw-sales-toast-plugins', false, array(), MW_SALES_TOAST_VERSION );
		wp_enqueue_style( 'mw-sales-toast-plugins' );
		wp_add_inline_style(
			'mw-sales-toast-plugins',
			'.plugins .mwst-go-pro{color:#c3368a;font-weight:700;}' .
			'.plugins .mwst-go-pro:hover,.plugins .mwst-go-pro:focus{color:#a12c72;}'
		);
	}

	/**
	 * Admin assets on this page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin( $hook ) {
		if ( false === strpos( $hook, 'mw-sales-toast' ) ) {
			return;
		}

		wp_enqueue_style(
			'mw-sales-toast',
			MW_SALES_TOAST_URL . 'assets/toast.css',
			array(),
			MW_SALES_TOAST_VERSION
		);

		$design_css = self::design_css();
		if ( '' !== $design_css ) {
			wp_add_inline_style( 'mw-sales-toast', $design_css );
		}

		wp_enqueue_style(
			'mw-sales-toast-admin',
			MW_SALES_TOAST_URL . 'assets/admin.css',
			array( 'mw-sales-toast' ),
			MW_SALES_TOAST_VERSION
		);

		wp_enqueue_style( 'wp-color-picker' );

		if ( class_exists( 'WooCommerce' ) ) {
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
		}

		$code_editor = false;
		if ( function_exists( 'wp_enqueue_code_editor' ) ) {
			$code_editor = wp_enqueue_code_editor(
				array(
					'type'       => 'text/css',
					'codemirror' => array(
						'indentUnit'   => 2,
						'tabSize'      => 2,
						'lineNumbers'  => true,
						'lineWrapping' => true,
						'readOnly'     => self::is_pro() ? false : 'nocursor',
					),
				)
			);
		}

		wp_enqueue_script(
			'mw-sales-toast-pop',
			MW_SALES_TOAST_URL . 'assets/pop-sound.js',
			array(),
			MW_SALES_TOAST_VERSION,
			true
		);

		$admin_deps = array( 'jquery', 'wp-color-picker', 'mw-sales-toast-pop' );
		if ( class_exists( 'WooCommerce' ) ) {
			$admin_deps[] = 'wc-enhanced-select';
		}
		if ( false !== $code_editor ) {
			$admin_deps[] = 'code-editor';
		}

		wp_enqueue_script(
			'mw-sales-toast-admin',
			MW_SALES_TOAST_URL . 'assets/admin.js',
			$admin_deps,
			MW_SALES_TOAST_VERSION,
			true
		);

		$defaults = self::defaults();
		wp_localize_script(
			'mw-sales-toast-admin',
			'mwSalesToastAdmin',
			array(
				'isPro'          => self::is_pro(),
				'codeEditor'     => ( false !== $code_editor ) ? $code_editor : null,
				'customCssExample' => self::custom_css_example(),
				'designDefaults' => array(
					'style_bg'             => $defaults['style_bg'],
					'style_bg_opacity'     => $defaults['style_bg_opacity'],
					'style_text'           => $defaults['style_text'],
					'style_body'           => $defaults['style_body'],
					'style_accent'         => $defaults['style_accent'],
					'style_meta'           => $defaults['style_meta'],
					'style_close_hover'    => $defaults['style_close_hover'],
					'style_border'         => $defaults['style_border'],
					'style_border_opacity' => $defaults['style_border_opacity'],
					'style_radius'         => $defaults['style_radius'],
					'style_padding'        => $defaults['style_padding'],
					'style_max_width'      => $defaults['style_max_width'],
					'style_shadow'         => $defaults['style_shadow'],
					'style_image_fit'      => $defaults['style_image_fit'],
					'custom_css'           => '',
				),
				'elementorTheme' => array(
					'available' => self::is_elementor_active(),
					'theme'     => self::get_elementor_theme(),
				),
				'support'        => array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'mw_st_support' ),
					'action'  => 'mw_st_support_request',
				),
				'analytics'      => ( self::is_pro() && class_exists( 'MW_Sales_Toast_Analytics' ) )
					? MW_Sales_Toast_Analytics::dashboard_payload()
					: null,
				'i18n'           => array(
					/* translators: %s: relative time */
					'ago'            => __( '%s ago', 'mw-sales-toast' ),
					'showToast'      => __( 'Show toast', 'mw-sales-toast' ),
					'hideToast'      => __( 'Hide toast', 'mw-sales-toast' ),
					'dismiss'        => __( 'Dismiss', 'mw-sales-toast' ),
					'sampleWhen'       => __( '2 minutes', 'mw-sales-toast' ),
					'sampleNatural'    => __( 'just now', 'mw-sales-toast' ),
					'sampleStockExact' => __( 'only 3 left', 'mw-sales-toast' ),
					'sampleStockSoft'  => __( 'only a few left', 'mw-sales-toast' ),
					'saving'           => __( 'Saving…', 'mw-sales-toast' ),
					'saveHintBusy'     => __( 'Saving your settings and rebuilding the sales cache…', 'mw-sales-toast' ),
					'supportSending'   => __( 'Sending…', 'mw-sales-toast' ),
					'supportSend'      => __( 'Send message', 'mw-sales-toast' ),
					'supportError'     => __( 'Something went wrong. Please try again.', 'mw-sales-toast' ),
					'statsEmpty'       => __( 'No product data yet.', 'mw-sales-toast' ),
					'statsMinutes'     => __( 'minutes', 'mw-sales-toast' ),
				),
			)
		);
	}

	/**
	 * Normalize a saved color for the picker UI (hex only).
	 *
	 * @param mixed  $value   Saved.
	 * @param string $default Default hex.
	 * @return string
	 */
	private static function picker_value( $value, $default ) {
		return self::sanitize_hex( $value, $default );
	}

	/**
	 * Render a WooCommerce product multi-select (or ID textarea fallback).
	 *
	 * @param string    $opt      Option name.
	 * @param string    $key      Setting key.
	 * @param string    $id       Input id.
	 * @param array     $selected Selected product IDs.
	 * @param bool      $enabled  Interactive.
	 */
	private static function render_product_picker( $opt, $key, $id, $selected, $enabled ) {
		$selected = array_filter( array_map( 'absint', (array) $selected ) );
		$use_wc   = $enabled && class_exists( 'WooCommerce' );

		if ( $use_wc ) {
			?>
			<select
				id="<?php echo esc_attr( $id ); ?>"
				class="wc-product-search"
				multiple="multiple"
				style="width:100%;"
				name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $key ); ?>][]"
				data-placeholder="<?php esc_attr_e( 'Search products…', 'mw-sales-toast' ); ?>"
				data-action="woocommerce_json_search_products_and_variations"
				data-allow_clear="true"
			>
				<?php
				foreach ( $selected as $product_id ) {
					$product = wc_get_product( $product_id );
					if ( ! $product ) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr( (string) $product_id ); ?>" selected="selected">
						<?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?>
					</option>
					<?php
				}
				?>
			</select>
			<?php
			return;
		}

		?>
		<input
			type="text"
			id="<?php echo esc_attr( $id ); ?>"
			class="large-text"
			name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( implode( ', ', $selected ) ); ?>"
			<?php disabled( ! $enabled ); ?>
			placeholder="<?php esc_attr_e( 'Product IDs, comma-separated', 'mw-sales-toast' ); ?>"
		/>
		<?php
	}

	/**
	 * Render a product category multi-select (or ID textarea fallback).
	 *
	 * @param string $opt      Option name.
	 * @param string $key      Setting key.
	 * @param string $id       Input id.
	 * @param array  $selected Selected term IDs.
	 * @param bool   $enabled  Interactive.
	 */
	private static function render_category_picker( $opt, $key, $id, $selected, $enabled ) {
		$selected = array_filter( array_map( 'absint', (array) $selected ) );
		$use_wc   = $enabled && taxonomy_exists( 'product_cat' );

		if ( $use_wc ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			);
			if ( is_wp_error( $terms ) ) {
				$terms = array();
			}
			?>
			<select
				id="<?php echo esc_attr( $id ); ?>"
				class="wc-enhanced-select"
				multiple="multiple"
				style="width:100%;"
				name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $key ); ?>][]"
				data-placeholder="<?php esc_attr_e( 'Select categories…', 'mw-sales-toast' ); ?>"
			>
				<?php foreach ( $terms as $term ) : ?>
					<option value="<?php echo esc_attr( (string) $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, $selected, true ) ); ?>>
						<?php echo esc_html( $term->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php
			return;
		}

		?>
		<input
			type="text"
			id="<?php echo esc_attr( $id ); ?>"
			class="large-text"
			name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( implode( ', ', $selected ) ); ?>"
			<?php disabled( ! $enabled ); ?>
			placeholder="<?php esc_attr_e( 'Category IDs, comma-separated', 'mw-sales-toast' ); ?>"
		/>
		<?php
	}

	/**
	 * Render a toggle field.
	 *
	 * @param string $opt   Option name.
	 * @param string $key   Setting key.
	 * @param array  $s     Settings.
	 * @param string $id    Input id.
	 * @param string $label Label text.
	 */
	private static function toggle( $opt, $key, $s, $id, $label ) {
		?>
		<label class="mwst-toggle" for="<?php echo esc_attr( $id ); ?>">
			<input
				id="<?php echo esc_attr( $id ); ?>"
				type="checkbox"
				name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $key ); ?>]"
				value="1"
				<?php checked( ! empty( $s[ $key ] ) ); ?>
			/>
			<span class="mwst-toggle__track" aria-hidden="true"></span>
			<span class="mwst-toggle__text"><?php echo esc_html( $label ); ?></span>
		</label>
		<?php
	}

	/**
	 * Cache / environment summary for sidebar.
	 *
	 * @param array $s Settings.
	 * @return array{events:int,ttl:string,wc:string,cron:string}
	 */
	private static function status_meta( $s ) {
		// Ensure cron exists (e.g. after upgrade without re-activate).
		if ( class_exists( 'MW_Sales_Toast_Cache' ) ) {
			MW_Sales_Toast_Cache::ensure_cron();
		}

		// Always rebuild on the settings screen so the Events metric matches current rules.
		if ( class_exists( 'MW_Sales_Toast_Cache' ) ) {
			$cached = MW_Sales_Toast_Cache::rebuild( $s );
		} else {
			$cached = get_transient( MW_SALES_TOAST_TRANSIENT );
		}

		$count = is_array( $cached ) ? count( $cached ) : 0;

		$ttl = __( 'Empty', 'mw-sales-toast' );
		if ( false !== $cached && is_array( $cached ) ) {
			$timeout = get_option( '_transient_timeout_' . MW_SALES_TOAST_TRANSIENT );
			if ( $timeout ) {
				$remaining = max( 0, (int) $timeout - time() );
				$ttl       = sprintf(
					/* translators: %s: human time difference */
					__( 'Expires in %s', 'mw-sales-toast' ),
					human_time_diff( time(), time() + $remaining )
				);
			} else {
				$ttl = __( 'Cached', 'mw-sales-toast' );
			}
		}

		$cron = wp_next_scheduled( MW_SALES_TOAST_CRON );
		$cron_label = $cron
			? sprintf(
				/* translators: %s: human time difference */
				__( 'Next run in %s', 'mw-sales-toast' ),
				human_time_diff( time(), $cron )
			)
			: __( 'Not scheduled', 'mw-sales-toast' );

		$wc_active = class_exists( 'WooCommerce' );

		return array(
			'events'    => $count,
			'ttl'       => $ttl,
			'wc'        => $wc_active ? __( 'Active', 'mw-sales-toast' ) : __( 'Not detected', 'mw-sales-toast' ),
			'wc_active' => $wc_active,
			'cron'      => $cron_label,
			'source'    => $s['source'],
		);
	}

	/**
	 * In-admin link that switches to another settings tab.
	 *
	 * @param string $tab   Tab id (general, message, design, timing, demo, statistics, support, account).
	 * @param string $label Link text.
	 * @return string Safe HTML anchor.
	 */
	private static function tab_link( $tab, $label ) {
		$tab = sanitize_key( $tab );
		return sprintf(
			'<a href="%1$s" class="mwst-tab-link" data-tab="%2$s">%3$s</a>',
			esc_url( add_query_arg( 'tab', $tab ) ),
			esc_attr( $tab ),
			esc_html( $label )
		);
	}

	/**
	 * Settings page.
	 */
	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$s       = self::get();
		$opt     = MW_SALES_TOAST_OPTION;
		$status  = self::status_meta( $s );
		$enabled = ! empty( $s['enabled'] );
		$saved   = isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$allowed_tabs = array( 'general', 'message', 'design', 'timing', 'demo', 'statistics', 'support', 'account' );
		$current_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Legacy Contact tab now lives under Support → Contact.
		if ( 'contact' === $current_tab ) {
			$current_tab = 'support';
		}
		// Legacy License tab → Account → License.
		if ( 'license' === $current_tab ) {
			$current_tab = 'account';
		}
		if ( ! in_array( $current_tab, $allowed_tabs, true ) ) {
			$current_tab = 'general';
		}
		$nonsave_tabs = array( 'statistics', 'support' );
		$is_pro = self::is_pro();

		$source_labels = array(
			'real_orders'    => __( 'Real orders only', 'mw-sales-toast' ),
			'demo'           => __( 'Demo only', 'mw-sales-toast' ),
			'real_then_demo' => __( 'Real + demo fill', 'mw-sales-toast' ),
		);
		?>
		<div class="wrap mwst-admin" id="mwst-admin">
			<header class="mwst-header">
				<div class="mwst-header__glow" aria-hidden="true"></div>
				<div class="mwst-header__main">
					<span class="mwst-header__mark" aria-hidden="true">
						<svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<rect x="3" y="7" width="18" height="12" rx="4" fill="currentColor" opacity="0.22"/>
							<rect x="3" y="7" width="18" height="12" rx="4" stroke="currentColor" stroke-width="1.5"/>
							<path d="M8 7V6a4 4 0 0 1 8 0v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
							<circle cx="17.5" cy="5.5" r="2" fill="#e8c872"/>
						</svg>
					</span>
					<div class="mwst-header__copy">
						<p class="mwst-header__eyebrow">
							<?php
							echo ! empty( $status['wc_active'] )
								? esc_html__( 'WooCommerce social proof', 'mw-sales-toast' )
								: esc_html__( 'Social proof toasts', 'mw-sales-toast' );
							?>
						</p>
						<div class="mwst-header__title-row">
							<h1><?php esc_html_e( 'Sales Toast', 'mw-sales-toast' ); ?></h1>
							<span class="mwst-header__version">v<?php echo esc_html( MW_SALES_TOAST_VERSION ); ?></span>
						</div>
						<p class="mwst-header__desc">
							<?php esc_html_e( 'Show recent purchases as discreet toasts — cached for performance, privacy-aware by default.', 'mw-sales-toast' ); ?>
						</p>
					</div>
				</div>
				<div class="mwst-header__aside">
					<span
						id="mwst-status-badge"
						class="mwst-badge <?php echo $enabled ? 'mwst-badge--on' : 'mwst-badge--off'; ?>"
					>
						<?php echo $enabled ? esc_html__( 'Enabled', 'mw-sales-toast' ) : esc_html__( 'Disabled', 'mw-sales-toast' ); ?>
					</span>
					<dl class="mwst-header__metrics" aria-label="<?php esc_attr_e( 'System status', 'mw-sales-toast' ); ?>">
						<div class="mwst-header__metric">
							<dt><?php esc_html_e( 'Plan', 'mw-sales-toast' ); ?></dt>
							<dd>
								<a href="<?php echo esc_url( add_query_arg( 'tab', 'account' ) ); ?>" class="mwst-plan-pill <?php echo $is_pro ? 'mwst-plan-pill--pro' : 'mwst-plan-pill--free'; ?>">
									<?php echo $is_pro ? esc_html__( 'Pro', 'mw-sales-toast' ) : esc_html__( 'Free', 'mw-sales-toast' ); ?>
								</a>
							</dd>
						</div>
						<div class="mwst-header__metric">
							<dt><?php esc_html_e( 'WooCommerce', 'mw-sales-toast' ); ?></dt>
							<dd>
								<span class="mwst-header__status <?php echo ! empty( $status['wc_active'] ) ? 'is-ok' : 'is-bad'; ?>">
									<?php echo esc_html( $status['wc'] ); ?>
								</span>
							</dd>
						</div>
						<div class="mwst-header__metric">
							<dt><?php esc_html_e( 'Source', 'mw-sales-toast' ); ?></dt>
							<dd><?php echo esc_html( $source_labels[ $s['source'] ] ?? $s['source'] ); ?></dd>
						</div>
					</dl>
				</div>
			</header>

			<?php if ( $saved ) : ?>
				<div class="mwst-notice mwst-notice--success" role="status" aria-live="polite" id="mwst-saved-notice">
					<span class="mwst-notice__icon" aria-hidden="true">✓</span>
					<div class="mwst-notice__body">
						<strong><?php esc_html_e( 'Settings saved', 'mw-sales-toast' ); ?></strong>
						<p><?php esc_html_e( 'Your Sales Toast settings were updated. The sales cache was cleared and will rebuild with the new options.', 'mw-sales-toast' ); ?></p>
					</div>
					<button type="button" class="mwst-notice__dismiss" id="mwst-dismiss-notice" aria-label="<?php esc_attr_e( 'Dismiss', 'mw-sales-toast' ); ?>">×</button>
				</div>
			<?php endif; ?>

			<?php if ( 0 === (int) $status['events'] && in_array( $s['source'], array( 'real_orders', 'real_then_demo' ), true ) && ! empty( $status['wc_active'] ) ) : ?>
				<div class="mwst-notice mwst-notice--warn" role="status">
					<span class="mwst-notice__icon" aria-hidden="true">!</span>
					<div class="mwst-notice__body">
						<strong><?php esc_html_e( 'No toast events cached', 'mw-sales-toast' ); ?></strong>
						<p>
							<?php
							if ( ! empty( $s['require_consent'] ) ) {
								esc_html_e( 'Real orders are enabled, but nothing is cached yet. Check Lookback window under Timing & cache, and Message & privacy → Checkout consent (customers who declined are hidden; admin/legacy orders without a choice still count).', 'mw-sales-toast' );
							} else {
								esc_html_e( 'Real orders are enabled, but nothing is cached yet. Confirm you have processing/completed orders inside the Lookback window (Timing & cache), then reload this page.', 'mw-sales-toast' );
							}
							?>
						</p>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'mw_sales_toast' ); ?>

				<div class="mwst-layout">
					<div class="mwst-main">
						<div class="mwst-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'mw-sales-toast' ); ?>">
							<button type="button" class="mwst-tabs__btn<?php echo 'general' === $current_tab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'general' === $current_tab ? 'true' : 'false'; ?>" data-tab="general"><?php esc_html_e( 'General', 'mw-sales-toast' ); ?></button>
							<button type="button" class="mwst-tabs__btn<?php echo 'message' === $current_tab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'message' === $current_tab ? 'true' : 'false'; ?>" data-tab="message"><?php esc_html_e( 'Message & privacy', 'mw-sales-toast' ); ?></button>
							<button type="button" class="mwst-tabs__btn<?php echo 'design' === $current_tab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'design' === $current_tab ? 'true' : 'false'; ?>" data-tab="design"><?php esc_html_e( 'Design', 'mw-sales-toast' ); ?></button>
							<button type="button" class="mwst-tabs__btn<?php echo 'timing' === $current_tab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'timing' === $current_tab ? 'true' : 'false'; ?>" data-tab="timing"><?php esc_html_e( 'Timing & cache', 'mw-sales-toast' ); ?></button>
							<button type="button" class="mwst-tabs__btn<?php echo 'demo' === $current_tab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'demo' === $current_tab ? 'true' : 'false'; ?>" data-tab="demo"><?php esc_html_e( 'Demo data', 'mw-sales-toast' ); ?></button>
							<button type="button" class="mwst-tabs__btn<?php echo 'statistics' === $current_tab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'statistics' === $current_tab ? 'true' : 'false'; ?>" data-tab="statistics"><?php esc_html_e( 'Statistics', 'mw-sales-toast' ); ?></button>
							<button type="button" class="mwst-tabs__btn<?php echo 'support' === $current_tab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'support' === $current_tab ? 'true' : 'false'; ?>" data-tab="support"><?php esc_html_e( 'Support', 'mw-sales-toast' ); ?></button>
							<button type="button" class="mwst-tabs__btn<?php echo 'account' === $current_tab ? ' is-active' : ''; ?>" role="tab" aria-selected="<?php echo 'account' === $current_tab ? 'true' : 'false'; ?>" data-tab="account">
								<?php esc_html_e( 'Account', 'mw-sales-toast' ); ?>
							</button>
						</div>

						<!-- General -->
						<div class="mwst-panel<?php echo 'general' === $current_tab ? ' is-active' : ''; ?>" id="mwst-panel-general" role="tabpanel">
							<div class="mwst-card mwst-card--accent">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Status', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Master switch for front-end toasts.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Enable', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'enabled', $s, 'mwst-enabled', __( 'Show toasts on the front end', 'mw-sales-toast' ) ); ?>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-source"><?php esc_html_e( 'Data source', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<select id="mwst-source" name="<?php echo esc_attr( $opt ); ?>[source]">
												<option value="demo" <?php selected( $s['source'], 'demo' ); ?>><?php esc_html_e( 'Demo / simulated only', 'mw-sales-toast' ); ?></option>
												<option value="real_orders" <?php selected( $s['source'], 'real_orders' ); ?> <?php disabled( empty( $status['wc_active'] ) ); ?>><?php esc_html_e( 'Real orders only', 'mw-sales-toast' ); ?></option>
												<option value="real_then_demo" <?php selected( $s['source'], 'real_then_demo' ); ?> <?php disabled( empty( $status['wc_active'] ) ); ?>><?php esc_html_e( 'Real orders, fill with demo if needed', 'mw-sales-toast' ); ?></option>
											</select>
											<p class="description">
												<?php
												echo ! empty( $status['wc_active'] )
													? esc_html__( 'Demo mode can fill gaps when order volume is low. Real orders require WooCommerce.', 'mw-sales-toast' )
													: esc_html__( 'WooCommerce is not active — demo toasts still work (catalog products when available, otherwise a generic label).', 'mw-sales-toast' );
												?>
											</p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-event-delivery"><?php esc_html_e( 'Event delivery', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<select id="mwst-event-delivery" name="<?php echo esc_attr( $opt ); ?>[event_delivery]">
												<option value="rest" <?php selected( $s['event_delivery'] ?? 'rest', 'rest' ); ?>><?php esc_html_e( 'REST API — fetch with nonce (default)', 'mw-sales-toast' ); ?></option>
												<option value="inline" <?php selected( $s['event_delivery'] ?? 'rest', 'inline' ); ?>><?php esc_html_e( 'Inline — embed JSON in the page (no REST)', 'mw-sales-toast' ); ?></option>
											</select>
											<p class="description"><?php esc_html_e( 'Inline disables the notifications REST route and prints events into the page script. Good for removing a public API; events refresh only when the HTML does (watch full-page caches).', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-cache-minutes"><?php esc_html_e( 'Cache lifetime', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-cache-minutes" type="number" min="1" max="120" class="small-text" name="<?php echo esc_attr( $opt ); ?>[cache_minutes]" value="<?php echo esc_attr( (string) (int) $s['cache_minutes'] ); ?>" />
											<span class="mwst-hint"><?php esc_html_e( 'minutes', 'mw-sales-toast' ); ?></span>
											<p class="description">
												<?php
												printf(
													/* translators: %s: current cache expiry status, e.g. "Expires in 3 minutes" */
													esc_html__( 'How long sales events stay cached before a rebuild is needed. %s', 'mw-sales-toast' ),
													esc_html( $status['ttl'] )
												);
												?>
											</p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-cron-minutes"><?php esc_html_e( 'Cron interval', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-cron-minutes" type="number" min="1" max="120" class="small-text" name="<?php echo esc_attr( $opt ); ?>[cron_minutes]" value="<?php echo esc_attr( (string) (int) $s['cron_minutes'] ); ?>" />
											<span class="mwst-hint"><?php esc_html_e( 'minutes', 'mw-sales-toast' ); ?></span>
											<p class="description">
												<?php
												printf(
													/* translators: %s: next cron run status, e.g. "Next run in 3 minutes" */
													esc_html__( 'How often WP-Cron rebuilds the sales cache in the background. %s', 'mw-sales-toast' ),
													esc_html( $status['cron'] )
												);
												?>
											</p>
										</div>
									</div>
								</div>
							</div>

							<div class="mwst-card">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Where toasts appear', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Control placement and which pages can show notifications.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label">
											<?php esc_html_e( 'Position', 'mw-sales-toast' ); ?>:
											<span id="mwst-pos-caption" class="mwst-pos-label"></span>
										</div>
										<div class="mwst-field__control">
											<div class="mwst-position-row">
												<div class="mwst-position-row__picker">
													<div class="mwst-positions" role="radiogroup" aria-label="<?php esc_attr_e( 'Toast position', 'mw-sales-toast' ); ?>">
														<?php
														$positions = array(
															'top-left'     => __( 'Top left', 'mw-sales-toast' ),
															'top-right'    => __( 'Top right', 'mw-sales-toast' ),
															'bottom-left'  => __( 'Bottom left', 'mw-sales-toast' ),
															'bottom-right' => __( 'Bottom right', 'mw-sales-toast' ),
														);
														foreach ( $positions as $value => $label ) :
															?>
															<label class="mwst-positions__opt" data-pos="<?php echo esc_attr( $value ); ?>" title="<?php echo esc_attr( $label ); ?>">
																<input
																	type="radio"
																	name="<?php echo esc_attr( $opt ); ?>[position]"
																	value="<?php echo esc_attr( $value ); ?>"
																	<?php checked( $s['position'], $value ); ?>
																/>
																<span class="mwst-positions__frame">
																	<span class="mwst-positions__dot"></span>
																</span>
																<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
															</label>
														<?php endforeach; ?>
													</div>
												</div>
												<div class="mwst-position-row__offsets">
													<p class="mwst-position-row__title"><?php esc_html_e( 'Edge distance', 'mw-sales-toast' ); ?></p>
													<div class="mwst-inline-nums">
														<label>
															<?php esc_html_e( 'Horizontal', 'mw-sales-toast' ); ?>
															<span class="mwst-offset-input">
																<input type="number" min="0" max="80" class="small-text mwst-design-input" data-design="offset_x" id="mwst-offset-x" name="<?php echo esc_attr( $opt ); ?>[offset_x]" value="<?php echo esc_attr( (string) (int) $s['offset_x'] ); ?>" />
																<span class="mwst-hint">px</span>
															</span>
														</label>
														<label>
															<?php esc_html_e( 'Vertical', 'mw-sales-toast' ); ?>
															<span class="mwst-offset-input">
																<input type="number" min="0" max="80" class="small-text mwst-design-input" data-design="offset_y" id="mwst-offset-y" name="<?php echo esc_attr( $opt ); ?>[offset_y]" value="<?php echo esc_attr( (string) (int) $s['offset_y'] ); ?>" />
																<span class="mwst-hint">px</span>
															</span>
														</label>
													</div>
													<p class="description"><?php esc_html_e( 'Gap from the screen edges (0–80px).', 'mw-sales-toast' ); ?></p>
												</div>
											</div>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-show-on"><?php esc_html_e( 'Show on', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<select id="mwst-show-on" name="<?php echo esc_attr( $opt ); ?>[show_on]">
												<option value="all" <?php selected( $s['show_on'], 'all' ); ?>><?php esc_html_e( 'Entire site', 'mw-sales-toast' ); ?></option>
												<option value="shop" <?php selected( $s['show_on'], 'shop' ); ?> <?php disabled( empty( $status['wc_active'] ) ); ?>><?php esc_html_e( 'Shop, categories & products', 'mw-sales-toast' ); ?></option>
												<option value="products" <?php selected( $s['show_on'], 'products' ); ?> <?php disabled( empty( $status['wc_active'] ) ); ?>><?php esc_html_e( 'Product pages only', 'mw-sales-toast' ); ?></option>
												<option value="home" <?php selected( $s['show_on'], 'home' ); ?>><?php esc_html_e( 'Homepage only', 'mw-sales-toast' ); ?></option>
											</select>
											<p class="description"><?php esc_html_e( 'Shop options need WooCommerce. Homepage uses your site’s front page setting.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field" id="mwst-exclude-home-field" data-show-when-not="home">
										<div class="mwst-field__label"><?php esc_html_e( 'Homepage', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'exclude_home', $s, 'mwst-exclude-home', __( 'Do not show on the homepage', 'mw-sales-toast' ) ); ?>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Cart & checkout', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'hide_cart_checkout', $s, 'mwst-hide-cart', __( 'Do not show on cart or checkout', 'mw-sales-toast' ) ); ?>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Thank-you page', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'hide_thankyou', $s, 'mwst-hide-thankyou', __( 'Do not show on the order received (thank-you) page', 'mw-sales-toast' ) ); ?>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'My Account', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'hide_account', $s, 'mwst-hide-account', __( 'Do not show on WooCommerce My Account pages', 'mw-sales-toast' ) ); ?>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Visitors', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'guests_only', $s, 'mwst-guests-only', __( 'Show only to logged-out visitors (hide for logged-in users)', 'mw-sales-toast' ) ); ?>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Mobile', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'disable_mobile', $s, 'mwst-disable-mobile', __( 'Hide toasts on small viewports', 'mw-sales-toast' ) ); ?>
										</div>
									</div>
									<div class="mwst-field" id="mwst-mobile-breakpoint-field">
										<div class="mwst-field__label"><label for="mwst-mobile-breakpoint"><?php esc_html_e( 'Mobile breakpoint', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-mobile-breakpoint" type="number" min="320" max="1200" name="<?php echo esc_attr( $opt ); ?>[mobile_breakpoint]" value="<?php echo esc_attr( (string) (int) ( $s['mobile_breakpoint'] ?? 768 ) ); ?>" class="small-text" />
											<span class="mwst-hint">px</span>
											<p class="description"><?php esc_html_e( 'Hide when the viewport is narrower than this width (used when Mobile is on). Default 768.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="mwst-card mwst-card--pro<?php echo $is_pro ? '' : ' is-locked'; ?>" id="mwst-targeting-pro">
								<div class="mwst-card__head">
									<h2>
										<?php esc_html_e( 'Advanced targeting', 'mw-sales-toast' ); ?>
										<span class="mwst-tab-pro"><?php esc_html_e( 'Pro', 'mw-sales-toast' ); ?></span>
									</h2>
									<p><?php esc_html_e( 'URL rules, product/category filters, PDP matching, and role exclusions.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<?php if ( ! $is_pro ) : ?>
										<div class="mwst-pro-lock" role="status">
											<span class="mwst-plan-pill mwst-plan-pill--pro"><?php esc_html_e( 'Pro', 'mw-sales-toast' ); ?></span>
											<p>
												<?php
												printf(
													/* translators: %s: Account tab link */
													esc_html__( 'Unlock advanced targeting with Pro. Activate a license under %s.', 'mw-sales-toast' ),
													self::tab_link( 'account', __( 'Account', 'mw-sales-toast' ) )
												);
												?>
											</p>
										</div>
									<?php endif; ?>

									<fieldset class="mwst-pro-fields" <?php disabled( ! $is_pro ); ?>>
										<div class="mwst-field">
											<div class="mwst-field__label"><label for="mwst-url-include"><?php esc_html_e( 'Include URLs', 'mw-sales-toast' ); ?></label></div>
											<div class="mwst-field__control">
												<textarea id="mwst-url-include" name="<?php echo esc_attr( $opt ); ?>[url_include]" rows="4" class="large-text code" <?php disabled( ! $is_pro ); ?> placeholder="<?php esc_attr_e( "/sale/*\n/collections/summer", 'mw-sales-toast' ); ?>"><?php echo esc_textarea( (string) ( $s['url_include'] ?? '' ) ); ?></textarea>
												<p class="description"><?php esc_html_e( 'One path per line. Use * as a wildcard. Leave empty to allow all (after other rules).', 'mw-sales-toast' ); ?></p>
												<?php if ( $is_pro ) : ?>
													<p class="mwst-path-actions">
														<button type="button" class="button button-small mwst-path-example" data-target="mwst-url-include" data-path="/sale/*"><?php esc_html_e( 'Add /sale/*', 'mw-sales-toast' ); ?></button>
													</p>
												<?php endif; ?>
											</div>
										</div>
										<div class="mwst-field">
											<div class="mwst-field__label"><label for="mwst-url-exclude"><?php esc_html_e( 'Exclude URLs', 'mw-sales-toast' ); ?></label></div>
											<div class="mwst-field__control">
												<textarea id="mwst-url-exclude" name="<?php echo esc_attr( $opt ); ?>[url_exclude]" rows="4" class="large-text code" <?php disabled( ! $is_pro ); ?> placeholder="<?php esc_attr_e( "/blog/*\n/about", 'mw-sales-toast' ); ?>"><?php echo esc_textarea( (string) ( $s['url_exclude'] ?? '' ) ); ?></textarea>
												<p class="description"><?php esc_html_e( 'One path per line. Matching pages never show toasts.', 'mw-sales-toast' ); ?></p>
												<?php if ( $is_pro ) : ?>
													<p class="mwst-path-actions">
														<button type="button" class="button button-small mwst-path-example" data-target="mwst-url-exclude" data-path="/blog/*"><?php esc_html_e( 'Add /blog/*', 'mw-sales-toast' ); ?></button>
													</p>
												<?php endif; ?>
											</div>
										</div>

										<?php
										$inc_products = array_map( 'absint', (array) ( $s['include_products'] ?? array() ) );
										$exc_products = array_map( 'absint', (array) ( $s['exclude_products'] ?? array() ) );
										$inc_cats     = array_map( 'absint', (array) ( $s['include_categories'] ?? array() ) );
										$exc_cats     = array_map( 'absint', (array) ( $s['exclude_categories'] ?? array() ) );
										?>

										<div class="mwst-field">
											<div class="mwst-field__label"><label for="mwst-include-products"><?php esc_html_e( 'Include products', 'mw-sales-toast' ); ?></label></div>
											<div class="mwst-field__control">
												<?php self::render_product_picker( $opt, 'include_products', 'mwst-include-products', $inc_products, $is_pro ); ?>
												<p class="description"><?php esc_html_e( 'Only sales of these products appear in toasts. Combined with Include categories as a union (product listed or in a listed category).', 'mw-sales-toast' ); ?></p>
											</div>
										</div>
										<div class="mwst-field">
											<div class="mwst-field__label"><label for="mwst-exclude-products"><?php esc_html_e( 'Exclude products', 'mw-sales-toast' ); ?></label></div>
											<div class="mwst-field__control">
												<?php self::render_product_picker( $opt, 'exclude_products', 'mwst-exclude-products', $exc_products, $is_pro ); ?>
												<p class="description"><?php esc_html_e( 'Never toast these products, and hide toasts on their product pages.', 'mw-sales-toast' ); ?></p>
											</div>
										</div>
										<div class="mwst-field">
											<div class="mwst-field__label"><label for="mwst-include-categories"><?php esc_html_e( 'Include categories', 'mw-sales-toast' ); ?></label></div>
											<div class="mwst-field__control">
												<?php self::render_category_picker( $opt, 'include_categories', 'mwst-include-categories', $inc_cats, $is_pro ); ?>
												<p class="description"><?php esc_html_e( 'Only sales of products in these categories (including child categories). Combined with Include products as a union.', 'mw-sales-toast' ); ?></p>
											</div>
										</div>
										<div class="mwst-field">
											<div class="mwst-field__label"><label for="mwst-exclude-categories"><?php esc_html_e( 'Exclude categories', 'mw-sales-toast' ); ?></label></div>
											<div class="mwst-field__control">
												<?php self::render_category_picker( $opt, 'exclude_categories', 'mwst-exclude-categories', $exc_cats, $is_pro ); ?>
												<p class="description"><?php esc_html_e( 'Never toast products in these categories, and hide toasts on those category/product pages.', 'mw-sales-toast' ); ?></p>
											</div>
										</div>

										<div class="mwst-field">
											<div class="mwst-field__label"><?php esc_html_e( 'Product page match', 'mw-sales-toast' ); ?></div>
											<div class="mwst-field__control">
												<?php
												if ( $is_pro ) {
													self::toggle( $opt, 'match_product_page', $s, 'mwst-match-product', __( 'On product pages, only show toasts for that product', 'mw-sales-toast' ) );
												} else {
													?>
													<label class="mwst-toggle is-disabled">
														<input type="checkbox" disabled <?php checked( ! empty( $s['match_product_page'] ) ); ?> />
														<span class="mwst-toggle__track" aria-hidden="true"></span>
														<span class="mwst-toggle__text"><?php esc_html_e( 'On product pages, only show toasts for that product', 'mw-sales-toast' ); ?></span>
													</label>
													<?php
												}
												?>
												<p class="description"><?php esc_html_e( 'Stronger social proof on the product the visitor is viewing.', 'mw-sales-toast' ); ?></p>
											</div>
										</div>

										<div class="mwst-field">
											<div class="mwst-field__label"><?php esc_html_e( 'Hide for roles', 'mw-sales-toast' ); ?></div>
											<div class="mwst-field__control">
												<?php
												$hide_roles = array_map( 'strval', (array) ( $s['hide_roles'] ?? array() ) );
												$wp_roles   = wp_roles();
												$all_roles  = $wp_roles ? $wp_roles->roles : array();
												?>
												<div class="mwst-role-list" role="group" aria-label="<?php esc_attr_e( 'Roles to hide toasts from', 'mw-sales-toast' ); ?>">
													<?php foreach ( $all_roles as $role_key => $role_obj ) : ?>
														<label class="mwst-role-list__item">
															<input
																type="checkbox"
																name="<?php echo esc_attr( $opt ); ?>[hide_roles][]"
																value="<?php echo esc_attr( $role_key ); ?>"
																<?php checked( in_array( $role_key, $hide_roles, true ) ); ?>
																<?php disabled( ! $is_pro ); ?>
															/>
															<span><?php echo esc_html( translate_user_role( $role_obj['name'] ) ); ?></span>
														</label>
													<?php endforeach; ?>
												</div>
												<p class="description"><?php esc_html_e( 'Logged-in users with any selected role will not see toasts. Useful for admins and shop managers.', 'mw-sales-toast' ); ?></p>
											</div>
										</div>
									</fieldset>
								</div>
							</div>

							<div class="mwst-card">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Sound', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Optional audio cue when a toast appears.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Pop sound', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'sound_enabled', $s, 'mwst-sound-enabled', __( 'Play a short sine-wave pop when a toast appears', 'mw-sales-toast' ) ); ?>
											<p class="description" style="margin-top:8px;">
												<button type="button" class="button" id="mwst-test-sound"><?php esc_html_e( 'Play sample', 'mw-sales-toast' ); ?></button>
											</p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Message & privacy -->
						<div class="mwst-panel<?php echo 'message' === $current_tab ? ' is-active' : ''; ?>" id="mwst-panel-message" role="tabpanel">
							<div class="mwst-card">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Message', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Template for the toast line. Preview updates live in the sidebar.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-template"><?php esc_html_e( 'Template', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-template" type="text" class="large-text" name="<?php echo esc_attr( $opt ); ?>[message_template]" value="<?php echo esc_attr( $s['message_template'] ); ?>" />
											<div class="mwst-tokens" aria-label="<?php esc_attr_e( 'Insert placeholder', 'mw-sales-toast' ); ?>">
												<button type="button" class="mwst-token" data-token="{name}">{name}</button>
												<button type="button" class="mwst-token" data-token="{city}">{city}</button>
												<button type="button" class="mwst-token" data-token="{product}">{product}</button>
												<button type="button" class="mwst-token" data-token="{stock}">{stock}</button>
												<button type="button" class="mwst-token" data-token="{stock_label}">{stock_label}</button>
											</div>
											<p class="description"><?php esc_html_e( 'If you omit stock tokens, a stock phrase still appears next to the time when stock display is on (real orders only).', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-fallback"><?php esc_html_e( 'Fallback name', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-fallback" type="text" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[fallback_name]" value="<?php echo esc_attr( $s['fallback_name'] ); ?>" />
											<p class="description"><?php esc_html_e( 'Used when a name is missing, or when “Hide names” is on.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-stock-display"><?php esc_html_e( 'Stock display', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<select id="mwst-stock-display" name="<?php echo esc_attr( $opt ); ?>[stock_display]">
												<option value="off" <?php selected( $s['stock_display'], 'off' ); ?>><?php esc_html_e( 'Off', 'mw-sales-toast' ); ?></option>
												<option value="soft" <?php selected( $s['stock_display'], 'soft' ); ?>><?php esc_html_e( 'Soft phrases — last one / only a few / low stock', 'mw-sales-toast' ); ?></option>
												<option value="exact_low" <?php selected( $s['stock_display'], 'exact_low' ); ?>><?php esc_html_e( 'Exact when low — “only 3 left”', 'mw-sales-toast' ); ?></option>
											</select>
											<p class="description"><?php esc_html_e( 'Real orders only. Demo toasts never show stock. Requires WooCommerce stock management on the product.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field" id="mwst-stock-threshold-field"<?php echo 'off' === ( $s['stock_display'] ?? 'off' ) ? ' data-disabled="1"' : ''; ?>>
										<div class="mwst-field__label"><label for="mwst-stock-threshold"><?php esc_html_e( 'Show stock when ≤', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input
												id="mwst-stock-threshold"
												type="number"
												min="1"
												max="50"
												class="small-text"
												name="<?php echo esc_attr( $opt ); ?>[stock_threshold]"
												value="<?php echo esc_attr( (string) (int) $s['stock_threshold'] ); ?>"
												<?php disabled( 'off' === ( $s['stock_display'] ?? 'off' ) ); ?>
											/>
											<span class="mwst-hint"><?php esc_html_e( 'units', 'mw-sales-toast' ); ?></span>
											<p class="description"><?php esc_html_e( 'Hide stock above this quantity so high inventory does not look odd.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="mwst-card">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Privacy', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Keep customer data minimal and consent-aware.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Hide names', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'hide_names', $s, 'mwst-hide-names', __( 'Always use the fallback name instead of the customer first name', 'mw-sales-toast' ) ); ?>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Checkout consent', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'require_consent', $s, 'mwst-require-consent', __( 'Hide orders where the customer declined the checkout consent checkbox (recommended)', 'mw-sales-toast' ) ); ?>
											<p class="description"><?php esc_html_e( 'Admin-created or older orders without a consent choice can still appear. Customers who unchecked the box never appear.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="mwst-card">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Session limits', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Reduce fatigue so toasts stay helpful, not noisy.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-mute"><?php esc_html_e( 'Mute after dismiss', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-mute" type="number" min="0" max="720" class="small-text" name="<?php echo esc_attr( $opt ); ?>[mute_hours]" value="<?php echo esc_attr( (string) $s['mute_hours'] ); ?>" />
											<span class="mwst-hint"><?php esc_html_e( 'hours', 'mw-sales-toast' ); ?></span>
											<p class="description"><?php esc_html_e( '0 = only hide the current toast; dismiss does not mute future ones.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-session"><?php esc_html_e( 'Max per session', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-session" type="number" min="1" max="100" class="small-text" name="<?php echo esc_attr( $opt ); ?>[max_per_session]" value="<?php echo esc_attr( (string) $s['max_per_session'] ); ?>" />
											<p class="description"><?php esc_html_e( 'Stop showing toasts after this many in one browser visit. Resets when the visitor starts a new session.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Reduced motion', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'respect_reduced_motion', $s, 'mwst-reduced-motion', __( 'Disable toasts when the visitor prefers reduced motion', 'mw-sales-toast' ) ); ?>
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Design -->
						<div class="mwst-panel<?php echo 'design' === $current_tab ? ' is-active' : ''; ?>" id="mwst-panel-design" role="tabpanel">
							<div class="mwst-card">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Colors & shape', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Quick controls map to CSS variables. Preview updates live — save to apply on the storefront.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<?php
									$elementor_active = self::is_elementor_active();
									$elementor_theme  = $elementor_active ? self::get_elementor_theme() : null;
									$design_preset    = isset( $s['design_preset'] ) ? (string) $s['design_preset'] : 'midnight';
									if ( ! isset( self::design_presets()[ $design_preset ] ) ) {
										$design_preset = 'midnight';
									}
									?>
									<div class="mwst-field" id="mwst-design-presets-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Theme', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<div class="mwst-presets mwst-design-presets" role="radiogroup" aria-label="<?php esc_attr_e( 'Design theme', 'mw-sales-toast' ); ?>">
												<?php foreach ( self::design_presets() as $key => $preset ) : ?>
													<label class="mwst-preset mwst-design-preset<?php echo ( $design_preset === $key ) ? ' is-active' : ''; ?>">
														<input
															type="radio"
															name="<?php echo esc_attr( $opt ); ?>[design_preset]"
															value="<?php echo esc_attr( $key ); ?>"
															class="mwst-design-preset-input"
															<?php checked( $design_preset, $key ); ?>
															<?php
															foreach ( array(
																'style_bg',
																'style_bg_opacity',
																'style_text',
																'style_body',
																'style_accent',
																'style_meta',
																'style_close_hover',
																'style_border',
																'style_border_opacity',
																'style_radius',
															) as $data_key ) :
																if ( ! array_key_exists( $data_key, $preset ) ) {
																	continue;
																}
																?>
																data-<?php echo esc_attr( str_replace( '_', '-', $data_key ) ); ?>="<?php echo esc_attr( (string) $preset[ $data_key ] ); ?>"
															<?php endforeach; ?>
														/>
														<span class="mwst-preset__label"><?php echo esc_html( $preset['label'] ); ?></span>
														<span class="mwst-preset__desc"><?php echo esc_html( $preset['desc'] ); ?></span>
														<?php if ( 'custom' !== $key ) : ?>
															<span class="mwst-theme-swatch" aria-hidden="true">
																<span style="background:<?php echo esc_attr( $preset['style_bg'] ); ?>"></span>
																<span style="background:<?php echo esc_attr( $preset['style_text'] ); ?>"></span>
																<span style="background:<?php echo esc_attr( $preset['style_accent'] ); ?>"></span>
																<span style="background:<?php echo esc_attr( $preset['style_body'] ); ?>"></span>
															</span>
														<?php endif; ?>
													</label>
												<?php endforeach; ?>
											</div>
											<p class="description"><?php esc_html_e( 'Pick a coherent palette, then fine-tune below. Editing colors switches to Custom.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field" id="mwst-elementor-theme-field"<?php echo $elementor_active ? '' : ' data-disabled="1"'; ?>>
										<div class="mwst-field__label"><?php esc_html_e( 'Elementor', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php if ( $elementor_active ) : ?>
												<?php self::toggle( $opt, 'use_elementor_theme', $s, 'mwst-use-elementor-theme', __( 'Use Elementor colors & font', 'mw-sales-toast' ) ); ?>
												<p class="description">
													<?php
													echo $elementor_theme
														? esc_html__( 'Maps Site Kit Text → message, Accent → product link, Secondary → background/meta/close, and Text font → toast typeface. Opacity and radius still apply.', 'mw-sales-toast' )
														: esc_html__( 'Elementor is active, but no Site Kit colors/fonts were found yet. Open Elementor → Site Settings → Global Colors / Global Fonts, then reload this page.', 'mw-sales-toast' );
													?>
												</p>
											<?php else : ?>
												<label class="mwst-toggle is-disabled">
													<input type="checkbox" disabled <?php checked( ! empty( $s['use_elementor_theme'] ) ); ?> />
													<span class="mwst-toggle__track" aria-hidden="true"></span>
													<span class="mwst-toggle__text"><?php esc_html_e( 'Use Elementor colors & font', 'mw-sales-toast' ); ?></span>
												</label>
												<p class="description"><?php esc_html_e( 'Install and activate Elementor to match toast colors and font to your Site Kit.', 'mw-sales-toast' ); ?></p>
											<?php endif; ?>
										</div>
									</div>
									<div class="mwst-field" id="mwst-custom-colors-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Colors', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<div class="mwst-color-grid">
												<div class="mwst-color-field">
													<span><?php esc_html_e( 'Message', 'mw-sales-toast' ); ?></span>
													<input type="text" class="mwst-design-input mwst-color-picker" data-design="style_body" id="mwst-style-body" name="<?php echo esc_attr( $opt ); ?>[style_body]" value="<?php echo esc_attr( self::picker_value( $s['style_body'], '#d7deea' ) ); ?>" data-default-color="#d7deea" />
												</div>
												<div class="mwst-color-field">
													<span><?php esc_html_e( 'Product accent', 'mw-sales-toast' ); ?></span>
													<input type="text" class="mwst-design-input mwst-color-picker" data-design="style_accent" id="mwst-style-accent" name="<?php echo esc_attr( $opt ); ?>[style_accent]" value="<?php echo esc_attr( self::picker_value( $s['style_accent'], '#e8c872' ) ); ?>" data-default-color="#e8c872" />
												</div>
												<div class="mwst-color-field">
													<span><?php esc_html_e( 'Meta / time / close', 'mw-sales-toast' ); ?></span>
													<input type="text" class="mwst-design-input mwst-color-picker" data-design="style_meta" id="mwst-style-meta" name="<?php echo esc_attr( $opt ); ?>[style_meta]" value="<?php echo esc_attr( self::picker_value( $s['style_meta'], '#a8b2c4' ) ); ?>" data-default-color="#a8b2c4" />
												</div>
												<div class="mwst-color-field">
													<span><?php esc_html_e( '× hover', 'mw-sales-toast' ); ?></span>
													<input type="text" class="mwst-design-input mwst-color-picker" data-design="style_close_hover" id="mwst-style-close-hover" name="<?php echo esc_attr( $opt ); ?>[style_close_hover]" value="<?php echo esc_attr( self::picker_value( isset( $s['style_close_hover'] ) ? $s['style_close_hover'] : '#dc2626', '#dc2626' ) ); ?>" data-default-color="#dc2626" />
												</div>
												<div class="mwst-color-field">
													<span><?php esc_html_e( 'Background', 'mw-sales-toast' ); ?></span>
													<input type="text" class="mwst-design-input mwst-color-picker" data-design="style_bg" id="mwst-style-bg" name="<?php echo esc_attr( $opt ); ?>[style_bg]" value="<?php echo esc_attr( self::picker_value( $s['style_bg'], '#0c1220' ) ); ?>" data-default-color="#0c1220" />
													<label class="mwst-opacity-field" for="mwst-style-bg-opacity">
														<span class="mwst-opacity-field__label"><?php esc_html_e( 'Opacity', 'mw-sales-toast' ); ?></span>
														<input type="range" min="0" max="100" step="1" class="mwst-design-input mwst-opacity-slider" data-design="style_bg_opacity" id="mwst-style-bg-opacity" name="<?php echo esc_attr( $opt ); ?>[style_bg_opacity]" value="<?php echo esc_attr( (string) (int) $s['style_bg_opacity'] ); ?>" />
														<span class="mwst-opacity-field__value" data-opacity-value><?php echo esc_html( (string) (int) $s['style_bg_opacity'] ); ?>%</span>
													</label>
												</div>
												<div class="mwst-color-field">
													<span><?php esc_html_e( 'Border', 'mw-sales-toast' ); ?></span>
													<input type="text" class="mwst-design-input mwst-color-picker" data-design="style_border" id="mwst-style-border" name="<?php echo esc_attr( $opt ); ?>[style_border]" value="<?php echo esc_attr( self::picker_value( $s['style_border'], '#ffffff' ) ); ?>" data-default-color="#ffffff" />
													<label class="mwst-opacity-field" for="mwst-style-border-opacity">
														<span class="mwst-opacity-field__label"><?php esc_html_e( 'Opacity', 'mw-sales-toast' ); ?></span>
														<input type="range" min="0" max="100" step="1" class="mwst-design-input mwst-opacity-slider" data-design="style_border_opacity" id="mwst-style-border-opacity" name="<?php echo esc_attr( $opt ); ?>[style_border_opacity]" value="<?php echo esc_attr( (string) (int) $s['style_border_opacity'] ); ?>" />
														<span class="mwst-opacity-field__value" data-opacity-value><?php echo esc_html( (string) (int) $s['style_border_opacity'] ); ?>%</span>
													</label>
												</div>
																						</div>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Shape', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<div class="mwst-inline-nums">
												<label>
													<?php esc_html_e( 'Corner radius', 'mw-sales-toast' ); ?>
													<input type="number" min="0" max="40" class="mwst-design-input small-text" data-design="style_radius" id="mwst-style-radius" name="<?php echo esc_attr( $opt ); ?>[style_radius]" value="<?php echo esc_attr( (string) $s['style_radius'] ); ?>" />
												</label>
												<label>
													<?php esc_html_e( 'Padding', 'mw-sales-toast' ); ?>
													<input type="number" min="4" max="32" class="mwst-design-input small-text" data-design="style_padding" id="mwst-style-padding" name="<?php echo esc_attr( $opt ); ?>[style_padding]" value="<?php echo esc_attr( (string) (int) ( $s['style_padding'] ?? 12 ) ); ?>" />
												</label>
												<label>
													<?php esc_html_e( 'Max width', 'mw-sales-toast' ); ?>
													<input type="number" min="220" max="560" class="mwst-design-input small-text" data-design="style_max_width" id="mwst-style-max-width" name="<?php echo esc_attr( $opt ); ?>[style_max_width]" value="<?php echo esc_attr( (string) $s['style_max_width'] ); ?>" />
												</label>
											</div>
											<p class="description"><?php esc_html_e( 'Radius 0–40px. Padding 4–32px. Max width 220–560px.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-style-shadow"><?php esc_html_e( 'Shadow', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<?php $shadow = isset( $s['style_shadow'] ) ? (string) $s['style_shadow'] : 'medium'; ?>
											<select id="mwst-style-shadow" class="mwst-design-input" data-design="style_shadow" name="<?php echo esc_attr( $opt ); ?>[style_shadow]">
												<option value="none" <?php selected( $shadow, 'none' ); ?>><?php esc_html_e( 'None', 'mw-sales-toast' ); ?></option>
												<option value="soft" <?php selected( $shadow, 'soft' ); ?>><?php esc_html_e( 'Soft', 'mw-sales-toast' ); ?></option>
												<option value="medium" <?php selected( $shadow, 'medium' ); ?>><?php esc_html_e( 'Medium (default)', 'mw-sales-toast' ); ?></option>
												<option value="strong" <?php selected( $shadow, 'strong' ); ?>><?php esc_html_e( 'Strong', 'mw-sales-toast' ); ?></option>
											</select>
											<p class="description"><?php esc_html_e( 'Drop shadow under the toast. Choose None for a flat look.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Product image', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'show_image', $s, 'mwst-show-image', __( 'Show product thumbnail', 'mw-sales-toast' ) ); ?>
											<?php
											$image_fit = ( 'padded' === ( $s['style_image_fit'] ?? '' ) ) ? 'padded' : 'full';
											$image_on  = ! empty( $s['show_image'] );
											?>
											<div class="mwst-image-fit<?php echo $image_on ? '' : ' is-disabled'; ?>" id="mwst-image-fit"<?php echo $image_on ? '' : ' data-disabled="1"'; ?> style="margin-top:12px;">
												<p class="description" style="margin:0 0 8px;"><?php esc_html_e( 'Thumbnail layout', 'mw-sales-toast' ); ?></p>
												<label class="mwst-inline-radio">
													<input type="radio" name="<?php echo esc_attr( $opt ); ?>[style_image_fit]" value="full" class="mwst-image-fit-input" <?php checked( $image_fit, 'full' ); ?> <?php disabled( ! $image_on ); ?> />
													<?php esc_html_e( 'Full height', 'mw-sales-toast' ); ?>
												</label>
												<label class="mwst-inline-radio">
													<input type="radio" name="<?php echo esc_attr( $opt ); ?>[style_image_fit]" value="padded" class="mwst-image-fit-input" <?php checked( $image_fit, 'padded' ); ?> <?php disabled( ! $image_on ); ?> />
													<?php esc_html_e( 'Same padding as text', 'mw-sales-toast' ); ?>
												</label>
											</div>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Reset', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<button type="button" class="button" id="mwst-reset-design"><?php esc_html_e( 'Reset design to defaults', 'mw-sales-toast' ); ?></button>
										</div>
									</div>
								</div>
							</div>

							<div class="mwst-card mwst-card--pro<?php echo $is_pro ? '' : ' is-locked'; ?>" id="mwst-custom-css-pro">
								<div class="mwst-card__head">
									<h2>
										<?php esc_html_e( 'Custom CSS', 'mw-sales-toast' ); ?>
										<span class="mwst-tab-pro"><?php esc_html_e( 'Pro', 'mw-sales-toast' ); ?></span>
									</h2>
									<p><?php esc_html_e( 'Advanced overrides. Loaded after the base toast styles.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<?php if ( ! $is_pro ) : ?>
										<div class="mwst-pro-lock" role="status">
											<span class="mwst-plan-pill mwst-plan-pill--pro"><?php esc_html_e( 'Pro', 'mw-sales-toast' ); ?></span>
											<p>
												<?php
												printf(
													/* translators: %s: Account tab link */
													esc_html__( 'Unlock custom CSS with Pro. Activate a license under %s.', 'mw-sales-toast' ),
													self::tab_link( 'account', __( 'Account', 'mw-sales-toast' ) )
												);
												?>
											</p>
										</div>
									<?php endif; ?>

									<fieldset class="mwst-pro-fields" <?php disabled( ! $is_pro ); ?>>
									<div class="mwst-field mwst-field--editor">
										<div class="mwst-field__label"><label for="mwst-custom-css"><?php esc_html_e( 'CSS', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<div class="mwst-css-editor">
												<?php
												$custom_css_value = (string) ( $s['custom_css'] ?? '' );
												if ( '' === trim( $custom_css_value ) ) {
													$custom_css_value = self::custom_css_example();
												}
												?>
												<textarea id="mwst-custom-css" name="<?php echo esc_attr( $opt ); ?>[custom_css]" rows="12" class="large-text code mwst-design-input" data-design="custom_css" spellcheck="false" <?php disabled( ! $is_pro ); ?>><?php echo esc_textarea( $custom_css_value ); ?></textarea>
											</div>
											<div class="mwst-css-cheatsheet">
												<p class="description" style="margin-top:10px;margin-bottom:6px;"><strong><?php esc_html_e( 'Structure', 'mw-sales-toast' ); ?></strong></p>
												<p class="description mwst-css-selectors">
													<code>.mw-sales-toast</code>
													<code>.mw-sales-toast__media</code>
													<code>.mw-sales-toast__media img</code>
													<code>.mw-sales-toast__media a</code>
													<code>.mw-sales-toast__media a img</code>
													<code>.mw-sales-toast__body</code>
													<code>.mw-sales-toast__text</code>
													<code>.mw-sales-toast__text a</code>
													<code>.mw-sales-toast__text strong</code>
													<code>.mw-sales-toast__meta</code>
													<code>.mw-sales-toast__close</code>
												</p>
												<p class="description" style="margin-top:10px;margin-bottom:6px;"><strong><?php esc_html_e( 'Layout & state', 'mw-sales-toast' ); ?></strong></p>
												<p class="description mwst-css-selectors">
													<code>.mw-sales-toast--media-full</code>
													<code>.mw-sales-toast--media-padded</code>
													<code>.mw-sales-toast--bottom-left</code>
													<code>.mw-sales-toast--bottom-right</code>
													<code>.mw-sales-toast--top-left</code>
													<code>.mw-sales-toast--top-right</code>
													<code>.mw-sales-toast.is-visible</code>
													<code>.mw-sales-toast.is-leaving</code>
												</p>
												<p class="description" style="margin-top:10px;margin-bottom:6px;"><strong><?php esc_html_e( 'CSS variables', 'mw-sales-toast' ); ?></strong></p>
												<p class="description mwst-css-selectors">
													<code>--mw-st-bg</code>
													<code>--mw-st-color</code>
													<code>--mw-st-body</code>
													<code>--mw-st-accent</code>
													<code>--mw-st-meta</code>
													<code>--mw-st-close-hover</code>
													<code>--mw-st-border</code>
													<code>--mw-st-close-bg</code>
													<code>--mw-st-close-bg-hover</code>
													<code>--mw-st-radius</code>
													<code>--mw-st-media-radius</code>
													<code>--mw-st-media-width</code>
													<code>--mw-st-padding</code>
													<code>--mw-st-max-width</code>
													<code>--mw-st-offset-x</code>
													<code>--mw-st-offset-y</code>
													<code>--mw-st-shadow</code>
													<code>--mw-st-font</code>
												</p>
											</div>
										</div>
									</div>
									</fieldset>
								</div>
							</div>
						</div>

						<!-- Timing & cache -->
						<div class="mwst-panel<?php echo 'timing' === $current_tab ? ' is-active' : ''; ?>" id="mwst-panel-timing" role="tabpanel">
							<div class="mwst-card">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Timing', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Show a toast, then wait a quiet gap before the next one. Pick a preset or fine-tune custom values.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Preset', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<div class="mwst-presets" role="radiogroup" aria-label="<?php esc_attr_e( 'Timing preset', 'mw-sales-toast' ); ?>">
												<?php foreach ( self::timing_presets() as $key => $preset ) : ?>
													<label class="mwst-preset<?php echo ( $s['timing_preset'] === $key ) ? ' is-active' : ''; ?>">
														<input
															type="radio"
															name="<?php echo esc_attr( $opt ); ?>[timing_preset]"
															value="<?php echo esc_attr( $key ); ?>"
															class="mwst-timing-preset"
															<?php checked( $s['timing_preset'], $key ); ?>
															data-delay="<?php echo esc_attr( (string) $preset['delay'] ); ?>"
															data-duration="<?php echo esc_attr( (string) $preset['duration'] ); ?>"
															data-gap="<?php echo esc_attr( (string) $preset['gap'] ); ?>"
														/>
														<span class="mwst-preset__label"><?php echo esc_html( $preset['label'] ); ?></span>
														<span class="mwst-preset__desc"><?php echo esc_html( $preset['desc'] ); ?></span>
														<?php if ( 'custom' !== $key ) : ?>
															<span class="mwst-preset__meta">
																<?php
																echo esc_html(
																	sprintf(
																		/* translators: 1: delay seconds, 2: visible seconds, 3: gap seconds */
																		__( '%1$ds delay · %2$ds visible · %3$ds gap', 'mw-sales-toast' ),
																		$preset['delay'],
																		$preset['duration'],
																		$preset['gap']
																	)
																);
																?>
															</span>
														<?php endif; ?>
													</label>
												<?php endforeach; ?>
											</div>
										</div>
									</div>
									<div class="mwst-field mwst-timing-custom" id="mwst-timing-custom" <?php echo 'custom' === $s['timing_preset'] ? '' : 'hidden'; ?>>
										<div class="mwst-field__label"><?php esc_html_e( 'Custom timing', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<div class="mwst-inline-nums">
												<label>
													<?php esc_html_e( 'First delay', 'mw-sales-toast' ); ?>
													<input type="number" min="1" max="120" id="mwst-delay" name="<?php echo esc_attr( $opt ); ?>[delay]" value="<?php echo esc_attr( (string) $s['delay'] ); ?>" class="small-text mwst-timing-input" />
												</label>
												<label>
													<?php esc_html_e( 'Visible for', 'mw-sales-toast' ); ?>
													<input type="number" min="2" max="60" id="mwst-duration" name="<?php echo esc_attr( $opt ); ?>[duration]" value="<?php echo esc_attr( (string) $s['duration'] ); ?>" class="small-text mwst-timing-input" />
												</label>
												<label>
													<?php esc_html_e( 'Gap after hide', 'mw-sales-toast' ); ?>
													<input type="number" min="1" max="300" id="mwst-gap" name="<?php echo esc_attr( $opt ); ?>[gap]" value="<?php echo esc_attr( (string) $s['gap'] ); ?>" class="small-text mwst-timing-input" />
												</label>
											</div>
											<p class="description"><?php esc_html_e( 'Seconds. Gap is the quiet time after a toast hides before the next one appears.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-jitter"><?php esc_html_e( 'Jitter', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-jitter" type="number" min="0" max="50" class="small-text" name="<?php echo esc_attr( $opt ); ?>[jitter]" value="<?php echo esc_attr( (string) (int) $s['jitter'] ); ?>" />
											<span class="mwst-hint">%</span>
											<p class="description"><?php esc_html_e( 'Randomizes first delay and gap by up to this percent so the rhythm feels less robotic. 0 = exact timing. Visible duration is never jittered.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-when-style"><?php esc_html_e( 'Time label', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<select id="mwst-when-style" name="<?php echo esc_attr( $opt ); ?>[when_style]">
												<option value="natural" <?php selected( $s['when_style'], 'natural' ); ?>><?php esc_html_e( 'Natural — just now, a few minutes ago…', 'mw-sales-toast' ); ?></option>
												<option value="exact" <?php selected( $s['when_style'], 'exact' ); ?>><?php esc_html_e( 'Exact — 2 minutes ago, 3 days ago…', 'mw-sales-toast' ); ?></option>
											</select>
											<p class="description"><?php esc_html_e( 'How real-order timestamps appear under the toast. Demo times are always shown exactly as written.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-max"><?php esc_html_e( 'Max events shown', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-max" type="number" min="1" max="30" name="<?php echo esc_attr( $opt ); ?>[max_events]" value="<?php echo esc_attr( (string) $s['max_events'] ); ?>" class="small-text" />
											<p class="description"><?php esc_html_e( 'Cap on events returned and cycled in the visitor session.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
								</div>
							</div>

							<div class="mwst-card">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Order cache', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Real orders are rebuilt on a schedule — not on every page view.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-cached"><?php esc_html_e( 'Max cached orders', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-cached" type="number" min="5" max="100" name="<?php echo esc_attr( $opt ); ?>[max_cached_orders]" value="<?php echo esc_attr( (string) $s['max_cached_orders'] ); ?>" class="small-text" />
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-lookback"><?php esc_html_e( 'Lookback window', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<input id="mwst-lookback" type="number" min="1" max="365" name="<?php echo esc_attr( $opt ); ?>[lookback_days]" value="<?php echo esc_attr( (string) $s['lookback_days'] ); ?>" class="small-text" />
											<span class="mwst-hint"><?php esc_html_e( 'days', 'mw-sales-toast' ); ?></span>
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Demo -->
						<div class="mwst-panel<?php echo 'demo' === $current_tab ? ' is-active' : ''; ?>" id="mwst-panel-demo" role="tabpanel">
							<div class="mwst-card">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Simulated social proof', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Useful for low-traffic stores or staging.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-people"><?php esc_html_e( 'Demo people', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<textarea id="mwst-people" name="<?php echo esc_attr( $opt ); ?>[demo_people]" rows="8" class="large-text code"><?php echo esc_textarea( $s['demo_people'] ); ?></textarea>
											<p class="description"><?php esc_html_e( 'One per line: Name, City', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
									<div class="mwst-field">
										<div class="mwst-field__label"><label for="mwst-whens"><?php esc_html_e( 'Demo times', 'mw-sales-toast' ); ?></label></div>
										<div class="mwst-field__control">
											<textarea id="mwst-whens" name="<?php echo esc_attr( $opt ); ?>[demo_whens]" rows="5" class="large-text code"><?php echo esc_textarea( $s['demo_whens'] ); ?></textarea>
											<p class="description"><?php esc_html_e( 'One per line, shown as-is (e.g. “just now”). No automatic “ago” is added.', 'mw-sales-toast' ); ?></p>
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Statistics (Pro toast performance) -->
						<?php
						$stats_payload = ( $is_pro && class_exists( 'MW_Sales_Toast_Analytics' ) )
							? MW_Sales_Toast_Analytics::dashboard_payload()
							: array();
						$stats_seed = ! empty( $stats_payload['7'] ) ? $stats_payload['7'] : array(
							'impressions' => 0,
							'clicks'      => 0,
							'ctr'         => 0,
							'dismissed'   => 0,
							'muted'       => 0,
							'atc'         => 0,
							'purchases'   => 0,
							'delta'       => array(),
							'products'    => array(),
							'hasData'     => false,
							'attrWindow'  => 30,
						);
						?>
						<div class="mwst-panel<?php echo 'statistics' === $current_tab ? ' is-active' : ''; ?>" id="mwst-panel-statistics" role="tabpanel" data-mwst-analytics="<?php echo $is_pro ? '1' : '0'; ?>">
							<div class="mwst-stats-toolbar">
								<div class="mwst-stats-toolbar__intro">
									<span class="mwst-plan-pill mwst-plan-pill--pro"><?php esc_html_e( 'Pro', 'mw-sales-toast' ); ?></span>
									<div class="mwst-stats-toolbar__copy">
										<strong><?php esc_html_e( 'Toast performance', 'mw-sales-toast' ); ?></strong>
										<p><?php esc_html_e( 'Aggregate engagement only — no names, emails, or IPs.', 'mw-sales-toast' ); ?></p>
									</div>
								</div>
								<div class="mwst-stats-range" role="group" aria-label="<?php esc_attr_e( 'Date range', 'mw-sales-toast' ); ?>">
									<button type="button" class="mwst-stats-range__btn is-active" data-range="7" aria-pressed="true"><?php esc_html_e( '7 days', 'mw-sales-toast' ); ?></button>
									<button type="button" class="mwst-stats-range__btn" data-range="30" aria-pressed="false"><?php esc_html_e( '30 days', 'mw-sales-toast' ); ?></button>
									<button type="button" class="mwst-stats-range__btn" data-range="90" aria-pressed="false"><?php esc_html_e( '90 days', 'mw-sales-toast' ); ?></button>
								</div>
							</div>

							<?php if ( ! $is_pro ) : ?>
								<div class="mwst-notice mwst-notice--pro" role="status">
									<span class="mwst-notice__icon" aria-hidden="true">★</span>
									<div class="mwst-notice__body">
										<strong><?php esc_html_e( 'Pro analytics', 'mw-sales-toast' ); ?></strong>
										<p>
											<?php
											printf(
												/* translators: %s: Account tab link */
												esc_html__( 'Unlock toast performance tracking with Pro. Activate a license under %s.', 'mw-sales-toast' ),
												self::tab_link( 'account', __( 'Account', 'mw-sales-toast' ) )
											);
											?>
										</p>
									</div>
								</div>
							<?php elseif ( empty( $stats_seed['hasData'] ) ) : ?>
								<div class="mwst-notice mwst-notice--warn" role="status">
									<span class="mwst-notice__icon" aria-hidden="true">!</span>
									<div class="mwst-notice__body">
										<strong><?php esc_html_e( 'Waiting for data', 'mw-sales-toast' ); ?></strong>
										<p><?php esc_html_e( 'Numbers appear after visitors see toasts on the storefront. Keep toasts enabled and browse the shop in a private window to generate the first events.', 'mw-sales-toast' ); ?></p>
									</div>
								</div>
							<?php endif; ?>

							<div class="mwst-card" id="mwst-stats-overview">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Overview', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Core funnel for toast visibility and product link engagement.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-stats-kpis" aria-label="<?php esc_attr_e( 'Key metrics', 'mw-sales-toast' ); ?>">
										<div class="mwst-stats-kpi">
											<span class="mwst-stats-kpi__label"><?php esc_html_e( 'Impressions', 'mw-sales-toast' ); ?></span>
											<span class="mwst-stats-kpi__value" data-stat="impressions"><?php echo esc_html( number_format_i18n( (int) $stats_seed['impressions'] ) ); ?></span>
											<span class="mwst-stats-kpi__delta" data-stat-delta="impressions"><?php echo esc_html( $stats_seed['delta']['impressions']['label'] ?? __( 'vs prior', 'mw-sales-toast' ) ); ?></span>
										</div>
										<div class="mwst-stats-kpi">
											<span class="mwst-stats-kpi__label"><?php esc_html_e( 'Clicks', 'mw-sales-toast' ); ?></span>
											<span class="mwst-stats-kpi__value" data-stat="clicks"><?php echo esc_html( number_format_i18n( (int) $stats_seed['clicks'] ) ); ?></span>
											<span class="mwst-stats-kpi__delta" data-stat-delta="clicks"><?php echo esc_html( $stats_seed['delta']['clicks']['label'] ?? __( 'vs prior', 'mw-sales-toast' ) ); ?></span>
										</div>
										<div class="mwst-stats-kpi">
											<span class="mwst-stats-kpi__label"><?php esc_html_e( 'CTR', 'mw-sales-toast' ); ?></span>
											<span class="mwst-stats-kpi__value" data-stat="ctr"><?php echo esc_html( (string) $stats_seed['ctr'] ); ?>%</span>
											<span class="mwst-stats-kpi__delta" data-stat-delta="ctr"><?php echo esc_html( $stats_seed['delta']['ctr']['label'] ?? __( 'vs prior', 'mw-sales-toast' ) ); ?></span>
										</div>
										<div class="mwst-stats-kpi">
											<span class="mwst-stats-kpi__label"><?php esc_html_e( 'Attributed carts', 'mw-sales-toast' ); ?></span>
											<span class="mwst-stats-kpi__value" data-stat="atc"><?php echo esc_html( number_format_i18n( (int) $stats_seed['atc'] ) ); ?></span>
											<span class="mwst-stats-kpi__delta" data-stat-delta="atc"><?php echo esc_html( $stats_seed['delta']['atc']['label'] ?? __( 'vs prior', 'mw-sales-toast' ) ); ?></span>
										</div>
									</div>
								</div>
							</div>

							<div class="mwst-card" id="mwst-stats-engagement">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Engagement breakdown', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'How visitors interact after a toast appears.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-stats-bars" aria-hidden="true">
										<div class="mwst-stats-bar">
											<div class="mwst-stats-bar__meta">
												<span><?php esc_html_e( 'Shown', 'mw-sales-toast' ); ?></span>
												<span data-stat="impressions"><?php echo esc_html( number_format_i18n( (int) $stats_seed['impressions'] ) ); ?></span>
											</div>
											<div class="mwst-stats-bar__track"><span class="mwst-stats-bar__fill" data-stat-bar="impressions" style="width:100%"></span></div>
										</div>
										<div class="mwst-stats-bar">
											<div class="mwst-stats-bar__meta">
												<span><?php esc_html_e( 'Dismissed', 'mw-sales-toast' ); ?></span>
												<span data-stat="dismissed"><?php echo esc_html( number_format_i18n( (int) $stats_seed['dismissed'] ) ); ?></span>
											</div>
											<div class="mwst-stats-bar__track"><span class="mwst-stats-bar__fill mwst-stats-bar__fill--muted" data-stat-bar="dismissed" style="width:0%"></span></div>
										</div>
										<div class="mwst-stats-bar">
											<div class="mwst-stats-bar__meta">
												<span><?php esc_html_e( 'Muted (after dismiss)', 'mw-sales-toast' ); ?></span>
												<span data-stat="muted"><?php echo esc_html( number_format_i18n( (int) $stats_seed['muted'] ) ); ?></span>
											</div>
											<div class="mwst-stats-bar__track"><span class="mwst-stats-bar__fill mwst-stats-bar__fill--soft" data-stat-bar="muted" style="width:0%"></span></div>
										</div>
										<div class="mwst-stats-bar">
											<div class="mwst-stats-bar__meta">
												<span><?php esc_html_e( 'Product link click-through', 'mw-sales-toast' ); ?></span>
												<span data-stat="clicks"><?php echo esc_html( number_format_i18n( (int) $stats_seed['clicks'] ) ); ?></span>
											</div>
											<div class="mwst-stats-bar__track"><span class="mwst-stats-bar__fill mwst-stats-bar__fill--accent" data-stat-bar="clicks" style="width:0%"></span></div>
										</div>
									</div>
									<ul class="mwst-stats-legend">
										<li><strong><?php esc_html_e( 'Shown', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Toast rendered on the storefront.', 'mw-sales-toast' ); ?></li>
										<li><strong><?php esc_html_e( 'Dismissed', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Visitor closed the toast.', 'mw-sales-toast' ); ?></li>
										<li><strong><?php esc_html_e( 'Muted', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Mute-after-dismiss applied.', 'mw-sales-toast' ); ?></li>
										<li><strong><?php esc_html_e( 'Click-through', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Product (or toast) link opened.', 'mw-sales-toast' ); ?></li>
									</ul>
								</div>
							</div>

							<div class="mwst-card" id="mwst-stats-attribution">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Soft attribution', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Window after a toast click — product ID only.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-stats-attr">
										<div class="mwst-stats-attr__card">
											<span class="mwst-stats-attr__label"><?php esc_html_e( 'Window', 'mw-sales-toast' ); ?></span>
											<span class="mwst-stats-attr__value" data-stat="attrWindow"><?php echo esc_html( (string) (int) ( $stats_seed['attrWindow'] ?? 30 ) ); ?> <?php esc_html_e( 'minutes', 'mw-sales-toast' ); ?></span>
											<span class="mwst-stats-attr__hint"><?php esc_html_e( 'Click → add to cart or purchase', 'mw-sales-toast' ); ?></span>
										</div>
										<div class="mwst-stats-attr__card">
											<span class="mwst-stats-attr__label"><?php esc_html_e( 'Attributed carts', 'mw-sales-toast' ); ?></span>
											<span class="mwst-stats-attr__value" data-stat="atc"><?php echo esc_html( number_format_i18n( (int) $stats_seed['atc'] ) ); ?></span>
											<span class="mwst-stats-attr__hint"><?php esc_html_e( 'Add-to-cart after toast click', 'mw-sales-toast' ); ?></span>
										</div>
										<div class="mwst-stats-attr__card">
											<span class="mwst-stats-attr__label"><?php esc_html_e( 'Attributed purchases', 'mw-sales-toast' ); ?></span>
											<span class="mwst-stats-attr__value" data-stat="purchases"><?php echo esc_html( number_format_i18n( (int) $stats_seed['purchases'] ) ); ?></span>
											<span class="mwst-stats-attr__hint"><?php esc_html_e( 'Orders in the same window', 'mw-sales-toast' ); ?></span>
										</div>
									</div>
									<p class="description"><?php esc_html_e( 'Soft attribution is correlation, not proven causation. Stored counts never include customer PII.', 'mw-sales-toast' ); ?></p>
								</div>
							</div>

							<div class="mwst-card" id="mwst-stats-products">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Per-product performance', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Top products by toast impressions in the selected range.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-stats-table-wrap">
										<table class="mwst-stats-table">
											<thead>
												<tr>
													<th scope="col"><?php esc_html_e( 'Product', 'mw-sales-toast' ); ?></th>
													<th scope="col" class="is-num"><?php esc_html_e( 'Impressions', 'mw-sales-toast' ); ?></th>
													<th scope="col" class="is-num"><?php esc_html_e( 'Clicks', 'mw-sales-toast' ); ?></th>
													<th scope="col" class="is-num"><?php esc_html_e( 'CTR', 'mw-sales-toast' ); ?></th>
													<th scope="col" class="is-num"><?php esc_html_e( 'Carts', 'mw-sales-toast' ); ?></th>
													<th scope="col" class="is-num"><?php esc_html_e( 'Orders', 'mw-sales-toast' ); ?></th>
												</tr>
											</thead>
											<tbody id="mwst-stats-products-body">
												<tr class="mwst-stats-empty">
													<td colspan="6"><?php esc_html_e( 'No product data yet.', 'mw-sales-toast' ); ?></td>
												</tr>
											</tbody>
										</table>
									</div>
									<p class="description"><?php esc_html_e( 'Product names link to the WooCommerce edit screen when available.', 'mw-sales-toast' ); ?></p>
								</div>
							</div>

							<div class="mwst-card" id="mwst-stats-privacy">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Privacy', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'What this dashboard stores.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<ul class="mwst-stats-privacy">
										<li><?php esc_html_e( 'Counts only: impressions, dismissals, mutes, clicks, soft-attributed carts/orders.', 'mw-sales-toast' ); ?></li>
										<li><?php esc_html_e( 'Product IDs only — never customer names, emails, or cities.', 'mw-sales-toast' ); ?></li>
										<li><?php esc_html_e( 'No cross-site tracking pixels; data stays on your WordPress site (90 days).', 'mw-sales-toast' ); ?></li>
									</ul>
								</div>
							</div>
						</div>

						<!-- Support (FAQ + documentation + contact) -->
						<?php
						$current_user = wp_get_current_user();
						$sys_info     = class_exists( 'MW_Sales_Toast_Support' ) ? MW_Sales_Toast_Support::system_info() : '';
						?>
						<div class="mwst-panel<?php echo 'support' === $current_tab ? ' is-active' : ''; ?>" id="mwst-panel-support" role="tabpanel">
							<nav class="mwst-jump" aria-label="<?php esc_attr_e( 'Support sections', 'mw-sales-toast' ); ?>">
								<a class="mwst-jump__link" href="#mwst-support-faq"><?php esc_html_e( 'FAQ', 'mw-sales-toast' ); ?></a>
								<a class="mwst-jump__link" href="#mwst-support-docs"><?php esc_html_e( 'Documentation', 'mw-sales-toast' ); ?></a>
								<a class="mwst-jump__link" href="#mwst-support-contact"><?php esc_html_e( 'Contact', 'mw-sales-toast' ); ?></a>
							</nav>

							<div class="mwst-card" id="mwst-support-faq">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'FAQ', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Common questions about setup, privacy, and troubleshooting.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-faq">
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'Why aren’t toasts showing on my site?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: 1: General tab link, 2: Message & privacy tab link */
														esc_html__( 'Confirm Enable is on under %1$s, then check Events in the page header (must be above 0). Try a private window — dismissing a toast can mute future ones. Also check Show on, Hide on cart & checkout, and Disable on mobile in %1$s, plus Mute after dismiss in %2$s.', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) ),
														self::tab_link( 'message', __( 'Message & privacy', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'Why don’t my real orders appear?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: 1: General tab link, 2: Message & privacy tab link, 3: Timing & cache tab link */
														esc_html__( 'Only processing and completed orders are used. Set Data source under %1$s to Real orders (or Real + demo fill). Checkout consent in %2$s hides customers who declined; admin/legacy orders without a choice can still appear. Check Lookback window in %3$s, then Save so the cache rebuilds.', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) ),
														self::tab_link( 'message', __( 'Message & privacy', 'mw-sales-toast' ) ),
														self::tab_link( 'timing', __( 'Timing & cache', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'What’s the difference between data sources?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<ul>
													<li><strong><?php esc_html_e( 'Real orders only', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Recent eligible WooCommerce sales.', 'mw-sales-toast' ); ?></li>
													<li><strong><?php esc_html_e( 'Demo only', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Simulated people/times using your catalog products.', 'mw-sales-toast' ); ?></li>
													<li><strong><?php esc_html_e( 'Real + demo fill', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Prefer real sales; fill with demo when volume is low.', 'mw-sales-toast' ); ?></li>
												</ul>
												<p>
													<?php
													printf(
														/* translators: 1: General tab link, 2: Demo data tab link */
														esc_html__( 'Choose the source in %1$s. Edit demo people and times anytime in %2$s (even if Real orders only is selected — they simply won’t show until you switch source).', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) ),
														self::tab_link( 'demo', __( 'Demo data', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'Is this GDPR / privacy friendly?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: %s: Message & privacy tab link */
														esc_html__( 'Only first name and city are shown — never email or full address. In %s you can require checkout consent for real orders, or hide names and use a fallback like “Someone”. No external tracking calls are made for toast delivery.', 'mw-sales-toast' ),
														self::tab_link( 'message', __( 'Message & privacy', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'How does stock display work?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: %s: Message & privacy tab link */
														esc_html__( 'Under %s, Stock display can be Off, Soft phrases (last one / only a few / low stock), or Exact when low (“only 3 left”). Stock only appears for real orders with WooCommerce stock management, and only when quantity is at or below your threshold. Demo toasts never show stock. Use {stock} / {stock_label} in the template, or leave them out to show the phrase next to the time.', 'mw-sales-toast' ),
														self::tab_link( 'message', __( 'Message & privacy', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'Will this slow down my store?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: %s: General tab link */
														esc_html__( 'Order queries run on WP-Cron and order hooks, not on every page view. Cache lifetime and cron interval are under %s. Visitors fetch a small REST payload; an empty response means no toast loop runs.', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'I closed a toast and they stopped appearing. Why?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: %s: Message & privacy tab link */
														esc_html__( 'Mute after dismiss (in %s) can hide toasts for a set number of hours in that browser. Set mute hours to 0 to only dismiss the current toast, or test in a private window.', 'mw-sales-toast' ),
														self::tab_link( 'message', __( 'Message & privacy', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'How do I preview before going live?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: 1: Design tab link, 2: Timing & cache tab link, 3: Demo data tab link */
														esc_html__( 'Use Show toast in the sidebar preview. Tune colors in %1$s and cadence in %2$s, then Save. For a quiet store, use Demo only or Real + demo fill and edit people/times in %3$s.', 'mw-sales-toast' ),
														self::tab_link( 'design', __( 'Design', 'mw-sales-toast' ) ),
														self::tab_link( 'timing', __( 'Timing & cache', 'mw-sales-toast' ) ),
														self::tab_link( 'demo', __( 'Demo data', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'Time labels feel jumpy or robotic. What can I change?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: 1: Timing & cache tab link, 2: Demo data tab link */
														esc_html__( 'In %1$s, use Natural time labels and raise Jitter so delays feel less mechanical. Keep Demo times in %2$s to a short recent band (just now, a few minutes ago…) and put freshest phrases first.', 'mw-sales-toast' ),
														self::tab_link( 'timing', __( 'Timing & cache', 'mw-sales-toast' ) ),
														self::tab_link( 'demo', __( 'Demo data', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'Cron isn’t updating the sales cache. What should I do?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: %s: General tab link */
														esc_html__( 'Open this settings page once, or deactivate and re-activate the plugin so the cache cron is scheduled. New orders also trigger a debounced rebuild. Adjust Cache lifetime and Cron interval under %s → Status.', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) )
													);
													?>
												</p>
											</div>
										</details>
										<details class="mwst-faq__item">
											<summary><?php esc_html_e( 'Still stuck — how do I get help?', 'mw-sales-toast' ); ?></summary>
											<div class="mwst-faq__answer">
												<p>
													<?php
													printf(
														/* translators: %s: anchor link to Contact section */
														esc_html__( 'Jump to %s, describe what you expected vs what happened, and leave Include system info checked so we can see WordPress, PHP, WooCommerce, and theme versions.', 'mw-sales-toast' ),
														'<a href="#mwst-support-contact">' . esc_html__( 'Contact', 'mw-sales-toast' ) . '</a>'
													);
													?>
												</p>
											</div>
										</details>
									</div>
								</div>
							</div>

							<div class="mwst-card" id="mwst-support-docs">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Documentation', 'mw-sales-toast' ); ?></h2>
									<p>
										<?php
										printf(
											/* translators: %s: plugin version */
											esc_html__( 'MW Sales Toast %s — setup guide, features, and how the cache works.', 'mw-sales-toast' ),
											esc_html( MW_SALES_TOAST_VERSION )
										);
										?>
									</p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-docs">
										<section class="mwst-docs__section">
											<h3><?php esc_html_e( 'Settings map', 'mw-sales-toast' ); ?></h3>
											<ul class="mwst-docs__map">
												<li>
													<?php
													printf(
														/* translators: %s: General tab link */
														esc_html__( '%s — enable toasts, data source, event delivery (REST or inline), position, where they appear, cache lifetime, cron, sound, and mobile.', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														/* translators: %s: Message & privacy tab link */
														esc_html__( '%s — message template tokens, fallback name, stock display, hide names, checkout consent, mute, and session limits.', 'mw-sales-toast' ),
														self::tab_link( 'message', __( 'Message & privacy', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														/* translators: %s: Design tab link */
														esc_html__( '%s — colors, radius, width, and (Pro) custom CSS for the toast chrome.', 'mw-sales-toast' ),
														self::tab_link( 'design', __( 'Design', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														/* translators: %s: Timing & cache tab link */
														esc_html__( '%s — delay, visible time, gap, jitter, time label style, max events, cached orders, and lookback days.', 'mw-sales-toast' ),
														self::tab_link( 'timing', __( 'Timing & cache', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														/* translators: %s: Demo data tab link */
														esc_html__( '%s — demo people and time phrases for Demo / Real + demo fill sources.', 'mw-sales-toast' ),
														self::tab_link( 'demo', __( 'Demo data', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														/* translators: %s: Account tab link */
														esc_html__( '%s — profile and license (Pro).', 'mw-sales-toast' ),
														self::tab_link( 'account', __( 'Account', 'mw-sales-toast' ) )
													);
													?>
												</li>
											</ul>
										</section>

										<section class="mwst-docs__section">
											<h3><?php esc_html_e( 'Getting started', 'mw-sales-toast' ); ?></h3>
											<ol>
												<li>
													<?php
													printf(
														/* translators: %s: General tab link */
														esc_html__( 'Turn Enable on in %s and pick a data source.', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														/* translators: %s: General tab link */
														esc_html__( 'Set position, edge distance, and which pages may show toasts (%s).', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														/* translators: 1: Message & privacy tab link, 2: Design tab link, 3: Timing & cache tab link */
														esc_html__( 'Edit the message and privacy options in %1$s, look in %2$s, and cadence in %3$s.', 'mw-sales-toast' ),
														self::tab_link( 'message', __( 'Message & privacy', 'mw-sales-toast' ) ),
														self::tab_link( 'design', __( 'Design', 'mw-sales-toast' ) ),
														self::tab_link( 'timing', __( 'Timing & cache', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li><?php esc_html_e( 'Use Show toast in the sidebar preview, then Save settings (Enter works in most fields).', 'mw-sales-toast' ); ?></li>
											</ol>
										</section>

										<section class="mwst-docs__section">
											<h3><?php esc_html_e( 'How it works', 'mw-sales-toast' ); ?></h3>
											<p>
												<?php
												printf(
													/* translators: %s: General tab link */
													esc_html__( 'Recent orders are rebuilt into a short-lived cache via WP-Cron and order hooks — not on every page view. Cache lifetime and cron interval live under %s → Status. Visitors load events from a REST endpoint; the front-end script shows one toast at a time with your delay / duration / gap.', 'mw-sales-toast' ),
													self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) )
												);
												?>
											</p>
											<pre class="mwst-docs__code" aria-hidden="true">WooCommerce order / WP-Cron
        ↓
  rebuild cache (transient)
        ↓
  GET /wp-json/mw-st/v1/notifications
        ↓
  visitor toast UI (one at a time)</pre>
										</section>

										<section class="mwst-docs__section">
											<h3><?php esc_html_e( 'Data sources', 'mw-sales-toast' ); ?></h3>
											<ul>
												<li><strong><?php esc_html_e( 'Real orders only', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Processing/completed orders inside the lookback window, respecting consent and privacy.', 'mw-sales-toast' ); ?></li>
												<li><strong><?php esc_html_e( 'Demo only', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Simulated people/times; products from your published catalog when available.', 'mw-sales-toast' ); ?></li>
												<li><strong><?php esc_html_e( 'Real + demo fill', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Prefer real sales; fill with demo when volume is low.', 'mw-sales-toast' ); ?></li>
											</ul>
											<p>
												<?php
												echo self::tab_link( 'general', __( 'Open General to change source →', 'mw-sales-toast' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
												?>
											</p>
										</section>

										<section class="mwst-docs__section">
											<h3><?php esc_html_e( 'Message, stock & privacy', 'mw-sales-toast' ); ?></h3>
											<ul>
												<li><?php esc_html_e( 'Template tokens: {name}, {city}, {product}, {stock}, {stock_label}.', 'mw-sales-toast' ); ?></li>
												<li><?php esc_html_e( 'Stock: Off / Soft phrases / Exact when low — real orders only, qty at or below threshold; demo never shows stock.', 'mw-sales-toast' ); ?></li>
												<li><?php esc_html_e( 'Only first name and city are shown — never email or full address.', 'mw-sales-toast' ); ?></li>
												<li><?php esc_html_e( 'Checkout consent hides customers who declined; legacy/admin orders without a choice can still appear.', 'mw-sales-toast' ); ?></li>
												<li><?php esc_html_e( 'Hide names always uses the fallback name (e.g. “Someone”).', 'mw-sales-toast' ); ?></li>
												<li><?php esc_html_e( 'Consent meta key on the order: _mw_st_allow_public.', 'mw-sales-toast' ); ?></li>
											</ul>
											<p>
												<?php
												echo self::tab_link( 'message', __( 'Open Message & privacy →', 'mw-sales-toast' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
												?>
											</p>
										</section>

										<section class="mwst-docs__section">
											<h3><?php esc_html_e( 'Timing & labels', 'mw-sales-toast' ); ?></h3>
											<ul>
												<li><strong><?php esc_html_e( 'First delay', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Wait after page load before the first toast.', 'mw-sales-toast' ); ?></li>
												<li><strong><?php esc_html_e( 'Visible for', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'How long each toast stays on screen.', 'mw-sales-toast' ); ?></li>
												<li><strong><?php esc_html_e( 'Gap after hide', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Quiet time after a toast hides before the next one.', 'mw-sales-toast' ); ?></li>
												<li><strong><?php esc_html_e( 'Jitter', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Randomizes delay and gap so the rhythm feels less robotic.', 'mw-sales-toast' ); ?></li>
												<li><strong><?php esc_html_e( 'Time label', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Natural (just now…) or Exact (2 minutes ago) for real orders; demo lines are shown as written.', 'mw-sales-toast' ); ?></li>
												<li><strong><?php esc_html_e( 'Lookback / max events', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'How far back orders are considered and how many cycle in a visit.', 'mw-sales-toast' ); ?></li>
												<li><strong><?php esc_html_e( 'Mute after dismiss', 'mw-sales-toast' ); ?></strong> — <?php esc_html_e( 'Closing with × can mute future toasts for N hours (0 = current toast only).', 'mw-sales-toast' ); ?></li>
											</ul>
											<p>
												<?php
												echo self::tab_link( 'timing', __( 'Open Timing & cache →', 'mw-sales-toast' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
												?>
											</p>
										</section>

										<section class="mwst-docs__section">
											<h3><?php esc_html_e( 'Event delivery', 'mw-sales-toast' ); ?></h3>
											<ul>
												<li>
													<strong><?php esc_html_e( 'REST API', 'mw-sales-toast' ); ?></strong> —
													<?php esc_html_e( 'Front end fetches events from', 'mw-sales-toast' ); ?>
													<code><?php echo esc_html( rest_url( 'mw-st/v1/notifications' ) ); ?></code>
													<?php esc_html_e( 'with an X-MW-ST-Nonce header. Bare requests get 403. Supports background refetch.', 'mw-sales-toast' ); ?>
												</li>
												<li>
													<strong><?php esc_html_e( 'Inline', 'mw-sales-toast' ); ?></strong> —
													<?php
													printf(
														/* translators: %s: General tab link */
														esc_html__( 'Events are printed into the page as JSON (mwSalesToast.events). The REST route is not registered. Choose this under %s → Event delivery. No refetch until the next full page load — beware full-page caches serving stale events.', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) )
													);
													?>
												</li>
											</ul>
										</section>

										<section class="mwst-docs__section">
											<h3><?php esc_html_e( 'Troubleshooting', 'mw-sales-toast' ); ?></h3>
											<ul>
												<li>
													<?php
													printf(
														/* translators: %s: General tab link */
														esc_html__( 'No toasts: Enable on, Events above 0 in the header, not muted — start in %s.', 'mw-sales-toast' ),
														self::tab_link( 'general', __( 'General', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li>
													<?php
													printf(
														/* translators: 1: Message & privacy tab link, 2: Timing & cache tab link */
														esc_html__( 'Real orders missing: %1$s (consent) and %2$s (lookback), plus processing/completed status.', 'mw-sales-toast' ),
														self::tab_link( 'message', __( 'Message & privacy', 'mw-sales-toast' ) ),
														self::tab_link( 'timing', __( 'Timing & cache', 'mw-sales-toast' ) )
													);
													?>
												</li>
												<li><?php esc_html_e( 'Cron not scheduled: open this settings page once or re-activate the plugin.', 'mw-sales-toast' ); ?></li>
												<li>
													<?php
													printf(
														/* translators: %s: anchor link to Contact section */
														esc_html__( 'Still stuck? Skim the FAQ above, or use %s and include system info.', 'mw-sales-toast' ),
														'<a href="#mwst-support-contact">' . esc_html__( 'Contact', 'mw-sales-toast' ) . '</a>'
													);
													?>
												</li>
											</ul>
										</section>
									</div>
								</div>
							</div>

							<div class="mwst-card" id="mwst-support-contact">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Contact', 'mw-sales-toast' ); ?></h2>
									<p>
										<?php
										echo $is_pro
											? esc_html__( 'Pro support — questions, bugs, or setup help for MW Sales Toast. We prioritize Pro tickets.', 'mw-sales-toast' )
											: esc_html__( 'Questions, bugs, or setup help for MW Sales Toast. We usually reply within 1–2 business days.', 'mw-sales-toast' );
										?>
									</p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-support" id="mwst-support">
										<div class="mwst-support__status" id="mwst-support-status" hidden></div>
										<input type="hidden" id="mwst-support-is-pro" value="<?php echo $is_pro ? '1' : '0'; ?>" />
										<div class="mwst-support__grid">
											<label class="mwst-support__field">
												<span><?php esc_html_e( 'Name', 'mw-sales-toast' ); ?></span>
												<input type="text" id="mwst-support-name" class="regular-text" value="<?php echo esc_attr( $current_user->display_name ); ?>" autocomplete="name" />
											</label>
											<label class="mwst-support__field">
												<span><?php esc_html_e( 'Email', 'mw-sales-toast' ); ?></span>
												<input type="email" id="mwst-support-email" class="regular-text" value="<?php echo esc_attr( $current_user->user_email ); ?>" autocomplete="email" />
											</label>
										</div>
										<label class="mwst-support__field">
											<span><?php esc_html_e( 'Subject', 'mw-sales-toast' ); ?></span>
											<input type="text" id="mwst-support-subject" class="large-text" maxlength="140" placeholder="<?php esc_attr_e( 'e.g. Toast not showing on product pages', 'mw-sales-toast' ); ?>" />
										</label>
										<label class="mwst-support__field">
											<span><?php esc_html_e( 'Message', 'mw-sales-toast' ); ?></span>
											<textarea id="mwst-support-message" class="large-text" rows="8" maxlength="5000" placeholder="<?php esc_attr_e( 'Describe what you expected, what happened, and any steps to reproduce…', 'mw-sales-toast' ); ?>"></textarea>
										</label>
										<label class="mwst-support__check">
											<input type="checkbox" id="mwst-support-system" value="1" checked />
											<span><?php esc_html_e( 'Include system info (plan, WordPress, PHP, WooCommerce, theme)', 'mw-sales-toast' ); ?></span>
										</label>
										<details class="mwst-support__details">
											<summary><?php esc_html_e( 'Preview system info', 'mw-sales-toast' ); ?></summary>
											<pre class="mwst-support__sys"><?php echo esc_html( $sys_info ); ?></pre>
										</details>
										<div class="mwst-support__actions">
											<button type="button" class="button button-primary" id="mwst-support-submit"><?php esc_html_e( 'Send message', 'mw-sales-toast' ); ?></button>
										</div>
									</div>
								</div>
							</div>
						</div>

						<!-- Account -->
						<div class="mwst-panel<?php echo 'account' === $current_tab ? ' is-active' : ''; ?>" id="mwst-panel-account" role="tabpanel">
							<?php if ( $is_pro ) : ?>
								<div class="mwst-card" id="mwst-account-profile">
									<div class="mwst-card__head">
										<h2><?php esc_html_e( 'Profile', 'mw-sales-toast' ); ?></h2>
										<p><?php esc_html_e( 'Account details used for license and support communication.', 'mw-sales-toast' ); ?></p>
									</div>
									<div class="mwst-card__body">
										<div class="mwst-account">
											<div class="mwst-account__hero">
												<span class="mwst-account__avatar" aria-hidden="true">
													<?php echo get_avatar( $current_user->ID, 64, '', '', array( 'class' => 'mwst-account__avatar-img' ) ); ?>
												</span>
												<div class="mwst-account__hero-copy">
													<p class="mwst-account__hero-name"><?php echo esc_html( $current_user->display_name ); ?></p>
													<p class="mwst-account__hero-email"><?php echo esc_html( $current_user->user_email ); ?></p>
												</div>
												<span class="mwst-plan-pill mwst-plan-pill--pro"><?php esc_html_e( 'Pro', 'mw-sales-toast' ); ?></span>
											</div>
											<div class="mwst-account__grid">
												<label class="mwst-account__field">
													<span><?php esc_html_e( 'Name', 'mw-sales-toast' ); ?></span>
													<input type="text" id="mwst-account-name" class="regular-text" value="<?php echo esc_attr( $current_user->display_name ); ?>" autocomplete="name" readonly />
												</label>
												<label class="mwst-account__field">
													<span><?php esc_html_e( 'Email', 'mw-sales-toast' ); ?></span>
													<input type="email" id="mwst-account-email" class="regular-text" value="<?php echo esc_attr( $current_user->user_email ); ?>" autocomplete="email" readonly />
												</label>
											</div>
											<p class="description">
												<?php
												printf(
													/* translators: %s: link to WordPress profile screen */
													esc_html__( 'Pulled from your WordPress user. Change your name or email in %s.', 'mw-sales-toast' ),
													'<a href="' . esc_url( get_edit_profile_url( $current_user->ID ) ) . '">' . esc_html__( 'your profile', 'mw-sales-toast' ) . '</a>'
												);
												?>
											</p>
										</div>
									</div>
								</div>
							<?php endif; ?>

							<div class="mwst-card" id="mwst-account-newsletter">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'Newsletter', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Occasional product updates, tips, and release notes. No spam.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-field">
										<div class="mwst-field__label"><?php esc_html_e( 'Email updates', 'mw-sales-toast' ); ?></div>
										<div class="mwst-field__control">
											<?php self::toggle( $opt, 'newsletter', $s, 'mwst-newsletter', __( 'Subscribe to the Sales Toast newsletter', 'mw-sales-toast' ) ); ?>
											<p class="description">
												<?php
												printf(
													/* translators: %s: admin email address */
													esc_html__( 'We will use %s from your WordPress profile. You can unsubscribe anytime.', 'mw-sales-toast' ),
													'<strong>' . esc_html( $current_user->user_email ) . '</strong>'
												);
												?>
											</p>
										</div>
									</div>
								</div>
							</div>

							<div class="mwst-card" id="mwst-account-license">
								<div class="mwst-card__head">
									<h2><?php esc_html_e( 'License', 'mw-sales-toast' ); ?></h2>
									<p><?php esc_html_e( 'Activate a Pro serial key to unlock advanced features. Design preview — activation is not wired yet.', 'mw-sales-toast' ); ?></p>
								</div>
								<div class="mwst-card__body">
									<div class="mwst-license">
										<div class="mwst-license__status">
											<span class="mwst-plan-pill <?php echo $is_pro ? 'mwst-plan-pill--pro' : 'mwst-plan-pill--free'; ?>">
												<?php echo $is_pro ? esc_html__( 'Pro', 'mw-sales-toast' ) : esc_html__( 'Free', 'mw-sales-toast' ); ?>
											</span>
											<p class="mwst-license__status-text">
												<?php
												echo $is_pro
													? esc_html__( 'Pro features are marked as active in this preview.', 'mw-sales-toast' )
													: esc_html__( 'You are on the Free plan. Enter a serial key when you have one.', 'mw-sales-toast' );
												?>
											</p>
										</div>
										<div class="mwst-license__field">
											<span><?php esc_html_e( 'Serial key', 'mw-sales-toast' ); ?></span>
											<?php if ( $is_pro ) : ?>
												<p class="mwst-license__key" id="mwst-license-key"><?php echo esc_html( '****-****-****-****' ); ?></p>
											<?php else : ?>
												<input type="text" id="mwst-license-key" class="large-text code" value="" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'XXXX-XXXX-XXXX-XXXX', 'mw-sales-toast' ); ?>" />
											<?php endif; ?>
										</div>
										<div class="mwst-license__actions">
											<?php if ( $is_pro ) : ?>
												<button type="button" class="button" id="mwst-license-deactivate"><?php esc_html_e( 'Deactivate', 'mw-sales-toast' ); ?></button>
											<?php else : ?>
												<button type="button" class="button button-primary" id="mwst-license-activate" disabled><?php esc_html_e( 'Activate', 'mw-sales-toast' ); ?></button>
											<?php endif; ?>
										</div>
										<p class="description"><?php esc_html_e( 'License validation and renew links will land here in a future update.', 'mw-sales-toast' ); ?></p>
									</div>
								</div>
							</div>
						</div>
					</div>

					<aside class="mwst-sidebar">
						<div class="mwst-side-card">
							<div class="mwst-side-card__head"><?php esc_html_e( 'Preview', 'mw-sales-toast' ); ?></div>
							<div class="mwst-side-card__body">
								<aside id="mwst-sample-toast" class="mw-sales-toast mwst-sample-toast is-visible mw-sales-toast--media-<?php echo esc_attr( ( 'padded' === ( $s['style_image_fit'] ?? '' ) ) ? 'padded' : 'full' ); ?>" aria-hidden="true">
									<div id="mwst-sample-media" class="mw-sales-toast__media"></div>
									<div class="mw-sales-toast__body">
										<p id="mwst-sample-text" class="mw-sales-toast__text"></p>
										<p id="mwst-sample-meta" class="mw-sales-toast__meta"></p>
									</div>
									<span class="mw-sales-toast__close" aria-hidden="true">×</span>
								</aside>
								<div class="mwst-preview-actions">
									<button type="button" class="button button-secondary" id="mwst-toggle-toast">
										<?php esc_html_e( 'Show toast', 'mw-sales-toast' ); ?>
									</button>
								</div>
								<p class="mwst-preview-hint">
									<?php esc_html_e( 'Shows the real toast over this admin page using the form values above (save to apply on the storefront).', 'mw-sales-toast' ); ?>
								</p>
							</div>
						</div>

						<div class="mwst-side-card">
							<div class="mwst-side-card__head"><?php esc_html_e( 'Tips', 'mw-sales-toast' ); ?></div>
							<div class="mwst-side-card__body">
								<ul class="mwst-tips">
									<li><?php esc_html_e( 'Prefer “Real + demo fill” until you have steady order volume.', 'mw-sales-toast' ); ?></li>
									<li><?php esc_html_e( 'Keep checkout consent on for GDPR-friendly social proof.', 'mw-sales-toast' ); ?></li>
									<li><?php esc_html_e( 'Saving settings clears the sales cache so the next rebuild uses your new options.', 'mw-sales-toast' ); ?></li>
								</ul>
							</div>
						</div>
					</aside>
				</div>

				<div class="mwst-save<?php echo in_array( $current_tab, $nonsave_tabs, true ) ? ' is-nonsave-tab' : ''; ?>" id="mwst-save-bar">
					<p class="mwst-save__hint" id="mwst-save-hint"><?php esc_html_e( 'Changes apply after save. The front-end cache rebuilds automatically.', 'mw-sales-toast' ); ?></p>
					<div class="mwst-save__actions">
						<span class="mwst-save__spinner" id="mwst-save-spinner" hidden aria-hidden="true"></span>
						<?php submit_button( __( 'Save settings', 'mw-sales-toast' ), 'primary', 'submit', false ); ?>
					</div>
				</div>
			</form>
		</div>
		<?php
	}
}
