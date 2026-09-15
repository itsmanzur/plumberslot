<?php
/**
 * Stripe Checkout.
 *
 * Checkout, not Elements and never a raw card form: the payer leaves the site,
 * so no card field ever renders on a WordPress page the site owner controls.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Payments;

use TutorSlot\Support\Crypto;
use TutorSlot\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class StripeGateway implements GatewayInterface {

	private const API_SESSIONS = 'https://api.stripe.com/v1/checkout/sessions';
	private const API_REFUNDS  = 'https://api.stripe.com/v1/refunds';

	public function id(): string {
		return 'stripe';
	}

	public function label(): string {
		return __( 'Card', 'tutorslot' );
	}

	public function is_configured(): bool {
		return '' !== $this->secret_key();
	}

	public function start(
		int $booking_id,
		int $amount_minor,
		string $currency,
		string $success_url,
		string $cancel_url
	): array|WP_Error {
		$response = wp_remote_post(
			self::API_SESSIONS,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization'   => 'Bearer ' . $this->secret_key(),
					'Idempotency-Key' => 'ts_start_' . $booking_id . '_' . $amount_minor . '_' . substr( md5( $success_url ), 0, 8 ),
				),
				'body'    => array(
					'mode'                                => 'payment',
					'success_url'                         => $success_url,
					'cancel_url'                          => $cancel_url,
					'client_reference_id'                 => (string) $booking_id,
					'line_items[0][quantity]'             => 1,
					'line_items[0][price_data][currency]' => strtolower( $currency ),
					'line_items[0][price_data][unit_amount]' => $amount_minor,
					'line_items[0][price_data][product_data][name]' => __( 'Lesson', 'tutorslot' ),
					'metadata[booking_id]'                => (string) $booking_id,
					'metadata[amount_minor]'              => (string) $amount_minor,
					'metadata[currency]'                  => strtoupper( $currency ),
					'payment_intent_data[metadata][booking_id]' => (string) $booking_id,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'tutorslot_stripe_http',
				__( 'Could not contact the card payment provider.', 'tutorslot' ),
				array( 'status' => 502 )
			);
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['url'] ) || empty( $body['id'] ) ) {
			return new WP_Error(
				'tutorslot_stripe_failed',
				__( 'Could not start the payment. Try again, or pick another method.', 'tutorslot' ),
				array( 'status' => 502 )
			);
		}

		return array(
			'url'       => (string) $body['url'],
			'reference' => (string) ( $body['payment_intent'] ?? $body['id'] ),
		);
	}

	/**
	 * @param array<string, string> $headers Request headers.
	 */
	public function verify_webhook( string $raw_body, array $headers ): bool {
		$header = $headers['stripe-signature'] ?? '';
		$secret = $this->webhook_secret();

		if ( '' === $header || '' === $secret ) {
			return false;
		}

		$timestamp = '';
		$signature = '';

		foreach ( explode( ',', $header ) as $part ) {
			[ $key, $value ] = array_pad( explode( '=', trim( $part ), 2 ), 2, '' );

			if ( 't' === $key ) {
				$timestamp = $value;
			}
			if ( 'v1' === $key ) {
				$signature = $value;
			}
		}

		if ( '' === $timestamp || abs( time() - (int) $timestamp ) > 5 * MINUTE_IN_SECONDS ) {
			return false;
		}

		return hash_equals( hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret ), $signature );
	}

	/**
	 * @return array{booking_id:int, status:string, reference:string, idempotency_key:string, amount_minor?:int, currency?:string}|WP_Error
	 */
	public function parse_webhook( string $raw_body ): array|WP_Error {
		$event = json_decode( $raw_body, true );

		if ( ! is_array( $event ) || empty( $event['id'] ) ) {
			return new WP_Error( 'tutorslot_bad_webhook', '', array( 'status' => 400 ) );
		}

		$type   = (string) ( $event['type'] ?? '' );
		$object = $event['data']['object'] ?? array();
		$status = 'ignored';

		if ( 'checkout.session.completed' === $type || 'payment_intent.succeeded' === $type ) {
			$status = 'paid';
		} elseif ( in_array( $type, array( 'checkout.session.expired', 'payment_intent.payment_failed' ), true ) ) {
			$status = 'failed';
		} elseif ( 'charge.refunded' === $type ) {
			$status = 'refunded';
		}

		$booking_id = (int) ( $object['metadata']['booking_id'] ?? $object['client_reference_id'] ?? 0 );
		$reference  = (string) ( $object['payment_intent'] ?? $object['id'] ?? '' );
		$amount     = isset( $object['amount_total'] )
			? (int) $object['amount_total']
			: (int) ( $object['amount'] ?? $object['metadata']['amount_minor'] ?? 0 );
		$currency   = strtoupper( (string) ( $object['currency'] ?? $object['metadata']['currency'] ?? '' ) );

		return array(
			'booking_id'      => $booking_id,
			'status'          => $status,
			'reference'       => $reference,
			'idempotency_key' => (string) $event['id'],
			'amount_minor'    => $amount,
			'currency'        => $currency,
		);
	}

	public function refund( string $reference, int $amount_minor, string $idempotency_key = '' ): bool|WP_Error {
		if ( '' === $reference ) {
			return new WP_Error( 'tutorslot_bad_reference', __( 'Missing payment reference.', 'tutorslot' ), array( 'status' => 422 ) );
		}

		$body = array(
			'payment_intent' => $reference,
			'amount'         => $amount_minor,
		);

		$headers = array(
			'Authorization' => 'Bearer ' . $this->secret_key(),
		);

		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$response = wp_remote_post(
			self::API_REFUNDS,
			array(
				'timeout' => 20,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'tutorslot_stripe_http',
				__( 'Could not contact the card payment provider.', 'tutorslot' ),
				array( 'status' => 502 )
			);
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 && is_array( $data ) && ! empty( $data['id'] ) ) {
			return true;
		}

		// Already-refunded is success for idempotent retries.
		if ( is_array( $data ) && isset( $data['error']['code'] ) && 'charge_already_refunded' === $data['error']['code'] ) {
			return true;
		}

		return new WP_Error(
			'tutorslot_stripe_refund_failed',
			__( 'Refund failed.', 'tutorslot' ),
			array( 'status' => 502 )
		);
	}

	private function secret_key(): string {
		return (string) ( Crypto::decrypt( Settings::string( 'stripe_secret_key' ) ) ?? '' );
	}

	private function webhook_secret(): string {
		return (string) ( Crypto::decrypt( Settings::string( 'stripe_webhook_secret' ) ) ?? '' );
	}
}
