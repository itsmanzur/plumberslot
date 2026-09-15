<?php
/**
 * Business rules: lead time, cancellation windows, trial eligibility.
 *
 * Kept apart from BookingService so a site owner can swap the whole rule set
 * without touching the code that writes rows.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain;

use DateTimeImmutable;
use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PolicyService {

	public function __construct(
		private readonly BookingRepository $bookings = new BookingRepository()
	) {}

	public function can_be_booked( int $tutor_id, DateTimeImmutable $start_utc ): bool|WP_Error {
		$lead = Settings::int( 'lead_time_minutes', 240 );

		if ( $start_utc->getTimestamp() < time() + ( $lead * MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'plumberslot_too_late',
				sprintf(
					/* translators: %d: number of hours. */
					_n(
						'Lessons need to be booked at least %d hour ahead.',
						'Lessons need to be booked at least %d hours ahead.',
						(int) round( $lead / 60 ),
						'plumberslot'
					),
					(int) round( $lead / 60 )
				),
				array( 'status' => 422 )
			);
		}

		return apply_filters( 'plumberslot_can_be_booked', true, $tutor_id, $start_utc );
	}

	public function can_reschedule( object $booking ): bool|WP_Error {
		$window = (int) Settings::int( 'reschedule_window_minutes', 720 );

		if ( strtotime( $booking->start_utc ) - time() < $window * MINUTE_IN_SECONDS ) {
			return new WP_Error(
				'plumberslot_reschedule_closed',
				__( 'This lesson is too close to its start time to move. Message your tutor instead.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		return true;
	}

	/**
	 * Free trials are one per student, ever — matching the admin offer copy.
	 */
	public function can_use_trial( int $student_id, object $subject ): bool|WP_Error {
		if ( empty( $subject->is_trial ) ) {
			return true;
		}

		if ( $this->bookings->student_has_used_trial( $student_id ) ) {
			return new WP_Error(
				'plumberslot_trial_used',
				__( 'This student has already used their free trial lesson.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $args Booking arguments.
	 */
	public function initial_status( array $args ): string {
		if ( ! empty( $args['credit_id'] ) ) {
			return 'confirmed';
		}

		if ( 0 === (int) $args['price_minor'] ) {
			return 'confirmed';
		}

		return Settings::bool( 'auto_confirm', true ) ? 'pending_payment' : 'pending';
	}

	public function tutor_timezone( int $tutor_id ): string {
		$repo  = new \PlumberSlot\Database\Repository\TutorRepository();
		$tutor = $repo->find( $tutor_id );

		return $tutor->timezone ?? wp_timezone_string();
	}
}
