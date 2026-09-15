<?php
/**
 * Creating, moving and cancelling bookings.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain;

use DateTimeImmutable;
use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\LockRepository;
use PlumberSlot\Database\TransactionManager;
use PlumberSlot\Notifications\Dispatcher;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Cache;
use PlumberSlot\Support\Time;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class BookingService {

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly LockRepository $locks,
		private readonly SlotEngine $slots,
		private readonly PolicyService $policy,
		private readonly Dispatcher $notify,
		private readonly CreditService $credits,
		private readonly TransactionManager $transactions
	) {}

	/**
	 * Create one booking.
	 *
	 * A per-tutor MySQL advisory lock serializes the overlap check and insert.
	 * The locked range query prevents different starts whose lesson intervals cross.
	 *
	 * @param array{
	 *   tutor_id:int, student_id:int, parent_id:?int, subject_id:?int,
	 *   start_utc:DateTimeImmutable, duration_min:int, tutor_tz:string,
	 *   student_tz:string, price_minor:int, currency:string,
	 *   credit_id:?int, consume_credit?:bool, lock_token:?string, notes:?string
	 * } $args Booking arguments, already validated by the controller.
	 * @return int|WP_Error Booking id, or an error.
	 */
	public function create( array $args ): int|WP_Error {
		$start = $args['start_utc'];
		$end   = $start->modify( '+' . $args['duration_min'] . ' minutes' );
		$token = ! empty( $args['lock_token'] ) ? (string) $args['lock_token'] : '';

		$allowed = $this->policy->can_be_booked( $args['tutor_id'], $start );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( '' !== $token
			&& ! $this->locks->verify( $token, $args['tutor_id'], get_current_user_id(), Time::sql( $start ) ) ) {
			return new WP_Error(
				'plumberslot_lock_expired',
				__( 'That slot was only held for a few minutes and the hold has expired. Pick a time again.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		if ( ! $this->bookings->acquire_tutor_lock( $args['tutor_id'] ) ) {
			return new WP_Error(
				'plumberslot_slot_taken',
				__( 'Someone is booking with this tutor right now. Try again.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		try {
			if ( '' !== $token ) {
				$this->locks->release( $token );
			}

			if ( $this->bookings->has_overlap( $args['tutor_id'], Time::sql( $start ), Time::sql( $end ) )
				|| ! $this->slots->is_open( $args['tutor_id'], $start, $args['tutor_tz'], $args['duration_min'] ) ) {
				return new WP_Error(
					'plumberslot_slot_taken',
					__( 'That time is no longer open. Choose another slot.', 'plumberslot' ),
					array( 'status' => 409 )
				);
			}

			if ( ! $this->transactions->begin() ) {
				return $this->transaction_failed();
			}

			try {
				$id = $this->bookings->insert_unique( $this->booking_row( $args, $start, $end ) );

				if ( null === $id ) {
					$this->transactions->rollback();

					return new WP_Error(
						'plumberslot_slot_taken',
						__( 'Someone booked that time a moment ago. Choose another slot.', 'plumberslot' ),
						array( 'status' => 409 )
					);
				}

				if ( ! empty( $args['consume_credit'] ) && ! empty( $args['credit_id'] ) ) {
					$spent = $this->credits->spend( (int) $args['credit_id'], $id );

					if ( is_wp_error( $spent ) ) {
						$this->transactions->rollback();

						return $spent;
					}
				}

				if ( ! $this->transactions->commit() ) {
					$this->transactions->rollback();

					return $this->transaction_failed();
				}
			} catch ( \Throwable $error ) {
				$this->transactions->rollback();

				throw $error;
			}

			Cache::forget_tutor( $args['tutor_id'] );
		} finally {
			$this->bookings->release_tutor_lock( $args['tutor_id'] );
		}

		$this->after_booking_created( $id, $args );

		return $id;
	}

	private function transaction_failed(): WP_Error {
		return new WP_Error(
			'plumberslot_database_error',
			__( 'The booking could not be saved. Try again.', 'plumberslot' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Move a single booking. A lesson inside a series moves alone; the other
	 * eleven are untouched.
	 */
	public function reschedule( int $booking_id, DateTimeImmutable $new_start_utc ): bool|WP_Error {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return new WP_Error( 'plumberslot_not_found', '', array( 'status' => 404 ) );
		}

		if ( 'confirmed' !== (string) $booking->status ) {
			return $this->invalid_transition( __( 'Only a confirmed lesson can be rescheduled.', 'plumberslot' ) );
		}

		$window = $this->policy->can_reschedule( $booking );
		if ( is_wp_error( $window ) ) {
			return $window;
		}

		$duration = ( strtotime( $booking->end_utc ) - strtotime( $booking->start_utc ) ) / MINUTE_IN_SECONDS;
		$tutor_id = (int) $booking->tutor_id;
		$end      = $new_start_utc->modify( '+' . $duration . ' minutes' );
		$args     = array(
			'tutor_id'       => $tutor_id,
			'student_id'     => (int) $booking->student_id,
			'parent_id'      => $booking->parent_id ? (int) $booking->parent_id : null,
			'subject_id'     => $booking->subject_id ? (int) $booking->subject_id : null,
			'series_id'      => $booking->series_id ? (int) $booking->series_id : null,
			'series_index'   => $booking->series_index ? (int) $booking->series_index : null,
			'start_utc'      => $new_start_utc,
			'duration_min'   => (int) $duration,
			'tutor_tz'       => $this->policy->tutor_timezone( $tutor_id ),
			'student_tz'     => (string) $booking->student_tz,
			'price_minor'    => (int) $booking->price_minor,
			'currency'       => (string) $booking->currency,
			'credit_id'      => $booking->credit_id ? (int) $booking->credit_id : null,
			'consume_credit' => false,
			'status'         => 'confirmed',
			'payment_ref'    => $booking->payment_ref ? (string) $booking->payment_ref : null,
			'lock_token'     => null,
			'notes'          => $booking->notes,
		);

		$allowed = $this->policy->can_be_booked( $tutor_id, $new_start_utc );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! $this->bookings->acquire_tutor_lock( $tutor_id ) ) {
			return new WP_Error(
				'plumberslot_slot_taken',
				__( 'Someone is booking with this tutor right now. Try again.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		try {
			if ( $this->bookings->has_overlap( $tutor_id, Time::sql( $new_start_utc ), Time::sql( $end ), $booking_id )
				|| ! $this->slots->is_open( $tutor_id, $new_start_utc, $args['tutor_tz'], (int) $duration, $booking_id ) ) {
				return new WP_Error(
					'plumberslot_slot_taken',
					__( 'That time is no longer open. Choose another slot.', 'plumberslot' ),
					array( 'status' => 409 )
				);
			}

			if ( ! $this->transactions->begin() ) {
				return $this->transaction_failed();
			}

			try {
				$new_id = $this->bookings->insert_unique(
					$this->booking_row( $args, $new_start_utc, $end ),
					$booking_id
				);

				if ( null === $new_id ) {
					$this->transactions->rollback();

					return new WP_Error(
						'plumberslot_slot_taken',
						__( 'Someone booked that time a moment ago. Choose another slot.', 'plumberslot' ),
						array( 'status' => 409 )
					);
				}

				if ( ! $this->bookings->update_status_if_current( $booking_id, 'confirmed', 'moved' ) ) {
					$this->transactions->rollback();

					$current = $this->bookings->find( $booking_id );

					return $current && 'confirmed' === (string) $current->status
						? $this->transaction_failed()
						: $this->invalid_transition( __( 'The booking changed before it could be rescheduled.', 'plumberslot' ) );
				}

				if ( ! $this->transactions->commit() ) {
					$this->transactions->rollback();

					return $this->transaction_failed();
				}
			} catch ( \Throwable $error ) {
				$this->transactions->rollback();

				throw $error;
			}

			Cache::forget_tutor( $tutor_id );
		} finally {
			$this->bookings->release_tutor_lock( $tutor_id );
		}

		$this->after_booking_created( $new_id, $args );
		do_action( 'plumberslot_booking_moved', $booking_id, $booking, $new_id );
		AuditLog::record( 'booking.rescheduled', 'booking', $booking_id, array( 'to' => $new_id ) );
		$this->notify->booking_rescheduled( $new_id, $booking_id );

		return true;
	}

	/**
	 * @param array<string, mixed> $args Booking arguments.
	 * @return array<string, mixed>
	 */
	private function booking_row( array $args, DateTimeImmutable $start, DateTimeImmutable $end ): array {
		return array(
			'tutor_id'      => $args['tutor_id'],
			'student_id'    => $args['student_id'],
			'parent_id'     => $args['parent_id'] ?? null,
			'subject_id'    => $args['subject_id'] ?? null,
			'series_id'     => $args['series_id'] ?? null,
			'series_index'  => $args['series_index'] ?? null,
			'start_utc'     => Time::sql( $start ),
			'end_utc'       => Time::sql( $end ),
			'student_tz'    => $args['student_tz'],
			'status'        => $args['status'] ?? $this->policy->initial_status( $args ),
			'price_minor'   => $args['price_minor'],
			'currency'      => $args['currency'],
			'credit_id'     => $args['credit_id'] ?? null,
			'payment_ref'   => $args['payment_ref'] ?? null,
			'meeting_token' => bin2hex( random_bytes( 32 ) ),
			'notes'         => $args['notes'] ?? null,
		);
	}

	/**
	 * Run side effects only after the booking transaction has committed.
	 *
	 * @param array<string, mixed> $args Booking arguments.
	 */
	private function after_booking_created( int $id, array $args ): void {
		AuditLog::record( 'booking.created', 'booking', $id );

		/**
		 * Fires after a booking row exists.
		 *
		 * Meeting-link providers and payment gateways hook here.
		 *
		 * @param int                  $id   Booking id.
		 * @param array<string, mixed> $args Original arguments.
		 */
		do_action( 'plumberslot_booking_created', $id, $args );

		$this->notify->booking_created( $id );
	}

	public function cancel( int $booking_id, string $reason = '' ): bool|WP_Error {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return new WP_Error( 'plumberslot_not_found', '', array( 'status' => 404 ) );
		}

		if ( 'cancelled' === (string) $booking->status ) {
			return true;
		}

		if ( 'confirmed' !== (string) $booking->status ) {
			return $this->invalid_transition( __( 'Only a confirmed lesson can be cancelled.', 'plumberslot' ) );
		}

		if ( ! $this->transactions->begin() ) {
			return $this->transaction_failed();
		}

		try {
			if ( ! $this->bookings->update_status_if_current( $booking_id, 'confirmed', 'cancelled' ) ) {
				$this->transactions->rollback();

				$current = $this->bookings->find( $booking_id );

				if ( $current && 'cancelled' === (string) $current->status ) {
					return true;
				}

				return $current && 'confirmed' === (string) $current->status
					? $this->transaction_failed()
					: $this->invalid_transition( __( 'The booking changed before it could be cancelled.', 'plumberslot' ) );
			}

			if ( ! $this->credits->maybe_refund_on_cancel( $booking ) ) {
				$this->transactions->rollback();

				return $this->transaction_failed();
			}

			if ( ! $this->transactions->commit() ) {
				$this->transactions->rollback();

				return $this->transaction_failed();
			}
		} catch ( \Throwable $error ) {
			$this->transactions->rollback();

			throw $error;
		}

		Cache::forget_tutor( (int) $booking->tutor_id );

		AuditLog::record( 'booking.cancelled', 'booking', $booking_id, array( 'reason' => $reason ) );

		/**
		 * Fires after a booking is cancelled.
		 *
		 * @param int    $booking_id Booking id.
		 * @param object $booking    Cancelled booking record.
		 */
		do_action( 'plumberslot_booking_cancelled', $booking_id, $booking );

		$this->notify->booking_cancelled( $booking_id );

		return true;
	}

	private function invalid_transition( string $message ): WP_Error {
		return new WP_Error(
			'plumberslot_invalid_transition',
			$message,
			array( 'status' => 409 )
		);
	}

	/**
	 * Tutor marks a lesson complete or as a no-show.
	 */
	public function mark_attendance( int $booking_id, string $status ): bool|WP_Error {
		if ( ! in_array( $status, array( 'completed', 'no_show' ), true ) ) {
			return new WP_Error(
				'plumberslot_bad_status',
				__( 'Attendance must be completed or no_show.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return new WP_Error( 'plumberslot_not_found', '', array( 'status' => 404 ) );
		}

		return $this->mark_attendance_outcome( $booking, $status );
	}

	/**
	 * Record a tutor-attested outcome without allowing time or concurrent
	 * requests to manufacture an invalid lifecycle transition.
	 */
	private function mark_attendance_outcome( object $booking, string $status ): bool|WP_Error {
		$booking_id = (int) $booking->id;

		// Replaying an already-applied outcome is a successful no-op.
		if ( $status === (string) $booking->status ) {
			return true;
		}

		if ( 'confirmed' !== (string) $booking->status ) {
			return new WP_Error(
				'plumberslot_invalid_transition',
				__( 'Attendance can be recorded only for a confirmed lesson.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		if ( Time::from_sql( (string) $booking->end_utc ) > new DateTimeImmutable( 'now', Time::utc() ) ) {
			return new WP_Error(
				'plumberslot_lesson_not_ended',
				__( 'Attendance can be recorded after the lesson ends.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		if ( ! $this->bookings->update_status_if_current( $booking_id, 'confirmed', $status ) ) {
			$current = $this->bookings->find( $booking_id );

			if ( $current && $status === (string) $current->status ) {
				return true;
			}

			return new WP_Error(
				'plumberslot_invalid_transition',
				__( 'The booking changed before attendance could be recorded.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		AuditLog::record( 'booking.' . $status, 'booking', $booking_id );

		return true;
	}

	public function update_notes( int $booking_id, string $notes ): bool|WP_Error {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return new WP_Error( 'plumberslot_not_found', '', array( 'status' => 404 ) );
		}

		$this->bookings->update_notes( $booking_id, $notes );
		AuditLog::record( 'booking.notes', 'booking', $booking_id );

		return true;
	}

	public function confirm_paid( int $booking_id, string $payment_ref ): void {
		$this->bookings->set_payment_ref( $booking_id, $payment_ref );
		$this->bookings->update_status( $booking_id, 'confirmed' );

		AuditLog::record( 'booking.paid', 'booking', $booking_id, array( 'ref' => $payment_ref ) );

		do_action( 'plumberslot_booking_paid', $booking_id, $payment_ref );

		$this->notify->booking_confirmed( $booking_id );
	}

	public function mark_payment_failed( int $booking_id, string $status = 'payment_failed' ): void {
		if ( ! in_array( $status, array( 'payment_failed', 'cancelled' ), true ) ) {
			$status = 'payment_failed';
		}

		$booking = $this->bookings->find( $booking_id );
		if ( ! $booking ) {
			return;
		}

		// Do not clobber a confirmed booking.
		if ( in_array( (string) $booking->status, array( 'confirmed', 'completed', 'refunded' ), true ) ) {
			return;
		}

		$this->bookings->update_status( $booking_id, $status );
		AuditLog::record( 'booking.payment_' . $status, 'booking', $booking_id );
	}

	public function mark_refunded( int $booking_id, string $payment_ref = '' ): void {
		$this->bookings->update_status( $booking_id, 'refunded' );
		if ( '' !== $payment_ref ) {
			$this->bookings->set_payment_ref( $booking_id, $payment_ref );
		}
		AuditLog::record( 'booking.refunded', 'booking', $booking_id, array( 'ref' => $payment_ref ) );
		do_action( 'plumberslot_booking_refunded', $booking_id, $payment_ref );
	}
}
