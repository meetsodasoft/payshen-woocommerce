<?php

use PHPUnit\Framework\TestCase;

final class ApiClientTest extends TestCase {

	/** @var array<int, array> */
	private array $sent = array();

	/** @var int[] */
	private array $slept = array();

	/**
	 * A client whose transport answers from a list (an array answer, or a Payshen_API_Exception to throw).
	 */
	private function client( array $answers, array $options = array() ): Payshen_API_Client {
		$this->sent  = array();
		$this->slept = array();
		$transport   = function ( $method, $url, $headers, $body, $timeout ) use ( &$answers ) {
			$this->sent[] = compact( 'method', 'url', 'headers', 'body', 'timeout' );
			$next         = array_shift( $answers );
			if ( $next instanceof Payshen_API_Exception ) {
				throw $next;
			}
			return $next;
		};
		return new Payshen_API_Client(
			'ps_test_abcdefghijklmnop',
			$options + array(
				'transport' => $transport,
				'sleep'     => function ( $ms ) {
					$this->slept[] = $ms;
				},
			)
		);
	}

	private static function json( int $status, array $body, array $headers = array() ): array {
		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => json_encode( $body ),
		);
	}

	public function test_create_sends_key_auth_and_json(): void {
		$client  = $this->client( array( self::json( 201, array( 'data' => array( 'id' => 'p1', 'status' => 'REQUIRES_ACTION', 'next_action' => array( 'type' => 'REDIRECT', 'url' => 'https://pay.example/x' ) ) ) ) ) );
		$payment = $client->create_payment( array( 'amount' => 1999, 'currency' => 'EUR', 'country' => 'DE' ), 'wc-pay-1-1-abc' );

		$this->assertSame( 'p1', $payment['id'] );
		$req = $this->sent[0];
		$this->assertSame( 'POST', $req['method'] );
		$this->assertSame( 'https://www.payshen.com/api/v1/payments', $req['url'] );
		$this->assertSame( 'Bearer ps_test_abcdefghijklmnop', $req['headers']['Authorization'] );
		$this->assertSame( 'wc-pay-1-1-abc', $req['headers']['Idempotency-Key'] );
		$this->assertSame( 'application/json', $req['headers']['Content-Type'] );
		$this->assertSame( '{"amount":1999,"currency":"EUR","country":"DE"}', $req['body'] );
	}

	public function test_get_has_no_key_and_puts_the_id_in_the_path(): void {
		$client = $this->client( array( self::json( 200, array( 'data' => array( 'id' => 'pay_01J-xY9' ) ) ) ), array( 'base_url' => 'https://staging.payshen.com/' ) );
		$client->get_payment( 'pay_01J-xY9' );
		$this->assertSame( 'GET', $this->sent[0]['method'] );
		$this->assertSame( 'https://staging.payshen.com/api/v1/payments/pay_01J-xY9', $this->sent[0]['url'] );
		$this->assertArrayNotHasKey( 'Idempotency-Key', $this->sent[0]['headers'] );
		$this->assertNull( $this->sent[0]['body'] );
	}

	public function test_empty_body_is_an_object_and_post_always_has_a_key(): void {
		$client = $this->client( array( self::json( 200, array( 'data' => array( 'status' => 'CANCELLED' ) ) ) ) );
		$client->request( 'POST', '/api/v1/payments/p1/void', array() );
		$this->assertSame( '{}', $this->sent[0]['body'] );
		$this->assertMatchesRegularExpression( '/^wc-[0-9a-f]{32}$/', $this->sent[0]['headers']['Idempotency-Key'] );
	}

	public function test_capture_and_refund_bodies(): void {
		$client = $this->client( array( self::json( 200, array( 'data' => array() ) ), self::json( 200, array( 'data' => array() ) ), self::json( 200, array( 'data' => array() ) ) ) );
		$client->capture( 'p1', null, 'k-capture-1' );
		$client->capture( 'p1', 500, 'k-capture-1' );
		$client->refund( 'p1', 250, 'k-refund-12' );
		$this->assertSame( '{}', $this->sent[0]['body'] );
		$this->assertSame( '{"amount":500}', $this->sent[1]['body'] );
		$this->assertSame( 'https://www.payshen.com/api/v1/payments/p1/refunds', $this->sent[2]['url'] );
		$this->assertSame( '{"amount":250}', $this->sent[2]['body'] );
		$this->assertSame( 'k-refund-12', $this->sent[2]['headers']['Idempotency-Key'] );
	}

	public function test_retryable_status_is_retried_with_the_same_key(): void {
		$client = $this->client(
			array(
				self::json( 503, array( 'error' => array( 'code' => 'internal_error', 'message' => 'x' ) ) ),
				self::json( 201, array( 'data' => array( 'id' => 'p1' ) ) ),
			)
		);
		$this->assertSame( 'p1', $client->create_payment( array( 'amount' => 100 ), 'same-key-1' )['id'] );
		$this->assertCount( 2, $this->sent );
		$this->assertSame( 'same-key-1', $this->sent[1]['headers']['Idempotency-Key'] );
		$this->assertCount( 1, $this->slept );
	}

	public function test_final_codes_are_not_retried(): void {
		$client = $this->client( array( self::json( 502, array( 'error' => array( 'code' => 'refund_result_unknown', 'message' => 'no answer' ) ), array( 'X-Request-Id' => 'req_9' ) ) ) );
		try {
			$client->refund( 'p1', 100, 'k-refund-1' );
			$this->fail( 'expected an exception' );
		} catch ( Payshen_API_Exception $e ) {
			$this->assertSame( 502, $e->status );
			$this->assertSame( 'refund_result_unknown', $e->error_code );
			$this->assertSame( 'no answer', $e->getMessage() );
			$this->assertSame( 'req_9', $e->request_id );
		}
		$this->assertCount( 1, $this->sent );
	}

	public function test_refund_pending_is_an_error_code_not_a_retry(): void {
		$client = $this->client( array( self::json( 409, array( 'error' => array( 'code' => 'refund_pending', 'message' => 'pending' ) ) ) ) );
		try {
			$client->refund( 'p1', 100, 'k-refund-1' );
			$this->fail( 'expected an exception' );
		} catch ( Payshen_API_Exception $e ) {
			$this->assertSame( 'refund_pending', $e->error_code );
			$this->assertFalse( $e->is_network_error() );
		}
		$this->assertCount( 1, $this->sent );
	}

	public function test_network_errors_are_retried_then_thrown(): void {
		$err    = new Payshen_API_Exception( 0, 'network_error', 'timeout' );
		$client = $this->client( array( $err, $err, $err ) );
		try {
			$client->get_payment( 'p1' );
			$this->fail( 'expected an exception' );
		} catch ( Payshen_API_Exception $e ) {
			$this->assertTrue( $e->is_network_error() );
		}
		$this->assertCount( 3, $this->sent );
	}

	public function test_rate_limit_waits_retry_after_capped(): void {
		$client = $this->client(
			array(
				self::json( 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'slow' ) ), array( 'Retry-After' => '30' ) ),
				self::json( 200, array( 'data' => array( 'id' => 'p1' ) ) ),
			)
		);
		$client->get_payment( 'p1' );
		$this->assertSame( array( 2000 ), $this->slept );
	}

	public function test_errors_without_a_body_get_a_code(): void {
		$client = $this->client( array( array( 'status' => 401, 'headers' => array(), 'body' => '' ) ) );
		try {
			$client->get_payment( 'p1' );
			$this->fail( 'expected an exception' );
		} catch ( Payshen_API_Exception $e ) {
			$this->assertSame( 'unauthorized', $e->error_code );
			$this->assertSame( 401, $e->status );
		}
	}

	public function test_base_url_must_be_https(): void {
		$this->assertTrue( Payshen_API_Client::is_allowed_base_url( 'https://staging.payshen.com' ) );
		$this->assertTrue( Payshen_API_Client::is_allowed_base_url( 'http://localhost:3000' ) );
		$this->assertTrue( Payshen_API_Client::is_allowed_base_url( 'http://127.0.0.1' ) );
		$this->assertFalse( Payshen_API_Client::is_allowed_base_url( 'http://staging.payshen.com' ) );
		$this->assertFalse( Payshen_API_Client::is_allowed_base_url( 'http://localhost.evil.com' ) );
		$this->expectException( InvalidArgumentException::class );
		new Payshen_API_Client( 'ps_test_abc', array( 'base_url' => 'http://payshen.com' ) );
	}

	public function test_an_id_that_is_not_an_id_is_refused_before_sending(): void {
		$client = $this->client( array() );
		$calls  = array(
			'get_payment' => fn ( $id ) => $client->get_payment( $id ),
			'capture'     => fn ( $id ) => $client->capture( $id, null, 'k-capture-1' ),
			'void'        => fn ( $id ) => $client->void( $id, 'k-void-0001' ),
			'refund'      => fn ( $id ) => $client->refund( $id, 100, 'k-refund-12' ),
		);
		// "." would read the list, ".." another route; the rest are not ids.
		foreach ( $calls as $name => $call ) {
			foreach ( array( '', '.', '..', 'a/b', 'p1/../x', "p1\n", 'p 1', str_repeat( 'a', 129 ), null, 12 ) as $bad ) {
				try {
					$call( $bad );
					$this->fail( $name . ' accepted ' . json_encode( $bad ) );
				} catch ( Payshen_API_Exception $e ) {
					$this->assertSame( 'invalid_payment_id', $e->error_code );
					$this->assertSame( 400, $e->status );
					$this->assertFalse( $e->is_network_error() );
				}
			}
		}
		$this->assertSame( array(), $this->sent, 'nothing is sent' );
	}

	public function test_a_128_character_id_is_accepted(): void {
		$client = $this->client( array( self::json( 200, array( 'data' => array() ) ) ) );
		$client->void( str_repeat( 'p', 128 ), 'k-void-0001' );
		$this->assertSame( 'https://www.payshen.com/api/v1/payments/' . str_repeat( 'p', 128 ) . '/void', $this->sent[0]['url'] );
	}

	public function test_a_payment_page_must_be_https_except_on_localhost(): void {
		foreach ( array( 'https://pay.payshen.com/p/abc', 'https://pay.example/x?y=1', 'http://localhost/pay', 'http://localhost:3000/p/abc', 'http://127.0.0.1:8080/p', 'HTTP://LOCALHOST' ) as $ok ) {
			$this->assertTrue( Payshen_API_Client::is_payment_page_url( $ok ), $ok );
		}
		foreach ( array( '', 'http://pay.payshen.com/p/abc', 'http://localhost.evil.com/p', 'http://localhost@evil.com/', 'http://127.0.0.1.evil.com/', 'http://127.0.0.1:80@evil.com/', 'javascript:alert(1)', 'ftp://pay.example/x', '//pay.example/x', 'https://pay.example/a b', null ) as $bad ) {
			$this->assertFalse( Payshen_API_Client::is_payment_page_url( $bad ), (string) $bad );
		}
	}

	public function test_key_is_required(): void {
		$this->expectException( InvalidArgumentException::class );
		new Payshen_API_Client( '' );
	}
}
