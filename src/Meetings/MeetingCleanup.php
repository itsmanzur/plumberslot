<?php
/**
 * Removes remote meetings after cancellation or rescheduling.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Meetings;

use TutorSlot\Database\Repository\BookingRepository;
use TutorSlot\Support\AuditLog;

defined( 'ABSPATH' ) || exit;

final class MeetingCleanup {

	private const ACTION       = 'tutorslot_cleanup_meeting';
	private const GROUP        = 'tutorslot';
	private const MAX_ATTEMPTS = 3;

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly ProviderRegistry $providers
	) {}

	public function register(): void {
		add_action( 'tutorslot_booking_cancelled', array( $this, 'request' ), 10, 1 );
		add_action( 'tutorslot_booking_moved', array( $this, 'request' ), 10, 1 );
		add_action( 'tutorslot_booking_refunded', array( $this, 'request' ), 10, 1 );
		add_action( self::ACTION, array( $this, 'run' ), 10, 2 );
	}

	public function request( int $booking_id ): void {
		$this->run( $booking_id, 0 );
	}

	public function run( int $booking_id, int $attempt = 0 ): void {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking || empty( $booking->meeting_ref ) ) {
			return;
		}

		$reference = (string) $booking->meeting_ref;
		$cancelled = $this->providers->cancel_reference( $reference );

		if ( ! is_wp_error( $cancelled ) && $cancelled ) {
			$this->bookings->clear_meeting_reference( $booking_id, $reference );
			AuditLog::record( 'meeting.cancelled', 'booking', $booking_id );

			return;
		}

		if ( $attempt >= self::MAX_ATTEMPTS || ! function_exists( 'as_schedule_single_action' ) ) {
			AuditLog::record(
				'meeting.cleanup_failed',
				'booking',
				$booking_id,
				array( 'attempts' => $attempt + 1 )
			);

			return;
		}

		as_schedule_single_action(
			time() + ( ( $attempt + 1 ) * 5 * MINUTE_IN_SECONDS ),
			self::ACTION,
			array( $booking_id, $attempt + 1 ),
			self::GROUP,
			true
		);
	}
}
