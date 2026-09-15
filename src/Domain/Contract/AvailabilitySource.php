<?php
/**
 * Availability data needed by the slot engine.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain\Contract;

defined( 'ABSPATH' ) || exit;

interface AvailabilitySource {

	/**
	 * @return list<object>
	 */
	public function rules_for( int $tutor_id ): array;

	/**
	 * @return list<object>
	 */
	public function exceptions_between( int $tutor_id, string $from_date, string $to_date ): array;
}
