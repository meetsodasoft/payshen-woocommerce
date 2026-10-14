<?php
/**
 * Logging through WooCommerce's logger.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes to WooCommerce > Status > Logs, source "payshen".
 *
 * Errors and warnings are always written; info and debug only with Debug log
 * on. Nothing secret is ever passed in: callers log ids, statuses and error
 * codes, never keys, signing secrets, card data or customer details, and
 * anything that looks like a key is masked here as a last guard.
 */
final class Payshen_Logger {

	/**
	 * Debug log setting, read once per request.
	 *
	 * @var bool|null
	 */
	private static $debug = null;

	/**
	 * Log a message.
	 *
	 * @param string $level   emergency|alert|critical|error|warning|notice|info|debug.
	 * @param string $message Message.
	 * @param array  $context Extra values (ids, codes). Never secrets or personal data.
	 */
	public static function log( $level, $message, array $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$always = in_array( $level, array( 'emergency', 'alert', 'critical', 'error', 'warning' ), true );
		if ( ! $always && ! self::debug_enabled() ) {
			return;
		}
		$line = $message;
		if ( array() !== $context ) {
			$line .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES );
		}
		wc_get_logger()->log( $level, self::mask( $line ), array( 'source' => 'payshen' ) );
	}

	/**
	 * Debug message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function debug( $message, array $context = array() ) {
		self::log( 'debug', $message, $context );
	}

	/**
	 * Error message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( $message, array $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Warning message.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function warning( $message, array $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Mask anything shaped like a Payshen key or signing secret.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function mask( $text ) {
		return (string) preg_replace( '/\b(ps_(?:test|live)_|whsec_)[A-Za-z0-9_\-]+/', '$1***', (string) $text );
	}

	/**
	 * Whether Debug log is on.
	 *
	 * @return bool
	 */
	private static function debug_enabled() {
		if ( null === self::$debug ) {
			$settings    = get_option( 'woocommerce_payshen_settings', array() );
			self::$debug = is_array( $settings ) && isset( $settings['debug'] ) && 'yes' === $settings['debug'];
		}
		return self::$debug;
	}

	/**
	 * Forget the cached setting (after settings are saved).
	 */
	public static function reset() {
		self::$debug = null;
	}
}
