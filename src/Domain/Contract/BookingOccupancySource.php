<?php
/**
 * Booking occupancy data needed by the slot engine.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain\Contract;

defined( 'ABSPATH' ) || exit;

interface BookingOccupancySource {

	/**
	 * @return list<object>
	 */
	public function find_in_range( int $technician_id, string $from_utc, string $to_utc ): array;
}
