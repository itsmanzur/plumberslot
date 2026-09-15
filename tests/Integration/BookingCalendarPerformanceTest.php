<?php
/**
 * Performance gate for calendar reads against a mature booking table.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use TutorSlot\Database\Repository\BookingRepository;
use TutorSlot\Database\Repository\TutorRepository;
use TutorSlot\Database\Schema;
use WP_UnitTestCase;

/**
 * @group integration
 * @group performance
 */
final class BookingCalendarPerformanceTest extends WP_UnitTestCase {

	private const BOOKING_COUNT = 10000;
	private const BUDGET_MS     = 500.0;
	private const SAMPLE_COUNT  = 25;
	private const WINDOW_COUNT  = 744;

	private int $tutor_id;
	private BookingRepository $bookings;
	private DateTimeImmutable $from;
	private DateTimeImmutable $to;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		$this->empty_tutorslot_tables();

		$tutor_user_id  = self::factory()->user->create();
		$student_id     = self::factory()->user->create();
		$this->tutor_id = ( new TutorRepository() )->create(
			array(
				'user_id'      => $tutor_user_id,
				'slug'         => 'calendar-performance-tutor',
				'display_name' => 'Calendar Performance Tutor',
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);
		$this->bookings = new BookingRepository();
		$this->from     = new DateTimeImmutable( '2026-01-01 00:00:00', new DateTimeZone( 'UTC' ) );
		$this->to       = $this->from->modify( '+1 month' );

		self::assertGreaterThan( 0, $this->tutor_id );
		$this->seed_bookings( $student_id );
		self::assertSame( self::BOOKING_COUNT, $this->booking_count() );
	}

	public function tear_down(): void {
		$this->empty_tutorslot_tables();
		parent::tear_down();
	}

	public function test_calendar_query_p75_stays_below_five_hundred_milliseconds_with_ten_thousand_bookings(): void {
		$from = $this->from->format( 'Y-m-d H:i:s' );
		$to   = $this->to->format( 'Y-m-d H:i:s' );

		$warm = $this->bookings->find_in_range( $this->tutor_id, $from, $to );
		self::assertCount( self::WINDOW_COUNT, $warm );
		$this->assert_calendar_query_uses_tutor_start_index();

		$samples = array();
		for ( $sample = 0; $sample < self::SAMPLE_COUNT; $sample++ ) {
			$started = hrtime( true );
			$rows    = $this->bookings->find_in_range( $this->tutor_id, $from, $to );
			$elapsed = ( hrtime( true ) - $started ) / 1_000_000;

			self::assertCount( self::WINDOW_COUNT, $rows );
			self::assertSame( '2026-01-01 00:00:00', (string) $rows[0]->start_utc );
			self::assertSame( '2026-01-31 23:00:00', (string) $rows[ self::WINDOW_COUNT - 1 ]->start_utc );
			$samples[] = $elapsed;
		}

		$p75 = $this->percentile( $samples, 75 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI metric output.
		fwrite(
			STDOUT,
			sprintf(
				"\n10,000-booking calendar query p75: %.3fms; min: %.3fms; max: %.3fms; rows/window: %d; samples: %d\n",
				$p75,
				min( $samples ),
				max( $samples ),
				self::WINDOW_COUNT,
				count( $samples )
			)
		);

		self::assertLessThan(
			self::BUDGET_MS,
			$p75,
			sprintf( '10,000-booking calendar query p75 %.3fms exceeded %.1fms.', $p75, self::BUDGET_MS )
		);
	}

	private function seed_bookings( int $student_id ): void {
		global $wpdb;

		$table       = Schema::table( Schema::BOOKINGS );
		$base        = ( new DateTimeImmutable( '2025-01-01 00:00:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$created_at  = '2025-01-01 00:00:00';
		$batch_size  = 500;
		$placeholder = '( %d, %d, %s, %s, %s, %s, %d, %s, %s, %s )';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- isolated integration fixture transaction.
		$wpdb->query( 'START TRANSACTION' );
		try {
			for ( $offset = 0; $offset < self::BOOKING_COUNT; $offset += $batch_size ) {
				$rows   = min( $batch_size, self::BOOKING_COUNT - $offset );
				$values = array();

				for ( $index = 0; $index < $rows; $index++ ) {
					$start = $base + ( ( $offset + $index ) * HOUR_IN_SECONDS );
					array_push(
						$values,
						$this->tutor_id,
						$student_id,
						gmdate( 'Y-m-d H:i:s', $start ),
						gmdate( 'Y-m-d H:i:s', $start + ( 45 * MINUTE_IN_SECONDS ) ),
						'UTC',
						'confirmed',
						4500,
						'USD',
						$created_at,
						$created_at
					);
				}

				$sql = 'INSERT INTO ' . $table
					. ' ( tutor_id, student_id, start_utc, end_utc, student_tz, status, price_minor, currency, created_at, updated_at ) VALUES '
					. implode( ', ', array_fill( 0, $rows, $placeholder ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- generated placeholders and isolated fixture values are prepared here.
				$inserted = $wpdb->query( $wpdb->prepare( $sql, $values ) );
				self::assertSame( $rows, $inserted );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- isolated integration fixture transaction.
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- isolated integration fixture transaction rollback.
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	private function assert_calendar_query_uses_tutor_start_index(): void {
		global $wpdb;

		$query = (string) $wpdb->last_query;
		self::assertStringContainsString( Schema::table( Schema::BOOKINGS ), $query );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- EXPLAIN runs the already prepared internal query captured above.
		$plan = $wpdb->get_row( 'EXPLAIN ' . $query );
		self::assertNotNull( $plan );
		self::assertSame( 'idx_tutor_end', (string) $plan->key );
		self::assertLessThan( self::BOOKING_COUNT, (int) $plan->rows );
	}

	/**
	 * @param list<float> $values Samples in milliseconds.
	 */
	private function percentile( array $values, int $percentile ): float {
		sort( $values, SORT_NUMERIC );
		$index = max( 0, (int) ceil( ( $percentile / 100 ) * count( $values ) ) - 1 );

		return $values[ $index ];
	}

	private function booking_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- whitelist table, isolated integration invariant.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( Schema::BOOKINGS ) );
	}

	private function empty_tutorslot_tables(): void {
		global $wpdb;

		foreach ( array_reverse( Schema::all_keys() ) as $key ) {
			$table = Schema::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated integration fixture tables.
			$wpdb->query( "DELETE FROM {$table}" );
		}
	}
}
