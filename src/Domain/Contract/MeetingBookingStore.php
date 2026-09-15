<?php
/**
 * Booking data needed by the meeting service.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Domain\Contract;

defined( 'ABSPATH' ) || exit;

interface MeetingBookingStore {

	public function find( int $id ): ?object;

	public function set_meeting_ref( int $id, string $meeting_ref ): bool;
}
