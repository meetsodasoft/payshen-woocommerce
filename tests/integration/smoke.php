<?php
/**
 * End-to-end smoke test inside a real WordPress + WooCommerce, with Payshen's
 * API replaced by an in-memory mock (through the payshen_api_client_options
 * filter). Run with:
 *
 *   wp eval-file tests/integration/smoke.php
 *
 * It creates products and orders on the site it runs on. Use a test site.
 *
 * @package Payshen_For_WooCommerce
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

final class Payshen_Smoke_Mock {
	public static array $payments = array();
	public static array $keys     = array();
	public static array $refunds  = array();
	public static array $calls    = array();
	public static string $refund_mode = 'ok'; // ok|pending|refuse|unknown
	public static string $capture_mode = 'ok'; // ok|pending|refuse

	public static function transport( $method, $url, $headers, $body, $timeout ) {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		self::$calls[] = $method . ' ' . $path;
		$in = null === $body ? array() : json_decode( $body, true );
		$key = $headers['Idempotency-Key'] ?? null;

		if ( 'POST' === $method && '/api/v1/payments' === $path ) {
			$hash = md5( $body );
			if ( isset( self::$keys[ $key ] ) ) {
				if ( self::$keys[ $key ]['hash'] !== $hash ) {
					return self::answer( 422, array( 'error' => array( 'code' => 'idempotency_key_reuse', 'message' => 'reuse' ) ) );
				}
				return self::$keys[ $key ]['answer'];
			}
			$id = wp_generate_uuid4();
			self::$payments[ $id ] = array(
				'id' => $id, 'reference' => $in['reference'] ?? null, 'status' => 'REQUIRES_ACTION', 'environment' => 'sandbox',
				'amount' => $in['amount'], 'currency' => $in['currency'], 'captured_amount' => 0, 'refunded_amount' => 0,
				'refund_pending_amount' => 0, 'capture_method' => $in['capture_method'], 'simulated' => false, 'body' => $in,
			);
			$answer = self::answer( 201, array( 'data' => array( 'id' => $id, 'payment_id' => $id, 'reference' => $in['reference'] ?? null, 'status' => 'REQUIRES_ACTION', 'environment' => 'sandbox', 'next_action' => array( 'type' => 'REDIRECT', 'url' => 'https://pay.example/s/' . $id, 'expires_at' => gmdate( 'c', time() + 3600 ) ) ) ) );
			self::$keys[ $key ] = array( 'hash' => $hash, 'answer' => $answer );
			return $answer;
		}
		if ( preg_match( '#^/api/v1/payments/([^/]+)(?:/(capture|void|refunds))?$#', $path, $m ) ) {
			$id = rawurldecode( $m[1] );
			if ( ! isset( self::$payments[ $id ] ) ) {
				return self::answer( 404, array( 'error' => array( 'code' => 'not_found', 'message' => 'No transaction with that id.' ) ) );
			}
			$p = &self::$payments[ $id ];
			$op = $m[2] ?? '';
			if ( 'GET' === $method && '' === $op ) {
				$out = $p;
				unset( $out['body'] );
				return self::answer( 200, array( 'data' => $out ) );
			}
			if ( 'capture' === $op ) {
				if ( 'refuse' === self::$capture_mode ) {
					return self::answer( 502, array( 'error' => array( 'code' => 'psp_error', 'message' => 'Card issuer refused.' ) ) );
				}
				if ( 'AUTHORIZED' !== $p['status'] ) {
					return self::answer( 409, array( 'error' => array( 'code' => 'not_capturable', 'message' => 'not authorized' ) ) );
				}
				if ( 'pending' === self::$capture_mode ) {
					$p['status'] = 'PENDING';
				} else {
					$p['status'] = 'CAPTURED';
					$p['captured_amount'] = $in['amount'] ?? $p['amount'];
				}
				return self::answer( 200, array( 'data' => self::op( $p ) ) );
			}
			if ( 'void' === $op ) {
				if ( 'AUTHORIZED' !== $p['status'] ) {
					return self::answer( 409, array( 'error' => array( 'code' => 'not_voidable', 'message' => 'not authorized' ) ) );
				}
				$p['status'] = 'CANCELLED';
				return self::answer( 200, array( 'data' => self::op( $p ) ) );
			}
			if ( 'refunds' === $op ) {
				$amount = $in['amount'] ?? ( $p['captured_amount'] - $p['refunded_amount'] );
				if ( isset( self::$refunds[ $key ] ) ) {
					$r = self::$refunds[ $key ];
					if ( 'pending' === $r['state'] ) {
						return self::answer( 409, array( 'error' => array( 'code' => 'refund_pending', 'message' => 'pending' ) ) );
					}
					if ( 'failed' === $r['state'] ) {
						return self::answer( 502, array( 'error' => array( 'code' => 'psp_error', 'message' => 'Refund declined by provider.' ) ) );
					}
					return self::answer( 200, array( 'data' => self::op( $p ) ) );
				}
				if ( 'refuse' === self::$refund_mode ) {
					return self::answer( 502, array( 'error' => array( 'code' => 'psp_error', 'message' => 'Refund declined by provider.' ) ) );
				}
				if ( 'pending' === self::$refund_mode ) {
					self::$refunds[ $key ] = array( 'state' => 'pending', 'amount' => $amount, 'payment' => $id );
					$p['refund_pending_amount'] += $amount;
					return self::answer( 409, array( 'error' => array( 'code' => 'refund_pending', 'message' => 'pending' ) ) );
				}
				self::$refunds[ $key ] = array( 'state' => 'succeeded', 'amount' => $amount, 'payment' => $id );
				$p['refunded_amount'] += $amount;
				$p['status'] = $p['refunded_amount'] >= $p['captured_amount'] ? 'REFUNDED' : 'PARTIALLY_REFUNDED';
				return self::answer( 200, array( 'data' => self::op( $p ) ) );
			}
		}
		return self::answer( 404, array( 'error' => array( 'code' => 'not_found', 'message' => 'no route' ) ) );
	}

	public static function settle_refund( string $key, bool $ok ): void {
		$r = &self::$refunds[ $key ];
		$p = &self::$payments[ $r['payment'] ];
		$p['refund_pending_amount'] -= $r['amount'];
		if ( $ok ) {
			$r['state'] = 'succeeded';
			$p['refunded_amount'] += $r['amount'];
			$p['status'] = $p['refunded_amount'] >= $p['captured_amount'] ? 'REFUNDED' : 'PARTIALLY_REFUNDED';
		} else {
			$r['state'] = 'failed';
		}
	}

	private static function op( array $p ): array {
		return array_intersect_key( $p, array_flip( array( 'id', 'reference', 'status', 'amount', 'currency', 'captured_amount', 'refunded_amount', 'refund_pending_amount', 'environment' ) ) ) + array( 'payment_id' => $p['id'] );
	}

	private static function answer( int $status, array $body ): array {
		return array( 'status' => $status, 'headers' => array( 'x-request-id' => 'req_mock' ), 'body' => wp_json_encode( $body ) );
	}
}

final class Payshen_Smoke_Count {
	public static int $checks   = 0;
	public static int $failures = 0;
	public static array $orders = array();
}
function payshen_smoke_check( $ok, string $what ) {
	++Payshen_Smoke_Count::$checks;
	if ( $ok ) {
		WP_CLI::log( "  ok   {$what}" );
	} else {
		++Payshen_Smoke_Count::$failures;
		WP_CLI::log( "  FAIL {$what}" );
	}
}

add_filter(
	'payshen_api_client_options',
	static function ( $o ) {
		$o['transport'] = array( 'Payshen_Smoke_Mock', 'transport' );
		$o['sleep']     = static function () {};
		return $o;
	}
);

$previous_settings = get_option( 'woocommerce_payshen_settings', null );
$secret            = 'whsec_smoke_' . wp_generate_password( 24, false );
update_option(
	'woocommerce_payshen_settings',
	array(
		'enabled'             => 'yes',
		'title'               => 'Pay online',
		'description'         => 'Secure page.',
		'testmode'            => 'yes',
		'test_api_key'        => 'ps_test_smoke_' . wp_generate_password( 20, false ),
		'test_webhook_secret' => $secret,
		'live_api_key'        => '',
		'live_webhook_secret' => '',
		'capture_method'      => 'automatic',
		'allowed_methods'     => array( 'card', 'wallet' ),
		'debug'               => 'yes',
	)
);
WC()->payment_gateways()->init();
$gateway = WC()->payment_gateways()->payment_gateways()['payshen'];

if ( ! WC()->session ) {
	WC()->session = new WC_Session_Handler();
	WC()->session->init();
}

$product = new WC_Product_Simple();
$product->set_name( 'Payshen smoke product' );
$product->set_regular_price( '24.50' );
$product->set_status( 'publish' );
$product->save();

function payshen_smoke_order( WC_Product $product, string $total = '' ): WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, 2 );
	$order->set_address(
		array(
			'first_name' => 'Ana', 'last_name' => 'Horvat', 'email' => 'ana@example.com', 'phone' => '+385 1 234 5678',
			'address_1' => 'Ilica 1', 'city' => 'Zagreb', 'postcode' => '10000', 'country' => 'HR',
		),
		'billing'
	);
	$order->set_payment_method( 'payshen' );
	$order->set_customer_ip_address( '203.0.113.9' );
	$order->set_currency( 'EUR' );
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->save();
	Payshen_Smoke_Count::$orders[] = $order->get_id();
	return wc_get_order( $order->get_id() );
}

function payshen_smoke_webhook( string $secret, string $type, string $payment_id, int $t = 0, ?string $sig = null, string $event_id = '' ) {
	$body = wp_json_encode(
		array(
			'id'      => '' !== $event_id ? $event_id : 'evt_' . wp_generate_uuid4(),
			'type'    => $type,
			'created' => gmdate( 'c' ),
			'data'    => array( 'id' => $payment_id, 'payment_id' => $payment_id, 'status' => 'X', 'environment' => 'sandbox' ),
		)
	);
	$t   = $t ?: time();
	$req = new WP_REST_Request( 'POST', '/payshen/v1/webhook' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_header( 'X-Payshen-Signature', $sig ?? Payshen_Signature::sign( $body, $secret, $t ) );
	$req->set_body( $body );
	$res = rest_do_request( $req );
	return array( $res->get_status(), $res->get_data()['result'] ?? null, $body );
}

function payshen_smoke_notes( WC_Order $order ): string {
	return implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
}

WP_CLI::log( 'Storage: ' . ( wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts' ) );

$fixed_event = 'evt_' . wp_generate_uuid4();
// 1. Automatic capture through a webhook.
WP_CLI::log( '1. Checkout, double click, webhook' );
$order  = payshen_smoke_order( $product );
$result = $gateway->process_payment( $order->get_id() );
payshen_smoke_check( 'success' === $result['result'] && str_starts_with( $result['redirect'] ?? '', 'https://pay.example/s/' ), 'process_payment redirects to Payshen' );
$order = wc_get_order( $order->get_id() );
$pid   = $order->get_meta( '_payshen_payment_id' );
$sent  = Payshen_Smoke_Mock::$payments[ $pid ]['body'];
payshen_smoke_check( 4900 === $sent['amount'] && 'EUR' === $sent['currency'] && 'HR' === $sent['country'], 'amount 4900 EUR, country HR' );
payshen_smoke_check( array( 'card', 'wallet' ) === $sent['allowed_methods'] && 'automatic' === $sent['capture_method'], 'allowed methods and capture method' );
payshen_smoke_check( '+38512345678' === $sent['customer']['phone'] && 'ana@example.com' === $sent['customer']['email'] && '203.0.113.9' === $sent['customer']['ip'], 'customer details normalised' );
payshen_smoke_check( str_contains( $sent['return_url'], 'payshen_return=' ) && str_contains( $sent['return_url'], 'key=wc_order_' ), 'return_url is the signed order received URL' );
payshen_smoke_check( str_contains( $sent['cancel_url'], 'payshen_cancel=' . $order->get_id() ), 'cancel_url' );
payshen_smoke_check( (string) $order->get_order_number() === $sent['reference'], 'reference is the order number' );
$again = $gateway->process_payment( $order->get_id() );
payshen_smoke_check( $again['redirect'] === $result['redirect'] && 1 === count( Payshen_Smoke_Mock::$payments ), 'double click: same payment, one payment created' );
payshen_smoke_check( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'payshen_check_payment', array( $order->get_id(), 0 ), 'payshen' ), 'fallback check scheduled' );

Payshen_Smoke_Mock::$payments[ $pid ]['status']          = 'CAPTURED';
Payshen_Smoke_Mock::$payments[ $pid ]['captured_amount'] = 4900;
list( $code, $what ) = payshen_smoke_webhook( $secret, 'payment.captured', $pid, 0, null, $fixed_event );
$order = wc_get_order( $order->get_id() );
payshen_smoke_check( 200 === $code && 'paid' === $what, "webhook accepted ({$code} {$what})" );
payshen_smoke_check( $order->has_status( 'processing' ) && $pid === $order->get_transaction_id() && $order->get_date_paid(), 'order processing, transaction id, date paid' );
list( $code, $what ) = payshen_smoke_webhook( $secret, 'payment.captured', $pid, 0, null, $fixed_event );
payshen_smoke_check( 200 === $code && 'duplicate' === $what, 'same event id again is a duplicate' );
list( $code ) = payshen_smoke_webhook( $secret, 'payment.captured', $pid, 0, 't=' . time() . ',v1=' . str_repeat( 'a', 64 ) );
payshen_smoke_check( 401 === $code, 'bad signature is 401' );
list( $code ) = payshen_smoke_webhook( $secret, 'payment.captured', $pid, time() - 1000 );
payshen_smoke_check( 401 === $code, 'stale timestamp is 401' );
list( $code ) = payshen_smoke_webhook( $secret, 'payment.captured', $pid, 0, '' );
payshen_smoke_check( 400 === $code, 'missing signature is 400' );
list( $code, $what ) = payshen_smoke_webhook( $secret, 'webhook.test', 'none' );
payshen_smoke_check( 200 === $code && 'test_received' === $what, 'test event answered 200' );
$notes_before = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
payshen_smoke_webhook( $secret, 'payment.captured', $pid );
payshen_smoke_check( count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) === $notes_before, 'a new event for the same state adds no note' );

// 2. Refunds.
WP_CLI::log( '2. Refunds' );
$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '5.00', 'reason' => 'test', 'refund_payment' => true ) );
payshen_smoke_check( $refund instanceof WC_Order_Refund && 'succeeded' === $refund->get_meta( '_payshen_refund_state' ), 'partial refund succeeds' );
payshen_smoke_check( 500 === Payshen_Smoke_Mock::$payments[ $pid ]['refunded_amount'], 'Payshen refunded 500' );
$key = $refund->get_meta( '_payshen_refund_key' );
payshen_smoke_check( str_starts_with( $key, 'wc-refund-' . $order->get_id() . '-' . $refund->get_id() . '-' ), 'refund key is per WooCommerce refund' );

Payshen_Smoke_Mock::$refund_mode = 'refuse';
$refused = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '1.00', 'refund_payment' => true ) );
payshen_smoke_check( is_wp_error( $refused ) && str_contains( $refused->get_error_message(), 'Refund declined by provider' ), 'refused refund returns the message' );
payshen_smoke_check( 1 === count( wc_get_order( $order->get_id() )->get_refunds() ), 'refused refund is not kept' );

Payshen_Smoke_Mock::$refund_mode = 'pending';
$pending = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '2.00', 'refund_payment' => true ) );
payshen_smoke_check( $pending instanceof WC_Order_Refund && 'pending' === wc_get_order( $pending->get_id() )->get_meta( '_payshen_refund_state' ), 'pending refund is kept as pending' );
payshen_smoke_check( as_has_scheduled_action( 'payshen_check_refund', null, 'payshen' ), 'pending refund check scheduled' );
Payshen_Smoke_Mock::settle_refund( wc_get_order( $pending->get_id() )->get_meta( '_payshen_refund_key' ), true );
payshen_smoke_webhook( $secret, 'payment.refunded', $pid );
payshen_smoke_check( 'succeeded' === wc_get_order( $pending->get_id() )->get_meta( '_payshen_refund_state' ), 'webhook settles the pending refund' );

$pending2 = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => '3.00', 'refund_payment' => true ) );
Payshen_Smoke_Mock::settle_refund( wc_get_order( $pending2->get_id() )->get_meta( '_payshen_refund_key' ), false );
Payshen_Scheduler::run_refund_check( $order->get_id(), $pending2->get_id(), 0, time() );
payshen_smoke_check( ! wc_get_order( $pending2->get_id() ), 'a refund that failed later is removed' );
payshen_smoke_check( str_contains( payshen_smoke_notes( $order ), 'did not go through' ), 'and the order says so' );

Payshen_Smoke_Mock::$refund_mode = 'ok';
Payshen_Smoke_Mock::$payments[ $pid ]['refunded_amount'] += 1000; // Refunded in the Payshen dashboard.
payshen_smoke_webhook( $secret, 'payment.refunded', $pid );
$order = wc_get_order( $order->get_id() );
payshen_smoke_check( abs( (float) $order->get_total_refunded() - 17.00 ) < 0.001, 'refund made in Payshen is recorded (total refunded ' . $order->get_total_refunded() . ')' );
payshen_smoke_webhook( $secret, 'payment.refunded', $pid );
payshen_smoke_check( abs( (float) wc_get_order( $order->get_id() )->get_total_refunded() - 17.00 ) < 0.001, 'and only once' );

// 3. Declined, then a new attempt, then a late capture of the first.
WP_CLI::log( '3. Decline and retry' );
$order2 = payshen_smoke_order( $product );
$gateway->process_payment( $order2->get_id() );
$p1 = wc_get_order( $order2->get_id() )->get_meta( '_payshen_payment_id' );
Payshen_Smoke_Mock::$payments[ $p1 ]['status']           = 'DECLINED';
Payshen_Smoke_Mock::$payments[ $p1 ]['decline_category'] = 'INSUFFICIENT_FUNDS';
Payshen_Order_Sync::sync( $order2->get_id(), '', 'return' );
$order2 = wc_get_order( $order2->get_id() );
payshen_smoke_check( $order2->has_status( 'failed' ) && str_contains( payshen_smoke_notes( $order2 ), 'insufficient funds' ), 'declined: order failed with the reason' );
$retry = $gateway->process_payment( $order2->get_id() );
$p2    = wc_get_order( $order2->get_id() )->get_meta( '_payshen_payment_id' );
payshen_smoke_check( 'success' === $retry['result'] && $p2 !== $p1 && 2 === (int) wc_get_order( $order2->get_id() )->get_meta( '_payshen_attempt' ), 'a new attempt makes a new payment' );
Payshen_Smoke_Mock::$payments[ $p2 ]['status']          = 'CAPTURED';
Payshen_Smoke_Mock::$payments[ $p2 ]['captured_amount'] = 4900;
Payshen_Scheduler::run_payment_check( $order2->get_id(), 0 );
payshen_smoke_check( wc_get_order( $order2->get_id() )->has_status( 'processing' ), 'scheduled check completes the order' );
Payshen_Smoke_Mock::$payments[ $p1 ]['status']          = 'CAPTURED';
Payshen_Smoke_Mock::$payments[ $p1 ]['captured_amount'] = 4900;
payshen_smoke_webhook( $secret, 'payment.captured', $p1 );
$order2 = wc_get_order( $order2->get_id() );
payshen_smoke_check( $p2 === $order2->get_meta( '_payshen_paid_payment' ) && str_contains( payshen_smoke_notes( $order2 ), 'was also collected' ), 'late capture of the first attempt: warning, order unchanged' );

// 4. Unknown and mismatches never pay.
WP_CLI::log( '4. In flight, mismatch' );
$order3 = payshen_smoke_order( $product );
$gateway->process_payment( $order3->get_id() );
$p3 = wc_get_order( $order3->get_id() )->get_meta( '_payshen_payment_id' );
Payshen_Smoke_Mock::$payments[ $p3 ]['status'] = 'UNKNOWN';
Payshen_Order_Sync::sync( $order3->get_id() );
$order3 = wc_get_order( $order3->get_id() );
payshen_smoke_check( $order3->has_status( 'on-hold' ) && ! Payshen_Order_Sync::is_paid( $order3 ), 'UNKNOWN: on hold, not paid' );
$blocked = $gateway->process_payment( $order3->get_id() );
payshen_smoke_check( 'failure' === $blocked['result'], 'no second payment while the first is in flight' );
wc_clear_notices();
Payshen_Smoke_Mock::$payments[ $p3 ]['status']          = 'CAPTURED';
Payshen_Smoke_Mock::$payments[ $p3 ]['amount']          = 100;
Payshen_Smoke_Mock::$payments[ $p3 ]['captured_amount'] = 100;
Payshen_Order_Sync::sync( $order3->get_id() );
$order3 = wc_get_order( $order3->get_id() );
payshen_smoke_check( $order3->has_status( 'on-hold' ) && ! Payshen_Order_Sync::is_paid( $order3 ) && str_contains( payshen_smoke_notes( $order3 ), 'put on hold' ), 'wrong amount: held, not paid' );

$order9 = payshen_smoke_order( $product );
$gateway->process_payment( $order9->get_id() );
$p9 = wc_get_order( $order9->get_id() )->get_meta( '_payshen_payment_id' );
wc_get_order( $order9->get_id() )->update_status( 'processing' ); // Marked paid by hand, too early.
Payshen_Smoke_Mock::$payments[ $p9 ]['status'] = 'DECLINED';
Payshen_Order_Sync::sync( $order9->get_id() );
$order9 = wc_get_order( $order9->get_id() );
payshen_smoke_check( $order9->has_status( 'processing' ) && str_contains( payshen_smoke_notes( $order9 ), 'Do not ship' ), 'declined payment on an order marked paid: warning note' );

$order10 = payshen_smoke_order( $product );
$gateway->process_payment( $order10->get_id() );
$p10 = wc_get_order( $order10->get_id() )->get_meta( '_payshen_payment_id' );
$o10 = wc_get_order( $order10->get_id() );
$o10->set_total( '30.00' ); // The order changed after the page was opened.
$o10->save();
Payshen_Smoke_Mock::$payments[ $p10 ]['status']          = 'CAPTURED';
Payshen_Smoke_Mock::$payments[ $p10 ]['captured_amount'] = 4900;
Payshen_Order_Sync::sync( $order10->get_id() );
payshen_smoke_check( wc_get_order( $order10->get_id() )->has_status( 'on-hold' ), 'collected amount differs from the order total: held' );

// 5. Customer cancelled.
$order4 = payshen_smoke_order( $product );
$gateway->process_payment( $order4->get_id() );
$p4 = wc_get_order( $order4->get_id() )->get_meta( '_payshen_payment_id' );
Payshen_Smoke_Mock::$payments[ $p4 ]['status'] = 'CANCELLED';
payshen_smoke_webhook( $secret, 'payment.cancelled', $p4 );
payshen_smoke_check( wc_get_order( $order4->get_id() )->has_status( 'cancelled' ), 'cancelled on the hosted page: order cancelled' );

// 6. Manual capture.
WP_CLI::log( '6. Manual capture' );
$settings                   = get_option( 'woocommerce_payshen_settings' );
$settings['capture_method'] = 'manual';
update_option( 'woocommerce_payshen_settings', $settings );
WC()->payment_gateways()->init();
$gateway = WC()->payment_gateways()->payment_gateways()['payshen'];

$order5 = payshen_smoke_order( $product );
$gateway->process_payment( $order5->get_id() );
$p5 = wc_get_order( $order5->get_id() )->get_meta( '_payshen_payment_id' );
payshen_smoke_check( 'manual' === Payshen_Smoke_Mock::$payments[ $p5 ]['body']['capture_method'], 'manual capture sent' );
Payshen_Smoke_Mock::$payments[ $p5 ]['status'] = 'AUTHORIZED';
payshen_smoke_webhook( $secret, 'payment.authorized', $p5 );
$order5 = wc_get_order( $order5->get_id() );
payshen_smoke_check( $order5->has_status( 'on-hold' ) && $p5 === $order5->get_meta( '_payshen_authorized' ) && ! Payshen_Order_Sync::is_paid( $order5 ), 'authorized: on hold, not paid' );
$refund_on_auth = wc_create_refund( array( 'order_id' => $order5->get_id(), 'amount' => '1.00', 'refund_payment' => true ) );
payshen_smoke_check( is_wp_error( $refund_on_auth ), 'refund of an uncaptured authorization is refused' );
$order5->update_status( 'completed' );
$order5 = wc_get_order( $order5->get_id() );
payshen_smoke_check( 'CAPTURED' === Payshen_Smoke_Mock::$payments[ $p5 ]['status'] && $p5 === $order5->get_meta( '_payshen_paid_payment' ) && $order5->has_status( 'completed' ), 'set to Completed: captured, stays completed' );
payshen_smoke_check( in_array( 'POST /api/v1/payments/' . $p5 . '/capture', Payshen_Smoke_Mock::$calls, true ), 'capture endpoint called' );

$order6 = payshen_smoke_order( $product );
$gateway->process_payment( $order6->get_id() );
$p6 = wc_get_order( $order6->get_id() )->get_meta( '_payshen_payment_id' );
Payshen_Smoke_Mock::$payments[ $p6 ]['status'] = 'AUTHORIZED';
Payshen_Order_Sync::sync( $order6->get_id() );
$order6 = wc_get_order( $order6->get_id() );
$order6->set_total( '40.00' );
$order6->save();
do_action( 'woocommerce_order_action_payshen_capture', wc_get_order( $order6->get_id() ) );
$order6 = wc_get_order( $order6->get_id() );
payshen_smoke_check( 4000 === Payshen_Smoke_Mock::$payments[ $p6 ]['captured_amount'] && $order6->has_status( 'processing' ), 'order action captures the lowered total (40.00) and completes payment' );

$order7 = payshen_smoke_order( $product );
$gateway->process_payment( $order7->get_id() );
$p7 = wc_get_order( $order7->get_id() )->get_meta( '_payshen_payment_id' );
Payshen_Smoke_Mock::$payments[ $p7 ]['status'] = 'AUTHORIZED';
Payshen_Order_Sync::sync( $order7->get_id() );
wc_get_order( $order7->get_id() )->update_status( 'cancelled' );
$order7 = wc_get_order( $order7->get_id() );
payshen_smoke_check( 'CANCELLED' === Payshen_Smoke_Mock::$payments[ $p7 ]['status'] && '' === $order7->get_meta( '_payshen_authorized' ), 'cancelling releases the authorization' );

$order8 = payshen_smoke_order( $product );
$gateway->process_payment( $order8->get_id() );
$p8 = wc_get_order( $order8->get_id() )->get_meta( '_payshen_payment_id' );
Payshen_Smoke_Mock::$payments[ $p8 ]['status'] = 'AUTHORIZED';
Payshen_Order_Sync::sync( $order8->get_id() );
Payshen_Smoke_Mock::$capture_mode = 'refuse';
wc_get_order( $order8->get_id() )->update_status( 'processing' );
$order8 = wc_get_order( $order8->get_id() );
payshen_smoke_check( $order8->has_status( 'on-hold' ) && ! Payshen_Order_Sync::is_paid( $order8 ) && str_contains( payshen_smoke_notes( $order8 ), 'refused the capture' ), 'refused capture puts the order back on hold' );
Payshen_Smoke_Mock::$capture_mode = 'ok';

// 7. Zero- and three-decimal currencies.
WP_CLI::log( '7. Currencies' );
$settings['capture_method'] = 'automatic';
update_option( 'woocommerce_payshen_settings', $settings );
WC()->payment_gateways()->init();
$gateway = WC()->payment_gateways()->payment_gateways()['payshen'];
foreach ( array( array( 'JPY', '5000', 5000 ), array( 'KWD', '12.35', 12350 ) ) as $case ) {
	$o = payshen_smoke_order( $product );
	$o->set_currency( $case[0] );
	$o->set_total( $case[1] );
	$o->save();
	$gateway->process_payment( $o->get_id() );
	$pp = wc_get_order( $o->get_id() )->get_meta( '_payshen_payment_id' );
	payshen_smoke_check( $case[2] === Payshen_Smoke_Mock::$payments[ $pp ]['amount'] && $case[0] === Payshen_Smoke_Mock::$payments[ $pp ]['currency'], "{$case[0]} {$case[1]} is {$case[2]} minor units" );
}

$small = payshen_smoke_order( $product );
$small->set_total( '0.40' );
$small->save();
$r = $gateway->process_payment( $small->get_id() );
payshen_smoke_check( 'failure' === $r['result'], 'a total under the minimum is refused before calling Payshen' );
wc_clear_notices();

// Clean up: the orders and product made here, their scheduled checks, and the settings as they were.
foreach ( Payshen_Smoke_Count::$orders as $id ) {
	Payshen_Scheduler::unschedule_payment_checks( $id );
	$o = wc_get_order( $id );
	if ( $o ) {
		foreach ( $o->get_refunds() as $r ) {
			$r->delete( true );
		}
		$o->delete( true );
	}
}
as_unschedule_all_actions( 'payshen_check_refund', array(), 'payshen' );
$product->delete( true );
if ( null === $previous_settings ) {
	delete_option( 'woocommerce_payshen_settings' );
} else {
	update_option( 'woocommerce_payshen_settings', $previous_settings );
}

WP_CLI::log( '' );
WP_CLI::log( Payshen_Smoke_Count::$checks . ' checks, ' . Payshen_Smoke_Count::$failures . ' failed. API calls: ' . count( Payshen_Smoke_Mock::$calls ) );
if ( Payshen_Smoke_Count::$failures > 0 ) {
	WP_CLI::halt( 1 );
}
