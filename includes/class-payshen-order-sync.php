<?php
/**
 * Keeps an order in step with its Payshen payment.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one place an order's status changes because of a payment.
 *
 * The customer's return, a webhook and the scheduled checks all end here,
 * always with the payment as the Payshen API reports it, never as a browser
 * or a webhook body says. Each transition is idempotent: applying the same
 * status twice changes nothing and writes no second note.
 *
 * Order meta (HPOS-safe, through the order CRUD):
 * - _payshen_payment_id     the current attempt's payment
 * - _payshen_payment_ref    every payment made for the order (one row each; used to find the order)
 * - _payshen_payments       per payment: mode, attempt, amount, currency, capture method, last status
 * - _payshen_mode           test|live of the current payment
 * - _payshen_attempt        the current attempt number
 * - _payshen_paid_payment   the payment that paid the order
 * - _payshen_authorized     a payment authorized and not captured yet (manual capture)
 */
final class Payshen_Order_Sync {

	/**
	 * Read the payment from Payshen and apply it to its order, under the order's lock.
	 *
	 * @param int    $order_id   Order id.
	 * @param string $payment_id Payment id ('' for the order's current one).
	 * @param string $source     return|webhook|schedule|admin|cancel.
	 * @param int    $wait       Seconds to wait for the lock.
	 * @return string An action from Payshen_Status, or 'locked', 'no_payment', 'no_client'.
	 * @throws Payshen_API_Exception When Payshen could not be read.
	 */
	public static function sync( $order_id, $payment_id = '', $source = 'schedule', $wait = 5 ) {
		$lock = Payshen_Lock::acquire( 'order_' . (int) $order_id, 60, $wait );
		if ( false === $lock ) {
			return 'locked';
		}
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return 'no_payment';
			}
			if ( '' === $payment_id ) {
				$payment_id = (string) $order->get_meta( '_payshen_payment_id' );
			}
			if ( '' === $payment_id || ! self::has_payment( $order, $payment_id ) ) {
				return 'no_payment';
			}
			$client = self::client_for( $order, $payment_id );
			if ( ! $client ) {
				Payshen_Logger::error(
					'No API key for the mode of this payment',
					array(
						'order_id'   => $order->get_id(),
						'payment_id' => $payment_id,
					)
				);
				return 'no_client';
			}
			$payment = $client->get_payment( $payment_id );
			Payshen_Logger::debug(
				'Payment read',
				array(
					'source'     => $source,
					'order_id'   => $order->get_id(),
					'payment_id' => $payment_id,
					'status'     => $payment['status'] ?? null,
				)
			);
			return self::apply( $order, $payment, $source );
		} finally {
			Payshen_Lock::release( 'order_' . (int) $order_id, $lock );
		}
	}

	/**
	 * Apply a payment, as Payshen reported it, to its order. The caller holds the order's lock.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payment The payment from the API.
	 * @param string   $source  What asked (kept for filters and logs).
	 * @return string An action from Payshen_Status, or 'no_payment'.
	 */
	public static function apply( $order, array $payment, $source ) {
		$payment_id = (string) ( $payment['id'] ?? ( $payment['payment_id'] ?? '' ) );
		if ( '' === $payment_id || ! self::has_payment( $order, $payment_id ) ) {
			return 'no_payment';
		}

		$status     = strtoupper( (string) ( $payment['status'] ?? '' ) );
		$action     = Payshen_Status::action( $status );
		$info       = self::payment_info( $order, $payment_id );
		$is_current = (string) $order->get_meta( '_payshen_payment_id' ) === $payment_id;
		$changed    = ( $info['status'] ?? '' ) !== $status;
		$paid_by    = (string) $order->get_meta( '_payshen_paid_payment' );

		self::set_payment_info( $order, $payment_id, array( 'status' => $status ) );

		switch ( $action ) {
			case Payshen_Status::PAID:
				self::apply_paid( $order, $payment, $payment_id, $info, $changed, $paid_by );
				break;

			case Payshen_Status::AUTHORIZED:
				self::apply_authorized( $order, $payment, $payment_id, $info, $changed, $paid_by );
				break;

			case Payshen_Status::AWAITING_CUSTOMER:
				// The customer has not finished on Payshen's page. Nothing to do.
				break;

			case Payshen_Status::IN_FLIGHT:
			case Payshen_Status::UNRECOGNISED:
				if ( $is_current && '' === $paid_by && $changed && $order->has_status( array( 'pending', 'failed' ) ) ) {
					$order->update_status(
						'on-hold',
						sprintf(
							/* translators: 1: payment id, 2: Payshen status. */
							__( 'Payshen payment %1$s is %2$s: the provider has not confirmed it yet. Do not ship until it is paid. The order updates by itself.', 'payshen-for-woocommerce' ),
							$payment_id,
							$status
						)
					);
				}
				break;

			case Payshen_Status::DECLINED:
			case Payshen_Status::FAILED:
				if ( $is_current && '' === $paid_by && $changed && $order->has_status( array( 'processing', 'completed' ) ) ) {
					$order->add_order_note(
						/* translators: 1: payment id, 2: Payshen status. */
						sprintf( __( 'Warning: Payshen payment %1$s is %2$s, but this order is marked as paid. Nothing was collected for it. Do not ship.', 'payshen-for-woocommerce' ), $payment_id, $status )
					);
					break;
				}
				if ( $is_current && '' === $paid_by && '' === (string) $order->get_meta( '_payshen_authorized' ) && $order->has_status( array( 'pending', 'on-hold' ) ) ) {
					$words = Payshen_Status::decline_words( $payment['decline_category'] ?? '' );
					if ( Payshen_Status::DECLINED === $action ) {
						$note = '' !== $words
							/* translators: 1: payment id, 2: reason such as "insufficient funds". */
							? sprintf( __( 'Payshen payment %1$s was declined (%2$s).', 'payshen-for-woocommerce' ), $payment_id, $words )
							/* translators: %s: payment id. */
							: sprintf( __( 'Payshen payment %s was declined.', 'payshen-for-woocommerce' ), $payment_id );
					} else {
						/* translators: %s: payment id. */
						$note = sprintf( __( 'Payshen payment %s failed: no provider could take it. Nothing was charged.', 'payshen-for-woocommerce' ), $payment_id );
					}
					$order->update_status( 'failed', $note );
				}
				break;

			case Payshen_Status::CANCELLED:
				if ( '' !== $paid_by ) {
					break;
				}
				if ( (string) $order->get_meta( '_payshen_authorized' ) === $payment_id ) {
					$order->delete_meta_data( '_payshen_authorized' );
					if ( $changed ) {
						/* translators: %s: payment id. */
						$note = sprintf( __( 'The authorization of Payshen payment %s was released. Nothing was charged.', 'payshen-for-woocommerce' ), $payment_id );
						if ( $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
							$order->update_status( 'cancelled', $note );
						} else {
							$order->add_order_note( $note );
						}
					}
				} elseif ( $is_current && $order->has_status( array( 'pending', 'on-hold', 'failed' ) ) ) {
					$order->update_status(
						'cancelled',
						/* translators: %s: payment id. */
						sprintf( __( 'Payshen payment %s ended without a charge: the customer cancelled it or did not finish in time.', 'payshen-for-woocommerce' ), $payment_id )
					);
				}
				break;

			case Payshen_Status::CHARGEBACK:
				if ( $changed ) {
					/* translators: %s: payment id. */
					$order->add_order_note( sprintf( __( 'A dispute on Payshen payment %s was lost (chargeback). Check it in Payshen.', 'payshen-for-woocommerce' ), $payment_id ) );
				}
				break;
		}

		$order->save();

		/**
		 * After a payment, as Payshen reported it, was applied to its order.
		 *
		 * @param WC_Order $order   Order.
		 * @param array    $payment The payment from the API.
		 * @param string   $action  What it meant (a Payshen_Status constant).
		 * @param string   $source  checkout|return|cancel|webhook|schedule|admin|capture.
		 */
		do_action( 'payshen_payment_applied', $order, $payment, $action, $source );
		return $action;
	}

	/**
	 * A collected payment.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $payment    Payment.
	 * @param string   $payment_id Payment id.
	 * @param array    $info       What the store recorded about it.
	 * @param bool     $changed    Whether its status changed since the last look.
	 * @param string   $paid_by    The payment that already paid the order, or ''.
	 */
	private static function apply_paid( $order, array $payment, $payment_id, array $info, $changed, $paid_by ) {
		if ( '' !== $paid_by || ( $order->is_paid() && '' === (string) $order->get_meta( '_payshen_authorized' ) ) ) {
			if ( '' !== $paid_by && $paid_by !== $payment_id && $changed ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: second payment id, 2: first payment id. */
						__( 'Warning: Payshen payment %1$s was also collected for this order, which payment %2$s had already paid. Refund %1$s in Payshen if it is not wanted.', 'payshen-for-woocommerce' ),
						$payment_id,
						$paid_by
					)
				);
				Payshen_Logger::warning(
					'Second payment collected for a paid order',
					array(
						'order_id'   => $order->get_id(),
						'payment_id' => $payment_id,
						'paid_by'    => $paid_by,
					)
				);
			}
			if ( '' === $paid_by || $paid_by === $payment_id ) {
				if ( '' === $paid_by ) {
					$order->update_meta_data( '_payshen_paid_payment', $payment_id );
				}
				Payshen_Refunds::sync_from_payment( $order, $payment );
			}
			return;
		}

		$problems = self::problems( $order, $payment, $info, true );
		if ( array() !== $problems ) {
			if ( $changed || ! $order->has_status( 'on-hold' ) ) {
				$note = sprintf(
					/* translators: 1: payment id, 2: list of problems. */
					__( 'Payshen payment %1$s was collected but the order was put on hold: %2$s', 'payshen-for-woocommerce' ),
					$payment_id,
					implode( ' ', $problems )
				);
				if ( $order->has_status( 'on-hold' ) ) {
					$order->add_order_note( $note );
				} else {
					$order->update_status( 'on-hold', $note );
				}
				Payshen_Logger::warning(
					'Collected payment held for review',
					array(
						'order_id'   => $order->get_id(),
						'payment_id' => $payment_id,
					)
				);
			}
			return;
		}

		self::make_current( $order, $payment_id );
		$order->update_meta_data( '_payshen_paid_payment', $payment_id );
		$order->delete_meta_data( '_payshen_authorized' );
		$order->set_transaction_id( $payment_id );
		$order->save();

		if ( $order->has_status( array( 'processing', 'completed' ) ) ) {
			// Captured after the order was moved on (manual capture): record it, keep the status.
			if ( ! $order->get_date_paid( 'edit' ) ) {
				$order->set_date_paid( time() );
			}
			/* translators: %s: payment id. */
			$order->add_order_note( sprintf( __( 'Payshen payment %s was captured.', 'payshen-for-woocommerce' ), $payment_id ) );
		} else {
			$order->payment_complete( $payment_id );
			// A capture the merchant started by completing the order returns it to that status.
			$target = (string) $order->get_meta( '_payshen_capture_target' );
			if ( 'completed' === $target && ! $order->has_status( 'completed' ) ) {
				$order->update_status( 'completed', __( 'Capture confirmed by the provider: the order is completed as requested.', 'payshen-for-woocommerce' ) );
			}
		}
		if ( '' !== (string) $order->get_meta( '_payshen_capture_target' ) ) {
			$order->delete_meta_data( '_payshen_capture_target' );
			$order->save();
		}

		Payshen_Refunds::sync_from_payment( $order, $payment );
	}

	/**
	 * An authorization held for capture.
	 *
	 * @param WC_Order $order      Order.
	 * @param array    $payment    Payment.
	 * @param string   $payment_id Payment id.
	 * @param array    $info       Recorded info.
	 * @param bool     $changed    Status changed since the last look.
	 * @param string   $paid_by    The payment that already paid the order, or ''.
	 */
	private static function apply_authorized( $order, array $payment, $payment_id, array $info, $changed, $paid_by ) {
		if ( '' !== $paid_by ) {
			if ( $paid_by !== $payment_id && $changed ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: second payment id, 2: first payment id. */
						__( 'Warning: Payshen payment %1$s was also authorized for this order, which payment %2$s had already paid. Void %1$s in Payshen.', 'payshen-for-woocommerce' ),
						$payment_id,
						$paid_by
					)
				);
			}
			return;
		}

		$problems = self::problems( $order, $payment, $info, false );
		$current  = (string) $order->get_meta( '_payshen_authorized' );

		self::make_current( $order, $payment_id );
		$order->update_meta_data( '_payshen_authorized', $payment_id );
		$order->set_transaction_id( $payment_id );

		if ( $current === $payment_id && ! $changed ) {
			return;
		}

		// With automatic capture, some providers answer "authorized" first and
		// capture moments later; the order moves on when the capture is seen.
		$note = 'manual' === ( $info['capture'] ?? 'automatic' )
			? sprintf(
				/* translators: %s: payment id. */
				__( 'Payshen payment %s is authorized, not captured yet. To collect it, set the order to Processing or Completed, or use the order action "Capture payment (Payshen)". Cancelling the order releases it.', 'payshen-for-woocommerce' ),
				$payment_id
			)
			: sprintf(
				/* translators: %s: payment id. */
				__( 'Payshen payment %s is authorized; the provider is capturing it. The order moves to Processing as soon as the capture is confirmed.', 'payshen-for-woocommerce' ),
				$payment_id
			);
		if ( array() !== $problems ) {
			$note .= ' ' . __( 'Check before capturing:', 'payshen-for-woocommerce' ) . ' ' . implode( ' ', $problems );
		}
		if ( $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
			$order->update_status( 'on-hold', $note );
		} else {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Reasons not to treat a payment as this order's money.
	 *
	 * @param WC_Order $order     Order.
	 * @param array    $payment   Payment.
	 * @param array    $info      What the store sent.
	 * @param bool     $collected Whether the payment is captured.
	 * @return string[] Sentences; empty when it is fine.
	 */
	private static function problems( $order, array $payment, array $info, $collected ) {
		$out      = array();
		$currency = strtoupper( (string) ( $payment['currency'] ?? '' ) );
		$expected = strtoupper( (string) ( $info['currency'] ?? $order->get_currency() ) );
		if ( '' !== $currency && $currency !== $expected ) {
			/* translators: 1: payment currency, 2: order currency. */
			$out[] = sprintf( __( 'its currency is %1$s, not %2$s.', 'payshen-for-woocommerce' ), $currency, $expected );
		}
		if ( isset( $payment['amount'], $info['amount'] ) && (int) $payment['amount'] !== (int) $info['amount'] ) {
			/* translators: 1: amount Payshen holds, 2: amount sent. */
			$out[] = sprintf( __( 'its amount is %1$s, not the %2$s that was sent.', 'payshen-for-woocommerce' ), Payshen_Amount::from_minor( (int) $payment['amount'], $currency ), Payshen_Amount::from_minor( (int) $info['amount'], $expected ) );
		}
		if ( $collected ) {
			// What was collected must be what the order costs now: the order may
			// have changed after this payment's page was opened.
			$got = isset( $payment['captured_amount'] ) && (int) $payment['captured_amount'] > 0 ? (int) $payment['captured_amount'] : (int) ( $payment['amount'] ?? 0 );
			try {
				$total = Payshen_Amount::to_minor( (string) $order->get_total( 'edit' ), $expected );
			} catch ( InvalidArgumentException $e ) {
				$total = -1;
			}
			if ( $got > 0 && $got !== $total ) {
				/* translators: 1: collected amount, 2: order total. */
				$out[] = sprintf( __( '%1$s was collected but the order total is %2$s.', 'payshen-for-woocommerce' ), Payshen_Amount::from_minor( $got, $expected ) . ' ' . $expected, Payshen_Amount::from_minor( max( 0, $total ), $expected ) . ' ' . $expected );
			}
		}
		$mode = (string) ( $info['mode'] ?? $order->get_meta( '_payshen_mode' ) );
		// A test-mode payment never pays an order once the store takes live
		// payments: a customer could otherwise pay an old test page with a
		// public test card after the switch, and nothing would have moved.
		$gateway = function_exists( 'payshen_wc_gateway' ) ? payshen_wc_gateway() : null;
		if ( 'live' !== $mode && $gateway && 'live' === $gateway->mode() ) {
			$out[] = __( 'it is a test-mode payment and the store now takes live payments, so no money moved.', 'payshen-for-woocommerce' );
		}
		if ( 'live' === $mode && ! empty( $payment['simulated'] ) ) {
			$out[] = __( 'Payshen says a simulation answered it, so no money moved.', 'payshen-for-woocommerce' );
		}
		$environment = (string) ( $payment['environment'] ?? '' );
		if ( '' !== $environment && ( 'live' === $mode ) !== ( 'production' === $environment ) ) {
			/* translators: %s: sandbox or production. */
			$out[] = sprintf( __( 'it is a %s payment, which does not match the order.', 'payshen-for-woocommerce' ), $environment );
		}
		return $out;
	}

	/**
	 * Whether the order is paid through Payshen (or otherwise marked paid).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_paid( $order ) {
		if ( '' !== (string) $order->get_meta( '_payshen_paid_payment' ) ) {
			return true;
		}
		return $order->is_paid() && '' === (string) $order->get_meta( '_payshen_authorized' );
	}

	/**
	 * Record a payment made for the order and make it the current one.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $payment_id Payment id.
	 * @param string   $mode       test|live.
	 * @param int      $attempt    Attempt number.
	 * @param array    $body       What was sent.
	 */
	public static function remember_payment( $order, $payment_id, $mode, $attempt, array $body ) {
		if ( ! self::has_payment( $order, $payment_id ) ) {
			$order->add_meta_data( '_payshen_payment_ref', $payment_id, false );
		}
		self::set_payment_info(
			$order,
			$payment_id,
			array(
				'mode'     => $mode,
				'attempt'  => (int) $attempt,
				'amount'   => (int) ( $body['amount'] ?? 0 ),
				'currency' => (string) ( $body['currency'] ?? '' ),
				'capture'  => (string) ( $body['capture_method'] ?? 'automatic' ),
			)
		);
		$order->update_meta_data( '_payshen_payment_id', $payment_id );
		$order->update_meta_data( '_payshen_mode', $mode );
		$order->update_meta_data( '_payshen_attempt', (int) $attempt );
		$order->set_transaction_id( $payment_id );
	}

	/**
	 * Whether a payment was made for this order.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $payment_id Payment id.
	 * @return bool
	 */
	public static function has_payment( $order, $payment_id ) {
		if ( '' === $payment_id ) {
			return false;
		}
		foreach ( $order->get_meta( '_payshen_payment_ref', false ) as $meta ) {
			if ( (string) $meta->value === $payment_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What the store recorded about one payment.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $payment_id Payment id.
	 * @return array
	 */
	public static function payment_info( $order, $payment_id ) {
		$all = $order->get_meta( '_payshen_payments' );
		return is_array( $all ) && isset( $all[ $payment_id ] ) && is_array( $all[ $payment_id ] ) ? $all[ $payment_id ] : array();
	}

	/**
	 * Merge values into one payment's record.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $payment_id Payment id.
	 * @param array    $values     Values.
	 */
	private static function set_payment_info( $order, $payment_id, array $values ) {
		$all = $order->get_meta( '_payshen_payments' );
		$all = is_array( $all ) ? $all : array();

		$all[ $payment_id ] = array_merge( isset( $all[ $payment_id ] ) && is_array( $all[ $payment_id ] ) ? $all[ $payment_id ] : array(), $values );
		$order->update_meta_data( '_payshen_payments', $all );
	}

	/**
	 * Make a payment the order's current one (a late payment of an earlier attempt that went through).
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $payment_id Payment id.
	 */
	private static function make_current( $order, $payment_id ) {
		if ( (string) $order->get_meta( '_payshen_payment_id' ) === $payment_id ) {
			return;
		}
		$info = self::payment_info( $order, $payment_id );
		$order->update_meta_data( '_payshen_payment_id', $payment_id );
		if ( ! empty( $info['mode'] ) ) {
			$order->update_meta_data( '_payshen_mode', $info['mode'] );
		}
		if ( ! empty( $info['attempt'] ) ) {
			$order->update_meta_data( '_payshen_attempt', (int) $info['attempt'] );
		}
	}

	/**
	 * The API client for the mode a payment was made in.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $payment_id Payment id ('' for the current one).
	 * @return Payshen_API_Client|null
	 */
	public static function client_for( $order, $payment_id = '' ) {
		$gateway = payshen_wc_gateway();
		if ( ! $gateway ) {
			return null;
		}
		$info = '' !== $payment_id ? self::payment_info( $order, $payment_id ) : array();
		$mode = (string) ( $info['mode'] ?? $order->get_meta( '_payshen_mode' ) );
		if ( 'live' !== $mode && 'test' !== $mode ) {
			$mode = $gateway->mode();
		}
		return $gateway->client( $mode );
	}

	/**
	 * The mode a payment was made in.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $payment_id Payment id.
	 * @return string test|live
	 */
	public static function mode_of( $order, $payment_id ) {
		$info = self::payment_info( $order, $payment_id );
		$mode = (string) ( $info['mode'] ?? $order->get_meta( '_payshen_mode' ) );
		return 'live' === $mode ? 'live' : 'test';
	}

	/**
	 * The order that holds a Payshen payment, or null.
	 *
	 * @param string $payment_id Payment id.
	 * @return WC_Order|null
	 */
	public static function find_order( $payment_id ) {
		if ( '' === $payment_id || preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $payment_id ) !== 1 ) {
			return null;
		}
		$args = array(
			'limit'  => 2,
			'return' => 'ids',
			'type'   => 'shop_order',
			'status' => array_keys( wc_get_order_statuses() ),
		);
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// Order tables: meta_query is the supported way.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One lookup per webhook.
			$args['meta_query'] = array(
				array(
					'key'   => '_payshen_payment_ref',
					'value' => $payment_id,
				),
			);
		} else {
			// Posts storage: WooCommerce passes meta_key and meta_value to WP_Query.
			$args['meta_key']   = '_payshen_payment_ref'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup per webhook.
			$args['meta_value'] = $payment_id; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One lookup per webhook.
		}
		$ids = wc_get_orders( $args );
		if ( empty( $ids ) ) {
			return null;
		}
		if ( count( $ids ) > 1 ) {
			Payshen_Logger::warning(
				'A payment is recorded on more than one order; using the first',
				array(
					'payment_id' => $payment_id,
					'orders'     => $ids,
				)
			);
		}
		$order = wc_get_order( (int) $ids[0] );
		return $order instanceof WC_Order ? $order : null;
	}
}
