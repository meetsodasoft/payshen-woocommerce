<?php
/**
 * Refunds through Payshen.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends WooCommerce refunds to Payshen and keeps them in step.
 *
 * Each WooCommerce refund has its own Idempotency-Key, which also names the
 * refund at Payshen: sending it again never refunds twice, and answers the
 * refund's result (pending now, final later). That is how a pending refund
 * is settled here: the same request again, until Payshen has the answer.
 *
 * Refund meta (on the WC_Order_Refund):
 * - _payshen_refund_key      the Idempotency-Key
 * - _payshen_refund_state    pending|succeeded|failed
 * - _payshen_refund_payment  the Payshen payment refunded
 * - _payshen_refund_minor    the amount in minor units
 */
final class Payshen_Refunds {

	/**
	 * The refund WooCommerce is creating right now (set just before process_refund runs).
	 *
	 * @var WC_Order_Refund|null
	 */
	private static $creating = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_create_refund', array( __CLASS__, 'remember_creating' ), 10, 1 );
	}

	/**
	 * Remember the refund being created, to give it its own key.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 */
	public static function remember_creating( $refund ) {
		self::$creating = $refund instanceof WC_Order_Refund ? $refund : null;
	}

	/**
	 * The gateway's process_refund.
	 *
	 * @param Payshen_Gateway $gateway  Gateway.
	 * @param int             $order_id Order id.
	 * @param float|null      $amount   Amount in major units.
	 * @param string          $reason   Reason.
	 * @return bool|WP_Error
	 */
	public static function process( $gateway, $order_id, $amount, $reason ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'payshen_refund', __( 'Order not found.', 'payshen-for-woocommerce' ) );
		}
		$payment_id = (string) $order->get_meta( '_payshen_paid_payment' );
		if ( '' === $payment_id ) {
			if ( '' !== (string) $order->get_meta( '_payshen_authorized' ) ) {
				return new WP_Error( 'payshen_refund', __( 'This payment is only authorized, not captured. Cancel the order to release it, or capture it first.', 'payshen-for-woocommerce' ) );
			}
			return new WP_Error( 'payshen_refund', __( 'This order has no collected Payshen payment to refund.', 'payshen-for-woocommerce' ) );
		}
		if ( null === $amount || '' === $amount ) {
			return new WP_Error( 'payshen_refund', __( 'Enter an amount to refund.', 'payshen-for-woocommerce' ) );
		}
		$currency = (string) $order->get_currency();
		try {
			$minor = Payshen_Amount::to_minor( (string) $amount, $currency );
		} catch ( InvalidArgumentException $e ) {
			return new WP_Error( 'payshen_refund', __( 'The refund amount is not valid.', 'payshen-for-woocommerce' ) );
		}
		if ( $minor <= 0 ) {
			return new WP_Error( 'payshen_refund', __( 'The refund amount must be more than zero.', 'payshen-for-woocommerce' ) );
		}

		$client = Payshen_Order_Sync::client_for( $order, $payment_id );
		if ( ! $client ) {
			return new WP_Error( 'payshen_refund', __( 'The Payshen API key for the mode this order was paid in is missing. Add it in the Payshen settings.', 'payshen-for-woocommerce' ) );
		}

		$refund = self::find_creating( $order, $amount );
		$key    = $refund && $refund->get_id() > 0
			? Payshen_Keys::refund( $order->get_id(), $refund->get_id(), home_url() )
			: 'wc-refund-' . $order->get_id() . '-x' . bin2hex( random_bytes( 8 ) );

		$lock = Payshen_Lock::acquire( 'order_' . $order->get_id(), 60, 15 );
		if ( false === $lock ) {
			return new WP_Error( 'payshen_refund', __( 'This order is being updated. Please try the refund again in a moment.', 'payshen-for-woocommerce' ) );
		}
		try {
			$state = self::send( $client, $payment_id, $minor, $key, $order, $refund );
		} finally {
			Payshen_Lock::release( 'order_' . $order->get_id(), $lock );
		}

		$money = wc_price( Payshen_Amount::from_minor( $minor, $currency ), array( 'currency' => $currency ) );
		$money = wp_strip_all_tags( html_entity_decode( $money, ENT_QUOTES, 'UTF-8' ) );

		if ( $state instanceof WP_Error ) {
			return $state;
		}
		if ( 'succeeded' === $state ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: amount, 2: payment id, 3: reason. */
					__( 'Refunded %1$s through Payshen (payment %2$s).%3$s', 'payshen-for-woocommerce' ),
					$money,
					$payment_id,
					'' !== (string) $reason ? ' ' . sprintf( /* translators: %s: reason. */ __( 'Reason: %s', 'payshen-for-woocommerce' ), $reason ) : ''
				)
			);
			return true;
		}

		// Pending: accepted, not confirmed yet.
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: payment id. */
				__( 'Refund of %1$s sent through Payshen (payment %2$s) and waiting for the provider to confirm it. Do not refund it again: this order is updated when it is confirmed, and you will see a note if it fails.', 'payshen-for-woocommerce' ),
				$money,
				$payment_id
			)
		);
		if ( $refund && $refund->get_id() > 0 ) {
			Payshen_Scheduler::schedule_refund_checks( $order->get_id(), $refund->get_id() );
		}
		return true;
	}

	/**
	 * Send one refund and record what Payshen answered.
	 *
	 * @param Payshen_API_Client   $client     Client.
	 * @param string               $payment_id Payment id.
	 * @param int                  $minor      Minor units.
	 * @param string               $key        Idempotency-Key.
	 * @param WC_Order             $order      Order.
	 * @param WC_Order_Refund|null $refund     The WooCommerce refund.
	 * @return string|WP_Error 'succeeded', 'pending', or the refusal.
	 */
	private static function send( $client, $payment_id, $minor, $key, $order, $refund ) {
		$state = 'pending';
		$error = null;
		try {
			$client->refund( $payment_id, $minor, $key );
			$state = 'succeeded';
		} catch ( Payshen_API_Exception $e ) {
			Payshen_Logger::log( self::is_pending_answer( $e ) ? 'info' : 'warning', 'Refund answer', Payshen_Gateway::error_context( $e, $order, $payment_id ) );
			if ( ! self::is_pending_answer( $e ) ) {
				$state = 'failed';
				$error = $e;
			}
		}

		if ( $refund ) {
			$refund->update_meta_data( '_payshen_refund_key', $key );
			$refund->update_meta_data( '_payshen_refund_state', $state );
			$refund->update_meta_data( '_payshen_refund_payment', $payment_id );
			$refund->update_meta_data( '_payshen_refund_minor', (int) $minor );
			if ( $refund->get_id() > 0 ) {
				$refund->save();
			}
		}

		if ( 'failed' === $state ) {
			$message = 'psp_error' === $error->error_code
				/* translators: %s: the provider's message. */
				? sprintf( __( 'The payment provider refused the refund: %s', 'payshen-for-woocommerce' ), $error->getMessage() )
				/* translators: %s: Payshen's message. */
				: sprintf( __( 'Payshen refused the refund: %s', 'payshen-for-woocommerce' ), $error->getMessage() );
			return new WP_Error( 'payshen_refund', $message );
		}
		return $state;
	}

	/**
	 * Whether an error answer means "accepted or maybe done, not confirmed": never refund again under a new key.
	 *
	 * @param Payshen_API_Exception $e Error.
	 * @return bool
	 */
	public static function is_pending_answer( Payshen_API_Exception $e ) {
		if ( in_array( $e->error_code, array( 'refund_pending', 'refund_result_unknown', 'state_unconfirmed', 'idempotency_in_progress' ), true ) ) {
			return true;
		}
		if ( $e->is_network_error() ) {
			return true;
		}
		// A 5xx with no code, or one that may hide a refund, is not a refusal.
		return $e->status >= 500 && ! in_array( $e->error_code, array( 'internal_error', 'provider_unavailable', 'psp_error', 'unsupported' ), true );
	}

	/**
	 * The WooCommerce refund being created for this call.
	 *
	 * @param WC_Order $order  Order.
	 * @param mixed    $amount Amount.
	 * @return WC_Order_Refund|null
	 */
	private static function find_creating( $order, $amount ) {
		$refund         = self::$creating;
		self::$creating = null;
		if ( $refund && (int) $refund->get_parent_id() === (int) $order->get_id() && abs( (float) $refund->get_amount() - (float) $amount ) < 0.00001 ) {
			return $refund;
		}
		foreach ( $order->get_refunds() as $candidate ) {
			if ( '' === (string) $candidate->get_meta( '_payshen_refund_key' ) && abs( (float) $candidate->get_amount() - (float) $amount ) < 0.00001 ) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * Ask again about one pending refund (same key, same amount). The caller holds the order's lock.
	 *
	 * @param WC_Order        $order  Order.
	 * @param WC_Order_Refund $refund Refund.
	 * @param bool            $last   Whether this is the last scheduled check.
	 * @return string pending|succeeded|failed|skipped
	 */
	public static function resolve( $order, $refund, $last = false ) {
		if ( 'pending' !== (string) $refund->get_meta( '_payshen_refund_state' ) ) {
			return 'skipped';
		}
		$key        = (string) $refund->get_meta( '_payshen_refund_key' );
		$payment_id = (string) $refund->get_meta( '_payshen_refund_payment' );
		$minor      = (int) $refund->get_meta( '_payshen_refund_minor' );
		if ( '' === $key || '' === $payment_id || $minor <= 0 ) {
			return 'skipped';
		}
		$client = Payshen_Order_Sync::client_for( $order, $payment_id );
		if ( ! $client ) {
			return 'pending';
		}

		$currency = (string) $order->get_currency();
		$money    = Payshen_Amount::from_minor( $minor, $currency ) . ' ' . $currency;
		try {
			$client->refund( $payment_id, $minor, $key );
		} catch ( Payshen_API_Exception $e ) {
			if ( self::is_pending_answer( $e ) ) {
				if ( $last ) {
					$order->add_order_note(
						/* translators: %s: amount. */
						sprintf( __( 'The refund of %s is still not confirmed after 48 hours. Check it in Payshen.', 'payshen-for-woocommerce' ), $money )
					);
				}
				return 'pending';
			}
			$refund->update_meta_data( '_payshen_refund_state', 'failed' );
			$refund->save();
			self::undo_failed( $order, $refund, $money, $e->getMessage() );
			return 'failed';
		}

		$refund->update_meta_data( '_payshen_refund_state', 'succeeded' );
		$refund->save();
		/* translators: %s: amount. */
		$order->add_order_note( sprintf( __( 'The provider confirmed the refund of %s.', 'payshen-for-woocommerce' ), $money ) );
		return 'succeeded';
	}

	/**
	 * A refund WooCommerce counted that did not go through: take it off the order.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WC_Order_Refund $refund  Refund.
	 * @param string          $money   Amount in words.
	 * @param string          $message Why.
	 */
	private static function undo_failed( $order, $refund, $money, $message ) {
		$refund_id = $refund->get_id();
		$refund->delete( true );
		$order = wc_get_order( $order->get_id() );
		if ( ! $order ) {
			return;
		}
		$note = sprintf(
			/* translators: 1: amount, 2: refund id, 3: reason. */
			__( 'Warning: the refund of %1$s (refund #%2$d) did not go through: %3$s The customer was not refunded, and the refund was removed from this order. Restocked items were not changed back.', 'payshen-for-woocommerce' ),
			$money,
			$refund_id,
			rtrim( (string) $message, '.' ) . '.'
		);
		if ( $order->has_status( 'refunded' ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
		Payshen_Logger::warning(
			'Refund failed after it was accepted',
			array(
				'order_id'  => $order->get_id(),
				'refund_id' => $refund_id,
			)
		);
	}

	/**
	 * Settle pending refunds and record refunds made in Payshen itself. The caller holds the order's lock.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payment The payment as Payshen reports it.
	 */
	public static function sync_from_payment( $order, array $payment ) {
		$payment_id = (string) ( $payment['id'] ?? '' );
		if ( '' === $payment_id ) {
			return;
		}

		foreach ( $order->get_refunds() as $refund ) {
			if ( 'pending' === (string) $refund->get_meta( '_payshen_refund_state' ) && (string) $refund->get_meta( '_payshen_refund_payment' ) === $payment_id ) {
				self::resolve( $order, $refund );
			}
		}

		if ( ! isset( $payment['refunded_amount'] ) ) {
			return;
		}
		$order    = wc_get_order( $order->get_id() );
		$currency = (string) $order->get_currency();
		$payshen  = (int) $payment['refunded_amount'] + (int) ( $payment['refund_pending_amount'] ?? 0 );
		try {
			$store = Payshen_Amount::to_minor( (string) $order->get_total_refunded(), $currency );
		} catch ( InvalidArgumentException $e ) {
			return;
		}
		$missing = $payshen - $store;
		if ( $missing <= 0 ) {
			return;
		}

		// Refunded in Payshen (the dashboard or the API), not from this store: record it here.
		$amount         = Payshen_Amount::from_minor( $missing, $currency );
		$refund         = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => __( 'Refunded in Payshen', 'payshen-for-woocommerce' ),
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);
		self::$creating = null;
		if ( is_wp_error( $refund ) ) {
			Payshen_Logger::error(
				'Could not record a refund made in Payshen',
				array(
					'order_id' => $order->get_id(),
					'error'    => $refund->get_error_message(),
				)
			);
			return;
		}
		$refund->update_meta_data( '_payshen_refund_state', 'succeeded' );
		$refund->update_meta_data( '_payshen_refund_payment', $payment_id );
		$refund->update_meta_data( '_payshen_refund_minor', $missing );
		$refund->update_meta_data( '_payshen_refund_source', 'payshen' );
		$refund->save();
		$order = wc_get_order( $order->get_id() );
		if ( $order ) {
			$order->add_order_note(
				/* translators: 1: amount and currency, 2: payment id. */
				sprintf( __( '%1$s was refunded in Payshen (payment %2$s) and recorded here. Stock was not changed.', 'payshen-for-woocommerce' ), $amount . ' ' . $currency, $payment_id )
			);
		}
	}
}
