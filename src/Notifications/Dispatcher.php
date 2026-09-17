<?php
/**
 * Turns booking events into messages.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Notifications;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Notifications\Channel\ChannelInterface;
use PlumberSlot\Notifications\Channel\EmailChannel;

defined( 'ABSPATH' ) || exit;

final class Dispatcher {

	/** @var list<ChannelInterface> */
	private array $channels;

	public function __construct(
		private readonly ?TechnicianRepository $technicians = null,
		private readonly ?ServiceRepository $services = null
	) {
		$this->channels = array( new EmailChannel() );
	}

	/**
	 * Lazily resolved, exactly like the BookingRepository below -- a bare
	 * `new Dispatcher()` (unit tests, older call sites) must not require a
	 * live $wpdb until a notification is actually dispatched.
	 */
	private function technicians(): TechnicianRepository {
		return $this->technicians ?? new TechnicianRepository();
	}

	private function services(): ServiceRepository {
		return $this->services ?? new ServiceRepository();
	}

	public function add_channel( ChannelInterface $channel ): void {
		$this->channels[] = $channel;
	}

	public function booking_created( int $booking_id ): void {
		$this->send( 'booking_created', $booking_id );
	}

	public function booking_confirmed( int $booking_id ): void {
		$this->send( 'booking_confirmed', $booking_id );
	}

	public function booking_on_the_way( int $booking_id ): void {
		$this->send( 'booking_on_the_way', $booking_id );
	}

	public function booking_completed( int $booking_id ): void {
		$this->send( 'booking_completed', $booking_id );
	}

	public function booking_cancelled( int $booking_id ): void {
		$this->send( 'booking_cancelled', $booking_id );
	}

	public function booking_rescheduled( int $new_id, int $old_id ): void {
		$this->send( 'booking_rescheduled', $new_id, array( 'previous' => $old_id ) );
	}

	public function reminder( int $booking_id, string $window ): void {
		$this->send( 'reminder_' . $window, $booking_id );
	}

	/**
	 * @param array<string, mixed> $context Extra template variables.
	 */
	private function send( string $event, int $booking_id, array $context = array() ): void {
		$repo    = new BookingRepository();
		$booking = $repo->find( $booking_id );

		if ( ! $booking ) {
			return;
		}

		$recipients = array( (int) $booking->customer_id );

		// An emergency job on creation goes to every active technician who
		// offers the service, not just whoever ends up assigned -- the point
		// is speed, not routing. A reschedule or cancellation of an
		// already-assigned emergency job still goes to that one technician
		// only; the rest of the team does not need to hear about it again.
		if ( 'booking_created' === $event && ! empty( $booking->is_emergency ) ) {
			foreach ( $this->emergency_technician_user_ids( $booking ) as $user_id ) {
				$recipients[] = $user_id;
			}
		} elseif ( in_array( $event, array( 'booking_created', 'booking_cancelled', 'reminder_24h' ), true ) ) {
			// Technician gets a copy on booking_created, booking_cancelled, reminder_24h.
			$technician_row = $this->technicians()->find( (int) $booking->technician_id );
			if ( $technician_row ) {
				$recipients[] = (int) $technician_row->user_id;
			}
		}

		/**
		 * Filter who hears about a booking event.
		 *
		 * @param list<int> $recipients WordPress user ids.
		 * @param string    $event      Event key.
		 * @param object    $booking    Booking row.
		 */
		$recipients = apply_filters( 'plumberslot_notification_recipients', $recipients, $event, $booking );

		foreach ( $this->channels as $channel ) {
			if ( ! $channel->is_enabled( $event ) ) {
				continue;
			}

			foreach ( array_unique( $recipients ) as $user_id ) {
				$channel->send( $event, $user_id, $booking, $context );
			}
		}
	}

	/**
	 * Every ACTIVE technician with an active service row matching this
	 * booking's service by name -- the same case-insensitive grouping
	 * PublicTechnicianController::aggregate_services() uses to decide who
	 * can fill an auto-assigned slot. No "on-call" concept: everyone who
	 * could plausibly take the job hears about it immediately.
	 *
	 * @return list<int> WordPress user ids.
	 */
	private function emergency_technician_user_ids( object $booking ): array {
		if ( empty( $booking->service_id ) ) {
			$technician_row = $this->technicians()->find( (int) $booking->technician_id );

			return $technician_row ? array( (int) $technician_row->user_id ) : array();
		}

		$service = $this->services()->find( (int) $booking->service_id );

		if ( ! $service ) {
			return array();
		}

		$name     = strtolower( trim( (string) $service->name ) );
		$user_ids = array();

		foreach ( $this->technicians()->all_active() as $technician ) {
			foreach ( $this->services()->all_for_technician( (int) $technician->id ) as $row ) {
				if ( 'active' === (string) $row->status && strtolower( trim( (string) $row->name ) ) === $name ) {
					$user_ids[] = (int) $technician->user_id;
					break;
				}
			}
		}

		return $user_ids;
	}
}
