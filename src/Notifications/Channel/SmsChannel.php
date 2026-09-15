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
		return in_array( $event, array( 'reminder_1h', 'booking_cancelled' ), true );
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
		return 'reminder_1h' === $event
			? __( 'Your lesson starts in an hour.', 'plumberslot' )
			: __( 'Your lesson has been cancelled.', 'plumberslot' );
	}
}
