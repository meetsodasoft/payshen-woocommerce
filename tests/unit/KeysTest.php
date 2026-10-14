<?php

use PHPUnit\Framework\TestCase;

final class KeysTest extends TestCase {

	private function assert_valid_idempotency_key( string $key ): void {
		// Payshen: 8-200 printable ASCII characters.
		$this->assertMatchesRegularExpression( '/^[\x20-\x7e]{8,200}$/', $key );
	}

	public function test_payment_key_is_stable_for_a_double_click(): void {
		$a = Payshen_Keys::payment( 1024, 'wc_order_abc123', 1, 'https://shop.example' );
		$b = Payshen_Keys::payment( 1024, 'wc_order_abc123', 1, 'https://shop.example' );
		$this->assertSame( $a, $b );
		$this->assert_valid_idempotency_key( $a );
		$this->assertStringStartsWith( 'wc-pay-1024-1-', $a );
	}

	public function test_payment_key_changes_for_a_new_attempt_order_or_store(): void {
		$base = Payshen_Keys::payment( 1024, 'wc_order_abc123', 1, 'https://shop.example' );
		$this->assertNotSame( $base, Payshen_Keys::payment( 1024, 'wc_order_abc123', 2, 'https://shop.example' ) );
		$this->assertNotSame( $base, Payshen_Keys::payment( 1025, 'wc_order_abc123', 1, 'https://shop.example' ) );
		$this->assertNotSame( $base, Payshen_Keys::payment( 1024, 'wc_order_other', 1, 'https://shop.example' ) );
		$this->assertNotSame( $base, Payshen_Keys::payment( 1024, 'wc_order_abc123', 1, 'https://other.example' ) );
	}

	public function test_operation_keys(): void {
		$id = '7d9f2c1e-1111-4a2b-9c3d-123456789abc';
		$this->assertSame( 'wc-capture-' . $id, Payshen_Keys::capture( $id ) );
		$this->assertSame( 'wc-void-' . $id, Payshen_Keys::void( $id ) );
		$this->assert_valid_idempotency_key( Payshen_Keys::capture( $id ) );
		$this->assertSame( 'wc-capture-abc', Payshen_Keys::capture( "a\nb c" ) );
	}

	public function test_refund_key_is_one_per_woocommerce_refund(): void {
		$a = Payshen_Keys::refund( 10, 55, 'https://shop.example' );
		$this->assertSame( $a, Payshen_Keys::refund( 10, 55, 'https://shop.example' ) );
		$this->assertNotSame( $a, Payshen_Keys::refund( 10, 56, 'https://shop.example' ) );
		$this->assert_valid_idempotency_key( $a );
	}

	public function test_return_token(): void {
		$token = Payshen_Keys::return_token( 7, 'wc_order_k', 'salt' );
		$this->assertSame( 32, strlen( $token ) );
		$this->assertTrue( Payshen_Keys::check_return_token( $token, 7, 'wc_order_k', 'salt' ) );
		$this->assertFalse( Payshen_Keys::check_return_token( $token, 8, 'wc_order_k', 'salt' ) );
		$this->assertFalse( Payshen_Keys::check_return_token( $token, 7, 'wc_order_x', 'salt' ) );
		$this->assertFalse( Payshen_Keys::check_return_token( $token, 7, 'wc_order_k', 'other' ) );
		$this->assertFalse( Payshen_Keys::check_return_token( '', 7, 'wc_order_k', 'salt' ) );
		$this->assertFalse( Payshen_Keys::check_return_token( null, 7, 'wc_order_k', 'salt' ) );
	}

	public function test_api_key_prefixes(): void {
		$this->assertTrue( Payshen_Keys::is_api_key( 'ps_test_' . str_repeat( 'a', 43 ), 'test' ) );
		$this->assertTrue( Payshen_Keys::is_api_key( 'ps_live_Ab-_09xyzXYZ', 'live' ) );
		$this->assertFalse( Payshen_Keys::is_api_key( 'ps_live_Ab-_09xyzXYZ', 'test' ) );
		$this->assertFalse( Payshen_Keys::is_api_key( 'ps_test_Ab-_09xyzXYZ', 'live' ) );
		$this->assertFalse( Payshen_Keys::is_api_key( 'ps_test_short', 'test' ) );
		$this->assertFalse( Payshen_Keys::is_api_key( ' ps_test_aaaaaaaaaaaa', 'test' ) );
		$this->assertFalse( Payshen_Keys::is_api_key( 'sk_test_aaaaaaaaaaaa', 'test' ) );
		$this->assertFalse( Payshen_Keys::is_api_key( null, 'test' ) );
	}

	public function test_webhook_secret_prefix(): void {
		$this->assertTrue( Payshen_Keys::is_webhook_secret( 'whsec_' . str_repeat( 'Z', 43 ) ) );
		$this->assertFalse( Payshen_Keys::is_webhook_secret( 'ps_test_aaaaaaaaaaaa' ) );
		$this->assertFalse( Payshen_Keys::is_webhook_secret( 'whsec_' ) );
	}
}
