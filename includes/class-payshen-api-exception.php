<?php
/**
 * An answer from the Payshen API that is not a success.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors the SDK's PayshenException: branch on `$error_code`, never on the message.
 *
 * `$status` is the HTTP status, 0 when no answer came (a network error: on a
 * POST the operation may or may not have happened, so the same
 * Idempotency-Key must be reused).
 */
class Payshen_API_Exception extends Exception {

	/**
	 * HTTP status, 0 for no answer.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Payshen's error code (`refund_pending`, `not_capturable`, `network_error`, ...).
	 *
	 * @var string
	 */
	public $error_code;

	/**
	 * The X-Request-Id of the failed call, for support.
	 *
	 * @var string|null
	 */
	public $request_id;

	/**
	 * Constructor.
	 *
	 * @param int         $status     HTTP status.
	 * @param string      $error_code Payshen error code.
	 * @param string      $message    Message.
	 * @param string|null $request_id X-Request-Id.
	 */
	public function __construct( $status, $error_code, $message, $request_id = null ) {
		parent::__construct( (string) $message, (int) $status );
		$this->status     = (int) $status;
		$this->error_code = (string) $error_code;
		$this->request_id = $request_id;
	}

	/**
	 * Whether no answer came, so the outcome is not known.
	 *
	 * @return bool
	 */
	public function is_network_error() {
		return 0 === $this->status;
	}
}
