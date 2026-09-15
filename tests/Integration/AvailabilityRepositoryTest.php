<?php
/**
 * Availability repository integration tests.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Support\Cache;
use WP_UnitTestCase;

final class AvailabilityRepositoryTest extends WP_UnitTestCase {

	private AvailabilityRepository $availability;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		$this->availability = new AvailabilityRepository();
		$this->empty_availability();
	}

	public function tear_down(): void {
		$this->empty_availability();

		parent::tear_down();
	}

	public function test_replace_week_swaps_the_full_schedule_atomically(): void {
		$technician_id = 41;

		self::assertTrue(
			$this->availability->replace_week(
				$technician_id,
				array(
					array(
						'weekday'   => 1,
						'start_min' => 540,
						'end_min'   => 720,
					),
					array(
						'weekday'   => 3,
						'start_min' => 600,
						'end_min'   => 780,
					),
				)
			)
		);

		$generation_before = Cache::generation( $technician_id );

		self::assertTrue(
			$this->availability->replace_week(
				$technician_id,
				array(
					array(
						'weekday'   => 2,
						'start_min' => 480,
						'end_min'   => 600,
					),
					array(
						'weekday'   => 2,
						'start_min' => 660,
						'end_min'   => 780,
					),
					array(
						'weekday'   => 5,
						'start_min' => 900,
						'end_min'   => 1020,
					),
				)
			)
		);

		$rules = $this->availability->rules_for( $technician_id );

		self::assertCount( 3, $rules );
		self::assertSame(
			array( 2, 2, 5 ),
			array_map( static fn ( object $rule ): int => (int) $rule->weekday, $rules )
		);
		self::assertSame(
			array( 480, 660, 900 ),
			array_map( static fn ( object $rule ): int => (int) $rule->start_min, $rules )
		);
		self::assertSame( array(), $this->availability->rules_for( 42 ) );
		self::assertSame( $generation_before + 1, Cache::generation( $technician_id ) );
	}

	public function test_replace_week_rolls_back_when_an_insert_fails(): void {
		global $wpdb;

		$technician_id = 43;

		self::assertTrue(
			$this->availability->replace_week(
				$technician_id,
				array(
					array(
						'weekday'   => 0,
						'start_min' => 600,
						'end_min'   => 720,
					),
				)
			)
		);

		$filter = static function ( $query ) {
			if ( is_string( $query ) && str_contains( $query, 'INSERT INTO' ) && str_contains( $query, Schema::AVAILABILITY ) ) {
				return 'INSERT INTO plumberslot_missing_availability (id) VALUES (1)';
			}

			return $query;
		};

		$previous_errors = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );

		try {
			$result = $this->availability->replace_week(
				$technician_id,
				array(
					array(
						'weekday'   => 4,
						'start_min' => 480,
						'end_min'   => 540,
					),
				)
			);
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous_errors );
		}

		$rules = $this->availability->rules_for( $technician_id );

		self::assertFalse( $result );
		self::assertCount( 1, $rules );
		self::assertSame( 0, (int) $rules[0]->weekday );
		self::assertSame( 600, (int) $rules[0]->start_min );
	}

	private function empty_availability(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- test cleanup of whitelist table.
		$wpdb->query( 'DELETE FROM ' . Schema::table( Schema::AVAILABILITY ) );
	}
}
