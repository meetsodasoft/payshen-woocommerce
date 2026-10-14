<?php

use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase {

	public function test_full_customer(): void {
		$c = Payshen_Customer::build(
			array(
				'id'          => 'wc-12',
				'email'       => 'ana@example.com',
				'first_name'  => ' Ana ',
				'last_name'   => 'Horvat',
				'phone'       => '+385 (1) 234-5678',
				'line1'       => 'Ilica 1',
				'line2'       => 'Floor 2',
				'city'        => 'Zagreb',
				'postal_code' => '10000',
				'state'       => '',
				'country'     => 'hr',
				'ip'          => '203.0.113.9',
			)
		);
		$this->assertSame(
			array(
				'id'         => 'wc-12',
				'email'      => 'ana@example.com',
				'first_name' => 'Ana',
				'last_name'  => 'Horvat',
				'phone'      => '+38512345678',
				'address'    => array(
					'line1'       => 'Ilica 1 Floor 2',
					'city'        => 'Zagreb',
					'postal_code' => '10000',
					'country'     => 'HR',
				),
				'ip'         => '203.0.113.9',
			),
			$c
		);
	}

	public function test_invalid_fields_are_left_out_not_sent(): void {
		$c = Payshen_Customer::build(
			array(
				'email'   => 'not-an-email',
				'phone'   => '12',
				'country' => 'Croatia',
				'ip'      => '999.1.1.1',
			)
		);
		$this->assertNull( $c );
	}

	public function test_emails_payshen_refuses_are_dropped(): void {
		$this->assertArrayNotHasKey( 'email', (array) Payshen_Customer::build( array( 'email' => 'a@localhost', 'first_name' => 'A' ) ) );
		$this->assertArrayNotHasKey( 'email', (array) Payshen_Customer::build( array( 'email' => '.a@example.com', 'first_name' => 'A' ) ) );
		$this->assertArrayNotHasKey( 'email', (array) Payshen_Customer::build( array( 'email' => 'a..b@example.com', 'first_name' => 'A' ) ) );
		$this->assertSame( "o'neil+shop@mail.example.co", Payshen_Customer::build( array( 'email' => "o'neil+shop@mail.example.co" ) )['email'] );
	}

	public function test_phone_forms(): void {
		$this->assertSame( '+442079460958', Payshen_Customer::phone( '+44 (0)20 7946-0958' ) );
		$this->assertSame( '+442079460958', Payshen_Customer::phone( '0044 20 7946 0958' ) );
		$this->assertSame( '0612345678', Payshen_Customer::phone( '06 12 34 56 78' ) );
		$this->assertNull( Payshen_Customer::phone( '123' ) );
		$this->assertNull( Payshen_Customer::phone( '' ) );
		$this->assertNull( Payshen_Customer::phone( str_repeat( '1', 21 ) ) );
	}

	public function test_text_is_cleaned_and_cut(): void {
		$this->assertSame( 'a b', Payshen_Customer::text( "a\x00\nb", 10 ) );
		$this->assertSame( 'ŠŠŠ', Payshen_Customer::text( 'ŠŠŠŠŠ', 3 ) );
		$long = Payshen_Customer::build( array( 'first_name' => str_repeat( 'x', 150 ) ) );
		$this->assertSame( 100, strlen( $long['first_name'] ) );
	}

	public function test_ipv6(): void {
		$this->assertSame( '2001:db8::1', Payshen_Customer::build( array( 'ip' => '2001:db8::1' ) )['ip'] );
	}
}
