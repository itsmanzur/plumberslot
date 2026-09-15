<?php
/**
 * Phase 7 — Meetings & Notifications integration tests.
 *
 * @package PlumberSlot\Tests
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Domain\Contract\MeetingBookingStore;
use PlumberSlot\Domain\Contract\TechnicianSource;
use PlumberSlot\Domain\MeetingService;
use PlumberSlot\Frontend\JoinRoute;
use PlumberSlot\Meetings\MeetingCleanup;
use PlumberSlot\Meetings\ProviderInterface;
use PlumberSlot\Meetings\ProviderRegistry;
use PlumberSlot\Meetings\GoogleMeetProvider;
use PlumberSlot\Notifications\Channel\ChannelInterface;
use PlumberSlot\Notifications\Channel\EmailChannel;
use PlumberSlot\Notifications\Dispatcher;
use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\AuditLog;
use WP_Error;

class Phase7MeetingsNotificationsTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_all();
	}

	public function tear_down(): void {
		global $wpdb;

		$_GET = array();
		set_query_var( 'plumberslot_join', null );
		wp_set_current_user( 0 );

		foreach ( Schema::all_keys() as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integration-test tables only.
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}

		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// Signed join URL tests
	// -----------------------------------------------------------------------

	public function test_signed_join_url_verifies(): void {
		$booking_id = 99;
		$token      = bin2hex( random_bytes( 32 ) );
		$url        = Crypto::signed_join_url( $booking_id, $token, 3600 );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		$this->assertTrue(
			Crypto::verify_join(
				(int) $params['ts_booking'],
				$token,
				(int) $params['ts_expires'],
				(string) $params['ts_sig']
			),
			'Fresh signed join URL must verify.'
		);
	}

	public function test_expired_join_url_fails(): void {
		$booking_id = 42;
		$token      = bin2hex( random_bytes( 32 ) );

		// Build a URL that expired in the past.
		$expires   = time() - 10;
		$key       = \PlumberSlot\Support\Crypto::class;
		$signature = hash_hmac( 'sha256', $booking_id . '|' . $token . '|' . $expires, defined( 'AUTH_KEY' ) ? constant( 'AUTH_KEY' ) : 'test' );

		$this->assertFalse(
			Crypto::verify_join( $booking_id, $token, $expires, $signature ),
			'Expired join URL must not verify.'
		);
	}

	public function test_tampered_signature_fails(): void {
		$booking_id = 55;
		$token      = bin2hex( random_bytes( 32 ) );
		$url        = Crypto::signed_join_url( $booking_id, $token, 3600 );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		$this->assertFalse(
			Crypto::verify_join(
				(int) $params['ts_booking'],
				$token,
				(int) $params['ts_expires'],
				'tampered_signature_value'
			),
			'Tampered signature must not verify.'
		);
	}

	public function test_wrong_booking_id_fails(): void {
		$token = bin2hex( random_bytes( 32 ) );
		$url   = Crypto::signed_join_url( 10, $token, 3600 );

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		$this->assertFalse(
			Crypto::verify_join( 11, $token, (int) $params['ts_expires'], (string) $params['ts_sig'] ),
			'Wrong booking id must not verify.'
		);
	}

	// -----------------------------------------------------------------------
	// Provider registry tests
	// -----------------------------------------------------------------------

	public function test_registry_reference_roundtrip(): void {
		$stored = ProviderRegistry::reference( 'zoom', 'meeting-123' );
		$this->assertSame( 'zoom|meeting-123', $stored );
	}

	public function test_registry_cancel_unknown_provider(): void {
		$registry = new ProviderRegistry();
		$result   = $registry->cancel_reference( 'unknown_provider|ref-456' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_registry_cancel_malformed_reference(): void {
		$registry = new ProviderRegistry();
		$result   = $registry->cancel_reference( 'no-pipe' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_google_connection_lifecycle_is_audited_without_tokens(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );

		$url = GoogleMeetProvider::authorization_url( $user_id );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$http = static function (): array {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'refresh_token' => 'super-secret-refresh-token' ) ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $http );
		try {
			$this->assertTrue( GoogleMeetProvider::handle_callback( 'oauth-code', (string) $query['state'] ) );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}

		GoogleMeetProvider::disconnect( $user_id );

		$events = array_values(
			array_filter(
				AuditLog::recent( 20 ),
				static fn ( object $row ): bool => $user_id === (int) $row->object_id
					&& in_array( (string) $row->action, array( 'meeting.connection_started', 'meeting.connected', 'meeting.disconnected' ), true )
			)
		);

		$this->assertCount( 3, $events );
		$this->assertSame(
			array( 'meeting.disconnected', 'meeting.connected', 'meeting.connection_started' ),
			array_map( static fn ( object $row ): string => (string) $row->action, $events )
		);
		foreach ( $events as $event ) {
			$this->assertSame( $user_id, (int) $event->actor_id );
			$this->assertSame( array( 'provider' => 'google_meet' ), json_decode( (string) $event->meta, true ) );
			$this->assertStringNotContainsString( 'super-secret', (string) $event->meta );
		}
	}

	// -----------------------------------------------------------------------
	// MeetingService tests (with a fake provider)
	// -----------------------------------------------------------------------

	public function test_meeting_service_skips_when_no_provider(): void {
		$registry = new ProviderRegistry();
		$bookings = $this->createMock( MeetingBookingStore::class );
		$technicians   = $this->createMock( TechnicianSource::class );
		$service  = new MeetingService( $registry, $bookings, $technicians );

		$fake_booking              = new \stdClass();
		$fake_booking->id          = 1;
		$fake_booking->customer_id  = 5;
		$fake_booking->technician_id    = 2;
		$fake_booking->start_utc   = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$fake_booking->end_utc     = gmdate( 'Y-m-d H:i:s', time() + 7200 );
		$fake_booking->price_minor = 0;
		$fake_booking->meeting_ref = null;

		$bookings->method( 'find' )->willReturn( $fake_booking );

		$fake_technician          = new \stdClass();
		$fake_technician->user_id = 10;
		$technicians->method( 'find' )->willReturn( $fake_technician );

		// Should run without error and without calling set_meeting_ref.
		$bookings->expects( $this->never() )->method( 'set_meeting_ref' );
		$service->create_for_booking( 1 );
	}

	public function test_meeting_service_creates_with_connected_provider(): void {
		$fake_provider = new class() implements ProviderInterface {
			public function id(): string {
				return 'fake';
			}
			public function label(): string {
				return 'Fake';
			}
			public function is_connected( int $technician_id ): bool {
				return true;
			}
			public function create( int $booking_id, int $technician_id, string $start_utc, int $duration_min, string $title ): string|WP_Error {
				return 'fake-ref-' . $booking_id;
			}
			public function cancel( string $reference ): bool|WP_Error {
				return true;
			}
			public function join_url( string $reference ): ?string {
				return 'https://example.com/meet';
			}
		};

		$registry = new ProviderRegistry();
		$registry->register( $fake_provider );

		$bookings = $this->createMock( MeetingBookingStore::class );
		$technicians   = $this->createMock( TechnicianSource::class );
		$service  = new MeetingService( $registry, $bookings, $technicians );

		$fake_booking              = new \stdClass();
		$fake_booking->id          = 7;
		$fake_booking->customer_id  = 5;
		$fake_booking->technician_id    = 2;
		$fake_booking->start_utc   = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$fake_booking->end_utc     = gmdate( 'Y-m-d H:i:s', time() + 7200 );
		$fake_booking->price_minor = 0;
		$fake_booking->meeting_ref = null;

		$fake_technician          = new \stdClass();
		$fake_technician->user_id = 10;

		$bookings->method( 'find' )->willReturn( $fake_booking );
		$technicians->method( 'find' )->willReturn( $fake_technician );
		$bookings->expects( $this->once() )->method( 'set_meeting_ref' )
			->with( 7, 'fake|fake-ref-7' );

		$service->create_for_booking( 7 );

		$events = array_values(
			array_filter(
				AuditLog::recent( 10 ),
				static fn ( object $row ): bool => 'meeting.created' === (string) $row->action
					&& 7 === (int) $row->object_id
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( array( 'provider' => 'fake' ), json_decode( (string) $events[0]->meta, true ) );
	}

	public function test_meeting_service_checks_connection_with_technician_user_id(): void {
		$technician_user_id = 417;
		$fake_provider = new class( $technician_user_id ) implements ProviderInterface {
			/** @var array<string, int> */
			public array $calls = array();

			public function __construct( private readonly int $expected_user_id ) {}

			public function id(): string {
				return 'identity-aware';
			}

			public function label(): string {
				return 'Identity aware';
			}

			public function is_connected( int $technician_id ): bool {
				$this->calls['connected'] = $technician_id;

				return $this->expected_user_id === $technician_id;
			}

			public function create( int $booking_id, int $technician_id, string $start_utc, int $duration_min, string $title ): string|WP_Error {
				$this->calls['created'] = $technician_id;

				return 'meeting-' . $booking_id;
			}

			public function cancel( string $reference ): bool|WP_Error {
				return true;
			}

			public function join_url( string $reference ): ?string {
				return null;
			}
		};

		$registry = new ProviderRegistry();
		$registry->register( $fake_provider );

		$bookings = $this->createMock( MeetingBookingStore::class );
		$technicians   = $this->createMock( TechnicianSource::class );
		$service  = new MeetingService( $registry, $bookings, $technicians );

		$booking              = new \stdClass();
		$booking->id          = 8;
		$booking->customer_id  = 5;
		$booking->technician_id    = 23;
		$booking->start_utc   = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$booking->end_utc     = gmdate( 'Y-m-d H:i:s', time() + 7200 );
		$booking->price_minor = 0;
		$booking->meeting_ref = null;

		$technician          = new \stdClass();
		$technician->user_id = $technician_user_id;

		$bookings->method( 'find' )->willReturn( $booking );
		$technicians->method( 'find' )->with( 23 )->willReturn( $technician );
		$bookings->expects( $this->once() )->method( 'set_meeting_ref' )
			->with( 8, 'identity-aware|meeting-8' );

		$service->create_for_booking( 8 );

		$this->assertSame( $technician_user_id, $fake_provider->calls['connected'] );
		$this->assertSame( $technician_user_id, $fake_provider->calls['created'] );
	}

	public function test_meeting_service_skips_already_has_ref(): void {
		$fake_provider = new class() implements ProviderInterface {
			public function id(): string {
				return 'fake';
			}
			public function label(): string {
				return 'Fake';
			}
			public function is_connected( int $technician_id ): bool {
				return true;
			}
			public function create( int $booking_id, int $technician_id, string $start_utc, int $duration_min, string $title ): string|WP_Error {
				return 'ref';
			}
			public function cancel( string $reference ): bool|WP_Error {
				return true;
			}
			public function join_url( string $reference ): ?string {
				return null;
			}
		};

		$registry = new ProviderRegistry();
		$registry->register( $fake_provider );

		$bookings = $this->createMock( MeetingBookingStore::class );
		$technicians   = $this->createMock( TechnicianSource::class );
		$service  = new MeetingService( $registry, $bookings, $technicians );

		$fake_booking              = new \stdClass();
		$fake_booking->id          = 3;
		$fake_booking->meeting_ref = 'fake|existing-ref';
		$fake_booking->price_minor = 0;

		$bookings->method( 'find' )->willReturn( $fake_booking );
		$bookings->expects( $this->never() )->method( 'set_meeting_ref' );

		$service->create_for_booking( 3 );
	}

	// -----------------------------------------------------------------------
	// Provider cancel cleanup test
	// -----------------------------------------------------------------------

	public function test_provider_cancel_routes_to_correct_provider(): void {
		$cancelled = false;

		$fake_provider = new class( $cancelled ) implements ProviderInterface {
			private bool $flag;

			public function __construct( bool &$flag ) {
				$this->flag =& $flag;
			}
			public function id(): string {
				return 'fake';
			}
			public function label(): string {
				return 'Fake';
			}
			public function is_connected( int $technician_id ): bool {
				return true;
			}
			public function create( int $b, int $t, string $s, int $d, string $ti ): string|WP_Error {
				return 'r';
			}
			public function cancel( string $reference ): bool|WP_Error {
				$this->flag = true;
				return true;
			}
			public function join_url( string $reference ): ?string {
				return null;
			}
		};

		$registry = new ProviderRegistry();
		$registry->register( $fake_provider );

		$result = $registry->cancel_reference( 'fake|ref-abc' );
		$this->assertTrue( $result );
		$this->assertTrue( $cancelled );
	}

	public function test_failed_meeting_cleanup_retries_then_records_terminal_audit(): void {
		$seed     = $this->seed_booking_with_meeting();
		$provider = new class() implements ProviderInterface {
			public function id(): string {
				return 'fake';
			}

			public function label(): string {
				return 'Failing cleanup provider';
			}

			public function is_connected( int $technician_id ): bool {
				return true;
			}

			public function create( int $booking_id, int $technician_id, string $start_utc, int $duration_min, string $title ): string|WP_Error {
				return 'unused';
			}

			public function cancel( string $reference ): bool|WP_Error {
				return new WP_Error( 'cleanup_unavailable' );
			}

			public function join_url( string $reference ): ?string {
				return null;
			}
		};
		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$cleanup = new MeetingCleanup( new BookingRepository(), $registry );

		try {
			$cleanup->request( $seed['booking_id'] );
			$this->assertNotFalse(
				as_has_scheduled_action(
					'plumberslot_cleanup_meeting',
					array( $seed['booking_id'], 1 ),
					'plumberslot'
				)
			);

			$cleanup->run( $seed['booking_id'], 3 );

			$events = array_values(
				array_filter(
					AuditLog::recent( 10 ),
					static fn ( object $row ): bool => 'meeting.cleanup_failed' === (string) $row->action
						&& $seed['booking_id'] === (int) $row->object_id
				)
			);
			$this->assertCount( 1, $events );
			$this->assertSame( array( 'attempts' => 4 ), json_decode( (string) $events[0]->meta, true ) );
			$this->assertSame( 'fake|phase7-meeting', ( new BookingRepository() )->find( $seed['booking_id'] )->meeting_ref );
		} finally {
			as_unschedule_all_actions(
				'plumberslot_cleanup_meeting',
				array( $seed['booking_id'], 1 ),
				'plumberslot'
			);
		}
	}

	public function test_join_route_requires_a_signed_in_participant(): void {
		$seed     = $this->seed_booking_with_meeting();
		$provider = $this->throwing_join_provider( 'Provider must not run before participant authorization.' );

		$registry = new ProviderRegistry();
		$registry->register( $provider );
		$this->prepare_join_request( $seed['booking_id'], $seed['token'] );
		wp_set_current_user( 0 );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'Sign in with a appointment participant account' );

		( new JoinRoute( new BookingRepository(), $registry ) )->handle();
	}

	public function test_join_route_allows_the_booked_customer_to_resolve_the_provider(): void {
		$seed     = $this->seed_booking_with_meeting();
		$registry = new ProviderRegistry();
		$registry->register( $this->throwing_join_provider( 'Authorized participant reached provider.' ) );

		$this->prepare_join_request( $seed['booking_id'], $seed['token'] );
		wp_set_current_user( $seed['customer_id'] );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Authorized participant reached provider.' );

		( new JoinRoute( new BookingRepository(), $registry ) )->handle();
	}

	public function test_join_route_rejects_a_signed_in_outsider(): void {
		$seed     = $this->seed_booking_with_meeting();
		$outsider = self::factory()->user->create();
		$registry = new ProviderRegistry();

		$this->prepare_join_request( $seed['booking_id'], $seed['token'] );
		wp_set_current_user( $outsider );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'not a participant' );

		( new JoinRoute( new BookingRepository(), $registry ) )->handle();
	}

	public function test_email_contains_only_the_signed_plumberslot_join_url(): void {
		$user_id = self::factory()->user->create(
			array(
				'user_email'   => 'phase7-customer@example.test',
				'display_name' => 'Phase 7 Customer',
			)
		);
		$token   = bin2hex( random_bytes( 32 ) );
		$booking = (object) array(
			'id'            => 77,
			'start_utc'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			'customer_tz'    => 'UTC',
			'meeting_ref'   => 'fake|https://provider.example.test/private-room',
			'meeting_token' => $token,
		);
		$mail    = null;
		$capture = static function ( $preempt, array $attributes ) use ( &$mail ) {
			$mail = $attributes;

			return true;
		};

		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		try {
			( new EmailChannel() )->send( 'booking_confirmed', $user_id, $booking );
		} finally {
			remove_filter( 'pre_wp_mail', $capture, 10 );
		}

		$this->assertIsArray( $mail );
		$this->assertStringContainsString( '/plumberslot/join', (string) $mail['message'] );
		$this->assertStringContainsString( 'ts_booking=77', (string) $mail['message'] );
		$this->assertStringNotContainsString( 'provider.example.test', (string) $mail['message'] );
		$this->assertStringNotContainsString( $token, (string) $mail['message'] );
	}

	public function test_dispatcher_reaches_customer_and_technician_by_event(): void {
		$seed = $this->seed_booking_with_meeting();
		$spy  = new class() implements ChannelInterface {
			/** @var list<array{event:string,user_id:int}> */
			public array $sent = array();

			public function id(): string {
				return 'spy';
			}

			public function is_enabled( string $event ): bool {
				return true;
			}

			public function send( string $event, int $user_id, object $booking, array $context = array() ): void {
				$this->sent[] = array(
					'event'   => $event,
					'user_id' => $user_id,
				);
			}
		};

		add_filter( 'plumberslot_email_enabled', '__return_false' );
		try {
			$dispatcher = new Dispatcher();
			$dispatcher->add_channel( $spy );
			$dispatcher->booking_created( $seed['booking_id'] );
			$dispatcher->booking_confirmed( $seed['booking_id'] );
			$dispatcher->reminder( $seed['booking_id'], '24h' );
		} finally {
			remove_filter( 'plumberslot_email_enabled', '__return_false' );
		}

		$this->assertSame(
			array( $seed['customer_id'], $seed['technician_user_id'] ),
			$this->recipients_for( $spy->sent, 'booking_created' )
		);
		$this->assertSame(
			array( $seed['customer_id'] ),
			$this->recipients_for( $spy->sent, 'booking_confirmed' )
		);
		$this->assertSame(
			array( $seed['customer_id'], $seed['technician_user_id'] ),
			$this->recipients_for( $spy->sent, 'reminder_24h' )
		);
	}

	/**
	 * @return array{booking_id:int,token:string,customer_id:int,technician_user_id:int}
	 */
	private function seed_booking_with_meeting(): array {
		$technician_user_id = self::factory()->user->create();
		$customer_id        = self::factory()->user->create();
		$technician_id      = ( new TechnicianRepository() )->create(
			array(
				'user_id'      => $technician_user_id,
				'slug'         => 'phase7-' . wp_generate_password( 8, false ),
				'display_name' => 'Phase 7 Technician',
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);
		$token         = bin2hex( random_bytes( 32 ) );
		$start         = gmdate( 'Y-m-d H:i:s', time() + ( 3 * DAY_IN_SECONDS ) );
		$booking_id    = ( new BookingRepository() )->insert_unique(
			array(
				'technician_id' => $technician_id,
				'customer_id'   => $customer_id,
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
				'meeting_ref'   => 'fake|phase7-meeting',
				'meeting_token' => $token,
				'notes'         => null,
			)
		);

		$this->assertNotNull( $booking_id );

		return array(
			'booking_id'         => (int) $booking_id,
			'token'              => $token,
			'customer_id'        => $customer_id,
			'technician_user_id' => $technician_user_id,
		);
	}

	private function prepare_join_request( int $booking_id, string $token ): void {
		$url = Crypto::signed_join_url( $booking_id, $token, HOUR_IN_SECONDS );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed join query fixture.
		set_query_var( 'plumberslot_join', 1 );
	}

	private function throwing_join_provider( string $message ): ProviderInterface {
		return new class( $message ) implements ProviderInterface {
			public function __construct( private readonly string $message ) {}

			public function id(): string {
				return 'fake';
			}

			public function label(): string {
				return 'Fake';
			}

			public function is_connected( int $technician_id ): bool {
				return true;
			}

			public function create( int $booking_id, int $technician_id, string $start_utc, int $duration_min, string $title ): string|WP_Error {
				return 'unused';
			}

			public function cancel( string $reference ): bool|WP_Error {
				return true;
			}

			public function join_url( string $reference ): ?string {
				throw new \RuntimeException( $this->message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test sentinel, not rendered output.
			}
		};
	}

	/**
	 * @param list<array{event:string,user_id:int}> $sent Sent events.
	 * @return list<int>
	 */
	private function recipients_for( array $sent, string $event ): array {
		$recipients = array_values(
			array_map(
				static fn( array $item ): int => $item['user_id'],
				array_filter( $sent, static fn( array $item ): bool => $event === $item['event'] )
			)
		);
		return $recipients;
	}
}
