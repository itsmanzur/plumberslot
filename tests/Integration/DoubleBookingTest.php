<?php
/**
 * Booking and credit concurrency integration tests.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use TutorSlot\Database\Repository\AvailabilityRepository;
use TutorSlot\Database\Repository\BookingRepository;
use TutorSlot\Database\Repository\CreditRepository;
use TutorSlot\Database\Repository\LockRepository;
use TutorSlot\Database\Repository\SubjectRepository;
use TutorSlot\Database\Repository\TutorRepository;
use TutorSlot\Database\Schema;
use TutorSlot\Database\TransactionManager;
use TutorSlot\Domain\BookingService;
use TutorSlot\Domain\CreditService;
use TutorSlot\Domain\PaymentService;
use TutorSlot\Domain\PolicyService;
use TutorSlot\Domain\SlotEngine;
use TutorSlot\Meetings\ProviderInterface;
use TutorSlot\Meetings\ProviderRegistry;
use TutorSlot\Notifications\Dispatcher;
use TutorSlot\Support\Cache;
use TutorSlot\Support\Capabilities;
use TutorSlot\Support\AuditLog;
use TutorSlot\Support\Settings;
use TutorSlot\Support\Time;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class DoubleBookingTest extends WP_UnitTestCase {

	private BookingRepository $bookings;
	private LockRepository $locks;
	private CreditRepository $credits;
	private AvailabilityRepository $availability;
	private TutorRepository $tutors;
	private SubjectRepository $subjects;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		Capabilities::add_all();
		$this->empty_tutorslot_tables();

		$this->bookings    = new BookingRepository();
		$this->locks       = new LockRepository();
		$this->credits     = new CreditRepository();
		$this->availability = new AvailabilityRepository();
		$this->tutors      = new TutorRepository();
		$this->subjects     = new SubjectRepository();

		Settings::update(
			array(
				'lead_time_minutes'        => 0,
				'slot_granularity_minutes' => 30,
				'default_lesson_minutes'   => 60,
				'buffer_minutes'           => 0,
				'slot_cache_ttl'           => 60,
				'auto_confirm'             => true,
				'allow_student_reschedule' => false,
			)
		);

		add_filter( 'tutorslot_email_enabled', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'tutorslot_email_enabled', '__return_false' );
		$this->empty_tutorslot_tables();

		parent::tear_down();
	}

	public function test_two_inserts_on_the_same_slot_produce_one_booking(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$start      = $this->future_start( 1 );
		$data       = $this->booking_row( $tutor_id, $student_id, $start );

		$winner = $this->bookings->insert_unique( $data );
		$loser  = ( new BookingRepository() )->insert_unique( $data );

		self::assertIsInt( $winner );
		self::assertNull( $loser );
		self::assertSame( 1, $this->booking_count( $tutor_id, $start ) );
	}

	public function test_the_loser_receives_a_409_not_a_fatal(): void {
		$tutor_id   = $this->create_tutor();
		$student_a  = self::factory()->user->create();
		$student_b  = self::factory()->user->create();
		$start      = $this->future_start( 2 );

		$this->open_window( $tutor_id, $start );

		$winner = $this->service()->create( $this->booking_args( $tutor_id, $student_a, $start ) );
		$loser  = $this->service()->create( $this->booking_args( $tutor_id, $student_b, $start ) );

		self::assertIsInt( $winner );
		self::assertInstanceOf( WP_Error::class, $loser );
		self::assertSame( 'tutorslot_slot_taken', $loser->get_error_code() );
		self::assertSame( 409, $loser->get_error_data()['status'] );
		self::assertSame( 1, $this->booking_count( $tutor_id, $start ) );
	}

	public function test_an_overlapping_start_is_rejected_but_an_adjacent_start_is_allowed(): void {
		$tutor_id  = $this->create_tutor();
		$student_a = self::factory()->user->create();
		$student_b = self::factory()->user->create();
		$student_c = self::factory()->user->create();
		$start     = $this->future_start( 2 );

		$this->open_window( $tutor_id, $start );

		$winner   = $this->service()->create( $this->booking_args( $tutor_id, $student_a, $start ) );
		$overlap  = $this->service()->create( $this->booking_args( $tutor_id, $student_b, $start->modify( '+30 minutes' ) ) );
		$adjacent = $this->service()->create( $this->booking_args( $tutor_id, $student_c, $start->modify( '+60 minutes' ) ) );

		self::assertIsInt( $winner );
		self::assertInstanceOf( WP_Error::class, $overlap );
		self::assertSame( 'tutorslot_slot_taken', $overlap->get_error_code() );
		self::assertSame( 409, $overlap->get_error_data()['status'] );
		self::assertIsInt( $adjacent );
		self::assertSame( 2, $this->booking_total( $tutor_id ) );
	}

	public function test_no_overlapping_active_lessons_can_be_created_or_moved(): void {
		$tutor_id  = $this->create_tutor();
		$student_a = self::factory()->user->create();
		$student_b = self::factory()->user->create();
		$student_c = self::factory()->user->create();
		$start     = $this->future_start( 40 );

		$this->open_window( $tutor_id, $start, 240 );

		$first = $this->service()->create( $this->booking_args( $tutor_id, $student_a, $start ) );
		self::assertIsInt( $first );

		// Starts earlier and runs into the first lesson.
		$wraps_start = $this->service()->create(
			$this->booking_args( $tutor_id, $student_b, $start->modify( '-30 minutes' ), 60 )
		);
		// Contained inside the first lesson.
		$contained = $this->service()->create(
			$this->booking_args( $tutor_id, $student_b, $start->modify( '+15 minutes' ), 30 )
		);
		// Starts inside the first lesson and finishes after it.
		$extends_past = $this->service()->create(
			$this->booking_args( $tutor_id, $student_b, $start->modify( '+30 minutes' ), 60 )
		);

		self::assertInstanceOf( WP_Error::class, $wraps_start );
		self::assertInstanceOf( WP_Error::class, $contained );
		self::assertInstanceOf( WP_Error::class, $extends_past );

		$second = $this->service()->create(
			$this->booking_args( $tutor_id, $student_b, $start->modify( '+60 minutes' ) )
		);
		self::assertIsInt( $second );

		$moved_into_other = $this->service()->reschedule( $second, $start->modify( '+30 minutes' ) );
		self::assertInstanceOf( WP_Error::class, $moved_into_other );
		self::assertSame( 'tutorslot_slot_taken', $moved_into_other->get_error_code() );

		$moved_adjacent = $this->service()->reschedule( $second, $start->modify( '+120 minutes' ) );
		self::assertTrue( $moved_adjacent );

		$third = $this->service()->create(
			$this->booking_args( $tutor_id, $student_c, $start->modify( '+60 minutes' ) )
		);
		self::assertIsInt( $third );

		self::assertSame( 0, $this->active_overlap_pairs( $tutor_id ) );
		self::assertSame( 3, $this->active_booking_total( $tutor_id ) );
	}

	public function test_a_held_slot_cannot_be_booked_by_someone_else(): void {
		$tutor_id     = $this->create_tutor();
		$holder_id    = self::factory()->user->create();
		$other_id     = self::factory()->user->create();
		$start        = $this->future_start( 3 );
		$start_sql    = Time::sql( $start );

		$this->open_window( $tutor_id, $start );
		$token = $this->locks->acquire( $tutor_id, $holder_id, $start_sql, 10 );

		self::assertNotNull( $token );
		self::assertTrue( $this->locks->verify( $token, $tutor_id, $holder_id, $start_sql ) );

		wp_set_current_user( $other_id );
		$result = $this->service()->create( $this->booking_args( $tutor_id, $other_id, $start ) );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'tutorslot_slot_taken', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( 0, $this->booking_count( $tutor_id, $start ) );
		self::assertTrue( $this->locks->verify( $token, $tutor_id, $holder_id, $start_sql ) );

		wp_set_current_user( $holder_id );
	}

	public function test_an_expired_hold_releases_the_slot(): void {
		$tutor_id  = $this->create_tutor();
		$owner_id  = self::factory()->user->create();
		$start_sql = Time::sql( $this->future_start( 4 ) );
		$expired   = $this->locks->acquire( $tutor_id, $owner_id, $start_sql, -1 );

		self::assertNotNull( $expired );
		self::assertFalse( $this->locks->verify( $expired, $tutor_id, $owner_id, $start_sql ) );
		self::assertSame( 1, $this->locks->purge_expired() );

		$fresh = $this->locks->acquire( $tutor_id, $owner_id, $start_sql, 10 );

		self::assertNotNull( $fresh );
		self::assertNotSame( $expired, $fresh );
		self::assertTrue( $this->locks->verify( $fresh, $tutor_id, $owner_id, $start_sql ) );
	}

	public function test_a_hold_token_can_only_be_used_by_its_owner(): void {
		$tutor_id = $this->create_tutor();
		$holder   = self::factory()->user->create();
		$thief    = self::factory()->user->create();
		$start    = $this->future_start( 5 );
		$start_sql = Time::sql( $start );

		$this->open_window( $tutor_id, $start );
		$token = $this->locks->acquire( $tutor_id, $holder, $start_sql, 10 );
		self::assertNotNull( $token );

		wp_set_current_user( $thief );
		$stolen_args               = $this->booking_args( $tutor_id, $thief, $start );
		$stolen_args['lock_token'] = $token;
		$stolen                    = $this->service()->create( $stolen_args );

		self::assertInstanceOf( WP_Error::class, $stolen );
		self::assertSame( 'tutorslot_lock_expired', $stolen->get_error_code() );
		self::assertSame( 409, $stolen->get_error_data()['status'] );
		self::assertTrue( $this->locks->verify( $token, $tutor_id, $holder, $start_sql ) );

		wp_set_current_user( $holder );
		$owned_args               = $this->booking_args( $tutor_id, $holder, $start );
		$owned_args['lock_token'] = $token;
		$owned                    = $this->service()->create( $owned_args );

		self::assertIsInt( $owned );
		self::assertFalse( $this->locks->verify( $token, $tutor_id, $holder, $start_sql ) );
		self::assertSame( 1, $this->booking_count( $tutor_id, $start ) );
	}

	public function test_the_hold_endpoint_rejects_a_closed_time(): void {
		$tutor_id = $this->create_tutor();
		$user_id  = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start    = $this->future_start( 6 );

		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/tutorslot/v1/bookings/hold' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'tutor_id' => $tutor_id,
				'start'    => $start->format( DATE_ATOM ),
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		self::assertSame( 409, $response->get_status() );
		self::assertIsArray( $data );
		self::assertSame( 'tutorslot_slot_taken', $data['code'] );
		self::assertSame( 0, $this->lock_total( $tutor_id ) );
	}

	public function test_the_hold_endpoint_records_the_current_user_as_owner(): void {
		global $wpdb;

		$tutor_id = $this->create_tutor();
		$user_id  = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start    = $this->future_start( 7 );

		$this->open_window( $tutor_id, $start );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/tutorslot/v1/bookings/hold' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'tutor_id' => $tutor_id,
				'start'    => $start->format( DATE_ATOM ),
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		self::assertSame( 200, $response->get_status() );
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'token', $data );

		$owner_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT owner_id FROM ' . Schema::table( Schema::LOCKS ) . ' WHERE token = %s',
				$data['token']
			)
		);

		self::assertSame( $user_id, $owner_id );
		self::assertTrue(
			$this->locks->verify(
				(string) $data['token'],
				$tutor_id,
				$user_id,
				Time::sql( $start )
			)
		);
	}

	public function test_repeated_hold_abuse_is_rate_limited_per_user(): void {
		$tutor_id = $this->create_tutor();
		$abuser   = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$other    = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start    = $this->future_start( 36 );

		$this->open_window( $tutor_id, $start, 180 );

		$first = $this->post_hold( $abuser, $tutor_id, $start );

		self::assertSame( 200, $first->get_status() );

		for ( $attempt = 2; $attempt <= 10; ++$attempt ) {
			$response = $this->post_hold( $abuser, $tutor_id, $start );

			self::assertSame( 409, $response->get_status() );
		}

		$blocked = $this->post_hold( $abuser, $tutor_id, $start );
		$data    = $blocked->get_data();

		self::assertSame( 429, $blocked->get_status() );
		self::assertIsArray( $data );
		self::assertSame( 'tutorslot_too_many', $data['code'] );
		self::assertSame( 1, $this->lock_total( $tutor_id ) );

		$separate_bucket = $this->post_hold( $other, $tutor_id, $start->modify( '+60 minutes' ) );

		self::assertSame( 200, $separate_bucket->get_status() );
		self::assertSame( 2, $this->lock_total( $tutor_id ) );
	}

	public function test_the_hold_owner_can_release_their_hold(): void {
		$tutor_id = $this->create_tutor();
		$user_id  = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start    = $this->future_start( 7 );

		$this->open_window( $tutor_id, $start );
		$token = $this->locks->acquire( $tutor_id, $user_id, Time::sql( $start ), 10 );
		self::assertNotNull( $token );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'DELETE', '/tutorslot/v1/bookings/hold' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( array( 'token' => $token ) );
		$response = rest_do_request( $request );

		self::assertSame( 200, $response->get_status() );
		self::assertSame( array( 'released' => true ), $response->get_data() );
		self::assertFalse( $this->locks->verify( $token, $tutor_id, $user_id, Time::sql( $start ) ) );
	}

	public function test_another_user_cannot_release_someone_elses_hold(): void {
		$tutor_id = $this->create_tutor();
		$owner_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$other_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start    = $this->future_start( 7 );

		$this->open_window( $tutor_id, $start );
		$token = $this->locks->acquire( $tutor_id, $owner_id, Time::sql( $start ), 10 );
		self::assertNotNull( $token );
		wp_set_current_user( $other_id );

		$request = new WP_REST_Request( 'DELETE', '/tutorslot/v1/bookings/hold' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( array( 'token' => $token ) );
		$response = rest_do_request( $request );

		self::assertSame( 404, $response->get_status() );
		self::assertTrue( $this->locks->verify( $token, $tutor_id, $owner_id, Time::sql( $start ) ) );
	}

	public function test_booking_accepts_a_subject_owned_by_the_selected_tutor(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$subject_id = $this->subjects->create( $tutor_id, array( 'name' => 'Mathematics' ) );
		$start      = $this->future_start( 18 );

		$this->open_window( $tutor_id, $start );
		$response = $this->post_booking( $student_id, $tutor_id, $subject_id, $start );
		$data     = $response->get_data();

		self::assertSame( 201, $response->get_status() );
		self::assertIsArray( $data );
		self::assertArrayHasKey( 'id', $data );
		self::assertSame( $subject_id, (int) $this->bookings->find( (int) $data['id'] )->subject_id );
	}

	public function test_booking_uses_duration_from_the_subject_record(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$subject_id = $this->subjects->create(
			$tutor_id,
			array(
				'name'         => 'Extended Mathematics',
				'duration_min' => 90,
			)
		);
		$start = $this->future_start( 19 );

		$this->open_window( $tutor_id, $start, 120, 0 );
		$response = $this->post_booking( $student_id, $tutor_id, $subject_id, $start );
		$data     = $response->get_data();
		$booking  = $this->bookings->find( (int) $data['id'] );

		self::assertSame( 201, $response->get_status() );
		self::assertNotNull( $booking );
		self::assertSame( Time::sql( $start ), $booking->start_utc );
		self::assertSame( Time::sql( $start->modify( '+90 minutes' ) ), $booking->end_utc );
	}

	public function test_booking_uses_price_from_the_subject_record(): void {
		$tutor_id   = $this->create_tutor( array( 'hourly_rate_minor' => 9999 ) );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$subject_id = $this->subjects->create(
			$tutor_id,
			array(
				'name'        => 'Physics',
				'price_minor' => 4500,
			)
		);
		$start = $this->future_start( 21 );

		$this->open_window( $tutor_id, $start );
		$response = $this->post_booking( $student_id, $tutor_id, $subject_id, $start );
		$data     = $response->get_data();
		$booking  = $this->bookings->find( (int) $data['id'] );

		self::assertSame( 201, $response->get_status() );
		self::assertNotNull( $booking );
		self::assertSame( 4500, (int) $booking->price_minor );
		self::assertSame( 'unpaid', $data['payment'] );
	}

	public function test_booking_uses_currency_from_the_tutor_record(): void {
		$tutor_id   = $this->create_tutor( array( 'currency' => 'BDT' ) );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$subject_id = $this->subjects->create(
			$tutor_id,
			array(
				'name'        => 'Chemistry',
				'price_minor' => 120000,
			)
		);
		$start = $this->future_start( 23 );

		$this->open_window( $tutor_id, $start );

		wp_set_current_user( $student_id );
		$request = new WP_REST_Request( 'POST', '/tutorslot/v1/bookings' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'tutor_id'   => $tutor_id,
				'subject_id' => $subject_id,
				'start'      => $start->format( DATE_ATOM ),
				'currency'   => 'USD',
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$booking  = $this->bookings->find( (int) $data['id'] );

		self::assertSame( 201, $response->get_status() );
		self::assertNotNull( $booking );
		self::assertSame( 'BDT', $booking->currency );
	}

	public function test_booking_ignores_client_supplied_price_and_duration(): void {
		$tutor_id   = $this->create_tutor( array( 'hourly_rate_minor' => 9999 ) );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$subject_id = $this->subjects->create(
			$tutor_id,
			array(
				'name'         => 'Biology',
				'duration_min' => 45,
				'price_minor'  => 2750,
			)
		);
		$start = $this->future_start( 25 );

		$this->open_window( $tutor_id, $start, 120, 0 );

		wp_set_current_user( $student_id );
		$request = new WP_REST_Request( 'POST', '/tutorslot/v1/bookings' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'tutor_id'     => $tutor_id,
				'subject_id'   => $subject_id,
				'start'        => $start->format( DATE_ATOM ),
				'duration_min' => 15,
				'price_minor'  => 1,
				'price'        => 0.01,
			)
		);

		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$booking  = $this->bookings->find( (int) $data['id'] );

		self::assertSame( 201, $response->get_status() );
		self::assertNotNull( $booking );
		self::assertSame( 2750, (int) $booking->price_minor );
		self::assertSame( Time::sql( $start->modify( '+45 minutes' ) ), $booking->end_utc );
	}

	public function test_booking_rejects_a_subject_owned_by_another_tutor(): void {
		$tutor_id       = $this->create_tutor();
		$other_tutor_id = $this->create_tutor();
		$student_id     = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$subject_id     = $this->subjects->create( $other_tutor_id, array( 'name' => 'Physics' ) );
		$start          = $this->future_start( 20 );

		$this->open_window( $tutor_id, $start );
		$response = $this->post_booking( $student_id, $tutor_id, $subject_id, $start );
		$data     = $response->get_data();

		self::assertSame( 404, $response->get_status() );
		self::assertIsArray( $data );
		self::assertSame( 'tutorslot_not_found', $data['code'] );
		self::assertSame( 0, $this->booking_total( $tutor_id ) );
	}

	public function test_booking_hides_whether_a_requested_subject_is_missing(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start      = $this->future_start( 22 );

		$this->open_window( $tutor_id, $start );
		$response = $this->post_booking( $student_id, $tutor_id, 999999, $start );
		$data     = $response->get_data();

		self::assertSame( 404, $response->get_status() );
		self::assertIsArray( $data );
		self::assertSame( 'tutorslot_not_found', $data['code'] );
		self::assertSame( 0, $this->booking_total( $tutor_id ) );
	}

	public function test_booking_rejects_an_inactive_tutor(): void {
		$tutor_id   = $this->create_tutor( array( 'status' => 'disabled' ) );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$subject_id = $this->subjects->create( $tutor_id, array( 'name' => 'History' ) );
		$start      = $this->future_start( 24 );

		$this->open_window( $tutor_id, $start );
		$response = $this->post_booking( $student_id, $tutor_id, $subject_id, $start );
		$data     = $response->get_data();

		self::assertSame( 404, $response->get_status() );
		self::assertIsArray( $data );
		self::assertSame( 'tutorslot_not_found', $data['code'] );
		self::assertSame( 0, $this->booking_total( $tutor_id ) );
	}

	public function test_booking_rejects_an_inactive_subject(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$subject_id = $this->subjects->create(
			$tutor_id,
			array(
				'name'   => 'Geography',
				'status' => 'inactive',
			)
		);
		$start = $this->future_start( 26 );

		$this->open_window( $tutor_id, $start );
		$response = $this->post_booking( $student_id, $tutor_id, $subject_id, $start );
		$data     = $response->get_data();

		self::assertSame( 404, $response->get_status() );
		self::assertIsArray( $data );
		self::assertSame( 'tutorslot_not_found', $data['code'] );
		self::assertSame( 0, $this->booking_total( $tutor_id ) );
	}

	public function test_booking_allows_one_free_trial_per_student(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$subject_id = $this->subjects->create(
			$tutor_id,
			array(
				'name'        => 'Trial Lesson',
				'is_trial'    => 1,
				'price_minor' => 5000,
			)
		);
		$first_start  = $this->future_start( 28 );
		$second_start = $this->future_start( 30 );

		$this->open_window( $tutor_id, $first_start );
		$first = $this->post_booking( $student_id, $tutor_id, $subject_id, $first_start );
		$first_data = $first->get_data();
		$booking    = $this->bookings->find( (int) $first_data['id'] );

		self::assertSame( 201, $first->get_status() );
		self::assertNotNull( $booking );
		self::assertSame( 0, (int) $booking->price_minor );
		self::assertSame( 'free', $first_data['payment'] );

		$this->open_window( $tutor_id, $second_start );
		$second = $this->post_booking( $student_id, $tutor_id, $subject_id, $second_start );
		$data   = $second->get_data();

		self::assertSame( 409, $second->get_status() );
		self::assertIsArray( $data );
		self::assertSame( 'tutorslot_trial_used', $data['code'] );
		self::assertSame( 1, $this->booking_total( $tutor_id ) );
	}

	public function test_cookie_authenticated_booking_write_requires_nonce(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start      = $this->future_start( 32 );

		$this->open_window( $tutor_id, $start );
		wp_set_current_user( $student_id );

		$_COOKIE[ LOGGED_IN_COOKIE ] = 'integration-test';

		try {
			$request = new WP_REST_Request( 'POST', '/tutorslot/v1/bookings' );
			$request->set_body_params(
				array(
					'tutor_id' => $tutor_id,
					'start'    => $start->format( DATE_ATOM ),
				)
			);

			$response = rest_do_request( $request );
			$data     = $response->get_data();

			self::assertSame( 403, $response->get_status() );
			self::assertIsArray( $data );
			self::assertSame( 'tutorslot_bad_nonce', $data['code'] );
			self::assertSame( 0, $this->booking_total( $tutor_id ) );
		} finally {
			unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		}
	}

	public function test_an_outsider_cannot_read_or_mutate_a_booking_by_id(): void {
		$tutor_id  = $this->create_tutor();
		$student   = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$outsider  = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start     = $this->future_start( 38 );

		$this->open_window( $tutor_id, $start, 240 );
		$booking_id = $this->service()->create( $this->booking_args( $tutor_id, $student, $start ) );

		self::assertIsInt( $booking_id );

		$requests = array(
			$this->authenticated_request( $outsider, 'GET', '/tutorslot/v1/bookings/' . $booking_id ),
			$this->authenticated_request( $outsider, 'DELETE', '/tutorslot/v1/bookings/' . $booking_id ),
			$this->authenticated_request(
				$outsider,
				'POST',
				'/tutorslot/v1/bookings/' . $booking_id . '/reschedule',
				array( 'start' => $start->modify( '+2 hours' )->format( DATE_ATOM ) )
			),
			$this->authenticated_request(
				$outsider,
				'POST',
				'/tutorslot/v1/payments/start',
				array(
					'booking_id' => $booking_id,
					'gateway'    => 'stripe',
				)
			),
			$this->authenticated_request( $outsider, 'GET', '/tutorslot/v1/payments/booking/' . $booking_id ),
			$this->authenticated_request( $outsider, 'POST', '/tutorslot/v1/payments/booking/' . $booking_id . '/cancel' ),
		);

		foreach ( $requests as $response ) {
			$data = $response->get_data();

			self::assertSame( 404, $response->get_status() );
			self::assertIsArray( $data );
			self::assertSame( 'tutorslot_not_found', $data['code'] );
		}

		$missing = $this->authenticated_request( $outsider, 'GET', '/tutorslot/v1/bookings/999999999' );
		self::assertSame( $requests[0]->get_status(), $missing->get_status() );
		self::assertSame( $requests[0]->get_data()['code'], $missing->get_data()['code'] );

		$booking = $this->bookings->find( $booking_id );
		self::assertNotNull( $booking );
		self::assertSame( 'confirmed', $booking->status );
		self::assertSame( Time::sql( $start ), $booking->start_utc );
		self::assertSame( 1, $this->booking_total( $tutor_id ) );
	}

	public function test_a_tutor_cannot_mutate_another_tutors_booking_by_id(): void {
		$owner_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$owner_id   = $this->create_tutor_for_user( $owner_user );
		$other_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$this->create_tutor_for_user( $other_user );
		$student    = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start      = $this->future_start( 39 );

		$this->open_window( $owner_id, $start );
		$booking_id = $this->service()->create( $this->booking_args( $owner_id, $student, $start ) );

		self::assertIsInt( $booking_id );

		$requests = array(
			$this->authenticated_request(
				$other_user,
				'POST',
				'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
				array( 'status' => 'completed' )
			),
			$this->authenticated_request(
				$other_user,
				'POST',
				'/tutorslot/v1/bookings/' . $booking_id . '/notes',
				array( 'notes' => 'Injected by another tutor' )
			),
			$this->authenticated_request( $other_user, 'POST', '/tutorslot/v1/payments/booking/' . $booking_id . '/refund' ),
		);

		foreach ( $requests as $response ) {
			$data = $response->get_data();

			self::assertSame( 404, $response->get_status() );
			self::assertIsArray( $data );
			self::assertSame( 'tutorslot_not_found', $data['code'] );
		}

		$booking = $this->bookings->find( $booking_id );
		self::assertNotNull( $booking );
		self::assertSame( 'confirmed', $booking->status );
		self::assertNull( $booking->notes );
		self::assertNull( $booking->payment_ref );
	}

	public function test_student_reschedule_respects_the_site_setting(): void {
		$tutor_id  = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$old_start  = $this->future_start( 40 );
		$new_start  = $old_start->modify( '+1 day' );

		$this->open_window( $tutor_id, $old_start );
		$booking_id = $this->service()->create( $this->booking_args( $tutor_id, $student_id, $old_start ) );
		self::assertIsInt( $booking_id );
		$this->open_window( $tutor_id, $new_start );

		$disabled = $this->authenticated_request(
			$student_id,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/reschedule',
			array( 'start' => $new_start->format( DATE_ATOM ) )
		);

		self::assertSame( 403, $disabled->get_status() );
		self::assertSame( 'tutorslot_reschedule_disabled', $disabled->get_data()['code'] );
		self::assertSame( 'confirmed', $this->bookings->find( $booking_id )->status );
		self::assertSame( 0, $this->booking_count( $tutor_id, $new_start ) );

		Settings::update( array( 'allow_student_reschedule' => true ) );
		$enabled = $this->authenticated_request(
			$student_id,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/reschedule',
			array( 'start' => $new_start->format( DATE_ATOM ) )
		);

		self::assertSame( 200, $enabled->get_status() );
		self::assertSame( 'moved', $this->bookings->find( $booking_id )->status );
		self::assertSame( 1, $this->booking_count( $tutor_id, $new_start ) );
	}

	public function test_owner_tutor_can_reschedule_when_student_rescheduling_is_disabled(): void {
		$tutor_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$tutor_id   = $this->create_tutor_for_user( $tutor_user );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$old_start  = $this->future_start( 42 );
		$new_start  = $old_start->modify( '+1 day' );

		$this->open_window( $tutor_id, $old_start );
		$booking_id = $this->service()->create( $this->booking_args( $tutor_id, $student_id, $old_start ) );
		self::assertIsInt( $booking_id );
		$this->open_window( $tutor_id, $new_start );

		$response = $this->authenticated_request(
			$tutor_user,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/reschedule',
			array( 'start' => $new_start->format( DATE_ATOM ) )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'moved', $this->bookings->find( $booking_id )->status );
		self::assertSame( 1, $this->booking_count( $tutor_id, $new_start ) );
	}

	public function test_student_can_cancel_their_own_confirmed_booking(): void {
		$tutor_id  = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start      = $this->future_start( 44 );

		$this->open_window( $tutor_id, $start );
		$booking_id = $this->service()->create( $this->booking_args( $tutor_id, $student_id, $start ) );
		self::assertIsInt( $booking_id );

		$response = $this->authenticated_request(
			$student_id,
			'DELETE',
			'/tutorslot/v1/bookings/' . $booking_id
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'cancelled', $this->bookings->find( $booking_id )->status );
		self::assertSame( 1, $this->audit_action_count( 'booking.cancelled', $booking_id, $student_id ) );
	}

	public function test_owner_tutor_can_complete_an_ended_confirmed_booking_idempotently(): void {
		$tutor_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$tutor_id   = $this->create_tutor_for_user( $tutor_user );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start      = new DateTimeImmutable( '-2 hours', new DateTimeZone( 'UTC' ) );
		$booking_id = $this->bookings->insert_unique( $this->booking_row( $tutor_id, $student_id, $start ) );

		self::assertIsInt( $booking_id );

		$response = $this->authenticated_request(
			$tutor_user,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'completed' )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'completed', $this->bookings->find( $booking_id )->status );
		self::assertSame( 1, $this->audit_action_count( 'booking.completed', $booking_id, $tutor_user ) );

		$replay = $this->authenticated_request(
			$tutor_user,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'completed' )
		);

		self::assertSame( 200, $replay->get_status() );
		self::assertSame( 1, $this->audit_action_count( 'booking.completed', $booking_id, $tutor_user ) );
	}

	public function test_confirmed_booking_cannot_be_completed_before_lesson_end(): void {
		$tutor_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$tutor_id   = $this->create_tutor_for_user( $tutor_user );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$booking_id = $this->bookings->insert_unique(
			$this->booking_row( $tutor_id, $student_id, $this->future_start( 1 ) )
		);

		self::assertIsInt( $booking_id );

		$response = $this->authenticated_request(
			$tutor_user,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'completed' )
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'tutorslot_lesson_not_ended', $response->get_data()['code'] );
		self::assertSame( 'confirmed', $this->bookings->find( $booking_id )->status );
		self::assertSame( 0, $this->audit_action_count( 'booking.completed', $booking_id ) );
	}

	public function test_only_confirmed_booking_can_transition_to_completed(): void {
		$tutor_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$tutor_id   = $this->create_tutor_for_user( $tutor_user );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$row        = $this->booking_row(
			$tutor_id,
			$student_id,
			new DateTimeImmutable( '-2 hours', new DateTimeZone( 'UTC' ) )
		);
		$row['status'] = 'cancelled';
		$booking_id    = $this->bookings->insert_unique( $row );

		self::assertIsInt( $booking_id );

		$response = $this->authenticated_request(
			$tutor_user,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'completed' )
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'tutorslot_invalid_transition', $response->get_data()['code'] );
		self::assertSame( 'cancelled', $this->bookings->find( $booking_id )->status );
		self::assertSame( 0, $this->audit_action_count( 'booking.completed', $booking_id ) );
	}

	public function test_site_manager_can_complete_an_ended_confirmed_booking(): void {
		$manager_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start      = new DateTimeImmutable( '-2 hours', new DateTimeZone( 'UTC' ) );
		$booking_id = $this->bookings->insert_unique( $this->booking_row( $tutor_id, $student_id, $start ) );

		self::assertIsInt( $booking_id );

		$response = $this->authenticated_request(
			$manager_id,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'completed' )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'completed', $this->bookings->find( $booking_id )->status );
		self::assertSame( 1, $this->audit_action_count( 'booking.completed', $booking_id, $manager_id ) );
	}

	public function test_owner_tutor_can_mark_an_ended_confirmed_booking_no_show_idempotently(): void {
		$tutor_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$tutor_id   = $this->create_tutor_for_user( $tutor_user );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start      = new DateTimeImmutable( '-2 hours', new DateTimeZone( 'UTC' ) );
		$booking_id = $this->bookings->insert_unique( $this->booking_row( $tutor_id, $student_id, $start ) );

		self::assertIsInt( $booking_id );

		$response = $this->authenticated_request(
			$tutor_user,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'no_show' )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'no_show', $this->bookings->find( $booking_id )->status );
		self::assertSame( 1, $this->audit_action_count( 'booking.no_show', $booking_id, $tutor_user ) );

		$replay = $this->authenticated_request(
			$tutor_user,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'no_show' )
		);

		self::assertSame( 200, $replay->get_status() );
		self::assertSame( 1, $this->audit_action_count( 'booking.no_show', $booking_id, $tutor_user ) );
	}

	public function test_confirmed_booking_cannot_be_marked_no_show_before_lesson_end(): void {
		$tutor_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$tutor_id   = $this->create_tutor_for_user( $tutor_user );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$booking_id = $this->bookings->insert_unique(
			$this->booking_row( $tutor_id, $student_id, $this->future_start( 1 ) )
		);

		self::assertIsInt( $booking_id );

		$response = $this->authenticated_request(
			$tutor_user,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'no_show' )
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'tutorslot_lesson_not_ended', $response->get_data()['code'] );
		self::assertSame( 'confirmed', $this->bookings->find( $booking_id )->status );
		self::assertSame( 0, $this->audit_action_count( 'booking.no_show', $booking_id ) );
	}

	public function test_completed_booking_cannot_transition_to_no_show(): void {
		$tutor_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$tutor_id   = $this->create_tutor_for_user( $tutor_user );
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$row        = $this->booking_row(
			$tutor_id,
			$student_id,
			new DateTimeImmutable( '-2 hours', new DateTimeZone( 'UTC' ) )
		);
		$row['status'] = 'completed';
		$booking_id    = $this->bookings->insert_unique( $row );

		self::assertIsInt( $booking_id );

		$response = $this->authenticated_request(
			$tutor_user,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'no_show' )
		);

		self::assertSame( 409, $response->get_status() );
		self::assertSame( 'tutorslot_invalid_transition', $response->get_data()['code'] );
		self::assertSame( 'completed', $this->bookings->find( $booking_id )->status );
		self::assertSame( 0, $this->audit_action_count( 'booking.no_show', $booking_id ) );
	}

	public function test_site_manager_can_mark_an_ended_confirmed_booking_no_show(): void {
		$manager_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start      = new DateTimeImmutable( '-2 hours', new DateTimeZone( 'UTC' ) );
		$booking_id = $this->bookings->insert_unique( $this->booking_row( $tutor_id, $student_id, $start ) );

		self::assertIsInt( $booking_id );

		$response = $this->authenticated_request(
			$manager_id,
			'POST',
			'/tutorslot/v1/bookings/' . $booking_id . '/attendance',
			array( 'status' => 'no_show' )
		);

		self::assertSame( 200, $response->get_status() );
		self::assertSame( 'no_show', $this->bookings->find( $booking_id )->status );
		self::assertSame( 1, $this->audit_action_count( 'booking.no_show', $booking_id, $manager_id ) );
	}

	public function test_closed_lifecycle_states_cannot_be_changed_by_booking_operations(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$statuses   = array( 'completed', 'no_show', 'moved', 'cancelled', 'refunded', 'payment_expired' );
		$service    = $this->service();

		foreach ( $statuses as $offset => $status ) {
			$start         = new DateTimeImmutable( '-' . ( $offset + 2 ) . ' days 10:00:00', new DateTimeZone( 'UTC' ) );
			$row           = $this->booking_row( $tutor_id, $student_id, $start );
			$row['status'] = $status;
			$booking_id    = $this->bookings->insert_unique( $row );

			self::assertIsInt( $booking_id, 'Fixture failed for ' . $status );

			foreach ( array( 'completed', 'no_show' ) as $outcome ) {
				$attendance = $service->mark_attendance( $booking_id, $outcome );

				if ( $status === $outcome ) {
					self::assertTrue( $attendance, $status . ' replay must be idempotent' );
				} else {
					self::assertInstanceOf( WP_Error::class, $attendance, $status . ' must reject ' . $outcome );
					self::assertSame( 'tutorslot_invalid_transition', $attendance->get_error_code() );
				}
			}

			$cancelled = $service->cancel( $booking_id );
			if ( 'cancelled' === $status ) {
				self::assertTrue( $cancelled, 'cancelled replay must be idempotent' );
			} else {
				self::assertInstanceOf( WP_Error::class, $cancelled, $status . ' must reject cancellation' );
				self::assertSame( 'tutorslot_invalid_transition', $cancelled->get_error_code() );
			}

			$moved = $service->reschedule( $booking_id, $start->modify( '+30 days' ) );
			self::assertInstanceOf( WP_Error::class, $moved, $status . ' must reject rescheduling' );
			self::assertSame( 'tutorslot_invalid_transition', $moved->get_error_code() );
			self::assertSame( $status, $this->bookings->find( $booking_id )->status );
		}
	}

	public function test_bookings_list_scopes_are_isolated(): void {
		$tutor_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$tutor_id   = $this->tutors->create(
			array(
				'user_id'      => $tutor_user,
				'slug'         => 'tutor-scope-' . $tutor_user,
				'display_name' => 'Scope Tutor',
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);
		$parent_id  = self::factory()->user->create( array( 'role' => Capabilities::ROLE_PARENT ) );
		$child_id   = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$other_id   = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start      = $this->future_start( 34 );

		Capabilities::add_all();
		$this->insert_relation( $parent_id, $child_id, true );
		$this->insert_relation( $parent_id, $other_id, false );

		$this->open_window( $tutor_id, $start );
		$child_booking = $this->service()->create( $this->booking_args( $tutor_id, $child_id, $start ) );
		$other_booking = $this->service()->create(
			$this->booking_args( $tutor_id, $other_id, $start->modify( '+1 hour' ) )
		);

		self::assertIsInt( $child_booking );
		self::assertIsInt( $other_booking );

		$mine = $this->list_bookings( $child_id, 'mine' );
		self::assertSame( 200, $mine->get_status() );
		self::assertSame( array( $child_booking ), $this->booking_ids( $mine ) );

		$family = $this->list_bookings( $parent_id, 'family' );
		self::assertSame( 200, $family->get_status() );
		self::assertSame( array( $child_booking ), $this->booking_ids( $family ) );

		$teaching = $this->list_bookings( $tutor_user, 'teaching' );
		self::assertSame( 200, $teaching->get_status() );
		self::assertSame( array( $child_booking, $other_booking ), $this->booking_ids( $teaching ) );

		$paged = $this->list_bookings(
			$tutor_user,
			'teaching',
			array(
				'per_page' => 1,
				'page'     => 2,
				'status'   => 'confirmed',
			)
		);
		$paged_data = $paged->get_data();

		self::assertSame( 200, $paged->get_status() );
		self::assertIsArray( $paged_data );
		self::assertSame( 2, (int) $paged_data['total'] );
		self::assertSame( 2, (int) $paged_data['page'] );
		self::assertSame( array( $other_booking ), $this->booking_ids( $paged ) );

		$stranger = $this->list_bookings( $other_id, 'family' );
		self::assertSame( array(), $this->booking_ids( $stranger ) );
	}

	public function test_booking_filters_remain_values_and_table_keys_are_whitelisted(): void {
		$tutor_id  = $this->create_tutor();
		$student   = self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) );
		$start     = $this->future_start( 41 );

		$this->open_window( $tutor_id, $start, 180 );
		$booking_id = $this->service()->create( $this->booking_args( $tutor_id, $student, $start ) );

		self::assertIsInt( $booking_id );

		$injected = $this->bookings->find_for_student(
			$student,
			array( 'status' => "confirmed' OR 1=1 --" )
		);
		$normal   = $this->bookings->find_for_student( $student );

		self::assertSame( 0, $injected['total'] );
		self::assertSame( array(), $injected['items'] );
		self::assertSame( 1, $normal['total'] );
		self::assertSame( $booking_id, (int) $normal['items'][0]->id );
		self::assertSame( 1, $this->booking_total( $tutor_id ) );

		$this->expectException( \InvalidArgumentException::class );
		Schema::table( 'tutorslot_bookings; DROP TABLE wp_users' );
	}

	public function test_booking_csv_export_records_the_privileged_read(): void {
		$tutor_user = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) );
		$this->create_tutor_for_user( $tutor_user );
		wp_set_current_user( $tutor_user );

		$request = new WP_REST_Request( 'GET', '/tutorslot/v1/bookings/export' );
		$request->set_param( 'scope', 'teaching' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$event = null;
		foreach ( AuditLog::recent( 20 ) as $row ) {
			if ( 'bookings.exported' === (string) $row->action ) {
				$event = $row;
				break;
			}
		}

		$this->assertNotNull( $event );
		$this->assertSame( $tutor_user, (int) $event->actor_id );
		$this->assertSame( $tutor_user, (int) $event->object_id );
		$this->assertSame(
			array(
				'count' => 0,
				'scope' => 'teaching',
			),
			json_decode( (string) $event->meta, true )
		);
	}

	public function test_a_credit_cannot_be_overdrawn_by_competing_consumers(): void {
		global $wpdb;

		$tutor_id = $this->create_tutor();
		$owner_id = self::factory()->user->create();
		$table     = Schema::table( Schema::CREDITS );

		$wpdb->insert(
			$table,
			array(
				'owner_id'   => $owner_id,
				'tutor_id'   => $tutor_id,
				'total'      => 1,
				'used'       => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%d', '%s' )
		);

		$credit_id = (int) $wpdb->insert_id;
		$first     = $this->credits->consume_one( $credit_id );
		$second    = ( new CreditRepository() )->consume_one( $credit_id );
		$used      = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT used FROM ' . $table . ' WHERE id = %d', $credit_id )
		);

		self::assertTrue( $first );
		self::assertFalse( $second );
		self::assertSame( 1, $used );
	}

	public function test_booking_creation_and_credit_spend_commit_together(): void {
		$tutor_id  = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$start      = $this->future_start( 8 );
		$credit_id  = $this->create_credit( $student_id, $tutor_id, 1, 0 );

		$this->open_window( $tutor_id, $start );
		wp_set_current_user( $student_id );

		$args                   = $this->booking_args( $tutor_id, $student_id, $start );
		$args['credit_id']      = $credit_id;
		$args['consume_credit'] = true;
		$result                 = $this->service()->create( $args );

		self::assertIsInt( $result );
		self::assertSame( 1, $this->booking_count( $tutor_id, $start ) );
		self::assertSame( $credit_id, (int) $this->bookings->find( $result )->credit_id );
		self::assertSame( 1, $this->credit_used( $credit_id ) );
	}

	public function test_credit_failure_rolls_back_the_booking_insert(): void {
		$tutor_id  = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$start      = $this->future_start( 9 );
		$credit_id  = $this->create_credit( $student_id, $tutor_id, 1, 1 );

		$this->open_window( $tutor_id, $start );
		wp_set_current_user( $student_id );

		$args                   = $this->booking_args( $tutor_id, $student_id, $start );
		$args['credit_id']      = $credit_id;
		$args['consume_credit'] = true;
		$result                 = $this->service()->create( $args );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'tutorslot_no_credits', $result->get_error_code() );
		self::assertSame( 409, $result->get_error_data()['status'] );
		self::assertSame( 0, $this->booking_count( $tutor_id, $start ) );
		self::assertSame( 1, $this->credit_used( $credit_id ) );
	}

	public function test_reschedule_creation_and_moved_transition_commit_together(): void {
		$tutor_id  = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$old_start  = $this->future_start( 10 );
		$new_start  = $old_start->modify( '+1 day' );

		$this->open_window( $tutor_id, $old_start );
		$booking_id = $this->service()->create( $this->booking_args( $tutor_id, $student_id, $old_start ) );
		self::assertIsInt( $booking_id );

		$this->open_window( $tutor_id, $new_start );
		$result = $this->service()->reschedule( $booking_id, $new_start );

		self::assertTrue( $result );
		self::assertSame( 'moved', $this->bookings->find( $booking_id )->status );
		self::assertSame( 1, $this->booking_count( $tutor_id, $new_start ) );
		self::assertSame( 2, $this->booking_total( $tutor_id ) );
	}

	public function test_reschedule_preserves_confirmed_payment_identity(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$old_start  = $this->future_start( 11 );
		$new_start  = $old_start->modify( '+1 day' );
		$row        = $this->booking_row( $tutor_id, $student_id, $old_start );
		$row['price_minor'] = 7500;
		$row['payment_ref'] = 'paid-before-reschedule';
		$booking_id         = $this->bookings->insert_unique( $row );

		self::assertIsInt( $booking_id );
		$this->open_window( $tutor_id, $new_start );
		self::assertTrue( $this->service()->reschedule( $booking_id, $new_start ) );

		$new_id  = $this->booking_id_at( $tutor_id, $new_start );
		$booking = $this->bookings->find( $new_id );

		self::assertGreaterThan( 0, $new_id );
		self::assertNotNull( $booking );
		self::assertSame( 'confirmed', $booking->status );
		self::assertSame( 'paid-before-reschedule', $booking->payment_ref );
		self::assertSame( 7500, (int) $booking->price_minor );
	}

	public function test_failed_moved_transition_rolls_back_the_rescheduled_booking(): void {
		global $wpdb;

		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$old_start  = $this->future_start( 12 );
		$new_start  = $old_start->modify( '+1 day' );

		$this->open_window( $tutor_id, $old_start );
		$booking_id = $this->service()->create( $this->booking_args( $tutor_id, $student_id, $old_start ) );
		self::assertIsInt( $booking_id );

		$this->open_window( $tutor_id, $new_start );

		$previous_errors = $wpdb->suppress_errors( true );
		$table           = Schema::table( Schema::BOOKINGS );
		$fail_moved      = static function ( string $query ) use ( $table ): string {
			if ( str_contains( $query, "UPDATE {$table} SET status = 'moved'" ) ) {
				return 'UPDATE `tutorslot_missing_table` SET `status` = \'moved\'';
			}

			return $query;
		};
		add_filter( 'query', $fail_moved );

		try {
			$result = $this->service()->reschedule( $booking_id, $new_start );
		} finally {
			remove_filter( 'query', $fail_moved );
			$wpdb->suppress_errors( $previous_errors );
		}

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'tutorslot_database_error', $result->get_error_code() );
		self::assertSame( 500, $result->get_error_data()['status'] );
		self::assertSame( 'confirmed', $this->bookings->find( $booking_id )->status );
		self::assertSame( 0, $this->booking_count( $tutor_id, $new_start ) );
		self::assertSame( 1, $this->booking_total( $tutor_id ) );
	}

	public function test_only_confirmed_bookings_can_be_moved_or_cancelled(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$old_start  = $this->future_start( 13 );
		$new_start  = $old_start->modify( '+1 day' );
		$row        = $this->booking_row( $tutor_id, $student_id, $old_start );
		$row['status'] = 'completed';
		$booking_id    = $this->bookings->insert_unique( $row );

		self::assertIsInt( $booking_id );
		$this->open_window( $tutor_id, $new_start );

		$moved = $this->service()->reschedule( $booking_id, $new_start );
		self::assertInstanceOf( WP_Error::class, $moved );
		self::assertSame( 'tutorslot_invalid_transition', $moved->get_error_code() );
		self::assertSame( 0, $this->booking_count( $tutor_id, $new_start ) );

		$cancelled = $this->service()->cancel( $booking_id );
		self::assertInstanceOf( WP_Error::class, $cancelled );
		self::assertSame( 'tutorslot_invalid_transition', $cancelled->get_error_code() );
		self::assertSame( 'completed', $this->bookings->find( $booking_id )->status );
		self::assertSame( 0, $this->audit_action_count( 'booking.cancelled', $booking_id ) );
	}

	public function test_confirmed_credit_booking_cancellation_is_atomic_and_idempotent(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$start      = $this->future_start( 15 );
		$credit_id  = $this->create_credit( $student_id, $tutor_id, 1, 0 );

		$this->open_window( $tutor_id, $start );
		$args                   = $this->booking_args( $tutor_id, $student_id, $start );
		$args['credit_id']      = $credit_id;
		$args['consume_credit'] = true;
		$booking_id             = $this->service()->create( $args );

		self::assertIsInt( $booking_id );
		self::assertSame( 1, $this->credit_used( $credit_id ) );
		self::assertTrue( $this->service()->cancel( $booking_id ) );
		self::assertSame( 'cancelled', $this->bookings->find( $booking_id )->status );
		self::assertSame( 0, $this->credit_used( $credit_id ) );
		self::assertSame( 1, $this->audit_action_count( 'booking.cancelled', $booking_id ) );
		self::assertSame( 1, $this->audit_action_count( 'credit.refunded', $credit_id ) );

		self::assertTrue( $this->service()->cancel( $booking_id ) );
		self::assertSame( 0, $this->credit_used( $credit_id ) );
		self::assertSame( 1, $this->audit_action_count( 'booking.cancelled', $booking_id ) );
		self::assertSame( 1, $this->audit_action_count( 'credit.refunded', $credit_id ) );
	}

	public function test_credit_refund_failure_rolls_back_cancellation(): void {
		global $wpdb;

		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$start      = $this->future_start( 17 );
		$credit_id  = $this->create_credit( $student_id, $tutor_id, 1, 0 );

		$this->open_window( $tutor_id, $start );
		$args                   = $this->booking_args( $tutor_id, $student_id, $start );
		$args['credit_id']      = $credit_id;
		$args['consume_credit'] = true;
		$booking_id             = $this->service()->create( $args );
		self::assertIsInt( $booking_id );

		$previous_errors = $wpdb->suppress_errors( true );
		$credits_table   = Schema::table( Schema::CREDITS );
		$fail_refund     = static function ( string $query ) use ( $credits_table ): string {
			if ( str_contains( $query, "UPDATE {$credits_table} SET used = used - 1" ) ) {
				return 'UPDATE `tutorslot_missing_table` SET `used` = 0';
			}

			return $query;
		};
		add_filter( 'query', $fail_refund );

		try {
			$result = $this->service()->cancel( $booking_id );
		} finally {
			remove_filter( 'query', $fail_refund );
			$wpdb->suppress_errors( $previous_errors );
		}

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'tutorslot_database_error', $result->get_error_code() );
		self::assertSame( 'confirmed', $this->bookings->find( $booking_id )->status );
		self::assertSame( 1, $this->credit_used( $credit_id ) );
		self::assertSame( 0, $this->audit_action_count( 'booking.cancelled', $booking_id ) );
		self::assertSame( 0, $this->audit_action_count( 'credit.refunded', $credit_id ) );
	}

	public function test_cancel_unschedules_reminders_and_cleans_the_meeting(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$start      = $this->future_start( 14 );
		$provider   = $this->fake_meeting_provider();
		$filter     = $this->register_meeting_provider( $provider );

		try {
			$this->open_window( $tutor_id, $start );
			$booking_id = $this->service()->create( $this->booking_args( $tutor_id, $student_id, $start ) );
			self::assertIsInt( $booking_id );

			$reference = ProviderRegistry::reference( 'integration_test', 'cancel-me' );
			$this->set_meeting_reference( $booking_id, $reference );
			$generation = Cache::generation( $tutor_id );

			self::assertTrue( $this->reminder_is_scheduled( $booking_id, '24h' ) );
			self::assertTrue( $this->reminder_is_scheduled( $booking_id, '1h' ) );

			$result = $this->service()->cancel( $booking_id );

			self::assertTrue( $result );
			self::assertSame( array( 'cancel-me' ), $provider->cancelled );
			self::assertNull( $this->bookings->find( $booking_id )->meeting_ref );
			self::assertFalse( $this->reminder_is_scheduled( $booking_id, '24h' ) );
			self::assertFalse( $this->reminder_is_scheduled( $booking_id, '1h' ) );
			self::assertGreaterThan( $generation, Cache::generation( $tutor_id ) );
			self::assertSame( 1, $this->audit_action_count( 'booking.cancelled', $booking_id ) );
			self::assertSame( 1, $this->audit_action_count( 'meeting.cancelled', $booking_id ) );

			self::assertTrue( $this->service()->cancel( $booking_id ) );
			self::assertSame( array( 'cancel-me' ), $provider->cancelled );
			self::assertSame( 1, $this->audit_action_count( 'booking.cancelled', $booking_id ) );
			self::assertSame( 1, $this->audit_action_count( 'meeting.cancelled', $booking_id ) );
		} finally {
			remove_filter( 'tutorslot_meeting_providers', $filter );
		}
	}

	public function test_reschedule_replaces_reminders_and_cleans_the_old_meeting(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$old_start  = $this->future_start( 16 );
		$new_start  = $old_start->modify( '+1 day' );
		$provider   = $this->fake_meeting_provider();
		$filter     = $this->register_meeting_provider( $provider );

		try {
			$this->open_window( $tutor_id, $old_start );
			$old_id = $this->service()->create( $this->booking_args( $tutor_id, $student_id, $old_start ) );
			self::assertIsInt( $old_id );

			$reference = ProviderRegistry::reference( 'integration_test', 'move-me' );
			$this->set_meeting_reference( $old_id, $reference );
			$generation = Cache::generation( $tutor_id );

			$this->open_window( $tutor_id, $new_start );
			$result = $this->service()->reschedule( $old_id, $new_start );
			$new_id = $this->booking_id_at( $tutor_id, $new_start );

			self::assertTrue( $result );
			self::assertGreaterThan( 0, $new_id );
			self::assertSame( array( 'move-me' ), $provider->cancelled );
			self::assertNull( $this->bookings->find( $old_id )->meeting_ref );
			self::assertFalse( $this->reminder_is_scheduled( $old_id, '24h' ) );
			self::assertFalse( $this->reminder_is_scheduled( $old_id, '1h' ) );
			self::assertTrue( $this->reminder_is_scheduled( $new_id, '24h' ) );
			self::assertTrue( $this->reminder_is_scheduled( $new_id, '1h' ) );
			self::assertGreaterThan( $generation, Cache::generation( $tutor_id ) );
			self::assertSame( 1, $this->audit_action_count( 'booking.rescheduled', $old_id ) );
			self::assertSame( 1, $this->audit_action_count( 'meeting.cancelled', $old_id ) );
		} finally {
			remove_filter( 'tutorslot_meeting_providers', $filter );
		}
	}

	public function test_refund_unschedules_reminders_cleans_meeting_and_audits_once(): void {
		$tutor_id   = $this->create_tutor();
		$student_id = self::factory()->user->create();
		$start      = $this->future_start( 18 );
		$credit_id  = $this->create_credit( $student_id, $tutor_id, 1, 0 );
		$provider   = $this->fake_meeting_provider();
		$filter     = $this->register_meeting_provider( $provider );

		try {
			$this->open_window( $tutor_id, $start );
			$args                   = $this->booking_args( $tutor_id, $student_id, $start );
			$args['credit_id']      = $credit_id;
			$args['consume_credit'] = true;
			$booking_id             = $this->service()->create( $args );

			self::assertIsInt( $booking_id );
			$this->set_meeting_reference( $booking_id, ProviderRegistry::reference( 'integration_test', 'refund-me' ) );
			self::assertTrue( $this->bookings->update_status_if_current( $booking_id, 'confirmed', 'completed' ) );
			self::assertTrue( $this->reminder_is_scheduled( $booking_id, '24h' ) );
			self::assertTrue( $this->reminder_is_scheduled( $booking_id, '1h' ) );

			$payments = \TutorSlot\Plugin::instance()->container()->get( PaymentService::class );
			$result   = $payments->refund_booking( $booking_id );

			self::assertIsArray( $result );
			self::assertTrue( $result['refunded'] );
			self::assertSame( array( 'refund-me' ), $provider->cancelled );
			self::assertNull( $this->bookings->find( $booking_id )->meeting_ref );
			self::assertFalse( $this->reminder_is_scheduled( $booking_id, '24h' ) );
			self::assertFalse( $this->reminder_is_scheduled( $booking_id, '1h' ) );
			self::assertSame( 1, $this->audit_action_count( 'booking.refunded', $booking_id ) );
			self::assertSame( 1, $this->audit_action_count( 'meeting.cancelled', $booking_id ) );

			$replay = $payments->refund_booking( $booking_id );
			self::assertIsArray( $replay );
			self::assertSame( array( 'refund-me' ), $provider->cancelled );
			self::assertSame( 1, $this->audit_action_count( 'booking.refunded', $booking_id ) );
			self::assertSame( 1, $this->audit_action_count( 'meeting.cancelled', $booking_id ) );
		} finally {
			remove_filter( 'tutorslot_meeting_providers', $filter );
		}
	}

	private function service(): BookingService {
		return new BookingService(
			new BookingRepository(),
			new LockRepository(),
			new SlotEngine(
				new AvailabilityRepository(),
				new BookingRepository(),
				new LockRepository()
			),
			new PolicyService( new BookingRepository() ),
			new Dispatcher(),
			new CreditService( new CreditRepository() ),
			new TransactionManager()
		);
	}

	/**
	 * @param array<string, mixed> $overrides Extra tutor column values.
	 */
	private function create_tutor( array $overrides = array() ): int {
		$user_id = self::factory()->user->create();

		return $this->create_tutor_for_user( $user_id, $overrides );
	}

	/**
	 * @param array<string, mixed> $overrides Extra tutor column values.
	 */
	private function create_tutor_for_user( int $user_id, array $overrides = array() ): int {
		return $this->tutors->create(
			array_merge(
				array(
					'user_id'      => $user_id,
					'slug'         => 'tutor-' . $user_id,
					'display_name' => 'Tutor ' . $user_id,
					'timezone'     => 'UTC',
					'status'       => 'active',
				),
				$overrides
			)
		);
	}

	private function open_window(
		int $tutor_id,
		DateTimeImmutable $start,
		int $length_min = 120,
		int $lead_min = 60
	): void {
		$start_minute = ( (int) $start->format( 'H' ) * 60 ) + (int) $start->format( 'i' );

		$this->availability->replace_week(
			$tutor_id,
			array(
				array(
					'weekday'   => (int) $start->format( 'w' ),
					'start_min' => max( 0, $start_minute - $lead_min ),
					'end_min'   => min( 1440, $start_minute + $length_min ),
				),
			)
		);
		Cache::forget_tutor( $tutor_id );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function booking_row( int $tutor_id, int $student_id, DateTimeImmutable $start ): array {
		return array(
			'tutor_id'      => $tutor_id,
			'student_id'    => $student_id,
			'start_utc'     => Time::sql( $start ),
			'end_utc'       => Time::sql( $start->modify( '+60 minutes' ) ),
			'student_tz'    => 'UTC',
			'status'        => 'confirmed',
			'price_minor'   => 0,
			'currency'      => 'USD',
			'meeting_token' => bin2hex( random_bytes( 32 ) ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function booking_args( int $tutor_id, int $student_id, DateTimeImmutable $start, int $duration_min = 60 ): array {
		return array(
			'tutor_id'       => $tutor_id,
			'student_id'     => $student_id,
			'parent_id'      => null,
			'subject_id'     => null,
			'start_utc'      => $start,
			'duration_min'   => $duration_min,
			'tutor_tz'       => 'UTC',
			'student_tz'     => 'UTC',
			'price_minor'    => 0,
			'currency'       => 'USD',
			'credit_id'      => null,
			'consume_credit' => false,
			'lock_token'     => null,
			'notes'          => null,
		);
	}

	private function active_overlap_pairs( int $tutor_id ): int {
		global $wpdb;

		$table = Schema::table( Schema::BOOKINGS );
		// WordPress' test harness creates plugin tables as temporary tables.
		// MySQL cannot self-join a temporary table, so compare the active rows in PHP.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integration invariant over a whitelist table.
		$bookings = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is resolved from the schema whitelist.
				"SELECT id, start_utc, end_utc FROM {$table}
				 WHERE tutor_id = %d
				   AND status NOT IN ( 'cancelled', 'refunded', 'moved', 'payment_expired' )
				 ORDER BY start_utc ASC, id ASC",
				$tutor_id
			)
		);

		$pairs = 0;
		$count = count( $bookings );

		for ( $left = 0; $left < $count; $left++ ) {
			for ( $right = $left + 1; $right < $count; $right++ ) {
				if ( (string) $bookings[ $left ]->start_utc < (string) $bookings[ $right ]->end_utc
					&& (string) $bookings[ $left ]->end_utc > (string) $bookings[ $right ]->start_utc ) {
					++$pairs;
				}
			}
		}

		return $pairs;
	}

	private function create_credit( int $owner_id, int $tutor_id, int $total, int $used ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::table( Schema::CREDITS ),
			array(
				'owner_id'   => $owner_id,
				'tutor_id'   => $tutor_id,
				'total'      => $total,
				'used'       => $used,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	private function credit_used( int $credit_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT used FROM ' . Schema::table( Schema::CREDITS ) . ' WHERE id = %d',
				$credit_id
			)
		);
	}

	private function set_meeting_reference( int $booking_id, string $reference ): void {
		global $wpdb;

		$wpdb->update(
			Schema::table( Schema::BOOKINGS ),
			array( 'meeting_ref' => $reference ),
			array( 'id' => $booking_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	private function booking_id_at( int $tutor_id, DateTimeImmutable $start ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . Schema::table( Schema::BOOKINGS ) . ' WHERE tutor_id = %d AND start_utc = %s',
				$tutor_id,
				Time::sql( $start )
			)
		);
	}

	private function reminder_is_scheduled( int $booking_id, string $window ): bool {
		return function_exists( 'as_has_scheduled_action' )
			&& false !== as_has_scheduled_action(
				'tutorslot_send_reminder',
				array( $booking_id, $window ),
				'tutorslot'
			);
	}

	private function post_booking(
		int $student_id,
		int $tutor_id,
		int $subject_id,
		DateTimeImmutable $start
	): WP_REST_Response {
		wp_set_current_user( $student_id );

		$request = new WP_REST_Request( 'POST', '/tutorslot/v1/bookings' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'tutor_id'   => $tutor_id,
				'subject_id' => $subject_id,
				'start'      => $start->format( DATE_ATOM ),
			)
		);

		return rest_do_request( $request );
	}

	private function post_hold( int $user_id, int $tutor_id, DateTimeImmutable $start ): WP_REST_Response {
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/tutorslot/v1/bookings/hold' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'tutor_id' => $tutor_id,
				'start'    => $start->format( DATE_ATOM ),
			)
		);

		return rest_do_request( $request );
	}

	/**
	 * @param array<string, mixed> $params Request body params.
	 */
	private function authenticated_request( int $user_id, string $method, string $route, array $params = array() ): WP_REST_Response {
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		if ( array() !== $params ) {
			$request->set_body_params( $params );
		}

		return rest_do_request( $request );
	}

	/**
	 * @param array<string, mixed> $params Extra query params.
	 */
	private function list_bookings( int $user_id, string $scope, array $params = array() ): WP_REST_Response {
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/tutorslot/v1/bookings' );
		$request->set_param( 'scope', $scope );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * @return list<int>
	 */
	private function booking_ids( WP_REST_Response $response ): array {
		$data = $response->get_data();

		if ( ! is_array( $data ) || ! isset( $data['bookings'] ) || ! is_array( $data['bookings'] ) ) {
			return array();
		}

		return array_map(
			static fn ( array $booking ): int => (int) $booking['id'],
			$data['bookings']
		);
	}

	private function insert_relation( int $parent_id, int $student_id, bool $confirmed ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( Schema::RELATIONS ),
			array(
				'parent_id'  => $parent_id,
				'student_id' => $student_id,
				'relation'   => 'guardian',
				'confirmed'  => $confirmed ? 1 : 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * @return ProviderInterface&object{cancelled:list<string>}
	 */
	private function fake_meeting_provider(): ProviderInterface {
		return new class implements ProviderInterface {
			/** @var list<string> */
			public array $cancelled = array();

			public function id(): string {
				return 'integration_test';
			}

			public function label(): string {
				return 'Integration Test';
			}

			public function is_connected( int $tutor_id ): bool {
				return true;
			}

			public function create( int $booking_id, int $tutor_id, string $start_utc, int $duration_min, string $title ): string|WP_Error {
				return 'unused';
			}

			public function cancel( string $reference ): bool|WP_Error {
				$this->cancelled[] = $reference;

				return true;
			}

			public function join_url( string $reference ): ?string {
				return null;
			}
		};
	}

	/**
	 * @param ProviderInterface&object{cancelled:list<string>} $provider Provider spy.
	 */
	private function register_meeting_provider( ProviderInterface $provider ): callable {
		$filter = static function ( array $providers ) use ( $provider ): array {
			$providers[ $provider->id() ] = $provider;

			return $providers;
		};
		add_filter( 'tutorslot_meeting_providers', $filter );

		return $filter;
	}

	private function future_start( int $days_ahead ): DateTimeImmutable {
		$zone = new DateTimeZone( 'UTC' );

		return ( new DateTimeImmutable( 'tomorrow 10:00:00', $zone ) )->modify( '+' . $days_ahead . ' days' );
	}

	private function booking_count( int $tutor_id, DateTimeImmutable $start ): int {
		global $wpdb;

		$table = Schema::table( Schema::BOOKINGS );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $table . ' WHERE tutor_id = %d AND start_utc = %s',
				$tutor_id,
				Time::sql( $start )
			)
		);
	}

	private function booking_total( int $tutor_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( Schema::BOOKINGS ) . ' WHERE tutor_id = %d',
				$tutor_id
			)
		);
	}

	private function audit_action_count( string $action, int $booking_id, int $actor_id = 0 ): int {
		global $wpdb;

		$object_type = str_starts_with( $action, 'credit.' ) ? 'credit' : 'booking';
		$sql    = 'SELECT COUNT(*) FROM ' . Schema::table( Schema::AUDIT ) . ' WHERE action = %s AND object_type = %s AND object_id = %d';
		$params = array( $action, $object_type, $booking_id );

		if ( $actor_id > 0 ) {
			$sql     .= ' AND actor_id = %d';
			$params[] = $actor_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- integration assertion with a schema-whitelisted table and prepared values.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	private function active_booking_total( int $tutor_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( Schema::BOOKINGS ) . "
				 WHERE tutor_id = %d
				   AND status NOT IN ( 'cancelled', 'refunded', 'moved', 'payment_expired' )",
				$tutor_id
			)
		);
	}

	private function lock_total( int $tutor_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Schema::table( Schema::LOCKS ) . ' WHERE tutor_id = %d',
				$tutor_id
			)
		);
	}

	private function empty_tutorslot_tables(): void {
		global $wpdb;

		foreach ( array_reverse( Schema::all_keys() ) as $key ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}
	}
}
