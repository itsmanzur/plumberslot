<?php
/**
 * bKash Tokenized Checkout.
 *
 * Almost every existing bKash plugin for WordPress is manual: the customer
 * types a transaction id into a text field and the shop owner reconciles by
 * hand. This is a real grant-token / create-payment / execute-payment flow, so
 * a booking is confirmed by bKash rather than by a typed string.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Payments;

use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class BkashGateway implements GatewayInterface {

	public function id(): string {
		return 'bkash';
	}

	public function label(): string {
		return __( 'bKash', 'plumberslot' );
	}

	public function is_configured(): bool {
		return '' !== Settings::string( 'bkash_app_key' )
			&& '' !== $this->app_secret()
			&& '' !== Settings::string( 'bkash_username' )
			&& '' !== $this->password();
	}

	public function start(
		int $booking_id,
		int $amount_minor,
		string $currency,
		string $success_url,
		string $cancel_url
	): array|WP_Error {
		if ( 'BDT' !== strtoupper( $currency ) ) {
			return new WP_Error(
				'plumberslot_bkash_currency',
				__( 'bKash only accepts BDT.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$token = $this->grant_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// bKash amounts are major units as a string (e.g. "500.00").
		$amount = number_format( $amount_minor / 100, 2, '.', '' );

		$callback = rest_url( 'plumberslot/v1/payments/bkash/callback' );
		$callback = add_query_arg(
			array(
				'booking_id' => $booking_id,
				'return'     => rawurlencode( $success_url ),
				'cancel'     => rawurlencode( $cancel_url ),
			),
			$callback
		);

		$response = wp_remote_post(
			$this->base_url() . '/tokenized/checkout/payment/create',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => $token,
					'X-APP-Key'     => Settings::string( 'bkash_app_key' ),
				),
				'body'    => wp_json_encode(
					array(
						'mode'                  => '0011',
						'payerReference'        => 'PS' . $booking_id,
						'callbackURL'           => $callback,
						'amount'                => $amount,
						'currency'              => 'BDT',
						'intent'                => 'sale',
						'merchantInvoiceNumber' => 'PS' . $booking_id . 'T' . time(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->http_error();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['bkashURL'] ) || empty( $body['paymentID'] ) ) {
			return new WP_Error(
				'plumberslot_bkash_failed',
				__( 'Could not start the bKash payment.', 'plumberslot' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'url'       => (string) $body['bkashURL'],
			'reference' => (string) $body['paymentID'],
		);
	}

	/**
	 * @param array<string, string> $headers Request headers.
	 */
	public function verify_webhook( string $raw_body, array $headers ): bool {
		// Callbacks are verified by re-querying / executing the payment server-side.
		return false;
	}

	/**
	 * @return array{booking_id:int, status:string, reference:string, idempotency_key:string, amount_minor?:int, currency?:string}|WP_Error
	 */
	public function parse_webhook( string $raw_body ): array|WP_Error {
		return new WP_Error( 'plumberslot_not_implemented', '', array( 'status' => 400 ) );
	}

	/**
	 * Execute + query a paymentID from the callback. Never trust query status alone.
	 *
	 * @return array{booking_id:int, status:string, reference:string, idempotency_key:string, amount_minor:int, currency:string}|WP_Error
	 */
	public function complete_callback( string $payment_id, int $booking_id ): array|WP_Error {
		$token = $this->grant_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$executed = $this->api_post(
			'/tokenized/checkout/payment/execute/' . rawurlencode( $payment_id ),
			$token,
			array()
		);

		if ( is_wp_error( $executed ) ) {
			// Fall back to query if execute already ran.
			$executed = $this->api_get(
				'/tokenized/checkout/payment/query/' . rawurlencode( $payment_id ),
				$token
			);
		}

		if ( is_wp_error( $executed ) ) {
			return $executed;
		}

		$status_code = (string) ( $executed['transactionStatus'] ?? $executed['statusCode'] ?? '' );
		$paid        = in_array( strtolower( $status_code ), array( 'completed', 'success' ), true )
			|| '0000' === (string) ( $executed['statusCode'] ?? '' );

		$amount_str = (string) ( $executed['amount'] ?? '0' );
		$amount     = (int) round( (float) $amount_str * 100 );
		$trx_id     = (string) ( $executed['trxID'] ?? $payment_id );

		return array(
			'booking_id'      => $booking_id,
			'status'          => $paid ? 'paid' : 'failed',
			'reference'       => $trx_id,
			'idempotency_key' => 'bkash_' . $payment_id,
			'amount_minor'    => $amount,
			'currency'        => 'BDT',
		);
	}

	public function refund( string $reference, int $amount_minor, string $idempotency_key = '' ): bool|WP_Error {
		$token = $this->grant_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$amount = number_format( $amount_minor / 100, 2, '.', '' );
		$body   = $this->api_post(
			'/tokenized/checkout/payment/refund',
			$token,
			array(
				'paymentID' => $reference,
				'amount'    => $amount,
				'trxID'     => $reference,
				'sku'       => 'service_call',
				'reason'    => 'Booking cancelled',
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$code = (string) ( $body['statusCode'] ?? '' );
		if ( '0000' === $code || ! empty( $body['refundTrxID'] ) ) {
			return true;
		}

		return new WP_Error(
			'plumberslot_bkash_refund_failed',
			__( 'bKash refund failed.', 'plumberslot' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * Grant token with a short safe cache.
	 */
	public function grant_token(): string|WP_Error {
		$cache_key = 'plumberslot_bkash_token_' . ( Settings::bool( 'bkash_sandbox', true ) ? 'sb' : 'live' );
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->base_url() . '/tokenized/checkout/token/grant',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'username'     => Settings::string( 'bkash_username' ),
					'password'     => $this->password(),
				),
				'body'    => wp_json_encode(
					array(
						'app_key'    => Settings::string( 'bkash_app_key' ),
						'app_secret' => $this->app_secret(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->http_error();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['id_token'] ) ) {
			return new WP_Error(
				'plumberslot_bkash_token',
				__( 'Could not authenticate with bKash.', 'plumberslot' ),
				array( 'status' => 502 )
			);
		}

		$token   = (string) $body['id_token'];
		$expires = max( 60, min( 3500, (int) ( $body['expires_in'] ?? 3600 ) - 60 ) );
		set_transient( $cache_key, $token, $expires );

		return $token;
	}

	private function base_url(): string {
		return Settings::bool( 'bkash_sandbox', true )
			? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
			: 'https://tokenized.pay.bka.sh/v1.2.0-beta';
	}

	/**
	 * @param array<string, mixed> $payload JSON body.
	 * @return array<string, mixed>|WP_Error
	 */
	private function api_post( string $path, string $token, array $payload ): array|WP_Error {
		$response = wp_remote_post(
			$this->base_url() . $path,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => $token,
					'X-APP-Key'     => Settings::string( 'bkash_app_key' ),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		return $this->decode( $response );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function api_get( string $path, string $token ): array|WP_Error {
		$response = wp_remote_get(
			$this->base_url() . $path,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => $token,
					'X-APP-Key'     => Settings::string( 'bkash_app_key' ),
				),
			)
		);

		return $this->decode( $response );
	}

	/**
	 * @param array<string, mixed>|\WP_Error $response HTTP response.
	 * @return array<string, mixed>|WP_Error
	 */
	private function decode( $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $this->http_error();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : new WP_Error( 'plumberslot_bkash_bad_response', '', array( 'status' => 502 ) );
	}

	private function http_error(): WP_Error {
		return new WP_Error(
			'plumberslot_bkash_http',
			__( 'Could not contact bKash.', 'plumberslot' ),
			array( 'status' => 502 )
		);
	}

	private function app_secret(): string {
		return (string) ( Crypto::decrypt( Settings::string( 'bkash_app_secret' ) ) ?? '' );
	}

	private function password(): string {
		$raw = Settings::string( 'bkash_password' );
		if ( '' === $raw ) {
			return '';
		}

		return (string) ( Crypto::decrypt( $raw ) ?? $raw );
	}
}
