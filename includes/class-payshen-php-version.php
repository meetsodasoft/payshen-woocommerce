<?php
/**
 * Whether the server's PHP has PHP's own security fixes.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * PHP's 21 October 2026 security release (CVE-2026-109618, CVE-2026-109619,
 * "PHP2Shell") fixes unauthenticated remote code execution in PHP itself. The
 * plugin cannot fix that; it can tell the store owner to ask the host.
 */
final class Payshen_Php_Version {

	/**
	 * The first fixed release of each supported branch.
	 */
	const FIXED = array(
		'8.2' => '8.2.35',
		'8.3' => '8.3.36',
		'8.4' => '8.4.27',
		'8.5' => '8.5.12',
	);

	/**
	 * True when this PHP version is older than its branch's fixed release, or
	 * its branch gets no fixes from PHP any more. Linux distributions sometimes
	 * patch without changing the version number, so the notice says "may".
	 *
	 * @param string $version A PHP version, e.g. PHP_VERSION.
	 * @return bool
	 */
	public static function needs_update( $version ) {
		if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)/', (string) $version, $m ) ) {
			return false;
		}
		$plain  = $m[1] . '.' . $m[2] . '.' . $m[3];
		$branch = $m[1] . '.' . $m[2];
		if ( isset( self::FIXED[ $branch ] ) ) {
			return version_compare( $plain, self::FIXED[ $branch ], '<' );
		}
		return version_compare( $plain, '8.2.0', '<' );
	}
}
