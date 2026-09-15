<?php
/**
 * A delivery channel: email, SMS, push, WhatsApp.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Notifications\Channel;

defined( 'ABSPATH' ) || exit;

interface ChannelInterface {

	public function id(): string;

	public function is_enabled( string $event ): bool;

	/**
	 * @param string               $event   Event key.
	 * @param int                  $user_id Recipient.
	 * @param object               $booking Booking row.
	 * @param array<string, mixed> $context Extra template variables.
	 */
	public function send( string $event, int $user_id, object $booking, array $context = array() ): void;
}
