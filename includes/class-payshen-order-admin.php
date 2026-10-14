<?php
/**
 * Capture, void and status checks from the order screen.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manual capture: capture when an authorized order is set to Processing or
 * Completed, or with the "Capture payment (Payshen)" order action; release
 * the authorization when the order is cancelled. Both are idempotent: each
 * payment has one capture key and one void key, so a repeat is answered
 * from the first result and never acts twice.
 *
 * WooCommerce checks the capability and nonce of the order screen before
 * order actions and status changes run.
 */
final class Payshen_Order_Admin {

	/**
	 * Orders this request is capturing or voiding, so a status change we make does not start another.
	 *
	 * @var array<int, bool>
	 */
	private static $busy = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_payshen_capture', array( __CLASS__, 'action_capture' ) );
		add_action( 'woocommerce_order_action_payshen_check', array( __CLASS__, 'action_check' ) );
		// Before the status emails, which are sent from the "<from>_to_<to>" hooks.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid_status' ), 5, 2 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid_status' ), 5, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_cancelled' ), 5, 2 );
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( __CLASS__, 'hold_paid_email' ), 10, 2 );
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( __CLASS__, 'hold_paid_email' ), 10, 2 );
	}

	/**
	 * Add the Payshen order actions.
	 *
	 * @param array         $actions Actions.
	 * @param WC_Order|null $order   Order (WooCommerce 7.6+ passes it).
	 * @return array
	 */
	public static function order_actions( $actions, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			global $theorder;
			$order = $theorder instanceof WC_Order ? $theorder : null;
		}
		if ( ! $order || Payshen_Gateway::ID !== $order->get_payment_method() || '' === (string) $order->get_meta( '_payshen_payment_id' ) ) {
			return $actions;
		}
		if ( '' !== (string) $order->get_meta( '_payshen_authorized' ) && ! Payshen_Order_Sync::is_paid( $order ) ) {
			$actions['payshen_capture'] = __( 'Capture payment (Payshen)', 'payshen-for-woocommerce' );
		}
		$actions['payshen_check'] = __( 'Check payment status (Payshen)', 'payshen-for-woocommerce' );
		return $actions;
	}

	/**
	 * Order action: capture.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function action_capture( $order ) {
		self::capture( $order, false );
	}

	/**
	 * Order action: read the payment now.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function action_check( $order ) {
		try {
			$action = Payshen_Order_Sync::sync( $order->get_id(), '', 'admin', 10 );
		} catch ( Payshen_API_Exception $e ) {
			Payshen_Logger::error( 'Status check failed', Payshen_Gateway::error_context( $e, $order ) );
			/* translators: %s: error message. */
			$order->add_order_note( sprintf( __( 'Could not check the payment with Payshen: %s', 'payshen-for-woocommerce' ), $e->getMessage() ) );
			return;
		}
		$fresh = wc_get_order( $order->get_id() );
		$info  = $fresh ? Payshen_Order_Sync::payment_info( $fresh, (string) $fresh->get_meta( '_payshen_payment_id' ) ) : array();
		if ( 'locked' === $action ) {
			$note = __( 'The order was being updated at the same moment. Check again in a moment.', 'payshen-for-woocommerce' );
		} else {
			/* translators: %s: Payshen status. */
			$note = sprintf( __( 'Checked with Payshen: the payment is %s.', 'payshen-for-woocommerce' ), $info['status'] ?? '?' );
		}
		if ( $fresh ) {
			$fresh->add_order_note( $note );
		}
	}

	/**
	 * Capture when an authorized order is set to Processing or Completed.
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order.
	 */
	public static function on_paid_status( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || Payshen_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}
		if ( '' === (string) $order->get_meta( '_payshen_authorized' ) || '' !== (string) $order->get_meta( '_payshen_paid_payment' ) ) {
			return;
		}
		self::capture( $order, true );
	}

	/**
	 * Capture the authorized payment.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $status_change Whether a status change asked; on failure the order goes back on hold.
	 */
	public static function capture( $order, $status_change ) {
		$order_id   = $order->get_id();
		$payment_id = (string) $order->get_meta( '_payshen_authorized' );
		if ( '' === $payment_id || ! empty( self::$busy[ $order_id ] ) ) {
			return;
		}
		if ( Payshen_Order_Sync::is_paid( $order ) && '' !== (string) $order->get_meta( '_payshen_paid_payment' ) ) {
			return;
		}
		self::$busy[ $order_id ] = true;

		$lock = Payshen_Lock::acquire( 'order_' . $order_id, 60, 15 );
		try {
			if ( false === $lock ) {
				self::capture_not_done( $order, $status_change, __( 'The order was being updated at the same moment. Try the capture again.', 'payshen-for-woocommerce' ) );
				return;
			}
			$client = Payshen_Order_Sync::client_for( $order, $payment_id );
			if ( ! $client ) {
				self::capture_not_done( $order, $status_change, __( 'The Payshen API key for this order\'s mode is missing.', 'payshen-for-woocommerce' ) );
				return;
			}

			// Capture the order total when it went down after checkout; never more than was authorized.
			$info     = Payshen_Order_Sync::payment_info( $order, $payment_id );
			$currency = (string) ( $info['currency'] ?? $order->get_currency() );
			$amount   = null;
			try {
				$total = Payshen_Amount::to_minor( (string) $order->get_total( 'edit' ), $currency );
			} catch ( InvalidArgumentException $e ) {
				$total = 0;
			}
			if ( $total > 0 && isset( $info['amount'] ) && $total < (int) $info['amount'] ) {
				$amount = $total;
			}

			try {
				$result = $client->capture( $payment_id, $amount, Payshen_Keys::capture( $payment_id ) );
			} catch ( Payshen_API_Exception $e ) {
				Payshen_Logger::warning( 'Capture answer', Payshen_Gateway::error_context( $e, $order, $payment_id ) );
				self::capture_error( $order, $client, $payment_id, $e, $status_change );
				return;
			}

			$status = strtoupper( (string) ( $result['status'] ?? '' ) );
			if ( 'PENDING' === $status ) {
				if ( $status_change && $order->has_status( array( 'processing', 'completed' ) ) ) {
					// The status the merchant chose comes back when the provider confirms.
					$order->update_meta_data( '_payshen_capture_target', $order->get_status() );
					$order->save();
				}
				self::capture_not_done(
					$order,
					$status_change,
					__( 'The provider accepted the capture and has not confirmed it yet. The order moves on when it does.', 'payshen-for-woocommerce' ),
					false
				);
				Payshen_Scheduler::schedule_checks( $order_id );
				return;
			}

			// Read the whole payment and apply it like any other update.
			try {
				$payment = $client->get_payment( $payment_id );
			} catch ( Payshen_API_Exception $e ) {
				$payment = $result;
			}
			Payshen_Order_Sync::apply( $order, $payment, 'capture' );
		} finally {
			if ( false !== $lock ) {
				Payshen_Lock::release( 'order_' . $order_id, $lock );
			}
			unset( self::$busy[ $order_id ] );
		}
	}

	/**
	 * A capture Payshen did not do.
	 *
	 * @param WC_Order              $order         Order.
	 * @param Payshen_API_Client    $client        Client.
	 * @param string                $payment_id    Payment id.
	 * @param Payshen_API_Exception $e             Error.
	 * @param bool                  $status_change From a status change.
	 */
	private static function capture_error( $order, $client, $payment_id, Payshen_API_Exception $e, $status_change ) {
		if ( in_array( $e->error_code, array( 'not_capturable', 'capture_conflict' ), true ) ) {
			// Maybe captured or released already: read it and apply what Payshen holds.
			try {
				$payment = $client->get_payment( $payment_id );
			} catch ( Payshen_API_Exception $read ) {
				$payment = null;
			}
			if ( $payment ) {
				$action = Payshen_Order_Sync::apply( $order, $payment, 'capture' );
				if ( Payshen_Status::PAID === $action ) {
					return;
				}
			}
			/* translators: %s: Payshen's message. */
			self::capture_not_done( $order, $status_change, sprintf( __( 'Payshen could not capture the payment: %s', 'payshen-for-woocommerce' ), $e->getMessage() ) );
			return;
		}
		if ( in_array( $e->error_code, array( 'capture_result_unknown', 'state_unconfirmed', 'state_conflict' ), true ) || $e->is_network_error() || ( $e->status >= 500 && 'psp_error' !== $e->error_code && 'provider_unavailable' !== $e->error_code ) ) {
			self::capture_not_done(
				$order,
				$status_change,
				__( 'Payshen could not confirm the capture yet. Do not ship and do not capture again: the order updates by itself once Payshen knows the result.', 'payshen-for-woocommerce' )
			);
			Payshen_Scheduler::schedule_checks( $order->get_id() );
			return;
		}
		$message = 'psp_error' === $e->error_code
			/* translators: %s: provider's message. */
			? sprintf( __( 'The provider refused the capture: %s The payment is still authorized.', 'payshen-for-woocommerce' ), rtrim( $e->getMessage(), '.' ) . '.' )
			/* translators: %s: Payshen's message. */
			: sprintf( __( 'The capture failed: %s', 'payshen-for-woocommerce' ), $e->getMessage() );
		self::capture_not_done( $order, $status_change, $message );
	}

	/**
	 * Say why the payment is not captured, and keep the order on hold.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $status_change From a status change.
	 * @param string   $why           Note.
	 * @param bool     $is_error      Whether it is an error (logged).
	 */
	private static function capture_not_done( $order, $status_change, $why, $is_error = true ) {
		$note = $why;
		if ( $status_change && ! $order->has_status( 'on-hold' ) ) {
			$note .= ' ' . __( 'The order was put back on hold.', 'payshen-for-woocommerce' );
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
		if ( $is_error ) {
			Payshen_Logger::warning( 'Capture not done', array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Hold the "processing" and "completed" emails of an order that went back on hold because its capture failed.
	 *
	 * @param bool          $enabled Enabled.
	 * @param WC_Order|null $order   Order.
	 * @return bool
	 */
	public static function hold_paid_email( $enabled, $order ) {
		if ( ! $enabled || ! $order instanceof WC_Order || Payshen_Gateway::ID !== $order->get_payment_method() ) {
			return $enabled;
		}
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh && '' !== (string) $fresh->get_meta( '_payshen_authorized' ) && ! $fresh->has_status( array( 'processing', 'completed' ) ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Release the authorization when an authorized order is cancelled.
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order.
	 */
	public static function on_cancelled( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || Payshen_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}
		$payment_id = (string) $order->get_meta( '_payshen_authorized' );
		if ( '' === $payment_id || '' !== (string) $order->get_meta( '_payshen_paid_payment' ) || ! empty( self::$busy[ $order_id ] ) ) {
			return;
		}
		self::$busy[ $order_id ] = true;
		$lock                    = Payshen_Lock::acquire( 'order_' . $order_id, 60, 15 );
		try {
			if ( false === $lock ) {
				$order->add_order_note( __( 'The authorization was not released because the order was being updated at the same moment. Release it in Payshen.', 'payshen-for-woocommerce' ) );
				return;
			}
			$client = Payshen_Order_Sync::client_for( $order, $payment_id );
			if ( ! $client ) {
				$order->add_order_note( __( 'The authorization was not released: the Payshen API key for this order\'s mode is missing. Release it in Payshen.', 'payshen-for-woocommerce' ) );
				return;
			}
			try {
				$result = $client->void( $payment_id, Payshen_Keys::void( $payment_id ) );
			} catch ( Payshen_API_Exception $e ) {
				Payshen_Logger::warning( 'Void answer', Payshen_Gateway::error_context( $e, $order, $payment_id ) );
				self::void_error( $order, $client, $payment_id, $e );
				return;
			}
			$status = strtoupper( (string) ( $result['status'] ?? '' ) );
			if ( 'PENDING' === $status ) {
				/* translators: %s: payment id. */
				$order->add_order_note( sprintf( __( 'Release of the authorization of Payshen payment %s was accepted; the provider confirms it shortly.', 'payshen-for-woocommerce' ), $payment_id ) );
				return;
			}
			$order->delete_meta_data( '_payshen_authorized' );
			$order->save();
			/* translators: %s: payment id. */
			$order->add_order_note( sprintf( __( 'The authorization of Payshen payment %s was released. Nothing was charged.', 'payshen-for-woocommerce' ), $payment_id ) );
		} finally {
			if ( false !== $lock ) {
				Payshen_Lock::release( 'order_' . $order_id, $lock );
			}
			unset( self::$busy[ $order_id ] );
		}
	}

	/**
	 * A void Payshen did not do.
	 *
	 * @param WC_Order              $order      Order.
	 * @param Payshen_API_Client    $client     Client.
	 * @param string                $payment_id Payment id.
	 * @param Payshen_API_Exception $e          Error.
	 */
	private static function void_error( $order, $client, $payment_id, Payshen_API_Exception $e ) {
		if ( in_array( $e->error_code, array( 'not_voidable', 'void_conflict' ), true ) ) {
			try {
				$payment = $client->get_payment( $payment_id );
			} catch ( Payshen_API_Exception $read ) {
				$payment = null;
			}
			$action = $payment ? Payshen_Status::action( $payment['status'] ?? '' ) : '';
			if ( Payshen_Status::CANCELLED === $action ) {
				$order->delete_meta_data( '_payshen_authorized' );
				$order->save();
				/* translators: %s: payment id. */
				$order->add_order_note( sprintf( __( 'The authorization of Payshen payment %s was already released.', 'payshen-for-woocommerce' ), $payment_id ) );
				return;
			}
			if ( Payshen_Status::PAID === $action ) {
				// Captured after all. The order stays cancelled, as it was set; the money is not returned by itself.
				$order->update_meta_data( '_payshen_paid_payment', $payment_id );
				$order->delete_meta_data( '_payshen_authorized' );
				$order->save();
				/* translators: %s: payment id. */
				$order->add_order_note( sprintf( __( 'Warning: Payshen payment %s was already captured, so it could not be released. Refund it if this order stays cancelled.', 'payshen-for-woocommerce' ), $payment_id ) );
				return;
			}
		}
		if ( in_array( $e->error_code, array( 'void_result_unknown', 'state_unconfirmed', 'state_conflict' ), true ) || $e->is_network_error() ) {
			/* translators: %s: payment id. */
			$order->add_order_note( sprintf( __( 'Payshen could not confirm the release of payment %s yet. Do not release it again; check it in Payshen later.', 'payshen-for-woocommerce' ), $payment_id ) );
			return;
		}
		/* translators: 1: payment id, 2: message. */
		$order->add_order_note( sprintf( __( 'The authorization of Payshen payment %1$s was not released: %2$s Release it in Payshen.', 'payshen-for-woocommerce' ), $payment_id, rtrim( $e->getMessage(), '.' ) . '.' ) );
	}
}
