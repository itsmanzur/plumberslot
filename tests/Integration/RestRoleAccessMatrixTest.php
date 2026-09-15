<?php
/**
 * Role and ownership coverage for protected REST surfaces.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Support\Capabilities;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class RestRoleAccessMatrixTest extends WP_UnitTestCase {

	/** @var array<string, int> */
	private array $users;
	private int $owned_technician_id;
	private int $foreign_technician_id;
	private int $booking_id;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		Capabilities::add_all();
		$this->empty_plumberslot_tables();

		$this->users = array(
			'administrator'    => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			'technician'       => self::factory()->user->create( array( 'role' => Capabilities::ROLE_TECHNICIAN ) ),
			'other_technician' => self::factory()->user->create( array( 'role' => Capabilities::ROLE_TECHNICIAN ) ),
			'customer'         => self::factory()->user->create( array( 'role' => Capabilities::ROLE_CUSTOMER ) ),
			'outsider'         => self::factory()->user->create( array( 'role' => 'subscriber' ) ),
		);

		$technicians                  = new TechnicianRepository();
		$this->owned_technician_id    = $this->create_technician( $technicians, $this->users['technician'], 'matrix-owner' );
		$this->foreign_technician_id  = $this->create_technician( $technicians, $this->users['other_technician'], 'matrix-foreign' );
		$this->booking_id             = $this->create_booking();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->empty_plumberslot_tables();
		parent::tear_down();
	}

	public function test_manager_surfaces_allow_only_administrators(): void {
		$routes = array(
			array( 'GET', '/plumberslot/v1/technicians' ),
			array( 'GET', '/plumberslot/v1/settings' ),
			array( 'GET', '/plumberslot/v1/audit' ),
		);

		foreach ( $routes as [$method, $route] ) {
			foreach ( array( 'administrator', 'technician', 'customer', 'outsider' ) as $role ) {
				$this->assert_access( $role, $method, $route, array(), 'administrator' === $role );
			}
		}
	}

	public function test_technician_surfaces_enforce_technician_ownership(): void {
		$surfaces = array(
			array( 'GET', '/plumberslot/v1/availability/(?P<technician_id>\d+)' ),
			array( 'GET', '/plumberslot/v1/technicians/(?P<technician_id>\d+)/services' ),
			array( 'GET', '/plumberslot/v1/dashboard' ),
		);

		foreach ( $surfaces as [$method, $route] ) {
			$params = array( 'technician_id' => $this->owned_technician_id );
			$this->assert_access( 'administrator', $method, $route, $params, true );
			$this->assert_access( 'technician', $method, $route, $params, true );
			$this->assert_access( 'other_technician', $method, $route, $params, false );
			$this->assert_access( 'customer', $method, $route, $params, false );
			$this->assert_access( 'outsider', $method, $route, $params, false );
		}

		$this->assert_access(
			'other_technician',
			'GET',
			'/plumberslot/v1/availability/(?P<technician_id>\d+)',
			array( 'technician_id' => $this->foreign_technician_id ),
			true
		);
	}

	public function test_booking_access_allows_every_legitimate_party_and_denies_outsiders(): void {
		$route  = '/plumberslot/v1/bookings/(?P<id>\d+)';
		$params = array( 'id' => $this->booking_id );

		foreach ( array( 'administrator', 'technician', 'customer' ) as $role ) {
			$this->assert_access( $role, 'GET', $route, $params, true );
		}

		$this->assert_access( 'other_technician', 'GET', $route, $params, false );
		$this->assert_access( 'outsider', 'GET', $route, $params, false );
	}

	public function test_booking_capability_and_self_scoped_listing_cover_every_account_role(): void {
		foreach ( array( 'administrator', 'technician', 'customer' ) as $role ) {
			$this->assert_access( $role, 'POST', '/plumberslot/v1/bookings', array(), true );
		}
		$this->assert_access( 'outsider', 'POST', '/plumberslot/v1/bookings', array(), false );

		foreach ( array( 'administrator', 'technician', 'customer', 'outsider' ) as $role ) {
			$this->assert_access( $role, 'GET', '/plumberslot/v1/bookings', array(), true );
		}
		$this->assert_access( 'anonymous', 'GET', '/plumberslot/v1/bookings', array(), false );
	}

	public function test_setup_requires_technician_management_capability(): void {
		foreach ( array( 'administrator', 'technician' ) as $role ) {
			$this->assert_access( $role, 'GET', '/plumberslot/v1/setup', array(), true );
		}

		foreach ( array( 'customer', 'outsider' ) as $role ) {
			$this->assert_access( $role, 'GET', '/plumberslot/v1/setup', array(), false );
		}
	}

	/**
	 * @param array<string, int> $params Request parameters.
	 */
	private function assert_access( string $role, string $method, string $route, array $params, bool $expected ): void {
		$user_id = 'anonymous' === $role ? 0 : $this->users[ $role ];
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( $user_id > 0 ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		$endpoint = $this->endpoint_for( $method, $route );
		$result   = call_user_func( $endpoint['permission_callback'], $request );
		$message  = sprintf( '%s access mismatch for %s %s.', $role, $method, $route );

		if ( $expected ) {
			self::assertTrue( $result, $message );
		} else {
			self::assertNotTrue( $result, $message );
		}
	}

	/** @return array<string, mixed> */
	private function endpoint_for( string $method, string $route ): array {
		$routes = rest_get_server()->get_routes();
		self::assertArrayHasKey( $route, $routes );

		foreach ( $routes[ $route ] as $endpoint ) {
			if ( is_array( $endpoint ) && ! empty( $endpoint['methods'][ $method ] ) ) {
				self::assertArrayHasKey( 'permission_callback', $endpoint );
				self::assertIsCallable( $endpoint['permission_callback'] );

				return $endpoint;
			}
		}

		self::fail( 'Missing PlumberSlot REST endpoint: ' . $method . ' ' . $route );
	}

	private function create_technician( TechnicianRepository $technicians, int $user_id, string $slug ): int {
		$technician_id = $technicians->create(
			array(
				'user_id'      => $user_id,
				'slug'         => $slug,
				'display_name' => ucwords( str_replace( '-', ' ', $slug ) ),
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);

		self::assertGreaterThan( 0, $technician_id );

		return $technician_id;
	}

	private function create_booking(): int {
		$start = new \DateTimeImmutable( '+2 days', new \DateTimeZone( 'UTC' ) );
		$repo  = new BookingRepository();
		$id    = $repo->insert_unique(
			array(
				'technician_id' => $this->owned_technician_id,
				'customer_id'   => $this->users['customer'],
				'service_id'    => null,
				'start_utc'     => $start->format( 'Y-m-d H:i:s' ),
				'end_utc'       => $start->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' ),
				'customer_tz'   => 'UTC',
				'status'        => 'confirmed',
				'price_minor'   => 0,
				'currency'      => 'USD',
			)
		);

		self::assertIsInt( $id );

		return $id;
	}

	private function empty_plumberslot_tables(): void {
		global $wpdb;

		foreach ( array_reverse( Schema::all_keys() ) as $key ) {
			$table = Schema::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated integration fixture table.
			$wpdb->query( "DELETE FROM {$table}" );
		}
	}
}
