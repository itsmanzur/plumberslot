<?php
/**
 * Pending-payment scheduling tests without a WordPress database.
 *
 * @package PlumberSlot\Tests
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PlumberSlot\Domain\PaymentService;
use PlumberSlot\Notifications\Dispatcher;
use PlumberSlot\Notifications\Scheduler;
use PlumberSlot\Support\Settings;

final class PaymentExpirySchedulerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['plumberslot_lifecycle_calls'] = array();
		$GLOBALS['plumberslot_test_settings']   = array( 'hold_window_minutes' => 12 );
		$GLOBALS['plumberslot_test_cache']      = array();

		$cache = new \ReflectionProperty( Settings::class, 'cache' );
		$cache->setValue( null, null );
	}

	public function test_schedules_one_unique_expiry_action_for_the_hold_window(): void {
		$service   = ( new \ReflectionClass( PaymentService::class ) )->newInstanceWithoutConstructor();
		$scheduler = new Scheduler( new Dispatcher(), $service );
		$before    = time();

		$scheduler->schedule_payment_expiry( 41, 73 );
		$scheduler->schedule_payment_expiry( 41, 73 );

		$actions = $GLOBALS['plumberslot_lifecycle_calls']['scheduled'];
		$this->assertCount( 1, $actions );
		$this->assertSame( 'plumberslot_expire_payment', $actions[0]['hook'] );
		$this->assertSame( array( 41, 73 ), $actions[0]['args'] );
		$this->assertSame( 'plumberslot', $actions[0]['group'] );
		$this->assertGreaterThanOrEqual( $before + ( 12 * MINUTE_IN_SECONDS ), $actions[0]['timestamp'] );
		$this->assertLessThanOrEqual( time() + ( 12 * MINUTE_IN_SECONDS ), $actions[0]['timestamp'] );
	}
}
