<?php
/**
 * Orchestrates start / confirm / fail / refund with server-side amount checks.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\PaymentRepository;
use PlumberSlot\Database\TransactionManager;
use PlumberSlot\Payments\BkashGateway;
use PlumberSlot\Payments\GatewayRegistry;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Cache;
use PlumberSlot\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PaymentService {

	public function __construct(
		private readonly GatewayRegistry $gateways,
		private readonly PaymentRepository $payments,
		private readonly BookingRepository $bookings,
		private readonly BookingService $booking_service,
		private readonly CreditService $credits,
		private readonly TransactionManager $transactions
	) {}

	/**
	 * @return list<array{id:string, label:string}>
	 */
	public function configured_gateways(): array {
		if ( ! Settings::bool( 'payments_enabled', true ) ) {
			return array();
		}

		$out = array();
		foreach ( $this->gateways->configured() as $gateway ) {
			$out[] = array(
				'id'    => $gateway->id(),
				'label' => $gateway->label(),
			);
		}

		return $out;
	}

	/**
	 * Start checkout. Amount and currency always come from the booking row.
	 *
	 * @return array{url:string, payment_id:int, gateway:string, amount_minor:int, currency:string}|WP_Error
	 */
	public function start( int $booking_id, string $gateway_id, string $success_url, string $cancel_url ): array|WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new WP_Error( 'plumberslot_not_found', __( 'Booking not found.', 'plumberslot' ), array( 'status' => 404 ) );
		}

		if ( in_array( (string) $booking->status, array( 'confirmed', 'completed', 'cancelled', 'refunded', 'payment_expired' ), true ) ) {
			return new WP_Error(
				'plumberslot_not_payable',
				__( 'This booking cannot be paid right now.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		$amount   = (int) $booking->price_minor;
		$currency = (string) $booking->currency;

		if ( $amount <= 0 ) {
			return new WP_Error(
				'plumberslot_zero_amount',
				__( 'This lesson does not need a payment.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$gateway = $this->gateways->get( $gateway_id );
		if ( ! $gateway || ! $gateway->is_configured() ) {
			return new WP_Error(
				'plumberslot_gateway_unavailable',
				__( 'That payment method is not available.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$payment_id = $this->payments->create(
			array(
				'booking_id'   => $booking_id,
				'gateway'      => $gateway->id(),
				'amount_minor' => $amount,
				'currency'     => $currency,
				'status'       => 'pending',
			)
		);

		$result = $gateway->start( $booking_id, $amount, $currency, $success_url, $cancel_url );

		if ( is_wp_error( $result ) ) {
			$this->payments->update_status( $payment_id, 'failed' );
			$this->booking_service->mark_payment_failed( $booking_id );

			return $result;
		}

		$this->payments->set_reference( $payment_id, (string) $result['reference'] );
		$this->bookings->update_status( $booking_id, 'pending_payment' );

		AuditLog::record(
			'payment.started',
			'payment',
			$payment_id,
			array(
				'booking' => $booking_id,
				'gateway' => $gateway->id(),
				'amount'  => $amount,
			)
		);

		do_action( 'plumberslot_payment_started', $payment_id, $booking_id );

		return array(
			'url'          => (string) $result['url'],
			'payment_id'   => $payment_id,
			'gateway'      => $gateway->id(),
			'amount_minor' => $amount,
			'currency'     => $currency,
		);
	}

	/**
	 * Apply a verified gateway event (webhook or bKash callback).
	 *
	 * @param array{booking_id:int, status:string, reference:string, idempotency_key:string, amount_minor?:int, currency?:string} $event Parsed event.
	 * @return array{ok?:bool, duplicate?:bool, ignored?:bool}|WP_Error
	 */
	public function apply_event( string $gateway_id, array $event ): array|WP_Error {
		$key = (string) ( $event['idempotency_key'] ?? '' );
		if ( '' === $key ) {
			return new WP_Error( 'plumberslot_bad_webhook', '', array( 'status' => 400 ) );
		}

		if ( $this->payments->webhook_seen( $gateway_id, $key ) ) {
			return array( 'duplicate' => true );
		}

		$status = (string) ( $event['status'] ?? 'ignored' );
		if ( ! in_array( $status, array( 'paid', 'failed', 'refunded', 'cancelled' ), true ) ) {
			$this->payments->claim_webhook( $gateway_id, $key, (int) ( $event['booking_id'] ?? 0 ) );

			return array( 'ignored' => true );
		}

		$booking_id = (int) ( $event['booking_id'] ?? 0 );
		$booking    = $booking_id > 0 ? $this->bookings->find( $booking_id ) : null;

		if ( ! $booking ) {
			return new WP_Error( 'plumberslot_not_found', '', array( 'status' => 404 ) );
		}

		// Server-side amount/currency check — client cannot underpay.
		if ( 'paid' === $status && isset( $event['amount_minor'] ) && (int) $event['amount_minor'] > 0 ) {
			if ( (int) $event['amount_minor'] < (int) $booking->price_minor ) {
				AuditLog::record(
					'payment.underpay_rejected',
					'booking',
					$booking_id,
					array(
						'expected' => (int) $booking->price_minor,
						'got'      => (int) $event['amount_minor'],
					)
				);

				return new WP_Error( 'plumberslot_underpay', '', array( 'status' => 409 ) );
			}
		}

		if ( ! empty( $event['currency'] ) && strtoupper( (string) $event['currency'] ) !== strtoupper( (string) $booking->currency ) ) {
			return new WP_Error( 'plumberslot_currency_mismatch', '', array( 'status' => 409 ) );
		}

		if ( 'payment_expired' === (string) $booking->status ) {
			return $this->apply_expired_event( $gateway_id, $event, $booking, $key );
		}

		if ( ! $this->payments->claim_webhook( $gateway_id, $key, $booking_id, hash( 'sha256', wp_json_encode( $event ) ) ) ) {
			return array( 'duplicate' => true );
		}

		$reference = (string) ( $event['reference'] ?? '' );
		$payment   = $reference
			? $this->payments->find_by_reference( $gateway_id, $reference )
			: $this->payments->latest_for_booking( $booking_id );

		if ( 'paid' === $status ) {
			if ( $payment ) {
				// Keep bKash paymentID for refunds; Stripe may upgrade to payment_intent.
				$ref_for_row = ( 'bkash' === $gateway_id && ! empty( $payment->reference ) )
					? null
					: $reference;
				$this->payments->update_status( (int) $payment->id, 'paid', $ref_for_row );
			} else {
				$this->payments->create(
					array(
						'booking_id'   => $booking_id,
						'gateway'      => $gateway_id,
						'reference'    => $reference,
						'amount_minor' => (int) $booking->price_minor,
						'currency'     => (string) $booking->currency,
						'status'       => 'paid',
						'idempotency'  => $key,
					)
				);
			}

			$this->booking_service->confirm_paid( $booking_id, $reference );

			return array( 'ok' => true );
		}

		if ( 'refunded' === $status ) {
			if ( $payment ) {
				$this->payments->update_status( (int) $payment->id, 'refunded', $reference );
			}
			$this->booking_service->mark_refunded( $booking_id, $reference );

			return array( 'ok' => true );
		}

		if ( $payment ) {
			$this->payments->update_status( (int) $payment->id, $status, $reference ? $reference : null );
		}
		$this->booking_service->mark_payment_failed( $booking_id );

		return array( 'ok' => true );
	}

	/**
	 * @return array{refunded:bool}|WP_Error
	 */
	public function refund_booking( int $booking_id ): array|WP_Error {
		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return new WP_Error( 'plumberslot_not_found', '', array( 'status' => 404 ) );
		}

		if ( 'refunded' === (string) $booking->status ) {
			return array( 'refunded' => true );
		}

		if ( 'completed' !== (string) $booking->status ) {
			return new WP_Error(
				'plumberslot_invalid_transition',
				__( 'Only a completed lesson can be refunded.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		if ( ! empty( $booking->credit_id ) ) {
			return $this->refund_credit_booking( $booking );
		}

		$payment = $this->payments->latest_for_booking( $booking_id );
		if ( ( ! $payment || 'paid' !== (string) $payment->status ) && ! empty( $booking->payment_ref ) ) {
			$payment = $this->payments->find_latest_by_reference( (string) $booking->payment_ref );
		}
		if ( ! $payment || 'paid' !== (string) $payment->status || empty( $payment->reference ) ) {
			return new WP_Error(
				'plumberslot_not_refundable',
				__( 'No paid payment found to refund.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		$gateway = $this->gateways->get( (string) $payment->gateway );
		if ( ! $gateway ) {
			return new WP_Error( 'plumberslot_gateway_unavailable', '', array( 'status' => 422 ) );
		}

		$idem = 'ts_refund_' . $booking_id . '_' . (int) $payment->id;
		$done = $gateway->refund( (string) $payment->reference, (int) $payment->amount_minor, $idem );

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		if ( ! $this->transactions->begin() ) {
			return $this->refund_persistence_failed();
		}

		if ( ! $this->payments->update_status_if_current( (int) $payment->id, 'paid', 'refunded' )
			|| ! $this->bookings->update_status_if_current( $booking_id, 'completed', 'refunded' )
			|| ! $this->transactions->commit() ) {
			$this->transactions->rollback();

			$current_payment = $this->payments->find_latest_by_reference( (string) $payment->reference );
			$current_booking = $this->bookings->find( $booking_id );

			if ( $current_booking && 'refunded' === (string) $current_booking->status
				&& $current_payment && 'refunded' === (string) $current_payment->status ) {
				return array( 'refunded' => true );
			}

			return $this->refund_persistence_failed();
		}

		// Durable record that the provider-side refund was accepted.
		$this->payments->claim_webhook( (string) $payment->gateway, $idem, $booking_id );

		AuditLog::record( 'payment.refunded', 'payment', (int) $payment->id, array( 'booking' => $booking_id ) );
		$this->after_booking_refunded( $booking, (string) $payment->reference );

		return array( 'refunded' => true );
	}

	/** @return array{refunded:bool}|WP_Error */
	private function refund_credit_booking( object $booking ): array|WP_Error {
		$booking_id = (int) $booking->id;

		if ( ! $this->transactions->begin() ) {
			return $this->refund_persistence_failed();
		}

		if ( ! $this->bookings->update_status_if_current( $booking_id, 'completed', 'refunded' )
			|| ! $this->credits->refund( (int) $booking->credit_id, $booking_id )
			|| ! $this->transactions->commit() ) {
			$this->transactions->rollback();

			return $this->refund_persistence_failed();
		}

		$this->after_booking_refunded( $booking );

		return array( 'refunded' => true );
	}

	private function after_booking_refunded( object $booking, string $payment_ref = '' ): void {
		$booking_id = (int) $booking->id;

		Cache::forget_technician( (int) $booking->technician_id );
		AuditLog::record( 'booking.refunded', 'booking', $booking_id, array( 'ref' => $payment_ref ) );
		do_action( 'plumberslot_booking_refunded', $booking_id, $payment_ref );
	}

	private function refund_persistence_failed(): WP_Error {
		return new WP_Error(
			'plumberslot_refund_persistence_failed',
			__( 'The refund status could not be saved. Retry with the same request.', 'plumberslot' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Mark a pending payment cancelled so the payer can retry.
	 */
	public function cancel_pending( int $booking_id ): void {
		$payment = $this->payments->latest_for_booking( $booking_id );
		if ( $payment && 'pending' === (string) $payment->status ) {
			$this->payments->update_status( (int) $payment->id, 'cancelled' );
		}
		$this->booking_service->mark_payment_failed( $booking_id, 'cancelled' );
	}

	/**
	 * Expire only the checkout attempt named by the scheduled action.
	 *
	 * A stale action from an earlier retry must never cancel the latest payment.
	 */
	public function expire_pending( int $payment_id, int $booking_id ): void {
		$payment = $this->payments->latest_for_booking( $booking_id );
		$booking = $this->bookings->find( $booking_id );
		if ( ! $payment || ! $booking
			|| $payment_id !== (int) $payment->id
			|| 'pending' !== (string) $payment->status
			|| 'pending_payment' !== (string) $booking->status ) {
			return;
		}

		if ( ! $this->transactions->begin() ) {
			return;
		}

		if ( ! $this->payments->update_status_if_current( $payment_id, 'pending', 'expired' )
			|| ! $this->bookings->update_status_if_current( $booking_id, 'pending_payment', 'payment_expired' )
			|| ! $this->transactions->commit() ) {
			$this->transactions->rollback();

			return;
		}

		Cache::forget_technician( (int) $booking->technician_id );
		AuditLog::record( 'payment.expired', 'payment', $payment_id, array( 'booking' => $booking_id ), 0 );
		AuditLog::record( 'booking.payment_expired', 'booking', $booking_id, array( 'payment' => $payment_id ), 0 );
		do_action( 'plumberslot_booking_payment_expired', $booking_id, $booking );
	}

	/**
	 * A provider may report success after the local checkout window closed.
	 * Never revive the released slot; refund the late capture idempotently.
	 *
	 * @param array{booking_id:int,status:string,reference:string,idempotency_key:string,amount_minor?:int,currency?:string} $event Parsed event.
	 * @return array{ok?:bool, duplicate?:bool, ignored?:bool}|WP_Error
	 */
	private function apply_expired_event( string $gateway_id, array $event, object $booking, string $key ): array|WP_Error {
		$status     = (string) $event['status'];
		$booking_id = (int) $booking->id;
		$reference  = (string) ( $event['reference'] ?? '' );
		$payment    = $reference
			? $this->payments->find_by_reference( $gateway_id, $reference )
			: null;
		$payment    = $payment ? $payment : $this->payments->latest_for_booking( $booking_id );

		if ( 'paid' === $status ) {
			$gateway    = $this->gateways->get( $gateway_id );
			$refund_ref = 'bkash' === $gateway_id && $payment && ! empty( $payment->reference )
				? (string) $payment->reference
				: $reference;

			if ( ! $gateway || '' === $refund_ref ) {
				return new WP_Error( 'plumberslot_late_payment_refund_unavailable', '', array( 'status' => 502 ) );
			}

			$refunded = $gateway->refund(
				$refund_ref,
				(int) $booking->price_minor,
				'ts_late_refund_' . hash( 'sha256', $gateway_id . '|' . $key )
			);
			if ( is_wp_error( $refunded ) ) {
				AuditLog::record( 'payment.late_refund_failed', 'booking', $booking_id, array( 'gateway' => $gateway_id ) );

				return $refunded;
			}
		}

		if ( ! $this->payments->claim_webhook( $gateway_id, $key, $booking_id, hash( 'sha256', wp_json_encode( $event ) ) ) ) {
			return array( 'duplicate' => true );
		}

		if ( in_array( $status, array( 'paid', 'refunded' ), true ) ) {
			if ( $payment ) {
				$this->payments->update_status( (int) $payment->id, 'refunded' );
			}
			$this->booking_service->mark_refunded( $booking_id, $reference );
			AuditLog::record( 'payment.late_capture_refunded', 'booking', $booking_id, array( 'gateway' => $gateway_id ) );

			return array( 'ok' => true );
		}

		AuditLog::record(
			'payment.late_terminal_ignored',
			'booking',
			$booking_id,
			array(
				'gateway' => $gateway_id,
				'status'  => $status,
			)
		);

		return array( 'ignored' => true );
	}

	/** @return array<string, mixed>|WP_Error */
	public function bkash_complete( string $payment_id, int $booking_id ): array|WP_Error {
		$gateway = $this->gateways->get( 'bkash' );
		if ( ! $gateway instanceof BkashGateway ) {
			return new WP_Error( 'plumberslot_gateway_unavailable', '', array( 'status' => 422 ) );
		}

		$event = $gateway->complete_callback( $payment_id, $booking_id );
		if ( is_wp_error( $event ) ) {
			return $event;
		}

		return $this->apply_event( 'bkash', $event );
	}
}
