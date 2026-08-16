<?php
/**
 * Settings and theme JSON import / export.
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Agency transfer: full settings pack or design-only theme JSON.
 */
class MW_Sales_Toast_Transfer {

	const NONCE     = 'mw_st_transfer';
	const MAX_BYTES = 524288; // 512 KB.

	/**
	 * Hook admin-post handlers.
	 */
	public static function init() {
		add_action( 'admin_post_mw_st_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_mw_st_import', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Design keys copied by a theme JSON file.
	 *
	 * @return array<int, string>
	 */
	public static function theme_keys() {
		return array(
			'position',
			'offset_x',
			'offset_y',
			'show_image',
			'style_bg',
			'style_bg_opacity',
			'style_text',
			'style_body',
			'style_accent',
			'style_meta',
			'style_border',
			'style_border_opacity',
			'style_border_width',
			'style_radius',
			'style_padding',
			'style_max_width',
			'style_shadow',
			'style_image_fit',
			'design_preset',
			'use_elementor_theme',
			'custom_css',
		);
	}

	/**
	 * Keys never written from an import (site / account specific).
	 *
	 * @return array<int, string>
	 */
	public static function skip_keys() {
		return array( 'newsletter', 'slack_webhook' );
	}

	/**
	 * Download URL for a pack.
	 *
	 * @param string $kind settings|theme.
	 * @return string
	 */
	public static function export_url( $kind ) {
		$kind = ( 'theme' === $kind ) ? 'theme' : 'settings';
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'mw_st_export',
					'kind'   => $kind,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE,
			'mw_st_transfer_nonce'
		);
	}

	/**
	 * Settings screen URL after import.
	 *
	 * @param string $tab     Tab id.
	 * @param string $status  Notice key.
	 * @return string
	 */
	public static function settings_url( $tab, $status ) {
		$tab = sanitize_key( $tab );
		if ( ! in_array( $tab, array( 'design', 'account' ), true ) ) {
			$tab = 'account';
		}
		return add_query_arg(
			array(
				'page'          => 'mw-sales-toast',
				'tab'           => $tab,
				'mwst_transfer' => sanitize_key( $status ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Stream a JSON download.
	 */
	public static function handle_export() {
		if ( ! current_user_can( MW_Sales_Toast_Settings::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to export Merchant Whisper settings.', 'mw-sales-toast' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE, 'mw_st_transfer_nonce' );

		$kind    = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'settings';
		$kind    = ( 'theme' === $kind ) ? 'theme' : 'settings';
		$payload = self::build_payload( $kind );
		$json    = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || '' === $json ) {
			wp_die( esc_html__( 'Could not build the export file.', 'mw-sales-toast' ) );
		}

		$date = gmdate( 'Y-m-d' );
		$name = ( 'theme' === $kind )
			? 'mw-sales-toast-theme-' . $date . '.json'
			: 'mw-sales-toast-settings-' . $date . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . (string) strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download body.
		exit;
	}

	/**
	 * Apply an uploaded JSON pack.
	 */
	public static function handle_import() {
		$tab = isset( $_POST['mwst_import_tab'] ) ? sanitize_key( wp_unslash( $_POST['mwst_import_tab'] ) ) : 'account'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! current_user_can( MW_Sales_Toast_Settings::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to import Merchant Whisper settings.', 'mw-sales-toast' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE, 'mw_st_transfer_nonce' );

		$expect = isset( $_POST['mwst_import_kind'] ) ? sanitize_key( wp_unslash( $_POST['mwst_import_kind'] ) ) : 'settings';
		$expect = ( 'theme' === $expect ) ? 'theme' : 'settings';

		if ( empty( $_FILES['mwst_file'] ) || ! is_array( $_FILES['mwst_file'] ) ) {
			wp_safe_redirect( self::settings_url( $tab, 'empty' ) );
			exit;
		}

		$file = $_FILES['mwst_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $code ) {
			wp_safe_redirect( self::settings_url( $tab, 'empty' ) );
			exit;
		}
		if ( UPLOAD_ERR_OK !== $code || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_safe_redirect( self::settings_url( $tab, 'error' ) );
			exit;
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size < 2 || $size > self::MAX_BYTES ) {
			wp_safe_redirect( self::settings_url( $tab, 'error' ) );
			exit;
		}

		$raw = file_get_contents( $file['tmp_name'] );
		if ( ! is_string( $raw ) || '' === $raw ) {
			wp_safe_redirect( self::settings_url( $tab, 'error' ) );
			exit;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( self::settings_url( $tab, 'invalid' ) );
			exit;
		}

		$type = self::detect_type( $decoded );
		if ( ! $type ) {
			wp_safe_redirect( self::settings_url( $tab, 'invalid' ) );
			exit;
		}

		// Theme slot only accepts theme packs. Settings slot accepts both.
		if ( 'theme' === $expect && 'theme' !== $type ) {
			wp_safe_redirect( self::settings_url( $tab, 'invalid' ) );
			exit;
		}

		$ok = self::apply_payload( $decoded, $type );
		if ( ! $ok ) {
			wp_safe_redirect( self::settings_url( $tab, 'error' ) );
			exit;
		}

		wp_safe_redirect( self::settings_url( $tab, ( 'theme' === $type ) ? 'theme' : 'imported' ) );
		exit;
	}

	/**
	 * Build a portable JSON document.
	 *
	 * @param string $kind settings|theme.
	 * @return array<string, mixed>
	 */
	public static function build_payload( $kind ) {
		$settings = class_exists( 'MW_Sales_Toast_Settings' ) ? MW_Sales_Toast_Settings::get() : array();
		$data     = array();

		if ( 'theme' === $kind ) {
			foreach ( self::theme_keys() as $key ) {
				if ( array_key_exists( $key, $settings ) ) {
					$data[ $key ] = $settings[ $key ];
				}
			}
		} else {
			$skip = array_fill_keys( self::skip_keys(), true );
			foreach ( $settings as $key => $value ) {
				if ( isset( $skip[ $key ] ) ) {
					continue;
				}
				$data[ $key ] = $value;
			}
		}

		/**
		 * Filter export payload data.
		 *
		 * @param array  $data Settings slice.
		 * @param string $kind settings|theme.
		 */
		$data = apply_filters( 'mw_sales_toast_export_data', $data, $kind );

		return array(
			'plugin'     => 'mw-sales-toast',
			'type'       => $kind,
			'version'    => defined( 'MW_SALES_TOAST_VERSION' ) ? MW_SALES_TOAST_VERSION : '',
			'exportedAt' => gmdate( 'c' ),
			'data'       => $data,
		);
	}

	/**
	 * Resolve pack type from a decoded file.
	 *
	 * @param array $decoded JSON.
	 * @return string|null settings|theme.
	 */
	public static function detect_type( $decoded ) {
		$plugin = isset( $decoded['plugin'] ) ? (string) $decoded['plugin'] : '';
		if ( 'mw-sales-toast' !== $plugin ) {
			return null;
		}

		$type = isset( $decoded['type'] ) ? sanitize_key( (string) $decoded['type'] ) : '';
		if ( in_array( $type, array( 'settings', 'theme' ), true ) && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			return $type;
		}

		return null;
	}

	/**
	 * Merge payload into saved options (via Settings::sanitize).
	 *
	 * @param array  $decoded Full document.
	 * @param string $type    settings|theme.
	 * @return bool
	 */
	public static function apply_payload( $decoded, $type ) {
		$data = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array();
		if ( ! $data ) {
			return false;
		}

		$current = MW_Sales_Toast_Settings::get();
		$skip    = array_fill_keys( self::skip_keys(), true );

		if ( 'theme' === $type ) {
			$merged = $current;
			$allow  = array_fill_keys( self::theme_keys(), true );
			foreach ( $data as $key => $value ) {
				if ( isset( $allow[ $key ] ) ) {
					$merged[ $key ] = $value;
				}
			}
		} else {
			$merged = $current;
			foreach ( $data as $key => $value ) {
				if ( isset( $skip[ $key ] ) ) {
					continue;
				}
				$merged[ $key ] = $value;
			}
		}

		foreach ( self::skip_keys() as $key ) {
			if ( array_key_exists( $key, $current ) ) {
				$merged[ $key ] = $current[ $key ];
			}
		}

		$clean = MW_Sales_Toast_Settings::sanitize( $merged );
		update_option( MW_SALES_TOAST_OPTION, $clean, false );
		return true;
	}

	/**
	 * Hidden import form (lives outside the settings form).
	 *
	 * @param string $kind settings|theme.
	 * @param string $tab  Return tab.
	 */
	public static function render_import_form( $kind, $tab ) {
		$kind = ( 'theme' === $kind ) ? 'theme' : 'settings';
		$tab  = sanitize_key( $tab );
		$id   = 'mwst-import-' . $kind;
		?>
		<form
			id="<?php echo esc_attr( $id ); ?>"
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			enctype="multipart/form-data"
			class="mwst-import-form"
			hidden
		>
			<?php wp_nonce_field( self::NONCE, 'mw_st_transfer_nonce' ); ?>
			<input type="hidden" name="action" value="mw_st_import" />
			<input type="hidden" name="mwst_import_kind" value="<?php echo esc_attr( $kind ); ?>" />
			<input type="hidden" name="mwst_import_tab" value="<?php echo esc_attr( $tab ); ?>" />
		</form>
		<?php
	}

	/**
	 * Admin card body: export link + import file.
	 *
	 * @param string $kind settings|theme.
	 */
	public static function render_controls( $kind ) {
		$kind     = ( 'theme' === $kind ) ? 'theme' : 'settings';
		$form_id  = 'mwst-import-' . $kind;
		$file_id  = 'mwst-import-file-' . $kind;
		$label    = ( 'theme' === $kind )
			? __( 'Export theme JSON', 'mw-sales-toast' )
			: __( 'Export settings', 'mw-sales-toast' );
		$import   = ( 'theme' === $kind )
			? __( 'Import theme', 'mw-sales-toast' )
			: __( 'Import settings', 'mw-sales-toast' );
		$confirm  = ( 'theme' === $kind )
			? __( 'Replace the current toast design with this theme file?', 'mw-sales-toast' )
			: __( 'Replace the current Merchant Whisper settings with this file? Product and category IDs are site-specific.', 'mw-sales-toast' );
		?>
		<div class="mwst-transfer">
			<p class="mwst-transfer__export">
				<a class="button" href="<?php echo esc_url( self::export_url( $kind ) ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			</p>
			<div class="mwst-transfer__import">
				<label class="screen-reader-text" for="<?php echo esc_attr( $file_id ); ?>">
					<?php echo esc_html( $import ); ?>
				</label>
				<input
					type="file"
					id="<?php echo esc_attr( $file_id ); ?>"
					name="mwst_file"
					form="<?php echo esc_attr( $form_id ); ?>"
					accept=".json,application/json"
					class="mwst-transfer__file"
					tabindex="-1"
				/>
				<button
					type="button"
					class="button button-secondary mwst-transfer__submit"
					data-mwst-form="<?php echo esc_attr( $form_id ); ?>"
					data-mwst-file="<?php echo esc_attr( $file_id ); ?>"
					data-mwst-confirm="<?php echo esc_attr( $confirm ); ?>"
				>
					<?php echo esc_html( $import ); ?>
				</button>
				<span class="mwst-transfer__spinner" hidden aria-hidden="true"></span>
			</div>
		</div>
		<?php
	}
}
