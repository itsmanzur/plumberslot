<?php
/**
 * Cache boundary tests.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PlumberSlot\Support\Cache;

final class CacheTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['plumberslot_test_cache']      = array();
		$GLOBALS['plumberslot_test_transients'] = array();
	}

	public function test_dashboard_aggregate_is_cached_then_invalidated_with_technician_state(): void {
		$technician_id = 41;
		$payload       = array(
			'today'   => array(),
			'next_up' => array( array( 'id' => 7 ) ),
		);

		self::assertNull( Cache::dashboard( $technician_id ) );

		Cache::set_dashboard( $technician_id, $payload );
		self::assertSame( $payload, Cache::dashboard( $technician_id ) );

		Cache::forget_technician( $technician_id );
		self::assertNull( Cache::dashboard( $technician_id ) );
	}
}
