<?php
/**
 * A small Payshen API client on the WordPress HTTP API.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calls Payshen's REST API v1 the way the official PHP SDK does: Bearer key,
 * JSON, an Idempotency-Key on every POST, retries only where a retry cannot
 * do harm, and the API's `{ "error": { "code", "message" } }` as an exception.
 */
class Payshen_API_Client {

	const DEFAULT_BASE_URL = 'https://www.payshen.com';

	/** Statuses worth another attempt, as in the SDKs. */
	const RETRY_STATUSES = array( 408, 425, 429, 500, 502, 503, 504 );

	/**
	 * Error codes a retry cannot improve although their status is retryable:
	 * the provider may have acted, and the API says not to send it again.
	 */
	const FINAL_CODES = array(
		'payment_result_unknown',
		'state_unconfirmed',
		'state_conflict',
		'capture_result_unknown',
		'void_result_unknown',
		'refund_result_unknown',
		'psp_error',
		'provider_unavailable',
		'merchant_limit',
	);

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Base URL without a trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Seconds per attempt.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Extra attempts after the first.
	 *
	 * @var int
	 */
	private $max_retries;

	/**
	 * Sends one request: fn( method, url, headers, body|null, timeout ) => array{status:int, headers:array, body:string}, or throws Payshen_API_Exception with status 0.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Waits between attempts: fn( milliseconds ).
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Constructor.
	 *
	 * @param string $api_key  ps_test_... or ps_live_...
	 * @param array  $options  base_url, timeout (seconds, default 30), max_retries (default 2), transport, sleep, user_agent.
	 * @throws InvalidArgumentException For a missing key or a base URL that is not https.
	 */
	public function __construct( $api_key, array $options = array() ) {
		if ( ! is_string( $api_key ) || ! str_starts_with( $api_key, 'ps_' ) ) {
			throw new InvalidArgumentException( 'A Payshen API key (ps_test_... or ps_live_...) is required.' );
		}
		$base = rtrim( (string) ( $options['base_url'] ?? self::DEFAULT_BASE_URL ), '/' );
		if ( ! self::is_allowed_base_url( $base ) ) {
			throw new InvalidArgumentException( 'The Payshen API URL must use https.' );
		}
		$this->api_key     = $api_key;
		$this->base_url    = $base;
		$this->timeout     = max( 5, (int) ( $options['timeout'] ?? 30 ) );
		$this->max_retries = max( 0, (int) ( $options['max_retries'] ?? 2 ) );
		$user_agent        = (string) ( $options['user_agent'] ?? 'payshen-for-woocommerce' );
		$this->transport   = $options['transport'] ?? self::wp_transport( $user_agent );
		$this->sleep       = $options['sleep'] ?? static function ( $ms ) {
			usleep( (int) $ms * 1000 );
		};
	}

