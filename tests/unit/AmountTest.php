<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase {

	public static function conversions(): array {
		return array(
			'EUR string'                  => array( '19.99', 'EUR', 1999 ),
			'EUR float'                   => array( 19.99, 'EUR', 1999 ),
			'EUR int'                     => array( 20, 'EUR', 2000 ),
			'EUR half up from string'     => array( '1.005', 'EUR', 101 ),
			'EUR half up from float'      => array( 1.005, 'EUR', 101 ),
			'EUR float noise'             => array( 0.1 + 0.2, 'EUR', 30 ),
			'EUR carry'                   => array( '9.999', 'EUR', 1000 ),
			'EUR below half'              => array( '10.004', 'EUR', 1000 ),
			'EUR no decimals'             => array( '50', 'EUR', 5000 ),
			'EUR trailing dot'            => array( '50.', 'EUR', 5000 ),
			'EUR leading dot'             => array( '.5', 'EUR', 50 ),
			'EUR zero'                    => array( '0.00', 'EUR', 0 ),
			'lowercase currency'          => array( '1.00', 'usd', 100 ),
			'JPY whole'                   => array( '5000', 'JPY', 5000 ),
			'JPY store decimals down'     => array( '5000.40', 'JPY', 5000 ),
			'JPY store decimals up'       => array( '5000.50', 'JPY', 5001 ),
			'KRW'                         => array( 12000.0, 'KRW', 12000 ),
			'KWD three decimals'          => array( '1.234', 'KWD', 1234 ),
			'KWD rounds at third'         => array( '1.2345', 'KWD', 1235 ),
			'BHD two given'               => array( '10.5', 'BHD', 10500 ),
			'HUF is whole units for Payshen' => array( '1234.50', 'HUF', 1235 ),
			'IQD is whole units for Payshen' => array( '1000', 'IQD', 1000 ),
			'IDR is whole units for Payshen' => array( '150000.00', 'IDR', 150000 ),
			'large'                       => array( '999999.99', 'EUR', 99999999 ),
		);
	}

	#[DataProvider( 'conversions' )]
	public function test_to_minor( $amount, string $currency, int $expected ): void {
		$this->assertSame( $expected, Payshen_Amount::to_minor( $amount, $currency ) );
	}

	public function test_exponents(): void {
		$this->assertSame( 2, Payshen_Amount::exponent( 'EUR' ) );
		$this->assertSame( 2, Payshen_Amount::exponent( 'XYZ' ) );
		$this->assertSame( 0, Payshen_Amount::exponent( 'JPY' ) );
		$this->assertSame( 0, Payshen_Amount::exponent( 'clp' ) );
		$this->assertSame( 3, Payshen_Amount::exponent( 'OMR' ) );
		$this->assertSame( 3, Payshen_Amount::exponent( 'TND' ) );
	}

	public function test_from_minor(): void {
		$this->assertSame( '19.99', Payshen_Amount::from_minor( 1999, 'EUR' ) );
		$this->assertSame( '0.05', Payshen_Amount::from_minor( 5, 'EUR' ) );
		$this->assertSame( '0.00', Payshen_Amount::from_minor( 0, 'EUR' ) );
		$this->assertSame( '5000', Payshen_Amount::from_minor( 5000, 'JPY' ) );
		$this->assertSame( '1.250', Payshen_Amount::from_minor( 1250, 'KWD' ) );
		$this->assertSame( '-1.50', Payshen_Amount::from_minor( -150, 'USD' ) );
	}

	public function test_round_trip(): void {
		foreach ( array( 'EUR', 'JPY', 'KWD' ) as $currency ) {
			foreach ( array( 1, 50, 999, 123456, 100000000 ) as $minor ) {
				$this->assertSame( $minor, Payshen_Amount::to_minor( Payshen_Amount::from_minor( $minor, $currency ), $currency ) );
			}
		}
	}

	public function test_range(): void {
		$this->assertFalse( Payshen_Amount::in_range( 49 ) );
		$this->assertTrue( Payshen_Amount::in_range( 50 ) );
		$this->assertTrue( Payshen_Amount::in_range( 100000000 ) );
		$this->assertFalse( Payshen_Amount::in_range( 100000001 ) );
	}

	public static function invalid(): array {
		return array( array( '-1.00' ), array( 'abc' ), array( '1,00' ), array( '1e3' ), array( '' ), array( -5 ), array( NAN ), array( INF ), array( null ), array( array() ) );
	}

	#[DataProvider( 'invalid' )]
	public function test_invalid_amounts_throw( $amount ): void {
		$this->expectException( InvalidArgumentException::class );
		Payshen_Amount::to_minor( $amount, 'EUR' );
	}
}
