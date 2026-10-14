<?php
/**
 * The Payshen payment gateway.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the customer to Payshen's hosted page and keeps the order in step with the payment.
 */
class Payshen_Gateway extends WC_Payment_Gateway {

	const ID = 'payshen';

	/** Payment methods Payshen's hosted page can offer. */
	const METHODS = array( 'card', 'bank', 'wallet', 'crypto' );

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Payshen', 'payshen-for-woocommerce' );
		$this->method_description = __( 'Customers pay on Payshen\'s secure page, through the payment providers you connected in Payshen. Payshen is a technology provider: your providers collect and pay out the money.', 'payshen-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		$this->view_transaction_url = self::base_url() . '/app/transactions/%s';

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * The API base URL: production, or another one set in wp-config.php
	 * (define( 'PAYSHEN_API_BASE_URL', 'https://staging.payshen.com' );) or by the
	 * `payshen_api_base_url` filter.
	 *
	 * @return string
	 */
	public static function base_url() {
		$url = defined( 'PAYSHEN_API_BASE_URL' ) ? (string) PAYSHEN_API_BASE_URL : Payshen_API_Client::DEFAULT_BASE_URL;
		$url = (string) apply_filters( 'payshen_api_base_url', $url );
		$url = rtrim( $url, '/' );
		return Payshen_API_Client::is_allowed_base_url( $url ) ? $url : Payshen_API_Client::DEFAULT_BASE_URL;
	}

	/**
	 * The settings form.
	 */
	public function init_form_fields() {
		$webhook_url = self::webhook_url();

		$this->form_fields = array(
			'enabled'             => array(
				'title'   => __( 'Enable', 'payshen-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Take payments with Payshen', 'payshen-for-woocommerce' ),
				'default' => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'payshen-for-woocommerce' ),
				'type'        => 'safe_text',
				'description' => __( 'What customers see at checkout.', 'payshen-for-woocommerce' ),
				'default'     => __( 'Pay online', 'payshen-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'payshen-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title at checkout.', 'payshen-for-woocommerce' ),
				'default'     => __( 'You will pay on a secure payment page and then come back here.', 'payshen-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'allowed_methods'     => array(
				'title'       => __( 'Payment methods', 'payshen-for-woocommerce' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'What the customer may choose from on Payshen\'s page. Only methods one of your Payshen connections can take are shown.', 'payshen-for-woocommerce' ),
				'default'     => array( 'card' ),
				'options'     => array(
					'card'   => __( 'Card', 'payshen-for-woocommerce' ),
					'bank'   => __( 'Bank', 'payshen-for-woocommerce' ),
					'wallet' => __( 'Wallet', 'payshen-for-woocommerce' ),
					'crypto' => __( 'Crypto', 'payshen-for-woocommerce' ),
				),
				'desc_tip'    => true,
			),
			'capture_method'      => array(
				'title'       => __( 'Capture', 'payshen-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Manual: the payment is only authorized at checkout. It is captured when you set the order to Processing or Completed, or with the "Capture payment (Payshen)" order action. Cancelling the order releases it.', 'payshen-for-woocommerce' ),
				'default'     => 'automatic',
				'options'     => array(
					'automatic' => __( 'Automatic: capture at checkout', 'payshen-for-woocommerce' ),
					'manual'    => __( 'Manual: authorize now, capture later', 'payshen-for-woocommerce' ),
				),
			),
			'api_title'           => array(
				'title'       => __( 'API keys', 'payshen-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Find your keys in Payshen under Developers. Test keys start with ps_test_ and use the sandbox: no money moves. Live keys start with ps_live_.', 'payshen-for-woocommerce' ),
			),
			'testmode'            => array(
				'title'       => __( 'Test mode', 'payshen-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the test key and the sandbox', 'payshen-for-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'Turn this off to take real payments with your live key.', 'payshen-for-woocommerce' ),
			),
			'test_api_key'        => array(
				'title'       => __( 'Test API key', 'payshen-for-woocommerce' ),
				'type'        => 'password',
				'placeholder' => 'ps_test_...',
				'default'     => '',
			),
			'test_webhook_secret' => array(
				'title'       => __( 'Test webhook signing secret', 'payshen-for-woocommerce' ),
				'type'        => 'password',
				'placeholder' => 'whsec_...',
				'default'     => '',
			),
			'live_api_key'        => array(
				'title'       => __( 'Live API key', 'payshen-for-woocommerce' ),
				'type'        => 'password',
				'placeholder' => 'ps_live_...',
				'default'     => '',
			),
			'live_webhook_secret' => array(
				'title'       => __( 'Live webhook signing secret', 'payshen-for-woocommerce' ),
				'type'        => 'password',
				'placeholder' => 'whsec_...',
				'default'     => '',
			),
			'webhook_title'       => array(
				'title'       => __( 'Webhooks', 'payshen-for-woocommerce' ),
				'type'        => 'title',
				/* translators: %s: the webhook URL. */
				'description' => sprintf( __( 'Webhook URL: %s', 'payshen-for-woocommerce' ), '<code style="user-select:all">' . esc_html( $webhook_url ) . '</code>' )
					. '<br>' . esc_html__( 'In Payshen, open Developers, add a webhook endpoint with this URL for Sandbox, and copy its signing secret into "Test webhook signing secret". Do the same for Production and "Live webhook signing secret". Subscribe to all payment events.', 'payshen-for-woocommerce' )
					. '<br>' . esc_html__( 'Orders are also checked with Payshen on a schedule, so a missed webhook never leaves an order wrong.', 'payshen-for-woocommerce' ),
			),
			'advanced_title'      => array(
				'title' => __( 'Advanced', 'payshen-for-woocommerce' ),
				'type'  => 'title',
			),
			'debug'               => array(
				'title'       => __( 'Debug log', 'payshen-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log Payshen requests and webhooks', 'payshen-for-woocommerce' ),
				'default'     => 'no',
				/* translators: %s: link to WooCommerce logs. */
				'description' => sprintf( __( 'Logs go to %s (source "payshen"). Keys, secrets and customer details are never logged. Errors are always logged.', 'payshen-for-woocommerce' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">' . esc_html__( 'WooCommerce > Status > Logs', 'payshen-for-woocommerce' ) . '</a>' ),
			),
		);
	}

	/**
	 * The webhook URL for this site.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( 'payshen/v1/webhook' );
	}

	/**
	 * Settings page: a warning when the active mode is not fully set up.
	 */
	public function admin_options() {
		$mode     = $this->mode();
		$problems = array();
		if ( '' === $this->api_key( $mode ) ) {
			$problems[] = 'live' === $mode ? __( 'Add your live API key, or turn on test mode.', 'payshen-for-woocommerce' ) : __( 'Add your test API key.', 'payshen-for-woocommerce' );
		}
		if ( '' === $this->webhook_secret( $mode ) ) {
			$problems[] = 'live' === $mode ? __( 'Add the live webhook signing secret so payments are confirmed the moment they happen.', 'payshen-for-woocommerce' ) : __( 'Add the test webhook signing secret so payments are confirmed the moment they happen.', 'payshen-for-woocommerce' );
		}
		if ( Payshen_API_Client::DEFAULT_BASE_URL !== self::base_url() ) {
			/* translators: %s: API URL. */
			$problems[] = sprintf( __( 'Using the Payshen API at %s (set in wp-config.php or by a filter).', 'payshen-for-woocommerce' ), self::base_url() );
		}
		foreach ( $problems as $problem ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $problem ) . '</p></div>';
		}
		parent::admin_options();
	}

	/**
	 * Check a test API key on save.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_test_api_key_field( $key, $value ) {
		return $this->validate_credential( $key, $value, 'test', 'api_key' );
	}

	/**
	 * Check a live API key on save.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_live_api_key_field( $key, $value ) {
		return $this->validate_credential( $key, $value, 'live', 'api_key' );
	}

	/**
	 * Check the test webhook secret on save.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_test_webhook_secret_field( $key, $value ) {
		return $this->validate_credential( $key, $value, 'test', 'webhook_secret' );
	}

	/**
	 * Check the live webhook secret on save.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_live_webhook_secret_field( $key, $value ) {
		return $this->validate_credential( $key, $value, 'live', 'webhook_secret' );
	}

	/**
	 * Keep only known payment methods.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return array
	 */
	public function validate_allowed_methods_field( $key, $value ) {
		$value   = is_array( $value ) ? array_map( 'sanitize_key', wp_unslash( $value ) ) : array();
		$methods = array_values( array_intersect( self::METHODS, $value ) );
		if ( array() === $methods ) {
			WC_Admin_Settings::add_error( __( 'Choose at least one payment method. Card was kept.', 'payshen-for-woocommerce' ) );
			return array( 'card' );
		}
		return $methods;
	}

	/**
	 * A posted key or secret: trimmed, its prefix checked. A wrong one keeps the saved value.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @param string $mode  test|live.
	 * @param string $kind  api_key|webhook_secret.
	 * @return string
	 */
	private function validate_credential( $key, $value, $mode, $kind ) {
		$value = trim( stripslashes( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		$ok = 'api_key' === $kind ? Payshen_Keys::is_api_key( $value, $mode ) : Payshen_Keys::is_webhook_secret( $value );
		if ( $ok ) {
			return $value;
		}
		if ( 'api_key' === $kind ) {
			$message = 'live' === $mode
				? __( 'The live API key must start with ps_live_. The previous value was kept.', 'payshen-for-woocommerce' )
				: __( 'The test API key must start with ps_test_. The previous value was kept.', 'payshen-for-woocommerce' );
		} else {
			$message = __( 'A webhook signing secret starts with whsec_. The previous value was kept.', 'payshen-for-woocommerce' );
		}
		WC_Admin_Settings::add_error( $message );
		return (string) $this->get_option( $key );
	}

	/**
	 * Save settings, then check any new API key with Payshen.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$before = array(
			'test' => $this->api_key( 'test' ),
			'live' => $this->api_key( 'live' ),
		);
		$saved  = parent::process_admin_options();
		Payshen_Logger::reset();

		foreach ( array( 'test', 'live' ) as $mode ) {
			$key = $this->api_key( $mode );
			if ( '' === $key || $key === $before[ $mode ] ) {
				continue;
			}
			$this->check_key_with_payshen( $mode );
		}
		return $saved;
	}

	/**
	 * Ask Payshen whether a key works, and say what it answered.
	 *
	 * @param string $mode test|live.
	 */
	private function check_key_with_payshen( $mode ) {
		$client = $this->client( $mode );
		if ( ! $client ) {
			return;
		}
		$label = 'live' === $mode ? __( 'live', 'payshen-for-woocommerce' ) : __( 'test', 'payshen-for-woocommerce' );
		try {
			$client->list_payments( 1 );
			/* translators: %s: test or live. */
			WC_Admin_Settings::add_message( sprintf( __( 'Payshen accepted the %s API key.', 'payshen-for-woocommerce' ), $label ) );
		} catch ( Payshen_API_Exception $e ) {
			if ( 401 === $e->status ) {
				/* translators: %s: test or live. */
				WC_Admin_Settings::add_error( sprintf( __( 'Payshen did not accept the %s API key. Check that you copied all of it.', 'payshen-for-woocommerce' ), $label ) );
			} elseif ( 403 === $e->status ) {
				/* translators: %s: test or live. */
				WC_Admin_Settings::add_error( sprintf( __( 'The %s API key is not allowed to do everything this plugin needs. Use a full-access key, or one with payments:read, payments:write and refunds:write.', 'payshen-for-woocommerce' ), $label ) );
			} else {
				/* translators: %s: test or live. */
				WC_Admin_Settings::add_error( sprintf( __( 'The %s API key was saved, but Payshen could not be reached to check it.', 'payshen-for-woocommerce' ), $label ) );
			}
			Payshen_Logger::warning(
				'API key check failed',
				array(
					'mode'   => $mode,
					'status' => $e->status,
					'code'   => $e->error_code,
				)
			);
		}
	}

	/**
	 * The active mode, test or live, from the Test mode setting.
	 *
	 * @return string
	 */
	public function mode() {
		return 'no' === $this->get_option( 'testmode', 'yes' ) ? 'live' : 'test';
	}

	/**
	 * The API key for a mode, or ''.
	 *
	 * @param string $mode test|live.
	 * @return string
	 */
	public function api_key( $mode ) {
		$key = trim( (string) $this->get_option( 'live' === $mode ? 'live_api_key' : 'test_api_key', '' ) );
		return Payshen_Keys::is_api_key( $key, $mode ) ? $key : '';
	}

	/**
	 * The webhook signing secret for a mode, or ''.
	 *
	 * @param string $mode test|live.
	 * @return string
	 */
	public function webhook_secret( $mode ) {
		$secret = trim( (string) $this->get_option( 'live' === $mode ? 'live_webhook_secret' : 'test_webhook_secret', '' ) );
		return Payshen_Keys::is_webhook_secret( $secret ) ? $secret : '';
	}

	/**
	 * An API client for a mode, or null when its key is missing.
	 *
	 * @param string $mode test|live.
	 * @return Payshen_API_Client|null
	 */
	public function client( $mode ) {
		$key = $this->api_key( $mode );
		if ( '' === $key ) {
			return null;
		}
		$options = array(
			'base_url'   => self::base_url(),
			'user_agent' => 'payshen-for-woocommerce/' . PAYSHEN_WC_VERSION . ' WordPress/' . get_bloginfo( 'version' ) . ' WooCommerce/' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ),
		);
		/**
		 * Filter the API client options (timeout, max_retries, transport).
		 *
		 * @param array  $options Options.
		 * @param string $mode    test|live.
		 */
		$options = (array) apply_filters( 'payshen_api_client_options', $options, $mode );
		try {
			return new Payshen_API_Client( $key, $options );
		} catch ( InvalidArgumentException $e ) {
			Payshen_Logger::error( 'API client not created: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Needs setup until the active mode has a key.
	 *
	 * @return bool
	 */
	public function needs_setup() {
		return '' === $this->api_key( $this->mode() );
	}

	/**
	 * Offered only with a key for the active mode, and for a total Payshen accepts.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( '' === $this->api_key( $this->mode() ) ) {
			return false;
		}
		if ( function_exists( 'WC' ) && WC()->cart && ( is_checkout() || is_checkout_pay_page() ) ) {
			$total = (float) $this->get_order_total();
			if ( $total > 0 ) {
				try {
					$minor = Payshen_Amount::to_minor( $total, get_woocommerce_currency() );
				} catch ( InvalidArgumentException $e ) {
					return false;
				}
				if ( ! Payshen_Amount::in_range( $minor ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * The allowed methods setting, never empty.
	 *
	 * @return string[]
	 */
	public function allowed_methods() {
		$value   = $this->get_option( 'allowed_methods', array( 'card' ) );
		$methods = array_values( array_intersect( self::METHODS, is_array( $value ) ? $value : array( $value ) ) );
		return array() === $methods ? array( 'card' ) : $methods;
	}

	/**
	 * The capture method: automatic or manual.
	 *
	 * @return string
	 */
	public function capture_method() {
		return 'manual' === $this->get_option( 'capture_method', 'automatic' ) ? 'manual' : 'automatic';
	}

	/**
	 * Start the payment and send the customer to Payshen's page.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $this->fail( __( 'The order could not be found. Please try again.', 'payshen-for-woocommerce' ) );
		}

		$mode   = $this->mode();
		$client = $this->client( $mode );
		if ( ! $client ) {
			Payshen_Logger::error( 'Checkout refused: no API key for the active mode', array( 'mode' => $mode ) );
			return $this->fail( __( 'This payment method is not available right now. Please choose another one.', 'payshen-for-woocommerce' ) );
		}

		// One request at a time per order: a double click waits for the first,
		// then finds its payment and sends the customer to the same page.
		$lock = Payshen_Lock::acquire( 'order_' . $order->get_id(), 90, 25 );
		if ( false === $lock ) {
			return $this->fail( __( 'Your payment is already being set up. Please wait a moment and try again.', 'payshen-for-woocommerce' ) );
		}
		try {
			$order = wc_get_order( $order->get_id() );
			return $this->start_payment( $order, $mode, $client );
		} finally {
			Payshen_Lock::release( 'order_' . $order->get_id(), $lock );
		}
	}

	/**
	 * The body of the create, the call, and the redirect.
	 *
	 * @param WC_Order           $order  Order.
	 * @param string             $mode   test|live.
	 * @param Payshen_API_Client $client Client.
	 * @return array
	 */
	private function start_payment( $order, $mode, $client ) {
		if ( Payshen_Order_Sync::is_paid( $order ) ) {
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			);
		}

		// Which attempt this is: the same one while the last payment still waits
		// for the customer, the next one once it ended without a charge.
		$attempt = 1;
		$prev_id = (string) $order->get_meta( '_payshen_payment_id' );
		if ( '' !== $prev_id ) {
			$prev_mode    = (string) $order->get_meta( '_payshen_mode' );
			$prev_attempt = max( 1, (int) $order->get_meta( '_payshen_attempt' ) );
			$prev_client  = $prev_mode === $mode ? $client : $this->client( $prev_mode );
			$prev         = null;
			if ( $prev_client ) {
				try {
					$prev = $prev_client->get_payment( $prev_id );
				} catch ( Payshen_API_Exception $e ) {
					if ( 404 !== $e->status ) {
						Payshen_Logger::error( 'Could not read the previous payment', self::error_context( $e, $order, $prev_id ) );
						return $this->fail( __( 'We could not reach the payment service. Please try again in a moment.', 'payshen-for-woocommerce' ) );
					}
				}
			}
			if ( null === $prev || array() === $prev ) {
				$attempt = $prev_attempt + 1;
			} else {
				$action = Payshen_Status::action( $prev['status'] ?? '' );
				if ( Payshen_Status::PAID === $action || Payshen_Status::AUTHORIZED === $action ) {
					Payshen_Order_Sync::apply( $order, $prev, 'checkout' );
					return array(
						'result'   => 'success',
						'redirect' => $this->get_return_url( $order ),
					);
				}
				if ( Payshen_Status::AWAITING_CUSTOMER === $action && $prev_mode === $mode ) {
					// The customer left the payment page: send them back to the same page
					// while it is still open, so one order never has two payable pages.
					$open_url = (string) $order->get_meta( '_payshen_next_url' );
					$open_til = (int) $order->get_meta( '_payshen_next_expires' );
					if ( self::is_page_url( $open_url ) && (string) $order->get_meta( '_payshen_next_payment' ) === $prev_id && ( 0 === $open_til || $open_til > time() + 60 ) ) {
						return array(
							'result'   => 'success',
							'redirect' => $open_url,
						);
					}
					$attempt = $prev_attempt;
				} elseif ( Payshen_Status::is_unpaid_end( $action ) || Payshen_Status::AWAITING_CUSTOMER === $action ) {
					$attempt = $prev_attempt + 1;
				} else {
					// In flight or unknown: it may still be charged. Never start a second one.
					return $this->fail( __( 'Your earlier payment for this order is still being confirmed. Please check your email before you pay again.', 'payshen-for-woocommerce' ) );
				}
			}
		}

		try {
			$body = $this->payment_body( $order, $mode );
		} catch ( InvalidArgumentException $e ) {
			return $this->fail( $e->getMessage() );
		}

		$payment = null;
		for ( $try = 0; $try < 4 && null === $payment; $try++ ) {
			$key = Payshen_Keys::payment( $order->get_id(), $order->get_order_key(), $attempt, home_url() );
			try {
				$answer = $client->create_payment( $body, $key );
			} catch ( Payshen_API_Exception $e ) {
				if ( 'idempotency_key_reuse' === $e->error_code ) {
					// The order changed since this attempt was made: a new attempt.
					++$attempt;
					continue;
				}
				if ( 'idempotency_in_progress' === $e->error_code ) {
					sleep( 2 );
					continue;
				}
				return $this->create_failed( $e, $order );
			}

			$expires = isset( $answer['next_action']['expires_at'] ) ? strtotime( (string) $answer['next_action']['expires_at'] ) : false;
			if ( false !== $expires && $expires < time() + 60 ) {
				// A replay of a page that has expired: start a fresh one.
				++$attempt;
				continue;
			}
			$payment = $answer;
		}

		if ( null === $payment ) {
			return $this->fail( __( 'Your payment is already being set up. Please wait a moment and try again.', 'payshen-for-woocommerce' ) );
		}

		$payment_id = isset( $payment['id'] ) && is_string( $payment['id'] ) ? $payment['id'] : ( isset( $payment['payment_id'] ) && is_string( $payment['payment_id'] ) ? $payment['payment_id'] : '' );
		$url        = isset( $payment['next_action']['url'] ) && is_string( $payment['next_action']['url'] ) ? $payment['next_action']['url'] : '';
		if ( '' === $payment_id || preg_match( '/^[A-Za-z0-9_\-]{1,100}$/', $payment_id ) !== 1 ) {
			Payshen_Logger::error( 'Create answered without a payment id', array( 'order_id' => $order->get_id() ) );
			return $this->fail( __( 'The payment could not be started. Please try again.', 'payshen-for-woocommerce' ) );
		}

		$is_new = $payment_id !== $prev_id;
		Payshen_Order_Sync::remember_payment( $order, $payment_id, $mode, $attempt, $body );
		// The payment page, so a customer who left it is sent back to the same one.
		$order->update_meta_data( '_payshen_next_payment', $payment_id );
		$order->update_meta_data( '_payshen_next_url', self::is_page_url( $url ) ? $url : '' );
		$order->update_meta_data( '_payshen_next_expires', isset( $payment['next_action']['expires_at'] ) ? (int) strtotime( (string) $payment['next_action']['expires_at'] ) : 0 );
		if ( $is_new ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: Payshen payment id, 2: test or live. */
					__( 'Payshen payment %1$s created (%2$s). The customer was sent to Payshen\'s payment page.', 'payshen-for-woocommerce' ),
					$payment_id,
					'live' === $mode ? __( 'live', 'payshen-for-woocommerce' ) : __( 'test', 'payshen-for-woocommerce' )
				)
			);
		}
		Payshen_Scheduler::schedule_checks( $order->get_id(), $order );
		$order->save();

		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) || preg_match( '#^https?://\S+$#i', $url ) !== 1 ) {
			// No page to send the customer to: read what Payshen holds and act on it.
			Payshen_Order_Sync::apply( $order, $payment, 'checkout' );
			Payshen_Logger::warning(
				'Create answered without a redirect',
				array(
					'order_id'   => $order->get_id(),
					'payment_id' => $payment_id,
					'status'     => $payment['status'] ?? null,
				)
			);
			if ( Payshen_Order_Sync::is_paid( $order ) || $order->has_status( 'on-hold' ) ) {
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
			return $this->fail( __( 'The payment could not be started. Please try again.', 'payshen-for-woocommerce' ) );
		}

		Payshen_Logger::debug(
			'Payment created',
			array(
				'order_id'   => $order->get_id(),
				'payment_id' => $payment_id,
				'attempt'    => $attempt,
				'mode'       => $mode,
			)
		);
		return array(
			'result'   => 'success',
			'redirect' => $url,
		);
	}

	/**
	 * What POST /api/v1/payments gets for this order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $mode  test|live.
	 * @return array
	 * @throws InvalidArgumentException With a message for the customer.
	 */
	public function payment_body( $order, $mode ) {
		$currency = strtoupper( (string) $order->get_currency() );
		try {
			$amount = Payshen_Amount::to_minor( (string) $order->get_total( 'edit' ), $currency );
		} catch ( InvalidArgumentException $e ) {
			throw new InvalidArgumentException( __( 'The order total could not be read. Please contact the store.', 'payshen-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown through wc_add_notice, which escapes.
		}
		if ( ! Payshen_Amount::in_range( $amount ) ) {
			throw new InvalidArgumentException( __( 'This payment method cannot take an order of this amount. Please choose another one.', 'payshen-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown through wc_add_notice, which escapes.
		}
		if ( preg_match( '/^[A-Z]{3}$/', $currency ) !== 1 ) {
			throw new InvalidArgumentException( __( 'This payment method cannot take this currency. Please choose another one.', 'payshen-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown through wc_add_notice, which escapes.
		}

		$country = Payshen_Customer::country( $order->get_billing_country() )
			?? Payshen_Customer::country( $order->get_shipping_country() )
			?? Payshen_Customer::country( WC()->countries ? WC()->countries->get_base_country() : '' );
		if ( null === $country ) {
			throw new InvalidArgumentException( __( 'Please enter your billing country.', 'payshen-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Shown through wc_add_notice, which escapes.
		}

		$token      = Payshen_Keys::return_token( $order->get_id(), $order->get_order_key(), wp_salt( 'auth' ) );
		$return_url = add_query_arg( 'payshen_return', $token, $this->get_return_url( $order ) );
		$cancel_url = add_query_arg(
			array(
				'payshen_cancel' => $order->get_id(),
				'payshen_token'  => $token,
			),
			wc_get_checkout_url()
		);

		$body = array(
			'amount'          => $amount,
			'currency'        => $currency,
			'country'         => $country,
			'allowed_methods' => $this->allowed_methods(),
			'reference'       => Payshen_Customer::text( (string) $order->get_order_number(), 64 ),
			'capture_method'  => $this->capture_method(),
			'return_url'      => $return_url,
			'cancel_url'      => $cancel_url,
		);

		$customer = Payshen_Customer::build(
			array(
				'id'          => $order->get_customer_id() > 0 ? 'wc-' . $order->get_customer_id() : '',
				'email'       => $order->get_billing_email(),
				'first_name'  => $order->get_billing_first_name(),
				'last_name'   => $order->get_billing_last_name(),
				'phone'       => $order->get_billing_phone(),
				'line1'       => $order->get_billing_address_1(),
				'line2'       => $order->get_billing_address_2(),
				'city'        => $order->get_billing_city(),
				'postal_code' => $order->get_billing_postcode(),
				'state'       => $order->get_billing_state(),
				'country'     => $order->get_billing_country(),
				'ip'          => $order->get_customer_ip_address(),
			)
		);
		if ( null !== $customer ) {
			$body['customer'] = $customer;
		}

		/**
		 * Filter the payment request sent to Payshen.
		 *
		 * @param array    $body  Request body.
		 * @param WC_Order $order Order.
		 * @param string   $mode  test|live.
		 */
		return (array) apply_filters( 'payshen_payment_request', $body, $order, $mode );
	}

	/**
	 * A create that Payshen refused or did not answer, in words for the customer.
	 *
	 * @param Payshen_API_Exception $e     Error.
	 * @param WC_Order              $order Order.
	 * @return array
	 */
	private function create_failed( Payshen_API_Exception $e, $order ) {
		Payshen_Logger::error( 'Create payment failed', self::error_context( $e, $order ) );

		switch ( $e->error_code ) {
			case 'no_eligible_method':
			case 'routing_failed':
			case 'no_eligible_connection':
				$message = __( 'This payment method cannot take this order right now. Please choose another one.', 'payshen-for-woocommerce' );
				break;
			case 'validation_error':
				$message = __( 'The payment could not be started. Please check your billing details and try again.', 'payshen-for-woocommerce' );
				break;
			case 'unauthorized':
			case 'invalid_api_key':
			case 'tenant_suspended':
			case 'insufficient_scope':
				$message = __( 'This payment method is not available right now. Please choose another one.', 'payshen-for-woocommerce' );
				break;
			case 'payment_result_unknown':
				$message = __( 'We could not confirm that your payment was set up. Please wait a minute, then try again. You will not be charged twice.', 'payshen-for-woocommerce' );
				break;
			default:
				$message = $e->is_network_error() || $e->status >= 500
					? __( 'We could not reach the payment service. Please try again in a moment.', 'payshen-for-woocommerce' )
					: __( 'The payment could not be started. Please try again or choose another payment method.', 'payshen-for-woocommerce' );
		}
		return $this->fail( $message );
	}

	/**
	 * Show an error at checkout and stop.
	 *
	 * @param string $message For the customer.
	 * @return array
	 */
	private function fail( $message ) {
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * Refund through Payshen.
	 *
	 * @param int        $order_id Order id.
	 * @param float|null $amount   Amount in major units.
	 * @param string     $reason   Reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return Payshen_Refunds::process( $this, $order_id, $amount, $reason );
	}

	/**
	 * A link to the payment in the Payshen dashboard.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public function get_transaction_url( $order ) {
		$id = (string) $order->get_meta( '_payshen_payment_id' );
		if ( '' === $id ) {
			$id = (string) $order->get_transaction_id();
		}
		return '' === $id ? '' : sprintf( $this->view_transaction_url, rawurlencode( $id ) );
	}

	/**
	 * What to log about an API error. Ids and codes only.
	 *
	 * @param Payshen_API_Exception $e          Error.
	 * @param WC_Order|null         $order      Order.
	 * @param string                $payment_id Payment id.
	 * @return array
	 */
	public static function error_context( Payshen_API_Exception $e, $order = null, $payment_id = '' ) {
		return array_filter(
			array(
				'order_id'   => $order ? $order->get_id() : null,
				'payment_id' => '' !== $payment_id ? $payment_id : null,
				'status'     => $e->status,
				'code'       => $e->error_code,
				'message'    => $e->getMessage(),
				'request_id' => $e->request_id,
			),
			static function ( $v ) {
				return null !== $v;
			}
		);
	}

	/**
	 * Whether a URL may be sent to the customer as a payment page: http(s) only.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_page_url( $url ) {
		return '' !== $url && false !== filter_var( $url, FILTER_VALIDATE_URL ) && 1 === preg_match( '#^https?://\S+$#i', $url );
	}
}
