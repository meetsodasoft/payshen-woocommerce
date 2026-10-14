<?php
/**
 * The `customer` object sent with a payment.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds Payshen's `customer` from billing details.
 *
 * Payshen refuses the whole payment (422) when any customer field breaks its
 * rules, and the object is strict (no unknown keys). So every field is checked
 * here against the API's own rules, and one that does not pass is left out
 * instead of failing the checkout.
 */
final class Payshen_Customer {

	/**
	 * The customer object, or null when nothing usable is left.
	 *
	 * @param array $in Keys: id, email, first_name, last_name, phone, line1, line2, city, postal_code, state, country, ip.
	 * @return array|null
	 */
	public static function build( array $in ) {
		$out = array();

		$id = self::text( $in['id'] ?? '', 64 );
		if ( '' !== $id ) {
			$out['id'] = $id;
		}

		$email = trim( (string) ( $in['email'] ?? '' ) );
		// The pattern Payshen's API validates with (zod 3 `email()`): an address
		// PHP accepts and Payshen does not (a quoted local part, an IDN) is left out.
		if ( '' !== $email && strlen( $email ) <= 254 && preg_match( "/^(?!\\.)(?!.*\\.\\.)([A-Z0-9_'+\\-\\.]*)[A-Z0-9_+-]@([A-Z0-9][A-Z0-9\\-]*\\.)+[A-Z]{2,}$/i", $email ) === 1 ) {
			$out['email'] = $email;
		}

		foreach ( array( 'first_name', 'last_name' ) as $field ) {
			$value = self::text( $in[ $field ] ?? '', 100 );
			if ( '' !== $value ) {
				$out[ $field ] = $value;
			}
		}

		$phone = self::phone( $in['phone'] ?? '' );
		if ( null !== $phone ) {
			$out['phone'] = $phone;
		}

		$address = array();
		$line1   = trim( (string) ( $in['line1'] ?? '' ) . ' ' . (string) ( $in['line2'] ?? '' ) );
		$line1   = self::text( $line1, 200 );
		if ( '' !== $line1 ) {
			$address['line1'] = $line1;
		}
		$city = self::text( $in['city'] ?? '', 100 );
		if ( '' !== $city ) {
			$address['city'] = $city;
		}
		$postal = self::text( $in['postal_code'] ?? '', 20 );
		if ( '' !== $postal ) {
			$address['postal_code'] = $postal;
		}
		$state = self::text( $in['state'] ?? '', 50 );
		if ( '' !== $state ) {
			$address['state'] = $state;
		}
		$country = self::country( $in['country'] ?? '' );
		if ( null !== $country ) {
			$address['country'] = $country;
		}
		if ( array() !== $address ) {
			$out['address'] = $address;
		}

		$ip = trim( (string) ( $in['ip'] ?? '' ) );
		if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$out['ip'] = $ip;
		}

		return array() === $out ? null : $out;
	}

	/**
	 * A two-letter country code, or null.
	 *
	 * @param string $value Input.
	 * @return string|null
	 */
	public static function country( $value ) {
		$value = strtoupper( trim( (string) $value ) );
		return preg_match( '/^[A-Z]{2}$/', $value ) === 1 ? $value : null;
	}

	/**
	 * A phone number as Payshen takes it (digits, optional leading +, 4-20 digits), or null.
	 *
	 * @param string $value Input such as "+44 (0)20 7946-0958".
	 * @return string|null
	 */
	public static function phone( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$plus = str_starts_with( $value, '+' ) || str_starts_with( $value, '00' );
		if ( str_starts_with( $value, '00' ) ) {
			$value = substr( $value, 2 );
		}
		// "(0)" is the trunk prefix shown beside an international number; it is not dialed.
		$value  = str_replace( '(0)', '', $value );
		$digits = preg_replace( '/\D+/', '', $value );
		$phone  = ( $plus ? '+' : '' ) . $digits;
		return preg_match( '/^\+?[0-9]{4,20}$/', $phone ) === 1 ? $phone : null;
	}

	/**
	 * Text with no control characters, trimmed and cut to a length.
	 *
	 * @param string $value Input.
	 * @param int    $max   Maximum characters.
	 * @return string
	 */
	public static function text( $value, $max ) {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, $max, 'UTF-8' );
		} else {
			$value = substr( $value, 0, $max );
		}
		return trim( $value );
	}
}
