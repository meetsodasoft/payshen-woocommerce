<?php
/**
 * "Connect with Payshen": the parts that need no WordPress.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * The store sends its admin to Payshen with a state and a PKCE challenge; the
 * merchant approves; the admin comes back with a one-time code; the store's
 * server trades the code and the verifier, which never left this site, for
 * its keys. A code seen in a log or a browser history is worth nothing alone.
 */
final class Payshen_Connect_Request {

	/**
	 * A new PKCE verifier: 64 characters of base64url.
	 *
	 * @return string
	 */
	public static function new_verifier() {
		return self::base64url( random_bytes( 48 ) );
	}

	/**
	 * A new state: 32 hex characters.
	 *
	 * @return string
	 */
	public static function new_state() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * The S256 challenge for a verifier.
	 *
	 * @param string $verifier Verifier.
	 * @return string
	 */
	public static function challenge( $verifier ) {
		return self::base64url( hash( 'sha256', (string) $verifier, true ) );
	}

	/**
	 * Where to send the admin to approve the store.
	 *
	 * @param string $base_url    Payshen base URL.
	 * @param string $site_url    The store's home URL.
	 * @param string $return_url  Where Payshen sends the admin back.
	 * @param string $webhook_url The store's webhook URL.
	 * @param string $state       State.
	 * @param string $verifier    PKCE verifier.
	 * @return string
	 */
	public static function authorize_url( $base_url, $site_url, $return_url, $webhook_url, $state, $verifier ) {
		return rtrim( (string) $base_url, '/' ) . '/connect/woocommerce?' . http_build_query(
			array(
				'site_url'              => $site_url,
				'return_url'            => $return_url,
				'webhook_url'           => $webhook_url,
				'state'                 => $state,
				'code_challenge'        => self::challenge( $verifier ),
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * The settings Payshen's answer to a code trade fills in, or null when the answer is not one.
	 *
	 * @param mixed $answer Decoded JSON.
	 * @return array{test_api_key:string,test_webhook_secret:string,live_api_key:string,live_webhook_secret:string,workspace:string,live:bool}|null
	 */
	public static function credentials( $answer ) {
		if ( ! is_array( $answer ) || ! isset( $answer['sandbox'] ) || ! is_array( $answer['sandbox'] ) ) {
			return null;
		}
		$test_key = (string) ( $answer['sandbox']['api_key'] ?? '' );
		if ( ! Payshen_Keys::is_api_key( $test_key, 'test' ) ) {
			return null;
		}
		$out = array(
			'test_api_key'        => $test_key,
			'test_webhook_secret' => self::secret( $answer['sandbox']['webhook_secret'] ?? '' ),
			'live_api_key'        => '',
			'live_webhook_secret' => '',
			'workspace'           => is_array( $answer['workspace'] ?? null ) ? substr( (string) ( $answer['workspace']['name'] ?? '' ), 0, 120 ) : '',
			'live'                => false,
		);
		if ( isset( $answer['production'] ) && is_array( $answer['production'] ) ) {
			$live_key = (string) ( $answer['production']['api_key'] ?? '' );
			if ( ! Payshen_Keys::is_api_key( $live_key, 'live' ) ) {
				return null;
			}
			$out['live_api_key']        = $live_key;
			$out['live_webhook_secret'] = self::secret( $answer['production']['webhook_secret'] ?? '' );
			$out['live']                = true;
		}
		return $out;
	}

	/**
	 * A webhook secret, or '' when there is none.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function secret( $value ) {
		$value = is_string( $value ) ? $value : '';
		return Payshen_Keys::is_webhook_secret( $value ) ? $value : '';
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	private static function base64url( $bytes ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE (RFC 7636) is defined in base64url.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
