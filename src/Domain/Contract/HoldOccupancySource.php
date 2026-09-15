<?php
/**
 * Live hold occupancy data needed by the slot engine.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Domain\Contract;

defined( 'ABSPATH' ) || exit;

interface HoldOccupancySource {

	/**
	 * @return list<string>
	 */
	public function held_in_range( int $tutor_id, string $from_utc, string $to_utc ): array;
}
