<?php
/**
 * Cache boundary tests.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TutorSlot\Support\Cache;

final class CacheTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['tutorslot_test_cache']      = array();
		$GLOBALS['tutorslot_test_transients'] = array();
	}

	public function test_dashboard_aggregate_is_cached_then_invalidated_with_tutor_state(): void {
		$tutor_id = 41;
		$payload  = array(
			'today'   => array(),
			'next_up' => array( array( 'id' => 7 ) ),
		);

		self::assertNull( Cache::dashboard( $tutor_id ) );

		Cache::set_dashboard( $tutor_id, $payload );
		self::assertSame( $payload, Cache::dashboard( $tutor_id ) );

		Cache::forget_tutor( $tutor_id );
		self::assertNull( Cache::dashboard( $tutor_id ) );
	}
}
