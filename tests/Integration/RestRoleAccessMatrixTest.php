<?php
/**
 * Role and ownership coverage for protected REST surfaces.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use TutorSlot\Database\Repository\BookingRepository;
use TutorSlot\Database\Repository\TutorRepository;
use TutorSlot\Database\Schema;
use TutorSlot\Support\Capabilities;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class RestRoleAccessMatrixTest extends WP_UnitTestCase {

	/** @var array<string, int> */
	private array $users;
	private int $owned_tutor_id;
	private int $foreign_tutor_id;
	private int $booking_id;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		Capabilities::add_all();
		$this->empty_tutorslot_tables();

		$this->users = array(
			'administrator' => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			'tutor'         => self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) ),
			'other_tutor'   => self::factory()->user->create( array( 'role' => Capabilities::ROLE_TUTOR ) ),
			'student'       => self::factory()->user->create( array( 'role' => Capabilities::ROLE_STUDENT ) ),
			'parent'        => self::factory()->user->create( array( 'role' => Capabilities::ROLE_PARENT ) ),
			'outsider'      => self::factory()->user->create( array( 'role' => 'subscriber' ) ),
		);

		$tutors                 = new TutorRepository();
		$this->owned_tutor_id   = $this->create_tutor( $tutors, $this->users['tutor'], 'matrix-owner' );
		$this->foreign_tutor_id = $this->create_tutor( $tutors, $this->users['other_tutor'], 'matrix-foreign' );
		$this->booking_id       = $this->create_booking();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->empty_tutorslot_tables();
		parent::tear_down();
	}

	public function test_manager_surfaces_allow_only_administrators(): void {
		$routes = array(
			array( 'GET', '/tutorslot/v1/tutors' ),
			array( 'GET', '/tutorslot/v1/settings' ),
			array( 'GET', '/tutorslot/v1/audit' ),
		);

		foreach ( $routes as [$method, $route] ) {
			foreach ( array( 'administrator', 'tutor', 'student', 'parent', 'outsider' ) as $role ) {
				$this->assert_access( $role, $method, $route, array(), 'administrator' === $role );
			}
		}
	}

	public function test_tutor_surfaces_enforce_tutor_ownership(): void {
		$surfaces = array(
			array( 'GET', '/tutorslot/v1/availability/(?P<tutor_id>\d+)' ),
			array( 'GET', '/tutorslot/v1/tutors/(?P<tutor_id>\d+)/subjects' ),
			array( 'GET', '/tutorslot/v1/dashboard' ),
		);

		foreach ( $surfaces as [$method, $route] ) {
			$params = array( 'tutor_id' => $this->owned_tutor_id );
			$this->assert_access( 'administrator', $method, $route, $params, true );
			$this->assert_access( 'tutor', $method, $route, $params, true );
			$this->assert_access( 'other_tutor', $method, $route, $params, false );
			$this->assert_access( 'student', $method, $route, $params, false );
			$this->assert_access( 'parent', $method, $route, $params, false );
			$this->assert_access( 'outsider', $method, $route, $params, false );
		}

		$this->assert_access(
			'other_tutor',
			'GET',
			'/tutorslot/v1/availability/(?P<tutor_id>\d+)',
			array( 'tutor_id' => $this->foreign_tutor_id ),
			true
		);
	}

	public function test_booking_access_allows_every_legitimate_party_and_denies_outsiders(): void {
		$route  = '/tutorslot/v1/bookings/(?P<id>\d+)';
		$params = array( 'id' => $this->booking_id );

		foreach ( array( 'administrator', 'tutor', 'student', 'parent' ) as $role ) {
			$this->assert_access( $role, 'GET', $route, $params, true );
		}

		$this->assert_access( 'other_tutor', 'GET', $route, $params, false );
		$this->assert_access( 'outsider', 'GET', $route, $params, false );
	}

	public function test_booking_capability_and_self_scoped_listing_cover_every_account_role(): void {
		foreach ( array( 'administrator', 'tutor', 'student', 'parent' ) as $role ) {
			$this->assert_access( $role, 'POST', '/tutorslot/v1/bookings', array(), true );
		}
		$this->assert_access( 'outsider', 'POST', '/tutorslot/v1/bookings', array(), false );

		foreach ( array( 'administrator', 'tutor', 'student', 'parent', 'outsider' ) as $role ) {
			$this->assert_access( $role, 'GET', '/tutorslot/v1/bookings', array(), true );
		}
		$this->assert_access( 'anonymous', 'GET', '/tutorslot/v1/bookings', array(), false );
	}

	public function test_setup_requires_tutor_management_capability(): void {
		foreach ( array( 'administrator', 'tutor' ) as $role ) {
			$this->assert_access( $role, 'GET', '/tutorslot/v1/setup', array(), true );
		}

		foreach ( array( 'student', 'parent', 'outsider' ) as $role ) {
			$this->assert_access( $role, 'GET', '/tutorslot/v1/setup', array(), false );
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

		self::fail( 'Missing TutorSlot REST endpoint: ' . $method . ' ' . $route );
	}

	private function create_tutor( TutorRepository $tutors, int $user_id, string $slug ): int {
		$tutor_id = $tutors->create(
			array(
				'user_id'      => $user_id,
				'slug'         => $slug,
				'display_name' => ucwords( str_replace( '-', ' ', $slug ) ),
				'timezone'     => 'UTC',
				'status'       => 'active',
			)
		);

		self::assertGreaterThan( 0, $tutor_id );

		return $tutor_id;
	}

	private function create_booking(): int {
		$start = new \DateTimeImmutable( '+2 days', new \DateTimeZone( 'UTC' ) );
		$repo  = new BookingRepository();
		$id    = $repo->insert_unique(
			array(
				'tutor_id'    => $this->owned_tutor_id,
				'student_id'  => $this->users['student'],
				'parent_id'   => $this->users['parent'],
				'subject_id'  => null,
				'start_utc'   => $start->format( 'Y-m-d H:i:s' ),
				'end_utc'     => $start->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' ),
				'student_tz'  => 'UTC',
				'status'      => 'confirmed',
				'price_minor' => 0,
				'currency'    => 'USD',
			)
		);

		self::assertIsInt( $id );

		return $id;
	}

	private function empty_tutorslot_tables(): void {
		global $wpdb;

		foreach ( array_reverse( Schema::all_keys() ) as $key ) {
			$table = Schema::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated integration fixture table.
			$wpdb->query( "DELETE FROM {$table}" );
		}
	}
}
