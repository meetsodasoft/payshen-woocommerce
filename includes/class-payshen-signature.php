<?php
/**
 * Webhook signature check.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies `X-Payshen-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`,
 * exactly as the official PHP SDK does (Payshen\Webhook::verifySignature).
 */
final class Payshen_Signature {

	/** Five minutes, as Payshen documents and the SDK uses. */
	const TOLERANCE_SECONDS = 300;

	const OK                  = 'ok';
	const MISSING_SIGNATURE   = 'missing_signature';
	const MALFORMED_SIGNATURE = 'malformed_signature';
	const EXPIRED_SIGNATURE   = 'expired_signature';
	const MISSING_SECRET      = 'missing_secret';
	const INVALID_SIGNATURE   = 'invalid_signature';

	/**
	 * Check one delivery against one signing secret.
	 *
	 * @param string      $payload   The raw request body, before any decoding.
	 * @param string|null $header    The X-Payshen-Signature header.
	 * @param string      $secret    The endpoint's signing secret (whsec_...).
	 * @param int|null    $now       Unix time (tests pass one).
	 * @param int         $tolerance Seconds a timestamp may be off.
	 * @return string One of the constants above; OK when it verifies.
	 */
	public static function verify( $payload, $header, $secret, $now = null, $tolerance = self::TOLERANCE_SECONDS ) {
		if ( null === $header || '' === trim( (string) $header ) ) {
			return self::MISSING_SIGNATURE;
		}
		if ( '' === (string) $secret ) {
			return self::MISSING_SECRET;
		}

		$parts = self::parse( (string) $header );
		$t     = $parts['t'] ?? '';
		$v1    = $parts['v1'] ?? array();
		if ( preg_match( '/^\d{1,12}$/', $t ) !== 1 || array() === $v1 ) {
			return self::MALFORMED_SIGNATURE;
		}

		$now = null === $now ? time() : (int) $now;
		if ( abs( $now - (int) $t ) > $tolerance ) {
			return self::EXPIRED_SIGNATURE;
		}

		$expected = hash_hmac( 'sha256', $t . '.' . $payload, (string) $secret );
		$matched  = false;
		foreach ( $v1 as $candidate ) {
			// Every candidate is compared, timing-safe each time.
			if ( hash_equals( $expected, strtolower( $candidate ) ) ) {
				$matched = true;
			}
		}
		return $matched ? self::OK : self::INVALID_SIGNATURE;
	}

	/**
	 * The header's fields. `v1` may repeat (several signatures during a rotation).
	 *
	 * @param string $header Header value.
	 * @return array{t?: string, v1?: string[]}
	 */
	private static function parse( $header ) {
		$out = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv  = explode( '=', $part, 2 );
			$key = trim( $kv[0] );
			$val = trim( $kv[1] ?? '' );
			if ( 't' === $key ) {
				$out['t'] = $val;
			} elseif ( 'v1' === $key && '' !== $val ) {
				$out['v1'][] = $val;
			}
		}
		return $out;
	}

	/**
	 * A signature header for a body, as Payshen builds it. For tests and tools.
	 *
	 * @param string $payload Raw body.
	 * @param string $secret  Signing secret.
	 * @param int    $t       Unix time.
	 * @return string
	 */
	public static function sign( $payload, $secret, $t ) {
		return 't=' . (int) $t . ',v1=' . hash_hmac( 'sha256', (int) $t . '.' . $payload, (string) $secret );
	}
}