	/**
	 * Whether a base URL may carry an API key: https, or http to this machine for a mock server.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_allowed_base_url( $url ) {
		if ( preg_match( '#^https://[^/\s]+#i', $url ) === 1 ) {
			return true;
		}
		return preg_match( '#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|$)#i', $url ) === 1;
	}

	/**
	 * Whether a URL may be sent to the customer as a payment page: https, or
	 * http to this machine for local development. Plain http elsewhere would
	 * let anyone on the customer's network rewrite the page they pay on.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_payment_page_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) || preg_match( '#^\S+$#', $url ) !== 1 ) {
			return false;
		}
		if ( preg_match( '#^https://[^/\s]+#i', $url ) === 1 ) {
			return true;
		}
		return preg_match( '#^http://(localhost|127\.0\.0\.1)(:\d+)?(/|\?|\#|$)#i', $url ) === 1;
	}

	/**
	 * A payment id checked before it becomes part of the path. Encoding alone
	 * is not enough: `.` and `..` pass rawurlencode unchanged, so a read of
	 * `.` would hit the list and a refund of `..` another route.
	 *
	 * @param mixed $payment_id Payment id.
	 * @return string
	 * @throws Payshen_API_Exception With `invalid_payment_id`, before anything is sent.
	 */
	private static function path_id( $payment_id ) {
		if ( ! is_string( $payment_id ) || preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $payment_id ) !== 1 ) {
			// 400, not 0: a refusal, never an unknown outcome to retry or wait for.
			throw new Payshen_API_Exception( 400, 'invalid_payment_id', 'The payment id must be 1-128 letters, digits, "_" or "-".' );
		}
		return $payment_id;
	}

	/**
	 * Create a payment. The hosted flow answers REQUIRES_ACTION with next_action.url.
	 *
	 * @param array  $body            Request body.
	 * @param string $idempotency_key Key.
	 * @return array The payment (`data`).
	 */
	public function create_payment( array $body, $idempotency_key ) {
		return $this->data( $this->request( 'POST', '/api/v1/payments', $body, $idempotency_key ) );
	}

	/**
	 * Retrieve a payment.
	 *
	 * @param string $payment_id Payment id.
	 * @return array
	 * @throws Payshen_API_Exception On an error answer, or `invalid_payment_id` before sending.
	 */
	public function get_payment( $payment_id ) {
		return $this->data( $this->request( 'GET', '/api/v1/payments/' . self::path_id( $payment_id ) ) );
	}

	/**
	 * Capture an authorization, in full (null) or in part.
	 *
	 * @param string   $payment_id      Payment id.
	 * @param int|null $amount          Minor units, or null for all of it.
	 * @param string   $idempotency_key Key.
	 * @return array
	 */
	public function capture( $payment_id, $amount, $idempotency_key ) {
		$body = null === $amount ? array() : array( 'amount' => (int) $amount );
		return $this->data( $this->request( 'POST', '/api/v1/payments/' . self::path_id( $payment_id ) . '/capture', $body, $idempotency_key ) );
	}

	/**
	 * Release an authorization.
	 *
	 * @param string $payment_id      Payment id.
	 * @param string $idempotency_key Key.
	 * @return array
	 */
	public function void( $payment_id, $idempotency_key ) {
		return $this->data( $this->request( 'POST', '/api/v1/payments/' . self::path_id( $payment_id ) . '/void', array(), $idempotency_key ) );
	}

	/**
	 * Refund, in full (null) or in part. A refund the provider has not
	 * confirmed yet is thrown as 409 `refund_pending`: accepted, not failed.
	 *
	 * @param string   $payment_id      Payment id.
	 * @param int|null $amount          Minor units.
	 * @param string   $idempotency_key Key. It also names the refund.
	 * @return array
	 */
	public function refund( $payment_id, $amount, $idempotency_key ) {
		$body = null === $amount ? array() : array( 'amount' => (int) $amount );
		return $this->data( $this->request( 'POST', '/api/v1/payments/' . self::path_id( $payment_id ) . '/refunds', $body, $idempotency_key ) );
	}

	/**
	 * A page of payments. Used to check that a key works.
	 *
	 * @param int $limit 1..100.
	 * @return array The whole answer.
	 */
	public function list_payments( $limit = 1 ) {
		return $this->request( 'GET', '/api/v1/payments?limit=' . max( 1, min( 100, (int) $limit ) ) );
	}

	/**
	 * Call an endpoint and return the decoded answer.
	 *
	 * @param string      $method          HTTP method.
	 * @param string      $path            Path starting with /api/v1.
	 * @param array|null  $body            JSON body; an empty array is sent as {}.
	 * @param string|null $idempotency_key Key for a POST; one is generated when missing.
	 * @return array
	 * @throws Payshen_API_Exception On any answer that is not 2xx, or no answer.
	 */
	public function request( $method, $path, $body = null, $idempotency_key = null ) {
		$method  = strtoupper( $method );
		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Accept'        => 'application/json',
		);
		$encoded = null;
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
			$encoded                 = json_encode( array() === $body ? new stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Kept free of WordPress so it is testable alone.
			if ( false === $encoded ) {
				throw new Payshen_API_Exception( 0, 'invalid_body', 'The request could not be encoded.' );
			}
		}
		if ( 'POST' === $method ) {
			// Without a key, a retry after a dropped connection could act twice.
			$headers['Idempotency-Key'] = ( null !== $idempotency_key && '' !== $idempotency_key ) ? $idempotency_key : 'wc-' . bin2hex( random_bytes( 16 ) );
		}

		$url = $this->base_url . $path;
		for ( $attempt = 0; ; $attempt++ ) {
			try {
				$response = call_user_func( $this->transport, $method, $url, $headers, $encoded, $this->timeout );
			} catch ( Payshen_API_Exception $e ) {
				if ( $e->is_network_error() && $attempt < $this->max_retries ) {
					call_user_func( $this->sleep, $this->backoff( $attempt ) );
					continue;
				}
				throw $e;
			}

			$status  = (int) $response['status'];
			$payload = self::decode( (string) $response['body'] );
			$rheads  = array_change_key_case( (array) ( $response['headers'] ?? array() ), CASE_LOWER );
			if ( $status >= 200 && $status < 300 ) {
				return $payload ?? array();
			}

			$error      = is_array( $payload['error'] ?? null ) ? $payload['error'] : array();
			$code       = is_string( $error['code'] ?? null ) ? $error['code'] : null;
			$message    = is_string( $error['message'] ?? null ) ? $error['message'] : null;
			$final      = null !== $code && in_array( $code, self::FINAL_CODES, true );
			$retryable  = in_array( $status, self::RETRY_STATUSES, true ) && ! $final;
			$request_id = isset( $rheads['x-request-id'] ) ? (string) $rheads['x-request-id'] : null;

			if ( $retryable && $attempt < $this->max_retries ) {
				$retry_after = isset( $rheads['retry-after'] ) && ctype_digit( (string) $rheads['retry-after'] ) ? (int) $rheads['retry-after'] : null;
				call_user_func( $this->sleep, $this->backoff( $attempt, $retry_after ) );
				continue;
			}

			if ( null === $code ) {
				$code = 401 === $status || 403 === $status ? 'unauthorized' : ( 422 === $status ? 'validation_error' : ( 429 === $status ? 'rate_limited' : 'unknown_error' ) );
			}
			throw new Payshen_API_Exception( $status, $code, $message ?? sprintf( 'Request failed with status %d.', $status ), $request_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; escaped where shown.
		}
	}

	/**
	 * The `data` member of an answer.
	 *
	 * @param array $answer Decoded answer.
	 * @return array
	 */
	private function data( array $answer ) {
		return is_array( $answer['data'] ?? null ) ? $answer['data'] : array();
	}

	/**
	 * Decode JSON to an array, or null.
	 *
	 * @param string $body Body.
	 * @return array|null
	 */
	private static function decode( $body ) {
		if ( '' === $body ) {
			return null;
		}
		$decoded = json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Milliseconds to wait: short, because a shopper or a webhook is waiting.
	 *
	 * @param int      $attempt     Attempts so far.
	 * @param int|null $retry_after Seconds the API asked for.
	 * @return int
	 */
	private function backoff( $attempt, $retry_after = null ) {
		if ( null !== $retry_after && $retry_after > 0 ) {
			return min( 2000, $retry_after * 1000 );
		}
		$exp = min( 2000, 250 * ( 2 ** $attempt ) );
		return (int) ( $exp + self::jitter( (int) ( $exp * 0.3 ) ) );
	}

	/**
	 * The transport on wp_remote_request: TLS verified, no redirects followed.
	 *
	 * @param string $user_agent User-Agent.
	 * @return callable
	 */
	private static function wp_transport( $user_agent ) {
		return static function ( $method, $url, $headers, $body, $timeout ) use ( $user_agent ) {
			if ( ! function_exists( 'wp_remote_request' ) ) {
				throw new Payshen_API_Exception( 0, 'network_error', 'The WordPress HTTP API is not loaded.' );
			}
			$args = array(
				'method'      => $method,
				'headers'     => $headers,
				'timeout'     => $timeout,
				// A redirect on an API call is a wrong base URL; following it could send the key elsewhere.
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => $user_agent,
			);
			if ( null !== $body ) {
				$args['body'] = $body;
			}
			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				throw new Payshen_API_Exception( 0, 'network_error', 'Payshen could not be reached: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; escaped where shown.
			}
			$headers_out = array();
			$raw_headers = wp_remote_retrieve_headers( $response );
			if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
				foreach ( $raw_headers->getAll() as $name => $value ) {
					$headers_out[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
				}
			} elseif ( is_array( $raw_headers ) ) {
				foreach ( $raw_headers as $name => $value ) {
					$headers_out[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
				}
			}
			return array(
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'headers' => $headers_out,
				'body'    => (string) wp_remote_retrieve_body( $response ),
			);
		};
	}

	/**
	 * A random wait of 0..$max milliseconds.
	 *
	 * @param int $max Maximum.
	 * @return int
	 */
	private static function jitter( $max ) {
		if ( $max <= 0 ) {
			return 0;
		}
		return function_exists( 'wp_rand' ) ? (int) wp_rand( 0, $max ) : random_int( 0, $max );
	}
}
