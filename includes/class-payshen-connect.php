<?php
/**
 * "Connect with Payshen": the store's keys without copying them.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the admin to Payshen to approve this store, takes the code that comes
 * back, trades it for keys and webhook secrets, and lets the store go again.
 */
final class Payshen_Connect {

	/** Where what the store is connected to is remembered. */
	const OPTION = 'payshen_wc_connection';

	/** Seconds the admin has to approve in Payshen. */
	const TTL = 1800;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_payshen_connect_start', array( __CLASS__, 'start' ) );
		add_action( 'admin_post_payshen_connect', array( __CLASS__, 'finish' ) );
		add_action( 'admin_post_payshen_disconnect', array( __CLASS__, 'disconnect' ) );
	}

	/**
	 * The settings page.
	 *
	 * @param array $args Query arguments to add.
	 * @return string
	 */
	public static function settings_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payshen' ) );
	}

	/**
	 * The link that starts a connection.
	 *
	 * @return string
	 */
	public static function start_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=payshen_connect_start' ), 'payshen_connect_start' );
	}

	/**
	 * The link that disconnects.
	 *
	 * @return string
	 */
	public static function disconnect_url() {
		return wp_nonce_url( admin_url( 'admin-post.php?action=payshen_disconnect' ), 'payshen_disconnect' );
	}

	/**
	 * What the store is connected to, or null when its keys were entered by hand or there are none.
	 *
	 * @return array{workspace:string,modes:string[],connected_at:int}|null
	 */
	public static function connection() {
		$value = get_option( self::OPTION );
		if ( ! is_array( $value ) || ! isset( $value['connected_at'], $value['modes'] ) || ! is_array( $value['modes'] ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * Remember the verifier and state for this admin, then send them to Payshen.
	 */
	public static function start() {
		self::guard( 'payshen_connect_start' );
		$state    = Payshen_Connect_Request::new_state();
		$verifier = Payshen_Connect_Request::new_verifier();
		set_transient(
			self::transient_key(),
			array(
				'state'    => $state,
				'verifier' => $verifier,
			),
			self::TTL
		);
		// The nonce comes back in the return address, so the return is tied to this admin's session too.
		// add_query_arg, not wp_nonce_url: that one escapes & for HTML, and this is a URL.
		$return_url = add_query_arg( '_wpnonce', wp_create_nonce( 'payshen_connect_finish' ), admin_url( 'admin-post.php?action=payshen_connect' ) );
		$url        = Payshen_Connect_Request::authorize_url( Payshen_Gateway::base_url(), home_url( '/' ), $return_url, Payshen_Gateway::webhook_url(), $state, $verifier );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_payshen_host' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Payshen's host, for wp_safe_redirect.
	 *
	 * @param array $hosts Allowed hosts.
	 * @return array
	 */
	public static function allow_payshen_host( $hosts ) {
		$host = wp_parse_url( Payshen_Gateway::base_url(), PHP_URL_HOST );
		if ( is_string( $host ) ) {
			$hosts[] = $host;
		}
		return $hosts;
	}

	/**
	 * Back from Payshen: check the state, trade the code, save the keys.
	 */
	public static function finish() {
		self::guard( 'payshen_connect_finish' );
		$pending = get_transient( self::transient_key() );
		delete_transient( self::transient_key() );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! is_array( $pending ) || '' === $state || ! hash_equals( (string) $pending['state'], $state ) ) {
			self::back( 'expired' );
		}
		if ( '' !== $error ) {
			self::back( 'cancelled' );
		}
		if ( '' === $code ) {
			self::back( 'failed' );
		}

		$response = wp_remote_post(
			Payshen_Gateway::base_url() . '/api/connect/token',
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode(
					array(
						'code'          => $code,
						'code_verifier' => (string) $pending['verifier'],
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			Payshen_Logger::error( 'Connect: Payshen could not be reached', array( 'error' => $response->get_error_message() ) );
			self::back( 'unreachable' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$answer = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$creds  = 200 === $status ? Payshen_Connect_Request::credentials( $answer ) : null;
		if ( null === $creds ) {
			$code_out = is_array( $answer ) && isset( $answer['error']['code'] ) ? sanitize_key( (string) $answer['error']['code'] ) : 'failed';
			Payshen_Logger::error(
				'Connect: Payshen refused the code',
				array(
					'status' => $status,
					'code'   => $code_out,
				)
			);
			self::back( 'live_not_allowed' === $code_out ? 'live_not_allowed' : 'failed' );
		}

		$gateway = payshen_wc_gateway();
		if ( ! $gateway ) {
			self::back( 'failed' );
		}
		foreach ( array( 'test_api_key', 'test_webhook_secret', 'live_api_key', 'live_webhook_secret' ) as $field ) {
			// A live key is only replaced when live was approved this time.
			if ( str_starts_with( $field, 'live_' ) && ! $creds['live'] ) {
				continue;
			}
			$gateway->update_option( $field, $creds[ $field ] );
		}
		// The modes whose keys came from Payshen: a live key connected earlier
		// stays connected when this time was test only. A key entered by hand
		// is never one of them, so Disconnect never revokes it.
		$before = self::connection();
		$modes  = array( 'test' );
		if ( $creds['live'] || ( $before && in_array( 'live', $before['modes'], true ) ) ) {
			$modes[] = 'live';
		}
		update_option(
			self::OPTION,
			array(
				'workspace'    => $creds['workspace'],
				'modes'        => $modes,
				'connected_at' => time(),
			),
			false
		);
		Payshen_Logger::reset();
		Payshen_Logger::debug( 'Connected with Payshen', array( 'modes' => implode( ',', $modes ) ) );
		self::back( '' === $creds['test_webhook_secret'] ? 'connected_no_webhook' : 'connected' );
	}

	/**
	 * Let the store go: Payshen revokes its keys and removes its webhooks, and the store forgets them.
	 */
	public static function disconnect() {
		self::guard( 'payshen_disconnect' );
		$gateway    = payshen_wc_gateway();
		$connection = self::connection();
		$modes      = $connection ? $connection['modes'] : array();
		if ( $gateway ) {
			foreach ( $modes as $mode ) {
				$key = $gateway->api_key( $mode );
				if ( '' === $key ) {
					continue;
				}
				$response = wp_remote_post(
					Payshen_Gateway::base_url() . '/api/connect/disconnect',
					array(
						'timeout'     => 20,
						'redirection' => 0,
						'headers'     => array(
							'Content-Type'  => 'application/json',
							'Authorization' => 'Bearer ' . $key,
						),
						'body'        => wp_json_encode( array( 'webhook_url' => Payshen_Gateway::webhook_url() ) ),
					)
				);
				if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
					Payshen_Logger::warning( 'Disconnect: Payshen did not confirm', array( 'mode' => $mode ) );
				}
			}
		}
		if ( $gateway ) {
			foreach ( $modes as $mode ) {
				$gateway->update_option( $mode . '_api_key', '' );
				$gateway->update_option( $mode . '_webhook_secret', '' );
			}
		}
		delete_option( self::OPTION );
		Payshen_Logger::reset();
		self::back( 'disconnected' );
	}

	/**
	 * Only a shop manager, only with this action's nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect this store.', 'payshen-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Per admin, so two admins connecting at once do not cross.
	 *
	 * @return string
	 */
	private static function transient_key() {
		return 'payshen_connect_' . get_current_user_id();
	}

	/**
	 * Back to the settings page with an outcome.
	 *
	 * @param string $outcome Outcome.
	 */
	private static function back( $outcome ) {
		wp_safe_redirect( self::settings_url( array( 'payshen_connect' => $outcome ) ) );
		exit;
	}

	/**
	 * The box at the top of the settings page.
	 */
	public static function render_box() {
		$messages = array(
			'connected'            => array( 'success', __( 'Connected with Payshen. Your keys and webhook are set.', 'payshen-for-woocommerce' ) ),
			'connected_no_webhook' => array( 'warning', __( 'Connected with Payshen. Payshen cannot send webhooks to this site\'s address (it is not public), so orders are confirmed by scheduled checks.', 'payshen-for-woocommerce' ) ),
			'cancelled'            => array( 'warning', __( 'The connection was cancelled in Payshen. Nothing was changed.', 'payshen-for-woocommerce' ) ),
			'expired'              => array( 'error', __( 'The connection took too long or was started in another window. Connect again.', 'payshen-for-woocommerce' ) ),
			'unreachable'          => array( 'error', __( 'Payshen could not be reached. Nothing was changed. Try again in a minute.', 'payshen-for-woocommerce' ) ),
			'live_not_allowed'     => array( 'error', __( 'Your Payshen workspace cannot take live payments yet. Connect again without live payments.', 'payshen-for-woocommerce' ) ),
			'failed'               => array( 'error', __( 'Payshen did not hand over the keys. Nothing was changed. Connect again.', 'payshen-for-woocommerce' ) ),
			'disconnected'         => array( 'success', __( 'Disconnected. The keys were removed from this store and revoked in Payshen.', 'payshen-for-woocommerce' ) ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$outcome = isset( $_GET['payshen_connect'] ) ? sanitize_key( wp_unslash( $_GET['payshen_connect'] ) ) : '';
		if ( isset( $messages[ $outcome ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $outcome ][0] ) . ' inline"><p>' . esc_html( $messages[ $outcome ][1] ) . '</p></div>';
		}

		$connection = self::connection();
		echo '<div class="payshen-connect" style="margin:16px 0;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;max-width:720px">';
		if ( $connection ) {
			$when = wp_date( get_option( 'date_format' ), (int) $connection['connected_at'] );
			echo '<p style="margin:0 0 8px;font-size:14px"><strong>' . esc_html__( 'Connected with Payshen', 'payshen-for-woocommerce' ) . '</strong></p>';
			echo '<p style="margin:0 0 12px">';
			if ( '' !== $connection['workspace'] ) {
				/* translators: 1: Payshen workspace name, 2: date. */
				echo esc_html( sprintf( __( 'Workspace: %1$s. Connected on %2$s.', 'payshen-for-woocommerce' ), $connection['workspace'], $when ) ) . ' ';
			}
			echo esc_html( in_array( 'live', $connection['modes'], true ) ? __( 'Test and live payments.', 'payshen-for-woocommerce' ) : __( 'Test payments only. Connect again and tick live payments when you are ready.', 'payshen-for-woocommerce' ) );
			echo '</p>';
			echo '<a class="button" href="' . esc_url( self::start_url() ) . '">' . esc_html__( 'Connect again', 'payshen-for-woocommerce' ) . '</a> ';
			echo '<a class="button button-link-delete" style="margin-left:8px" href="' . esc_url( self::disconnect_url() ) . '" onclick="return confirm(\'' . esc_js( __( 'Disconnect this store? Its keys are revoked in Payshen and Payshen stops taking payments here.', 'payshen-for-woocommerce' ) ) . '\')">' . esc_html__( 'Disconnect', 'payshen-for-woocommerce' ) . '</a>';
		} else {
			echo '<p style="margin:0 0 8px;font-size:14px"><strong>' . esc_html__( 'Connect your Payshen account', 'payshen-for-woocommerce' ) . '</strong></p>';
			echo '<p style="margin:0 0 12px">' . esc_html__( 'Sign in to Payshen and approve this store. Your API keys and webhook are set up for you, nothing to copy.', 'payshen-for-woocommerce' ) . '</p>';
			echo '<a class="button button-primary" href="' . esc_url( self::start_url() ) . '">' . esc_html__( 'Connect with Payshen', 'payshen-for-woocommerce' ) . '</a>';
			echo '<p style="margin:12px 0 0;color:#646970">' . wp_kses(
				/* translators: %s: link to Payshen pricing. */
				sprintf( __( 'No Payshen account yet? <a href="%s" target="_blank" rel="noopener">Ask for one</a>. You can also enter keys by hand below.', 'payshen-for-woocommerce' ), esc_url( 'https://www.payshen.com/pricing' ) ),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			) . '</p>';
		}
		echo '</div>';
	}
}
