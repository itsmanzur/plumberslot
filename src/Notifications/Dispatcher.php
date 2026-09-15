<?php
/**
 * Turns booking events into messages.
 *
 * Every message can go to two people. In tutoring the payer and the attendee
 * are usually different, and sending only to the person who clicked the button
 * is how a parent finds out about a cancelled lesson from their child.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Notifications;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Notifications\Channel\ChannelInterface;
use PlumberSlot\Notifications\Channel\EmailChannel;
use PlumberSlot\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Dispatcher {

	/** @var list<ChannelInterface> */
	private array $channels;

	public function __construct() {
		$this->channels = array( new EmailChannel() );
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
		$parent_id  = $booking->parent_id ? (int) $booking->parent_id : 0;

		// Receipts always go to the payer when a parent paid for the child.
		$is_receipt = in_array( $event, array( 'booking_created', 'booking_confirmed' ), true );
		if ( $parent_id > 0 && ( $is_receipt || Settings::bool( 'copy_parent_on_all_mail', true ) ) ) {
			$recipients[] = $parent_id;
		}

		// Reminders always reach both customer and parent when linked.
		if ( $parent_id > 0 && str_starts_with( $event, 'reminder_' ) ) {
			$recipients[] = $parent_id;
		}

		// Technician gets a copy on booking_created, booking_cancelled, reminder_24h.
		if ( in_array( $event, array( 'booking_created', 'booking_cancelled', 'reminder_24h' ), true ) ) {
			$technician_row = ( new \PlumberSlot\Database\Repository\TechnicianRepository() )->find( (int) $booking->technician_id );
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
}
