<?php
/**
 * The customer's way back from Payshen's page.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Applies the payment when the customer comes back, and says where it stands.
 *
 * The browser is never trusted: Payshen adds `status` to the return URL, and
 * it is ignored. The payment is read from the API and applied like any other
 * update. Returns soon after paying often still read REQUIRES_ACTION (the
 * provider confirms a moment later); the page then says the payment is being
 * confirmed, and the webhook or a scheduled check finishes the order.
 */
final class Payshen_Return_Handler {

	/**
	 * Hooks.
	 */
	public static function init() {
		// Before WooCommerce empties the cart on the order received page (priority 20).
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 5 );
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'received_text' ), 10, 2 );
	}

	/**
	 * Route the request.
	 */
	public static function maybe_handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A redirect back from Payshen carries no nonce; the order key and a signed token are checked instead.
		if ( isset( $_GET['payshen_cancel'], $_GET['payshen_token'] ) ) {
			$order_id = absint( wp_unslash( $_GET['payshen_cancel'] ) );
			$token    = sanitize_text_field( wp_unslash( $_GET['payshen_token'] ) );
			self::handle_cancel( $order_id, $token );
			return;
		}
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$token = isset( $_GET['payshen_return'] ) ? sanitize_text_field( wp_unslash( $_GET['payshen_return'] ) ) : '';
			$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
			self::handle_return( absint( get_query_var( 'order-received' ) ), $key, $token );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The order received page of a Payshen order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $key      Order key from the URL.
	 * @param string $token    Return token from the URL, or ''.
	 */
	private static function handle_return( $order_id, $key, $token ) {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order || Payshen_Gateway::ID !== $order->get_payment_method() || ! hash_equals( $order->get_order_key(), $key ) ) {
			return;
		}
		if ( Payshen_Order_Sync::is_paid( $order ) || '' === (string) $order->get_meta( '_payshen_payment_id' ) ) {
			return;
		}

		$genuine = Payshen_Keys::check_return_token( $token, $order->get_id(), $order->get_order_key(), wp_salt( 'auth' ) );
		// A reload of the page is answered from the last look for a few seconds,
		// genuine return or not, so repeated hits never queue API reads.
		$throttle = 'payshen_seen_' . $order->get_id();
		if ( false !== get_transient( $throttle ) ) {
			return;
		}
		set_transient( $throttle, 1, 10 );

		// Just back from paying: the provider usually confirms within seconds.
		$tries  = $genuine ? 3 : 1;
		$action = self::sync_for_customer( $order->get_id(), $tries );

		$order = wc_get_order( $order->get_id() );
		if ( ! $order ) {
			return;
		}
		if ( Payshen_Status::DECLINED === $action || Payshen_Status::FAILED === $action ) {
			if ( $order->has_status( 'failed' ) && $order->needs_payment() ) {
				wc_add_notice( __( 'Your payment was not accepted. Please try again or choose another payment method.', 'payshen-for-woocommerce' ), 'error' );
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;
			}
		}
		if ( Payshen_Status::CANCELLED === $action && $order->has_status( 'cancelled' ) ) {
			wc_add_notice( __( 'Your payment was not completed, so the order was cancelled. You can place it again.', 'payshen-for-woocommerce' ), 'notice' );
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}

	/**
	 * The customer gave up on Payshen's page.
	 *
	 * @param int    $order_id Order id.
	 * @param string $token    Token from the URL.
	 */
	private static function handle_cancel( $order_id, $token ) {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		$back  = remove_query_arg( array( 'payshen_cancel', 'payshen_token', 'payment_id', 'reference', 'status' ) );
		if ( ! $order || Payshen_Gateway::ID !== $order->get_payment_method()
			|| ! Payshen_Keys::check_return_token( $token, $order->get_id(), $order->get_order_key(), wp_salt( 'auth' ) ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		// The cancel link is the customer's own and works until the order is
		// paid, so a reload loop must not turn into one API read per hit (each
		// spends the store's Payshen rate limit). The same few seconds' memory
		// as the return.
		$throttle = 'payshen_seen_' . $order->get_id();
		if ( ! Payshen_Order_Sync::is_paid( $order ) && false === get_transient( $throttle ) ) {
			set_transient( $throttle, 1, 10 );
			self::sync_for_customer( $order->get_id(), 1 );
		}
		$order = wc_get_order( $order->get_id() );

		if ( Payshen_Order_Sync::is_paid( $order ) || $order->has_status( 'on-hold' ) ) {
			// Paid after all (another tab, or the provider confirmed): show the order.
			wp_safe_redirect( $order->get_checkout_order_received_url() );
			exit;
		}

		wc_add_notice( __( 'Your payment was not completed. You can try again or choose another payment method.', 'payshen-for-woocommerce' ), 'notice' );
		if ( $order->needs_payment() && ( ! WC()->cart || WC()->cart->is_empty() ) ) {
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}
		wp_safe_redirect( WC()->cart && ! WC()->cart->is_empty() ? wc_get_checkout_url() : wc_get_cart_url() );
		exit;
	}

	/**
	 * Read the payment, a few times while it is still waiting.
	 *
	 * @param int $order_id Order id.
	 * @param int $tries    How many reads at most.
	 * @return string The last action, or '' when nothing could be read.
	 */
	private static function sync_for_customer( $order_id, $tries ) {
		$action = '';
		for ( $i = 0; $i < $tries; $i++ ) {
			if ( $i > 0 ) {
				sleep( 2 );
			}
			try {
				$action = Payshen_Order_Sync::sync( $order_id, '', 'return', 5 );
			} catch ( Payshen_API_Exception $e ) {
				Payshen_Logger::error( 'Return could not read the payment', Payshen_Gateway::error_context( $e, null ) + array( 'order_id' => $order_id ) );
				return '';
			}
			if ( ! in_array( $action, array( Payshen_Status::AWAITING_CUSTOMER, Payshen_Status::IN_FLIGHT, 'locked' ), true ) ) {
				break;
			}
		}
		return $action;
	}

	/**
	 * The order received text, while the payment is not confirmed yet.
	 *
	 * @param string        $text  Text.
	 * @param WC_Order|null $order Order.
	 * @return string
	 */
	public static function received_text( $text, $order ) {
		if ( ! $order instanceof WC_Order || Payshen_Gateway::ID !== $order->get_payment_method() ) {
			return $text;
		}
		if ( '' !== (string) $order->get_meta( '_payshen_authorized' ) && ! Payshen_Order_Sync::is_paid( $order ) ) {
			return esc_html__( 'Thank you. Your payment is approved and your order has been received.', 'payshen-for-woocommerce' );
		}
		if ( ! Payshen_Order_Sync::is_paid( $order ) && $order->has_status( 'pending' ) && $order->needs_payment() ) {
			// Still waiting for the customer: they may have left the payment page.
			// Paying again resumes the same Payshen payment, so it cannot be paid twice.
			return sprintf(
				/* translators: %s: link to pay for the order. */
				esc_html__( 'We have not received your payment yet. If you left the payment page, %s. If you did pay, you will get an email as soon as the payment is confirmed.', 'payshen-for-woocommerce' ),
				'<a href="' . esc_url( $order->get_checkout_payment_url() ) . '">' . esc_html__( 'you can pay now', 'payshen-for-woocommerce' ) . '</a>'
			);
		}
		if ( ! Payshen_Order_Sync::is_paid( $order ) && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
			return esc_html__( 'Thank you. Your order has been received and we are waiting for your payment to be confirmed. You will get an email as soon as it is.', 'payshen-for-woocommerce' );
		}
		return $text;
	}
}
