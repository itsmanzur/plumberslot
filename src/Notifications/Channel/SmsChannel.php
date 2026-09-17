<?php
/**
 * SMS delivery.
 *
 * Provider-agnostic on purpose: Twilio is the default abroad, but in South Asia
 * a local aggregator is an order of magnitude cheaper, and the site owner
 * should be able to choose without a code change.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Notifications\Channel;

use PlumberSlot\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class SmsChannel implements ChannelInterface {

	public function id(): string {
		return 'sms';
	}

	public function is_enabled( string $event ): bool {
		if ( ! Settings::bool( 'sms_enabled', false ) ) {
			return false;
		}

		// SMS costs money per message: reminders only, never every status change.
		return in_array( $event, array( 'reminder_1h', 'booking_cancelled', 'booking_on_the_way' ), true );
	}

	/**
	 * @param array<string, mixed> $context Extra template variables.
	 */
	public function send( string $event, int $user_id, object $booking, array $context = array() ): void {
		$number = get_user_meta( $user_id, '_plumberslot_mobile', true );

		if ( ! is_string( $number ) || '' === $number ) {
			return;
		}

		/**
		 * Deliver one SMS.
		 *
		 * Add-ons implementing Twilio, BulkSMSBD or Vonage hook here.
		 *
		 * @param string $number  E.164 number.
		 * @param string $message Message body.
		 * @param object $booking Booking row.
		 */
		do_action( 'plumberslot_send_sms', $number, $this->message( $event, $booking ), $booking );
	}

	private function message( string $event, object $booking ): string {
		if ( 'booking_cancelled' === $event ) {
			return __( 'Your appointment has been cancelled.', 'plumberslot' );
		}

		if ( 'booking_on_the_way' === $event ) {
			return __( 'Your technician is on the way.', 'plumberslot' );
		}

		if ( 'reminder_1h' === $event ) {
			$address_line1 = trim( (string) ( $booking->address_line1 ?? '' ) );

			if ( '' === $address_line1 ) {
				return __( 'Your appointment starts in an hour.', 'plumberslot' );
			}

			$city  = trim( (string) ( $booking->address_city ?? '' ) );
			$where = '' !== $city ? sprintf( '%s, %s', $address_line1, $city ) : $address_line1;

			return sprintf(
				/* translators: %s: short job-site address (street and city). */
				__( 'Your appointment starts in an hour at %s.', 'plumberslot' ),
				$where
			);
		}

		// Unreachable given is_enabled()'s whitelist, but never fall back to
		// cancellation copy for an event this method does not recognise.
		return __( 'You have an update on your appointment.', 'plumberslot' );
	}
}
