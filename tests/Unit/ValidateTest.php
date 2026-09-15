<?php
/**
 * REST validation helpers.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PlumberSlot\Support\Validate;
use WP_Error;

final class ValidateTest extends TestCase {

	public function test_sanitize_week_accepts_valid_non_overlapping_blocks(): void {
		$result = Validate::sanitize_week(
			array(
				array(
					'weekday'   => 1,
					'start_min' => 540,
					'end_min'   => 720,
				),
				array(
					'weekday'   => 1,
					'start_min' => 720,
					'end_min'   => 900,
				),
				array(
					'weekday'   => '2',
					'start_min' => '0',
					'end_min'   => '60',
				),
			)
		);

		self::assertIsArray( $result );
		self::assertCount( 3, $result );
		self::assertSame( 540, $result[0]['start_min'] );
		self::assertSame( 2, $result[2]['weekday'] );
	}

	public function test_sanitize_week_rejects_invalid_weekday_and_minutes(): void {
		$weekday = Validate::sanitize_week(
			array(
				array(
					'weekday'   => 7,
					'start_min' => 0,
					'end_min'   => 60,
				),
			)
		);
		$minutes = Validate::sanitize_week(
			array(
				array(
					'weekday'   => 0,
					'start_min' => 100,
					'end_min'   => 100,
				),
			)
		);
		$bounds = Validate::sanitize_week(
			array(
				array(
					'weekday'   => 0,
					'start_min' => 0,
					'end_min'   => 1441,
				),
			)
		);

		self::assertInstanceOf( WP_Error::class, $weekday );
		self::assertSame( 'plumberslot_invalid_week_block', $weekday->get_error_code() );
		self::assertInstanceOf( WP_Error::class, $minutes );
		self::assertInstanceOf( WP_Error::class, $bounds );
	}

	public function test_sanitize_week_rejects_same_day_overlaps(): void {
		$result = Validate::sanitize_week(
			array(
				array(
					'weekday'   => 3,
					'start_min' => 600,
					'end_min'   => 720,
				),
				array(
					'weekday'   => 3,
					'start_min' => 660,
					'end_min'   => 780,
				),
			)
		);

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'plumberslot_overlapping_week', $result->get_error_code() );
	}
}
