<?php
/**
 * Performance gate for synchronous booking creation.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\LockRepository;
use PlumberSlot\Database\Repository\SubjectRepository;
use PlumberSlot\Database\Repository\TutorRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @group integration
 * @group performance
 */
final class BookingPerformanceTest extends WP_UnitTestCase {

	private const BUDGET_MS    = 300.0;
	private const SAMPLE_COUNT = 25;

	private int $tutor_id;
	private int $subject_id;
	private int $student_id;
	private LockRepository $locks;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		Capabilities::add_all();
		$this->empty_plumberslot_tables();

		Settings::update(
			array(
				'lead_time_minutes'        => 0,
				'slot_granularity_minutes' => 30,
				'default_lesson_minutes'   => 60,
				'buffer_minutes'           => 0,
				'auto_confirm'             => true,
			)
		);

		$tutor_user_id    = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$this->student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$this->tutor_id   = ( new TutorRepository() )->create(
			array(
				'user_id'           => $tutor_user_id,
				'slug'              => 'booking-performance-tutor',
				'display_name'      => 'Booking Performance Tutor',
				'timezone'          => 'UTC',
				'hourly_rate_minor' => 4500,
				'currency'          => 'USD',
				'status'            => 'active',
			)
		);
		$this->subject_id = ( new SubjectRepository() )->create(
			$this->tutor_id,
			array(
				'name'         => 'Performance Mathematics',
				'duration_min' => 60,
				'price_minor'  => 4500,
				'status'       => 'active',
			)
		);
		$this->locks      = new LockRepository();

		self::assertGreaterThan( 0, $this->tutor_id );
		self::assertGreaterThan( 0, $this->subject_id );

		$rules = array();
		for ( $weekday = 0; $weekday < 7; $weekday++ ) {
			$rules[] = array(
				'weekday'   => $weekday,
				'start_min' => 9 * 60,
				'end_min'   => 12 * 60,
			);
		}

		self::assertTrue( ( new AvailabilityRepository() )->replace_week( $this->tutor_id, $rules ) );
	}

	public function tear_down(): void {
		$this->clear_rate_limit();
		wp_set_current_user( 0 );
		$this->empty_plumberslot_tables();

		parent::tear_down();
	}

	public function test_booking_post_synchronous_work_p75_stays_below_three_hundred_milliseconds(): void {
		$samples     = array();
		$booking_ids = array();
		$first_day   = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )
			->modify( '+7 days' )
			->setTime( 9, 0 );

		for ( $sample = 0; $sample < self::SAMPLE_COUNT; $sample++ ) {
			$start = $first_day->modify( '+' . $sample . ' days' );
			wp_set_current_user( $this->student_id );
			$this->clear_rate_limit();

			// Holding the slot is a separate request; verification and consumption are timed below.
			$token = $this->locks->acquire(
				$this->tutor_id,
				$this->student_id,
				Time::sql( $start ),
				10
			);
			self::assertNotNull( $token );

			$started  = hrtime( true );
			$response = $this->post_booking( $start, $token );
			$elapsed  = ( hrtime( true ) - $started ) / 1_000_000;

			self::assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$data       = (array) $response->get_data();
			$booking_id = (int) ( $data['id'] ?? 0 );
			self::assertGreaterThan( 0, $booking_id );
			self::assertSame( 'pending_payment', $data['status'] ?? null );
			self::assertSame( 'unpaid', $data['payment'] ?? null );
			self::assertFalse( $this->locks->verify( $token, $this->tutor_id, $this->student_id, Time::sql( $start ) ) );

			$samples[]     = $elapsed;
			$booking_ids[] = $booking_id;
		}

		$p75 = $this->percentile( $samples, 75 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI metric output.
		fwrite(
			STDOUT,
			sprintf(
				"\nBooking POST synchronous p75: %.3fms; min: %.3fms; max: %.3fms; samples: %d\n",
				$p75,
				min( $samples ),
				max( $samples ),
				count( $samples )
			)
		);

		self::assertCount( self::SAMPLE_COUNT, $booking_ids );
		self::assertSame( self::SAMPLE_COUNT, $this->booking_count() );
		self::assertSame( self::SAMPLE_COUNT, $this->created_audit_count() );
		$this->assert_reminders_were_synchronously_scheduled( $booking_ids[0] );
		self::assertLessThan(
			self::BUDGET_MS,
			$p75,
			sprintf( 'Booking POST synchronous p75 %.3fms exceeded %.1fms.', $p75, self::BUDGET_MS )
		);
	}

	private function post_booking( DateTimeImmutable $start, string $token ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/plumberslot/v1/bookings' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'tutor_id'   => $this->tutor_id,
				'subject_id' => $this->subject_id,
				'start'      => $start->format( DATE_ATOM ),
				'timezone'   => 'UTC',
				'lock_token' => $token,
				'notes'      => 'Performance budget verification.',
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
		$key = 'plumberslot_rl_' . md5( 'create_booking|u' . $this->student_id );
		delete_transient( $key );
	}

	private function booking_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- whitelist table, isolated integration invariant.
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( Schema::BOOKINGS ) );
	}

	private function created_audit_count(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- whitelist table, isolated integration invariant.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( Schema::AUDIT ) . ' WHERE action = %s',
				'booking.created'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	private function assert_reminders_were_synchronously_scheduled( int $booking_id ): void {
		self::assertTrue( function_exists( 'as_has_scheduled_action' ) );
		self::assertNotFalse(
			as_has_scheduled_action( 'plumberslot_send_reminder', array( $booking_id, '24h' ), 'plumberslot' )
		);
		self::assertNotFalse(
			as_has_scheduled_action( 'plumberslot_send_reminder', array( $booking_id, '1h' ), 'plumberslot' )
		);
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
