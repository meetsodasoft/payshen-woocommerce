<?php
/**
 * The webhook endpoint: /wp-json/payshen/v1/webhook.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives Payshen's signed events.
 *
 * Anyone can call the URL, so nothing is trusted until the signature over
 * the raw body verifies with a signing secret saved in the settings. Even
 * then the event is only a hint: the payment is read from the API before
 * anything changes. Answers: 2xx taken (Payshen does not send it again),
 * 400 a body that will never be valid (not retried), 401 a signature that
 * does not verify yet (retried, so fixing the secret recovers the events),
 * 503 try again later.
 */
final class Payshen_Webhook {

	/** How long a processed event id is remembered. Payshen retries for about 28 hours. */
	const SEEN_TTL = 259200;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * Register the route.
	 */
	public static function register_route() {
		register_rest_route(
			'payshen/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public on purpose: Payshen calls it. The signature is checked in handle().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle one delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$body    = (string) $request->get_body();
		$header  = $request->get_header( 'x_payshen_signature' );
		$gateway = payshen_wc_gateway();
		if ( ! $gateway ) {
			return self::answer( 503, 'not_ready' );
		}

		$secrets = array_filter(
			array(
				'live' => $gateway->webhook_secret( 'live' ),
				'test' => $gateway->webhook_secret( 'test' ),
			)
		);
		if ( array() === $secrets ) {
			Payshen_Logger::warning( 'Webhook received but no signing secret is saved' );
			return self::answer( 401, 'no_secret' );
		}

		$mode    = null;
		$verdict = Payshen_Signature::MISSING_SECRET;
		foreach ( $secrets as $candidate_mode => $secret ) {
			$verdict = Payshen_Signature::verify( $body, $header, $secret );
			if ( Payshen_Signature::OK === $verdict ) {
				$mode = $candidate_mode;
				break;
			}
			if ( Payshen_Signature::INVALID_SIGNATURE !== $verdict ) {
				// Missing, malformed or stale: the same for every secret.
				break;
			}
		}
		if ( null === $mode ) {
			// Anyone can post to this URL, so a refusal is a warning at most once in
			// ten minutes (enough to spot a wrong secret) and debug otherwise: junk
			// must not be able to fill the store's logs.
			if ( false === get_transient( 'payshen_badsig_logged' ) ) {
				set_transient( 'payshen_badsig_logged', 1, 10 * MINUTE_IN_SECONDS );
				Payshen_Logger::warning( 'Webhook signature did not verify (further refusals in the next 10 minutes are logged at debug level)', array( 'reason' => $verdict ) );
			} else {
				Payshen_Logger::debug( 'Webhook signature did not verify', array( 'reason' => $verdict ) );
			}
			$status = in_array( $verdict, array( Payshen_Signature::MISSING_SIGNATURE, Payshen_Signature::MALFORMED_SIGNATURE ), true ) ? 400 : 401;
			return self::answer( $status, $verdict );
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || ! is_string( $event['id'] ?? null ) || ! is_string( $event['type'] ?? null ) ) {
			return self::answer( 400, 'malformed_event' );
		}
		$event_id = $event['id'];
		$type     = $event['type'];
		$data     = is_array( $event['data'] ?? null ) ? $event['data'] : array();

		Payshen_Logger::debug(
			'Webhook received',
			array(
				'event_id' => $event_id,
				'type'     => $type,
				'mode'     => $mode,
			)
		);

		if ( 'webhook.test' === $type ) {
			return self::answer( 200, 'test_received' );
		}

		$seen_key = 'payshen_evt_' . md5( $mode . '|' . $event_id );
		if ( false !== get_transient( $seen_key ) ) {
			return self::answer( 200, 'duplicate' );
		}

		if ( str_starts_with( $type, 'payment.' ) ) {
			// The wire envelope carries it in `data`; Payshen's events API lists it beside `data`.
			$payment_id = (string) ( $data['payment_id'] ?? ( $event['payment_id'] ?? ( $data['id'] ?? '' ) ) );
			$result     = self::handle_payment_event( $payment_id, $mode, $type );
		} elseif ( str_starts_with( $type, 'dispute.' ) ) {
			$result = self::handle_dispute_event( $data, $mode, $type );
		} else {
			$result = 'ignored';
		}

		if ( in_array( $result, array( 'locked', 'api_error', 'no_client' ), true ) ) {
			// Payshen sends it again later; by then the order is free or the API answers.
			return self::answer( 503, $result );
		}
		// Only an event that did something is remembered. One that found no order
		// (it can arrive before checkout has stored the payment id) stays
		// unremembered, so a later delivery or replay of it is applied.
		if ( 'no_order' !== $result ) {
			set_transient( $seen_key, 1, self::SEEN_TTL );
		}
		return self::answer( 200, $result );
	}

	/**
	 * A payment.* event: read the payment and apply it.
	 *
	 * @param string $payment_id Payment id from the event.
	 * @param string $mode       The mode whose secret verified it.
	 * @param string $type       Event type.
	 * @return string What happened.
	 */
	private static function handle_payment_event( $payment_id, $mode, $type ) {
		$order = Payshen_Order_Sync::find_order( $payment_id );
		if ( ! $order ) {
			// Not one of this store's payments (another shop on the same account), or not saved yet: the scheduled checks cover that.
			Payshen_Logger::debug(
				'Webhook for a payment no order holds',
				array(
					'payment_id' => $payment_id,
					'type'       => $type,
				)
			);
			return 'no_order';
		}
		if ( Payshen_Order_Sync::mode_of( $order, $payment_id ) !== $mode ) {
			Payshen_Logger::warning(
				'Webhook signed for the other mode than the order\'s payment; ignored',
				array(
					'order_id'   => $order->get_id(),
					'payment_id' => $payment_id,
				)
			);
			return 'mode_mismatch';
		}
		try {
			$result = Payshen_Order_Sync::sync( $order->get_id(), $payment_id, 'webhook', 3 );
		} catch ( Payshen_API_Exception $e ) {
			Payshen_Logger::error( 'Webhook could not read the payment', Payshen_Gateway::error_context( $e, $order, $payment_id ) );
			return 404 === $e->status ? 'not_found' : 'api_error';
		}
		return $result;
	}

	/**
	 * A dispute.* event: a note on the order, so nobody misses the deadline.
	 *
	 * @param array  $data Event data.
	 * @param string $mode Mode.
	 * @param string $type Event type.
	 * @return string What happened.
	 */
	private static function handle_dispute_event( array $data, $mode, $type ) {
		$payment_id = (string) ( $data['payment_id'] ?? '' );
		$order      = Payshen_Order_Sync::find_order( $payment_id );
		if ( ! $order || Payshen_Order_Sync::mode_of( $order, $payment_id ) !== $mode ) {
			return 'no_order';
		}
		$status     = sanitize_text_field( (string) ( $data['status'] ?? '' ) );
		$respond_by = sanitize_text_field( (string) ( $data['respond_by'] ?? '' ) );
		switch ( $type ) {
			case 'dispute.opened':
				$note = '' !== $respond_by
					/* translators: 1: payment id, 2: deadline. */
					? sprintf( __( 'A dispute was opened on Payshen payment %1$s. Respond in Payshen by %2$s.', 'payshen-for-woocommerce' ), $payment_id, $respond_by )
					/* translators: %s: payment id. */
					: sprintf( __( 'A dispute was opened on Payshen payment %s. Respond in Payshen.', 'payshen-for-woocommerce' ), $payment_id );
				break;
			case 'dispute.closed':
				/* translators: 1: payment id, 2: outcome such as WON or LOST. */
				$note = sprintf( __( 'The dispute on Payshen payment %1$s was closed: %2$s.', 'payshen-for-woocommerce' ), $payment_id, $status );
				break;
			default:
				/* translators: 1: payment id, 2: dispute status. */
				$note = sprintf( __( 'The dispute on Payshen payment %1$s changed: %2$s.', 'payshen-for-woocommerce' ), $payment_id, $status );
		}
		$order->add_order_note( $note );
		return 'noted';
	}

	/**
	 * A short JSON answer. Nothing about the store is disclosed.
	 *
	 * @param int    $status HTTP status.
	 * @param string $result Short word.
	 * @return WP_REST_Response
	 */
	private static function answer( $status, $result ) {
		$response = new WP_REST_Response( array( 'result' => $result ), $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
