<?php
/**
 * Deposit/balance split.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PlumberSlot\Support\Deposits;

final class DepositsTest extends TestCase {

	private function service( string $deposit_type, int $deposit_value ): object {
		return (object) array(
			'deposit_type'  => $deposit_type,
			'deposit_value' => $deposit_value,
		);
	}

	public function test_none_type_charges_full_price_now(): void {
		$result = Deposits::split( $this->service( 'none', 0 ), 10000 );

		self::assertSame( 10000, $result['deposit_minor'] );
		self::assertSame( 0, $result['balance_minor'] );
	}

	public function test_null_service_charges_full_price_now(): void {
		$result = Deposits::split( null, 10000 );

		self::assertSame( 10000, $result['deposit_minor'] );
		self::assertSame( 0, $result['balance_minor'] );
	}

	public function test_zero_price_is_untouched_regardless_of_deposit_config(): void {
		$result = Deposits::split( $this->service( 'percent', 50 ), 0 );

		self::assertSame( 0, $result['deposit_minor'] );
		self::assertSame( 0, $result['balance_minor'] );
	}

	public function test_fixed_deposit_under_price(): void {
		$result = Deposits::split( $this->service( 'fixed', 2000 ), 10000 );

		self::assertSame( 2000, $result['deposit_minor'] );
		self::assertSame( 8000, $result['balance_minor'] );
	}

	public function test_fixed_deposit_never_exceeds_price(): void {
		$result = Deposits::split( $this->service( 'fixed', 15000 ), 10000 );

		self::assertSame( 10000, $result['deposit_minor'] );
		self::assertSame( 0, $result['balance_minor'] );
	}

	public function test_percent_deposit_rounds( ): void {
		$result = Deposits::split( $this->service( 'percent', 33 ), 10000 );

		self::assertSame( 3300, $result['deposit_minor'] );
		self::assertSame( 6700, $result['balance_minor'] );
	}

	public function test_percent_deposit_is_clamped_to_0_100(): void {
		$over  = Deposits::split( $this->service( 'percent', 150 ), 10000 );
		$under = Deposits::split( $this->service( 'percent', -10 ), 10000 );

		self::assertSame( 10000, $over['deposit_minor'] );
		self::assertSame( 0, $over['balance_minor'] );
		self::assertSame( 0, $under['deposit_minor'] );
		self::assertSame( 10000, $under['balance_minor'] );
	}
}
