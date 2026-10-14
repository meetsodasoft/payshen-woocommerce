<?php
/**
 * Cart and Checkout blocks integration.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers Payshen as a payment method in the Checkout block. Payment runs
 * through the gateway's process_payment, exactly as in the classic checkout.
 */
final class Payshen_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method name; matches the gateway id.
	 *
	 * @var string
	 */
	protected $name = 'payshen';

	/**
	 * The gateway.
	 *
	 * @var Payshen_Gateway|null
	 */
	private $gateway = null;

	/**
	 * Load settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_payshen_settings', array() );
	}

	/**
	 * The gateway instance.
	 *
	 * @return Payshen_Gateway|null
	 */
	private function gateway() {
		if ( null === $this->gateway ) {
			$this->gateway = payshen_wc_gateway();
		}
		return $this->gateway;
	}

	/**
	 * Whether the method can be offered.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = $this->gateway();
		return $gateway && 'yes' === $gateway->enabled && '' !== $gateway->api_key( $gateway->mode() );
	}

	/**
	 * The script that registers the method.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'payshen-blocks',
			PAYSHEN_WC_URL . 'assets/js/payshen-blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			PAYSHEN_WC_VERSION,
			true
		);
		wp_set_script_translations( 'payshen-blocks', 'payshen-for-woocommerce' );
		return array( 'payshen-blocks' );
	}

	/**
	 * Data the script reads (window.wc.wcSettings.getSetting( 'payshen_data' )).
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();
		return array(
			'title'       => $gateway ? $gateway->get_title() : $this->get_setting( 'title' ),
			'description' => $gateway ? $gateway->get_description() : $this->get_setting( 'description' ),
			'supports'    => $gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array( 'products' ),
		);
	}
}
