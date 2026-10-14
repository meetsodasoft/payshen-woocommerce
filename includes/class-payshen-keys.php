<?php
/**
 * Idempotency keys, return tokens and credential formats.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic keys, so a repeated request is recognised as the same one.
 *
 * Payshen takes an Idempotency-Key of 8-200 printable ASCII characters. The
 * same key with the same body answers the first result and never charges or
 * refunds twice; a new key is a new operation.
 */
final class Payshen_Keys {

	/**
	 * The key for creating the payment of one checkout attempt.
	 *
	 * Same order, same order key, same attempt: same key, so a double click or
	 * a retry after a lost answer never creates a second payment. The attempt
	 * number only moves on after the previous payment ended unpaid.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $order_key WooCommerce order key.
	 * @param int    $attempt   1, 2, ...
	 * @param string $site      Something unique to this store (its home URL), so two stores on one Payshen account never collide.
	 * @return string
	 */
	public static function payment( $order_id, $order_key, $attempt, $site = '' ) {
		$hash = substr( hash( 'sha256', 'payment|' . $site . '|' . (int) $order_id . '|' . $order_key . '|' . (int) $attempt ), 0, 24 );
		return 'wc-pay-' . (int) $order_id . '-' . (int) $attempt . '-' . $hash;
	}

	/**
	 * The key for capturing one payment. Stable: Payshen releases it after a refusal, so a new attempt is safe under it.
	 *
	 * @param string $payment_id Payshen payment id.
	 * @return string
	 */
	public static function capture( $payment_id ) {
		return 'wc-capture-' . self::safe( $payment_id );
	}

	/**
	 * The key for voiding one payment.
	 *
	 * @param string $payment_id Payshen payment id.
	 * @return string
	 */
	public static function void( $payment_id ) {
		return 'wc-void-' . self::safe( $payment_id );
	}

	/**
	 * The key for one WooCommerce refund. It also names the refund at Payshen.
	 *
	 * @param int    $order_id  Order id.
	 * @param int    $refund_id WooCommerce refund id.
	 * @param string $site      Something unique to this store.
	 * @return string
	 */
	public static function refund( $order_id, $refund_id, $site = '' ) {
		$hash = substr( hash( 'sha256', 'refund|' . $site . '|' . (int) $order_id . '|' . (int) $refund_id ), 0, 16 );
		return 'wc-refund-' . (int) $order_id . '-' . (int) $refund_id . '-' . $hash;
	}

	/**
	 * A token that proves a return or cancel link was made by this store for this order.
	 *
	 * @param int    $order_id  Order id.
	 * @param string $order_key Order key.
	 * @param string $secret    A site secret (wp_salt).
	 * @return string 32 hex characters.
	 */
	public static function return_token( $order_id, $order_key, $secret ) {
		return substr( hash_hmac( 'sha256', 'return|' . (int) $order_id . '|' . $order_key, (string) $secret ), 0, 32 );
	}

	/**
	 * Whether a token is the one return_token() makes. Timing-safe.
	 *
	 * @param string $token     Token from the URL.
	 * @param int    $order_id  Order id.
	 * @param string $order_key Order key.
	 * @param string $secret    The same site secret.
	 * @return bool
	 */
	public static function check_return_token( $token, $order_id, $order_key, $secret ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}
		return hash_equals( self::return_token( $order_id, $order_key, $secret ), $token );
	}

	/**
	 * Whether an API key has the expected form for a mode.
	 *
	 * @param string $key  The key.
	 * @param string $mode 'test' or 'live'.
	 * @return bool
	 */
	public static function is_api_key( $key, $mode ) {
		$prefix = 'live' === $mode ? 'ps_live_' : 'ps_test_';
		return is_string( $key ) && preg_match( '/^' . $prefix . '[A-Za-z0-9_\-]{8,200}$/', $key ) === 1;
	}

	/**
	 * Whether a webhook signing secret has the form Payshen issues (whsec_...).
	 *
	 * @param string $secret The secret.
	 * @return bool
	 */
	public static function is_webhook_secret( $secret ) {
		return is_string( $secret ) && preg_match( '/^whsec_[A-Za-z0-9_\-]{8,200}$/', $secret ) === 1;
	}

	/**
	 * A value reduced to the characters an Idempotency-Key may carry.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function safe( $value ) {
		return substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value ), 0, 120 );
	}
}
