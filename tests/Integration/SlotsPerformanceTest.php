<?php
/**
 * Performance gates for the public slots route.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\TutorRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Support\Cache;
use PlumberSlot\Support\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @group integration
 * @group performance
 */
final class SlotsPerformanceTest extends WP_UnitTestCase {

	private const CACHED_BUDGET_MS   = 20.0;
	private const UNCACHED_BUDGET_MS = 200.0;
	private const SAMPLE_COUNT       = 25;

	private int $tutor_id;
	private string $previous_remote_addr = '';
	private DateTimeImmutable $from;
	private DateTimeImmutable $to;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		$this->empty_plumberslot_tables();
		$this->previous_remote_addr  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$_SERVER['REMOTE_ADDR']      = '127.0.0.77';
		$this->clear_rate_limit();
		wp_set_current_user( 0 );

		$user_id        = self::factory()->user->create();
		$this->tutor_id = ( new TutorRepository() )->create(
			array(
				'user_id'      => $user_id,
				'slug'         => 'slots-performance-tutor',
				'display_name' => 'Slots Performance Tutor',
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);

		self::assertGreaterThan( 0, $this->tutor_id );

		$rules = array();
		for ( $weekday = 0; $weekday < 7; $weekday++ ) {
			$rules[] = array(
				'weekday'   => $weekday,
				'start_min' => 9 * 60,
				'end_min'   => 17 * 60,
			);
		}

		self::assertTrue( ( new AvailabilityRepository() )->replace_week( $this->tutor_id, $rules ) );

		$this->from = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->modify( '+7 days' )
			->setTime( 0, 0 );
		$this->to   = $this->from->modify( '+7 days' );
	}

	public function tear_down(): void {
		$this->clear_rate_limit();
		wp_set_current_user( 0 );

		if ( '' === $this->previous_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->previous_remote_addr;
		}

		$this->empty_plumberslot_tables();
		parent::tear_down();
	}

	public function test_cached_slots_rest_response_p75_stays_below_twenty_milliseconds(): void {
		$warm = $this->request_slots();
		self::assertSame( 200, $warm->get_status() );
		self::assertNotEmpty( $warm->get_data()['slots'] ?? array() );

		$slot_queries = array();
		$query_filter = static function ( string $query ) use ( &$slot_queries ): string {
			foreach ( array( Schema::AVAILABILITY, Schema::EXCEPTIONS, Schema::BOOKINGS, Schema::LOCKS ) as $table_key ) {
				if ( str_contains( $query, Schema::table( $table_key ) ) ) {
					$slot_queries[] = $query;
				}
			}

			return $query;
		};

		add_filter( 'query', $query_filter );
		try {
			$proof = $this->request_slots();
		} finally {
			remove_filter( 'query', $query_filter );
		}

		self::assertSame( 200, $proof->get_status() );
		self::assertSame( array(), $slot_queries, 'A cached response queried slot source tables.' );

		$samples = array();
		for ( $sample = 0; $sample < self::SAMPLE_COUNT; $sample++ ) {
			$started  = hrtime( true );
			$response = $this->request_slots();
			$elapsed  = ( hrtime( true ) - $started ) / 1_000_000;

			self::assertSame( 200, $response->get_status() );
			self::assertNotEmpty( $response->get_data()['slots'] ?? array() );
			$samples[] = $elapsed;
		}

		$p75 = $this->percentile( $samples, 75 );

		fwrite(
			STDOUT,
			sprintf(
				"\nCached /slots p75: %.3fms; min: %.3fms; max: %.3fms; samples: %d\n",
				$p75,
				min( $samples ),
				max( $samples ),
				count( $samples )
			)
		);

		self::assertLessThan(
			self::CACHED_BUDGET_MS,
			$p75,
			sprintf( 'Cached /slots p75 %.3fms exceeded %.1fms.', $p75, self::CACHED_BUDGET_MS )
		);
	}

	public function test_uncached_slots_rest_response_p75_stays_below_two_hundred_milliseconds(): void {
		$slot_queries = array();
		$query_filter = static function ( string $query ) use ( &$slot_queries ): string {
			foreach ( array( Schema::AVAILABILITY, Schema::EXCEPTIONS, Schema::BOOKINGS, Schema::LOCKS ) as $table_key ) {
				if ( str_contains( $query, Schema::table( $table_key ) ) ) {
					$slot_queries[ $table_key ][] = $query;
				}
			}

			return $query;
		};

		Cache::forget_tutor( $this->tutor_id );
		add_filter( 'query', $query_filter );
		try {
			$proof = $this->request_slots();
		} finally {
			remove_filter( 'query', $query_filter );
		}

		self::assertSame( 200, $proof->get_status() );
		self::assertNotEmpty( $proof->get_data()['slots'] ?? array() );
		foreach ( array( Schema::AVAILABILITY, Schema::EXCEPTIONS, Schema::BOOKINGS, Schema::LOCKS ) as $table_key ) {
			self::assertArrayHasKey( $table_key, $slot_queries, 'An uncached response skipped ' . $table_key . '.' );
			self::assertNotEmpty( $slot_queries[ $table_key ] );
		}

		$samples = array();
		for ( $sample = 0; $sample < self::SAMPLE_COUNT; $sample++ ) {
			// Invalidation is not request work; the generation bump guarantees the next request misses cache.
			Cache::forget_tutor( $this->tutor_id );
			$started  = hrtime( true );
			$response = $this->request_slots();
			$elapsed  = ( hrtime( true ) - $started ) / 1_000_000;

			self::assertSame( 200, $response->get_status() );
			self::assertNotEmpty( $response->get_data()['slots'] ?? array() );
			$samples[] = $elapsed;
		}

		$p75 = $this->percentile( $samples, 75 );

		fwrite(
			STDOUT,
			sprintf(
				"\nUncached /slots p75: %.3fms; min: %.3fms; max: %.3fms; samples: %d\n",
				$p75,
				min( $samples ),
				max( $samples ),
				count( $samples )
			)
		);

		self::assertLessThan(
			self::UNCACHED_BUDGET_MS,
			$p75,
			sprintf( 'Uncached /slots p75 %.3fms exceeded %.1fms.', $p75, self::UNCACHED_BUDGET_MS )
		);
	}

	private function request_slots(): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/plumberslot/v1/slots' );
		$request->set_query_params(
			array(
				'tutor_id' => $this->tutor_id,
				'from'     => $this->from->format( DATE_ATOM ),
				'to'       => $this->to->format( DATE_ATOM ),
				'duration' => 60,
				'timezone' => 'UTC',
			)
		);

		$response = rest_do_request( $request );
		self::assertInstanceOf( WP_REST_Response::class, $response );

		return $response;
	}

	/**
	 * @param list<float> $values Samples in milliseconds.
	 */
	private function percentile( array $values, int $percentile ): float {
		sort( $values, SORT_NUMERIC );
		$index = max( 0, (int) ceil( ( $percentile / 100 ) * count( $values ) ) - 1 );

		return $values[ $index ];
	}

	private function clear_rate_limit(): void {
		$key = 'plumberslot_rl_' . md5( 'read_slots|i' . RateLimiter::ip_hash() );
		delete_transient( $key );
	}

	private function empty_plumberslot_tables(): void {
		global $wpdb;

		foreach ( array_reverse( Schema::all_keys() ) as $key ) {
			$table = Schema::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated integration fixture tables.
			$wpdb->query( "DELETE FROM {$table}" );
		}
	}
}
