<?php
/**
 * Business rules: lead time, cancellation windows, free-estimate eligibility.
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

	public function can_be_booked( int $technician_id, DateTimeImmutable $start_utc ): bool|WP_Error {
		$lead = Settings::int( 'lead_time_minutes', 240 );

		if ( $start_utc->getTimestamp() < time() + ( $lead * MINUTE_IN_SECONDS ) ) {
			return new WP_Error(
				'plumberslot_too_late',
				sprintf(
					/* translators: %d: number of hours. */
					_n(
						'Appointments need to be booked at least %d hour ahead.',
						'Appointments need to be booked at least %d hours ahead.',
						(int) round( $lead / 60 ),
						'plumberslot'
					),
					(int) round( $lead / 60 )
				),
				array( 'status' => 422 )
			);
		}

		return apply_filters( 'plumberslot_can_be_booked', true, $technician_id, $start_utc );
	}

	public function can_reschedule( object $booking ): bool|WP_Error {
		$window = (int) Settings::int( 'reschedule_window_minutes', 720 );

		if ( strtotime( $booking->start_utc ) - time() < $window * MINUTE_IN_SECONDS ) {
			return new WP_Error(
				'plumberslot_reschedule_closed',
				__( 'This appointment is too close to its start time to move. Message your technician instead.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		return true;
	}

	/**
	 * Free estimates are one per customer, ever — matching the admin offer copy.
	 */
	public function can_use_free_estimate( int $customer_id, object $service ): bool|WP_Error {
		if ( empty( $service->is_free_estimate ) ) {
			return true;
		}

		if ( $this->bookings->customer_has_used_free_estimate( $customer_id ) ) {
			return new WP_Error(
				'plumberslot_free_estimate_used',
				__( 'This customer has already used their free estimate.', 'plumberslot' ),
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

	public function technician_timezone( int $technician_id ): string {
		$repo  = new \PlumberSlot\Database\Repository\TechnicianRepository();
		$technician = $repo->find( $technician_id );

		return $technician->timezone ?? wp_timezone_string();
	}
}
