<?php
/**
 * Technician data needed by domain services.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain\Contract;

defined( 'ABSPATH' ) || exit;

interface TechnicianSource {

	public function find( int $id ): ?object;
}
