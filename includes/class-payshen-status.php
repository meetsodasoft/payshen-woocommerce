<?php
/**
 * Payshen payment status to what the store does.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps a Payshen payment status (UPPERCASE on the wire) to one store action.
 *
 * Only a collected payment is ever "paid". Anything not recognised is held
 * for review and never treated as paid or as declined: Payshen may add
 * statuses within API v1 (it added UNKNOWN in 2026-09).
 */
final class Payshen_Status {

	/** Collected: complete the order. */
	const PAID = 'paid';
	/** Approved and held for capture (manual capture). */
	const AUTHORIZED = 'authorized';
	/** The customer has not finished on Payshen's page yet. */
	const AWAITING_CUSTOMER = 'awaiting_customer';
	/** The provider is still deciding, or its answer is not known yet. Never a decline. */
	const IN_FLIGHT = 'in_flight';
	/** The provider declined it. */
	const DECLINED = 'declined';
	/** No provider executed it. Nothing was charged. */
	const FAILED = 'failed';
	/** Ended without a charge: abandoned, expired, or an authorization released. */
	const CANCELLED = 'cancelled';
	/** A dispute was lost after the payment was collected. */
	const CHARGEBACK = 'chargeback';
	/** A status this version does not know. Held, never paid. */
	const UNRECOGNISED = 'unrecognised';

	/**
	 * The action for a status.
	 *
	 * @param string|null $status Payshen status, any case.
	 * @return string One of the constants above.
	 */
	public static function action( $status ) {
		switch ( strtoupper( trim( (string) $status ) ) ) {
			case 'CAPTURED':
			case 'SETTLED':
			case 'PARTIALLY_REFUNDED':
			case 'REFUNDED':
				return self::PAID;
			case 'AUTHORIZED':
				return self::AUTHORIZED;
			case 'CREATED':
			case 'REQUIRES_ACTION':
				return self::AWAITING_CUSTOMER;
			case 'PENDING':
			case 'PROCESSING':
			case 'UNKNOWN':
				return self::IN_FLIGHT;
			case 'DECLINED':
				return self::DECLINED;
			case 'FAILED':
				return self::FAILED;
			case 'CANCELLED':
			case 'VOIDED':
				return self::CANCELLED;
			case 'CHARGEBACK':
				return self::CHARGEBACK;
			default:
				return self::UNRECOGNISED;
		}
	}

	/**
	 * Whether the store can stop asking Payshen about this payment.
	 *
	 * @param string $action An action from action().
	 * @return bool
	 */
	public static function is_settled( $action ) {
		return in_array( $action, array( self::PAID, self::AUTHORIZED, self::DECLINED, self::FAILED, self::CANCELLED, self::CHARGEBACK ), true );
	}

	/**
	 * Whether this action means the money did not move.
	 *
	 * @param string $action An action from action().
	 * @return bool
	 */
	public static function is_unpaid_end( $action ) {
		return in_array( $action, array( self::DECLINED, self::FAILED, self::CANCELLED ), true );
	}

	/**
	 * A decline category in plain words: "INSUFFICIENT_FUNDS" is "insufficient funds".
	 *
	 * @param string|null $category Payshen decline_category, any case.
	 * @return string Empty when there is none.
	 */
	public static function decline_words( $category ) {
		$category = strtolower( trim( (string) $category ) );
		if ( '' === $category || preg_match( '/^[a-z0-9_]{1,64}$/', $category ) !== 1 ) {
			return '';
		}
		return str_replace( '_', ' ', $category );
	}
}
