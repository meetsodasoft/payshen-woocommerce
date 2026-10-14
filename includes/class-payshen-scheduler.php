<?php
/**
 * Scheduled checks with Payshen.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Re-reads payments and pending refunds on a schedule (Action Scheduler),
 * so a webhook that never arrives never leaves an order wrong.
 *
 * Checks run 5 minutes, 30 minutes, 2 hours, 12 hours and 48 hours after a
 * payment or refund starts, and stop as soon as Payshen has a final answer.
 */
final class Payshen_Scheduler {

	const GROUP        = 'payshen';
	const PAYMENT_HOOK = 'payshen_check_payment';
	const REFUND_HOOK  = 'payshen_check_refund';

	/** Seconds after the start for each check. */
	const STEPS = array( 300, 1800, 7200, 43200, 172800 );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::PAYMENT_HOOK, array( __CLASS__, 'run_payment_check' ), 10, 2 );
		add_action( self::REFUND_HOOK, array( __CLASS__, 'run_refund_check' ), 10, 4 );
	}

	/**
	 * Start the checks for an order's current payment, replacing earlier ones.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    The order object the caller saves next, if any.
	 */
	public static function schedule_checks( $order_id, $order = null ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$order_id = (int) $order_id;
		self::unschedule_payment_checks( $order_id );
		if ( $order instanceof WC_Order ) {
			$order->update_meta_data( '_payshen_checks_from', time() );
		} else {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( '_payshen_checks_from', time() );
				$order->save();
			}
		}
		as_schedule_single_action( time() + self::STEPS[0], self::PAYMENT_HOOK, array( $order_id, 0 ), self::GROUP );
	}

	/**
	 * Remove an order's pending payment checks.
	 *
	 * @param int $order_id Order id.
	 */
	public static function unschedule_payment_checks( $order_id ) {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		foreach ( array_keys( self::STEPS ) as $step ) {
			as_unschedule_all_actions( self::PAYMENT_HOOK, array( (int) $order_id, (int) $step ), self::GROUP );
		}
	}

	/**
	 * One payment check.
	 *
	 * @param int $order_id Order id.
	 * @param int $step     Index into STEPS.
	 */
	public static function run_payment_check( $order_id, $step = 0 ) {
		$order_id = (int) $order_id;
		$step     = (int) $step;
		$order    = wc_get_order( $order_id );
		if ( ! $order || Payshen_Gateway::ID !== $order->get_payment_method() ) {
			return;
		}
		if ( Payshen_Order_Sync::is_paid( $order ) ) {
			return;
		}
		$start = (int) $order->get_meta( '_payshen_checks_from' );
		$start = $start > 0 ? $start : time();

		$action = 'error';
		try {
			$action = Payshen_Order_Sync::sync( $order_id, '', 'schedule', 20 );
		} catch ( Payshen_API_Exception $e ) {
			Payshen_Logger::error( 'Scheduled check could not read the payment', Payshen_Gateway::error_context( $e, $order ) );
			if ( 404 === $e->status ) {
				return;
			}
		}

		if ( Payshen_Status::is_settled( $action ) || in_array( $action, array( 'no_payment', 'no_client' ), true ) ) {
			return;
		}
		$next = $step + 1;
		if ( ! isset( self::STEPS[ $next ] ) ) {
			$order = wc_get_order( $order_id );
			if ( $order && ! Payshen_Order_Sync::is_paid( $order ) && $order->has_status( 'on-hold' ) ) {
				$order->add_order_note( __( 'Payshen still has no final answer for this payment after 48 hours. Check it in Payshen before shipping.', 'payshen-for-woocommerce' ) );
			}
			return;
		}
		as_schedule_single_action( max( time() + 60, $start + self::STEPS[ $next ] ), self::PAYMENT_HOOK, array( $order_id, $next ), self::GROUP );
	}

	/**
	 * Start the checks for a pending refund.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id WooCommerce refund id.
	 */
	public static function schedule_refund_checks( $order_id, $refund_id ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$start = time();
		as_schedule_single_action( $start + self::STEPS[0], self::REFUND_HOOK, array( (int) $order_id, (int) $refund_id, 0, $start ), self::GROUP );
	}

	/**
	 * One pending refund check.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @param int $step      Index into STEPS.
	 * @param int $start     When the checks started.
	 */
	public static function run_refund_check( $order_id, $refund_id, $step = 0, $start = 0 ) {
		$order  = wc_get_order( (int) $order_id );
		$refund = wc_get_order( (int) $refund_id );
		if ( ! $order || ! $refund instanceof WC_Order_Refund || (int) $refund->get_parent_id() !== (int) $order_id ) {
			return;
		}
		$step  = (int) $step;
		$start = (int) $start > 0 ? (int) $start : time();
		$last  = ! isset( self::STEPS[ $step + 1 ] );

		$lock = Payshen_Lock::acquire( 'order_' . (int) $order_id, 60, 20 );
		if ( false === $lock ) {
			$state = 'pending';
		} else {
			try {
				$state = Payshen_Refunds::resolve( $order, $refund, $last );
			} finally {
				Payshen_Lock::release( 'order_' . (int) $order_id, $lock );
			}
		}

		if ( 'pending' === $state && ! $last ) {
			as_schedule_single_action( max( time() + 60, $start + self::STEPS[ $step + 1 ] ), self::REFUND_HOOK, array( (int) $order_id, (int) $refund_id, $step + 1, $start ), self::GROUP );
		}
	}
}
