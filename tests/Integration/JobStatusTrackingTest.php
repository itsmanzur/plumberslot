<?php
/**
 * Phase 9 — Job status tracking (`job_stage`) tests.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\CreditRepository;
use PlumberSlot\Database\Repository\LockRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Database\TransactionManager;
use PlumberSlot\Domain\BookingService;
use PlumberSlot\Domain\CreditService;
use PlumberSlot\Domain\PolicyService;
use PlumberSlot\Domain\SlotEngine;
use PlumberSlot\Notifications\Channel\ChannelInterface;
use PlumberSlot\Notifications\Dispatcher;
use PlumberSlot\Support\Crypto;
use WP_Error;
use WP_UnitTestCase;

final class JobStatusTrackingTest extends WP_UnitTestCase {

	private BookingRepository $bookings;
	private TechnicianRepository $technicians;

	public function set_up(): void {
		parent::set_up();
		Schema::create_all();
		$this->empty_tables();

		$this->bookings    = new BookingRepository();
		$this->technicians = new TechnicianRepository();

		add_filter( 'plumberslot_email_enabled', '__return_false' );
	}

	public function tear_down(): void {
		remove_filter( 'plumberslot_email_enabled', '__return_false' );
		$this->empty_tables();
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// BookingService::set_job_stage()
	// -----------------------------------------------------------------------

	public function test_valid_transition_on_confirmed_booking_succeeds_without_notifying_for_in_progress(): void {
		$ctx     = $this->seed();
		$spy     = $this->spy_channel();
		$service = $this->booking_service( $spy );

		$result = $service->set_job_stage( $ctx['booking_id'], 'in_progress' );

		$this->assertTrue( $result );
		$this->assertSame( 'in_progress', (string) $this->bookings->find( $ctx['booking_id'] )->job_stage );
		$this->assertSame( array(), $this->events_named( $spy->sent, 'booking_on_the_way' ) );
	}

	public function test_on_the_way_transition_triggers_the_on_the_way_notification_only(): void {
		$ctx     = $this->seed();
		$spy     = $this->spy_channel();
		$service = $this->booking_service( $spy );

		$result = $service->set_job_stage( $ctx['booking_id'], 'on_the_way' );

		$this->assertTrue( $result );
		$this->assertSame( 'on_the_way', (string) $this->bookings->find( $ctx['booking_id'] )->job_stage );

		$on_the_way_events = $this->events_named( $spy->sent, 'booking_on_the_way' );
		$this->assertCount( 1, $on_the_way_events );
		$this->assertSame( $ctx['customer_id'], $on_the_way_events[0]['user_id'] );

		// No other event fired as a side effect of this single stage update.
		$this->assertCount( 1, $spy->sent );
	}

	public function test_invalid_stage_string_is_rejected(): void {
		$ctx     = $this->seed();
		$service = $this->booking_service( $this->spy_channel() );

		$result = $service->set_job_stage( $ctx['booking_id'], 'driving_fast' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'plumberslot_bad_status', $result->get_error_code() );
		$this->assertSame( 'scheduled', (string) $this->bookings->find( $ctx['booking_id'] )->job_stage );
	}

	public function test_non_confirmed_booking_is_rejected(): void {
		$ctx     = $this->seed();
		$service = $this->booking_service( $this->spy_channel() );

		$this->bookings->update_status( $ctx['booking_id'], 'pending' );

		$result = $service->set_job_stage( $ctx['booking_id'], 'on_the_way' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'plumberslot_invalid_transition', $result->get_error_code() );
		$this->assertSame( 'scheduled', (string) $this->bookings->find( $ctx['booking_id'] )->job_stage );
	}

	public function test_unknown_booking_id_returns_not_found(): void {
		$service = $this->booking_service( $this->spy_channel() );

		$result = $service->set_job_stage( 999999, 'on_the_way' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'plumberslot_not_found', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// TrackRoute token verification shape (Crypto::track_url + hash_equals)
	// -----------------------------------------------------------------------

	public function test_track_url_carries_the_bookings_own_meeting_token(): void {
		$token = bin2hex( random_bytes( 32 ) );
		$url   = Crypto::track_url( 42, $token );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		$this->assertSame( '42', (string) $params['ts_booking'] );
		$this->assertSame( $token, (string) $params['ts_token'] );
		$this->assertStringContainsString( '/plumberslot/track', $url );
	}

	/**
	 * @return array{technician_id:int,customer_id:int,booking_id:int}
	 */
	private function seed(): array {
		$user     = self::factory()->user->create();
		$customer = self::factory()->user->create();

		$technician_id = $this->technicians->create(
			array(
				'user_id'      => $user,
				'slug'         => 'job-status-technician-' . $user,
				'display_name' => 'Job Status Technician',
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);

		$start      = gmdate( 'Y-m-d H:i:s', time() + ( 3 * DAY_IN_SECONDS ) );
		$booking_id = $this->bookings->insert_unique(
			array(
				'technician_id' => $technician_id,
				'customer_id'   => $customer,
				'service_id'    => null,
				'series_id'     => null,
				'series_index'  => null,
				'start_utc'     => $start,
				'end_utc'       => gmdate( 'Y-m-d H:i:s', strtotime( $start ) + HOUR_IN_SECONDS ),
				'customer_tz'   => 'UTC',
				'status'        => 'confirmed',
				'price_minor'   => 0,
				'currency'      => 'USD',
				'credit_id'     => null,
				'payment_ref'   => null,
				'meeting_token' => bin2hex( random_bytes( 32 ) ),
				'notes'         => null,
			)
		);

		$this->assertIsInt( $booking_id );

		return array(
			'technician_id' => $technician_id,
			'customer_id'   => $customer,
			'booking_id'    => (int) $booking_id,
		);
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

	private function empty_tables(): void {
		global $wpdb;
		foreach ( Schema::all_keys() as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integration-test tables only.
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}
	}
}
