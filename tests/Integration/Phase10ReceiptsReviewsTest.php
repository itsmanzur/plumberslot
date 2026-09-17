<?php
/**
 * Phase 10 — printable receipt and post-completion review request.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\CreditRepository;
use PlumberSlot\Database\Repository\LockRepository;
use PlumberSlot\Database\Repository\ReviewRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Database\TransactionManager;
use PlumberSlot\Domain\BookingService;
use PlumberSlot\Domain\CreditService;
use PlumberSlot\Domain\PolicyService;
use PlumberSlot\Domain\SlotEngine;
use PlumberSlot\Frontend\ReceiptRoute;
use PlumberSlot\Notifications\Channel\ChannelInterface;
use PlumberSlot\Notifications\Dispatcher;
use PlumberSlot\Rest\Guard;
use PlumberSlot\Rest\ReviewsController;
use PlumberSlot\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class Phase10ReceiptsReviewsTest extends WP_UnitTestCase {

	private BookingRepository $bookings;
	private TechnicianRepository $technicians;
	private ReviewRepository $reviews;

	public function set_up(): void {
		parent::set_up();
		Schema::create_all();
		Capabilities::add_all();
		$this->empty_tables();

		$this->bookings    = new BookingRepository();
		$this->technicians = new TechnicianRepository();
		$this->reviews     = new ReviewRepository();

		add_filter( 'plumberslot_email_enabled', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'plumberslot_email_enabled', '__return_false' );
		wp_set_current_user( 0 );
		$this->empty_tables();
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// ReviewsController::submit()
	// -----------------------------------------------------------------------

	public function test_review_rejected_when_booking_not_completed(): void {
		$ctx = $this->seed_booking( 'confirmed' );
		wp_set_current_user( $ctx['customer_id'] );

		$result = $this->controller()->submit( $this->review_request( $ctx['booking_id'], 5, 'Great job' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'plumberslot_review_not_completed', $result->get_error_code() );
		$this->assertNull( $this->reviews->for_booking( $ctx['booking_id'] ) );
	}

	public function test_second_review_for_the_same_booking_is_rejected(): void {
		$ctx = $this->seed_booking( 'completed' );
		wp_set_current_user( $ctx['customer_id'] );

		$first = $this->controller()->submit( $this->review_request( $ctx['booking_id'], 4, 'First review' ) );
		$this->assertNotInstanceOf( WP_Error::class, $first );

		$second = $this->controller()->submit( $this->review_request( $ctx['booking_id'], 2, 'Trying again' ) );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'plumberslot_review_exists', $second->get_error_code() );

		// Still exactly one row, and it is the first submission's rating.
		$row = $this->reviews->for_booking( $ctx['booking_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( 4, (int) $row->rating );
	}

	/**
	 * A review can only ever be submitted for the requester's own completed
	 * booking, never someone else's -- enforced server-side in the handler,
	 * not merely hidden client-side.
	 */
	public function test_review_submitted_by_someone_other_than_the_customer_is_rejected(): void {
		$ctx      = $this->seed_booking( 'completed' );
		$outsider = self::factory()->user->create( array( 'role' => Capabilities::ROLE_CUSTOMER ) );
		wp_set_current_user( $outsider );

		$result = $this->controller()->submit( $this->review_request( $ctx['booking_id'], 5, 'Not my booking' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		// Guard::deny() is this codebase's deliberately indistinct rejection --
		// "exists but not yours" and "does not exist" both answer 404, never a
		// distinguishing 403, so an outsider can never enumerate real bookings.
		$this->assertSame( 'plumberslot_not_found', $result->get_error_code() );
		$this->assertSame( 404, (int) $result->get_error_data()['status'] );
		$this->assertNull( $this->reviews->for_booking( $ctx['booking_id'] ) );
	}

	/**
	 * A manager may submit on a customer's behalf -- the one documented
	 * override to the "own booking only" rule.
	 */
	public function test_manager_may_submit_a_review_on_the_customers_behalf(): void {
		$ctx     = $this->seed_booking( 'completed' );
		$manager = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $manager );

		$result = $this->controller()->submit( $this->review_request( $ctx['booking_id'], 5, 'On behalf of the customer' ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	public function test_valid_submission_is_pending_and_invisible_until_moderated(): void {
		$ctx = $this->seed_booking( 'completed' );
		wp_set_current_user( $ctx['customer_id'] );

		$result = $this->controller()->submit( $this->review_request( $ctx['booking_id'], 5, 'Excellent work' ) );
		$this->assertNotInstanceOf( WP_Error::class, $result );

		$data = $result->get_data();
		$this->assertSame( 'pending', $data['status'] );
		$review_id = (int) $data['id'];
		$this->assertGreaterThan( 0, $review_id );

		$row = $this->reviews->for_booking( $ctx['booking_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( 'pending', (string) $row->status );

		// Invisible on the public technician surface until an admin approves it.
		$this->assertSame( array(), $this->reviews->approved_for_technician( $ctx['technician_id'] ) );

		$this->assertTrue( $this->reviews->set_status( $review_id, 'approved' ) );

		$approved = $this->reviews->approved_for_technician( $ctx['technician_id'] );
		$this->assertCount( 1, $approved );
		$this->assertSame( $review_id, (int) $approved[0]->id );
	}

	// -----------------------------------------------------------------------
	// BookingService::mark_attendance() -> booking_completed notification
	// -----------------------------------------------------------------------

	public function test_marking_a_booking_completed_fires_exactly_one_completion_notification(): void {
		$ctx     = $this->seed_booking( 'confirmed', true );
		$spy     = $this->spy_channel();
		$service = $this->booking_service( $spy );

		$result = $service->mark_attendance( $ctx['booking_id'], 'completed' );

		$this->assertTrue( $result );
		$completed_events = $this->events_named( $spy->sent, 'booking_completed' );
		$this->assertCount( 1, $completed_events );
		$this->assertSame( $ctx['customer_id'], $completed_events[0]['user_id'] );
	}

	public function test_marking_a_booking_no_show_fires_no_completion_notification(): void {
		$ctx     = $this->seed_booking( 'confirmed', true );
		$spy     = $this->spy_channel();
		$service = $this->booking_service( $spy );

		$result = $service->mark_attendance( $ctx['booking_id'], 'no_show' );

		$this->assertTrue( $result );
		$this->assertSame( array(), $this->events_named( $spy->sent, 'booking_completed' ) );
	}

	// -----------------------------------------------------------------------
	// ReceiptRoute
	// -----------------------------------------------------------------------

	public function test_receipt_route_rejects_an_unauthenticated_request(): void {
		$ctx = $this->seed_booking( 'completed' );
		$this->prepare_receipt_request( $ctx['booking_id'] );
		wp_set_current_user( 0 );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'Sign in' );

		$this->receipt_route()->handle();
	}

	public function test_receipt_route_rejects_a_logged_in_outsider(): void {
		$ctx      = $this->seed_booking( 'completed' );
		$outsider = self::factory()->user->create();
		$this->prepare_receipt_request( $ctx['booking_id'] );
		wp_set_current_user( $outsider );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'not a participant' );

		$this->receipt_route()->handle();
	}

	// Deliberately no "allowed" counterpart here: on success, handle() renders
	// the page and calls exit (the same shape as TrackRoute/JoinRoute), which
	// would terminate the whole PHPUnit process if invoked directly. The
	// rejection paths above are what the task calls for (both must 403/deny
	// before ever reaching render()), and they are exercised without ever
	// reaching that exit.

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function controller(): ReviewsController {
		return new ReviewsController(
			new Guard( $this->technicians ),
			$this->reviews,
			$this->bookings,
			$this->technicians
		);
	}

	private function review_request( int $booking_id, int $rating, string $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/plumberslot/v1/bookings/' . $booking_id . '/review' );
		$request->set_param( 'id', $booking_id );
		$request->set_param( 'rating', $rating );
		$request->set_param( 'body', $body );

		return $request;
	}

	private function receipt_route(): ReceiptRoute {
		return new ReceiptRoute( $this->bookings, $this->technicians, new ServiceRepository() );
	}

	private function prepare_receipt_request( int $booking_id ): void {
		$_GET['booking'] = (string) $booking_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- integration-test fixture.
		set_query_var( 'plumberslot_receipt', 1 );
	}

	private function booking_service( ChannelInterface $spy ): BookingService {
		$availability = new AvailabilityRepository();
		$locks        = new LockRepository();

		$dispatcher = new Dispatcher();
		$dispatcher->add_channel( $spy );

		return new BookingService(
			$this->bookings,
			$locks,
			new SlotEngine( $availability, $this->bookings, $locks ),
			new PolicyService( $this->bookings ),
			$dispatcher,
			new CreditService( new CreditRepository() ),
			new TransactionManager()
		);
	}

	/**
	 * Records every dispatched event instead of sending real email/SMS.
	 */
	private function spy_channel(): object {
		return new class() implements ChannelInterface {
			/** @var list<array{event:string,user_id:int}> */
			public array $sent = array();

			public function id(): string {
				return 'spy';
			}

			public function is_enabled( string $event ): bool {
				return true;
			}

			/**
			 * @param array<string, mixed> $context Extra template variables.
			 */
			public function send( string $event, int $user_id, object $booking, array $context = array() ): void {
				$this->sent[] = array(
					'event'   => $event,
					'user_id' => $user_id,
				);
			}
		};
	}

	/**
	 * @param list<array{event:string,user_id:int}> $sent Sent events.
	 * @return list<array{event:string,user_id:int}>
	 */
	private function events_named( array $sent, string $event ): array {
		return array_values( array_filter( $sent, static fn( array $item ): bool => $event === $item['event'] ) );
	}

	/**
	 * @return array{technician_id:int,technician_user_id:int,customer_id:int,booking_id:int}
	 */
	private function seed_booking( string $status, bool $ended = false ): array {
		$technician_user_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TECHNICIAN ) );
		$customer_id        = self::factory()->user->create( array( 'role' => Capabilities::ROLE_CUSTOMER ) );

		$technician_id = $this->technicians->create(
			array(
				'user_id'      => $technician_user_id,
				'slug'         => 'phase10-technician-' . $technician_user_id,
				'display_name' => 'Phase 10 Technician',
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);

		// A completed/no_show job always lies in the past (mark_attendance_outcome()
		// refuses to record attendance before the appointment ends), while a still
		// 'confirmed' fixture used only for the "not completed yet" review test can
		// stay in the future.
		$in_past = $ended || in_array( $status, array( 'completed', 'no_show' ), true );
		$start   = $in_past
			? gmdate( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ) )
			: gmdate( 'Y-m-d H:i:s', time() + ( 3 * DAY_IN_SECONDS ) );
		$end     = gmdate( 'Y-m-d H:i:s', strtotime( $start ) + HOUR_IN_SECONDS );

		$booking_id = $this->bookings->insert_unique(
			array(
				'technician_id' => $technician_id,
				'customer_id'   => $customer_id,
				'service_id'    => null,
				'series_id'     => null,
				'series_index'  => null,
				'start_utc'     => $start,
				'end_utc'       => $end,
				'customer_tz'   => 'UTC',
				'status'        => $status,
				'price_minor'   => 5000,
				'deposit_minor' => 5000,
				'balance_minor' => 0,
				'currency'      => 'USD',
				'credit_id'     => null,
				'payment_ref'   => null,
				'meeting_token' => bin2hex( random_bytes( 32 ) ),
				'notes'         => null,
			)
		);

		$this->assertIsInt( $booking_id );
		$booking_id = (int) $booking_id;

		return array(
			'technician_id'      => $technician_id,
			'technician_user_id' => $technician_user_id,
			'customer_id'        => $customer_id,
			'booking_id'         => $booking_id,
		);
	}

	private function empty_tables(): void {
		global $wpdb;
		foreach ( Schema::all_keys() as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integration-test tables only.
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}
	}
}
