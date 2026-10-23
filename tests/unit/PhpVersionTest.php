<?php
/**
 * PHP security-release check.
 *
 * @package Payshen_For_WooCommerce
 */

use PHPUnit\Framework\TestCase;

final class PhpVersionTest extends TestCase {

	public function test_versions_before_the_fix_need_an_update() {
		foreach ( array( '8.2.34', '8.3.35', '8.4.18', '8.5.11', '8.1.33', '7.4.33' ) as $v ) {
			$this->assertTrue( Payshen_Php_Version::needs_update( $v ), $v );
		}
	}

	public function test_fixed_and_newer_versions_do_not() {
		foreach ( array( '8.2.35', '8.3.36', '8.4.27', '8.5.12', '8.5.13', '8.6.0', '8.4.27-1+deb13u1' ) as $v ) {
			$this->assertFalse( Payshen_Php_Version::needs_update( $v ), $v );
		}
	}

	public function test_an_unreadable_version_is_not_flagged() {
		$this->assertFalse( Payshen_Php_Version::needs_update( '' ) );
		$this->assertFalse( Payshen_Php_Version::needs_update( 'dev' ) );
	}
}
