<?php
/**
 * Creates and cleans up video meetings when bookings change state.
 *
 * Meeting creation is decoupled from the booking write so a provider API
 * failure never rolls back a confirmed payment. If the meeting fails, the
 * booking stays confirmed and an admin notice appears; the appointment still runs,
 * the customer just has to use the fallback link.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain;

use PlumberSlot\Domain\Contract\MeetingBookingStore;
use PlumberSlot\Domain\Contract\TechnicianSource;
use PlumberSlot\Meetings\ProviderRegistry;
use PlumberSlot\Support\AuditLog;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class MeetingService {

	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly MeetingBookingStore $bookings,
		private readonly TechnicianSource $technicians
	) {}

	public function register(): void {
		add_action( 'plumberslot_booking_paid', array( $this, 'create_for_booking' ), 20, 1 );
		add_action( 'plumberslot_booking_created', array( $this, 'maybe_create_free' ), 20, 1 );
	}

	/**
	 * Called after a free (no-payment) booking is confirmed.
	 */
	public function maybe_create_free( int $booking_id ): void {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking || $booking->price_minor > 0 ) {
			return;
		}

		$this->create_for_booking( $booking_id );
	}

	/**
	 * Create a meeting for a just-confirmed booking.
	 *
	 * Silently skips when no provider is connected for the technician.
	 */
	public function create_for_booking( int $booking_id ): void {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return;
		}

		// Already has a meeting (e.g. created on a retry).
		if ( ! empty( $booking->meeting_ref ) ) {
			return;
		}

		$technician_id  = (int) $booking->technician_id;
		$technician_row = $this->technicians->find( $technician_id );

		if ( ! $technician_row ) {
			return;
		}

		$provider = $this->resolve_provider( (int) $technician_row->user_id );

		if ( ! $provider ) {
			return;
		}

		$title     = $this->meeting_title( $booking );
		$reference = $provider->create(
			$booking_id,
			(int) $technician_row->user_id,
			(string) $booking->start_utc,
			(int) ( $booking->end_utc ? ( strtotime( (string) $booking->end_utc ) - strtotime( (string) $booking->start_utc ) ) / 60 : 60 ),
			$title
		);

		if ( is_wp_error( $reference ) ) {
			AuditLog::record(
				'meeting.create_failed',
				'booking',
				$booking_id,
				array(
					'provider' => $provider->id(),
					'error'    => $reference->get_error_code(),
				)
			);

			return;
		}

		$stored = ProviderRegistry::reference( $provider->id(), $reference );
		$this->bookings->set_meeting_ref( $booking_id, $stored );
		AuditLog::record( 'meeting.created', 'booking', $booking_id, array( 'provider' => $provider->id() ) );
	}

	/**
	 * Resolve the first connected provider for a given technician user_id.
	 */
	private function resolve_provider( int $technician_user_id ): ?\PlumberSlot\Meetings\ProviderInterface {
		foreach ( $this->providers->all() as $provider ) {
			if ( $provider->is_connected( $technician_user_id ) ) {
				return $provider;
			}
		}

		return null;
	}

	private function meeting_title( object $booking ): string {
		$customer = get_userdata( (int) $booking->customer_id );

		return sprintf(
			/* translators: %s: customer display name. */
			__( 'Appointment with %s', 'plumberslot' ),
			$customer ? $customer->display_name : __( 'Customer', 'plumberslot' )
		);
	}
}
