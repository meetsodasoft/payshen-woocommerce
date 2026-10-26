<?php
/**
 * Plugin Name:          Payshen for WooCommerce
 * Plugin URI:           https://www.payshen.com/developers
 * Description:          Take payments through all your payment providers at once. Payshen routes each payment and the customer pays on the provider's secure page.
 * Version:              1.1.0
 * Requires at least:    6.5
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce
 * Author:               Payshen
 * Author URI:           https://www.payshen.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          payshen-for-woocommerce
 * WC requires at least: 8.5
 * WC tested up to:      11.2
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'PAYSHEN_WC_VERSION', '1.1.0' );
define( 'PAYSHEN_WC_FILE', __FILE__ );
define( 'PAYSHEN_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'PAYSHEN_WC_URL', plugin_dir_url( __FILE__ ) );

// Pure helpers: no WordPress calls, so they also load in the unit tests.
require_once PAYSHEN_WC_PATH . 'includes/class-payshen-amount.php';
require_once PAYSHEN_WC_PATH . 'includes/class-payshen-signature.php';
require_once PAYSHEN_WC_PATH . 'includes/class-payshen-status.php';
require_once PAYSHEN_WC_PATH . 'includes/class-payshen-keys.php';
require_once PAYSHEN_WC_PATH . 'includes/class-payshen-customer.php';
require_once PAYSHEN_WC_PATH . 'includes/class-payshen-api-exception.php';
require_once PAYSHEN_WC_PATH . 'includes/class-payshen-api-client.php';
require_once PAYSHEN_WC_PATH . 'includes/class-payshen-connect-request.php';
require_once PAYSHEN_WC_PATH . 'includes/class-payshen-php-version.php';

/**
 * Declare support for order storage (HPOS) and the Cart and Checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PAYSHEN_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PAYSHEN_WC_FILE, true );
		}
	}
);

/**
 * Load the gateway once WooCommerce is loaded.
 */
function payshen_wc_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', 'payshen_wc_missing_woocommerce_notice' );
		return;
	}

	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-logger.php';
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-lock.php';
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-gateway.php';
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-order-sync.php';
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-refunds.php';
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-scheduler.php';
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-webhook.php';
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-return-handler.php';
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-order-admin.php';
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-connect.php';

	add_filter( 'woocommerce_payment_gateways', 'payshen_wc_add_gateway' );
	add_filter( 'plugin_action_links_' . plugin_basename( PAYSHEN_WC_FILE ), 'payshen_wc_action_links' );

	Payshen_Scheduler::init();
	Payshen_Webhook::init();
	Payshen_Return_Handler::init();
	Payshen_Order_Admin::init();
	Payshen_Refunds::init();
	Payshen_Connect::init();

	// Fired by WooCommerce when the Checkout block collects its payment methods.
	add_action( 'woocommerce_blocks_payment_method_type_registration', 'payshen_wc_register_blocks' );
}
add_action( 'plugins_loaded', 'payshen_wc_init', 11 );

/**
 * Add the gateway to WooCommerce.
 *
 * @param array $gateways Gateway class names.
 * @return array
 */
function payshen_wc_add_gateway( $gateways ) {
	$gateways[] = 'Payshen_Gateway';
	return $gateways;
}

/**
 * A Settings link on the Plugins screen.
 *
 * @param array $links Existing links.
 * @return array
 */
function payshen_wc_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payshen' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'payshen-for-woocommerce' ) . '</a>' );
	return $links;
}

/**
 * Register the Cart and Checkout blocks integration.
 *
 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registry.
 */
function payshen_wc_register_blocks( $registry ) {
	if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
		return;
	}
	require_once PAYSHEN_WC_PATH . 'includes/class-payshen-blocks-support.php';
	$registry->register( new Payshen_Blocks_Support() );
}

/**
 * Shown when WooCommerce is not active.
 */
function payshen_wc_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Payshen for WooCommerce needs WooCommerce to be installed and active.', 'payshen-for-woocommerce' ) . '</p></div>';
}

/**
 * The gateway instance WooCommerce holds, or a new one.
 *
 * @return Payshen_Gateway|null
 */
function payshen_wc_gateway() {
	if ( ! function_exists( 'WC' ) || ! class_exists( 'Payshen_Gateway' ) ) {
		return null;
	}
	$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
	if ( isset( $gateways['payshen'] ) && $gateways['payshen'] instanceof Payshen_Gateway ) {
		return $gateways['payshen'];
	}
	return new Payshen_Gateway();
}
