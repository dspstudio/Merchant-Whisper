<?php
/**
 * Checkout consent for public sale notifications.
 *
 * @package MW_Sales_Toast
 */

defined( 'ABSPATH' ) || exit;

/**
 * Privacy / consent field.
 */
class MW_Sales_Toast_Privacy {

	/**
	 * Hook checkout (classic + block additional fields).
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
		add_action( 'woocommerce_set_additional_field_value', array( __CLASS__, 'save_block_consent' ), 10, 4 );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render_checkbox' ), 12 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_consent' ), 20, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'save_consent_legacy' ), 20, 1 );
	}

	/**
	 * Block / Store API checkout checkbox.
	 */
	public static function register_block_field() {
		if ( ! self::should_show_field() ) {
			return;
		}
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'mw-st/allow-public',
				'label'    => __( 'Allow my first name and city to appear in live purchase notifications on this site', 'mw-sales-toast' ),
				'location' => 'order',
				'type'     => 'checkbox',
			)
		);
	}

	/**
	 * Map block checkout field onto our meta key.
	 *
	 * @param string $key       Field id.
	 * @param mixed  $value     Value.
	 * @param string $group     Group.
	 * @param object $wc_object Order/customer.
	 */
	public static function save_block_consent( $key, $value, $group, $wc_object ) {
		if ( 'mw-st/allow-public' !== $key || ! self::should_show_field() ) {
			return;
		}
		if ( ! $wc_object instanceof WC_Order ) {
			return;
		}
		$wc_object->update_meta_data( MW_SALES_TOAST_CONSENT_META, ! empty( $value ) ? 'yes' : 'no' );
	}

	/**
	 * Whether consent UI should show.
	 *
	 * @return bool
	 */
	public static function should_show_field() {
		$settings = MW_Sales_Toast_Settings::get();
		return ! empty( $settings['enabled'] ) && ! empty( $settings['require_consent'] );
	}

	/**
	 * Classic checkout checkbox.
	 */
	public static function render_checkbox() {
		if ( ! self::should_show_field() ) {
			return;
		}
		?>
		<p class="form-row form-row-wide mw-st-consent">
			<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
				<input
					type="checkbox"
					class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox"
					name="mw_st_allow_public"
					id="mw_st_allow_public"
					value="1"
				/>
				<span>
					<?php esc_html_e( 'Allow my first name and city to appear in live purchase notifications on this site', 'mw-sales-toast' ); ?>
				</span>
			</label>
		</p>
		<?php
	}

	/**
	 * Save consent on WC_Order create (modern checkout).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 */
	public static function save_consent( $order, $data ) {
		if ( ! $order instanceof WC_Order || ! self::should_show_field() ) {
			return;
		}

		$allowed = ! empty( $_POST['mw_st_allow_public'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order->update_meta_data( MW_SALES_TOAST_CONSENT_META, $allowed ? 'yes' : 'no' );
	}

	/**
	 * Legacy save path.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function save_consent_legacy( $order_id ) {
		if ( ! self::should_show_field() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Avoid double-write if already set by create_order hook.
		if ( '' !== (string) $order->get_meta( MW_SALES_TOAST_CONSENT_META ) ) {
			return;
		}

		$allowed = ! empty( $_POST['mw_st_allow_public'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order->update_meta_data( MW_SALES_TOAST_CONSENT_META, $allowed ? 'yes' : 'no' );
		$order->save();
	}
}
