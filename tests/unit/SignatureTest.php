<?php

use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase {

	private const SECRET = 'whsec_test_secret_value_123456';
	private const BODY   = '{"id":"evt_1","type":"payment.captured","created":"2026-10-14T10:00:00Z","data":{"id":"p1"}}';

	public function test_valid_signature(): void {
		$now    = 1_800_000_000;
		$header = Payshen_Signature::sign( self::BODY, self::SECRET, $now );
		$this->assertSame( Payshen_Signature::OK, Payshen_Signature::verify( self::BODY, $header, self::SECRET, $now ) );
	}

	public function test_matches_the_sdk_formula(): void {
		// t=<unix>,v1=hex(HMAC-SHA256(secret, "<t>.<body>")), as payshen-php Webhook::verifySignature.
		$t      = 1_800_000_000;
		$v1     = hash_hmac( 'sha256', $t . '.' . self::BODY, self::SECRET );
		$header = "t={$t},v1={$v1}";
		$this->assertSame( Payshen_Signature::OK, Payshen_Signature::verify( self::BODY, $header, self::SECRET, $t + 10 ) );
	}

	public function test_wrong_secret(): void {
		$now    = 1_800_000_000;
		$header = Payshen_Signature::sign( self::BODY, self::SECRET, $now );
		$this->assertSame( Payshen_Signature::INVALID_SIGNATURE, Payshen_Signature::verify( self::BODY, $header, 'whsec_other_secret_value', $now ) );
	}

	public function test_tampered_body(): void {
		$now    = 1_800_000_000;
		$header = Payshen_Signature::sign( self::BODY, self::SECRET, $now );
		$this->assertSame( Payshen_Signature::INVALID_SIGNATURE, Payshen_Signature::verify( str_replace( 'captured', 'declined', self::BODY ), $header, self::SECRET, $now ) );
	}

	public function test_body_whitespace_matters(): void {
		$now    = 1_800_000_000;
		$header = Payshen_Signature::sign( self::BODY, self::SECRET, $now );
		$this->assertSame( Payshen_Signature::INVALID_SIGNATURE, Payshen_Signature::verify( self::BODY . "\n", $header, self::SECRET, $now ) );
	}

	public function test_stale_and_future_timestamps(): void {
		$t      = 1_800_000_000;
		$header = Payshen_Signature::sign( self::BODY, self::SECRET, $t );
		$this->assertSame( Payshen_Signature::OK, Payshen_Signature::verify( self::BODY, $header, self::SECRET, $t + 300 ) );
		$this->assertSame( Payshen_Signature::EXPIRED_SIGNATURE, Payshen_Signature::verify( self::BODY, $header, self::SECRET, $t + 301 ) );
		$this->assertSame( Payshen_Signature::EXPIRED_SIGNATURE, Payshen_Signature::verify( self::BODY, $header, self::SECRET, $t - 301 ) );
	}

	public function test_missing_and_malformed(): void {
		$this->assertSame( Payshen_Signature::MISSING_SIGNATURE, Payshen_Signature::verify( self::BODY, null, self::SECRET ) );
		$this->assertSame( Payshen_Signature::MISSING_SIGNATURE, Payshen_Signature::verify( self::BODY, '  ', self::SECRET ) );
		$this->assertSame( Payshen_Signature::MALFORMED_SIGNATURE, Payshen_Signature::verify( self::BODY, 'v1=abc', self::SECRET ) );
		$this->assertSame( Payshen_Signature::MALFORMED_SIGNATURE, Payshen_Signature::verify( self::BODY, 't=abc,v1=abc', self::SECRET ) );
		$this->assertSame( Payshen_Signature::MALFORMED_SIGNATURE, Payshen_Signature::verify( self::BODY, 't=1800000000', self::SECRET ) );
		$this->assertSame( Payshen_Signature::MISSING_SECRET, Payshen_Signature::verify( self::BODY, 't=1,v1=a', '' ) );
	}

	public function test_spaces_and_several_signatures(): void {
		$t      = 1_800_000_000;
		$good   = hash_hmac( 'sha256', $t . '.' . self::BODY, self::SECRET );
		$header = "t={$t}, v1=" . str_repeat( '0', 64 ) . ", v1={$good}";
		$this->assertSame( Payshen_Signature::OK, Payshen_Signature::verify( self::BODY, $header, self::SECRET, $t ) );
	}
}
