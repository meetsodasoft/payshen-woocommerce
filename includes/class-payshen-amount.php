<?php
/**
 * Money in Payshen's minor units.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts store amounts to the integer minor units the Payshen API takes, and back.
 *
 * The exponent table is Payshen's own: the API reads a currency's minor unit
 * from the Unicode CLDR data (JavaScript Intl, "en-US" currency format), not
 * from ISO 4217. The two differ for some currencies (HUF, IDR, COP, PKR, IQD
 * and others are whole units in CLDR), and the amount must mean what Payshen
 * reads it as. Every currency not listed has two decimals.
 */
final class Payshen_Amount {

	/** Payshen's minimum and maximum `amount` on POST /api/v1/payments. */
	const MIN_MINOR = 50;
	const MAX_MINOR = 100000000;

	/**
	 * Currencies whose minor unit is not 1/100, as Payshen reads them.
	 *
	 * @var array<string, int>
	 */
	const EXPONENTS = array(
		// Whole units.
		'AFN' => 0,
		'ALL' => 0,
		'BIF' => 0,
		'CLP' => 0,
		'COP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'HUF' => 0,
		'IDR' => 0,
		'IQD' => 0,
		'IRR' => 0,
		'ISK' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KPW' => 0,
		'KRW' => 0,
		'LAK' => 0,
		'LBP' => 0,
		'MGA' => 0,
		'MMK' => 0,
		'PKR' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'SLL' => 0,
		'SOS' => 0,
		'SYP' => 0,
		'UGX' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'YER' => 0,
		// Thousandths.
		'BHD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
	);

	/**
	 * How many decimals the currency's minor unit has.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return int
	 */
	public static function exponent( $currency ) {
		$code = strtoupper( trim( (string) $currency ) );
		return self::EXPONENTS[ $code ] ?? 2;
	}

	/**
	 * A store amount ("19.99", 19.99, "1234.5") as integer minor units.
	 *
	 * Rounds half up at the currency's exponent, from the decimal string, so
	 * 1.005 EUR is 101 and never 100 by a float accident.
	 *
	 * @param string|float|int $amount   Amount in major units.
	 * @param string           $currency ISO 4217 code.
	 * @return int
	 * @throws InvalidArgumentException For a value that is not a non-negative number.
	 */
	public static function to_minor( $amount, $currency ) {
		$exp = self::exponent( $currency );
		$str = self::decimal_string( $amount );
		if ( null === $str ) {
			throw new InvalidArgumentException( 'Amount is not a non-negative number.' );
		}

		$parts = explode( '.', $str, 2 );
		$whole = ltrim( $parts[0], '0' );
		$frac  = $parts[1] ?? '';

		$kept = substr( str_pad( $frac, $exp, '0' ), 0, $exp );
		$next = strlen( $frac ) > $exp ? (int) $frac[ $exp ] : 0;

		$digits = ( '' === $whole ? '' : $whole ) . $kept;
		$minor  = (int) ( '' === ltrim( $digits, '0' ) ? '0' : ltrim( $digits, '0' ) );
		if ( $next >= 5 ) {
			++$minor;
		}
		return $minor;
	}

	/**
	 * Minor units as a decimal string in major units ("19.99", "5000", "1.250").
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	public static function from_minor( $minor, $currency ) {
		$exp   = self::exponent( $currency );
		$minor = (int) $minor;
		$sign  = $minor < 0 ? '-' : '';
		$abs   = (string) abs( $minor );
		if ( 0 === $exp ) {
			return $sign . $abs;
		}
		$abs = str_pad( $abs, $exp + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $abs, 0, -$exp ) . '.' . substr( $abs, -$exp );
	}

	/**
	 * Whether Payshen accepts this many minor units for a payment.
	 *
	 * @param int $minor Minor units.
	 * @return bool
	 */
	public static function in_range( $minor ) {
		return $minor >= self::MIN_MINOR && $minor <= self::MAX_MINOR;
	}

	/**
	 * A plain decimal string for a number, or null when it is not one.
	 *
	 * @param mixed $amount Input.
	 * @return string|null
	 */
	private static function decimal_string( $amount ) {
		if ( is_int( $amount ) ) {
			return $amount < 0 ? null : (string) $amount;
		}
		if ( is_float( $amount ) ) {
			if ( ! is_finite( $amount ) || $amount < 0 ) {
				return null;
			}
			// Twelve places hold every real price; this also undoes 0.1 + 0.2 noise.
			$str = rtrim( rtrim( number_format( $amount, 12, '.', '' ), '0' ), '.' );
			return '' === $str ? '0' : $str;
		}
		if ( is_string( $amount ) ) {
			$str = trim( $amount );
			if ( preg_match( '/^\d+(\.\d*)?$|^\.\d+$/', $str ) !== 1 ) {
				return null;
			}
			if ( '.' === $str[0] ) {
				$str = '0' . $str;
			}
			return rtrim( $str, '.' );
		}
		return null;
	}
}
