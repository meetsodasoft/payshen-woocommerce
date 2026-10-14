<?php
/**
 * A short lock per order.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps two requests (a webhook, the customer's return, a scheduled check)
 * from changing the same order at the same moment, which would send the
 * customer two emails and write two notes.
 *
 * A row in the options table, inserted with INSERT IGNORE: only one request
 * can insert it. add_option() is not used because it upserts, so two callers
 * would both believe they hold the lock. Each lock expires on its own, so a
 * request that dies holding it blocks the order for at most $ttl seconds.
 */
final class Payshen_Lock {

	/**
	 * Take the lock, waiting up to $wait seconds for it.
	 *
	 * @param string $name Lock name (e.g. "order_123").
	 * @param int    $ttl  Seconds before the lock expires on its own.
	 * @param int    $wait Seconds to wait for it.
	 * @return string|false A token for release(), or false when it stayed taken.
	 */
	public static function acquire( $name, $ttl = 60, $wait = 0 ) {
		global $wpdb;
		$option   = self::option_name( $name );
		$deadline = microtime( true ) + max( 0, $wait );
		$token    = wp_generate_password( 20, false, false );

		do {
			$expires = time() + max( 5, (int) $ttl );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic insert is the lock; a cached read would not be one.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
					$option,
					$expires . '|' . $token
				)
			);
			if ( 1 === (int) $inserted ) {
				return $token;
			}

			// Taken. Clear it when it has expired, then try again.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );
			if ( null !== $value ) {
				$until = (int) strtok( (string) $value, '|' );
				if ( $until < time() ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $option, $value ) );
					continue;
				}
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( 250000 );
		} while ( true );
	}

	/**
	 * Release a lock this request holds.
	 *
	 * @param string $name  Lock name.
	 * @param string $token The token acquire() returned.
	 */
	public static function release( $name, $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || '' === $token ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
				self::option_name( $name ),
				'%|' . $wpdb->esc_like( $token )
			)
		);
	}

	/**
	 * The option row's name.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function option_name( $name ) {
		return 'payshen_lock_' . substr( preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $name ) ), 0, 100 );
	}
}
