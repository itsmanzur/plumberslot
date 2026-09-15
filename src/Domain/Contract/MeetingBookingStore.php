<?php
/**
 * Booking data needed by the meeting service.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain\Contract;

defined( 'ABSPATH' ) || exit;

interface MeetingBookingStore {

	public function find( int $id ): ?object;

	public function set_meeting_ref( int $id, string $meeting_ref ): bool;
}
