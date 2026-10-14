<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase {

	public static function statuses(): array {
		return array(
			array( 'CAPTURED', Payshen_Status::PAID ),
			array( 'captured', Payshen_Status::PAID ),
			array( 'SETTLED', Payshen_Status::PAID ),
			array( 'PARTIALLY_REFUNDED', Payshen_Status::PAID ),
			array( 'REFUNDED', Payshen_Status::PAID ),
			array( 'AUTHORIZED', Payshen_Status::AUTHORIZED ),
			array( 'CREATED', Payshen_Status::AWAITING_CUSTOMER ),
			array( 'REQUIRES_ACTION', Payshen_Status::AWAITING_CUSTOMER ),
			array( 'PENDING', Payshen_Status::IN_FLIGHT ),
			array( 'PROCESSING', Payshen_Status::IN_FLIGHT ),
			array( 'UNKNOWN', Payshen_Status::IN_FLIGHT ),
			array( 'DECLINED', Payshen_Status::DECLINED ),
			array( 'FAILED', Payshen_Status::FAILED ),
			array( 'CANCELLED', Payshen_Status::CANCELLED ),
			array( 'CHARGEBACK', Payshen_Status::CHARGEBACK ),
			array( 'SOMETHING_NEW', Payshen_Status::UNRECOGNISED ),
			array( '', Payshen_Status::UNRECOGNISED ),
			array( null, Payshen_Status::UNRECOGNISED ),
		);
	}

	#[DataProvider( 'statuses' )]
	public function test_action( $status, string $expected ): void {
		$this->assertSame( $expected, Payshen_Status::action( $status ) );
	}

	public function test_only_collected_statuses_are_paid(): void {
		foreach ( array( 'AUTHORIZED', 'PENDING', 'UNKNOWN', 'REQUIRES_ACTION', 'CREATED', 'DECLINED', 'FAILED', 'CANCELLED', 'WHATEVER' ) as $status ) {
			$this->assertNotSame( Payshen_Status::PAID, Payshen_Status::action( $status ), $status );
		}
	}

	public function test_unknown_is_never_a_decline_or_settled(): void {
		$action = Payshen_Status::action( 'UNKNOWN' );
		$this->assertFalse( Payshen_Status::is_unpaid_end( $action ) );
		$this->assertFalse( Payshen_Status::is_settled( $action ) );
		$this->assertFalse( Payshen_Status::is_settled( Payshen_Status::action( 'NEW_STATUS' ) ) );
		$this->assertFalse( Payshen_Status::is_settled( Payshen_Status::action( 'REQUIRES_ACTION' ) ) );
	}

	public function test_settled(): void {
		foreach ( array( 'CAPTURED', 'AUTHORIZED', 'DECLINED', 'FAILED', 'CANCELLED' ) as $status ) {
			$this->assertTrue( Payshen_Status::is_settled( Payshen_Status::action( $status ) ), $status );
		}
	}

	public function test_decline_words(): void {
		$this->assertSame( 'insufficient funds', Payshen_Status::decline_words( 'INSUFFICIENT_FUNDS' ) );
		$this->assertSame( 'authentication failed', Payshen_Status::decline_words( 'authentication_failed' ) );
		$this->assertSame( '', Payshen_Status::decline_words( null ) );
		$this->assertSame( '', Payshen_Status::decline_words( '<script>' ) );
	}
}
