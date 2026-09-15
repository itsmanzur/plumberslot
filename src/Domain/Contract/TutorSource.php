<?php
/**
 * Tutor data needed by domain services.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Domain\Contract;

defined( 'ABSPATH' ) || exit;

interface TutorSource {

	public function find( int $id ): ?object;
}
