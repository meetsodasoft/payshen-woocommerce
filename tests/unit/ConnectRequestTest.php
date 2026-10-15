<?php

use PHPUnit\Framework\TestCase;

final class ConnectRequestTest extends TestCase {

	public function test_verifier_and_state_are_fresh_and_well_formed(): void {
		$verifier = Payshen_Connect_Request::new_verifier();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{64}$/', $verifier );
		$this->assertNotSame( $verifier, Payshen_Connect_Request::new_verifier() );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', Payshen_Connect_Request::new_state() );
	}

	public function test_challenge_is_rfc7636_s256(): void {
		// The example from RFC 7636, appendix B.
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Payshen_Connect_Request::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
	}

	public function test_authorize_url_carries_the_challenge_never_the_verifier(): void {
		$url = Payshen_Connect_Request::authorize_url( 'https://www.payshen.com/', 'https://shop.example/', 'https://shop.example/wp-admin/admin-post.php?action=payshen_connect&_wpnonce=abc', 'https://shop.example/wp-json/payshen/v1/webhook', 'st', 'the-verifier' );
		$this->assertStringStartsWith( 'https://www.payshen.com/connect/woocommerce?', $url );
		$this->assertStringNotContainsString( 'the-verifier', $url );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( 'https://shop.example/wp-admin/admin-post.php?action=payshen_connect&_wpnonce=abc', $q['return_url'] );
		$this->assertSame( Payshen_Connect_Request::challenge( 'the-verifier' ), $q['code_challenge'] );
		$this->assertSame( 'S256', $q['code_challenge_method'] );
	}

	public function test_credentials_from_a_test_only_answer(): void {
		$creds = Payshen_Connect_Request::credentials(
			array(
				'workspace'  => array( 'name' => 'Atelier' ),
				'sandbox'    => array( 'api_key' => 'ps_test_abcdefgh1234', 'webhook_secret' => 'whsec_abcdefgh1234' ),
				'production' => null,
			)
		);
		$this->assertSame( 'ps_test_abcdefgh1234', $creds['test_api_key'] );
		$this->assertSame( 'whsec_abcdefgh1234', $creds['test_webhook_secret'] );
		$this->assertSame( '', $creds['live_api_key'] );
		$this->assertFalse( $creds['live'] );
		$this->assertSame( 'Atelier', $creds['workspace'] );
	}

	public function test_credentials_with_live_and_without_a_webhook(): void {
		$creds = Payshen_Connect_Request::credentials(
			array(
				'sandbox'    => array( 'api_key' => 'ps_test_abcdefgh1234', 'webhook_secret' => null ),
				'production' => array( 'api_key' => 'ps_live_abcdefgh1234', 'webhook_secret' => null ),
			)
		);
		$this->assertTrue( $creds['live'] );
		$this->assertSame( 'ps_live_abcdefgh1234', $creds['live_api_key'] );
		$this->assertSame( '', $creds['test_webhook_secret'] );
	}

	public function test_an_answer_that_is_not_one_is_refused(): void {
		$this->assertNull( Payshen_Connect_Request::credentials( null ) );
		$this->assertNull( Payshen_Connect_Request::credentials( array( 'error' => array( 'code' => 'invalid_grant' ) ) ) );
		// A live key where a test key belongs, or the other way round.
		$this->assertNull( Payshen_Connect_Request::credentials( array( 'sandbox' => array( 'api_key' => 'ps_live_abcdefgh1234' ) ) ) );
		$this->assertNull(
			Payshen_Connect_Request::credentials(
				array(
					'sandbox'    => array( 'api_key' => 'ps_test_abcdefgh1234' ),
					'production' => array( 'api_key' => 'ps_test_abcdefgh1234' ),
				)
			)
		);
	}
}
